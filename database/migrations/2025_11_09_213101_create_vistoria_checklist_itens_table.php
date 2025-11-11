<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create('vistoria_checklist_itens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('vistoria_id')->constrained('vistorias')->onDelete('cascade');
            $table->string('item_key'); // 'identificacao_cto', 'conector_anilha', etc.
            $table->enum('status', ['Conforme', 'Não Conforme', 'Não se Aplica']);
            $table->text('observacao')->nullable();
            $table->string('foto_path')->nullable(); // Caminho para a imagem armazenada
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::dropIfExists('vistoria_checklist_itens');
    }
};
