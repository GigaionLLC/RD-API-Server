# Encrypted notes vault

Working notes that belong in the repository's history but not in public view —
competitive analysis, unreleased planning, anything commercially sensitive.

`vault.enc` **is committed**. The decrypted `notes/` directory is gitignored. The
passphrase lives in `.env`, which is also gitignored.

The point is that gitignoring the notes outright would mean they exist on one machine and
vanish with it. This way they are versioned, backed up and reviewable, just not readable
by anyone who clones the repo.

## Use

```bash
node scripts/vault.mjs status     # what is in the vault, without decrypting
node scripts/vault.mjs unlock     # vault.enc -> notes/
node scripts/vault.mjs lock       # notes/ -> vault.enc, then commit vault.enc
```

Edit under `notes/`, run `lock`, commit `vault.enc`. The plaintext never enters git.

Without Node on the host, use the toolchain image:

```bash
docker run --rm -v "$PWD:/app" -w /app node:22-alpine node scripts/vault.mjs unlock
```

## The passphrase

`node scripts/vault.mjs init` generates a 256-bit passphrase and appends it to `.env`. It
refuses to overwrite an existing one, because doing so would make the current vault
permanently unreadable.

**Keep a copy in a password manager, and share it with collaborators the same way.** There
is no recovery path — any recovery path would also be a bypass.

## Crypto

scrypt (N=2^16, r=8, p=1) derives a 256-bit key from the passphrase, then AES-256-GCM with
a fresh random salt and IV on every lock.

The GCM tag is verified on unlock, so a modified `vault.enc` fails loudly rather than
producing plausible-looking garbage. A failure means either the wrong passphrase or a
tampered blob; GCM cannot tell you which, and treating both as "do not trust" is correct.

The work factor is deliberately high: the encrypted blob is public, so the passphrase is
the only barrier and an offline attacker has unlimited attempts.

## What belongs here

Notes whose *existence* is fine but whose *contents* should not be public. Anything that
must never exist in git — credentials, keys, customer data — belongs in a secret manager
instead. A vault in a public repository is only as good as the passphrase, and a leaked
passphrase exposes every historical revision, not just the current one.
