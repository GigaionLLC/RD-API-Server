<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * RustDesk 1.5.0 turned the three audit feeds into a retried, deduplicated protocol (#15759):
 * every /api/audit/conn, /api/audit/file and /api/audit/alarm body now carries a `nonce`
 * (UUID v4) that is unique per record, and the client re-posts the same body when a response
 * body is not empty. The client documents the server half of that contract — a record's nonce
 * is remembered for five minutes and released when its write fails — so the nonce has to become
 * a stored, enforced fact rather than a hint.
 *
 * Each table gets:
 *  - client_nonce, nullable: pre-1.5.0 clients send no nonce at all, and a unique index ignores
 *    NULL, so those rows keep their old behaviour.
 *  - a composite unique index scoped to the reporting device, not the nonce alone. A retry is
 *    always the same device re-sending the same record, so scoping on the device is enough to
 *    dedupe, and it means one device can never make another device's record fail to store (it
 *    cannot even mount that, since it would have to guess a UUID v4 minted on another host).
 *  - alarms additionally get conn_audit_ref: since 1.5.0 the IpWhitelist and IdWhitelist alarms
 *    carry the same controller-attribution token /api/audit/conn already stores.
 */
return new class extends Migration
{
    public function up(): void
    {
        // peer_id is the reporting (controlled) device for connection records.
        Schema::table('audit_conns', function (Blueprint $table): void {
            $table->string('client_nonce', 64)->nullable()->after('uuid');
            $table->unique(['peer_id', 'client_nonce'], 'audit_conns_client_nonce_unique');
        });

        // File-transfer records report the sending device in from_peer; peer_id is the remote one.
        Schema::table('audit_files', function (Blueprint $table): void {
            $table->string('client_nonce', 64)->nullable()->after('uuid');
            $table->unique(['from_peer', 'client_nonce'], 'audit_files_client_nonce_unique');
        });

        Schema::table('alarms', function (Blueprint $table): void {
            $table->string('client_nonce', 64)->nullable()->after('ip');
            $table->string('conn_audit_ref')->nullable()->after('client_nonce');
            $table->unique(['peer_id', 'client_nonce'], 'alarms_client_nonce_unique');
        });
    }

    public function down(): void
    {
        Schema::table('audit_conns', function (Blueprint $table): void {
            $table->dropUnique('audit_conns_client_nonce_unique');
            $table->dropColumn('client_nonce');
        });

        Schema::table('audit_files', function (Blueprint $table): void {
            $table->dropUnique('audit_files_client_nonce_unique');
            $table->dropColumn('client_nonce');
        });

        Schema::table('alarms', function (Blueprint $table): void {
            $table->dropUnique('alarms_client_nonce_unique');
            $table->dropColumn(['client_nonce', 'conn_audit_ref']);
        });
    }
};
