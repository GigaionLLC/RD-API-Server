<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Services\AlarmService;
use App\Services\AuditIngestionGuard;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use JsonException;
use Throwable;

/**
 * Device-bound audit ingestion endpoints (docs/modernization/02-client-api-contract.md section 8).
 *
 * RustDesk clients cannot attach an account bearer to these fire-and-forget requests. Each write
 * is therefore bound to the exact id + UUID of an existing approved device before it may reach
 * the database, mailer, or webhook pipeline.
 */
class AuditController extends Controller
{
    public function __construct(private AuditIngestionGuard $ingestion) {}

    /**
     * The RustDesk client's connection alarm types (AlarmAuditType in the client).
     *
     * @var array<int, string>
     */
    public const ALARM_TYPES = [
        0 => 'Connection from a non-whitelisted IP',
        1 => 'Excessive login attempts (>30)',
        2 => 'Rapid login attempts (6/min)',
        6 => 'IPv6 prefix abuse',
        7 => 'Terminal OS-login backoff',
        8 => 'Terminal session concurrency limit',
        9 => 'Session-scope permission violation',
    ];

    /**
     * POST /api/audit/conn
     *
     * Host connection events carry id, uuid, conn_id, session_id, and optionally action, peer,
     * type, IP, and authentication-attribution fields. The host's authenticated follow-up event
     * omits action and retains the historical "new" default.
     */
    public function conn(Request $request, AlarmService $alarms, WebhookService $webhooks): JsonResponse
    {
        $device = $this->ingestion->deviceFor($request, 'conn');
        $payload = $this->payload($request);
        if ($device === null || $payload === null) {
            return $this->acknowledge();
        }

        // The legacy controlling-side note shape has no bearer token or UUID. It therefore
        // no-ops unless a compatible caller supplies the controlled device's exact UUID. The
        // current authenticated note flow is PUT /api/audit and uses a server-issued guid.
        if (array_key_exists('note', $payload) && ! array_key_exists('action', $payload)) {
            $data = $this->validated($payload, [
                'id' => ['required', 'string', 'max:255'],
                'uuid' => ['required', 'string', 'max:255'],
                'session_id' => ['required'],
                'note' => ['required', 'string', 'max:4000'],
            ]);
            if ($data === null) {
                return $this->acknowledge();
            }

            $sessionId = $this->wireIdentifier($data['session_id']);
            if ($sessionId === null) {
                return $this->acknowledge();
            }

            AuditConn::query()
                ->where('peer_id', $data['id'])
                ->where('session_id', $sessionId)
                ->where('action', AuditConn::ACTION_NEW)
                ->latest('id')
                ->first()
                ?->update(['note' => $data['note']]);

            return $this->acknowledge();
        }

        $data = $this->validated($payload, [
            'id' => ['required', 'string', 'max:255'],
            'uuid' => ['required', 'string', 'max:255'],
            'conn_id' => ['required', 'integer', 'min:0', 'max:9223372036854775807'],
            // session_id is a JSON u64. payload() preserves values above PHP_INT_MAX as strings.
            'session_id' => ['required'],
            'action' => ['sometimes', 'string', 'in:new,close'],
            'peer' => ['sometimes', 'array', 'list', 'max:2'],
            'peer.*' => ['nullable', 'string', 'max:255'],
            'ip' => ['sometimes', 'nullable', 'ip'],
            'type' => ['sometimes', 'integer', 'between:0,255'],
            'primary_auth' => ['sometimes', 'nullable', 'integer', 'between:0,255'],
            'two_factor' => ['sometimes', 'nullable', 'integer', 'between:0,255'],
            'conn_audit_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);
        if ($data === null) {
            return $this->acknowledge();
        }

        $sessionId = $this->wireIdentifier($data['session_id']);
        if ($sessionId === null) {
            return $this->acknowledge();
        }

        $peer = is_array($data['peer'] ?? null) ? $data['peer'] : [];
        $action = (string) ($data['action'] ?? AuditConn::ACTION_NEW);
        $peerId = (string) $data['id'];
        $ip = (string) ($data['ip'] ?? $request->ip() ?? '');

        $audit = AuditConn::create([
            'guid' => $action === AuditConn::ACTION_NEW ? (string) Str::uuid() : null,
            'action' => $action,
            'conn_id' => (int) $data['conn_id'],
            'peer_id' => $peerId,
            'from_peer' => (string) ($peer[0] ?? ''),
            'from_name' => (string) ($peer[1] ?? ''),
            'ip' => $ip,
            'session_id' => $sessionId,
            'type' => (int) ($data['type'] ?? 0),
            'primary_auth' => isset($data['primary_auth']) ? (int) $data['primary_auth'] : null,
            'two_factor' => isset($data['two_factor']) ? (int) $data['two_factor'] : null,
            'conn_audit_ref' => isset($data['conn_audit_ref']) && $data['conn_audit_ref'] !== ''
                ? $data['conn_audit_ref']
                : null,
            'uuid' => $data['uuid'],
            'closed_at' => $action === AuditConn::ACTION_CLOSE ? now() : null,
        ]);

        // Alarms and webhooks remain best-effort so their failures do not lose the audit row.
        if ($action === AuditConn::ACTION_NEW) {
            try {
                $fromName = (string) ($peer[1] ?? $peer[0] ?? '');
                $authSummary = $audit->authSummary();
                $alarms->raise(
                    $device,
                    $peerId,
                    'new_connection',
                    'New connection to '.$peerId.($fromName !== '' ? ' from '.$fromName : '').' ('.$ip.')'
                        .($authSummary !== '' ? ' - authenticated via '.$authSummary : ''),
                    $ip
                );
            } catch (Throwable $e) {
                Log::error('AlarmService raise failed in audit conn', [
                    'peer_id' => $peerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $webhooks->dispatch(
            $action === AuditConn::ACTION_CLOSE ? 'connection.closed' : 'connection.new',
            [
                'peer_id' => $peerId,
                'from' => (string) ($peer[1] ?? $peer[0] ?? ''),
                'ip' => $ip,
                'session_id' => $sessionId,
                'primary_auth' => $audit->primaryAuthLabel() ?: null,
                'two_factor' => $audit->twoFactorLabel() ?: null,
                'conn_audit_ref' => $audit->conn_audit_ref,
            ]
        );

        return $this->acknowledge();
    }

    /**
     * POST /api/audit/alarm
     * Body: { id, uuid, typ:<int>, info:<json string|object> }.
     */
    public function alarm(Request $request, AlarmService $alarms): JsonResponse
    {
        $device = $this->ingestion->deviceFor($request, 'alarm');
        $payload = $this->payload($request);
        if ($device === null || $payload === null) {
            return $this->acknowledge();
        }

        $data = $this->validated($payload, [
            'id' => ['required', 'string', 'max:255'],
            'uuid' => ['required', 'string', 'max:255'],
            'typ' => ['required', 'integer', 'between:0,255'],
            'info' => ['sometimes', 'nullable'],
            'ip' => ['sometimes', 'nullable', 'ip'],
        ]);
        if ($data === null) {
            return $this->acknowledge();
        }

        $info = $data['info'] ?? '';
        if (is_array($info)) {
            try {
                $info = json_encode($info, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return $this->acknowledge();
            }
        }
        if (! is_string($info) || strlen($info) > 8192) {
            return $this->acknowledge();
        }

        $peerId = (string) $data['id'];
        $typ = (int) $data['typ'];
        $label = self::ALARM_TYPES[$typ] ?? ('Security alarm (type '.$typ.')');
        $message = $info !== '' ? $label.': '.$info : $label;
        $ip = (string) ($data['ip'] ?? $request->ip() ?? '');

        try {
            $alarms->raise($device, $peerId, $label, $message, $ip);
        } catch (Throwable $e) {
            Log::error('AlarmService raise failed in audit alarm', [
                'peer_id' => $peerId,
                'typ' => $typ,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->acknowledge();
    }

    /**
     * POST /api/audit/file
     * Body: { id, uuid, peer_id, info, is_file, path, type, ip }.
     */
    public function file(Request $request): JsonResponse
    {
        if ($this->ingestion->deviceFor($request, 'file') === null) {
            return $this->acknowledge();
        }

        $payload = $this->payload($request);
        if ($payload === null) {
            return $this->acknowledge();
        }

        $data = $this->validated($payload, [
            'id' => ['required', 'string', 'max:255'],
            'uuid' => ['required', 'string', 'max:255'],
            'peer_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'from_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'info' => ['sometimes', 'nullable', 'string'],
            'is_file' => ['sometimes', 'boolean'],
            'path' => ['sometimes', 'nullable', 'string', 'max:255'],
            'type' => ['sometimes', 'integer', 'between:0,255'],
            'ip' => ['sometimes', 'nullable', 'ip'],
            'num' => ['sometimes', 'integer', 'between:0,2147483647'],
        ]);
        if ($data === null || strlen((string) ($data['info'] ?? '')) > 60000) {
            return $this->acknowledge();
        }

        AuditFile::create([
            'peer_id' => (string) ($data['peer_id'] ?? $data['id']),
            'from_peer' => $data['id'],
            'from_name' => (string) ($data['from_name'] ?? ''),
            'info' => (string) ($data['info'] ?? ''),
            'is_file' => (bool) ($data['is_file'] ?? true),
            'path' => (string) ($data['path'] ?? ''),
            'type' => (int) ($data['type'] ?? 0),
            'ip' => (string) ($data['ip'] ?? $request->ip() ?? ''),
            'num' => (int) ($data['num'] ?? 0),
            'uuid' => $data['uuid'],
        ]);

        return $this->acknowledge();
    }

    /**
     * GET /api/audit/conn/active?id=&session_id=&conn_type=
     * Returns the live session's server-issued guid as a bare JSON string.
     */
    public function active(Request $request): JsonResponse
    {
        $peerId = (string) $request->query('id', '');
        $sessionId = (string) $request->query('session_id', '');

        $guid = '';
        if ($peerId !== '' && $sessionId !== '') {
            $guid = (string) (AuditConn::query()
                ->where('peer_id', $peerId)
                ->where('session_id', $sessionId)
                ->where('action', AuditConn::ACTION_NEW)
                ->whereNotNull('guid')
                ->latest('id')
                ->value('guid') ?? '');
        }

        return response()->json($guid);
    }

    /**
     * PUT /api/audit - { guid, note }
     * Attaches an operator note to the record identified by the authenticated client's guid.
     */
    public function note(Request $request): JsonResponse
    {
        $guid = (string) $request->input('guid', '');
        $note = (string) $request->input('note', '');

        if ($guid !== '') {
            AuditConn::where('guid', $guid)->update(['note' => $note]);
        }

        return $this->acknowledge();
    }

    /**
     * Decode the raw JSON with exact u64 handling and a conservative nesting ceiling.
     *
     * @return array<string, mixed>|null
     */
    private function payload(Request $request): ?array
    {
        try {
            $payload = json_decode(
                (string) $request->getContent(),
                true,
                32,
                JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR
            );
        } catch (JsonException) {
            return null;
        }

        if (! is_array($payload)) {
            return null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, array<int, string>>  $rules
     * @return array<string, mixed>|null
     */
    private function validated(array $payload, array $rules): ?array
    {
        $validator = Validator::make($payload, $rules);

        return $validator->fails() ? null : $validator->validated();
    }

    private function wireIdentifier(mixed $value): ?string
    {
        if (is_int($value) && $value >= 0) {
            return (string) $value;
        }

        if (is_string($value) && $value !== '' && mb_strlen($value) <= 255) {
            return $value;
        }

        return null;
    }

    private function acknowledge(): JsonResponse
    {
        return response()->json((object) []);
    }
}
