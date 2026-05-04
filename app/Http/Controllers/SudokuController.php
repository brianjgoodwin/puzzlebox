<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\Puzzle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SudokuController extends Controller
{
    public function index(): View
    {
        $difficulties = app()->environment('production')
            ? ['easy', 'medium', 'hard', 'expert']
            : ['debug', 'easy', 'medium', 'hard', 'expert'];
        $today = now()->toDateString();

        $puzzles = [];
        foreach ($difficulties as $difficulty) {
            // Prefer today's scheduled puzzle; fall back to a random unscheduled one.
            $puzzle = Puzzle::where('type', 'sudoku')
                ->where('difficulty', $difficulty)
                ->where('publish_date', $today)
                ->first();

            $puzzle ??= Puzzle::where('type', 'sudoku')
                ->where('difficulty', $difficulty)
                ->whereNull('publish_date')
                ->inRandomOrder()
                ->first();

            $puzzles[$difficulty] = $puzzle;
        }

        return view('sudoku.index', compact('puzzles'));
    }

    public function show(Puzzle $puzzle): View
    {
        abort_if($puzzle->type !== 'sudoku', 404);

        return view('sudoku.show', compact('puzzle'));
    }

    /**
     * Find an existing incomplete session or create a new one.
     * Called by Alpine on page load before the first move.
     */
    public function startSession(Request $request, Puzzle $puzzle): JsonResponse
    {
        abort_if($puzzle->type !== 'sudoku', 404);

        $request->validate(['session_token' => 'required|uuid']);

        $token = $request->input('session_token');

        $session = GameSession::where('puzzle_id', $puzzle->id)
            ->where('session_token', $token)
            ->where('is_completed', false)
            ->latest()
            ->first();

        if (! $session) {
            $session = GameSession::create([
                'puzzle_id'     => $puzzle->id,
                'user_id'       => auth()->id(),
                'session_token' => $token,
                'board_state'   => [
                    'cells' => $puzzle->puzzle_data,
                    'notes' => array_fill(0, 81, []),
                ],
            ]);
        } elseif (auth()->check() && $session->user_id === null) {
            // Link an anonymous session to the user if they've since logged in.
            $session->update(['user_id' => auth()->id()]);
        }

        return response()->json([
            'session_id'      => $session->id,
            'board_state'     => $session->board_state,
            'elapsed_seconds' => $session->elapsed_seconds,
            'mistakes'        => $session->mistakes,
            'hints_used'      => $session->hints_used,
        ]);
    }

    /**
     * Autosave the current board state. Called every few seconds by Alpine.
     */
    public function saveSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token'              => 'required|uuid',
            'board_state'                => 'required|array',
            'board_state.cells'          => 'required|array|size:81',
            'board_state.notes'          => 'required|array|size:81',
            'elapsed_seconds'            => 'required|integer|min:0',
            'mistakes'                   => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $session->update([
            'board_state'     => $data['board_state'],
            'elapsed_seconds' => $data['elapsed_seconds'],
            'mistakes'        => $data['mistakes'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Validate the submitted solution server-side.
     * Returns which cells are wrong without revealing the full solution.
     */
    public function completeSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token'     => 'required|uuid',
            'board_state'       => 'required|array',
            'board_state.cells' => 'required|array|size:81',
            'board_state.notes' => 'required|array|size:81',
            'elapsed_seconds'   => 'required|integer|min:0',
            'mistakes'          => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $submitted = $data['board_state']['cells'];
        $solution  = $session->puzzle->solution_data;

        $wrongCells = [];
        foreach ($solution as $i => $correct) {
            if ($submitted[$i] !== $correct) {
                $wrongCells[] = $i;
            }
        }

        $session->update([
            'board_state'     => $data['board_state'],
            'elapsed_seconds' => $data['elapsed_seconds'],
            'mistakes'        => $data['mistakes'],
        ]);

        if (! empty($wrongCells)) {
            return response()->json([
                'ok'          => false,
                'wrong_cells' => $wrongCells,
            ]);
        }

        $session->is_completed = true;
        $session->completed_at = now();
        $session->save();

        return response()->json([
            'ok'   => true,
            'stats' => [
                'elapsed_seconds' => $session->elapsed_seconds,
                'mistakes'        => $session->mistakes,
                'hints_used'      => $session->hints_used,
            ],
        ]);
    }

    /**
     * Reveal one correct cell value.
     * Prefers the player's selected cell (if empty/wrong); falls back to a random one.
     * Increments hints_used on the session. Capped at config('puzzlebox.max_hints').
     */
    public function hintSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token' => 'required|uuid',
            'cells'         => 'required|array|size:81',
            'selected'      => 'nullable|integer|min:0|max:80',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $maxHints = config('puzzlebox.max_hints');
        if ($maxHints !== null && $session->hints_used >= $maxHints) {
            return response()->json(['ok' => false, 'message' => 'Hint limit reached.'], 422);
        }

        $solution = $session->puzzle->solution_data;
        $cells    = $data['cells'];
        $selected = $data['selected'];

        // Build list of cells that are empty or wrong.
        $hintable = [];
        foreach ($cells as $i => $val) {
            if ($val !== $solution[$i]) {
                $hintable[] = $i;
            }
        }

        if (empty($hintable)) {
            return response()->json(['ok' => false, 'message' => 'No cells to hint.'], 422);
        }

        // Use the selected cell if it's hintable, otherwise pick randomly.
        $index = ($selected !== null && in_array($selected, $hintable, true))
            ? $selected
            : $hintable[array_rand($hintable)];

        $session->increment('hints_used');

        return response()->json([
            'ok'    => true,
            'index' => $index,
            'value' => $solution[$index],
        ]);
    }

    /**
     * Check submitted cells against the stored solution without modifying the session.
     * Returns wrong cell indices for any filled cell that doesn't match the solution.
     * Empty cells are ignored — a partial board can still return ok: true.
     */
    public function checkSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token' => 'required|uuid',
            'cells'         => 'required|array|size:81',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $submitted = $data['cells'];
        $solution  = $session->puzzle->solution_data;

        $wrongCells = [];
        foreach ($submitted as $i => $val) {
            if ($val !== null && $val !== $solution[$i]) {
                $wrongCells[] = $i;
            }
        }

        return response()->json([
            'ok'          => empty($wrongCells),
            'wrong_cells' => $wrongCells,
        ]);
    }

    /**
     * Return the full solution for a session — local debug tool only.
     * Gated by SUDOKU_SOLVER_ENABLED in .env (never set in production).
     */
    public function solveSession(Request $request, GameSession $session): JsonResponse
    {
        abort_unless(config('puzzlebox.sudoku_solver_enabled'), 404);

        $request->validate(['session_token' => 'required|uuid']);
        $this->authorizeSession($session, $request->input('session_token'));

        return response()->json([
            'solution' => $session->puzzle->solution_data,
        ]);
    }

    private function authorizeSession(GameSession $session, string $token): void
    {
        $authorized = auth()->check()
            ? $session->user_id === auth()->id() ||
              ($session->user_id === null && $session->session_token === $token)
            : $session->session_token === $token;

        abort_unless($authorized, 403);
    }
}
