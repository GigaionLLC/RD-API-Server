<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Alarm;
use App\Services\AdminScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Read-only alarm log: paginated list of raised alarms with a type filter and delete.
 */
class AlarmController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $type = trim((string) $request->query('type', ''));

        $alarms = $this->scope->scopeDeviceLinkedRecords(
            Alarm::query(),
            $request->user(),
            'alarms.view',
        )
            ->with('device:id,rustdesk_id,hostname,alias')
            ->when($type !== '', fn ($query) => $query->where('type', $type))
            ->orderByDesc('created_at')
            ->paginate(30)
            ->appends($request->query());

        // Distinct types for the filter dropdown.
        $types = $this->scope->scopeDeviceLinkedRecords(
            Alarm::query(),
            $request->user(),
            'alarms.view',
        )->distinct()->orderBy('type')->pluck('type');

        return view('admin.alarms.index', compact('alarms', 'type', 'types'));
    }

    public function destroy(Request $request, Alarm $alarm): RedirectResponse
    {
        $this->scope->authorizeDeviceLinkedRecord(
            $request->user(),
            $alarm->device_id === null ? null : (int) $alarm->device_id,
            (string) $alarm->peer_id,
            'alarms.edit',
        );
        $alarm->delete();

        return redirect()
            ->route('admin.alarms.index')
            ->with('status', 'Alarm deleted.');
    }
}
