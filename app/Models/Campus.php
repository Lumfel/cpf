<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'code',
    'name',
    'slug',
    'current_academic_year',
    'adviser_name',
    'gc_group_name',
    'is_active',
    'mandatory_tour_enabled',
])]
class Campus extends Model
{
    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'mandatory_tour_enabled' => 'boolean',
        ];
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CampusMembership::class);
    }

    public function testingRoom(): HasOne
    {
        return $this->hasOne(CampusRoom::class)->where('is_single_allowed_room', true);
    }
}
