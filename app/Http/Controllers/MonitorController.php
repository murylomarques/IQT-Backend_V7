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
        $maxCities = (int) $request->query('max_cities', 18);
        $maxCities = $maxCities > 0 ? min($maxCities, 60) : 18;
        $cacheTtlSeconds = (int) $request->query('cache_ttl', 3600);
        $cacheTtlSeconds = $cacheTtlSeconds > 0 ? min($cacheTtlSeconds, 7200) : 3600;
        $forceRefresh = in_array(
            strtolower((string) $request->query('force_refresh', '0')),
            ['1', 'true', 'yes', 'on'],
            true
        );

        $query = array_filter([
            'start' => $start,
            'end' => $end,
        ], fn($v) => $v !== null && $v !== '');
        $cacheKey = 'monitor:cities-analytics:' . md5(json_encode([
            'query' => $query,
            'limit' => $limit,
            'max_cities' => $maxCities,
            'base' => $this->baseUrl(),
        ]));
        $lastSuccessKey = 'monitor:cities-analytics:last-success';

        $compute = function () use ($query, $limit, $maxCities, $lastSuccessKey) {
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
                ->sortByDesc(function ($c) {
                    return (float) ($c['mm_chuva'] ?? 0) + ((float) ($c['vento_speed'] ?? 0) * 0.5);
                })
                ->pluck('nome')
                ->filter(fn($n) => !empty($n))
                ->unique()
                ->take($maxCities)
                ->values()
                ->all();

            $detailsByCity = [];
            foreach (array_chunk($cityNames, 12) as $chunk) {
                $responses = Http::pool(function (Pool $pool) use ($chunk, $query) {
                    return collect($chunk)->map(function ($name) use ($pool, $query) {
                        return $pool
                            ->as((string) $name)
                            ->connectTimeout(4)
                            ->timeout(8)
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
                        'today_so_far' => (int) ($json['tickets']['todaySoFar'] ?? 0),
                        'today_forecast' => (int) ($json['tickets']['todayForecast'] ?? 0),
                    ];
                }
            }

            if (count($detailsByCity) === 0) {
                return [
                    '__error' => true,
                    'status_code' => 504,
                    '__empty' => true,
                ];
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

            $result = [
                'resumo' => [
                    'cidades_analisadas' => $count,
                    'media_global_total_periodo' => $mediaGlobalTotal,
                    'escopo_analise' => "Top {$maxCities} cidades por severidade climática atual",
                ],
                'top_entrantes' => $topEntrantes,
                'acima_media_operacional' => $acimaMediaOperacional,
                'acima_media_global' => $acimaMediaGlobal,
            ];

            Cache::put($lastSuccessKey, $result, now()->addHours(6));

            return $result;
        };

        if ($forceRefresh) {
            $payload = $compute();
            if (($payload['__error'] ?? false) !== true) {
                Cache::put($cacheKey, $payload, now()->addSeconds($cacheTtlSeconds));
            }
        } else {
            $payload = Cache::remember($cacheKey, now()->addSeconds($cacheTtlSeconds), $compute);
        }

        if (($payload['__error'] ?? false) === true) {
            $stale = Cache::get($lastSuccessKey);
            if ($stale) {
                $stale['resumo']['cache_stale'] = true;
                $stale['resumo']['cache_notice'] = 'Exibindo ultimo resultado consolidado por timeout/falha na atualização.';
                return response()->json($stale);
            }

            return response()->json([
                'message' => 'Falha ao buscar dashboard para analytics',
                'status_code' => $payload['status_code'] ?? 502,
            ], 502);
        }

        return response()->json($payload);
    }
}
