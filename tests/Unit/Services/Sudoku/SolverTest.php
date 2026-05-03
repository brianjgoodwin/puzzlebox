<?php

namespace Tests\Unit\Services\Sudoku;

use App\Services\Sudoku\Solver;
use PHPUnit\Framework\TestCase;

class SolverTest extends TestCase
{
    private Solver $solver;

    // A well-known puzzle with exactly one solution.
    // 0 = empty cell.
    private const PUZZLE = [
        5, 3, 0,  0, 7, 0,  0, 0, 0,
        6, 0, 0,  1, 9, 5,  0, 0, 0,
        0, 9, 8,  0, 0, 0,  0, 6, 0,

        8, 0, 0,  0, 6, 0,  0, 0, 3,
        4, 0, 0,  8, 0, 3,  0, 0, 1,
        7, 0, 0,  0, 2, 0,  0, 0, 6,

        0, 6, 0,  0, 0, 0,  2, 8, 0,
        0, 0, 0,  4, 1, 9,  0, 0, 5,
        0, 0, 0,  0, 8, 0,  0, 7, 9,
    ];

    private const SOLUTION = [
        5, 3, 4,  6, 7, 8,  9, 1, 2,
        6, 7, 2,  1, 9, 5,  3, 4, 8,
        1, 9, 8,  3, 4, 2,  5, 6, 7,

        8, 5, 9,  7, 6, 1,  4, 2, 3,
        4, 2, 6,  8, 5, 3,  7, 9, 1,
        7, 1, 3,  9, 2, 4,  8, 5, 6,

        9, 6, 1,  5, 3, 7,  2, 8, 4,
        2, 8, 7,  4, 1, 9,  6, 3, 5,
        3, 4, 5,  2, 8, 6,  1, 7, 9,
    ];

    protected function setUp(): void
    {
        $this->solver = new Solver;
    }

    // -- solve() --------------------------------------------------------------

    public function test_solve_returns_correct_solution(): void
    {
        $result = $this->solver->solve(self::PUZZLE);

        $this->assertSame(self::SOLUTION, $result);
    }

    public function test_solve_returns_null_for_unsolvable_puzzle(): void
    {
        // Two 5s in the first row — no solution possible.
        $bad = self::PUZZLE;
        $bad[2] = 5;

        $this->assertNull($this->solver->solve($bad));
    }

    public function test_solve_returns_grid_unchanged_when_already_complete(): void
    {
        $result = $this->solver->solve(self::SOLUTION);

        $this->assertSame(self::SOLUTION, $result);
    }

    // -- countSolutions() -----------------------------------------------------

    public function test_count_solutions_returns_1_for_unique_puzzle(): void
    {
        $this->assertSame(1, $this->solver->countSolutions(self::PUZZLE));
    }

    public function test_count_solutions_returns_2_for_underconstrained_puzzle(): void
    {
        // Remove enough clues from a known unique puzzle to guarantee
        // multiple solutions exist (empty grid has many).
        $empty = array_fill(0, 81, 0);

        $this->assertSame(2, $this->solver->countSolutions($empty));
    }

    public function test_count_solutions_returns_0_for_unsolvable_puzzle(): void
    {
        $bad = self::PUZZLE;
        $bad[2] = 5; // Conflict in row 0.

        $this->assertSame(0, $this->solver->countSolutions($bad));
    }

    // -- isValidSolution() ----------------------------------------------------

    public function test_is_valid_solution_returns_true_for_correct_board(): void
    {
        $this->assertTrue($this->solver->isValidSolution(self::SOLUTION));
    }

    public function test_is_valid_solution_returns_false_when_row_has_duplicate(): void
    {
        $bad = self::SOLUTION;
        $bad[1] = 5; // Row 0 now has two 5s.

        $this->assertFalse($this->solver->isValidSolution($bad));
    }

    public function test_is_valid_solution_returns_false_when_column_has_duplicate(): void
    {
        $bad = self::SOLUTION;
        $bad[9] = 5; // Column 0 now has two 5s (positions 0 and 9).

        $this->assertFalse($this->solver->isValidSolution($bad));
    }

    public function test_is_valid_solution_returns_false_when_board_has_empty_cells(): void
    {
        $incomplete = self::PUZZLE; // Has zeros.

        $this->assertFalse($this->solver->isValidSolution($incomplete));
    }

    // -- isValidPlacement() ---------------------------------------------------

    public function test_is_valid_placement_allows_legal_value(): void
    {
        // Position 2 (row 0, col 2) is empty; value 4 is the correct answer
        // and should be a legal placement.
        $this->assertTrue($this->solver->isValidPlacement(self::PUZZLE, 2, 4));
    }

    public function test_is_valid_placement_rejects_row_conflict(): void
    {
        // 5 already exists in row 0.
        $this->assertFalse($this->solver->isValidPlacement(self::PUZZLE, 2, 5));
    }

    public function test_is_valid_placement_rejects_column_conflict(): void
    {
        // 6 already exists in column 2 (position 20 = row 2, col 2 has 8;
        // position 11 = row 1, col 2 is empty; col 2 contains 8 at pos 20).
        // Use position 11 (row 1, col 2) and value 8 which is in the column.
        $this->assertFalse($this->solver->isValidPlacement(self::PUZZLE, 11, 8));
    }

    public function test_is_valid_placement_rejects_box_conflict(): void
    {
        // Position 11 (row 1, col 2) is in the top-left box which contains 5,3,6,9,8.
        // Value 9 is already in that box.
        $this->assertFalse($this->solver->isValidPlacement(self::PUZZLE, 11, 9));
    }
}
