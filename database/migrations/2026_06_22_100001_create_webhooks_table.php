<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Outbound webhooks / notification targets. Each row subscribes to a set of server events
 * (alarms, connection open/close, new device) and delivers them to a Slack incoming webhook,
 * a Telegram bot, or a generic JSON endpoint (optionally HMAC-signed). See WebhookService.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('webhooks', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            // generic | slack | telegram
            $table->string('type', 16)->default('generic');
            $table->text('url');
            // HMAC signing secret (generic) or chat id (telegram); null = none.
            $table->string('secret')->nullable();
            $table->json('events');
            $table->boolean('enabled')->default(true);
            $table->timestamp('last_triggered_at')->nullable();
            // Last delivery outcome, e.g. "200", "timeout", "error".
            $table->string('last_status')->nullable();
            $table->unsignedInteger('failure_count')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('webhooks');
    }
};
