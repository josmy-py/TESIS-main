<?php

namespace App\Http\Controllers;

use App\Services\CalendarioService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Años lectivos (M2.2). Consultas para cuentas con sesión; escrituras solo
 * DIRECTOR (lo exigen las rutas). 422 = datos inválidos, 409 = la operación
 * no es posible en el estado actual.
 */
class AnioLectivoController extends Controller
{
    private const MENSAJES = [
        'anio.required' => 'El año es obligatorio.',
        'anio.integer' => 'El año debe ser un número, por ejemplo 2027.',
        'anio.between' => 'El año debe estar entre 2000 y 2100.',
        'anio.unique' => 'Ya existe un año lectivo con ese año.',
        'fecha_inicio.required' => 'La fecha de inicio es obligatoria.',
        'fecha_inicio.date' => 'La fecha de inicio no es válida.',
        'fecha_fin.required' => 'La fecha de fin es obligatoria.',
        'fecha_fin.date' => 'La fecha de fin no es válida.',
        'fecha_fin.after' => 'La fecha de fin debe ser posterior a la fecha de inicio.',
        'inicio_matricula.required' => 'La fecha de inicio de matrícula es obligatoria.',
        'inicio_matricula.date' => 'La fecha de inicio de matrícula no es válida.',
        'fin_matricula.required' => 'La fecha de fin de matrícula es obligatoria.',
        'fin_matricula.date' => 'La fecha de fin de matrícula no es válida.',
        'fin_matricula.after_or_equal' => 'La fecha de fin de matrícula no puede ser anterior a la de inicio de matrícula.',
        'fin_matricula.before_or_equal' => 'La matrícula debe terminar a más tardar en la fecha de fin del año lectivo.',
        'nombre.required' => 'El nombre del periodo es obligatorio.',
        'nombre.max' => 'El nombre del periodo no debe exceder los 50 caracteres.',
        'nivel.required' => 'El nivel del periodo es obligatorio.',
        'nivel.integer' => 'El nivel debe ser un número entero.',
        'nivel.between' => 'El nivel debe estar entre 1 y '.CalendarioService::MAX_PERIODOS.'.',
    ];

    // Periodo de matrícula (pedido de Josmy, 20/10/2026): puede ser antes del inicio del año, pero termina a más tardar con el año
    private const REGLAS_MATRICULA = [
        'inicio_matricula' => 'required|date',
        'fin_matricula' => 'required|date|after_or_equal:inicio_matricula|before_or_equal:fecha_fin',
    ];

    public function __construct(private CalendarioService $calendario)
    {
    }

    public function index()
    {
        return $this->responder(fn () => response()->json(['anios_lectivos' => $this->calendario->listar()]), 'Error al obtener los años lectivos');
    }

    // Año activo con sus periodos (para paneles y formularios)
    public function actual()
    {
        return $this->responder(function () {
            $anio = $this->calendario->actual();

            return response()->json([
                'anio_lectivo' => $anio,
                'message' => $anio === null ? 'No hay un año lectivo activo.' : null,
            ]);
        }, 'Error al obtener el año lectivo actual');
    }

    public function show(string $id)
    {
        return $this->responder(fn () => response()->json(['anio_lectivo' => $this->calendario->obtener($id)]), 'Error al obtener el año lectivo');
    }

    public function periodos(string $id)
    {
        return $this->responder(fn () => response()->json(['periodos' => $this->calendario->periodos($id)]), 'Error al obtener los periodos');
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'anio' => 'required|integer|between:2000,2100|unique:anios_lectivos,anio',
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after:fecha_inicio',
            ] + self::REGLAS_MATRICULA, self::MENSAJES);

            return response()->json([
                'message' => 'Año lectivo creado exitosamente',
                'anio_lectivo' => $this->calendario->crear($datos),
            ], 201);
        }, 'Error al crear el año lectivo');
    }

    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->calendario->obtener($id);

            $datos = $request->validate([
                'anio' => ['required', 'integer', 'between:2000,2100', Rule::unique('anios_lectivos', 'anio')->ignore($id)],
                'fecha_inicio' => 'required|date',
                'fecha_fin' => 'required|date|after:fecha_inicio',
            ] + self::REGLAS_MATRICULA, self::MENSAJES);

            return response()->json([
                'message' => 'Año lectivo actualizado exitosamente',
                'anio_lectivo' => $this->calendario->actualizar($id, $datos),
            ]);
        }, 'Error al actualizar el año lectivo');
    }

    public function activar(string $id)
    {
        return $this->responder(fn () => response()->json([
            'message' => 'Año lectivo activado',
            'anio_lectivo' => $this->calendario->activar($id),
        ]), 'Error al activar el año lectivo');
    }

    public function cerrar(string $id)
    {
        return $this->responder(fn () => response()->json([
            'message' => 'Año lectivo cerrado',
            'anio_lectivo' => $this->calendario->cerrar($id),
        ]), 'Error al cerrar el año lectivo');
    }

    public function destroy(string $id)
    {
        return $this->responder(function () use ($id) {
            $this->calendario->eliminar($id);

            return response()->json(['message' => 'Año lectivo eliminado']);
        }, 'Error al eliminar el año lectivo');
    }

    public function agregarPeriodo(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->calendario->obtener($id);

            $datos = $request->validate([
                'nombre' => 'required|string|max:50',
                'nivel' => 'required|integer|between:1,'.CalendarioService::MAX_PERIODOS,
            ], self::MENSAJES);

            return response()->json([
                'message' => 'Periodo agregado',
                'periodo' => $this->calendario->agregarPeriodo($id, $datos),
            ], 201);
        }, 'Error al agregar el periodo');
    }

    private function responder(callable $accion, string $error)
    {
        try {
            return $accion();
        } catch (ValidationException $e) {
            return response()->json(['message' => 'Error de validación', 'errors' => $e->errors()], 422);
        } catch (ModelNotFoundException $e) {
            return response()->json(['message' => 'Año lectivo no encontrado'], 404);
        } catch (\DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        } catch (QueryException $e) {
            report($e);

            // Índices únicos de año activo o periodo abierto (peticiones simultáneas)
            if ((string) $e->getCode() === '23000' || (string) $e->getCode() === '23505') {
                return response()->json(['message' => 'La operación entra en conflicto con el estado actual. Actualice e intente de nuevo.'], 409);
            }

            return response()->json(['message' => $error], 500);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['message' => $error], 500);
        }
    }
}
