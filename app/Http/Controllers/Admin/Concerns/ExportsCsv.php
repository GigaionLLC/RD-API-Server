<?php

namespace App\Http\Controllers\Admin\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a filtered Eloquent query to a CSV download. Chunked so large exports stay
 * memory-safe; the filename is timestamped.
 */
trait ExportsCsv
{
    /**
     * @template TModel of Model
     *
     * @param  list<string>  $headers
     * @param  Builder<TModel>  $query
     * @param  callable(TModel): list<mixed>  $row
     */
    protected function streamCsv(string $name, array $headers, Builder $query, callable $row): StreamedResponse
    {
        $filename = $name.'-'.now()->format('Ymd-His').'.csv';

        return response()->streamDownload(function () use ($headers, $query, $row): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            // cursor() streams one row at a time from a single query, so it stays memory-safe
            // for large exports while preserving the query's own ordering.
            foreach ($query->cursor() as $record) {
                fputcsv($out, array_map(fn ($value) => $this->safeCsvCell($value), $row($record)));
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv']);
    }

    /**
     * Neutralize spreadsheet formulas while keeping the original value visible in exports.
     * Leading ASCII controls/space and a UTF-8 BOM are ignored for detection because common
     * spreadsheet applications may discard them before evaluating a cell.
     */
    private function safeCsvCell(mixed $value): mixed
    {
        if ($value === null) {
            return '';
        }

        if (! is_string($value)) {
            return $value;
        }

        $candidate = ltrim($value, "\x00..\x20");
        while (str_starts_with($candidate, "\xEF\xBB\xBF")) {
            $candidate = ltrim(substr($candidate, 3), "\x00..\x20");
        }

        if ($candidate !== '' && str_contains('=+-@', $candidate[0])) {
            return "'".$value;
        }

        return $value;
    }
}
