<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Device;
use App\Models\Group;
use App\Models\Strategy;
use App\Models\StrategyAssignment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Value precedence (Jake, 2026-11-25): a value an administrator stored in a strategy always
 * wins. The server never adds, rewrites or silently drops an option value by itself — also not
 * when a later release adds a built-in (catalog) control for a key someone had already set by
 * hand as a custom option.
 *
 * Regression guards for the editor's silent drop: a toggle holding `true` (or a select holding a
 * value outside its choices) used to render as "Default", so the next save of an unrelated field
 * deleted it — and for a full-admin-only key that also made a delegated editor's save fail.
 */
class StrategyValuePrecedenceTest extends TestCase
{
    use RefreshDatabase;

    /** A timestamp before any `added_on` in the catalog (2026-11-24 UTC). */
    private const BEFORE_V1_8_0 = 1782181674; // 2026-06-22

    private ?User $admin = null;

    private function admin(): User
    {
        return $this->admin ??= User::create([
            'username' => 'admin', 'password' => 'secret12345', 'is_admin' => true, 'status' => User::STATUS_NORMAL,
        ]);
    }

    private function strategyWithDevice(array $options, int $modifiedAt, ?Device $device = null): Strategy
    {
        $device ??= Device::create(['rustdesk_id' => 'precedence-1', 'uuid' => 'precedence-uuid-1', 'approved' => true]);
        $strategy = Strategy::create([
            'name' => 'Policy', 'enabled' => true, 'options' => $options, 'modified_at' => $modifiedAt,
        ]);
        StrategyAssignment::create([
            'strategy_id' => $strategy->id,
            'target_type' => StrategyAssignment::TARGET_DEVICE,
            'target_id' => $device->id,
        ]);

        return $strategy;
    }

    public function test_a_value_set_by_hand_before_the_catalog_offered_the_control_renders_on_with_the_notice(): void
    {
        $strategy = $this->strategyWithDevice(
            ['allow-drm-capture' => 'Y', 'enable-audio' => 'N'],
            self::BEFORE_V1_8_0,
        );

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('value="Y" selected', false)
            ->assertDontSee('Custom:')
            ->assertSee('Added as a built-in control in RD-API 1.8.0. This strategy already had `Y` set by hand; it was kept and is still pushed.');

        // The notice is about the previous save only: it is gone once the strategy is saved.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy renamed', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'Y', 'enable-audio' => 'N'],
            ])
            ->assertOk()
            ->assertExactJson([]);

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertDontSee('Added as a built-in control');
    }

    public function test_a_value_set_by_hand_survives_the_save_and_is_still_pushed(): void
    {
        $strategy = $this->strategyWithDevice(
            ['allow-drm-capture' => 'Y', 'enable-audio' => 'N'],
            self::BEFORE_V1_8_0,
        );

        // What the editor renders for this strategy: the DRM toggle selected "On".
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy renamed', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'Y', 'enable-audio' => 'N'],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertSame('Y', $strategy->options['allow-drm-capture'], 'A save of an unrelated field dropped the stored value.');

        $this->postJson('/api/heartbeat', ['id' => 'precedence-1', 'uuid' => 'precedence-uuid-1', 'modified_at' => 0])
            ->assertOk()
            ->assertJsonPath('strategy.config_options.allow-drm-capture', 'Y');
    }

    public function test_unrecognised_toggle_and_select_values_render_as_custom_and_survive_a_save(): void
    {
        $strategy = $this->strategyWithDevice(
            ['allow-drm-capture' => 'true', 'access-mode' => 'fullscreen', 'enable-audio' => 'N'],
            time(),
        );

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('Custom: true')
            ->assertSee('Custom: fullscreen')
            ->assertSee('value="true" selected', false)
            ->assertSee('value="fullscreen" selected', false)
            ->assertSee('Not a value this control offers; kept exactly as set.')
            // Verified in the client: option2bool() reads only an exact "Y" as on for `allow-` keys.
            ->assertSee('RustDesk treats anything other than <code>Y</code> as off for this key.', false)
            ->assertDontSee('Added as a built-in control');

        // Saving what the editor renders keeps both values exactly as they are.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'true', 'access-mode' => 'fullscreen', 'enable-audio' => 'Y'],
            ])
            ->assertOk()
            ->assertExactJson([]);

        $strategy->refresh();
        $this->assertSame('true', $strategy->options['allow-drm-capture']);
        $this->assertSame('fullscreen', $strategy->options['access-mode']);
        $this->assertSame('Y', $strategy->options['enable-audio']);

        // A request that never mentions the key cannot express such a value, so it is preserved
        // instead of being read as "the admin cleared it".
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), ['name' => 'Policy', 'enabled' => 1])
            ->assertOk();

        $strategy->refresh();
        $this->assertSame('true', $strategy->options['allow-drm-capture']);
        $this->assertSame('fullscreen', $strategy->options['access-mode']);

        // The editor's explicit "Default" choice still clears them.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => '', 'access-mode' => ''],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertArrayNotHasKey('allow-drm-capture', $strategy->options);
        $this->assertArrayNotHasKey('access-mode', $strategy->options);
    }

    public function test_a_custom_row_naming_a_built_in_control_wins_and_the_save_says_so(): void
    {
        $strategy = Strategy::create(['name' => 'Policy', 'enabled' => true, 'options' => [], 'modified_at' => 1]);

        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'Y'],
                'option_keys' => ['allow-drm-capture'],
                'option_values' => ['N'],
            ])
            ->assertOk()
            ->assertJsonPath('warning', '`allow-drm-capture` is also a built-in control; your custom value was saved and takes precedence.');

        // The custom value still wins — precedence is unchanged, only the silence is gone.
        $this->assertSame('N', $strategy->refresh()->options['allow-drm-capture']);

        // Both warnings can appear, in one string, and the shape stays {"warning": "..."}.
        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['enable-audio' => 'Y'],
                'option_keys' => ['stop-service', 'enable-audio'],
                'option_values' => ['Y', 'N'],
            ])
            ->assertOk()
            ->assertExactJson(['warning' => '`enable-audio` is also a built-in control; your custom value was saved and takes precedence.'
                .' RustDesk 1.5.3+ ignores stop-service when pushed by a server; set it at install time with --option or the config string.']);

        $strategy->refresh();
        $this->assertSame('N', $strategy->options['enable-audio']);
        $this->assertSame('Y', $strategy->options['stop-service']);
    }

    public function test_delegated_editor_cannot_change_a_full_admin_only_value_but_never_loses_it(): void
    {
        $inside = Group::create(['name' => 'Inside', 'type' => Group::TYPE_DEFAULT]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id,
        ]);
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped', 'type' => AdminRole::TYPE_GROUP, 'scope' => [$inside->id],
            'perms' => ['strategies.view', 'strategies.edit'],
        ]));

        $device = Device::create([
            'rustdesk_id' => 'inside-peer', 'uuid' => 'uuid-inside', 'user_id' => $delegate->id, 'approved' => true,
        ]);
        $strategy = $this->strategyWithDevice(
            ['allow-drm-capture' => 'true', 'enable-audio' => 'N'],
            time(),
            $device,
        );

        // The editor renders the stored value readably for a delegated editor too.
        $this->actingAs($delegate)->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('Custom: true');

        // An unrelated save keeps it byte for byte, whether the form posts it or not.
        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'true', 'enable-audio' => 'Y'],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertSame('true', $strategy->options['allow-drm-capture']);
        $this->assertSame('Y', $strategy->options['enable-audio']);

        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), ['name' => 'Policy', 'enabled' => 1])
            ->assertOk();

        $this->assertSame('true', $strategy->refresh()->options['allow-drm-capture']);

        // The guard is untouched: a delegated editor still cannot set, change or remove it.
        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => 'N'],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.opt.0', 'Only a full administrator can set or change: allow-drm-capture.');

        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['allow-drm-capture' => ''],
            ])
            ->assertUnprocessable()
            ->assertJsonPath('errors.opt.0', 'Only a full administrator can set or change: allow-drm-capture.');

        $this->assertSame('true', $strategy->refresh()->options['allow-drm-capture']);
    }

    public function test_delegated_editor_sees_full_admin_only_controls_locked_and_their_save_keeps_the_value(): void
    {
        $inside = Group::create(['name' => 'Inside', 'type' => Group::TYPE_DEFAULT]);
        $delegate = User::create([
            'username' => 'delegate', 'password' => 'secret12345', 'status' => User::STATUS_NORMAL, 'group_id' => $inside->id,
        ]);
        $delegate->adminRoles()->attach(AdminRole::create([
            'name' => 'Scoped', 'type' => AdminRole::TYPE_GROUP, 'scope' => [$inside->id],
            'perms' => ['strategies.view', 'strategies.edit'],
        ]));
        $device = Device::create([
            'rustdesk_id' => 'inside-peer', 'uuid' => 'uuid-inside', 'user_id' => $delegate->id, 'approved' => true,
        ]);
        $strategy = $this->strategyWithDevice(['allow-drm-capture' => 'Y'], time(), $device);

        // A full administrator gets the normal, editable control.
        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertDontSee('Only a full administrator can change this.');

        // For the delegated editor the control is rendered disabled (the browser then posts
        // nothing for it, and the section bulk buttons skip it) with a note saying why.
        $html = $this->actingAs($delegate)->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            ->assertSee('Only a full administrator can change this.')
            ->getContent();
        $this->assertMatchesRegularExpression('/<select[^>]*name="opt\[allow-drm-capture\]"[^>]*disabled/', (string) $html);

        // The delegated save omits the disabled key (as a browser does) and changes another one:
        // the stored full-admin-only value is carried through, not cleared, and the guard is quiet.
        $this->actingAs($delegate)
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['enable-audio' => 'N'],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertSame('Y', $strategy->options['allow-drm-capture']);
        $this->assertSame('N', $strategy->options['enable-audio']);
    }

    public function test_number_option_with_a_non_numeric_stored_value_renders_as_text_and_survives(): void
    {
        $strategy = $this->strategyWithDevice([
            'file-transfer-max-files' => 'unlimited',
            // A browser normalises `007` to `7` in a numeric input, which would rewrite it too.
            'direct-access-port' => '007',
        ], time());

        $this->actingAs($this->admin())->get(route('admin.strategies.edit', $strategy))
            ->assertOk()
            // A numeric input would blank a non-numeric value and lose it on save.
            ->assertSee('type="text" name="opt[file-transfer-max-files]" value="unlimited"', false)
            ->assertSee('type="text" name="opt[direct-access-port]" value="007"', false)
            ->assertSee('Not a value this control offers; kept exactly as set.');

        $this->actingAs($this->admin())
            ->putJson(route('admin.strategies.update', $strategy), [
                'name' => 'Policy', 'enabled' => 1,
                'opt' => ['file-transfer-max-files' => 'unlimited', 'direct-access-port' => '007'],
            ])
            ->assertOk();

        $strategy->refresh();
        $this->assertSame('unlimited', $strategy->options['file-transfer-max-files']);
        $this->assertSame('007', $strategy->options['direct-access-port']);
    }
}
