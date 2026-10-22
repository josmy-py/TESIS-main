{{-- Firma del director en todos los reportes PDF (diseño de Josmy del 22/10/2026):
     F. ____, nombre en negrita azul, cargo y Sello Institucional. Parámetros:
     nombreDirector (sin él, el director actual), margen (px arriba) y corta (línea
     más corta cuando va en una columna junto a otra firma). --}}
@php
    $nombreFirma = trim((string) ($nombreDirector ?? '')) !== '' ? $nombreDirector : 'Lic. Rolando Alexander Hernandez Peñate';
    $lineaFirma = ($corta ?? false) ? '____________________________' : '______________________________________';
@endphp
<div style="text-align: center; font-size: 10.5px; margin-top: {{ $margen ?? 45 }}px; page-break-inside: avoid;">
    <div style="color: #0b2c5a; font-size: 11px;">F. {{ $lineaFirma }}</div>
    <div style="color: #0b2c5a; font-weight: bold; font-size: 11px; margin-top: 6px;">{{ $nombreFirma }}</div>
    <div style="margin-top: 4px;">Director(a) del Complejo Educativo Hacienda Colima Cantón el Tule</div>
    <div style="color: #4b5563; font-weight: bold; font-size: 9.5px; margin-top: 4px;">Sello Institucional</div>
</div>
