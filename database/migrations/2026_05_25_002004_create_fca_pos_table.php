<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_pos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fca_period_id');
            $table->foreign('fca_period_id')->references('id')->on('fca_periods')->cascadeOnDelete();
            $table->unsignedBigInteger('supervisor_id');
            $table->foreign('supervisor_id')->references('id')->on('fca_users')->cascadeOnDelete();
            $table->unsignedBigInteger('tecnico_id');
            $table->foreign('tecnico_id')->references('id')->on('fca_period_tecnicos')->cascadeOnDelete();
            $table->json('answers');
            $table->date('po_date');
            $table->timestamps();

            $table->index(['fca_period_id', 'supervisor_id', 'tecnico_id', 'po_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_pos');
    }
};
