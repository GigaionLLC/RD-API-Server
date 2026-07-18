import http from 'k6/http';
import { check } from 'k6';
import exec from 'k6/execution';
import { Counter, Rate, Trend } from 'k6/metrics';

const targetUrl = (__ENV.PERF_TARGET_URL || 'http://app').replace(/\/$/, '');
const deviceCount = integerSetting('PERF_DEVICE_COUNT', 30, 1, 50000);
const activeFraction = numberSetting('PERF_ACTIVE_FRACTION', 0.2, 0, 1);
const warmupDuration = durationSetting('PERF_WARMUP_DURATION', '5s');
const testDuration = durationSetting('PERF_TEST_DURATION', '20s');
const connectionProfile = enumSetting('PERF_CONNECTION_PROFILE', 'keepalive', ['keepalive', 'no-reuse']);
const runLabel = safeLabel(__ENV.PERF_RUN_LABEL || 'local-smoke');
const strategyModifiedAt = integerSetting('PERF_STRATEGY_MODIFIED_AT', 1700000000, 1, 2147483647);
const enforceGate = booleanSetting('PERF_ENFORCE_GATE', false);
const p95LimitMs = integerSetting('PERF_P95_LIMIT_MS', 250, 1, 600000);
const p99LimitMs = integerSetting('PERF_P99_LIMIT_MS', 750, 1, 600000);

const activeDevices = Math.round(deviceCount * activeFraction);
const idleDevices = deviceCount - activeDevices;
const strategyPullDevices = Math.ceil(deviceCount / 20);
const expectedRps = (idleDevices / 15) + (activeDevices / 3);
const minimumScenarioVus = activeDevices > 0 && idleDevices > 0 ? 2 : 1;
const preAllocatedVus = integerSetting('PERF_PREALLOCATED_VUS', 20, minimumScenarioVus, 20000);
const maxVus = integerSetting('PERF_MAX_VUS', 100, preAllocatedVus, 50000);
const activeRatePerFifteenSeconds = activeDevices * 5;
const idleRatePerFifteenSeconds = idleDevices;
const preAllocatedSplit = splitCapacity(preAllocatedVus);
const maximumSplit = splitCapacity(maxVus);

const heartbeatDuration = new Trend('heartbeat_duration', true);
const heartbeatFailures = new Rate('heartbeat_failures');
const heartbeatRequests = new Counter('heartbeat_requests');
const wireMismatches = new Counter('heartbeat_wire_mismatches');

const thresholds = {
    checks: ['rate==1'],
    dropped_iterations: ['count==0'],
    heartbeat_failures: ['rate==0'],
    heartbeat_wire_mismatches: ['count==0'],
};

if (enforceGate) {
    thresholds.heartbeat_duration = [
        `p(95)<${p95LimitMs}`,
        `p(99)<${p99LimitMs}`,
    ];
}

export const options = {
    discardResponseBodies: false,
    noConnectionReuse: connectionProfile === 'no-reuse',
    noVUConnectionReuse: connectionProfile === 'no-reuse',
    summaryTrendStats: ['avg', 'min', 'med', 'p(90)', 'p(95)', 'p(99)', 'max'],
    systemTags: ['method', 'name', 'scenario', 'status'],
    thresholds,
    scenarios: buildScenarios(),
};

export function setup() {
    const response = http.get(`${targetUrl}/up`, {
        tags: { endpoint: 'health', phase: 'setup', profile: connectionProfile },
        timeout: '10s',
    });

    if (response.status !== 200) {
        throw new Error(`Target readiness failed with HTTP ${response.status}`);
    }
}

export function warmupActiveHeartbeat() {
    runHeartbeat('warmup', true, false, 0, activeDevices);
}

export function warmupIdleHeartbeat() {
    runHeartbeat('warmup', false, false, activeDevices, idleDevices);
}

export function measuredActiveHeartbeat() {
    runHeartbeat('measure', true, true, 0, activeDevices);
}

export function measuredIdleHeartbeat() {
    runHeartbeat('measure', false, true, activeDevices, idleDevices);
}

function runHeartbeat(phase, active, measured, deviceOffset, subsetCount) {
    // Each scenario gets its own monotonic iteration counter. Round-robin over its identity
    // subset so rate=(devices*5)/15s revisits every active device at 3s, while rate=devices/15s
    // revisits every idle device at 15s, without allocating one JavaScript isolate per device.
    const deviceIndex = deviceOffset + (exec.scenario.iterationInTest % subsetCount);

    const expectsStrategyPull = deviceIndex % 20 === 0;
    const requestBody = JSON.stringify({
        id: deviceId(deviceIndex),
        uuid: deviceUuid(deviceIndex),
        ver: 1000000,
        conns: active ? [deviceIndex + 1] : [],
        modified_at: expectsStrategyPull ? 0 : strategyModifiedAt,
    });
    const requestTags = {
        endpoint: 'heartbeat',
        phase,
        profile: connectionProfile,
        activity: active ? 'active' : 'idle',
        strategy: expectsStrategyPull ? 'pull' : 'unchanged',
    };
    const response = http.post(`${targetUrl}/api/heartbeat`, requestBody, {
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'User-Agent': 'RD-API-Server-performance-harness/1',
        },
        tags: requestTags,
        timeout: '10s',
    });
    const validWireResponse = responseMatchesContract(response, expectsStrategyPull);
    const valid = check(response, {
        'heartbeat returns HTTP 200': (value) => value.status === 200,
        'heartbeat response matches the client contract': () => validWireResponse,
    }, requestTags);

    if (measured) {
        heartbeatRequests.add(1, requestTags);
        heartbeatDuration.add(response.timings.duration, requestTags);
        heartbeatFailures.add(!valid, requestTags);
        if (!validWireResponse) {
            wireMismatches.add(1, requestTags);
        }
    }
}

function buildScenarios() {
    const scenarios = {};
    addCadenceScenarios(scenarios, 'active', activeRatePerFifteenSeconds, 'ActiveHeartbeat');
    addCadenceScenarios(scenarios, 'idle', idleRatePerFifteenSeconds, 'IdleHeartbeat');

    return scenarios;
}

function addCadenceScenarios(scenarios, activity, ratePerFifteenSeconds, functionSuffix) {
    if (ratePerFifteenSeconds === 0) {
        return;
    }

    const capacity = scenarioCapacity(activity);
    scenarios[`warmup_${activity}`] = {
        executor: 'constant-arrival-rate',
        exec: `warmup${functionSuffix}`,
        rate: ratePerFifteenSeconds,
        timeUnit: '15s',
        duration: warmupDuration,
        gracefulStop: '0s',
        preAllocatedVUs: capacity.preAllocated,
        maxVUs: capacity.maximum,
        tags: { phase: 'warmup', profile: connectionProfile, activity },
    };
    scenarios[`measurement_${activity}`] = {
        executor: 'constant-arrival-rate',
        exec: `measured${functionSuffix}`,
        rate: ratePerFifteenSeconds,
        timeUnit: '15s',
        startTime: warmupDuration,
        duration: testDuration,
        gracefulStop: '30s',
        preAllocatedVUs: capacity.preAllocated,
        maxVUs: capacity.maximum,
        tags: { phase: 'measure', profile: connectionProfile, activity },
    };
}

function scenarioCapacity(activity) {
    return {
        preAllocated: preAllocatedSplit[activity],
        maximum: maximumSplit[activity],
    };
}

function splitCapacity(total) {
    if (activeDevices === 0) {
        return { active: 0, idle: total };
    }
    if (idleDevices === 0) {
        return { active: total, idle: 0 };
    }

    const activeShare = activeRatePerFifteenSeconds
        / (activeRatePerFifteenSeconds + idleRatePerFifteenSeconds);
    const activeCapacity = Math.max(1, Math.min(total - 1, Math.round(total * activeShare)));

    return { active: activeCapacity, idle: total - activeCapacity };
}

function responseMatchesContract(response, expectsStrategyPull) {
    if (response.status !== 200) {
        return false;
    }

    let body;
    try {
        body = response.json();
    } catch (_) {
        return false;
    }

    if (expectsStrategyPull) {
        return body !== null
            && typeof body === 'object'
            && body.modified_at === strategyModifiedAt
            && body.strategy !== null
            && typeof body.strategy === 'object'
            && body.strategy.config_options !== null
            && typeof body.strategy.config_options === 'object'
            && !Array.isArray(body.strategy.config_options)
            && body.strategy.extra !== null
            && typeof body.strategy.extra === 'object'
            && !Array.isArray(body.strategy.extra);
    }

    return body !== null
        && typeof body === 'object'
        && !Array.isArray(body)
        && Object.keys(body).length === 0;
}

function deviceId(index) {
    return `perf-${String(index + 1).padStart(8, '0')}`;
}

function deviceUuid(index) {
    return `perf-uuid-${String(index + 1).padStart(8, '0')}`;
}

export function handleSummary(data) {
    const summary = {
        schema_version: 1,
        generated_at: new Date().toISOString(),
        run: {
            label: runLabel,
            mode: __ENV.PERF_MODE || 'custom',
            trial: integerSetting('PERF_TRIAL', 1, 1, 100),
            total_trials: integerSetting('PERF_TOTAL_TRIALS', 1, 1, 100),
            order_seed: integerSetting('PERF_ORDER_SEED', 20260718, 0, 2147483647),
            runtime: __ENV.PERF_RUNTIME || 'unknown',
            image: __ENV.PERF_RUNTIME_IMAGE || 'unknown',
            image_id: __ENV.PERF_RUNTIME_IMAGE_ID || 'unknown',
            oci_revision: __ENV.PERF_OCI_REVISION || 'unknown',
            application_fingerprint: __ENV.PERF_APPLICATION_FINGERPRINT || 'unknown',
            connection_profile: connectionProfile,
            device_count: deviceCount,
            active_fraction: activeFraction,
            effective_active_fraction: activeDevices / deviceCount,
            active_devices: activeDevices,
            idle_devices: idleDevices,
            cadence_seconds: { active: 3, idle: 15 },
            strategy_pull_fraction: strategyPullDevices / deviceCount,
            strategy_pull_devices: strategyPullDevices,
            warmup_duration: warmupDuration,
            test_duration: testDuration,
            expected_heartbeat_rps: expectedRps,
            achieved_heartbeat_rps: metricValue(data.metrics, 'heartbeat_requests', 'count')
                / durationSeconds(testDuration),
            arrival_rate: {
                time_unit: '15s',
                active_iterations: activeRatePerFifteenSeconds,
                idle_iterations: idleRatePerFifteenSeconds,
                pre_allocated_vus: preAllocatedVus,
                max_vus: maxVus,
            },
            gate_enforced: enforceGate,
            latency_limits_ms: {
                p95: p95LimitMs,
                p99: p99LimitMs,
            },
            resource_limits: {
                app_cpus: __ENV.PERF_APP_CPUS || '2.0',
                app_memory: __ENV.PERF_APP_MEMORY || '1024m',
                database_cpus: __ENV.PERF_DB_CPUS || '2.0',
                database_memory: __ENV.PERF_DB_MEMORY || '2048m',
                load_generator_cpus: __ENV.PERF_K6_CPUS || '2.0',
                load_generator_memory: __ENV.PERF_K6_MEMORY || '1024m',
            },
        },
        k6: data,
    };

    return {
        [`/results/${runLabel}.summary.json`]: JSON.stringify(summary, null, 2),
        stdout: conciseSummary(summary),
    };
}

function conciseSummary(summary) {
    const metrics = summary.k6.metrics;
    const achieved = summary.run.achieved_heartbeat_rps;
    const p95 = metricValue(metrics, 'heartbeat_duration', 'p(95)');
    const p99 = metricValue(metrics, 'heartbeat_duration', 'p(99)');
    const failures = metricValue(metrics, 'heartbeat_failures', 'rate');
    const dropped = metricValue(metrics, 'dropped_iterations', 'count');
    const ratio = summary.run.expected_heartbeat_rps > 0
        ? (achieved / summary.run.expected_heartbeat_rps) * 100
        : 0;

    return [
        '',
        `run=${summary.run.label}`,
        `profile=${summary.run.connection_profile}`,
        `expected_rps=${summary.run.expected_heartbeat_rps.toFixed(2)}`,
        `achieved_rps=${achieved.toFixed(2)}`,
        `schedule_achievement=${ratio.toFixed(2)}%`,
        `heartbeat_p95_ms=${p95.toFixed(2)}`,
        `heartbeat_p99_ms=${p99.toFixed(2)}`,
        `heartbeat_failure_rate=${failures.toFixed(6)}`,
        `dropped_iterations=${dropped.toFixed(0)}`,
        '',
    ].join('\n');
}

function metricValue(metrics, metric, value) {
    const entry = metrics[metric];
    return entry && entry.values && Number.isFinite(entry.values[value]) ? entry.values[value] : 0;
}

function integerSetting(name, fallback, minimum, maximum) {
    const raw = __ENV[name];
    if (raw === undefined || raw === '') {
        return fallback;
    }

    const value = Number(raw);
    if (!Number.isInteger(value) || value < minimum || value > maximum) {
        throw new Error(`${name} must be an integer between ${minimum} and ${maximum}`);
    }

    return value;
}

function numberSetting(name, fallback, minimum, maximum) {
    const raw = __ENV[name];
    if (raw === undefined || raw === '') {
        return fallback;
    }

    const value = Number(raw);
    if (!Number.isFinite(value) || value < minimum || value > maximum) {
        throw new Error(`${name} must be between ${minimum} and ${maximum}`);
    }

    return value;
}

function booleanSetting(name, fallback) {
    const raw = (__ENV[name] || '').toLowerCase();
    if (raw === '') {
        return fallback;
    }
    if (raw === 'true' || raw === '1') {
        return true;
    }
    if (raw === 'false' || raw === '0') {
        return false;
    }

    throw new Error(`${name} must be true, false, 1, or 0`);
}

function enumSetting(name, fallback, allowed) {
    const value = __ENV[name] || fallback;
    if (!allowed.includes(value)) {
        throw new Error(`${name} must be one of: ${allowed.join(', ')}`);
    }

    return value;
}

function durationSetting(name, fallback) {
    const value = __ENV[name] || fallback;
    if (!/^\d+(ms|s|m|h)$/.test(value)) {
        throw new Error(`${name} must be a k6 duration such as 20s or 30m`);
    }

    return value;
}

function durationSeconds(value) {
    const match = /^(\d+)(ms|s|m|h)$/.exec(value);
    if (!match) {
        throw new Error(`Unsupported duration: ${value}`);
    }

    const amount = Number(match[1]);
    const multiplier = { ms: 0.001, s: 1, m: 60, h: 3600 }[match[2]];
    return amount * multiplier;
}

function safeLabel(value) {
    if (!/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,127}$/.test(value)) {
        throw new Error('PERF_RUN_LABEL contains unsupported characters');
    }

    return value;
}
