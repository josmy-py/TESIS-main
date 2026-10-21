<?php

namespace Tests\Feature\Constancias;

use App\Models\AnioLectivo;
use App\Models\Especialidad;
use App\Models\Estudiante;
use App\Models\Grado;
use App\Models\NotaFinal;
use App\Models\Seccion;
use App\Models\User;
use App\Services\Admin\ConstanciasPdfService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\M8\DatosAcademicos;
use Tests\TestCase;

/**
 * Constancia de Egresado: los datos salen del NIE y solo se emite si el
 * estudiante aprobó el último año de su bachillerato (nota anual Aprobada).
 */
class ConstanciaEgresadoTest extends TestCase
{
    use DatosAcademicos, RefreshDatabase;

    private array $catalogos;
    private User $director;
    private array $secciones = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
        Especialidad::whereKey($this->catalogos['especialidad'])->update(['nombre' => 'Bachillerato General']);

        $docente = $this->docenteNuevo(1)[1];
        foreach (['2025' => 'Primer año de bachillerato', '2026' => 'Segundo año de bachillerato'] as $anio => $grado) {
            $lectivo = AnioLectivo::create(['anio' => $anio, 'fecha_inicio' => "{$anio}-01-15", 'fecha_fin' => "{$anio}-11-30"]);
            $this->secciones[$grado] = Seccion::create([
                'nombre' => 'A', 'capacidad' => 30, 'turno' => 'matutino', 'anio_lectivo_id' => $lectivo->id,
                'grado_id' => Grado::create(['nombre' => $grado])->id, 'profesor_id' => $docente->id,
            ]);
        }
    }

    /** Estudiante con matrículas aprobadas: [grado => [promedio, estado] | null (sin nota anual)]. */
    private function estudiante(string $nie, array $matriculas, ?int $especialidad = null): Estudiante
    {
        $estudiante = $this->estudianteDe($this->usuario(User::ROL_ENCARGADO), $this->catalogos['distrito'], ['nombre' => 'Angel Francisco Erazo Lopez', 'nie' => $nie]);
        foreach ($matriculas as $grado => $nota) {
            $seccion = $this->secciones[$grado];
            $id = $this->matriculaDe($estudiante, $this->catalogos, [
                'estado' => 'aprobado', 'seccion_id' => $seccion->id, 'anio_lectivo_id' => $seccion->anio_lectivo_id,
                'especialidad_id' => $especialidad ?? $this->catalogos['especialidad'],
            ]);
            if ($nota) {
                NotaFinal::create(['matricula_id' => $id, 'promedio_anual' => $nota[0], 'estado' => $nota[1]]);
            }
        }

        return $estudiante;
    }

    public function test_datos_del_egresado_por_nie(): void
    {
        $this->estudiante('176040304', ['Primer año de bachillerato' => [8, 'Aprobada'], 'Segundo año de bachillerato' => [8.5, 'Aprobada']]);

        $this->como($this->director)->getJson('/api/constancias/egresado?nie=176040304')->assertOk()->assertExactJson([
            'nombre' => 'Angel Francisco Erazo Lopez', 'nie' => '176040304', 'nivel' => 'Bachillerato',
            'opcion' => 'Bachillerato General', 'grado' => 'Segundo año de bachillerato', 'seccion' => 'A', 'anio_lectivo' => '2026',
        ]);
    }

    public function test_solo_si_termino_el_bachillerato(): void
    {
        $url = '/api/constancias/egresado?nie=';
        $this->estudiante('1000001', ['Primer año de bachillerato' => [8, 'Aprobada']]);
        $this->estudiante('1000002', ['Primer año de bachillerato' => [8, 'Aprobada'], 'Segundo año de bachillerato' => null]);
        $this->estudiante('1000003', ['Primer año de bachillerato' => [8, 'Aprobada'], 'Segundo año de bachillerato' => [5.5, 'Reprobada']]);
        $tecnica = Especialidad::create(['nombre' => 'Técnico Vocacional en Contaduría'])->id;
        $this->estudiante('1000004', ['Primer año de bachillerato' => [8, 'Aprobada'], 'Segundo año de bachillerato' => [9, 'Aprobada']], $tecnica);

        $this->como($this->director)->getJson($url.'9999999')->assertNotFound()->assertJson(['message' => 'No hay ningún estudiante registrado con ese NIE.']);
        $this->como($this->director)->getJson($url.'1000001')->assertStatus(409)->assertJson(['message' => 'Angel Francisco Erazo Lopez aún no ha terminado el bachillerato: su última matrícula aprobada es de Primer año de bachillerato (2025).']);
        $this->como($this->director)->getJson($url.'1000002')->assertStatus(409)->assertJson(['message' => 'La matrícula de Segundo año de bachillerato (2026) de Angel Francisco Erazo Lopez aún no tiene nota anual registrada.']);
        $this->como($this->director)->getJson($url.'1000003')->assertStatus(409)->assertJson(['message' => 'Angel Francisco Erazo Lopez no aprobó Segundo año de bachillerato en 2026.']);
        $this->como($this->director)->getJson($url.'1000004')->assertStatus(409)->assertJsonFragment(['message' => 'La opción Técnico Vocacional en Contaduría termina en tercer año de bachillerato, que aún no está en el catálogo de grados de SIGA: no se puede emitir la constancia de egresado.']);
        $this->como($this->director)->getJson($url)->assertStatus(422)->assertJsonValidationErrors('nie');

        // Sin bachillerato: estudiante de básica
        $basica = $this->estudianteDe($this->usuario(User::ROL_ENCARGADO), $this->catalogos['distrito'], ['nombre' => 'Luis', 'nie' => '1000005']);
        $this->como($this->director)->getJson($url.$basica->nie)->assertStatus(409)->assertJson(['message' => 'Luis no tiene matrículas aprobadas de bachillerato en la institución.']);
    }

    public function test_pdf_de_la_constancia(): void
    {
        $this->estudiante('176040304', ['Segundo año de bachillerato' => [8.5, 'Aprobada']]);
        $this->travelTo(now()->parse('2026-10-22 03:00:00', 'UTC')); // 21/10 en El Salvador
        $datos = ['nie' => '176040304', 'director' => 'Lic. Rolando Alexander Hernandez Peñate'];

        $r = $this->como($this->director)->postJson('/api/constancias/egresado/pdf', $datos)->assertOk();
        $this->assertSame('application/pdf', $r->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $r->getContent());

        $vista = app(ConstanciasPdfService::class)->datosConstanciaEgresado([...$datos, 'fecha' => '2020-01-01']);
        $this->assertSame('21 de octubre de 2026', $vista['fecha']);
        $html = view('constancias.egresado', $vista)->render();
        foreach (['REPÚBLICA DE EL SALVADOR', 'CONSTANCIA DE EGRESADO', 'HACE CONSTAR:', 'ANGEL FRANCISCO ERAZO LOPEZ', '176040304',
            'BACHILLERATO GENERAL</span>', '<strong>2026</strong>', 'EGRESADO(A)', 'Cantón El Tule, 21 de octubre de 2026.',
            'Lic. Rolando Alexander Hernandez Peñate', 'Sello Institucional'] as $texto) {
            $this->assertStringContainsString($texto, $html);
        }

        // Validaciones, NIE que no terminó y solo el director
        $this->como($this->director)->postJson('/api/constancias/egresado/pdf', [...$datos, 'director' => ''])->assertStatus(422)->assertJsonValidationErrors('director');
        $this->estudiante('1000001', ['Primer año de bachillerato' => [8, 'Aprobada']]);
        $this->como($this->director)->postJson('/api/constancias/egresado/pdf', [...$datos, 'nie' => '1000001'])->assertStatus(409);
        $this->como($this->usuario(User::ROL_DOCENTE))->postJson('/api/constancias/egresado/pdf', $datos)->assertForbidden();
        $this->como($this->usuario(User::ROL_DOCENTE))->getJson('/api/constancias/egresado?nie=176040304')->assertForbidden();
    }
}
