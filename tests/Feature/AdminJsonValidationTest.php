<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminJsonValidationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_live_form_validation_errors_render_as_json(): void
    {
        $admin = User::create([
            'username' => 'json-validation-admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $target = User::create([
            'username' => 'json-validation-target',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);

        $this->actingAs($admin)
            ->withHeader('Accept', 'application/json')
            ->put(route('admin.users.password', $target), ['password' => 'short'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('password');
    }
}
