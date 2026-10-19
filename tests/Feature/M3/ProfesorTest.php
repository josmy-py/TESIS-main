<?php

namespace Tests\Feature\M3;

use App\Models\Profesor;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M3: registro de docentes vinculados con una cuenta DOCENTE ya creada en
 * Usuarios (19/10/2026: sin correo ni contraseña en el formulario), código
 * PROF-### automático y datos no editables.
 */
class ProfesorTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR);
    }

    // DUI válido a partir de 8 dígitos (calcula el verificador)
    private function dui(string $ocho): string
    {
        $suma = 0;
        foreach (str_split($ocho) as $i => $d) {
            $suma += (int) $d * (9 - $i);
        }

        return $ocho.'-'.((10 - $suma % 10) % 10);
    }

    private function datos(array $cambios = []): array
    {
        return array_merge([
            'nombre' => 'Ana López',
            'dui' => $this->dui('04567890'),
            'fecha_nacimiento' => '1990-04-20',
            'telefono' => '7123-4567',
            'direccion' => 'Colonia Escalón, San Salvador',
        ], $cambios);
    }

    // Sin user_id se crea una cuenta DOCENTE libre para el profesor
    private function crear(array $cambios = [])
    {
        if (! array_key_exists('user_id', $cambios)) {
            $cambios['user_id'] = $this->usuario(User::ROL_DOCENTE)->id;
        }

        return $this->como($this->director)->postJson('/api/profesores', $this->datos($cambios));
    }

    public function test_crear_docente_vincula_la_cuenta_y_codigo_automatico(): void
    {
        $cuenta = $this->usuario(User::ROL_DOCENTE, ['email' => 'ana.lopez@example.com']);

        $this->crear(['codigo' => 'X-999', 'user_id' => $cuenta->id, 'email' => 'ignorado@example.com', 'password' => 'Ignorada123'])
            ->assertCreated()
            ->assertJsonPath('profesor.codigo', 'PROF-001')
            ->assertJsonPath('profesor.user.email', 'ana.lopez@example.com');

        $this->assertSame($cuenta->id, Profesor::firstOrFail()->user_id);
        $this->assertNull(User::where('email', 'ignorado@example.com')->first());

        $this->crear(['nombre' => 'Luis Pérez', 'dui' => $this->dui('01234567'), 'telefono' => '2222-3333'])
            ->assertCreated()->assertJsonPath('profesor.codigo', 'PROF-002');
    }

    public function test_cuentas_disponibles_son_docentes_sin_profesor(): void
    {
        $libre = $this->usuario(User::ROL_DOCENTE, ['email' => 'libre@example.com']);
        $usada = $this->usuario(User::ROL_DOCENTE, ['email' => 'usada@example.com']);
        $this->usuario(User::ROL_ENCARGADO, ['email' => 'encargado@example.com']);
        $this->crear(['user_id' => $usada->id])->assertCreated();

        $this->como($this->director)->getJson('/api/profesores/cuentas-disponibles')->assertOk()
            ->assertExactJson(['cuentas' => [['id' => $libre->id, 'email' => 'libre@example.com']]]);
        $this->como($this->usuario(User::ROL_DOCENTE))->getJson('/api/profesores/cuentas-disponibles')->assertForbidden();
    }

    public function test_codigo_continua_desde_el_mayor_existente(): void
    {
        $otro = $this->usuario(User::ROL_DOCENTE);
        Profesor::create([
            'nombre' => 'Heredado', 'dui' => $this->dui('09999999'), 'fecha_nacimiento' => '1980-01-01',
            'codigo' => 'PROF-041', 'telefono' => '6000-0000', 'direccion' => 'X', 'user_id' => $otro->id,
        ]);

        $this->crear()->assertCreated()->assertJsonPath('profesor.codigo', 'PROF-042');
    }

    public function test_validaciones_al_crear(): void
    {
        $this->crear(['user_id' => null])
            ->assertStatus(422)->assertJsonPath('errors.user_id.0', 'Seleccione la cuenta de usuario del profesor.');

        $this->crear(['user_id' => 99999])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $this->crear(['fecha_nacimiento' => now()->subYears(10)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_nacimiento']);

        $this->crear(['nombre' => str_repeat('a', 81)])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre']);

        $this->crear(['dui' => '12345678-0'])
            ->assertStatus(422)->assertJsonValidationErrors(['dui']);

        $this->assertSame(0, Profesor::count());
    }

    public function test_la_cuenta_debe_ser_docente_y_estar_libre(): void
    {
        $encargado = $this->usuario(User::ROL_ENCARGADO);

        $this->crear(['user_id' => $encargado->id])
            ->assertStatus(422)->assertJsonPath('errors.user_id.0', 'La cuenta seleccionada no tiene el rol DOCENTE.');
        $this->crear(['user_id' => $this->director->id])
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        $docente = $this->usuario(User::ROL_DOCENTE);
        $this->crear(['user_id' => $docente->id])->assertCreated();
        // Esa cuenta ya tiene profesor
        $this->crear(['user_id' => $docente->id, 'dui' => $this->dui('01234567'), 'telefono' => '2222-3333'])
            ->assertStatus(422)->assertJsonPath('errors.user_id.0', 'La cuenta seleccionada ya está vinculada con otro profesor.');

        $this->assertSame(1, Profesor::count());
    }

    public function test_editar_no_permite_cambiar_codigo_ni_dui_y_si_la_cuenta(): void
    {
        $id = $this->crear()->json('profesor.id');
        $profesor = Profesor::findOrFail($id);

        $base = $this->datos(['codigo' => 'PROF-001', 'dui' => $profesor->dui]);

        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['codigo' => 'PROF-999']))
            ->assertStatus(422)->assertJsonValidationErrors(['codigo']);

        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['dui' => $this->dui('01234567')]))
            ->assertStatus(422)->assertJsonValidationErrors(['dui']);

        // La cuenta se cambia solo por otra cuenta DOCENTE libre
        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['user_id' => $this->director->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);
        $nueva = $this->usuario(User::ROL_DOCENTE);
        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['user_id' => $nueva->id]))
            ->assertOk()->assertJsonPath('profesor.user_id', $nueva->id);

        // El mismo DUI con otro formato no es un cambio
        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, [
            'dui' => str_replace('-', '', $profesor->dui),
            'nombre' => 'Ana María López',
            'telefono' => '6111-2222',
        ]))->assertOk()
            ->assertJsonPath('profesor.codigo', 'PROF-001')
            ->assertJsonPath('profesor.nombre', 'Ana María López')
            ->assertJsonPath('profesor.user_id', $nueva->id);

        $profesor->refresh();
        $this->assertSame('6111-2222', $profesor->telefono);
    }

    public function test_buscar_por_nombre_codigo_o_dui(): void
    {
        $this->crear()->assertCreated();
        $this->crear([
            'nombre' => 'Luis Pérez', 'dui' => $this->dui('01234567'), 'telefono' => '2222-3333',
        ])->assertCreated();

        $buscar = fn ($t) => $this->como($this->director)->getJson('/api/profesores?buscar='.urlencode($t))->json('profesores');

        $this->assertCount(2, $buscar(''));
        $this->assertSame(['Luis Pérez'], array_column($buscar('pérez'), 'nombre'));
        $this->assertSame(['Luis Pérez'], array_column($buscar('PROF-002'), 'nombre'));
        $this->assertSame(['Ana López'], array_column($buscar(str_replace('-', '', $this->dui('04567890'))), 'nombre'));
        $this->assertArrayNotHasKey('password', $buscar('Ana')[0]['user']);
    }

    public function test_eliminar_borra_el_perfil_y_conserva_la_cuenta_salvo_con_asignaciones(): void
    {
        $id = $this->crear()->json('profesor.id');
        $userId = Profesor::findOrFail($id)->user_id;

        $this->como($this->director)->deleteJson("/api/profesores/{$id}")->assertOk();
        $this->assertNull(Profesor::find($id));
        $this->assertNotNull(User::find($userId));

        // La cuenta queda libre para vincularla de nuevo
        $id = $this->crear(['user_id' => $userId])->assertCreated()->json('profesor.id');
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado']);
        DB::table('secciones')->insert([
            'nombre' => 'A', 'grado_id' => $grado, 'profesor_id' => $id,
        ] + $this->columnasSeccion());

        $this->como($this->director)->deleteJson("/api/profesores/{$id}")->assertStatus(409);
        $this->assertNotNull(Profesor::find($id));
    }

    public function test_solo_el_director_administra_docentes(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE);

        $this->como($docente)->getJson('/api/profesores')->assertForbidden();
        $this->como($docente)->postJson('/api/profesores', $this->datos())->assertForbidden();
    }

    public function test_seeder_crea_perfil_del_docente_de_prueba_una_vez(): void
    {
        config(['app.seed_password' => 'ClaveSemilla123']);

        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $docente = User::where('email', 'docente@siga.test')->firstOrFail();
        $this->assertSame(1, Profesor::where('user_id', $docente->id)->count());
        $this->assertSame('PROF-001', $docente->profesor->codigo);
    }

    // Columnas obligatorias de secciones según el esquema actual
    private function columnasSeccion(): array
    {
        return ['capacidad' => 30, 'turno' => 'matutino'];
    }
}
