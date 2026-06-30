<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias_manutencao', function (Blueprint $table) {
            if (!Schema::hasColumn('vistorias_manutencao', 'retorno_tecnico')) {
                $table->string('retorno_tecnico')->nullable()->after('metros_drop');
            }
        });
    }

    public function down(): void
    {
        Schema::table('vistorias_manutencao', function (Blueprint $table) {
            if (Schema::hasColumn('vistorias_manutencao', 'retorno_tecnico')) {
                $table->dropColumn('retorno_tecnico');
            }
        });
    }
};
