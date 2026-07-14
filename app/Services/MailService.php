<?php

namespace App\Services;

use App\Models\MailLog;
use App\Models\MailTemplate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends templated emails using DB-stored templates and records a MailLog row for every
 * send attempt. Mirrors RustDesk Server Pro's MailService (app/service/mail.go): templates
 * are looked up by type, body placeholders are substituted, and a send-log row captures the
 * outcome (success or error) of each attempt.
 *
 * Templates use {$key} placeholders, substituted from the $vars map passed to send().
 */
class MailService
{
    /** Template type: login verification code. */
    public const TYPE_LOGIN_VERIFY = 1;

    /** Template type: invitation to join. */
    public const TYPE_INVITATION = 2;

    /** Template type: alarm / alert notification. */
    public const TYPE_ALARM = 3;

    /** Template type: password reset. */
    public const TYPE_PASSWORD_RESET = 4;

    /** MailLog status: send succeeded. */
    public const STATUS_OK = 1;

    /** MailLog status: send failed. */
    public const STATUS_ERR = 2;

    /**
     * Fetch the most recent template for the given type, or null when none exists.
     */
    public function getTemplateByType(int $type): ?MailTemplate
    {
        return MailTemplate::where('type', $type)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * Send a templated email and always record a MailLog row describing the outcome.
     *
     * The template body is loaded by $templateId, its {$key} placeholders are replaced with
     * the matching values from $vars, and the result is delivered as HTML. Any failure is
     * caught, logged, and reflected in the MailLog row; this method never throws.
     *
     * @param  int|null  $userId  The user the mail relates to, if any.
     * @param  int  $templateId  The MailTemplate id to render.
     * @param  string  $to  The recipient address.
     * @param  string  $uuid  A correlation id for this send (e.g. a device or request uuid).
     * @param  array<string, string|int|float>  $vars  Placeholder values keyed without braces.
     * @return bool True when the mail was sent, false on any failure.
     */
    public function send(?int $userId, int $templateId, string $to, string $uuid, array $vars): bool
    {
        $fromAddress = (string) config('mail.from.address');

        $template = MailTemplate::find($templateId);

        if ($template === null) {
            $this->writeLog($userId, $templateId, $fromAddress, $to, $uuid, '', '', self::STATUS_ERR, "template not found: id={$templateId}");

            return false;
        }

        $body = $this->renderBody((string) $template->contents, $vars);
        $subject = (string) $template->subject;

        try {
            Mail::html($body, function ($message) use ($to, $subject): void {
                $message->to($to)->subject($subject);
            });

            $this->writeLog($userId, $templateId, $fromAddress, $to, $uuid, $subject, $body, self::STATUS_OK, 'sent');

            return true;
        } catch (Throwable $e) {
            Log::error('MailService send failed', [
                'template_id' => $templateId,
                'to' => $to,
                'uuid' => $uuid,
                'error' => $e->getMessage(),
            ]);

            $this->writeLog($userId, $templateId, $fromAddress, $to, $uuid, $subject, $body, self::STATUS_ERR, 'send error: '.$e->getMessage());

            return false;
        }
    }

    /**
     * Replace {$key} placeholders in the template body with values from $vars.
     *
     * @param  array<string, string|int|float>  $vars
     */
    private function renderBody(string $contents, array $vars): string
    {
        $replacements = [];
        foreach ($vars as $key => $value) {
            // Templates are HTML. Treat every runtime placeholder as text so device names,
            // usernames, audit messages, and links cannot inject markup or scriptable attrs.
            $replacements['{$'.$key.'}'] = htmlspecialchars(
                (string) $value,
                ENT_QUOTES | ENT_SUBSTITUTE,
                'UTF-8'
            );
        }

        return strtr($contents, $replacements);
    }

    /**
     * Persist a single send-attempt record.
     */
    private function writeLog(
        ?int $userId,
        int $templateId,
        string $fromAddress,
        string $to,
        string $uuid,
        string $subject,
        string $contents,
        int $status,
        string $logs,
    ): void {
        MailLog::create([
            'user_id' => $userId,
            'template_id' => $templateId,
            'from_address' => $fromAddress,
            'to_address' => $to,
            'uuid' => $uuid,
            'subject' => $subject,
            'contents' => $contents,
            'status' => $status,
            'logs' => $logs,
        ]);
    }
}
