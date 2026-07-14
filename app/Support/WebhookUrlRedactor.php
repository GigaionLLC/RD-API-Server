<?php

namespace App\Support;

/**
 * Produces safe webhook labels and transport errors without changing the raw destination used
 * for delivery. Webhook URLs commonly carry credentials in userinfo, path segments, query
 * values, or fragments, so administrative list/history surfaces must never render them whole.
 */
final class WebhookUrlRedactor
{
    public const MASK = '[redacted]';

    public const HIDDEN_URL = '[redacted webhook URL]';

    /**
     * Return a useful origin and webhook-kind hint while hiding every credential-bearing URL
     * component. The optional type avoids relying on a vendor hostname for Slack/Telegram URLs.
     */
    public static function redact(string $url, ?string $type = null): string
    {
        $parts = parse_url(trim($url));

        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return self::HIDDEN_URL;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['http', 'https'], true)) {
            return self::HIDDEN_URL;
        }

        $host = (string) $parts['host'];
        if ($host === '') {
            return self::HIDDEN_URL;
        }

        $origin = $scheme.'://'.$host;
        if (isset($parts['port'])) {
            $origin .= ':'.(int) $parts['port'];
        }

        $path = (string) ($parts['path'] ?? '');
        $safePath = self::redactPath($path, $type ?? self::inferType($host, $path));
        $safeQuery = array_key_exists('query', $parts) ? '?'.self::MASK : '';
        $safeFragment = array_key_exists('fragment', $parts) ? '#'.self::MASK : '';

        return $origin.$safePath.$safeQuery.$safeFragment;
    }

    /**
     * Remove a webhook's raw URL/secret and any additional HTTP URLs from an error or log line.
     */
    public static function redactText(
        string $text,
        ?string $sensitiveUrl = null,
        ?string $secret = null,
        ?string $type = null
    ): string {
        if ($text === '') {
            return '';
        }

        if ($sensitiveUrl !== null && $sensitiveUrl !== '') {
            $text = str_replace($sensitiveUrl, self::redact($sensitiveUrl, $type), $text);
        }

        if ($secret !== null && $secret !== '') {
            $text = str_replace($secret, self::MASK, $text);
        }

        return preg_replace_callback(
            '~https?://[^\s<>"\']+~iu',
            static function (array $matches): string {
                $url = $matches[0];
                $trailing = '';

                while ($url !== '' && str_contains('.,;:!?)]}', substr($url, -1))) {
                    $trailing = substr($url, -1).$trailing;
                    $url = substr($url, 0, -1);
                }

                return self::redact($url).$trailing;
            },
            $text
        ) ?? self::MASK;
    }

    private static function redactPath(string $path, ?string $type): string
    {
        if ($path === '' || $path === '/') {
            return $path;
        }

        if ($type === 'slack' && str_starts_with(strtolower($path), '/services/')) {
            return '/services/'.self::MASK;
        }

        if ($type === 'telegram' && preg_match('~^/bot[^/]+/sendMessage/?$~i', $path) === 1) {
            return '/bot'.self::MASK.'/sendMessage';
        }

        return '/'.self::MASK;
    }

    private static function inferType(string $host, string $path): ?string
    {
        $host = strtolower(rtrim($host, '.'));

        if (($host === 'hooks.slack.com' || str_ends_with($host, '.slack.com'))
            && str_starts_with(strtolower($path), '/services/')) {
            return 'slack';
        }

        if ($host === 'api.telegram.org' && str_starts_with(strtolower($path), '/bot')) {
            return 'telegram';
        }

        return null;
    }
}
