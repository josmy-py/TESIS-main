<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Académico Institucional</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 16px; }
        h2 { font-size: 13px; font-weight: normal; color: #1f2937; margin: 22px 0 8px; }
        .tres { width: 100%; border-collapse: collapse; }
        .tres td { width: 33%; vertical-align: top; padding: 2px 0; }
        .etiqueta { font-size: 8.5px; color: #6b7280; }
        .valor { font-size: 11px; color: #111827; padding-top: 6px; }
        .tabla { width: 100%; border-collapse: collapse; }
        .tabla th { background: #f3f4f6; text-align: left; font-size: 9.5px; padding: 6px 8px; }
        .tabla td { padding: 6px 8px; font-size: 10px; }
        .tabla td.etq { width: 55%; font-weight: bold; font-size: 9.5px; }
        p { text-align: justify; line-height: 1.5; margin: 0; }
        ul { margin: 0; padding-left: 18px; line-height: 1.6; }
    </style>
</head>
<body>
    @php
        $nota = fn ($v) => $v === null ? '—' : rtrim(rtrim(number_format((float) $v, 2), '0'), '.');
        $porcentaje = fn ($v) => $v === null ? '—' : $nota($v).'%';
    @endphp

    @include('encabezado_reporte')

    <div class="titulo">INFORME ACADÉMICO INSTITUCIONAL</div>

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

    <h2>INDICADORES ACADÉMICOS</h2>
    <table class="tabla">
        <tr><th>Indicador</th><th>Resultado</th></tr>
        <tr><td>Total de Estudiantes</td><td>{{ $total_estudiantes }}</td></tr>
        <tr><td>Promedio General</td><td>{{ $nota($promedio_general) }}</td></tr>
        <tr><td>% Aprobación</td><td>{{ $porcentaje($porcentaje_aprobacion) }}</td></tr>
        <tr><td>% Reprobación</td><td>{{ $porcentaje($porcentaje_reprobacion) }}</td></tr>
    </table>

    <h2>RENDIMIENTO ACADÉMICO</h2>
    <p>{{ $rendimiento_academico !== '' ? $rendimiento_academico : '—' }}</p>

    <h2>MATERIAS CON MAYOR Y MENOR RENDIMIENTO</h2>
    <table class="tabla">
        <tr><td class="etq">Mayor Rendimiento</td><td>{{ $materia_mayor ?? '—' }}</td></tr>
        <tr><td class="etq">Menor Rendimiento</td><td>{{ $materia_menor ?? '—' }}</td></tr>
    </table>

    <h2>ESTUDIANTES DESTACADOS</h2>
    @if (count($estudiantes_destacados))
        <ul>@foreach ($estudiantes_destacados as $linea)<li>{{ $linea }}</li>@endforeach</ul>
    @else
        <p>—</p>
    @endif

    <h2>RECOMENDACIONES ACADÉMICAS</h2>
    @if (count($recomendaciones))
        <ul>@foreach ($recomendaciones as $linea)<li>{{ $linea }}</li>@endforeach</ul>
    @else
        <p>—</p>
    @endif

    <h2>OBSERVACIONES GENERALES</h2>
    <p>{{ $observaciones !== '' ? $observaciones : '—' }}</p>

    @include('firma_director', ['nombreDirector' => $nombre, 'margen' => 45])
</body>
</html>
