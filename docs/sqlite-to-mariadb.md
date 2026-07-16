# SQLite-to-MariaDB Migration Boundary

This document is an upgrade boundary for installations created while SQLite was still an
optional deployment path. It does **not** make SQLite a supported database in the current
release. RD-API-Server now supports MariaDB with InnoDB only.

> **No automated converter is included.** Database layouts and production histories vary, so
> this repository does not attempt a destructive best-effort conversion. Complete and verify a
> manual DBA/ETL migration on the last SQLite-compatible release before starting a MariaDB-only
> release. If that cannot be done, remain on the last compatible release.

## Before you begin

- Identify and pin the exact last release or commit that successfully runs the current SQLite
  installation. Keep its image and configuration available for rollback.
- Schedule maintenance and stop every API, queue, scheduler, and replica process that can write
  to the database. Do not perform a live copy.
- Record the old release's effective `DB_DATABASE` path. Older Docker and local setups used
  different locations; do not assume the filename from a current example.
- Provision a separate MariaDB/InnoDB target. Never test the conversion against an existing
  production, development, PHPUnit, or screenshot database.

## 1. Take a recoverable backup

Back up all of these together before changing either environment:

1. The SQLite database file, including any write-ahead-log or journal files that belong to a
   consistent stopped copy.
2. The complete application `storage/` data, including recordings and uploaded files.
3. The application encryption key: either `storage/app/.appkey` or the secret-store value used
   for `APP_KEY`, plus any `APP_PREVIOUS_KEYS`.
4. The old image/tag, Compose configuration, `.env` values, and a list of enabled integrations.

Store the backup outside the container volumes, restrict its permissions, and verify that it can
be read. Authenticator secrets and recovery-code digests depend on the matching application key;
a database-only backup is not sufficient.

## 2. Build the MariaDB schema with the old release

Use the pinned, SQLite-compatible application release to create an empty schema on the new
MariaDB server. That release names its MariaDB-compatible Laravel connection `mysql`, so use
`DB_CONNECTION=mysql` only during this pre-upgrade conversion stage. Point it at a new database,
run its migrations without seeders, and confirm `php artisan migrate:status` is clean.

Do not run the MariaDB-only release yet. Its later migrations assume that the data has already
been transferred into MariaDB and can include one-way or key-dependent security changes.

## 3. Transfer data manually

Use a reviewed database migration/ETL tool or a DBA-authored process that understands both
SQLite and MariaDB. A raw `sqlite3 .dump` piped into a MariaDB client is not a supported method;
DDL, quoting, booleans, JSON/text values, generated identifiers, and foreign-key behavior differ.

The transfer must:

- preserve primary keys, foreign keys, `NULL` values, password hashes, tokens, timestamps, JSON,
  binary values, and case-sensitive identifiers without transforming application payloads;
- load parent rows before dependent rows, or use a controlled foreign-key strategy followed by
  an integrity check;
- advance every MariaDB auto-increment value beyond the imported maximum;
- copy durable application tables while retaining the target's migration history created in the
  previous step; do not blindly replace the target `migrations` table;
- make an explicit decision about disposable cache, session, failed-job, and queue rows rather
  than treating them as durable application data; and
- leave the stopped SQLite source untouched so rollback remains possible.

Repeat the conversion in a disposable environment until it is deterministic and every validation
below passes. Document the exact tool version and commands used for the real migration.

## 4. Validate on the old release

Keep the application pinned to the last SQLite-compatible release, but point it at the converted
MariaDB database with `DB_CONNECTION=mysql`. Before allowing writes, compare at least:

- source and target row counts for every durable table;
- orphaned foreign keys and duplicate unique values;
- maximum identifiers versus MariaDB auto-increment values;
- representative timestamps, JSON/configuration payloads, address books, audit records, and
  device ownership/permissions; and
- application migration status and logs.

Then perform a maintenance-only smoke test: administrator login, password and TOTP/recovery-code
authentication where configured, a RustDesk client login/heartbeat, address-book access, and the
critical integrations used by the deployment. Resolve discrepancies by correcting and repeating
the conversion, not by editing the original SQLite backup.

## 5. Cross the MariaDB-only boundary

After the old release works correctly on MariaDB:

1. Stop every old application replica and all background writers again.
2. Back up the converted MariaDB database and the same application key/storage unit.
3. Run the read-only [InnoDB engine audit](../Wiki/database/database-index.md#upgrade-boundary)
   against the converted database. Resolve any reported table with a MariaDB DBA before upgrading.
4. Update the application image or source to the MariaDB-only release.
5. Change the connection name from `DB_CONNECTION=mysql` to `DB_CONNECTION=mariadb`. This is a
   Laravel connection-name change; the validated InnoDB data remains in place.
   If the old release used `DB_URL`, first expand it into `DB_HOST`, `DB_PORT`, `DB_DATABASE`,
   `DB_USERNAME`, and `DB_PASSWORD`, validate those settings, and unset `DB_URL`.
6. Start one upgraded instance and allow its migrations to finish. Review the logs before
   starting any additional replicas.
7. Start only upgraded replicas, then repeat the authentication, client, address-book, audit, and
   integration smoke tests.

Do not use a mixed-version rolling deployment across this boundary. In particular, the migrations
that encrypt authenticator secrets, normalize/enforce canonical TOTP state, and enforce the
email-verification address invariant require old writers to remain stopped while they run.
MariaDB cannot make a preflight/repair and subsequent CHECK installation one atomic unit. If the
email migration reports account IDs, add a valid address or intentionally change those policies
and retry; it will not silently downgrade them to password-only. Review the first upgraded
instance's complete migration result before starting any writer.

## Rollback

If conversion validation or the upgrade fails, stop the new application before it can accept
writes. Restore the coupled MariaDB/key/storage backup when retrying the new release, or return to
the pinned old release and the untouched SQLite backup. Never point the MariaDB-only application
at the SQLite file, and never combine a restored database with a different application key.

Keep the stopped SQLite backup until the MariaDB deployment has passed the organization's normal
acceptance and retention window. Securely dispose of it only under the operator's data-retention
policy.
