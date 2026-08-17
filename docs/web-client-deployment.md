# Browser remote desktop — deployment

The **Connect** action on a device opens a remote desktop in the browser. The page talks
the RustDesk protocol **directly to `hbbs` and `hbbr`** over WebSocket; this API server is
never in the media path and runs no proxy, so the feature adds nothing long-lived to
operate.

That does mean the browser needs to reach the WebSocket ports, and there is one hard
constraint to get right before it will work at all.

---

## 1. The constraint

`hbbs` binds **`PORT+2` (21118)** and `hbbr` binds **`PORT+2` (21119)** for WebSocket in
every stock build since server **1.1.6**. There is no flag — the bind is unconditional and
a failure aborts startup.

But **both speak plain `ws://` only.** They link no TLS crate and cannot terminate HTTPS.

A browser refuses to open a `ws://` socket from an `https://` page. So:

| Console served over | What works |
|---|---|
| `http://localhost` | Direct ports. Nothing else to configure. `localhost` is a secure context, so video decoding works too. |
| `https://…` | **A TLS terminator in front of 21118/21119 is mandatory**, and `RUSTDESK_WS_*_URL` must point at it. |

There is no third option: an HTTPS console cannot reach plain ws, and an HTTP console on a
non-localhost host cannot decode video, because WebCodecs requires a secure context.

---

## 2. Two ways to arrange it

| | **Proxied** (§3) | **Direct** (§4, §5) |
|---|---|---|
| Configure | Two upstreams | Two URLs plus a reverse-proxy vhost |
| Certificates | The console's own | A second one for the WebSocket host |
| Public ports | None beyond the console | 21118 and 21119, behind TLS |
| Media path | Through this container | Browser to hbbr, this server uninvolved |
| Best for | Most deployments | Many concurrent sessions |

```env
# Proxied: this container serves /ws/id and /ws/relay and forwards them.
RUSTDESK_WS_ID_UPSTREAM=hbbs:21118
RUSTDESK_WS_RELAY_UPSTREAM=hbbr:21119

# Direct: you terminate TLS in front of hbbs and hbbr and name the endpoints.
RUSTDESK_WS_ID_URL=wss://rustdesk.example.com/ws/id
RUSTDESK_WS_RELAY_URL=wss://rustdesk.example.com/ws/relay
```

Explicit URLs always win, so a deployment can move from one arrangement to the other by
setting or clearing them.

With neither set, the viewer derives `ws://<RUSTDESK_ID_SERVER host>:21118` and `:21119` —
which works only for a console on `http://localhost`, because a secure page cannot open a
plain `ws://` socket.

The server ignores the request path entirely, so `/ws/id` and `/ws/relay` are purely a
routing convention — use whatever paths suit the proxy.

---

## 3. Letting this container carry the WebSocket

The simplest arrangement, and the one to reach for first. Set two upstreams:

```yaml
- RUSTDESK_WS_ID_UPSTREAM=hbbs:21118
- RUSTDESK_WS_RELAY_UPSTREAM=hbbr:21119
```

The values are `host:port` as reachable *from this container* — on a Compose network that
is the service name. The runtime then serves `/ws/id` and `/ws/relay` on the console's own
hostname and certificate and forwards them to hbbs and hbbr.

Nothing else needs configuring. The viewer derives its endpoints from `APP_URL`, so there
is no second place to keep in step, and `RUSTDESK_WS_ID_URL` / `RUSTDESK_WS_RELAY_URL` are
not needed.

What this buys you:

- **One hostname and one certificate.** No second TLS terminator, no extra DNS name.
- **No reverse-proxy edit.** Your existing vhost already forwards everything for that
  domain — provided it forwards WebSocket upgrades, which is the one thing to verify.
- **No extra public ports.** 21118 and 21119 need only be reachable from this container,
  so they can be bound to a private interface, or not published at all.
- **The X-Real-IP trap is handled for you.** This runtime blanks those headers toward
  hbbs; §5 explains why that matters and why it is easy to get wrong by hand.

What it costs:

- **This container joins the media path.** Relayed session video passes through it, which
  is exactly what the direct arrangement avoids. For a handful of concurrent sessions this
  is not measurable; for many, prefer §4.
- **Two proxy hops** if your console is already behind one.

Both upstreams must be set. Setting one is refused at start-up rather than accepted,
because half a configuration produces a rendezvous endpoint with no matching relay — a
session that connects and then stops, which is harder to diagnose than one that never
starts.

The **Remote desktop** page under System in the console reports what is configured, whether
the upstreams answer from inside the container, and whether the browser can actually reach
the endpoint. That last check is the one that matters and the only one this server cannot
make on its own.

---

## 4. nginx

```nginx
map $http_upgrade $connection_upgrade {
    default upgrade;
    ''      close;
}

server {
    listen 443 ssl http2;
    server_name rustdesk.example.com;

    # ... your certificate directives ...

    location /ws/id {
        proxy_pass http://127.0.0.1:21118;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;

        # Deliberately NOT forwarding X-Real-IP / X-Forwarded-For. See §6.

        proxy_read_timeout 120s;
        proxy_send_timeout 120s;
    }

    location /ws/relay {
        proxy_pass http://127.0.0.1:21119;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection $connection_upgrade;
        proxy_set_header Host $host;

        # Relay carries the session. Long-lived and can be idle during a static screen.
        proxy_read_timeout 600s;
        proxy_send_timeout 600s;
        proxy_buffering off;
    }
}
```

## 5. Caddy

```caddyfile
rustdesk.example.com {
    handle /ws/id {
        reverse_proxy 127.0.0.1:21118 {
            header_up -X-Forwarded-For
            header_up -X-Real-IP
        }
    }
    handle /ws/relay {
        reverse_proxy 127.0.0.1:21119 {
            header_up -X-Forwarded-For
            header_up -X-Real-IP
            flush_interval -1
        }
    }
}
```

---

## 6. Do not forward client-IP headers to 21118

This one is counter-intuitive and breaks concurrent sessions in a way that looks like a
client bug.

`hbbs` reads `X-Real-IP`, falling back to `X-Forwarded-For`, and **overwrites the
connection's address with `<ip>:0`** — unvalidated. It then keys its pending-response map
on that address.

So every browser session arriving through a proxy that sets those headers collapses to the
same map key, and concurrent viewers **steal each other's `PunchHoleResponse` and
`RelayResponse`**. Two operators behind one public IP is enough to trigger it.

Omitting the headers lets `hbbs` see the proxy's own connection, which has a unique
ephemeral port per session, and the collisions disappear. The cost is that `hbbs` logs the
proxy's address rather than the operator's.

---

## 7. Never TCP-proxy 21115 or 21117 from localhost

`hbbs` and `hbbr` treat a **non-WebSocket loopback TCP connection as an unauthenticated
admin console** — `blacklist-add`, `limit-speed`, `total-bandwidth` and friends. The guard
is "not WebSocket", so terminating `wss` in front of 21118/21119 is safe.

But a plain TCP proxy on the same host in front of 21117 or 21115 makes every remote
caller appear to arrive from `127.0.0.1`, handing them that console.

---

## 8. Installing the viewer assets

There is no build step — the viewer ships as ES modules.

```bash
node web-client/scripts/install-assets.mjs
```

This copies `web-client/src` and `web-client/vendor` into `public/assets/webclient/`,
which is generated and gitignored. Re-run it after changing anything under `web-client/`.
`--check` verifies the published copy matches the source, for CI.

The device page says plainly when the assets are missing, rather than rendering a blank
canvas.

---

## 9. Requirements and limits

| | |
|---|---|
| Browser | **Chrome / Chromium, desktop.** Firefox and Safari are not supported. |
| Context | **HTTPS, or `localhost`.** WebCodecs is unavailable otherwise. |
| Server | rustdesk-server **≥ 1.1.6**; **≥ 1.1.15** recommended. |
| Kubernetes | The upstream example manifest omits 21118/21119 from the Service — add them. |

**Every browser session is relayed.** A browser cannot hole-punch, so there is no direct
P2P path and all traffic crosses `hbbr`. Budget relay bandwidth accordingly before
offering this widely.

Also note that stock `hbbr`'s relay key defaults to empty, meaning it will relay for anyone
presenting a matching UUID within 30 seconds. Confidentiality comes from the end-to-end
NaCl handshake inside the pipe, not from `hbbr`. Setting a relay key is worthwhile
defence in depth.

---

## 10. Troubleshooting

| Symptom | Cause |
|---|---|
| "Viewer assets are not installed" | Run `install-assets.mjs`. |
| Mixed-content error in the console | Console is HTTPS but `RUSTDESK_WS_*_URL` is empty, so the viewer tried `ws://`. |
| "WebCodecs unavailable" | Not a secure context. Use HTTPS or `localhost`. |
| Connects, then "ID does not exist" | Peer is offline, or the server key does not match the server's `id_ed25519.pub`. |
| "The server key configured here does not match the ID server" | `RUSTDESK_PUBLIC_KEY` is wrong — most often it holds `id_ed25519` (64 bytes, the private key) instead of `id_ed25519.pub` (32 bytes). The diagnostics page names this and prints the correct value. |
| "Wrong Password" | The peer's own connection password, which this server does not hold. |
| Two operators disconnect each other | Client-IP headers are being forwarded to 21118. See §6. |
| Black screen, no error | Almost always the relay endpoint: `/ws/relay` must reach **21119**, not 21117. |
