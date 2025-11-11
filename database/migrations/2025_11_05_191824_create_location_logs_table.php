<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('location_logs', function (Blueprint $table) {
            $table->id(); // Coluna de ID auto-incremento (chave primária)

            // Chave estrangeira para conectar com o usuário.
            // Se um usuário for deletado, seus logs de localização também serão.
            $table->foreignId('user_id')->constrained()->onDelete('cascade');

            // Usamos 'decimal' para alta precisão de latitude e longitude.
            // 10 dígitos no total, com 7 após o ponto decimal.
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            $table->string('network_type'); // Para "Wi-Fi", "Rede Móvel", etc.
            $table->integer('signal_level'); // Nível do sinal (ex: 1, 2, 3, 4)

            $table->timestamps(); // Cria as colunas 'created_at' e 'updated_at' automaticamente.
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('location_logs');
    }
};
