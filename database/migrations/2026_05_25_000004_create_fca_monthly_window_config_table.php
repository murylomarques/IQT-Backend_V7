<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_monthly_window_config', function (Blueprint $table) {
            $table->id();
            $table->tinyInteger('start_day')->default(1);
            $table->tinyInteger('end_day')->default(7);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_monthly_window_config');
    }
};
