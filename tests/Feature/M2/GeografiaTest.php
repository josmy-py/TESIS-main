<?php

namespace Tests\Feature\M2;

use App\Models\Departamento;
use App\Models\Distrito;
use App\Models\Municipio;
use App\Models\User;
use Database\Seeders\GeografiaElSalvadorSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M2.1.5: división territorial de El Salvador (seeder idempotente y consultas en cascada).
 */
class GeografiaTest extends TestCase
{
    use DatosM1, DatosM2, RefreshDatabase;

    public function test_el_archivo_de_datos_tiene_la_estructura_del_decreto(): void
    {
        $datos = require database_path(GeografiaElSalvadorSeeder::ARCHIVO);

        $this->assertCount(14, $datos);
        $this->assertSame(44, collect($datos)->sum(fn ($m) => count($m)));
        $this->assertSame(262, collect($datos)->flatten()->count());

        foreach ($datos as $departamento => $municipios) {
            foreach ($municipios as $municipio => $distritos) {
                // Cada municipio nuevo lleva el nombre del departamento y un punto cardinal
                $this->assertMatchesRegularExpression('/^'.preg_quote($departamento, '/').' (Norte|Sur|Este|Oeste|Centro|Costa)$/u', $municipio);
                $this->assertSame(count($distritos), count(array_unique($distritos)), "Distrito repetido en {$municipio}");
                foreach ($distritos as $distrito) {
                    $this->assertLessThanOrEqual(50, mb_strlen($distrito));
                }
            }
        }
    }

    public function test_seeder_crea_el_catalogo_con_claves_foraneas_validas(): void
    {
        $this->seed(GeografiaElSalvadorSeeder::class);

        $this->assertSame(14, Departamento::count());
        $this->assertSame(44, Municipio::count());
        $this->assertSame(262, Distrito::count());

        $this->assertSame(0, Municipio::whereNotIn('departamento_id', Departamento::pluck('id'))->count());
        $this->assertSame(0, Distrito::whereNotIn('municipio_id', Municipio::pluck('id'))->count());

        $distrito = Distrito::where('nombre', 'El Paisnal')->with('municipio.departamento')->sole();
        $this->assertSame('San Salvador Norte', $distrito->municipio->nombre);
        $this->assertSame('San Salvador', $distrito->municipio->departamento->nombre);

        // El mismo nombre de distrito existe en municipios distintos
        $this->assertSame(3, Distrito::where('nombre', 'El Rosario')->distinct()->count('municipio_id'));
    }

    public function test_seeder_es_idempotente_y_conserva_registros_existentes(): void
    {
        // Datos anteriores: un distrito del catálogo ya usado por un estudiante y uno ajeno al catálogo
        $catalogos = $this->catalogosBasicos(); // San Salvador / San Salvador Centro / San Salvador
        $ajeno = DB::table('distritos')->insertGetId(['nombre' => 'Distrito de prueba', 'municipio_id' => Municipio::first()->id]);
        $encargado = $this->sembrarRolesYEncargado();
        $estudiante = $this->estudianteDe($encargado, $catalogos['distrito']);

        $this->seed(GeografiaElSalvadorSeeder::class);
        $conteos = [Departamento::count(), Municipio::count(), Distrito::count()];
        $this->seed(GeografiaElSalvadorSeeder::class);

        $this->assertSame([14, 44, 263], $conteos);
        $this->assertSame($conteos, [Departamento::count(), Municipio::count(), Distrito::count()]);

        // El distrito existente se reutiliza (mismo id) y el ajeno no se borra
        $this->assertSame($catalogos['distrito'], Distrito::where('nombre', 'San Salvador')->sole()->id);
        $this->assertNotNull(Distrito::find($ajeno));
        $this->assertSame($catalogos['distrito'], $estudiante->fresh()->distrito_id);

        // Sin duplicados dentro del mismo padre
        $this->assertSame(0, DB::table('municipios')->select('nombre', 'departamento_id')->groupBy('nombre', 'departamento_id')->havingRaw('count(*) > 1')->count());
        $this->assertSame(0, DB::table('distritos')->select('nombre', 'municipio_id')->groupBy('nombre', 'municipio_id')->havingRaw('count(*) > 1')->count());
    }

    public function test_consultas_en_cascada(): void
    {
        $this->seed(GeografiaElSalvadorSeeder::class);
        $this->sembrarRoles();
        $cuenta = $this->usuario(User::ROL_ENCARGADO);

        $departamentos = $this->como($cuenta)->getJson('/api/departamentos')->assertOk()->json('departamentos');
        $this->assertCount(14, $departamentos);
        $this->assertSame('Ahuachapán', $departamentos[0]['nombre']);

        $sanSalvador = collect($departamentos)->firstWhere('nombre', 'San Salvador');
        $municipios = $this->como($cuenta)->getJson("/api/departamentos/{$sanSalvador['id']}/municipios")->assertOk()
            ->assertJsonPath('departamento.nombre', 'San Salvador')->json('municipios');
        $this->assertSame(['San Salvador Centro', 'San Salvador Este', 'San Salvador Norte', 'San Salvador Oeste', 'San Salvador Sur'], array_column($municipios, 'nombre'));

        $norte = collect($municipios)->firstWhere('nombre', 'San Salvador Norte');
        $distritos = $this->como($cuenta)->getJson("/api/municipios/{$norte['id']}/distritos")->assertOk()
            ->assertJsonPath('municipio.departamento.nombre', 'San Salvador')->json('distritos');
        $this->assertSame(['Aguilares', 'El Paisnal', 'Guazapa'], array_column($distritos, 'nombre'));

        // El detalle de un distrito devuelve la misma jerarquía
        $this->como($cuenta)->getJson("/api/distritos/{$distritos[1]['id']}")->assertOk()
            ->assertJsonPath('distrito.municipio.nombre', 'San Salvador Norte')
            ->assertJsonPath('distrito.municipio.departamento.nombre', 'San Salvador');
        $this->assertCount(262, $this->como($cuenta)->getJson('/api/distritos')->assertOk()->json('distritos'));

        $this->como($cuenta)->getJson('/api/departamentos/99999/municipios')->assertNotFound()->assertJsonPath('message', 'Departamento no encontrado');
        $this->como($cuenta)->getJson('/api/municipios/99999/distritos')->assertNotFound()->assertJsonPath('message', 'Municipio no encontrado');
        $this->como($cuenta)->getJson('/api/distritos/99999')->assertNotFound();
    }

    public function test_consultas_requieren_sesion_y_no_hay_escritura(): void
    {
        $this->seed(GeografiaElSalvadorSeeder::class);
        $this->sembrarRoles();

        foreach (['/api/departamentos', '/api/departamentos/1/municipios', '/api/municipios/1/distritos', '/api/distritos', '/api/distritos/1'] as $ruta) {
            $this->sinSesion()->getJson($ruta)->assertStatus(401);
        }

        $director = $this->usuario(User::ROL_DIRECTOR);
        $this->como($director)->postJson('/api/departamentos', ['nombre' => 'X'])->assertStatus(405);
        $this->assertSame(14, Departamento::count());
    }

    private function sembrarRolesYEncargado(): User
    {
        $this->sembrarRoles();

        return $this->usuario(User::ROL_ENCARGADO);
    }
}
