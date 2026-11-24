<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('blockchain_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->string('tx_hash')->unique();
            $table->unsignedBigInteger('block_number')->nullable();
            $table->unsignedBigInteger('log_index')->default(0);
            $table->string('event_type');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('blockchain_transactions');
    }
};
