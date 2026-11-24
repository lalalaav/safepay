<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('escrows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contract_id')->unique(); // id payment di smart contract
            $table->string('status')->default('CREATED');
            $table->unsignedBigInteger('deadline')->nullable(); // unix timestamp
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('escrows');
    }
};
