<?php

namespace Tests\Feature\Reportes;

use App\Models\Boleta;
use App\Models\Matricula;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte Institucional del director: indicadores del nivel en el período que
 * el formulario toma de la base (matrícula, nuevos ingresos, boletas, docentes).
 */
class ReporteInstitucionalTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    public function test_indicadores_por_nivel_y_periodo(): void
    {
        $this->prepararSeccion();
        $director = $this->usuario(User::ROL_DIRECTOR);
        Matricula::whereKey($this->matriculas['Zoila Pérez'])->update(['ingreso' => 'Reingreso']);

        $periodo = $this->periodos[0]->id;
        foreach ([['Ana López', 9, 9.5], ['Zoila Pérez', 6, 6.5]] as [$nombre, $matematica, $lenguaje]) {
            Boleta::create(['promedio' => $matematica, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->matematica->id, 'periodo_escolar_id' => $periodo]);
            Boleta::create(['promedio' => $lenguaje, 'matricula_id' => $this->matriculas[$nombre], 'asignacion_id' => $this->lenguaje->id, 'periodo_escolar_id' => $periodo]);
        }
        $url = fn (string $nivel, int $periodo) => '/api/reportes/institucional/indicadores?nivel='.urlencode($nivel).'&periodo_escolar_id='.$periodo;

        // Pedro Pendiente no cuenta; Ana 9.25 destacada, Zoila 6.25 reprueba
        $this->como($director)->getJson($url('Educación Básica', $periodo))->assertOk()->assertExactJson([
            'total_estudiantes' => 2, 'nuevos_ingresos' => 1, 'estudiantes_evaluados' => 2, 'promedio_general' => 7.75,
            'estudiantes_destacados' => 1, 'estudiantes_reprobados' => 1, 'total_docentes' => 2,
        ]);

        // Período sin boletas y nivel sin secciones
        $r = $this->como($director)->getJson($url('Educación Básica', $this->periodos[1]->id))->assertOk();
        $this->assertSame(2, $r->json('total_estudiantes'));
        $this->assertNull($r->json('promedio_general'));
        $this->assertSame(0, $r->json('estudiantes_reprobados'));
        $r = $this->como($director)->getJson($url('Parvularia', $periodo))->assertOk();
        $this->assertSame(0, $r->json('total_estudiantes'));
        $this->assertSame(0, $r->json('total_docentes'));

        $this->como($director)->getJson($url('Media', $periodo))->assertStatus(422);
        $this->como($director)->getJson($url('Bachillerato', 999))->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson($url('Educación Básica', $periodo))->assertForbidden();
    }
}
