<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Informe de Profesor</title>
    <style>
        @page { margin: 30px 40px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 10.5px; color: #1f2937; }
        .titulo { background: #f3f4f6; border-radius: 6px; text-align: center; padding: 14px; font-size: 12px; font-weight: bold; color: #1f2937; margin: 0 0 18px; }
        .datos { width: 100%; border-collapse: collapse; margin-bottom: 8px; }
        .datos td { width: 50%; padding: 5px 0; font-size: 11px; vertical-align: top; }
        .datos b { color: #111827; }
        .valor { color: #2c4a7c; }
        h2 { font-size: 12px; font-weight: bold; color: #2c4a7c; margin: 20px 0 8px; }
        p { text-align: justify; line-height: 1.55; margin: 0; }
    </style>
</head>
<body>
    @include('encabezado_reporte')

    <div class="titulo">INFORME DE PROFESOR</div>

    <table class="datos">
        <tr>
            <td><b>Grado:</b> <span class="valor">{{ $grado }}</span></td>
            <td><b>Sección:</b> <span class="valor">{{ $seccion }}</span></td>
        </tr>
        <tr>
            <td><b>Profesor:</b> <span class="valor">{{ $profesor }}</span></td>
            <td><b>Fecha:</b> <span class="valor">{{ $fecha_formateada }}</span></td>
        </tr>
        <tr>
            <td><b>Período:</b> <span class="valor">{{ $periodo }}</span></td>
            <td></td>
        </tr>
    </table>

    <h2>ASUNTO</h2>
    <p>{{ $asunto }}</p>

    <h2>DESARROLLO DEL INFORME</h2>
    <p>{!! nl2br(e($desarrollo)) !!}</p>

    <h2>OBSERVACIONES</h2>
    <p>{!! $observaciones !== '' ? nl2br(e($observaciones)) : '—' !!}</p>

    <table style="width: 100%; margin-top: 55px; border-collapse: collapse;">
        <tr>
            <td style="width: 50%; vertical-align: top;">@include('firma_persona', ['nombreFirma' => $profesor, 'cargoFirma' => 'Profesor(a)'])</td>
            <td style="width: 50%; vertical-align: top;">@include('firma_director', ['margen' => 0, 'corta' => true])</td>
        </tr>
    </table>
</body>
</html>
