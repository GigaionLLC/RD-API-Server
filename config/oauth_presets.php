<?php

/*
 * Guided setup presets for the OAuth/OIDC provider create form. Selecting a preset prefills the
 * type / scopes / PKCE / issuer-shape fields and shows provider-specific guidance; the admin
 * still supplies the client id + secret (and the real issuer host). Keycloak is first as the
 * primary supported IdP. These are UI conveniences only — nothing here changes the login flow.
 */

return [

    'keycloak' => [
        'label' => 'Keycloak',
        'type' => 'oidc',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => true,
        'pkce_method' => 'S256',
        'issuer_placeholder' => 'https://KEYCLOAK_HOST/realms/REALM',
        'hint' => 'Issuer is https://<host>/realms/<realm>. In Keycloak: create an OpenID Connect client with Standard flow, turn on "Client authentication" to get a secret, and add the Redirect URI above to the client\'s "Valid redirect URIs".',
    ],

    'authentik' => [
        'label' => 'Authentik',
        'type' => 'oidc',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => true,
        'pkce_method' => 'S256',
        'issuer_placeholder' => 'https://AUTHENTIK_HOST/application/o/APP_SLUG/',
        'hint' => 'Create an OAuth2/OpenID Provider + Application; the issuer is the provider\'s OpenID Configuration Issuer URL. Add the Redirect URI above as a valid redirect URI.',
    ],

    'azure' => [
        'label' => 'Microsoft Entra ID (Azure AD)',
        'type' => 'oidc',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => true,
        'pkce_method' => 'S256',
        'issuer_placeholder' => 'https://login.microsoftonline.com/TENANT_ID/v2.0',
        'hint' => 'Register an app in Entra ID; the issuer is https://login.microsoftonline.com/<tenant-id>/v2.0. Add the Redirect URI above as a Web redirect URI and create a client secret.',
    ],

    'okta' => [
        'label' => 'Okta',
        'type' => 'oidc',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => true,
        'pkce_method' => 'S256',
        'issuer_placeholder' => 'https://YOUR_DOMAIN.okta.com',
        'hint' => 'Create an OIDC Web application in Okta; the issuer is your Okta domain (or an Authorization Server issuer). Add the Redirect URI above as a Sign-in redirect URI.',
    ],

    'google' => [
        'label' => 'Google',
        'type' => 'google',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => false,
        'pkce_method' => 'S256',
        'issuer_placeholder' => '',
        'hint' => 'Create OAuth client credentials in Google Cloud Console (type: Web application). No issuer needed. Add the Redirect URI above as an authorised redirect URI.',
    ],

    'github' => [
        'label' => 'GitHub',
        'type' => 'github',
        'scopes' => 'read:user,user:email',
        'pkce_enable' => false,
        'pkce_method' => 'S256',
        'issuer_placeholder' => '',
        'hint' => 'Create an OAuth App in GitHub Developer settings. No issuer needed. Set the Authorization callback URL to the Redirect URI above.',
    ],

    'oidc' => [
        'label' => 'Generic OIDC',
        'type' => 'oidc',
        'scopes' => 'openid,profile,email',
        'pkce_enable' => true,
        'pkce_method' => 'S256',
        'issuer_placeholder' => 'https://idp.example.com',
        'hint' => 'Any OpenID Connect provider with a /.well-known/openid-configuration document. Set the issuer to its base URL and register the Redirect URI above.',
    ],

];
