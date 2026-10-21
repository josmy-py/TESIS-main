<?php

namespace Tests\Feature\Reportes;

use App\Models\Grado;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Profesor\ReportesProfesorPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte de Conducta de un estudiante (portal docente): datos de la base, el
 * docente que inició sesión como profesor y PDF con dompdf. No guarda nada.
 */
class ReporteConductaTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private const URL = '/api/docente/reportes/conducta/pdf';

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-06-10 15:00:00', 'UTC'));
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'grado_id' => $this->seccion->grado_id, 'seccion_id' => $this->seccion->id, 'periodo_escolar_id' => $this->periodos[0]->id,
            'matricula_id' => $this->matriculas['Ana López'], 'conducta' => 'Regular',
            'descripcion_hechos' => 'Interrupciones ocasionales en clase.',
            'acciones_tomadas' => "Llamado de atención verbal.\n\n  Comunicación con el responsable.  ",
            'compromiso_mejora' => 'Mantener una actitud respetuosa.',
        ], $cambios);
    }

    public function test_el_docente_genera_el_reporte(): void
    {
        $r = $this->como($this->cuentaOtroDocente)->postJson(self::URL, $this->datos())->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());
        $this->assertDatabaseCount('conductas', 0);

        $html = view('profesor.conducta', app(ReportesProfesorPdfService::class)->datosConducta($this->cuentaOtroDocente, $this->datos()))->render();
        foreach (['COMPLEJO EDUCATIVO HACIENDA COLIMA', 'REPORTE DE CONDUCTA', 'Ana López', '10/06/2026', 'Docente 2', 'Primer grado',
            'Primer trimestre (2026)', '<td class="valor">Regular</td>', 'Interrupciones ocasionales en clase.',
            '<td>Llamado de atención verbal.</td>', '<td>Comunicación con el responsable.</td>', 'Mantener una actitud respetuosa.',
            'Docente Responsable', 'Padre / Madre o Responsable', 'Director(a)'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }
        $this->assertSame(2, substr_count($html, '☑'));
    }

    public function test_validaciones(): void
    {
        $otroGrado = Grado::create(['nombre' => 'Segundo grado']);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['grado_id' => $otroGrado->id]))
            ->assertStatus(422)->assertJsonValidationErrors('seccion_id');

        [, $tercero] = $this->docenteNuevo(3);
        $ajena = Seccion::create(['nombre' => 'B', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $this->anio->id,
            'grado_id' => $this->seccion->grado_id, 'profesor_id' => $tercero->id]);
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['seccion_id' => $ajena->id]))->assertNotFound();

        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['matricula_id' => $this->matriculas['Pedro Pendiente']]))
            ->assertStatus(422)->assertJsonValidationErrors('matricula_id');
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['periodo_escolar_id' => 999]))
            ->assertStatus(422)->assertJsonValidationErrors('periodo_escolar_id');
        $this->como($this->cuentaDocente)->postJson(self::URL, $this->datos(['conducta' => '', 'acciones_tomadas' => str_repeat('a', 501)]))
            ->assertStatus(422)->assertJsonValidationErrors(['conducta', 'acciones_tomadas']);
    }

    public function test_solo_docentes(): void
    {
        $this->como($this->usuario(User::ROL_DIRECTOR))->postJson(self::URL, $this->datos())->assertForbidden();
        $this->como($this->encargado)->postJson(self::URL, $this->datos())->assertForbidden();
    }
}
