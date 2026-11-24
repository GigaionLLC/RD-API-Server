<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Alarm;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\Device;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AlarmService;
use App\Services\AuditIngestionGuard;
use App\Services\WebhookService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
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
 *
 * Since client 1.5.0 (#15759) the three feeds are retried, deduplicated requests: the body carries
 * a per-record `nonce`, success is a 2xx with an EMPTY body, and any non-empty 2xx body makes the
 * client re-post the same record (10 s, then 30 s, deadline 120 s). So a stored record is
 * acknowledged with an empty 200 and a retry of an already-stored nonce is acknowledged the same
 * way without a second row and without repeating its alarms, mail, or webhooks. The guard (device
 * identity + rate limits) always runs first, so deduplication cannot be probed across devices.
 */
class AuditController extends Controller
{
    public function __construct(
        private AuditIngestionGuard $ingestion,
        private AccessService $access,
    ) {}

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
        10 => 'ID whitelist blocked connection',
    ];

    /**
     * Client nonce (#15759). The client sends a UUID v4 today; the format is deliberately checked
     * loosely so a future nonce shape is not dropped, while whitespace, control characters and
     * oversized values are refused like any other invalid field.
     *
     * @var array<int, string>
     */
    private const NONCE_RULES = ['sometimes', 'nullable', 'string', 'regex:/^[A-Za-z0-9._-]{8,64}$/'];

    /**
     * POST /api/audit/conn
     *
     * Host connection events carry id, uuid, conn_id, session_id, and optionally action, peer,
     * type, IP, and authentication-attribution fields. The host's authenticated follow-up event
     * omits action and retains the historical "new" default.
     */
    public function conn(Request $request, AlarmService $alarms, WebhookService $webhooks): JsonResponse|Response
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

            return $this->stored();
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
            'nonce' => self::NONCE_RULES,
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
        $nonce = $data['nonce'] ?? null;

        try {
            $audit = $this->storeOnce($nonce, 'audit_conns_client_nonce_unique', static fn (): AuditConn => AuditConn::create([
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
                'client_nonce' => $nonce,
                'closed_at' => $action === AuditConn::ACTION_CLOSE ? now() : null,
            ]));
        } catch (Throwable $e) {
            return $this->writeFailed($e, ['kind' => 'conn', 'peer_id' => $peerId]);
        }

        // A retried nonce is already stored: acknowledge it like the original write, but do not
        // repeat the alarm or the webhook. The identity + rate-limit guard above already ran, so
        // this path cannot be used to probe another device's records.
        if ($audit === null) {
            return $this->stored();
        }

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

        return $this->stored();
    }

    /**
     * POST /api/audit/alarm
     * Body: { id, uuid, typ:<int>, info:<json string|object> }, plus the optional nonce and the
     * conn_audit_ref that the whitelist alarms carry.
     */
    public function alarm(Request $request, AlarmService $alarms): JsonResponse|Response
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
            'conn_audit_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'nonce' => self::NONCE_RULES,
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
        $nonce = $data['nonce'] ?? null;

        try {
            // Only the row is written inside the transaction: the webhook and mail fan-out below
            // must not hold one open (a webhook is a synchronous outbound HTTP call).
            $alarm = $this->storeOnce($nonce, 'alarms_client_nonce_unique', fn (): Alarm => $alarms->create(
                $device,
                $peerId,
                $label,
                $message,
                $ip,
                $nonce,
                isset($data['conn_audit_ref']) && $data['conn_audit_ref'] !== '' ? $data['conn_audit_ref'] : null,
            ));
        } catch (Throwable $e) {
            return $this->writeFailed($e, ['kind' => 'alarm', 'peer_id' => $peerId, 'typ' => $typ]);
        }

        if ($alarm === null) {
            return $this->stored();
        }

        try {
            $alarms->notify($alarm, $device);
        } catch (Throwable $e) {
            Log::error('AlarmService notify failed in audit alarm', [
                'peer_id' => $peerId,
                'typ' => $typ,
                'error' => $e->getMessage(),
            ]);
        }

        return $this->stored();
    }

    /**
     * POST /api/audit/file
     * Body: { id, uuid, peer_id, info, is_file, path, type, ip, nonce }.
     */
    public function file(Request $request): JsonResponse|Response
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
            'nonce' => self::NONCE_RULES,
        ]);
        if ($data === null || strlen((string) ($data['info'] ?? '')) > 60000) {
            return $this->acknowledge();
        }

        $nonce = $data['nonce'] ?? null;

        try {
            // A duplicate nonce simply means the record is already stored — the acknowledgement
            // below is identical, and file records have no side effects to suppress.
            $this->storeOnce($nonce, 'audit_files_client_nonce_unique', static fn (): AuditFile => AuditFile::create([
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
                'client_nonce' => $nonce,
            ]));
        } catch (Throwable $e) {
            return $this->writeFailed($e, ['kind' => 'file', 'peer_id' => (string) $data['id']]);
        }

        return $this->stored();
    }

    /**
     * GET /api/audit/conn/active?id=&session_id=&conn_type=
     * Returns the live session's server-issued guid as a bare JSON string.
     */
    public function active(Request $request): JsonResponse
    {
        $peerId = $request->query('id');
        $sessionId = $request->query('session_id');

        if (! is_string($peerId) || $peerId === '' || mb_strlen($peerId) > 255
            || ! is_string($sessionId) || $sessionId === '' || mb_strlen($sessionId) > 255) {
            return response()->json('');
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return response()->json('');
        }

        $guid = '';
        $accessiblePeerIds = $this->accessiblePeerIds($user);
        if ($accessiblePeerIds !== []) {
            $guid = (string) (AuditConn::query()
                ->where('peer_id', $peerId)
                ->whereIn('peer_id', $accessiblePeerIds)
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
        $guid = $request->input('guid');
        $note = $request->input('note');

        if (! is_string($guid) || ! Str::isUuid($guid)
            || ! is_string($note) || mb_strlen($note) > 4000) {
            return $this->acknowledge();
        }

        /** @var User|null $user */
        $user = $request->user();
        if ($user === null) {
            return $this->acknowledge();
        }

        $accessiblePeerIds = $this->accessiblePeerIds($user);
        if ($accessiblePeerIds !== []) {
            AuditConn::query()
                ->where('guid', $guid)
                ->whereIn('peer_id', $accessiblePeerIds)
                ->update(['note' => $note]);
        }

        return $this->acknowledge();
    }

    /**
     * RustDesk peer ids the authenticated account may operate on.
     *
     * @return list<string>
     */
    private function accessiblePeerIds(User $user): array
    {
        return Device::query()
            ->whereIn('id', $this->access->accessibleDeviceIds($user))
            ->pluck('rustdesk_id')
            ->map(static fn ($peerId): string => (string) $peerId)
            ->values()
            ->all();
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

    /**
     * Write one audit record at most once per client nonce.
     *
     * The identity and rate-limit guard has already run, and the uniqueness is scoped to the
     * reporting device, so a duplicate can only be that device re-sending its own record — there
     * is no lookup the caller can probe. Returns null when the record was already stored.
     *
     * @template TRecord of \Illuminate\Database\Eloquent\Model
     *
     * @param  callable(): TRecord  $write
     * @return TRecord|null
     */
    private function storeOnce(?string $clientNonce, string $index, callable $write): mixed
    {
        try {
            // Single-row writes, but wrapped so a later failure inside the same unit of work can
            // never leave a committed row that keeps the nonce busy: a failed write releases it
            // for the client's next attempt.
            return DB::transaction($write);
        } catch (UniqueConstraintViolationException $e) {
            if ($clientNonce !== null && $e->index === $index) {
                return null;
            }

            throw $e;
        }
    }

    /**
     * A stored record is acknowledged with an empty 200 — the only response the 1.5.0+ client
     * accepts as success (it re-posts on any non-empty 2xx body). Pre-1.5.0 clients ignore the
     * body entirely.
     */
    private function stored(): Response
    {
        return response('', 200, ['Content-Length' => '0']);
    }

    /**
     * The `{}` no-op that every request storing nothing has always received: failed identity
     * binding, invalid fields, and rate-limited events are dropped silently in this shape rather
     * than told why. `PUT /api/audit` keeps it as its success shape too, since that client only
     * reads the status code.
     */
    private function acknowledge(): JsonResponse
    {
        return response()->json((object) []);
    }

    /**
     * A server-side storage failure answers 200 with a generic error object.
     *
     * The 1.5.0+ client retries this shape exactly as it retries a 5xx, and the failed write above
     * released the nonce, so the retry stores the record. It is preferred over 5xx because the
     * request was understood and the fault is ours: a 5xx is reported by any proxy or uptime
     * monitor in front of us as an upstream outage, and some proxies replace a 5xx body with their
     * own page, which the client would log as an "unexpected response body". No internal detail
     * leaves the process.
     *
     * @param  array<string, mixed>  $context
     */
    private function writeFailed(Throwable $e, array $context): JsonResponse
    {
        Log::error('Audit write failed', $context + ['error' => $e->getMessage()]);

        return response()->json(['error' => 'Audit record could not be stored']);
    }
}
