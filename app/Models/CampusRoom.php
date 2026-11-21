<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['campus_id', 'room_code', 'room_name', 'capacity', 'is_single_allowed_room'])]
class CampusRoom extends Model
{
    protected function casts(): array
    {
        return ['is_single_allowed_room' => 'boolean'];
    }

    public function campus(): BelongsTo { return $this->belongsTo(Campus::class); }
    public function requests(): HasMany { return $this->hasMany(RoomRequest::class, 'room_id'); }
}
