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
        .tipo { margin: 20px 0 4px; font-size: 12px; color: #374151; }
        .tipo span { color: #111827; }
        h2 { font-size: 12px; font-weight: normal; color: #374151; margin: 22px 0 8px; }
        p { text-align: justify; line-height: 1.55; margin: 0; font-size: 10.5px; color: #374151; }
        .acciones { width: 100%; border-collapse: collapse; }
        .acciones td { padding: 3px 0; font-size: 10.5px; color: #374151; vertical-align: top; }
        .acciones td.check { width: 18px; color: #9ca3af; font-size: 11px; }
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

    <div class="tipo">TIPO DE CONDUCTA: <span>{{ $tipo_conducta }}</span></div>

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

    <table style="width: 100%; margin-top: 50px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top;">@include('firma_persona', ['nombreFirma' => $profesor, 'cargoFirma' => 'Docente Responsable'])</td>
            <td style="width: 50%; vertical-align: top;">@include('firma_persona', ['nombreFirma' => '', 'cargoFirma' => 'Padre / Madre o Responsable'])</td>
        </tr>
    </table>
    @include('firma_director', ['margen' => 40])
</body>
</html>
