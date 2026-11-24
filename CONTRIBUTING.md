# Contributing

Thanks for your interest. Bug reports, questions and design discussion are welcome without any
paperwork — open an issue.

## Before your first pull request

Nothing to sign. By submitting a contribution you agree it is licensed under the project's MIT
License (see [LICENSE](LICENSE)); contributions are accepted inbound = outbound, so you keep the
copyright in your work and everyone gets it on the same terms as the rest of the project.

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
