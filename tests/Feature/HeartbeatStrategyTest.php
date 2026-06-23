<?php

namespace Tests\Feature;

use App\Models\Device;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The heartbeat strategy push must serialize `config_options` and `extra` as JSON objects.
 * The client deserializes both into HashMap<String,String> (sync.rs StrategyOptions); an empty
 * PHP array encodes as "[]", which fails serde and silently drops the whole strategy — the
 * client stores modified_at but never applies the options. Regression guard for that bug.
 */
class HeartbeatStrategyTest extends TestCase
{
    use RefreshDatabase;

    public function test_strategy_block_serializes_config_options_and_extra_as_objects(): void
    {
        $device = Device::create(['rustdesk_id' => 'hb-1', 'uuid' => 'u1', 'approved' => true]);
        $strategy = Strategy::create([
            'name' => 'Default Policy', 'enabled' => true,
            'options' => ['enable-keyboard' => 'N', 'enable-clipboard' => 'N'],
            'extra' => [], // empty — the exact case that used to encode as "[]"
            'modified_at' => 1782181674,
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE,
            'target_id' => $device->id,
        ]);

        // Client's known timestamp differs → server must push the strategy.
        $res = $this->postJson('/api/heartbeat', ['id' => 'hb-1', 'uuid' => 'u1', 'modified_at' => 0]);
        $res->assertOk();

        $body = $res->getContent();
        $this->assertStringContainsString('"extra":{}', $body);          // object, not "[]"
        $this->assertStringNotContainsString('"extra":[]', $body);
        $this->assertStringContainsString('"config_options":{', $body);  // object
        $res->assertJsonPath('modified_at', 1782181674);
        $res->assertJsonPath('strategy.config_options.enable-keyboard', 'N');
    }

    public function test_no_strategy_pushed_when_timestamp_already_matches(): void
    {
        $device = Device::create(['rustdesk_id' => 'hb-2', 'uuid' => 'u2', 'approved' => true]);
        $strategy = Strategy::create([
            'name' => 'P', 'enabled' => true, 'options' => ['enable-audio' => 'N'], 'modified_at' => 555,
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE,
            'target_id' => $device->id,
        ]);

        // Client already has this version → empty object ack, no strategy block.
        $res = $this->postJson('/api/heartbeat', ['id' => 'hb-2', 'uuid' => 'u2', 'modified_at' => 555]);
        $res->assertOk();
        $this->assertSame('{}', $res->getContent());
    }
}
