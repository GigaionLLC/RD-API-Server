<?php

namespace Tests\Unit;

use App\Support\BootstrapAdminCredentials;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class BootstrapAdminCredentialsTest extends TestCase
{
    public function test_production_accepts_a_long_unique_passphrase_without_composition_rules(): void
    {
        $password = 'correct-horse-battery-staple';

        $this->assertSame(
            $password,
            BootstrapAdminCredentials::resolvePassword($password, 'admin', true),
        );
    }

    #[DataProvider('unsafeProductionPasswords')]
    public function test_production_rejects_missing_known_or_weak_passwords(?string $password): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Production bootstrap refused');

        BootstrapAdminCredentials::resolvePassword($password, 'fleet-admin', true);
    }

    /** @return array<string, array{0: string|null}> */
    public static function unsafeProductionPasswords(): array
    {
        return [
            'missing' => [null],
            'blank' => ['   '],
            'too short' => ['short-pass'],
            'known default' => ['admin123456'],
            'documented placeholder' => ['CHANGE_ME_admin_password'],
            'former quickstart placeholder' => ['choose-a-strong-password'],
            'generic placeholder' => ['replace-me-with-a-unique-password'],
            'generated-password placeholder' => ['generate-a-strong-password-here'],
            'angle bracket placeholder' => ['<unique admin password of at least 12 characters>'],
            'repeated character' => ['aaaaaaaaaaaaaaaa'],
            'repeated pattern' => ['abcabcabcabc'],
            'digits only' => ['1234567890123456'],
            'repeated common word' => ['passwordpassword'],
            'repeated username' => ['fleet-admin-fleet-admin'],
            'username plus year' => ['fleet-admin-2026'],
        ];
    }

    public function test_local_and_test_bootstraps_keep_the_development_default(): void
    {
        $this->assertSame(
            BootstrapAdminCredentials::DEVELOPMENT_PASSWORD,
            BootstrapAdminCredentials::resolvePassword(null, 'admin', false),
        );

        $this->assertSame(
            'local',
            BootstrapAdminCredentials::resolvePassword('local', 'admin', false),
        );
    }

    public function test_validation_errors_do_not_echo_the_supplied_password(): void
    {
        $password = 'replace-me-super-secret';

        try {
            BootstrapAdminCredentials::resolvePassword($password, 'admin', true);
            $this->fail('Expected the production credential validator to reject the placeholder.');
        } catch (RuntimeException $exception) {
            $this->assertStringNotContainsString($password, $exception->getMessage());
        }
    }
}
