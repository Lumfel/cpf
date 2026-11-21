<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'block_index',
    'campus_id',
    'recipient_user_id',
    'issuer_user_id',
    'credential_type',
    'credential_id',
    'credential_code',
    'canonical_payload',
    'payload_hash',
    'previous_block_hash',
    'block_hash',
    'driver',
    'network',
    'transaction_hash',
    'contract_address',
    'status',
    'anchored_at',
    'revoked_at',
    'revocation_reason',
])]
class BlockchainCredentialLedger extends Model
{
    protected function casts(): array
    {
        return [
            'canonical_payload' => 'array',
            'anchored_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issuer_user_id');
    }

    public function verificationLogs(): HasMany
    {
        return $this->hasMany(BlockchainVerificationLog::class, 'ledger_id');
    }
}
