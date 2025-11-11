<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->enum('status_laudo', ['Pendente', 'Em Correção', 'Finalizado'])
                  ->default('Pendente')
                  ->after('observacoes_gerais');
        });
    }

    public function down(): void
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->dropColumn('status_laudo');
        });
    }
};