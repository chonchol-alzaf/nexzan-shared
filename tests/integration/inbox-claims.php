<?php

// php inbox-claims.php /absolute/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Exceptions\MessageDependencyNotReady;
use Nexzan\Shared\Infrastructure\FieldVersionProjection;
use Nexzan\Shared\Infrastructure\InboxEventDispatcher;
use Nexzan\Shared\Infrastructure\InboxEventProcessor;
use Nexzan\Shared\Infrastructure\ResourceDeletionGuard;
use Nexzan\Shared\Models\InboxEvent;

$service = realpath($argv[1] ?? '') ?: throw new RuntimeException('Gateway service path required');
$socket = $argv[2] ?? '';
if (! str_starts_with($socket, '/tmp/nexzan-messaging-integration.')) {
    throw new RuntimeException('Only a disposable MySQL socket is allowed');
}
$database = $argv[3] ?? 'nxclaims_'.getmypid().'_'.time();
if (! preg_match('/^nxclaims_[0-9_]+$/', $database)) {
    throw new RuntimeException('Invalid isolated database name');
}
putenv('APP_CONFIG_CACHE='.dirname($socket).'/absent-config.php');
putenv('APP_ENV=testing');
$loader = require $service.'/vendor/autoload.php';
// Test this checkout before release, even if the service installs an older classmap.
$shared = dirname(__DIR__, 2);
$classmap = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($shared.'/src')) as $file) {
    if ($file->getExtension() === 'php') {
        $classmap['Nexzan\\Shared\\'.str_replace('/', '\\', substr($file->getPathname(), strlen($shared.'/src/'), -4))] = $file->getPathname();
    }
}
$loader->addClassMap($classmap);
$app = require $service.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $e): void {
    fwrite(STDERR, get_class($e).': '.$e->getMessage().PHP_EOL.$e->getTraceAsString().PHP_EOL);
    exit(1);
});
config(['database.default' => 'mysql', 'database.connections.mysql' => [
    'driver' => 'mysql', 'unix_socket' => $socket, 'database' => $database, 'username' => 'root',
    'password' => '', 'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true,
], 'cache.default' => 'array', 'logging.default' => 'null', 'queue.default' => 'sync',
    'rabbitmq.inbox_job' => ClaimIntegrationJob::class]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');

class ClaimIntegrationJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public string $id, public ?string $dispatchToken = null) {}

    public function handle(InboxEventProcessor $processor): void
    {
        DB::table('test_dispatches')->insert(['receipt' => $this->id, 'token' => $this->dispatchToken]);
        $processor->process($this->id, fn () => throw new MessageDependencyNotReady('Creation pending'), $this->dispatchToken);
    }
}

if (isset($argv[4])) {
    $event = InboxEvent::findOrFail($argv[5]);
    if ($argv[4] === 'field') {
        DB::transaction(function () use ($event): void {
            DB::table('inbox_events')->count(); // Establish an older REPEATABLE READ snapshot.
            app(InboxEventProcessor::class)->process($event->id, function () use ($event): void {
                app(FieldVersionProjection::class)->apply('server.readiness', 'server', $event->aggregate_id,
                    fn () => DB::table('test_effects')->insert(['name' => 'stale field applied']), ['server.ready']);
            });
        });
    } elseif ($argv[4] === 'worker') {
        app(InboxEventProcessor::class)->process($event->id, function (): void {
            DB::table('test_effects')->insert(['name' => 'stale worker ran']);
        }, $argv[6]);
    } else {
        app(InboxEventDispatcher::class)->dispatch($event, recoverStale: $argv[4] === 'recover');
    }
    exit(0);
}

function claimCheck(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}
function claimReceipt(): InboxEvent
{
    return InboxEvent::create(['event_id' => (string) Str::ulid(), 'event_type' => 'claim.test',
        'exchange' => 'test_exchange', 'routing_key' => 'claim.test', 'queue_name' => 'claim-test',
        'producer' => 'test', 'payload' => [], 'status' => InboxStatus::Pending]);
}
$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '');
$pdo->exec('CREATE DATABASE `'.$database.'`');
$workers = [];
try {
    foreach (glob($shared.'/database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    Schema::create('test_dispatches', function ($table): void {
        $table->id();
        $table->string('receipt');
        $table->string('token');
    });
    Schema::create('test_effects', function ($table): void {
        $table->id();
        $table->string('name');
    });
    $startBlocked = function (InboxEvent $event, string $mode, string $token = '') use ($service, $socket, $database, $pdo, &$workers) {
        $log = dirname($socket).'/'.$mode.'-'.$event->id.'.log';
        $worker = proc_open([PHP_BINARY, __FILE__, $service, $socket, $database, $mode, $event->id, $token],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Cannot start competing process');
        }
        $workers[] = $worker;
        $deadline = microtime(true) + 10;
        do {
            $blocked = (int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn() > 0;
            if ($blocked) {
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        claimCheck($blocked, $mode.' observed waiting on the real Inbox row lock');

        return [$worker, $log];
    };
    $finish = function (array $child) use (&$workers): void {
        [$worker, $log] = $child;
        $exit = proc_close($worker);
        array_pop($workers);
        claimCheck($exit === 0, 'competing process completed: '.($exit === 0 ? 'OK' : file_get_contents($log)));
    };
    $event = claimReceipt();
    DB::beginTransaction();
    InboxEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
    $child = $startBlocked($event, 'dispatch');
    app(InboxEventDispatcher::class)->dispatch($event);
    claimCheck(DB::table('test_dispatches')->count() === 0, 'no enqueue before outermost commit');
    DB::commit();
    $finish($child);
    claimCheck(DB::table('test_dispatches')->count() === 1, 'concurrent dispatchers enqueue once');
    claimCheck($event->fresh()->status === InboxStatus::Waiting && $event->fresh()->attempts === 0, 'immediate worker waiting survives both dispatchers');

    // A stale candidate selected earlier cannot reclaim a freshly renewed claim.
    $event = claimReceipt();
    $event->update(['status' => InboxStatus::Queued, 'dispatch_token' => (string) Str::uuid(), 'dispatched_at' => now()->subMinutes(10)]);
    DB::beginTransaction();
    InboxEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
    $child = $startBlocked($event, 'recover');
    $freshToken = (string) Str::uuid();
    $event->update(['dispatch_token' => $freshToken, 'dispatched_at' => now()]);
    DB::commit();
    $finish($child);
    claimCheck($event->fresh()->dispatch_token === $freshToken && $event->fresh()->status === InboxStatus::Queued, 'recovery rechecks fresh claim under lock');

    // Simulate a process dying after durable claim commit, before pushing its job.
    $event->update(['dispatched_at' => now()->subMinutes(10)]);
    DB::beginTransaction();
    InboxEvent::whereKey($event->id)->lockForUpdate()->firstOrFail();
    $child = $startBlocked($event, 'worker', $freshToken);
    app(InboxEventDispatcher::class)->dispatch($event, recoverStale: true);
    DB::commit();
    $finish($child);
    claimCheck($event->fresh()->dispatch_token !== $freshToken && $event->fresh()->status === InboxStatus::Waiting, 'crashed claim recovered with a new token');
    claimCheck(DB::table('test_effects')->count() === 0, 'superseded queued worker cannot run its handler');
    $event = claimReceipt();
    $event->update(['producer' => 'atom-service', 'event_type' => 'server.ready', 'aggregate_type' => 'server',
        'aggregate_id' => 'field-resource', 'aggregate_version' => 2]);
    DB::beginTransaction();
    app(ResourceDeletionGuard::class)->lock('server', 'field-resource');
    $child = $startBlocked($event, 'field');
    $known = claimReceipt();
    $known->update(['producer' => 'atom-service', 'event_type' => 'server.ready', 'aggregate_type' => 'server',
        'aggregate_id' => 'field-resource', 'aggregate_version' => 5, 'status' => InboxStatus::Completed]);
    DB::commit();
    $finish($child);
    claimCheck(DB::table('test_effects')->count() === 0 && $event->fresh()->status === InboxStatus::Completed,
        'field watermark bootstrap observes completed evidence after waiting for the lifecycle lock');
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($workers as $worker) {
        proc_terminate($worker);
        proc_close($worker);
    }
    DB::disconnect('mysql');
    $pdo->exec('DROP DATABASE `'.$database.'`');
}
