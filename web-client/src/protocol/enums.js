/**
 * Protocol enums, transcribed from docs/spec/06-schema.md §2.
 *
 * Several enums skip value 1 — that is the protocol's own history, not a typo here.
 * `ControlKey` F-keys are deliberately non-contiguous for the same reason.
 */

export const NatType = { UNKNOWN_NAT: 0, ASYMMETRIC: 1, SYMMETRIC: 2 };

export const ConnType = {
    DEFAULT_CONN: 0,
    FILE_TRANSFER: 1,
    PORT_FORWARD: 2,
    RDP: 3,
    VIEW_CAMERA: 4,
    TERMINAL: 5,
};

/** No value 1. `ID_NOT_EXIST` is 0, i.e. the proto3 default — see schema §1.4. */
export const PunchHoleFailure = {
    ID_NOT_EXIST: 0,
    OFFLINE: 2,
    LICENSE_MISMATCH: 3,
    LICENSE_OVERUSE: 4,
};

export const RegisterPkResult = {
    OK: 0,
    UUID_MISMATCH: 2,
    ID_EXISTS: 3,
    TOO_FREQUENT: 4,
    INVALID_ID_FORMAT: 5,
    NOT_SUPPORT: 6,
    SERVER_ERROR: 7,
    NOT_DEPLOYED: 8,
};

/** No value 1. */
export const ImageQuality = { NotSet: 0, Low: 2, Balanced: 3, Best: 4 };

/** `NotSet` means "leave unchanged", NOT false. */
export const BoolOption = { NotSet: 0, No: 1, Yes: 2 };

export const Chroma = { I420: 0, I444: 1 };

export const PreferCodec = { Auto: 0, VP9: 1, H264: 2, H265: 3, VP8: 4, AV1: 5 };

export const KeyboardMode = { Legacy: 0, Map: 1, Translate: 2, Auto: 3 };

/** No value 1. `size` is 0 for every type except `File`. */
export const FileType = { Dir: 0, DirLink: 2, DirDrive: 3, File: 4, FileLink: 5 };

export const SendFileType = { Generic: 0, Printer: 1 };

export const ClipboardFormat = {
    Text: 0,
    Rtf: 1,
    Html: 2,
    ImageRgba: 21,
    ImagePng: 22,
    ImageSvg: 23,
    Special: 31,
};

/**
 * No value 1. Signalled NEGATIVELY: the host sends `enabled:false` only for permissions
 * it denies, so a client must default every entry to true. See session/permissions.js.
 */
export const Permission = {
    Keyboard: 0,
    Clipboard: 2,
    Audio: 3,
    File: 4,
    Restart: 5,
    Recording: 6,
    BlockInput: 7,
    PrivacyMode: 8,
};

export const BlockInputState = {
    BlkStateUnknown: 0,
    BlkOnSucceeded: 2,
    BlkOnFailed: 3,
    BlkOffSucceeded: 4,
    BlkOffFailed: 5,
};

export const PrivacyModeState = {
    PrvStateUnknown: 0,
    PrvOnByOther: 2,
    PrvNotSupported: 3,
    PrvOnSucceeded: 4,
    PrvOnFailedDenied: 5,
    PrvOnFailedPlugin: 6,
    PrvOnFailed: 7,
    PrvOffSucceeded: 8,
    PrvOffByPeer: 9,
    PrvOffFailed: 10,
    PrvOffUnknown: 11,
};

/**
 * Layout-independent named keys. F1 = 9, then F10/F11/F12, then F2..F9 at 13..20 —
 * the ordering is alphabetical in the source enum, which is why it looks wrong.
 */
export const ControlKey = {
    Unknown: 0,
    Alt: 1,
    Backspace: 2,
    CapsLock: 3,
    Control: 4,
    Delete: 5,
    DownArrow: 6,
    End: 7,
    Escape: 8,
    F1: 9,
    F10: 10,
    F11: 11,
    F12: 12,
    F2: 13,
    F3: 14,
    F4: 15,
    F5: 16,
    F6: 17,
    F7: 18,
    F8: 19,
    F9: 20,
    Home: 21,
    LeftArrow: 22,
    Meta: 23,
    Option: 24,
    PageDown: 25,
    PageUp: 26,
    Return: 27,
    RightArrow: 28,
    Shift: 29,
    Space: 30,
    Tab: 31,
    UpArrow: 32,
    Numpad0: 33,
    Numpad1: 34,
    Numpad2: 35,
    Numpad3: 36,
    Numpad4: 37,
    Numpad5: 38,
    Numpad6: 39,
    Numpad7: 40,
    Numpad8: 41,
    Numpad9: 42,
    Cancel: 43,
    Clear: 44,
    Menu: 45,
    Pause: 46,
    Kana: 47,
    Hangul: 48,
    Junja: 49,
    Final: 50,
    Hanja: 51,
    Kanji: 52,
    Convert: 53,
    Select: 54,
    Print: 55,
    Execute: 56,
    Snapshot: 57,
    Insert: 58,
    Help: 59,
    Sleep: 60,
    Separator: 61,
    Scroll: 62,
    NumLock: 63,
    RWin: 64,
    Apps: 65,
    Multiply: 66,
    Add: 67,
    Subtract: 68,
    Decimal: 69,
    Divide: 70,
    Equals: 71,
    NumpadEnter: 72,
    RShift: 73,
    RControl: 74,
    RAlt: 75,
    VolumeMute: 76,
    VolumeUp: 77,
    VolumeDown: 78,
    Power: 79,
    // Synthetic actions: the host performs a system action and injects no key.
    CtrlAltDel: 100,
    LockScreen: 101,
};

/** Keys the host tracks as modifiers for state synchronisation. */
export const MODIFIER_KEYS = new Set([
    ControlKey.Alt,
    ControlKey.Control,
    ControlKey.Meta,
    ControlKey.Shift,
    ControlKey.RWin,
    ControlKey.RShift,
    ControlKey.RControl,
    ControlKey.RAlt,
]);

/* -------------------------------------------------------------------------- */
/* Mouse                                                                      */
/* -------------------------------------------------------------------------- */

/** `mask = (button << 3) | type`. Extract with `mask & 0x7` and `mask >> 3`. */
export const MouseType = {
    MOVE: 0,
    DOWN: 1,
    UP: 2,
    WHEEL: 3,
    TRACKPAD: 4,
    MOVE_RELATIVE: 5,
};

/** Pre-shift button bits. Set exactly ONE on DOWN/UP — the host matches by equality. */
export const MouseButton = {
    LEFT: 0x01,
    RIGHT: 0x02,
    MIDDLE: 0x04,
    BACK: 0x08,
    FORWARD: 0x10,
};

/**
 * @param {number} type One of MouseType.
 * @param {number} [button] One of MouseButton; omit for MOVE/WHEEL/TRACKPAD.
 * @returns {number}
 */
export function mouseMask(type, button = 0) {
    return (button << 3) | (type & 0x7);
}
