<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_user_imports', function (Blueprint $table) {
            $table->id();
            $table->string('label', 100)->nullable();
            $table->unsignedBigInteger('uploaded_by')->nullable();
            $table->string('source_filename')->nullable();
            $table->unsignedInteger('rows_count')->default(0);
            $table->timestamps();

            $table->foreign('uploaded_by')->references('id')->on('fca_users')->nullOnDelete();
        });

        Schema::create('fca_user_import_rows', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fca_user_import_id');
            $table->unsignedBigInteger('fca_user_id')->nullable();
            $table->unsignedInteger('source_row')->nullable();
            $table->string('name')->nullable();
            $table->string('usuario')->nullable();
            $table->string('email')->nullable();
            $table->string('role', 40)->nullable();
            $table->string('employee_id', 50)->nullable();
            $table->string('cpf', 20)->nullable();
            $table->string('empresa', 150)->nullable();
            $table->string('territory', 100)->nullable();
            $table->string('regional', 100)->nullable();
            $table->string('title', 150)->nullable();
            $table->string('tecnico')->nullable();
            $table->string('supervisor')->nullable();
            $table->string('coordenador')->nullable();
            $table->string('gerente')->nullable();
            $table->string('hierarquia_completa', 20)->nullable();
            $table->string('usuario_created_at', 30)->nullable();
            $table->text('observacao')->nullable();
            $table->timestamps();

            $table->foreign('fca_user_import_id')
                ->references('id')
                ->on('fca_user_imports')
                ->cascadeOnDelete();
            $table->foreign('fca_user_id')->references('id')->on('fca_users')->nullOnDelete();
            $table->index(['fca_user_import_id', 'usuario']);
            $table->index(['fca_user_import_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_user_import_rows');
        Schema::dropIfExists('fca_user_imports');
    }
};
