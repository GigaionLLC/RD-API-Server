<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const EXPECTED_DATABASE = 'rustdesk_api_performance';
const EXPECTED_GUARD = 'I_UNDERSTAND_THIS_IS_A_DISPOSABLE_PERFORMANCE_DATABASE';

if (getenv('PERF_DISPOSABLE_DATABASE') !== EXPECTED_GUARD) {
    fwrite(STDERR, "Refusing to seed without the disposable-performance-database guard.\n");
    exit(1);
}

$applicationRoot = getenv('PERF_APPLICATION_ROOT') ?: '/var/www/html';
if (! is_file($applicationRoot.'/vendor/autoload.php') || ! is_file($applicationRoot.'/bootstrap/app.php')) {
    fwrite(STDERR, "Laravel application files were not found at {$applicationRoot}.\n");
    exit(1);
}

chdir($applicationRoot);
require $applicationRoot.'/vendor/autoload.php';
$app = require $applicationRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$database = (string) DB::connection()->getDatabaseName();
if ($database !== EXPECTED_DATABASE || (string) config('database.default') !== 'mariadb') {
    fwrite(STDERR, "Refusing to seed database '{$database}'; expected disposable MariaDB schema '".EXPECTED_DATABASE."'.\n");
    exit(1);
}

$deviceCount = boundedInteger('PERF_DEVICE_COUNT', 30, 1, 50000);
$modifiedAt = boundedInteger('PERF_STRATEGY_MODIFIED_AT', 1700000000, 1, 2147483647);
$now = now();

DB::transaction(function () use ($deviceCount, $modifiedAt, $now): void {
    $strategyId = DB::table('strategies')->insertGetId([
        'name' => 'Performance heartbeat strategy',
        'enabled' => true,
        'is_default' => false,
        'options' => json_encode(['allow-only-conn-window-open' => 'Y'], JSON_THROW_ON_ERROR),
        'extra' => json_encode(['source' => 'performance-harness'], JSON_THROW_ON_ERROR),
        'modified_at' => $modifiedAt,
        'note' => 'Disposable performance-harness fixture',
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    $groupId = DB::table('device_groups')->insertGetId([
        'name' => 'Performance devices',
        'note' => 'Disposable performance-harness fixture',
        'is_default' => false,
        'created_at' => $now,
        'updated_at' => $now,
    ]);

    for ($start = 0; $start < $deviceCount; $start += 1000) {
        $rows = [];
        $end = min($start + 1000, $deviceCount);
        for ($index = $start; $index < $end; $index++) {
            $number = str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT);
            $rows[] = [
                'rustdesk_id' => 'perf-'.$number,
                'uuid' => 'perf-uuid-'.$number,
                'hostname' => 'perf-host-'.$number,
                'os' => 'linux',
                'version' => '1.4.9',
                'device_group_id' => $groupId,
                'strategy_id' => $strategyId,
                'is_online' => false,
                'conns' => 0,
                'approved' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('devices')->insert($rows);
    }
});

fwrite(STDOUT, json_encode([
    'schema_version' => 1,
    'database' => $database,
    'devices' => $deviceCount,
    'strategy_modified_at' => $modifiedAt,
    'strategy_pull_fraction' => (intdiv($deviceCount - 1, 20) + 1) / $deviceCount,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES).PHP_EOL);

function boundedInteger(string $name, int $fallback, int $minimum, int $maximum): int
{
    $raw = getenv($name);
    if ($raw === false || $raw === '') {
        return $fallback;
    }

    $value = filter_var($raw, FILTER_VALIDATE_INT);
    if ($value === false || $value < $minimum || $value > $maximum) {
        fwrite(STDERR, "{$name} must be an integer between {$minimum} and {$maximum}.\n");
        exit(1);
    }

    return $value;
}
