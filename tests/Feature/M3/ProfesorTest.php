<?php

namespace Tests\Feature\M3;

use App\Models\Profesor;
use App\Models\User;
use App\Notifications\RestablecerContrasena;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Feature\M1\DatosM1;
use Tests\TestCase;

/**
 * M3: registro de docentes con su cuenta, código PROF-### automático,
 * especialidad y fecha de contratación (D11), y datos no editables.
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
            'email' => 'ana.lopez@example.com',
            'fecha_nacimiento' => '1990-04-20',
            'telefono' => '7123-4567',
            'direccion' => 'Colonia Escalón, San Salvador',
            'especialidad' => 'Ciencias Naturales',
            'fecha_contratacion' => '2018-01-10',
        ], $cambios);
    }

    private function crear(array $cambios = [])
    {
        return $this->como($this->director)->postJson('/api/profesores', $this->datos($cambios));
    }

    public function test_crear_docente_crea_cuenta_docente_y_codigo_automatico(): void
    {
        Notification::fake();

        $respuesta = $this->crear(['codigo' => 'X-999', 'user_id' => $this->director->id]);

        $respuesta->assertCreated()
            ->assertJsonPath('profesor.codigo', 'PROF-001')
            ->assertJsonPath('profesor.especialidad', 'Ciencias Naturales')
            ->assertJsonPath('profesor.fecha_contratacion', '2018-01-10')
            ->assertJsonPath('profesor.user.email', 'ana.lopez@example.com');

        $user = User::where('email', 'ana.lopez@example.com')->firstOrFail();
        $this->assertTrue($user->hasRole(User::ROL_DOCENTE));
        $this->assertSame(User::ESTADO_PENDIENTE, $user->estado);
        $this->assertSame($user->id, Profesor::firstOrFail()->user_id);
        $this->assertSame('Ana López', $user->nombre);

        Notification::assertSentTo($user, RestablecerContrasena::class);

        $this->crear([
            'nombre' => 'Luis Pérez', 'dui' => $this->dui('01234567'),
            'email' => 'luis@example.com', 'telefono' => '2222-3333',
        ])->assertCreated()->assertJsonPath('profesor.codigo', 'PROF-002');
    }

    public function test_codigo_continua_desde_el_mayor_existente(): void
    {
        Notification::fake();
        $otro = $this->usuario(User::ROL_DOCENTE);
        Profesor::create([
            'nombre' => 'Heredado', 'dui' => $otro->dui, 'fecha_nacimiento' => '1980-01-01',
            'codigo' => 'PROF-041', 'telefono' => '6000-0000', 'direccion' => 'X', 'user_id' => $otro->id,
        ]);

        $this->crear()->assertCreated()->assertJsonPath('profesor.codigo', 'PROF-042');
    }

    public function test_validaciones_al_crear(): void
    {
        Notification::fake();

        $this->crear(['especialidad' => '', 'fecha_contratacion' => '', 'email' => ''])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['especialidad', 'fecha_contratacion', 'email']);

        $this->crear(['fecha_nacimiento' => now()->subYears(10)->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_nacimiento']);

        $this->crear(['fecha_contratacion' => now()->addDay()->toDateString()])
            ->assertStatus(422)->assertJsonValidationErrors(['fecha_contratacion']);

        $this->crear(['nombre' => str_repeat('a', 51)])
            ->assertStatus(422)->assertJsonValidationErrors(['nombre']);

        $this->crear(['dui' => '12345678-0'])
            ->assertStatus(422)->assertJsonValidationErrors(['dui']);

        // Nada quedó a medias
        $this->assertSame(0, Profesor::count());
        $this->assertSame(1, User::count());
    }

    public function test_correo_o_dui_de_otra_cuenta(): void
    {
        Notification::fake();
        $encargado = $this->usuario(User::ROL_ENCARGADO);

        $this->crear(['email' => $encargado->email])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->crear(['dui' => $encargado->dui])
            ->assertStatus(422)->assertJsonValidationErrors(['dui']);

        $this->assertSame(0, Profesor::count());
    }

    public function test_vincula_cuenta_docente_existente_sin_perfil(): void
    {
        Notification::fake();
        $docente = $this->usuario(User::ROL_DOCENTE, ['email' => 'previa@example.com']);

        $this->crear(['dui' => $docente->dui, 'email' => 'otro@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['email']);

        $this->crear(['dui' => $docente->dui, 'email' => 'previa@example.com'])
            ->assertCreated()->assertJsonPath('profesor.user_id', $docente->id);

        $this->assertSame(2, User::count());
        Notification::assertNothingSent();
    }

    public function test_si_falla_el_perfil_no_queda_cuenta_ni_correo(): void
    {
        Notification::fake();
        $this->crear()->assertCreated();

        // Mismo teléfono: el perfil no pasa la validación
        $this->crear(['nombre' => 'Otra', 'dui' => $this->dui('07654321'), 'email' => 'otra@example.com'])
            ->assertStatus(422)->assertJsonValidationErrors(['telefono']);

        $this->assertNull(User::where('email', 'otra@example.com')->first());
        Notification::assertCount(1);
    }

    public function test_editar_no_permite_cambiar_codigo_dui_ni_cuenta(): void
    {
        Notification::fake();
        $id = $this->crear()->json('profesor.id');
        $profesor = Profesor::findOrFail($id);

        $base = $this->datos(['codigo' => 'PROF-001', 'dui' => $profesor->dui]);

        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['codigo' => 'PROF-999']))
            ->assertStatus(422)->assertJsonValidationErrors(['codigo']);

        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['dui' => $this->dui('01234567')]))
            ->assertStatus(422)->assertJsonValidationErrors(['dui']);

        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, ['user_id' => $this->director->id]))
            ->assertStatus(422)->assertJsonValidationErrors(['user_id']);

        // El mismo DUI con otro formato no es un cambio
        $this->como($this->director)->putJson("/api/profesores/{$id}", array_merge($base, [
            'dui' => str_replace('-', '', $profesor->dui),
            'nombre' => 'Ana María López',
            'telefono' => '6111-2222',
            'especialidad' => 'Matemática',
        ]))->assertOk()
            ->assertJsonPath('profesor.codigo', 'PROF-001')
            ->assertJsonPath('profesor.especialidad', 'Matemática');

        $profesor->refresh();
        $this->assertSame('6111-2222', $profesor->telefono);
        $this->assertSame('Ana María López', $profesor->user->nombre);
    }

    public function test_buscar_por_nombre_codigo_o_dui(): void
    {
        Notification::fake();
        $this->crear()->assertCreated();
        $this->crear([
            'nombre' => 'Luis Pérez', 'dui' => $this->dui('01234567'),
            'email' => 'luis@example.com', 'telefono' => '2222-3333',
        ])->assertCreated();

        $buscar = fn ($t) => $this->como($this->director)->getJson('/api/profesores?buscar='.urlencode($t))->json('profesores');

        $this->assertCount(2, $buscar(''));
        $this->assertSame(['Luis Pérez'], array_column($buscar('pérez'), 'nombre'));
        $this->assertSame(['Luis Pérez'], array_column($buscar('PROF-002'), 'nombre'));
        $this->assertSame(['Ana López'], array_column($buscar(str_replace('-', '', $this->dui('04567890'))), 'nombre'));
        $this->assertArrayNotHasKey('password', $buscar('Ana')[0]['user']);
    }

    public function test_eliminar_borra_perfil_y_cuenta_salvo_con_asignaciones(): void
    {
        Notification::fake();
        $id = $this->crear()->json('profesor.id');
        $userId = Profesor::findOrFail($id)->user_id;

        $this->como($this->director)->deleteJson("/api/profesores/{$id}")->assertOk();
        $this->assertNull(Profesor::find($id));
        $this->assertNull(User::find($userId));

        $id = $this->crear()->json('profesor.id');
        $grado = DB::table('grados')->insertGetId(['nombre' => 'Primer grado', 'orden' => 1]);
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
        return ['capacidad' => 30, 'turno' => 'matutino', 'anio' => (int) now()->year];
    }
}
