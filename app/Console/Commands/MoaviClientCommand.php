<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class MoaviClientCommand extends Command
{
    protected $signature = 'moavi:client
        {client_id : Identificador do client externo}
        {--name=Moavi : Nome descritivo}
        {--cnpj= : CNPJ da empresa retornado na API}
        {--secret= : Segredo; se omitido, sera gerado}
        {--ttl=60 : TTL do token em minutos}';

    protected $description = 'Cria ou atualiza um client da API Moavi no banco dw_moavi.';

    public function handle(): int
    {
        $clientId = (string) $this->argument('client_id');
        $secret = (string) ($this->option('secret') ?: bin2hex(random_bytes(24)));
        $cnpj = (string) ($this->option('cnpj') ?: config('moavi.company_cnpj'));
        $ttl = max((int) $this->option('ttl'), 1);
        $now = now();
        $db = DB::connection(config('moavi.connection'));

        $values = [
            'name' => (string) $this->option('name'),
            'cnpj_empresa' => $cnpj,
            'secret_hash' => Hash::make($secret),
            'scopes' => json_encode(['moavi:read', 'moavi:changes'], JSON_UNESCAPED_SLASHES),
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

        $this->info('Client Moavi pronto.');
        $this->line('client_id: ' . $clientId);
        $this->line('client_secret: ' . $secret);
        $this->warn('Guarde o client_secret agora. Depois ele fica salvo apenas como hash.');

        return self::SUCCESS;
    }
}
