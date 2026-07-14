<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\BootstrapAdminCredentials;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class BootstrapAdminSeederTest extends TestCase
{
    use RefreshDatabase;

    private string $originalEnvironment;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalEnvironment = $this->app->environment();
        config([
            'bootstrap.admin.username' => 'admin',
            'bootstrap.admin.password' => null,
        ]);
    }

    protected function tearDown(): void
    {
        $this->app['env'] = $this->originalEnvironment;

        parent::tearDown();
    }

    public function test_production_seed_fails_before_creating_an_admin_when_password_is_missing(): void
    {
        $this->app['env'] = 'production';

        try {
            $this->runSeeder();
            $this->fail('Expected the production bootstrap to fail without ADMIN_PASS.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('ADMIN_PASS is required', $exception->getMessage());
        }

        $this->assertDatabaseCount('users', 0);
    }

    public function test_production_seed_creates_the_admin_with_an_explicit_safe_password(): void
    {
        $password = 'correct-horse-battery-staple';
        $this->setAdminPassword($password);
        $this->app['env'] = 'production';

        $this->runSeeder();

        $admin = User::where('username', 'admin')->sole();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check($password, $admin->password));
    }

    public function test_production_reseed_does_not_require_or_replace_a_preexisting_admin_password(): void
    {
        $admin = User::create([
            'username' => 'admin',
            'password' => 'existing-unique-password',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $originalHash = $admin->password;
        $this->app['env'] = 'production';

        $this->runSeeder();

        $this->assertSame($originalHash, $admin->refresh()->password);
    }

    public function test_nonproduction_seed_retains_the_development_fixture_password(): void
    {
        $this->app['env'] = 'testing';

        $this->runSeeder();

        $admin = User::where('username', 'admin')->sole();
        $this->assertTrue(Hash::check(BootstrapAdminCredentials::DEVELOPMENT_PASSWORD, $admin->password));
    }

    private function setAdminPassword(string $password): void
    {
        config(['bootstrap.admin.password' => $password]);
    }

    private function runSeeder(): void
    {
        $this->app->make(DatabaseSeeder::class)
            ->setContainer($this->app)
            ->__invoke();
    }
}
