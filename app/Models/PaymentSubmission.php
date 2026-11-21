<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'user_id', 'destination_id', 'purpose', 'amount', 'reference_number', 'receipt_hdd_path', 'status', 'validated_by_treasurer_id', 'validated_at', 'treasurer_notes'])]
class PaymentSubmission extends Model
{}
