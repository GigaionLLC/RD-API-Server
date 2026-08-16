/**
 * Turns protocol events the operator cannot otherwise see into text.
 *
 * Three things arrive mid-session that have no representation in the video stream, and
 * without them a session simply appears to stop working:
 *
 *  - `MessageBox`, which the peer uses for everything from "waiting for the user to
 *    accept" to "that account is locked out". Dropping it leaves a viewer that sits on a
 *    black canvas with no explanation.
 *  - Permission denials, sent only as negatives (see permissions.js). A peer with
 *    keyboard control switched off looks identical to a broken input pipeline.
 *  - Elevation state on Windows. A UAC prompt runs on the secure desktop, where injected
 *    input is discarded by the OS: the screen shows a dialog that does not respond, which
 *    reads as a frozen session rather than a permissions boundary.
 *
 * Pure functions, no DOM — the viewer renders what these return, and the mapping is
 * testable without a browser.
 */

/* -------------------------------------------------------------------------- */
/* MessageBox                                                                 */
/* -------------------------------------------------------------------------- */

/**
 * `msgtype` is a hyphenated token list rather than an enum: severity plus dialog hints
 * such as which buttons to draw. We read the severity and ignore the button hints — a
 * notice here is a banner, not a modal, so it is always dismissible.
 *
 * @param {{msgtype?: string, title?: string, text?: string, link?: string}} box
 * @returns {{severity: 'info'|'warn'|'error', title: string, detail: string,
 *            link: string, prompt: 'password'|'2fa'|null, key: string}}
 */
export function describeMessageBox(box = {}) {
    const tokens = new Set(String(box.msgtype ?? '').toLowerCase().split('-').filter(Boolean));

    let severity = 'info';
    if (tokens.has('error')) severity = 'error';
    else if (tokens.has('warn') || tokens.has('warning')) severity = 'warn';

    // A peer asking for the password again means the one we sent was rejected or expired;
    // the operator needs the password field, not a message to read.
    const prompt = tokens.has('password') ? 'password' : (tokens.has('2fa') ? '2fa' : null);

    const title = (box.title ?? '').trim() || defaultTitle(severity, prompt);
    const detail = (box.text ?? '').trim();

    return {
        severity,
        title,
        detail,
        // Never rendered as an href. This string comes off the wire from a machine the
        // operator is, by definition, not yet sure about; a clickable link in a trusted
        // chrome position is a phishing surface for one hyperlink of effort.
        link: (box.link ?? '').trim(),
        prompt,
        // Peers repeat the same box while they wait for a decision. Keying on the content
        // lets the UI refresh one banner instead of stacking twenty identical ones.
        key: `box:${box.msgtype ?? ''}:${box.title ?? ''}:${box.text ?? ''}`,
    };
}

/** @param {string} severity @param {string | null} prompt */
function defaultTitle(severity, prompt) {
    if (prompt === 'password') return 'Password required';
    if (prompt === '2fa') return 'Two-factor code required';
    return severity === 'error' ? 'Remote machine reported an error' : 'Message from the remote machine';
}

/* -------------------------------------------------------------------------- */
/* Permissions                                                                */
/* -------------------------------------------------------------------------- */

/**
 * What a denial actually costs the operator, in their words rather than the protocol's.
 * Keyed by the names in protocol/enums.js `Permission`.
 */
const PERMISSION_TEXT = {
    Keyboard: ['Input disabled', 'The remote machine is not accepting keyboard or mouse input. This session is view-only until it is re-enabled there.'],
    Clipboard: ['Clipboard disabled', 'Copy and paste between this browser and the remote machine is switched off at the remote end.'],
    Audio: ['Audio disabled', 'The remote machine is not sending sound.'],
    File: ['File transfer disabled', 'The remote machine is refusing file transfers.'],
    Restart: ['Restart disabled', 'This session cannot restart the remote machine.'],
    Recording: ['Recording disabled', 'The remote machine has refused session recording.'],
    BlockInput: ['Input blocking disabled', 'This session cannot block input at the remote machine.'],
    PrivacyMode: ['Privacy mode disabled', 'The remote machine has refused to blank its own screen.'],
};

/**
 * @param {string[]} denied Names from `PermissionSet.denied()`.
 * @returns {Array<{key: string, severity: 'warn', title: string, detail: string}>}
 */
export function describeDenials(denied = []) {
    return denied.map((name) => {
        const [title, detail] = PERMISSION_TEXT[name]
            ?? [`${name} disabled`, `The remote machine has denied ${name}.`];
        return { key: `perm:${name}`, severity: /** @type {'warn'} */ ('warn'), title, detail };
    });
}

/* -------------------------------------------------------------------------- */
/* Elevation (Windows)                                                        */
/* -------------------------------------------------------------------------- */

/**
 * Two distinct Windows states, both of which silently swallow input:
 *
 *  - `uac`: a UAC consent dialog is up. It runs on the secure desktop, which by design
 *    receives no injected input at all — only someone physically at the machine can
 *    answer it, unless the peer's own service elevates the session.
 *  - `elevated`: the foreground window runs as administrator. Windows drops input from a
 *    lower-integrity process, so clicks land nowhere.
 *
 * The distinction matters because the remedy differs: the first is answerable by
 * elevating, the second needs the session itself to be elevated before that window is
 * usable at all.
 *
 * @param {{uac?: boolean, elevated?: boolean, portable?: boolean}} state
 * @returns {{key: string, severity: 'warn', title: string, detail: string,
 *            action: 'elevate'} | null}
 */
export function describeElevation({ uac = false, elevated = false, portable = false } = {}) {
    if (uac) {
        return {
            key: 'elev:uac',
            severity: 'warn',
            title: 'Waiting on a UAC prompt',
            detail: portable
                ? 'A Windows consent dialog is open on the remote machine. It runs on the secure desktop, so your input cannot reach it — someone at the machine has to answer it.'
                : 'A Windows consent dialog is open on the remote machine. It runs on the secure desktop, so your input cannot reach it. Elevate this session, or ask someone at the machine to answer it.',
            action: 'elevate',
        };
    }
    if (elevated) {
        return {
            key: 'elev:foreground',
            severity: 'warn',
            title: 'Foreground window runs as administrator',
            detail: 'Windows ignores input sent to an elevated window from an unelevated session, so clicks and keys land nowhere. Elevate this session to interact with it.',
            action: 'elevate',
        };
    }
    return null;
}

/**
 * The peer answers an elevation request with a string: empty means it succeeded.
 *
 * @param {string} response
 * @returns {{key: string, severity: 'info'|'error', title: string, detail: string}}
 */
export function describeElevationResponse(response) {
    const text = (response ?? '').trim();
    if (!text) {
        return {
            key: 'elev:response',
            severity: 'info',
            title: 'Session elevated',
            detail: 'The remote machine granted administrator rights to this session.',
        };
    }
    return {
        key: 'elev:response',
        severity: 'error',
        title: 'Elevation refused',
        // The peer's own wording. It distinguishes a declined consent prompt from bad
        // credentials from a machine with no elevation path at all, and paraphrasing
        // would collapse three different next steps into one.
        detail: text,
    };
}
