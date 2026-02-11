<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->index(['status_laudo', 'created_at'], 'vistorias_status_laudo_created_at_idx');
            $table->index(['agenda_id', 'created_at'], 'vistorias_agenda_created_at_idx');
            $table->index(['fiscal_id', 'created_at'], 'vistorias_fiscal_created_at_idx');
        });

        Schema::table('vistoria_checklist_itens', function (Blueprint $table) {
            $table->index(['vistoria_id', 'status'], 'vci_vistoria_status_idx');
            $table->index(['vistoria_id', 'status', 'status_correcao'], 'vci_vistoria_status_correcao_idx');
            $table->index(['status_correcao', 'created_at'], 'vci_status_correcao_created_at_idx');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at'], 'activity_logs_user_created_at_idx');
        });
    }

    public function down()
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->dropIndex('vistorias_status_laudo_created_at_idx');
            $table->dropIndex('vistorias_agenda_created_at_idx');
            $table->dropIndex('vistorias_fiscal_created_at_idx');
        });

        Schema::table('vistoria_checklist_itens', function (Blueprint $table) {
            $table->dropIndex('vci_vistoria_status_idx');
            $table->dropIndex('vci_vistoria_status_correcao_idx');
            $table->dropIndex('vci_status_correcao_created_at_idx');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->dropIndex('activity_logs_user_created_at_idx');
        });
    }
};

