<?php

/*
 * LDAP / Active Directory authentication settings.
 *
 * Mirrors the Go reference (service/ldap.go, config/ldap.go and the `ldap:` block of
 * conf/config.yaml). LDAP is DISABLED by default so existing local login is unaffected;
 * set LDAP_ENABLED=true to turn it on. All values come from the environment so deployments
 * need no code change.
 */

return [

    // Master switch. When false, LdapService::enabled() is false and login behaves exactly
    // like the pure-local flow.
    'enabled' => (bool) env('LDAP_ENABLED', false),

    // Directory server connection.
    'host' => env('LDAP_HOST', 'ldap.example.com'),
    'port' => (int) env('LDAP_PORT', 389),

    // Where to search for users.
    'base_dn' => env('LDAP_BASE_DN', 'dc=example,dc=com'),

    // Stable namespace for persisted LDAP identity links. Leave blank to derive it from
    // host + port + base DN; set an explicit value before intentionally moving a directory.
    'provider_id' => env('LDAP_PROVIDER_ID', ''),

    // Immutable per-entry subject used for identity binding. OpenLDAP commonly provides entryUUID;
    // use objectGUID for Active Directory. Blank or `dn` fails closed because DNs can be reused.
    'subject_attr' => env('LDAP_SUBJECT_ATTR', 'entryUUID'),

    // Service account used to search the directory before the user re-bind.
    'bind_dn' => env('LDAP_BIND_DN', ''),
    'bind_password' => env('LDAP_BIND_PASSWORD', ''),

    // User search filter. %s is replaced with the (escaped) login username, e.g. "(uid=%s)"
    // or "(sAMAccountName=%s)" for Active Directory.
    'user_filter' => env('LDAP_USER_FILTER', '(uid=%s)'),

    // Attribute mapping from the directory entry to the local User columns.
    'username_attr' => env('LDAP_USERNAME_ATTR', 'uid'),
    'email_attr' => env('LDAP_EMAIL_ATTR', 'mail'),
    'displayname_attr' => env('LDAP_DISPLAYNAME_ATTR', 'cn'),

    // Transport security.
    'use_starttls' => (bool) env('LDAP_USE_STARTTLS', false),
    'tls_verify' => (bool) env('LDAP_TLS_VERIFY', true),

    // Optional group DNs. admin_group grants is_admin; allow_group, when set, denies any user
    // who is not a member. Both are matched against the user's memberOf values (case-insensitive).
    'admin_group' => env('LDAP_ADMIN_GROUP', ''),
    'allow_group' => env('LDAP_ALLOW_GROUP', ''),

    // When true, update local email/display_name/is_admin from LDAP on every login. When false,
    // those attributes are only set when the local user is first created.
    'sync' => (bool) env('LDAP_SYNC', false),
];
