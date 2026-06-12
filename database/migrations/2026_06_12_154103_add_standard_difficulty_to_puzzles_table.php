<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE puzzles MODIFY COLUMN difficulty ENUM('debug','easy','medium','hard','expert','standard') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE puzzles MODIFY COLUMN difficulty ENUM('debug','easy','medium','hard','expert') NOT NULL");
    }
};
