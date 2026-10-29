<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentDecision extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['decided_at' => 'datetime'];
}
