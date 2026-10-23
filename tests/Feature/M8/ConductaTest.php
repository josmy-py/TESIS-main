<?php

namespace Tests\Feature\M8;

use App\Models\AnioLectivo;
use App\Models\Conducta;
use App\Models\PeriodoEscolar;
use App\Models\User;
use App\Services\ConductaService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * M8 Conducta (diagrama: Conducta.registrar(); Docente > Conducta > Registrar
 * observaciones / Seguimiento conductual). Pedido de Josmy del 23/10/2026: 5
 * criterios fijos con su valoración, una sola vez por estudiante y periodo,
 * cuando el periodo está cerrado y el año lectivo sigue activo.
 */
class ConductaTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private const VALORACIONES = ['Excelente', 'Bueno', 'Bueno', 'Malo', 'Deficiente'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->periodos[0]->forceFill(['estado' => PeriodoEscolar::CERRADO])->save();
    }

    private function registrar($matriculaId, array $valoraciones = self::VALORACIONES, ?User $docente = null, $periodo = null)
    {
        return $this->como($docente ?? $this->cuentaDocente)->putJson("/api/docente/secciones/{$this->seccion->id}/conducta", [
            'periodo_escolar_id' => ($periodo ?? $this->periodos[0])->id, 'matricula_id' => $matriculaId, 'valoraciones' => $valoraciones,
        ]);
    }

    private function listar($periodo = null)
    {
        $consulta = $periodo ? "?periodo_escolar_id={$periodo->id}" : '';

        return $this->como($this->cuentaDocente)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta{$consulta}")->assertOk();
    }

    public function test_lista_estudiantes_aprobados_con_sus_criterios(): void
    {
        $r = $this->listar();

        $this->assertSame(['Ana López', 'Zoila Pérez'], collect($r->json('estudiantes'))->pluck('estudiante.nombre')->all());
        $this->assertSame($this->periodos[0]->id, $r->json('periodo.id'));
        $this->assertTrue($r->json('editable'));
        $this->assertNull($r->json('motivo'));
        $this->assertSame(ConductaService::CRITERIOS, $r->json('criterios'));
        $this->assertFalse($r->json('estudiantes.0.registrada'));
        $this->assertSame(ConductaService::CRITERIOS, collect($r->json('estudiantes.0.criterios'))->pluck('criterio')->all());
        $this->assertSame([null, null, null, null, null], collect($r->json('estudiantes.0.criterios'))->pluck('valoracion')->all());
    }

    public function test_registra_los_cinco_criterios_una_sola_vez(): void
    {
        $ana = $this->matriculas['Ana López'];
        $this->registrar($ana)->assertOk()->assertJson(['registradas' => 5, 'reemplazadas' => 0]);
        $this->assertSame(5, Conducta::where('matricula_id', $ana)->count());

        $r = $this->listar();
        $this->assertTrue($r->json('estudiantes.0.registrada'));
        $this->assertSame(self::VALORACIONES, collect($r->json('estudiantes.0.criterios'))->pluck('valoracion')->all());
        $this->assertFalse($r->json('estudiantes.1.registrada'));

        // Ya registrada: no se crea otra ni se modifica, tampoco desde otro docente de la sección
        $this->registrar($ana, array_fill(0, 5, 'Excelente'))->assertStatus(409)
            ->assertJson(['message' => 'La conducta de este estudiante ya está registrada en este periodo.']);
        $this->registrar($ana, array_fill(0, 5, 'Excelente'), $this->cuentaOtroDocente)->assertStatus(409);
        $this->assertSame(5, Conducta::count());
        $this->assertSame(1, Conducta::where('valoracion', 'Deficiente')->count());

        // El siguiente periodo se registra cuando termine
        $this->registrar($ana, self::VALORACIONES, null, $this->periodos[1])->assertStatus(409);
        $this->periodos[1]->forceFill(['estado' => PeriodoEscolar::CERRADO])->save();
        $this->registrar($ana, self::VALORACIONES, null, $this->periodos[1])->assertOk();
        $this->assertSame(10, Conducta::count());
    }

    public function test_solo_con_el_periodo_cerrado_y_el_anio_activo(): void
    {
        $this->periodos[0]->forceFill(['estado' => PeriodoEscolar::ACTIVO])->save();
        $r = $this->listar();
        $this->assertFalse($r->json('editable'));
        $this->assertSame('La conducta se registra cuando el periodo Primer trimestre termine (esté cerrado).', $r->json('motivo'));
        $this->registrar($this->matriculas['Ana López'])->assertStatus(409)
            ->assertJson(['message' => 'La conducta se registra cuando el periodo Primer trimestre termine (esté cerrado).']);

        $this->periodos[0]->forceFill(['estado' => PeriodoEscolar::CERRADO])->save();
        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->assertFalse($this->listar()->json('editable'));
        $this->registrar($this->matriculas['Ana López'])->assertStatus(409);
        $this->assertSame(0, Conducta::count());
    }

    public function test_reemplaza_la_conducta_del_formato_anterior(): void
    {
        $ana = $this->matriculas['Ana López'];
        Conducta::create(['descripcion' => 'Texto libre', 'valoracion' => 'Bueno', 'matricula_id' => $ana, 'periodo_escolar_id' => $this->periodos[0]->id]);
        $this->assertFalse($this->listar()->json('estudiantes.0.registrada'));

        $this->registrar($ana)->assertOk()->assertJson(['registradas' => 5, 'reemplazadas' => 1]);
        $this->assertSame(0, Conducta::where('descripcion', 'Texto libre')->count());
        $this->assertSame(ConductaService::CRITERIOS, Conducta::orderBy('id')->pluck('descripcion')->all());
    }

    public function test_validaciones(): void
    {
        $ana = $this->matriculas['Ana López'];
        $this->registrar($ana, ['Excelente', 'Bueno'])->assertStatus(422)->assertJsonValidationErrors('valoraciones');
        $this->registrar($ana, ['Excelente', 'Bueno', 'Regular', '', 'Malo'])
            ->assertStatus(422)->assertJsonValidationErrors(['valoraciones.2', 'valoraciones.3']);
        $this->registrar($this->matriculas['Pedro Pendiente'])->assertStatus(422)->assertJsonValidationErrors('matricula_id');
        $this->registrar(999)->assertStatus(422)->assertJsonValidationErrors('matricula_id');

        $otroAnio = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $ajeno = PeriodoEscolar::create(['nombre' => 'P', 'nivel' => 1, 'anio_lectivo_id' => $otroAnio->id]);
        $this->registrar($ana, self::VALORACIONES, null, $ajeno)->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');
        $this->assertSame(0, Conducta::count());
    }

    public function test_solo_docentes_de_la_seccion(): void
    {
        [$ajeno] = $this->docenteNuevo(3);
        $this->como($ajeno)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")->assertNotFound();
        $this->registrar($this->matriculas['Ana López'], self::VALORACIONES, $ajeno)->assertNotFound();
        $this->como($this->encargado)->getJson("/api/docente/secciones/{$this->seccion->id}/conducta")->assertForbidden();
        $this->assertSame(0, Conducta::count());
    }
}
