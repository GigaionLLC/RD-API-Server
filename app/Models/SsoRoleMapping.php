<?php

namespace App\Models;

use App\Support\SsoGroupKey;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * "Membership of this identity-provider group grants this console role."
 *
 * A mapping is a federated authority grant: it hands an external system the ability to decide
 * who may administer this console. It therefore never targets `users.is_admin`, only an
 * AdminRole, and it is always scoped to one provider.
 */
#[Fillable(['provider_kind', 'provider_key', 'group_value', 'group_key', 'admin_role_id', 'enabled'])]
class SsoRoleMapping extends Model
{
    use HasFactory;

    public const KIND_LDAP = SsoGroupKey::KIND_LDAP;

    public const KIND_OIDC = SsoGroupKey::KIND_OIDC;

    /** @var list<string> */
    public const KINDS = [self::KIND_LDAP, self::KIND_OIDC];

    /**
     * The provenance marker written onto every grant this mapping produces, so a login
     * reconciles only what its own provider owns.
     */
    public static function originFor(string $providerKind, string $providerKey): string
    {
        return 'idp:'.$providerKind.':'.$providerKey;
    }

    public function origin(): string
    {
        return self::originFor((string) $this->provider_kind, (string) $this->provider_key);
    }

    /**
     * @return BelongsTo<AdminRole, $this>
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(AdminRole::class, 'admin_role_id');
    }

    /**
     * @param  Builder<SsoRoleMapping>  $query
     * @return Builder<SsoRoleMapping>
     */
    public function scopeForProvider(Builder $query, string $providerKind, string $providerKey): Builder
    {
        return $query->where('provider_kind', $providerKind)->where('provider_key', $providerKey);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }
}
