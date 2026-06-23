<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\Device;
use App\Services\AlarmService;
use App\Services\WebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Audit ingestion endpoints (docs/modernization/02-client-api-contract.md §8,
 * docs/modernization/13-deepscan-connection.md).
 *
 * Unauthenticated: hbbs and clients POST connection, file-transfer and security-alarm events
 * here. We persist them as AuditConn / AuditFile / Alarm rows.
 */
class AuditController extends Controller
{
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
    ];

    /**
     * POST /api/audit/conn
     * Two shapes:
     *  - host connection event:  { id, action:"new"|"close", conn_id, peer:[id,name]?, ip, session_id, type, uuid }
     *  - operator session note:  { id, session_id, note }   (no `action`)
     */
    public function conn(Request $request, AlarmService $alarms, WebhookService $webhooks): JsonResponse
    {
        // Operator session note (posted by the controlling side) — attach to the open session.
        $note = $request->input('note');
        $sessionId = (string) $request->input('session_id', '');
        if ($note !== null && ! $request->has('action')) {
            if ($sessionId !== '') {
                AuditConn::where('session_id', $sessionId)
                    ->where('action', AuditConn::ACTION_NEW)
                    ->latest('id')
                    ->first()
                    ?->update(['note' => (string) $note]);
            }

            return response()->json((object) []);
        }

        $peer = $request->input('peer', []);
        $peer = is_array($peer) ? $peer : [];

        $action = (string) $request->input('action', AuditConn::ACTION_NEW);
        $peerId = (string) $request->input('id', '');
        $ip = (string) $request->input('ip', $request->ip());

        AuditConn::create([
            // New connections get a guid the controlling client later looks up via
            // GET /api/audit/conn/active to attach an end-of-session note (see active()).
            'guid' => $action === AuditConn::ACTION_NEW ? (string) Str::uuid() : null,
            'action' => $action,
            'conn_id' => (int) $request->input('conn_id', 0),
            'peer_id' => $peerId,
            'from_peer' => (string) ($peer[0] ?? ''),
            'from_name' => (string) ($peer[1] ?? ''),
            'ip' => $ip,
            'session_id' => $sessionId,
            'type' => (int) $request->input('type', 0),
            'uuid' => (string) $request->input('uuid', ''),
            'closed_at' => $action === AuditConn::ACTION_CLOSE ? now() : null,
        ]);

        // Raise an alarm for newly established connections. Wrapped so audit never fails.
        if ($action === AuditConn::ACTION_NEW && $peerId !== '') {
            try {
                $device = Device::where('rustdesk_id', $peerId)->first();
                $fromName = (string) ($peer[1] ?? $peer[0] ?? '');
                $alarms->raise(
                    $device,
                    $peerId,
                    'new_connection',
                    'New connection to '.$peerId.($fromName !== '' ? ' from '.$fromName : '').' ('.$ip.')',
                    $ip
                );
            } catch (Throwable $e) {
                Log::error('AlarmService raise failed in audit conn', [
                    'peer_id' => $peerId,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Notify webhooks of the session lifecycle (distinct from the alarm above).
        $webhooks->dispatch(
            $action === AuditConn::ACTION_CLOSE ? 'connection.closed' : 'connection.new',
            [
                'peer_id' => $peerId,
                'from' => (string) ($peer[1] ?? $peer[0] ?? ''),
                'ip' => $ip,
                'session_id' => $sessionId,
            ]
        );

        return response()->json((object) []);
    }

    /**
     * POST /api/audit/alarm
     * Body: { id, uuid, typ:<int>, info:<json string|object> } — connection security alarms.
     */
    public function alarm(Request $request, AlarmService $alarms): JsonResponse
    {
        $peerId = (string) $request->input('id', '');
        $typ = (int) $request->input('typ', -1);

        $info = $request->input('info', '');
        if (is_array($info)) {
            $info = json_encode($info);
        }
        $info = (string) $info;

        $label = self::ALARM_TYPES[$typ] ?? ('Security alarm (type '.$typ.')');
        $message = $info !== '' ? $label.': '.$info : $label;
        $ip = (string) $request->input('ip', $request->ip());

        try {
            $device = $peerId !== '' ? Device::where('rustdesk_id', $peerId)->first() : null;
            $alarms->raise($device, $peerId, $label, $message, $ip);
        } catch (Throwable $e) {
            Log::error('AlarmService raise failed in audit alarm', [
                'peer_id' => $peerId,
                'typ' => $typ,
                'error' => $e->getMessage(),
            ]);
        }

        return response()->json((object) []);
    }

    /**
     * POST /api/audit/file
     * Body: { id, peer_id, info, is_file, path, type, ip, uuid }.
     */
    public function file(Request $request): JsonResponse
    {
        AuditFile::create([
            'peer_id' => (string) ($request->input('peer_id') ?? $request->input('id') ?? ''),
            'from_peer' => (string) $request->input('id', ''),
            'from_name' => (string) $request->input('from_name', ''),
            'info' => (string) $request->input('info', ''),
            'is_file' => $request->boolean('is_file', true),
            'path' => (string) $request->input('path', ''),
            'type' => (int) $request->input('type', 0),
            'ip' => (string) $request->input('ip', $request->ip()),
            'num' => (int) $request->input('num', 0),
            'uuid' => (string) $request->input('uuid', ''),
        ]);

        return response()->json((object) []);
    }

    /**
     * GET /api/audit/conn/active?id=&session_id=&conn_type=
     * The controlling client polls this on a fresh connection to obtain the server-issued
     * guid for the live session, which it caches and later uses to PUT a note (see note()).
     * Returns the guid as a bare JSON string, or "" while the matching conn audit hasn't
     * landed yet — the client retries with backoff (model.dart _queryAuditGuid).
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
     * PUT /api/audit — { guid, note }
     * Attaches an operator's end-of-connection note to the audit record identified by the
     * guid issued in active(). The client only checks for HTTP 200 (dialog.dart).
     */
    public function note(Request $request): JsonResponse
    {
        $guid = (string) $request->input('guid', '');
        $note = (string) $request->input('note', '');

        if ($guid !== '') {
            AuditConn::where('guid', $guid)->update(['note' => $note]);
        }

        return response()->json((object) []);
    }
}
