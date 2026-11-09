<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Portal FUP (produção regional) — público, somente leitura, acessado por /fup.
 * Porte de FUP/portal-producao/db_portal.py: monta window.PORTAL_DATA / PORTAL_VALIDATION a partir do banco dw_fup.
 *
 * Bloco do dia X (igual ao Compromissos D0 da planilha):
 *   fila, DU, estoque máximo, entrante médio, necessidade = foto D0 de X (vw_bloco_diario)
 *   compromisso = man_compromisso de X
 *   realizado   = real_d1 da foto de X+1; sem essa foto, a base atual (fat_producao_diaria)
 */
class FupController extends Controller
{
    private const ENT_CODE = ['CE', 'CE01', 'CE02', 'CE03', 'CO', 'CO01', 'CO02', 'CO03', 'SE', 'SE01', 'SE02', 'SE03', 'DESKTOP'];
    private const ENTITIES = [
        ['CE', 'Central', 'regional', 'CE'], ['CE01', 'Sumaré', 'polo', 'CE'], ['CE02', 'Sorocaba', 'polo', 'CE'], ['CE03', 'Campinas', 'polo', 'CE'],
        ['CO', 'Centro Oeste', 'regional', 'CO'], ['CO01', 'Lençóis Paulista', 'polo', 'CO'], ['CO02', 'Araraquara', 'polo', 'CO'], ['CO03', 'Barretos', 'polo', 'CO'],
        ['SE', 'Sudeste', 'regional', 'SE'], ['SE01', 'Praia Grande', 'polo', 'SE'], ['SE02', 'São José dos Campos', 'polo', 'SE'], ['SE03', 'Jundiaí', 'polo', 'SE'],
        ['DESKTOP', 'Desktop', 'total', ''],
    ];
    private const TYPES = ['Reparo', 'Instalação', 'MEs', 'PREV', 'Outros', 'Chip', 'Retiradas'];
    private const TIPOS = ['REPARO', 'ATIVACAO', 'MUD_END', 'PREV', 'OUTROS', 'CHIP', 'RETIRADA'];
    private const TIPO8 = ['REPARO', 'ATIVACAO', 'MUD_END', 'PREV', 'OUTROS', 'CHIP', 'RETIRADA', 'TOTAL'];
    private const MESES = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];
    // feriados que a aba ENTRANTE troca pelo dia seguinte nas médias de 4 semanas (só os que a planilha troca: 02/11 ela mantém)
    private const ENTR_FERIADOS = ['2026-10-12'];
    private const LEG_KEYS = [
        'du' => 'du', 'queue' => 'fila', 'emax' => 'estoque_maximo', 'entrAvg' => 'entrante_medio_d0', 'need' => 'necessidade',
        'commit' => 'compromisso', 'actual' => 'realizado', 'pctCommit' => 'pct_compromisso', 'pctNeed' => 'pct_necessidade',
        'pctCommitNeed' => 'pct_compromisso_x_necessidade',
    ];

    /** GET /api/fup/dados → {data, validation} (cache curto, como o servidor local). */
    public function dados()
    {
        $ttl = max(30, (int) env('FUP_CACHE_SECONDS', 60));

        try {
            $payload = Cache::remember('fup:portal:v1', now()->addSeconds($ttl), fn () => $this->build());

            return response()->json($payload, 200, [], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => 'Não foi possível ler o banco dw_fup.'], 500);
        }
    }

    protected function q(string $sql, array $args = []): array
    {
        return array_map(fn ($r) => array_values((array) $r), DB::connection('fup')->select($sql, $args));
    }

    private static function f($v): ?float
    {
        return $v === null ? null : round((float) $v, 6);
    }

    private static function ratio($a, $b): ?float
    {
        return $a !== null && $b ? $a / $b : null;
    }

    private static function blank(int $n): array
    {
        return array_fill(0, 13, array_fill(0, $n, null));
    }

    private static function iso($v): ?string
    {
        return $v === null ? null : str_replace(' ', 'T', substr((string) $v, 0, 19));
    }

    private static function day($v): string
    {
        return substr((string) $v, 0, 10);
    }

    private static function addDays(string $d, int $n): string
    {
        return date('Y-m-d', strtotime("$d $n day"));
    }

    private static function ei($e): ?int
    {
        $i = array_search($e, self::ENT_CODE, true);
        return $i === false ? null : $i;
    }

    private static function ti($t, array $list = self::TIPOS): ?int
    {
        $i = array_search($t, $list, true);
        return $i === false ? null : $i;
    }

    private static function sumOrNull(array $vals): ?float
    {
        $nn = array_filter($vals, fn ($v) => $v !== null);
        return $nn ? round(array_sum($nn), 6) : null;
    }

    private function build(): array
    {
        $P = [];
        foreach ($this->q('SELECT chave, valor FROM man_parametro') as [$k, $v]) {
            $P[$k] = (float) $v;
        }
        $target = array_map(fn ($t) => $P["META_DU_$t"] ?? null, array_slice(self::TIPOS, 0, 6));

        $snap = [];  // data -> "e,t" -> [fila, du, emax, entr, nec, real_d1]
        foreach ($this->q('SELECT data_ref, entidade, tipo, fila, du, estoque_maximo, entrante_medio_d0, necessidade, realizado_d1 FROM vw_bloco_diario') as $r) {
            [$e, $t] = [self::ei($r[1]), self::ti($r[2])];
            if ($e !== null && $t !== null) {
                $snap[self::day($r[0])]["$e,$t"] = array_slice($r, 3);
            }
        }
        $vendas = [];     // data da foto -> e -> entrante de ativação D-1 (vendas da aba Ati)
        $entrFoto = [];   // data da foto -> "e,t" -> entrante D-1 congelado
        foreach ($this->q('SELECT s.data_ref, e.entidade, s.tipo, SUM(s.entr_d1) FROM fat_snapshot_diario s
                           JOIN vw_entidade e ON e.cod_ger = s.cod_ger GROUP BY s.data_ref, e.entidade, s.tipo') as [$d, $e, $t, $v]) {
            [$e, $ti] = [self::ei($e), self::ti($t)];
            if ($e === null || $ti === null) {
                continue;
            }
            $entrFoto[self::day($d)]["$e,$ti"] = $v;
            if ($t === 'ATIVACAO') {
                $vendas[self::day($d)][$e] = $v;
            }
        }
        $legEntr = [];  // aba ENTRANTE da planilha (até 02/11/2026)
        foreach ($this->q('SELECT l.data, e.entidade, l.tipo, SUM(l.entrante) FROM leg_entrante_diario l
                           JOIN vw_entidade e ON e.cod_ger = l.cod_ger GROUP BY l.data, e.entidade, l.tipo') as [$d, $e, $t, $v]) {
            [$e, $t] = [self::ei($e), self::ti($t)];
            if ($e !== null && $t !== null) {
                $legEntr[self::day($d)]["$e,$t"] = $v;
            }
        }
        $legVendas = [];
        foreach ($this->q('SELECT data, entidade, vendas FROM leg_vendas_ati') as [$d, $e, $v]) {
            if (($e = self::ei($e)) !== null) {
                $legVendas[self::day($d)][$e] = $v;
            }
        }
        // vendas (entrante de ativação congelado) do dia x: aba Ati onde a planilha cobre, senão a foto da manhã seguinte
        $vendasDia = fn (string $x, int $e) => self::f($legVendas[$x][$e] ?? $vendas[self::addDays($x, 1)][$e] ?? null);
        $commit = [];
        foreach ($this->q('SELECT c.data, e.entidade, c.tipo, SUM(c.compromisso) FROM man_compromisso c
                           JOIN vw_entidade e ON e.cod_ger = c.cod_ger GROUP BY c.data, e.entidade, c.tipo') as [$d, $e, $t, $v]) {
            [$e, $t] = [self::ei($e), self::ti($t)];
            if ($e !== null && $t !== null) {
                $commit[self::day($d)]["$e,$t"] = $v;
            }
        }
        $hc = $hcconf = [];
        foreach ($this->q('SELECT h.data, e.entidade, SUM(h.hc_proprio), SUM(h.hc_terceiro), SUM(h.hc_conf_proprio), SUM(h.hc_conf_terceiro) FROM man_hc_compromisso h
                           JOIN vw_entidade e ON e.cod_ger = h.cod_ger GROUP BY h.data, e.entidade') as [$d, $e, $a, $b, $ca, $cb]) {
            if (($e = self::ei($e)) === null) {
                continue;
            }
            if ($a !== null || $b !== null) {
                $hc[self::day($d)][$e] = [self::f($a), self::f($b)];
            }
            if ($ca !== null || $cb !== null) {  // HC confirmado COP informado pelas regionais
                $hcconf[self::day($d)][$e] = [self::f($ca), self::f($cb)];
            }
        }
        $live = $entrante = [];
        foreach ($this->q('SELECT p.data, e.entidade, p.tipo, SUM(p.realizado), SUM(p.entrante) FROM fat_producao_diaria p
                           JOIN vw_entidade e ON e.cod_ger = p.cod_ger GROUP BY p.data, e.entidade, p.tipo') as [$d, $e, $t, $v, $en]) {
            [$e, $t] = [self::ei($e), self::ti($t)];
            if ($e !== null && $t !== null) {
                $live[self::day($d)]["$e,$t"] = $v;
                $entrante[self::day($d)]["$e,$t"] = $en;
            }
        }

        // dias: fotos D0 e o dia anterior de cada foto (para o realizado D-1 do painel)
        $dates = array_keys($snap);
        foreach (array_keys($snap) as $d) {
            $dates[] = self::addDays($d, -1);
        }
        $dates = array_values(array_unique($dates));
        sort($dates);
        $days = [];
        foreach ($dates as $d) {
            $S = $snap[$d] ?? [];
            $nxt = $snap[self::addDays($d, 1)] ?? [];
            $day = ['date' => $d, 'col' => $S ? 'foto ' . date('d/m', strtotime($d)) : '—', 'layout' => 7, 'target' => $target];
            foreach (['du', 'queue', 'emax', 'entrAvg', 'need', 'commit', 'actual'] as $key) {
                $day[$key] = self::blank(8);
            }
            foreach ($S as $et => [$fila, $du, $emax, $entr, $nec]) {
                [$e, $t] = array_map('intval', explode(',', $et));
                $day['queue'][$e][$t] = $t < 6 ? self::f($fila) : null;
                $day['du'][$e][$t] = $t < 6 ? self::f($du) : null;
                $day['emax'][$e][$t] = $t < 6 ? self::f($emax) : null;
                $day['entrAvg'][$e][$t] = self::f($entr);
                $day['need'][$e][$t] = self::f($nec);
            }
            foreach ($commit[$d] ?? [] as $et => $v) {
                [$e, $t] = array_map('intval', explode(',', $et));
                $day['commit'][$e][$t] = self::f($v);
            }
            for ($e = 0; $e < 13; $e++) {
                for ($t = 0; $t < 7; $t++) {
                    if (isset($nxt["$e,$t"])) {
                        $day['actual'][$e][$t] = self::f($nxt["$e,$t"][5]);
                    } elseif (array_key_exists("$e,$t", $live[$d] ?? [])) {
                        $day['actual'][$e][$t] = self::f($live[$d]["$e,$t"]);
                    }
                }
                foreach (['need', 'commit', 'actual'] as $key) {
                    $day[$key][$e][7] = self::sumOrNull(array_slice($day[$key][$e], 0, 7));
                }
            }
            foreach (['pctCommit' => ['actual', 'commit'], 'pctNeed' => ['actual', 'need'], 'pctCommitNeed' => ['commit', 'need']] as $key => [$a, $b]) {
                $day[$key] = [];
                for ($e = 0; $e < 13; $e++) {
                    for ($t = 0; $t < 8; $t++) {
                        $day[$key][$e][$t] = self::ratio($day[$a][$e][$t], $day[$b][$e][$t]);
                    }
                }
            }
            // EFETIV = compromisso de instalação ÷ média das vendas do mesmo dia da semana nas 4 semanas anteriores
            // (igual à planilha); sem as 4 semanas, o entrante médio da foto.
            // REAL = realizado de ativação ÷ vendas (entrante de ativação), da foto da manhã seguinte; sem ela, a base atual.
            // COBERT = real ÷ comprometida. Regra conferida contra a efetividade colada da planilha (decidido em 05/11/2026).
            $V = $vendas[self::addDays($d, 1)] ?? [];
            if (!$V) {
                for ($e = 0; $e < 13; $e++) {
                    if (array_key_exists("$e,1", $entrante[$d] ?? [])) {
                        $V[$e] = $entrante[$d]["$e,1"];
                    }
                }
            }
            $day['efet'] = $day['efetParts'] = []; // partes [compromisso inst, média vendas, realizado inst, vendas]: consolidado de fim de semana
            for ($e = 0; $e < 13; $e++) {
                $sem = array_map(fn ($k) => $vendasDia(self::addDays($d, -7 * $k), $e), [1, 2, 3, 4]);
                $base = in_array(null, $sem, true) ? $day['entrAvg'][$e][1] : array_sum($sem) / 4;
                $ef = self::ratio($day['commit'][$e][1], $base);
                $real = self::ratio($day['actual'][$e][1], self::f($V[$e] ?? null));
                $day['efet'][] = [$ef, $real, self::ratio($real, $ef)];
                $day['efetParts'][] = [$day['commit'][$e][1], $base, $day['actual'][$e][1], self::f($V[$e] ?? null)];
            }
            if (!empty($hc[$d])) {
                $day['hcCommit'] = array_map(fn ($e) => $hc[$d][$e] ?? [null, null], range(0, 12));
            }
            $days[] = $day;
        }

        // curva mensal
        $mens = [];
        foreach ($this->q('SELECT mes, servico, data, entidade, peso, meta_dia, realizado, forecast FROM vw_mensal ORDER BY data') as [$mes, $svc, $d, $ent, $peso, $md, $real, $fc]) {
            if (($e = self::ei($ent)) !== null) {
                $mens[self::day($mes) . '|' . $svc][self::day($d)][$e] = [self::f($peso), self::f($md), self::f($real), self::f($fc)];
            }
        }
        $metas = [];
        foreach ($this->q('SELECT mm.mes, mm.servico, e.entidade, SUM(mm.meta_mes), SUM(mm.churn_base)
                           FROM man_meta_mensal mm JOIN vw_entidade e ON e.cod_ger = mm.cod_ger GROUP BY mm.mes, mm.servico, e.entidade') as [$m, $s, $e, $a, $b]) {
            if (($e = self::ei($e)) !== null) {
                $metas[self::day($m) . "|$s|$e"] = [self::f($a), self::f($b)];
            }
        }
        // meta de efetividade de Ativação: polos em man_meta_mensal; regionais e Desktop digitados à parte (não são a média dos polos)
        $metaEfet = [];
        foreach ($this->q("SELECT mm.mes, g.sigla, mm.meta_efetividade FROM man_meta_mensal mm
                           JOIN dim_gerencia g ON g.cod_ger = mm.cod_ger WHERE mm.servico = 'ATIVACAO' AND mm.meta_efetividade IS NOT NULL") as [$m, $e, $v]) {
            if (($e = self::ei($e)) !== null) {
                $metaEfet[self::day($m) . "|$e"] = self::f($v);
            }
        }
        foreach ($this->q('SELECT mes, entidade, meta_efetividade FROM man_meta_efetividade_regional') as [$m, $e, $v]) {
            if (($e = self::ei($e)) !== null) {
                $metaEfet[self::day($m) . "|$e"] = self::f($v);
            }
        }
        $hoje = $snap ? max(array_keys($snap)) : date('Y-m-d');
        ksort($mens);
        $monthly = [];
        foreach ($mens as $ms => $byday) {
            [$mes, $svc] = explode('|', $ms, 2);
            ksort($byday);
            $ds = array_keys($byday);
            $n = count($ds);
            $weights = array_map(fn ($d) => $byday[$d][12][0] ?? null, $ds);
            $SP = array_sum($weights);
            $SPd = 0;
            foreach ($ds as $i => $d) {
                if ($d < $hoje) {
                    $SPd += $weights[$i];
                }
            }
            $targetDay = $actualDay = $forecastDay = $gapDay = $tgt = $act = $proj = $cov = [];
            for ($e = 0; $e < 13; $e++) {
                foreach ($ds as $i => $d) {
                    $row = $byday[$d][$e] ?? [null, null, null, null];
                    $targetDay[$e][$i] = $row[1];
                    $actualDay[$e][$i] = $d < $hoje ? $row[2] : null;
                    $forecastDay[$e][$i] = $row[3];
                    $gapDay[$e][$i] = $d < $hoje ? ($actualDay[$e][$i] ?? 0) - ($row[1] ?? 0) : null;
                }
                if (!$n) {
                    $targetDay[$e] = $actualDay[$e] = $forecastDay[$e] = $gapDay[$e] = [];
                }
                $tgt[$e] = $metas["$mes|$svc|$e"][0] ?? null;
                $act[$e] = round(array_sum(array_map(fn ($v) => $v ?? 0, $actualDay[$e])), 6);
                $proj[$e] = $SPd ? $act[$e] / $SPd * $SP : null;
                $cov[$e] = self::ratio($proj[$e], $tgt[$e]);
            }
            $ret = $svc !== 'ATIVACAO';
            $rec = [
                'month' => substr($mes, 0, 7), 'service' => $ret ? 'Retirada' : 'Ativação',
                'sheet' => ($ret ? 'Ret' : 'Ati') . ' ' . ucfirst(self::MESES[(int) substr($mes, 5, 2) - 1]),
                'dates' => $ds, 'weights' => $weights, 'sumWeights' => $SP, 'sumWeightsDone' => $SPd,
                'target' => $tgt, 'targetDay' => $targetDay, 'actual' => $act, 'actualDay' => $actualDay, 'forecastDay' => $forecastDay,
                'gapDay' => $gapDay, 'projection' => $proj, 'coverage' => $cov, 'labels' => new \stdClass(),
            ];
            if ($svc === 'RETIRADA') {
                $rec += [
                    'churn' => array_map(fn ($e) => $metas["$mes|$svc|$e"][1] ?? null, range(0, 12)),
                    'metaA' => array_fill(0, 13, null), 'metaB' => array_fill(0, 13, null), 'pctMetaB' => array_fill(0, 13, null),
                ];
                $rec['labels'] = ['churn' => 'Base churn', 'metaA' => 'Meta', 'metaB' => 'Meta B', 'projection' => 'Proj'];
            } else {
                $rec += ['efetMonth' => array_fill(0, 13, null), 'efetTarget' => array_map(fn ($e) => $metaEfet["$mes|$e"] ?? null, range(0, 12)), 'metaComercial' => array_fill(0, 13, null)];
            }
            $monthly[] = $rec;
        }

        // capacity
        $capd = [];
        foreach ($this->q('SELECT data, entidade, hc_presente_proprio, hc_presente_terceiro FROM vw_capacity') as [$d, $e, $a, $b]) {
            if (($e = self::ei($e)) !== null) {
                $capd[self::day($d)][$e] = [self::f($a), self::f($b)];
            }
        }
        $cdates = array_values(array_unique(array_merge(array_keys($capd), array_keys($hc), array_keys($hcconf))));
        sort($cdates);
        $capacity = ['dates' => $cdates];
        foreach ([['mopActual', $capd, 0], ['motActual', $capd, 1], ['mopPlan', $hc, 0], ['motPlan', $hc, 1], ['mopConf', $hcconf, 0], ['motConf', $hcconf, 1]] as [$key, $src, $j]) {
            $capacity[$key] = array_map(fn ($e) => array_map(fn ($d) => $src[$d][$e][$j] ?? null, $cdates), range(0, 12));
        }

        [$days, $monthly, $capacity, $legAte] = $this->legado($days, $monthly, $capacity);

        // cobertura de entrantes D-1: entrante do dia ÷ média dos 4 mesmos dias da semana anteriores (Reparo, Instalação e MEs).
        // Entrante congelado: aba ENTRANTE onde a planilha cobre, senão a foto da manhã seguinte (a base atual perde o mês anterior)
        $entrDia = fn (string $x, int $e, int $t) => self::f($legEntr[$x]["$e,$t"] ?? $entrFoto[self::addDays($x, 1)]["$e,$t"] ?? null);
        foreach ($days as &$day) {
            $x = $day['date'];
            $cob = $partes = array_fill(0, 13, array_fill(0, 3, null)); // partes [entrante, média]: consolidado de fim de semana
            $algum = false;
            for ($e = 0; $e < 13; $e++) {
                for ($t = 0; $t < 3; $t++) {
                    $vs = array_map(fn ($k) => $entrDia(self::addDays($x, -7 * $k), $e, $t), [1, 2, 3, 4]);
                    if (!in_array(null, $vs, true)) {
                        $cob[$e][$t] = self::ratio($entrDia($x, $e, $t), array_sum($vs) / 4);
                        $partes[$e][$t] = [$entrDia($x, $e, $t), array_sum($vs) / 4];
                        $algum = $algum || $cob[$e][$t] !== null;
                    }
                }
            }
            if ($algum) {
                $day['entrCob'] = $cob;
                $day['entrParts'] = $partes;
            }
        }
        unset($day);
        // aba ENTRANTE da planilha: entrante congelado por dia e tipo (7 tipos), do 1º dia do portal − 56 dias (médias de até 8 semanas)
        // até o último dia com foto; só os dias com algum valor
        $entranteDia = ['dates' => [], 'values' => [], 'feriados' => self::ENTR_FERIADOS];
        if ($days) {
            for ($x = self::addDays($days[0]['date'], -56), $fim = $days[count($days) - 1]['date']; $x <= $fim; $x = self::addDays($x, 1)) {
                $m = [];
                $algum = false;
                for ($e = 0; $e < 13; $e++) {
                    for ($t = 0; $t < 7; $t++) {
                        $m[$e][$t] = $entrDia($x, $e, $t);
                        $algum = $algum || $m[$e][$t] !== null;
                    }
                }
                if ($algum) {
                    $entranteDia['dates'][] = $x;
                    $entranteDia['values'][] = $m;
                }
            }
        }

        $mapa = $this->mapa();
        $ult = $this->q('SELECT id, data_ref, inicio, fim, status, mensagem FROM log_execucao ORDER BY id DESC LIMIT 1')[0] ?? null;
        $chk = $ult ? $this->q('SELECT verificacao, ok, total, detalhe FROM chk_conciliacao WHERE execucao_id = ?', [$ult[0]]) : [];

        $extracted = date('Y-m-d\TH:i:s');
        $data = [
            'source' => 'dw_fup', 'sourceKind' => 'db', 'sourceModified' => $ult ? self::iso($ult[3]) : null, 'extracted' => $extracted,
            'entities' => array_map(fn ($x) => ['code' => $x[0], 'name' => $x[1], 'level' => $x[2], 'regional' => $x[3]], self::ENTITIES),
            'types' => self::TYPES, 'days' => $days, 'monthly' => $monthly, 'capacity' => $capacity,
            'stats' => ['blocks' => count($days), 'monthly' => count($monthly), 'errors' => 0], 'commitFile' => null,
            'robot' => $ult ? [
                'id' => (int) $ult[0], 'date' => self::day($ult[1]), 'start' => self::iso($ult[2]), 'end' => self::iso($ult[3]),
                'status' => $ult[4], 'message' => $ult[5],
            ] : null,
            'legacyUntil' => $legAte, 'mapa' => $mapa, 'entrante' => $entranteDia,
        ];
        $validation = [
            'generated' => $data['sourceModified'] ?: $extracted,
            'reference' => ['d1' => count($days) > 1 ? $days[count($days) - 2]['date'] : null, 'd0' => $days ? end($days)['date'] : null],
            'passed' => $ult && $ult[4] === 'OK',
            'checks' => array_map(fn ($c) => [
                'group' => 'Robô dw_fup', 'desc' => $c[0], 'ok' => (int) $c[1], 'total' => (int) $c[2],
                'unexplained' => (int) $c[2] - (int) $c[1], 'hypotheses' => 0, 'causes' => [],
                'examples' => (int) $c[1] !== (int) $c[2] && $c[3] ? [['ref' => mb_substr($c[3], 0, 300), 'portal' => null, 'planilha' => null, 'why' => null]] : [],
            ], $chk),
        ];

        return ['data' => $data, 'validation' => $validation];
    }

    /** Municípios de SP projetados para SVG (contornos), foto operacional por cidade e previsão de 3 dias (último D0). */
    private function mapa(): ?array
    {
        $mun = $this->q('SELECT m.cod_ibge, m.nome, m.atua, g.sigla, m.lat, m.lon, m.contorno FROM dim_municipio m LEFT JOIN dim_gerencia g ON g.cod_ger = m.cod_ger');
        if (!$mun) {
            return null;
        }
        $polys = fn ($g) => $g['type'] === 'MultiPolygon' ? $g['coordinates'] : [$g['coordinates']];
        $geos = array_map(fn ($r) => [$r, json_decode($r[6], true)], $mun);
        $lon0 = $lat0 = INF;
        $lon1 = $lat1 = -INF;
        foreach ($geos as [, $g]) {
            foreach ($polys($g) as $poly) {
                foreach ($poly as $ring) {
                    foreach ($ring as $p) {
                        $lon0 = min($lon0, $p[0]);
                        $lon1 = max($lon1, $p[0]);
                        $lat0 = min($lat0, $p[1]);
                        $lat1 = max($lat1, $p[1]);
                    }
                }
            }
        }
        $kx = cos(deg2rad(($lat0 + $lat1) / 2));
        $S = 1000 / (($lon1 - $lon0) * $kx);
        $X = fn ($lon) => round(($lon - $lon0) * $kx * $S, 1);
        $Y = fn ($lat) => round(($lat1 - $lat) * $S, 1);

        $r = $this->q('SELECT MAX(data_ref) FROM fat_cidade_diaria');
        $d0 = $r[0][0] ?? null;
        $ops = [];
        if ($d0) {
            foreach ($this->q('SELECT cod_ibge, tipo, fila, media_du, real_d1, entr_d1, entr_medio_d0 FROM fat_cidade_diaria WHERE data_ref = ?', [$d0]) as [$ib, $t, $fila, $mdu, $real, $entr, $emed]) {
                if (($t = self::ti($t)) === null) {
                    continue;
                }
                $ops[(int) $ib] ??= array_fill(0, 7, array_fill(0, 5, null));
                $ops[(int) $ib][$t] = [self::f($fila), self::f($mdu), self::f($real), self::f($entr), self::f($emed)];
            }
        }
        $r = $this->q('SELECT MAX(data_ref) FROM fat_tempo');
        $t0 = $r[0][0] ?? null;
        $tempo = $dias = [];
        if ($t0) {
            foreach ($this->q('SELECT cod_ibge, dia, codigo_tempo, temp_max, temp_min, chuva_mm, prob_chuva, vento_max FROM fat_tempo WHERE data_ref = ? ORDER BY dia', [$t0]) as [$ib, $dia, $cod, $tmax, $tmin, $chuva, $prob, $vento]) {
                $tempo[(int) $ib][] = [self::day($dia), $cod === null ? null : (int) $cod, self::f($tmax), self::f($tmin), self::f($chuva), $prob === null ? null : (int) $prob, self::f($vento)];
                $dias[self::day($dia)] = true;
            }
        }
        $dias = array_keys($dias);
        sort($dias);

        $out = [];
        foreach ($geos as [[$ib, $nome, $atua, $sigla, $lat, $lon], $g]) {
            $d = '';
            foreach ($polys($g) as $poly) {
                foreach ($poly as $ring) {
                    $d .= 'M' . implode('L', array_map(fn ($p) => $X($p[0]) . ' ' . $Y($p[1]), $ring)) . 'Z';
                }
            }
            $ib = (int) $ib;
            $out[] = [
                'ibge' => $ib, 'nome' => $nome, 'atua' => (bool) $atua, 'ger' => $sigla, 'x' => $X((float) $lon), 'y' => $Y((float) $lat), 'd' => $d,
                'op' => $atua ? ($ops[$ib] ?? null) : null, 'tempo' => $tempo[$ib] ?? null,
            ];
        }

        return [
            'd0' => $d0 ? self::day($d0) : null, 'tempoRef' => $t0 ? self::day($t0) : null, 'dias' => $dias,
            'width' => 1000, 'height' => round(($lat1 - $lat0) * $S, 1), 'municipios' => $out,
        ];
    }

    /**
     * Histórico importado da planilha (leg_*), até leg_importacao.ate. Regras:
     * dias <= ate: valores do legado; o realizado vazio no legado é completado pelo robô (foto do dia seguinte);
     * meses: legado só para (mês, serviço) que o robô não calculou; capacity: legado para datas < ate.
     */
    private function legado(array $days, array $monthly, array $capacity): array
    {
        $r = $this->q('SELECT MAX(ate) FROM leg_importacao');
        if (empty($r[0][0])) {
            return [$days, $monthly, $capacity, null];
        }
        $ate = self::day($r[0][0]);
        $robo = array_column($days, null, 'date');

        $leg = [];
        $cols = implode(', ', self::LEG_KEYS);
        foreach ($this->q("SELECT data, entidade, tipo, bloco_planilha, alvo_du, $cols FROM leg_bloco_diario WHERE data <= ?", [$ate]) as $row) {
            [$d, $e, $t, $col, $alvo] = $row;
            $vals = array_slice($row, 5);
            [$d, $e, $ti] = [self::day($d), self::ei($e), self::ti($t, self::TIPO8)];
            if ($e === null || $ti === null) {
                continue;
            }
            $leg[$d] ??= ['col' => null, '_alvo' => null] + array_fill_keys(array_keys(self::LEG_KEYS), self::blank(8));
            $leg[$d]['col'] = $col;
            if ($alvo !== null && $t !== 'TOTAL') {
                $leg[$d]['_alvo'] ??= array_fill(0, 6, null);
                $leg[$d]['_alvo'][$ti] = self::f($alvo);
            }
            foreach (array_keys(self::LEG_KEYS) as $i => $k) {
                $leg[$d][$k][$e][$ti] = self::f($vals[$i]);
            }
        }
        $efet = [];
        foreach ($this->q('SELECT data, entidade, efetividade, efetividade_real, cobertura FROM leg_efetividade WHERE data <= ?', [$ate]) as [$d, $e, $a, $b, $c]) {
            if (($e = self::ei($e)) !== null) {
                $efet[self::day($d)][$e] = [self::f($a), self::f($b), self::f($c)];
            }
        }
        ksort($leg);
        $out = [];
        foreach ($leg as $d => $L) {
            $day = [
                'date' => $d, 'col' => $L['col'], 'layout' => 7, 'target' => $L['_alvo'] ?? array_fill(0, 6, null),
                'efet' => array_map(fn ($e) => $efet[$d][$e] ?? [null, null, null], range(0, 12)), 'source' => 'legado_planilha',
            ];
            foreach (array_keys(self::LEG_KEYS) as $k) {
                $day[$k] = $L[$k];
            }
            if ($R = $robo[$d] ?? null) {  // completa só o que o legado não tem (ex.: realizado gravado pela foto do dia seguinte)
                for ($e = 0; $e < 13; $e++) {
                    for ($t = 0; $t < 8; $t++) {
                        if ($day['actual'][$e][$t] === null && $R['actual'][$e][$t] !== null) {
                            $day['actual'][$e][$t] = $R['actual'][$e][$t];
                            $day['pctCommit'][$e][$t] = self::ratio($day['actual'][$e][$t], $day['commit'][$e][$t]);
                            $day['pctNeed'][$e][$t] = self::ratio($day['actual'][$e][$t], $day['need'][$e][$t]);
                        }
                    }
                }
                if (!empty($R['hcCommit'])) {
                    $day['hcCommit'] = $R['hcCommit'];
                }
                // efetividade que a planilha ainda não tinha calculado na extração (EFETIV 0 e REAL vazia): vem do robô
                for ($e = 0; $e < 13; $e++) {
                    if ($day['efet'][$e][1] === null && $R['efet'][$e][1] !== null) {
                        $day['efet'][$e] = !$day['efet'][$e][0] ? $R['efet'][$e]
                            : [$day['efet'][$e][0], $R['efet'][$e][1], self::ratio($R['efet'][$e][1], $day['efet'][$e][0])];
                    }
                }
            }
            $out[] = $day;
        }
        foreach ($days as $day) {
            if ($day['date'] > $ate) {
                $out[] = $day;
            }
        }

        // mensal: legado só onde o robô não tem o mês
        $feitos = array_flip(array_map(fn ($m) => $m['month'] . '|' . $m['service'], $monthly));
        $res = [];
        foreach ($this->q('SELECT mes, servico, entidade, aba, meta, realizado, projecao, cobertura, churn, meta_a, meta_b, pct_meta_b,
                           efetividade, meta_efetividade, soma_pesos, soma_pesos_realizados, rotulos FROM leg_mensal_resumo') as $row) {
            if (($e = self::ei($row[2])) !== null) {
                $res[self::day($row[0]) . '|' . $row[1]][$e] = array_slice($row, 3);
            }
        }
        $curva = [];
        foreach ($this->q('SELECT mes, servico, data, entidade, peso, meta_dia, realizado, forecast, gap FROM leg_mensal') as [$mes, $svc, $d, $e, $peso, $md, $real, $fc, $gap]) {
            if (($e = self::ei($e)) !== null) {
                $curva[self::day($mes) . '|' . $svc][self::day($d)][$e] = [self::f($peso), self::f($md), self::f($real), self::f($fc), self::f($gap)];
            }
        }
        ksort($curva);
        foreach ($curva as $ms => $byday) {
            [$mes, $svc] = explode('|', $ms, 2);
            $nome = $svc === 'ATIVACAO' ? 'Ativação' : 'Retirada';
            $R0 = $res[$ms] ?? [];
            if (isset($feitos[substr($mes, 0, 7) . "|$nome"]) || !isset($R0[12])) {
                continue;
            }
            ksort($byday);
            $ds = array_keys($byday);
            $col = fn ($i) => array_map(fn ($e) => isset($R0[$e]) ? self::f($R0[$e][$i]) : null, range(0, 12));
            $serie = fn ($i) => array_map(fn ($e) => array_map(fn ($d) => $byday[$d][$e][$i] ?? null, $ds), range(0, 12));
            $rec = [
                'month' => substr($mes, 0, 7), 'service' => $nome, 'sheet' => $R0[12][0], 'dates' => $ds,
                'weights' => array_map(fn ($d) => $byday[$d][12][0] ?? null, $ds),
                'sumWeights' => self::f($R0[12][11]), 'sumWeightsDone' => self::f($R0[12][12]),
                'target' => $col(1), 'actual' => $col(2), 'projection' => $col(3), 'coverage' => $col(4),
                'targetDay' => $serie(1), 'actualDay' => $serie(2), 'forecastDay' => $serie(3), 'gapDay' => $serie(4),
                'labels' => json_decode($R0[12][13] ?: '{}') ?? new \stdClass(), 'source' => 'legado_planilha',
            ];
            if ($svc === 'RETIRADA') {
                $rec += ['churn' => $col(5), 'metaA' => $col(6), 'metaB' => $col(7), 'pctMetaB' => $col(8)];
            } else {
                $rec += ['efetMonth' => $col(9), 'efetTarget' => $col(10)];
            }
            $monthly[] = $rec;
        }
        usort($monthly, fn ($a, $b) => [$a['month'], $a['service']] <=> [$b['month'], $b['service']]);

        // capacity: legado antes de "ate"
        $lc = [];
        foreach ($this->q('SELECT data, entidade, mop_plan, mop_conf, mop_real, mot_plan, mot_conf, mot_real FROM leg_capacity WHERE data < ?', [$ate]) as $row) {
            if (($e = self::ei($row[1])) !== null) {
                $lc[self::day($row[0])][$e] = array_map(fn ($v) => self::f($v), array_slice($row, 2));
            }
        }
        ksort($lc);
        $robIdx = array_keys(array_filter($capacity['dates'], fn ($x) => $x >= $ate));
        $cap = ['dates' => array_merge(array_keys($lc), array_map(fn ($i) => $capacity['dates'][$i], $robIdx))];
        foreach (['mopPlan', 'mopConf', 'mopActual', 'motPlan', 'motConf', 'motActual'] as $j => $k) {
            $cap[$k] = array_map(fn ($e) => array_merge(
                array_map(fn ($x) => $lc[$x][$e][$j] ?? null, array_keys($lc)),
                array_map(fn ($i) => $capacity[$k][$e][$i], $robIdx)
            ), range(0, 12));
        }

        return [$out, $monthly, $cap, $ate];
    }
}
