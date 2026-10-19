<?php

namespace App\Services;

use App\Models\Profesor;
use App\Models\User;
use App\Support\DuiFormato;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Perfil del docente (tabla profesores). La cuenta DOCENTE se crea antes en
 * Usuarios y aquí solo se vincula con el registro del profesor (pedido de
 * Josmy, 19/10/2026). El código PROF-001, PROF-002... se asigna aquí, nunca
 * desde el cliente.
 */
class ProfesorService
{
    // Campos de la cuenta que se muestran con el perfil
    private const CAMPOS_CUENTA = 'user:id,email';

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

    /** Cuentas DOCENTE que todavía no están vinculadas con un profesor. */
    public function cuentasDisponibles()
    {
        return User::whereHas('rolAsignado', fn ($q) => $q->where('nombre', User::ROL_DOCENTE))
            ->doesntHave('profesor')
            ->orderBy('email')
            ->get(['id', 'email']);
    }

    /**
     * Crea el perfil vinculado con una cuenta DOCENTE sin perfil.
     */
    public function crear(array $data): Profesor
    {
        return DB::transaction(function () use ($data) {
            $user = $this->cuentaDocente((int) $data['user_id']);

            $profesor = Profesor::create([
                'nombre' => $data['nombre'],
                'dui' => $data['dui'],
                'fecha_nacimiento' => $data['fecha_nacimiento'],
                'codigo' => $this->siguienteCodigo(),
                'telefono' => $data['telefono'],
                'direccion' => $data['direccion'],
                'user_id' => $user->id,
            ]);

            return $profesor->load(self::CAMPOS_CUENTA);
        });
    }

    /**
     * actualizarDatos: el código y el DUI no cambian. La cuenta vinculada se
     * puede cambiar por otra cuenta DOCENTE sin perfil.
     */
    public function actualizar($id, array $data): Profesor
    {
        $profesor = Profesor::findOrFail($id);

        $cambios = [
            'nombre' => $data['nombre'],
            'fecha_nacimiento' => $data['fecha_nacimiento'],
            'telefono' => $data['telefono'],
            'direccion' => $data['direccion'],
        ];
        if (! empty($data['user_id']) && (int) $data['user_id'] !== (int) $profesor->user_id) {
            $cambios['user_id'] = $this->cuentaDocente((int) $data['user_id'])->id;
        }

        $profesor->update($cambios);

        return $profesor->load(self::CAMPOS_CUENTA);
    }

    /**
     * Solo se elimina un docente sin secciones ni asignaciones (registrado por
     * error). La cuenta se administra en Usuarios: queda libre para vincularla
     * de nuevo.
     */
    public function eliminar($id): bool
    {
        $profesor = Profesor::findOrFail($id);

        if ($profesor->secciones()->exists() || $profesor->asignaciones()->exists()) {
            return false;
        }

        $profesor->delete();

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

    /** La cuenta que se vincula debe existir, ser DOCENTE y no tener otro profesor. */
    private function cuentaDocente(int $userId): User
    {
        $cuenta = User::find($userId);

        if (! $cuenta) {
            throw ValidationException::withMessages(['user_id' => ['La cuenta seleccionada no existe.']]);
        }
        if (! $cuenta->hasRole(User::ROL_DOCENTE)) {
            throw ValidationException::withMessages(['user_id' => ['La cuenta seleccionada no tiene el rol DOCENTE.']]);
        }
        if ($cuenta->profesor()->exists()) {
            throw ValidationException::withMessages(['user_id' => ['La cuenta seleccionada ya está vinculada con otro profesor.']]);
        }

        return $cuenta;
    }
}
