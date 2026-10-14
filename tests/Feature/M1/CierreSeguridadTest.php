<?php

namespace Tests\Feature\M1;

use App\Models\Padre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Cierre de M1: propiedad de los padres y revocación de sesiones al cambiar
 * o restablecer la contraseña.
 */
class CierreSeguridadTest extends TestCase
{
    use DatosM1, RefreshDatabase;

    private User $a;
    private User $b;
    private array $catalogos;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarRoles();
        $this->catalogos = $this->catalogosBasicos();
        $this->a = $this->usuario(User::ROL_ENCARGADO);
        $this->b = $this->usuario(User::ROL_ENCARGADO);
    }

    private function vincular(User $cuenta, int $estudianteId, int $padreId)
    {
        return $this->como($cuenta)->postJson('/api/parientes', [
            'estudiante_id' => $estudianteId, 'padre_id' => $padreId, 'parentesco' => 'Madre',
        ]);
    }

    // El encargado registra al padre ya vinculado a su estudiante (tabla parientes); otro encargado no lo ve
    public function test_padre_se_registra_vinculado_y_otro_encargado_no_lo_usa(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);

        // Sin estudiante no se registra (no quedan padres sin dueño)
        $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre de A', 'dui' => '00000003-4', 'es_encargado' => false])
            ->assertStatus(422)->assertJsonValidationErrors('estudiante_id');

        // Con un estudiante ajeno responde como no encontrado
        $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre de A', 'dui' => '00000003-4', 'es_encargado' => false,
            'estudiante_id' => $deB->id, 'parentesco' => 'Madre'])->assertNotFound();
        $this->assertSame(0, Padre::count());

        $madre = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre de A', 'dui' => '00000003-4', 'es_encargado' => false,
            'estudiante_id' => $deA->id, 'parentesco' => 'Madre'])->assertCreated()->json('padre.id');
        $this->assertSame(1, DB::table('parientes')->where('padre_id', $madre)->where('estudiante_id', $deA->id)->count());

        // B no lo encuentra ni lo vincula, aunque conozca el id
        $this->como($this->b)->postJson('/api/padres/buscar-dui', ['dui' => '00000003-4'])->assertNotFound();
        $this->vincular($this->b, $deB->id, $madre)->assertNotFound();

        // A sí lo encuentra y lo vincula a otro de sus estudiantes
        $this->como($this->a)->postJson('/api/padres/buscar-dui', ['dui' => '000000034'])->assertOk()->assertJsonPath('padre.id', $madre);
        $otroDeA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->vincular($this->a, $otroDeA->id, $madre)->assertCreated();

        $this->como($this->b)->getJson("/api/matriculas/estudiantes/{$deA->id}/padres")->assertNotFound();
        $this->como($this->a)->getJson("/api/matriculas/estudiantes/{$deA->id}/padres")->assertOk()->assertJsonPath('padres.0.id', $madre);
    }

    // B no puede registrar de nuevo a la misma persona para quedarse con el registro
    public function test_otro_encargado_no_se_apropia_registrando_el_mismo_dui(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $madre = $this->como($this->a)->postJson('/api/padres', ['nombre' => 'Madre', 'dui' => '00000003-4', 'es_encargado' => false,
            'estudiante_id' => $deA->id, 'parentesco' => 'Madre'])->assertCreated()->json('padre.id');

        $this->como($this->b)->postJson('/api/padres', ['nombre' => 'Otra', 'dui' => '000000034', 'es_encargado' => false,
            'estudiante_id' => $deB->id, 'parentesco' => 'Madre'])->assertStatus(422)->assertJsonValidationErrors('dui');

        $this->assertSame('Madre', Padre::find($madre)->nombre);
    }

    // Padres sin cuenta ni estudiantes (heredados o registrados por el director): se deniega a todo encargado
    public function test_padre_sin_estudiantes_solo_lo_vincula_el_director(): void
    {
        $deA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $deB = $this->estudianteDe($this->b, $this->catalogos['distrito']);
        $director = $this->usuario(User::ROL_DIRECTOR);

        // El director sí puede registrar un padre sin vincular
        $sinVincular = $this->como($director)->postJson('/api/padres', ['nombre' => 'Padre', 'dui' => '00016297-5', 'es_encargado' => false])
            ->assertCreated()->json('padre.id');

        foreach ([[$this->a, $deA], [$this->b, $deB]] as [$cuenta, $estudiante]) {
            $this->como($cuenta)->postJson('/api/padres/buscar-dui', ['dui' => '00016297-5'])->assertNotFound();
            $this->vincular($cuenta, $estudiante->id, $sinVincular)->assertNotFound();
        }

        $this->como($director)->postJson('/api/padres/buscar-dui', ['dui' => '00016297-5'])->assertOk();
        $this->vincular($director, $deA->id, $sinVincular)->assertCreated();

        // Ya vinculado al estudiante de A: A puede usarlo; B sigue sin acceso
        $otroDeA = $this->estudianteDe($this->a, $this->catalogos['distrito']);
        $this->vincular($this->a, $otroDeA->id, $sinVincular)->assertCreated();
        $this->vincular($this->b, $deB->id, $sinVincular)->assertNotFound();
    }

    // Cambio de contraseña: las demás sesiones dejan de servir; la actual recibe un token nuevo
    public function test_cambio_de_contrasena_revoca_las_sesiones_anteriores(): void
    {
        $credenciales = ['email' => $this->a->email, 'password' => 'password'];
        $sesion1 = $this->postJson('/api/auth/login', $credenciales)->assertOk()->json('access_token');
        $sesion2 = $this->postJson('/api/auth/login', $credenciales)->assertOk()->json('access_token');

        $nuevo = $this->conToken($sesion1)->putJson('/api/auth/contrasena', [
            'actual' => 'password', 'password' => 'Nueva1234', 'password_confirmation' => 'Nueva1234',
        ])->assertOk()->assertJsonPath('message', 'Contraseña actualizada correctamente')->json('access_token');

        $this->assertNotEmpty($nuevo);
        $this->conToken($nuevo)->getJson('/api/auth/me')->assertOk();

        $this->conToken($sesion1)->getJson('/api/auth/me')->assertStatus(401);
        $this->conToken($sesion2)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');
        $this->conToken($sesion2)->postJson('/api/auth/refresh')->assertStatus(401);
        $this->conToken($sesion2)->getJson('/api/matriculas')->assertStatus(401);

        $this->postJson('/api/auth/login', $credenciales)->assertStatus(401);
        $this->postJson('/api/auth/login', ['email' => $this->a->email, 'password' => 'Nueva1234'])->assertOk();
    }

    // Un token sin huella válida (por ejemplo, emitido antes de esta corrección) se rechaza
    public function test_token_sin_huella_vigente_se_rechaza(): void
    {
        // El token normal lleva la huella, que no expone el hash de la contraseña
        $payload = json_decode(base64_decode(strtr(explode('.', $this->tokenDe($this->a))[1], '-_', '+/')), true);
        $this->assertSame($this->a->huellaContrasena(), $payload[User::CLAIM_CONTRASENA]);
        $this->assertStringNotContainsString($payload[User::CLAIM_CONTRASENA], $this->a->password);

        $sinHuella = JWTAuth::claims([User::CLAIM_CONTRASENA => null])->fromUser($this->a);
        $ajena = JWTAuth::claims([User::CLAIM_CONTRASENA => str_repeat('0', 32)])->fromUser($this->a);

        $this->conToken($sinHuella)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');
        $this->conToken($ajena)->getJson('/api/auth/me')->assertStatus(401)->assertJsonPath('codigo', 'sesion_revocada');

    }

}
