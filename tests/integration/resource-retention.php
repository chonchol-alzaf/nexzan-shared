<?php

// Uses only a disposable MySQL socket, never the application's database.
// php resource-retention.php /absolute/path/to/gateway-service-api /tmp/nexzan-messaging-integration.RUN/mysql.sock
use App\Jobs\RabbitMessageHandleJob;
use App\Models\Server;
use App\Models\Site;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Nexzan\Shared\Enums\InboxStatus;
use Nexzan\Shared\Infrastructure\InboxEventProcessor;
use Nexzan\Shared\Models\InboxEvent;
use Nexzan\Shared\Models\OutboxEvent;

$service = realpath($argv[1] ?? '') ?: throw new RuntimeException('Gateway service path required');
$socket = $argv[2] ?? '';
if (! str_starts_with($socket, '/tmp/nexzan-messaging-integration.')) {
    throw new RuntimeException('Refusing a non-test MySQL socket');
}
$database = $argv[3] ?? 'nxret_'.getmypid().'_'.time();
if (! preg_match('/^nxret_[0-9_]+$/', $database)) {
    throw new RuntimeException('Invalid test database');
}
putenv('APP_CONFIG_CACHE='.dirname($socket).'/absent-config.php');
putenv('APP_ENV=testing');
require $service.'/vendor/autoload.php';
$app = require $service.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): void {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});
config([
    'database.default' => 'mysql',
    'database.connections.mysql' => ['driver' => 'mysql', 'unix_socket' => $socket,
        'database' => $database, 'username' => 'root', 'password' => '', 'prefix' => '',
        'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'strict' => true],
    'cache.default' => 'array', 'logging.default' => 'stderr',
]);
DB::purge('mysql');
DB::setDefaultConnection('mysql');
Queue::fake();

function consumeRetentionEvent(string $id): void
{
    (new RabbitMessageHandleJob($id))->handle(app(InboxEventProcessor::class));
}

if (isset($argv[4])) {
    consumeRetentionEvent($argv[4]);
    exit(0);
}

function retentionCheck(bool $condition, string $label): void
{
    if (! $condition) {
        throw new RuntimeException('FAIL: '.$label);
    }
    echo 'PASS: '.$label.PHP_EOL;
}

function retentionInbox(string $event, array $resource, string $aggregateId): InboxEvent
{
    $type = explode('.', $event)[0];

    return InboxEvent::create([
        'event_id' => (string) Str::ulid(), 'event_type' => $event,
        'exchange' => $type.'_exchange', 'routing_key' => $event,
        'queue_name' => 'retention.'.$type, 'producer' => 'retention-test',
        'aggregate_type' => $type, 'aggregate_id' => $aggregateId, 'status' => InboxStatus::Pending,
        'payload' => ['event' => $event, 'occurred_at' => now()->toIso8601String(), 'resource' => $resource],
    ]);
}

$pdo = new PDO('mysql:unix_socket='.$socket, 'root', '');
$pdo->exec('CREATE DATABASE `'.$database.'`');
$workers = [];
try {
    foreach (glob($service.'/vendor/nexzan/shared/database/migrations/*.php') as $migration) {
        (require $migration)->up();
    }
    foreach (['2024_10_14_124512_create_servers_table.php', '2024_12_02_211805_create_sites_table.php',
        '2026_09_10_000001_rename_site_domain_to_site_name.php',
        '2026_09_10_000002_add_soft_deletes_to_resource_projections.php'] as $migration) {
        (require $service.'/database/migrations/'.$migration)->up();
    }
    $serverId = (string) Str::ulid();
    DB::table('servers')->insert(['id' => $serverId, 'team_id' => 1001, 'project_id' => (string) Str::ulid(),
        'name' => 'Retention test', 'port' => 22, 'provider' => 'custom', 'status' => 'ready']);
    $siteData = fn (string $id) => ['id' => $id, 'server_id' => $serverId,
        'team_id' => 1001, 'site_name' => 'Late site', 'status' => 'ready'];

    // Wait for an actual InnoDB lock wait, rather than assuming startup timing.
    $runBlocked = function (InboxEvent $event, string $label) use ($pdo, $service, $socket, $database, &$workers): void {
        $log = dirname($socket).'/'.$event->id.'.log';
        $worker = proc_open([PHP_BINARY, __FILE__, $service, $socket, $database, $event->id],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);
        if (! is_resource($worker)) {
            throw new RuntimeException('Cannot start test worker');
        }
        $workers[] = $worker;
        $deadline = microtime(true) + 10;
        $blocked = false;
        do {
            if ((int) $pdo->query('SELECT COUNT(*) FROM performance_schema.data_lock_waits')->fetchColumn() > 0) {
                $blocked = true;
                break;
            }
            usleep(20000);
        } while (microtime(true) < $deadline);
        retentionCheck($blocked, $label.' waits for concurrent deletion');
        DB::commit();
        retentionCheck(proc_close($worker) === 0, $label.' worker succeeds: '.$log);
        array_pop($workers);
        retentionCheck($event->fresh()->status === InboxStatus::Completed, $label.' Inbox completed');
    };

    // The parent is active and the child never existed locally. Its deletion
    // receipt must become visible after waiting for the concurrent child delete.
    $siteId = (string) Str::uuid();
    $delete = retentionInbox('site.delete', ['site_id' => $siteId], $siteId);
    $ready = retentionInbox('site.ready', ['site' => $siteData($siteId)], $siteId);
    DB::beginTransaction();
    consumeRetentionEvent($delete->id);
    $runBlocked($ready, 'Late site.ready');
    retentionCheck(! Site::withTrashed()->whereKey($siteId)->exists(), 'Concurrent delete-before-create leaves no site');

    // Source cleanup locks the server row without an Inbox lifecycle mutex.
    // A child event must observe that deletion after the source commits.
    $otherSiteId = (string) Str::uuid();
    $lateChild = retentionInbox('site.ready', ['site' => $siteData($otherSiteId)], $otherSiteId);
    DB::beginTransaction();
    Server::whereKey($serverId)->update(['deleted_at' => now()->subMonths(7)]);
    OutboxEvent::record('server.deleted', 'server_exchange', ['server_id' => $serverId], 'server', $serverId);
    $runBlocked($lateChild, 'Child of a source-deleted server');
    retentionCheck(! Site::withTrashed()->whereKey($otherSiteId)->exists(), 'Concurrent source deletion prevents late child');

    $kernel = $app->make(Kernel::class);
    retentionCheck($kernel->call('resources:purge-deleted') === 0, 'Purge command succeeds on MySQL');
    retentionCheck(! Server::withTrashed()->whereKey($serverId)->exists(), 'Seven-month-old server is permanently deleted');
    $afterPurge = retentionInbox('site.ready', ['site' => $siteData($otherSiteId)], $otherSiteId);
    consumeRetentionEvent($afterPurge->id);
    retentionCheck(! Site::withTrashed()->whereKey($otherSiteId)->exists(), 'Outbox deletion receipt protects after purge');
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
