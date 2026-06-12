<?php

use App\Http\Controllers\CryptogramController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\SudokuController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

// Sudoku game — no auth required to play
Route::get('/sudoku', [SudokuController::class, 'index'])->name('sudoku.index');
Route::get('/sudoku/{puzzle}', [SudokuController::class, 'show'])->name('sudoku.show');

// Session start: once per puzzle load; 20/min is generous headroom for normal use
Route::post('/sudoku/{puzzle}/session', [SudokuController::class, 'startSession'])
    ->middleware('throttle:20,1')
    ->name('sudoku.session.start');

// Autosave: fires every 4 s; 60/min = 4× normal rate, enough buffer for fast play
Route::patch('/sudoku/sessions/{session}', [SudokuController::class, 'saveSession'])
    ->middleware('throttle:60,1')
    ->name('sudoku.session.save');

// Completion / check: low volume in normal play; 20/min prevents brute-forcing
Route::post('/sudoku/sessions/{session}/complete', [SudokuController::class, 'completeSession'])
    ->middleware('throttle:20,1')
    ->name('sudoku.session.complete');
Route::post('/sudoku/sessions/{session}/check', [SudokuController::class, 'checkSession'])
    ->middleware('throttle:20,1')
    ->name('sudoku.session.check');

// Hints: reasonable per-minute ceiling; 30/min ~ one every 2 s
Route::post('/sudoku/sessions/{session}/hint', [SudokuController::class, 'hintSession'])
    ->middleware('throttle:30,1')
    ->name('sudoku.session.hint');

// Solver: debug only, tight limit
Route::post('/sudoku/sessions/{session}/solve', [SudokuController::class, 'solveSession'])
    ->middleware('throttle:10,1')
    ->name('sudoku.session.solve');

// Cryptogram game — no auth required to play
Route::get('/cryptogram', [CryptogramController::class, 'index'])->name('cryptogram.index');
Route::get('/cryptogram/{puzzle}', [CryptogramController::class, 'show'])->name('cryptogram.show');

Route::post('/cryptogram/{puzzle}/session', [CryptogramController::class, 'startSession'])
    ->middleware('throttle:20,1')
    ->name('cryptogram.session.start');

Route::patch('/cryptogram/sessions/{session}', [CryptogramController::class, 'saveSession'])
    ->middleware('throttle:60,1')
    ->name('cryptogram.session.save');

Route::post('/cryptogram/sessions/{session}/complete', [CryptogramController::class, 'completeSession'])
    ->middleware('throttle:20,1')
    ->name('cryptogram.session.complete');

Route::post('/cryptogram/sessions/{session}/hint', [CryptogramController::class, 'hintSession'])
    ->middleware('throttle:30,1')
    ->name('cryptogram.session.hint');

require __DIR__.'/auth.php';
