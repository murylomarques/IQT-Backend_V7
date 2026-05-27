<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fca_users', function (Blueprint $table) {
            $table->string('cpf', 20)->nullable()->after('employee_id');
        });
    }

    public function down(): void
    {
        Schema::table('fca_users', function (Blueprint $table) {
            $table->dropColumn('cpf');
        });
    }
};
