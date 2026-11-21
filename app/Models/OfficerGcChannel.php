<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campus_id', 'academic_year', 'channel_key', 'is_active', 'reset_at'])]
class OfficerGcChannel extends Model
{
    protected function casts(): array { return ['is_active' => 'boolean', 'reset_at' => 'datetime']; }
    public function messages(): HasMany { return $this->hasMany(OfficerGcMessage::class, 'channel_id'); }
}
