<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function schema()
    {
        return Schema::connection(config('tsp.connection', 'tsp'));
    }

    public function up(): void
    {
        $schema = $this->schema();

        $schema->create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->string('name')->nullable();
            $table->string('secret_hash');
            $table->json('scopes')->nullable();
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('token_ttl_minutes')->default(60);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['active', 'client_id']);
        });

        $schema->create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('name')->nullable();
            $table->json('scopes')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['api_client_id', 'expires_at']);
            $table->index(['revoked_at', 'expires_at']);
        });

        $schema->create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->string('endpoint', 128);
            $table->string('method', 16);
            $table->unsignedSmallInteger('status_code');
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('error_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['api_client_id', 'created_at'], 'tsp_log_client_created_idx');
            $table->index(['endpoint', 'created_at'], 'tsp_log_endpoint_created_idx');
        });

        $schema->create('write_operation_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->nullable()->constrained('api_clients')->nullOnDelete();
            $table->string('request_id', 36);
            $table->string('operation', 64);
            $table->string('source_id');
            $table->string('numero_compromisso')->nullable();
            $table->string('salesforce_id')->nullable();
            $table->string('status', 32);
            $table->string('error_code', 64)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['operation', 'created_at'], 'tsp_write_operation_created_idx');
            $table->index(['source_id', 'created_at'], 'tsp_write_source_created_idx');
        });
    }

    public function down(): void
    {
        $schema = $this->schema();

        $schema->dropIfExists('write_operation_logs');
        $schema->dropIfExists('api_request_logs');
        $schema->dropIfExists('api_tokens');
        $schema->dropIfExists('api_clients');
    }
};
