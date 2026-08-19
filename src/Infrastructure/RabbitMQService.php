<?php

namespace Nexzan\Shared\Infrastructure;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Exceptions\InvalidMessageEnvelope;
use Nexzan\Shared\Messaging\DomainEventEnvelope;
use Nexzan\Shared\Models\InboxEvent;
use Nexzan\Shared\Models\OutboxEvent;
use PhpAmqpLib\Channel\AMQPChannel;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Exchange\AMQPExchangeType;
use PhpAmqpLib\Message\AMQPMessage;
use PhpAmqpLib\Wire\AMQPTable;
use RuntimeException;
use Throwable;

class RabbitMQService
{
    /**
     * Compatibility facade for existing producers. This records the event in
     * the transactional Outbox; only OutboxPublisher talks to RabbitMQ.
     */
    public function recordOutboxEvent(
        array $message,
        string $routingKey,
        string $exchange = 'topic_exchange',
        ?string $aggregateType = null,
        string|int|null $aggregateId = null,
        ?int $aggregateVersion = null,
    ): OutboxEvent {
        return OutboxEvent::record(
            eventType: $routingKey,
            exchange: $exchange,
            payload: $message,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    public function publishEnvelope(
        DomainEventEnvelope|array $envelope,
        string $exchange,
        ?string $routingKey = null,
    ): void {
        $envelope = DomainEventEnvelope::fromArray(
            is_array($envelope) ? $envelope : $envelope->toArray()
        );
        $routingKey ??= $envelope->event;
        [$connection, $channel] = $this->createConnection();
        $acknowledged = false;
        $nacked = false;
        $returned = null;
        $originalException = null;

        try {
            $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
            $channel->confirm_select();
            $channel->set_ack_handler(function () use (&$acknowledged): void {
                $acknowledged = true;
            });
            $channel->set_nack_handler(function () use (&$nacked): void {
                $nacked = true;
            });
            $channel->set_return_listener(function (
                int $replyCode,
                string $replyText,
                string $returnedExchange,
                string $returnedRoutingKey,
            ) use (&$returned): void {
                $returned = "{$replyCode} {$replyText} ({$returnedExchange}:{$returnedRoutingKey})";
            });

            $body = json_encode($envelope->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            $message = new AMQPMessage($body, [
                'content_type' => 'application/json',
                'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT,
                'message_id' => $envelope->eventId,
                'type' => $envelope->event,
                'timestamp' => time(),
            ]);

            $channel->basic_publish($message, $exchange, $routingKey, true);
            $channel->wait_for_pending_acks_returns((float) config('rabbitmq.publisher_confirm_timeout', 5));

            if ($returned !== null) {
                throw new RuntimeException("RabbitMQ returned an unroutable message: {$returned}");
            }

            if ($nacked || ! $acknowledged) {
                throw new RuntimeException($nacked
                    ? 'RabbitMQ negatively acknowledged the published message.'
                    : 'RabbitMQ did not confirm the published message.');
            }
        } catch (Throwable $exception) {
            $originalException = $exception;
            throw $exception;
        } finally {
            $this->shutdown($channel, $connection, $originalException);
        }
    }

    public function consumeTopic(
        string $queueName,
        string $exchange,
        array $exactBindings,
    ): void {
        $bindings = array_values(array_unique($exactBindings));

        if ($bindings === [] || in_array('', $bindings, true)) {
            throw new RuntimeException("RabbitMQ queue {$queueName} requires at least one non-empty binding.");
        }

        [$connection, $channel] = $this->createConnection();
        $originalException = null;

        try {
            $this->declareConsumerTopology($channel, $exchange, $queueName, $bindings);

            if (config('rabbitmq.declare_only', false)) {
                Log::info('RabbitMQ consumer topology declared without consuming.', [
                    'exchange' => $exchange,
                    'queue' => $queueName,
                    'bindings' => $bindings,
                ]);

                return;
            }

            $channel->basic_qos(null, (int) config('rabbitmq.prefetch_count', 10), null);
            $channel->basic_consume(
                $queueName,
                '',
                false,
                false,
                false,
                false,
                fn (AMQPMessage $message) => $this->handleDelivery($message, $exchange, $queueName),
            );

            Log::info('RabbitMQ reliable consumer started.', [
                'exchange' => $exchange,
                'queue' => $queueName,
                'bindings' => $bindings,
            ]);

            while ($channel->is_consuming()) {
                $channel->wait();
            }
        } catch (Throwable $exception) {
            $originalException = $exception;
            throw $exception;
        } finally {
            $this->shutdown($channel, $connection, $originalException);
        }
    }

    public function retryDeadLetters(
        string $exchange,
        string $queueName,
        int $limit = 100,
        bool $discardInvalid = false,
    ): array {
        [$connection, $channel] = $this->createConnection();
        $retried = 0;
        $discarded = 0;
        $originalException = null;

        try {
            $deadQueue = "{$queueName}.dlq";
            $channel->queue_declare($deadQueue, false, true, false, false);

            for ($index = 0; $index < max(0, $limit); $index++) {
                $message = $channel->basic_get($deadQueue, false);

                if (! $message instanceof AMQPMessage) {
                    break;
                }

                try {
                    $envelope = DomainEventEnvelope::fromJson($message->getBody());
                    $this->publishEnvelope($envelope, $exchange, $envelope->event);
                    $message->ack();
                    $retried++;
                } catch (InvalidMessageEnvelope $exception) {
                    if (! $discardInvalid) {
                        $message->nack(true);
                        break;
                    }

                    $message->reject(false);
                    $discarded++;
                } catch (Throwable $exception) {
                    $message->nack(true);
                    throw $exception;
                }
            }
        } catch (Throwable $exception) {
            $originalException = $exception;
            throw $exception;
        } finally {
            $this->shutdown($channel, $connection, $originalException);
        }

        return compact('retried', 'discarded');
    }

    private function handleDelivery(AMQPMessage $message, string $exchange, string $queueName): void
    {
        try {
            $envelope = DomainEventEnvelope::fromJson($message->getBody());

            if ($message->getRoutingKey() !== $envelope->event) {
                throw new InvalidMessageEnvelope('Envelope event must match the AMQP routing key.');
            }
        } catch (InvalidMessageEnvelope $exception) {
            Log::warning('Rejected invalid RabbitMQ message.', [
                'exchange' => $exchange,
                'queue' => $queueName,
                'routing_key' => $message->getRoutingKey(),
                'error' => $exception->getMessage(),
            ]);
            $message->reject(false);

            return;
        }

        try {
            $inboxEvent = DB::transaction(function () use ($envelope, $exchange, $queueName, $message): InboxEvent {
                return InboxEvent::query()->firstOrCreate(
                    ['event_id' => $envelope->eventId],
                    [
                        'event_type' => $envelope->event,
                        'exchange' => $exchange,
                        'routing_key' => $message->getRoutingKey(),
                        'queue_name' => $queueName,
                        'producer' => $envelope->producer,
                        'aggregate_type' => $envelope->aggregateType,
                        'aggregate_id' => $envelope->aggregateId,
                        'aggregate_version' => $envelope->aggregateVersion,
                        'payload' => $envelope->toArray(),
                        'status' => InboxStatus::Pending,
                    ],
                );
            });
        } catch (Throwable $exception) {
            Log::error('Could not persist RabbitMQ message to Inbox.', [
                'event_id' => $envelope->eventId,
                'event' => $envelope->event,
                'queue' => $queueName,
                'error' => $exception->getMessage(),
            ]);
            $message->nack(true);

            return;
        }

        $message->ack();

        if ($inboxEvent->status === InboxStatus::Completed) {
            return;
        }

        try {
            app(InboxEventDispatcher::class)->dispatch($inboxEvent);
        } catch (Throwable $exception) {
            Log::error('Inbox persisted but job dispatch failed; recovery will retry it.', [
                'event_id' => $envelope->eventId,
                'event' => $envelope->event,
                'queue' => $queueName,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    private function declareConsumerTopology(
        AMQPChannel $channel,
        string $exchange,
        string $queueName,
        array $bindings,
    ): void {
        $channel->exchange_declare($exchange, AMQPExchangeType::TOPIC, false, true, false);
        $arguments = [];

        if (config('rabbitmq.enable_dlx', true)) {
            $deadExchange = "{$exchange}.dlx";
            $deadQueue = "{$queueName}.dlq";
            $deadRoutingKey = "{$queueName}.dlq";
            $channel->exchange_declare($deadExchange, AMQPExchangeType::DIRECT, false, true, false);
            $channel->queue_declare($deadQueue, false, true, false, false);
            $channel->queue_bind($deadQueue, $deadExchange, $deadRoutingKey);
            $arguments = [
                'x-dead-letter-exchange' => $deadExchange,
                'x-dead-letter-routing-key' => $deadRoutingKey,
            ];
        }

        $channel->queue_declare(
            $queueName,
            false,
            true,
            false,
            false,
            false,
            new AMQPTable($arguments),
        );

        foreach ($bindings as $binding) {
            $channel->queue_bind($queueName, $exchange, $binding);
        }
    }

    /** @return array{AMQPStreamConnection, AMQPChannel} */
    private function createConnection(): array
    {
        $connection = new AMQPStreamConnection(
            (string) config('rabbitmq.host', '127.0.0.1'),
            (int) config('rabbitmq.port', 5672),
            (string) config('rabbitmq.user', 'guest'),
            (string) config('rabbitmq.password', 'guest'),
            (string) config('rabbitmq.vhost', '/'),
            false,
            'AMQPLAIN',
            null,
            'en_US',
            (float) config('rabbitmq.connection_timeout', 3),
            (float) config('rabbitmq.read_write_timeout', 65),
            null,
            false,
            (int) config('rabbitmq.heartbeat', 30),
            (float) config('rabbitmq.channel_rpc_timeout', 5),
        );

        return [$connection, $connection->channel()];
    }

    private function shutdown(AMQPChannel $channel, AMQPStreamConnection $connection, ?Throwable $original): void
    {
        try {
            if ($channel->is_open()) {
                $channel->close();
            }

            if ($connection->isConnected()) {
                $connection->close();
            }
        } catch (Throwable $closeException) {
            if ($original === null) {
                throw $closeException;
            }

            Log::warning('RabbitMQ connection cleanup failed after another exception.', [
                'error' => $closeException->getMessage(),
            ]);
        }
    }
}
