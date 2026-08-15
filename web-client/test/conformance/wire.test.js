/**
 * Wire-primitive conformance. Cases 7c and 7e from PLAN.md §6, plus the varint
 * boundaries and sign-extension rules from docs/spec/06-schema.md §1.2.
 *
 * Written before src/protocol/wire.js was trusted; every case here corresponds to a
 * way a hand-rolled protobuf codec is known to break silently.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { Reader, Writer, WireError, WT_VARINT, WT_FIXED64, WT_BYTES, WT_FIXED32 } from '../../src/protocol/wire.js';

/** @param {(w: Writer) => void} fn */
function written(fn) {
    const w = new Writer();
    fn(w);
    return w.finish();
}

/* -------------------------------------------------------------------------- */
/* Varint boundaries (PLAN.md §6 case 7e)                                     */
/* -------------------------------------------------------------------------- */

test('varint round-trips at every byte-length boundary', () => {
    const cases = [0, 1, 127, 128, 300, 16383, 16384, 2097151, 2097152,
        268435455, 268435456, 2 ** 31 - 1, 2 ** 32 - 1, 2 ** 53 - 1];
    for (const value of cases) {
        const buf = written((w) => w.varint(value));
        assert.equal(new Reader(buf).uint32() >>> 0, value >>> 0, `uint32 ${value}`);
        // Verify the encoded length matches the spec's thresholds.
        const expectedLen = value === 0 ? 1 : Math.ceil(Math.log2(value + 1) / 7);
        assert.equal(buf.length, Math.max(1, expectedLen), `byte length for ${value}`);
    }
});

test('varint above 2^32 does not truncate', () => {
    // A naive `v >>>= 7` implementation silently corrupts here.
    const value = 2 ** 40 + 12345;
    const buf = written((w) => w.varint(value));
    assert.equal(new Reader(buf).uint64(), BigInt(value));
});

test('varint rejects negative and non-integer input', () => {
    assert.throws(() => written((w) => w.varint(-1)), WireError);
    assert.throws(() => written((w) => w.varint(1.5)), WireError);
});

test('varint longer than 10 bytes is rejected', () => {
    const buf = new Uint8Array(11).fill(0x80);
    assert.throws(() => new Reader(buf)._varintParts(), WireError);
});

test('truncated varint is rejected rather than returning a partial value', () => {
    assert.throws(() => new Reader(new Uint8Array([0x80])).uint32(), WireError);
});

/* -------------------------------------------------------------------------- */
/* Signed integers — zigzag vs sign-extension (case 7b)                        */
/* -------------------------------------------------------------------------- */

test('int32 sign-extends negatives to 10 bytes', () => {
    const buf = written((w) => w.int32(-1));
    assert.equal(buf.length, 10, 'negative int32 must occupy 10 bytes');
    assert.equal(new Reader(buf).int32(), -1);
});

test('int32 round-trips the full range', () => {
    for (const v of [0, 1, -1, 127, -127, 2 ** 31 - 1, -(2 ** 31)]) {
        assert.equal(new Reader(written((w) => w.int32(v))).int32(), v, `int32 ${v}`);
    }
});

test('sint32 zigzag round-trips and is compact for small negatives', () => {
    for (const v of [0, -1, 1, -2, 2, 63, -64, 2 ** 31 - 1, -(2 ** 31)]) {
        assert.equal(new Reader(written((w) => w.sint32(v))).sint32(), v, `sint32 ${v}`);
    }
    // The whole point of zigzag: -1 is one byte, not ten.
    assert.equal(written((w) => w.sint32(-1)).length, 1);
});

test('sint32 and int32 encodings differ — confusing them corrupts the -1 sentinel', () => {
    // FileTransferBlock.file_num is sint32; FileTransferSendRequest.file_num is int32.
    const asSint = written((w) => w.sint32(-1));
    const asInt = written((w) => w.int32(-1));
    assert.notDeepEqual(asSint, asInt);
    // Decoding a sint32 field with int32 semantics yields 1, not -1 — silently wrong.
    assert.equal(new Reader(asSint).int32(), 1);
});

test('64-bit integers round-trip without precision loss', () => {
    const big = 0x7fffffffffffffffn;
    assert.equal(new Reader(written((w) => w.varint64(big))).uint64(), big);
    assert.equal(new Reader(written((w) => w.varint64(-1n))).int64(), -1n);
    for (const v of [0n, -1n, 1n, -2n, 2n, 0x7fffffffffffffffn, -0x8000000000000000n]) {
        assert.equal(new Reader(written((w) => w.sint64(v))).sint64(), v, `sint64 ${v}`);
    }
});

test('uint64 preserves opaque handles above 2^53', () => {
    // cursor_id is a cache key; Number would round it and break shape lookup.
    const handle = 0x0123456789abcdefn;
    assert.equal(new Reader(written((w) => w.varint64(handle))).uint64(), handle);
    assert.notEqual(Number(handle), 0x0123456789abcdef); // precision really is lost as Number
});

/* -------------------------------------------------------------------------- */
/* Other scalars                                                              */
/* -------------------------------------------------------------------------- */

test('bool treats any non-zero varint as true', () => {
    assert.equal(new Reader(written((w) => w.bool(true))).bool(), true);
    assert.equal(new Reader(written((w) => w.bool(false))).bool(), false);
    assert.equal(new Reader(new Uint8Array([0x02])).bool(), true);
});

test('double round-trips little-endian', () => {
    for (const v of [0, 1, -1, 1.5, 2.0, 1e-300, 1e300]) {
        assert.equal(new Reader(written((w) => w.double(v))).double(), v, `double ${v}`);
    }
    // DisplayInfo.scale is the only double in the protocol; verify byte order explicitly.
    assert.deepEqual(written((w) => w.double(2)), new Uint8Array([0, 0, 0, 0, 0, 0, 0, 0x40]));
});

test('string round-trips UTF-8 including astral planes', () => {
    for (const s of ['', 'hello', 'héllo wörld', '日本語', '👋🏽 emoji']) {
        assert.equal(new Reader(written((w) => w.string(s))).string(), s, `string ${s}`);
    }
});

test('bytes round-trip and empty is distinguishable from absent', () => {
    // PunchHoleResponse.socket_addr being empty IS the failure signal, so a present-but-
    // empty bytes field must decode as a zero-length value, not be skipped.
    const buf = written((w) => {
        w.tag(1, WT_BYTES);
        w.bytes(new Uint8Array(0));
    });
    const r = new Reader(buf);
    assert.equal(r.uint32(), 1 * 8 + WT_BYTES);
    const v = r.bytes();
    assert.equal(v.length, 0);
    assert.ok(v instanceof Uint8Array);
});

test('bytes returns a view, not a copy', () => {
    const buf = written((w) => w.bytes(new Uint8Array([1, 2, 3])));
    const view = new Reader(buf).bytes();
    assert.equal(view.buffer, buf.buffer, 'zero-copy: must share the backing buffer');
});

/* -------------------------------------------------------------------------- */
/* Tags and unknown-field skipping (case 7c)                                   */
/* -------------------------------------------------------------------------- */

test('tag packs field number and wire type', () => {
    const buf = written((w) => w.tag(14, WT_VARINT));
    const key = new Reader(buf).uint32();
    assert.equal(key >>> 3, 14);
    assert.equal(key & 0x07, WT_VARINT);
});

test('tag survives high field numbers', () => {
    for (const fieldNo of [1, 15, 16, 2047, 2048, 536870911]) {
        const key = new Reader(written((w) => w.tag(fieldNo, WT_BYTES))).uint32();
        assert.equal(key >>> 3, fieldNo, `field ${fieldNo}`);
    }
});

test('unknown fields of every wire type skip cleanly and the rest survives', () => {
    // Simulates RustDesk adding fields we do not know: an unknown field of each wire
    // type is interleaved with a known trailing field that must still decode.
    const buf = written((w) => {
        w.tag(900, WT_VARINT);
        w.varint(300);
        w.tag(901, WT_FIXED64);
        w.double(1.5);
        w.tag(902, WT_BYTES);
        w.bytes(new Uint8Array([9, 9, 9]));
        w.tag(903, WT_FIXED32);
        w._ensure(4);
        w.len += 4;
        w.tag(7, WT_BYTES);
        w.string('survived');
    });

    const r = new Reader(buf);
    let found = null;
    while (!r.eof) {
        const key = r.uint32();
        const fieldNo = key >>> 3;
        const wireType = key & 0x07;
        if (fieldNo === 7) found = r.string();
        else r.skip(wireType);
    }
    assert.equal(found, 'survived');
});

test('skip rejects obsolete group wire types', () => {
    assert.throws(() => new Reader(new Uint8Array([0])).skip(3), WireError);
    assert.throws(() => new Reader(new Uint8Array([0])).skip(4), WireError);
});

test('sub() bounds a nested message and leaves the parent positioned correctly', () => {
    const inner = written((w) => {
        w.tag(1, WT_VARINT);
        w.varint(42);
    });
    const buf = written((w) => {
        w.tag(3, WT_BYTES);
        w.bytes(inner);
        w.tag(4, WT_VARINT);
        w.varint(7);
    });

    const r = new Reader(buf);
    assert.equal(r.uint32() >>> 3, 3);
    const nested = r.sub();
    assert.equal(nested.uint32() >>> 3, 1);
    assert.equal(nested.uint32(), 42);
    assert.ok(nested.eof, 'sub-reader must stop at the nested boundary');
    assert.equal(r.uint32() >>> 3, 4, 'parent must resume after the nested block');
    assert.equal(r.uint32(), 7);
});

test('writer grows past its initial capacity', () => {
    const big = new Uint8Array(10_000).fill(7);
    const buf = written((w) => w.bytes(big));
    const out = new Reader(buf).bytes();
    assert.equal(out.length, 10_000);
    assert.equal(out[9_999], 7);
});
