<?php

namespace Tests\Feature\M1;

use App\Models\User;
use App\Notifications\RestablecerContrasena;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GestionUsuariosTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $director;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->director = $this->usuario(User::ROL_DIRECTOR, ['email' => 'director@siga.test']);
    }

    // 13. Solo el DIRECTOR accede a la gestión de usuarios
    public function test_solo_el_director_gestiona_usuarios(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE);
        $encargado = $this->usuario(User::ROL_ENCARGADO);

        $this->getJson('/api/usuarios')->assertStatus(401);
        $this->como($docente)->getJson('/api/usuarios')->assertStatus(403)->assertJsonStructure(['message']);
        $this->como($encargado)->postJson('/api/usuarios', [])->assertStatus(403);
        $this->como($encargado)->patchJson("/api/usuarios/{$this->director->id}/estado", ['estado' => 'inactivo'])->assertStatus(403);
        $this->como($docente)->putJson('/api/matriculas/1/estado', ['estado' => 'aprobado'])->assertStatus(403);
        $this->como($encargado)->putJson('/api/matriculas/1/estado', ['estado' => 'aprobado'])->assertStatus(403);

        $this->como($this->director)->getJson('/api/usuarios')->assertOk()->assertJsonCount(3, 'usuarios');
    }

    public function test_listado_y_busqueda_sin_contrasenas(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE, ['nombre' => 'Carlos Pérez', 'dui' => '00000001-8']);
        $this->usuario(User::ROL_ENCARGADO, ['nombre' => 'Ana Ruiz']);

        $this->como($this->director)->getJson('/api/usuarios?buscar=000000018')
            ->assertOk()->assertJsonCount(1, 'usuarios')->assertJsonPath('usuarios.0.id', $docente->id);

        $this->como($this->director)->getJson('/api/usuarios?buscar=carlos')->assertJsonCount(1, 'usuarios');
        $this->como($this->director)->getJson('/api/usuarios?rol=ENCARGADO')->assertJsonCount(1, 'usuarios');
        $this->como($this->director)->getJson('/api/usuarios?rol=CLIENTE')->assertStatus(422);

        $respuesta = $this->como($this->director)->getJson('/api/usuarios');
        $this->assertStringNotContainsString('password', $respuesta->getContent());
        $this->assertStringNotContainsString('$2y$', $respuesta->getContent());

        $this->como($this->director)->getJson("/api/usuarios/{$docente->id}")
            ->assertOk()->assertJsonPath('usuario.rol', 'DOCENTE')->assertJsonMissingPath('usuario.password');
        $this->como($this->director)->getJson('/api/usuarios/9999')->assertNotFound();
    }

    public function test_director_crea_cuenta_con_enlace_para_definir_contrasena(): void
    {
        Notification::fake();

        $this->como($this->director)->postJson('/api/usuarios', [
            'nombre' => 'Docente Nuevo', 'dui' => '000000018', 'email' => 'Nuevo@siga.test',
            'rol' => 'DOCENTE', 'password' => 'Intento123', 'estado' => 'activo',
        ])->assertCreated()
            ->assertJsonPath('usuario.estado', 'pendiente')
            ->assertJsonPath('usuario.rol', 'DOCENTE')
            ->assertJsonPath('usuario.dui', '00000001-8');

        $nuevo = User::where('email', 'nuevo@siga.test')->firstOrFail();
        $this->assertFalse(\Hash::check('Intento123', $nuevo->password));

        Notification::assertSentTo($nuevo, RestablecerContrasena::class, fn ($n) => $n->inicial && str_contains($n->url, 'inicial=1'));

        // Rol no permitido y DUI duplicado en otro formato
        $this->como($this->director)->postJson('/api/usuarios', ['nombre' => 'X', 'dui' => '00000002-6', 'email' => 'x@siga.test', 'rol' => 'ADMIN'])
            ->assertStatus(422)->assertJsonValidationErrors('rol');
        $this->como($this->director)->postJson('/api/usuarios', ['nombre' => 'X', 'dui' => '00000001-8', 'email' => 'x@siga.test', 'rol' => 'DOCENTE'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        // La cuenta pendiente no inicia sesión ni puede activarse sin confirmar su correo
        $this->como($this->director)->patchJson("/api/usuarios/{$nuevo->id}/estado", ['estado' => 'activo'])->assertStatus(409);
        $this->como($this->director)->postJson("/api/usuarios/{$nuevo->id}/reenviar-enlace")->assertOk();
    }

    public function test_edicion_de_datos_y_rol(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO, ['dui' => '00000002-6']);

        $this->como($this->director)->putJson("/api/usuarios/{$user->id}", ['nombre' => 'N', 'email' => $user->email, 'rol' => 'DOCENTE', 'dui' => '00016297-5'])
            ->assertStatus(422)->assertJsonValidationErrors('dui');

        $this->como($this->director)->putJson("/api/usuarios/{$user->id}", ['nombre' => 'Nombre Editado', 'email' => 'editado@siga.test', 'rol' => 'DOCENTE'])
            ->assertOk()->assertJsonPath('usuario.rol', 'DOCENTE')->assertJsonPath('usuario.dui', '00000002-6');

        $this->assertSame(['DOCENTE'], $user->fresh()->getRoleNames()->all());

        $this->como($this->director)->putJson("/api/usuarios/{$user->id}", ['nombre' => 'N', 'email' => $this->director->email, 'rol' => 'DOCENTE'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // 14. Último director activo y autoprotección
    public function test_proteccion_del_ultimo_director(): void
    {
        $this->como($this->director)->patchJson("/api/usuarios/{$this->director->id}/estado", ['estado' => 'inactivo'])->assertStatus(409);
        $this->como($this->director)->putJson("/api/usuarios/{$this->director->id}", ['nombre' => 'D', 'email' => $this->director->email, 'rol' => 'DOCENTE'])->assertStatus(409);
        $this->como($this->director)->deleteJson("/api/usuarios/{$this->director->id}")->assertStatus(409);
        $this->assertSame(User::ESTADO_ACTIVO, $this->director->fresh()->estado);
        $this->assertTrue($this->director->fresh()->hasRole('DIRECTOR'));

        // Con un segundo director, uno puede desactivar al otro
        $otro = $this->usuario(User::ROL_DIRECTOR);
        $this->como($this->director)->patchJson("/api/usuarios/{$otro->id}/estado", ['estado' => 'inactivo'])
            ->assertOk()->assertJsonPath('usuario.estado', 'inactivo');
        $this->como($this->director)->patchJson("/api/usuarios/{$otro->id}/estado", ['estado' => 'activo'])->assertOk();
    }

    public function test_no_se_elimina_una_cuenta_con_datos_asociados(): void
    {
        $catalogos = $this->catalogosBasicos();
        $encargado = $this->usuario(User::ROL_ENCARGADO);
        $this->estudianteDe($encargado, $catalogos['distrito']);
        $sinDatos = $this->usuario(User::ROL_DOCENTE);

        $this->como($this->director)->deleteJson("/api/usuarios/{$encargado->id}")->assertStatus(409);
        $this->assertDatabaseHas('users', ['id' => $encargado->id]);

        $this->como($this->director)->deleteJson("/api/usuarios/{$sinDatos->id}")->assertOk();
        $this->assertDatabaseMissing('users', ['id' => $sinDatos->id]);
        $this->assertSame(0, DB::table('model_has_roles')->where('model_id', $sinDatos->id)->count());
    }
}
