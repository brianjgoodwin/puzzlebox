<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropUnique('puzzles_publish_date_unique');
            $table->unique(['type', 'difficulty', 'publish_date']);
        });
    }

    public function down(): void
    {
        Schema::table('puzzles', function (Blueprint $table) {
            $table->dropUnique(['type', 'difficulty', 'publish_date']);
            $table->unique('publish_date');
        });
    }
};
