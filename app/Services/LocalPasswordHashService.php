<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * Upgrades a verified legacy password hash without racing a real password replacement.
 */
final class LocalPasswordHashService
{
    /**
     * Return false when another request changed the stored password before the upgrade won.
     */
    public function upgradeIfNeeded(User $user, #[SensitiveParameter] string $password): bool
    {
        $column = $user->getAuthPasswordName();
        $storedHash = (string) $user->getAuthPassword();

        if (! Hash::needsRehash($storedHash)) {
            return true;
        }

        $updated = User::query()
            ->whereKey($user->getKey())
            ->where($column, $storedHash)
            ->update([$column => Hash::make($password)]);

        if ($updated !== 1) {
            return false;
        }

        $user->refresh();

        return true;
    }
}
