<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Cuentas de acceso (tabla users: email, password, rol_id).
 * Solo maneja la cuenta; los perfiles (profesores, padres, encargados) se
 * crean en sus propios servicios.
 */
class UsuarioService
{
    // ---------------------------------------------------------------
    // Creación de cuentas
    // ---------------------------------------------------------------

    /** Registrarse (diagrama): cuenta pública con rol fijo ENCARGADO. */
    public function registrarEncargado(array $datos): User
    {
        return $this->crearCuenta($datos, User::ROL_ENCARGADO);
    }

    /** Cuenta con correo, contraseña y un único rol. */
    public function crearCuenta(array $datos, string $rol): User
    {
        if (! in_array($rol, User::ROLES, true)) {
            throw new \InvalidArgumentException('Rol no permitido.');
        }

        $user = new User([
            'email' => $datos['email'],
            'password' => Hash::make($datos['password']),
        ]);
        $user->asignarRol($rol);
        $user->save();

        return $user;
    }

    // ---------------------------------------------------------------
    // Gestión por el director
    // ---------------------------------------------------------------

    public function listar(?string $buscar = null, ?string $rol = null)
    {
        $query = User::with('rolAsignado')->orderBy('id', 'desc');

        if ($buscar) {
            $query->where('email', 'like', '%'.$buscar.'%');
        }

        if ($rol) {
            $query->conRol($rol);
        }

        return $query->get()->map(fn (User $u) => $u->datosSesion());
    }

    /** Correo y rol (asignarRol). */
    public function actualizar(User $user, array $datos): User
    {
        if (isset($datos['rol']) && $datos['rol'] !== $user->rol()) {
            $this->validarCambioDeRol($user, $datos['rol']);
        }

        DB::transaction(function () use ($user, $datos) {
            $user->email = $datos['email'];

            if (isset($datos['rol'])) {
                $user->asignarRol($datos['rol']);
            }

            $user->save();
        });

        return $user->refresh();
    }

    public function eliminar(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw new \DomainException('No puede eliminar su propia cuenta.');
        }

        if ($this->esUltimoDirector($user)) {
            throw new \DomainException('No se puede eliminar al último director.');
        }

        if ($this->tieneRegistrosAsociados($user)) {
            throw new \DomainException('La cuenta tiene información asociada y no puede eliminarse.');
        }

        $user->delete();
    }

    public function tieneRegistrosAsociados(User $user): bool
    {
        return $user->profesor()->exists()
            || $user->padre()->exists()
            || $user->encargado()->exists()
            || $user->estudiantes()->exists();
    }

    public function esUltimoDirector(User $user): bool
    {
        if (! $user->hasRole(User::ROL_DIRECTOR)) {
            return false;
        }

        return User::conRol(User::ROL_DIRECTOR)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    /**
     * Un cambio de rol no puede dejar sin director al sistema ni contradecir
     * un perfil ya vinculado a la cuenta.
     */
    public function validarCambioDeRol(User $user, string $nuevoRol): void
    {
        if (! in_array($nuevoRol, User::ROLES, true)) {
            throw new \DomainException('Rol no permitido.');
        }

        if ($nuevoRol !== User::ROL_DIRECTOR && $this->esUltimoDirector($user)) {
            throw new \DomainException('No se puede quitar el rol al último director.');
        }

        if ($nuevoRol !== User::ROL_DOCENTE && $user->profesor()->exists()) {
            throw new \DomainException('La cuenta está vinculada a un perfil de docente; su rol debe ser DOCENTE.');
        }

        if ($nuevoRol !== User::ROL_ENCARGADO && ($user->padre()->exists() || $user->encargado()->exists() || $user->estudiantes()->exists())) {
            throw new \DomainException('La cuenta tiene estudiantes o datos de encargado vinculados; su rol debe ser ENCARGADO.');
        }
    }

    /** El perfil (docente, padre o encargado) vinculado a una cuenta exige el rol correspondiente. */
    public function verificarRol(User $user, string $rolEsperado): void
    {
        if (! $user->hasRole($rolEsperado)) {
            throw new \DomainException("La cuenta seleccionada no tiene el rol {$rolEsperado}.");
        }
    }
}
