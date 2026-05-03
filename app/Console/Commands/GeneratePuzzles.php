<?php

namespace App\Console\Commands;

use App\Models\Puzzle;
use App\Services\Sudoku\Generator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GeneratePuzzles extends Command
{
    protected $signature = 'puzzle:generate
                            {difficulty : easy, medium, hard, or expert}
                            {--count=1 : Number of puzzles to generate}
                            {--schedule : Assign sequential publish_date values starting after the last scheduled date}';

    protected $description = 'Generate Sudoku puzzles and store them in the database';

    private const VALID_DIFFICULTIES = ['debug', 'easy', 'medium', 'hard', 'expert'];

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

        $schedule = $this->option('schedule');
        $nextDate = null;

        if ($schedule) {
            $last = Puzzle::where('type', 'sudoku')
                ->where('difficulty', $difficulty)
                ->whereNotNull('publish_date')
                ->max('publish_date');

            $nextDate = $last
                ? Carbon::parse($last)->addDay()
                : Carbon::today();
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
                'publish_date'  => $nextDate?->clone()->addDays($i),
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $suffix = $schedule ? " (scheduled from {$nextDate->toDateString()})" : '';
        $this->info("Done. {$count} {$difficulty} sudoku puzzle(s) added{$suffix}.");

        return Command::SUCCESS;
    }
}
