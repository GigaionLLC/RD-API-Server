/**
 * Zero-dependency static server for the development viewer.
 *
 * Serves the package root so `src/ui/viewer.html` can import from `src/` and `vendor/`
 * as plain ES modules — the same files that ship, with no bundler in between.
 *
 *   node tools/serve.mjs [--port 8788]
 *
 * Bind to localhost only: the viewer takes a peer password, and `localhost` is a secure
 * context, so WebCodecs works over plain http without a certificate.
 */

import { createServer } from 'node:http';
import { readFile } from 'node:fs/promises';
import { extname, join, normalize, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(fileURLToPath(new URL('..', import.meta.url)));
const portArg = process.argv.indexOf('--port');
const port = portArg > -1 ? Number(process.argv[portArg + 1]) : 8788;
const host = process.argv.includes('--any') ? '0.0.0.0' : '127.0.0.1';

const TYPES = {
    '.html': 'text/html; charset=utf-8',
    '.js': 'text/javascript; charset=utf-8',
    '.mjs': 'text/javascript; charset=utf-8',
    '.json': 'application/json; charset=utf-8',
    '.css': 'text/css; charset=utf-8',
    '.wasm': 'application/wasm',
};

const server = createServer(async (req, res) => {
    const url = new URL(req.url, `http://${req.headers.host}`);
    const rel = normalize(decodeURIComponent(url.pathname)).replace(/^([/\\])+/, '');
    const path = join(root, rel === '' ? 'src/ui/viewer.html' : rel);

    // Containment check: normalize() collapses traversal, this rejects what escapes.
    if (!path.startsWith(root)) {
        res.writeHead(403).end('forbidden');
        return;
    }

    try {
        const body = await readFile(path);
        res.writeHead(200, {
            'content-type': TYPES[extname(path)] ?? 'application/octet-stream',
            'cache-control': 'no-store',
        });
        res.end(body);
    } catch {
        res.writeHead(404).end('not found');
    }
});

server.listen(port, host, () => {
    console.log(`serving ${root}`);
    console.log(`  http://localhost:${port}/src/ui/viewer.html`);
});
