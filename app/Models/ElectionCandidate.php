<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['election_id', 'user_id', 'position', 'platform', 'nominated_by_user_id', 'nomination_status', 'is_tie_contender'])]
class ElectionCandidate extends Model
{
    protected function casts(): array
    {
        return [
            'is_tie_contender' => 'boolean',
        ];
    }
}
