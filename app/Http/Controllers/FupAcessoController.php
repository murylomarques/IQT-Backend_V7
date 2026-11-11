<?php

namespace App\Http\Controllers;

use App\Http\Middleware\FupAuth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Portal FUP (/fup): login próprio, sessões e usuários com telas liberadas (porte do auth.py do portal local).
 * · Senha só como hash (bcrypt). Token aleatório; no banco fica só o sha256 dele.
 * · 5 tentativas erradas seguidas bloqueiam o usuário por 5 minutos (mensagem sempre genérica).
 * · O "dono" (primeiro administrador, criado por `php artisan fup:criar-admin`) não pode ser alterado/excluído por outro
 *   administrador; a tela não sabe quem é (a recusa é neutra).
 */
class FupAcessoController extends Controller
{
    // telas liberáveis (id = chave do VIEWS no app.js). O assistente (chat) só existe no portal local.
    public const TELAS = [
        ['resumo', 'Resumo'], ['entrantes', 'Entrantes'], ['backlog', 'Backlog'], ['mapa', 'Mapa'], ['metas', 'Metas do mês'],
        ['capacity', 'Capacity'], ['conferencia', 'Conferência'], ['compromisso', 'Compromisso D0 (lançar)'],
    ];
    public const TELA_IDS = ['resumo', 'entrantes', 'backlog', 'mapa', 'metas', 'capacity', 'conferencia', 'compromisso'];
    public const SENHA_MIN = 8;
    private const SESSAO_H = 12, MAX_ERROS = 5, BLOQUEIO_S = 300;

    public static function agora()
    {
        return now('America/Sao_Paulo');
    }

    /** Usuário para a tela (sem o "dono"). */
    public static function pub(array $u): array
    {
        unset($u['dono'], $u['id']);
        return $u;
    }

    // ------------------------------------------------------------------ público
    /** GET /fup/estado → a tela de login avisa quando ainda não há nenhum usuário. */
    public function estado()
    {
        return response()->json(['configurado' => DB::table('fup_usuarios')->exists()]);
    }

    /** POST /fup/login {usuario, senha} → {token, usuario} */
    public function login(Request $request)
    {
        $usuario = strtolower(trim(substr((string) $request->input('usuario', ''), 0, 40)));
        $senha = substr((string) $request->input('senha', ''), 0, 200);
        $chave = 'fup-login:' . $usuario;
        if (RateLimiter::tooManyAttempts($chave, self::MAX_ERROS)) {
            return response()->json(['erro' => 'Muitas tentativas. Aguarde alguns minutos e tente de novo.'], 429);
        }
        $u = DB::table('fup_usuarios')->where('usuario', $usuario)->first();
        // usuário inexistente também passa por um Hash::check (mesmo tempo de resposta, não revela quem existe)
        $ok = Hash::check($senha, $u->senha ?? Hash::make('fup-usuario-inexistente')) && $u && $u->ativo;
        if (!$ok) {
            RateLimiter::hit($chave, self::BLOQUEIO_S);
            return response()->json(['erro' => 'Usuário ou senha inválidos.'], 401);
        }
        RateLimiter::clear($chave);
        $token = bin2hex(random_bytes(32));
        $agora = self::agora();
        DB::table('fup_sessoes')->where('expira_em', '<', $agora->toDateTimeString())->delete();
        DB::table('fup_sessoes')->insert([
            'usuario_id' => $u->id, 'token_hash' => hash('sha256', $token), 'criada_em' => $agora->toDateTimeString(),
            'usada_em' => $agora->toDateTimeString(), 'expira_em' => $agora->copy()->addHours(self::SESSAO_H)->toDateTimeString(),
        ]);
        return response()->json(['token' => $token, 'usuario' => self::pub(FupAuth::publico($u))]);
    }

    // ------------------------------------------------------------------ logado
    public function logout(Request $request)
    {
        DB::table('fup_sessoes')->where('id', $request->attributes->get('fup_sessao'))->delete();
        return response()->json(['ok' => true]);
    }

    public function me(Request $request)
    {
        return response()->json(self::pub($request->attributes->get('fup_user')));
    }

    /** POST /fup/senha {atual, nova} */
    public function senha(Request $request)
    {
        $eu = $request->attributes->get('fup_user');
        $u = DB::table('fup_usuarios')->where('id', $eu['id'])->first();
        if (!$u || !Hash::check(substr((string) $request->input('atual', ''), 0, 200), $u->senha)) {
            return response()->json(['erro' => 'Senha atual incorreta.'], 400);
        }
        if ($erro = self::validaSenha($request->input('nova'))) {
            return response()->json(['erro' => $erro], 400);
        }
        DB::table('fup_usuarios')->where('id', $u->id)->update(['senha' => Hash::make($request->input('nova')), 'updated_at' => self::agora()]);
        return response()->json(['ok' => true]);
    }

    public static function validaSenha($s): ?string
    {
        if (!is_string($s) || mb_strlen($s) < self::SENHA_MIN) {
            return 'A senha precisa ter pelo menos ' . self::SENHA_MIN . ' caracteres.';
        }
        return mb_strlen($s) > 200 ? 'Senha longa demais.' : null;
    }

    // ------------------------------------------------------------------ administração
    private function soAdmin(Request $request)
    {
        return $request->attributes->get('fup_user')['admin'] ? null : response()->json(['erro' => 'Só administradores.'], 403);
    }

    public static function listar(): array
    {
        return DB::table('fup_usuarios')->orderBy('usuario')->get()->map(fn ($u) => self::pub(FupAuth::publico($u)) + [
            'ativo' => (bool) $u->ativo,
            'criado' => $u->created_at ? str_replace(' ', 'T', substr((string) $u->created_at, 0, 19)) : null,
            'alterado' => $u->updated_at ? str_replace(' ', 'T', substr((string) $u->updated_at, 0, 19)) : null,
        ])->all();
    }

    /** GET /fup/usuarios */
    public function usuarios(Request $request)
    {
        if ($r = $this->soAdmin($request)) {
            return $r;
        }
        return response()->json(['usuarios' => self::listar(), 'telas' => self::TELAS, 'senhaMin' => self::SENHA_MIN]);
    }

    /** POST /fup/usuarios {usuario, nome, admin, ativo, telas[], senha?} — cria ou altera */
    public function salvar(Request $request)
    {
        if ($r = $this->soAdmin($request)) {
            return $r;
        }
        $eu = $request->attributes->get('fup_user');
        $usuario = strtolower(trim((string) $request->input('usuario', '')));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            return $this->erro('Usuário: 3 a 40 caracteres, só letras minúsculas, números, ponto, hífen ou sublinhado.');
        }
        $nome = mb_substr(trim((string) $request->input('nome', '')), 0, 80) ?: $usuario;
        $telas = array_values(array_intersect(self::TELA_IDS, (array) $request->input('telas', [])));
        $admin = (bool) $request->input('admin');
        $ativo = (bool) $request->input('ativo', true);
        $senha = $request->input('senha') ?: null;

        return DB::transaction(function () use ($eu, $usuario, $nome, $telas, $admin, $ativo, $senha) {
            $atual = DB::table('fup_usuarios')->where('usuario', $usuario)->lockForUpdate()->first();
            if ($atual && $atual->dono && $eu['usuario'] !== $usuario) {
                return $this->erro('Você não tem permissão para alterar este usuário.');
            }
            if (!$atual && !$senha) {
                return $this->erro('Defina a senha inicial do usuário.');
            }
            if ($senha && ($e = self::validaSenha($senha))) {
                return $this->erro($e);
            }
            if (!$admin && !$telas) {
                return $this->erro('Marque pelo menos uma tela (ou deixe como administrador).');
            }
            if ($usuario === $eu['usuario'] && (!$admin || !$ativo)) {
                return $this->erro('Você não pode tirar o seu próprio acesso de administrador nem se desativar.');
            }
            $outroAdmin = DB::table('fup_usuarios')->where('admin', true)->where('ativo', true)->when($atual, fn ($q) => $q->where('id', '<>', $atual->id))->exists();
            if (!$outroAdmin && !($admin && $ativo)) {
                return $this->erro('Precisa existir pelo menos um administrador ativo.');
            }
            $dados = ['nome' => $nome, 'admin' => $admin, 'ativo' => $ativo, 'telas' => json_encode($telas), 'updated_at' => self::agora()];
            if ($senha) {
                $dados['senha'] = Hash::make($senha);
            }
            if ($atual) {
                DB::table('fup_usuarios')->where('id', $atual->id)->update($dados);
            } else {
                DB::table('fup_usuarios')->insert($dados + ['usuario' => $usuario, 'dono' => false, 'created_at' => self::agora()]);
            }
            if ($atual && $usuario !== $eu['usuario'] && (!$ativo || $senha)) {
                DB::table('fup_sessoes')->where('usuario_id', $atual->id)->delete();  // desativado ou senha trocada: sai na hora
            }
            return response()->json(['ok' => true, 'usuarios' => self::listar()]);
        });
    }

    /** POST /fup/usuarios/excluir {usuario} */
    public function excluir(Request $request)
    {
        if ($r = $this->soAdmin($request)) {
            return $r;
        }
        $eu = $request->attributes->get('fup_user');
        $usuario = strtolower(trim((string) $request->input('usuario', '')));
        $u = DB::table('fup_usuarios')->where('usuario', $usuario)->first();
        if (!$u) {
            return $this->erro('Usuário não encontrado.');
        }
        if ($usuario === $eu['usuario']) {
            return $this->erro('Você não pode excluir o seu próprio usuário.');
        }
        if ($u->dono) {
            return $this->erro('Você não tem permissão para excluir este usuário.');
        }
        if ($u->admin && !DB::table('fup_usuarios')->where('admin', true)->where('ativo', true)->where('id', '<>', $u->id)->exists()) {
            return $this->erro('Precisa existir pelo menos um administrador ativo.');
        }
        DB::table('fup_usuarios')->where('id', $u->id)->delete();  // sessões caem junto (cascade)
        DB::table('fup_sessoes')->where('usuario_id', $u->id)->delete();
        return response()->json(['ok' => true, 'usuarios' => self::listar()]);
    }

    private function erro(string $msg)
    {
        return response()->json(['erro' => $msg], 400);
    }
}
