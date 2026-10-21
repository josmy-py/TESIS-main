<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia de Egresado</title>
    <style>
        @page { margin: 50px 70px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; color: #1f2937; }
        .titulo { text-align: center; color: #0b2c5a; font-size: 22px; font-weight: bold; margin: 26px 0 6px; }
        .subtitulo { text-align: center; color: #4b5563; font-size: 13px; font-weight: bold; margin: 0 0 30px; }
        p { text-align: justify; line-height: 1.65; margin: 0 0 16px; }
        .resaltado { color: #0b2c5a; font-weight: bold; }
        .constar { text-align: center; color: #0b2c5a; font-size: 16px; font-weight: bold; margin: 26px 0 18px; }
        .fecha { text-align: right; margin: 6px 0 0; }
        .firma { margin-top: 45px; text-align: center; page-break-inside: avoid; }
        .linea { color: #0b2c5a; }
        .director { color: #0b2c5a; font-weight: bold; font-size: 13px; margin-top: 6px; }
        .cargo { font-size: 12px; margin-top: 4px; }
        .sello { color: #4b5563; font-weight: bold; font-size: 11px; margin-top: 6px; }
    </style>
</head>
<body>
    @include('encabezado_reporte')

    <div class="titulo">CONSTANCIA DE EGRESADO</div>
    <div class="subtitulo">BACHILLERATO GENERAL / TÉCNICO VOCACIONAL</div>

    <p>
        El suscrito Director(a) del <span class="resaltado">COMPLEJO EDUCATIVO HACIENDA COLIMA</span>, ubicado en el
        Cantón El Tule, República de El Salvador, en cumplimiento con la Ley General de Educación
        (Decreto Legislativo N.º&nbsp;917) y la normativa académica vigente del Ministerio de Educación,
        Ciencia y Tecnología (MINED), por este medio:
    </p>

    <div class="constar">HACE CONSTAR:</div>

    <p>
        Que él/la estudiante <span class="resaltado">{{ mb_strtoupper($estudiante['nombre'], 'UTF-8') }}</span>, identificado(a) con Número de
        Identificación Estudiantil (NIE) <strong>{{ $estudiante['nie'] }}</strong> cursó y aprobó satisfactoriamente la totalidad del
        plan de estudios correspondiente a la opción de <span class="resaltado">{{ mb_strtoupper($estudiante['opcion'], 'UTF-8') }}</span>
        durante el Año Lectivo <strong>{{ $estudiante['anio_lectivo'] }}</strong>, habiendo cumplido con todos los requisitos académicos
        y legales establecidos por las autoridades del Ministerio de Educación, Ciencia y Tecnología (MINED).
    </p>

    <p>
        Asimismo, se hace constar que él/la interesado(a) ha acreditado el cumplimiento del Servicio
        Social Estudiantil de acuerdo con el Art. 61 de la Ley General de Educación, así como las
        evaluaciones estandarizadas del sistema educativo nacional, encontrándose formalmente en
        condición de <span class="resaltado">EGRESADO(A)</span> y apto(a) para recibir el Título de Bachiller expedido por el
        Ministerio de Educación, Ciencia y Tecnología.
    </p>

    <p class="fecha">Cantón El Tule, {{ $fecha }}.</p>

    <div class="firma">
        <div class="linea">F. ____________________________________</div>
        <div class="director">{{ $director }}</div>
        <div class="cargo">Director(a) del Complejo Educativo Hacienda Colima Cantón el Tule</div>
        <div class="sello">Sello Institucional</div>
    </div>
</body>
</html>
