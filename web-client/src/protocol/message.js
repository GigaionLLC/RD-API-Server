/**
 * `message.proto` descriptors — the session protocol carried inside the relay.
 *
 * Transcribed from docs/spec/06-schema.md §4. Tag numbers are the wire contract.
 * Leaf messages are declared before the composites that reference them.
 */

import { REPEATED } from './codec.js';

/* -------------------------------------------------------------------------- */
/* Handshake and login                                                        */
/* -------------------------------------------------------------------------- */

export const SignedId = { name: 'SignedId', fields: { 1: ['id', 'bytes'] } };

export const PublicKey = {
    name: 'PublicKey',
    fields: {
        1: ['asymmetric_value', 'bytes'], // our 32-byte X25519 public key
        2: ['symmetric_value', 'bytes'], // 48-byte sealed session key
    },
};

export const IdPk = { name: 'IdPk', fields: { 1: ['id', 'string'], 2: ['pk', 'bytes'] } };

export const Hash = {
    name: 'Hash',
    fields: { 1: ['salt', 'string'], 2: ['challenge', 'string'] },
};

export const Auth2FA = {
    name: 'Auth2FA',
    fields: { 1: ['code', 'string'], 2: ['hwid', 'bytes'] },
};

export const OSLogin = {
    name: 'OSLogin',
    fields: { 1: ['username', 'string'], 2: ['password', 'string'] },
};

export const FileTransfer = {
    name: 'FileTransfer',
    fields: { 1: ['dir', 'string'], 2: ['show_hidden', 'bool'] },
};

export const PortForward = {
    name: 'PortForward',
    fields: { 1: ['host', 'string'], 2: ['port', 'int32'] },
};

export const ViewCamera = { name: 'ViewCamera', fields: {} };

export const Terminal = { name: 'Terminal', fields: { 1: ['service_id', 'string'] } };

/* -------------------------------------------------------------------------- */
/* Capabilities                                                               */
/* -------------------------------------------------------------------------- */

export const CodecAbility = {
    name: 'CodecAbility',
    fields: {
        1: ['vp8', 'bool'],
        2: ['vp9', 'bool'],
        3: ['av1', 'bool'],
        4: ['h264', 'bool'],
        5: ['h265', 'bool'],
    },
};

/** `ability_*` are int32 used as booleans: 0 = cannot decode, non-zero = can. */
export const SupportedDecoding = {
    name: 'SupportedDecoding',
    fields: {
        1: ['ability_vp9', 'int32'],
        2: ['ability_h264', 'int32'],
        3: ['ability_h265', 'int32'],
        4: ['prefer', 'enum'],
        5: ['ability_vp8', 'int32'],
        6: ['ability_av1', 'int32'],
        7: ['i444', CodecAbility],
        8: ['prefer_chroma', 'enum'],
    },
};

/** VP9 is absent because it is always available — the implicit baseline. */
export const SupportedEncoding = {
    name: 'SupportedEncoding',
    fields: {
        1: ['h264', 'bool'],
        2: ['h265', 'bool'],
        3: ['vp8', 'bool'],
        4: ['av1', 'bool'],
        5: ['i444', CodecAbility],
    },
};

export const Resolution = {
    name: 'Resolution',
    fields: { 1: ['width', 'int32'], 2: ['height', 'int32'] },
};

export const SupportedResolutions = {
    name: 'SupportedResolutions',
    fields: { 1: ['resolutions', Resolution, REPEATED] },
};

export const DisplayInfo = {
    name: 'DisplayInfo',
    fields: {
        1: ['x', 'sint32'],
        2: ['y', 'sint32'],
        3: ['width', 'int32'],
        4: ['height', 'int32'],
        5: ['name', 'string'],
        6: ['online', 'bool'],
        7: ['cursor_embedded', 'bool'],
        8: ['original_resolution', Resolution],
        9: ['scale', 'double'], // the protocol's only double
    },
};

export const Features = {
    name: 'Features',
    fields: { 1: ['privacy_mode', 'bool'], 2: ['terminal', 'bool'] },
};

export const WindowsSession = {
    name: 'WindowsSession',
    fields: { 1: ['sid', 'uint32'], 2: ['name', 'string'] },
};

export const WindowsSessions = {
    name: 'WindowsSessions',
    fields: {
        1: ['sessions', WindowsSession, REPEATED],
        2: ['current_sid', 'uint32'],
    },
};

export const PeerInfo = {
    name: 'PeerInfo',
    fields: {
        1: ['username', 'string'],
        2: ['hostname', 'string'],
        3: ['platform', 'string'],
        4: ['displays', DisplayInfo, REPEATED],
        5: ['current_display', 'int32'],
        6: ['sas_enabled', 'bool'],
        7: ['version', 'string'], // gates nearly every optional feature
        9: ['features', Features], // tag 8 unused
        10: ['encoding', SupportedEncoding],
        11: ['resolutions', SupportedResolutions],
        12: ['platform_additions', 'string'], // flat JSON
        13: ['windows_sessions', WindowsSessions],
    },
};

/**
 * Tags 13 and 14 are retired and must never be reused.
 * `custom_image_quality` is `percent << 8`; never send it alongside `image_quality`.
 */
export const OptionMessage = {
    name: 'OptionMessage',
    fields: {
        1: ['image_quality', 'enum'],
        2: ['lock_after_session_end', 'enum'],
        3: ['show_remote_cursor', 'enum'],
        4: ['privacy_mode', 'enum'],
        5: ['block_input', 'enum'],
        6: ['custom_image_quality', 'int32'],
        7: ['disable_audio', 'enum'],
        8: ['disable_clipboard', 'enum'],
        9: ['enable_file_transfer', 'enum'], // gates cliprdr, not file transfer
        10: ['supported_decoding', SupportedDecoding],
        11: ['custom_fps', 'int32'], // ignored outside [1,120]
        12: ['disable_keyboard', 'enum'],
        15: ['follow_remote_cursor', 'enum'],
        16: ['follow_remote_window', 'enum'],
        17: ['disable_camera', 'enum'],
        18: ['terminal_persistent', 'enum'],
        19: ['show_my_cursor', 'enum'],
    },
};

export const LoginRequest = {
    name: 'LoginRequest',
    fields: {
        1: ['username', 'string'], // the PEER's id, not a user name
        2: ['password', 'bytes'], // h2, or empty
        4: ['my_id', 'string'],
        5: ['my_name', 'string'],
        6: ['option', OptionMessage],
        7: ['file_transfer', FileTransfer],
        8: ['port_forward', PortForward],
        9: ['video_ack_required', 'bool'],
        10: ['session_id', 'uint64'],
        11: ['version', 'string'],
        12: ['os_login', OSLogin],
        13: ['my_platform', 'string'],
        14: ['hwid', 'bytes'],
        15: ['view_camera', ViewCamera],
        16: ['terminal', Terminal],
        17: ['avatar', 'string'],
    },
    oneofs: { union: ['file_transfer', 'port_forward', 'view_camera', 'terminal'] },
};

export const LoginResponse = {
    name: 'LoginResponse',
    fields: {
        1: ['error', 'string'],
        2: ['peer_info', PeerInfo],
        3: ['enable_trusted_devices', 'bool'],
    },
    oneofs: { union: ['error', 'peer_info'] },
};

/** Echo verbatim when `from_client` is false — late replies clamp the host to 2 fps. */
export const TestDelay = {
    name: 'TestDelay',
    fields: {
        1: ['time', 'int64'],
        2: ['from_client', 'bool'],
        3: ['last_delay', 'uint32'],
        4: ['target_bitrate', 'uint32'],
    },
};

/* -------------------------------------------------------------------------- */
/* Video and audio                                                            */
/* -------------------------------------------------------------------------- */

export const EncodedVideoFrame = {
    name: 'EncodedVideoFrame',
    fields: { 1: ['data', 'bytes'], 2: ['key', 'bool'], 3: ['pts', 'int64'] },
};

export const EncodedVideoFrames = {
    name: 'EncodedVideoFrames',
    fields: { 1: ['frames', EncodedVideoFrame, REPEATED] },
};

export const RGB = { name: 'RGB', fields: { 1: ['compress', 'bool'] } };
export const YUV = {
    name: 'YUV',
    fields: { 1: ['compress', 'bool'], 2: ['stride', 'int32'] },
};

/** The oneof tag IS the codec identifier — there is no separate codec field. */
export const VideoFrame = {
    name: 'VideoFrame',
    fields: {
        6: ['vp9s', EncodedVideoFrames],
        7: ['rgb', RGB],
        8: ['yuv', YUV],
        10: ['h264s', EncodedVideoFrames],
        11: ['h265s', EncodedVideoFrames],
        12: ['vp8s', EncodedVideoFrames],
        13: ['av1s', EncodedVideoFrames],
        14: ['display', 'int32'],
    },
    oneofs: { union: ['vp9s', 'rgb', 'yuv', 'h264s', 'h265s', 'vp8s', 'av1s'] },
};

/** Maps a VideoFrame oneof member to the WebCodecs codec family. */
export const CODEC_BY_FIELD = {
    vp8s: 'vp8',
    vp9s: 'vp9',
    av1s: 'av1',
    h264s: 'h264',
    h265s: 'h265',
};

export const AudioFormat = {
    name: 'AudioFormat',
    fields: { 1: ['sample_rate', 'uint32'], 2: ['channels', 'uint32'] },
};

export const AudioFrame = { name: 'AudioFrame', fields: { 1: ['data', 'bytes'] } };

/* -------------------------------------------------------------------------- */
/* Input and cursor                                                           */
/* -------------------------------------------------------------------------- */

export const MouseEvent = {
    name: 'MouseEvent',
    fields: {
        1: ['mask', 'int32'],
        2: ['x', 'sint32'],
        3: ['y', 'sint32'],
        4: ['modifiers', 'enum', REPEATED],
    },
};

export const KeyEvent = {
    name: 'KeyEvent',
    fields: {
        1: ['down', 'bool'],
        2: ['press', 'bool'],
        3: ['control_key', 'enum'],
        4: ['chr', 'uint32'],
        5: ['unicode', 'uint32'],
        6: ['seq', 'string'],
        7: ['win2win_hotkey', 'uint32'],
        8: ['modifiers', 'enum', REPEATED],
        9: ['mode', 'enum'],
    },
    oneofs: { union: ['control_key', 'chr', 'unicode', 'seq', 'win2win_hotkey'] },
};

export const CursorData = {
    name: 'CursorData',
    fields: {
        1: ['id', 'uint64'], // opaque handle; cache key, never truncate to Number
        2: ['hotx', 'sint32'],
        3: ['hoty', 'sint32'],
        4: ['width', 'int32'],
        5: ['height', 'int32'],
        6: ['colors', 'bytes'], // zstd-compressed RGBA8888, top-down
    },
};

export const CursorPosition = {
    name: 'CursorPosition',
    fields: { 1: ['x', 'sint32'], 2: ['y', 'sint32'] },
};

/* -------------------------------------------------------------------------- */
/* Misc                                                                       */
/* -------------------------------------------------------------------------- */

export const SwitchDisplay = {
    name: 'SwitchDisplay',
    fields: {
        1: ['display', 'int32'],
        2: ['x', 'sint32'],
        3: ['y', 'sint32'],
        4: ['width', 'int32'],
        5: ['height', 'int32'],
        6: ['cursor_embedded', 'bool'],
        7: ['resolutions', SupportedResolutions],
        8: ['original_resolution', Resolution],
    },
};

export const CaptureDisplays = {
    name: 'CaptureDisplays',
    fields: {
        1: ['add', 'int32', REPEATED],
        2: ['sub', 'int32', REPEATED],
        3: ['set', 'int32', REPEATED],
    },
};

export const DisplayResolution = {
    name: 'DisplayResolution',
    fields: { 1: ['display', 'int32'], 2: ['resolution', Resolution] },
};

export const PermissionInfo = {
    name: 'PermissionInfo',
    fields: { 1: ['permission', 'enum'], 2: ['enabled', 'bool'] },
};

export const ChatMessage = { name: 'ChatMessage', fields: { 1: ['text', 'string'] } };

export const MessageBox = {
    name: 'MessageBox',
    fields: {
        1: ['msgtype', 'string'],
        2: ['title', 'string'],
        3: ['text', 'string'],
        4: ['link', 'string'], // NOT a safe URL — never render as a bare href
    },
};

export const BackNotification = {
    name: 'BackNotification',
    fields: {
        1: ['privacy_mode_state', 'enum'],
        2: ['block_input_state', 'enum'],
        3: ['details', 'string'],
        4: ['impl_key', 'string'],
    },
    oneofs: { union: ['privacy_mode_state', 'block_input_state'] },
};

export const TogglePrivacyMode = {
    name: 'TogglePrivacyMode',
    fields: { 1: ['impl_key', 'string'], 2: ['on', 'bool'] },
};

export const ToggleVirtualDisplay = {
    name: 'ToggleVirtualDisplay',
    fields: { 1: ['display', 'int32'], 2: ['on', 'bool'] },
};

export const MessageQuery = {
    name: 'MessageQuery',
    fields: { 1: ['switch_display', 'int32'] },
};

export const SwitchSidesRequest = { name: 'SwitchSidesRequest', fields: { 1: ['uuid', 'bytes'] } };
export const SwitchBack = { name: 'SwitchBack', fields: {} };

export const ElevationRequestWithLogon = {
    name: 'ElevationRequestWithLogon',
    fields: { 1: ['username', 'string'], 2: ['password', 'string'] },
};

export const ElevationRequest = {
    name: 'ElevationRequest',
    fields: { 1: ['direct', 'bool'], 2: ['logon', ElevationRequestWithLogon] },
    oneofs: { union: ['direct', 'logon'] },
};

export const PluginRequest = {
    name: 'PluginRequest',
    fields: { 1: ['id', 'string'], 2: ['content', 'bytes'] },
};

export const PluginFailure = {
    name: 'PluginFailure',
    fields: { 1: ['id', 'string'], 2: ['name', 'string'], 3: ['msg', 'string'] },
};

/** Tags 1–3, 11 and 23 are unused. */
export const Misc = {
    name: 'Misc',
    fields: {
        4: ['chat_message', ChatMessage],
        5: ['switch_display', SwitchDisplay],
        6: ['permission_info', PermissionInfo],
        7: ['option', OptionMessage],
        8: ['audio_format', AudioFormat],
        9: ['close_reason', 'string'],
        10: ['refresh_video', 'bool'],
        12: ['video_received', 'bool'],
        13: ['back_notification', BackNotification],
        14: ['restart_remote_device', 'bool'],
        15: ['uac', 'bool'],
        16: ['foreground_window_elevated', 'bool'],
        17: ['stop_service', 'bool'],
        18: ['elevation_request', ElevationRequest],
        19: ['elevation_response', 'string'],
        20: ['portable_service_running', 'bool'],
        21: ['switch_sides_request', SwitchSidesRequest],
        22: ['switch_back', SwitchBack],
        24: ['change_resolution', Resolution],
        25: ['plugin_request', PluginRequest],
        26: ['plugin_failure', PluginFailure],
        27: ['full_speed_fps', 'uint32'],
        28: ['auto_adjust_fps', 'uint32'],
        29: ['client_record_status', 'bool'],
        30: ['capture_displays', CaptureDisplays],
        31: ['refresh_video_display', 'int32'],
        32: ['toggle_virtual_display', ToggleVirtualDisplay],
        33: ['toggle_privacy_mode', TogglePrivacyMode],
        34: ['supported_encoding', SupportedEncoding],
        35: ['selected_sid', 'uint32'],
        36: ['change_display_resolution', DisplayResolution],
        37: ['message_query', MessageQuery],
        38: ['follow_current_display', 'int32'],
    },
    oneofs: {
        union: [
            'chat_message', 'switch_display', 'permission_info', 'option', 'audio_format',
            'close_reason', 'refresh_video', 'video_received', 'back_notification',
            'restart_remote_device', 'uac', 'foreground_window_elevated', 'stop_service',
            'elevation_request', 'elevation_response', 'portable_service_running',
            'switch_sides_request', 'switch_back', 'change_resolution', 'plugin_request',
            'plugin_failure', 'full_speed_fps', 'auto_adjust_fps', 'client_record_status',
            'capture_displays', 'refresh_video_display', 'toggle_virtual_display',
            'toggle_privacy_mode', 'supported_encoding', 'selected_sid',
            'change_display_resolution', 'message_query', 'follow_current_display',
        ],
    },
};

/* -------------------------------------------------------------------------- */
/* Clipboard                                                                  */
/* -------------------------------------------------------------------------- */

export const Clipboard = {
    name: 'Clipboard',
    fields: {
        1: ['compress', 'bool'],
        2: ['content', 'bytes'],
        3: ['width', 'int32'],
        4: ['height', 'int32'],
        5: ['format', 'enum'],
        6: ['special_name', 'string'],
    },
};

export const MultiClipboards = {
    name: 'MultiClipboards',
    fields: { 1: ['clipboards', Clipboard, REPEATED] },
};

/* -------------------------------------------------------------------------- */
/* File transfer                                                              */
/* -------------------------------------------------------------------------- */

export const FileEntry = {
    name: 'FileEntry',
    fields: {
        1: ['entry_type', 'enum'],
        2: ['name', 'string'],
        3: ['is_hidden', 'bool'],
        4: ['size', 'uint64'],
        5: ['modified_time', 'uint64'], // SECONDS since epoch, not ms
    },
};

export const FileDirectory = {
    name: 'FileDirectory',
    fields: {
        1: ['id', 'int32'],
        2: ['path', 'string'],
        3: ['entries', FileEntry, REPEATED],
    },
};

export const ReadDir = {
    name: 'ReadDir',
    fields: { 1: ['path', 'string'], 2: ['include_hidden', 'bool'] },
};

export const ReadEmptyDirs = {
    name: 'ReadEmptyDirs',
    fields: { 1: ['path', 'string'], 2: ['include_hidden', 'bool'] },
};

export const ReadEmptyDirsResponse = {
    name: 'ReadEmptyDirsResponse',
    fields: { 1: ['path', 'string'], 2: ['empty_dirs', FileDirectory, REPEATED] },
};

export const ReadAllFiles = {
    name: 'ReadAllFiles',
    fields: {
        1: ['id', 'int32'],
        2: ['path', 'string'],
        3: ['include_hidden', 'bool'],
    },
};

export const FileRename = {
    name: 'FileRename',
    fields: { 1: ['id', 'int32'], 2: ['path', 'string'], 3: ['new_name', 'string'] },
};

export const FileDirCreate = {
    name: 'FileDirCreate',
    fields: { 1: ['id', 'int32'], 2: ['path', 'string'] },
};

export const FileRemoveDir = {
    name: 'FileRemoveDir',
    fields: { 1: ['id', 'int32'], 2: ['path', 'string'], 3: ['recursive', 'bool'] },
};

export const FileRemoveFile = {
    name: 'FileRemoveFile',
    fields: { 1: ['id', 'int32'], 2: ['path', 'string'], 3: ['file_num', 'sint32'] },
};

export const FileTransferBlock = {
    name: 'FileTransferBlock',
    fields: {
        1: ['id', 'int32'],
        2: ['file_num', 'sint32'], // sint32 — NOT int32; see schema §1.2
        3: ['data', 'bytes'],
        4: ['compressed', 'bool'],
        5: ['blk_id', 'uint32'], // declared but never used by either side
    },
};

export const FileTransferDone = {
    name: 'FileTransferDone',
    fields: { 1: ['id', 'int32'], 2: ['file_num', 'sint32'] },
};

export const FileTransferError = {
    name: 'FileTransferError',
    fields: {
        1: ['id', 'int32'],
        2: ['error', 'string'],
        3: ['file_num', 'sint32'], // -1 means job-level, not attributable to a file
    },
};

export const FileTransferCancel = { name: 'FileTransferCancel', fields: { 1: ['id', 'int32'] } };

export const FileTransferDigest = {
    name: 'FileTransferDigest',
    fields: {
        1: ['id', 'int32'],
        2: ['file_num', 'sint32'],
        3: ['last_modified', 'uint64'],
        4: ['file_size', 'uint64'],
        5: ['is_upload', 'bool'], // the dispatch discriminator — see docs/spec/05
        6: ['is_identical', 'bool'],
        7: ['transferred_size', 'uint64'],
        8: ['is_resume', 'bool'],
    },
};

export const FileTransferSendRequest = {
    name: 'FileTransferSendRequest',
    fields: {
        1: ['id', 'int32'],
        2: ['path', 'string'],
        3: ['include_hidden', 'bool'],
        4: ['file_num', 'int32'], // plain int32 here, unlike the sint32 siblings
        5: ['file_type', 'enum'],
    },
};

export const FileTransferSendConfirmRequest = {
    name: 'FileTransferSendConfirmRequest',
    fields: {
        1: ['id', 'int32'],
        2: ['file_num', 'sint32'],
        3: ['skip', 'bool'],
        4: ['offset_blk', 'uint32'], // a BYTE offset despite the name; caps resume at 4 GiB
    },
    oneofs: { union: ['skip', 'offset_blk'] },
};

export const FileTransferReceiveRequest = {
    name: 'FileTransferReceiveRequest',
    fields: {
        1: ['id', 'int32'],
        2: ['path', 'string'],
        3: ['files', FileEntry, REPEATED],
        4: ['file_num', 'int32'],
        5: ['total_size', 'uint64'],
    },
};

export const FileAction = {
    name: 'FileAction',
    fields: {
        1: ['read_dir', ReadDir],
        2: ['send', FileTransferSendRequest],
        3: ['receive', FileTransferReceiveRequest],
        4: ['create', FileDirCreate],
        5: ['remove_dir', FileRemoveDir],
        6: ['remove_file', FileRemoveFile],
        7: ['all_files', ReadAllFiles],
        8: ['cancel', FileTransferCancel],
        9: ['send_confirm', FileTransferSendConfirmRequest],
        10: ['rename', FileRename],
        11: ['read_empty_dirs', ReadEmptyDirs],
    },
    oneofs: {
        union: ['read_dir', 'send', 'receive', 'create', 'remove_dir', 'remove_file',
            'all_files', 'cancel', 'send_confirm', 'rename', 'read_empty_dirs'],
    },
};

export const FileResponse = {
    name: 'FileResponse',
    fields: {
        1: ['dir', FileDirectory],
        2: ['block', FileTransferBlock],
        3: ['error', FileTransferError],
        4: ['done', FileTransferDone],
        5: ['digest', FileTransferDigest],
        6: ['empty_dirs', ReadEmptyDirsResponse],
    },
    oneofs: { union: ['dir', 'block', 'error', 'done', 'digest', 'empty_dirs'] },
};

/* -------------------------------------------------------------------------- */
/* Terminal                                                                   */
/* -------------------------------------------------------------------------- */

export const OpenTerminal = {
    name: 'OpenTerminal',
    fields: { 1: ['terminal_id', 'int32'], 2: ['rows', 'uint32'], 3: ['cols', 'uint32'] },
};

export const ResizeTerminal = {
    name: 'ResizeTerminal',
    fields: { 1: ['terminal_id', 'int32'], 2: ['rows', 'uint32'], 3: ['cols', 'uint32'] },
};

export const TerminalData = {
    name: 'TerminalData',
    fields: {
        1: ['terminal_id', 'int32'],
        2: ['data', 'bytes'], // raw PTY stream, zstd-compressed above 512 bytes
        3: ['compressed', 'bool'],
    },
};

export const CloseTerminal = { name: 'CloseTerminal', fields: { 1: ['terminal_id', 'int32'] } };

export const TerminalAction = {
    name: 'TerminalAction',
    fields: {
        1: ['open', OpenTerminal],
        2: ['data', TerminalData],
        3: ['resize', ResizeTerminal],
        4: ['close', CloseTerminal],
    },
    oneofs: { union: ['open', 'data', 'resize', 'close'] },
};

export const TerminalOpened = {
    name: 'TerminalOpened',
    fields: {
        1: ['terminal_id', 'int32'],
        2: ['success', 'bool'],
        3: ['message', 'string'],
        4: ['pid', 'uint32'],
        5: ['service_id', 'string'], // store and echo to reattach a persistent session
        6: ['persistent_sessions', 'int32', REPEATED],
        7: ['replay_terminal_output', 'bool'],
    },
};

export const TerminalClosed = {
    name: 'TerminalClosed',
    fields: { 1: ['terminal_id', 'int32'], 2: ['exit_code', 'int32'] },
};

export const TerminalError = {
    name: 'TerminalError',
    fields: { 1: ['terminal_id', 'int32'], 2: ['message', 'string'] },
};

export const TerminalResponse = {
    name: 'TerminalResponse',
    fields: {
        1: ['opened', TerminalOpened],
        2: ['data', TerminalData],
        3: ['closed', TerminalClosed],
        4: ['error', TerminalError],
    },
    oneofs: { union: ['opened', 'data', 'closed', 'error'] },
};

/* -------------------------------------------------------------------------- */
/* Misc leaves and the envelope                                               */
/* -------------------------------------------------------------------------- */

export const ScreenshotRequest = {
    name: 'ScreenshotRequest',
    fields: { 1: ['display', 'int32'], 2: ['sid', 'string'] },
};

export const ScreenshotResponse = {
    name: 'ScreenshotResponse',
    fields: { 1: ['sid', 'string'], 2: ['msg', 'string'], 3: ['data', 'bytes'] },
};

export const VoiceCallRequest = {
    name: 'VoiceCallRequest',
    fields: { 1: ['req_timestamp', 'int64'], 2: ['is_connect', 'bool'] },
};

export const VoiceCallResponse = {
    name: 'VoiceCallResponse',
    fields: {
        1: ['accepted', 'bool'],
        2: ['req_timestamp', 'int64'],
        3: ['ack_timestamp', 'int64'],
    },
};

export const SwitchSidesResponse = {
    name: 'SwitchSidesResponse',
    fields: { 1: ['uuid', 'bytes'], 2: ['lr', LoginRequest] },
};

/**
 * The session envelope. Tags 1–2 unused.
 *
 * `cursor_id` (14) is a bare uint64, not a wrapper message — the only such field.
 * `cliprdr` (20) and `pointer_device_event` (26) are intentionally absent: see
 * README.md for why neither is implementable or worthwhile in a browser.
 */
export const Message = {
    name: 'Message',
    fields: {
        3: ['signed_id', SignedId],
        4: ['public_key', PublicKey],
        5: ['test_delay', TestDelay],
        6: ['video_frame', VideoFrame],
        7: ['login_request', LoginRequest],
        8: ['login_response', LoginResponse],
        9: ['hash', Hash],
        10: ['mouse_event', MouseEvent],
        11: ['audio_frame', AudioFrame],
        12: ['cursor_data', CursorData],
        13: ['cursor_position', CursorPosition],
        14: ['cursor_id', 'uint64'],
        15: ['key_event', KeyEvent],
        16: ['clipboard', Clipboard],
        17: ['file_action', FileAction],
        18: ['file_response', FileResponse],
        19: ['misc', Misc],
        21: ['message_box', MessageBox],
        22: ['switch_sides_response', SwitchSidesResponse],
        23: ['voice_call_request', VoiceCallRequest],
        24: ['voice_call_response', VoiceCallResponse],
        25: ['peer_info', PeerInfo],
        27: ['auth_2fa', Auth2FA],
        28: ['multi_clipboards', MultiClipboards],
        29: ['screenshot_request', ScreenshotRequest],
        30: ['screenshot_response', ScreenshotResponse],
        31: ['terminal_action', TerminalAction],
        32: ['terminal_response', TerminalResponse],
    },
    oneofs: {
        union: [
            'signed_id', 'public_key', 'test_delay', 'video_frame', 'login_request',
            'login_response', 'hash', 'mouse_event', 'audio_frame', 'cursor_data',
            'cursor_position', 'cursor_id', 'key_event', 'clipboard', 'file_action',
            'file_response', 'misc', 'message_box', 'switch_sides_response',
            'voice_call_request', 'voice_call_response', 'peer_info', 'auth_2fa',
            'multi_clipboards', 'screenshot_request', 'screenshot_response',
            'terminal_action', 'terminal_response',
        ],
    },
};
