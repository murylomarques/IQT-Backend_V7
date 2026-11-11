<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Portal FUP (/fup): login próprio, permissões por tela e lançamento do Compromisso D0.
 * Tabelas no banco do IQT (conexão padrão, como o FCA). O compromisso em si é gravado no dw_fup (man_compromisso).
 * SQL equivalente para revisão: database/sql/fup_acesso.sql
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fup_usuarios', function (Blueprint $t) {
            $t->id();
            $t->string('usuario', 40)->unique();
            $t->string('nome', 80);
            $t->string('senha');                       // Hash::make (bcrypt); nunca a senha em si
            $t->boolean('admin')->default(false);
            $t->boolean('dono')->default(false);       // protegido: só ele mesmo se altera (invisível na tela)
            $t->boolean('ativo')->default(true);
            $t->json('telas');                         // ["resumo","metas",...]
            $t->timestamps();
        });

        Schema::create('fup_sessoes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('usuario_id')->constrained('fup_usuarios')->cascadeOnDelete();
            $t->char('token_hash', 64)->unique();      // sha256 do token; o token em si só fica no navegador
            $t->dateTime('criada_em');
            $t->dateTime('usada_em');
            $t->dateTime('expira_em');
        });

        Schema::create('fup_compromisso_log', function (Blueprint $t) {
            $t->id();
            $t->dateTime('em');                        // horário de Brasília
            $t->string('usuario', 40);
            $t->string('acao', 12);                    // valor | prorrogacao
            $t->date('data');                          // dia do compromisso
            $t->unsignedTinyInteger('cod_ger')->nullable();
            $t->string('tipo', 10)->nullable();
            $t->integer('de')->nullable();
            $t->integer('para')->nullable();
            $t->string('para_quem', 40)->nullable();   // prorrogação: usuário ou "todos"
            $t->string('ate', 5)->nullable();          // prorrogação: HH:MM (null = removida)
            $t->index(['data', 'em']);
        });

        Schema::create('fup_prorrogacoes', function (Blueprint $t) {
            $t->date('data');
            $t->string('alvo', 40);                    // usuário, ou "*" = todos
            $t->string('ate', 5);                      // HH:MM
            $t->primary(['data', 'alvo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fup_prorrogacoes');
        Schema::dropIfExists('fup_compromisso_log');
        Schema::dropIfExists('fup_sessoes');
        Schema::dropIfExists('fup_usuarios');
    }
};
