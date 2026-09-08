<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        // base_manutencao.motivo_caso ja existe em producao (adicionada fora das migrations,
        // por isso o guard) — aqui garantimos que qualquer ambiente (novo, teste, etc.) tenha
        // a mesma coluna, sem tentar recriar onde ja existe.
        if (!Schema::hasColumn('base_manutencao', 'motivo_caso')) {
            Schema::table('base_manutencao', function (Blueprint $table) {
                $table->string('motivo_caso')->nullable()->after('tipo_servico');
            });
        }

        if (!Schema::hasColumn('agenda_manutencao', 'motivo_caso')) {
            Schema::table('agenda_manutencao', function (Blueprint $table) {
                $table->string('motivo_caso')->nullable()->after('tipo_servico');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('agenda_manutencao', 'motivo_caso')) {
            Schema::table('agenda_manutencao', function (Blueprint $table) {
                $table->dropColumn('motivo_caso');
            });
        }

        if (Schema::hasColumn('base_manutencao', 'motivo_caso')) {
            Schema::table('base_manutencao', function (Blueprint $table) {
                $table->dropColumn('motivo_caso');
            });
        }
    }
};
