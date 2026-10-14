<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Models\Padre;
use App\Models\User;
use App\Rules\Dui;
use App\Services\PadreService;
use App\Services\UsuarioService;
use App\Support\DuiFormato;
use App\Support\NuevoPadre;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PadreController extends Controller
{
    use RespondeErrores;

    protected PadreService $padreService;

    protected UsuarioService $usuarioService;

    public function __construct(PadreService $padreService, UsuarioService $usuarioService)
    {
        $this->padreService = $padreService;
        $this->usuarioService = $usuarioService;
    }
    
    public function index(Request $request)
    {
        return $this->responder(fn () => response()->json([
            'padres' => $this->padreService->listar($this->propietarioId(), $request->query('q')),
        ]), 'Error al obtener los padres');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json(['padre' => $this->padreService->obtener($id, $this->propietarioId())]),
            'Error al obtener el padre',
            'Padre no encontrado'
        );
    }

    /** D-E5: solo su propio perfil o el padre vinculado únicamente con sus estudiantes. */
    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $request->merge(['dui' => DuiFormato::normalizar($request->dui), 'nombre' => NuevoPadre::limpiarNombre($request->nombre)]);
            $validated = $request->validate([
                'nombre' => ['required', 'string', 'max:80', NuevoPadre::REGLA_NOMBRE],
                'dui' => ['required', new Dui],
                'telefono' => 'nullable|string|max:15',
                'email' => 'nullable|email|max:100',
            ], ['nombre.required' => 'El nombre es obligatorio.', 'nombre.regex' => NuevoPadre::MENSAJE_NOMBRE, 'dui.required' => 'El DUI es obligatorio.']);

            return response()->json([
                'message' => 'Datos actualizados correctamente',
                'padre' => $this->padreService->actualizar($id, $validated, $this->propietarioId()),
            ]);
        }, 'Error al actualizar el padre', 'Padre no encontrado');
    }

    public function buscarPorDui(Request $request)
    {
        $request->validate([
            'dui' => 'required|string'
        ]);

        try {
            $padre = $this->padreService->buscarPorDui($request->dui, $this->propietarioId());

            if ($padre) {
                return response()->json([
                    'encontrado' => true,
                    'padre' => $padre
                ], 200);
            }

            return response()->json([
                'encontrado' => false,
                'message' => 'No se encontró ningún padre con este DUI'
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al buscar el padre',
            ], 500);
        }
    }

    /**
     * Registra un padre o una madre. El encargado los registra antes que al
     * estudiante, sin vínculo; la relación se crea al guardar al estudiante,
     * donde se le busca por su DUI. Con estudiante_id y parentesco queda
     * vinculado en la misma transacción.
     */
    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $propietarioId = $this->propietarioId();
            $responsable = $request->boolean('es_responsable') || $request->boolean('es_encargado');

            $vinculo = $request->validate([
                'estudiante_id' => ['nullable', 'integer'],
                'parentesco' => ['required_with:estudiante_id', 'nullable', 'in:Padre,Madre'],
            ], [
                'parentesco.required_with' => 'Indique el parentesco (Padre o Madre).',
                'parentesco.in' => 'El parentesco debe ser Padre o Madre.',
            ]);

            // El estudiante debe ser de la cuenta (lo ajeno responde como no encontrado)
            if (! empty($vinculo['estudiante_id'])) {
                \App\Models\Estudiante::query()
                    ->when($propietarioId, fn ($q) => $q->where('user_id', $propietarioId))
                    ->findOrFail($vinculo['estudiante_id']);
            }

            $datos = NuevoPadre::validar($request->all() + ['es_responsable' => $responsable], $request->user());
            $padre = $this->padreService->crear($datos + $vinculo);

            return response()->json([
                'message' => 'Padre registrado exitosamente',
                'padre' => $padre,
            ], 201);
        }, 'Error al registrar el padre', 'Estudiante no encontrado');
    }
}
