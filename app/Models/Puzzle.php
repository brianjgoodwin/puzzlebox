<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Puzzle extends Model
{
    protected $fillable = ['type', 'difficulty', 'puzzle_data', 'solution_data', 'publish_date'];

    protected $hidden = ['solution_data'];

    protected $casts = [
        'puzzle_data' => 'array',
        'solution_data' => 'array',
        'publish_date' => 'date',
    ];

    public function gameSessions(): HasMany
    {
        return $this->hasMany(GameSession::class);
    }
}
