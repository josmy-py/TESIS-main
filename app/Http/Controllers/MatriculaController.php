<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\MatriculaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * M6.1: API de matrícula. Las reglas (grado, plan, cupo, responsable,
 * duplicidad) están en MatriculaService y se aplican al crear, editar y
 * aprobar. Lo ajeno responde 404; las reglas de estado, 409.
 */
class MatriculaController extends Controller
{
    use RespondeErrores;

    private const MENSAJES = [
        'ingreso.required' => 'El tipo de ingreso es obligatorio.',
        'estudiante_id.required' => 'El estudiante es obligatorio.',
        'seccion_id.prohibited' => 'La sección la asigna la dirección al revisar la matrícula.',
        'grado_id.required' => 'Seleccione el grado a matricular.',
        'turno.required' => 'Seleccione el turno.',
        'turno.required_with' => 'Seleccione el turno.',
        'turno.in' => 'El turno debe ser mañana o tarde.',
        'nie.required' => 'El NIE del estudiante es obligatorio.',
        'nie.regex' => 'El NIE debe tener solo números (hasta 10 dígitos).',
        'nie.unique' => 'El NIE ya está registrado; contacte a la dirección.',
        'nie.prohibited' => 'En Nuevo ingreso sin traslado el NIE lo registra la dirección al aprobar la matrícula.',
        'foto.required' => 'La foto es obligatoria.',
        'foto.image' => 'La foto debe ser una imagen JPEG o PNG.',
        'documento.required_if' => 'La partida de nacimiento es obligatoria para Nuevo ingreso.',
        'documento.mimes' => 'El documento debe ser PDF, JPEG, PNG o JPG.',
        'es_trasladado.required' => 'Debe indicar si el estudiante es trasladado.',
        'es_trasladado.boolean' => 'El campo es trasladado debe ser verdadero o falso.',
        'discapacidades.*.exists' => 'Una de las discapacidades no existe.',
        'discapacidades.*.distinct' => 'Hay discapacidades repetidas.',
    ];

    public function __construct(private MatriculaService $matriculaService) {}

    /** Filtros: estado, ingreso, anio_lectivo_id, grado_id, q. Devuelve también el resumen por estado. */
    public function index(Request $request)
    {
        return $this->responder(function () use ($request) {
            $filtros = $request->validate([
                'estado' => 'nullable|in:pendiente,aprobado,rechazado',
                'ingreso' => 'nullable|in:Nuevo ingreso,Reingreso',
                'anio_lectivo_id' => 'nullable|integer',
                'grado_id' => 'nullable|integer',
                'q' => 'nullable|string|max:100',
            ]);

            return response()->json($this->matriculaService->listar($filtros, $this->propietarioId()));
        }, 'Error al obtener las matrículas');
    }

    public function store(Request $request)
    {
        return $this->responder(function () use ($request) {
            $propietarioId = $this->propietarioId();
            $validador = Validator::make($request->all(), [
                'ingreso' => 'required|in:Nuevo ingreso,Reingreso',
                'estudiante_id' => 'required|integer|exists:estudiantes,id',
                // NIE: el encargado lo ingresa en Reingreso o con traslado; en Nuevo ingreso sin traslado lo agrega la dirección
                'nie' => $propietarioId && $request->input('ingreso') === 'Nuevo ingreso' && ! $request->boolean('es_trasladado')
                    ? 'prohibited'
                    : $this->reglaNie((int) $request->input('estudiante_id'), $propietarioId !== null),
                // El encargado no elige sección: indica grado y turno, que sirven a la
                // dirección para asignarla (queda una sección de ese grado y turno con cupo)
                'seccion_id' => $propietarioId ? 'prohibited' : 'nullable|integer|exists:secciones,id',
                'grado_id' => [$propietarioId ? 'required' : 'nullable', 'integer', 'exists:grados,id'],
                'turno' => [$propietarioId ? 'required' : 'nullable', 'required_with:grado_id', 'in:matutino,vespertino'],
                'anio_lectivo_id' => 'nullable|integer',
                'especialidad_id' => 'nullable|integer|exists:especialidades,id',
                'encargado_id' => 'nullable|integer|exists:encargados,id',
                'enfermedad_id' => 'nullable|integer|exists:enfermedades,id',
                // Medicamento que toma el estudiante para esa enfermedad (texto libre)
                'medicamento' => 'nullable|string|max:50',
                'discapacidades' => 'nullable|array',
                'discapacidades.*' => 'integer|distinct|exists:discapacidades,id',
                'foto' => 'required|image|mimes:jpeg,png,jpg|max:2048',
                'documento' => 'required_if:ingreso,Nuevo ingreso|nullable|file|mimes:pdf,jpeg,png,jpg|max:2048',
                'es_trasladado' => 'required|boolean',
            ], self::MENSAJES);

            // El encargado solo matricula a sus estudiantes y con su propio perfil de
            // encargado; se informa junto con los demás errores del formulario
            $validador->after(function ($v) use ($request, $propietarioId) {
                try {
                    $this->validarPropiedad($request->input('estudiante_id'), $request->input('encargado_id'), $propietarioId);
                } catch (ValidationException $e) {
                    foreach ($e->errors() as $campo => $mensajes) {
                        $v->errors()->add($campo, $mensajes[0]);
                    }
                }
            });
            $datos = $validador->validate();

            $matricula = $this->matriculaService->crear(
                $datos,
                $request->file('foto'),
                $request->file('documento'),
                $request->user(),
                $propietarioId
            );

            return response()->json(['message' => 'Solicitud de matrícula registrada. Queda pendiente de aprobación.', 'matricula' => $matricula], 201);
        }, 'Error al crear la matrícula');
    }

    public function show(string $id)
    {
        return $this->responder(
            fn () => response()->json($this->matriculaService->obtener($id, $this->propietarioId())),
            'Error al obtener la matrícula',
            'Matrícula no encontrada'
        );
    }

    /** D-M7: solo pendientes. Con archivos, enviar como POST multipart con _method=PUT. */
    public function update(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $propietarioId = $this->propietarioId();
            $actual = $this->matriculaService->matriculas($propietarioId)->whereKey($id)->first(['id', 'estudiante_id', 'ingreso', 'es_trasladado']);
            $trasladado = $request->has('es_trasladado') ? $request->boolean('es_trasladado') : (bool) $actual?->es_trasladado;
            $datos = $request->validate([
                'seccion_id' => $propietarioId ? 'prohibited' : 'sometimes|required|integer|exists:secciones,id',
                'nie' => $propietarioId && $actual?->ingreso === 'Nuevo ingreso' && ! $trasladado
                    ? 'prohibited'
                    : $this->reglaNie((int) $actual?->estudiante_id, false),
                'especialidad_id' => 'sometimes|nullable|integer|exists:especialidades,id',
                'encargado_id' => 'sometimes|nullable|integer|exists:encargados,id',
                'enfermedad_id' => 'sometimes|nullable|integer|exists:enfermedades,id',
                'discapacidades' => 'sometimes|nullable|array',
                'discapacidades.*' => 'integer|distinct|exists:discapacidades,id',
                'es_trasladado' => 'sometimes|required|boolean',
                'foto' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
                'documento' => 'sometimes|file|mimes:pdf,jpeg,png,jpg|max:2048',
            ], self::MENSAJES);

            if (array_key_exists('encargado_id', $datos)) {
                $this->validarPropiedad(null, $datos['encargado_id'], $propietarioId);
            }

            $matricula = $this->matriculaService->actualizar(
                $id,
                collect($datos)->except(['foto', 'documento'])->all(),
                $request->file('foto'),
                $request->file('documento'),
                $request->user(),
                $propietarioId
            );

            return response()->json(['message' => 'Matrícula actualizada exitosamente', 'matricula' => $matricula]);
        }, 'Error al actualizar la matrícula', 'Matrícula no encontrada');
    }

    /** D-M7: cancelar una solicitud pendiente. */
    public function destroy(Request $request, string $id)
    {
        return $this->responder(function () use ($request, $id) {
            $this->matriculaService->cancelar($id, $request->user(), $this->propietarioId());

            return response()->json(['message' => 'Solicitud de matrícula cancelada.']);
        }, 'Error al cancelar la matrícula', 'Matrícula no encontrada');
    }

    /** Solo DIRECTOR (págs. 63-64): aprobar (con sección opcional si falta) o rechazar. */
    public function estadoMatricula(Request $request, $id)
    {
        return $this->responder(function () use ($request, $id) {
            $datos = $request->validate([
                'estado' => 'required|in:aprobado,rechazado',
                'seccion_id' => 'nullable|integer|exists:secciones,id',
                'nie' => ['nullable', 'string', 'regex:/^[0-9]{1,10}$/'],
            ], self::MENSAJES + [
                'estado.in' => 'Solo se puede aprobar o rechazar una matrícula.',
            ]);

            if ($datos['estado'] === 'aprobado') {
                $matricula = $this->matriculaService->aprobar($id, $datos['seccion_id'] ?? null, $request->user(), $datos['nie'] ?? null);
                $mensaje = 'Matrícula aprobada.';
            } else {
                $matricula = $this->matriculaService->rechazar($id, $request->user());
                $mensaje = 'Matrícula rechazada.';
            }

            return response()->json(['message' => $mensaje, 'matricula' => $matricula]);
        }, 'Error al actualizar el estado de la matrícula', 'Matrícula no encontrada');
    }

    /** Solo DIRECTOR: grados, turnos y secciones con cupo donde se puede ubicar la matrícula. */
    public function seccionesParaAsignar($id)
    {
        return $this->responder(
            fn () => response()->json($this->matriculaService->seccionesParaAsignar($id)),
            'Error al obtener las secciones', 'Matrícula no encontrada'
        );
    }

    /** D-M9: foto o documento desde el disco privado, solo para la cuenta dueña o la dirección. */
    public function archivo($id, string $tipo)
    {
        return $this->responder(function () use ($id, $tipo) {
            [$ruta, $nombre] = $this->matriculaService->archivo($id, $tipo, $this->propietarioId());

            $respuesta = response()->download($ruta, $nombre, [], 'inline');
            $respuesta->setPrivate();
            $respuesta->headers->addCacheControlDirective('no-store');

            return $respuesta;
        }, 'Error al obtener el archivo', 'Archivo no encontrado');
    }

    /** Tipo de ingreso, grados y especialidad que corresponden al estudiante en el año (D-M5). */
    public function opciones(Request $request, $estudianteId)
    {
        return $this->responder(function () use ($request, $estudianteId) {
            $request->validate(['anio_lectivo_id' => 'nullable|integer']);

            return response()->json($this->matriculaService->opciones($estudianteId, $request->anio_lectivo_id, $this->propietarioId()));
        }, 'Error al obtener las opciones de matrícula', 'Estudiante no encontrado');
    }

    public function buscarNie(Request $request)
    {
        return $this->responder(function () use ($request) {
            $request->validate(['nie' => 'required|string|max:20']);
            $estudiante = $this->matriculaService->buscarPorNie($request->nie, $this->propietarioId());

            if (! $estudiante) {
                return response()->json(['message' => 'El estudiante no pertenece a la institución.'], 404);
            }

            return response()->json(['estudiante' => $estudiante]);
        }, 'Error al buscar el estudiante por NIE');
    }

    public function estudianteInscrito()
    {
        return $this->responder(
            fn () => response()->json(['estudiantes' => $this->matriculaService->obtenerEstudiantesInscritos($this->propietarioId())]),
            'Error al obtener los estudiantes inscritos'
        );
    }

    public function padresEstudiante(string $estudianteId)
    {
        return $this->responder(
            fn () => response()->json(['padres' => $this->matriculaService->obtenerPadresEstudiante($estudianteId, $this->propietarioId())]),
            'Error al obtener los padres del estudiante',
            'Estudiante no encontrado'
        );
    }

    public function validarGrado(Request $request, $estudiante, $grado)
    {
        return $this->responder(function () use ($request, $estudiante, $grado) {
            $request->validate([
                'ingreso' => 'required|in:Nuevo ingreso,Reingreso',
                'anio_lectivo_id' => 'nullable|integer',
            ]);

            $resultado = $this->matriculaService->validarGrado($estudiante, $grado, $request->ingreso, $this->propietarioId(), $request->anio_lectivo_id);

            return response()->json([
                'permitido' => $resultado['permitido'],
                'grado_permitido' => $resultado['grado_permitido'] ?? null,
                'message' => $resultado['message'],
            ], $resultado['status'] ?? 200);
        }, 'Error al validar el grado', 'Estudiante no encontrado');
    }

    public function disponibilidadSeccion(Request $request)
    {
        return $this->responder(function () use ($request) {
            $request->validate([
                'grado_id' => 'required|exists:grados,id',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'nullable|integer',
            ]);

            return response()->json($this->matriculaService->verificarDisponibilidadSeccion($request->grado_id, $request->turno, $request->anio_lectivo_id));
        }, 'Error al verificar la disponibilidad de secciones');
    }

    public function seccionesDisponibles(Request $request)
    {
        return $this->responder(function () use ($request) {
            $request->validate([
                'grado_id' => 'required|exists:grados,id',
                'turno' => 'required|in:matutino,vespertino',
                'anio_lectivo_id' => 'nullable|integer',
            ]);

            return response()->json(['secciones' => $this->matriculaService->obtenerSeccionesDisponibles($request->grado_id, $request->turno, $request->anio_lectivo_id)]);
        }, 'Error al obtener las secciones disponibles');
    }

    public function duplicidadMatricula(Request $request)
    {
        return $this->responder(function () use ($request) {
            $request->validate([
                'estudiante_id' => 'required|exists:estudiantes,id',
                'anio_lectivo_id' => 'nullable|integer|exists:anios_lectivos,id',
            ]);

            $this->matriculaService->estudiantePropio($request->estudiante_id, $this->propietarioId());

            if ($this->matriculaService->verificarDuplicidad($request->estudiante_id, $request->anio_lectivo_id)) {
                return response()->json(['duplicidad' => true, 'message' => 'El estudiante ya tiene una matrícula registrada'], 422);
            }

            return response()->json(['duplicidad' => false, 'message' => 'El estudiante no tiene una matrícula registrada']);
        }, 'Error al verificar la matrícula', 'Estudiante no encontrado');
    }

    /** NIE del estudiante: solo números, hasta 10 dígitos, único (sin contar al propio estudiante). */
    private function reglaNie(int $estudianteId, bool $obligatorio): array
    {
        return [$obligatorio ? 'required' : 'sometimes', 'required', 'string', 'regex:/^[0-9]{1,10}$/',
            \Illuminate\Validation\Rule::unique('estudiantes', 'nie')->ignore($estudianteId)];
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
