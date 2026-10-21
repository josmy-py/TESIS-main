{{-- Encabezado común de los reportes PDF (dompdf): escudo a la izquierda y datos de la institución al centro. --}}
@php
    $escudo = public_path('img/escudo_colima.png');
    $escudoBase64 = is_file($escudo) ? base64_encode(file_get_contents($escudo)) : null;
@endphp
<table class="encabezado">
    <tr>
        <td class="encabezado-escudo">
            @if ($escudoBase64)
                <img src="data:image/png;base64,{{ $escudoBase64 }}" alt="Escudo">
            @endif
        </td>
        <td class="encabezado-texto">
            <div class="enc-fuerte">REPÚBLICA DE EL SALVADOR</div>
            <div class="enc-fuerte">MINISTERIO DE EDUCACIÓN, CIENCIA Y TECNOLOGÍA</div>
            <div class="enc-suave">DIRECCIÓN DEPARTAMENTAL DE EDUCACIÓN</div>
            <div class="enc-institucion">COMPLEJO EDUCATIVO HACIENDA COLIMA</div>
            <div class="enc-suave">CANTÓN EL TULE, EL SALVADOR</div>
            <div class="enc-codigo">CÓDIGO DE INFRAESTRUCTURA: 1689</div>
        </td>
        <td class="encabezado-escudo"></td>
    </tr>
</table>
<div class="encabezado-linea"></div>

<style>
    .encabezado { width: 100%; border-collapse: collapse; }
    .encabezado td { vertical-align: middle; }
    .encabezado-escudo { width: 95px; }
    .encabezado-escudo img { width: 85px; }
    .encabezado-texto { text-align: center; line-height: 1.35; }
    .enc-fuerte { color: #0b2c5a; font-weight: bold; font-size: 12px; }
    .enc-suave { color: #4b5563; font-size: 11px; }
    .enc-institucion { color: #0b2c5a; font-weight: bold; font-size: 14px; }
    .enc-codigo { color: #4b5563; font-size: 10px; }
    .encabezado-linea { border-top: 3px solid #0b2c5a; margin: 12px 0 18px; }
</style>
