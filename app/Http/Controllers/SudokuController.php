<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\Puzzle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SudokuController extends Controller
{
    public function index(): RedirectResponse
    {
        $puzzle = Puzzle::where('type', 'sudoku')->inRandomOrder()->first();

        abort_unless($puzzle, 404, 'No Sudoku puzzles available yet. Run: php artisan puzzle:generate easy');

        return redirect()->route('sudoku.show', $puzzle);
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
        $request->validate(['session_token' => 'required|string|max:64']);

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
        ]);
    }

    /**
     * Autosave the current board state. Called every few seconds by Alpine.
     */
    public function saveSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token'              => 'required|string|max:64',
            'board_state'                => 'required|array',
            'board_state.cells'          => 'required|array|size:81',
            'board_state.notes'          => 'required|array|size:81',
            'elapsed_seconds'            => 'required|integer|min:0',
            'mistakes'                   => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);

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
            'session_token'     => 'required|string|max:64',
            'board_state'       => 'required|array',
            'board_state.cells' => 'required|array|size:81',
            'board_state.notes' => 'required|array|size:81',
            'elapsed_seconds'   => 'required|integer|min:0',
            'mistakes'          => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);

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

        $session->update([
            'is_completed' => true,
            'completed_at' => now(),
        ]);

        return response()->json([
            'ok'   => true,
            'stats' => [
                'elapsed_seconds' => $session->elapsed_seconds,
                'mistakes'        => $session->mistakes,
                'hints_used'      => $session->hints_used,
            ],
        ]);
    }

    private function authorizeSession(GameSession $session, string $token): void
    {
        $authorized = auth()->check()
            ? $session->user_id === auth()->id() || $session->session_token === $token
            : $session->session_token === $token;

        abort_unless($authorized, 403);
    }
}
