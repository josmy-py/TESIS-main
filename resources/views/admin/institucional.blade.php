<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Institucional</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 12px; }
        h2 { font-size: 13px; font-weight: normal; color: #1f2937; margin: 16px 0 6px; page-break-after: avoid; }
        .tres { width: 100%; border-collapse: collapse; }
        .tres td { width: 33%; vertical-align: top; padding: 2px 0; }
        .etiqueta { font-size: 8.5px; color: #6b7280; }
        .valor { font-size: 11px; color: #111827; padding-top: 6px; }
        .tabla { width: 100%; border-collapse: collapse; }
        .tabla th { background: #f3f4f6; text-align: left; font-size: 9.5px; padding: 6px 8px; }
        .tabla td { padding: 5px 8px; font-size: 10px; }
        .tabla .res { width: 40%; }
        p { text-align: justify; line-height: 1.5; margin: 6px 0 0; }
        ul { margin: 6px 0 0; padding-left: 18px; line-height: 1.6; }
        .final { page-break-inside: avoid; }
        .firmas { width: 100%; margin-top: 50px; border-collapse: collapse; }
        .firmas td { width: 50%; text-align: center; font-size: 10.5px; }
        .linea { display: inline-block; width: 150px; border-top: 1px solid #374151; margin-bottom: 6px; }
    </style>
</head>
<body>
    @php
        $nota = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $valor = fn ($v) => $v === null || $v === '' ? '—' : $v;
        $texto = fn (string $t) => $t !== '' ? nl2br(e($t)) : '';
    @endphp

    @include('encabezado_reporte')

    <div class="titulo">INFORME INSTITUCIONAL</div>

    <h2>IDENTIFICACIÓN</h2>
    <table class="tres">
        <tr><td class="etiqueta">Nivel Académico</td><td class="etiqueta">Período</td><td class="etiqueta">Fecha</td></tr>
        <tr><td class="valor">{{ $nivel }}</td><td class="valor">{{ $periodo }}</td><td class="valor">{{ $fecha_formateada }}</td></tr>
    </table>

    <h2>RESPONSABLE</h2>
    <table class="tres">
        <tr><td class="etiqueta">Nombre</td><td class="etiqueta">Cargo</td><td class="etiqueta">Correo</td></tr>
        <tr><td class="valor">{{ $nombre }}</td><td class="valor">{{ $cargo }}</td><td class="valor">{{ $correo }}</td></tr>
    </table>

    <h2>RENDIMIENTO ACADÉMICO</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th class="res">Resultado</th></tr>
        <tr><td>Total de Estudiantes</td><td>{{ $total_estudiantes }}</td></tr>
        <tr><td>Promedio General</td><td>{{ $nota($promedio_general) }}</td></tr>
        <tr><td>Estudiantes Destacados</td><td>{{ $estudiantes_destacados }}</td></tr>
    </table>
    @if ($descripcion_calificaciones !== '')<p>{!! $texto($descripcion_calificaciones) !!}</p>@endif

    <h2>CONDUCTA Y DISCIPLINA</h2>
    <table class="tabla">
        <tr><th>Registro</th><th class="res">Cantidad</th></tr>
        <tr><td>Incidentes</td><td>{{ $valor($incidentes) }}</td></tr>
        <tr><td>Reportes Leves</td><td>{{ $valor($reportes_leves) }}</td></tr>
        <tr><td>Reportes Graves</td><td>{{ $valor($reportes_graves) }}</td></tr>
        <tr><td>Medidas Aplicadas</td><td>{{ $valor($medidas_aplicadas) }}</td></tr>
    </table>
    @if ($descripcion_casos !== '')<p>{!! $texto($descripcion_casos) !!}</p>@endif

    <h2>ACTIVIDADES INSTITUCIONALES</h2>
    <table class="tabla">
        <tr><th>Registro</th><th class="res">Cantidad</th></tr>
        <tr><td>Total de Actividades</td><td>{{ $total_actividades }}</td></tr>
        <tr><td>Total de Proyectos</td><td>{{ $total_proyectos }}</td></tr>
    </table>
    @if (count($actividades))
        <ul>@foreach ($actividades as $actividad)<li>{{ $actividad }}</li>@endforeach</ul>
    @endif
    @if ($descripcion_actividades !== '')<p>{!! $texto($descripcion_actividades) !!}</p>@endif

    <h2>CONTROL ESCOLAR</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th class="res">Resultado</th></tr>
        <tr><td>Matrícula Total</td><td>{{ $total_estudiantes }}</td></tr>
        <tr><td>Nuevos Ingresos</td><td>{{ $nuevos_ingresos }}</td></tr>
        <tr><td>Estudiantes Reprobados</td><td>{{ $estudiantes_reprobados }}</td></tr>
    </table>

    <h2>RECURSOS HUMANOS</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th class="res">Resultado</th></tr>
        <tr><td>Total de Docentes</td><td>{{ $total_docentes }}</td></tr>
        <tr><td>Docentes Ausentes</td><td>{{ $docentes_ausentes }}</td></tr>
    </table>

    {{-- Observaciones y firmas no se separan entre páginas --}}
    <div class="final">
        <h2>OBSERVACIONES GENERALES</h2>
        <p>{!! $observaciones !== '' ? $texto($observaciones) : '—' !!}</p>

        <table class="firmas">
            <tr>
                <td><span class="linea"></span><br>{{ $cargo }} Institucional</td>
                <td><span class="linea"></span><br>Sello Institucional</td>
            </tr>
        </table>
    </div>
</body>
</html>
