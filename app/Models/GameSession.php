<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameSession extends Model
{
    protected $fillable = [
        'puzzle_id', 'user_id', 'session_token',
        'board_state', 'hints_used', 'mistakes',
        'elapsed_seconds', 'is_completed', 'completed_at',
    ];

    protected $casts = [
        'board_state' => 'array',
        'is_completed' => 'boolean',
        'completed_at' => 'datetime',
    ];

    public function puzzle(): BelongsTo
    {
        return $this->belongsTo(Puzzle::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
