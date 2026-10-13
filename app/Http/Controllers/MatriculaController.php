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
        'seccion_id.required' => 'Seleccione la sección (grado y turno).',
        'foto.required' => 'La foto es obligatoria.',
        'foto.image' => 'La foto debe ser una imagen JPEG o PNG.',
        'documento.required_if' => 'La partida de nacimiento es obligatoria para Nuevo ingreso.',
        'documento.mimes' => 'El documento debe ser PDF, JPEG, PNG o JPG.',
        'es_trasladado.required' => 'Debe indicar si el estudiante es trasladado.',
        'es_trasladado.boolean' => 'El campo es trasladado debe ser verdadero o falso.',
        'discapacidades.*.exists' => 'Una de las discapacidades no existe.',
        'discapacidades.*.distinct' => 'Hay discapacidades repetidas.',
        'motivo.required' => 'El motivo del rechazo es obligatorio.',
        'motivo.min' => 'Escriba un motivo de al menos 5 caracteres.',
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
                'seccion_id' => 'required|integer|exists:secciones,id',
                'anio_lectivo_id' => 'nullable|integer',
                'especialidad_id' => 'nullable|integer|exists:especialidades,id',
                'encargado_id' => 'nullable|integer|exists:encargados,id',
                'enfermedad_id' => 'nullable|integer|exists:enfermedades,id',
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
            $datos = $request->validate([
                'seccion_id' => 'sometimes|required|integer|exists:secciones,id',
                'especialidad_id' => 'sometimes|nullable|integer|exists:especialidades,id',
                'encargado_id' => 'sometimes|nullable|integer|exists:encargados,id',
                'enfermedad_id' => 'sometimes|nullable|integer|exists:enfermedades,id',
                'discapacidades' => 'sometimes|nullable|array',
                'discapacidades.*' => 'integer|distinct|exists:discapacidades,id',
                'es_trasladado' => 'sometimes|required|boolean',
                'foto' => 'sometimes|image|mimes:jpeg,png,jpg|max:2048',
                'documento' => 'sometimes|file|mimes:pdf,jpeg,png,jpg|max:2048',
            ], self::MENSAJES);

            $propietarioId = $this->propietarioId();
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

    /** Solo DIRECTOR (págs. 63-64): aprobar (con sección opcional si falta) o rechazar con motivo. */
    public function estadoMatricula(Request $request, $id)
    {
        return $this->responder(function () use ($request, $id) {
            $datos = $request->validate([
                'estado' => 'required|in:aprobado,rechazado',
                'seccion_id' => 'nullable|integer|exists:secciones,id',
                'motivo' => 'required_if:estado,rechazado|nullable|string|min:5|max:500',
            ], self::MENSAJES + [
                'estado.in' => 'Solo se puede aprobar o rechazar una matrícula.',
                'motivo.required_if' => 'El motivo del rechazo es obligatorio.',
            ]);

            if ($datos['estado'] === 'aprobado') {
                $matricula = $this->matriculaService->aprobar($id, $datos['seccion_id'] ?? null, $request->user());
                $mensaje = 'Matrícula aprobada. Se notificó al encargado.';
            } else {
                $matricula = $this->matriculaService->rechazar($id, trim($datos['motivo']), $request->user());
                $mensaje = 'Matrícula rechazada. Se notificó el motivo al encargado.';
            }

            return response()->json(['message' => $mensaje, 'matricula' => $matricula]);
        }, 'Error al actualizar el estado de la matrícula', 'Matrícula no encontrada');
    }

    /** Solo DIRECTOR: cambia la sección (mismo grado, con cupo) de una pendiente o aprobada. */
    public function cambiarSeccion(Request $request, $id)
    {
        return $this->responder(function () use ($request, $id) {
            $datos = $request->validate(['seccion_id' => 'required|integer|exists:secciones,id'], self::MENSAJES);
            $matricula = $this->matriculaService->cambiarSeccion($id, (int) $datos['seccion_id'], $request->user());

            return response()->json(['message' => 'Sección actualizada.', 'matricula' => $matricula]);
        }, 'Error al cambiar la sección', 'Matrícula no encontrada');
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
