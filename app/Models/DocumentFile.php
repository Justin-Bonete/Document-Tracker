<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentFile extends Model
{
    protected $guarded = [];
    protected $hidden = ['storage_key'];   // never expose the storage key to clients
}
