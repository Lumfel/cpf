<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['election_id', 'candidate_id', 'voter_user_id', 'position', 'round_number', 'cast_at'])]
class ElectionVote extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'round_number' => 'integer',
        ];
    }
}
