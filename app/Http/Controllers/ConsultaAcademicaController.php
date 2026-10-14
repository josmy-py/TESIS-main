<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\ConsultaAcademicaService;
use App\Services\EstudianteService;
use Illuminate\Http\Request;

/**
 * M10 y M11: consultar notas y conducta de un estudiante (encargado: solo los
 * suyos; director: cualquiera), consultar expediente (director) y comprobante
 * de matrícula.
 */
class ConsultaAcademicaController extends Controller
{
    use RespondeErrores;

    public function __construct(private ConsultaAcademicaService $consulta, private EstudianteService $estudiantes)
    {
    }

    /** Consultar notas: Matricula.obtenerNotas() de la matrícula elegida. */
    public function notas(Request $request, string $estudianteId)
    {
        return $this->responder(function () use ($request, $estudianteId) {
            $datos = $this->matricula($request, $estudianteId);

            return response()->json($datos + ($datos['matricula'] ? $this->consulta->obtenerNotas($datos['matricula']) : ['periodos' => [], 'materias' => [], 'nota_final' => null]));
        }, 'Error al consultar las notas', 'Estudiante no encontrado');
    }

    public function asignaturas(Request $request, string $estudianteId)
    {
        return $this->responder(function () use ($request, $estudianteId) {
            $datos = $this->matricula($request, $estudianteId);

            return response()->json($datos + ($datos['matricula'] ? $this->consulta->obtenerAsignaturas($datos['matricula']) : ['asignaturas' => []]));
        }, 'Error al consultar las asignaturas', 'Estudiante no encontrado');
    }

    public function conducta(Request $request, string $estudianteId)
    {
        return $this->responder(function () use ($request, $estudianteId) {
            $datos = $this->matricula($request, $estudianteId);

            return response()->json($datos + ($datos['matricula'] ? $this->consulta->conducta($datos['matricula']) : ['periodos' => []]));
        }, 'Error al consultar la conducta', 'Estudiante no encontrado');
    }

    public function expediente(string $estudianteId)
    {
        return $this->responder(
            fn () => response()->json($this->consulta->expediente($estudianteId)),
            'Error al consultar el expediente',
            'Estudiante no encontrado'
        );
    }

    public function comprobante(string $matriculaId)
    {
        return $this->responder(
            fn () => response()->json($this->consulta->generarComprobante($matriculaId, $this->propietarioId())),
            'Error al generar el comprobante',
            'Matrícula no encontrada'
        );
    }

    private function matricula(Request $request, string $estudianteId): array
    {
        $datos = $request->validate(['matricula_id' => 'nullable|integer']);
        $estudiante = $this->estudiantes->propio($estudianteId, $this->propietarioId());

        return $this->consulta->matriculaDe($estudiante, $datos['matricula_id'] ?? null);
    }
}
