<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Portal FUP: confere o token (Authorization: Bearer). O banco guarda só o sha256 do token.
 * Sessão expira em 12 h ou depois de 2 h sem uso; usuário desativado perde o acesso na hora.
 * Coloca o usuário em $request->attributes['fup_user'] (array: id, usuario, nome, admin, dono, telas).
 */
class FupAuth
{
    public const OCIOSO_MIN = 120;

    public function handle(Request $request, Closure $next)
    {
        $token = (string) $request->header('Authorization', '');
        if (str_starts_with($token, 'Bearer ')) {
            $token = substr($token, 7);
        }
        if (strlen($token) < 32) {
            return response()->json(['erro' => 'Sessão expirada. Entre de novo.'], 401);
        }
        $agora = now('America/Sao_Paulo');
        $s = DB::table('fup_sessoes as s')->join('fup_usuarios as u', 'u.id', '=', 's.usuario_id')
            ->where('s.token_hash', hash('sha256', $token))
            ->first(['s.id as sessao_id', 's.usada_em', 's.expira_em', 'u.id', 'u.usuario', 'u.nome', 'u.admin', 'u.dono', 'u.ativo', 'u.telas']);
        if (!$s || !$s->ativo || $agora->toDateTimeString() >= $s->expira_em
            || $agora->copy()->subMinutes(self::OCIOSO_MIN)->toDateTimeString() > $s->usada_em) {
            if ($s) {
                DB::table('fup_sessoes')->where('id', $s->sessao_id)->delete();
            }
            return response()->json(['erro' => 'Sessão expirada. Entre de novo.'], 401);
        }
        if ($agora->copy()->subMinute()->toDateTimeString() > $s->usada_em) {  // grava o uso no máximo 1 vez por minuto
            DB::table('fup_sessoes')->where('id', $s->sessao_id)->update(['usada_em' => $agora->toDateTimeString()]);
        }
        $request->attributes->set('fup_user', self::publico($s));
        $request->attributes->set('fup_sessao', $s->sessao_id);

        return $next($request);
    }

    /** Dados do usuário que a tela pode ver (o "dono" fica só no servidor). */
    public static function publico(object $u): array
    {
        $admin = (bool) $u->admin;
        $telas = json_decode($u->telas ?? '[]', true) ?: [];
        return [
            'id' => (int) $u->id, 'usuario' => $u->usuario, 'nome' => $u->nome ?: $u->usuario, 'admin' => $admin,
            'dono' => (bool) ($u->dono ?? false),
            'telas' => $admin ? \App\Http\Controllers\FupAcessoController::TELA_IDS
                : array_values(array_intersect(\App\Http\Controllers\FupAcessoController::TELA_IDS, $telas)),
        ];
    }
}
