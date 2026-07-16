<?php

namespace App\Services;

use App\Models\LdapIdentity;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use LDAP\Connection;
use RuntimeException;

/**
 * LDAP / Active Directory authentication and user synchronization.
 *
 * Mirrors the Go reference (service/ldap.go): connect, bind as the service account, search the
 * directory for the user, then re-bind as the found user DN with their password to verify the
 * credentials (the password is never read from the directory). Group membership drives admin
 * rights and the optional allow-group gate.
 *
 * Directory/connection methods are defensive: they log and return null/false on LDAP failures so
 * callers can fall back to local-password auth. Persistence errors from an authenticated identity
 * deliberately propagate so login fails closed instead of selecting a local account. Passwords are
 * never logged.
 */
class LdapService
{
    private const PROVIDER_MAX_LENGTH = 100;

    private const SUBJECT_HASH_LENGTH = 64;

    private const USERNAME_MAX_LENGTH = 255;

    private const PROVISION_ATTEMPTS = 3;

    private const HOST_MAX_LENGTH = 253;

    /**
     * Whether LDAP authentication is configured and enabled.
     */
    public function enabled(): bool
    {
        return (bool) config('ldap.enabled', false) && extension_loaded('ldap');
    }

    /**
     * Verify the username/password against the directory.
     *
     * Returns the mapped attributes on success:
     *   ['username','email','display_name','dn','is_admin','groups','provider','subject_hash']
     * Returns null on any failure (bad credentials, user not found, not in allow-group,
     * connection/search error). Never throws.
     *
     * @return array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}|null
     */
    public function authenticate(string $username, string $password): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        // An empty password would otherwise trigger an LDAP "unauthenticated bind" that succeeds
        // without verifying anything — reject it up front.
        if ($username === '' || $password === '') {
            return null;
        }

        $transport = $this->transportConfiguration();
        if ($transport['error'] !== null) {
            Log::error($transport['error']);

            return null;
        }

        $provider = $this->identityProvider($transport['port']);
        if ($provider === null) {
            Log::error('LDAP identity provider configuration is invalid.');

            return null;
        }

        $connection = null;

        try {
            $connection = $this->connect();
            if ($connection === null) {
                return null;
            }

            // 1. Bind as the service account so we can search the directory.
            if (! $this->bindServiceAccount($connection)) {
                return null;
            }

            // 2. Locate the user entry.
            $entry = $this->findUser($connection, $username);
            if ($entry === null) {
                return null;
            }

            $userDn = (string) ($entry['dn'] ?? '');
            if ($userDn === '') {
                return null;
            }

            $subject = $this->identitySubject($entry);
            if ($subject === null) {
                Log::error('LDAP user entry has no stable identity subject.', [
                    'subject_attribute' => (string) config('ldap.subject_attr', 'entryUUID'),
                ]);

                return null;
            }

            $groups = $this->extractGroups($entry);

            // 3. Honor the allow-group gate before attempting the user bind.
            $allowGroup = (string) config('ldap.allow_group', '');
            if ($allowGroup !== '' && ! $this->inGroup($groups, $allowGroup)) {
                Log::warning('LDAP login denied: user not in allow-group', ['username' => $username]);

                return null;
            }

            // 4. Re-bind as the user to verify their password. This is the credential check.
            if (! $this->bindLdap($connection, $userDn, $password)) {
                return null;
            }

            return [
                'username' => $this->attr($entry, (string) config('ldap.username_attr', 'uid')) ?: $username,
                'email' => $this->attr($entry, (string) config('ldap.email_attr', 'mail')),
                'display_name' => $this->attr($entry, (string) config('ldap.displayname_attr', 'cn')),
                'dn' => $userDn,
                'is_admin' => $this->isAdmin($groups),
                'groups' => $groups,
                'provider' => $provider,
                'subject_hash' => hash('sha256', $subject),
            ];
        } catch (\Throwable $e) {
            Log::error('LDAP authenticate error: '.$e->getMessage());

            return null;
        } finally {
            if ($connection !== null) {
                $this->unbindLdap($connection);
            }
        }
    }

    /**
     * Resolve an authenticated LDAP identity to exactly one local user.
     *
     * Existing unlinked users are deliberately never adopted by username or email. This makes an
     * upgrade fail secure: the first login for an LDAP subject creates a separate collision-safe
     * local account, while only a persisted provider/subject link can resolve future logins.
     *
     * @param  array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}  $attrs
     */
    public function syncUser(array $attrs): User
    {
        $provider = trim($attrs['provider']);
        $subjectHash = strtolower($attrs['subject_hash']);

        if ($provider === ''
            || mb_strlen($provider) > self::PROVIDER_MAX_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/u', $provider) === 1) {
            throw new InvalidArgumentException('LDAP provider identity is invalid.');
        }
        if (preg_match('/\A[a-f0-9]{'.self::SUBJECT_HASH_LENGTH.'}\z/', $subjectHash) !== 1) {
            throw new InvalidArgumentException('LDAP subject identity is invalid.');
        }

        // Unique database constraints are the final authority. Retrying the whole transaction
        // safely resolves concurrent first-login races without leaving an orphan local account.
        for ($attempt = 1; $attempt <= self::PROVISION_ATTEMPTS; $attempt++) {
            try {
                return DB::transaction(
                    fn (): User => $this->syncUserInTransaction($attrs, $provider, $subjectHash),
                    self::PROVISION_ATTEMPTS,
                );
            } catch (QueryException $exception) {
                if ($attempt === self::PROVISION_ATTEMPTS) {
                    throw $exception;
                }
            }
        }

        throw new RuntimeException('LDAP identity provisioning failed.');
    }

    /**
     * @param  array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}  $attrs
     */
    private function syncUserInTransaction(array $attrs, string $provider, string $subjectHash): User
    {
        $identity = LdapIdentity::query()
            ->where('provider', $provider)
            ->where('subject_hash', $subjectHash)
            ->lockForUpdate()
            ->first();

        if ($identity !== null) {
            $user = User::query()->whereKey($identity->user_id)->lockForUpdate()->first();
            if ($user === null) {
                throw new RuntimeException('LDAP identity references a missing local user.');
            }

            return $this->syncLinkedAttributes($user, $attrs);
        }

        $user = new User;
        $user->username = $this->availableUsername($attrs['username'], $subjectHash);
        $user->email = $this->nullableUserAttribute($attrs['email']);
        $user->display_name = $this->nullableUserAttribute($attrs['display_name']);
        $user->is_admin = $attrs['is_admin'];
        $user->status = User::STATUS_NORMAL;
        // LDAP users never use the local hash; make fallback password authentication infeasible.
        $user->password = Str::random(64);
        $user->save();

        LdapIdentity::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'subject_hash' => $subjectHash,
        ]);

        return $user;
    }

    /**
     * @param  array{username:string,email:string,display_name:string,dn:string,is_admin:bool,groups:array<int,string>,provider:string,subject_hash:string}  $attrs
     */
    private function syncLinkedAttributes(User $user, array $attrs): User
    {
        if ((bool) config('ldap.sync', false)) {
            // The local username and identity link are immutable. Mutable directory attributes
            // may be refreshed, but they can never select or relink another local account.
            $directoryEmail = $this->nullableUserAttribute($attrs['email']);
            // Fail closed if LDAP removes or corrupts the destination required by email
            // verification. Validate before assigning any synchronized account fields.
            $requiredEmailIsInvalid = $user->login_verify === User::LOGIN_VERIFY_EMAIL
                && Validator::make(
                    ['email' => trim($attrs['email'])],
                    ['email' => ['required', 'email', 'max:255']],
                )->fails();
            if ($requiredEmailIsInvalid) {
                throw new RuntimeException(
                    'LDAP synchronization requires a valid email address while email verification is enabled.'
                );
            }
            $user->email = $directoryEmail;
            $user->display_name = $this->nullableUserAttribute($attrs['display_name']);
            $user->is_admin = $attrs['is_admin'];
            $user->save();
        }

        return $user;
    }

    private function availableUsername(string $preferred, string $subjectHash): string
    {
        $preferred = preg_replace('/[\x00-\x1F\x7F]/u', '', $preferred) ?? '';
        $preferred = trim($preferred);
        if ($preferred === '') {
            $preferred = 'ldap-user';
        }
        $preferred = mb_substr($preferred, 0, self::USERNAME_MAX_LENGTH);

        if (! $this->usernameIsTaken($preferred)) {
            return $preferred;
        }

        $identitySuffix = '-ldap-'.substr($subjectHash, 0, 12);
        for ($sequence = 1; $sequence <= 1000; $sequence++) {
            $sequenceSuffix = $sequence === 1 ? '' : '-'.$sequence;
            $suffix = $identitySuffix.$sequenceSuffix;
            $base = mb_substr($preferred, 0, self::USERNAME_MAX_LENGTH - mb_strlen($suffix));
            $candidate = $base.$suffix;

            if (! $this->usernameIsTaken($candidate)) {
                return $candidate;
            }
        }

        throw new RuntimeException('Could not allocate a unique LDAP username.');
    }

    private function usernameIsTaken(string $username): bool
    {
        return User::query()
            ->where('username', $username)
            ->orWhere('email', $username)
            ->exists();
    }

    private function nullableUserAttribute(string $value): ?string
    {
        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, self::USERNAME_MAX_LENGTH);
    }

    /**
     * Resolve and validate the effective LDAP transport before any connection or bind attempt.
     *
     * Bare hosts and ldap:// endpoints use StartTLS when enabled. ldaps:// endpoints are already
     * encrypted, so StartTLS is never applied a second time. URI endpoints use their embedded port
     * or the scheme default (389/636); bare hosts use LDAP_PORT. This preserves PHP's prior URI
     * behavior while removing the deprecated and ambiguous two-argument ldap_connect() call.
     *
     * @return array{host:string,port:int,scheme:string,uri:string,start_tls:bool,encrypted:bool,tls_verify:bool,allow_insecure:bool,label:string,error:string|null}
     */
    public function transportConfiguration(): array
    {
        $configuredHost = trim((string) config('ldap.host', ''));
        $useStartTls = (bool) config('ldap.use_starttls', true);
        $tlsVerify = (bool) config('ldap.tls_verify', true);
        $allowInsecure = (bool) config('ldap.allow_insecure', false);

        if ($configuredHost === '') {
            return $this->invalidTransportConfiguration(
                $configuredHost,
                $tlsVerify,
                $allowInsecure,
                'LDAP_HOST must not be empty.',
            );
        }
        if (strlen($configuredHost) > 512
            || preg_match('/[\x00-\x20\x7F]/', $configuredHost) === 1) {
            return $this->invalidTransportConfiguration(
                $configuredHost,
                $tlsVerify,
                $allowInsecure,
                'LDAP_HOST must contain one hostname or IP address without whitespace or control characters.',
            );
        }

        $scheme = 'ldap';
        $host = $configuredHost;
        $embeddedPort = null;
        $schemeProvided = false;

        if (str_contains($configuredHost, '://')) {
            $schemeProvided = true;
            try {
                $parts = parse_url($configuredHost);
            } catch (\ValueError) {
                $parts = false;
            }

            if (! is_array($parts)) {
                return $this->invalidTransportConfiguration(
                    $configuredHost,
                    $tlsVerify,
                    $allowInsecure,
                    'LDAP_HOST is not a valid LDAP URI.',
                );
            }

            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if (! in_array($scheme, ['ldap', 'ldaps'], true)) {
                return $this->invalidTransportConfiguration(
                    $configuredHost,
                    $tlsVerify,
                    $allowInsecure,
                    'LDAP_HOST supports only ldap:// or ldaps:// schemes.',
                );
            }
            if (array_key_exists('user', $parts)
                || array_key_exists('pass', $parts)
                || array_key_exists('query', $parts)
                || array_key_exists('fragment', $parts)
                || ! in_array((string) ($parts['path'] ?? ''), ['', '/'], true)) {
                return $this->invalidTransportConfiguration(
                    $configuredHost,
                    $tlsVerify,
                    $allowInsecure,
                    'LDAP_HOST must not contain credentials, a path, query parameters, or a fragment.',
                );
            }

            $host = (string) ($parts['host'] ?? '');
            $embeddedPort = array_key_exists('port', $parts) ? (int) $parts['port'] : null;
        }

        if (str_starts_with($host, '[') || str_ends_with($host, ']')) {
            if (! str_starts_with($host, '[') || ! str_ends_with($host, ']')) {
                return $this->invalidTransportConfiguration(
                    $configuredHost,
                    $tlsVerify,
                    $allowInsecure,
                    'LDAP_HOST contains an invalid bracketed address.',
                );
            }
            $host = substr($host, 1, -1);
        }

        if (! $this->validLdapHost($host)) {
            return $this->invalidTransportConfiguration(
                $configuredHost,
                $tlsVerify,
                $allowInsecure,
                'LDAP_HOST must contain a valid hostname, IPv4 address, or bracketed IPv6 address.',
            );
        }

        $port = $embeddedPort
            ?? ($schemeProvided
                ? ($scheme === 'ldaps' ? 636 : 389)
                : $this->validLdapPort(config('ldap.port', 389)));
        if ($port === null || $port < 1 || $port > 65535) {
            return $this->invalidTransportConfiguration(
                $configuredHost,
                $tlsVerify,
                $allowInsecure,
                'LDAP_PORT (or the URI port) must be an integer from 1 through 65535.',
            );
        }

        $uriHost = filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
            ? '['.$host.']'
            : $host;
        $uri = $scheme.'://'.$uriHost.':'.$port;
        $startTls = $scheme === 'ldap' && $useStartTls;
        $encrypted = $scheme === 'ldaps' || $startTls;

        $label = match (true) {
            $scheme === 'ldaps' && $tlsVerify => 'LDAPS (certificate verified)',
            $scheme === 'ldaps' => 'LDAPS (certificate verification disabled)',
            $startTls && $tlsVerify => 'StartTLS (certificate verified)',
            $startTls => 'StartTLS (certificate verification disabled)',
            default => 'Plaintext LDAP (explicit insecure override)',
        };

        $configuration = [
            'host' => $host,
            'port' => $port,
            'scheme' => $scheme,
            'uri' => $uri,
            'start_tls' => $startTls,
            'encrypted' => $encrypted,
            'tls_verify' => $tlsVerify,
            'allow_insecure' => $allowInsecure,
            'label' => $label,
            'error' => null,
        ];

        if (! $encrypted && ! $allowInsecure) {
            $configuration['label'] = 'Plaintext LDAP (blocked)';
            $configuration['error'] = 'LDAP configuration error: plaintext LDAP is blocked. '
                .'Enable LDAP_USE_STARTTLS=true or use an ldaps:// endpoint. For isolated legacy '
                .'environments only, LDAP_ALLOW_INSECURE=true is the explicit risk override.';
        } elseif (! $tlsVerify && ! $allowInsecure) {
            $configuration['label'] .= ' (blocked)';
            $configuration['error'] = 'LDAP configuration error: TLS certificate verification is disabled. '
                .'Set LDAP_TLS_VERIFY=true. For isolated legacy environments only, '
                .'LDAP_ALLOW_INSECURE=true is the explicit risk override.';
        }

        return $configuration;
    }

    /**
     * Attempt the service-account bind only (used by the admin "Test connection" action).
     * Returns null on success or a human-readable error message on failure.
     */
    public function testConnection(): ?string
    {
        if (! (bool) config('ldap.enabled', false)) {
            return 'LDAP is disabled.';
        }

        $transport = $this->transportConfiguration();
        if ($transport['error'] !== null) {
            return $transport['error'];
        }

        if (! extension_loaded('ldap')) {
            return 'The PHP ldap extension is not installed.';
        }

        $connection = null;

        try {
            $connection = $this->connect();
            if ($connection === null) {
                return 'Could not establish the configured '.$transport['label'].' connection. '
                    .'Check the LDAP host, port, TLS support, and trusted CA certificates.';
            }

            if (! $this->bindServiceAccount($connection)) {
                return 'Service-account bind failed: '.$this->ldapError($connection);
            }

            return null;
        } catch (\Throwable $e) {
            return 'LDAP error: '.$e->getMessage();
        } finally {
            if ($connection !== null) {
                $this->unbindLdap($connection);
            }
        }
    }

    /**
     * @return array{host:string,port:int,scheme:string,uri:string,start_tls:bool,encrypted:bool,tls_verify:bool,allow_insecure:bool,label:string,error:string|null}
     */
    private function invalidTransportConfiguration(
        string $host,
        bool $tlsVerify,
        bool $allowInsecure,
        string $message,
    ): array {
        return [
            'host' => $host,
            'port' => 0,
            'scheme' => '',
            'uri' => '',
            'start_tls' => false,
            'encrypted' => false,
            'tls_verify' => $tlsVerify,
            'allow_insecure' => $allowInsecure,
            'label' => 'Invalid LDAP transport configuration',
            'error' => 'LDAP configuration error: '.$message,
        ];
    }

    private function validLdapHost(string $host): bool
    {
        if ($host === '' || strlen($host) > self::HOST_MAX_LENGTH) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        return filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function validLdapPort(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 1 && $value <= 65535 ? $value : null;
        }

        if (! is_string($value) || preg_match('/\A[0-9]{1,5}\z/', $value) !== 1) {
            return null;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * Return the configured stable directory namespace. When no explicit identifier is supplied,
     * derive one from the connection host, port, and search base. Configuration changes therefore
     * fail closed into a new namespace instead of silently reusing another directory's subjects.
     */
    private function identityProvider(int $effectivePort): ?string
    {
        $configured = trim((string) config('ldap.provider_id', ''));
        if ($configured !== '') {
            if (mb_strlen($configured) > self::PROVIDER_MAX_LENGTH
                || preg_match('/[\x00-\x1F\x7F]/u', $configured) === 1) {
                return null;
            }

            return $configured;
        }

        $host = strtolower(trim((string) config('ldap.host', '')));
        $baseDn = strtolower(trim((string) config('ldap.base_dn', '')));
        $port = $this->validLdapPort(config('ldap.port', 389)) ?? $effectivePort;
        if ($host === '' || $baseDn === '') {
            return null;
        }

        return 'auto:'.hash('sha256', $host."\0".$port."\0".$baseDn);
    }

    /**
     * Extract the immutable subject configured for identity binding. OpenLDAP commonly exposes
     * entryUUID; Active Directory deployments should configure objectGUID.
     *
     * @param  array<string, mixed>  $entry
     */
    private function identitySubject(array $entry): ?string
    {
        $attribute = trim((string) config('ldap.subject_attr', 'entryUUID'));
        if ($attribute === '' || strcasecmp($attribute, 'dn') === 0) {
            return null;
        }

        $subject = $this->attr($entry, $attribute);

        return $subject === '' ? null : $subject;
    }

    /**
     * Open the validated connection and establish its required encryption before any bind.
     */
    private function connect(): ?Connection
    {
        $transport = $this->transportConfiguration();
        if ($transport['error'] !== null) {
            Log::error($transport['error']);

            return null;
        }

        // PHP requires TLS options to be set globally before ldap_connect() for LDAPS. Applying
        // the same policy before StartTLS also prevents a process-level weak setting from leaking
        // into this request.
        $certificatePolicy = $transport['tls_verify']
            ? LDAP_OPT_X_TLS_DEMAND
            : LDAP_OPT_X_TLS_NEVER;
        if (! $this->setLdapOption(null, LDAP_OPT_X_TLS_REQUIRE_CERT, $certificatePolicy)) {
            Log::error('LDAP could not apply the TLS certificate verification policy.');

            return null;
        }

        $connection = $this->initializeLdapConnection($transport['uri']);
        if ($connection === false) {
            Log::error('LDAP endpoint URI is not accepted by the LDAP runtime.', [
                'uri' => $transport['uri'],
            ]);

            return null;
        }

        if (! $this->setLdapOption($connection, LDAP_OPT_PROTOCOL_VERSION, 3)
            || ! $this->setLdapOption($connection, LDAP_OPT_REFERRALS, 0)) {
            Log::error('LDAP could not apply required connection options.');
            $this->unbindLdap($connection);

            return null;
        }

        if ($transport['start_tls']) {
            if (! $this->startLdapTls($connection)) {
                Log::error('LDAP StartTLS failed: '.$this->ldapError($connection));
                $this->unbindLdap($connection);

                return null;
            }
        }

        return $connection;
    }

    /**
     * Bind using the configured service account.
     */
    private function bindServiceAccount(Connection $connection): bool
    {
        $bindDn = (string) config('ldap.bind_dn', '');
        $bindPassword = (string) config('ldap.bind_password', '');

        // An anonymous bind is allowed when no service account is configured.
        if ($bindDn === '') {
            return $this->bindLdap($connection);
        }

        if (! $this->bindLdap($connection, $bindDn, $bindPassword)) {
            Log::error('LDAP service-account bind failed: '.$this->ldapError($connection));

            return false;
        }

        return true;
    }

    /**
     * Low-level LDAP operations are isolated behind protected methods so transport policy and
     * operation ordering can be tested without placing credentials on a real network.
     */
    protected function initializeLdapConnection(string $uri): Connection|false
    {
        return @ldap_connect($uri);
    }

    protected function setLdapOption(
        ?Connection $connection,
        int $option,
        array|string|int|bool $value,
    ): bool {
        return @ldap_set_option($connection, $option, $value);
    }

    protected function startLdapTls(Connection $connection): bool
    {
        return @ldap_start_tls($connection);
    }

    protected function bindLdap(
        Connection $connection,
        ?string $dn = null,
        ?string $password = null,
    ): bool {
        return @ldap_bind($connection, $dn, $password);
    }

    protected function unbindLdap(Connection $connection): bool
    {
        return @ldap_unbind($connection);
    }

    protected function ldapError(Connection $connection): string
    {
        return ldap_error($connection);
    }

    /**
     * Search the base DN for the user with the configured filter. Returns the single matched
     * entry (associative, lowercased attribute keys + 'dn') or null when not exactly one match.
     *
     * @return array<string,mixed>|null
     */
    private function findUser(Connection $connection, string $username): ?array
    {
        $baseDn = (string) config('ldap.base_dn', '');
        $filterTemplate = (string) config('ldap.user_filter', '(uid=%s)');
        $filter = sprintf($filterTemplate, $this->escapeFilter($username));

        $attributes = [
            'dn',
            (string) config('ldap.username_attr', 'uid'),
            (string) config('ldap.email_attr', 'mail'),
            (string) config('ldap.displayname_attr', 'cn'),
            'memberof',
        ];
        $subjectAttribute = trim((string) config('ldap.subject_attr', 'entryUUID'));
        if ($subjectAttribute !== '' && strcasecmp($subjectAttribute, 'dn') !== 0) {
            $attributes[] = $subjectAttribute;
        }
        $attributes = array_values(array_unique($attributes));

        $search = @ldap_search($connection, $baseDn, $filter, $attributes);

        if ($search === false) {
            Log::error('LDAP search failed: '.ldap_error($connection));

            return null;
        }

        $entries = @ldap_get_entries($connection, $search);
        if (! is_array($entries) || ! isset($entries['count']) || (int) $entries['count'] !== 1) {
            return null;
        }

        return $entries[0];
    }

    /**
     * Pull a single attribute value from an ldap_get_entries() row (keys are lowercased).
     *
     * @param  array<string,mixed>  $entry
     */
    private function attr(array $entry, string $attribute): string
    {
        $key = strtolower($attribute);
        if (isset($entry[$key][0]) && is_string($entry[$key][0])) {
            return $entry[$key][0];
        }

        return '';
    }

    /**
     * Extract the user's group DNs from the memberOf attribute.
     *
     * @param  array<string,mixed>  $entry
     * @return array<int,string>
     */
    private function extractGroups(array $entry): array
    {
        $groups = [];
        if (isset($entry['memberof']) && is_array($entry['memberof'])) {
            $count = (int) ($entry['memberof']['count'] ?? 0);
            for ($i = 0; $i < $count; $i++) {
                if (isset($entry['memberof'][$i]) && is_string($entry['memberof'][$i])) {
                    $groups[] = $entry['memberof'][$i];
                }
            }
        }

        return $groups;
    }

    /**
     * Case-insensitive membership test against a group DN.
     *
     * @param  array<int,string>  $groups
     */
    private function inGroup(array $groups, string $groupDn): bool
    {
        foreach ($groups as $group) {
            if (strcasecmp($group, $groupDn) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Determine admin status from admin_group membership.
     *
     * @param  array<int,string>  $groups
     */
    private function isAdmin(array $groups): bool
    {
        $adminGroup = (string) config('ldap.admin_group', '');
        if ($adminGroup === '') {
            return false;
        }

        return $this->inGroup($groups, $adminGroup);
    }

    /**
     * Escape a value for safe inclusion in an LDAP filter (RFC 4515).
     */
    private function escapeFilter(string $value): string
    {
        if (function_exists('ldap_escape')) {
            return ldap_escape($value, '', LDAP_ESCAPE_FILTER);
        }

        return str_replace(
            ['\\', '*', '(', ')', "\x00"],
            ['\\5c', '\\2a', '\\28', '\\29', '\\00'],
            $value
        );
    }
}
