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
        Schema::table('vistoria_checklist_itens', function (Blueprint $table) {
            // Status do item corrigido: Pendente (terceirizado ainda não corrigiu),
            // Em Análise (terceirizado enviou, admin precisa ver), Aprovado, Reprovado
            $table->enum('status_correcao', ['Pendente', 'Em Análise', 'Aprovado', 'Reprovado'])->default('Pendente')->after('foto_path');
            $table->text('observacao_correcao')->nullable()->after('status_correcao');
            $table->string('foto_correcao_path')->nullable()->after('observacao_correcao');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::table('vistoria_checklist_itens', function (Blueprint $table) {
            //
        });
    }
};
