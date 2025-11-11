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
        Schema::create('agenda', function (Blueprint $table) {
            $table->id();

            // --- DADOS DO AGENDAMENTO ---
            $table->unsignedBigInteger('fiscal_id'); // O fiscal responsável
            $table->date('data_agendamento');
            $table->time('hora_agendamento')->nullable();
            $table->string('periodo');
            $table->text('observacoes')->nullable();
            $table->enum('status', ['Agendado', 'Concluído', 'Cancelado'])->default('Agendado');
            $table->enum('statusAgendamento', ['Pendente','Reparo','Caminho','Realizando', 'Concluído', 'Cancelado'])->default('Pendente');
            $table->enum('statusLaudo', ['Pendente','Reprovado', 'Concluído', 'Cancelado'])->default('Pendente');
            $table->string('tipo');
            // --- CÓPIA DOS DADOS DO SALESFORCE (O "RETRATO") ---
            $table->unsignedBigInteger('original_atendimento_id'); // Guardamos o ID original para referência
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

            $table->timestamps(); // created_at e updated_at

            // --- RELAÇÕES (FOREIGN KEYS) ---
            $table->foreign('fiscal_id')->references('id')->on('users')->onDelete('cascade');
            
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists('agenda');
    }
};