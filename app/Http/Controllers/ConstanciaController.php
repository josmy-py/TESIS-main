<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\RespondeErrores;
use App\Services\Admin\ConstanciasPdfService;
use Illuminate\Http\Request;

/**
 * Portal director > Constancias de Egresado y de Buena Conducta. No se guarda
 * nada en la base de datos.
 */
class ConstanciaController extends Controller
{
    use RespondeErrores;

    private const NO_ENCONTRADO = 'No hay ningún estudiante registrado con ese NIE.';

    public function __construct(private ConstanciasPdfService $constancias)
    {
    }

    // Constancia de Egresado: datos del estudiante por NIE para llenar el formulario
    public function egresado(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate(['nie' => 'required|string|max:10'], $this->mensajes());

            return response()->json($this->constancias->egresado($datos['nie']));
        }, 'Error al buscar el estudiante', self::NO_ENCONTRADO);
    }

    // Constancia de Egresado: PDF con dompdf (vuelve a validar que el estudiante terminó)
    public function egresadoPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'nie' => 'required|string|max:10',
                'director' => 'required|string|max:100',
            ], [
                ...$this->mensajes(),
                'director.required' => 'Ingrese el nombre del director(a).',
            ]);

            return $this->constancias->generarConstanciaEgresado($datos)->stream('constancia_egresado.pdf');
        }, 'Error al generar la constancia de egresado', self::NO_ENCONTRADO);
    }

    // Constancia de Buena Conducta: nombre y años lectivos del estudiante por NIE
    public function conducta(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate(['nie' => 'required|string|max:10'], $this->mensajes());

            return response()->json($this->constancias->conducta($datos['nie']));
        }, 'Error al buscar el estudiante', self::NO_ENCONTRADO);
    }

    // Constancia de Buena Conducta: PDF con dompdf
    public function conductaPdf(Request $request)
    {
        return $this->responder(function () use ($request) {
            $datos = $request->validate([
                'nie' => 'required|string|max:10',
                'anio_lectivo' => 'required|digits:4',
                'director' => 'required|string|max:100',
            ], [
                ...$this->mensajes(),
                'anio_lectivo.required' => 'Seleccione el año lectivo.',
                'director.required' => 'Ingrese el nombre del director(a).',
            ]);

            return $this->constancias->generarConstanciaConducta($datos)->stream('constancia_conducta.pdf');
        }, 'Error al generar la constancia de conducta', self::NO_ENCONTRADO);
    }

    private function mensajes(): array
    {
        return [
            'nie.required' => 'Ingrese el NIE del estudiante.',
            'nie.max' => 'El NIE no puede tener más de 10 caracteres.',
        ];
    }
}
