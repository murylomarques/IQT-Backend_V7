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
    Schema::create('vistorias_seguranca', function (Blueprint $table) {
        $table->id();
        $table->foreignId('inspetor_id')->constrained('users');
        $table->foreignId('regional_id')->constrained('regionais');
        $table->foreignId('empresa_id')->constrained('empresas');
        
        $table->string('cidade');
        $table->string('nome_tecnico');
        $table->string('cpf_tecnico');
        $table->string('nome_supervisor');
        $table->string('placa');
        $table->string('modo_despache'); // <-- COLUNA QUE FALTAVA
        
        $table->enum('tecnico_no_local', ['Sim', 'Não']);
        $table->enum('atividade_externa', ['Sim', 'Não']);
        $table->enum('uso_capacete', ['Sim', 'Não']);
        $table->enum('uso_cinto', ['Sim', 'Não']);
        $table->enum('uso_talabarte', ['Sim', 'Não']);
        $table->enum('uso_botas', ['Sim', 'Não']);
        $table->enum('escada_estavel', ['Sim', 'Não']);
        $table->enum('escada_amarrada', ['Sim', 'Não']);
        $table->enum('sinalizacao_cones', ['Sim', 'Não']);
        $table->enum('escada_bom_estado', ['Sim', 'Não']);

        $table->text('observacoes')->nullable();
        $table->timestamps();
    });

    // A tabela de arquivos já está correta
    Schema::create('vistoria_seguranca_arquivos', function (Blueprint $table) {
        $table->id();
        $table->foreignId('vistoria_seguranca_id')->constrained('vistorias_seguranca')->onDelete('cascade');
        $table->string('path');
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vistoria_segurancas');
    }
};
