<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Concerns\ExportsCsv;
use App\Http\Controllers\Controller;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\LoginLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Read-only audit views: connection, file-transfer, and login logs, each with a CSV export
 * that honours the current search filter.
 */
class AuditController extends Controller
{
    use ExportsCsv;

    public function connections(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $action = $request->query('action');

        $logs = $this->connectionsQuery($q, is_string($action) ? $action : null)
            ->paginate(30)
            ->appends($request->query());

        return view('admin.audit.connections', compact('logs', 'q', 'action'));
    }

    public function exportConnections(Request $request): StreamedResponse
    {
        $action = $request->query('action');
        $query = $this->connectionsQuery(trim((string) $request->query('q', '')), is_string($action) ? $action : null);

        return $this->streamCsv('connection-audit', [
            'time', 'action', 'peer_id', 'from_peer', 'from_name', 'ip', 'session_id', 'conn_id',
            'primary_auth', 'two_factor', 'conn_audit_ref', 'note',
        ], $query, fn (AuditConn $r): array => [
            (string) $r->created_at, $r->action, $r->peer_id, $r->from_peer, $r->from_name,
            $r->ip, $r->session_id, $r->conn_id,
            $r->primaryAuthLabel(), $r->twoFactorLabel(), $r->conn_audit_ref, $r->note,
        ]);
    }

    public function files(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $logs = $this->filesQuery($q)->paginate(30)->appends($request->query());

        return view('admin.audit.files', compact('logs', 'q'));
    }

    public function exportFiles(Request $request): StreamedResponse
    {
        $query = $this->filesQuery(trim((string) $request->query('q', '')));

        return $this->streamCsv('file-audit', [
            'time', 'peer_id', 'from_peer', 'from_name', 'info', 'is_file', 'path', 'type', 'ip', 'num',
        ], $query, fn (AuditFile $r): array => [
            (string) $r->created_at, $r->peer_id, $r->from_peer, $r->from_name, $r->info,
            $r->is_file ? 'file' : 'dir', $r->path, $r->type, $r->ip, $r->num,
        ]);
    }

    public function logins(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));

        $logs = $this->loginsQuery($q)->paginate(30)->appends($request->query());

        return view('admin.audit.logins', compact('logs', 'q'));
    }

    public function exportLogins(Request $request): StreamedResponse
    {
        $query = $this->loginsQuery(trim((string) $request->query('q', '')));

        return $this->streamCsv('login-audit', [
            'time', 'user', 'client', 'device_id', 'platform', 'ip',
        ], $query, fn (LoginLog $r): array => [
            (string) $r->created_at, $r->user->username ?? '', $r->client, $r->device_id, $r->platform, $r->ip,
        ]);
    }

    // --- Query builders (shared by the view + CSV export) -------------------------------

    /**
     * @return Builder<AuditConn>
     */
    private function connectionsQuery(string $q, ?string $action): Builder
    {
        return AuditConn::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('peer_id', 'like', "%{$q}%")
                ->orWhere('from_peer', 'like', "%{$q}%")
                ->orWhere('from_name', 'like', "%{$q}%")
                ->orWhere('ip', 'like', "%{$q}%")))
            ->when(in_array($action, [AuditConn::ACTION_NEW, AuditConn::ACTION_CLOSE], true),
                fn (Builder $query) => $query->where('action', $action))
            ->orderByDesc('created_at');
    }

    /**
     * @return Builder<AuditFile>
     */
    private function filesQuery(string $q): Builder
    {
        return AuditFile::query()
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('peer_id', 'like', "%{$q}%")
                ->orWhere('from_peer', 'like', "%{$q}%")
                ->orWhere('from_name', 'like', "%{$q}%")
                ->orWhere('path', 'like', "%{$q}%")
                ->orWhere('ip', 'like', "%{$q}%")))
            ->orderByDesc('created_at');
    }

    /**
     * @return Builder<LoginLog>
     */
    private function loginsQuery(string $q): Builder
    {
        return LoginLog::query()
            ->with('user:id,username')
            ->when($q !== '', fn (Builder $query) => $query->where(fn (Builder $w) => $w
                ->where('client', 'like', "%{$q}%")
                ->orWhere('device_id', 'like', "%{$q}%")
                ->orWhere('ip', 'like', "%{$q}%")
                ->orWhere('platform', 'like', "%{$q}%")))
            ->orderByDesc('created_at');
    }
}
