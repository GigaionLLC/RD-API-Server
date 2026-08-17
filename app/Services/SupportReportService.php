<?php

namespace App\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/**
 * Builds the report an operator attaches to a bug report.
 *
 * The point is to make a *useful* issue cheap to file. Most reports arrive as "the remote
 * desktop doesn't work", and the first four exchanges are always the same questions:
 * which version, which PHP, is it behind a proxy, what does the log say. This answers all
 * of them in one paste.
 *
 * Everything it produces goes through LogRedactor, and the operator sees the finished text
 * before it leaves their machine. That ordering is deliberate — redaction is a large
 * reduction in what escapes, not a guarantee, and the last check has to be a human who
 * knows their own deployment.
 *
 * Nothing here reads a secret in order to redact it: values that are secret by name are
 * never fetched, only their presence is reported.
 */
class SupportReportService
{
    public function __construct(
        private readonly WebClientDiagnosticsService $diagnostics,
        private readonly ServerKeyService $serverKeys,
    ) {}

    /** Log lines to include. Enough for a stack trace and its lead-up, not a day's traffic. */
    private const LOG_LINES = 400;

    public function build(Request $request): string
    {
        $redactor = new LogRedactor;

        $body = implode("\n", [
            $this->header(),
            $this->environment(),
            $this->configuration(),
            $this->remoteDesktop($request),
            $this->database(),
            $this->logs(),
        ]);

        $redacted = $redactor->redact($body);

        // The summary goes on after redaction so its own counts are not redacted, and so a
        // reader can see that redaction ran rather than inferring it from absence.
        return $redacted."\n".$this->redactionSummary($redactor);
    }

    private function header(): string
    {
        return implode("\n", [
            '# RD-API-Server support report',
            '',
            'Generated: '.now()->toIso8601String(),
            '',
            'Identifying values have been replaced with placeholders such as `<host-1>` and',
            '`<ip-1>`. The same value always gets the same placeholder, so lines about one',
            'machine can still be followed. Read this through before posting it.',
            '',
        ]);
    }

    private function environment(): string
    {
        return $this->section('Environment', [
            'Application' => config('app.version', 'unknown'),
            'Laravel' => app()->version(),
            'PHP' => PHP_VERSION,
            'OS' => PHP_OS_FAMILY.' '.php_uname('r'),
            'Server' => $_SERVER['SERVER_SOFTWARE'] ?? 'unknown',
            'Environment' => (string) config('app.env'),
            'Debug mode' => config('app.debug') ? 'ON — should be off in production' : 'off',
            'Timezone' => (string) config('app.timezone'),
        ]);
    }

    /**
     * Configuration that shapes behaviour — never the values of secrets.
     *
     * "Set" or "not set" answers the question a maintainer actually has, and cannot leak
     * anything by being wrong about what to hide.
     */
    private function configuration(): string
    {
        $key = $this->serverKeys;

        return $this->section('Configuration', [
            'APP_URL scheme' => str_starts_with((string) config('app.url'), 'https://') ? 'https' : 'http',
            'Trusted proxies' => config('trustedproxy.proxies') ? 'configured' : 'not configured',
            'ID server' => config('rustdesk.id_server') ? 'set' : 'NOT SET',
            'Relay server' => config('rustdesk.relay_server') ? 'set' : 'NOT SET',
            'Server key' => match (true) {
                ! $key->isConfigured() => 'not configured',
                $key->isMalformed() => 'MALFORMED',
                $key->isPrivate() => 'PRIVATE KEY CONFIGURED — should be the public half',
                default => 'public key, valid',
            },
            'WebSocket URLs' => config('rustdesk.web_client.ws_id_url') ? 'set' : 'not set',
            'WebSocket upstreams' => config('rustdesk.web_client.ws_id_upstream') ? 'set' : 'not set',
            'Device enrollment' => config('rustdesk.devices.require_deployment') ? 'requires token' : 'open',
            'Mail transport' => (string) config('mail.default'),
            'Queue' => (string) config('queue.default'),
            'Cache' => (string) config('cache.default'),
            'Session driver' => (string) config('session.driver'),
        ]);
    }

    private function remoteDesktop(Request $request): string
    {
        $report = $this->diagnostics->report($request);
        $rows = ['Overall' => strtoupper($report['status']), 'Transport' => $report['transport']];

        foreach ($report['checks'] as $check) {
            $rows[$check['label']] = strtoupper($check['status']).' — '.$check['detail'];
        }

        return $this->section('Browser remote desktop', $rows);
    }

    private function database(): string
    {
        try {
            $version = (string) DB::selectOne('select version() as v')?->v;
            $driver = (string) DB::connection()->getDriverName();
            $migrations = DB::table('migrations')->count();
            $devices = DB::table('devices')->count();
            $users = DB::table('users')->count();
        } catch (\Throwable $e) {
            return $this->section('Database', ['Status' => 'unreachable: '.$e->getMessage()]);
        }

        return $this->section('Database', [
            'Driver' => $driver,
            'Version' => $version,
            'Migrations applied' => (string) $migrations,
            // Counts describe scale, which is often the whole explanation for a report
            // about slowness, and identify nobody.
            'Devices' => (string) $devices,
            'Users' => (string) $users,
        ]);
    }

    private function logs(): string
    {
        $path = storage_path('logs/laravel.log');

        if (! File::isFile($path)) {
            return "\n## Recent log\n\nNo log file at storage/logs/laravel.log.\n";
        }

        $lines = $this->tail($path, self::LOG_LINES);

        return "\n## Recent log (last ".count($lines)." lines)\n\n```\n".implode("\n", $lines)."\n```\n";
    }

    /**
     * The last N lines, read from the end.
     *
     * A log on a busy deployment is far too large to load in order to throw most of it
     * away, and this runs while an operator waits.
     *
     * @return array<int, string>
     */
    private function tail(string $path, int $lines): array
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['(log file could not be opened)'];
        }

        $buffer = '';
        $chunk = 8192;
        fseek($handle, 0, SEEK_END);
        $position = ftell($handle);

        while ($position > 0 && substr_count($buffer, "\n") <= $lines) {
            $read = (int) min($chunk, $position);
            $position -= $read;
            fseek($handle, $position);
            $buffer = fread($handle, $read).$buffer;
        }
        fclose($handle);

        $all = explode("\n", rtrim($buffer, "\n"));

        return array_slice($all, -$lines);
    }

    /** @param array<string, string> $rows */
    private function section(string $title, array $rows): string
    {
        $out = "\n## {$title}\n\n";
        foreach ($rows as $label => $value) {
            $out .= '- **'.$label.'**: '.$value."\n";
        }

        return $out;
    }

    private function redactionSummary(LogRedactor $redactor): string
    {
        $summary = $redactor->summary();
        if ($summary === []) {
            return "\n## Redaction\n\nNothing matched a redaction rule.\n";
        }

        $parts = [];
        foreach ($summary as $kind => $count) {
            $parts[] = $count.' '.$kind.($count === 1 ? '' : 's');
        }

        return "\n## Redaction\n\nReplaced with placeholders: ".implode(', ', $parts).".\n";
    }
}
