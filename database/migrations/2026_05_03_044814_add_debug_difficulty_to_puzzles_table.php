<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Adds 'debug' to the difficulty enum on the puzzles table.
 *
 * SQLite does not enforce enum constraints, so no DDL change is needed there.
 * On MySQL the enum column must be explicitly altered.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE puzzles MODIFY COLUMN difficulty ENUM('debug','easy','medium','hard','expert') NOT NULL");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE puzzles MODIFY COLUMN difficulty ENUM('easy','medium','hard','expert') NOT NULL");
        }
    }
};
