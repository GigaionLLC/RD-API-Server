/**
 * Copies the viewer into the Laravel application's public directory.
 *
 * There is no build step: `src/` and `vendor/` are the artifact, so this is a copy, not a
 * bundle. It mirrors the repository's existing scripts/copy-admin-vendor.mjs pattern,
 * including a --check mode so CI can prove the published copy matches the source.
 *
 *   node web-client/scripts/install-assets.mjs
 *   node web-client/scripts/install-assets.mjs --check
 *
 * Run it after changing anything under web-client/src or web-client/vendor.
 */

import { createHash } from 'node:crypto';
import { cp, mkdir, readFile, readdir, rm, stat } from 'node:fs/promises';
import { dirname, join, relative, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageRoot = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const repoRoot = resolve(packageRoot, '..');
const target = resolve(repoRoot, 'public/assets/webclient');

/** Directories copied verbatim, in the layout viewer.js expects at runtime. */
const TREES = [
    { from: 'src', to: '.' },
    { from: 'vendor', to: 'vendor' },
];

const check = process.argv.includes('--check');

/** @param {string} dir @returns {Promise<string[]>} Files, relative to `dir`. */
async function walk(dir) {
    /** @type {string[]} */
    const out = [];
    for (const entry of await readdir(dir, { withFileTypes: true })) {
        const full = join(dir, entry.name);
        if (entry.isDirectory()) out.push(...(await walk(full)).map((p) => join(entry.name, p)));
        else out.push(entry.name);
    }
    return out;
}

/** @param {Buffer} b */
const digest = (b) => createHash('sha256').update(b).digest('hex').slice(0, 12);

let failed = false;
let copied = 0;
let bytes = 0;

for (const tree of TREES) {
    const source = resolve(packageRoot, tree.from);
    const destination = resolve(target, tree.to);

    try {
        await stat(source);
    } catch {
        console.error(`✗ ${tree.from}/ is missing. Run: node scripts/vendor.mjs`);
        failed = true;
        continue;
    }

    const files = await walk(source);

    if (check) {
        for (const file of files) {
            const want = await readFile(join(source, file));
            let have;
            try {
                have = await readFile(join(destination, file));
            } catch {
                console.error(`✗ missing: ${relative(repoRoot, join(destination, file))}`);
                failed = true;
                continue;
            }
            if (!want.equals(have)) {
                console.error(`✗ stale: ${relative(repoRoot, join(destination, file))} (${digest(have)} != ${digest(want)})`);
                failed = true;
            }
        }
        continue;
    }

    // Remove first: a file deleted from src/ would otherwise linger and keep being served.
    await rm(destination, { recursive: true, force: true });
    await mkdir(destination, { recursive: true });
    await cp(source, destination, { recursive: true });

    for (const file of files) {
        copied++;
        bytes += (await stat(join(source, file))).size;
    }
}

if (check) {
    console.log(failed ? '✗ public assets differ from web-client/' : '✓ public assets match web-client/');
} else {
    console.log(`✓ installed ${copied} files (${(bytes / 1024).toFixed(0)} KiB) to ${relative(repoRoot, target)}`);
    console.log('  the viewer is served from /assets/webclient/ui/viewer.html');
}

process.exit(failed ? 1 : 0);
