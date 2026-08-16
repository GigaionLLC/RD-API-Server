/**
 * Endpoint derivation conformance.
 *
 * Every one of these failures looks identical from the browser — "cannot connect" — and
 * none of them is visible in the code that produced the wrong URL. That makes the
 * arithmetic worth pinning: a deployment on non-standard ports has no way to tell whether
 * its server is down or its client is dialling the wrong number.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { endpoints, splitHostPort } from '../../src/transport/ws.js';

/* -------------------------------------------------------------------------- */
/* host:port parsing                                                          */
/* -------------------------------------------------------------------------- */

test('a bare host has no port', () => {
    assert.deepEqual(splitHostPort('example.com'), { host: 'example.com', port: null });
    assert.deepEqual(splitHostPort(''), { host: '', port: null });
    assert.deepEqual(splitHostPort('  example.com  '), { host: 'example.com', port: null });
});

test('a host:port pair splits', () => {
    assert.deepEqual(splitHostPort('example.com:31116'), { host: 'example.com', port: 31116 });
    assert.deepEqual(splitHostPort('10.0.0.5:21117'), { host: '10.0.0.5', port: 21117 });
});

test('IPv6 literals survive', () => {
    // Splitting on the first colon turns "[::1]:21117" into the host "[" — which then
    // fails to connect with an error that says nothing about why.
    assert.deepEqual(splitHostPort('[::1]:21117'), { host: '[::1]', port: 21117 });
    assert.deepEqual(splitHostPort('[2001:db8::1]'), { host: '[2001:db8::1]', port: null });
    // A bare literal has no port and must be bracketed before it can go in a URL.
    assert.deepEqual(splitHostPort('2001:db8::1'), { host: '[2001:db8::1]', port: null });
});

test('a nonsense port is ignored rather than propagated', () => {
    assert.deepEqual(splitHostPort('example.com:'), { host: 'example.com', port: null });
    assert.deepEqual(splitHostPort('example.com:abc'), { host: 'example.com', port: null });
});

/* -------------------------------------------------------------------------- */
/* WebSocket URL derivation                                                   */
/* -------------------------------------------------------------------------- */

test('the standard deployment derives 21118 and 21119', () => {
    // hbbs and hbbr each bind their TCP port plus two for WebSocket.
    assert.deepEqual(endpoints({ host: 'example.com' }), {
        rendezvous: 'ws://example.com:21118',
        relay: 'ws://example.com:21119',
    });
});

test('a port in the host string is honoured, with hbbr one above hbbs', () => {
    // How every other RustDesk component is configured, so a client that ignored it sent
    // custom-port deployments to a closed socket on 21118.
    assert.deepEqual(endpoints({ host: 'example.com:31116' }), {
        rendezvous: 'ws://example.com:31118',
        relay: 'ws://example.com:31119',
    });
});

test('explicit ports override the host string', () => {
    assert.deepEqual(endpoints({ host: 'example.com:31116', rendezvousPort: 21116, relayPort: 21117 }), {
        rendezvous: 'ws://example.com:21118',
        relay: 'ws://example.com:21119',
    });
});

test('a separate relay host does not inherit the id server’s port', () => {
    // hbbr on its own machine runs on its own default; deriving 31117 from the id server's
    // 31116 would be an invention.
    assert.deepEqual(endpoints({ host: 'id.example.com:31116', relayHost: 'relay.example.com' }), {
        rendezvous: 'ws://id.example.com:31118',
        relay: 'ws://relay.example.com:21119',
    });
});

test('a separate relay host keeps its own port', () => {
    assert.deepEqual(endpoints({ host: 'id.example.com', relayHost: 'relay.example.com:31117' }), {
        rendezvous: 'ws://id.example.com:21118',
        relay: 'ws://relay.example.com:31119',
    });
});

test('secure deployments use wss', () => {
    // Mandatory in production: the servers speak plain ws, so a TLS terminator sits in
    // front, and an https page cannot open a ws:// socket at all.
    const u = endpoints({ host: 'example.com', secure: true });
    assert.match(u.rendezvous, /^wss:\/\//);
    assert.match(u.relay, /^wss:\/\//);
});

test('path routing ignores ports entirely', () => {
    assert.deepEqual(endpoints({ host: 'example.com:31116', secure: true, pathRouted: true }), {
        rendezvous: 'wss://example.com/ws/id',
        relay: 'wss://example.com/ws/relay',
    });
});

test('explicit URLs win over everything', () => {
    // Behind a reverse proxy the ports are usually not exposed at all, and the wss
    // endpoints may live on a different hostname than the server itself.
    assert.deepEqual(endpoints({
        host: 'example.com',
        rendezvousUrl: 'wss://edge.example.com/ws/id',
        relayUrl: 'wss://edge.example.com/ws/relay',
    }), {
        rendezvous: 'wss://edge.example.com/ws/id',
        relay: 'wss://edge.example.com/ws/relay',
    });
});

test('one explicit URL alone is not enough to skip derivation', () => {
    // Half a configuration would otherwise produce a rendezvous URL and no relay.
    const u = endpoints({ host: 'example.com', relayUrl: 'wss://edge.example.com/ws/relay' });
    assert.equal(u.rendezvous, 'ws://example.com:21118');
});

test('an IPv6 id server produces a usable URL', () => {
    assert.deepEqual(endpoints({ host: '[fd00::1]:21116' }), {
        rendezvous: 'ws://[fd00::1]:21118',
        relay: 'ws://[fd00::1]:21119',
    });
});
