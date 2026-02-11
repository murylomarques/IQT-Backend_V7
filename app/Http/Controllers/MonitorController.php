<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\Pool;
use Illuminate\Support\Facades\Cache;

class MonitorController extends Controller
{
    private function baseUrl(): string
    {
        return rtrim(env('MONITOR_API_BASE_URL', 'http://172.29.5.3:3001'), '/');
    }

    public function health()
    {
        return response()->json([
            'ok' => true,
            'service' => 'iqt-monitor-proxy',
            'source' => $this->baseUrl(),
        ]);
    }

    public function dashboard()
    {
        $resp = Http::timeout(20)->get($this->baseUrl() . '/api/dashboard');

        if (!$resp->successful()) {
            return response()->json([
                'message' => 'Falha ao buscar dashboard do monitor',
                'status_code' => $resp->status(),
            ], 502);
        }

        return response()->json($resp->json());
    }

    public function city(string $nome, Request $request)
    {
        $query = array_filter([
            'start' => $request->query('start'),
            'end' => $request->query('end'),
        ], fn($v) => $v !== null && $v !== '');

        $resp = Http::timeout(25)->get(
            $this->baseUrl() . '/api/city/' . urlencode($nome),
            $query
        );

        if (!$resp->successful()) {
            return response()->json([
                'message' => 'Falha ao buscar detalhes da cidade no monitor',
                'status_code' => $resp->status(),
            ], 502);
        }

        return response()->json($resp->json());
    }

    public function citiesAnalytics(Request $request)
    {
        $start = $request->query('start');
        $end = $request->query('end');
        $limit = (int) $request->query('limit', 10);
        $limit = $limit > 0 ? min($limit, 30) : 10;
        $cacheTtlSeconds = (int) $request->query('cache_ttl', 120);
        $cacheTtlSeconds = $cacheTtlSeconds > 0 ? min($cacheTtlSeconds, 600) : 120;

        $query = array_filter([
            'start' => $start,
            'end' => $end,
        ], fn($v) => $v !== null && $v !== '');
        $cacheKey = 'monitor:cities-analytics:' . md5(json_encode([
            'query' => $query,
            'limit' => $limit,
            'base' => $this->baseUrl(),
        ]));

        $payload = Cache::remember($cacheKey, now()->addSeconds($cacheTtlSeconds), function () use ($query, $limit) {
            $dashboardResp = Http::timeout(25)->get($this->baseUrl() . '/api/dashboard');
            if (!$dashboardResp->successful()) {
                return [
                    '__error' => true,
                    'status_code' => $dashboardResp->status(),
                ];
            }

            $dashboard = $dashboardResp->json();
            $mapData = $dashboard['mapData'] ?? [];
            $cityNames = collect($mapData)
                ->pluck('nome')
                ->filter(fn($n) => !empty($n))
                ->unique()
                ->values()
                ->all();

            $detailsByCity = [];
            foreach (array_chunk($cityNames, 12) as $chunk) {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $query) {
                    return collect($chunk)->map(function ($name) use ($pool, $query) {
                        return $pool
                            ->as((string) $name)
                            ->timeout(20)
                            ->get($this->baseUrl() . '/api/city/' . urlencode((string) $name), $query);
                    })->all();
                });

                foreach ($chunk as $name) {
                    $resp = $responses[(string) $name] ?? null;
                    if (!$resp || !$resp->successful()) {
                        continue;
                    }

                    $json = $resp->json();
                    $detailsByCity[] = [
                        'nome' => $json['nome'] ?? $name,
                        'condicao' => $json['condicao'] ?? null,
                        'temp' => $json['temp'] ?? null,
                        'mm_chuva' => $json['mm_chuva'] ?? null,
                        'vento_speed' => $json['vento_speed'] ?? null,
                        'total_periodo' => (int) ($json['tickets']['total_periodo'] ?? 0),
                        'avg_periodo' => (int) ($json['tickets']['avg_periodo'] ?? 0),
                        'today_forecast' => (int) ($json['tickets']['todayForecast'] ?? 0),
                    ];
                }
            }

            $count = count($detailsByCity);
            $mediaGlobalTotal = $count > 0
                ? (int) round(collect($detailsByCity)->avg('total_periodo'))
                : 0;

            $topEntrantes = collect($detailsByCity)
                ->sortByDesc('total_periodo')
                ->take($limit)
                ->values()
                ->all();

            $acimaMediaOperacional = collect($detailsByCity)
                ->filter(fn($c) => (int) $c['today_forecast'] > (int) $c['avg_periodo'])
                ->map(function ($c) {
                    $c['delta_media_operacional'] = (int) $c['today_forecast'] - (int) $c['avg_periodo'];
                    return $c;
                })
                ->sortByDesc('delta_media_operacional')
                ->values()
                ->all();

            $acimaMediaGlobal = collect($detailsByCity)
                ->filter(fn($c) => (int) $c['total_periodo'] > $mediaGlobalTotal)
                ->map(function ($c) use ($mediaGlobalTotal) {
                    $c['delta_media_global'] = (int) $c['total_periodo'] - (int) $mediaGlobalTotal;
                    return $c;
                })
                ->sortByDesc('delta_media_global')
                ->values()
                ->all();

            return [
                'resumo' => [
                    'cidades_analisadas' => $count,
                    'media_global_total_periodo' => $mediaGlobalTotal,
                ],
                'top_entrantes' => $topEntrantes,
                'acima_media_operacional' => $acimaMediaOperacional,
                'acima_media_global' => $acimaMediaGlobal,
            ];
        });

        if (($payload['__error'] ?? false) === true) {
            return response()->json([
                'message' => 'Falha ao buscar dashboard para analytics',
                'status_code' => $payload['status_code'] ?? 502,
            ], 502);
        }

        return response()->json($payload);
    }
}
