<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('base_manutencao', function (Blueprint $table) {
            $table->id();
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('regional')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_servico')->nullable()->default('Manutencao');
            $table->string('motivo_vistoria')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            $table->string('territorio')->nullable();
            $table->timestamps();

            $table->index('numero_compromisso', 'bm_numero_compromisso_idx');
            $table->index('nome_tecnico', 'bm_nome_tecnico_idx');
            $table->index('empresa_tecnico', 'bm_empresa_tecnico_idx');
        });

        Schema::create('agenda_manutencao', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fiscal_id');
            $table->date('data_agendamento');
            $table->time('hora_agendamento')->nullable();
            $table->string('periodo');
            $table->text('observacoes')->nullable();
            $table->string('status')->default('Agendado');
            $table->string('statusAgendamento')->default('Pendente');
            $table->string('statusLaudo')->default('Pendente');
            $table->string('tipo')->default('Agendado');

            $table->unsignedBigInteger('original_atendimento_id');
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('regional')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_servico')->nullable()->default('Manutencao');
            $table->string('motivo_vistoria')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            $table->string('territorio')->nullable();
            $table->timestamps();

            $table->foreign('fiscal_id', 'agenda_manutencao_fiscal_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->index(['fiscal_id', 'data_agendamento'], 'am_fiscal_data_idx');
            $table->index('numero_compromisso', 'am_numero_compromisso_idx');
        });

        Schema::create('vistorias_manutencao', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agenda_manutencao_id');
            $table->unsignedBigInteger('fiscal_id');
            $table->string('tipo');
            $table->unsignedInteger('metros_drop')->nullable();
            $table->string('resultado_final');
            $table->text('observacoes_gerais')->nullable();
            $table->string('status_laudo')->default('Finalizado');
            $table->timestamps();

            $table->foreign('agenda_manutencao_id', 'vm_agenda_fk')
                ->references('id')
                ->on('agenda_manutencao')
                ->cascadeOnDelete();
            $table->foreign('fiscal_id', 'vm_fiscal_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->index(['status_laudo', 'created_at'], 'vm_status_created_idx');
            $table->index(['fiscal_id', 'created_at'], 'vm_fiscal_created_idx');
        });

        Schema::create('vistoria_manutencao_checklist_itens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('vistoria_manutencao_id');
            $table->string('item_key');
            $table->string('status');
            $table->text('observacao')->nullable();
            $table->string('foto_path')->nullable();
            $table->string('status_correcao')->default('Pendente');
            $table->text('observacao_correcao')->nullable();
            $table->string('foto_correcao_path')->nullable();
            $table->timestamps();

            $table->foreign('vistoria_manutencao_id', 'vmci_vistoria_fk')
                ->references('id')
                ->on('vistorias_manutencao')
                ->cascadeOnDelete();
            $table->index(['vistoria_manutencao_id', 'status'], 'vmci_vistoria_status_idx');
            $table->index(['status_correcao', 'created_at'], 'vmci_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vistoria_manutencao_checklist_itens');
        Schema::dropIfExists('vistorias_manutencao');
        Schema::dropIfExists('agenda_manutencao');
        Schema::dropIfExists('base_manutencao');
    }
};
