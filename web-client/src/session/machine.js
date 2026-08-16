/**
 * Session orchestration: rendezvous → relay → handshake → login → streaming.
 *
 * Spec: docs/spec/02-rendezvous-relay.md and 03-crypto-login.md.
 *
 * Events (assign a handler, or leave it unset to ignore):
 *   onState(name)          progress through the state machine
 *   onPeerInfo(info)       login succeeded; displays are known
 *   onVideoFrame(frame)    {display, codec, units, key} — already ACKed
 *   onCursor(evt)          {type:'shape'|'position'|'id', ...}
 *   onAudioFormat(fmt) / onAudioFrame(bytes)
 *   onChat(text)
 *   onPermissions(set)     the PermissionSet, after a change
 *   onClipboard(entries)
 *   onDisplaySwitch(info)
 *   onMessage(msg)         every decoded Message, for anything unhandled
 *   onClose(reason)
 *
 * Deliberately absent: any method that sends input. Input lives in src/input/ and is
 * wired up by the UI, so a headless consumer of this class cannot move a remote mouse
 * by accident.
 */

import { encode, decode } from '../protocol/codec.js';
import { RendezvousMessage } from '../protocol/rendezvous.js';
import { Message, CODEC_BY_FIELD } from '../protocol/message.js';
import { NatType, ConnType, PunchHoleFailure } from '../protocol/enums.js';
import { FrameSocket, endpoints, TransportError } from '../transport/ws.js';
import { negotiate, decodeBase64 } from '../crypto/handshake.js';
import { derivePassword } from '../crypto/password.js';
import { secretboxCipher } from '../crypto/cipher.js';
import { SecretStream } from '../crypto/stream.js';
import { PermissionSet } from './permissions.js';
import { CodecCapabilities } from '../media/codec.js';

export const OUR_VERSION = '1.4.8';
export const OUR_PLATFORM = 'Web';

export class SessionError extends Error {
    /** @param {string} message @param {string} [code] */
    constructor(message, code = 'session') {
        super(message);
        this.name = 'SessionError';
        this.code = code;
    }
}

export class RustDeskSession {
    /**
     * @param {object} opts
     * @param {string} opts.host Rendezvous host, optionally `host:port`.
     * @param {string} opts.peerId
     * @param {string} [opts.serverKey] Base64 Ed25519 server key.
     * @param {string} [opts.password]
     * @param {string} [opts.myId]
     * @param {string} [opts.myName]
     * @param {boolean} [opts.secure] Use wss. Required in production.
     * @param {boolean} [opts.pathRouted]
     * @param {number} [opts.rendezvousPort]
     * @param {number} [opts.relayPort]
     * @param {CodecCapabilities} [opts.codecs]
     */
    constructor(opts) {
        this.opts = opts;
        this.peerId = opts.peerId;
        this.state = 'idle';
        this.permissions = new PermissionSet(() => this.onPermissions?.(this.permissions));
        this.codecs = opts.codecs ?? new CodecCapabilities(['vp9']);
        /** @type {import('../protocol/message.js').PeerInfo | null} */
        this.peerInfo = null;
        this.encrypted = false;
        this.downgradeReason = null;
        /** @type {FrameSocket | null} */
        this.socket = null;
        /** @type {SecretStream | null} */
        this.stream = null;
        this._running = false;
    }

    /** @param {string} name */
    _setState(name) {
        this.state = name;
        this.onState?.(name);
    }

    /** @param {object} msg A `Message` shaped object. */
    send(msg) {
        if (!this.socket || !this.stream) throw new SessionError('not connected', 'not_connected');
        this.socket.send(this.stream.encrypt(encode(Message, msg)));
    }

    /** Backpressure signal for input pacing and file transfer. */
    get bufferedAmount() {
        return this.socket?.bufferedAmount ?? 0;
    }

    /**
     * Runs rendezvous, relay, handshake and login. Resolves once PeerInfo has arrived;
     * the message pump keeps running until `close()` or the peer disconnects.
     * @returns {Promise<object>} PeerInfo
     */
    async connect() {
        const urls = endpoints({
            host: this.opts.host,
            rendezvousPort: this.opts.rendezvousPort ?? 21116,
            relayPort: this.opts.relayPort ?? 21117,
            secure: this.opts.secure ?? false,
            pathRouted: this.opts.pathRouted ?? false,
        });

        const relayInfo = await this._rendezvous(urls.rendezvous);
        await this._openRelay(urls.relay, relayInfo);
        await this._handshake(relayInfo);
        const info = await this._login();
        this._pump(); // fire and forget; errors surface via onClose
        return info;
    }

    /**
     * @param {string} url
     * @returns {Promise<{uuid: string, relayServer: string, signedIdPk: Uint8Array}>}
     */
    async _rendezvous(url) {
        this._setState('rendezvous');
        const sock = await FrameSocket.open(url);
        try {
            sock.send(encode(RendezvousMessage, {
                punch_hole_request: {
                    id: this.peerId,
                    // The actual relay trigger. OSS hbbs drops `force_relay` without
                    // forwarding it, but does copy nat_type into the peer's PunchHole.
                    nat_type: NatType.SYMMETRIC,
                    licence_key: this.opts.serverKey ?? '',
                    conn_type: ConnType.DEFAULT_CONN,
                    version: OUR_VERSION,
                    force_relay: true,
                },
            }));

            const msg = decode(RendezvousMessage, await sock.next('rendezvous reply'));

            if (msg.$case === 'relay_response') {
                const rr = msg.relay_response;
                if (rr.refuse_reason) throw new SessionError(rr.refuse_reason, 'refused');
                return { uuid: rr.uuid, relayServer: rr.relay_server, signedIdPk: rr.pk };
            }

            if (msg.$case === 'punch_hole_response') {
                const ph = msg.punch_hole_response;
                // Empty socket_addr IS the failure signal, and `failure` defaults to
                // ID_NOT_EXIST = 0 — so this must be checked before reading `failure`.
                if (ph.socket_addr.length === 0) {
                    const name = Object.keys(PunchHoleFailure)
                        .find((k) => PunchHoleFailure[k] === (ph.failure ?? 0));
                    throw new SessionError(ph.other_failure || name || 'unknown failure', 'rendezvous_failed');
                }
                // A browser cannot hole-punch, so a direct address is unusable.
                throw new SessionError('peer offered a direct connection; a browser can only relay', 'no_relay');
            }

            throw new SessionError(`unexpected rendezvous reply: ${msg.$case}`, 'protocol');
        } finally {
            sock.close();
        }
    }

    /**
     * @param {string} fallbackUrl
     * @param {{uuid: string, relayServer: string}} info
     */
    async _openRelay(fallbackUrl, info) {
        this._setState('relay');
        const url = info.relayServer
            ? endpoints({
                host: info.relayServer,
                relayPort: this.opts.relayPort ?? 21117,
                secure: this.opts.secure ?? false,
                pathRouted: this.opts.pathRouted ?? false,
            }).relay
            : fallbackUrl;

        this.socket = await FrameSocket.open(url);
        this.socket.send(encode(RendezvousMessage, {
            request_relay: {
                id: this.peerId,
                uuid: info.uuid, // the pairing token, byte-for-byte
                licence_key: this.opts.serverKey ?? '',
                conn_type: ConnType.DEFAULT_CONN,
            },
        }));
    }

    /** @param {{signedIdPk: Uint8Array}} info */
    async _handshake(info) {
        this._setState('handshake');
        // Still plaintext: the stream becomes secretbox only after we answer with
        // PublicKey, so this frame must not touch the counters.
        const first = decode(Message, await this.socket.next('SignedId'));
        if (first.$case !== 'signed_id') {
            throw new SessionError(`expected signed_id, got ${first.$case}`, 'protocol');
        }

        const result = negotiate({
            signedIdPk: info.signedIdPk,
            serverPk: this.opts.serverKey ? decodeBase64(this.opts.serverKey) : new Uint8Array(0),
            peerSignedId: first.signed_id.id,
            peerId: this.peerId,
        });

        this.downgradeReason = result.downgradeReason;
        this.encrypted = result.sessionKey !== null;

        this.socket.send(encode(Message, {
            public_key: result.publicKeyMessage ?? {
                asymmetric_value: new Uint8Array(0),
                symmetric_value: new Uint8Array(0),
            },
        }));
        this.stream = new SecretStream(secretboxCipher, result.sessionKey);
    }

    /** @returns {Promise<object>} PeerInfo */
    async _login() {
        this._setState('login');

        let msg = await this._recv('Hash');
        while (msg.$case === 'test_delay') {
            this._echoTestDelay(msg.test_delay);
            msg = await this._recv('Hash');
        }
        if (msg.$case !== 'hash') throw new SessionError(`expected hash, got ${msg.$case}`, 'protocol');

        const { salt = '', challenge = '' } = msg.hash;
        const password = this.opts.password
            ? await derivePassword(this.opts.password, salt, challenge)
            : new Uint8Array(0);

        this.send({
            login_request: {
                username: this.peerId, // the PEER's id — not a user name
                password,
                my_id: this.opts.myId ?? 'web-client',
                my_name: this.opts.myName ?? 'Web Client',
                my_platform: OUR_PLATFORM,
                version: OUR_VERSION,
                // Real flow control: the peer will not capture frame N+1 until we ACK N.
                video_ack_required: true,
                session_id: this._sessionId(),
                option: { supported_decoding: this.codecs.toSupportedDecoding() },
            },
        });

        for (;;) {
            const m = await this._recv('LoginResponse');
            if (m.$case === 'test_delay') { this._echoTestDelay(m.test_delay); continue; }
            if (m.$case === 'misc') { this._handleMisc(m.misc); continue; }

            if (m.$case === 'login_response') {
                const lr = m.login_response;
                if (lr.error) throw new SessionError(lr.error, 'login_failed');
                if (!lr.peer_info?.displays?.length) {
                    throw new SessionError('peer reported no displays', 'no_displays');
                }
                this.peerInfo = lr.peer_info;
                this._setState('connected');
                this.onPeerInfo?.(lr.peer_info);
                return lr.peer_info;
            }
        }
    }

    /** The message pump for the life of the session. */
    async _pump() {
        this._running = true;
        try {
            while (this._running && this.socket && !this.socket.closed) {
                this._dispatch(await this._recv('message'));
            }
        } catch (err) {
            if (this._running) this._fail(err);
        }
    }

    /** @param {object} m */
    _dispatch(m) {
        switch (m.$case) {
            case 'video_frame': {
                // ACK before anything else. The host's capture loop blocks on this, so
                // any work done first is added to every frame interval.
                this.send({ misc: { video_received: true } });
                const field = m.video_frame.$case;
                const units = m.video_frame[field]?.frames ?? [];
                this.onVideoFrame?.({
                    display: m.video_frame.display ?? 0,
                    codec: CODEC_BY_FIELD[field],
                    units,
                    key: units.some((u) => u.key),
                });
                break;
            }
            case 'test_delay':
                this._echoTestDelay(m.test_delay);
                break;
            case 'misc':
                this._handleMisc(m.misc);
                break;
            case 'cursor_data':
                this.onCursor?.({ type: 'shape', ...m.cursor_data });
                break;
            case 'cursor_position':
                this.onCursor?.({ type: 'position', x: m.cursor_position.x ?? 0, y: m.cursor_position.y ?? 0 });
                break;
            case 'cursor_id':
                this.onCursor?.({ type: 'id', id: m.cursor_id });
                break;
            case 'audio_frame':
                this.onAudioFrame?.(m.audio_frame.data);
                break;
            case 'clipboard':
                this.onClipboard?.([m.clipboard]);
                break;
            case 'multi_clipboards':
                this.onClipboard?.(m.multi_clipboards.clipboards);
                break;
            case 'peer_info':
                // Topology changed: replaces the display list wholesale.
                this.peerInfo = m.peer_info;
                this.onPeerInfo?.(m.peer_info);
                break;
            default:
                break;
        }
        this.onMessage?.(m);
    }

    /** @param {object} misc */
    _handleMisc(misc) {
        switch (misc.$case) {
            case 'permission_info':
                this.permissions.apply(misc.permission_info);
                break;
            case 'audio_format':
                this.onAudioFormat?.(misc.audio_format);
                break;
            case 'switch_display':
                this.onDisplaySwitch?.(misc.switch_display);
                break;
            case 'chat_message':
                this.onChat?.(misc.chat_message.text ?? '');
                break;
            case 'close_reason':
                this._fail(new SessionError(misc.close_reason || 'peer closed the session', 'closed_by_peer'));
                break;
            default:
                break;
        }
    }

    /**
     * Echo verbatim. A reply later than 2s — or one carrying a fresh timestamp rather
     * than the peer's — clamps every display on this connection to 2 fps.
     * @param {object} td
     */
    _echoTestDelay(td) {
        this.send({ test_delay: td });
        this.lastDelayMs = td.last_delay ?? 0;
        this.targetBitrateKbps = td.target_bitrate ?? 0;
    }

    /** @param {string} label */
    async _recv(label) {
        return decode(Message, this.stream.decrypt(await this.socket.next(label)));
    }

    /** @returns {bigint} Non-zero and stable for this session. */
    _sessionId() {
        const buf = new Uint32Array(2);
        crypto.getRandomValues(buf);
        return ((BigInt(buf[0]) << 32n) | BigInt(buf[1])) || 1n;
    }

    /** @param {Error} err */
    _fail(err) {
        if (!this._running && this.state === 'closed') return;
        this._running = false;
        this._setState('closed');
        this.onClose?.(err instanceof TransportError ? new SessionError(err.message, 'transport') : err);
    }

    close() {
        this._running = false;
        this.socket?.close();
        this._setState('closed');
    }
}
