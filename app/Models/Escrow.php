<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Escrow extends Model
{
    protected $fillable = ['order_id', 'contract_id', 'status', 'deadline'];

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function transactions()
    {
        return $this->hasMany(BlockchainTransaction::class);
    }

    public function dispute()
    {
        return $this->hasOne(Dispute::class);
    }
}
