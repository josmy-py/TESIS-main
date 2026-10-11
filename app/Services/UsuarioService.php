<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\ActivarCuenta;
use App\Notifications\RestablecerContrasena;
use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Cuentas de acceso (tabla users + rol Spatie).
 * Solo maneja la identidad de acceso; los perfiles (profesores, padres,
 * encargados) se crean en sus propios servicios. M3 debe usar crearCuenta()
 * para la cuenta del docente y luego crear su perfil.
 */
class UsuarioService
{
    // ---------------------------------------------------------------
    // Creación de cuentas
    // ---------------------------------------------------------------

    /**
     * Registro público del encargado: rol fijo ENCARGADO, estado pendiente
     * hasta que use el enlace de activación.
     */
    public function registrarEncargado(array $datos): User
    {
        $user = DB::transaction(function () use ($datos) {
            $user = new User([
                'nombre' => $datos['nombre'],
                'dui' => DuiFormato::normalizar($datos['dui']),
                'email' => $datos['email'],
                'password' => Hash::make($datos['password']),
            ]);
            $user->estado = User::ESTADO_PENDIENTE;
            $user->save();

            $user->syncRoles([User::ROL_ENCARGADO]);

            return $user;
        });

        $this->enviarActivacion($user);

        return $user;
    }

    /**
     * Cuenta creada por el director (cualquier rol permitido). Nace pendiente
     * con una contraseña aleatoria que nadie conoce; la persona define la suya
     * con el enlace que recibe por correo, y al hacerlo la cuenta se activa.
     */
    public function crearCuenta(array $datos, string $rol): User
    {
        if (! in_array($rol, User::ROLES, true)) {
            throw new \InvalidArgumentException('Rol no permitido.');
        }

        $user = DB::transaction(function () use ($datos, $rol) {
            $user = new User([
                'nombre' => $datos['nombre'],
                'dui' => DuiFormato::normalizar($datos['dui']),
                'email' => $datos['email'],
                'password' => Hash::make(Str::password(40)),
            ]);
            $user->estado = User::ESTADO_PENDIENTE;
            $user->save();

            $user->syncRoles([$rol]);

            return $user;
        });

        $this->enviarEnlaceContrasena($user, true);

        return $user;
    }

    // ---------------------------------------------------------------
    // Activación y contraseñas
    // ---------------------------------------------------------------

    public function minutosActivacion(): int
    {
        return (int) config('auth.verification.expire', 60);
    }

    /**
     * URL firmada y temporal del backend que activa la cuenta. El correo
     * lleva al frontend, que llama a esta URL.
     */
    public function urlActivacion(User $user): string
    {
        return URL::temporarySignedRoute(
            'auth.activar',
            now()->addMinutes($this->minutosActivacion()),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function enviarActivacion(User $user): void
    {
        $enlace = rtrim(config('app.frontend_url'), '/').'/activar?enlace='.urlencode($this->urlActivacion($user));

        $user->notify(new ActivarCuenta($enlace, $this->minutosActivacion()));
    }

    public function enviarEnlaceContrasena(User $user, bool $inicial = false): void
    {
        $token = Password::broker()->createToken($user);

        $user->notify(new RestablecerContrasena(
            $this->urlContrasena($user, $token, $inicial),
            (int) config('auth.passwords.users.expire', 60),
            $inicial
        ));
    }

    public function urlContrasena(User $user, string $token, bool $inicial = false): string
    {
        $query = http_build_query(array_filter([
            'token' => $token,
            'email' => $user->email,
            'inicial' => $inicial ? 1 : null,
        ]));

        return rtrim(config('app.frontend_url'), '/').'/restablecer-contrasena?'.$query;
    }

    /**
     * Devuelve 'activada', 'ya_activa' o 'inactiva'.
     */
    public function activar(User $user): string
    {
        if ($user->estado === User::ESTADO_INACTIVO) {
            return 'inactiva';
        }

        if ($user->estado === User::ESTADO_ACTIVO) {
            return 'ya_activa';
        }

        $user->estado = User::ESTADO_ACTIVO;
        $user->email_verified_at = now();
        $user->save();

        return 'activada';
    }

    /**
     * Tras restablecer la contraseña con el enlace del correo: la persona
     * demostró que controla el correo, así que una cuenta pendiente se activa.
     * Una cuenta desactivada por el director sigue inactiva.
     */
    public function despuesDeRestablecer(User $user): void
    {
        if ($user->estado === User::ESTADO_PENDIENTE) {
            $user->estado = User::ESTADO_ACTIVO;
            $user->email_verified_at = $user->email_verified_at ?? now();
            $user->save();
        }
    }

    // ---------------------------------------------------------------
    // Gestión por el director
    // ---------------------------------------------------------------

    public function listar(?string $buscar = null, ?string $rol = null, ?string $estado = null)
    {
        $query = User::with('roles')->orderBy('id', 'desc');

        if ($buscar) {
            $termino = '%'.$buscar.'%';
            $duiNormalizado = DuiFormato::normalizar($buscar);

            $query->where(function ($q) use ($termino, $duiNormalizado) {
                $q->where('nombre', 'like', $termino)
                    ->orWhere('email', 'like', $termino)
                    ->orWhere('dui', 'like', $termino)
                    ->orWhere('dui', $duiNormalizado);
            });
        }

        if ($rol) {
            $query->role($rol);
        }

        if ($estado) {
            $query->where('estado', $estado);
        }

        return $query->get()->map(fn (User $u) => $u->datosSesion());
    }

    /**
     * Nombre, correo y rol. El DUI no se modifica desde la gestión.
     */
    public function actualizar(User $user, array $datos): User
    {
        if (isset($datos['rol']) && $datos['rol'] !== $user->rol()) {
            $this->validarCambioDeRol($user, $datos['rol']);
        }

        DB::transaction(function () use ($user, $datos) {
            $user->nombre = $datos['nombre'];

            // Solo se completa un DUI que falta; nunca se reemplaza uno existente
            if ($user->dui === null && ! empty($datos['dui'])) {
                $user->dui = $datos['dui'];
            }

            if ($datos['email'] !== $user->email) {
                $user->email = $datos['email'];
            }

            $user->save();

            if (isset($datos['rol'])) {
                $user->syncRoles([$datos['rol']]);
            }
        });

        return $user->refresh();
    }

    public function cambiarEstado(User $user, string $estado, User $actor): User
    {
        if ($user->id === $actor->id) {
            throw new \DomainException('No puede cambiar el estado de su propia cuenta.');
        }

        if ($estado === User::ESTADO_INACTIVO && $this->esUltimoDirectorActivo($user)) {
            throw new \DomainException('No se puede desactivar al último director activo.');
        }

        if ($estado === User::ESTADO_ACTIVO && $user->email_verified_at === null) {
            throw new \DomainException('La cuenta aún no ha confirmado su correo. Reenvíe el enlace para que defina su contraseña.');
        }

        $user->estado = $estado;
        $user->save();

        return $user;
    }

    public function eliminar(User $user, User $actor): void
    {
        if ($user->id === $actor->id) {
            throw new \DomainException('No puede eliminar su propia cuenta.');
        }

        if ($this->esUltimoDirectorActivo($user)) {
            throw new \DomainException('No se puede eliminar al último director activo.');
        }

        if ($this->tieneRegistrosAsociados($user)) {
            throw new \DomainException('La cuenta tiene información asociada y no puede eliminarse. Desactívela en su lugar.');
        }

        DB::transaction(function () use ($user) {
            $user->syncRoles([]);
            $user->delete();
        });
    }

    public function tieneRegistrosAsociados(User $user): bool
    {
        return $user->profesor()->exists()
            || $user->padre()->exists()
            || $user->encargado()->exists()
            || $user->estudiantes()->exists();
    }

    public function esUltimoDirectorActivo(User $user): bool
    {
        if (! $user->hasRole(User::ROL_DIRECTOR) || $user->estado !== User::ESTADO_ACTIVO) {
            return false;
        }

        return User::role(User::ROL_DIRECTOR)
            ->where('estado', User::ESTADO_ACTIVO)
            ->where('id', '!=', $user->id)
            ->doesntExist();
    }

    /**
     * Un cambio de rol no puede dejar sin director activo al sistema ni
     * contradecir un perfil ya vinculado a la cuenta.
     */
    public function validarCambioDeRol(User $user, string $nuevoRol): void
    {
        if (! in_array($nuevoRol, User::ROLES, true)) {
            throw new \DomainException('Rol no permitido.');
        }

        if ($nuevoRol !== User::ROL_DIRECTOR && $this->esUltimoDirectorActivo($user)) {
            throw new \DomainException('No se puede quitar el rol al último director activo.');
        }

        if ($nuevoRol !== User::ROL_DOCENTE && $user->profesor()->exists()) {
            throw new \DomainException('La cuenta está vinculada a un perfil de docente; su rol debe ser DOCENTE.');
        }

        if ($nuevoRol !== User::ROL_ENCARGADO && ($user->padre()->exists() || $user->encargado()->exists() || $user->estudiantes()->exists())) {
            throw new \DomainException('La cuenta tiene estudiantes o datos de encargado vinculados; su rol debe ser ENCARGADO.');
        }
    }

    // ---------------------------------------------------------------
    // Correspondencia cuenta - perfil
    // ---------------------------------------------------------------

    /**
     * El perfil (docente, padre o encargado) vinculado a una cuenta debe ser
     * de la misma persona: mismo DUI y el rol esperado.
     */
    public function verificarCorrespondencia(User $user, string $duiPerfil, string $rolEsperado): void
    {
        if (! $user->hasRole($rolEsperado)) {
            throw new \DomainException("La cuenta seleccionada no tiene el rol {$rolEsperado}.");
        }

        if ($user->dui === null || DuiFormato::normalizar($duiPerfil) !== $user->dui) {
            throw new \DomainException('El DUI no coincide con el de la cuenta de usuario.');
        }
    }
}
