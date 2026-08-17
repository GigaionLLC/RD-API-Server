<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\DeviceGroup;
use App\Models\Group;
use App\Models\User;
use App\Models\UserGroupAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The pickers that used to render every row into the page.
 *
 * A fleet is thousands of devices and, on a directory-synced deployment, thousands of
 * groups. These forms now search instead, which changes two things worth pinning: the page
 * must no longer contain the whole list, and the form must still submit what the
 * controller already expects — the conversion is not allowed to change the request shape.
 */
class ComboboxPickerTest extends TestCase
{
    use RefreshDatabase;

    public function test_group_pickers_do_not_render_the_whole_directory(): void
    {
        // The defect: a directory with thousands of groups put every one of them in the
        // DOM of every form with a group picker.
        $admin = $this->admin();
        for ($i = 0; $i < 60; $i++) {
            Group::create(['name' => "Directory group {$i}", 'type' => Group::TYPE_DEFAULT]);
        }

        foreach ([
            route('admin.users.create'),
            route('admin.roles.create'),
        ] as $url) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Directory group 42', $html,
                "{$url} still renders the group list into the page");
            $this->assertStringContainsString('rd-combo', $html,
                "{$url} should use the searchable combobox");
        }
    }

    public function test_the_scope_picker_still_submits_its_array(): void
    {
        // Each chip emits its own scope[] input, so the request shape is unchanged. If the
        // conversion had broken this, roles would silently save with an empty scope —
        // which widens rather than narrows what they can reach.
        $admin = $this->admin();
        $a = Group::create(['name' => 'Alpha', 'type' => Group::TYPE_DEFAULT]);
        $b = Group::create(['name' => 'Beta', 'type' => Group::TYPE_DEFAULT]);

        $this->actingAs($admin)->post(route('admin.roles.store'), [
            'name' => 'Scoped role',
            'type' => AdminRole::TYPE_GROUP,
            'scope' => [$a->id, $b->id],
            'perms' => ['devices.view'],
        ])->assertRedirect();

        $role = AdminRole::where('name', 'Scoped role')->firstOrFail();
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_map('intval', (array) $role->scope));
    }

    public function test_the_edit_form_shows_the_current_selection_as_chips(): void
    {
        // Only the chosen groups are loaded now, so the form has to render them itself —
        // otherwise an operator opening the page sees an empty picker and saves it away.
        $admin = $this->admin();
        $chosen = Group::create(['name' => 'Chosen group', 'type' => Group::TYPE_DEFAULT]);
        $other = Group::create(['name' => 'Unrelated group', 'type' => Group::TYPE_DEFAULT]);

        $role = AdminRole::create([
            'name' => 'Existing', 'type' => AdminRole::TYPE_GROUP,
            'scope' => [$chosen->id], 'perms' => ['devices.view'],
        ]);

        $html = $this->actingAs($admin)->get(route('admin.roles.edit', $role))->assertOk()->getContent();

        $this->assertStringContainsString('Chosen group', $html);
        $this->assertStringContainsString('rd-combo__chip', $html);
        $this->assertStringNotContainsString('Unrelated group', $html,
            'only the selection is rendered; the rest is searched');
        // And the value still posts back untouched if the operator changes nothing.
        $this->assertStringContainsString('name="scope[]" value="'.$chosen->id.'"', $html);
    }

    public function test_group_access_pickers_keep_their_joined_field(): void
    {
        // These two post a comma-joined hidden field rather than an array. The combobox
        // writes the same shape, so the controllers did not change.
        $admin = $this->admin();
        $group = Group::create(['name' => 'Owning', 'type' => Group::TYPE_DEFAULT]);
        $target = Group::create(['name' => 'Reachable', 'type' => Group::TYPE_DEFAULT]);
        UserGroupAccess::create(['group_id' => $group->id, 'can_access_group_id' => $target->id]);

        $html = $this->actingAs($admin)->get(route('admin.groups.edit', $group))->assertOk()->getContent();
        $this->assertStringContainsString('id="can_access_group_ids"', $html);
        $this->assertStringContainsString('value="'.$target->id.'"', $html);
        $this->assertStringContainsString('rd-combo--multi', $html);

        $deviceGroup = DeviceGroup::create(['name' => 'Workstations']);
        $html = $this->actingAs($admin)->get(route('admin.device-groups.edit', $deviceGroup))->assertOk()->getContent();
        $this->assertStringContainsString('id="access_group_ids"', $html);
        $this->assertStringContainsString('rd-combo--multi', $html);
    }

    public function test_group_search_is_scoped_and_bounded(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 30; $i++) {
            Group::create(['name' => "Team {$i}", 'type' => Group::TYPE_DEFAULT]);
        }

        $this->assertCount(20, $this->actingAs($admin)
            ->getJson(route('admin.groups.search'))->assertOk()->json());

        $found = $this->actingAs($admin)
            ->getJson(route('admin.groups.search', ['q' => 'Team 7']))->assertOk()->json();
        $this->assertNotEmpty($found);
        $this->assertStringContainsString('Team 7', $found[0]['text']);
    }

    private function admin(): User
    {
        $user = User::create([
            'username' => 'picker-admin',
            'password' => 'secret12345',
            'status' => User::STATUS_NORMAL,
        ]);
        $user->is_admin = true;
        $user->save();

        return $user;
    }
}
