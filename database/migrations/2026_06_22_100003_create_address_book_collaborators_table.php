<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-user access grants on a shared address book. `rule` follows the RustDesk client's
 * ShareRule vocabulary: 1 = read, 2 = read/write, 3 = full control.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('address_book_collaborators', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('address_book_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rule')->default(1);
            $table->timestamps();
            $table->unique(['address_book_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('address_book_collaborators');
    }
};
