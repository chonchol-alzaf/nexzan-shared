<?php

namespace Nexzan\Shared\Messaging;

use DateTimeImmutable;
use Illuminate\Support\Str;
use JsonException;
use Nexzan\Shared\Exceptions\InvalidMessageEnvelope;

final readonly class DomainEventEnvelope
{
    public function __construct(
        public string $event,
        public string $eventId,
        public int $version,
        public array $resource,
        public string $occurredAt,
        public string $producer,
        public ?string $aggregateType = null,
        public ?string $aggregateId = null,
        public ?int $aggregateVersion = null,
    ) {}

    /** @throws InvalidMessageEnvelope */
    public static function fromJson(string $json): self
    {
        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidMessageEnvelope('Message body is not valid JSON.', previous: $exception);
        }

        if (! is_array($payload)) {
            throw new InvalidMessageEnvelope('Message body must decode to a JSON object.');
        }

        return self::fromArray($payload);
    }

    /** @throws InvalidMessageEnvelope */
    public static function fromArray(array $payload): self
    {
        $event = $payload['event'] ?? null;
        $eventId = $payload['event_id'] ?? null;
        $version = $payload['version'] ?? null;
        $resource = $payload['resource'] ?? null;
        $occurredAt = $payload['occurred_at'] ?? null;

        if (! is_string($event) || trim($event) === '') {
            throw new InvalidMessageEnvelope('Envelope event must be a non-empty string.');
        }

        if (! is_string($eventId) || ! Str::isUlid($eventId)) {
            throw new InvalidMessageEnvelope('Envelope event_id must be a valid ULID.');
        }

        if (! is_int($version) || $version < 1) {
            throw new InvalidMessageEnvelope('Envelope version must be a positive integer.');
        }

        if (! is_array($resource)) {
            throw new InvalidMessageEnvelope('Envelope resource must be a JSON object or array.');
        }

        if (! is_string($occurredAt) || trim($occurredAt) === '') {
            throw new InvalidMessageEnvelope('Envelope occurred_at must be a non-empty ISO-8601 string.');
        }

        try {
            new DateTimeImmutable($occurredAt);
        } catch (\Throwable $exception) {
            throw new InvalidMessageEnvelope('Envelope occurred_at must be a valid ISO-8601 timestamp.', previous: $exception);
        }

        $producer = $payload['producer'] ?? null;

        if (! is_string($producer) || trim($producer) === '') {
            throw new InvalidMessageEnvelope('Envelope producer must be a non-empty string.');
        }

        $aggregateType = self::optionalString($payload, 'aggregate_type');
        $aggregateId = self::optionalString($payload, 'aggregate_id');
        $aggregateVersion = $payload['aggregate_version'] ?? null;

        if ($aggregateVersion !== null && (! is_int($aggregateVersion) || $aggregateVersion < 1)) {
            throw new InvalidMessageEnvelope('Envelope aggregate_version must be a positive integer when present.');
        }

        $aggregateParts = [$aggregateType, $aggregateId, $aggregateVersion];
        $presentAggregateParts = count(array_filter($aggregateParts, fn (mixed $value): bool => $value !== null));

        if ($presentAggregateParts !== 0 && $presentAggregateParts !== count($aggregateParts)) {
            throw new InvalidMessageEnvelope(
                'Envelope aggregate_type, aggregate_id, and aggregate_version must be provided together.'
            );
        }

        return new self(
            event: $event,
            eventId: $eventId,
            version: $version,
            resource: $resource,
            occurredAt: $occurredAt,
            producer: $producer,
            aggregateType: $aggregateType,
            aggregateId: $aggregateId,
            aggregateVersion: $aggregateVersion,
        );
    }

    public function toArray(): array
    {
        return [
            'event' => $this->event,
            'event_id' => $this->eventId,
            'version' => $this->version,
            'producer' => $this->producer,
            'aggregate_type' => $this->aggregateType,
            'aggregate_id' => $this->aggregateId,
            'aggregate_version' => $this->aggregateVersion,
            'resource' => $this->resource,
            'occurred_at' => $this->occurredAt,
        ];
    }

    private static function optionalString(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if ($value === null) {
            return null;
        }

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidMessageEnvelope("Envelope {$key} must be a non-empty string when present.");
        }

        return $value;
    }
}
