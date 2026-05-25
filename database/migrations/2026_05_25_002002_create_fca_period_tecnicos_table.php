<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_period_tecnicos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fca_period_id');
            $table->foreign('fca_period_id')->references('id')->on('fca_periods')->cascadeOnDelete();
            $table->string('nome', 255);
            $table->decimal('prod_bruta', 12, 8)->nullable();
            $table->decimal('revisita', 12, 8)->nullable();
            $table->decimal('tec1', 12, 8)->nullable();
            $table->enum('certificado', ['Sim', 'Não', '-'])->default('-');
            $table->timestamps();

            $table->index(['fca_period_id', 'nome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_period_tecnicos');
    }
};
