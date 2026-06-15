<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fca_checklists', function (Blueprint $table) {
            $table->dropUnique('fca_checklists_fca_period_id_supervisor_id_tecnico_id_unique');
            $table->index(
                ['fca_period_id', 'supervisor_id', 'tecnico_id'],
                'fca_checklists_period_supervisor_tecnico_index'
            );
        });
    }

    public function down(): void
    {
        Schema::table('fca_checklists', function (Blueprint $table) {
            $table->dropIndex('fca_checklists_period_supervisor_tecnico_index');
            $table->unique(['fca_period_id', 'supervisor_id', 'tecnico_id']);
        });
    }
};
