<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Rendimiento Académico</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 16px; }
        .datos { width: 100%; border-collapse: collapse; }
        .datos td { width: 50%; padding: 4px 0; font-size: 11px; vertical-align: top; }
        .datos b { color: #111827; }
        h2 { font-size: 13px; font-weight: normal; color: #374151; margin: 26px 0 8px; }
        .tabla { width: 100%; border-collapse: separate; border-spacing: 2px; }
        .tabla th { background: #f3f4f6; text-align: left; font-size: 10.5px; color: #111827; padding: 7px 8px; }
        .tabla td { font-size: 10.5px; padding: 7px 8px; }
        .tabla .prom { width: 40%; text-align: center; }
        .tabla td.prom { font-weight: bold; }
        .nota { font-size: 8.5px; color: #6b7280; margin: 6px 0 0; }
        p.texto { font-size: 11px; text-align: justify; line-height: 1.6; margin: 0; }
        .firmas { width: 100%; margin-top: 60px; border-collapse: collapse; }
        .firmas td { width: 50%; text-align: center; font-size: 11px; }
        .linea { display: inline-block; width: 160px; border-top: 1px solid #374151; margin-bottom: 6px; }
    </style>
</head>
<body>
    @php
        // Notas con uno o dos decimales, como en el diseño: 9.0, 8.72
        $nota = fn ($v) => $v === null ? '—' : preg_replace('/(\.\d)0$/', '$1', number_format((float) $v, 2));
    @endphp

    @include('encabezado_reporte')

    <div class="titulo">REPORTE DE RENDIMIENTO ACADÉMICO</div>

    <table class="datos">
        <tr>
            <td><b>Grado:</b> {{ $grado }}</td>
            <td><b>Sección:</b> {{ $seccion }}</td>
        </tr>
        <tr>
            <td><b>Profesor:</b> {{ $profesor }}</td>
            <td><b>Fecha:</b> {{ $fecha_formateada }}</td>
        </tr>
    </table>

    <h2>RESUMEN ACADÉMICO</h2>
    <table class="datos">
        <tr>
            <td><b>Total de estudiantes:</b> {{ $total_estudiantes }}</td>
            <td><b>Promedio general:</b> {{ $nota($promedio_general) }}</td>
        </tr>
        <tr>
            <td><b>Materia:</b> {{ $materia }}</td>
            <td><b>Período:</b> {{ $periodo }}</td>
        </tr>
    </table>

    <h2>ESTUDIANTES DESTACADOS (PROMEDIO ≥ {{ $nota($nota_destacado) }})</h2>
    <table class="tabla">
        <tr><th>Alumno</th><th class="prom">Promedio</th></tr>
        @forelse ($destacados as $alumno)
            <tr><td>{{ $alumno['nombre'] }}</td><td class="prom">{{ $nota($alumno['promedio']) }}</td></tr>
        @empty
            <tr><td colspan="2">Ningún estudiante alcanza el promedio.</td></tr>
        @endforelse
    </table>
    <p class="nota">Se consideran estudiantes destacados aquellos que obtienen una calificación igual o superior a {{ $nota($nota_destacado) }}.</p>

    <h2>OBSERVACIONES</h2>
    <p class="texto">{!! $observaciones !== '' ? nl2br(e($observaciones)) : '—' !!}</p>

    <table class="firmas">
        <tr>
            <td><span class="linea"></span><br>Docente</td>
            <td><span class="linea"></span><br>Director(a)</td>
        </tr>
    </table>
</body>
</html>
