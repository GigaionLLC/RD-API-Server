<?php

namespace Tests\Feature;

use App\Models\Strategy;
use App\Models\SystemSetting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Js;
use Tests\TestCase;

class AdminInlineScriptSerializationTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT_PAYLOAD = '</script><script>globalThis.inlineScriptExecuted=true</script>';

    private function admin(): User
    {
        return User::create([
            'username' => 'admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
    }

    public function test_settings_view_safely_serializes_script_terminators_without_losing_values(): void
    {
        SystemSetting::create([
            'key' => 'security.serialization-probe',
            'value' => self::SCRIPT_PAYLOAD,
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.settings.index'))
            ->assertOk();

        $expected = 'var settings = '.Js::from([
            'security.serialization-probe' => self::SCRIPT_PAYLOAD,
        ])->toHtml().';';

        $content = (string) $response->getContent();
        $this->assertStringContainsString($expected, $content);
        $this->assertStringNotContainsString(self::SCRIPT_PAYLOAD, $content);
    }

    public function test_strategy_editor_safely_serializes_script_terminators_without_losing_values(): void
    {
        $strategy = Strategy::create([
            'name' => 'Serialization probe',
            'enabled' => true,
            'options' => ['custom-probe' => self::SCRIPT_PAYLOAD],
            'modified_at' => 1,
        ]);

        $response = $this->actingAs($this->admin())
            ->get(route('admin.strategies.edit', $strategy))
            ->assertOk();

        $expected = 'var customOptions = '.Js::from((object) [
            'custom-probe' => self::SCRIPT_PAYLOAD,
        ])->toHtml().';';

        $content = (string) $response->getContent();
        $this->assertStringContainsString($expected, $content);
        $this->assertStringNotContainsString(self::SCRIPT_PAYLOAD, $content);
    }
}
