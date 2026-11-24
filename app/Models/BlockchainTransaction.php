<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BlockchainTransaction extends Model
{
    protected $fillable = ['escrow_id', 'tx_hash', 'block_number', 'log_index', 'event_type'];

    public function escrow()
    {
        return $this->belongsTo(Escrow::class);
    }
}
