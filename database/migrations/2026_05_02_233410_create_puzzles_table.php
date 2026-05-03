<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('puzzles', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['sudoku', 'cryptogram', 'kenken'])->index();
            $table->enum('difficulty', ['debug', 'easy', 'medium', 'hard', 'expert']);
            $table->json('puzzle_data');   // starting state (nulls = blanks for sudoku)
            $table->json('solution_data'); // complete solved state
            $table->date('publish_date')->nullable()->unique(); // for daily puzzle concept
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('puzzles');
    }
};
