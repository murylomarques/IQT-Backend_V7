<?php

namespace App\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Portal FUP (/fup): lançamento do Compromisso D0 (porte do compromisso.py do portal local).
 * · Hoje: abre depois que o robô grava a foto do dia (necessidade) e trava às 08:30 (horário de Brasília).
 * · A partir de quarta-feira também o sábado e o domingo seguintes (aba PLAN_FDS), cada um travando às 08:30 do próprio dia;
 *   com feriado, os dias até ele também (ver datasAbertas);
 *   sem foto ainda, a referência é a projeção de entrantes (média do mesmo dia da semana nas 4 semanas anteriores).
 * · Trava para todos, inclusive administrador; o administrador prorroga o dia de hoje (todos ou um usuário), só para um horário
 *   que ainda não passou. Removida a prorrogação, a pessoa volta a ficar travada na hora (o servidor recusa gravar).
 * · Grava só o que mudou na dw_fup.man_compromisso (conexão fup_escrita), com atualizado_por = "portal:<usuário>";
 *   recusa se outra pessoa mudou a célula depois que a tela foi aberta.
 * · Na mesma tela, HC INT e HC EPS comprometidos por polo (dw_fup.man_hc_compromisso), com a mesma trava das 08:30 (a prorrogação vale também).
 * · Histórico e prorrogações: tabelas fup_compromisso_log e fup_prorrogacoes (banco do IQT).
 */
class FupCompromissoController extends Controller
{
    private const HORA_TRAVA = '08:30';           // todas as colunas (serviços e HC INT/EPS) travam no mesmo horário
    private const HORA_TRAVA_HC = self::HORA_TRAVA;
    private const HC = ['HC_INT' => 'hc_proprio', 'HC_EPS' => 'hc_terceiro'];            // chave na tela → coluna em man_hc_compromisso
    private const QUARTA = 3;                      // Carbon: domingo = 0 … sábado = 6
    private const MAX_VALOR = 100000;
    private const DIAS_SEM = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    private const ENTR_FERIADOS = ['2026-10-12'];  // mesmos do FupController / db_portal.py
    // feriados nacionais (Carnaval e Corpus Christi são ponto facultativo, ficam de fora); incluir os anos seguintes aqui
    private const FERIADOS = [
        '2026-01-01', '2026-04-03', '2026-04-21', '2026-05-01', '2026-09-07', '2026-10-12', '2026-11-02', '2026-11-15', '2026-11-20', '2026-12-25',
        '2027-01-01', '2027-03-26', '2027-04-21', '2027-05-01', '2027-09-07', '2027-10-12', '2027-11-02', '2027-11-15', '2027-11-20', '2027-12-25',
    ];

    private static function agora(): Carbon
    {
        $t = env('FUP_AGORA');  // só para teste fora de produção
        return $t && config('app.env') !== 'production' ? Carbon::parse($t, 'America/Sao_Paulo') : now('America/Sao_Paulo');
    }

    private function q(string $sql, array $args = [], string $con = 'fup'): array
    {
        return array_map(fn ($r) => array_values((array) $r), DB::connection($con)->select($sql, $args));
    }

    private static function num($v): ?float
    {
        return $v === null ? null : (float) $v;
    }

    private static function eu(Request $r): array
    {
        return $r->attributes->get('fup_user');
    }

    private function semPermissao(Request $r)
    {
        return in_array('compromisso', self::eu($r)['telas'], true) ? null
            : response()->json(['erro' => 'Lançamento de compromisso não liberado para o seu usuário.'], 403);
    }

    // ------------------------------------------------------------------ prazos
    private static function fds(Carbon $d): bool
    {
        return $d->dayOfWeek === 0 || $d->dayOfWeek === 6;
    }

    private static function feriado(Carbon $d): bool
    {
        return in_array($d->toDateString(), self::FERIADOS, true);
    }

    /** Sábado, domingo ou feriado nacional. */
    private static function folga(Carbon $d): bool
    {
        return self::fds($d) || self::feriado($d);
    }

    /**
     * Hoje + os dias lançados antes de cada folga (sábado, domingo e feriados seguidos formam um bloco), datas Y-m-d:
     * · bloco que começa no sábado: abre na quarta anterior, só os dias do bloco (ex.: feriado na segunda → sáb, dom, seg);
     * · bloco que começa num feriado de dia de semana: abre 2 dias antes, com os dias úteis até ele
     *   (ex.: feriado na sexta → na quarta aparecem quinta, sexta, sábado e domingo).
     */
    public static function datasAbertas(Carbon $hoje): array
    {
        $out = [$hoje->toDateString()];
        $d = $hoje->copy()->addDay();
        $fim = $hoje->copy()->addDays(10);
        while ($d->lte($fim)) {
            $antes = $d->copy()->subDay();
            if (!self::folga($d) || ($antes->gt($hoje) && self::folga($antes))) {  // procura o 1º dia de cada bloco (de amanhã em diante)
                $d->addDay();
                continue;
            }
            $ini = $d->copy();
            $ult = $d->copy();
            while (self::folga($ult->copy()->addDay())) {
                $ult->addDay();
            }
            $sabado = $ini->dayOfWeek === 6;
            $abre = $ini->copy()->subDays($sabado ? 6 - self::QUARTA : 2);
            if ($hoje->gte($abre)) {
                $x = $sabado ? $ini->copy() : $hoje->copy()->addDay();
                for (; $x->lte($ult); $x->addDay()) {
                    $out[] = $x->toDateString();
                }
            }
            $d = $ult->copy()->addDay();
        }
        return array_values(array_unique($out));
    }

    private static function limite(string $usuario, string $data, string $padrao = self::HORA_TRAVA): string
    {
        $h = DB::table('fup_prorrogacoes')->where('data', $data)->whereIn('alvo', ['*', $usuario])->max('ate');
        return $h && $h > $padrao ? $h : $padrao;
    }

    private function roboOk(string $d, int $n): array
    {
        $foto = (int) $this->q('SELECT COUNT(*) FROM fat_snapshot_diario WHERE data_ref = ?', [$d])[0][0];
        $ex = $this->q("SELECT status, fim FROM log_execucao WHERE data_ref = ? AND status IN ('OK','ALERTA') AND fim IS NOT NULL ORDER BY id DESC LIMIT 1", [$d]);
        return [$foto >= $n && $ex, $ex ? ['status' => $ex[0][0], 'fim' => str_replace(' ', 'T', substr((string) $ex[0][1], 0, 19))] : null];
    }

    private function janela(string $usuario, string $data, int $n): array
    {
        $agora = self::agora();
        $d = Carbon::parse($data, 'America/Sao_Paulo')->startOfDay();
        [$robo, $exec] = $this->roboOk($data, $n);
        $lim = self::limite($usuario, $data);
        $fim = Carbon::parse("$data $lim", 'America/Sao_Paulo');
        $limHc = self::limite($usuario, $data, self::HORA_TRAVA_HC);
        $fimHc = Carbon::parse("$data $limHc", 'America/Sao_Paulo');
        if ($data === $agora->toDateString()) {
            $disp = $robo || self::folga($d);  // sábado/domingo/feriado já vêm lançados desde antes
        } else {
            $disp = in_array($data, self::datasAbertas($agora->copy()->startOfDay()), true);
        }
        return [
            'data' => $data, 'diaSemana' => self::DIAS_SEM[$d->dayOfWeek], 'feriado' => self::feriado($d), 'tipo' => $data === $agora->toDateString() ? 'hoje' : 'fds',
            'disponivel' => $disp, 'motivo' => $disp ? null : ($data === $agora->toDateString() ? 'espera' : 'fora'), 'robo' => $exec,
            'temNecessidade' => (bool) $robo, 'hora' => self::HORA_TRAVA, 'limite' => $lim, 'prorrogado' => $lim > self::HORA_TRAVA,
            'travado' => $agora->greaterThanOrEqualTo($fim), 'restanteS' => max(0, $fim->getTimestamp() - $agora->getTimestamp()),
            'limiteHc' => $limHc, 'travadoHc' => $agora->greaterThanOrEqualTo($fimHc), 'restanteHcS' => max(0, $fimHc->getTimestamp() - $agora->getTimestamp()),
        ];
    }

    // ------------------------------------------------------------------ leitura
    private function dimensoes(): array
    {
        $polos = array_map(fn ($r) => ['cod' => (int) $r[0], 'sigla' => $r[1], 'regional' => $r[2], 'nome' => $r[3], 'nomeRegional' => $r[4]],
            $this->q('SELECT cod_ger, sigla, regional, nome, nome_regional FROM dim_gerencia ORDER BY ordem'));
        $tipos = array_map(fn ($r) => ['tipo' => $r[0], 'nome' => $r[1], 'du' => (bool) $r[2]], $this->q('SELECT tipo, nome_painel, tem_du FROM dim_tipo ORDER BY ordem'));
        return [$polos, $tipos];
    }

    /** ["cod|tipo" => [valor, por, em]] do compromisso já gravado (lido pela mesma conexão que grava). */
    private function atuais(string $data): array
    {
        $out = [];
        foreach ($this->q('SELECT cod_ger, tipo, compromisso, atualizado_por, atualizado_em FROM man_compromisso WHERE data = ?', [$data], 'fup_escrita') as [$c, $t, $v, $por, $em]) {
            $out["$c|$t"] = [self::num($v), $por, $em ? str_replace(' ', 'T', substr((string) $em, 0, 19)) : null];
        }
        return $out;
    }

    /** ["cod|chave" => [valor, por, em]] de man_hc_compromisso; $cols = chave na tela → coluna (self::HC). */
    private function atuaisHc(string $data, array $cols): array
    {
        $out = [];
        foreach (DB::connection('fup_escrita')->table('man_hc_compromisso')->where('data', $data)->get() as $r) {
            $em = $r->atualizado_em ? str_replace(' ', 'T', substr((string) $r->atualizado_em, 0, 19)) : null;
            foreach ($cols as $k => $col) {
                $out["{$r->cod_ger}|$k"] = [self::num($r->$col), $r->atualizado_por, $em];
            }
        }
        return $out;
    }

    /** Média do entrante do mesmo dia da semana nas 4 semanas anteriores (feriado → dia seguinte), por "cod|tipo". */
    private function projEntrante(string $data): array
    {
        $dias = [];
        for ($k = 1; $k <= 4; $k++) {
            $x = Carbon::parse($data)->subDays(7 * $k)->toDateString();
            $dias[] = in_array($x, self::ENTR_FERIADOS, true) ? Carbon::parse($x)->addDay()->toDateString() : $x;
        }
        $in = implode(',', array_fill(0, 4, '?'));
        $leg = $foto = [];
        foreach ($this->q("SELECT data, cod_ger, tipo, SUM(entrante) FROM leg_entrante_diario WHERE data IN ($in) GROUP BY data, cod_ger, tipo", $dias) as [$x, $c, $t, $v]) {
            $leg[substr((string) $x, 0, 10) . "|$c|$t"] = self::num($v);
        }
        $seguintes = array_map(fn ($x) => Carbon::parse($x)->addDay()->toDateString(), $dias);
        foreach ($this->q("SELECT data_ref, cod_ger, tipo, entr_d1 FROM fat_snapshot_diario WHERE data_ref IN ($in)", $seguintes) as [$x, $c, $t, $v]) {
            $foto[Carbon::parse(substr((string) $x, 0, 10))->subDay()->toDateString() . "|$c|$t"] = self::num($v);
        }
        $chaves = [];
        foreach (array_merge(array_keys($leg), array_keys($foto)) as $k) {
            $chaves[substr($k, 11)] = true;
        }
        $out = [];
        foreach (array_keys($chaves) as $ct) {
            $vs = array_map(fn ($x) => $leg["$x|$ct"] ?? $foto["$x|$ct"] ?? null, $dias);
            $out[$ct] = in_array(null, $vs, true) ? null : array_sum($vs) / 4;
        }
        return $out;
    }

    private function montaEstado(array $u, ?string $data): array
    {
        $agora = self::agora();
        $abertas = self::datasAbertas($agora->copy()->startOfDay());
        $data = $data && in_array($data, $abertas, true) ? $data : $agora->toDateString();
        [$polos, $tipos] = $this->dimensoes();
        $n = count($polos) * count($tipos);
        $jan = $this->janela($u['usuario'], $data, $n);
        $dias = array_map(fn ($x) => $this->janela($u['usuario'], $x, $n), $abertas);
        $foto = [];
        foreach ($this->q('SELECT cod_ger, tipo, necessidade, fila, du, emax FROM fat_snapshot_diario WHERE data_ref = ?', [$data]) as [$c, $t, $nv, $f, $du, $e]) {
            $foto["$c|$t"] = ['nec' => self::num($nv), 'fila' => self::num($f), 'du' => self::num($du), 'emax' => self::num($e)];
        }
        $atuais = $this->atuais($data);
        $proj = $this->projEntrante($data);
        $d7 = Carbon::parse($data)->subDays(7)->toDateString();
        $comp7 = $real7 = [];
        foreach ($this->q('SELECT cod_ger, tipo, compromisso FROM man_compromisso WHERE data = ?', [$d7]) as [$c, $t, $v]) {
            $comp7["$c|$t"] = self::num($v);
        }
        foreach ($this->q('SELECT cod_ger, tipo, real_d1 FROM fat_snapshot_diario WHERE data_ref = ?', [Carbon::parse($d7)->addDay()->toDateString()]) as [$c, $t, $v]) {
            $real7["$c|$t"] = self::num($v);
        }
        $cel = [];
        foreach ($polos as $p) {
            foreach ($tipos as $t) {
                $k = $p['cod'] . '|' . $t['tipo'];
                $a = $atuais[$k] ?? [null, null, null];
                $cel[$k] = ($foto[$k] ?? ['nec' => null, 'fila' => null, 'du' => null, 'emax' => null])
                    + ['proj' => $proj[$k] ?? null, 'valor' => $a[0], 'por' => $a[1], 'em' => $a[2], 'comp7' => $comp7[$k] ?? null, 'real7' => $real7[$k] ?? null];
            }
        }
        $atHc = $this->atuaisHc($data, self::HC);
        $hc7 = $this->atuaisHc($d7, self::HC);
        $hc = [];
        foreach ($polos as $p) {
            foreach (array_keys(self::HC) as $h) {
                $k = $p['cod'] . '|' . $h;
                $a = $atHc[$k] ?? [null, null, null];
                $hc[$k] = ['valor' => $a[0], 'por' => $a[1], 'em' => $a[2], 'sem7' => $hc7[$k][0] ?? null];
            }
        }
        $out = ['data' => $data, 'hoje' => $agora->toDateString(), 'agora' => $agora->format('Y-m-d\TH:i:s'), 'janela' => $jan, 'dias' => $dias,
            'polos' => $polos, 'tipos' => $tipos, 'celulas' => $cel, 'hc' => $hc, 'semanaAnterior' => $d7, 'modoTeste' => false];
        if ($u['admin']) {
            $todos = null;
            $ind = [];
            foreach (DB::table('fup_prorrogacoes')->where('data', $agora->toDateString())->get() as $p) {
                if ($p->alvo === '*') {
                    $todos = $p->ate;
                } else {
                    $ind[$p->alvo] = $p->ate;
                }
            }
            $out['prorrogacoes'] = ['todos' => $todos, 'usuarios' => (object) $ind];
            $out['usuariosCompromisso'] = array_values(array_map(fn ($x) => $x['usuario'], array_filter(FupAcessoController::listar(),
                fn ($x) => $x['ativo'] && in_array('compromisso', $x['telas'], true))));
        }
        return $out;
    }

    /** GET /fup/compromisso?data= */
    public function estado(Request $request)
    {
        if ($r = $this->semPermissao($request)) {
            return $r;
        }
        try {
            return response()->json($this->montaEstado(self::eu($request), $request->query('data')), 200, [], JSON_UNESCAPED_UNICODE);
        } catch (Throwable $e) {
            report($e);
            return response()->json(['erro' => 'Não foi possível ler o banco dw_fup.'], 500);
        }
    }

    // ------------------------------------------------------------------ gravação
    /** POST /fup/compromisso {data, base: {"cod|tipo": valor carregado}, valores: {"cod|tipo": novo|null}} */
    public function salvar(Request $request)
    {
        if ($r = $this->semPermissao($request)) {
            return $r;
        }
        $u = self::eu($request);
        $agora = self::agora();
        $data = (string) $request->input('data', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data) || !in_array($data, self::datasAbertas($agora->copy()->startOfDay()), true)) {
            return $this->erro('Esse dia não está aberto para lançamento (ou a tela está desatualizada: recarregue a página).');
        }
        $base = $request->input('base') ?: [];
        $valores = $request->input('valores') ?: [];
        if (!is_array($base) || !is_array($valores) || count($valores) > 200) {
            return $this->erro('Dados inválidos.');
        }
        [$polos, $tipos] = $this->dimensoes();
        $tps = array_column($tipos, 'tipo');
        $r = $this->compara($valores, $base, $this->atuais($data) + $this->atuaisHc($data, self::HC), array_column($polos, 'cod'), array_merge($tps, array_keys(self::HC)));
        if (is_string($r)) {
            return $this->erro($r);
        }
        [$mud, $conf] = $r;
        $chaves = array_map(fn ($k) => explode('|', (string) $k)[1], array_keys($valores));
        $jan = $this->janela($u['usuario'], $data, count($polos) * count($tipos));
        if (array_intersect($chaves, $tps)) {
            if (!$jan['disponivel']) {
                return $this->erro('A base de hoje ainda não foi atualizada pelo robô.');
            }
            if ($jan['travado']) {
                return $this->erro('Lançamento de ' . Carbon::parse($data)->format('d/m') . " travado desde as {$jan['limite']}. Peça uma prorrogação ao administrador.");
            }
        }
        if (array_intersect($chaves, array_keys(self::HC)) && $jan['travadoHc']) {
            return $this->erro('HC INT/EPS de ' . Carbon::parse($data)->format('d/m') . " travado desde as {$jan['limiteHc']}. Peça uma prorrogação ao administrador.");
        }
        if ($conf) {
            return $this->erro('Outra pessoa alterou ' . implode(', ', array_slice($conf, 0, 6)) . '. Recarregue a tela para ver os valores atuais; nada foi gravado.');
        }
        if ($mud) {
            $por = mb_substr('portal:' . $u['usuario'], 0, 60);
            $comp = array_values(array_filter($mud, fn ($m) => !isset(self::HC[$m[1]])));
            if ($comp) {
                DB::connection('fup_escrita')->table('man_compromisso')->upsert(
                    array_map(fn ($m) => ['data' => $data, 'cod_ger' => $m[0], 'tipo' => $m[1], 'compromisso' => $m[3], 'atualizado_por' => $por], $comp),
                    ['data', 'cod_ger', 'tipo'], ['compromisso', 'atualizado_por']);
            }
            $this->gravaHc($data, array_filter($mud, fn ($m) => isset(self::HC[$m[1]])), self::HC, $por);
            $this->registra($u, $data, $mud);
        }
        return response()->json(['gravadas' => count($mud), 'estado' => $this->montaEstado($u, $data)], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /**
     * Confere os valores enviados contra os gravados: [mudanças [cod, chave, de, para], conflitos] ou a mensagem de erro.
     * Conflito = alguém mudou a célula depois que a tela foi aberta (o valor carregado, em $base, não bate com o atual).
     */
    private function compara(array $valores, array $base, array $atuais, array $cods, array $chaves)
    {
        $mud = $conf = [];
        foreach ($valores as $k => $v) {
            $p = explode('|', (string) $k);
            if (count($p) !== 2 || !ctype_digit($p[0]) || !in_array((int) $p[0], $cods, true) || !in_array($p[1], $chaves, true)) {
                return 'Dados inválidos.';
            }
            [$c, $t] = [(int) $p[0], $p[1]];
            if ($v !== null) {
                if (is_bool($v) || !is_numeric($v) || is_string($v) || (float) $v != (int) $v || $v < 0 || $v > self::MAX_VALOR) {
                    return "Valor inválido em $k: use um número inteiro de 0 a " . self::MAX_VALOR . '.';
                }
                $v = (int) $v;
            }
            $atual = $atuais[$k][0] ?? null;
            $atual = $atual === null ? null : (int) round($atual);
            if ($v === $atual) {
                continue;
            }
            $b = $base[$k] ?? null;
            if (($b === null ? null : (int) $b) !== $atual) {
                $conf[] = "$c/$t (agora " . ($atual ?? 'vazio') . ')';
                continue;
            }
            $mud[] = [$c, $t, $atual, $v];
        }
        return [$mud, $conf];
    }

    /** Grava só as colunas alteradas de man_hc_compromisso (uma linha por dia × polo). */
    private function gravaHc(string $data, array $mud, array $cols, string $por): void
    {
        $porCod = [];
        foreach ($mud as [$c, $k, , $v]) {
            $porCod[$c][$cols[$k]] = $v;
        }
        if (!$porCod) {
            return;
        }
        $db = DB::connection('fup_escrita');
        $db->transaction(function () use ($db, $data, $porCod, $por) {
            foreach ($porCod as $c => $set) {
                $db->table('man_hc_compromisso')->updateOrInsert(['data' => $data, 'cod_ger' => $c], $set + ['atualizado_por' => $por]);
            }
        });
    }

    private function registra(array $u, string $data, array $mud): void
    {
        DB::table('fup_compromisso_log')->insert(array_map(fn ($m) => ['em' => self::agora()->toDateTimeString(), 'usuario' => $u['usuario'], 'acao' => 'valor',
            'data' => $data, 'cod_ger' => $m[0], 'tipo' => $m[1], 'de' => $m[2], 'para' => $m[3]], $mud));
        \Illuminate\Support\Facades\Cache::forget('fup:portal:v1');  // o portal passa a mostrar o valor novo
    }

    /** POST /fup/compromisso/prorrogar {data, ate: "HH:MM"|null, usuario: string|null} (administrador; só hoje) */
    public function prorrogar(Request $request)
    {
        $u = self::eu($request);
        if (!$u['admin']) {
            return response()->json(['erro' => 'Só administradores.'], 403);
        }
        $agora = self::agora();
        $data = (string) $request->input('data', '');
        if ($data !== $agora->toDateString()) {
            return $this->erro('Só dá para prorrogar o dia de hoje.');
        }
        $ate = $request->input('ate');
        if ($ate !== null) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) $ate)) {
                return $this->erro('Horário inválido (use HH:MM).');
            }
            if ($ate <= self::HORA_TRAVA) {
                return $this->erro('A prorrogação precisa ser depois das ' . self::HORA_TRAVA . '.');
            }
            if ($ate <= $agora->format('H:i')) {
                return $this->erro('Esse horário já passou (agora são ' . $agora->format('H:i') . '). Escolha um horário mais tarde.');
            }
        }
        $alvo = $request->input('usuario');
        if ($alvo !== null) {
            $alvo = strtolower(trim((string) $alvo));
            if (!DB::table('fup_usuarios')->where('usuario', $alvo)->exists()) {
                return $this->erro('Usuário não encontrado.');
            }
        }
        $chave = ['data' => $data, 'alvo' => $alvo ?? '*'];
        if ($ate === null) {
            DB::table('fup_prorrogacoes')->where($chave)->delete();
        } else {
            DB::table('fup_prorrogacoes')->updateOrInsert($chave, ['ate' => $ate]);
        }
        DB::table('fup_compromisso_log')->insert(['em' => $agora->toDateTimeString(), 'usuario' => $u['usuario'], 'acao' => 'prorrogacao',
            'data' => $data, 'para_quem' => $alvo ?? 'todos', 'ate' => $ate]);
        return response()->json(['ok' => true, 'estado' => $this->montaEstado($u, $request->input('dataTela'))], 200, [], JSON_UNESCAPED_UNICODE);
    }

    /** GET /fup/compromisso/log?de=&ate= (administrador) — por dia do compromisso */
    public function log(Request $request)
    {
        if (!self::eu($request)['admin']) {
            return response()->json(['erro' => 'Só administradores.'], 403);
        }
        $hoje = self::agora()->toDateString();
        $de = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('de')) ? $request->query('de') : $hoje;
        $ate = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $request->query('ate')) ? $request->query('ate') : $hoje;
        $reg = DB::table('fup_compromisso_log')->whereBetween('data', [$de, $ate])->orderByDesc('em')->orderByDesc('id')->limit(5000)->get()
            ->map(fn ($r) => array_filter([
                'em' => str_replace(' ', 'T', substr((string) $r->em, 0, 19)), 'usuario' => $r->usuario, 'acao' => $r->acao, 'data' => substr((string) $r->data, 0, 10),
                'cod' => $r->cod_ger === null ? null : (int) $r->cod_ger, 'tipo' => $r->tipo, 'de' => $r->de === null ? null : (int) $r->de,
                'para' => $r->para === null ? null : (int) $r->para, 'para_quem' => $r->para_quem, 'ate' => $r->ate,
            ], fn ($v, $k) => $v !== null || in_array($k, ['de', 'para', 'ate'], true), ARRAY_FILTER_USE_BOTH))->values();
        return response()->json(['de' => $de, 'ate' => $ate, 'registros' => $reg], 200, [], JSON_UNESCAPED_UNICODE);
    }

    private function erro(string $msg)
    {
        return response()->json(['erro' => $msg], 409);
    }
}
