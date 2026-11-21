<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'ledger_id',
    'queried_identifier',
    'verified_by_user_id',
    'verifier_ip',
    'verification_result',
    'checked_at',
])]
class BlockchainVerificationLog extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
        ];
    }

    public function ledgerEntry(): BelongsTo
    {
        return $this->belongsTo(BlockchainCredentialLedger::class, 'ledger_id');
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_user_id');
    }
}
