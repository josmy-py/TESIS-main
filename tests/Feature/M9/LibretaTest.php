<?php

namespace Tests\Feature\M9;

use App\Models\AnioLectivo;
use App\Models\Boleta;
use App\Models\Evaluacion;
use App\Models\NotaFinal;
use App\Models\Estudiante;
use App\Services\PromocionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * M9 Libreta: Boleta (promedio por materia y periodo) y Nota_final
 * (calcularPromedioAnual, determinarEstado).
 */
class LibretaTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
    }

    private function generar($asignacion = null, $periodo = null, $cuenta = null)
    {
        $asignacion ??= $this->matematica;

        return $this->como($cuenta ?? $this->cuentaDocente)->postJson("/api/docente/asignaciones/{$asignacion->id}/libreta", [
            'periodo_escolar_id' => ($periodo ?? $this->periodos[0])->id,
        ]);
    }

    public function test_genera_boletas_con_el_promedio_ponderado(): void
    {
        $this->conNotas($this->matematica, $this->periodos[0], [
            [40, ['Ana López' => 8, 'Zoila Pérez' => 5.5]],
            [60, ['Ana López' => 9.5, 'Zoila Pérez' => 7]],
        ]);

        $this->generar()->assertOk()->assertJson(['creadas' => 2, 'actualizadas' => 0]);
        $this->assertEquals(8.9, Boleta::where('matricula_id', $this->matriculas['Ana López'])->value('promedio'));
        $this->assertEquals(6.4, Boleta::where('matricula_id', $this->matriculas['Zoila Pérez'])->value('promedio'));
        $this->generar()->assertOk()->assertJson(['creadas' => 0, 'actualizadas' => 0]);

        $r = $this->como($this->cuentaDocente)->getJson("/api/docente/asignaciones/{$this->matematica->id}/libreta?periodo_escolar_id={$this->periodos[0]->id}")->assertOk();
        $this->assertTrue($r->json('completa'));
        $this->assertEquals([8.9, 6.4], collect($r->json('estudiantes'))->pluck('boleta')->all());
    }

    public function test_exige_100_por_ciento_y_todas_las_notas(): void
    {
        $this->conNotas($this->matematica, $this->periodos[0], [[60, ['Ana López' => 8, 'Zoila Pérez' => 7]]]);
        $this->generar()->assertStatus(422)->assertJsonValidationErrors('libreta');

        $this->conNotas($this->matematica, $this->periodos[0], [[40, ['Ana López' => 8]]]);
        $r = $this->generar()->assertStatus(422);
        $this->assertStringContainsString('Zoila Pérez', $r->json('errors.libreta.0'));
        $this->assertSame(0, Boleta::count());
    }

    public function test_cambiar_una_nota_actualiza_la_boleta(): void
    {
        $this->conNotas($this->matematica, $this->periodos[0], [[100, ['Ana López' => 8, 'Zoila Pérez' => 7]]]);
        $this->generar()->assertOk();

        $evaluacion = Evaluacion::first();
        $this->como($this->cuentaDocente)->putJson("/api/docente/evaluaciones/{$evaluacion->id}/calificaciones", [
            'notas' => [['matricula_id' => $this->matriculas['Ana López'], 'nota' => 9.25]],
        ])->assertOk();

        $this->assertEquals(9.25, Boleta::where('matricula_id', $this->matriculas['Ana López'])->value('promedio'));
    }

    public function test_nota_final_cuando_estan_todas_las_boletas(): void
    {
        $notas = fn ($ana, $zoila) => [[100, ['Ana López' => $ana, 'Zoila Pérez' => $zoila]]];
        $this->conNotas($this->matematica, $this->periodos[0], $notas(8, 6));
        $this->conNotas($this->matematica, $this->periodos[1], $notas(9, 7));
        $this->conNotas($this->lenguaje, $this->periodos[0], $notas(7, 6.5));
        $this->conNotas($this->lenguaje, $this->periodos[1], $notas(10, 8));

        $this->generar(null, $this->periodos[0])->assertOk();
        $this->generar(null, $this->periodos[1])->assertOk();
        $this->generar($this->lenguaje, $this->periodos[0], $this->cuentaOtroDocente)->assertOk();
        $this->assertSame(0, NotaFinal::count(), 'Sin todas las boletas no hay nota final');

        $this->generar($this->lenguaje, $this->periodos[1], $this->cuentaOtroDocente)->assertOk();
        $ana = NotaFinal::where('matricula_id', $this->matriculas['Ana López'])->first();
        $zoila = NotaFinal::where('matricula_id', $this->matriculas['Zoila Pérez'])->first();
        $this->assertEquals(8.5, $ana->promedio_anual);
        $this->assertSame('Aprobada', $ana->estado);
        $this->assertEquals(6.88, $zoila->promedio_anual);
        $this->assertSame('Reprobada', $zoila->estado);

        // La promoción (M6) ya puede usar la nota final
        $siguiente = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $opciones = app(PromocionService::class)->opciones(Estudiante::find($this->estudiantes['Zoila Pérez']->id), $siguiente);
        $this->assertStringContainsString('debe repetir', $opciones['mensaje']);
    }

    public function test_solo_el_docente_de_la_materia_y_anio_activo(): void
    {
        $this->conNotas($this->matematica, $this->periodos[0], [[100, ['Ana López' => 8, 'Zoila Pérez' => 7]]]);
        $this->generar(null, null, $this->cuentaOtroDocente)->assertNotFound();

        $this->anio->forceFill(['estado' => AnioLectivo::CIERRE])->save();
        $this->generar()->assertStatus(409);
        $this->assertSame(0, Boleta::count());
    }
}
