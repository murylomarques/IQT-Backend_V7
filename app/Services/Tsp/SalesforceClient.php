<?php

namespace App\Services\Tsp;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class SalesforceClient
{
    private ?array $token = null;

    public function query(string $soql): array
    {
        return $this->request('get', '/query', ['query' => ['q' => $soql]]);
    }

    public function patchSObject(string $object, string $id, array $payload): void
    {
        $this->request('patch', "/sobjects/{$object}/{$id}", ['json' => $payload], 204);
    }

    public function postSObject(string $object, array $payload): array
    {
        return $this->request('post', "/sobjects/{$object}", ['json' => $payload], 201);
    }

    public function composite(array $requests, bool $allOrNone = true): array
    {
        return $this->request('post', '/composite', [
            'json' => [
                'allOrNone' => $allOrNone,
                'compositeRequest' => $requests,
            ],
        ]);
    }

    private function request(string $method, string $path, array $options = [], int $expectedStatus = 200): array
    {
        $token = $this->token();
        $url = rtrim($token['instance_url'], '/') . '/services/data/' . config('tsp.salesforce.api_version') . $path;

        $request = Http::withToken($token['access_token'])->acceptJson();
        if (isset($options['query'])) {
            $request = $request->withOptions(['query' => $options['query']]);
        }

        $response = match ($method) {
            'get' => $request->get($url),
            'post' => $request->post($url, $options['json'] ?? []),
            'patch' => $request->patch($url, $options['json'] ?? []),
            default => throw new RuntimeException('Metodo Salesforce nao suportado.'),
        };

        if ($response->status() !== $expectedStatus) {
            throw new RuntimeException('Falha Salesforce: HTTP ' . $response->status() . ' ' . $response->body());
        }

        if ($expectedStatus === 204) {
            return [];
        }

        return $response->json() ?: [];
    }

    private function token(): array
    {
        if ($this->token) {
            return $this->token;
        }

        $domain = rtrim((string) config('tsp.salesforce.domain'), '/');
        $clientId = (string) config('tsp.salesforce.client_id');
        $clientSecret = (string) config('tsp.salesforce.client_secret');

        if (!$domain || !$clientId || !$clientSecret) {
            throw new RuntimeException('Credenciais Salesforce nao configuradas.');
        }

        $response = Http::asForm()->post($domain . '/services/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]);

        if (!$response->successful()) {
            throw new RuntimeException('Falha ao autenticar no Salesforce: HTTP ' . $response->status());
        }

        return $this->token = $response->json();
    }
}
