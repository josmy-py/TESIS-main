<?php

namespace App\Http\Controllers;

use App\Services\MatriculaService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class MatriculaController extends Controller
{
    protected $matriculaService;

    public function __construct(MatriculaService $matriculaService)
    {
        $this->matriculaService = $matriculaService;
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request)
    {
        try {
            $matriculas = $this->matriculaService->listar(
                $request->has('estado') ? $request->estado : null,
                $this->propietarioId()
            );

            return response()->json([
                'matriculas' => $matriculas
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las matriculas',
            ], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $request->validate([
                'ingreso' => 'required|in:Nuevo ingreso,Reingreso',
                'estudiante_id' => 'required|exists:estudiantes,id',
                'enfermedad_id' => 'required|exists:enfermedades,id',
                'seccion_id' => 'nullable|exists:secciones,id',
                'especialidad_id' => 'required|exists:especialidades,id',
                'encargado_id' => 'nullable|exists:encargados,id',
                'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048',
                'documento' => 'nullable|file|mimes:pdf,jpeg,png,jpg|max:2048',
                'es_trasladado' => 'required|boolean',
                'anio_lectivo_id' => 'nullable|integer',
            ], [
                'ingreso.required' => 'El tipo de ingreso es obligatorio.',
                'estudiante_id.required' => 'El estudiante es obligatorio.',
                'enfermedad_id.required' => 'La enfermedad es obligatoria.',
                'especialidad_id.required' => 'La especialidad es obligatoria.',
                'foto.required' => 'La foto es obligatoria.',
                'documento.mimes' => 'El documento debe ser PDF, JPEG, PNG o JPG.',
                'es_trasladado.required' => 'Debe indicar si el estudiante es trasladado.',
                'es_trasladado.boolean' => 'El campo es trasladado debe ser verdadero o falso.',
            ]);

            // El encargado solo matricula a sus estudiantes y con su propio perfil de encargado
            $propietarioId = $this->propietarioId();
            $this->validarPropiedad($request->estudiante_id, $request->encargado_id, $propietarioId);

            $matricula = $this->matriculaService->crear(
                $request->all(),
                $request->file('foto'),
                $request->file('documento')
            );

            return response()->json([
                'message' => 'Matricula creada exitosamente',
                'matricula' => $matricula,
            ], 201);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al crear la matricula',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        try {
            $matricula = $this->matriculaService->obtener($id, $this->propietarioId());

            return response()->json($matricula);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Matricula no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener la matricula',
            ], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        try {
            $validated = $request->validate([
                'seccion_id' => 'nullable|exists:secciones,id',
                'enfermedad_id' => 'required|exists:enfermedades,id',
                'encargado_id' => 'required|exists:encargados,id',
                'especialidad_id' => 'required|exists:especialidades,id',
            ]);

            $propietarioId = $this->propietarioId();
            $this->validarPropiedad(null, $validated['encargado_id'], $propietarioId);

            $matricula = $this->matriculaService->actualizar(
                $id,
                $validated,
                $propietarioId
            );

            return response()->json([
                'message' => 'Matrícula actualizada exitosamente',
                'matricula' => $matricula,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Matrícula no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar la matrícula',
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }

    public function estadoMatricula(Request $request, $id)
    {
        try {
            $request->validate([
                'estado' => 'required|in:pendiente,aprobado,rechazado',
            ]);

            $resultado = $this->matriculaService->actualizarEstado(
                $id,
                $request->estado
            );

            if ($resultado['error']) {
                return response()->json([
                    'message' => $resultado['message'],
                ], $resultado['status']);
            }

            return response()->json([
                'message' => 'La matricula ha sido actualizada correctamente',
                'matriculas' => $resultado['matricula'],
            ]);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Matricula no encontrada',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al actualizar el estado la matricula',
            ], 500);
        }
    }

    public function buscarNie(Request $request)
    {
        try {
            $request->validate([
                'nie' => 'required|string|max:20',
            ]);

            $estudiante = $this->matriculaService->buscarPorNie(
                $request->nie,
                $this->propietarioId()
            );

            if (!$estudiante) {
                return response()->json([
                    'message' => 'El estudiante no pertenece a la institución.',
                ], 404);
            }

            return response()->json([
                'estudiante' => $estudiante,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al buscar el estudiante por NIE',
            ], 500);
        }
    }

    public function estudianteInscrito(Request $request)
    {
        try {
            $estudiantes = $this->matriculaService
                ->obtenerEstudiantesInscritos($this->propietarioId());

            return response()->json([
                'estudiantes' => $estudiantes,
            ], 200);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los estudiantes inscritos',
            ], 500);
        }
    }

    public function padresEstudiante(string $estudianteId)
    {
        try {
            $padres = $this->matriculaService
                ->obtenerPadresEstudiante($estudianteId, $this->propietarioId());

            return response()->json([
                'padres' => $padres,
            ], 200);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Estudiante no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener los padres del estudiante',
            ], 500);
        }
    }

    public function validarGrado(Request $request, $estudiante, $grado)
    {
        try {
            $request->validate([
                'ingreso' => 'required|in:Nuevo ingreso,Reingreso',
            ]);

            $resultado = $this->matriculaService->validarGrado(
                $estudiante,
                $grado,
                $request->ingreso,
                $this->propietarioId()
            );

            return response()->json([
                'permitido' => $resultado['permitido'],
                'grado_permitido' => $resultado['grado_permitido'] ?? null,
                'message' => $resultado['message'],
            ], $resultado['status'] ?? 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Estudiante no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al validar el grado',
            ], 500);
        }
    }

    public function disponibilidadSeccion(Request $request)
    {
        try {
            $request->validate([
                'grado_id' => 'required|exists:grados,id',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'nullable|integer',
            ]);

            $resultado = $this->matriculaService
                ->verificarDisponibilidadSeccion(
                    $request->grado_id,
                    $request->turno,
                    $request->anio_lectivo_id
                );

            return response()->json($resultado, 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al verificar la disponibilidad de secciones',
            ], 500);
        }
    }

    public function seccionesDisponibles(Request $request)
    {
        try {
            $request->validate([
                'grado_id' => 'required|exists:grados,id',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'nullable|integer',
            ]);

            $secciones = $this->matriculaService
                ->obtenerSeccionesDisponibles(
                    $request->grado_id,
                    $request->turno,
                    $request->anio_lectivo_id
                );

            return response()->json([
                'secciones' => $secciones,
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al obtener las secciones disponibles',
            ], 500);
        }
    }

    public function duplicidadMatricula(Request $request)
    {
        try {
            $request->validate([
                'estudiante_id' => 'required|exists:estudiantes,id',
                'anio' => 'required_without:anio_lectivo_id|nullable|string|size:4',
                'anio_lectivo_id' => 'nullable|integer|exists:anios_lectivos,id',
            ]);

            $this->matriculaService->estudiantePropio($request->estudiante_id, $this->propietarioId());

            $duplicidad = $this->matriculaService
                ->verificarDuplicidad(
                    $request->estudiante_id,
                    $request->anio,
                    $request->anio_lectivo_id
                );

            if ($duplicidad) {
                return response()->json([
                    'duplicidad' => true,
                    'message' => 'El estudiante ya tiene una matrícula registrada',
                ], 422);
            }

            return response()->json([
                'duplicidad' => false,
                'message' => 'El estudiante no tiene una matrícula registrada',
            ], 200);

        } catch (ValidationException $e) {
            return response()->json([
                'message' => 'Error de validación',
                'errors' => $e->errors(),
            ], 422);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'message' => 'Estudiante no encontrado',
            ], 404);

        } catch (\Exception $e) {
            report($e);

            return response()->json([
                'message' => 'Error al verificar la matrícula',
            ], 500);
        }
    }

    /**
     * Para el ENCARGADO: el estudiante debe ser suyo y el encargado_id debe
     * ser su propio perfil. Se responde como error de validación del campo.
     */
    private function validarPropiedad($estudianteId, $encargadoId, ?int $propietarioId): void
    {
        $errores = [];

        if ($estudianteId !== null && ! $this->matriculaService->estudiantes($propietarioId)->whereKey($estudianteId)->exists()) {
            $errores['estudiante_id'] = ['El estudiante seleccionado no es válido.'];
        }

        if (! $this->matriculaService->encargadoPermitido($encargadoId, $propietarioId)) {
            $errores['encargado_id'] = ['El encargado seleccionado no es válido.'];
        }

        if ($errores) {
            throw ValidationException::withMessages($errores);
        }
    }
}
