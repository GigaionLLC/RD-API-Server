/**
 * Table-driven protobuf encoder/decoder.
 *
 * Messages are described by plain-object tables (see rendezvous.js / message.js) that
 * transcribe docs/spec/06-schema.md directly. There is no code generation and no
 * runtime `.proto` parsing — which is what keeps the viewer free of `'unsafe-eval'`.
 *
 * Descriptor shape:
 *   {
 *     name:   'PunchHoleRequest',
 *     fields: { <tag>: [ <name>, <type>, <flags> ] },
 *     oneofs: { <groupName>: ['fieldA', 'fieldB'] }   // optional
 *   }
 *
 * `type` is either a scalar name from TYPES or a nested descriptor object.
 * `flags` is optional; currently only REPEATED.
 *
 * Decoded objects carry `$case` for each oneof group, naming the member that was set
 * (last-one-wins, per proto3).
 */

import { Reader, Writer, WireError, WT_VARINT, WT_FIXED64, WT_BYTES } from './wire.js';

export const REPEATED = 1;

/**
 * Scalar type table: wire type, reader method, writer method, and whether a repeated
 * field of this type may be packed. Only numeric/enum/bool types are packable — see
 * docs/spec/06-schema.md §1.3.
 */
const TYPES = {
    int32: { wt: WT_VARINT, read: 'int32', write: 'int32', packable: true, zero: 0 },
    uint32: { wt: WT_VARINT, read: 'uint32', write: 'uint32', packable: true, zero: 0 },
    sint32: { wt: WT_VARINT, read: 'sint32', write: 'sint32', packable: true, zero: 0 },
    enum: { wt: WT_VARINT, read: 'uint32', write: 'uint32', packable: true, zero: 0 },
    bool: { wt: WT_VARINT, read: 'bool', write: 'bool', packable: true, zero: false },
    int64: { wt: WT_VARINT, read: 'int64', write: 'varint64', packable: true, zero: 0n },
    uint64: { wt: WT_VARINT, read: 'uint64', write: 'varint64', packable: true, zero: 0n },
    sint64: { wt: WT_VARINT, read: 'sint64', write: 'sint64', packable: true, zero: 0n },
    double: { wt: WT_FIXED64, read: 'double', write: 'double', packable: true, zero: 0 },
    string: { wt: WT_BYTES, read: 'string', write: 'string', packable: false, zero: '' },
    bytes: { wt: WT_BYTES, read: 'bytes', write: 'bytes', packable: false, zero: null },
};

/** @param {any} type */
function isMessage(type) {
    return typeof type === 'object' && type !== null;
}

/**
 * proto3 omits fields equal to their default. The peer reconstructs them as zero, so
 * this is lossless — with one exception the callers must know about: a present-but-empty
 * `bytes` field is indistinguishable from an absent one on the wire. `PunchHoleResponse`
 * relies on exactly that (empty `socket_addr` *is* the failure signal), which works
 * because the decoder defaults it to an empty array rather than leaving it undefined.
 * @param {any} value
 * @param {any} type
 */
function isDefault(value, type) {
    if (value === undefined || value === null) return true;
    if (isMessage(type)) return false;
    const spec = TYPES[type];
    if (type === 'bytes') return value.length === 0;
    return value === spec.zero;
}

/* -------------------------------------------------------------------------- */
/* Encode                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * @param {object} desc
 * @param {Record<string, any>} obj
 * @returns {Uint8Array}
 */
export function encode(desc, obj) {
    const w = new Writer();
    encodeInto(w, desc, obj);
    return w.finish();
}

/**
 * @param {Writer} w
 * @param {object} desc
 * @param {Record<string, any>} obj
 */
export function encodeInto(w, desc, obj) {
    assertOneofs(desc, obj);

    for (const tagStr of Object.keys(desc.fields)) {
        const tag = Number(tagStr);
        const [name, type, flags = 0] = desc.fields[tag];
        const value = obj[name];
        if (value === undefined || value === null) continue;

        if (flags & REPEATED) {
            if (!Array.isArray(value)) throw new WireError(`${desc.name}.${name} must be an array`);
            if (value.length === 0) continue;
            encodeRepeated(w, tag, type, value, desc.name, name);
            continue;
        }

        if (isDefault(value, type)) continue;
        encodeSingle(w, tag, type, value, desc.name, name);
    }
}

/**
 * @param {Writer} w
 * @param {number} tag
 * @param {any} type
 * @param {any} value
 * @param {string} msgName
 * @param {string} fieldName
 */
function encodeSingle(w, tag, type, value, msgName, fieldName) {
    if (isMessage(type)) {
        w.tag(tag, WT_BYTES);
        w.bytes(encode(type, value));
        return;
    }
    const spec = TYPES[type];
    if (!spec) throw new WireError(`${msgName}.${fieldName}: unknown type ${type}`);
    w.tag(tag, spec.wt);
    w[spec.write](value);
}

/**
 * Packable scalars are written packed, which is the proto3 default and what RustDesk
 * emits. Non-packable types get one tagged entry each.
 * @param {Writer} w
 * @param {number} tag
 * @param {any} type
 * @param {any[]} values
 * @param {string} msgName
 * @param {string} fieldName
 */
function encodeRepeated(w, tag, type, values, msgName, fieldName) {
    if (isMessage(type) || !TYPES[type].packable) {
        for (const v of values) encodeSingle(w, tag, type, v, msgName, fieldName);
        return;
    }
    const spec = TYPES[type];
    const inner = new Writer();
    for (const v of values) inner[spec.write](v);
    w.tag(tag, WT_BYTES);
    w.bytes(inner.finish());
}

/**
 * @param {object} desc
 * @param {Record<string, any>} obj
 */
function assertOneofs(desc, obj) {
    if (!desc.oneofs) return;
    for (const group of Object.keys(desc.oneofs)) {
        const set = desc.oneofs[group].filter((n) => obj[n] !== undefined && obj[n] !== null);
        if (set.length > 1) {
            throw new WireError(`${desc.name}.${group}: oneof has ${set.length} members set (${set.join(', ')})`);
        }
    }
}

/* -------------------------------------------------------------------------- */
/* Decode                                                                     */
/* -------------------------------------------------------------------------- */

/**
 * @param {object} desc
 * @param {Uint8Array | Reader} input
 * @returns {Record<string, any>}
 */
export function decode(desc, input) {
    const r = input instanceof Reader ? input : new Reader(input);
    /** @type {Record<string, any>} */
    const out = {};

    // Repeated fields always exist as arrays, and `bytes` fields default to empty rather
    // than undefined so `socket_addr.length === 0` is a valid test without a guard.
    for (const tagStr of Object.keys(desc.fields)) {
        const [name, type, flags = 0] = desc.fields[tagStr];
        if (flags & REPEATED) out[name] = [];
        else if (type === 'bytes') out[name] = new Uint8Array(0);
    }

    /** @type {Record<string, string>} */
    const oneofOf = {};
    if (desc.oneofs) {
        for (const group of Object.keys(desc.oneofs)) {
            for (const name of desc.oneofs[group]) oneofOf[name] = group;
        }
    }

    while (!r.eof) {
        const key = r.uint32();
        const tag = key >>> 3;
        const wireType = key & 0x07;
        const field = desc.fields[tag];

        if (!field) {
            r.skip(wireType);
            continue;
        }

        const [name, type, flags = 0] = field;
        const repeated = (flags & REPEATED) !== 0;

        if (isMessage(type)) {
            const value = decode(type, r.sub());
            if (repeated) out[name].push(value);
            else out[name] = value;
        } else {
            const spec = TYPES[type];
            if (!spec) throw new WireError(`${desc.name}.${name}: unknown type ${type}`);

            if (repeated && spec.packable && wireType === WT_BYTES) {
                // Packed form. A conformant decoder must also accept the unpacked form,
                // handled by the branch below.
                const packed = r.sub();
                while (!packed.eof) out[name].push(packed[spec.read]());
            } else if (wireType !== spec.wt) {
                // Wire type disagrees with the schema — treat as unknown rather than
                // misparsing, so a protocol change degrades instead of corrupting.
                r.skip(wireType);
                continue;
            } else if (repeated) {
                out[name].push(r[spec.read]());
            } else {
                out[name] = r[spec.read]();
            }
        }

        if (oneofOf[name]) out.$case = name; // last one wins, per proto3
    }

    return out;
}

/**
 * Convenience for the `Message` / `RendezvousMessage` wrappers: builds the outer object
 * for a single oneof member.
 * @param {object} desc
 * @param {string} member
 * @param {any} value
 * @returns {Uint8Array}
 */
export function encodeOneof(desc, member, value) {
    return encode(desc, { [member]: value });
}
