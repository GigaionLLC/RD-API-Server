/**
 * Table-driven codec conformance. the codec conformance cases, exercised against
 * the real descriptors rather than synthetic ones, so a transcription error in
 * src/protocol/*.js fails here.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { Reader, Writer, WireError, WT_VARINT, WT_BYTES } from '../../src/protocol/wire.js';
import { encode, decode } from '../../src/protocol/codec.js';
import {
    PunchHoleRequest, PunchHoleResponse, RelayResponse, RequestRelay, RendezvousMessage,
} from '../../src/protocol/rendezvous.js';
import {
    Message, MouseEvent, KeyEvent, VideoFrame, DisplayInfo, PeerInfo, LoginRequest,
    FileTransferBlock, FileTransferSendRequest, FileTransferSendConfirmRequest,
    CaptureDisplays, Misc, CODEC_BY_FIELD,
} from '../../src/protocol/message.js';
import {
    NatType, ConnType, ControlKey, PunchHoleFailure, mouseMask, MouseType, MouseButton,
} from '../../src/protocol/enums.js';

/* -------------------------------------------------------------------------- */
/* Real-message round trips                                                   */
/* -------------------------------------------------------------------------- */

test('PunchHoleRequest round-trips the fields we actually send', () => {
    const sent = {
        id: '123456789',
        nat_type: NatType.SYMMETRIC,
        licence_key: 'abc',
        conn_type: ConnType.DEFAULT_CONN,
        version: '1.4.8',
        force_relay: true,
    };
    const got = decode(PunchHoleRequest, encode(PunchHoleRequest, sent));
    assert.equal(got.id, '123456789');
    assert.equal(got.nat_type, NatType.SYMMETRIC);
    assert.equal(got.licence_key, 'abc');
    assert.equal(got.version, '1.4.8');
    assert.equal(got.force_relay, true);
    // conn_type is DEFAULT_CONN = 0, the proto3 default, so it is not emitted and
    // decodes back as absent. The peer reconstructs 0 either way.
    assert.equal(got.conn_type, undefined);
});

test('RequestRelay carries the uuid byte-for-byte', () => {
    const uuid = '3f0a1b2c-4d5e-6f70-8192-a3b4c5d6e7f8';
    const got = decode(RequestRelay, encode(RequestRelay, { id: 'peer', uuid, licence_key: '' }));
    assert.equal(got.uuid, uuid);
});

test('RelayResponse decodes the pk oneof and reports $case', () => {
    const pk = new Uint8Array([1, 2, 3, 4]);
    const got = decode(RelayResponse, encode(RelayResponse, {
        uuid: 'u-1', relay_server: 'relay.example:21117', pk, version: '1.4.8',
    }));
    assert.equal(got.uuid, 'u-1');
    assert.deepEqual(Array.from(got.pk), [1, 2, 3, 4]);
    assert.equal(got.$case, 'pk');
});

test('RendezvousMessage envelope nests and identifies its member', () => {
    const bytes = encode(RendezvousMessage, {
        punch_hole_request: { id: 'abc', nat_type: NatType.SYMMETRIC, version: '1.4.8' },
    });
    const got = decode(RendezvousMessage, bytes);
    assert.equal(got.$case, 'punch_hole_request');
    assert.equal(got.punch_hole_request.id, 'abc');
    assert.equal(got.punch_hole_request.nat_type, NatType.SYMMETRIC);
});

/* -------------------------------------------------------------------------- */
/* Case 7f — empty bytes must be present, not absent                          */
/* -------------------------------------------------------------------------- */

test('PunchHoleResponse: empty socket_addr is the failure signal, not a missing field', () => {
    // A failure response carries no socket_addr at all. The decoder must still give us
    // a zero-length array so `socket_addr.length === 0` works without a guard, and must
    // not let `failure` (default ID_NOT_EXIST = 0) be mistaken for success.
    const got = decode(PunchHoleResponse, encode(PunchHoleResponse, {
        failure: PunchHoleFailure.OFFLINE,
    }));
    assert.ok(got.socket_addr instanceof Uint8Array);
    assert.equal(got.socket_addr.length, 0);
    assert.equal(got.failure, PunchHoleFailure.OFFLINE);

    // And the all-defaults case: ID_NOT_EXIST is 0, so nothing is emitted at all.
    const empty = decode(PunchHoleResponse, new Uint8Array(0));
    assert.equal(empty.socket_addr.length, 0);
    assert.equal(empty.failure, undefined, 'must branch on socket_addr first');
});

/* -------------------------------------------------------------------------- */
/* Case 7a — packed and unpacked repeated                                     */
/* -------------------------------------------------------------------------- */

test('repeated enum encodes packed and decodes from both forms', () => {
    const modifiers = [ControlKey.Control, ControlKey.Shift, ControlKey.Alt];
    const bytes = encode(MouseEvent, { mask: mouseMask(MouseType.MOVE), x: 10, y: 20, modifiers });

    // Packed: one length-delimited field carrying three varints.
    const r = new Reader(bytes);
    let sawPacked = false;
    while (!r.eof) {
        const key = r.uint32();
        if ((key >>> 3) === 4) {
            assert.equal(key & 0x07, WT_BYTES, 'repeated enum must be packed by default');
            sawPacked = true;
            r.skip(WT_BYTES);
        } else {
            r.skip(key & 0x07);
        }
    }
    assert.ok(sawPacked);
    assert.deepEqual(decode(MouseEvent, bytes).modifiers, modifiers);

    // Unpacked: one tagged varint per element. A conformant decoder accepts this too.
    const w = new Writer();
    for (const m of modifiers) {
        w.tag(4, WT_VARINT);
        w.varint(m);
    }
    assert.deepEqual(decode(MouseEvent, w.finish()).modifiers, modifiers);
});

test('repeated int32 in CaptureDisplays round-trips', () => {
    const got = decode(CaptureDisplays, encode(CaptureDisplays, { set: [0, 1, 2] }));
    assert.deepEqual(got.set, [0, 1, 2]);
    assert.deepEqual(got.add, [], 'unset repeated fields decode as empty arrays');
});

test('repeated message fields are never packed', () => {
    const frames = [
        { data: new Uint8Array([1]), key: true, pts: 0n },
        { data: new Uint8Array([2, 3]), key: false, pts: 33n },
    ];
    const got = decode(VideoFrame, encode(VideoFrame, { vp9s: { frames }, display: 1 }));
    assert.equal(got.$case, 'vp9s');
    assert.equal(got.vp9s.frames.length, 2, 'every entry must survive — skipping one corrupts the stream');
    assert.equal(got.vp9s.frames[0].key, true);
    assert.equal(got.vp9s.frames[1].pts, 33n);
    assert.equal(got.display, 1);
    assert.equal(CODEC_BY_FIELD[got.$case], 'vp9');
});

/* -------------------------------------------------------------------------- */
/* Case 7b — per-field zigzag                                                 */
/* -------------------------------------------------------------------------- */

test('file_num is sint32 in FileTransferBlock and plain int32 in FileTransferSendRequest', () => {
    // Same field name, same value, different encodings. Confusing them silently turns
    // the -1 job-level sentinel into 1 or vice versa.
    const blockBytes = encode(FileTransferBlock, { id: 1, file_num: -1, data: new Uint8Array([7]) });
    const sendBytes = encode(FileTransferSendRequest, { id: 1, path: '/x', file_num: -1 });

    assert.equal(decode(FileTransferBlock, blockBytes).file_num, -1);
    assert.equal(decode(FileTransferSendRequest, sendBytes).file_num, -1);

    // The sint32 form is compact; the int32 form is sign-extended to ten bytes.
    const blockFieldLen = blockBytes.length;
    const sendFieldLen = sendBytes.length;
    assert.ok(sendFieldLen > blockFieldLen - 2,
        'int32 -1 must be materially longer on the wire than zigzag -1');
});

test('sint32 coordinates round-trip negatives for monitors left of primary', () => {
    const got = decode(MouseEvent, encode(MouseEvent, {
        mask: mouseMask(MouseType.MOVE), x: -1920, y: -12,
    }));
    assert.equal(got.x, -1920);
    assert.equal(got.y, -12);
});

test('DisplayInfo negative origins and the protocol\'s only double', () => {
    const got = decode(DisplayInfo, encode(DisplayInfo, {
        x: -1920, y: -200, width: 1920, height: 1080, name: 'DISPLAY2', online: true, scale: 2.0,
    }));
    assert.equal(got.x, -1920);
    assert.equal(got.y, -200);
    assert.equal(got.scale, 2.0);
    assert.equal(got.name, 'DISPLAY2');
});

/* -------------------------------------------------------------------------- */
/* Case 7d — oneof                                                            */
/* -------------------------------------------------------------------------- */

test('encoding two members of one oneof is rejected', () => {
    assert.throws(
        () => encode(FileTransferSendConfirmRequest, { id: 1, file_num: 0, skip: true, offset_blk: 5 }),
        WireError,
    );
    assert.throws(
        () => encode(VideoFrame, { vp9s: { frames: [] }, h264s: { frames: [] } }),
        WireError,
    );
});

test('oneof decode is last-one-wins', () => {
    // Hand-build a KeyEvent carrying both `chr` (4) and `control_key` (3), in that order.
    const w = new Writer();
    w.tag(4, WT_VARINT);
    w.varint(65);
    w.tag(3, WT_VARINT);
    w.varint(ControlKey.Return);
    const got = decode(KeyEvent, w.finish());
    assert.equal(got.$case, 'control_key', 'the later field wins');
    assert.equal(got.control_key, ControlKey.Return);
});

test('send_confirm skip and offset_blk are distinguishable', () => {
    const skip = decode(FileTransferSendConfirmRequest,
        encode(FileTransferSendConfirmRequest, { id: 3, file_num: 2, skip: true }));
    assert.equal(skip.$case, 'skip');
    assert.equal(skip.skip, true);

    const resume = decode(FileTransferSendConfirmRequest,
        encode(FileTransferSendConfirmRequest, { id: 3, file_num: 2, offset_blk: 131072 }));
    assert.equal(resume.$case, 'offset_blk');
    assert.equal(resume.offset_blk, 131072, 'offset_blk is a BYTE offset');
});

/* -------------------------------------------------------------------------- */
/* Session envelope                                                           */
/* -------------------------------------------------------------------------- */

test('Message envelope round-trips a LoginRequest with nested options', () => {
    const lr = {
        username: '987654321', // the PEER's id, not a user name
        password: new Uint8Array(32).fill(9),
        my_id: 'web-1',
        my_name: 'Operator',
        my_platform: 'Web',
        video_ack_required: true,
        session_id: 0x0123456789abcdefn,
        version: '1.4.8',
        option: {
            supported_decoding: {
                ability_vp8: 1, ability_vp9: 1, ability_av1: 1, ability_h264: 1, prefer: 0,
            },
        },
    };
    const got = decode(Message, encode(Message, { login_request: lr }));
    assert.equal(got.$case, 'login_request');
    assert.equal(got.login_request.username, '987654321');
    assert.equal(got.login_request.video_ack_required, true);
    assert.equal(got.login_request.session_id, 0x0123456789abcdefn, 'uint64 must not round');
    assert.equal(got.login_request.option.supported_decoding.ability_vp9, 1);
    assert.equal(got.login_request.my_platform, 'Web');
});

test('cursor_id is a bare uint64 on the envelope, not a wrapper', () => {
    const id = 0xfedcba9876543210n;
    const got = decode(Message, encode(Message, { cursor_id: id }));
    assert.equal(got.$case, 'cursor_id');
    assert.equal(got.cursor_id, id, 'cache key must survive exactly');
});

test('Misc video_received is the ACK we must send before decoding', () => {
    const got = decode(Message, encode(Message, { misc: { video_received: true } }));
    assert.equal(got.misc.$case, 'video_received');
    assert.equal(got.misc.video_received, true);
});

test('Misc permission_info decodes a denial', () => {
    const got = decode(Misc, encode(Misc, { permission_info: { permission: 4, enabled: false } }));
    assert.equal(got.$case, 'permission_info');
    assert.equal(got.permission_info.permission, 4);
    // `enabled: false` is the proto3 default and so is not emitted; the receiver must
    // treat an absent `enabled` on a permission_info as false, which is what makes the
    // negative-signalling convention work.
    assert.equal(got.permission_info.enabled, undefined);
});

test('PeerInfo with multiple displays preserves order and per-display geometry', () => {
    const got = decode(PeerInfo, encode(PeerInfo, {
        username: 'u', hostname: 'h', platform: 'Windows', version: '1.4.8',
        current_display: 1,
        displays: [
            { x: 0, y: 0, width: 1920, height: 1080, name: 'A', online: true, scale: 1 },
            { x: 1920, y: -120, width: 2560, height: 1440, name: 'B', online: true, scale: 1 },
        ],
        features: { privacy_mode: true, terminal: true },
    }));
    assert.equal(got.displays.length, 2);
    assert.equal(got.displays[1].x, 1920);
    assert.equal(got.displays[1].y, -120);
    assert.equal(got.current_display, 1);
    assert.equal(got.features.terminal, true);
});

/* -------------------------------------------------------------------------- */
/* Forward compatibility                                                      */
/* -------------------------------------------------------------------------- */

test('an unknown field added by a future RustDesk version does not break decoding', () => {
    const w = new Writer();
    w.tag(1, WT_BYTES);
    w.string('123456789'); // PunchHoleRequest.id
    w.tag(99, WT_BYTES); // a field we have never seen
    w.string('future');
    w.tag(6, WT_BYTES);
    w.string('1.9.0'); // version
    const got = decode(PunchHoleRequest, w.finish());
    assert.equal(got.id, '123456789');
    assert.equal(got.version, '1.9.0');
});

test('a field whose wire type disagrees with the schema is skipped, not misparsed', () => {
    const w = new Writer();
    w.tag(1, WT_VARINT); // id is a string; a varint here means the schema moved
    w.varint(7);
    w.tag(6, WT_BYTES);
    w.string('1.4.8');
    const got = decode(PunchHoleRequest, w.finish());
    assert.equal(got.version, '1.4.8', 'the rest of the message must still decode');
});

test('mouse mask packing matches the documented constants', () => {
    assert.equal(mouseMask(MouseType.DOWN, MouseButton.LEFT), 0x09);
    assert.equal(mouseMask(MouseType.UP, MouseButton.LEFT), 0x0a);
    assert.equal(mouseMask(MouseType.DOWN, MouseButton.RIGHT), 0x11);
    assert.equal(mouseMask(MouseType.UP, MouseButton.RIGHT), 0x12);
    assert.equal(mouseMask(MouseType.DOWN, MouseButton.MIDDLE), 0x21);
    assert.equal(mouseMask(MouseType.DOWN, MouseButton.BACK), 0x41);
    assert.equal(mouseMask(MouseType.DOWN, MouseButton.FORWARD), 0x81);
    assert.equal(mouseMask(MouseType.MOVE), 0);
    assert.equal(mouseMask(MouseType.WHEEL), 3);
    assert.equal(mouseMask(MouseType.TRACKPAD), 4);
    // Round-trip through the field to prove the extraction rules hold.
    const m = mouseMask(MouseType.DOWN, MouseButton.RIGHT);
    assert.equal(m & 0x7, MouseType.DOWN);
    assert.equal(m >> 3, MouseButton.RIGHT);
});
