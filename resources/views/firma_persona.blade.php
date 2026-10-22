{{-- Firma de otra persona (docente, padre o responsable) con el mismo estilo de
     la firma del director. Parámetros: nombreFirma (puede ir vacío) y cargoFirma. --}}
<div style="text-align: center; font-size: 10.5px; page-break-inside: avoid;">
    <div style="color: #0b2c5a; font-size: 11px;">F. ____________________________</div>
    <div style="color: #0b2c5a; font-weight: bold; font-size: 11px; margin-top: 6px;">{{ trim((string) ($nombreFirma ?? '')) !== '' ? $nombreFirma : ' ' }}</div>
    <div style="margin-top: 4px;">{{ $cargoFirma }}</div>
</div>
