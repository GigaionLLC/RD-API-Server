<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\DeployToken;
use App\Models\Device;
use App\Services\AdminScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Deploy tokens and device approval: list/create/revoke the current user's deploy tokens,
 * and approve or reject devices awaiting approval.
 */
class DeployTokenController extends Controller
{
    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $this->scope->authorizeUserId($request->user(), (int) $request->user()->id, 'deploy.view');
        $tokens = DeployToken::query()
            ->where('user_id', Auth::id())
            ->orderByDesc('created_at')
            ->paginate(20);

        // A freshly generated token is flashed once so it can be shown to the admin.
        $newToken = $request->session()->get('new_token');

        return view('admin.deploy_tokens.index', compact('tokens', 'newToken'));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->scope->authorizeUserId($request->user(), (int) $request->user()->id, 'deploy.edit');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:255'],
            'expires_at' => ['nullable', 'date'],
        ]);

        $token = Str::random(48);

        DeployToken::create([
            'user_id' => Auth::id(),
            'credential_version' => max(1, (int) $request->user()->credential_version),
            'token' => $token,
            'name' => $data['name'] ?? null,
            'expires_at' => $data['expires_at'] ?? null,
        ]);

        return redirect()
            ->route('admin.deploy-tokens.index')
            ->with('status', 'Deploy token created.')
            ->with('new_token', $token);
    }

    public function destroy(Request $request, DeployToken $deployToken): RedirectResponse
    {
        $this->scope->authorizeUserId($request->user(), (int) $request->user()->id, 'deploy.edit');
        // Only the owner may revoke their own token.
        abort_unless($deployToken->user_id === Auth::id(), 403);

        $deployToken->delete();

        return redirect()
            ->route('admin.deploy-tokens.index')
            ->with('status', 'Deploy token revoked.');
    }

    /**
     * Devices awaiting approval (approved = false).
     */
    public function pending(Request $request): View
    {
        $devices = $this->scope->scopeDevices(Device::query(), $request->user(), 'deploy.view')
            ->with('user:id,username')
            ->where('approved', false)
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('admin.deploy_tokens.pending', compact('devices'));
    }

    public function approve(Request $request, Device $device): RedirectResponse
    {
        $this->scope->authorizeDevice($request->user(), $device, 'deploy.edit');
        $device->forceFill(['approved' => true])->save();

        return redirect()
            ->route('admin.devices.pending')
            ->with('status', 'Device approved.');
    }

    public function reject(Request $request, Device $device): RedirectResponse
    {
        $this->scope->authorizeDevice($request->user(), $device, 'deploy.edit');
        $device->delete();

        return redirect()
            ->route('admin.devices.pending')
            ->with('status', 'Device rejected.');
    }
}
