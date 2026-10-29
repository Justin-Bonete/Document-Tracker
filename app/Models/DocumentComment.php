<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentComment extends Model
{
    protected $guarded = [];

    public function author() { return $this->belongsTo(User::class, 'user_id'); }
}
