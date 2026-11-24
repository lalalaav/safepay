<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Dispute extends Model
{
    protected $fillable = ['escrow_id', 'reason', 'status', 'resolution'];

    public function escrow()
    {
        return $this->belongsTo(Escrow::class);
    }
}
