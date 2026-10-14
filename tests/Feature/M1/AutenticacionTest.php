<?php

namespace Tests\Feature\M1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AutenticacionTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
    }

    private function datosRegistro(array $cambios = []): array
    {
        return array_merge([
            'email' => 'Maria@Correo.com ',
            'password' => 'Segura123',
            'password_confirmation' => 'Segura123',
        ], $cambios);
    }

    // 1. Registrarse: cuenta ENCARGADO lista para iniciar sesión; el rol del cliente se ignora
    public function test_registro_crea_cuenta_encargado(): void
    {
        $respuesta = $this->postJson('/api/auth/register', $this->datosRegistro(['rol' => 'DIRECTOR', 'role' => 'DIRECTOR', 'rol_id' => 1]));

        $respuesta->assertCreated()->assertJsonMissingPath('access_token');

        $user = User::where('email', 'maria@correo.com')->firstOrFail();
        $this->assertSame('ENCARGADO', $user->rol());
        $this->assertTrue(Hash::check('Segura123', $user->password));

        $this->postJson('/api/auth/login', ['email' => 'maria@correo.com', 'password' => 'Segura123'])->assertOk();
    }

    // 2. Correo duplicado y confirmación de contraseña
    public function test_registro_rechaza_duplicados(): void
    {
        $this->postJson('/api/auth/register', $this->datosRegistro())->assertCreated();

        $this->postJson('/api/auth/register', $this->datosRegistro(['email' => 'MARIA@correo.com']))
            ->assertStatus(422)->assertJsonValidationErrors('email');

        $this->postJson('/api/auth/register', $this->datosRegistro(['email' => 'x@correo.com', 'password_confirmation' => 'Otra1234']))
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->assertSame(1, User::count());
    }

    // 3. Inicio de sesión
    public function test_login(): void
    {
        $activo = $this->usuario(User::ROL_ENCARGADO, ['email' => 'activo@siga.test']);

        $this->postJson('/api/auth/login', ['email' => 'activo@siga.test', 'password' => 'incorrecta'])
            ->assertStatus(401)->assertJson(['message' => 'Credenciales inválidas']);

        $this->postJson('/api/auth/login', ['email' => 'noexiste@siga.test', 'password' => 'password'])
            ->assertStatus(401);

        $this->postJson('/api/auth/login', ['email' => ''])
            ->assertStatus(422)->assertJsonStructure(['message', 'errors' => ['email', 'password']]);

        $respuesta = $this->postJson('/api/auth/login', ['email' => ' ACTIVO@siga.test', 'password' => 'password']);

        $respuesta->assertOk()
            ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email', 'rol']])
            ->assertJsonPath('user.rol', 'ENCARGADO')
            ->assertJsonPath('expires_in', 3600)
            ->assertJsonMissingPath('user.password');

        $this->assertStringNotContainsString($activo->password, $respuesta->getContent());
    }

    // 4. me, refresh y logout con un token real
    public function test_me_refresh_y_logout(): void
    {
        $user = $this->usuario(User::ROL_DOCENTE);
        $token = $this->tokenDe($user);

        $this->conToken($token)->getJson('/api/auth/me')
            ->assertOk()->assertJsonPath('user.email', $user->email)->assertJsonPath('user.rol', 'DOCENTE')
            ->assertJsonMissingPath('user.password');

        $nuevo = $this->conToken($token)->postJson('/api/auth/refresh')->assertOk()->json('access_token');
        $this->assertNotSame($token, $nuevo);

        // El token anterior queda invalidado
        $this->conToken($token)->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertOk();

        $this->conToken($nuevo)->postJson('/api/auth/logout')->assertOk();
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertStatus(401);
    }

    // 5. 401 en JSON sin token o con token alterado
    public function test_respuestas_401_en_json(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401)->assertJsonStructure(['message']);
        $this->get('/api/auth/me')->assertStatus(401)->assertJsonStructure(['message']);
        $this->conToken('token.falso.123')->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken('x')->postJson('/api/auth/refresh')->assertStatus(401);
    }

    // 10. Cambio de contraseña autenticado
    public function test_cambio_de_contrasena(): void
    {
        $user = $this->usuario(User::ROL_ENCARGADO);

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'mala', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'])
            ->assertStatus(422)->assertJsonValidationErrors('actual');

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'password', 'password' => 'corta', 'password_confirmation' => 'corta'])
            ->assertStatus(422)->assertJsonValidationErrors('password');

        $this->como($user)->putJson('/api/auth/contrasena', ['actual' => 'password', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234'])
            ->assertOk();

        $this->assertTrue(Hash::check('Nueva1234', $user->fresh()->password));
    }

    // 11. Límite de intentos en endpoints públicos
    public function test_limite_de_intentos_de_login(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'x@siga.test', 'password' => 'mala'])->assertStatus(401);
        }

        $this->postJson('/api/auth/login', ['email' => 'x@siga.test', 'password' => 'mala'])
            ->assertStatus(429)->assertJsonStructure(['message']);
    }

    // 12. CORS: el preflight del frontend se acepta con Authorization
    public function test_preflight_cors_del_frontend(): void
    {
        $this->call('OPTIONS', '/api/auth/me', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:5173',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'authorization,content-type',
        ])->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'http://localhost:5173');
    }
}
