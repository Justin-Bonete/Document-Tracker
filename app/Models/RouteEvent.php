<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RouteEvent extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['metadata_json' => 'array', 'occurred_at' => 'datetime'];

    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }
}
