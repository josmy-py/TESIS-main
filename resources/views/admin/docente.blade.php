<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte del Personal Docente</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 12px; }
        h2 { font-size: 13px; font-weight: bold; color: #1f2937; margin: 20px 0 8px; }
        .tres { width: 100%; border-collapse: collapse; }
        .tres td { width: 33%; vertical-align: top; padding: 2px 0; }
        .etiqueta { font-size: 9.5px; color: #6b7280; }
        .valor { font-size: 11px; font-weight: bold; color: #111827; padding-top: 6px; }
        .correo { font-size: 9px; font-weight: normal; color: #6b7280; padding-top: 2px; }
        .tabla { width: 100%; border-collapse: collapse; }
        .tabla th { background: #f3f4f6; text-align: left; font-size: 10.5px; padding: 7px 8px; }
        .tabla td { padding: 6px 8px; font-size: 10.5px; color: #374151; }
        .tabla .res { width: 22%; }
        .dos { border-collapse: collapse; }
        .dos td { padding: 6px 8px; font-size: 10.5px; color: #374151; }
        .dos td.etq { width: 260px; }
        p { text-align: justify; line-height: 1.5; margin: 8px 0 0; font-size: 10.5px; color: #374151; }
        ul { margin: 0; padding-left: 18px; line-height: 1.7; color: #374151; }
        .firmas { width: 100%; margin-top: 50px; border-collapse: collapse; }
        .firmas td { width: 50%; text-align: center; font-size: 10.5px; }
        .linea { display: inline-block; width: 150px; border-top: 1px solid #374151; margin-bottom: 6px; }
    </style>
</head>
<body>
    @php
        $numero = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
    @endphp

    @include('encabezado_reporte')

    <div class="titulo">REPORTE DEL PERSONAL DOCENTE</div>

    <h2>IDENTIFICACIÓN</h2>
    <table class="tres">
        <tr><td class="etiqueta">{{ $cargo }}</td><td class="etiqueta">Período</td><td class="etiqueta">Fecha</td></tr>
        <tr>
            <td class="valor">{{ $director }}<div class="correo">{{ $correo_usuario }}</div></td>
            <td class="valor">{{ $periodo }}</td>
            <td class="valor">{{ $fecha_formateada }}</td>
        </tr>
    </table>

    <h2>ESTADÍSTICAS DOCENTES</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th class="res">Resultado</th></tr>
        <tr><td>Total de Docentes</td><td>{{ $total_docentes }}</td></tr>
        <tr><td>Docentes Activos</td><td>{{ $docentes_activos }}</td></tr>
        <tr><td>Docentes Ausentes</td><td>{{ $docentes_ausentes }}</td></tr>
    </table>

    <h2>CONTROL DE ASISTENCIA</h2>
    <table class="dos">
        <tr><td class="etq">Asistencia Promedio</td><td>{{ $asistencia_promedio === null ? '—' : $numero($asistencia_promedio).'%' }}</td></tr>
        <tr><td class="etq">Incapacidades</td><td>{{ $docentes_incapacitados }}</td></tr>
    </table>

    <h2>DESEMPEÑO DOCENTE</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th class="res">Resultado</th></tr>
        <tr><td>Docentes Evaluados</td><td>{{ $docentes_evaluados }}</td></tr>
        <tr><td>Promedio de Evaluación</td><td>{{ $numero($promedio_evaluacion) }}</td></tr>
    </table>
    @if ($descripcion_general !== '')
        <p>{!! nl2br(e($descripcion_general)) !!}</p>
    @endif

    <h2>CAPACITACIONES</h2>
    @if ($capacitaciones_realizadas !== null || $docentes_participantes !== null)
        <table class="dos">
            <tr><td class="etq">Capacitaciones Realizadas</td><td>{{ $capacitaciones_realizadas ?? '—' }}</td></tr>
            <tr><td class="etq">Docentes Participantes</td><td>{{ $docentes_participantes ?? '—' }}</td></tr>
        </table>
    @endif
    <p>{!! $detalles_capacitaciones !== '' ? nl2br(e($detalles_capacitaciones)) : '—' !!}</p>

    <h2>RECONOCIMIENTOS</h2>
    @if (count($reconocimientos))
        <ul>@foreach ($reconocimientos as $linea)<li>{{ $linea }}</li>@endforeach</ul>
    @else
        <p>—</p>
    @endif

    <h2>OBSERVACIONES GENERALES</h2>
    <p>{!! $observaciones !== '' ? nl2br(e($observaciones)) : '—' !!}</p>

    <table class="firmas">
        <tr>
            <td><span class="linea"></span><br>{{ $cargo }} Institucional</td>
            <td><span class="linea"></span><br>Sello Institucional</td>
        </tr>
    </table>
</body>
</html>
