/**
 * Session chat.
 *
 * Spec: docs/spec/06-schema.md §4.7 — `Misc.chat_message`, a single UTF-8 string in each
 * direction.
 *
 * The protocol offers nothing else: no ids, no ordering, no delivery receipts, no typing
 * indicator, no history. So local ordering is arrival order, and a message that is lost
 * is simply lost — there is no retransmit to implement and no ack to wait for.
 *
 * On the peer this surfaces in its connection-manager window, and an unanswered inbound
 * message suppresses that side's idle auto-disconnect. That makes chat genuinely useful
 * for asking someone at the machine to grant access, which is its main purpose here.
 */

/** Guards against a peer flooding the transcript into unbounded memory. */
const MAX_HISTORY = 500;

/** Peers truncate long messages; keep well inside that and avoid oversized frames. */
export const MAX_MESSAGE_LENGTH = 4096;

export class ChatChannel {
    /**
     * @param {object} opts
     * @param {(msg: object) => void} opts.send Sends a `Message`.
     * @param {(entry: {from: 'peer'|'me', text: string, at: number}) => void} [opts.onMessage]
     */
    constructor({ send, onMessage }) {
        this.sendMessage = send;
        this.onMessage = onMessage;
        /** @type {Array<{from: 'peer'|'me', text: string, at: number}>} */
        this.history = [];
        this.unread = 0;
        this.sent = 0;
        this.received = 0;
    }

    /**
     * @param {'peer'|'me'} from
     * @param {string} text
     */
    _record(from, text) {
        const entry = { from, text, at: Date.now() };
        this.history.push(entry);
        if (this.history.length > MAX_HISTORY) this.history.splice(0, this.history.length - MAX_HISTORY);
        this.onMessage?.(entry);
        return entry;
    }

    /**
     * Sends a message to the peer.
     * @param {string} text
     * @returns {boolean} False when there was nothing to send.
     */
    send(text) {
        const trimmed = String(text ?? '').trim();
        if (!trimmed) return false;
        const clipped = trimmed.length > MAX_MESSAGE_LENGTH ? trimmed.slice(0, MAX_MESSAGE_LENGTH) : trimmed;
        this.sendMessage({ misc: { chat_message: { text: clipped } } });
        this.sent++;
        this._record('me', clipped);
        return true;
    }

    /**
     * Handles an inbound message.
     * @param {string} text
     */
    receive(text) {
        // An empty inbound message is not worth showing, but is not an error either.
        if (typeof text !== 'string' || text === '') return false;
        this.received++;
        this.unread++;
        this._record('peer', text);
        return true;
    }

    markRead() {
        this.unread = 0;
    }

    clear() {
        this.history.length = 0;
        this.unread = 0;
    }

    stats() {
        return {
            sent: this.sent,
            received: this.received,
            unread: this.unread,
            history: this.history.length,
        };
    }
}
