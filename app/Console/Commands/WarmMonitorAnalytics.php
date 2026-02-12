<?php

namespace App\Console\Commands;

use App\Http\Controllers\MonitorController;
use Illuminate\Console\Command;
use Illuminate\Http\Request;

class WarmMonitorAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:warm-analytics
                            {--cache_ttl=3600 : TTL do cache em segundos}
                            {--limit=10 : Quantidade para Top Entrantes}
                            {--max_cities=0 : Quantidade de cidades a analisar (0 = todas)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Pré-processa e aquece o cache do analytics de monitor por cidade.';

    /**
     * Execute the console command.
     *
     * @return int
     */
    public function handle()
    {
        $cacheTtl = (int) $this->option('cache_ttl');
        $limit = (int) $this->option('limit');
        $maxCities = (int) $this->option('max_cities');

        $request = Request::create('/api/monitor/cities-analytics', 'GET', [
            'cache_ttl' => $cacheTtl,
            'limit' => $limit,
            'max_cities' => $maxCities,
        ]);

        /** @var MonitorController $controller */
        $controller = app(MonitorController::class);
        $response = $controller->citiesAnalytics($request);
        $status = $response->getStatusCode();

        if ($status >= 400) {
            $this->error("Falha ao aquecer cache de analytics. HTTP {$status}");
            return self::FAILURE;
        }

        $data = $response->getData(true);
        $count = (int) ($data['resumo']['cidades_analisadas'] ?? 0);
        $avg = (int) ($data['resumo']['media_global_total_periodo'] ?? 0);
        $stale = (bool) ($data['resumo']['cache_stale'] ?? false);

        $msg = "Cache aquecido com sucesso. Cidades analisadas: {$count} | Média global: {$avg}";
        if ($stale) {
            $msg .= ' | Observação: usando fallback de cache anterior.';
        }

        $this->info($msg);
        return self::SUCCESS;
    }
}
