<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'campus_id',
    'academic_year',
    'title',
    'status',
    'current_position',
    'current_phase',
    'round_number',
    'position_results',
    'opens_at',
    'closes_at',
    'created_by_user_id',
])]
class Election extends Model
{
    protected function casts(): array
    {
        return [
            'opens_at' => 'datetime',
            'closes_at' => 'datetime',
            'round_number' => 'integer',
            'position_results' => 'array',
        ];
    }
}
