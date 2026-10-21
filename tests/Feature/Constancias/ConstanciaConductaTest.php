<?php

namespace Tests\Feature\Constancias;

use App\Models\AnioLectivo;
use App\Models\User;
use App\Services\Admin\ConstanciasPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Constancia de Buena Conducta: sin validaciones de bachillerato; por el NIE
 * salen el nombre y los años lectivos de las matrículas aprobadas.
 */
class ConstanciaConductaTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    public function test_datos_del_estudiante_por_nie(): void
    {
        $this->prepararSeccion();
        $director = $this->usuario(User::ROL_DIRECTOR);
        $ana = $this->estudiantes['Ana López'];
        $anterior = AnioLectivo::create(['anio' => '2025', 'fecha_inicio' => '2025-01-15', 'fecha_fin' => '2025-11-30']);
        $copia = (array) DB::table('matriculas')->find($this->matriculas['Ana López']);
        unset($copia['id']);
        DB::table('matriculas')->insert([...$copia, 'anio_lectivo_id' => $anterior->id, 'seccion_id' => null]);

        $this->como($director)->getJson('/api/constancias/conducta?nie='.$ana->nie)->assertOk()
            ->assertExactJson(['nombre' => 'Ana López', 'nie' => $ana->nie, 'anios' => ['2026', '2025']]);

        // Pedro solo tiene una matrícula pendiente; NIE inexistente; sin NIE
        $this->como($director)->getJson('/api/constancias/conducta?nie='.$this->estudiantes['Pedro Pendiente']->nie)
            ->assertStatus(409)->assertJson(['message' => 'Pedro Pendiente no tiene matrículas aprobadas en la institución.']);
        $this->como($director)->getJson('/api/constancias/conducta?nie=0000000000')->assertNotFound();
        $this->como($director)->getJson('/api/constancias/conducta')->assertStatus(422)->assertJsonValidationErrors('nie');
        $this->como($this->cuentaDocente)->getJson('/api/constancias/conducta?nie='.$ana->nie)->assertForbidden();
    }

    public function test_pdf_de_la_constancia(): void
    {
        $this->prepararSeccion();
        $this->travelTo(now()->parse('2026-10-22 03:00:00', 'UTC')); // 21/10 en El Salvador
        $director = $this->usuario(User::ROL_DIRECTOR);
        $ana = $this->estudiantes['Ana López'];
        $datos = ['nie' => $ana->nie, 'anio_lectivo' => '2026', 'director' => 'Lic. Rolando Alexander Hernandez Peñate'];

        $r = $this->como($director)->postJson('/api/constancias/conducta/pdf', $datos)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $vista = app(ConstanciasPdfService::class)->datosConstanciaConducta($datos);
        $html = view('constancias.conducta', $vista)->render();
        foreach (['REPÚBLICA DE EL SALVADOR', 'CONSTANCIA DE BUENA CONDUCTA', 'HACE CONSTAR:', 'ANA LÓPEZ', $ana->nie, '<strong>2026</strong>',
            'BUENA CONDUCTA</span>', 'a los 21 días del mes de octubre del año 2026.', 'Lic. Rolando Alexander Hernandez Peñate', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        // Año que no es de sus matrículas aprobadas, campos obligatorios y solo el director
        $this->como($director)->postJson('/api/constancias/conducta/pdf', [...$datos, 'anio_lectivo' => '2020'])
            ->assertStatus(422)->assertJsonValidationErrors('anio_lectivo');
        $this->como($director)->postJson('/api/constancias/conducta/pdf', [...$datos, 'director' => ''])->assertStatus(422)->assertJsonValidationErrors('director');
        $this->como($director)->postJson('/api/constancias/conducta/pdf', [...$datos, 'anio_lectivo' => ''])->assertStatus(422)->assertJsonValidationErrors('anio_lectivo');
        $this->como($this->cuentaDocente)->postJson('/api/constancias/conducta/pdf', $datos)->assertForbidden();
    }
}
