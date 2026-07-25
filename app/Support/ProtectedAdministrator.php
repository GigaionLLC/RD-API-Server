<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The break-glass administrator: the one account no external system can take away.
 *
 * Every trust root in this application is ultimately someone else's: a directory can stop
 * answering, an OIDC provider can be misconfigured, a group can be renamed, and any of those
 * removes an operator's access. This flag marks one account as outside all of it, so there is
 * always a way back into a console whose identity provider is wrong.
 *
 * It confers no permission. `is_admin` already grants unconditional access; this only refuses the
 * writes that would take that access away.
 */
final class ProtectedAdministrator
{
    /**
     * Every reason the protected account refuses a change, keyed by the field being written.
     */
    public const REFUSALS = [
        'is_admin' => 'The break-glass administrator cannot be demoted. Transfer the designation first with `php artisan rustdesk:admin:transfer`.',
        'status' => 'The break-glass administrator cannot be disabled. Transfer the designation first with `php artisan rustdesk:admin:transfer`.',
        'force_sso' => 'The break-glass administrator must keep local password sign-in, which is the point of it.',
        'delete' => 'The break-glass administrator cannot be deleted. Transfer the designation first with `php artisan rustdesk:admin:transfer`.',
        'link' => 'The break-glass administrator cannot be linked to an external identity, because a linked account cannot receive a local password.',
    ];

    public static function current(): ?User
    {
        return User::where('is_protected_admin', true)->first();
    }

    public static function isProtected(?User $user): bool
    {
        return $user !== null && (bool) $user->is_protected_admin;
    }

    /**
     * @param  array<int, int|string>  $userIds
     * @return list<int>
     */
    public static function idsWithin(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::whereIn('id', $userIds)
            ->where('is_protected_admin', true)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * Move the designation, or clear it, as one atomic step.
     *
     * The unique index makes "two protected accounts" impossible, so the clear must land before the
     * set. Doing it in a transaction keeps a crash between the two from leaving an installation
     * with no break-glass account at all.
     */
    public static function designate(?User $user): void
    {
        DB::transaction(function () use ($user): void {
            User::where('is_protected_admin', true)->update(['is_protected_admin' => false]);

            if ($user !== null) {
                User::whereKey($user->getKey())->update(['is_protected_admin' => true]);
            }
        });
    }
}
