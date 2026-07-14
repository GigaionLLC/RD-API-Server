<?php

namespace App\Services;

use App\Models\Recording;
use Throwable;

/**
 * Session-recording chunked upload handling
 * (docs/modernization/02-client-api-contract.md §5).
 *
 * Uploads are strictly sequential and bound to the source address that created them. The
 * surrounding route supplies the fail-closed authorization and request-rate boundaries.
 */
class RecordingService
{
    /** Maximum header length accepted in the `tail` phase (fixed by the client contract). */
    private const MAX_HEADER = 1024;

    private const MAX_FILENAME_BYTES = 200;

    private const LOCK_FILE = '.recording-upload.lock';

    private const DEFAULT_MAX_CHUNK_BYTES = 8 * 1024 * 1024;

    private const DEFAULT_MAX_FILE_BYTES = 2 * 1024 * 1024 * 1024;

    private const DEFAULT_MAX_TOTAL_BYTES = 10 * 1024 * 1024 * 1024;

    private const DEFAULT_MAX_TOTAL_FILES = 5000;

    private const DEFAULT_MAX_ACTIVE_PER_SOURCE = 4;

    /**
     * Absolute directory recordings are written to.
     */
    public function storageDir(): string
    {
        return storage_path('app/recordings');
    }

    /**
     * Validate the client-supplied filename without normalising distinct inputs together.
     */
    public function safeName(string $name): string
    {
        if ($name === '' || strlen($name) > self::MAX_FILENAME_BYTES) {
            return '';
        }

        $normalised = str_replace('\\', '/', $name);
        if ($normalised !== basename($normalised)) {
            return '';
        }

        if (! preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]*\z/D', $normalised)) {
            return '';
        }

        return $normalised;
    }

    public function pathFor(string $safeName): string
    {
        return $this->storageDir().DIRECTORY_SEPARATOR.$safeName;
    }

    public function maxChunkBytes(): int
    {
        return $this->limit('max_chunk_bytes', self::DEFAULT_MAX_CHUNK_BYTES);
    }

    public function maxHeaderBytes(): int
    {
        return self::MAX_HEADER;
    }

    /**
     * Phase "new": begin an upload without ever replacing an existing file or row.
     *
     * @return array<string, mixed> response payload ({} on success, {error} on failure)
     */
    public function start(
        string $name,
        ?string $peerId,
        ?string $fromPeer,
        ?int $connId,
        string $sourceIp,
    ): array {
        $safe = $this->safeName($name);
        if ($safe === '') {
            return ['error' => 'Invalid file name'];
        }

        if ($sourceIp === '' || strlen($sourceIp) > 45) {
            return ['error' => 'Invalid upload source'];
        }

        return $this->withStorageLock(function () use (
            $safe,
            $peerId,
            $fromPeer,
            $connId,
            $sourceIp,
        ): array {
            $path = $this->pathFor($safe);

            if (file_exists($path) || is_link($path) || Recording::where('filename', $safe)->exists()) {
                return ['error' => 'Recording already exists'];
            }

            if (Recording::count() >= $this->limit('max_total_files', self::DEFAULT_MAX_TOTAL_FILES)) {
                return ['error' => 'Recording file quota exceeded'];
            }

            $activeForSource = Recording::where('source_ip', $sourceIp)
                ->where('status', 'recording')
                ->count();
            if ($activeForSource >= $this->limit(
                'max_active_per_source',
                self::DEFAULT_MAX_ACTIVE_PER_SOURCE,
            )) {
                return ['error' => 'Too many active recording uploads'];
            }

            if ($this->trackedBytes() >= $this->limit(
                'max_total_bytes',
                self::DEFAULT_MAX_TOTAL_BYTES,
            )) {
                return ['error' => 'Recording storage quota exceeded'];
            }

            $handle = @fopen($path, 'x+b');
            if ($handle === false) {
                return ['error' => 'Could not create recording file'];
            }
            fclose($handle);

            try {
                Recording::create([
                    'peer_id' => $peerId ?: '',
                    'from_peer' => $fromPeer,
                    'conn_id' => $connId,
                    'source_ip' => $sourceIp,
                    'filename' => $safe,
                    'path' => $path,
                    'size' => 0,
                    'status' => 'recording',
                    'started_at' => now(),
                    'finished_at' => null,
                ]);
            } catch (Throwable $exception) {
                @unlink($path);
                report($exception);

                return ['error' => 'Could not track recording upload'];
            }

            return [];
        });
    }

    /**
     * Phase "part": append one exact body chunk at the current end of the file.
     *
     * @return array<string, mixed>
     */
    public function part(
        string $name,
        int $offset,
        int $length,
        string $body,
        string $sourceIp,
    ): array {
        $safe = $this->safeName($name);
        if (
            $safe === ''
            || $offset < 0
            || $length <= 0
            || $length > $this->maxChunkBytes()
            || strlen($body) !== $length
        ) {
            return ['error' => 'Invalid recording chunk'];
        }

        return $this->withStorageLock(function () use (
            $safe,
            $offset,
            $length,
            $body,
            $sourceIp,
        ): array {
            $recording = $this->activeUpload($safe, $sourceIp);
            $path = $this->pathFor($safe);

            if (! $recording || ! $this->isRegularFile($path)) {
                return ['error' => 'Recording not started'];
            }

            clearstatcache(true, $path);
            $currentSize = filesize($path);
            if ($currentSize === false || $offset !== $currentSize) {
                return ['error' => 'Invalid recording offset'];
            }

            $maxFileBytes = $this->limit('max_file_bytes', self::DEFAULT_MAX_FILE_BYTES);
            if ($length > $maxFileBytes || $offset > $maxFileBytes - $length) {
                return ['error' => 'Recording file size limit exceeded'];
            }
            $projectedSize = $offset + $length;

            $maxTotalBytes = $this->limit('max_total_bytes', self::DEFAULT_MAX_TOTAL_BYTES);
            $otherTrackedBytes = max(0, $this->trackedBytes() - max(0, (int) $recording->size));
            if ($projectedSize > $maxTotalBytes || $otherTrackedBytes > $maxTotalBytes - $projectedSize) {
                return ['error' => 'Recording storage quota exceeded'];
            }

            $handle = @fopen($path, 'ab');
            if ($handle === false) {
                return ['error' => 'Could not open recording file'];
            }

            try {
                if (! $this->writeAll($handle, $body)) {
                    $this->touchSize($recording, $path);

                    return ['error' => 'Could not write recording chunk'];
                }
            } finally {
                fclose($handle);
            }

            clearstatcache(true, $path);
            $writtenSize = filesize($path);
            if ($writtenSize === false || $writtenSize !== $projectedSize) {
                $this->touchSize($recording, $path);

                return ['error' => 'Could not verify recording chunk'];
            }

            $recording->forceFill(['size' => $writtenSize])->save();

            return [];
        });
    }

    /**
     * Phase "tail": patch the final header at offset zero and mark the upload finished.
     *
     * @return array<string, mixed>
     */
    public function tail(
        string $name,
        int $offset,
        int $length,
        string $body,
        string $sourceIp,
    ): array {
        $safe = $this->safeName($name);
        if (
            $safe === ''
            || $offset !== 0
            || $length < 0
            || $length > self::MAX_HEADER
            || strlen($body) !== $length
        ) {
            return ['error' => 'Invalid recording header'];
        }

        return $this->withStorageLock(function () use ($safe, $length, $body, $sourceIp): array {
            $recording = $this->activeUpload($safe, $sourceIp);
            $path = $this->pathFor($safe);

            if (! $recording || ! $this->isRegularFile($path)) {
                return ['error' => 'Recording not started'];
            }

            clearstatcache(true, $path);
            $currentSize = filesize($path);
            if ($currentSize === false || $length > $currentSize) {
                return ['error' => 'Invalid recording header'];
            }

            $handle = @fopen($path, 'r+b');
            if ($handle === false) {
                return ['error' => 'Could not open recording file'];
            }

            try {
                if (fseek($handle, 0) !== 0 || ! $this->writeAll($handle, $body)) {
                    return ['error' => 'Could not write recording header'];
                }
            } finally {
                fclose($handle);
            }

            $recording->forceFill([
                'size' => $currentSize,
                'status' => 'finished',
                'finished_at' => now(),
            ])->save();

            return [];
        });
    }

    /**
     * Phase "remove": abort only an active upload created by the same source address.
     *
     * @return array<string, mixed>
     */
    public function remove(string $name, string $sourceIp): array
    {
        $safe = $this->safeName($name);
        if ($safe === '') {
            return ['error' => 'Invalid file name'];
        }

        return $this->withStorageLock(function () use ($safe, $sourceIp): array {
            $recording = $this->activeUpload($safe, $sourceIp);
            $path = $this->pathFor($safe);

            if (! $recording || ! $this->isRegularFile($path)) {
                return ['error' => 'Recording not started'];
            }

            if (! @unlink($path)) {
                return ['error' => 'Could not remove recording file'];
            }

            $recording->delete();

            return [];
        });
    }

    /**
     * @param  callable(): array<string, mixed>  $callback
     * @return array<string, mixed>
     */
    private function withStorageLock(callable $callback): array
    {
        if (! $this->ensureDir()) {
            return ['error' => 'Could not create recording directory'];
        }

        $handle = @fopen($this->pathFor(self::LOCK_FILE), 'c');
        if ($handle === false) {
            return ['error' => 'Could not lock recording storage'];
        }

        $locked = false;

        try {
            $locked = @flock($handle, LOCK_EX);
            if (! $locked) {
                return ['error' => 'Could not lock recording storage'];
            }

            return $callback();
        } finally {
            if ($locked) {
                @flock($handle, LOCK_UN);
            }
            fclose($handle);
        }
    }

    private function ensureDir(): bool
    {
        $dir = $this->storageDir();

        return is_dir($dir) || (@mkdir($dir, 0775, true) && is_dir($dir));
    }

    private function activeUpload(string $safe, string $sourceIp): ?Recording
    {
        if ($sourceIp === '') {
            return null;
        }

        return Recording::where('filename', $safe)
            ->where('source_ip', $sourceIp)
            ->where('status', 'recording')
            ->latest('id')
            ->first();
    }

    private function isRegularFile(string $path): bool
    {
        return is_file($path) && ! is_link($path);
    }

    /**
     * @param  resource  $handle
     */
    private function writeAll($handle, string $body): bool
    {
        $length = strlen($body);
        $written = 0;

        while ($written < $length) {
            $count = fwrite($handle, substr($body, $written));
            if ($count === false || $count === 0) {
                return false;
            }
            $written += $count;
        }

        return true;
    }

    private function touchSize(Recording $recording, string $path): void
    {
        clearstatcache(true, $path);
        $size = filesize($path);
        if ($size !== false) {
            $recording->forceFill(['size' => $size])->save();
        }
    }

    private function trackedBytes(): int
    {
        return max(0, (int) Recording::sum('size'));
    }

    private function limit(string $key, int $fallback): int
    {
        $configured = (int) config('recordings.upload.'.$key, $fallback);

        return $configured > 0 ? $configured : $fallback;
    }
}
