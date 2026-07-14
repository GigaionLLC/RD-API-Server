<?php

namespace Tests\Feature;

use App\Models\AdminRole;
use App\Models\Alarm;
use App\Models\Device;
use App\Models\Recording;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class AlarmRecordingAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_view_only_delegate_can_read_but_cannot_delete_alarms_or_recordings(): void
    {
        $delegate = $this->delegateWithPermissions([
            'alarms.view',
            'recordings.view',
        ]);
        $this->registerActivityPeers($delegate);
        $alarm = $this->createAlarm();
        $recording = $this->createRecording();

        $this->actingAs($delegate)
            ->get(route('admin.alarms.index'))
            ->assertOk()
            ->assertSee($alarm->message)
            ->assertDontSee('Delete alarm');

        $this->actingAs($delegate)
            ->delete(route('admin.alarms.destroy', $alarm))
            ->assertForbidden();
        $this->assertDatabaseHas('alarms', ['id' => $alarm->id]);

        $this->actingAs($delegate)
            ->get(route('admin.recordings.index'))
            ->assertOk()
            ->assertSee('Download')
            ->assertDontSee('Delete recording');

        // A missing test file reaches the download controller and returns 404 rather than
        // failing authorization, proving recordings.view still permits downloads.
        $this->actingAs($delegate)
            ->get(route('admin.recordings.download', $recording))
            ->assertNotFound();

        $this->actingAs($delegate)
            ->delete(route('admin.recordings.destroy', $recording))
            ->assertForbidden();
        $this->assertDatabaseHas('recordings', ['id' => $recording->id]);
    }

    public function test_edit_permissions_allow_delegated_alarm_and_recording_deletion(): void
    {
        $delegate = $this->delegateWithPermissions([
            'alarms.view',
            'alarms.edit',
            'recordings.view',
            'recordings.edit',
        ]);
        $this->registerActivityPeers($delegate);
        $alarm = $this->createAlarm();
        $recording = $this->createRecording();

        $this->actingAs($delegate)
            ->delete(route('admin.alarms.destroy', $alarm))
            ->assertRedirect(route('admin.alarms.index'));
        $this->assertDatabaseMissing('alarms', ['id' => $alarm->id]);

        $this->actingAs($delegate)
            ->delete(route('admin.recordings.destroy', $recording))
            ->assertRedirect(route('admin.recordings.index'));
        $this->assertDatabaseMissing('recordings', ['id' => $recording->id]);
    }

    public function test_legacy_full_admin_retains_destructive_access(): void
    {
        $admin = User::create([
            'username' => 'legacy-admin',
            'password' => 'secret12345',
            'is_admin' => true,
            'status' => User::STATUS_NORMAL,
        ]);
        $alarm = $this->createAlarm();
        $recording = $this->createRecording();

        $this->actingAs($admin)
            ->delete(route('admin.alarms.destroy', $alarm))
            ->assertRedirect(route('admin.alarms.index'));
        $this->actingAs($admin)
            ->delete(route('admin.recordings.destroy', $recording))
            ->assertRedirect(route('admin.recordings.index'));

        $this->assertDatabaseMissing('alarms', ['id' => $alarm->id]);
        $this->assertDatabaseMissing('recordings', ['id' => $recording->id]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function delegateWithPermissions(array $permissions): User
    {
        $role = AdminRole::create([
            'name' => 'Activity delegate',
            'type' => AdminRole::TYPE_INDIVIDUAL,
            'scope' => [],
            'perms' => $permissions,
        ]);
        $delegate = User::create([
            'username' => 'activity-delegate',
            'password' => 'secret12345',
            'is_admin' => false,
            'status' => User::STATUS_NORMAL,
        ]);
        $delegate->adminRoles()->attach($role);

        return $delegate;
    }

    private function createAlarm(): Alarm
    {
        return Alarm::create([
            'peer_id' => 'alarm-peer',
            'type' => 'Test alarm',
            'message' => 'Authorization test alarm',
            'ip' => '192.0.2.10',
        ]);
    }

    private function createRecording(): Recording
    {
        return Recording::create([
            'peer_id' => 'recording-peer',
            'filename' => 'missing-authorization-test-'.Str::uuid().'.webm',
            'status' => 'complete',
        ]);
    }

    private function registerActivityPeers(User $owner): void
    {
        foreach (['alarm-peer', 'recording-peer'] as $peerId) {
            Device::create([
                'rustdesk_id' => $peerId,
                'uuid' => 'uuid-'.$peerId,
                'user_id' => $owner->id,
            ]);
        }
    }
}
