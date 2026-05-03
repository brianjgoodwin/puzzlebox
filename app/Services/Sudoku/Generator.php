<?php

namespace App\Services\Sudoku;

/**
 * Generates valid, uniquely-solvable Sudoku puzzles.
 *
 * Process:
 *   1. Fill a blank grid with a valid complete solution (randomized backtracking).
 *   2. Remove cells one at a time in random order, checking after each removal
 *      that the puzzle still has exactly one solution.
 *   3. Stop when the target clue count for the requested difficulty is reached,
 *      or when no further cells can be removed without breaking uniqueness.
 *
 * Grid representation: flat 81-element int array, 0 = empty, 1–9 = placed.
 * Puzzle data returned uses null for empty cells (matches DB schema).
 */
class Generator
{
    /**
     * Target number of clues (given cells) per difficulty.
     * The algorithm removes cells until this count is reached or uniqueness
     * would be violated — so the actual count may be slightly higher.
     */
    private const CLUE_TARGETS = [
        'debug'  => 78, // Only 3 blank cells — for testing
        'easy'   => 46,
        'medium' => 34,
        'hard'   => 28,
        'expert' => 24,
    ];

    public function __construct(private readonly Solver $solver) {}

    /**
     * Generate a puzzle at the given difficulty.
     *
     * @param  string  $difficulty  easy|medium|hard|expert
     * @return array{puzzle: array, solution: array}
     *   - puzzle:   81-element array, null = blank cell
     *   - solution: 81-element array, 1–9 everywhere
     */
    public function generate(string $difficulty): array
    {
        $target = self::CLUE_TARGETS[$difficulty]
            ?? throw new \InvalidArgumentException("Unknown difficulty: {$difficulty}");

        $solution = $this->fillGrid(array_fill(0, 81, 0));
        $puzzle   = $this->removeClues($solution, $target);

        return [
            'puzzle'   => array_map(fn ($v) => $v === 0 ? null : $v, $puzzle),
            'solution' => $solution,
        ];
    }

    // -------------------------------------------------------------------------

    /**
     * Fill a grid using randomized backtracking to produce a random valid solution.
     */
    private function fillGrid(array $grid): array
    {
        $pos = array_search(0, $grid, true);

        if ($pos === false) {
            return $grid; // Complete.
        }

        $values = range(1, 9);
        shuffle($values);

        foreach ($values as $val) {
            if ($this->solver->isValidPlacement($grid, $pos, $val)) {
                $grid[$pos] = $val;
                $result = $this->fillGrid($grid);

                if (!empty($result)) {
                    return $result;
                }

                $grid[$pos] = 0;
            }
        }

        return []; // Signal backtrack.
    }

    /**
     * Remove cells from a complete solution until $targetClues remain,
     * skipping any removal that would create a non-unique puzzle.
     */
    private function removeClues(array $grid, int $targetClues): array
    {
        $positions = range(0, 80);
        shuffle($positions);

        $cluesRemaining = 81;

        foreach ($positions as $pos) {
            if ($cluesRemaining <= $targetClues) {
                break;
            }

            $saved      = $grid[$pos];
            $grid[$pos] = 0;

            if ($this->solver->countSolutions($grid) !== 1) {
                $grid[$pos] = $saved; // Would break uniqueness — restore.
            } else {
                $cluesRemaining--;
            }
        }

        return $grid;
    }
}
