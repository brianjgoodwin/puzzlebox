<?php

namespace App\Console\Commands;

use App\Models\Puzzle;
use App\Services\Sudoku\Generator;
use Illuminate\Console\Command;

class GeneratePuzzles extends Command
{
    protected $signature = 'puzzle:generate
                            {difficulty : easy, medium, hard, or expert}
                            {--count=1 : Number of puzzles to generate}';

    protected $description = 'Generate Sudoku puzzles and store them in the database';

    private const VALID_DIFFICULTIES = ['easy', 'medium', 'hard', 'expert'];

    public function handle(Generator $generator): int
    {
        $difficulty = $this->argument('difficulty');
        $count      = (int) $this->option('count');

        if (! in_array($difficulty, self::VALID_DIFFICULTIES, true)) {
            $this->error('Invalid difficulty. Choose from: ' . implode(', ', self::VALID_DIFFICULTIES));

            return Command::FAILURE;
        }

        if ($count < 1) {
            $this->error('--count must be at least 1.');

            return Command::FAILURE;
        }

        $this->info("Generating {$count} {$difficulty} puzzle(s)…");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $data = $generator->generate($difficulty);

            Puzzle::create([
                'type'          => 'sudoku',
                'difficulty'    => $difficulty,
                'puzzle_data'   => $data['puzzle'],
                'solution_data' => $data['solution'],
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Done. {$count} {$difficulty} sudoku puzzle(s) added.");

        return Command::SUCCESS;
    }
}
