<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminRole;
use App\Models\OauthProvider;
use App\Models\SsoRoleMapping;
use App\Models\SsoRoleSyncLog;
use App\Services\LdapService;
use App\Support\SsoGroupKey;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Manages "identity-provider group grants console role" declarations.
 *
 * Authoring a mapping decides who becomes an administrator, which is the same escalation surface
 * as rewriting a role. Mutation is therefore restricted to full administrators exactly as
 * AdminRoleController restricts role mutation; delegates with `sso_mappings.view` may inspect
 * the configuration but not change it.
 */
class SsoRoleMappingController extends Controller
{
    public function __construct(private readonly LdapService $ldap) {}

    public function index(Request $request): View
    {
        $mappings = SsoRoleMapping::with('role')
            ->orderBy('provider_kind')
            ->orderBy('provider_key')
            ->orderBy('group_value')
            ->paginate(20);

        $canEdit = (bool) $request->user()?->is_admin;

        return view('admin.sso_role_mappings.index', [
            'mappings' => $mappings,
            'canEdit' => $canEdit,
            'allowGlobal' => $this->allowsGlobalRoles(),
            // The panel names arbitrary accounts and their granted roles, which a group- or
            // user-scoped delegate has no business reading. Full administrators only.
            'recentSyncs' => $canEdit
                ? SsoRoleSyncLog::orderByDesc('id')->limit(10)->get()
                : collect(),
        ]);
    }

    public function create(Request $request): View
    {
        $this->authorizeMappingMutation($request);

        return view('admin.sso_role_mappings.create', $this->formData(new SsoRoleMapping([
            'provider_kind' => SsoRoleMapping::KIND_OIDC,
            'enabled' => true,
        ])));
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeMappingMutation($request);

        $mapping = new SsoRoleMapping($this->validated($request));
        $mapping->save();

        return redirect()->route('admin.sso-role-mappings.index')
            ->with('status', 'Mapping created. It applies at each affected user\'s next sign-in.');
    }

    public function edit(Request $request, SsoRoleMapping $ssoRoleMapping): View
    {
        return view('admin.sso_role_mappings.edit', $this->formData($ssoRoleMapping));
    }

    public function update(Request $request, SsoRoleMapping $ssoRoleMapping): RedirectResponse
    {
        $this->authorizeMappingMutation($request);

        // Captured before the write: save() calls syncOriginal(), after which getOriginal()
        // returns the new values and the previous target would be unrecoverable.
        $previousOrigin = $ssoRoleMapping->origin();
        $previousRoleId = (int) $ssoRoleMapping->admin_role_id;

        $ssoRoleMapping->fill($this->validated($request, $ssoRoleMapping))->save();

        $this->revokeOrphanedGrants($previousOrigin, $previousRoleId, (int) $ssoRoleMapping->getKey());

        return redirect()->route('admin.sso-role-mappings.index')
            ->with('status', 'Mapping updated. Roles it no longer justifies were revoked.');
    }

    public function destroy(Request $request, SsoRoleMapping $ssoRoleMapping): RedirectResponse
    {
        $this->authorizeMappingMutation($request);

        $origin = $ssoRoleMapping->origin();
        $roleId = (int) $ssoRoleMapping->admin_role_id;

        // Revocation otherwise waits for a sign-in that may never come.
        $ssoRoleMapping->delete();
        $this->revokeOrphanedGrants($origin, $roleId, null);

        return redirect()->route('admin.sso-role-mappings.index')
            ->with('status', 'Mapping deleted and the roles it granted were revoked.');
    }

    /**
     * Drop a provider's grants of a role that no enabled mapping justifies any more.
     *
     * Two providers, or two groups within one provider, can legitimately grant the same role, so
     * removing one mapping must not strip a grant another still earns. Anything still justified
     * is therefore left standing, and only the provider's own grants are ever considered:
     * manually assigned roles and other providers' grants are untouched.
     */
    private function revokeOrphanedGrants(string $origin, int $roleId, ?int $excludedMappingId): void
    {
        if ($roleId <= 0) {
            return;
        }

        [, $providerKind, $providerKey] = array_pad(explode(':', $origin, 3), 3, '');

        $stillJustified = SsoRoleMapping::query()
            ->forProvider($providerKind, $providerKey)
            ->where('admin_role_id', $roleId)
            ->where('enabled', true)
            ->when($excludedMappingId !== null, static fn ($query) => $query->whereKeyNot($excludedMappingId))
            ->exists();

        if ($stillJustified) {
            return;
        }

        DB::table('admin_role_user')
            ->where('origin', $origin)
            ->where('admin_role_id', $roleId)
            ->delete();
    }

    /**
     * @return array<string, mixed>
     */
    private function validated(Request $request, ?SsoRoleMapping $existing = null): array
    {
        $validated = $request->validate([
            'provider_kind' => ['required', Rule::in(SsoRoleMapping::KINDS)],
            'provider_key' => ['required', 'string', 'max:191'],
            'group_value' => ['required', 'string', 'max:'.SsoGroupKey::MAX_LENGTH],
            'admin_role_id' => ['required', 'integer', 'exists:admin_roles,id'],
            'enabled' => ['nullable', 'boolean'],
        ], [], [
            'provider_key' => 'provider',
            'group_value' => 'group',
            'admin_role_id' => 'role',
        ]);

        $group = trim((string) $validated['group_value']);
        if (! SsoGroupKey::isUsable($group)) {
            throw ValidationException::withMessages([
                'group_value' => 'That group value cannot be used as a mapping key.',
            ]);
        }

        $role = AdminRole::findOrFail((int) $validated['admin_role_id']);
        if ($role->type === AdminRole::TYPE_GLOBAL && ! $this->allowsGlobalRoles()) {
            throw ValidationException::withMessages([
                'admin_role_id' => 'Mapping a group to a global role requires SSO_ROLE_MAPPING_ALLOW_GLOBAL=true on the server.',
            ]);
        }

        $kind = (string) $validated['provider_kind'];
        $key = trim((string) $validated['provider_key']);

        $duplicate = SsoRoleMapping::query()
            ->forProvider($kind, $key)
            ->where('group_key', SsoGroupKey::digest($kind, $group))
            ->where('admin_role_id', $role->id)
            ->when($existing !== null, static fn ($q) => $q->whereKeyNot($existing?->getKey()))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages([
                'group_value' => 'That group already grants this role for this provider.',
            ]);
        }

        return [
            'provider_kind' => $kind,
            'provider_key' => $key,
            'group_value' => $group,
            'group_key' => SsoGroupKey::digest($kind, $group),
            'admin_role_id' => $role->id,
            'enabled' => (bool) ($validated['enabled'] ?? false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(SsoRoleMapping $mapping): array
    {
        return [
            'mapping' => $mapping,
            'roles' => AdminRole::orderBy('name')->get(['id', 'name', 'type']),
            'oidcProviders' => OauthProvider::orderBy('op')->get(['op', 'type', 'groups_claim']),
            'ldapProviderKey' => $this->ldap->enabled() ? $this->ldap->identityProviderKey() : '',
            'allowGlobal' => $this->allowsGlobalRoles(),
        ];
    }

    private function allowsGlobalRoles(): bool
    {
        return (bool) config('rustdesk.sso_role_mapping.allow_global_roles', false);
    }

    private function authorizeMappingMutation(Request $request): void
    {
        // Identical to AdminRoleController: an editor who could author a mapping could point it
        // at a role more privileged than their own and then simply sign in through the provider.
        if (! $request->user()?->is_admin) {
            abort(403, 'Only a full administrator may modify SSO role mappings.');
        }
    }
}
