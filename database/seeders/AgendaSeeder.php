<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class AgendaSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run(): void
    {
        DB::table('agenda')->insert([
            [
                'fiscal_id' => 2,
                'data_agendamento' => '2025-10-06',
                'hora_agendamento' => '09:00:00',
                'periodo' => 'Manhã',
                'observacoes' => 'Primeiro agendamento de teste',
                'status' => 'Agendado',
                'statusAgendamento' => 'Pendente',
                'statusLaudo' => 'Pendente',
                'tipo' => 'Inspeção',
                'original_atendimento_id' => 1001,
                'caso' => 'Caso A',
                'numero_compromisso' => '12345',
                'city' => 'Nova Décia',
                'data_sa_concluida' => null,
                'nome_tecnico' => 'João Silva',
                'empresa_tecnico' => 'Empresa X',
                'tipo_trabalho' => 'Verificação',
                'status_caso' => 'Aberto',
                'cto' => 'CTO-01',
                'porta' => 'P01',
                'nome_conta' => 'Conta Teste',
                'endereco' => 'Rua Teste, 123',
                'telefone' => '11999999999',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'fiscal_id' => 2,
                'data_agendamento' => '2025-10-07',
                'hora_agendamento' => '14:00:00',
                'periodo' => 'Tarde',
                'observacoes' => 'Segundo agendamento de teste',
                'status' => 'Agendado',
                'statusAgendamento' => 'Pendente',
                'statusLaudo' => 'Pendente',
                'tipo' => 'Manutenção',
                'original_atendimento_id' => 1002,
                'caso' => 'Caso B',
                'numero_compromisso' => '12346',
                'city' => 'Nova Décia',
                'data_sa_concluida' => null,
                'nome_tecnico' => 'Maria Souza',
                'empresa_tecnico' => 'Empresa Y',
                'tipo_trabalho' => 'Reparo',
                'status_caso' => 'Aberto',
                'cto' => 'CTO-02',
                'porta' => 'P02',
                'nome_conta' => 'Conta Teste 2',
                'endereco' => 'Rua Teste, 456',
                'telefone' => '11988888888',
                'created_at' => now(),
                'updated_at' => now()
            ]
        ]);
    }
}
