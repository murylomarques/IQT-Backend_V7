<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fca_link_requests', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requester_user_id');
            $table->unsignedBigInteger('parent_user_id');
            $table->unsignedBigInteger('child_user_id');
            $table->enum('parent_role', ['supervisao', 'coordenacao']);
            $table->enum('child_role', ['tecnico', 'supervisao']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->timestamp('requested_at');
            $table->timestamp('decided_at')->nullable();
            $table->unsignedBigInteger('decided_by_user_id')->nullable();
            $table->text('decision_note')->nullable();
            $table->timestamps();

            $table->foreign('requester_user_id')->references('id')->on('fca_users')->onDelete('cascade');
            $table->foreign('parent_user_id')->references('id')->on('fca_users')->onDelete('cascade');
            $table->foreign('child_user_id')->references('id')->on('fca_users')->onDelete('cascade');
            $table->foreign('decided_by_user_id')->references('id')->on('fca_users')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fca_link_requests');
    }
};
