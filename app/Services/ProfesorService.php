<?php

namespace App\Services;

use App\Models\Profesor;
use App\Models\User;
use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perfil del docente (tabla profesores). Al registrar un docente se crea
 * también su cuenta DOCENTE (UsuarioService::crearCuenta), y el código
 * PROF-001, PROF-002... se asigna aquí, nunca desde el cliente.
 */
class ProfesorService
{
    // Campos de la cuenta que se muestran con el perfil
    private const CAMPOS_CUENTA = 'user:id,nombre,email,estado,email_verified_at';

    public function __construct(protected UsuarioService $usuarioService)
    {
    }

    public function listar(?string $buscar = null)
    {
        $query = Profesor::with(self::CAMPOS_CUENTA)->orderBy('id', 'desc');

        if ($buscar !== null && trim($buscar) !== '') {
            $termino = '%'.trim($buscar).'%';
            $duiNormalizado = DuiFormato::normalizar(trim($buscar));

            $query->where(function ($q) use ($termino, $duiNormalizado) {
                $q->where('nombre', 'like', $termino)
                    ->orWhere('codigo', 'like', $termino)
                    ->orWhere('dui', 'like', $termino)
                    ->orWhere('dui', $duiNormalizado);
            });
        }

        return $query->get();
    }

    public function obtener($id)
    {
        return Profesor::with(self::CAMPOS_CUENTA)->findOrFail($id);
    }

    /**
     * Crea el perfil y su cuenta DOCENTE en una sola transacción. Si ya existe
     * una cuenta DOCENTE con ese DUI y sin perfil (creada antes desde la
     * gestión de usuarios), se vincula en lugar de crear otra.
     */
    public function crear(array $data): Profesor
    {
        return DB::transaction(function () use ($data) {
            $user = $this->cuentaDocente($data);

            $profesor = Profesor::create([
                'nombre' => $data['nombre'],
                'dui' => $data['dui'],
                'fecha_nacimiento' => $data['fecha_nacimiento'],
                'codigo' => $this->siguienteCodigo(),
                'telefono' => $data['telefono'],
                'direccion' => $data['direccion'],
                'especialidad' => $data['especialidad'],
                'fecha_contratacion' => $data['fecha_contratacion'],
                'user_id' => $user->id,
            ]);

            return $profesor->load(self::CAMPOS_CUENTA);
        });
    }

    /**
     * El código, el DUI y la cuenta no cambian. El nombre se mantiene igual
     * en el perfil y en la cuenta.
     */
    public function actualizar($id, array $data): Profesor
    {
        $profesor = Profesor::findOrFail($id);

        DB::transaction(function () use ($profesor, $data) {
            $profesor->update([
                'nombre' => $data['nombre'],
                'fecha_nacimiento' => $data['fecha_nacimiento'],
                'telefono' => $data['telefono'],
                'direccion' => $data['direccion'],
                'especialidad' => $data['especialidad'],
                'fecha_contratacion' => $data['fecha_contratacion'],
            ]);

            if ($profesor->user && $profesor->user->nombre !== $data['nombre']) {
                $profesor->user->nombre = $data['nombre'];
                $profesor->user->save();
            }
        });

        return $profesor->load(self::CAMPOS_CUENTA);
    }

    /**
     * Solo se elimina un docente sin secciones ni asignaciones (registrado por
     * error); se elimina también su cuenta. Un docente con historial se
     * desactiva desde la gestión de usuarios.
     */
    public function eliminar($id): bool
    {
        $profesor = Profesor::findOrFail($id);

        if ($profesor->secciones()->exists() || $profesor->asignaciones()->exists()) {
            return false;
        }

        DB::transaction(function () use ($profesor) {
            $user = $profesor->user;

            $profesor->delete();

            if ($user && ! $this->usuarioService->tieneRegistrosAsociados($user)) {
                $user->syncRoles([]);
                $user->delete();
            }
        });

        return true;
    }

    /**
     * Siguiente código correlativo PROF-001, PROF-002... (al menos 3 dígitos).
     * Se bloquean las filas leídas para que dos altas simultáneas no tomen el
     * mismo número; el índice único de la columna es la última barrera.
     */
    public function siguienteCodigo(): string
    {
        $ultimo = Profesor::where('codigo', 'like', Profesor::PREFIJO_CODIGO.'%')
            ->lockForUpdate()
            ->pluck('codigo')
            ->map(function ($codigo) {
                $numero = substr($codigo, strlen(Profesor::PREFIJO_CODIGO));

                return ctype_digit($numero) ? (int) $numero : 0;
            })
            ->max() ?? 0;

        return Profesor::PREFIJO_CODIGO.str_pad((string) ($ultimo + 1), 3, '0', STR_PAD_LEFT);
    }

    private function cuentaDocente(array $data): User
    {
        $existente = User::where('dui', $data['dui'])->first();

        if ($existente) {
            if (! $existente->hasRole(User::ROL_DOCENTE)) {
                throw ValidationException::withMessages([
                    'dui' => ['Este DUI ya tiene una cuenta con otro rol.'],
                ]);
            }

            if ($existente->profesor()->exists()) {
                throw ValidationException::withMessages([
                    'dui' => ['Este DUI ya tiene un perfil de docente.'],
                ]);
            }

            if ($existente->email !== $data['email']) {
                throw ValidationException::withMessages([
                    'email' => ['Este DUI ya tiene una cuenta DOCENTE con otro correo. Use el correo de esa cuenta.'],
                ]);
            }

            return $existente;
        }

        if (User::where('email', $data['email'])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['Este correo ya pertenece a otra cuenta.'],
            ]);
        }

        return $this->usuarioService->crearCuenta([
            'nombre' => $data['nombre'],
            'dui' => $data['dui'],
            'email' => $data['email'],
        ], User::ROL_DOCENTE);
    }
}
