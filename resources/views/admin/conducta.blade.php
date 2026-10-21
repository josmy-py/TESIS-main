<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe Institucional de Conducta y Convivencia Escolar</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 12px; }
        h2 { font-size: 13px; font-weight: bold; color: #1f2937; margin: 14px 0 6px; page-break-after: avoid; }
        .tres { width: 100%; border-collapse: collapse; }
        .tres td { width: 33%; vertical-align: top; padding: 2px 0; }
        .etiqueta { font-size: 9.5px; color: #6b7280; }
        .valor { font-size: 11px; font-weight: bold; color: #111827; padding-top: 6px; }
        .tabla { width: 100%; border-collapse: collapse; }
        .tabla th { background: #f3f4f6; text-align: left; font-size: 10.5px; padding: 6px 8px; }
        .tabla td { padding: 4px 8px; font-size: 10.5px; color: #374151; }
        .tabla .res { width: 42%; }
        p { text-align: justify; line-height: 1.5; margin: 8px 0 0; font-size: 10.5px; color: #374151; }
        ul { margin: 0; padding-left: 18px; line-height: 1.6; color: #374151; }
        .final { page-break-inside: avoid; }
        .firmas { width: 100%; margin-top: 45px; border-collapse: collapse; }
        .firmas td { width: 50%; text-align: center; font-size: 10.5px; }
        .linea { display: inline-block; width: 150px; border-top: 1px solid #374151; margin-bottom: 6px; }
    </style>
</head>
<body>
    @include('encabezado_reporte')

    <div class="titulo">INFORME INSTITUCIONAL DE CONDUCTA Y CONVIVENCIA ESCOLAR</div>

    <h2>INFORMACIÓN GENERAL</h2>
    <table class="tres">
        <tr><td class="etiqueta">Fecha</td><td class="etiqueta">Período</td><td class="etiqueta">Director Responsable</td></tr>
        <tr>
            <td class="valor">{{ $fecha_formateada }}</td>
            <td class="valor">{{ $periodo }}</td>
            <td class="valor">{{ $director }}</td>
        </tr>
    </table>

    <h2>RESUMEN GENERAL</h2>
    <table class="tabla">
        <tr><th>Registro</th><th class="res">Cantidad</th></tr>
        <tr><td>Total de Estudiantes</td><td>{{ $total_estudiantes }}</td></tr>
        <tr><td>Incidentes Registrados</td><td>{{ $incidentes_registrados }}</td></tr>
    </table>

    <h2>CLASIFICACIÓN DE REPORTES</h2>
    <table class="tabla">
        <tr><th>Nivel</th><th class="res">Cantidad</th></tr>
        <tr><td>Leves</td><td>{{ $leves }}</td></tr>
        <tr><td>Moderados</td><td>{{ $moderados }}</td></tr>
        <tr><td>Graves</td><td>{{ $graves }}</td></tr>
    </table>

    <h2>INCIDENTES REGISTRADOS</h2>
    <p>{!! $incidentes !== '' ? nl2br(e($incidentes)) : '—' !!}</p>

    <h2>MEDIDAS DISCIPLINARIAS</h2>
    @if (count($medidas))
        <ul>@foreach ($medidas as $medida)<li>{{ $medida }}</li>@endforeach</ul>
    @else
        <p>—</p>
    @endif

    <h2>OBSERVACIONES GENERALES</h2>
    <p>{!! $observaciones !== '' ? nl2br(e($observaciones)) : '—' !!}</p>

    {{-- Recomendaciones y firmas no se separan entre páginas --}}
    <div class="final">
    <h2>RECOMENDACIONES</h2>
    @if (count($recomendaciones))
        <ul>@foreach ($recomendaciones as $linea)<li>{{ $linea }}</li>@endforeach</ul>
    @else
        <p>—</p>
    @endif

    <table class="firmas">
        <tr>
            <td><span class="linea"></span><br>Director</td>
            <td><span class="linea"></span><br>Sello Institucional</td>
        </tr>
    </table>
    </div>
</body>
</html>
