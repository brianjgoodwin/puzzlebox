<?php

namespace App\Http\Controllers;

use App\Models\GameSession;
use App\Models\Puzzle;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CryptogramController extends Controller
{
    public function index(): View
    {
        $today = now()->toDateString();

        $puzzle = Puzzle::where('type', 'cryptogram')
            ->where('difficulty', 'standard')
            ->where('publish_date', $today)
            ->first();

        $puzzle ??= Puzzle::where('type', 'cryptogram')
            ->where('difficulty', 'standard')
            ->whereNull('publish_date')
            ->inRandomOrder()
            ->first();

        return view('cryptogram.index', compact('puzzle'));
    }

    public function show(Puzzle $puzzle): View
    {
        abort_if($puzzle->type !== 'cryptogram', 404);

        return view('cryptogram.show', compact('puzzle'));
    }

    /**
     * Find an existing incomplete session or create a new one.
     * board_state.guesses maps cipher letter → player's guessed plain letter.
     */
    public function startSession(Request $request, Puzzle $puzzle): JsonResponse
    {
        abort_if($puzzle->type !== 'cryptogram', 404);

        $request->validate(['session_token' => 'required|uuid']);

        $token = $request->input('session_token');

        $session = GameSession::where('puzzle_id', $puzzle->id)
            ->where('session_token', $token)
            ->where('is_completed', false)
            ->latest()
            ->first();

        if (! $session) {
            $revealed = $puzzle->puzzle_data['revealed'] ?? [];
            $mapping  = $puzzle->solution_data['mapping'] ?? [];

            // Pre-populate guesses for revealed cipher letters.
            $guesses = [];
            foreach ($revealed as $cipherLetter) {
                $guesses[$cipherLetter] = $mapping[$cipherLetter] ?? null;
            }

            $session = GameSession::create([
                'puzzle_id'     => $puzzle->id,
                'user_id'       => auth()->id(),
                'session_token' => $token,
                'board_state'   => ['guesses' => $guesses],
            ]);
        } elseif (auth()->check() && $session->user_id === null) {
            $session->update(['user_id' => auth()->id()]);
        }

        return response()->json([
            'session_id'      => $session->id,
            'board_state'     => $session->board_state,
            'elapsed_seconds' => $session->elapsed_seconds,
            'hints_used'      => $session->hints_used,
        ]);
    }

    /**
     * Autosave the current guess state.
     */
    public function saveSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token'        => 'required|uuid',
            'board_state'          => 'required|array',
            'board_state.guesses'  => 'required|array',
            'elapsed_seconds'      => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $session->update([
            'board_state'     => $data['board_state'],
            'elapsed_seconds' => $data['elapsed_seconds'],
        ]);

        return response()->json(['ok' => true]);
    }

    /**
     * Check the player's current guesses and complete the session if fully correct.
     */
    public function completeSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token'       => 'required|uuid',
            'board_state'         => 'required|array',
            'board_state.guesses' => 'required|array',
            'elapsed_seconds'     => 'required|integer|min:0',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $guesses = $data['board_state']['guesses'];
        $mapping = $session->puzzle->solution_data['mapping'];

        $wrongLetters = [];
        foreach ($mapping as $cipher => $plain) {
            $guess = $guesses[$cipher] ?? null;
            if ($guess !== null && strtoupper($guess) !== $plain) {
                $wrongLetters[] = $cipher;
            }
        }

        // Check completeness: every cipher letter that appears in the ciphertext must be guessed.
        $ciphertext      = $session->puzzle->puzzle_data['ciphertext'];
        $usedCipherLetters = array_unique(str_split(preg_replace('/[^A-Z]/', '', $ciphertext)));
        $missingLetters    = [];
        foreach ($usedCipherLetters as $cipher) {
            if (empty($guesses[$cipher])) {
                $missingLetters[] = $cipher;
            }
        }

        $session->update([
            'board_state'     => $data['board_state'],
            'elapsed_seconds' => $data['elapsed_seconds'],
        ]);

        if (! empty($wrongLetters) || ! empty($missingLetters)) {
            return response()->json([
                'ok'             => false,
                'wrong_letters'  => $wrongLetters,
                'missing_letters' => $missingLetters,
            ]);
        }

        $session->is_completed = true;
        $session->completed_at = now();
        $session->save();

        return response()->json([
            'ok'    => true,
            'stats' => [
                'elapsed_seconds' => $session->elapsed_seconds,
                'hints_used'      => $session->hints_used,
            ],
        ]);
    }

    /**
     * Reveal one cipher letter's correct plain-text mapping.
     */
    public function hintSession(Request $request, GameSession $session): JsonResponse
    {
        $data = $request->validate([
            'session_token' => 'required|uuid',
            'guesses'       => 'required|array',
        ]);

        $this->authorizeSession($session, $data['session_token']);
        abort_if($session->is_completed, 409);

        $maxHints = config('puzzlebox.max_hints');
        if ($maxHints !== null && $session->hints_used >= $maxHints) {
            return response()->json(['ok' => false, 'message' => 'Hint limit reached.'], 422);
        }

        $mapping  = $session->puzzle->solution_data['mapping'];
        $guesses  = $data['guesses'];

        // Only hint cipher letters that are still wrong or empty.
        $hintable = [];
        foreach ($mapping as $cipher => $plain) {
            $guess = $guesses[$cipher] ?? null;
            if ($guess === null || strtoupper($guess) !== $plain) {
                $hintable[] = $cipher;
            }
        }

        if (empty($hintable)) {
            return response()->json(['ok' => false, 'message' => 'No letters to hint.'], 422);
        }

        $cipher = $hintable[array_rand($hintable)];
        $session->increment('hints_used');

        return response()->json([
            'ok'     => true,
            'cipher' => $cipher,
            'plain'  => $mapping[$cipher],
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
