<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentRoute extends Model
{
    protected $guarded = [];
    protected $casts = [
        'assigned_at' => 'datetime', 'due_at' => 'datetime',
        'acknowledged_at' => 'datetime', 'completed_at' => 'datetime',
    ];
}
