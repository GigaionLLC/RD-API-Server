# Contributing

Thanks for your interest. Bug reports, questions and design discussion are welcome without any
paperwork — open an issue.

## Before your first pull request

Code and documentation contributions require a signed [Contributor Assignment Agreement](CLA.md).
It assigns copyright in your contribution to the project owner, with a fallback exclusive licence
for jurisdictions where assignment is not fully effective, and it leaves you free to use your own
work however you like.

The reason is stated plainly in the agreement: the project keeps the option of licensing the
codebase on other terms in future. Without an assignment, that decision would need the agreement of
every past contributor, which in practice means it could never be made.

Signing is one click. A bot comments on your first pull request with a link, and remembers your
account afterwards.

## Ground rules

- **English everywhere** — identifiers, comments, commit messages, documentation.
- **No Vue or SPA frameworks.** The admin UI is Blade + jQuery + Bootstrap 5 with the project's own
  CSS. Use the tokens in `Wiki/core/06-design-system.md`; do not invent classes or hard-code
  colours.
- **Never rename what the RustDesk client speaks.** JSON keys, API paths and response shapes are a
  wire contract with a client you do not control. English renaming applies to PHP identifiers, not
  to the protocol. The contract is `docs/modernization/02-client-api-contract.md`.
- **MariaDB/InnoDB only.**
- **This is an independent project.** It is not affiliated with, endorsed by, or sponsored by
  RustDesk or Purslane Ltd. Do not present it or its UI as an official RustDesk product, and do not
  copy RustDesk source into it — the browser client here is a clean-room implementation written
  against a published protocol description, and it has to stay that way.

## Running the gates

The host needs no PHP, Composer or Node: everything runs in the toolchain image.

```bash
docker build -f docker/Dockerfile.toolchain -t rustdesk-api-php-toolchain .
```

Style and static analysis:

```bash
docker compose -f docker/compose.toolchain.yml run --rm app bash -lc './vendor/bin/pint --test && ./vendor/bin/phpstan analyse --memory-limit=1G'
```

PHPUnit, against a guarded throwaway schema — never the dev database:

```bash
docker compose -f docker/compose.toolchain.yml --profile test run --rm test php artisan test
```

Browser tests:

```bash
docker compose -f docker/compose.toolchain.yml --profile e2e run --rm e2e bash docker/e2e.sh
```

The browser client under `web-client/` has its own suites:

```bash
docker run --rm -v "$PWD/web-client:/w" -w /w rustdesk-api-php-toolchain node --test
```

If you change anything under `web-client/src` or `web-client/vendor`, republish the copy the
application serves and commit the result:

```bash
docker run --rm -v "$PWD:/r" -w /r rustdesk-api-php-toolchain node web-client/scripts/install-assets.mjs
```

## What makes a change easy to accept

- A description of the behaviour that was wrong, not only the code that changed.
- A test that fails before the change. The suites here are written to pin behaviour that is
  expensive to notice in the wild — silent, plausible-looking wrongness — so a test that would have
  caught the bug is worth more than one that covers the new lines.
- Comments that explain *why*, where the reason is not obvious from the code. Match the density and
  voice of the surrounding file.
- Small, self-contained commits with a real message.
