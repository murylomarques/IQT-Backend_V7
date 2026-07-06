<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class TspClientCommand extends Command
{
    protected $signature = 'tsp:client
        {client_id : Identificador do client externo}
        {--name=TSP : Nome descritivo}
        {--secret= : Segredo; se omitido, sera gerado}
        {--ttl=60 : TTL do token em minutos}';

    protected $description = 'Cria ou atualiza um client da API TSP no banco dw_tsp.';

    public function handle(): int
    {
        $clientId = (string) $this->argument('client_id');
        $secret = (string) ($this->option('secret') ?: bin2hex(random_bytes(24)));
        $ttl = max((int) $this->option('ttl'), 1);
        $now = now();
        $db = DB::connection(config('tsp.connection'));

        $values = [
            'name' => (string) $this->option('name'),
            'secret_hash' => Hash::make($secret),
            'scopes' => json_encode(['tsp:read', 'tsp:write'], JSON_UNESCAPED_SLASHES),
            'active' => true,
            'token_ttl_minutes' => $ttl,
            'updated_at' => $now,
        ];

        $existing = $db->table('api_clients')->where('client_id', $clientId)->first();

        if ($existing) {
            $db->table('api_clients')->where('id', $existing->id)->update($values);
        } else {
            $db->table('api_clients')->insert(array_merge($values, [
                'client_id' => $clientId,
                'created_at' => $now,
            ]));
        }

        $this->info('Client TSP pronto.');
        $this->line('client_id: ' . $clientId);
        $this->line('client_secret: ' . $secret);
        $this->warn('Guarde o client_secret agora. Depois ele fica salvo apenas como hash.');

        return self::SUCCESS;
    }
}
