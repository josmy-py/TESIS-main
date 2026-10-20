<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Models\Especialidad;
use App\Services\CalendarioService;
use App\Support\NombreUnico;
use Illuminate\Http\Request;

/**
 * Especialidades. Consultas para cuentas con sesión; crear, editar y eliminar
 * solo DIRECTOR (lo exigen las rutas) y solo mientras no haya un año lectivo
 * activo (pedido de Josmy, 19/10/2026): durante el año las matrículas y los
 * planes dependen de ellas.
 */
class EspecialidadController extends Controller
{
    use RespondeErrores;

    // Pedido de Josmy (20/10/2026): solo se bloquean cuando el año activo ya comenzó
    private const BLOQUEO = 'El año lectivo activo (:anio) ya comenzó (:fecha): las especialidades no se pueden crear, modificar ni eliminar hasta que se cierre.';

    public function __construct(private CalendarioService $calendario)
    {
    }

    public function index()
    {
        return $this->responder(
            fn () => response()->json(['especialidades' => Especialidad::orderBy('id', 'desc')->get()]),
            'Error al obtener las especialidades'
        );
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $this->calendario->asegurarAnioActivoSinComenzar(self::BLOQUEO);
            $datos = $this->validar($request);

            $espe = Especialidad::create(['nombre' => $datos['nombre']]);

            return response()->json(['message' => 'Especialidad registrada exitosamente', 'especialidad' => $espe], 201);
        }, 'Error al crear la especialidad');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json(['especialidad' => Especialidad::findOrFail($id)]),
            'Error al obtener la especialidad',
            'Especialidad no encontrada'
        );
    }

    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $espe = Especialidad::findOrFail($id);
            $this->calendario->asegurarAnioActivoSinComenzar(self::BLOQUEO);
            $datos = $this->validar($request, $espe->id);

            $espe->update(['nombre' => $datos['nombre']]);

            return response()->json(['message' => 'Especialidad actualizada exitosamente', 'especialidad' => $espe]);
        }, 'Error al actualizar la especialidad', 'Especialidad no encontrada');
    }

    public function destroy(string $id)
    {
        return $this->responder(function () use ($id) {
            $espe = Especialidad::findOrFail($id);
            $this->calendario->asegurarAnioActivoSinComenzar(self::BLOQUEO);

            if ($espe->matricula()->exists()) {
                throw new \DomainException('No se puede eliminar la especialidad porque tiene matrículas asociadas.');
            }
            // M2.1.2: plan_estudios.especialidad_id también referencia la especialidad
            if ($espe->planesEstudios()->exists()) {
                throw new \DomainException('No se puede eliminar la especialidad porque tiene planes de estudio asociados.');
            }

            $espe->delete();

            return response()->json(['message' => 'Especialidad eliminada exitosamente']);
        }, 'Error al eliminar la especialidad', 'Especialidad no encontrada');
    }

    private function validar(Request $request, ?int $excepto = null): array
    {
        $otras = Especialidad::query()->when($excepto, fn ($q) => $q->where('id', '<>', $excepto));

        return $request->validate([
            'nombre' => ['required', 'string', 'max:50', NombreUnico::regla($otras, 'nombre', 'Ya existe una especialidad con ese nombre.')],
        ], [
            'nombre.required' => 'El nombre de la especialidad es obligatorio.',
            'nombre.max' => 'El nombre de la especialidad no debe exceder los 50 caracteres.',
        ]);
    }
}
