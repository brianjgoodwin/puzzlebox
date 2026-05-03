<?php

namespace Tests\Unit\Services\Sudoku;

use App\Services\Sudoku\Generator;
use App\Services\Sudoku\Solver;
use PHPUnit\Framework\TestCase;

class GeneratorTest extends TestCase
{
    private Generator $generator;
    private Solver $solver;

    protected function setUp(): void
    {
        $this->solver    = new Solver;
        $this->generator = new Generator($this->solver);
    }

    // -- Structure ------------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('difficultyProvider')]
    public function test_generate_returns_correct_structure(string $difficulty): void
    {
        $result = $this->generator->generate($difficulty);

        $this->assertArrayHasKey('puzzle', $result);
        $this->assertArrayHasKey('solution', $result);
        $this->assertCount(81, $result['puzzle']);
        $this->assertCount(81, $result['solution']);
    }

    // -- Solution validity ----------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('difficultyProvider')]
    public function test_solution_is_valid(string $difficulty): void
    {
        $result = $this->generator->generate($difficulty);

        $this->assertTrue(
            $this->solver->isValidSolution($result['solution']),
            "Solution grid failed validation for difficulty: {$difficulty}"
        );
    }

    // -- Puzzle uses nulls for blanks -----------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('difficultyProvider')]
    public function test_puzzle_uses_null_for_empty_cells(string $difficulty): void
    {
        $result = $this->generator->generate($difficulty);

        foreach ($result['puzzle'] as $cell) {
            $this->assertTrue(
                $cell === null || (is_int($cell) && $cell >= 1 && $cell <= 9),
                "Unexpected cell value: " . var_export($cell, true)
            );
        }
    }

    // -- Puzzle solution consistency ------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('difficultyProvider')]
    public function test_puzzle_cells_match_solution(string $difficulty): void
    {
        $result = $this->generator->generate($difficulty);

        foreach ($result['puzzle'] as $i => $cell) {
            if ($cell !== null) {
                $this->assertSame(
                    $result['solution'][$i],
                    $cell,
                    "Clue at position {$i} does not match solution"
                );
            }
        }
    }

    // -- Uniqueness -----------------------------------------------------------

    #[\PHPUnit\Framework\Attributes\DataProvider('difficultyProvider')]
    public function test_puzzle_has_exactly_one_solution(string $difficulty): void
    {
        $result = $this->generator->generate($difficulty);

        // Convert null → 0 for the solver.
        $grid = array_map(fn ($v) => $v ?? 0, $result['puzzle']);

        $this->assertSame(
            1,
            $this->solver->countSolutions($grid),
            "Puzzle for difficulty '{$difficulty}' does not have a unique solution"
        );
    }

    // -- Clue counts ----------------------------------------------------------

    public function test_easy_puzzle_has_enough_clues(): void
    {
        $result = $this->generator->generate('easy');
        $clues  = count(array_filter($result['puzzle'], fn ($v) => $v !== null));

        // Easy should leave at least 36 clues; we target 46 but uniqueness
        // checks may leave slightly more.
        $this->assertGreaterThanOrEqual(36, $clues);
    }

    public function test_expert_puzzle_has_fewer_clues_than_easy(): void
    {
        $easy   = $this->generator->generate('easy');
        $expert = $this->generator->generate('expert');

        $easyClues   = count(array_filter($easy['puzzle'], fn ($v) => $v !== null));
        $expertClues = count(array_filter($expert['puzzle'], fn ($v) => $v !== null));

        $this->assertLessThan($easyClues, $expertClues);
    }

    // -- Invalid input --------------------------------------------------------

    public function test_generate_throws_for_unknown_difficulty(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->generator->generate('impossible');
    }

    // -------------------------------------------------------------------------

    public static function difficultyProvider(): array
    {
        return [
            'easy'   => ['easy'],
            'medium' => ['medium'],
            'hard'   => ['hard'],
            'expert' => ['expert'],
        ];
    }
}
