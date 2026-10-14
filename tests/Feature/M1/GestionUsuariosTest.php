<?php

namespace Tests\Feature\M1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->como($encargado)->deleteJson("/api/usuarios/{$this->director->id}")->assertStatus(403);
        $this->como($docente)->putJson('/api/matriculas/1/estado', ['estado' => 'aprobado'])->assertStatus(403);
        $this->como($encargado)->putJson('/api/matriculas/1/estado', ['estado' => 'aprobado'])->assertStatus(403);

        $this->como($this->director)->getJson('/api/usuarios')->assertOk()->assertJsonCount(3, 'usuarios');
    }

    public function test_listado_y_busqueda_sin_contrasenas(): void
    {
        $docente = $this->usuario(User::ROL_DOCENTE, ['email' => 'carlos@siga.test']);
        $this->usuario(User::ROL_ENCARGADO);

        $this->como($this->director)->getJson('/api/usuarios?buscar=carlos')
            ->assertOk()->assertJsonCount(1, 'usuarios')->assertJsonPath('usuarios.0.id', $docente->id);
        $this->como($this->director)->getJson('/api/usuarios?rol=ENCARGADO')->assertJsonCount(1, 'usuarios');
        $this->como($this->director)->getJson('/api/usuarios?rol=CLIENTE')->assertStatus(422);

        $respuesta = $this->como($this->director)->getJson('/api/usuarios');
        $this->assertStringNotContainsString('password', $respuesta->getContent());
        $this->assertStringNotContainsString('$2y$', $respuesta->getContent());

        $this->como($this->director)->getJson("/api/usuarios/{$docente->id}")
            ->assertOk()->assertJsonPath('usuario.rol', 'DOCENTE')->assertJsonMissingPath('usuario.password');
        $this->como($this->director)->getJson('/api/usuarios/9999')->assertNotFound();
    }

    public function test_director_crea_cuenta_con_contrasena_inicial_y_rol(): void
    {
        $this->como($this->director)->postJson('/api/usuarios', [
            'email' => 'Nuevo@siga.test', 'rol' => 'DOCENTE', 'password' => 'Inicial123',
        ])->assertCreated()
            ->assertJsonPath('usuario.rol', 'DOCENTE')
            ->assertJsonPath('usuario.email', 'nuevo@siga.test');

        $this->postJson('/api/auth/login', ['email' => 'nuevo@siga.test', 'password' => 'Inicial123'])->assertOk();

        // Rol no permitido, correo repetido y contraseña débil
        $this->como($this->director)->postJson('/api/usuarios', ['email' => 'x@siga.test', 'password' => 'Inicial123', 'rol' => 'ADMIN'])
            ->assertStatus(422)->assertJsonValidationErrors('rol');
        $this->como($this->director)->postJson('/api/usuarios', ['email' => 'NUEVO@siga.test', 'password' => 'Inicial123', 'rol' => 'DOCENTE'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
        $this->como($this->director)->postJson('/api/usuarios', ['email' => 'y@siga.test', 'password' => 'corta', 'rol' => 'DOCENTE'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_edicion_de_correo_y_rol(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO);

        $this->como($this->director)->putJson("/api/usuarios/{$user->id}", ['email' => 'editado@siga.test', 'rol' => 'DOCENTE'])
            ->assertOk()->assertJsonPath('usuario.rol', 'DOCENTE')->assertJsonPath('usuario.email', 'editado@siga.test');

        $this->assertSame('DOCENTE', $user->fresh()->rol());

        $this->como($this->director)->putJson("/api/usuarios/{$user->id}", ['email' => $this->director->email, 'rol' => 'DOCENTE'])
            ->assertStatus(422)->assertJsonValidationErrors('email');
    }

    // 14. Último director y autoprotección
    public function test_proteccion_del_ultimo_director(): void
    {
        $this->como($this->director)->putJson("/api/usuarios/{$this->director->id}", ['email' => $this->director->email, 'rol' => 'DOCENTE'])->assertStatus(409);
        $this->como($this->director)->deleteJson("/api/usuarios/{$this->director->id}")->assertStatus(409);
        $this->assertTrue($this->director->fresh()->hasRole('DIRECTOR'));

        // Con un segundo director, uno puede eliminar al otro
        $otro = $this->usuario(User::ROL_DIRECTOR);
        $this->como($this->director)->deleteJson("/api/usuarios/{$otro->id}")->assertOk();
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
    }
}
