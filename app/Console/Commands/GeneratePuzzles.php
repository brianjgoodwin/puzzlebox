<?php

namespace App\Console\Commands;

use App\Models\Puzzle;
use App\Services\Cryptogram\Generator as CryptogramGenerator;
use App\Services\Sudoku\Generator as SudokuGenerator;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class GeneratePuzzles extends Command
{
    protected $signature = 'puzzle:generate
                            {type : sudoku or cryptogram}
                            {difficulty? : (sudoku only) easy, medium, hard, or expert}
                            {--count=1 : Number of puzzles to generate}
                            {--schedule : Assign sequential publish_date values starting after the last scheduled date}';

    protected $description = 'Generate puzzles and store them in the database';

    private const SUDOKU_DIFFICULTIES = ['debug', 'easy', 'medium', 'hard', 'expert'];

    public function handle(SudokuGenerator $sudokuGenerator, CryptogramGenerator $cryptogramGenerator): int
    {
        $type  = $this->argument('type');
        $count = (int) $this->option('count');

        if ($count < 1) {
            $this->error('--count must be at least 1.');

            return Command::FAILURE;
        }

        return match ($type) {
            'sudoku'     => $this->generateSudoku($sudokuGenerator, $count),
            'cryptogram' => $this->generateCryptogram($cryptogramGenerator, $count),
            default      => $this->invalidType($type),
        };
    }

    private function generateSudoku(SudokuGenerator $generator, int $count): int
    {
        $difficulty = $this->argument('difficulty');

        if (! $difficulty) {
            $this->error('difficulty is required for sudoku. Choose from: ' . implode(', ', self::SUDOKU_DIFFICULTIES));

            return Command::FAILURE;
        }

        if (! in_array($difficulty, self::SUDOKU_DIFFICULTIES, true)) {
            $this->error('Invalid difficulty. Choose from: ' . implode(', ', self::SUDOKU_DIFFICULTIES));

            return Command::FAILURE;
        }

        $nextDate = $this->resolveNextDate('sudoku', $difficulty);

        $this->info("Generating {$count} {$difficulty} sudoku puzzle(s)…");
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
        $suffix = $nextDate ? " (scheduled from {$nextDate->toDateString()})" : '';
        $this->info("Done. {$count} {$difficulty} sudoku puzzle(s) added{$suffix}.");

        return Command::SUCCESS;
    }

    private function generateCryptogram(CryptogramGenerator $generator, int $count): int
    {
        $nextDate = $this->resolveNextDate('cryptogram', 'standard');

        $this->info("Generating {$count} cryptogram puzzle(s)…");
        $bar = $this->output->createProgressBar($count);
        $bar->start();

        for ($i = 0; $i < $count; $i++) {
            $data = $generator->generate();

            Puzzle::create([
                'type'          => 'cryptogram',
                'difficulty'    => 'standard',
                'puzzle_data'   => $data['puzzle'],
                'solution_data' => $data['solution'],
                'publish_date'  => $nextDate?->clone()->addDays($i),
            ]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $suffix = $nextDate ? " (scheduled from {$nextDate->toDateString()})" : '';
        $this->info("Done. {$count} cryptogram puzzle(s) added{$suffix}.");

        return Command::SUCCESS;
    }

    private function resolveNextDate(string $type, string $difficulty): ?Carbon
    {
        if (! $this->option('schedule')) {
            return null;
        }

        $last = Puzzle::where('type', $type)
            ->where('difficulty', $difficulty)
            ->whereNotNull('publish_date')
            ->max('publish_date');

        return $last
            ? Carbon::parse($last)->addDay()
            : Carbon::today();
    }

    private function invalidType(string $type): int
    {
        $this->error("Invalid type '{$type}'. Choose from: sudoku, cryptogram");

        return Command::FAILURE;
    }
}
