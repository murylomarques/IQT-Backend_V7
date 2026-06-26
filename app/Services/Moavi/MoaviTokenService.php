<?php

namespace App\Services\Moavi;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use stdClass;

class MoaviTokenService
{
    public function issueToken(string $clientId, string $clientSecret): ?array
    {
        $db = $this->db();
        $client = $db->table('api_clients')
            ->where('client_id', $clientId)
            ->where('active', true)
            ->first();

        if (!$client || !Hash::check($clientSecret, $client->secret_hash)) {
            return null;
        }

        $plainToken = bin2hex(random_bytes(32));
        $ttlMinutes = (int) ($client->token_ttl_minutes ?: config('moavi.token_ttl_minutes'));
        $expiresAt = now()->addMinutes(max($ttlMinutes, 1));
        $scopes = $this->scopes($client);

        $db->transaction(function () use ($db, $client, $plainToken, $scopes, $expiresAt): void {
            $db->table('api_tokens')->insert([
                'api_client_id' => $client->id,
                'token_hash' => hash('sha256', $plainToken),
                'name' => 'moavi-api',
                'scopes' => json_encode($scopes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'expires_at' => $expiresAt,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $db->table('api_clients')
                ->where('id', $client->id)
                ->update([
                    'last_used_at' => now(),
                    'updated_at' => now(),
                ]);
        });

        return [
            'client' => $client,
            'access_token' => $plainToken,
            'token_type' => 'Bearer',
            'expires_in' => $expiresAt->diffInSeconds(now()),
            'scope' => implode(' ', $scopes),
            'expires_at' => $expiresAt->toIso8601String(),
        ];
    }

    public function authenticateBearer(string $plainToken): ?array
    {
        $db = $this->db();

        $token = $db->table('api_tokens')
            ->where('token_hash', hash('sha256', $plainToken))
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->first();

        if (!$token) {
            return null;
        }

        $client = $db->table('api_clients')
            ->where('id', $token->api_client_id)
            ->where('active', true)
            ->first();

        if (!$client) {
            return null;
        }

        $db->table('api_tokens')
            ->where('id', $token->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        $db->table('api_clients')
            ->where('id', $client->id)
            ->update([
                'last_used_at' => now(),
                'updated_at' => now(),
            ]);

        return [
            'client' => $client,
            'token' => $token,
            'scopes' => $this->scopes($token),
        ];
    }

    private function db()
    {
        return DB::connection(config('moavi.connection'));
    }

    private function scopes(stdClass $record): array
    {
        $decoded = json_decode((string) ($record->scopes ?? ''), true);
        if (!is_array($decoded) || $decoded === []) {
            return ['moavi:read', 'moavi:changes'];
        }

        return array_values(array_filter($decoded, 'is_string'));
    }
}
