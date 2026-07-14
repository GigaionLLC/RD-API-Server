<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ldap_identities', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('provider', 100);
            $table->char('subject_hash', 64);
            $table->timestamps();

            $table->unique(
                ['provider', 'subject_hash'],
                'ldap_identities_provider_subject_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ldap_identities');
    }
};
