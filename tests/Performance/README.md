# Apache vs Nginx heartbeat performance harness

This harness compares the published Apache/mod_php runtime with a local or registry-hosted
Nginx/PHP-FPM candidate. It is deliberately separate from the normal development database and
application stack:

- each runtime/profile/trial pair starts with fresh app processes and a fresh MariaDB
  11.8.8/InnoDB database on `tmpfs`;
- no service publishes a host port;
- the dataset creator refuses any schema except `rustdesk_api_performance` unless the Compose-only
  disposable-database guard is present;
- both candidates receive the same deterministic devices and Strategy fixture;
- candidates run sequentially under the same fixed CPU, memory, and PID limits;
- k6 is pinned to the multi-architecture image
  `grafana/k6:1.4.2@sha256:3656673de3f30424e8ebcfa46acd9558d83b6a43612d0f668ffeac953950c6c7`.

The workload uses bounded constant-arrival-rate workers rather than allocating one JavaScript
isolate per device. Over each 15-second time unit it schedules one iteration per idle device and
five per active device. Each scenario's monotonic iteration counter round-robins its own seeded
identity range, so an identity recurs at the real 15-second idle or three-second active cadence
while the aggregate request stream stays evenly scheduled. Five percent of identities request the
Strategy body; the other 95% send its current timestamp and must receive `{}`. Every response is
checked against that wire contract.

The open arrival model does not hide saturation by delaying the schedule. If the bounded worker
pool cannot start an iteration on time, k6 records `dropped_iterations`, and every preset treats
even one drop as a failed gate. `PERF_PREALLOCATED_VUS` and `PERF_MAX_VUS` size that pool without
making memory usage proportional to the simulated fleet.

Measurement stops scheduling at the configured duration and allows up to 30 seconds for requests
already in flight to finish. Requests still use a ten-second HTTP timeout. The comparison also
invalidates a run below 99% schedule attainment, so a truncated or incomplete timed phase cannot
look like a throughput improvement.

## Quick smoke comparison

Build the Nginx candidate locally as `rustdesk-api:nginx-candidate`, then run from the repository
root:

```powershell
.\tests\Performance\run.ps1 -Mode smoke
```

```bash
bash tests/Performance/run.sh
```

The smoke preset uses 30 devices, a 20% active-session mix, five seconds of warm-up, 20 seconds of
measurement, and one trial. It runs both the keep-alive and no-connection-reuse profiles against
both runtimes. It is a functional check of the harness, not evidence for runtime promotion.

To use a candidate from a registry, pass an immutable digest and allow Compose to pull it:

```powershell
.\tests\Performance\run.ps1 -Mode smoke `
  -NginxImage 'registry.example/rustdesk-api@sha256:<candidate-digest>' `
  -NginxPullPolicy missing
```

```bash
NGINX_IMAGE='registry.example/rustdesk-api@sha256:<candidate-digest>' \
NGINX_PULL_POLICY=missing \
bash tests/Performance/run.sh
```

Before seeding, each running container calculates a deterministic SHA-256 over sorted relative
paths and per-file content hashes for `app`, `bootstrap` except `bootstrap/cache`, `config`,
`database`, `public`, `resources`, `routes`, `artisan`, `composer.json`, and `composer.lock`.
This proves that the compared Laravel payloads match while intentionally allowing the Apache and
Nginx runtime files to differ. The report also records the locally resolved image ID and the OCI
`org.opencontainers.image.revision` label when one exists.

A comparison pair is invalid when either payload fingerprint is unknown or the fingerprints do
not match. Even a valid fingerprint-matched smoke, recovery, shortened, or otherwise overridden
steady pair cannot report an observed material win. That field is enabled only for the complete
12-run matrix from the three-trial 15,000-device/20%-active/two-minute-warmup/30-minute steady
preset. Every run must use the exact preset VU, latency, and resource limits, pass its gate, share
one payload fingerprint, and use a digest-pinned image; each runtime must keep one image ID across
all trials. Image references and revision labels remain traceability metadata rather than parity
proof.

## Longer presets

`steady` uses three reproducibly randomized-order trials, a two-minute warm-up, and a 30-minute measurement.
It uses 15,000 cadence-faithful devices at a 20% active mix, which yields the plan's 1,800
heartbeat RPS headroom target without shortening either real client cadence. `recovery` uses
10,000 active devices for 60 seconds, yielding 3,333 heartbeat RPS.

```powershell
.\tests\Performance\run.ps1 -Mode steady
.\tests\Performance\run.ps1 -Mode recovery
```

```bash
PERF_MODE=steady bash tests/Performance/run.sh
PERF_MODE=recovery bash tests/Performance/run.sh
```

The longer presets need substantial load-generator memory and should run on a quiet, dedicated
host. Their defaults cap each application at 2 CPUs/1 GiB, each MariaDB at 2 CPUs/2 GiB, and k6 at
4 CPUs/4 GiB. Override limits only when the same values are used for both runtimes. Common
PowerShell parameters are `-DeviceCount`, `-ActiveFraction`, `-WarmupDuration`, `-TestDuration`,
`-Trials`, `-AppCpus`, `-AppMemory`, `-DatabaseCpus`, `-DatabaseMemory`,
`-PreAllocatedVus`, `-MaxVus`, `-LoadGeneratorCpus`, and `-LoadGeneratorMemory`. The Bash runner accepts the equivalent
`PERF_*` environment variables defined in `docker/compose.performance.yml`.
Runtime and connection-profile order are randomized from `OrderSeed`/`PERF_ORDER_SEED` (default
`20260718`), which avoids a fixed warm-cache ordering while keeping a run reproducible.

On Linux the Bash runner runs the non-root k6 process as the invoking host UID/GID so its bind
mount remains writable. Docker Desktop uses the image's non-root `12345:12345` identity by
default; `-LoadGeneratorUser` or `PERF_K6_USER` can override it. The load container has a read-only
root filesystem, all capabilities dropped, and `no-new-privileges`; only the results bind and a
small temporary filesystem are writable.

The direct-container steady workload covers the 1,800 heartbeat-RPS portion of the release gate.
It does not fabricate the separate 180-RPS authenticated/background workload. Login, peer-list,
address-book, audit, upload/download, public TLS/1Panel, MariaDB telemetry, graceful drain, and
slow-client suites remain separate release evidence. Consequently, `comparison.json` explicitly
does not make a release-promotion decision.

## Connection profiles

- `keepalive` reuses backend HTTP connections and isolates application/PHP/MariaDB throughput.
- `no-reuse` sets both k6 connection-reuse controls so every heartbeat opens a fresh backend TCP
  connection, matching the RustDesk client's per-post HTTP-client behavior more closely.

These are direct HTTP tests on the isolated Compose network. Public TLS connection churn is paid
by the real edge proxy, so repeat the chosen load through the canary's public 1Panel HTTPS route
before promotion.

## Results

Each invocation creates a timestamped directory under `tests/Performance/results/` unless a
different result directory is supplied. It contains:

- `<runtime>-<profile>-trial-<n>.summary.json`: complete k6 summary plus workload, resolved image
  metadata, application fingerprint, gate state, and resource configuration;
- `<runtime>-<profile>-trial-<n>.stats.jsonl`: one-second Docker CPU/memory samples for the app
  and MariaDB;
- `<runtime>-<profile>-trial-<n>.seed.json`: deterministic dataset receipt for that isolated
  runtime/profile/trial stack;
- `comparison.json`: normalized runs, source-parity eligibility, and paired Nginx-vs-Apache
  throughput, latency, and peak application-memory deltas.

The raw results directory is ignored by Git. Archive release-gate evidence in the approved release
process rather than committing large local result sets accidentally.
