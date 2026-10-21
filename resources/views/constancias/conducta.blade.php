<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Constancia de Buena Conducta</title>
    <style>
        @page { margin: 50px 70px; }
        body { font-family: 'DejaVu Sans', sans-serif; font-size: 13px; color: #1f2937; }
        .titulo { text-align: center; color: #0b2c5a; font-size: 22px; font-weight: bold; margin: 26px 0 30px; }
        p { text-align: justify; line-height: 1.65; margin: 0 0 16px; }
        .resaltado { color: #0b2c5a; font-weight: bold; }
        .constar { text-align: center; color: #0b2c5a; font-size: 16px; font-weight: bold; margin: 26px 0 18px; }
        .firma { margin-top: 50px; text-align: center; page-break-inside: avoid; }
        .linea { color: #0b2c5a; }
        .director { color: #0b2c5a; font-weight: bold; font-size: 13px; margin-top: 6px; }
        .cargo { font-size: 12px; margin-top: 4px; }
        .sello { color: #4b5563; font-weight: bold; font-size: 11px; margin-top: 6px; }
    </style>
</head>
<body>
    @include('encabezado_reporte')

    <div class="titulo">CONSTANCIA DE BUENA CONDUCTA</div>

    <p>
        El suscrito Director(a) del <span class="resaltado">COMPLEJO EDUCATIVO HACIENDA COLIMA</span>, ubicado en el
        Cantón El Tule, municipio de El Paisnal, departamento de San Salvador, República de El
        Salvador, por medio de la presente y con base en los registros de conducta estudiantil de
        esta institución educativa:
    </p>

    <div class="constar">HACE CONSTAR:</div>

    <p>
        Que él/la estudiante <span class="resaltado">{{ mb_strtoupper($estudiante['nombre'], 'UTF-8') }}</span>, identificado con el Número de
        Identificación Estudiantil (NIE) <span class="resaltado">{{ $estudiante['nie'] }}</span>, quien cursó sus estudios en esta institución
        educativa durante el año lectivo <strong>{{ $anio_lectivo }}</strong>, ha demostrado <span class="resaltado">BUENA CONDUCTA</span>, manteniendo un
        comportamiento respetuoso, responsable y acorde con las normas de convivencia
        establecidas por el centro educativo.
    </p>

    <p>
        Asimismo, se hace constar que, de acuerdo con los registros institucionales correspondientes
        al período académico indicado, el estudiante ha demostrado respeto hacia las autoridades,
        personal docente, compañeros y demás miembros de la comunidad educativa, cumpliendo
        con las disposiciones disciplinarias y los valores promovidos por la institución.
    </p>

    <p>
        Se extiende la presente constancia a solicitud de la parte interesada, para los fines
        académicos, administrativos o personales que estime convenientes.
    </p>

    <p>
        Dado en el Cantón El Tule, municipio de El Paisnal, departamento de San Salvador,
        República de El Salvador, a los {{ $dia }} días del mes de {{ $mes }} del año {{ $anio }}.
    </p>

    <div class="firma">
        <div class="linea">F. ____________________________________</div>
        <div class="director">{{ $director }}</div>
        <div class="cargo">Director(a) del Complejo Educativo Hacienda Colima Cantón el Tule</div>
        <div class="sello">Sello Institucional</div>
    </div>
</body>
</html>
