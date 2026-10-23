<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\EstudianteService;
use App\Support\NuevoPadre;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EstudianteController extends Controller
{
    use RespondeErrores;

    protected EstudianteService $estudianteService;

    public function __construct(EstudianteService $estudianteService)
    {
        $this->estudianteService = $estudianteService;
    }

    public function index(Request $request)
    {
        return $this->responder(function () use ($request) {
            $filtros = $request->validate([
                'q' => 'nullable|string|max:80',
                'estado' => 'nullable|in:inscrito,activo,inactivo,egresado,graduado',
                'user_id' => 'nullable|integer',
                'con_matricula_aprobada' => 'nullable|boolean',
                // Filtros de la dirección por la matrícula: año lectivo, grado y sección
                'anio_lectivo_id' => 'nullable|integer',
                'grado_id' => 'nullable|integer',
                'seccion_id' => 'nullable|integer',
            ]);

            return response()->json([
                'estudiantes' => $this->estudianteService->listar($filtros, $this->propietarioId()),
            ]);
        }, 'Error al obtener los estudiantes');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json($this->estudianteService->obtener($id, $this->propietarioId())),
            'Error al obtener el estudiante',
            'Estudiante no encontrado'
        );
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $this->limpiarNombre($request);
            // El encargado registra primero a los padres; al guardar al estudiante indica
            // el DUI del padre y/o de la madre y aquí se crea la relación (parientes).
            $validated = $request->validate($this->reglas() + [
                'padres' => [$this->propietarioId() !== null ? 'required' : 'nullable', 'array', 'max:2'],
                'padres.*' => 'array',
                'padres.*.parentesco' => 'required|in:Padre,Madre|distinct',
                'padres.*.dui' => 'required|string|distinct',
            ], $this->mensajes() + [
                'padres.required' => 'Indique el DUI del padre y/o de la madre del estudiante. Si aún no están registrados, regístrelos primero en «Registro de Padres».',
                'padres.max' => 'Indique como máximo un padre y una madre.',
                'padres.*.dui.required' => 'Indique el DUI.',
                'padres.*.dui.distinct' => 'La misma persona no puede ser padre y madre.',
                'padres.*.parentesco.required' => 'Indique el parentesco (Padre o Madre).',
                'padres.*.parentesco.in' => 'El parentesco debe ser Padre o Madre.',
                'padres.*.parentesco.distinct' => 'Solo puede indicar un padre y una madre.',
            ]);
            $validated['user_id'] = auth()->id();
            $padres = $request->input('padres', []);
            unset($validated['padres']);

            return response()->json([
                'message' => 'Estudiante registrado exitosamente',
                'estudiante' => $this->estudianteService->crearConPadres($validated, is_array($padres) ? $padres : [], $this->propietarioId()),
            ], 201);
        }, 'Error al registrar el estudiante');
    }

    public function update(Request $request, $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->limpiarNombre($request);
            $validated = $request->validate($this->reglas((int) $id), $this->mensajes());

            // El propietario no cambia y no se acepta desde la petición;
            // el encargado solo puede actualizar sus propios estudiantes.
            return response()->json([
                'message' => 'Estudiante actualizado exitosamente',
                'estudiante' => $this->estudianteService->actualizar($id, $validated, $this->propietarioId()),
            ]);
        }, 'Error al actualizar el estudiante', 'Estudiante no encontrado para actualizar');
    }

    public function destroy(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->estudianteService->eliminar($id, $this->propietarioId(), $request->user());

            return response()->json(['message' => 'Estudiante eliminado exitosamente']);
        }, 'Error al eliminar el estudiante', 'Estudiante no encontrado');
    }

    private function reglas(?int $id = null): array
    {
        return [
            'nombre' => ['required', 'string', 'max:80', NuevoPadre::REGLA_NOMBRE],
            'fecha_nacimiento' => 'required|date|before:today',
            'genero' => 'required|in:Masculino,Femenino',
            'distrito_id' => 'required|exists:distritos,id',
            // D-E1: NIE opcional, solo dígitos y único. El formulario del estudiante ya no
            // lo pide (va en la matrícula): si no se envía, el NIE guardado no cambia.
            'nie' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/', Rule::unique('estudiantes', 'nie')->ignore($id)],
            'direccion' => 'required|string|max:100',
            'canton' => 'nullable|string|max:50',
        ];
    }

    /** Nombre sin espacios sobrantes, antes de validarlo. */
    private function limpiarNombre(Request $request): void
    {
        if ($request->has('nombre')) {
            $request->merge(['nombre' => NuevoPadre::limpiarNombre($request->input('nombre'))]);
        }
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
            'nombre.regex' => NuevoPadre::MENSAJE_NOMBRE,
            'fecha_nacimiento.required' => 'La fecha de nacimiento es obligatoria.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'genero.required' => 'El género es obligatorio.',
            'distrito_id.required' => 'El distrito es obligatorio.',
            'distrito_id.exists' => 'El distrito seleccionado no existe.',
            'nie.regex' => 'El NIE debe tener solo números (hasta 10 dígitos).',
            'nie.unique' => 'El NIE ya está registrado; contacte a la dirección.',
            'direccion.required' => 'La dirección es obligatoria.',
        ];
    }
}
