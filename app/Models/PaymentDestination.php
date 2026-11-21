<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'channel', 'account_name', 'account_number', 'is_active', 'approved_by_adviser_id'])]
class PaymentDestination extends Model
{}
