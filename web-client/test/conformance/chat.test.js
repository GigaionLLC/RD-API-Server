/**
 * Chat conformance.
 *
 * The protocol carries a bare string with no ids, ordering or receipts, so almost all the
 * behaviour worth testing is local: what gets sent, what is refused, and that a peer
 * cannot grow the transcript without bound.
 */

import { test } from 'node:test';
import assert from 'node:assert/strict';

import { ChatChannel, MAX_MESSAGE_LENGTH } from '../../src/chat.js';

function channel() {
    const sent = [];
    const seen = [];
    const c = new ChatChannel({ send: (m) => sent.push(m), onMessage: (e) => seen.push(e) });
    return { c, sent, seen };
}

test('a message is wrapped in Misc.chat_message', () => {
    const { c, sent } = channel();
    assert.equal(c.send('hello there'), true);
    assert.deepEqual(sent[0], { misc: { chat_message: { text: 'hello there' } } });
});

test('whitespace-only and empty messages are refused', () => {
    const { c, sent } = channel();
    assert.equal(c.send(''), false);
    assert.equal(c.send('   \n\t '), false);
    assert.equal(c.send(undefined), false);
    assert.equal(c.send(null), false);
    assert.equal(sent.length, 0);
});

test('outgoing text is trimmed', () => {
    const { c, sent } = channel();
    c.send('  padded  ');
    assert.equal(sent[0].misc.chat_message.text, 'padded');
});

test('an over-long message is clipped rather than dropped', () => {
    // Peers truncate long messages themselves; clipping locally keeps the frame sane and
    // means the user sees exactly what was sent.
    const { c, sent } = channel();
    assert.equal(c.send('x'.repeat(MAX_MESSAGE_LENGTH + 500)), true);
    assert.equal(sent[0].misc.chat_message.text.length, MAX_MESSAGE_LENGTH);
    assert.equal(c.history[0].text.length, MAX_MESSAGE_LENGTH, 'history shows what was actually sent');
});

test('inbound messages are recorded and counted unread', () => {
    const { c, seen } = channel();
    assert.equal(c.receive('from the peer'), true);
    assert.equal(c.stats().received, 1);
    assert.equal(c.stats().unread, 1);
    assert.equal(seen[0].from, 'peer');
    assert.equal(seen[0].text, 'from the peer');
});

test('an empty or non-string inbound message is ignored', () => {
    const { c } = channel();
    assert.equal(c.receive(''), false);
    assert.equal(c.receive(undefined), false);
    assert.equal(c.receive(42), false);
    assert.equal(c.stats().history, 0);
});

test('sending does not mark anything unread', () => {
    const { c } = channel();
    c.send('mine');
    assert.equal(c.stats().unread, 0);
    c.receive('theirs');
    assert.equal(c.stats().unread, 1);
    c.markRead();
    assert.equal(c.stats().unread, 0);
});

test('history preserves order across both directions', () => {
    const { c } = channel();
    c.receive('one');
    c.send('two');
    c.receive('three');
    assert.deepEqual(c.history.map((h) => [h.from, h.text]),
        [['peer', 'one'], ['me', 'two'], ['peer', 'three']]);
});

test('history is bounded so a peer cannot exhaust memory', () => {
    const { c } = channel();
    for (let i = 0; i < 600; i++) c.receive(`message ${i}`);
    assert.equal(c.history.length, 500);
    // The oldest are dropped, not the newest.
    assert.equal(c.history[c.history.length - 1].text, 'message 599');
    assert.ok(!c.history.some((h) => h.text === 'message 0'));
});

test('clear resets the transcript and unread count', () => {
    const { c } = channel();
    c.receive('a');
    c.send('b');
    c.clear();
    assert.equal(c.stats().history, 0);
    assert.equal(c.stats().unread, 0);
    assert.equal(c.stats().sent, 1, 'counters are cumulative, not transcript state');
});
