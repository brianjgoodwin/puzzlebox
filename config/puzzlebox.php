<?php

return [

    /*
     * Show the in-game Solve button for instant board completion.
     * For local testing only — never enable in production.
     */
    'sudoku_solver_enabled' => env('SUDOKU_SOLVER_ENABLED', false),

    /*
     * Maximum hints a player may use per puzzle session.
     * Set to null for unlimited (not recommended in production).
     */
    'max_hints' => env('SUDOKU_MAX_HINTS', 10),

];
