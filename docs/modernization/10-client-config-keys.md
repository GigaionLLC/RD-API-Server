# 10 · Client Configuration Keys — the Strategy / Security-Settings-Sync surface

This is an exhaustive catalog of the client option keys a self-hosted API server can push to
RustDesk clients via the heartbeat **Strategy** mechanism (the Pro "Security Settings sync /
Strategy" feature). It drives our Strategy feature implementation.

Everything here is sourced from the RustDesk client at **rustdesk commit `a0c0963c`**
(master, 2026-11-24; the `1.4.9` → `1.5.3` changes are noted where they matter). The single
authoritative source is now `libs/base/src/config/keys.rs` in the rustdesk repo — the option
`keys` module moved out of the `hbb_common` submodule in 1.5.0 (#16107/#598), and
`libs/hbb_common/src/config.rs` keeps only the ~33 constants that `hbb_common` itself reads.
Key lists at `a0c0963c`: `KEYS_DISPLAY_SETTINGS:191`, `KEYS_LOCAL_SETTINGS:223`,
`KEYS_SETTINGS:276`, `KEYS_BUILDIN_SETTINGS:343`; individual constants are cited by line
(`keys.rs:NN`).

> Line numbers of the form `config.rs:NNNN` further down are from the pre-1.5.0 layout and were
> **not** re-verified against `keys.rs`; treat the file above as the source of truth and the
> numbers as historical anchors.

**Client versions verified against:** `1.4.9` (the original baseline) and `1.5.3`
(`95cca3f6`) / master `a0c0963c`. Two behaviours changed in between and are load-bearing for
any server that pushes a Strategy:

1. **1.5.3+ strips 24 keys from a server push** — see §1b.
2. **`allow-linux-headless` no longer exists** (deleted in 1.5.0, #15866).

### 1b. What a 1.5.3+ client refuses to apply (`BLOCKED_CONFIG_OPTIONS`)

`src/hbbs_http/sync.rs:297-326` (list) + `:329-341` (`filter_config_options`) at `a0c0963c`;
added by #16696 (first tag 1.5.3) and extended by #17130 on master. The client logs
`Rejected heartbeat strategy options: [...]` and drops these keys **before**
`Config::set_options`, so an admin who believes they disabled insecure TLS, WebSocket or
trusted devices gets none of it on a 1.5.3+ client:

| Group | Keys |
|-------|------|
| Server endpoints and trust | `custom-rendezvous-server`, `rendezvous-servers`, `api-server`, `relay-server`, `key`, `ice-servers` |
| Transport security | `allow-insecure-tls-fallback`, `allow-websocket`, `websocket-port` |
| Authentication and credentials | `2fa`, `bot`, `preset-address-book-password`, `enable-trusted-devices`, `trusted-device-expiration-days` |
| Deployment and address-book metadata | `preset-address-book-name`, `preset-address-book-tag`, `preset-address-book-alias`, `preset-address-book-note`, `preset-device-name`, `preset-device-username`, `preset-note` |
| Recording location and service state | `windows-service-video-save-directory`, `stop-service`, `reg_recovery` |

Consequences for us (implemented in `config/strategy_options.php` +
`ClientConfigService::STRATEGY_IGNORED_SINCE`):

- The catalog keeps the three keys it offers from that list
  (`allow-insecure-tls-fallback`, `allow-websocket`, `enable-trusted-devices`) because **older
  clients still apply them**; each is flagged `'strategy_ignored_since' => '1.5.3'` and the
  editor prints the warning under the control.
- A free-form custom option that names a blocked key is saved but the save response carries a
  `warning`, so the admin is told instead of silently pushing nothing.
- Install-time delivery still works: `rustdesk --option <key> <value>`, the config string and a
  custom client's `default-settings`/`override-settings` are not filtered this way.

### 1c. Value precedence — a stored value always wins (implemented)

> A value stored in a strategy always wins; the server never adds, rewrites or drops an option
> value by itself.

Enforced in the editor and in both write paths (`Admin\StrategyController::update`,
`Api\V1\StrategyController::update`). The load-bearing fact is that `strategies.options` is one
flat map: "catalog" versus "custom" is only a display split, and a key becomes a built-in control
the moment `config/strategy_options.php` starts offering one for it — including keys someone had
already set by hand as a custom option.

- **A value the control cannot express is kept, not blanked.** A toggle holding `true`/`1`/`y`, a
  select value outside its `choices`, or a non-numeric value in a `number` field renders as an
  extra `Custom: <value>` choice (already selected) with the note "Not a value this control
  offers; kept exactly as set." Number fields fall back to a text input so the browser cannot
  blank the value on render. The value stays until an administrator picks another choice,
  including *Default*, which omits the key from the push again. For the `allow-` keys the editor
  adds "RustDesk treats anything other than `Y` as off for this key" (that is `option2bool`,
  §2 — verified at rustdesk a0c0963c → `libs/hbb_common` @ 450cc1d7, `src/config.rs:2924`-`2936`).
- **A request that never mentions such a key cannot delete it.** The editor posts every control
  it renders, so an absent catalog key with a value the control cannot express is re-merged from
  the stored map server-side. An explicit empty value is the *Default* choice and still clears.
- **A custom row that names a built-in control keeps winning.** The custom value is stored and
  the save response carries `` `<key>` is also a built-in control; your custom value was saved and
  takes precedence. `` It is appended to the same single `warning` field as the 1.5.3+ ignore
  notice (§1b); the JSON shape stays `{}` when there is nothing to say.
- **A new built-in control announces itself once.** Catalog options carry `added_in` / `added_on`.
  When a strategy stores a value for such a key and was last saved (`modified_at`) before that
  date, the editor shows "Added as a built-in control in RD-API `<added_in>`. This strategy
  already had `<value>` set by hand; it was kept and is still pushed." The note disappears on the
  strategy's next save.
- **A delegated editor cannot lose a full-admin-only value.** The editor renders and posts the
  stored value, so an unrelated save keeps `ClientConfigService::FULL_ADMIN_ONLY_OPTION_KEYS`
  exactly as stored — and a value the control cannot express is re-merged server-side on top. The
  guard is unchanged: setting, changing *or* removing one is still rejected with `Only a full
  administrator can set or change: …`.
- **Nothing else injects or rewrites values.** `Strategy::pushableOptions()` only drops keys the
  client no longer defines (`removed`), `StrategyService::heartbeatPayload()` pushes the map as a
  JSON object unchanged, and the install-script generator
  (`ClientConfigService::installScript`) only skips empty values.

---

## 1. How the Strategy mechanism works end-to-end

### The transport (client side)

1. Every ~15s (and on first connect) the client POSTs `/api/heartbeat` with
   `{id, uuid, ver, conns?, modified_at}` — see
   `src/hbbs_http/sync.rs:235`-`244`. `modified_at` is the client's last-known strategy
   timestamp, read from local config key `strategy_timestamp`
   (`src/hbbs_http/sync.rs:242`).
2. The server's heartbeat response is parsed as a `HashMap<&str, Value>`
   (`src/hbbs_http/sync.rs:245`). The client looks for these optional keys in the response:
   - `sysinfo` — if present, force a sysinfo re-upload (`sync.rs:246`).
   - `disconnect` — a `Vec<i32>` of connection ids to drop (`sync.rs:251`).
   - `modified_at` — server's current strategy timestamp; if it differs from the client's,
     the client stores it locally (`sync.rs:256`-`262`).
   - `strategy` — the payload that carries config (`sync.rs:263`).
3. The `strategy` value deserializes into `StrategyOptions` (`src/hbbs_http/sync.rs:44`-`50`):

   ```rust
   pub struct StrategyOptions {
       #[serde(default, skip_serializing_if = "HashMap::is_empty")]
       pub config_options: HashMap<String, String>,   // <-- the option keys in this doc
       #[serde(default, skip_serializing_if = "HashMap::is_empty")]
       pub extra: HashMap<String, String>,            // <-- see §4
   }
   ```

4. Only `config_options` is consumed: `handle_config_options(strategy.config_options)`
   (`sync.rs:266`). **`extra` is currently ignored by the client** (see §4).

### `handle_config_options` — how keys are applied (`src/hbbs_http/sync.rs:287`-`304`)

```rust
fn handle_config_options(config_options: HashMap<String, String>) {
    let mut options = Config::get_options();
    let default_settings = config::DEFAULT_SETTINGS.read().unwrap().clone();
    for (k, v) in config_options {
        // Priority: user config > default advanced options.
        if v.is_empty() && default_settings.get(k).map_or("", |v| v).is_empty() {
            options.remove(k);          // empty value + no custom-client default => fall back to built-in default
        } else {
            options.insert(k.to_string(), v.to_string());  // otherwise set it
        }
    }
    Config::set_options(options);
}
```

Key facts that constrain what the server can push:

- The whole map is written into `CONFIG2.options` (the persisted user-overridable layer).
  Pushing `key -> ""` **deletes** the key (reverting to the built-in/custom-client default);
  pushing `key -> "value"` **sets** it. So a strategy is a full desired-state map, not a diff.
- `Config::set_options` runs `purify_options` (`config.rs:1231`-`1243`), which drops any key
  that (a) is locked by `OVERWRITE_SETTINGS`, or (b) equals the `DEFAULT_SETTINGS` value. So
  the server cannot override a hard-locked (custom-client `--overwrite`) key, and redundant
  values are not persisted.
- There is **no allow-list** on `handle_config_options` — any string key the server sends is
  written verbatim. The keys below are simply the ones the client *reads* elsewhere; sending
  an unknown key just litters the config. The catalog below is therefore the set of keys
  worth exposing in a Strategy editor.

### Config resolution / precedence (read path)

`Config::get_option(k)` (`config.rs:1245`) and `get_options()` (`config.rs:1223`) resolve in
this order (highest wins):

1. **`OVERWRITE_SETTINGS`** — hard locks from a custom-built client (`--overwrite-settings`).
   Not strategy-controllable.
2. **`CONFIG2.options`** — the user-overridable layer. **This is where strategy writes.**
3. **`DEFAULT_SETTINGS`** — soft defaults baked into a custom-built client
   (`--default-settings`).
4. **Built-in default semantics** — when a key is absent everywhere, `option2bool` decides
   (see §2).

### Boolean default semantics — `option2bool` (`config.rs:2829`-`2841`)

This is load-bearing for every bool key's default. Stored values are the strings `"Y"`/`"N"`
(or empty):

```rust
pub fn option2bool(option: &str, value: &str) -> bool {
    if option.starts_with("enable-") {        value != "N" }   // default ON  (absent => true)
    else if option.starts_with("allow-")
         || option == "stop-service"
         || option == "direct-server"
         || option == "force-always-relay" {  value == "Y" }   // default OFF (absent => false)
    else {                                     value != "N" }   // default ON
}
```

So in the tables below: `enable-*` keys default **ON**, `allow-*` (plus `direct-server`)
keys default **OFF**, and a value of `""`/absent means "use this default".

---

## 2. The catalog — every option key

Keys come from four named groups in the `keys` module, each backed by a different config
layer:

| List | Backing layer | Server-side meaning | Strategy-relevant? |
|------|---------------|---------------------|--------------------|
| `KEYS_SETTINGS` (`config.rs:3130`) | `DEFAULT_SETTINGS` / `OVERWRITE_SETTINGS` → `CONFIG2.options` | host security & connection settings | **Yes — primary Strategy surface** |
| `KEYS_DISPLAY_SETTINGS` (`config.rs:3055`) | `DEFAULT_DISPLAY_SETTINGS` | per-remote-session viewer prefs | Mostly viewer-side; rarely pushed |
| `KEYS_LOCAL_SETTINGS` (`config.rs:3087`) | `DEFAULT_LOCAL_SETTINGS` | local UI/app prefs | Mostly local; rarely pushed |
| `KEYS_BUILDIN_SETTINGS` (`config.rs:3189`) | built-in/preset (custom-client) | client-build presets & UI hiding | Built at client-gen time, not strategy |

> The thing a Strategy normally edits is the **`KEYS_SETTINGS`** group (host-side security and
> permissions). `config_options` *can* carry display/local keys too, but those describe the
> controlling viewer, so pushing them to a device is unusual.

### 2a. Permissions (in-session capabilities the host grants) — `enable-*`

These are the host-side toggles that decide what an incoming remote operator may do. Every
one is also a Pro **Control Role** permission (see §3). All default **ON** (`enable-` prefix).

| Key | Default | Type | Meaning | Maps-to Pro |
|-----|---------|------|---------|-------------|
| `enable-keyboard` | ON | bool Y/N | Allow remote keyboard/mouse input | Control Role: keyboard |
| `enable-clipboard` | ON | bool Y/N | Allow clipboard sync | Control Role: clipboard |
| `enable-file-transfer` | ON | bool Y/N | Allow file transfer | Control Role: file |
| `enable-camera` | ON | bool Y/N | Allow remote camera access | Control Role: camera |
| `enable-terminal` | ON | bool Y/N | Allow remote terminal | Control Role: terminal |
| `enable-remote-printer` | ON | bool Y/N | Allow remote printing | Control Role: remote_printer |
| `enable-audio` | ON | bool Y/N | Allow audio forwarding | Control Role: audio |
| `enable-tunnel` | ON | bool Y/N | Allow TCP tunneling/port-forward | Control Role: tunnel |
| `enable-remote-restart` | ON | bool Y/N | Allow remote restart of the machine | Control Role: restart |
| `enable-record-session` | ON | bool Y/N | Allow the operator to record the session | Control Role: recording |
| `enable-block-input` | ON | bool Y/N | Allow blocking local input during control | Control Role: block_input |
| `enable-privacy-mode` | ON | bool Y/N | Allow privacy (black-screen) mode | Control Role: privacy_mode |

Definitions: `config.rs:2899`-`2910`. The Control-Role override mapping lives in
`src/server/connection.rs:2262`-`2292` (`Connection::permission`), which prefers a protocol
`ControlPermissions` value and otherwise falls back to `is_permission_enabled_locally`
(`connection.rs:2249`, which also honors `access-mode`).

### 2b. Security / Access control

| Key | Default | Type | Meaning | Maps-to Pro |
|-----|---------|------|---------|-------------|
| `access-mode` | `""` (custom) | enum `""`/`full`/`view` | Master switch: `full` forces all perms on, `view` forces view-only, `""` = use individual `enable-*` | Strategy: Permissions preset |
| `approve-mode` | `both` | enum `password`/`click`/`both` | How incoming sessions are approved: password only, manual click, or either | Strategy: security |
| `verification-method` | `use-both-passwords` | enum `use-temporary-password`/`use-permanent-password`/`use-both-passwords` | Which password types are accepted | Strategy: security |
| `temporary-password-length` | `6` | enum `6`/`8`/`10` | Length of generated one-time password | Strategy: security |
| `allow-numeric-one-time-password` | OFF | bool Y/N | Generate numeric (vs alphanumeric) OTP | Strategy: security |
| `allow-remote-config-modification` | OFF | bool Y/N | Let a connected operator change this host's settings | Strategy: security |
| `verification-method` … | — | — | (enum resolution: `libs/hbb_common/src/password_security.rs:42`-`51`) | — |
| `whitelist` | `""` (none) | string (comma/`,`-sep IPs/CIDRs; `0.0.0.0` = allow all) | IP allow-list for incoming connections | Strategy: Access control |
| `id-whitelist` | `""` (none) | string (comma`,`-separated client IDs, `*` wildcards) | Client-ID allow-list, enforced **in addition to** the IP whitelist | Strategy: Access control (1.5.0+, #15586) |
| `allow-auto-disconnect` | OFF | bool Y/N | Auto-drop idle incoming sessions | Strategy: security |
| `auto-disconnect-timeout` | `""` (mins) | string (minutes) | Idle timeout used by the above | Strategy: security |
| `allow-only-conn-window-open` | OFF | bool Y/N | Only accept connections while the main window is open | Strategy: security |
| `enable-trusted-devices` | ON | bool Y/N | Allow "trusted device" 2FA bypass | **not strategy-settable on 1.5.3+** (§1b); trusted-device V2 moved it to its own auth path |
| `allow-logon-screen-password` | OFF (built-in)\* | bool Y/N | Allow permanent password at the Windows logon screen | Strategy: security |
| `allow-remove-wallpaper` | OFF | bool Y/N | Allow removing wallpaper during a session | Strategy: privacy |

Definitions: `access-mode` `config.rs:2898`; `approve-mode`/`verification-method`/
`temporary-password-length` `config.rs:2930`-`2932`; `allow-numeric-one-time-password`
`config.rs:2914`; `allow-remote-config-modification` `config.rs:2913`; `whitelist`
`config.rs:2918`; auto-disconnect `config.rs:2919`-`2921`; `enable-trusted-devices`
`config.rs:2948`. Enum resolution for password modes:
`libs/hbb_common/src/password_security.rs:42`-`86`. Whitelist parse/`0.0.0.0`-means-all:
`src/server/connection.rs:1291`-`1312`. `access-mode` short-circuit:
`connection.rs:2249`-`2254`.

\* `allow-logon-screen-password` is in `KEYS_BUILDIN_SETTINGS` (`config.rs:3208`), so it is
normally a custom-client preset rather than a strategy push.

### 2c. Connection / Network

| Key | Default | Type | Meaning | Maps-to Pro |
|-----|---------|------|---------|-------------|
| `custom-rendezvous-server` | `""` | string (host) | Override ID/rendezvous server (hbbs) | Strategy: server settings |
| `relay-server` | `""` | string (host) | Override relay server (hbbr) | Strategy: server settings |
| `api-server` | `""` | string (URL) | Override API server URL | Strategy: server settings |
| `key` | `""` | string | Public key of the self-hosted server | Strategy: server settings |
| `direct-server` | OFF | bool Y/N | Enable direct IP access (listen locally) | Strategy: network |
| `direct-access-port` | `""` | string (port) | Port for direct IP access | Strategy: network |
| `enable-lan-discovery` | ON | bool Y/N | Respond to LAN discovery broadcasts | Strategy: network |
| `allow-websocket` | OFF | bool Y/N | Use WebSocket transport | **not strategy-settable on 1.5.3+** (§1b) |
| `allow-kcp-congestion-control` | OFF | bool Y/N | Run KCP with its congestion window instead of the turbo profile | Strategy: network (opt-in; `src/common.rs:2756`-`2763`) |
| `disable-udp` | OFF | bool Y/N | Disable UDP (force TCP) | Strategy: network |
| `allow-insecure-tls-fallback` | OFF | bool Y/N | Permit insecure TLS fallback | **not strategy-settable on 1.5.3+** (§1b) |
| `ice-servers` | `""` | string (JSON) | Custom ICE/STUN/TURN servers | Strategy: network |
| `relay-server` … | — | — | — | — |
| `register-device` | `""` | bool Y/N | Auto-register device with the server | Strategy: device mgmt |

Definitions: `config.rs:2933`-`2936` (`custom-rendezvous-server`, `api-server`, `key`,
`allow-websocket`), `2916`-`2917` (`direct-server`, `direct-access-port`), `2915`
(`enable-lan-discovery`), `2952`-`2953` (`relay-server`, `ice-servers`), `2964`-`2965`
(`disable-udp`, `allow-insecure-tls-fallback`), `2951` (`register-device`). `use_ws()` reads
`allow-websocket` via `option2bool` at `config.rs:2843`-`2846`. `heartbeat_url()` derives the
endpoint from `api-server` + `custom-rendezvous-server` (`src/hbbs_http/sync.rs:276`-`285`).

### 2d. Audio / Recording

| Key | Default | Type | Meaning | Maps-to Pro |
|-----|---------|------|---------|-------------|
| `enable-record-session` | ON | bool Y/N | Allow operator-initiated recording (also a permission, §2a) | Control Role: recording |
| `allow-auto-record-incoming` | OFF | bool Y/N | Auto-record incoming (host) sessions | Strategy: recording |
| `allow-auto-record-outgoing` | OFF | bool Y/N | Auto-record outgoing (viewer) sessions — *local setting* | Strategy: recording (viewer) |
| `video-save-directory` / `windows-service-video-save-directory` | `""` | string (path) | Where recordings are written — *local setting* / service key | local; the service key is **not strategy-settable on 1.5.3+** (§1b) |
| `disable_audio` | OFF | bool Y/N | (viewer-side) mute remote audio — *display setting* | viewer pref |
| `enable-audio` | ON | bool Y/N | Allow audio (host permission, §2a) | Control Role: audio |

Definitions: `allow-auto-record-incoming` `config.rs:2922`, `allow-auto-record-outgoing`
`config.rs:2923` (in `KEYS_LOCAL_SETTINGS`, `config.rs:3119`), `video-save-directory`
`config.rs:2924` (local, `config.rs:3120`), `disable_audio` `config.rs:2862` (display,
`config.rs:3064`).

### 2e. Display / Performance (host capture & codec)

These mostly describe the controlling viewer (`KEYS_DISPLAY_SETTINGS`), but a few host-side
capture/codec keys live in `KEYS_SETTINGS` and are legitimately strategy-pushable.

Host-side (`KEYS_SETTINGS`):

| Key | Default | Type | Meaning |
|-----|---------|------|---------|
| `enable-abr` | ON | bool Y/N | Adaptive bitrate |
| `enable-hwcodec` | ON | bool Y/N | Hardware codec |
| `enable-directx-capture` | ON | bool Y/N | Use DirectX capture (Windows) |
| `enable-android-software-encoding-half-scale` | ON | bool Y/N | Android half-scale software encoding |
| `allow-always-software-render` | OFF | bool Y/N | Force software rendering |
| `allow-drm-capture` | OFF | bool Y/N | Linux + Wayland: capture scanout through the service (no portal prompt, works at the login screen) — `keys.rs:92`, `KEYS_SETTINGS:339`; needs a build **newer than 1.5.3** (#17089) and the `drm` feature + a loadable `libdrmtap`; X11 unaffected; toggling it restarts `--server` |

`allow-linux-headless` was **removed upstream** in 1.5.0 (#15866): no client reads it, so it is
no longer in `keys.rs` and must not be offered as a Strategy control (our catalog lists it under
its `removed` map instead: stored value kept, not pushed).

Definitions: `config.rs:2925`-`2929`, `2945`-`2946`. Viewer-side display keys (codec
preference, image quality, fps, view style, cursor options, etc.) are listed in
`KEYS_DISPLAY_SETTINGS` (`config.rs:3055`-`3085`) — generally not part of a device Strategy.

### 2f. Privacy

| Key | Default | Type | Meaning | Maps-to Pro |
|-----|---------|------|---------|-------------|
| `enable-privacy-mode` | ON | bool Y/N | Allow privacy mode (host permission, §2a) | Control Role: privacy_mode |
| `allow-remove-wallpaper` | OFF | bool Y/N | Remove wallpaper during sessions | Strategy: privacy |
| `keep-awake-during-incoming-sessions` | ON\* | bool Y/N | Keep host awake during incoming sessions (security setting) | Strategy: security |
| `lock_after_session_end` | OFF | bool Y/N | Lock host on session end — *display setting* | viewer-driven |
| `one-way-clipboard-redirection` | OFF (built-in) | bool Y/N | Restrict clipboard to one direction | custom-client preset |
| `one-way-file-transfer` | OFF (built-in) | bool Y/N | Restrict file transfer to one direction | custom-client preset |

Definitions: `keep-awake-during-incoming-sessions` `config.rs:3036` (in `KEYS_SETTINGS`,
`config.rs:3184`); `lock_after_session_end` `config.rs:2866` (display); one-way keys
`config.rs:2997`/`3001` (built-in). \* `keep-awake-*` has no special prefix, so `option2bool`
returns ON unless set to `"N"`.

### 2g. Other / Updates / Address-book presets

| Key | Default | Type | Meaning | Strategy-relevant? |
|-----|---------|------|---------|--------------------|
| `enable-check-update` | ON | bool Y/N | Check for client updates (`KEYS_LOCAL_SETTINGS`-adjacent) | local |
| `allow-auto-update` | OFF | bool Y/N | Auto-update client (in `KEYS_SETTINGS`, `config.rs:3185`) | Strategy: updates |
| `proxy-url` / `proxy-username` / `proxy-password` | `""` | string | Proxy settings (pseudo-keys → `Config2::socks`) | Strategy: network/proxy |
| `preset-address-book-name` / `-tag` / `-alias` / `-password` / `-note` | `""` | string | Preset address-book assignment (uploaded with sysinfo) | device onboarding |
| `preset-device-username` / `preset-device-name` / `preset-note` | `""` | string | Preset device identity (override hostname/username) | device onboarding |
| `file-transfer-max-files` | `0`→safe default | int | Max files per transfer request | hardening (built-in) |

Definitions: `enable-check-update`/`allow-auto-update` `config.rs:2893`-`2894`; proxy
pseudo-keys `config.rs:3050`-`3052` (note the comment: these are not real keys, they map to
`Config2::socks`); preset address-book keys `config.rs:2937`-`2944`; `file-transfer-max-files`
`config.rs:2954`-`2963`. The preset-* keys are read back into the sysinfo upload at
`src/hbbs_http/sync.rs:135`-`178`.

**1.5.x changes here:** every `preset-*` key and `windows-service-video-save-directory` are in
the 1.5.3+ deny-list (§1b), so onboarding data must arrive through `/api/assign` /
`/api/devices/cli` or a custom client, not a Strategy. On master, `/api/sysinfo` **no longer
uploads `preset-address-book-password`** at all (#17130, `src/hbbs_http/sync.rs:145-152`): our
`/api/sysinfo` handler still accepts the field, which is now a dead path (keep it for 1.4.9
clients, do not build on it).

### 2h. Built-in / UI-hiding keys (custom-client only — NOT strategy)

`KEYS_BUILDIN_SETTINGS` (`keys.rs:343`-`380`) are baked at client-generation time and read
via `get_builtin_option` (the hard/preset layer), e.g. `hide-security-settings`,
`hide-network-settings`, `hide-server-settings`, `hide-proxy-settings`, `hide-tray`,
`disable-change-permanent-password`, `disable-change-id`, `disable-unlock-pin`,
`default-connect-password`, `register-device`, `allow-hostname-as-id`, `hide-powered-by-me`,
`main-window-always-on-top`, `enable-perm-change-in-accept-window`,
`allow-command-line-settings-when-settings-disabled`. These configure the *client build*, not
a per-device strategy, and are surfaced via the Custom Client Generator rather than the
Strategy editor.

**Why that matters for 1.5.x keys.** `BUILTIN_SETTINGS` is filled *only* from a custom client's
`custom.txt` default/override settings (`src/common.rs:2563` at `a0c0963c`); `Config::set_options`
writes `CONFIG2.options` and nothing copies it into the builtin layer. So the 1.5.x additions in
that list — `hide-general-settings` (`keys.rs:119`), `hide-account-password-login`
(`keys.rs:127`), `quit-button-text` (`keys.rs:128`) — are **inert when pushed by a strategy** and
are deliberately absent from the editor. The same is true for the new `KEYS_LOCAL_SETTINGS` keys
read through `LocalConfig` / `mainGetLocalOption`: `keep-awake-during-outgoing-sessions`
(`keys.rs:184`), `relay-fallback-delay` (`keys.rs:138`), `hide-recording-button` (`keys.rs:65`).

---

## 3. Pro feature mapping: Control Role vs Strategy vs Security settings

RustDesk Pro splits the option surface into three conceptual buckets. They share the same key
strings but are delivered/enforced differently:

- **Control Role (in-session permissions)** — the 12 `enable-*` permission keys in §2a. In a
  real session these can be overridden per-connection by a protocol `ControlPermissions`
  message (mapped in `src/server/connection.rs:2262`-`2292`). A "role" is a named bundle of
  these toggles assigned to a user; it answers *"what can this operator do once connected?"*.
  When no role/protocol override is present, the host's local `enable-*` config (which a
  Strategy may have set) is the fallback.

- **Strategy (device settings)** — a named bundle of `config_options` pushed to assigned
  devices/groups via heartbeat (this whole document). It answers *"how is this device
  configured?"* — security mode, network/server overrides, recording, permission defaults,
  etc. Primarily the `KEYS_SETTINGS` group (§2a–2g).

- **Security settings** — the subset of Strategy keys governing authentication and access:
  `approve-mode`, `verification-method`, `temporary-password-length`,
  `allow-numeric-one-time-password`, `allow-remote-config-modification`, `whitelist`,
  auto-disconnect keys, `enable-trusted-devices`, `keep-awake-during-incoming-sessions`. These
  are what the Pro console groups under a device's "Security" tab and are the same keys a
  Strategy carries.

In practice for *this* server: a Strategy = `{config_options: {…}, extra: {…}}` keyed by
device/group, served from the heartbeat response. Control Roles additionally need the protocol
`ControlPermissions` path (a server-cooperation feature that lives in `hbbs`, not the API
layer) to take effect mid-session; without it, only the pushed `enable-*` config defaults
apply.

---

## 4. `config_options` and `extra` — consumption status

- **`config_options: HashMap<String,String>`** — fully consumed by
  `handle_config_options` (`src/hbbs_http/sync.rs:287`). Written into the user-overridable
  config layer. This is the field our Strategy editor populates.
- **`extra: HashMap<String,String>`** — declared on `StrategyOptions`
  (`sync.rs:48`-`49`) but **never read** in the open-source client at this commit. The
  Pro `hbbs` uses `extra` for non-`config_options` payloads (e.g. assignment metadata,
  address-book/strategy bookkeeping). For our implementation, populate `extra` only if/when a
  consuming client path exists; today it is inert and safe to omit (`skip_serializing_if`
  drops it when empty).
- **`modified_at` / `strategy_timestamp`** — the change-detection handshake. The client sends
  its stored `strategy_timestamp` as `modified_at`; the server should return its current
  strategy version as `modified_at`, and the client persists it (`sync.rs:242`,
  `sync.rs:256`-`262`). Bumping this server-side is how a client is told "your strategy
  changed, re-read it". The `strategy` object itself can be returned every heartbeat or only
  when the timestamp advances.

### Minimal server heartbeat response shape

```jsonc
{
  "modified_at": 1718700000,        // strategy version/timestamp; client stores it
  "strategy": {
    "config_options": {
      "approve-mode": "password",
      "verification-method": "use-permanent-password",
      "enable-file-transfer": "N",
      "enable-audio": "N",
      "whitelist": "10.0.0.0/8,192.168.1.5",
      "custom-rendezvous-server": "hbbs.example.com",
      "relay-server": "hbbr.example.com",
      "api-server": "https://api.example.com",
      "key": "<server-public-key>"
    }
    // "extra": { ... }   // optional, currently ignored by client
  },
  "disconnect": [/* conn ids */]    // optional
}
```

> Current state in *this* repo: `http/controller/api/index.go:41` (`Heartbeat`) only records
> online status and returns `{}`. It returns **no** `strategy`/`modified_at`, so Strategy is
> presently unimplemented here — this catalog is the spec for adding it.

---

## 5. Read-only / built-in vs user-overridable (what a Strategy may push)

| Layer | Keys | Strategy-pushable? |
|-------|------|--------------------|
| `OVERWRITE_SETTINGS` (custom-client `--overwrite-settings`) | any `KEYS_SETTINGS` key the build locked | **No** — `purify_options` drops them (`config.rs:1232`, `2766`) |
| `KEYS_BUILDIN_SETTINGS` (`config.rs:3189`) | `hide-*`, `disable-change-*`, `default-connect-password`, presets, etc. | **No** — set at client-build time, read via the hard/preset layer |
| `DEFAULT_SETTINGS` (custom-client `--default-settings`) | soft defaults | Indirectly — a pushed value overrides them; pushing `""` reverts to them |
| `CONFIG2.options` (user layer) | all `KEYS_SETTINGS` (and any key) | **Yes** — this is exactly what `handle_config_options` writes |
| `KEYS_DISPLAY_SETTINGS` / `KEYS_LOCAL_SETTINGS` | viewer/UI prefs | Technically pushable (no allow-list), but they describe the controlling viewer, not the device — avoid unless intentional |

Practical rule for the editor: a Strategy should expose the **`KEYS_SETTINGS`** group
(`keys.rs:276`-`340`). Pushing a key that the target client has locked via `OVERWRITE_SETTINGS`
is silently ignored on the client, and so is every key in the 1.5.3+ deny-list (§1b) — which is
why the catalog flags those instead of hiding them (older clients still honour them).

Two more facts worth knowing when reading a saved strategy back:

- **A pushed option is sticky.** `handle_config_options` merges the pushed map into the client's
  current options and stores the whole map, so *removing* a key from a Strategy does not revert a
  device. Pushing `""` deletes the key (back to the client default), pushing `"N"` pins it off.
- **Keys the client no longer defines** (`allow-linux-headless`) stay in stored strategies. The
  editor shows them read-only under "Removed upstream", saving keeps the value verbatim, and the
  heartbeat/install script skip them until an admin ticks them for removal.

---

## 6. How the server should expose these in a Strategy editor

1. **Model a Strategy as a desired-state map of `KEYS_SETTINGS`.** Store per strategy:
   `config_options map[string]string`, plus a monotonically increasing `modified_at`
   (unix-seconds or a version counter). Assign strategies to devices and/or device-groups.

2. **Tri-state every bool key**, because the client distinguishes *set-ON* / *set-OFF* /
   *unset*:
   - "On" → send `"Y"`.
   - "Off" → send `"N"`.
   - "Inherit / default" → either omit the key, or send `""` (which **deletes** it on the
     client, reverting to the custom-client/built-in default). Render the built-in default
     from the §2 tables (driven by `option2bool`: `enable-*` = ON, `allow-*`/`direct-server` =
     OFF) so admins see what "inherit" resolves to.

3. **Group the editor exactly as §2:** Permissions (the 12 `enable-*`), Security/Access,
   Connection/Network, Audio/Recording, Display/Performance (host-capture subset only),
   Privacy, Other/Updates. Hide viewer-only display/local keys by default.

4. **Use enums, not free text, for:** `access-mode` (`""`/`full`/`view`), `approve-mode`
   (`password`/`click`/`both`), `verification-method`
   (`use-temporary-password`/`use-permanent-password`/`use-both-passwords`),
   `temporary-password-length` (`6`/`8`/`10`). Validate against
   `libs/hbb_common/src/password_security.rs` so a typo doesn't silently fall through to the
   default branch.

5. **Validate string keys:** `whitelist` as comma-separated IPs/CIDRs (special value `0.0.0.0`
   = allow all, per `connection.rs:1291`-`1312`); `direct-access-port`/`auto-disconnect-timeout`
   as integers; `api-server` as a URL; `custom-rendezvous-server`/`relay-server` as host[:port].

6. **Serve via the heartbeat handler.** On `/api/heartbeat`, resolve the device's effective
   strategy (device override > group default), and when the request's `modified_at` is older
   than the strategy's, return `{ "modified_at": <ver>, "strategy": { "config_options": {…} } }`.
   You may return it every heartbeat; the timestamp handshake just lets the client skip
   re-applying unchanged config. This is the gap to close versus the current `Heartbeat`
   handler at `http/controller/api/index.go:41`.

7. **Leave `extra` empty** for now (`skip_serializing_if` omits it). Reserve it for future
   client-consumed payloads; the current client ignores it.

8. **Do not try to push `KEYS_BUILDIN_SETTINGS` / locked `OVERWRITE_SETTINGS` keys** — surface
   those (UI-hiding, `disable-change-*`, presets) in the Custom Client Generator instead; the
   client will not honor them via strategy.
