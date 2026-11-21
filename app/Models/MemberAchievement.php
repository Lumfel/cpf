<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'user_id',
    'achievement_code',
    'title',
    'category',
    'achieved_on',
    'description',
    'verified_by_user_id',
    'blockchain_status',
    'credential_hash',
    'blockchain_ledger_id',
])]
class MemberAchievement extends Model
{
    protected function casts(): array
    {
        return [
            'achieved_on' => 'date:Y-m-d',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(BlockchainCredentialLedger::class, 'blockchain_ledger_id');
    }
}
