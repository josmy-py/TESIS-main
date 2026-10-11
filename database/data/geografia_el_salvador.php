<?php

/**
 * División territorial de El Salvador vigente desde el 1 de mayo de 2024.
 *
 * Fuente legal: Ley Especial para la Reestructuración Municipal, Decreto
 * Legislativo N.º 762 del 13 de junio de 2023, Diario Oficial del 14/06/2023,
 * Tomo 439, Art. 1 (copia del Centro de Documentación Judicial:
 * https://www.jurisprudencia.gob.sv/DocumentosBoveda/D/2/2020-2029/2023/06/F9625.PDF).
 * 14 departamentos, 44 municipios y 262 distritos (los 262 municipios
 * anteriores pasan a ser distritos con su nombre histórico).
 *
 * Cotejo (2026-10-11): los 44 municipios con su conjunto de distritos y la
 * grafía de los 256 nombres distintos de distrito se compararon con el Art. 1.
 * Los nombres de distrito siguen la grafía del decreto, incluidas "Juayua"
 * (sin tilde en el decreto) y "de La" en Santiago de La Frontera, San Antonio
 * de La Cruz y San Luis de La Reina. Detalle: informe de verificación de M2.1.
 *
 * Normalizaciones de formato del proyecto (no cambian la denominación legal):
 *   - El decreto escribe "DEPARTAMENTO DE ..." y "MUNICIPIO DE ..." en
 *     mayúsculas y a veces sin tilde (AHUACHAPAN, CUSCATLAN, CABANAS ESTE);
 *     aquí van con mayúscula inicial y tildes: "Ahuachapán Norte".
 *   - Se omite el prefijo "Distrito de".
 *   - El decreto nombra "Distrito de San Salvador y Capital de la República"
 *     (con una nota de reforma por el D.L. 978); aquí se guarda "San Salvador".
 *
 * Primera versión tomada de la transcripción de Diario El Mundo (sin
 * diferencias de pertenencia frente al decreto).
 *
 * Estructura: departamento => [municipio => [distritos]].
 */
return [
    'Ahuachapán' => [
        'Ahuachapán Norte' => ['Atiquizaya', 'El Refugio', 'San Lorenzo', 'Turín'],
        'Ahuachapán Centro' => ['Ahuachapán', 'Apaneca', 'Concepción de Ataco', 'Tacuba'],
        'Ahuachapán Sur' => ['Guaymango', 'Jujutla', 'San Francisco Menéndez', 'San Pedro Puxtla'],
    ],
    'Santa Ana' => [
        'Santa Ana Norte' => ['Masahuat', 'Metapán', 'Santa Rosa Guachipilín', 'Texistepeque'],
        'Santa Ana Centro' => ['Santa Ana'],
        'Santa Ana Este' => ['Coatepeque', 'El Congo'],
        'Santa Ana Oeste' => ['Candelaria de la Frontera', 'Chalchuapa', 'El Porvenir', 'San Antonio Pajonal', 'San Sebastián Salitrillo', 'Santiago de La Frontera'],
    ],
    'Sonsonate' => [
        'Sonsonate Norte' => ['Juayua', 'Nahuizalco', 'Salcoatitán', 'Santa Catarina Masahuat'],
        'Sonsonate Centro' => ['Sonsonate', 'Sonzacate', 'Nahulingo', 'San Antonio del Monte', 'Santo Domingo de Guzmán'],
        'Sonsonate Este' => ['Izalco', 'Armenia', 'Caluco', 'San Julián', 'Cuisnahuat', 'Santa Isabel Ishuatán'],
        'Sonsonate Oeste' => ['Acajutla'],
    ],
    'Chalatenango' => [
        'Chalatenango Norte' => ['La Palma', 'Citalá', 'San Ignacio'],
        'Chalatenango Centro' => ['Nueva Concepción', 'Tejutla', 'La Reina', 'Agua Caliente', 'Dulce Nombre de María', 'El Paraíso', 'San Fernando', 'San Francisco Morazán', 'San Rafael', 'Santa Rita'],
        'Chalatenango Sur' => ['Chalatenango', 'Arcatao', 'Azacualpa', 'Comalapa', 'Concepción Quezaltepeque', 'El Carrizal', 'La Laguna', 'Las Vueltas', 'Nombre de Jesús', 'Nueva Trinidad', 'Ojos de Agua', 'Potonico', 'San Antonio de La Cruz', 'San Antonio Los Ranchos', 'San Francisco Lempa', 'San Isidro Labrador', 'San José Cancasque', 'San Miguel de Mercedes', 'San José Las Flores', 'San Luis del Carmen'],
    ],
    'La Libertad' => [
        'La Libertad Norte' => ['Quezaltepeque', 'San Matías', 'San Pablo Tacachico'],
        'La Libertad Centro' => ['San Juan Opico', 'Ciudad Arce'],
        'La Libertad Oeste' => ['Colón', 'Jayaque', 'Sacacoyo', 'Tepecoyo', 'Talnique'],
        'La Libertad Este' => ['Antiguo Cuscatlán', 'Huizúcar', 'Nuevo Cuscatlán', 'San José Villanueva', 'Zaragoza'],
        'La Libertad Costa' => ['Chiltiupán', 'Jicalapa', 'La Libertad', 'Tamanique', 'Teotepeque'],
        'La Libertad Sur' => ['Comasagua', 'Santa Tecla'],
    ],
    'San Salvador' => [
        'San Salvador Norte' => ['Aguilares', 'El Paisnal', 'Guazapa'],
        'San Salvador Oeste' => ['Apopa', 'Nejapa'],
        'San Salvador Este' => ['Ilopango', 'San Martín', 'Soyapango', 'Tonacatepeque'],
        'San Salvador Centro' => ['Ayutuxtepeque', 'Mejicanos', 'San Salvador', 'Cuscatancingo', 'Ciudad Delgado'],
        'San Salvador Sur' => ['Panchimalco', 'Rosario de Mora', 'San Marcos', 'Santo Tomás', 'Santiago Texacuangos'],
    ],
    'Cuscatlán' => [
        'Cuscatlán Norte' => ['Suchitoto', 'San José Guayabal', 'Oratorio de Concepción', 'San Bartolomé Perulapía', 'San Pedro Perulapán'],
        'Cuscatlán Sur' => ['Cojutepeque', 'San Rafael Cedros', 'Candelaria', 'Monte San Juan', 'El Carmen', 'San Cristóbal', 'Santa Cruz Michapa', 'San Ramón', 'El Rosario', 'Santa Cruz Analquito', 'Tenancingo'],
    ],
    'La Paz' => [
        'La Paz Oeste' => ['Cuyultitán', 'Olocuilta', 'San Juan Talpa', 'San Luis Talpa', 'San Pedro Masahuat', 'Tapalhuaca', 'San Francisco Chinameca'],
        'La Paz Centro' => ['El Rosario', 'Jerusalén', 'Mercedes La Ceiba', 'Paraíso de Osorio', 'San Antonio Masahuat', 'San Emigdio', 'San Juan Tepezontes', 'San Luis La Herradura', 'San Miguel Tepezontes', 'San Pedro Nonualco', 'Santa María Ostuma', 'Santiago Nonualco'],
        'La Paz Este' => ['San Juan Nonualco', 'San Rafael Obrajuelo', 'Zacatecoluca'],
    ],
    'Cabañas' => [
        'Cabañas Este' => ['Sensuntepeque', 'Victoria', 'Dolores', 'Guacotecti', 'San Isidro'],
        'Cabañas Oeste' => ['Ilobasco', 'Tejutepeque', 'Jutiapa', 'Cinquera'],
    ],
    'San Vicente' => [
        'San Vicente Norte' => ['Apastepeque', 'Santa Clara', 'San Ildefonso', 'San Esteban Catarina', 'San Sebastián', 'San Lorenzo', 'Santo Domingo'],
        'San Vicente Sur' => ['San Vicente', 'Guadalupe', 'Verapaz', 'Tepetitán', 'Tecoluca', 'San Cayetano Istepeque'],
    ],
    'Usulután' => [
        'Usulután Norte' => ['Santiago de María', 'Alegría', 'Berlín', 'Mercedes Umaña', 'Jucuapa', 'El Triunfo', 'Estanzuelas', 'San Buenaventura', 'Nueva Granada'],
        'Usulután Este' => ['Usulután', 'Jucuarán', 'San Dionisio', 'Concepción Batres', 'Santa María', 'Ozatlán', 'Tecapán', 'Santa Elena', 'California', 'Ereguayquín'],
        'Usulután Oeste' => ['Jiquilisco', 'Puerto El Triunfo', 'San Agustín', 'San Francisco Javier'],
    ],
    'San Miguel' => [
        'San Miguel Norte' => ['Ciudad Barrios', 'Sesori', 'Nuevo Edén de San Juan', 'San Gerardo', 'San Luis de La Reina', 'Carolina', 'San Antonio del Mosco', 'Chapeltique'],
        'San Miguel Centro' => ['San Miguel', 'Comacarán', 'Uluazapa', 'Moncagua', 'Quelepa', 'Chirilagua'],
        'San Miguel Oeste' => ['Chinameca', 'Nueva Guadalupe', 'Lolotique', 'San Jorge', 'San Rafael Oriente', 'El Tránsito'],
    ],
    'Morazán' => [
        'Morazán Norte' => ['Arambala', 'Cacaopera', 'Corinto', 'El Rosario', 'Joateca', 'Jocoaitique', 'Meanguera', 'Perquín', 'San Fernando', 'San Isidro', 'Torola'],
        'Morazán Sur' => ['Chilanga', 'Delicias de Concepción', 'El Divisadero', 'Gualococti', 'Guatajiagua', 'Jocoro', 'Lolotiquillo', 'Osicala', 'San Carlos', 'San Francisco Gotera', 'San Simón', 'Sensembra', 'Sociedad', 'Yamabal', 'Yoloaiquín'],
    ],
    'La Unión' => [
        'La Unión Norte' => ['Anamorós', 'Bolívar', 'Concepción de Oriente', 'El Sauce', 'Lislique', 'Nueva Esparta', 'Pasaquina', 'Polorós', 'San José La Fuente', 'Santa Rosa de Lima'],
        'La Unión Sur' => ['Conchagua', 'El Carmen', 'Intipucá', 'La Unión', 'Meanguera del Golfo', 'San Alejo', 'Yayantique', 'Yucuaiquín'],
    ],
];
