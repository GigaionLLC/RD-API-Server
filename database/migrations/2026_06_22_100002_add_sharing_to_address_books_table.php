<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mark address books as shareable team books, with an optional description note. Collaborators
 * (and their per-book permission rule) live in the address_book_collaborators table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('address_books', function (Blueprint $table): void {
            $table->boolean('is_shared')->default(false)->after('name');
            $table->string('note')->nullable()->after('is_shared');
        });
    }

    public function down(): void
    {
        Schema::table('address_books', function (Blueprint $table): void {
            $table->dropColumn(['is_shared', 'note']);
        });
    }
};
