# 📦 Parcel Plan: Browser RustDesk Client (`web-client/`)

## 📊 State Dashboard
| Metric | Value |
| :--- | :--- |
| **Status** | `IN_PROGRESS` |
| **Version** | `v1.0.0` |
| **Active Persona** | `Architect` → `Implementer` |
| **Last Updated** | 2026-08-15 16:00 |

---

## 1️⃣ Phase 1: Expansion & Scoping

* **Intent:** A browser-based RustDesk viewer, written from scratch, that runs against **stock**
  `hbbs`/`hbbr` with no server-side sidecar — fast, feature-complete, and embeddable.

* **In Scope:**
  - Relay-only session: rendezvous → relay → NaCl handshake → login → stream
  - Video (VP8/VP9/AV1/H264/H265 via WebCodecs), decode + render in a Worker
  - **Multi-monitor** — enumeration, switching, simultaneous capture
  - Input: pointer (capture, coalescing, real `deltaMode`), keyboard (modes + locks + keyboard lock)
  - Cursor overlay with permanent shape cache
  - Audio (Opus → `AudioDecoder` → `AudioWorklet`)
  - **File transfer** — browse, download, upload, directories, empty dirs, overwrite negotiation, **resume**
  - **Chat**, clipboard (text/html/png), terminal (xterm.js)
  - Quality/FPS control + viewer-side adaptive controller
  - Host integration: viewer route, per-peer grants, nginx/Caddy reference config

* **Out of Scope:**
  - cliprdr file clipboard (no browser API can be a deferred OS clipboard file provider)
  - Switch-sides (requires input injection), port-forward (no listening socket)
  - Direct P2P / hole punching, LAN discovery (browser limits — **all sessions are relayed**)
  - Firefox, Safari, touch, mobile layouts, insecure contexts

## 2️⃣ Phase 2: Requirements & Context

* **Relevant Docs:**
  - `DevOps/vault/notes/web-client-strategy-plan.md` → strategy and phases (encrypted)
  - `DevOps/vault/notes/18-competitive-analysis-2026-08.md` → market research (encrypted)
  - `web-client/docs/spec/*` → **the clean-room specification the code is written from**

* **Relevant Code:**
  - `scripts/copy-admin-vendor.mjs` → the vendoring pattern `web-client/vendor/` follows
  - `routes/web.php`, `app/Http/Controllers/Admin/DeviceController.php` → Phase 8 integration points
  - `app/Services/AccessService.php`, `AdminScopeService.php` → per-peer grant authorization

## 3️⃣ Phase 3: User Clarification

* `[x]` HTTPS required? → **Yes.** No insecure-context fallback.
* `[x]` Browser targets? → **Chrome/Chromium desktop only.** No Firefox/Safari/touch/mobile.
* `[x]` Licence? → **AGPL-3.0**, same as the repo.
* `[x]` Build step? → **None.** Native ES modules, hand-written codec, JSDoc + `checkJs`.
* `[x]` Copy code from RustDesk/the reviewed implementation? → **No.** Clean-room: implement from `docs/spec/`.
* `[ ]` v1 release scope → recommend Phase 5 (view + control + audio); files/terminal follow.

## 4️⃣ Phase 4: Detailed Execution Plan

### Module map

| Path | Responsibility | Depends on |
|---|---|---|
| `src/protocol/wire.js` | varint/zigzag/len-delim reader+writer, unknown-field skip | — |
| `src/protocol/descriptors.js` | message field tables (from spec §06) | wire |
| `src/protocol/codec.js` | `encode(desc, obj)` / `decode(desc, bytes)`, oneof handling | wire, descriptors |
| `src/protocol/enums.js` | ControlKey, NatType, ConnType, PreferCodec, ImageQuality, … | — |
| `src/transport/ws.js` | WebSocket open/send/close, one-shot rendezvous, relay socket | — |
| `src/crypto/session.js` | Ed25519 verify chain, X25519 box, secretbox + counters | vendor/noble |
| `src/crypto/password.js` | `h1`/`h2` derivation via WebCrypto SHA-256 | — |
| `src/session/machine.js` | connection state machine (spec §02/§03 state diagram) | all above |
| `src/session/permissions.js` | permission map defaulting to **all true** | — |
| `src/workers/decode.worker.js` | WS recv → **ACK** → decrypt → decode → render | codec, crypto |
| `src/media/video.js` | main-thread decoder control, codec negotiation | — |
| `src/media/audio.js` | Opus → `AudioDecoder` → `AudioWorklet` ring | — |
| `src/render/surface.js` | `OffscreenCanvas`, `drawImage(VideoFrame)` | — |
| `src/render/cursor.js` | overlay layer, permanent id cache, zstd colors | vendor/zstd |
| `src/input/pointer.js` | Pointer Events, capture, coalescing, wheel | codec |
| `src/input/keyboard.js` | key modes, modifiers, locks, `keyboard.lock()` | codec |
| `src/monitors/displays.js` | enumeration, switch, capture set, per-display decoders | — |
| `src/files/*` | job tables, download/upload machines, digest, resume | codec, vendor/zstd |
| `src/chat.js` | `Misc.chat_message` both ways | codec |
| `src/clipboard.js` | text/html/png, gesture-gated write, paste listener | codec |
| `src/terminal/*` | xterm.js binding, `service_id` persistence | vendor/xterm |
| `src/ui/*` | viewer shell, toolbar, dialogs | all |

### Build order (dependency-first, each step testable)

1. `wire.js` + conformance tests ← **start here; everything rests on it**
2. `enums.js`, `descriptors.js`, `codec.js` + round-trip tests
3. `crypto/` + known-answer tests
4. `transport/ws.js` + mock server
5. `session/machine.js` → **milestone: connect to a real peer, log `PeerInfo`**
6. worker + video + render → **milestone: pixels**
7. input → **milestone: control**
8. displays, cursor, audio
9. files, chat, clipboard, terminal
10. host integration

### Test Verification Plan

```
cd web-client && npm test          # node --test, no browser needed for 1-4
cd web-client && npm run typecheck # tsc --noEmit --checkJs
```

Conformance suite (`test/conformance/`) is written **before** the modules it covers. Cases enumerated
in `web-client/test/conformance/` — codec cases first, then the protocol silent-failure cases.

## 5️⃣ Phase 5: Product Owner Review
* **Status:** `PENDING`

## 6️⃣ Phase 6: Senior Dev Hygiene Review
* **Status:** `PENDING`

## 7️⃣ Phase 7: Implementation Checklist

- `[x]` Branch `feat/web-client`, research + strategy plan committed
- `[x]` `docs/spec/06-schema.md` — the wire schema (the source for the descriptors)
- `[ ]` Spec corpus `docs/spec/01`–`05` (prose specs; 06 is the load-bearing one)
- `[x]` `wire.js` + 22 conformance tests
- `[x]` `enums.js` + `rendezvous.js` + `message.js` + `codec.js` + 22 round-trip tests
- `[x]` `crypto/stream.js` — secretbox counters + nonce, 10 tests (cipher injected)
- `[x]` **Live rendezvous verified against real hbbs** (`test/integration/rendezvous.mjs`) —
  WS on 21118 accepted, one frame = one message, our PunchHoleRequest understood,
  RelayResponse decoded, `nat_type=SYMMETRIC` confirmed as the relay trigger.
  **The no-sidecar architecture is now empirically proven, not just read from source.**
- `[x]` `vendor/` bootstrap — `scripts/vendor.mjs`, tweetnacl@1.0.3 UMD→ESM, with `--check`
- `[x]` `crypto/cipher.js` + `handshake.js` + `password.js` + 20 tests
- `[x]` relay leg: `RequestRelay` on :21119, pair by uuid
- `[x]` **Phase 1 + first frame verified against a live peer** (`tools/integration/login.mjs`):
  rendezvous → relay → two-step Ed25519 chain → sealed session key → secretbox →
  password login → PeerInfo (4 displays) → first H.264 keyframe. No input sent.

**Crypto library decision changed from the plan:** tweetnacl, not @noble. Noble has
`secretbox` and `x25519` but no `crypto_box`, which would mean composing the box
construction from `hsalsa` + `x25519` by hand. tweetnacl maps 1:1 onto the NaCl primitives
the protocol requires: combined-mode Ed25519 verify, crypto_box, crypto_secretbox. `SecretStream` takes the
cipher by injection, so swapping the per-frame secretbox to noble later stays contained.
- `[ ]` `transport/` + mock peer
- `[ ]` `session/machine.js` — connect milestone
- `[ ]` worker + video + render — pixels milestone
- `[ ]` input — control milestone
- `[ ]` displays / cursor / audio
- `[ ]` files / chat / clipboard / terminal
- `[ ]` host integration + reverse-proxy config
- `[ ]` hardening, perf gates, security review

## 8️⃣ Phase 8: Verification Dashboard
* **Verification Status:** `PENDING`

## 9️⃣ Phase 9: User Verification
* **Status:** `PENDING`

## 🔟 Phase 10: Wrap Up & Archival
* Persist to core docs: the `web-client/` boundary (own licence header, no build step, clean-room
  rule), and the reverse-proxy requirements for operators.
