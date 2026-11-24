<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'academic_year', 'zip_file_name', 'hdd_storage_path', 'sha256_checksum', 'total_messages', 'archived_by_user_id', 'archived_at'])]
#[Hidden(['hdd_storage_path'])]
class OfficerGcArchive extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['archived_at' => 'datetime'];
    }
}
