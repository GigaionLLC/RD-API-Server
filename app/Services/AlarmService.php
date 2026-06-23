<?php

namespace App\Services;

use App\Models\Alarm;
use App\Models\Device;
use Illuminate\Support\Str;

/**
 * Raises operational/security alarms for peers and devices, persisting an Alarm row and
 * optionally notifying the owning user by email (via MailService::TYPE_ALARM) when they have
 * opted in through their email_alarm_notification flag.
 */
class AlarmService
{
    public function __construct(private MailService $mail, private WebhookService $webhooks) {}

    /**
     * Create an alarm and, when the device's owner has opted in, email them about it.
     *
     * The email is sent through the templated MailService::TYPE_ALARM template with the
     * placeholders {$username}, {$device}, and {$message}. On a successful send the alarm's
     * emailed flag is set to true. This method never throws because of email failures.
     */
    public function raise(?Device $device, string $peerId, string $type, string $message, ?string $ip): Alarm
    {
        $alarm = Alarm::create([
            'device_id' => $device?->id,
            'peer_id' => $peerId,
            'type' => $type,
            'message' => $message,
            'ip' => $ip,
            'emailed' => false,
        ]);

        // Fan the alarm out to any configured webhooks (Slack/Telegram/generic). Best-effort.
        $this->webhooks->dispatch('alarm.raised', [
            'peer_id' => $peerId,
            'type' => $type,
            'message' => $message,
            'ip' => $ip,
            'device' => $device?->hostname ?: $device?->alias,
        ]);

        $user = $device?->user;

        if ($user !== null
            && $user->email_alarm_notification
            && ! empty($user->email)) {
            $template = $this->mail->getTemplateByType(MailService::TYPE_ALARM);

            if ($template !== null) {
                $sent = $this->mail->send(
                    $user->id,
                    $template->id,
                    (string) $user->email,
                    (string) Str::uuid(),
                    [
                        'username' => (string) $user->username,
                        'device' => $device->hostname ?: $device->alias ?: $peerId,
                        'message' => $message,
                    ]
                );

                if ($sent) {
                    $alarm->forceFill(['emailed' => true])->save();
                }
            }
        }

        return $alarm;
    }
}
