<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('fca_users')) {
            DB::statement("ALTER TABLE fca_users MODIFY role ENUM('admin','coordenacao','supervisao','tecnico','consulta','gerente') NOT NULL DEFAULT 'consulta'");
        }

        if (Schema::hasTable('fca_link_requests')) {
            DB::statement("ALTER TABLE fca_link_requests MODIFY parent_role ENUM('supervisao','coordenacao','gerente') NOT NULL");
            DB::statement("ALTER TABLE fca_link_requests MODIFY child_role ENUM('tecnico','supervisao','coordenacao') NOT NULL");
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('fca_link_requests')) {
            DB::statement("ALTER TABLE fca_link_requests MODIFY child_role ENUM('tecnico','supervisao') NOT NULL");
            DB::statement("ALTER TABLE fca_link_requests MODIFY parent_role ENUM('supervisao','coordenacao') NOT NULL");
        }

        if (Schema::hasTable('fca_users')) {
            DB::statement("ALTER TABLE fca_users MODIFY role ENUM('admin','coordenacao','supervisao','tecnico','consulta') NOT NULL DEFAULT 'consulta'");
        }
    }
};
