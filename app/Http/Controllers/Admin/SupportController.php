<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\SupportReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The report an operator attaches to a bug report.
 *
 * Shown before it can be downloaded, and deliberately in that order. The text is going to
 * a public issue tracker, so the operator has to be able to read exactly what they are
 * about to publish — redaction reduces what escapes, but the last check is a person who
 * knows their own deployment.
 */
class SupportController extends Controller
{
    public function __construct(private readonly SupportReportService $reports) {}

    public function show(Request $request): View
    {
        return view('admin.support.index', [
            'report' => $this->reports->build($request),
            'version' => (string) config('app.version'),
        ]);
    }

    public function download(Request $request): Response
    {
        $name = 'rd-api-server-report-'.now()->format('Ymd-His').'.md';

        return response($this->reports->build($request))
            ->header('Content-Type', 'text/markdown; charset=utf-8')
            ->header('Content-Disposition', 'attachment; filename="'.$name.'"')
            // Contains an account of the deployment, redacted but not public: never let a
            // shared cache hold it.
            ->header('Cache-Control', 'no-store');
    }
}
