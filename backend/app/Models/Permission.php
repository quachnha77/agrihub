<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Quyền trong catalog (toàn cục, chỉ đọc đối với ứng dụng; được đồng bộ từ config/rbac.php). */
class Permission extends Model
{
    protected $fillable = ['key', 'resource', 'action', 'label'];
}
