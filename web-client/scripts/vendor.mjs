/**
 * Vendors the audited crypto bundle into vendor/ as a native ES module.
 *
 * Mirrors the repository's existing scripts/copy-admin-vendor.mjs pattern: pinned
 * version, deterministic transform, license file copied alongside, and a --check mode
 * so CI can prove the committed artifact still matches upstream.
 *
 * Consumers never run npm. `npm i` here is a build-time step for maintainers only;
 * the committed vendor/ output is what ships.
 *
 *   node scripts/vendor.mjs           # regenerate vendor/
 *   node scripts/vendor.mjs --check   # verify vendor/ matches what we would generate
 */

import { createHash } from 'node:crypto';
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const vendorDir = resolve(root, 'vendor');

/**
 * tweetnacl ships UMD. Rather than eval or a global shim — both of which would fight the
 * strict CSP this client is built for — we rewrite the module boundary textually.
 *
 * The upstream file ends with the factory being handed either `module.exports` or a
 * global. We hand it a local object instead and export that. The assertions below make
 * an upstream layout change a loud failure rather than a silently broken bundle.
 */
const assets = [
    {
        pkg: 'tweetnacl',
        version: '1.0.3',
        source: 'node_modules/tweetnacl/nacl-fast.js',
        destination: 'tweetnacl/nacl.js',
        license: 'node_modules/tweetnacl/LICENSE',
        licenseDestination: 'tweetnacl/LICENSE',
        transform(src) {
            const tail = /\}\)\(typeof module !== 'undefined' && module\.exports \? module\.exports : \(self\.nacl = self\.nacl \|\| \{\}\)\);\s*$/;
            if (!tail.test(src)) {
                throw new Error('tweetnacl UMD tail not found — upstream layout changed, review before vendoring');
            }
            if (!src.includes('nacl.sign.open') || !src.includes('nacl.box.keyPair')) {
                throw new Error('tweetnacl source missing expected exports');
            }
            const body = src.replace(tail, '})(nacl);\n');
            return [
                '/* Vendored from tweetnacl@1.0.3 (public domain). See ./LICENSE.',
                ' * Transformed from UMD to an ES module by scripts/vendor.mjs — the module',
                ' * boundary is the only change; the implementation is untouched.',
                ' */',
                'const nacl = {};',
                body,
                'export default nacl;',
                '',
            ].join('\n');
        },
    },
];

/** @param {string} s */
function sha256(s) {
    return createHash('sha256').update(s).digest('hex').slice(0, 16);
}

const check = process.argv.includes('--check');
let failed = false;

for (const asset of assets) {
    const srcPath = resolve(root, asset.source);
    let src;
    try {
        src = await readFile(srcPath, 'utf8');
    } catch {
        console.error(`✗ ${asset.pkg}: ${asset.source} not found. Run: npm i ${asset.pkg}@${asset.version}`);
        failed = true;
        continue;
    }

    const out = asset.transform(src);
    const destPath = resolve(vendorDir, asset.destination);

    if (check) {
        let current;
        try {
            current = await readFile(destPath, 'utf8');
        } catch {
            console.error(`✗ ${asset.destination} missing`);
            failed = true;
            continue;
        }
        if (current !== out) {
            console.error(`✗ ${asset.destination} differs from upstream (${sha256(current)} vs ${sha256(out)})`);
            failed = true;
        } else {
            console.log(`✓ ${asset.destination} (${sha256(out)})`);
        }
        continue;
    }

    await mkdir(dirname(destPath), { recursive: true });
    await writeFile(destPath, out, 'utf8');
    console.log(`✓ wrote ${asset.destination} (${sha256(out)}, ${out.length} bytes)`);

    if (asset.license) {
        const licPath = resolve(vendorDir, asset.licenseDestination);
        await mkdir(dirname(licPath), { recursive: true });
        await writeFile(licPath, await readFile(resolve(root, asset.license), 'utf8'), 'utf8');
        console.log(`✓ wrote ${asset.licenseDestination}`);
    }
}

process.exit(failed ? 1 : 0);
