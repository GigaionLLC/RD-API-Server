<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Recording;
use App\Services\AdminScopeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Session recordings: list, download (streamed from storage/app/recordings), and delete.
 */
class RecordingController extends Controller
{
    /** Directory (under storage/app) where recording files live. */
    private const STORAGE_DIR = 'recordings';

    public function __construct(private readonly AdminScopeService $scope) {}

    public function index(Request $request): View
    {
        $q = trim((string) $request->query('q', ''));
        $status = trim((string) $request->query('status', ''));

        $recordings = $this->scope->scopePeerRecords(
            Recording::query(),
            $request->user(),
            'recordings.view',
        )
            ->when($q !== '', function ($query) use ($q) {
                $query->where(fn ($search) => $search
                    ->where('peer_id', 'like', "%{$q}%")
                    ->orWhere('from_peer', 'like', "%{$q}%")
                    ->orWhere('filename', 'like', "%{$q}%"));
            })
            ->when($status !== '', fn ($query) => $query->where('status', $status))
            ->orderByDesc('started_at')
            ->paginate(30)
            ->appends($request->query());

        return view('admin.recordings.index', compact('recordings', 'q', 'status'));
    }

    public function download(Request $request, Recording $recording): StreamedResponse
    {
        $this->scope->authorizePeerId($request->user(), (string) $recording->peer_id, 'recordings.view');
        $fullPath = $this->resolvePath($recording);

        abort_if($fullPath === null || ! is_file($fullPath), 404, 'Recording file not found.');

        return response()->streamDownload(function () use ($fullPath): void {
            $stream = fopen($fullPath, 'rb');
            if ($stream !== false) {
                fpassthru($stream);
                fclose($stream);
            }
        }, basename($recording->filename));
    }

    public function destroy(Request $request, Recording $recording): RedirectResponse
    {
        $this->scope->authorizePeerId($request->user(), (string) $recording->peer_id, 'recordings.edit');
        $fullPath = $this->resolvePath($recording);

        if ($fullPath !== null && is_file($fullPath)) {
            @unlink($fullPath);
        }

        $recording->delete();

        return redirect()
            ->route('admin.recordings.index')
            ->with('status', 'Recording deleted.');
    }

    /**
     * Resolve the on-disk path for a recording while guarding against path traversal.
     *
     * Only the bare filename is honoured (any directory components are stripped) and the
     * resolved path is verified to live inside the recordings storage directory.
     */
    private function resolvePath(Recording $recording): ?string
    {
        $filename = basename((string) $recording->filename);

        if ($filename === '' || $filename === '.' || $filename === '..') {
            return null;
        }

        $baseDir = storage_path('app/'.self::STORAGE_DIR);
        $candidate = $baseDir.DIRECTORY_SEPARATOR.$filename;

        $realBase = realpath($baseDir);
        $realCandidate = realpath($candidate);

        // The directory or file may not exist yet; fall back to the candidate path but still
        // ensure no traversal sneaks in via the stripped filename.
        if ($realCandidate === false) {
            return $candidate;
        }

        if ($realBase === false || ! str_starts_with($realCandidate, $realBase.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $realCandidate;
    }
}
