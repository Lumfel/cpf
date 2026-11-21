<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['referral_id', 'actor_user_id', 'action', 'ip_address'])]
class ReferralAuditLog extends Model
{
    public $timestamps = false;
}
