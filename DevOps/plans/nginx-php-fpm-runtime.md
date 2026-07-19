# Parcel Plan: Nginx + PHP-FPM runtime and fleet-capacity gate

## State dashboard

| Metric | Value |
| :--- | :--- |
| **Status** | `V1.1.0 RELEASE AUTHORIZED - FLEET CAPACITY QUALIFICATION PENDING` |
| **Target Version** | `v1.1.0` |
| **Active Persona** | `Runtime architect` |
| **Last Updated** | 2026-07-18 20:17 PDT |

## 1. Decision

Build and benchmark an Nginx + PHP-FPM candidate inside the existing single public application
container. Promote it only if it preserves every HTTP and deployment contract and produces a
material measured capacity or memory improvement over the current Apache/mod_php image.

This is a runtime implementation change, not a claim that Nginx alone solves fleet scale. The
current 1Panel edge already handles public TLS and client sockets. Dynamic throughput still
depends on the bounded PHP worker pool and MariaDB work performed by every request.

Treat this as the operationally significant `v1.1.0` release. Keep the published `v1.0.1` image
at manifest digest `sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2`
as the immediate rollback path. Native AMD64/ARM64 integration and user review are hard release
requirements. The preferred promotion sequence also completes the fleet-capacity matrix and
public-proxy canary; the user explicitly authorized v1.1.0 promotion on 2026-07-18 with those two
qualification items still outstanding and required transparent release notes.

## 2. Evidence

- The published runtime uses the official PHP Apache variant. It reports Apache 2.4.68,
  `mpm_prefork`, mod_php, `MaxRequestWorkers 150`, and a five-second keep-alive timeout.
- The [official PHP image documentation](https://hub.docker.com/_/php/) confirms that its Apache
  variant uses mod_php plus `mpm_prefork`, while its FPM variant is the recommended FastCGI
  implementation. It also warns never to publish FastCGI to an untrusted network.
- [Nginx uses an event-based worker model](https://nginx.org/en/docs/beginners_guide.html), while
  PHP-FPM provides an explicit dynamic-request ceiling through
  [`pm.max_children`](https://www.php.net/manual/en/install.fpm.configuration.php).
- Apache itself is not inherently unsuitable: its
  [event MPM](https://httpd.apache.org/docs/2.4/en/mod/event.html) also releases worker threads
  from idle keep-alive connections. The candidate is justified by the combined move from
  mod_php/prefork to event-driven HTTP handling plus a separately bounded PHP pool.
- The current RustDesk client source defines a 15-second idle heartbeat and a three-second loop
  for devices with active connections in
  [`src/hbbs_http/sync.rs`](https://github.com/rustdesk/rustdesk/blob/5f015c9da13cb227a414c6d295a5c81e5360eccb/src/hbbs_http/sync.rs#L17-L19).
  Its [send check](https://github.com/rustdesk/rustdesk/blob/5f015c9da13cb227a414c6d295a5c81e5360eccb/src/hbbs_http/sync.rs#L231-L244)
  skips the intermediate ticks only when no connection is active.
- The application heartbeat path currently performs a device lookup, unconditional liveness
  update, strategy resolution, and database-backed disconnect-cache pull. Depending on the
  strategy path, this is approximately five to eight MariaDB statements per heartbeat.

For `N` devices and active-session fraction `a`, the expected heartbeat request rate is:

```text
RPS = N * ((1 - a) / 15 + a / 3) = N * (1 + 4a) / 15
```

Reference load for 10,000 devices:

| Active fraction | Heartbeat RPS | Approximate DB statements/second |
| :--- | ---: | ---: |
| 0% | 667 | 3,300–5,300 |
| 10% | 933 | 4,700–7,500 |
| 20% | 1,200 | 6,000–9,600 |
| 100% short burst | 3,333 | 16,700–26,700 |

At the idle rate, logging every successful heartbeat would produce about 57.6 million access-log
records per day. Logging and the MariaDB hot path therefore need measurement even if Nginx wins
the web-tier comparison.

## 3. Non-disruptive deployment contract

The candidate must remain a drop-in replacement. Existing installations must not need a Compose,
1Panel, volume, database, or environment migration.

- Keep `ghcr.io/gigaionllc/rustdesk-api-server` as the image and keep container port `80`.
- Keep `/var/www/html`, document root `/var/www/html/public`, and the persistent
  `/var/www/html/storage` mount.
- Keep every existing environment name and default. New FPM/body/log tuning values must be
  optional and have safe defaults.
- Keep the MariaDB/InnoDB-only entrypoint checks, persistent application key, install marker,
  migrations, first-run seeding, production caches, and CLI access unchanged.
- Preserve the configured `TRUSTED_PROXIES` boundary. Nginx must pass the immediate proxy address
  as `REMOTE_ADDR` and pass incoming `X-Forwarded-For` and `X-Forwarded-Proto` unchanged; it must
  not enable `real_ip` rewriting or replace the public forwarded scheme with internal HTTP. The
  application supports warned wildcard convenience mode and recommended exact IP/CIDR restrictions.
- Preserve access-log output to stdout with the immediate peer address first, and error output to
  stderr, so existing proxy diagnostics continue to work.
- Keep the normal upgrade operation limited to pulling and recreating the API service. No schema
  or RustDesk wire-contract change belongs in the runtime migration.
- Rollback must be a one-service recreation using the published `v1.0.1` tag and digest; the
  database, storage, application key, proxy, and MariaDB container remain untouched.

  ```bash
  RUSTDESK_API_IMAGE='ghcr.io/gigaionllc/rustdesk-api-server:1.0.1@sha256:65fdd380ab101ef8fcf40e8281aa303257559f3da4008dfb00782138e71268e2' \
    docker compose up -d --no-deps --force-recreate rustdesk-api
  ```

## 4. Runtime architecture

### Image and processes

- Change the shared runtime base to digest-pinned
  `php:8.5.8-fpm-bookworm@sha256:83c155135b9c4aa664fc6ce47020a10fe53576a0ed3468119cf2efec22fd16b9`.
- Retain the current one-extension-compilation and Composer dependency-cache design.
- Install Debian Bookworm's signed/security-maintained `nginx-light` and `tini` packages. Do not
  install a second Debian PHP runtime or compile PHP manually.
- Run Nginx and PHP-FPM in one image for compatibility. Use `tini -g` plus a small tested wrapper
  that starts both services, reaps children, forwards graceful shutdown, exits nonzero when either
  service fails, and stops the surviving peer. Docker documents the
  [wrapper pattern](https://docs.docker.com/engine/containers/multi-service_container/).
- Declare `STOPSIGNAL SIGQUIT` explicitly, matching the official FPM base's graceful stop signal.
  The wrapper must handle both the real Docker/Compose `SIGQUIT` path and explicit `SIGTERM`,
  forwarding graceful `SIGQUIT` to Nginx and FPM and enforcing the documented drain deadline.
- Connect Nginx to FPM only through a permission-restricted Unix socket. Never listen on or publish
  FastCGI port 9000. The official FPM base retains inherited OCI `ExposedPorts` metadata for 9000;
  that metadata is not a listener and does not publish a port. CI must inspect the live socket
  table and FPM configuration rather than mistake image metadata for network reachability.

### Nginx parity and security

- Follow Laravel's documented [`try_files` and index-only FastCGI pattern](https://laravel.com/docs/13.x/deployment):
  serve existing public files directly and route all other requests only to `public/index.php`.
- Deny dotfiles, `.env`, `vendor`, private application storage, non-index PHP execution, directory
  indexes, and server-version disclosure. Preserve Laravel's optional `public/storage` symlink for
  intentionally public files. Keep `public/.htaccess` only as an external-Apache compatibility
  fallback; the container must not depend on it.
- Explicitly preserve query strings, methods, raw bodies, `Host` including a nonstandard port,
  Authorization, X-XSRF-Token, API-key, recording-token, content headers, and forwarded headers.
- Do not enable FastCGI or HTTP response caching for dynamic, authenticated, or client API routes.
  Static assets are not content-hashed, so use normal ETag/Last-Modified revalidation rather than
  long immutable cache headers.
- Keep request buffering on so slow uploads do not occupy FPM workers. Derive Nginx's body ceiling
  from the configured recording-chunk limit with headroom, and keep it above the application's
  normal rejection boundary. Nginx's one-megabyte default would break four-megabyte CSV imports
  and eight-megabyte recording chunks.
- Explicitly disable FastCGI response buffering for the initial parity candidate so streamed CSV
  exports and multi-gigabyte recording downloads cannot be spooled into Nginx temporary files.
  Measure slow-client worker occupancy and bounded temp-disk I/O. A later, separate change may
  selectively re-enable buffering or authorize recording downloads in Laravel and serve them with
  `X-Accel-Redirect`; that optimization is not part of initial parity.

### PHP-FPM controls

- Use `pm = dynamic`, a nonzero `pm.max_requests`, slow-request logging, and private status/ping
  endpoints that are never publicly routed.
- Do not inherit FPM's small default pool or copy Apache's limit of 150. Calculate the memory
  ceiling from measured worker PSS:

  ```text
  memory ceiling = floor((container RAM - Nginx/master reserve - 25% headroom) /
                         measured p95 PHP worker PSS)
  ```

- Lower that ceiling when CPU saturation or MariaDB's connection budget is reached. Across all
  replicas, FPM children plus scheduler/admin margin must remain below MariaDB's safe connection
  capacity.
- Ensure FPM receives the application environment only after the entrypoint has removed
  `ADMIN_PASS`.

## 5. Safe publication sequence

Implement as separate, independently revertible commits:

1. **Release-channel safety:** change main-branch publication to a full-SHA discovery tag plus its
   recorded content digest. A registry tag, including one named after a SHA, is mutable; only the
   digest is the deployment identity. Move `latest`, SemVer aliases, and stable channels only from
   a reviewed annotated stable tag, and make CI reject lightweight stable tags. This lets main
   build the candidate without silently upgrading existing `latest` deployments. Never use the
   short-SHA tag as a security boundary.
2. **Runtime candidate:** add Nginx/FPM configuration, pool template, body-limit rendering, and
   graceful dual-process launcher while preserving the current entrypoint contract. Retain the
   Apache configuration until the benchmark decision is accepted.
3. **Native integration gates:** replace Apache-only CI assertions with `nginx -t`, `php-fpm -tt`,
   Unix-socket/no-port-9000 assertions, process-failure tests, and a disposable-MariaDB HTTP smoke
   on both AMD64 and ARM64. Probe `/up`, `/api/version`, a CSS asset, admin login, API headers,
   trusted HTTPS scheme, and client IP recovery.
4. **Capacity harness:** add reproducible, pinned k6 scenarios and dataset creation. Keep this
   separate from production code so runtime changes and load tooling can be reviewed or reverted
   independently.
5. **Documentation and tuning:** document optional FPM/body/log controls, memory/DB sizing,
   canary deployment, one-service rollback, and the unchanged proxy settings.
6. **Canary and release:** the preferred path deploys the recorded full-SHA-tagged digest to one
   API instance, runs the public HTTPS checker and workload gate, obtains user approval, then
   creates an annotated tag and publishes `v1.1.0`. For this release the user approved promotion
   before the public canary and fleet-scale workload; those remain explicit post-release
   qualification work rather than completed evidence.

## 6. Functional verification matrix

- [x] Full PHPUnit, Pint, PHPStan, ESLint, vendor-integrity, dependency-audit, and Playwright gates.
- [x] Nginx and FPM syntax pass on native AMD64 and ARM64 images.
- [x] Both managed processes start, stop gracefully, reap children, and fail the container when
      either process exits unexpectedly. Test the image's real `SIGQUIT` stop path and an explicit
      `SIGTERM` path.
- [x] `/up`, `/api/version`, admin login, static CSS/JS/fonts, 404, 405, query strings, and
      trailing-slash behavior match the Apache image.
- [x] Authorization, X-XSRF-Token, API-key, recording-token, JSON, form, and raw binary requests
      reach Laravel unchanged.
- [x] HTTPS forwarded scheme, secure cookies, generated asset URLs, proxy IP, and client IP match
      the existing narrow trusted-proxy contract.
- [x] Four-megabyte CSV import, configured recording chunks, oversized-body errors, slow uploads,
      streamed exports, and large recording downloads retain expected status/body behavior.
- [x] The final image contains no Composer, extension installer, C/C++ compiler driver, `make`,
      `linux-libc-dev`, listening or published FPM TCP socket, or unintended public PHP entry point.
      Inherited `EXPOSE 9000` metadata is documented and is not treated as a listener.
- [x] The entrypoint still blocks unsupported databases, unsafe bootstrap credentials, broken
      application-key state, and invalid proxy configuration before serving traffic.

## 7. Capacity benchmark and promotion gate

Compare the published Apache/mod_php control and Nginx/FPM candidate with identical application
source, MariaDB dataset, CPU/RAM limits, and warmup. Run direct-container HTTP first, then repeat
through the real 1Panel HTTPS path because the RustDesk client creates a new HTTP client per post
and the edge—not the application container—pays public TLS connection churn.

The promotion design fleet is 10,000 devices at a 20% active-session mix: 1,200 heartbeat RPS.
The steady headroom gate is exactly 1,800 heartbeat RPS plus 180 RPS of randomized non-heartbeat
traffic, for 1,980 total RPS over 30 minutes. The recovery gate is the same 10,000-device fleet at
100% active for exactly 3,333 heartbeat RPS plus the 180 RPS background mix, for 3,513 total RPS
over 60 seconds. The larger 25,000-device dataset is exploratory and does not silently redefine
the release gate.

Run three randomized 30-minute trials for each candidate:

- 1,000 / 5,000 / 10,000 / 25,000 unique approved-device datasets.
- Heartbeats at the real 15-second idle and three-second active cadence with 0%, 10%, and 20%
  active mixes, plus a 100%-active 60-second recovery burst.
- 95% unchanged strategy timestamps and 5% strategy-pull responses.
- Startup `sysinfo` storm, authenticated login/current-user/peer mix, `/api/peers` at
  1,000/10,000/25,000 visible devices, and address books at 50/500/5,000 peers.
- Separate Argon2id login burst, audit ingestion, webhook latency/failure, recording uploads at
  1/4/16 concurrency, slow bodies, and large recording downloads.
- A no-connection-reuse profile matching the client plus a keep-alive profile that isolates
  backend application throughput.

Collect achieved/dropped RPS, p50/p95/p99 latency, unexpected 4xx/5xx/502/504, CPU, cgroup memory,
per-worker PSS, FPM queue/max-children events, MariaDB QPS/connections/row locks/slow queries,
container I/O, access-log bytes, and graceful-drain time.

Promotion requires all of the following:

- Zero wire-contract mismatches and zero unexpected server errors.
- Sustain the defined 1,980-RPS steady headroom profile for 30 minutes with heartbeat p95 at or
  below 250 ms, heartbeat p99 at or below 750 ms, application and DB CPU at or below 70%, memory
  at or below 75%, and no sustained FPM or MariaDB queue.
- Tolerate the defined 3,513-RPS all-active recovery profile for 60 seconds with heartbeat p99 at
  or below two seconds and drain the backlog within 30 seconds.
- An orchestrator stop using the image's declared `SIGQUIT` (and the separately tested explicit
  `SIGTERM` path) translates into graceful Nginx/FPM shutdown and lets requests that complete
  inside the configured grace period finish without corruption. Tests must state that grace
  period. A normal single-replica Compose recreation cannot guarantee zero interruption for an
  arbitrarily slow upload or multi-gigabyte download; operators requiring that property need a
  blue-green/two-replica proxy drain.
- Nginx/FPM produces a material win: at least 25% lower peak web-tier memory or at least 20% more
  sustainable throughput at the same latency/error objective.

If the candidate does not meet the material-win gate, keep Apache stable and prioritize the
heartbeat/database/logging hot path. Those application changes must remain separate from the
runtime migration: coalescing liveness writes, removing empty database-cache pulls, reducing
strategy queries, avoiding bearer-token writes on every read, indexing retention queries, and
considering an optional Redis cache/limiter tier for large installations.

## 8. Review state

### Local implementation evidence

- The final source-parity-safe 300-heartbeat-RPS tuning run used matching application payload
  fingerprints, separate disposable app/MariaDB stacks for every runtime/profile pair, two app
  CPUs, one GiB app memory, a ten-second warmup, and a 20-second measurement. Apache and Nginx
  both completed 100.02% of the schedule with zero failures, drops, or wire mismatches. P95 was
  7.03-7.12 ms for Nginx and 7.29-7.31 ms for Apache. Sampled Nginx app memory was 17.7% lower in
  the no-reuse profile and 84.2% lower in the keep-alive profile; this short run is tuning evidence,
  not the required three-trial 1,800-RPS promotion result.
- The quota-aware candidate rendered two Nginx workers under the two-CPU limit. An earlier
  `worker_processes auto` build saw all 20 host CPUs and produced misleading saturation results;
  those runs are intentionally not promotion evidence.
- Local production smoke passed real MariaDB startup, Nginx/FPM syntax, Unix-socket isolation,
  HTTPS proxy recovery, secure cookies, API/static behavior, request-size/path boundaries,
  managed-process failure, and in-flight graceful `SIGQUIT`/`SIGTERM` shutdown.
- A same-database Trivy comparison after removing compiler-driver/kernel-header packages found
  zero fixable high/critical findings in the candidate versus four in v1.0.1. The candidate
  reported 49 high and 16 critical unfixed Debian advisories versus 181 high and 17 critical in
  v1.0.1; Composer dependencies reported none. These 2026-07-18 counts are baseline context, not a
  substitute for exploitability review or vendor updates.

### Decision state

- Product intent: satisfied by a drop-in candidate, stable-channel protection, and one-command
  rollback.
- Runtime/security review: no blocker to a single-container Nginx/FPM candidate when FastCGI stays
  on a Unix socket and process supervision, proxy headers, body limits, and native HTTP integration
  are hard gates.
- Capacity review: Nginx is approved only as a benchmarked web-tier improvement. It is not accepted
  as the sole answer to a 10,000-device fleet.
- The exact three-trial 1,800-RPS workload, 3,333-RPS recovery workload, background-route coverage,
  and public 1Panel canary remain incomplete. The user authorized v1.1.0 stable promotion with
  those limits documented rather than represented as passing evidence. They remain follow-up
  qualification for large fleets; the tracked Apache compatibility configuration and immutable
  v1.0.1 rollback image are retained.
