<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditConn;
use App\Models\Device;
use App\Models\User;
use App\Services\AdminScopeService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Live sessions = currently-open connections, derived from the audit stream (a "new" event
 * with no later matching "close"). Admins can force-disconnect a session: the connection id is
 * queued in the cache and delivered to the controlled device on its next heartbeat
 * (SystemController returns `disconnect: [...]`, which the RustDesk client honors).
 */
class SessionController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $sessions = $this->activeSessionsQuery($request->user(), 'sessions.view')
            ->orderByDesc('id')
            ->paginate(20);

        // Map peer ids to device hostnames for display.
        $hostnames = Device::whereIn('rustdesk_id', $sessions->pluck('peer_id')->unique())
            ->pluck('hostname', 'rustdesk_id');

        return view('admin.sessions.index', compact('sessions', 'hostnames'));
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'peer_id' => ['required', 'string'],
            'conn_id' => ['required', 'integer'],
        ]);

        abort_unless($this->activeSessionsQuery($request->user(), 'sessions.edit')
            ->where('peer_id', $data['peer_id'])
            ->where('conn_id', $data['conn_id'])
            ->exists(), 403, 'This session is outside your administrative scope or is no longer active.');

        $key = 'rd:disconnect:'.$data['peer_id'];
        $queued = (array) Cache::get($key, []);
        $queued[] = (int) $data['conn_id'];
        Cache::put($key, array_values(array_unique($queued)), now()->addMinutes(5));

        return back()->with('status', 'Disconnect requested — it will apply on the device\'s next heartbeat.');
    }

    /** @return Builder<AuditConn> */
    private function activeSessionsQuery(User $actor, string $permission): Builder
    {
        return $this->scope->scopePeerRecords(AuditConn::query(), $actor, $permission)
            ->where('action', AuditConn::ACTION_NEW)
            ->whereNotExists(function ($query): void {
                $query->select(DB::raw(1))
                    ->from('audit_conns as c2')
                    ->whereColumn('c2.peer_id', 'audit_conns.peer_id')
                    ->whereColumn('c2.conn_id', 'audit_conns.conn_id')
                    ->where('c2.action', AuditConn::ACTION_CLOSE)
                    ->whereColumn('c2.id', '>', 'audit_conns.id');
            });
    }
}
