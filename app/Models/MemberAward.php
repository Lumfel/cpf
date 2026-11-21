<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'user_id',
    'award_code',
    'title',
    'award_category',
    'academic_year',
    'awarded_on',
    'citation',
    'issued_by_user_id',
    'blockchain_status',
    'credential_hash',
    'blockchain_ledger_id',
])]
class MemberAward extends Model
{
    protected function casts(): array
    {
        return [
            'awarded_on' => 'date:Y-m-d',
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

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(BlockchainCredentialLedger::class, 'blockchain_ledger_id');
    }
}
