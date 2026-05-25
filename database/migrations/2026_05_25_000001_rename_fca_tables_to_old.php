<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::rename('fca_users', 'fca_users_old');
        Schema::rename('fca_registros', 'fca_registros_old');
    }

    public function down(): void
    {
        Schema::rename('fca_users_old', 'fca_users');
        Schema::rename('fca_registros_old', 'fca_registros');
    }
};
