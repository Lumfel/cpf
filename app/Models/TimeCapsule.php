<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'user_id', 'title', 'encrypted_message', 'unlock_at', 'is_opened', 'opened_at'])]
class TimeCapsule extends Model
{
    protected function casts(): array
    {
        return ['encrypted_message' => 'encrypted', 'unlock_at' => 'datetime', 'is_opened' => 'boolean', 'opened_at' => 'datetime'];
    }
}
