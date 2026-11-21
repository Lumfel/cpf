<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'submitted_by_user_id', 'quote_text', 'author_name', 'mood_tag', 'is_approved'])]
class PeerQuote extends Model
{}
