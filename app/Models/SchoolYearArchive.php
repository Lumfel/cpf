<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['campus_id', 'academic_year', 'archive_type', 'title', 'zip_file_name', 'hdd_storage_path', 'sha256_checksum', 'archived_by_user_id', 'archived_at'])]
class SchoolYearArchive extends Model
{
    public $timestamps = false;
}
