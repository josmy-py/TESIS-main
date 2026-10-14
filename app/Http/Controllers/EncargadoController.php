<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Models\Encargado;
use App\Models\Padre;
use App\Rules\Dui;
use App\Services\EncargadoService;
use App\Support\DuiFormato;
use Illuminate\Http\Request;

/** M5 (D3, D-E6): encargados que no son padre ni madre, y perfil familiar de la cuenta. */
class EncargadoController extends Controller
{
    use RespondeErrores;

    public function __construct(private EncargadoService $encargadoService)
    {
    }

    public function index(Request $request)
    {
        return $this->responder(fn () => response()->json([
            'encargados' => $this->encargadoService->listar($this->propietarioId(), $request->query('q')),
        ]), 'Error al obtener los encargados');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json(['encargado' => $this->encargadoService->obtener($id, $this->propietarioId())]),
            'Error al obtener el encargado',
            'Encargado no encontrado'
        );
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $validated = $this->validar($request);

            return response()->json([
                'message' => 'Encargado registrado exitosamente',
                'encargado' => $this->encargadoService->crear($validated, $request->user(), $this->propietarioId()),
            ], 201);
        }, 'Error al registrar el encargado');
    }

    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $validated = $this->validar($request);

            return response()->json([
                'message' => 'Encargado actualizado exitosamente',
                'encargado' => $this->encargadoService->actualizar($id, $validated, $this->propietarioId()),
            ]);
        }, 'Error al actualizar el encargado', 'Encargado no encontrado');
    }

    public function destroy(string $id)
    {
        return $this->responder(function () use ($id) {
            $this->encargadoService->eliminar($id);

            return response()->json(['message' => 'Encargado eliminado exitosamente']);
        }, 'Error al eliminar el encargado', 'Encargado no encontrado');
    }

    /** Perfil familiar de la cuenta: sus datos y si es padre/madre o encargado registrado. */
    public function perfil(Request $request)
    {
        $cuenta = $request->user();

        return response()->json([
            'cuenta' => $cuenta->only(['id', 'email']),
            'padre' => Padre::where('user_id', $cuenta->id)->first(),
            'encargado' => Encargado::where('user_id', $cuenta->id)->first(),
            'estudiantes' => $cuenta->estudiantes()->count(),
            'estado_matricula' => $this->estadoMatricula($cuenta->id),
        ]);
    }

    /**
     * Estado de matrícula de la cuenta en el año actual (el activo o, sin
     * él, el último con matrículas de sus estudiantes). Decide el menú:
     * pendiente si alguna solicitud espera revisión; aprobada si no hay
     * pendientes y al menos una está aprobada; si no, sin_matricula.
     */
    private function estadoMatricula(int $cuentaId): string
    {
        $matriculas = \App\Models\Matricula::whereHas('estudiante', fn ($q) => $q->where('user_id', $cuentaId));
        $anioId = \App\Models\AnioLectivo::activo()?->id
            ?? (clone $matriculas)->whereNotNull('anio_lectivo_id')->orderByDesc('anio_lectivo_id')->value('anio_lectivo_id');
        $estados = $matriculas->where('anio_lectivo_id', $anioId)->pluck('estado');

        return match (true) {
            $estados->contains('pendiente') => 'pendiente',
            $estados->contains('aprobado') => 'aprobada',
            default => 'sin_matricula',
        };
    }

    private function validar(Request $request): array
    {
        $request->merge(['dui' => DuiFormato::normalizar($request->dui)]);

        return $request->validate([
            'nombre' => 'required|string|max:80',
            'parentesco' => 'required|in:Tío(a),Abuelo(a),Hermano(a),Primo(a)',
            'telefono' => ['required', 'string', 'max:15', 'regex:/^[267][0-9]{3}-?[0-9]{4}$/'],
            'dui' => ['required', new Dui],
            'direccion' => 'required|string|max:100',
        ], [
            'nombre.required' => 'El nombre es obligatorio.',
            'parentesco.required' => 'El parentesco es obligatorio.',
            'parentesco.in' => 'El parentesco debe ser Tío(a), Abuelo(a), Hermano(a) o Primo(a).',
            'telefono.required' => 'El teléfono es obligatorio.',
            'telefono.regex' => 'El teléfono debe tener 8 dígitos y empezar con 2, 6 o 7.',
            'dui.required' => 'El DUI es obligatorio.',
            'direccion.required' => 'La dirección es obligatoria.',
        ]);
    }
}
