<?php

namespace App\Console\Commands;

use App\Models\Alarm;
use App\Models\AuditConn;
use App\Models\AuditFile;
use App\Models\LoginLog;
use App\Models\SsoRoleSyncLog;
use Illuminate\Console\Command;

/**
 * Delete audit history (connection / file / login logs + alarms) older than the configured
 * retention window. A no-op when RUSTDESK_AUDIT_RETENTION_DAYS is 0 (keep forever). Scheduled
 * daily; also runnable on demand:
 *   php artisan audit:prune --days=90
 */
class PruneAudit extends Command
{
    protected $signature = 'audit:prune {--days= : Override the retention window (days)}';

    protected $description = 'Delete audit logs and alarms older than the retention window';

    public function handle(): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('rustdesk.audit_retention_days', 0);

        if ($days <= 0) {
            $this->info('Audit retention is disabled (keep forever); nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($days);
        $total = 0;

        // Role synchronization writes a row per effective change or diagnosable skip, at login
        // rate, so it grows with the same shape as the audit feeds and prunes on the same window.
        foreach ([AuditConn::class, AuditFile::class, LoginLog::class, Alarm::class, SsoRoleSyncLog::class] as $model) {
            $total += $model::where('created_at', '<', $cutoff)->delete();
        }

        $this->info("Pruned {$total} audit rows older than {$days} days.");

        return self::SUCCESS;
    }
}
