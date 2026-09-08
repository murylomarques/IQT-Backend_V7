<?php

namespace Tests\Feature;

use App\Models\Agenda;
use App\Models\AgendaManutencao;
use App\Models\BaseManutencao;
use App\Models\Empresa;
use App\Models\Regional;
use App\Models\User;
use App\Models\Vistoria;
use App\Models\VistoriaChecklistItem;
use App\Models\VistoriaManutencao;
use App\Models\VistoriaManutencaoChecklistItem;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ManutencaoRegressionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        config()->set('database.connections.sqlite.foreign_key_constraints', false);

        DB::purge('sqlite');
        DB::reconnect('sqlite');

        $this->createSchema();
        Carbon::setTestNow(Carbon::parse('2026-09-08 12:00:00'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_manutencao_backlog_marks_items_as_overdue_at_exactly_72_hours(): void
    {
        $admin = $this->createUser('admin@example.com', 1);
        $agenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-72H',
            'regional' => 'SUDESTE',
            'territorio' => 'SUDESTE',
        ]);
        $vistoria = $this->createVistoriaManutencao($agenda, $admin);
        $this->setVistoriaManutencaoCreatedAt($vistoria, now()->subHours(72));

        $this->createItemManutencao($vistoria);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/manutencao/vistorias/backlog');

        $response->assertOk();
        $response->assertJsonCount(0, 'tableData');
        $response->assertJsonPath('kpiData.slaVencido', 1);
        $this->assertDatabaseHas('vistorias_manutencao', [
            'id' => $vistoria->id,
            'status_laudo' => 'Vencido',
        ]);
    }

    public function test_manutencao_backlog_handles_under_over_and_finalizado_sla_cases(): void
    {
        $admin = $this->createUser('sla-admin@example.com', 1);

        $underAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-UNDER',
        ]);
        $overAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-OVER',
        ]);
        $finalizadoAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-FINAL',
        ]);

        $under = $this->createVistoriaManutencao($underAgenda, $admin);
        $over = $this->createVistoriaManutencao($overAgenda, $admin);
        $finalizado = $this->createVistoriaManutencao($finalizadoAgenda, $admin, [
            'status_laudo' => 'Finalizado',
        ]);
        $this->setVistoriaManutencaoCreatedAt($under, now()->subHours(71));
        $this->setVistoriaManutencaoCreatedAt($over, now()->subHours(73));
        $this->setVistoriaManutencaoCreatedAt($finalizado, now()->subHours(100));
        $this->createItemManutencao($under);
        $this->createItemManutencao($over);
        $this->createItemManutencao($finalizado);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/manutencao/vistorias/backlog');

        $response->assertOk();
        $table = collect($response->json('tableData'))->keyBy('id');

        $this->assertSame('No Prazo', $table[$under->id]['sla']);
        $this->assertSame('Pendente', $table[$under->id]['statusLaudo']);
        $this->assertFalse($table->has($over->id));
        $this->assertFalse($table->has($finalizado->id));
        $response->assertJsonPath('kpiData.slaVencido', 1);
        $this->assertDatabaseHas('vistorias_manutencao', [
            'id' => $over->id,
            'status_laudo' => 'Vencido',
        ]);
    }

    public function test_manutencao_backlog_does_not_expose_retorno_tecnico(): void
    {
        $admin = $this->createUser('retorno-admin@example.com', 1);
        $agenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-RETORNO',
        ]);
        $vistoria = $this->createVistoriaManutencao($agenda, $admin, [
            'retorno_tecnico' => 'Sim',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/manutencao/vistorias/backlog');

        $response->assertOk();
        $row = collect($response->json('tableData'))->firstWhere('id', $vistoria->id);

        $this->assertIsArray($row);
        $this->assertArrayNotHasKey('retornoTecnico', $row);
        $this->assertSame('Sem pendencia', $row['correcaoStatus']);
    }

    public function test_usuario_proprio_sees_manutencao_backlog_by_territory_not_company(): void
    {
        $desktop = Empresa::create(['nome' => 'Desktop']);
        $partner = Empresa::create(['nome' => 'Parceira']);
        $regional = Regional::create(['nome' => 'SUDESTE', 'uf' => 'SP']);
        $user = $this->createUser('fiscal@example.com', 3, $desktop, $regional);

        $insideAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $user->id,
            'empresa_tecnico' => $partner->nome,
            'numero_compromisso' => 'SA-IN',
            'regional' => 'SUDESTE',
            'territorio' => 'SUDESTE',
        ]);
        $outsideAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $user->id,
            'empresa_tecnico' => $partner->nome,
            'numero_compromisso' => 'SA-OUT',
            'regional' => 'CENTRAL',
            'territorio' => 'CENTRAL',
        ]);

        $inside = $this->createVistoriaManutencao($insideAgenda, $user);
        $outside = $this->createVistoriaManutencao($outsideAgenda, $user);
        $this->createItemManutencao($inside);
        $this->createItemManutencao($outside);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/manutencao/vistorias/backlog');

        $response->assertOk();
        $ids = collect($response->json('tableData'))->pluck('id')->all();

        $this->assertContains($inside->id, $ids);
        $this->assertNotContains($outside->id, $ids);
        $response->assertJsonPath('tableData.0.empresa', 'Parceira');
    }

    public function test_usuario_proprio_pdf_access_is_scoped_by_manutencao_territory(): void
    {
        $desktop = Empresa::create(['nome' => 'Desktop']);
        $partner = Empresa::create(['nome' => 'Parceira']);
        $regional = Regional::create(['nome' => 'SUDESTE', 'uf' => 'SP']);
        $user = $this->createUser('pdf-fiscal@example.com', 3, $desktop, $regional);

        $allowedAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $user->id,
            'empresa_tecnico' => $partner->nome,
            'regional' => 'SUDESTE',
            'territorio' => 'SUDESTE',
        ]);
        $blockedAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $user->id,
            'empresa_tecnico' => $partner->nome,
            'regional' => 'CENTRAL',
            'territorio' => 'CENTRAL',
        ]);

        $allowed = $this->createVistoriaManutencao($allowedAgenda, $user);
        $blocked = $this->createVistoriaManutencao($blockedAgenda, $user);

        Sanctum::actingAs($user);

        $this->getJson("/api/manutencao/vistorias/{$allowed->id}/data-pdf")
            ->assertOk()
            ->assertJsonPath('id', $allowed->id);

        $this->getJson("/api/manutencao/vistorias/{$blocked->id}/data-pdf")
            ->assertForbidden();
    }

    public function test_manutencao_atendimentos_show_sa_reason_not_service_type(): void
    {
        $admin = $this->createUser('motivo-admin@example.com', 1);
        $this->createBaseManutencao([
            'numero_compromisso' => 'SA-MOTIVO',
            'tipo_servico' => 'Reparo',
            'motivo_vistoria' => 'Queda de sinal',
            'tipo_trabalho' => 'Reparo',
        ]);
        $this->createBaseManutencao([
            'numero_compromisso' => 'SA-GENERICO',
            'tipo_servico' => 'Queda de sinal',
            'motivo_vistoria' => null,
            'tipo_trabalho' => 'Reparo',
        ]);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/manutencao/atendimentos');

        $response->assertOk();
        $rows = collect($response->json('data'))->keyBy('NumeroCompromisso');

        $this->assertSame('Queda de sinal', $rows['SA-MOTIVO']['Motivo']);
        $this->assertSame('N/A', $rows['SA-GENERICO']['Motivo']);
    }

    public function test_manutencao_export_is_forbidden_for_non_admin_users(): void
    {
        $user = $this->createUser('non-admin@example.com', 3);

        Sanctum::actingAs($user);

        $this->getJson('/api/export/manutencao?start_date=2026-09-01&end_date=2026-09-08')
            ->assertForbidden();
    }

    public function test_manutencao_export_is_allowed_for_admin_users(): void
    {
        $admin = $this->createUser('export-admin@example.com', 1);
        $agenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-EXPORT',
            'motivo_vistoria' => 'Queda de sinal',
            'tipo_trabalho' => 'Reparo',
        ]);
        $vistoria = $this->createVistoriaManutencao($agenda, $admin);
        $this->createItemManutencao($vistoria);

        Sanctum::actingAs($admin);

        $response = $this->get('/api/export/manutencao?start_date=2026-09-01&end_date=2026-09-08');

        $response->assertOk();
        $this->assertStringContainsString('Queda de sinal', $response->streamedContent());
    }

    public function test_quality_backlog_still_uses_quality_tables_only(): void
    {
        $admin = $this->createUser('quality-admin@example.com', 1);

        $maintenanceAgenda = $this->createAgendaManutencao([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-MAINT',
            'regional' => 'SUDESTE',
            'territorio' => 'SUDESTE',
        ]);
        $maintenance = $this->createVistoriaManutencao($maintenanceAgenda, $admin);
        $this->createItemManutencao($maintenance);

        $qualityAgenda = $this->createAgenda([
            'fiscal_id' => $admin->id,
            'numero_compromisso' => 'SA-QUAL',
            'empresa_tecnico' => 'Empresa Qualidade',
            'territorio' => 'QUALIDADE',
        ]);
        $quality = $this->createVistoria($qualityAgenda, $admin);
        $this->createItemQualidade($quality);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/vistorias/backlog');

        $response->assertOk();
        $response->assertJsonCount(1, 'tableData');
        $response->assertJsonPath('tableData.0.id', $quality->id);
        $response->assertJsonPath('tableData.0.protocolo', 'SA-QUAL');
    }

    public function test_user_list_exposes_assigned_regional_for_territory_management(): void
    {
        $admin = $this->createUser('territorio-admin@example.com', 1);
        $regional = Regional::create(['nome' => 'SUDESTE', 'uf' => 'SP']);
        $fiscal = $this->createUser('territorio-fiscal@example.com', 3, null, $regional);

        Sanctum::actingAs($admin);

        $response = $this->getJson('/api/users');

        $response->assertOk();
        $user = collect($response->json())->firstWhere('id', $fiscal->id);

        $this->assertEquals($regional->id, $user['regional_id']);
        $this->assertSame('SUDESTE', $user['regional']['nome'] ?? null);
    }

    private function createSchema(): void
    {
        Schema::create('empresas', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->string('cnpj')->nullable();
            $table->string('endereco')->nullable();
            $table->timestamps();
        });

        Schema::create('regionais', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->string('uf', 2);
            $table->timestamps();
        });

        Schema::create('cargos', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->timestamps();
        });

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('nome');
            $table->string('email')->unique();
            $table->string('password');
            $table->unsignedBigInteger('supervisor_id')->nullable();
            $table->string('status')->default('ativo');
            $table->unsignedBigInteger('empresa_id')->nullable();
            $table->unsignedBigInteger('cargo_id')->nullable();
            $table->unsignedBigInteger('regional_id')->nullable();
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('base_manutencao', function (Blueprint $table): void {
            $table->id();
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('regional')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_servico')->nullable();
            $table->string('motivo_vistoria')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            $table->string('territorio')->nullable();
            $table->timestamps();
        });

        Schema::create('agenda_manutencao', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fiscal_id');
            $table->date('data_agendamento');
            $table->time('hora_agendamento')->nullable();
            $table->string('periodo');
            $table->text('observacoes')->nullable();
            $table->string('status')->default('Agendado');
            $table->string('statusAgendamento')->default('Pendente');
            $table->string('statusLaudo')->default('Pendente');
            $table->string('tipo')->default('Agendado');
            $table->unsignedBigInteger('original_atendimento_id')->nullable();
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('regional')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_servico')->nullable();
            $table->string('motivo_vistoria')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            $table->string('territorio')->nullable();
            $table->timestamps();
        });

        Schema::create('vistorias_manutencao', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agenda_manutencao_id');
            $table->unsignedBigInteger('fiscal_id');
            $table->string('tipo');
            $table->unsignedInteger('metros_drop')->nullable();
            $table->string('retorno_tecnico')->nullable();
            $table->string('resultado_final');
            $table->text('observacoes_gerais')->nullable();
            $table->string('status_laudo')->default('Pendente');
            $table->timestamps();
        });

        Schema::create('vistoria_manutencao_checklist_itens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vistoria_manutencao_id');
            $table->string('item_key');
            $table->string('status');
            $table->text('observacao')->nullable();
            $table->string('foto_path')->nullable();
            $table->string('status_correcao')->default('Pendente');
            $table->text('observacao_correcao')->nullable();
            $table->string('foto_correcao_path')->nullable();
            $table->timestamps();
        });

        Schema::create('agenda', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('fiscal_id');
            $table->date('data_agendamento');
            $table->time('hora_agendamento')->nullable();
            $table->string('periodo');
            $table->text('observacoes')->nullable();
            $table->string('status')->default('Agendado');
            $table->string('statusAgendamento')->default('Pendente');
            $table->string('statusLaudo')->default('Pendente');
            $table->string('tipo');
            $table->unsignedBigInteger('original_atendimento_id')->nullable();
            $table->string('caso')->nullable();
            $table->string('numero_compromisso')->nullable();
            $table->string('city')->nullable();
            $table->string('data_sa_concluida')->nullable();
            $table->string('nome_tecnico')->nullable();
            $table->string('empresa_tecnico')->nullable();
            $table->string('tipo_trabalho')->nullable();
            $table->string('status_caso')->nullable();
            $table->string('cto')->nullable();
            $table->string('porta')->nullable();
            $table->string('nome_conta')->nullable();
            $table->string('endereco')->nullable();
            $table->string('telefone')->nullable();
            $table->string('territorio')->nullable();
            $table->timestamps();
        });

        Schema::create('vistorias', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('agenda_id');
            $table->unsignedBigInteger('fiscal_id');
            $table->string('tipo');
            $table->unsignedInteger('metros_drop')->nullable();
            $table->string('retorno_tecnico')->nullable();
            $table->text('observacoes_gerais')->nullable();
            $table->string('status_laudo')->default('Pendente');
            $table->timestamps();
        });

        Schema::create('vistoria_checklist_itens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('vistoria_id');
            $table->string('item_key');
            $table->string('status');
            $table->text('observacao')->nullable();
            $table->string('foto_path')->nullable();
            $table->string('status_correcao')->default('Pendente');
            $table->text('observacao_correcao')->nullable();
            $table->string('foto_correcao_path')->nullable();
            $table->timestamps();
        });
    }

    private function createUser(
        string $email,
        int $cargoId,
        ?Empresa $empresa = null,
        ?Regional $regional = null
    ): User {
        $empresa ??= Empresa::create(['nome' => 'Empresa ' . $cargoId]);

        return User::create([
            'nome' => 'Usuario ' . $cargoId,
            'email' => $email,
            'password' => 'password',
            'status' => 'ativo',
            'empresa_id' => $empresa->id,
            'cargo_id' => $cargoId,
            'regional_id' => $regional?->id,
        ]);
    }

    private function createAgendaManutencao(array $overrides = []): AgendaManutencao
    {
        return AgendaManutencao::create(array_merge([
            'fiscal_id' => $overrides['fiscal_id'] ?? 1,
            'data_agendamento' => now()->toDateString(),
            'hora_agendamento' => '09:00',
            'periodo' => 'Manha',
            'status' => 'Agendado',
            'statusAgendamento' => 'Concluido',
            'statusLaudo' => 'Pendente',
            'tipo' => 'Agendado',
            'original_atendimento_id' => 1,
            'caso' => 'CASO-1',
            'numero_compromisso' => 'SA-1',
            'regional' => 'SUDESTE',
            'city' => 'Sao Paulo',
            'data_sa_concluida' => now()->subDay()->toDateString(),
            'nome_tecnico' => 'Tecnico',
            'empresa_tecnico' => 'Parceira',
            'tipo_servico' => 'Manutencao',
            'motivo_vistoria' => 'Rompimento drop',
            'tipo_trabalho' => 'Manutencao',
            'status_caso' => 'Concluido',
            'cto' => 'CTO-1',
            'porta' => '1',
            'nome_conta' => 'Cliente',
            'endereco' => 'Rua 1',
            'telefone' => '11999999999',
            'territorio' => 'SUDESTE',
        ], $overrides));
    }

    private function createBaseManutencao(array $overrides = []): BaseManutencao
    {
        return BaseManutencao::create(array_merge([
            'caso' => 'CASO-B',
            'numero_compromisso' => 'SA-B',
            'regional' => 'SUDESTE',
            'city' => 'Sao Paulo',
            'data_sa_concluida' => now()->subDay()->toDateString(),
            'nome_tecnico' => 'Tecnico',
            'empresa_tecnico' => 'Parceira',
            'tipo_servico' => 'Manutencao',
            'motivo_vistoria' => 'Queda de sinal',
            'tipo_trabalho' => 'Manutencao',
            'status_caso' => 'Concluido',
            'cto' => 'CTO-B',
            'porta' => '1',
            'nome_conta' => 'Cliente',
            'endereco' => 'Rua B',
            'telefone' => '11999999999',
            'territorio' => 'SUDESTE',
        ], $overrides));
    }

    private function createVistoriaManutencao(
        AgendaManutencao $agenda,
        User $fiscal,
        array $overrides = []
    ): VistoriaManutencao {
        return VistoriaManutencao::create(array_merge([
            'agenda_manutencao_id' => $agenda->id,
            'fiscal_id' => $fiscal->id,
            'tipo' => 'Completa',
            'metros_drop' => 10,
            'retorno_tecnico' => 'Nao',
            'resultado_final' => 'Reprovado',
            'observacoes_gerais' => 'Fixture',
            'status_laudo' => 'Pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function setVistoriaManutencaoCreatedAt(VistoriaManutencao $vistoria, Carbon $createdAt): void
    {
        DB::table('vistorias_manutencao')
            ->where('id', $vistoria->id)
            ->update([
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);

        $vistoria->refresh();
    }

    private function createItemManutencao(VistoriaManutencao $vistoria): VistoriaManutencaoChecklistItem
    {
        return VistoriaManutencaoChecklistItem::create([
            'vistoria_manutencao_id' => $vistoria->id,
            'item_key' => 'identificacao_cto',
            'status' => 'Nao Conforme',
            'observacao' => 'Fixture',
            'foto_path' => 'evidencias/foto.jpg',
            'status_correcao' => 'Pendente',
        ]);
    }

    private function createAgenda(array $overrides = []): Agenda
    {
        return Agenda::create(array_merge([
            'fiscal_id' => $overrides['fiscal_id'] ?? 1,
            'data_agendamento' => now()->toDateString(),
            'hora_agendamento' => '09:00',
            'periodo' => 'Manha',
            'status' => 'Agendado',
            'statusAgendamento' => 'Concluido',
            'statusLaudo' => 'Pendente',
            'tipo' => 'Agendado',
            'original_atendimento_id' => 1,
            'caso' => 'CASO-Q',
            'numero_compromisso' => 'SA-Q',
            'city' => 'Sao Paulo',
            'data_sa_concluida' => now()->subDay()->toDateString(),
            'nome_tecnico' => 'Tecnico Qualidade',
            'empresa_tecnico' => 'Empresa Qualidade',
            'tipo_trabalho' => 'Instalacao',
            'status_caso' => 'Concluido',
            'cto' => 'CTO-Q',
            'porta' => '2',
            'nome_conta' => 'Cliente Qualidade',
            'endereco' => 'Rua Q',
            'telefone' => '11888888888',
            'territorio' => 'QUALIDADE',
        ], $overrides));
    }

    private function createVistoria(Agenda $agenda, User $fiscal, array $overrides = []): Vistoria
    {
        return Vistoria::create(array_merge([
            'agenda_id' => $agenda->id,
            'fiscal_id' => $fiscal->id,
            'tipo' => 'Completa',
            'metros_drop' => 12,
            'retorno_tecnico' => 'Nao',
            'observacoes_gerais' => 'Fixture',
            'status_laudo' => 'Pendente',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function createItemQualidade(Vistoria $vistoria): VistoriaChecklistItem
    {
        return VistoriaChecklistItem::create([
            'vistoria_id' => $vistoria->id,
            'item_key' => 'identificacao_cto',
            'status' => 'Nao Conforme',
            'observacao' => 'Fixture',
            'foto_path' => 'evidencias/foto.jpg',
            'status_correcao' => 'Pendente',
        ]);
    }
}
