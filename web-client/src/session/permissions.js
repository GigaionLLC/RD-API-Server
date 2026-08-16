/**
 * Peer permission tracking.
 *
 * Spec: docs/spec/06-schema.md §4.7.
 *
 * The convention is inverted from what anyone would guess: the peer sends
 * `PermissionInfo{enabled:false}` ONLY for permissions it denies. A granted permission
 * produces no message at all. So every entry starts true and is switched off on receipt.
 *
 * Default them to false instead and you get a client where nothing works against a fully
 * permissive peer, with no error anywhere to explain why.
 *
 * They also change mid-session when the operator toggles them in the peer's connection
 * manager, so this is a live view, not a login-time snapshot.
 */

import { Permission } from '../protocol/enums.js';

/** @typedef {'Keyboard'|'Clipboard'|'Audio'|'File'|'Restart'|'Recording'|'BlockInput'|'PrivacyMode'} PermissionName */

const NAME_BY_VALUE = new Map(Object.entries(Permission).map(([k, v]) => [v, k]));

export class PermissionSet {
    /** @param {(name: string, enabled: boolean) => void} [onChange] */
    constructor(onChange) {
        /** @type {Map<string, boolean>} */
        this.state = new Map(Object.keys(Permission).map((name) => [name, true]));
        this.onChange = onChange;
    }

    /**
     * Applies an inbound `Misc.permission_info`.
     *
     * Note `enabled` is a proto3 bool: when the peer denies something it sends
     * `enabled:false`, which is the default value and therefore omitted from the wire
     * entirely. An absent `enabled` on a permission_info means false.
     *
     * @param {{permission?: number, enabled?: boolean}} info
     * @returns {string | null} The permission name that changed, or null.
     */
    apply(info) {
        const name = NAME_BY_VALUE.get(info.permission ?? 0);
        if (!name) return null; // a permission value we do not know about yet
        const enabled = info.enabled === true;
        if (this.state.get(name) === enabled) return null;
        this.state.set(name, enabled);
        this.onChange?.(name, enabled);
        return name;
    }

    /** @param {PermissionName} name */
    allows(name) {
        return this.state.get(name) ?? true;
    }

    /** @returns {string[]} Names the peer has explicitly denied. */
    denied() {
        return [...this.state].filter(([, v]) => !v).map(([k]) => k);
    }

    /** @returns {Record<string, boolean>} */
    snapshot() {
        return Object.fromEntries(this.state);
    }
}
