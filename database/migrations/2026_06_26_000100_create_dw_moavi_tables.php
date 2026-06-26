<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private function schema()
    {
        return Schema::connection(config('moavi.connection', 'moavi'));
    }

    public function up(): void
    {
        $schema = $this->schema();

        $schema->create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('client_id')->unique();
            $table->string('name')->nullable();
            $table->string('cnpj_empresa', 32)->nullable();
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

        $schema->create('sync_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('api_client_id')->constrained('api_clients')->cascadeOnDelete();
            $table->string('sync_key_hash', 64)->unique();
            $table->string('endpoint', 64);
            $table->date('period_start');
            $table->date('period_end');
            $table->dateTime('baseline_started_at');
            $table->dateTime('baseline_completed_at')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedInteger('source_count')->default(0);
            $table->string('status', 32)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['api_client_id', 'status', 'expires_at'], 'moavi_sync_client_status_exp_idx');
            $table->index(['period_start', 'period_end'], 'moavi_sync_period_idx');
        });

        $schema->create('sync_session_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sync_session_id')->constrained('sync_sessions')->cascadeOnDelete();
            $table->string('source_id');
            $table->string('source_numero_compromisso');
            $table->string('row_hash', 64);
            $table->dateTime('source_updated_at')->nullable();
            $table->dateTime('source_schedule_at')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->unique(['sync_session_id', 'source_id'], 'moavi_sync_item_source_unique');
            $table->index(['sync_session_id', 'source_updated_at', 'source_id'], 'moavi_sync_item_updated_idx');
            $table->index(['sync_session_id', 'row_hash'], 'moavi_sync_item_hash_idx');
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

            $table->index(['api_client_id', 'created_at'], 'moavi_log_client_created_idx');
            $table->index(['endpoint', 'created_at'], 'moavi_log_endpoint_created_idx');
        });
    }

    public function down(): void
    {
        $schema = $this->schema();

        $schema->dropIfExists('api_request_logs');
        $schema->dropIfExists('sync_session_items');
        $schema->dropIfExists('sync_sessions');
        $schema->dropIfExists('api_tokens');
        $schema->dropIfExists('api_clients');
    }
};
