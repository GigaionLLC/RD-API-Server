/**
 * `rendezvous.proto` descriptors — the messages exchanged with hbbs and hbbr.
 *
 * Transcribed from docs/spec/06-schema.md §3. Tag numbers are the wire contract.
 *
 * A browser viewer only ever sends PunchHoleRequest and RequestRelay, and only ever
 * needs PunchHoleResponse and RelayResponse back. The rest are described so unknown
 * traffic decodes rather than throwing, and so the union tags stay documented.
 */

import { REPEATED } from './codec.js';

export const ControlPermissions = {
    name: 'ControlPermissions',
    fields: { 1: ['permissions', 'uint64'] },
};

export const ConfigUpdate = {
    name: 'ConfigUpdate',
    fields: {
        1: ['serial', 'int32'],
        2: ['rendezvous_servers', 'string', REPEATED],
    },
};

export const PunchHoleRequest = {
    name: 'PunchHoleRequest',
    fields: {
        1: ['id', 'string'],
        2: ['nat_type', 'enum'],
        3: ['licence_key', 'string'],
        4: ['conn_type', 'enum'],
        5: ['token', 'string'],
        6: ['version', 'string'],
        7: ['udp_port', 'int32'],
        // Forwarded nowhere by OSS hbbs — `nat_type: SYMMETRIC` is the real relay
        // trigger. Sent anyway so the intent is on the wire for servers that read it.
        8: ['force_relay', 'bool'],
        9: ['upnp_port', 'int32'],
        10: ['socket_addr_v6', 'bytes'],
    },
};

export const PunchHoleResponse = {
    name: 'PunchHoleResponse',
    fields: {
        // Empty `socket_addr` IS the failure signal; check it before `failure`, which
        // defaults to ID_NOT_EXIST = 0.
        1: ['socket_addr', 'bytes'],
        2: ['pk', 'bytes'],
        3: ['failure', 'enum'],
        4: ['relay_server', 'string'],
        5: ['nat_type', 'enum'],
        6: ['is_local', 'bool'],
        7: ['other_failure', 'string'],
        8: ['feedback', 'int32'],
        9: ['is_udp', 'bool'],
        10: ['upnp_port', 'int32'],
        11: ['socket_addr_v6', 'bytes'],
    },
    oneofs: { union: ['nat_type', 'is_local'] },
};

export const RequestRelay = {
    name: 'RequestRelay',
    fields: {
        1: ['id', 'string'],
        2: ['uuid', 'string'],
        3: ['socket_addr', 'bytes'],
        4: ['relay_server', 'string'],
        5: ['secure', 'bool'],
        6: ['licence_key', 'string'],
        7: ['conn_type', 'enum'],
        8: ['token', 'string'],
        9: ['control_permissions', ControlPermissions],
    },
};

export const RelayResponse = {
    name: 'RelayResponse',
    fields: {
        1: ['socket_addr', 'bytes'],
        2: ['uuid', 'string'],
        3: ['relay_server', 'string'],
        4: ['id', 'string'],
        5: ['pk', 'bytes'], // signed IdPk — this is our `signed_id_pk`
        6: ['refuse_reason', 'string'],
        7: ['version', 'string'],
        9: ['feedback', 'int32'], // tag 8 unused
        10: ['socket_addr_v6', 'bytes'],
        11: ['upnp_port', 'int32'],
    },
    oneofs: { union: ['id', 'pk'] },
};

/* ---- Decode-and-ignore: present so unknown traffic is inspectable, not fatal ---- */

export const RegisterPeer = {
    name: 'RegisterPeer',
    fields: { 1: ['id', 'string'], 2: ['serial', 'int32'] },
};

export const RegisterPeerResponse = {
    name: 'RegisterPeerResponse',
    fields: { 2: ['request_pk', 'bool'] },
};

export const RegisterPk = {
    name: 'RegisterPk',
    fields: {
        1: ['id', 'string'],
        2: ['uuid', 'bytes'],
        3: ['pk', 'bytes'],
        4: ['old_id', 'string'],
        5: ['no_register_device', 'bool'],
    },
};

export const RegisterPkResponse = {
    name: 'RegisterPkResponse',
    fields: { 1: ['result', 'enum'], 2: ['keep_alive', 'int32'] },
};

export const PunchHole = {
    name: 'PunchHole',
    fields: {
        1: ['socket_addr', 'bytes'],
        2: ['relay_server', 'string'],
        3: ['nat_type', 'enum'],
        4: ['udp_port', 'int32'],
        5: ['force_relay', 'bool'],
        6: ['upnp_port', 'int32'],
        7: ['socket_addr_v6', 'bytes'],
        8: ['control_permissions', ControlPermissions],
    },
};

export const PunchHoleSent = {
    name: 'PunchHoleSent',
    fields: {
        1: ['socket_addr', 'bytes'],
        2: ['id', 'string'],
        3: ['relay_server', 'string'],
        4: ['nat_type', 'enum'],
        5: ['version', 'string'],
        6: ['upnp_port', 'int32'],
        7: ['socket_addr_v6', 'bytes'],
    },
};

export const TestNatRequest = { name: 'TestNatRequest', fields: { 1: ['serial', 'int32'] } };

export const TestNatResponse = {
    name: 'TestNatResponse',
    fields: { 1: ['port', 'int32'], 2: ['cu', ConfigUpdate] },
};

export const SoftwareUpdate = { name: 'SoftwareUpdate', fields: { 1: ['url', 'string'] } };

export const FetchLocalAddr = {
    name: 'FetchLocalAddr',
    fields: {
        1: ['socket_addr', 'bytes'],
        2: ['relay_server', 'string'],
        3: ['socket_addr_v6', 'bytes'],
        4: ['control_permissions', ControlPermissions],
    },
};

export const LocalAddr = {
    name: 'LocalAddr',
    fields: {
        1: ['socket_addr', 'bytes'],
        2: ['local_addr', 'bytes'],
        3: ['relay_server', 'string'],
        4: ['id', 'string'],
        5: ['version', 'string'],
        6: ['socket_addr_v6', 'bytes'],
    },
};

export const PeerDiscovery = {
    name: 'PeerDiscovery',
    fields: {
        1: ['cmd', 'string'],
        2: ['mac', 'string'],
        3: ['id', 'string'],
        4: ['username', 'string'],
        5: ['hostname', 'string'],
        6: ['platform', 'string'],
        7: ['misc', 'string'],
    },
};

export const OnlineRequest = {
    name: 'OnlineRequest',
    fields: { 1: ['id', 'string'], 2: ['peers', 'string', REPEATED] },
};

export const OnlineResponse = { name: 'OnlineResponse', fields: { 1: ['states', 'bytes'] } };

export const KeyExchange = { name: 'KeyExchange', fields: { 1: ['keys', 'bytes', REPEATED] } };

export const HealthCheck = { name: 'HealthCheck', fields: { 1: ['token', 'string'] } };

/**
 * The outer envelope. Tags 1–5 are historical and unused.
 *
 * Note hbbs keeps a TCP/WS read loop alive only for `punch_hole_request` and
 * `request_relay`; any other message closes the socket. See docs/spec/02.
 */
export const RendezvousMessage = {
    name: 'RendezvousMessage',
    fields: {
        6: ['register_peer', RegisterPeer],
        7: ['register_peer_response', RegisterPeerResponse],
        8: ['punch_hole_request', PunchHoleRequest],
        9: ['punch_hole', PunchHole],
        10: ['punch_hole_sent', PunchHoleSent],
        11: ['punch_hole_response', PunchHoleResponse],
        12: ['fetch_local_addr', FetchLocalAddr],
        13: ['local_addr', LocalAddr],
        14: ['configure_update', ConfigUpdate],
        15: ['register_pk', RegisterPk],
        16: ['register_pk_response', RegisterPkResponse],
        17: ['software_update', SoftwareUpdate],
        18: ['request_relay', RequestRelay],
        19: ['relay_response', RelayResponse],
        20: ['test_nat_request', TestNatRequest],
        21: ['test_nat_response', TestNatResponse],
        22: ['peer_discovery', PeerDiscovery],
        23: ['online_request', OnlineRequest],
        24: ['online_response', OnlineResponse],
        25: ['key_exchange', KeyExchange],
        26: ['hc', HealthCheck],
    },
    oneofs: {
        union: [
            'register_peer', 'register_peer_response', 'punch_hole_request', 'punch_hole',
            'punch_hole_sent', 'punch_hole_response', 'fetch_local_addr', 'local_addr',
            'configure_update', 'register_pk', 'register_pk_response', 'software_update',
            'request_relay', 'relay_response', 'test_nat_request', 'test_nat_response',
            'peer_discovery', 'online_request', 'online_response', 'key_exchange', 'hc',
        ],
    },
};
