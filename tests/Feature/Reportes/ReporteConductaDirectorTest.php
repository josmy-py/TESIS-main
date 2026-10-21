<?php

namespace Tests\Feature\Reportes;

use App\Models\AnioLectivo;
use App\Models\PeriodoEscolar;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Reporte de Conducta del director: el formulario toma de la base el total de
 * estudiantes con matrícula aprobada en el año del período.
 */
class ReporteConductaDirectorTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    public function test_total_de_estudiantes_del_anio_del_periodo(): void
    {
        $this->prepararSeccion();
        $director = $this->usuario(User::ROL_DIRECTOR);
        $url = '/api/reportes/conducta/indicadores?periodo_escolar_id=';

        // Ana y Zoila aprobadas; Pedro pendiente no cuenta
        $this->como($director)->getJson($url.$this->periodos[0]->id)->assertOk()->assertExactJson(['total_estudiantes' => 2]);

        $otro = AnioLectivo::create(['anio' => '2027', 'fecha_inicio' => '2027-01-15', 'fecha_fin' => '2027-11-30']);
        $periodo = PeriodoEscolar::create(['nombre' => 'Primer trimestre', 'nivel' => 1, 'anio_lectivo_id' => $otro->id]);
        $this->como($director)->getJson($url.$periodo->id)->assertOk()->assertExactJson(['total_estudiantes' => 0]);

        $this->como($director)->getJson($url.'999')->assertStatus(422);
        $this->como($this->cuentaDocente)->getJson($url.$this->periodos[0]->id)->assertForbidden();
    }
}
