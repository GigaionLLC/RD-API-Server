---
type: "core"
name: "Core Architecture & Logic Flow"
status: "stable"
dependencies: ["07-state-context.md"]
description: "Documents the 'why' behind critical technical decisions."
---

# 🧠 Core Architecture

## 🔄 Data Lifecycle

HTTP and scheduled work flow through Laravel services and Eloquent into one MariaDB/InnoDB data
layer. The supported connection and isolated development, test, and screenshot schemas are
defined in the [Database Index](../database/database-index.md). Client-facing routes and JSON
remain independent of physical schema refactors.

## ⚙️ Core Engines & Algorithms
*Detailed breakdowns of key processes, state updates, or engines.*

## 🛡️ Guardrails & Safety
- **Database fail-closed:** Runtime and destructive test paths validate MariaDB plus their exact
  allowed database name before migrations. Unsupported engines never receive a best-effort run.
- **Isolation:** PHPUnit and screenshot fixtures use separate tmpfs-backed MariaDB services;
  neither may inherit or refresh the persistent development schema.
- **Transactions:** One-time challenges, recovery-code consumption, and similar concurrency
  boundaries use InnoDB row locks and transactions rather than process-local assumptions.
- **Personal collection identity:** A nullable marker plus MariaDB CHECK and unique index gives
  each owner exactly zero-or-one personal address book without restricting ordinary books.
  Concurrent first-use requests converge through the database constraint, not a name lookup.
- **Peer identity:** A peer's RustDesk ID is unique within its address book. Every writer treats
  MariaDB as the final concurrency authority and maps a lost insert race back to its normal
  duplicate response instead of exposing an integrity exception.
- **Wire stability:** Database changes never rename the RustDesk client's fixed API paths or JSON
  keys.
