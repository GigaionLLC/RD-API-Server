<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\RecordingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Session-recording chunked upload (docs/modernization/02-client-api-contract.md §5).
 *
 * Driven by the `type` query param; the request body is raw bytes for the part/tail phases.
 * Responds with {} on success or {"error":"<msg>"} on failure (any error aborts the upload).
 */
class RecordController extends Controller
{
    public function __construct(private readonly RecordingService $recordings) {}

    /**
     * POST /api/record?type=new|part|tail|remove&file=<name>&offset=<n>&length=<m>
     */
    public function store(Request $request): JsonResponse
    {
        $type = $request->query('type');
        $file = $request->query('file');

        if (! is_string($type) || ! in_array($type, ['new', 'part', 'tail', 'remove'], true)) {
            return $this->error('Unknown record type');
        }

        if (! is_string($file) || $file === '') {
            return $this->error('Missing file name');
        }

        $contentLength = $this->contentLength($request);
        if ($contentLength === false) {
            return $this->error('Invalid request body length');
        }
        if ($contentLength !== null && $contentLength > $this->recordings->maxChunkBytes()) {
            return $this->error('Recording chunk is too large', 413);
        }

        try {
            $result = match ($type) {
                'new' => $this->start($request, $file),
                'part' => $this->writePart($request, $file, $contentLength),
                'tail' => $this->writeTail($request, $file, $contentLength),
                'remove' => $this->remove($request, $file),
            };
        } catch (Throwable $exception) {
            report($exception);
            $result = ['error' => 'Recording upload failed'];
        }

        return response()->json($result === [] ? (object) [] : $result);
    }

    /**
     * @return array<string, mixed>
     */
    private function start(Request $request, string $file): array
    {
        if ($this->exactBody($request, 0) === null) {
            return ['error' => 'The recording start body must be empty'];
        }

        $peerId = $request->query('id', '');
        $fromPeer = $request->query('from', '');
        if (
            ! is_string($peerId)
            || strlen($peerId) > 255
            || ! is_string($fromPeer)
            || strlen($fromPeer) > 255
        ) {
            return ['error' => 'Invalid recording metadata'];
        }

        $connId = null;
        if ($request->query->has('conn_id')) {
            $connId = $this->nonNegativeInteger($request->query('conn_id'));
            if ($connId === null) {
                return ['error' => 'Invalid recording connection ID'];
            }
        }

        return $this->recordings->start(
            $file,
            $peerId !== '' ? $peerId : null,
            $fromPeer !== '' ? $fromPeer : null,
            $connId,
            (string) $request->ip(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writePart(Request $request, string $file, ?int $contentLength): array
    {
        $offset = $this->nonNegativeInteger($request->query('offset'));
        $length = $this->nonNegativeInteger($request->query('length'));
        if ($offset === null || $length === null) {
            return ['error' => 'Invalid recording chunk coordinates'];
        }

        if ($length > $this->recordings->maxChunkBytes()) {
            return ['error' => 'Recording chunk is too large'];
        }

        if ($contentLength !== null && $contentLength !== $length) {
            return ['error' => 'Recording chunk length does not match its body'];
        }

        $body = $this->exactBody($request, $length);
        if ($body === null) {
            return ['error' => 'Recording chunk length does not match its body'];
        }

        return $this->recordings->part(
            $file,
            $offset,
            $length,
            $body,
            (string) $request->ip(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function writeTail(Request $request, string $file, ?int $contentLength): array
    {
        $offset = $this->nonNegativeInteger($request->query('offset'));
        $length = $this->nonNegativeInteger($request->query('length'));
        if ($offset === null || $length === null) {
            return ['error' => 'Invalid recording header coordinates'];
        }

        if ($length > $this->recordings->maxHeaderBytes()) {
            return ['error' => 'Recording header is too large'];
        }

        if ($contentLength !== null && $contentLength !== $length) {
            return ['error' => 'Recording header length does not match its body'];
        }

        $body = $this->exactBody($request, $length);
        if ($body === null) {
            return ['error' => 'Recording header length does not match its body'];
        }

        return $this->recordings->tail(
            $file,
            $offset,
            $length,
            $body,
            (string) $request->ip(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function remove(Request $request, string $file): array
    {
        if ($this->exactBody($request, 0) === null) {
            return ['error' => 'The recording remove body must be empty'];
        }

        return $this->recordings->remove($file, (string) $request->ip());
    }

    private function error(string $message, int $status = 200): JsonResponse
    {
        return response()->json(['error' => $message], $status);
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (! is_string($value) || ! preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $value)) {
            return null;
        }

        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'max_range' => PHP_INT_MAX],
        ]);

        return is_int($parsed) ? $parsed : null;
    }

    /**
     * @return int|false|null false means malformed; null means no Content-Length was supplied
     */
    private function contentLength(Request $request): int|false|null
    {
        $value = $request->server('CONTENT_LENGTH');
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value) && ! is_int($value)) {
            return false;
        }

        return $this->nonNegativeInteger((string) $value) ?? false;
    }

    private function exactBody(Request $request, int $expectedBytes): ?string
    {
        $stream = $request->getContent(true);
        if (! is_resource($stream)) {
            return null;
        }

        $body = stream_get_contents($stream, $expectedBytes + 1);

        return is_string($body) && strlen($body) === $expectedBytes ? $body : null;
    }
}
