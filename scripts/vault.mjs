/**
 * Encrypted notes vault.
 *
 * Keeps commercially sensitive working notes — competitor analysis, unreleased planning —
 * inside the repository and its history, but unreadable to anyone without the passphrase.
 * The alternative, gitignoring them, means they exist only on one machine and are lost
 * with it.
 *
 *   node scripts/vault.mjs unlock     decrypt vault.enc -> DevOps/vault/notes/
 *   node scripts/vault.mjs lock       encrypt notes/ -> vault.enc  (then commit vault.enc)
 *   node scripts/vault.mjs status     what is in the vault, without decrypting anything
 *   node scripts/vault.mjs init       generate a passphrase for a new vault
 *
 * The passphrase lives in .env as VAULT_PASSPHRASE, which is gitignored. Losing it means
 * losing the contents: there is no recovery path by design, since one would also be a
 * bypass.
 *
 * Crypto: scrypt (N=2^16) to derive a 256-bit key from the passphrase, then AES-256-GCM.
 * A random salt and IV per lock, and the GCM tag is verified on unlock — so tampering
 * with the committed blob fails loudly rather than yielding garbage.
 */

import { createCipheriv, createDecipheriv, randomBytes, scryptSync } from 'node:crypto';
import { mkdir, readFile, readdir, rm, stat, writeFile } from 'node:fs/promises';
import { dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const vaultDir = resolve(repoRoot, 'DevOps/vault');
const notesDir = resolve(vaultDir, 'notes');
const blobPath = resolve(vaultDir, 'vault.enc');
const envPath = resolve(repoRoot, '.env');

const MAGIC = 'RDVAULT1';
const SALT_BYTES = 16;
const IV_BYTES = 12;
const TAG_BYTES = 16;
const KEY_BYTES = 32;
// Deliberately expensive: the blob is public, so the passphrase is the only barrier and
// an offline attacker gets unlimited attempts.
const SCRYPT = { N: 65536, r: 8, p: 1, maxmem: 128 * 1024 * 1024 };

/** @param {string} message */
function die(message) {
    console.error(`✗ ${message}`);
    process.exit(1);
}

/** Reads VAULT_PASSPHRASE from the environment or .env, without pulling in a dependency. */
async function passphrase() {
    if (process.env.VAULT_PASSPHRASE) return process.env.VAULT_PASSPHRASE;
    let raw;
    try {
        raw = await readFile(envPath, 'utf8');
    } catch {
        die('no VAULT_PASSPHRASE in the environment and no .env file. Run: node scripts/vault.mjs init');
    }
    const line = raw.split(/\r?\n/).find((l) => l.trimStart().startsWith('VAULT_PASSPHRASE='));
    if (!line) die('VAULT_PASSPHRASE is not set in .env. Run: node scripts/vault.mjs init');
    const value = line.slice(line.indexOf('=') + 1).trim().replace(/^["']|["']$/g, '');
    if (!value) die('VAULT_PASSPHRASE in .env is empty');
    return value;
}

/** @param {string} pass @param {Buffer} salt */
const deriveKey = (pass, salt) => scryptSync(pass, salt, KEY_BYTES, SCRYPT);

/** @param {string} dir @returns {Promise<string[]>} paths relative to dir, POSIX-separated */
async function walk(dir, base = dir) {
    /** @type {string[]} */
    const out = [];
    let entries;
    try {
        entries = await readdir(dir, { withFileTypes: true });
    } catch {
        return out;
    }
    for (const entry of entries) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) out.push(...(await walk(full, base)));
        else out.push(relative(base, full).split(sep).join('/'));
    }
    return out.sort();
}

async function lock() {
    const files = await walk(notesDir);
    if (files.length === 0) die(`nothing to lock: ${relative(repoRoot, notesDir)} is empty or missing`);

    /** @type {Record<string, string>} */
    const manifest = {};
    let plainBytes = 0;
    for (const file of files) {
        const buf = await readFile(join(notesDir, file));
        manifest[file] = buf.toString('base64');
        plainBytes += buf.length;
    }

    const payload = Buffer.from(JSON.stringify({ files: manifest, lockedAt: new Date().toISOString() }), 'utf8');
    const salt = randomBytes(SALT_BYTES);
    const iv = randomBytes(IV_BYTES);
    const cipher = createCipheriv('aes-256-gcm', deriveKey(await passphrase(), salt), iv);
    const body = Buffer.concat([cipher.update(payload), cipher.final()]);
    const tag = cipher.getAuthTag();

    // Header is authenticated implicitly: a changed salt or IV yields a failed tag check.
    const blob = Buffer.concat([Buffer.from(MAGIC, 'ascii'), salt, iv, tag, body]);
    await mkdir(vaultDir, { recursive: true });
    await writeFile(blobPath, blob);

    console.log(`✓ locked ${files.length} file(s), ${(plainBytes / 1024).toFixed(1)} KiB`);
    console.log(`  ${relative(repoRoot, blobPath)}  (${(blob.length / 1024).toFixed(1)} KiB) — commit this`);
    for (const f of files) console.log(`    ${f}`);
}

async function unlock() {
    let blob;
    try {
        blob = await readFile(blobPath);
    } catch {
        die(`no vault at ${relative(repoRoot, blobPath)}`);
    }
    if (blob.subarray(0, MAGIC.length).toString('ascii') !== MAGIC) die('not a vault file, or a newer format');

    let at = MAGIC.length;
    const salt = blob.subarray(at, at += SALT_BYTES);
    const iv = blob.subarray(at, at += IV_BYTES);
    const tag = blob.subarray(at, at += TAG_BYTES);
    const body = blob.subarray(at);

    const decipher = createDecipheriv('aes-256-gcm', deriveKey(await passphrase(), salt), iv);
    decipher.setAuthTag(tag);

    let plain;
    try {
        plain = Buffer.concat([decipher.update(body), decipher.final()]);
    } catch {
        // GCM cannot distinguish a wrong key from a modified blob; both mean "do not trust".
        die('decryption failed — wrong VAULT_PASSPHRASE, or the vault has been modified');
    }

    const { files, lockedAt } = JSON.parse(plain.toString('utf8'));
    // Replace rather than merge: a file deleted before the last lock should not reappear.
    await rm(notesDir, { recursive: true, force: true });
    for (const [file, b64] of Object.entries(files)) {
        const target = join(notesDir, file);
        if (!resolve(target).startsWith(notesDir)) die(`refusing path outside the vault: ${file}`);
        await mkdir(dirname(target), { recursive: true });
        await writeFile(target, Buffer.from(String(b64), 'base64'));
    }

    console.log(`✓ unlocked ${Object.keys(files).length} file(s) to ${relative(repoRoot, notesDir)}`);
    console.log(`  locked at ${lockedAt}`);
    console.log('  notes/ is gitignored — re-run `lock` and commit vault.enc after editing');
}

async function status() {
    let size = 0;
    let present = true;
    try {
        size = (await stat(blobPath)).size;
    } catch {
        present = false;
    }
    const open = await walk(notesDir);
    console.log(`vault    ${present ? `${(size / 1024).toFixed(1)} KiB encrypted` : 'not created'}`);
    console.log(`notes/   ${open.length ? `${open.length} file(s) decrypted locally` : 'locked (no plaintext on disk)'}`);
    for (const f of open) console.log(`         ${f}`);
    if (open.length) console.log('\nplaintext is present — run `lock` before committing, and it is gitignored either way');
}

async function init() {
    // 32 bytes of entropy, base64url. Long enough that scrypt is not the last line.
    const generated = randomBytes(32).toString('base64url');
    let existing = '';
    try {
        existing = await readFile(envPath, 'utf8');
    } catch { /* no .env yet */ }

    if (/^\s*VAULT_PASSPHRASE=\S/m.test(existing)) {
        console.log('VAULT_PASSPHRASE is already set in .env — leaving it alone.');
        console.log('Overwriting it would make the existing vault permanently unreadable.');
        return;
    }

    const block = `${existing.endsWith('\n') || existing === '' ? '' : '\n'}`
        + '\n# Passphrase for the encrypted notes vault (scripts/vault.mjs).\n'
        + '# .env is gitignored. Losing this makes DevOps/vault/vault.enc unrecoverable —\n'
        + '# keep a copy in a password manager and share it the same way.\n'
        + `VAULT_PASSPHRASE=${generated}\n`;
    await writeFile(envPath, existing + block, 'utf8');
    console.log('✓ generated VAULT_PASSPHRASE and appended it to .env (gitignored)');
    console.log('  put a copy in your password manager now; there is no recovery path.');
}

const command = process.argv[2] ?? 'status';
const commands = { lock, unlock, status, init };
if (!commands[command]) die(`unknown command "${command}". Use: lock | unlock | status | init`);

await commands[command]();
