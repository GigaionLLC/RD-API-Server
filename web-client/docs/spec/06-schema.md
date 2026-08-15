# 06 · Wire Schema

Complete field tables for the RustDesk wire protocol. **This document is the source of truth for
`src/protocol/descriptors.js`** — the descriptors are these tables transcribed into JavaScript.

Tag numbers are the wire contract and must be exact. All messages are proto3, package `hbb`.

> **Clean-room note.** These are documented facts about a wire format — message names, field names,
> tag numbers, types. Implement from this table. Do not consult, copy, or compile any third-party
> `.proto` file. See `PLAN.md` §2.

---

## 1. Wire primitives

### 1.1 Wire types

Every field is preceded by a varint **key**: `key = (tag << 3) | wire_type`.

| Wire type | Name | Used for | Needed by us |
|---|---|---|---|
| 0 | varint | `int32 int64 uint32 uint64 sint32 sint64 bool enum` | yes |
| 1 | fixed64 | `double fixed64 sfixed64` | yes — only `DisplayInfo.scale` |
| 2 | length-delimited | `string bytes`, embedded messages, packed repeated | yes |
| 5 | fixed32 | `float fixed32 sfixed32` | no (skip only) |

Wire types 3 and 4 (start/end group) are obsolete and never appear. A decoder must still **skip
unknown fields by wire type** or forward compatibility breaks when RustDesk adds a field.

### 1.2 Varint

Base-128, little-endian groups, continuation bit is the MSB of each byte.

Negative `int32`/`int64` values are sign-extended to **10 bytes**. `sint32`/`sint64` instead use
zigzag: `encode(n) = (n << 1) ^ (n >> 31)` for 32-bit, `decode(u) = (u >>> 1) ^ -(u & 1)`.

**Zigzag applies only to `sint*` fields.** Getting this wrong is silent: `FileTransferBlock.file_num`
is `sint32` and `FileTransferSendRequest.file_num` is plain `int32`, in the same feature, and the
`-1` sentinel becomes garbage if confused.

Test boundaries: `0`, `127/128`, `16383/16384`, `2^31-1`, `2^32-1`, `2^63-1`, and negative `int32`.

### 1.3 Repeated fields

In proto3, `repeated` fields of **numeric and enum** type are **packed by default** — encoded once
with wire type 2, payload being the concatenated varints. `repeated string`, `repeated bytes`, and
`repeated <message>` are **never packable** and appear as one length-delimited field per element.

A conformant decoder must accept **both** packed and unpacked forms for packable types.

Packable in this protocol: `ControlKey modifiers`, `int32 add/sub/set`, `int32 persistent_sessions`.
Not packable: `string rendezvous_servers`, `bytes keys`, `DisplayInfo displays`, `FileEntry entries`,
`Clipboard clipboards`, `CliprdrFormat formats`, `Resolution resolutions`, `WindowsSession sessions`,
`EncodedVideoFrame frames`, `FileDirectory empty_dirs`, `CliprdrFile files`, `HeaderEntry headers`.

### 1.4 Defaults, presence, and `oneof`

Proto3 scalars have no explicit presence: a field equal to its zero value is **not emitted**. The
decoder must therefore treat absent as zero/empty.

This matters in one load-bearing place: **`PunchHoleResponse.socket_addr` being empty *is* the
failure signal**, and `PunchHoleResponse.failure` defaults to `ID_NOT_EXIST = 0`. Branch on
`socket_addr.length === 0` first, then `other_failure`, then `failure`.

`oneof`: exactly one member set on encode; **last one wins** on decode.

### 1.5 Framing and encryption (summary — see spec 01)

- **WebSocket:** one binary frame = one payload. **No length prefix.** This is the only transport we
  use. Text/ping frames carry no payload of interest.
- **Encryption:** after handshake, each payload is a NaCl secretbox. Nonce = 24 bytes, zero-filled,
  first 8 bytes little-endian counter. Send/receive counters are independent and **pre-incremented**
  — the first message in each direction uses counter **1**.
- **Payloads of length ≤ 1 bypass decryption and do not advance the receive counter** (zero-byte
  heartbeat).

---

## 2. Enums

```
NatType          : UNKNOWN_NAT=0, ASYMMETRIC=1, SYMMETRIC=2
ConnType         : DEFAULT_CONN=0, FILE_TRANSFER=1, PORT_FORWARD=2, RDP=3, VIEW_CAMERA=4, TERMINAL=5
PunchHoleResponse.Failure : ID_NOT_EXIST=0, OFFLINE=2, LICENSE_MISMATCH=3, LICENSE_OVERUSE=4   (no 1)
RegisterPkResponse.Result : OK=0, UUID_MISMATCH=2, ID_EXISTS=3, TOO_FREQUENT=4,
                            INVALID_ID_FORMAT=5, NOT_SUPPORT=6, SERVER_ERROR=7, NOT_DEPLOYED=8  (no 1)

ImageQuality     : NotSet=0, Low=2, Balanced=3, Best=4                                (no 1)
BoolOption       : NotSet=0, No=1, Yes=2         ← NotSet means "no change", NOT false
Chroma           : I420=0, I444=1
PreferCodec      : Auto=0, VP9=1, H264=2, H265=3, VP8=4, AV1=5
KeyboardMode     : Legacy=0, Map=1, Translate=2, Auto=3
FileType         : Dir=0, DirLink=2, DirDrive=3, File=4, FileLink=5                   (no 1)
FileTransferSendRequest.FileType : Generic=0, Printer=1
ClipboardFormat  : Text=0, Rtf=1, Html=2, ImageRgba=21, ImagePng=22, ImageSvg=23, Special=31
PermissionInfo.Permission : Keyboard=0, Clipboard=2, Audio=3, File=4, Restart=5,
                            Recording=6, BlockInput=7, PrivacyMode=8                  (no 1)
BlockInputState  : BlkStateUnknown=0, BlkOnSucceeded=2, BlkOnFailed=3,
                   BlkOffSucceeded=4, BlkOffFailed=5
PrivacyModeState : PrvStateUnknown=0, PrvOnByOther=2, PrvNotSupported=3, PrvOnSucceeded=4,
                   PrvOnFailedDenied=5, PrvOnFailedPlugin=6, PrvOnFailed=7, PrvOffSucceeded=8,
                   PrvOffByPeer=9, PrvOffFailed=10, PrvOffUnknown=11
```

### 2.1 `ControlKey`

**F-keys are not contiguous.** F1=9, then F10/F11/F12, then F2–F9 at 13–20.

| # | Key | # | Key | # | Key | # | Key |
|---|---|---|---|---|---|---|---|
| 0 | Unknown | 20 | F9 | 40 | Numpad7 | 60 | Sleep |
| 1 | Alt | 21 | Home | 41 | Numpad8 | 61 | Separator |
| 2 | Backspace | 22 | LeftArrow | 42 | Numpad9 | 62 | Scroll |
| 3 | CapsLock | 23 | Meta | 43 | Cancel | 63 | NumLock |
| 4 | Control | 24 | Option *(dep.)* | 44 | Clear | 64 | RWin |
| 5 | Delete | 25 | PageDown | 45 | Menu *(dep.)* | 65 | Apps |
| 6 | DownArrow | 26 | PageUp | 46 | Pause | 66 | Multiply |
| 7 | End | 27 | Return | 47 | Kana | 67 | Add |
| 8 | Escape | 28 | RightArrow | 48 | Hangul | 68 | Subtract |
| 9 | F1 | 29 | Shift | 49 | Junja | 69 | Decimal |
| 10 | F10 | 30 | Space | 50 | Final | 70 | Divide |
| 11 | F11 | 31 | Tab | 51 | Hanja | 71 | Equals |
| 12 | F12 | 32 | UpArrow | 52 | Kanji | 72 | NumpadEnter |
| 13 | F2 | 33 | Numpad0 | 53 | Convert | 73 | RShift |
| 14 | F3 | 34 | Numpad1 | 54 | Select | 74 | RControl |
| 15 | F4 | 35 | Numpad2 | 55 | Print | 75 | RAlt |
| 16 | F5 | 36 | Numpad3 | 56 | Execute | 76 | VolumeMute |
| 17 | F6 | 37 | Numpad4 | 57 | Snapshot | 77 | VolumeUp |
| 18 | F7 | 38 | Numpad5 | 58 | Insert | 78 | VolumeDown |
| 19 | F8 | 39 | Numpad6 | 59 | Help | 79 | Power |

Two synthetic actions — not keys; the host performs a system action and injects nothing:

| 100 | `CtrlAltDel` — Secure Attention Sequence (Windows, elevated host) |
| 101 | `LockScreen` — lock the host session |

Keys the host tracks as modifiers: `Alt(1)`, `Control(4)`, `Meta(23)`, `Shift(29)`, `RWin(64)`,
`RShift(73)`, `RControl(74)`, `RAlt(75)`.

### 2.2 Mouse constants

`mask = (button << 3) | event_type`

| Event type | # | | Button (pre-shift) | # |
|---|---|---|---|---|
| MOVE | 0 | | LEFT | 0x01 |
| DOWN | 1 | | RIGHT | 0x02 |
| UP | 2 | | MIDDLE | 0x04 |
| WHEEL | 3 | | BACK | 0x08 |
| TRACKPAD | 4 | | FORWARD | 0x10 |
| MOVE_RELATIVE | 5 | | | |

Extract type with `mask & 0x7`, button with `mask >> 3`. **Set exactly one button bit** on DOWN/UP —
the host's dispatch is an exact-equality match and silently does nothing otherwise. Plain move is
`mask = 0`; wheel is `mask = 3`; trackpad is `mask = 4`.

Worked values: LEFT down `0x09`, LEFT up `0x0A`, RIGHT down `0x11`, RIGHT up `0x12`, MIDDLE down
`0x21`, MIDDLE up `0x22`, BACK down `0x41`, FORWARD down `0x81`.

---

## 3. `rendezvous.proto`

### 3.1 `RendezvousMessage` — the outer `oneof`

| Tag | Field | Tag | Field |
|---|---|---|---|
| 6 | `register_peer` | 18 | **`request_relay`** |
| 7 | `register_peer_response` | 19 | **`relay_response`** |
| 8 | **`punch_hole_request`** | 20 | `test_nat_request` |
| 9 | `punch_hole` | 21 | `test_nat_response` |
| 10 | `punch_hole_sent` | 22 | `peer_discovery` |
| 11 | **`punch_hole_response`** | 23 | `online_request` |
| 12 | `fetch_local_addr` | 24 | `online_response` |
| 13 | `local_addr` | 25 | `key_exchange` |
| 14 | `configure_update` | 26 | `hc` |
| 15 | `register_pk` | 27 | `http_proxy_request` |
| 16 | `register_pk_response` | 28 | `http_proxy_response` |
| 17 | `software_update` | | |

Tags 1–5 are historical and unused. **Bold** = the four we implement.

### 3.2 Messages we send

**`PunchHoleRequest`**

| Tag | Field | Type | Our value |
|---|---|---|---|
| 1 | `id` | string | target peer ID |
| 2 | `nat_type` | NatType | **`SYMMETRIC` (2)** — the real relay trigger |
| 3 | `licence_key` | string | server key or `""` |
| 4 | `conn_type` | ConnType | `DEFAULT_CONN` (0) |
| 5 | `token` | string | `""` |
| 6 | `version` | string | ours, e.g. `"1.4.8"` — never empty |
| 7 | `udp_port` | int32 | 0 |
| 8 | `force_relay` | bool | `true` — **dropped by OSS hbbs**, set for honesty |
| 9 | `upnp_port` | int32 | 0 |
| 10 | `socket_addr_v6` | bytes | empty |

**`RequestRelay`**

| Tag | Field | Type | Notes |
|---|---|---|---|
| 1 | `id` | string | peer ID (informational to hbbr) |
| 2 | `uuid` | string | **the pairing token** — must match byte-for-byte |
| 3 | `socket_addr` | bytes | leave empty; hbbs overwrites |
| 4 | `relay_server` | string | from `RelayResponse` |
| 5 | `secure` | bool | true iff we have a non-empty signed pk |
| 6 | `licence_key` | string | **required on the hbbr hop** if the server sets a key |
| 7 | `conn_type` | ConnType | |
| 8 | `token` | string | |
| 9 | `control_permissions` | ControlPermissions | optional, omit |

### 3.3 Messages we receive

**`PunchHoleResponse`**

| Tag | Field | Type | Notes |
|---|---|---|---|
| 1 | `socket_addr` | bytes | **empty ⇒ this is a failure response** |
| 2 | `pk` | bytes | signed `IdPk` blob |
| 3 | `failure` | Failure | defaults to `ID_NOT_EXIST=0` — check `socket_addr` first |
| 4 | `relay_server` | string | |
| 5 | `nat_type` | NatType | *oneof `union`* |
| 6 | `is_local` | bool | *oneof `union`* |
| 7 | `other_failure` | string | takes precedence over `failure` |
| 8 | `feedback` | int32 | |
| 9 | `is_udp` | bool | |
| 10 | `upnp_port` | int32 | |
| 11 | `socket_addr_v6` | bytes | |

**`RelayResponse`** — the relay happy path

| Tag | Field | Type | Notes |
|---|---|---|---|
| 1 | `socket_addr` | bytes | cleared by hbbs before forwarding |
| 2 | `uuid` | string | **the pairing token** |
| 3 | `relay_server` | string | connect here |
| 4 | `id` | string | *oneof `union`* |
| 5 | `pk` | bytes | *oneof `union`* — **use as `signed_id_pk`** |
| 6 | `refuse_reason` | string | non-empty ⇒ abort |
| 7 | `version` | string | |
| 9 | `feedback` | int32 | *(tag 8 unused)* |
| 10 | `socket_addr_v6` | bytes | |
| 11 | `upnp_port` | int32 | |

### 3.4 Other rendezvous messages (decode-and-ignore)

`ControlPermissions{permissions:1 uint64}` · `RegisterPeer{id:1, serial:2}` ·
`RegisterPeerResponse{request_pk:2}` · `RegisterPk{id:1, uuid:2 bytes, pk:3 bytes, old_id:4,
no_register_device:5}` · `RegisterPkResponse{result:1, keep_alive:2}` ·
`PunchHole{socket_addr:1, relay_server:2, nat_type:3, udp_port:4, force_relay:5, upnp_port:6,
socket_addr_v6:7, control_permissions:8}` · `PunchHoleSent{socket_addr:1, id:2, relay_server:3,
nat_type:4, version:5, upnp_port:6, socket_addr_v6:7}` · `TestNatRequest{serial:1}` ·
`TestNatResponse{port:1, cu:2}` · `ConfigUpdate{serial:1, rendezvous_servers:2 rep string}` ·
`SoftwareUpdate{url:1}` · `FetchLocalAddr{socket_addr:1, relay_server:2, socket_addr_v6:3,
control_permissions:4}` · `LocalAddr{socket_addr:1, local_addr:2, relay_server:3, id:4, version:5,
socket_addr_v6:6}` · `PeerDiscovery{cmd:1, mac:2, id:3, username:4, hostname:5, platform:6, misc:7}` ·
`OnlineRequest{id:1, peers:2 rep string}` · `OnlineResponse{states:1 bytes}` ·
`KeyExchange{keys:1 rep bytes}` · `HealthCheck{token:1}` · `HeaderEntry{name:1, value:2}` ·
`HttpProxyRequest{method:1, path:2, headers:3 rep, body:4 bytes}` ·
`HttpProxyResponse{status:1, headers:2 rep, body:3 bytes, error:4}`

---

## 4. `message.proto`

### 4.1 `Message` — the outer `oneof`

| Tag | Field | Dir | Tag | Field | Dir |
|---|---|---|---|---|---|
| 3 | `signed_id` | ← | 18 | `file_response` | ↔ |
| 4 | `public_key` | → | 19 | `misc` | ↔ |
| 5 | `test_delay` | ↔ | 20 | `cliprdr` | ↔ *(not impl.)* |
| 6 | `video_frame` | ← | 21 | `message_box` | ← |
| 7 | `login_request` | → | 22 | `switch_sides_response` | ← *(not impl.)* |
| 8 | `login_response` | ← | 23 | `voice_call_request` | ↔ |
| 9 | `hash` | ← | 24 | `voice_call_response` | ↔ |
| 10 | `mouse_event` | → | 25 | `peer_info` | ← |
| 11 | `audio_frame` | ↔ | 26 | `pointer_device_event` | → *(not impl.)* |
| 12 | `cursor_data` | ← | 27 | `auth_2fa` | → |
| 13 | `cursor_position` | ← | 28 | `multi_clipboards` | ↔ |
| 14 | `cursor_id` | ← *(bare uint64)* | 29 | `screenshot_request` | → |
| 15 | `key_event` | → | 30 | `screenshot_response` | ← |
| 16 | `clipboard` | ↔ | 31 | `terminal_action` | → |
| 17 | `file_action` | ↔ | 32 | `terminal_response` | ← |

Tags 1–2 unused. `cursor_id` is a **bare `uint64`**, not a wrapper message.

### 4.2 Handshake and login

```
SignedId    { id:1 bytes }                       // Ed25519 combined-mode blob
PublicKey   { asymmetric_value:1 bytes,          // our 32-byte X25519 pk
              symmetric_value:2 bytes }          // 48-byte sealed session key
IdPk        { id:1 string, pk:2 bytes }
Hash        { salt:1 string, challenge:2 string }
Auth2FA     { code:1 string, hwid:2 bytes }
OSLogin     { username:1 string, password:2 string }
FileTransfer{ dir:1 string, show_hidden:2 bool }
PortForward { host:1 string, port:2 int32 }
ViewCamera  { }
Terminal    { service_id:1 string }
```

**`LoginRequest`**

| Tag | Field | Type | Our value |
|---|---|---|---|
| 1 | `username` | string | **the peer's ID** — not a username. Mismatch ⇒ `"Offline"` |
| 2 | `password` | bytes | `h2` (32 B) or empty |
| 4 | `my_id` | string | our client id |
| 5 | `my_name` | string | operator display name |
| 6 | `option` | OptionMessage | carries `supported_decoding` |
| 7 | `file_transfer` | FileTransfer | *oneof `union`* — omit for screen sessions |
| 8 | `port_forward` | PortForward | *oneof* — not implemented |
| 9 | `video_ack_required` | bool | **`true`** |
| 10 | `session_id` | uint64 | non-zero random, stable across reconnects |
| 11 | `version` | string | ours — never empty |
| 12 | `os_login` | OSLogin | omit |
| 13 | `my_platform` | string | `"Web"` |
| 14 | `hwid` | bytes | only for trusted-device 2FA |
| 15 | `view_camera` | ViewCamera | *oneof* |
| 16 | `terminal` | Terminal | *oneof* |
| 17 | `avatar` | string | optional |

*(tag 3 unused)*

```
LoginResponse { error:1 string | peer_info:2 PeerInfo,   // oneof union
                enable_trusted_devices:3 bool }
TestDelay     { time:1 int64, from_client:2 bool,
                last_delay:3 uint32, target_bitrate:4 uint32 }
```

**`TestDelay` must be echoed verbatim when `from_client == false`.** Later than 2000 ms and the host
hard-clamps every display on the connection to 2 fps.

### 4.3 `PeerInfo` and capabilities

```
PeerInfo    { username:1, hostname:2, platform:3 (string),
              displays:4 rep DisplayInfo, current_display:5 int32,
              sas_enabled:6 bool, version:7 string,
              features:9 Features, encoding:10 SupportedEncoding,
              resolutions:11 SupportedResolutions,
              platform_additions:12 string (JSON, one level),
              windows_sessions:13 WindowsSessions }          // tag 8 unused

Features    { privacy_mode:1 bool, terminal:2 bool }
DisplayInfo { x:1 sint32, y:2 sint32, width:3 int32, height:4 int32,
              name:5 string, online:6 bool, cursor_embedded:7 bool,
              original_resolution:8 Resolution, scale:9 double }   // fixed64
Resolution           { width:1 int32, height:2 int32 }
SupportedResolutions { resolutions:1 rep Resolution }
WindowsSession       { sid:1 uint32, name:2 string }
WindowsSessions      { sessions:1 rep WindowsSession, current_sid:2 uint32 }

CodecAbility      { vp8:1, vp9:2, av1:3, h264:4, h265:5 (bool) }
SupportedEncoding { h264:1, h265:2, vp8:3, av1:4 (bool), i444:5 CodecAbility }
SupportedDecoding { ability_vp9:1, ability_h264:2, ability_h265:3 (int32),
                    prefer:4 PreferCodec, ability_vp8:5, ability_av1:6 (int32),
                    i444:7 CodecAbility, prefer_chroma:8 Chroma }
```

`ability_*` are `int32` used as booleans: 0 = cannot decode, non-zero = can. **VP9 is always usable**
and is not listed in `SupportedEncoding` — it is the implicit baseline. Advertise `ability_vp9 = 1`
unconditionally.

`DisplayInfo.scale` is the only `double` in the protocol — wire type 1, IEEE-754 little-endian.

### 4.4 `OptionMessage`

| Tag | Field | Type |
|---|---|---|
| 1 | `image_quality` | ImageQuality |
| 2 | `lock_after_session_end` | BoolOption |
| 3 | `show_remote_cursor` | BoolOption |
| 4 | `privacy_mode` | BoolOption *(deprecated ≥1.2.4)* |
| 5 | `block_input` | BoolOption |
| 6 | `custom_image_quality` | int32 — **`percent << 8`** |
| 7 | `disable_audio` | BoolOption |
| 8 | `disable_clipboard` | BoolOption |
| 9 | `enable_file_transfer` | BoolOption *(gates cliprdr, not file transfer)* |
| 10 | `supported_decoding` | SupportedDecoding |
| 11 | `custom_fps` | int32 — silently ignored outside `[1,120]` |
| 12 | `disable_keyboard` | BoolOption *(view-only)* |
| 15 | `follow_remote_cursor` | BoolOption |
| 16 | `follow_remote_window` | BoolOption |
| 17 | `disable_camera` | BoolOption |
| 18 | `terminal_persistent` | BoolOption |
| 19 | `show_my_cursor` | BoolOption |

**Tags 13 and 14 are retired — never reuse.** Never send `image_quality` and `custom_image_quality`
together: `image_quality` wins and the custom value is silently dropped.

Quality codes: `2`→Low (ratio 0.50), `3`→Balanced (0.67), `4`→Best (1.50); anything else is Custom
with `ratio = ((q >> 8) & 0xFFF) * 2 / 100`, clamped `[0.2, 40.0]`. So UI percent `P` ⇒
`custom_image_quality = P << 8`, giving `ratio = P/50`. Valid `P` is 10–100 (extended 10–2000).

### 4.5 Video

```
EncodedVideoFrame  { data:1 bytes, key:2 bool, pts:3 int64 }
EncodedVideoFrames { frames:1 rep EncodedVideoFrame }
RGB                { compress:1 bool }      // never emitted by current hosts
YUV                { compress:1 bool, stride:2 int32 }   // never emitted
VideoFrame         { vp9s:6 | rgb:7 | yuv:8 | h264s:10 | h265s:11 | vp8s:12 | av1s:13,  // oneof
                     display:14 int32 }
AudioFormat        { sample_rate:1 uint32, channels:2 uint32 }
AudioFrame         { data:1 bytes }
```

**The `oneof` tag *is* the codec identifier** — there is no separate codec field. Switch decoders on
the tag, not on what was requested.

`frames` is **repeated**: iterate the whole list in order. Skipping entries corrupts the reference
chain until the next key frame. A `VideoFrame` contains a key frame iff **any** entry has `key`.

`pts` is milliseconds since the current run of the host's per-display service and **resets to ~0** on
refresh, codec change, display change, or new subscriber. Do not build a presentation clock on it.

H.264/H.265 are **Annex-B with in-band SPS/PPS/VPS repeated on key frames**. There is no
`description`/`extradata` field anywhere — configure `VideoDecoder` with `description` omitted and
wait for a key frame before the first `decode()`.

### 4.6 Input

```
MouseEvent  { mask:1 int32, x:2 sint32, y:3 sint32, modifiers:4 rep ControlKey }
KeyEvent    { down:1 bool, press:2 bool,
              control_key:3 ControlKey | chr:4 uint32 | unicode:5 uint32
              | seq:6 string | win2win_hotkey:7 uint32,          // oneof union
              modifiers:8 rep ControlKey, mode:9 KeyboardMode }
CursorData     { id:1 uint64, hotx:2 sint32, hoty:3 sint32,
                 width:4 int32, height:5 int32, colors:6 bytes }
CursorPosition { x:1 sint32, y:2 sint32 }
PointerDeviceEvent { touch_event:1 TouchEvent, modifiers:2 rep ControlKey }  // not implemented
```

`MouseEvent.x/y` for MOVE are absolute in the peer's **virtual-desktop** space (`display.x + offset`),
`sint32` so negative for monitors left of/above primary. For WHEEL they are notch counts, normally
±1, and **the sign is inverted** relative to the browser: `deltaY > 0` (scroll down) ⇒ `y = -1`.

`CursorData.colors` is **zstd-compressed** RGBA8888, top-down, no stride, straight alpha; decompressed
length is exactly `width * height * 4`. Repeat shapes arrive as a bare `cursor_id` — **cache every
shape by `id` for the whole session and never evict**, there is no way to re-request one.

### 4.7 `Misc` — the control `oneof`

| Tag | Field | Type | Tag | Field | Type |
|---|---|---|---|---|---|
| 4 | `chat_message` | ChatMessage | 24 | `change_resolution` | Resolution *(dep. ≥1.2.4)* |
| 5 | `switch_display` | SwitchDisplay | 25 | `plugin_request` | PluginRequest |
| 6 | `permission_info` | PermissionInfo | 26 | `plugin_failure` | PluginFailure |
| 7 | `option` | OptionMessage | 27 | `full_speed_fps` | uint32 *(dep.)* |
| 8 | `audio_format` | AudioFormat | 28 | `auto_adjust_fps` | uint32 |
| 9 | `close_reason` | string | 29 | `client_record_status` | bool |
| 10 | `refresh_video` | bool | 30 | `capture_displays` | CaptureDisplays |
| 12 | `video_received` | bool | 31 | `refresh_video_display` | int32 |
| 13 | `back_notification` | BackNotification | 32 | `toggle_virtual_display` | ToggleVirtualDisplay |
| 14 | `restart_remote_device` | bool | 33 | `toggle_privacy_mode` | TogglePrivacyMode |
| 15 | `uac` | bool | 34 | `supported_encoding` | SupportedEncoding |
| 16 | `foreground_window_elevated` | bool | 35 | `selected_sid` | uint32 |
| 17 | `stop_service` | bool | 36 | `change_display_resolution` | DisplayResolution |
| 18 | `elevation_request` | ElevationRequest | 37 | `message_query` | MessageQuery |
| 19 | `elevation_response` | string | 38 | `follow_current_display` | int32 |
| 20 | `portable_service_running` | bool | | | |
| 21 | `switch_sides_request` | SwitchSidesRequest | | | |
| 22 | `switch_back` | SwitchBack | | | |

Tags 1–3, 11, 23 unused.

```
SwitchDisplay   { display:1 int32, x:2 sint32, y:3 sint32, width:4 int32, height:5 int32,
                  cursor_embedded:6 bool, resolutions:7 SupportedResolutions,
                  original_resolution:8 Resolution }
CaptureDisplays { add:1 rep int32, sub:2 rep int32, set:3 rep int32 }
DisplayResolution { display:1 int32, resolution:2 Resolution }
PermissionInfo  { permission:1 Permission, enabled:2 bool }
MessageQuery    { switch_display:1 int32 }
ChatMessage     { text:1 string }
MessageBox      { msgtype:1, title:2, text:3, link:4 (string) }
BackNotification{ privacy_mode_state:1 | block_input_state:2,   // oneof union
                  details:3 string, impl_key:4 string }
TogglePrivacyMode    { impl_key:1 string, on:2 bool }
ToggleVirtualDisplay { display:1 int32, on:2 bool }
ScreenshotRequest    { display:1 int32, sid:2 string }
ScreenshotResponse   { sid:1 string, msg:2 string, data:3 bytes }
VoiceCallRequest     { req_timestamp:1 int64, is_connect:2 bool }
VoiceCallResponse    { accepted:1 bool, req_timestamp:2 int64, ack_timestamp:3 int64 }
```

**Permissions are signalled negatively.** After login the host sends `PermissionInfo{enabled:false}`
**only for permissions it denies**; grants produce no message at all. Initialize every permission to
**true**. They change mid-session when the operator toggles them.

**Ordering:** the host uses two queues. `VideoFrame` and `Misc{switch_display}` share the *video*
queue; everything else is on the *general* queue. Only `switch_display` is ordered against video.

### 4.8 Clipboard

```
Clipboard        { compress:1 bool, content:2 bytes, width:3 int32, height:4 int32,
                   format:5 ClipboardFormat, special_name:6 string }
MultiClipboards  { clipboards:1 rep Clipboard }
```

`content` after optional zstd-decompress: `Text`/`Rtf`/`Html`/`ImageSvg` are UTF-8 bytes; `ImageRgba`
is raw RGBA8 with `width`/`height` meaningful; `ImagePng` is a complete PNG and **`compress` is always
false** for it. Send `MultiClipboards` to peers ≥1.3.0 (non-iOS; Android ≥1.3.3), else a single
`Clipboard` with the `Text` entry.

Loop suppression uses a synthetic `Special` entry named `dyn.com.rustdesk.owner` which a browser
cannot write. Instead, hash the last content we applied and suppress echoing it.

`Cliprdr` (Message tag 20) is **not implemented** — see `PLAN.md` §5.

### 4.9 File transfer

```
FileEntry     { entry_type:1 FileType, name:2 string, is_hidden:3 bool,
                size:4 uint64, modified_time:5 uint64 }        // SECONDS, not ms
FileDirectory { id:1 int32, path:2 string, entries:3 rep FileEntry }
ReadDir       { path:1 string, include_hidden:2 bool }
ReadEmptyDirs { path:1 string, include_hidden:2 bool }
ReadEmptyDirsResponse { path:1 string, empty_dirs:2 rep FileDirectory }
ReadAllFiles  { id:1 int32, path:2 string, include_hidden:3 bool }
FileRename    { id:1 int32, path:2 string, new_name:3 string }
FileDirCreate { id:1 int32, path:2 string }
FileRemoveDir { id:1 int32, path:2 string, recursive:3 bool }
FileRemoveFile{ id:1 int32, path:2 string, file_num:3 sint32 }

FileTransferBlock  { id:1 int32, file_num:2 sint32, data:3 bytes,
                     compressed:4 bool, blk_id:5 uint32 }      // blk_id unused
FileTransferDone   { id:1 int32, file_num:2 sint32 }
FileTransferError  { id:1 int32, error:2 string, file_num:3 sint32 }
FileTransferCancel { id:1 int32 }
FileTransferDigest { id:1 int32, file_num:2 sint32, last_modified:3 uint64,
                     file_size:4 uint64, is_upload:5 bool, is_identical:6 bool,
                     transferred_size:7 uint64, is_resume:8 bool }
FileTransferSendRequest { id:1 int32, path:2 string, include_hidden:3 bool,
                          file_num:4 int32, file_type:5 FileType }
FileTransferSendConfirmRequest { id:1 int32, file_num:2 sint32,
                                 skip:3 bool | offset_blk:4 uint32 }   // oneof union
FileTransferReceiveRequest { id:1 int32, path:2 string, files:3 rep FileEntry,
                             file_num:4 int32, total_size:5 uint64 }

FileAction   { read_dir:1 | send:2 | receive:3 | create:4 | remove_dir:5 | remove_file:6
             | all_files:7 | cancel:8 | send_confirm:9 | rename:10 | read_empty_dirs:11 }
FileResponse { dir:1 | block:2 | error:3 | done:4 | digest:5 | empty_dirs:6 }
```

Traps: `modified_time` is **seconds**; `size` is 0 for every non-`File` type; `offset_blk` is a
**byte** offset despite the name (and `uint32`, so resume caps at 4 GiB); `id == 0` is reserved for
unsolicited `read_dir` results, correlated by echoed `path` rather than id; `read_dir` has **no error
response** — use a 2 s timeout; and both `FileAction` and `FileResponse` flow **both directions** (the
*reader* emits `block`/`digest`/`done`, the *writer* emits `send_confirm`/`cancel`).

Compression is **zstd level 3**, self-contained frame per message, signalled per-message by
`compressed`. We decompress on download and always send `compressed:false` on upload.

### 4.10 Terminal

```
OpenTerminal   { terminal_id:1 int32, rows:2 uint32, cols:3 uint32 }
ResizeTerminal { terminal_id:1 int32, rows:2 uint32, cols:3 uint32 }
TerminalData   { terminal_id:1 int32, data:2 bytes, compressed:3 bool }
CloseTerminal  { terminal_id:1 int32 }
TerminalAction { open:1 | data:2 | resize:3 | close:4 }

TerminalOpened { terminal_id:1 int32, success:2 bool, message:3 string, pid:4 uint32,
                 service_id:5 string, persistent_sessions:6 rep int32,
                 replay_terminal_output:7 bool }
TerminalClosed { terminal_id:1 int32, exit_code:2 int32 }
TerminalError  { terminal_id:1 int32, message:2 string }
TerminalResponse { opened:1 | data:2 | closed:3 | error:4 }
```

`TerminalData.data` is a raw PTY byte stream (ANSI/VT included), zstd-compressed when >512 bytes.
Not UTF-8-guaranteed — decode incrementally with a streaming decoder tolerant of split sequences.

---

## 5. Version gates

Compare `PeerInfo.version` numerically (`major*1e6 + minor*1e3 + patch`).

| Capability | Min peer version |
|---|---|
| `"No Password Access"` login error | 1.2.0 |
| Keyboard modes Map / Translate | 1.2.0 |
| Overwrite detection (file digest) | 1.1.10 |
| `windows_sessions` + `selected_sid`, `change_display_resolution`, `refresh_video_display` | 1.2.4 |
| `MultiClipboards` | 1.3.0 (Android 1.3.3) |
| `read_empty_dirs` | 1.3.3 |
| File copy/paste on unix | 1.3.8 |
| `view_camera` | 1.3.9 |
| Screenshot | 1.4.0 |
| `terminal` | 1.4.1 |
| File transfer resume | 1.4.2 |

Below 1.2.4, use `Misc{refresh_video:true}` instead of `refresh_video_display`, and
`Misc{option{privacy_mode}}` instead of `toggle_privacy_mode`.
