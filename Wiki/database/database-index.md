# 🗄️ Database Index

This is the source of truth for the application's database support and isolation boundaries.

## Supported engine

RD-API-Server supports **MariaDB with InnoDB only**. This is an architecture contract, not a
default recommendation: runtime startup and every destructive verification path must reject any
other driver or server before migrations begin. Oracle MySQL, SQLite, PostgreSQL, and SQL Server
are not compatibility targets.

Use Laravel's `mariadb` connection (`DB_CONNECTION=mariadb`). The names `pdo_mysql`, the PDO
`mysql:` DSN, and MariaDB's `/var/lib/mysql` data directory are correct implementation details;
they do not imply Oracle MySQL support.

One engine keeps production and verification on the same DDL, transaction, foreign-key, JSON,
locking, and collation behavior. Row-lock security boundaries such as one-time challenge and
recovery-code consumption depend on InnoDB transactions and are verified on MariaDB directly.

## Connection endpoints

| Setting | Behavior |
| :--- | :--- |
| `DB_HOST` / `DB_PORT` | Conventional TCP endpoint; use an address reachable from the application container for external MariaDB |
| `DB_SOCKET` | Optional Unix socket path; mount the socket into the application container and leave it empty for the bundled `db` service |
| `DB_CONNECT_TIMEOUT` | Integer connection timeout in seconds; accepted range is 1-10 |
| `MYSQL_ATTR_SSL_CA` | Optional readable CA certificate path; mount the file into the application container |
| `DB_URL` | Rejected; expand a legacy URL into the discrete connection settings and unset it before upgrading |

The current `.env.example` deliberately comments out `DB_HOST`. Laravel therefore retains its
`127.0.0.1` default for direct host-side commands, while the bundled root/development Compose
files use their `db` service by default. Before using those stacks, remove or change an inherited
`DB_HOST=127.0.0.1` from an older copied `.env`; container loopback is not the database service.
Set `DB_HOST` / `DB_PORT` explicitly only when selecting an external MariaDB endpoint or another
intentional network target. This only changes the app connection: the root/development Compose
files still define their bundled `db` service and app `depends_on`. A truly external-only topology
needs a custom Compose definition/override that removes or replaces both.

URL-only MariaDB configurations are not accepted. Before upgrading, expand `DB_URL` into
`DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`, test those discrete settings
on the old release, then unset `DB_URL` without printing the credential-bearing URL to logs.

## Environment isolation

| Purpose | Compose services | Database | Storage policy |
| :--- | :--- | :--- | :--- |
| Production/runtime | `rustdesk-api` + `db` | `rustdesk_api` by default | Persistent named/bind volumes; operator backups required |
| Development | `app` + `db` | `rustdesk_api` | Persistent `dbdata` volume |
| PHPUnit | `test` + `test-db` (`test` profile) | `rustdesk_api_testing` | MariaDB data on tmpfs; guarded exact-name/server check |
| Local Playwright | `e2e` + `e2e-db` (`e2e` profile) | `rustdesk_api_e2e` | MariaDB data on tmpfs; guarded exact-name/server check |
| CI Playwright | Job-scoped app + MariaDB service | `rustdesk_api_e2e` | Disposable CI service; never a developer/operator database |
| Screenshot gallery | `screenshots` + `screenshot-db` (`screenshots` profile) | `rustdesk_api_screenshots` | MariaDB data on tmpfs; never touches development data |

Never run PHPUnit, browser fixtures, or screenshot migrations against `rustdesk_api` or an
operator-supplied database. The dedicated runner must force its database host/name instead of
inheriting them from `.env`, then verify the live server identifies itself as MariaDB before any
refresh migration executes. Browser and screenshot runners perform this rejection before they
install any missing locked Composer/npm dependencies.

## Schema source

| Schema source | Scope | Rule |
| :--- | :--- | :--- |
| [`database/migrations/`](../../database/migrations/) | Application, cache, queue, session, and feature tables | Forward and rollback behavior targets MariaDB/InnoDB only |
| [`app/Models/`](../../app/Models/) | Eloquent relations, casts, and protected attributes | Keep model behavior synchronized with migrations |
| [`docs/modernization/02-client-api-contract.md`](../../docs/modernization/02-client-api-contract.md) | RustDesk client wire contract | Database refactors must not rename client JSON keys or API paths |

## Upgrade boundary

An existing MariaDB deployment that explicitly uses the former Laravel connection name
`mysql` may change only that setting to `mariadb` **after** a backup and a clean server/table audit.
First confirm both the server identity and its default engine:

```sql
SELECT VERSION() AS server_version,
       @@default_storage_engine AS default_storage_engine;
```

`server_version` must contain `MariaDB`, and `default_storage_engine` must be `InnoDB`. Then run
this read-only query while connected to the application database:

```sql
SELECT TABLE_NAME, ENGINE
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_TYPE = 'BASE TABLE'
  AND COALESCE(UPPER(ENGINE), '') <> 'INNODB';
```

An empty table result, together with the required server identity and default engine, means the
connection-name change does not move or rewrite existing InnoDB data. If any check fails or any
MyISAM, Aria, or other engine is listed, do not start the MariaDB-only release; retain the backup
and have a MariaDB DBA review, convert, and validate the deployment first.

An installation on the retired SQLite path must be converted before upgrading, or remain on the
last compatible release. No automated converter is provided. See the
[manual SQLite-to-MariaDB migration boundary](../../docs/sqlite-to-mariadb.md).
