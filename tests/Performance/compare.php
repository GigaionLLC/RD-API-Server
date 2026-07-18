<?php

declare(strict_types=1);

$resultsDirectory = $argv[1] ?? '/results';
if (! is_dir($resultsDirectory)) {
    fwrite(STDERR, "Results directory '{$resultsDirectory}' does not exist.\n");
    exit(1);
}

$summaryFiles = glob(rtrim($resultsDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*.summary.json') ?: [];
if ($summaryFiles === []) {
    fwrite(STDERR, "No k6 summary files were found in '{$resultsDirectory}'.\n");
    exit(1);
}

$runs = [];
foreach ($summaryFiles as $summaryFile) {
    $summary = decodeJsonFile($summaryFile);
    if (($summary['schema_version'] ?? null) !== 1 || ! isset($summary['run'], $summary['k6']['metrics'])) {
        throw new RuntimeException("Unsupported summary schema in {$summaryFile}");
    }

    $configuration = $summary['run'];
    $metrics = $summary['k6']['metrics'];
    $label = (string) ($configuration['label'] ?? pathinfo($summaryFile, PATHINFO_FILENAME));
    $stats = readDockerStats(dirname($summaryFile).DIRECTORY_SEPARATOR.$label.'.stats.jsonl');
    addLimitUtilization($stats, is_array($configuration['resource_limits'] ?? null)
        ? $configuration['resource_limits']
        : []);
    $expectedRps = (float) ($configuration['expected_heartbeat_rps'] ?? 0);
    $achievedRps = is_numeric($configuration['achieved_heartbeat_rps'] ?? null)
        ? (float) $configuration['achieved_heartbeat_rps']
        : metric($metrics, 'heartbeat_requests', 'rate');
    $failureRate = metric($metrics, 'heartbeat_failures', 'rate');
    $wireMismatches = metric($metrics, 'heartbeat_wire_mismatches', 'count');
    $droppedIterations = metric($metrics, 'dropped_iterations', 'count');
    $failedThresholds = failedThresholds($metrics);
    $missingResourceSamples = array_keys(array_filter(
        $stats,
        static fn (array $role): bool => (int) ($role['samples'] ?? 0) === 0,
    ));
    $invalidReasons = [];
    $scheduleAchievement = $expectedRps > 0 ? ($achievedRps / $expectedRps) * 100 : 0;
    if ($failureRate > 0) {
        $invalidReasons[] = 'heartbeat_failures';
    }
    if ($wireMismatches > 0) {
        $invalidReasons[] = 'wire_mismatches';
    }
    if ($droppedIterations > 0) {
        $invalidReasons[] = 'dropped_iterations';
    }
    if ($failedThresholds !== []) {
        $invalidReasons[] = 'failed_thresholds';
    }
    if ($missingResourceSamples !== []) {
        $invalidReasons[] = 'missing_resource_samples';
    }
    if ($scheduleAchievement < 99) {
        $invalidReasons[] = 'schedule_achievement_below_99_percent';
    }

    $runs[] = [
        'label' => $label,
        'mode' => (string) ($configuration['mode'] ?? 'custom'),
        'trial' => (int) ($configuration['trial'] ?? 1),
        'total_trials' => (int) ($configuration['total_trials'] ?? 1),
        'order_seed' => (int) ($configuration['order_seed'] ?? 0),
        'runtime' => (string) ($configuration['runtime'] ?? 'unknown'),
        'image' => (string) ($configuration['image'] ?? 'unknown'),
        'image_id' => (string) ($configuration['image_id'] ?? 'unknown'),
        'oci_revision' => (string) ($configuration['oci_revision'] ?? 'unknown'),
        'application_fingerprint' => (string) ($configuration['application_fingerprint'] ?? 'unknown'),
        'gate_enforced' => (bool) ($configuration['gate_enforced'] ?? false),
        'latency_limits_ms' => is_array($configuration['latency_limits_ms'] ?? null)
            ? $configuration['latency_limits_ms']
            : [],
        'connection_profile' => (string) ($configuration['connection_profile'] ?? 'unknown'),
        'device_count' => (int) ($configuration['device_count'] ?? 0),
        'active_fraction' => (float) ($configuration['active_fraction'] ?? 0),
        'warmup_duration' => (string) ($configuration['warmup_duration'] ?? ''),
        'test_duration' => (string) ($configuration['test_duration'] ?? ''),
        'arrival_rate' => is_array($configuration['arrival_rate'] ?? null) ? $configuration['arrival_rate'] : [],
        'resource_limits' => is_array($configuration['resource_limits'] ?? null) ? $configuration['resource_limits'] : [],
        'expected_heartbeat_rps' => $expectedRps,
        'achieved_heartbeat_rps' => $achievedRps,
        'schedule_achievement_percent' => $scheduleAchievement,
        'valid' => $invalidReasons === [],
        'invalid_reasons' => $invalidReasons,
        'failed_thresholds' => $failedThresholds,
        'missing_resource_samples' => $missingResourceSamples,
        'heartbeat_p50_ms' => metric($metrics, 'heartbeat_duration', 'med'),
        'heartbeat_p95_ms' => metric($metrics, 'heartbeat_duration', 'p(95)'),
        'heartbeat_p99_ms' => metric($metrics, 'heartbeat_duration', 'p(99)'),
        'heartbeat_failure_rate' => $failureRate,
        'dropped_iterations' => $droppedIterations,
        'wire_mismatches' => $wireMismatches,
        'requests' => metric($metrics, 'heartbeat_requests', 'count'),
        'docker' => $stats,
    ];
}

usort($runs, static fn (array $left, array $right): int => [
    $left['trial'], $left['connection_profile'], $left['runtime'],
] <=> [
    $right['trial'], $right['connection_profile'], $right['runtime'],
]);

$pairs = [];
foreach ($runs as $run) {
    $key = $run['mode'].'|'.$run['trial'].'|'.$run['connection_profile'];
    $pairs[$key][$run['runtime']] = $run;
}

$comparisons = [];
$promotionMatrixComplete = fullSteadyMatrixComplete($runs);
foreach ($pairs as $key => $pair) {
    if (! isset($pair['apache'], $pair['nginx'])) {
        continue;
    }

    $apache = $pair['apache'];
    $nginx = $pair['nginx'];
    $throughputChange = percentChange(
        (float) $apache['achieved_heartbeat_rps'],
        (float) $nginx['achieved_heartbeat_rps'],
    );
    $memoryReduction = percentReduction(
        (float) ($apache['docker']['app']['peak_memory_bytes'] ?? 0),
        (float) ($nginx['docker']['app']['peak_memory_bytes'] ?? 0),
    );
    $mismatchedConfiguration = mismatchedConfiguration($apache, $nginx);
    $fingerprintsKnown = validFingerprint((string) $apache['application_fingerprint'])
        && validFingerprint((string) $nginx['application_fingerprint']);
    $fingerprintsMatch = $fingerprintsKnown && hash_equals(
        (string) $apache['application_fingerprint'],
        (string) $nginx['application_fingerprint'],
    );
    $validPair = $apache['valid'] && $nginx['valid']
        && $mismatchedConfiguration === []
        && $fingerprintsMatch;
    $promotionComparisonEligible = $validPair
        && $promotionMatrixComplete
        && isFullSteadyConfiguration($apache)
        && isFullSteadyConfiguration($nginx)
        && $apache['gate_enforced'] === true
        && $nginx['gate_enforced'] === true;
    $pairInvalidReasons = [];
    if (! $apache['valid'] || ! $nginx['valid']) {
        $pairInvalidReasons[] = 'invalid_run';
    }
    if ($mismatchedConfiguration !== []) {
        $pairInvalidReasons[] = 'mismatched_configuration';
    }
    if (! $fingerprintsKnown) {
        $pairInvalidReasons[] = 'unknown_application_fingerprint';
    } elseif (! $fingerprintsMatch) {
        $pairInvalidReasons[] = 'application_fingerprint_mismatch';
    }

    $comparisons[] = [
        'key' => $key,
        'mode' => $apache['mode'],
        'trial' => $apache['trial'],
        'connection_profile' => $apache['connection_profile'],
        'nginx_vs_apache' => [
            'throughput_change_percent' => $throughputChange,
            'p95_latency_change_percent' => percentChange(
                (float) $apache['heartbeat_p95_ms'],
                (float) $nginx['heartbeat_p95_ms'],
            ),
            'p99_latency_change_percent' => percentChange(
                (float) $apache['heartbeat_p99_ms'],
                (float) $nginx['heartbeat_p99_ms'],
            ),
            'peak_app_memory_reduction_percent' => $memoryReduction,
            'valid_pair' => $validPair,
            'pair_invalid_reasons' => $pairInvalidReasons,
            'mismatched_configuration' => $mismatchedConfiguration,
            'application_fingerprint_matched' => $fingerprintsMatch,
            'promotion_matrix_complete' => $promotionMatrixComplete,
            'promotion_comparison_eligible' => $promotionComparisonEligible,
            'observed_material_win' => $promotionComparisonEligible && (
                ($throughputChange !== null && $throughputChange >= 20)
                || ($memoryReduction !== null && $memoryReduction >= 25)
            ),
        ],
    ];
}

$report = [
    'schema_version' => 1,
    'generated_at' => gmdate(DATE_ATOM),
    'scope' => 'Direct-container heartbeat comparison only; not by itself a release-promotion decision.',
    'promotion_matrix_complete' => $promotionMatrixComplete,
    'runs' => $runs,
    'comparisons' => $comparisons,
];

$encoded = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
$output = rtrim($resultsDirectory, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'comparison.json';
if (file_put_contents($output, $encoded) === false) {
    throw new RuntimeException("Unable to write {$output}");
}

fwrite(STDOUT, $encoded);

/**
 * @return array<string, mixed>
 */
function decodeJsonFile(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Unable to read {$path}");
    }

    $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("Expected a JSON object in {$path}");
    }

    return $decoded;
}

/**
 * @param  array<string, mixed>  $metrics
 */
function metric(array $metrics, string $metric, string $value): float
{
    $candidate = $metrics[$metric]['values'][$value] ?? 0;

    return is_numeric($candidate) ? (float) $candidate : 0;
}

/**
 * @param  array<string, mixed>  $metrics
 * @return list<string>
 */
function failedThresholds(array $metrics): array
{
    $failed = [];
    foreach ($metrics as $metricName => $metric) {
        if (! is_array($metric) || ! is_array($metric['thresholds'] ?? null)) {
            continue;
        }
        foreach ($metric['thresholds'] as $expression => $state) {
            if (! is_array($state) || ($state['ok'] ?? false) !== true) {
                $failed[] = $metricName.':'.$expression;
            }
        }
    }

    sort($failed);

    return $failed;
}

/**
 * @return array<string, array<string, float|int>>
 */
function readDockerStats(string $path): array
{
    $result = [
        'app' => ['samples' => 0, 'peak_cpu_percent' => 0.0, 'peak_memory_bytes' => 0],
        'database' => ['samples' => 0, 'peak_cpu_percent' => 0.0, 'peak_memory_bytes' => 0],
        'load_generator' => ['samples' => 0, 'peak_cpu_percent' => 0.0, 'peak_memory_bytes' => 0],
    ];
    if (! is_file($path)) {
        return $result;
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        return $result;
    }

    while (($line = fgets($handle)) !== false) {
        try {
            $sample = json_decode(trim($line), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            continue;
        }
        if (! is_array($sample)) {
            continue;
        }

        $name = (string) ($sample['Name'] ?? '');
        $role = str_contains($name, '-app-')
            ? 'app'
            : (str_contains($name, '-db-')
                ? 'database'
                : (str_contains($name, '-k6-') ? 'load_generator' : null));
        if ($role === null) {
            continue;
        }

        $cpu = (float) rtrim((string) ($sample['CPUPerc'] ?? '0'), "% \t\n\r\0\x0B");
        $memory = parseBytes(trim(explode('/', (string) ($sample['MemUsage'] ?? '0B'))[0]));
        $result[$role]['samples']++;
        $result[$role]['peak_cpu_percent'] = max($result[$role]['peak_cpu_percent'], $cpu);
        $result[$role]['peak_memory_bytes'] = max($result[$role]['peak_memory_bytes'], $memory);
    }
    fclose($handle);

    return $result;
}

/**
 * @param  array<string, array<string, float|int>>  $stats
 * @param  array<string, mixed>  $limits
 */
function addLimitUtilization(array &$stats, array $limits): void
{
    $roleLimits = [
        'app' => ['app_cpus', 'app_memory'],
        'database' => ['database_cpus', 'database_memory'],
        'load_generator' => ['load_generator_cpus', 'load_generator_memory'],
    ];

    foreach ($roleLimits as $role => [$cpuKey, $memoryKey]) {
        $cpus = is_numeric($limits[$cpuKey] ?? null) ? (float) $limits[$cpuKey] : 0;
        $memoryLimit = parseBytes((string) ($limits[$memoryKey] ?? '0B'));
        $stats[$role]['peak_cpu_limit_utilization_percent'] = $cpus > 0
            ? (float) $stats[$role]['peak_cpu_percent'] / $cpus
            : 0.0;
        $stats[$role]['peak_memory_limit_utilization_percent'] = $memoryLimit > 0
            ? ((int) $stats[$role]['peak_memory_bytes'] / $memoryLimit) * 100
            : 0.0;
    }
}

function parseBytes(string $value): int
{
    if (! preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([kmgt]?i?b|[kmgt])$/i', $value, $matches)) {
        return 0;
    }

    $unit = strtolower($matches[2]);
    $powers = [
        'b' => 0,
        'k' => 1, 'kb' => 1, 'kib' => 1,
        'm' => 2, 'mb' => 2, 'mib' => 2,
        'g' => 3, 'gb' => 3, 'gib' => 3,
        't' => 4, 'tb' => 4, 'tib' => 4,
    ];
    $base = str_contains($unit, 'i') || ! str_ends_with($unit, 'b') ? 1024 : 1000;

    return (int) round((float) $matches[1] * ($base ** ($powers[$unit] ?? 0)));
}

function percentChange(float $baseline, float $candidate): ?float
{
    return $baseline > 0 ? (($candidate / $baseline) - 1) * 100 : null;
}

function percentReduction(float $baseline, float $candidate): ?float
{
    return $baseline > 0 ? (1 - ($candidate / $baseline)) * 100 : null;
}

/**
 * @param  array<string, mixed>  $apache
 * @param  array<string, mixed>  $nginx
 * @return list<string>
 */
function mismatchedConfiguration(array $apache, array $nginx): array
{
    $fields = [
        'mode', 'trial', 'total_trials', 'order_seed', 'connection_profile', 'device_count',
        'active_fraction', 'warmup_duration', 'test_duration', 'expected_heartbeat_rps',
        'arrival_rate', 'latency_limits_ms', 'resource_limits',
        'application_fingerprint', 'gate_enforced',
    ];

    return array_values(array_filter(
        $fields,
        static fn (string $field): bool => ($apache[$field] ?? null) !== ($nginx[$field] ?? null),
    ));
}

/**
 * Only the complete three-trial steady preset may emit a material-win observation. Shortened or
 * otherwise overridden steady runs remain useful tuning evidence without looking release-ready.
 *
 * @param  array<string, mixed>  $run
 */
function isFullSteadyConfiguration(array $run): bool
{
    $arrival = is_array($run['arrival_rate'] ?? null) ? $run['arrival_rate'] : [];
    $limits = is_array($run['latency_limits_ms'] ?? null) ? $run['latency_limits_ms'] : [];
    $resources = is_array($run['resource_limits'] ?? null) ? $run['resource_limits'] : [];

    return ($run['mode'] ?? null) === 'steady'
        && (int) ($run['total_trials'] ?? 0) === 3
        && (int) ($run['device_count'] ?? 0) === 15000
        && abs((float) ($run['active_fraction'] ?? -1) - 0.2) < 0.000001
        && ($run['warmup_duration'] ?? null) === '2m'
        && ($run['test_duration'] ?? null) === '30m'
        && abs((float) ($run['expected_heartbeat_rps'] ?? 0) - 1800.0) < 0.000001
        && $arrival === [
            'time_unit' => '15s',
            'active_iterations' => 15000,
            'idle_iterations' => 12000,
            'pre_allocated_vus' => 1024,
            'max_vus' => 4096,
        ]
        && $limits === ['p95' => 250, 'p99' => 750]
        && $resources === [
            'app_cpus' => '2.0',
            'app_memory' => '1024m',
            'database_cpus' => '2.0',
            'database_memory' => '2048m',
            'load_generator_cpus' => '4.0',
            'load_generator_memory' => '4096m',
        ];
}

/**
 * Require the exact three-trial x two-runtime x two-profile matrix before any pair may be called
 * promotion-eligible. Every run must be valid, payload-identical, and backed by immutable image
 * references; a partial results directory or a mutable local tag can never satisfy this check.
 *
 * @param  list<array<string, mixed>>  $runs
 */
function fullSteadyMatrixComplete(array $runs): bool
{
    if (count($runs) !== 12) {
        return false;
    }

    $expected = [];
    foreach (range(1, 3) as $trial) {
        foreach (['apache', 'nginx'] as $runtime) {
            foreach (['keepalive', 'no-reuse'] as $profile) {
                $expected["{$trial}|{$runtime}|{$profile}"] = true;
            }
        }
    }

    $seen = [];
    $fingerprint = null;
    $runtimeImages = [];
    foreach ($runs as $run) {
        if (! ($run['valid'] ?? false) || ! ($run['gate_enforced'] ?? false)
            || ! isFullSteadyConfiguration($run)
            || ! validFingerprint((string) ($run['application_fingerprint'] ?? ''))
            || preg_match('/@sha256:[0-9a-f]{64}$/', (string) ($run['image'] ?? '')) !== 1
            || preg_match('/^sha256:[0-9a-f]{64}$/', (string) ($run['image_id'] ?? '')) !== 1) {
            return false;
        }

        $key = ((int) $run['trial']).'|'.$run['runtime'].'|'.$run['connection_profile'];
        if (! isset($expected[$key]) || isset($seen[$key])) {
            return false;
        }
        $seen[$key] = true;

        $runFingerprint = (string) $run['application_fingerprint'];
        $fingerprint ??= $runFingerprint;
        if (! hash_equals($fingerprint, $runFingerprint)) {
            return false;
        }

        $runtime = (string) $run['runtime'];
        $imageId = (string) $run['image_id'];
        $runtimeImages[$runtime] ??= $imageId;
        if (! hash_equals($runtimeImages[$runtime], $imageId)) {
            return false;
        }
    }

    return count($seen) === count($expected)
        && isset($runtimeImages['apache'], $runtimeImages['nginx'])
        && ! hash_equals($runtimeImages['apache'], $runtimeImages['nginx']);
}

function validFingerprint(string $fingerprint): bool
{
    return preg_match('/^sha256:[0-9a-f]{64}$/', $fingerprint) === 1;
}
