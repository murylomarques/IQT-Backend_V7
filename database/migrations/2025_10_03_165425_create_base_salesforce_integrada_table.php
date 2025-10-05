<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('base_salesforce_integrada', function (Blueprint $table) {
            $table->id(); // Cria uma chave primária auto-incremental (ID)
            
            // Colunas da sua tabela, todas como string (VARCHAR) e permitindo valores nulos
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            
            $table->timestamps(); // Cria as colunas created_at e updated_at
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('base_salesforce_integrada');
    }
};