<?php

namespace Tests\Feature;

use App\Http\Controllers\FcaChecklistController;
use App\Http\Controllers\FcaFormController;
use App\Models\FcaChecklist;
use App\Models\FcaPeriod;
use App\Models\FcaPeriodTecnico;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FcaChecklistTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        Schema::create('fca_users', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('regional')->nullable();
            $table->string('territory')->nullable();
            $table->unsignedBigInteger('manager_id')->nullable();
        });
        Schema::create('fca_periods', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_active');
            $table->timestamp('expires_at');
            $table->timestamps();
        });
        Schema::create('fca_period_tecnicos', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('fca_period_id');
            $table->string('certificado');
            $table->timestamps();
        });
        foreach (['fca_checklists', 'fca_pos'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('fca_period_id');
                $table->unsignedBigInteger('tecnico_id');
                $table->unsignedBigInteger('supervisor_id');
                $table->json('answers');
                $table->timestamps();
            });
        }
        Route::post('/test-fca-checklist', function (Request $request) {
            $request->attributes->set('fca_user', (object) ['id' => 1]);
            return app(FcaChecklistController::class)->store($request);
        });
    }

    public function test_new_options_and_question_observations_are_saved(): void
    {
        $period = FcaPeriod::create(['is_active' => true, 'expires_at' => now()->addMonth()]);
        $technician = FcaPeriodTecnico::create(['fca_period_id' => $period->id, 'certificado' => 'Sim']);
        $answers = array_map(fn($option) => [
            'section' => '01', 'question' => "Pergunta {$option}",
            'answer' => $option, 'observation' => "Observacao {$option}",
        ], ['NA', 'PRO', 'PMR', 'OK', 'ME']);
        array_unshift($answers, [
            'section' => 'SA', 'question' => 'Numero da SA', 'input_type' => 'text',
            'answer' => 'SA-1234567', 'observation' => null,
        ]);

        $this->postJson('/test-fca-checklist', ['tecnico_id' => $technician->id, 'answers' => $answers])
            ->assertCreated();
        $this->assertSame($answers, FcaChecklist::first()->answers);
        $saved = app(FcaChecklistController::class)->getForTecnico(Request::create('/'), $technician->id)->getData(true);
        $this->assertSame($answers, $saved['answers']);

        $method = new \ReflectionMethod(FcaFormController::class, 'formatTecnico');
        $method->setAccessible(true);
        $detail = $method->invoke(app(FcaFormController::class), $technician, null, $period);
        $this->assertSame('realizado', $detail['status']);
        $this->assertSame(0, $detail['po_count']);
        $this->assertFalse($detail['can_create_po']);
        $this->assertSame('1/1 Checklists', $detail['progress_text']);
    }

    public function test_old_and_invalid_answers_are_rejected_for_new_checklists(): void
    {
        $period = FcaPeriod::create(['is_active' => true, 'expires_at' => now()->addMonth()]);
        $technician = FcaPeriodTecnico::create(['fca_period_id' => $period->id, 'certificado' => 'Sim']);
        foreach (['Sim', 'Nao', 'INVALID', null] as $answer) {
            $this->postJson('/test-fca-checklist', [
                'tecnico_id' => $technician->id,
                'answers' => [['question' => 'Pergunta', 'answer' => $answer, 'observation' => '']],
            ])->assertUnprocessable()->assertJsonValidationErrors('answers.0.answer');
        }
        $this->assertSame(0, FcaChecklist::count());
    }

    public function test_non_certified_technician_needs_three_checklists_and_no_po(): void
    {
        $period = FcaPeriod::create(['is_active' => true, 'expires_at' => now()->addMonth()]);
        $technician = FcaPeriodTecnico::create(['fca_period_id' => $period->id, 'certificado' => 'Nao']);
        $method = new \ReflectionMethod(FcaFormController::class, 'formatTecnico');
        $method->setAccessible(true);
        for ($count = 0; $count <= 3; $count++) {
            $detail = $method->invoke(app(FcaFormController::class), $technician, null, $period);
            $this->assertSame($count === 3 ? 'realizado' : ($count === 0 ? 'nao_iniciado' : 'em_andamento'), $detail['status']);
            if ($count < 3) {
                FcaChecklist::create([
                    'fca_period_id' => $period->id, 'tecnico_id' => $technician->id,
                    'supervisor_id' => 1, 'answers' => [['answer' => 'OK', 'observation' => '']],
                ]);
            }
        }
    }
}
