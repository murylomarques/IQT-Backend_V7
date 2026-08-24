<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('vistorias') && Schema::hasColumn('vistorias', 'status_laudo')) {
            DB::statement("ALTER TABLE vistorias MODIFY status_laudo VARCHAR(50) NOT NULL DEFAULT 'Pendente'");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('vistorias') && Schema::hasColumn('vistorias', 'status_laudo')) {
            DB::table('vistorias')
                ->where('status_laudo', 'Vencido')
                ->update(['status_laudo' => 'Em Correção']);

            DB::statement("ALTER TABLE vistorias MODIFY status_laudo ENUM('Pendente','Em Correção','Finalizado') NOT NULL DEFAULT 'Pendente'");
        }
    }
};
