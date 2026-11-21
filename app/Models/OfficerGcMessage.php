<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['channel_id', 'sender_user_id', 'sender_role', 'message_body', 'attachment_hdd_path'])]
class OfficerGcMessage extends Model
{
    public $timestamps = false;
    public function sender(): BelongsTo { return $this->belongsTo(User::class, 'sender_user_id'); }
}
