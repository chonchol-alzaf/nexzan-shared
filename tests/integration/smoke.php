<?php

// Run against disposable localhost infrastructure only; never loads a service's database credentials.
// php tests/integration/smoke.php /absolute/path/to/service /absolute/path/to/test-mysql.sock
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nexzan\Shared\Infrastructure\DurableOperationProcessor;
use Nexzan\Shared\Infrastructure\InboxEventProcessor;
use Nexzan\Shared\Infrastructure\OutboxPublisher;
use Nexzan\Shared\Infrastructure\RabbitMQService;
use Nexzan\Shared\Models\DurableOperation;
use Nexzan\Shared\Models\InboxEvent;
use Nexzan\Shared\Models\OutboxEvent;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

$service = realpath($argv[1] ?? '') ?: throw new RuntimeException('Service path required');
$socket = $argv[2] ?? throw new RuntimeException('Disposable MySQL socket required');
if (! str_starts_with($socket, '/tmp/nexzan-messaging-integration.')) {
    throw new RuntimeException('Refusing a non-test MySQL socket');
}
$database = $argv[3] ?? 'nxmsg_'.getmypid().'_'.time();
if (! preg_match('/^nxmsg_[0-9_]+$/', $database)) {
    throw new RuntimeException('Invalid test database');
}
$worker = ($argv[4] ?? '') === 'worker';
require $service.'/vendor/autoload.php';
class SmokeInboxJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(public string $id) {}

    public function handle(InboxEventProcessor $processor): void
    {
        $processor->process($this->id, function () {
            usleep(150000);
            DB::table('messaging_probe')->insert(['kind' => 'domain']);
            DurableOperation::dispatch(new SmokeOperation, 'probe');
        });
    }
}

class SmokeOperation
{
    public function handle(): void
    {
        check(DB::transactionLevel() === 0, 'external operation outside Inbox transaction');
        DB::table('messaging_probe')->insert(['kind' => 'operation']);
    }
}

$app = require $service.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});
config([
    'app.env' => 'testing', 'app.key' => 'base64:'.base64_encode(str_repeat('x', 32)),
    'database.default' => 'smoke',
    'database.connections.smoke' => ['driver' => 'mysql', 'unix_socket' => $socket,
        'database' => $database, 'username' => 'root', 'password' => '', 'prefix' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true],
    'database.redis.client' => 'phpredis',
    'database.redis.options.prefix' => $database.':',
    'database.redis.default' => ['host' => '127.0.0.1', 'port' => 16389, 'database' => 0, 'password' => null],
    'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 16389, 'database' => 0, 'password' => null],
    'queue.default' => 'redis', 'queue.connections.redis.connection' => 'default',
    'queue.connections.redis.after_commit' => false,
    'cache.default' => 'array', 'horizon.use' => 'default', 'horizon.prefix' => $database.':horizon:',
    'rabbitmq.host' => '127.0.0.1', 'rabbitmq.port' => 15682,
    'rabbitmq.user' => 'guest', 'rabbitmq.password' => 'guest', 'rabbitmq.vhost' => '/',
    'rabbitmq.producer' => 'integration', 'rabbitmq.inbox_job' => SmokeInboxJob::class,
    'rabbitmq.enable_dlx' => true,
    'logging.default' => 'stderr', 'telescope.enabled' => false,
]);
DB::purge('smoke');
DB::setDefaultConnection('smoke');
$queue = $database.'.queue';
if ($worker) {
    exit($kernel->call('horizon:work', ['connection' => 'redis', '--queue' => $queue,
        '--once' => true, '--tries' => 1, '--timeout' => 30]));
}

function check(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}

$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '');
$pdo->exec('CREATE DATABASE `'.$database.'`');
foreach (glob(__DIR__.'/../../database/migrations/*.php') as $migration) {
    (require $migration)->up();
}
Schema::create('messaging_probe', function ($table) {
    $table->id();
    $table->string('kind');
});
check(DB::connection()->getDriverName() === 'mysql', 'real MySQL migrations');
check(! Schema::hasTable('processed_events'), 'legacy table absent');

try {
    DB::transaction(function () {
        DB::table('messaging_probe')->insert(['kind' => 'rollback']);
        OutboxEvent::record('smoke.created', 'ignored', []);
        throw new RuntimeException('expected rollback');
    });
} catch (RuntimeException $e) {
    if ($e->getMessage() !== 'expected rollback') {
        throw $e;
    }
}
check(OutboxEvent::count() === 0 && DB::table('messaging_probe')->count() === 0, 'atomic producer rollback');

$rabbit = app(RabbitMQService::class);
$exchange = $database.'.exchange';
config(['rabbitmq.declare_only' => true]);
$rabbit->consumeTopic($queue, $exchange, ['smoke.created']);
$event = DB::transaction(fn () => OutboxEvent::record('smoke.created', $exchange, ['probe' => true]));
app(OutboxPublisher::class)->publishBatch(1);
check($event->fresh()->status->value === 'published', 'mandatory confirmed publish');
$connection = new AMQPStreamConnection('127.0.0.1', 15682, 'guest', 'guest');
$channel = $connection->channel();
$delivery = new ReflectionMethod(RabbitMQService::class, 'handleDelivery');
$message = $channel->basic_get($queue);
check($message instanceof AMQPMessage, 'exact binding receives event');
$body = $message->body;
$delivery->invoke($rabbit, $message, $exchange, $queue);
check(InboxEvent::count() === 1, 'broker delivery persisted to Inbox');
// Replay a confirmed publish whose producer crashed before recording published status.
$channel->basic_publish(new AMQPMessage($body), $exchange, 'smoke.created');
$delivery->invoke($rabbit, $channel->basic_get($queue), $exchange, $queue);
check(InboxEvent::count() === 1, 'broker duplicate uses same Inbox');
SmokeInboxJob::dispatch(InboxEvent::sole()->id)->onQueue($queue);

$processes = [];
for ($i = 0; $i < 2; $i++) {
    $processes[] = proc_open([PHP_BINARY, __FILE__, $service, $socket, $database, 'worker'],
        [0 => ['file', '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
}
foreach ($processes as $process) {
    check(proc_close($process) === 0, 'Horizon worker exit');
}
check(InboxEvent::sole()->status->value === 'completed', 'Horizon completes Inbox');
check(DB::table('messaging_probe')->where('kind', 'domain')->count() === 1, 'concurrent duplicate has one domain effect');
check(DurableOperation::count() === 1, 'one durable secondary operation');
app(DurableOperationProcessor::class)->processNext();
check(DB::table('messaging_probe')->where('kind', 'operation')->count() === 1, 'secondary effect executes outside transaction');

$channel->basic_publish(new AMQPMessage('{invalid'), $exchange, 'smoke.created');
$delivery->invoke($rabbit, $channel->basic_get($queue), $exchange, $queue);
$dead = null;
for ($i = 0; $i < 30 && ! $dead; $i++) {
    usleep(20000);
    $dead = $channel->basic_get($queue.'.dlq');
}
check($dead instanceof AMQPMessage, 'invalid envelope reaches broker DLQ');
$dead->ack();

// A DB outage must leave the broker delivery recoverable.
$newBody = json_decode($body, true);
$newBody['event_id'] = (string) Str::ulid();
$channel->basic_publish(new AMQPMessage(json_encode($newBody)), $exchange, 'smoke.created');
$message = $channel->basic_get($queue);
config(['database.connections.smoke.database' => $database.'_missing']);
DB::purge('smoke');
$delivery->invoke($rabbit, $message, $exchange, $queue);
config(['database.connections.smoke.database' => $database]);
DB::purge('smoke');
$requeued = $channel->basic_get($queue);
check($requeued instanceof AMQPMessage && $requeued->isRedelivered(), 'DB failure requeues broker delivery');
$requeued->ack();

$unroutable = OutboxEvent::record('smoke.unsupported', $exchange, []);
app(OutboxPublisher::class)->publishBatch(1);
check($unroutable->fresh()->status->value === 'failed', 'unroutable publish backs off');
$channel->queue_delete($queue);
$channel->queue_delete($queue.'.dlq');
$channel->exchange_delete($exchange);
$channel->exchange_delete($exchange.'.dlx');
$channel->close();
$connection->close();
echo 'SUCCESS Laravel '.$app->version().' / '.$database.PHP_EOL;
