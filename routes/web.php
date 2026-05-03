<?php

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
Route::post('/sudoku/{puzzle}/session', [SudokuController::class, 'startSession'])->name('sudoku.session.start');
Route::patch('/sudoku/sessions/{session}', [SudokuController::class, 'saveSession'])->name('sudoku.session.save');
Route::post('/sudoku/sessions/{session}/complete', [SudokuController::class, 'completeSession'])->name('sudoku.session.complete');

require __DIR__.'/auth.php';
