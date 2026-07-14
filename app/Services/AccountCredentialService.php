<?php

namespace App\Services;

use App\Models\ApiKey;
use App\Models\AuthToken;
use App\Models\DeployToken;
use App\Models\LdapIdentity;
use App\Models\User;
use App\Models\UserThird;
use App\Models\VerifyCode;
use App\Support\AccountPasswordPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Replaces an account password and invalidates every credential derived from the old one.
 *
 * The monotonically increasing credential version is the race-resistant boundary: even if
 * an in-flight request writes a token after this transaction, that token captured the old
 * version and authentication middleware rejects it.
 */
final class AccountCredentialService
{
    public const FEDERATED_IDENTITY_MESSAGE = 'Linked LDAP and SSO identities cannot receive a local password. Reset access at the identity provider or use an explicit unlink/conversion flow.';

    /**
     * @param  list<string|null>  $previousEmails
     */
    public function replacePassword(User $user, string $password, array $previousEmails = []): User
    {
        AccountPasswordPolicy::assertValid($password);

        $updated = DB::transaction(function () use ($user, $password, $previousEmails): User {
            /** @var User $locked */
            $locked = User::query()->lockForUpdate()->findOrFail($user->getKey());
            if ($this->hasFederatedIdentity($locked)) {
                throw ValidationException::withMessages([
                    'password' => self::FEDERATED_IDENTITY_MESSAGE,
                ]);
            }

            $nextVersion = max(1, (int) $locked->credential_version) + 1;
            $emails = $this->resetTokenEmails($locked, $previousEmails);

            $locked->forceFill([
                'password' => $password,
                'credential_version' => $nextVersion,
                'remember_token' => Str::random(60),
            ])->save();

            AuthToken::query()
                ->where('user_id', $locked->id)
                ->where('status', AuthToken::STATUS_ACTIVE)
                ->update(['status' => AuthToken::STATUS_REVOKED]);

            VerifyCode::query()
                ->where('user_id', $locked->id)
                ->where('status', VerifyCode::STATUS_ACTIVE)
                ->update(['status' => VerifyCode::STATUS_INACTIVE]);

            ApiKey::query()->where('user_id', $locked->id)->delete();
            DeployToken::query()->where('user_id', $locked->id)->delete();

            if ($emails !== []) {
                DB::table($this->passwordResetTable())->whereIn('email', $emails)->delete();
            }

            return $locked;
        });

        $this->scheduleDatabaseSessionCleanup((int) $updated->id);

        return $updated;
    }

    /**
     * @param  list<string|null>  $previousEmails
     * @return list<string>
     */
    private function resetTokenEmails(User $user, array $previousEmails): array
    {
        $emails = [...$previousEmails, $user->email];

        return array_values(array_unique(array_filter(array_map(
            static fn ($email): string => trim((string) $email),
            $emails,
        ), static fn (string $email): bool => $email !== '')));
    }

    private function passwordResetTable(): string
    {
        $broker = (string) config('auth.defaults.passwords', 'users');

        return (string) config("auth.passwords.{$broker}.table", 'password_reset_tokens');
    }

    private function hasFederatedIdentity(User $user): bool
    {
        return LdapIdentity::query()->where('user_id', $user->id)->exists()
            || UserThird::query()->where('user_id', $user->id)->exists();
    }

    private function deleteDatabaseSessions(int $userId): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }

        $connection = config('session.connection');
        $connection = is_string($connection) && $connection !== '' ? $connection : null;
        $table = (string) config('session.table', 'sessions');

        DB::connection($connection)->table($table)->where('user_id', $userId)->delete();
    }

    private function scheduleDatabaseSessionCleanup(int $userId): void
    {
        $cleanup = function () use ($userId): void {
            try {
                $this->deleteDatabaseSessions($userId);
            } catch (Throwable $exception) {
                // The version boundary already invalidated every browser session. Physical
                // cleanup is only hygiene and must not turn a committed reset into an error.
                Log::warning('Could not delete superseded database sessions after a password reset.', [
                    'user_id' => $userId,
                    'error' => $exception->getMessage(),
                ]);
            }
        };

        $sessionConnection = config('session.connection');
        $usesSeparateConnection = is_string($sessionConnection)
            && $sessionConnection !== ''
            && $sessionConnection !== DB::getDefaultConnection();

        if ($usesSeparateConnection) {
            DB::afterCommit($cleanup);

            return;
        }

        // The default session table participates in the same outer transaction, so deleting
        // it now remains atomic. A separate session database must wait for the outer commit.
        $cleanup();
    }
}
