<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Office extends Model
{
    protected $fillable = ['office_code', 'name', 'parent_office_id', 'active'];
    protected $casts = ['active' => 'boolean'];
}
