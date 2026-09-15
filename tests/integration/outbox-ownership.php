<?php

// php outbox-ownership.php /absolute/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Nexzan\Shared\Enums\OutboxStatus;
use Nexzan\Shared\Infrastructure\FieldVersionProjection;
use Nexzan\Shared\Infrastructure\OutboxPublisher;
use Nexzan\Shared\Infrastructure\RabbitMQService;
use Nexzan\Shared\Messaging\DomainEventEnvelope;
use Nexzan\Shared\Models\OutboxEvent;

$service = realpath($argv[1] ?? '') ?: throw new RuntimeException('Service path required');
$socket = $argv[2] ?? '';
if (! str_starts_with($socket, '/tmp/nexzan-messaging-integration.')) {
    throw new RuntimeException('Disposable MySQL socket required');
}
$database = $argv[3] ?? 'nxoutbox_'.getmypid().'_'.time();
if (! preg_match('/^nxoutbox_[0-9_]+$/', $database)) {
    throw new RuntimeException('Invalid test database');
}
putenv('APP_CONFIG_CACHE='.dirname($socket).'/absent-config.php');
putenv('APP_ENV=testing');
$loader = require $service.'/vendor/autoload.php';
$shared = dirname(__DIR__, 2);
$classes = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shared.'/src')) as $file) {
    if ($file->getExtension() === 'php') {
        $classes['Nexzan\\Shared\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($shared.'/src/'), -4))] = $file->getPathname();
    }
}
$loader->addClassMap($classes);
$app = require $service.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.default' => 'mysql', 'database.connections.mysql' => ['driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database,
    'username' => 'root', 'password' => '', 'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true],
    'cache.default' => 'array', 'logging.default' => 'null', 'rabbitmq.producer' => 'test-source']);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

function ownershipWait(callable $condition): void
{
    $until = microtime(true) + 10;
    do {
        if ($condition()) {
            return;
        } usleep(20000);
    } while (microtime(true) < $until);
    throw new RuntimeException('Test barrier timeout');
}
function ownershipCheck(bool $ok, string $label): void
{
    if (! $ok) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}
class OwnershipBroker extends RabbitMQService
{
    public function __construct(private ?string $barrier = null, private bool $fail = false) {}

    public function publishEnvelope(DomainEventEnvelope|array $envelope, string $exchange, ?string $routingKey = null): void
    {
        if ($this->barrier) {
            touch($this->barrier.'.claimed');
            ownershipWait(fn () => file_exists($this->barrier.'.release'));
        }
        if ($this->fail) {
            throw new RuntimeException('Delayed old publisher failure');
        }
    }
}
if (isset($argv[4])) {
    if ($argv[4] === 'field') {
        DB::transaction(function (): void {
            app(FieldVersionProjection::class)->applySnapshot('test-source', 'server.project', 'server', 'server-one', 1,
                fn () => DB::table('test_effects')->insert(['name' => 'stale placement']));
        });
    } else {
        (new OutboxPublisher(new OwnershipBroker($argv[5], $argv[4] === 'failure')))->publishBatch(1);
    }
    exit(0);
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '');
$pdo->exec('CREATE DATABASE `'.$database.'`');
$children = [];
try {
    foreach (glob($shared.'/database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    DB::statement('CREATE TABLE test_effects (name VARCHAR(100))');
    foreach (['failure', 'success'] as $mode) {
        $event = OutboxEvent::record('test.created', 'test_exchange', []);
        $barrier = dirname($socket).'/'.$database.'-'.$mode;
        $log = $barrier.'.log';
        $child = proc_open([PHP_BINARY, __FILE__, $service, $socket, $database, $mode, $barrier],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        $children[] = $child;
        ownershipWait(fn () => file_exists($barrier.'.claimed'));
        ownershipCheck($event->fresh()->status === OutboxStatus::Publishing, 'first process has durable publish claim');
        $oldToken = $event->fresh()->publish_token;
        $event->update(['publishing_started_at' => now()->subMinutes(10)]);
        app(Kernel::class)->call('outbox:recover');
        if ($mode === 'failure') {
            (new OutboxPublisher(new OwnershipBroker))->publishBatch(1);
        } else {
            $event->update(['status' => OutboxStatus::Publishing, 'publish_token' => Str::uuid(), 'publishing_started_at' => now()]);
        }
        touch($barrier.'.release');
        $exit = proc_close($child);
        array_pop($children);
        ownershipCheck($exit === 0, 'old process resumed cleanly: '.$mode);
        ownershipCheck($event->fresh()->status === ($mode === 'failure' ? OutboxStatus::Published : OutboxStatus::Publishing), 'old '.$mode.' cannot overwrite replacement outcome');
        ownershipCheck($event->fresh()->publish_token !== $oldToken, 'expired ownership token cannot regain control');
        $event->update(['status' => OutboxStatus::Published, 'publish_token' => null]);
    }
    DB::beginTransaction();
    app(FieldVersionProjection::class)->applySnapshot('test-source', 'server.project', 'server', 'server-one', 3,
        fn () => DB::table('test_effects')->insert(['name' => 'current placement']));
    $log = dirname($socket).'/'.$database.'-field.log';
    $child = proc_open([PHP_BINARY, __FILE__, $service, $socket, $database, 'field'],
        [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
    $children[] = $child;
    ownershipWait(fn () => (int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn() > 0);
    ownershipCheck(true, 'competing projection waits on real InnoDB lock');
    DB::commit();
    $exit = proc_close($child);
    array_pop($children);
    ownershipCheck($exit === 0, 'competing projection completed');
    ownershipCheck(DB::table('test_effects')->pluck('name')->all() === ['current placement'], 'late nested sequence cannot replace newer placement');
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($children as $child) {
        proc_terminate($child);
        proc_close($child);
    }
    DB::disconnect('mysql');
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
