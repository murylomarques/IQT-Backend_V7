<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fca_users', function (Blueprint $table) {
            $table->date('data_admissao')->nullable()->after('title');
            $table->date('data_demissao')->nullable()->after('data_admissao');
            $table->text('observacao')->nullable()->after('data_demissao');
        });
    }

    public function down(): void
    {
        Schema::table('fca_users', function (Blueprint $table) {
            $table->dropColumn(['data_admissao', 'data_demissao', 'observacao']);
        });
    }
};
