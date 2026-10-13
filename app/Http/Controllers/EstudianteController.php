<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\EstudianteService;
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
            $validated = $request->validate($this->reglas(), $this->mensajes());
            $validated['user_id'] = auth()->id();

            return response()->json([
                'message' => 'Estudiante registrado exitosamente',
                'estudiante' => $this->estudianteService->crear($validated),
            ], 201);
        }, 'Error al registrar el estudiante');
    }

    public function update(Request $request, $id)
    {
        return $this->responder(function () use ($request, $id) {
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

    /** D-E3 (DIRECTOR): pasa el estudiante a otra cuenta ENCARGADO. */
    public function cambiarCuenta(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $validated = $request->validate(['user_id' => 'required|integer'], ['user_id.required' => 'Seleccione la nueva cuenta.']);

            return response()->json([
                'message' => 'El estudiante pasó a la nueva cuenta',
                'estudiante' => $this->estudianteService->cambiarCuenta($id, (int) $validated['user_id'], $request->user()),
            ]);
        }, 'Error al cambiar la cuenta del estudiante', 'Estudiante no encontrado');
    }

    private function reglas(?int $id = null): array
    {
        return [
            'nombre' => 'required|string|max:80',
            'fecha_nacimiento' => 'required|date|before:today',
            'genero' => 'required|in:Masculino,Femenino',
            'distrito_id' => 'required|exists:distritos,id',
            // D-E1: NIE opcional, solo dígitos y único
            'nie' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/', Rule::unique('estudiantes', 'nie')->ignore($id)],
            'direccion' => 'required|string|max:100',
            'canton' => 'nullable|string|max:50',
        ];
    }

    private function mensajes(): array
    {
        return [
            'nombre.required' => 'El nombre es obligatorio.',
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
