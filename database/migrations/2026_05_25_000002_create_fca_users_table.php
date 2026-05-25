<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_users', function (Blueprint $table) {
            $table->id();
            $table->string('employee_id', 50)->nullable();
            $table->string('name');
            $table->string('email')->nullable()->unique();
            $table->string('usuario')->unique();
            $table->string('password');
            $table->enum('role', ['admin', 'coordenacao', 'supervisao', 'tecnico', 'consulta'])->default('consulta');
            $table->unsignedBigInteger('manager_id')->nullable();
            $table->string('territory', 100)->nullable();
            $table->string('regional', 100)->nullable();
            $table->string('title', 150)->nullable();
            $table->string('token')->nullable();
            $table->timestamps();

            $table->foreign('manager_id')->references('id')->on('fca_users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_users');
    }
};
