<?php

namespace App\Console\Commands;

use App\Http\Controllers\FupAcessoController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cria o PRIMEIRO administrador do portal FUP (/fup), que fica como "dono" (nenhum outro administrador o altera).
 * Só funciona com a tabela fup_usuarios vazia; os próximos usuários são criados pela tela Usuários do portal.
 *   php artisan fup:criar-admin murylo.marques --nome="Murylo Marques"          (pede a senha, sem mostrar)
 *   php artisan fup:criar-admin murylo.marques --gerar                           (gera uma senha e mostra uma vez)
 */
class FupCriarAdmin extends Command
{
    protected $signature = 'fup:criar-admin {usuario} {--nome=} {--gerar : gera uma senha aleatória}';
    protected $description = 'Cria o primeiro administrador (dono) do portal FUP';

    public function handle(): int
    {
        if (DB::table('fup_usuarios')->exists()) {
            $this->error('Já existem usuários do portal FUP: crie os próximos pela tela Usuários (logado como administrador).');
            return self::FAILURE;
        }
        $usuario = strtolower(trim($this->argument('usuario')));
        if (!preg_match('/^[a-z0-9._-]{3,40}$/', $usuario)) {
            $this->error('Usuário: 3 a 40 caracteres, só letras minúsculas, números, ponto, hífen ou sublinhado.');
            return self::FAILURE;
        }
        if ($this->option('gerar')) {
            $alf = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
            $senha = 'Fup-' . implode('', array_map(fn () => $alf[random_int(0, strlen($alf) - 1)], range(1, 12)));
        } else {
            $senha = (string) $this->secret('Senha (mínimo ' . FupAcessoController::SENHA_MIN . ' caracteres)');
            if ($senha !== (string) $this->secret('Repita a senha')) {
                $this->error('As senhas não conferem. Nada foi criado.');
                return self::FAILURE;
            }
        }
        if ($e = FupAcessoController::validaSenha($senha)) {
            $this->error($e);
            return self::FAILURE;
        }
        $agora = FupAcessoController::agora();
        DB::table('fup_usuarios')->insert([
            'usuario' => $usuario, 'nome' => $this->option('nome') ?: $usuario, 'senha' => Hash::make($senha),
            'admin' => true, 'dono' => true, 'ativo' => true, 'telas' => json_encode(FupAcessoController::TELA_IDS),
            'created_at' => $agora, 'updated_at' => $agora,
        ]);
        $this->info("Administrador \"$usuario\" criado.");
        if ($this->option('gerar')) {
            $this->line("Senha: $senha   (troque em \"Trocar senha\" depois de entrar)");
        }
        return self::SUCCESS;
    }
}
