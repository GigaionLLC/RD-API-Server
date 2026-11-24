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
     * Create an alarm and fan it out — the one-call form used by callers that store the alarm as
     * a side effect of another record (e.g. a new connection).
     *
     * The email is sent through the templated MailService::TYPE_ALARM template with the
     * placeholders {$username}, {$device}, and {$message}. On a successful send the alarm's
     * emailed flag is set to true. This method never throws because of email failures.
     */
    public function raise(?Device $device, string $peerId, string $type, string $message, ?string $ip): Alarm
    {
        $alarm = $this->create($device, $peerId, $type, $message, $ip);
        $this->notify($alarm, $device);

        return $alarm;
    }

    /**
     * Persist the alarm row only.
     *
     * Split from notify() so a caller that owns the persistence decision — alarm ingestion, which
     * deduplicates on the client nonce and must not fire a webhook or an email for a retried
     * record — can write the row in its own transaction and fan out only after it committed.
     */
    public function create(
        ?Device $device,
        string $peerId,
        string $type,
        string $message,
        ?string $ip,
        ?string $clientNonce = null,
        ?string $connAuditRef = null,
    ): Alarm {
        return Alarm::create([
            'device_id' => $device?->id,
            'peer_id' => $peerId,
            'type' => $type,
            'message' => $message,
            'ip' => $ip,
            'client_nonce' => $clientNonce,
            'conn_audit_ref' => $connAuditRef,
            'emailed' => false,
        ]);
    }

    /**
     * Send a persisted alarm to the configured webhooks (Slack/Telegram/generic) and email it to
     * the device's owner when they opted in. Best-effort by contract: it never throws.
     */
    public function notify(Alarm $alarm, ?Device $device = null): void
    {
        $peerId = (string) $alarm->peer_id;
        $type = (string) $alarm->type;
        $message = (string) $alarm->message;

        $this->webhooks->dispatch('alarm.raised', [
            'peer_id' => $peerId,
            'type' => $type,
            'message' => $message,
            'ip' => $alarm->ip,
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
    }
}
