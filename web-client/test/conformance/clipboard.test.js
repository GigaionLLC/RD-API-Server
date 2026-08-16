/**
 * Clipboard and version-gate conformance.
 *
 * The clipboard's failure modes are all silent: an ungated MultiClipboards is ignored by
 * an older peer, an unsuppressed echo makes the two sides trade the same text forever,
 * and a format with no browser MIME type either throws or writes garbage.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';
import zlib from 'node:zlib';

import { versionNumber, atLeast, supportsMultiClipboard, supportsPerDisplayRefresh } from '../../src/protocol/version.js';
import { ClipboardFormat } from '../../src/protocol/enums.js';

// Blob is available in Node 18+; ClipboardItem and navigator.clipboard are not, which is
// the point — the send path and the gating must work without them.
const { ClipboardSync } = await import('../../src/clipboard.js');

const utf8 = (s) => new TextEncoder().encode(s);

/** Captures outbound messages and lets the peer version be varied per test. */
function sync(peer = { version: '1.4.9', platform: 'Windows' }, enabled = true) {
    const sent = [];
    const s = new ClipboardSync({ send: (m) => sent.push(m), peerInfo: () => peer, enabled });
    return { s, sent };
}

/** A minimal ClipboardEvent stand-in. */
const pasteEvent = (text, html = '') => ({
    clipboardData: { getData: (t) => (t === 'text/plain' ? text : t === 'text/html' ? html : '') },
});

/* -------------------------------------------------------------------------- */
/* Version comparison                                                         */
/* -------------------------------------------------------------------------- */

test('versions compare numerically, not lexically', () => {
    // "1.10.0" < "1.9.0" as strings, which is exactly the bug this avoids.
    assert.ok(versionNumber('1.10.0') > versionNumber('1.9.0'));
    assert.ok(versionNumber('2.0.0') > versionNumber('1.99.99'));
    assert.equal(versionNumber('1.4.9'), 1_004_009);
});

test('version suffixes and junk are tolerated', () => {
    assert.equal(versionNumber('1.4.9-1'), versionNumber('1.4.9'));
    assert.equal(versionNumber('1.4.9-beta.2'), versionNumber('1.4.9'));
    assert.equal(versionNumber(''), 0);
    assert.equal(versionNumber('unknown'), 0);
    assert.equal(versionNumber(undefined), 0);
});

test('atLeast is inclusive at the boundary', () => {
    assert.equal(atLeast('1.2.4', '1.2.4'), true);
    assert.equal(atLeast('1.2.3', '1.2.4'), false);
    assert.equal(atLeast('1.3.0', '1.2.4'), true);
    // An unknown version must not unlock gated features.
    assert.equal(atLeast('', '1.2.4'), false);
});

test('per-display refresh is gated at 1.2.4', () => {
    // Below this the only option restarts capture for EVERY display, not one.
    assert.equal(supportsPerDisplayRefresh({ version: '1.2.4' }), true);
    assert.equal(supportsPerDisplayRefresh({ version: '1.2.3' }), false);
});

test('multi-clipboard gating covers the platform exceptions', () => {
    assert.equal(supportsMultiClipboard({ version: '1.3.0', platform: 'Windows' }), true);
    assert.equal(supportsMultiClipboard({ version: '1.2.9', platform: 'Windows' }), false);
    // Android needed an extra patch release beyond the general gate.
    assert.equal(supportsMultiClipboard({ version: '1.3.0', platform: 'Android' }), false);
    assert.equal(supportsMultiClipboard({ version: '1.3.3', platform: 'Android' }), true);
    // iOS never advertises it, and an empty platform is unknown rather than permissive.
    assert.equal(supportsMultiClipboard({ version: '1.4.9', platform: 'iOS' }), false);
    assert.equal(supportsMultiClipboard({ version: '1.4.9', platform: '' }), false);
});

/* -------------------------------------------------------------------------- */
/* Sending                                                                    */
/* -------------------------------------------------------------------------- */

test('a modern peer receives MultiClipboards with text and html', () => {
    const { s, sent } = sync();
    assert.equal(s.sendFromPaste(pasteEvent('hello', '<b>hello</b>')), true);
    assert.equal(sent.length, 1);
    const entries = sent[0].multi_clipboards.clipboards;
    assert.equal(entries.length, 2);
    assert.equal(entries[0].format, ClipboardFormat.Text);
    assert.equal(entries[1].format, ClipboardFormat.Html);
    assert.deepEqual(entries[0].content, utf8('hello'));
});

test('an older peer receives a single Clipboard carrying only text', () => {
    // Sending MultiClipboards to a peer below 1.3.0 is silently ignored — the paste just
    // appears not to work.
    const { s, sent } = sync({ version: '1.2.0', platform: 'Windows' });
    assert.equal(s.sendFromPaste(pasteEvent('hello', '<b>hello</b>')), true);
    assert.equal(sent[0].$case, undefined);
    assert.ok(sent[0].clipboard, 'must be a single Clipboard');
    assert.equal(sent[0].clipboard.format, ClipboardFormat.Text);
    assert.equal(sent[0].multi_clipboards, undefined);
});

test('html alone is not sent to a peer that cannot take it', () => {
    const { s, sent } = sync({ version: '1.2.0', platform: 'Windows' });
    assert.equal(s.sendFromPaste(pasteEvent('', '<b>only html</b>')), false);
    assert.equal(sent.length, 0);
});

test('outbound content is never marked compressed', () => {
    // The vendored zstd is decompress-only, and `compress` is per-message, so sending
    // uncompressed is legal and avoids shipping a compressor.
    const { s, sent } = sync();
    s.sendFromPaste(pasteEvent('plain'));
    assert.equal(sent[0].multi_clipboards.clipboards[0].compress, false);
});

test('an empty paste sends nothing', () => {
    const { s, sent } = sync();
    assert.equal(s.sendFromPaste(pasteEvent('', '')), false);
    assert.equal(s.sendFromPaste({ clipboardData: null }), false);
    assert.equal(sent.length, 0);
});

test('sendText works without a paste event', () => {
    const { s, sent } = sync();
    assert.equal(s.sendText('typed'), true);
    assert.deepEqual(sent[0].multi_clipboards.clipboards[0].content, utf8('typed'));
    assert.equal(s.sendText(''), false);
});

/* -------------------------------------------------------------------------- */
/* Receiving and echo suppression                                             */
/* -------------------------------------------------------------------------- */

test('inbound text is buffered for the next user gesture', () => {
    // Writing the clipboard requires transient activation, so it cannot happen on arrival.
    const { s } = sync();
    assert.equal(s.receive([{ format: ClipboardFormat.Text, content: utf8('from peer') }]), true);
    assert.equal(s.stats().pending, true);
    assert.equal(s.stats().received, 1);
});

test('compressed inbound content is decompressed', { skip: typeof zlib.zstdCompressSync !== 'function' }, () => {
    const { s } = sync();
    const content = new Uint8Array(zlib.zstdCompressSync(Buffer.from('compressed text')));
    assert.equal(s.receive([{ format: ClipboardFormat.Text, content, compress: true }]), true);
    assert.equal(s.stats().dropped, 0);
});

test('an undecompressable entry is dropped, not fatal', () => {
    const { s } = sync();
    assert.equal(s.receive([{ format: ClipboardFormat.Text, content: new Uint8Array([1, 2, 3]), compress: true }]), false);
    assert.equal(s.stats().dropped, 1);
});

test('formats with no browser representation are dropped', () => {
    // Rtf, ImageSvg and Special cannot be expressed as a ClipboardItem.
    const { s } = sync();
    const dropped = s.receive([
        { format: ClipboardFormat.Rtf, content: utf8('{\\rtf1}') },
        { format: ClipboardFormat.ImageSvg, content: utf8('<svg/>') },
        { format: ClipboardFormat.Special, content: utf8('x'), special_name: 'XML Spreadsheet' },
    ]);
    assert.equal(dropped, false);
    assert.equal(s.stats().dropped, 3);
    assert.equal(s.stats().pending, false);
});

test('a known format alongside unknown ones still gets through', () => {
    const { s } = sync();
    assert.equal(s.receive([
        { format: ClipboardFormat.Rtf, content: utf8('{\\rtf1}') },
        { format: ClipboardFormat.Text, content: utf8('keep me') },
    ]), true);
    assert.equal(s.stats().dropped, 1);
    assert.equal(s.stats().pending, true);
});

test('content just applied locally is not echoed back', () => {
    // Peers mark their own writes with a synthetic entry a browser cannot produce, so
    // without this the two sides trade the same text indefinitely.
    const { s, sent } = sync();
    s.receive([{ format: ClipboardFormat.Text, content: utf8('round trip') }]);
    assert.equal(s.sendFromPaste(pasteEvent('round trip')), false, 'echo must be suppressed');
    assert.equal(sent.length, 0);

    assert.equal(s.sendFromPaste(pasteEvent('something else')), true, 'genuinely new content passes');
    assert.equal(sent.length, 1);
});

test('disabling stops both directions and clears the buffer', () => {
    const { s, sent } = sync();
    s.receive([{ format: ClipboardFormat.Text, content: utf8('x') }]);
    s.setEnabled(false);
    assert.equal(s.stats().pending, false, 'buffered peer content is discarded');
    assert.equal(s.receive([{ format: ClipboardFormat.Text, content: utf8('y') }]), false);
    assert.equal(s.sendFromPaste(pasteEvent('z')), false);
    assert.equal(sent.length, 0);
});

test('an empty entry list is ignored', () => {
    const { s } = sync();
    assert.equal(s.receive([]), false);
    assert.equal(s.receive(undefined), false);
});
