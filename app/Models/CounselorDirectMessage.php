<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'thread_key',
    'sender_user_id',
    'recipient_user_id',
    'sender_role',
    'message_body',
])]
class CounselorDirectMessage extends Model
{
    public static function makeThreadKey(int $userIdA, int $userIdB): string
    {
        $low = min($userIdA, $userIdB);
        $high = max($userIdA, $userIdB);

        return "{$low}-{$high}";
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }
}
