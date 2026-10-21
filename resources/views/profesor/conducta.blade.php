<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Reporte de Conducta</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; color: #1f2937; margin: 0 0 12px; }
        .caja { background: #f3f4f6; border-radius: 6px; padding: 12px 14px; }
        .caja .encabezado-caja { font-size: 10px; color: #1f2937; margin-bottom: 8px; }
        .datos { width: 100%; border-collapse: collapse; }
        .datos td { width: 50%; padding: 6px 0; font-size: 10px; color: #4b5563; vertical-align: top; }
        .datos b { color: #111827; }
        .tipo { margin: 18px 0 4px; border-collapse: collapse; }
        .tipo td { vertical-align: middle; padding: 0; }
        .tipo td.etq { font-size: 12px; color: #374151; padding-right: 8px; }
        .tipo td.valor { border: 1px solid #d1d5db; border-radius: 4px; padding: 5px 10px; font-size: 12px; color: #111827; }
        h2 { font-size: 12px; font-weight: normal; color: #374151; margin: 22px 0 8px; }
        p { text-align: justify; line-height: 1.55; margin: 0; font-size: 10.5px; color: #374151; }
        .acciones { width: 100%; border-collapse: collapse; }
        .acciones td { padding: 3px 0; font-size: 10.5px; color: #374151; vertical-align: top; }
        .acciones td.check { width: 18px; color: #9ca3af; font-size: 11px; }
        .firmas { width: 100%; margin-top: 55px; border-collapse: collapse; }
        .firmas td { width: 33%; text-align: center; font-size: 10px; color: #1f2937; }
        .linea { display: inline-block; width: 140px; border-top: 1px solid #374151; margin-bottom: 6px; }
    </style>
</head>
<body>
    @include('encabezado_reporte')

    <div class="titulo">REPORTE DE CONDUCTA</div>

    <div class="caja">
        <div class="encabezado-caja">INFORMACIÓN DEL ESTUDIANTE</div>
        <table class="datos">
            <tr>
                <td><b>Nombre:</b> {{ $estudiante }}</td>
                <td><b>Período:</b> {{ $periodo }}@if ($anio) ({{ $anio }})@endif</td>
            </tr>
            <tr>
                <td><b>Fecha:</b> {{ $fecha_formateada }}</td>
                <td><b>Profesor:</b> {{ $profesor ?? '—' }}</td>
            </tr>
            <tr>
                <td><b>Grado:</b> {{ $grado }}</td>
                <td><b>Sección:</b> {{ $seccion }}</td>
            </tr>
        </table>
    </div>

    <table class="tipo"><tr><td class="etq">TIPO DE CONDUCTA</td><td class="valor">{{ $tipo_conducta }}</td></tr></table>

    <h2>DESCRIPCIÓN DEL COMPORTAMIENTO OBSERVADO</h2>
    <p>{!! nl2br(e($descripcion_hechos)) !!}</p>

    <h2>ACCIONES TOMADAS</h2>
    <table class="acciones">
        @foreach ($acciones_tomadas as $accion)
            <tr><td class="check">☑</td><td>{{ $accion }}</td></tr>
        @endforeach
    </table>

    <h2>COMPROMISOS DE MEJORA</h2>
    <p>{!! nl2br(e($compromiso_mejora)) !!}</p>

    <table class="firmas">
        <tr>
            <td><span class="linea"></span><br>Docente Responsable</td>
            <td><span class="linea"></span><br>Padre / Madre o Responsable</td>
            <td><span class="linea"></span><br>Director(a)</td>
        </tr>
    </table>
</body>
</html>
