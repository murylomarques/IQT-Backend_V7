<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->unsignedInteger('metros_drop')->nullable()->after('tipo');
            $table->string('retorno_tecnico')->nullable()->after('metros_drop');
        });
    }

    public function down(): void
    {
        Schema::table('vistorias', function (Blueprint $table) {
            $table->dropColumn(['metros_drop', 'retorno_tecnico']);
        });
    }
};
