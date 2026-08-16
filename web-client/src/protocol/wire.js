/**
 * Protobuf wire-format primitives.
 *
 * Implements only what the RustDesk protocol uses: wire types 0 (varint), 1 (fixed64),
 * and 2 (length-delimited). Wire type 5 (fixed32) is skip-only; groups (3, 4) are
 * obsolete and never appear.
 *
 * Spec: docs/spec/06-schema.md §1.
 *
 * 64-bit values are exchanged as BigInt so opaque handles (`cursor_id`) and large
 * sizes survive intact — Number would silently lose precision above 2^53.
 */

export const WT_VARINT = 0;
export const WT_FIXED64 = 1;
export const WT_BYTES = 2;
export const WT_FIXED32 = 5;

const MAX_VARINT_BYTES = 10;

/** Thrown for any malformed input. Callers treat this as a fatal protocol error. */
export class WireError extends Error {
    /** @param {string} message */
    constructor(message) {
        super(message);
        this.name = 'WireError';
    }
}

/* -------------------------------------------------------------------------- */
/* Writer                                                                     */
/* -------------------------------------------------------------------------- */

export class Writer {
    constructor() {
        /** @type {Uint8Array} */
        this.buf = new Uint8Array(256);
        this.len = 0;
    }

    /** @param {number} extra */
    _ensure(extra) {
        const needed = this.len + extra;
        if (needed <= this.buf.length) return;
        let cap = this.buf.length * 2;
        while (cap < needed) cap *= 2;
        const next = new Uint8Array(cap);
        next.set(this.buf.subarray(0, this.len));
        this.buf = next;
    }

    /** @param {number} b */
    byte(b) {
        this._ensure(1);
        this.buf[this.len++] = b & 0xff;
    }

    /**
     * Unsigned varint from a Number. Value must be a non-negative integer < 2^53.
     * @param {number} value
     */
    varint(value) {
        if (!Number.isInteger(value) || value < 0) {
            throw new WireError(`varint expects a non-negative integer, got ${value}`);
        }
        this._ensure(MAX_VARINT_BYTES);
        let v = value;
        while (v > 0x7f) {
            // Not `v >>>= 7`: that truncates to 32 bits and corrupts values above 2^32.
            this.buf[this.len++] = (v & 0x7f) | 0x80;
            v = Math.floor(v / 128);
        }
        this.buf[this.len++] = v;
    }

    /** @param {bigint} value Unsigned varint from a BigInt (two's complement for negatives). */
    varint64(value) {
        this._ensure(MAX_VARINT_BYTES);
        let v = BigInt.asUintN(64, value);
        while (v > 0x7fn) {
            this.buf[this.len++] = Number(v & 0x7fn) | 0x80;
            v >>= 7n;
        }
        this.buf[this.len++] = Number(v);
    }

    /**
     * proto3 `int32`. Negative values are sign-extended to 64 bits, i.e. 10 bytes on
     * the wire — this is required by the format, not an optimisation we may skip.
     * @param {number} value
     */
    int32(value) {
        const v = value | 0;
        if (v < 0) this.varint64(BigInt(v));
        else this.varint(v);
    }

    /** @param {number} value proto3 `uint32`. */
    uint32(value) {
        this.varint(value >>> 0);
    }

    /** @param {number} value proto3 `sint32` (zigzag). */
    sint32(value) {
        this.varint(((value << 1) ^ (value >> 31)) >>> 0);
    }

    /** @param {bigint} value proto3 `sint64` (zigzag). */
    sint64(value) {
        const v = BigInt.asIntN(64, value);
        this.varint64(BigInt.asUintN(64, (v << 1n) ^ (v >> 63n)));
    }

    /** @param {boolean} value */
    bool(value) {
        this.byte(value ? 1 : 0);
    }

    /** @param {number} value IEEE-754 double, little-endian (wire type 1). */
    double(value) {
        this._ensure(8);
        new DataView(this.buf.buffer, this.buf.byteOffset + this.len, 8).setFloat64(0, value, true);
        this.len += 8;
    }

    /** @param {Uint8Array} value */
    bytes(value) {
        this.varint(value.length);
        this._ensure(value.length);
        this.buf.set(value, this.len);
        this.len += value.length;
    }

    /** @param {string} value */
    string(value) {
        this.bytes(new TextEncoder().encode(value));
    }

    /**
     * @param {number} fieldNo
     * @param {number} wireType
     */
    tag(fieldNo, wireType) {
        this.varint(fieldNo * 8 + wireType);
    }

    /** @returns {Uint8Array} A copy of the written bytes. */
    finish() {
        return this.buf.slice(0, this.len);
    }
}

/* -------------------------------------------------------------------------- */
/* Reader                                                                     */
/* -------------------------------------------------------------------------- */

export class Reader {
    /**
     * @param {Uint8Array} buf
     * @param {number} [start]
     * @param {number} [end]
     */
    constructor(buf, start = 0, end = buf.length) {
        this.buf = buf;
        this.pos = start;
        this.end = end;
    }

    get eof() {
        return this.pos >= this.end;
    }

    /** @param {number} n */
    _need(n) {
        if (this.pos + n > this.end) throw new WireError('unexpected end of buffer');
    }

    /**
     * Consumes one varint and returns it as two 32-bit halves. Splitting here keeps the
     * common 32-bit case off BigInt entirely; only true 64-bit readers pay for it.
     * @returns {{lo: number, hi: number}}
     */
    _varintParts() {
        let lo = 0;
        let hi = 0;
        let b = 0;

        for (let shift = 0; shift < 28; shift += 7) {
            this._need(1);
            b = this.buf[this.pos++];
            lo |= (b & 0x7f) << shift;
            if ((b & 0x80) === 0) return { lo: lo >>> 0, hi: 0 };
        }

        // Fifth byte straddles the halves: 4 bits complete `lo`, 3 bits start `hi`.
        this._need(1);
        b = this.buf[this.pos++];
        lo |= (b & 0x0f) << 28;
        hi = (b >>> 4) & 0x07;
        if ((b & 0x80) === 0) return { lo: lo >>> 0, hi: hi >>> 0 };

        for (let shift = 3; shift < 32; shift += 7) {
            this._need(1);
            b = this.buf[this.pos++];
            hi |= (b & 0x7f) << shift;
            if ((b & 0x80) === 0) return { lo: lo >>> 0, hi: hi >>> 0 };
        }

        throw new WireError('varint longer than 10 bytes');
    }

    /** @returns {number} proto3 `uint32` / enum / tag key. */
    uint32() {
        return this._varintParts().lo;
    }

    /** @returns {number} proto3 `int32`. Sign-extended negatives occupy 10 bytes. */
    int32() {
        return this._varintParts().lo | 0;
    }

    /** @returns {number} proto3 `sint32` (zigzag). */
    sint32() {
        const lo = this._varintParts().lo;
        return (lo >>> 1) ^ -(lo & 1);
    }

    /** @returns {bigint} proto3 `uint64`. */
    uint64() {
        const { lo, hi } = this._varintParts();
        return (BigInt(hi) << 32n) | BigInt(lo);
    }

    /** @returns {bigint} proto3 `int64`. */
    int64() {
        return BigInt.asIntN(64, this.uint64());
    }

    /** @returns {bigint} proto3 `sint64` (zigzag). */
    sint64() {
        const v = this.uint64();
        return (v >> 1n) ^ -(v & 1n);
    }

    /** @returns {boolean} */
    bool() {
        const { lo, hi } = this._varintParts();
        return lo !== 0 || hi !== 0;
    }

    /** @returns {number} */
    double() {
        this._need(8);
        const v = new DataView(this.buf.buffer, this.buf.byteOffset + this.pos, 8).getFloat64(0, true);
        this.pos += 8;
        return v;
    }

    /**
     * @returns {Uint8Array} A view into the source buffer — no copy. Callers that retain
     * the value beyond the current frame must copy it themselves.
     */
    bytes() {
        const len = this.uint32();
        this._need(len);
        const view = this.buf.subarray(this.pos, this.pos + len);
        this.pos += len;
        return view;
    }

    /** @returns {string} */
    string() {
        return new TextDecoder().decode(this.bytes());
    }

    /** @returns {Reader} A sub-reader over the next length-delimited block. */
    sub() {
        const len = this.uint32();
        this._need(len);
        const r = new Reader(this.buf, this.pos, this.pos + len);
        this.pos += len;
        return r;
    }

    /**
     * Skips a field of unknown tag. Required for forward compatibility: without it the
     * first field RustDesk adds desynchronises the whole message.
     * @param {number} wireType
     */
    skip(wireType) {
        switch (wireType) {
            case WT_VARINT:
                this._varintParts();
                return;
            case WT_FIXED64:
                this._need(8);
                this.pos += 8;
                return;
            case WT_BYTES: {
                const len = this.uint32();
                this._need(len);
                this.pos += len;
                return;
            }
            case WT_FIXED32:
                this._need(4);
                this.pos += 4;
                return;
            default:
                throw new WireError(`cannot skip wire type ${wireType}`);
        }
    }
}
