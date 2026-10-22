<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Libreta de Notas</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; font-weight: bold; color: #374151; margin: 0 0 16px; }
        .datos { width: 100%; border-collapse: collapse; margin-bottom: 10px; }
        .datos td { width: 50%; padding: 6px 0; font-size: 11px; vertical-align: top; }
        .datos b, .caja b { color: #111827; }
        .valor { color: #4b5563; font-weight: bold; }
        .caja { background: #f3f4f6; padding: 10px 14px; margin-top: 10px; }
        .caja.conducta { background: #eef6f9; }
        .caja h3 { font-size: 12px; color: #374151; margin: 0 0 8px; }
        .caja div { font-size: 10.5px; margin-bottom: 6px; }
        .caja p { color: #4b5563; font-weight: bold; text-align: justify; line-height: 1.5; margin: 0; }
        h2 { font-size: 12px; font-weight: bold; color: #374151; margin: 22px 0 8px; }
        .notas { width: 100%; border-collapse: separate; border-spacing: 0 4px; }
        .notas th { background: #f3f4f6; text-align: left; font-size: 10.5px; color: #111827; padding: 8px 10px; }
        .notas td { font-size: 11px; font-weight: bold; color: #4b5563; padding: 8px 10px; }
        .notas .nota { width: 22%; text-align: center; }
        .notas tr.promedio td { background: #f3f4f6; color: #111827; }
        p.obs { text-align: justify; line-height: 1.5; margin: 0; }
    </style>
</head>
<body>
    @php
        // Notas con uno o dos decimales, como en el diseño: 9.0, 8.3, 8.72
        $nota = fn ($v) => $v === null ? '—' : preg_replace('/(\.\d)0$/', '$1', number_format((float) $v, 2));
    @endphp

    @include('encabezado_reporte')

    <div class="titulo">REPORTE DE LIBRETA DE NOTAS</div>

    <table class="datos">
        <tr>
            <td><b>Fecha:</b> <span class="valor">{{ $fecha_formateada }}</span></td>
            <td><b>Período:</b> <span class="valor">{{ $periodo }}</span></td>
        </tr>
        <tr>
            <td><b>Grado:</b> <span class="valor">{{ $grado }}</span></td>
            <td><b>Sección:</b> <span class="valor">{{ $seccion }}</span></td>
        </tr>
        <tr>
            <td colspan="2"><b>Profesor:</b> <span class="valor">{{ $profesor ?? '—' }}</span></td>
        </tr>
    </table>

    <div class="caja">
        <h3>INFORMACIÓN DEL ESTUDIANTE</h3>
        <div><b>Nombre:</b> <span class="valor">{{ $estudiante }}</span></div>
    </div>

    <div class="caja conducta">
        <h3>CONDUCTA DEL ESTUDIANTE</h3>
        <div><b>Conducta:</b> <span class="valor">{{ $conducta !== '' ? $conducta : '—' }}</span></div>
        @if ($conducta_descripcion !== '')
            <p>{{ $conducta_descripcion }}</p>
        @endif
    </div>

    <h2>DETALLE DE CALIFICACIONES</h2>
    <table class="notas">
        <tr><th>Materias</th><th class="nota">Nota</th></tr>
        @foreach ($materias as $materia)
            <tr><td>{{ $materia['nombre'] }}</td><td class="nota">{{ $nota($materia['promedio']) }}</td></tr>
        @endforeach
        <tr class="promedio"><td>Promedio general</td><td class="nota">{{ $nota($promedio_general) }}</td></tr>
    </table>

    @if ($observaciones !== '')
        <h2>OBSERVACIONES DEL DOCENTE</h2>
        <p class="obs">{!! nl2br(e($observaciones)) !!}</p>
    @endif

    @include('firma_director', ['margen' => 60])
</body>
</html>
