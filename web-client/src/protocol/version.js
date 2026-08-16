/**
 * Peer version comparison.
 *
 * Spec: docs/spec/06-schema.md §5.
 *
 * Almost every optional capability is gated on `PeerInfo.version`, and getting the
 * comparison wrong is worse than not gating at all: sending a message an older peer does
 * not understand is silently ignored, so the feature simply appears broken with no error
 * anywhere.
 *
 * Versions are compared numerically as `major*1e6 + minor*1e3 + patch`, which is how the
 * protocol's own gates are expressed. Suffixes like `-1` or `-beta` are ignored.
 */

/**
 * @param {string} version e.g. "1.4.9"
 * @returns {number} A comparable integer; 0 when unparseable.
 */
export function versionNumber(version) {
    if (!version) return 0;
    const m = /^(\d+)\.(\d+)\.(\d+)/.exec(String(version).trim());
    if (!m) return 0;
    return Number(m[1]) * 1_000_000 + Number(m[2]) * 1_000 + Number(m[3]);
}

/**
 * @param {string} version
 * @param {string} minimum
 * @returns {boolean}
 */
export function atLeast(version, minimum) {
    return versionNumber(version) >= versionNumber(minimum);
}

/** Minimum peer versions for capabilities this client can use. */
export const MIN_VERSION = {
    multiClipboard: '1.3.0',
    multiClipboardAndroid: '1.3.3',
    readEmptyDirs: '1.3.3',
    overwriteDetection: '1.1.10',
    refreshVideoDisplay: '1.2.4',
    changeDisplayResolution: '1.2.4',
    selectedSid: '1.2.4',
    togglePrivacyMode: '1.2.4',
    viewCamera: '1.3.9',
    screenshot: '1.4.0',
    terminal: '1.4.1',
    fileTransferResume: '1.4.2',
};

/**
 * Whether the peer accepts `MultiClipboards`, which carries several formats at once.
 *
 * Older peers understand only a single `Clipboard`, and iOS never advertises support at
 * all. Android needed an extra patch release beyond the general gate.
 *
 * @param {{version?: string, platform?: string}} peerInfo
 */
export function supportsMultiClipboard(peerInfo) {
    const platform = peerInfo?.platform ?? '';
    if (platform === '' || platform === 'iOS') return false;
    const minimum = platform === 'Android' ? MIN_VERSION.multiClipboardAndroid : MIN_VERSION.multiClipboard;
    return atLeast(peerInfo?.version ?? '', minimum);
}

/**
 * Whether `Misc.refresh_video_display` may be used. Below this the only option is
 * `refresh_video`, which restarts capture for EVERY display rather than one.
 * @param {{version?: string}} peerInfo
 */
export function supportsPerDisplayRefresh(peerInfo) {
    return atLeast(peerInfo?.version ?? '', MIN_VERSION.refreshVideoDisplay);
}
