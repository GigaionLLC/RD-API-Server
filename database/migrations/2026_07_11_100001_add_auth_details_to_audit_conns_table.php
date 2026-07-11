<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RustDesk 1.4.9 enriched the connection-audit payload the controlled client POSTs to
 * /api/audit/conn with three new optional keys:
 *
 *  - primary_auth  (int)    how the session was authorized — PR #15456 (ConnAuditPrimaryAuth:
 *                           1=Click, 2=TemporaryPassword, 3=PermanentPassword, 4=SwitchSides;
 *                           the key is omitted client-side when None/0).
 *  - two_factor    (int)    second factor — PR #15456 (ConnAuditTwoFactor: 1=Totp,
 *                           2=TrustedDevice; omitted when None/0).
 *  - conn_audit_ref (string) opaque controller-user attribution token minted by hbbs and
 *                           echoed back by the controlled peer — PR #15407. Stored as-is;
 *                           full user resolution additionally needs hbbs-side work.
 *
 * All three are nullable: pre-1.4.9 clients and close events simply leave them null, so the
 * ingest stays backward-compatible. See docs/modernization/16-response-contract.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_conns', function (Blueprint $table): void {
            $table->unsignedTinyInteger('primary_auth')->nullable()->after('type');
            $table->unsignedTinyInteger('two_factor')->nullable()->after('primary_auth');
            $table->string('conn_audit_ref')->nullable()->index()->after('two_factor');
        });
    }

    public function down(): void
    {
        Schema::table('audit_conns', function (Blueprint $table): void {
            $table->dropColumn(['primary_auth', 'two_factor', 'conn_audit_ref']);
        });
    }
};
