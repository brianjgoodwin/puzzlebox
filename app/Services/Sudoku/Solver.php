<?php

namespace App\Services\Sudoku;

/**
 * Backtracking Sudoku solver.
 *
 * Grid representation: flat 81-element int array, 0 = empty, 1–9 = given/placed.
 */
class Solver
{
    /**
     * Solve the puzzle. Returns the completed grid, or null if unsolvable.
     */
    public function solve(array $grid): ?array
    {
        if ($this->backtrack($grid)) {
            return $grid;
        }

        return null;
    }

    /**
     * Count how many solutions exist, stopping once $limit is reached.
     * Pass limit=2 to cheaply verify uniqueness: result === 1 means unique.
     */
    public function countSolutions(array $grid, int $limit = 2): int
    {
        $count = 0;
        $this->countBacktrack($grid, $count, $limit);

        return $count;
    }

    /**
     * Validate that a complete (no zeros) board satisfies all Sudoku rules.
     */
    public function isValidSolution(array $grid): bool
    {
        if (count($grid) !== 81 || in_array(0, $grid, true)) {
            return false;
        }

        $expected = range(1, 9);

        for ($i = 0; $i < 9; $i++) {
            $row = array_slice($grid, $i * 9, 9);
            sort($row);
            if ($row !== $expected) {
                return false;
            }

            $col = [];
            for ($r = 0; $r < 9; $r++) {
                $col[] = $grid[$r * 9 + $i];
            }
            sort($col);
            if ($col !== $expected) {
                return false;
            }

            // Box $i: row-band = intdiv($i,3)*3, col-band = ($i%3)*3
            $boxRow = intdiv($i, 3) * 3;
            $boxCol = ($i % 3) * 3;
            $box = [];
            for ($r = $boxRow; $r < $boxRow + 3; $r++) {
                for ($c = $boxCol; $c < $boxCol + 3; $c++) {
                    $box[] = $grid[$r * 9 + $c];
                }
            }
            sort($box);
            if ($box !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check whether placing $value at $pos violates any Sudoku constraint.
     * The cell at $pos must be empty (0) when this is called.
     */
    public function isValidPlacement(array $grid, int $pos, int $value): bool
    {
        $row = intdiv($pos, 9);
        $col = $pos % 9;

        // Row
        for ($c = 0; $c < 9; $c++) {
            if ($grid[$row * 9 + $c] === $value) {
                return false;
            }
        }

        // Column
        for ($r = 0; $r < 9; $r++) {
            if ($grid[$r * 9 + $col] === $value) {
                return false;
            }
        }

        // 3×3 box
        $boxRow = intdiv($row, 3) * 3;
        $boxCol = intdiv($col, 3) * 3;
        for ($r = $boxRow; $r < $boxRow + 3; $r++) {
            for ($c = $boxCol; $c < $boxCol + 3; $c++) {
                if ($grid[$r * 9 + $c] === $value) {
                    return false;
                }
            }
        }

        return true;
    }

    // -------------------------------------------------------------------------

    private function backtrack(array &$grid): bool
    {
        $pos = $this->findEmpty($grid);

        if ($pos === -1) {
            return true; // No empty cells — solved.
        }

        for ($val = 1; $val <= 9; $val++) {
            if ($this->isValidPlacement($grid, $pos, $val)) {
                $grid[$pos] = $val;

                if ($this->backtrack($grid)) {
                    return true;
                }

                $grid[$pos] = 0;
            }
        }

        return false;
    }

    private function countBacktrack(array $grid, int &$count, int $limit): void
    {
        if ($count >= $limit) {
            return;
        }

        $pos = $this->findEmpty($grid);

        if ($pos === -1) {
            $count++;
            return;
        }

        for ($val = 1; $val <= 9; $val++) {
            if ($count >= $limit) {
                return;
            }

            if ($this->isValidPlacement($grid, $pos, $val)) {
                $grid[$pos] = $val;
                $this->countBacktrack($grid, $count, $limit);
                $grid[$pos] = 0;
            }
        }
    }

    private function findEmpty(array $grid): int
    {
        $pos = array_search(0, $grid, true);

        return $pos === false ? -1 : $pos;
    }
}
