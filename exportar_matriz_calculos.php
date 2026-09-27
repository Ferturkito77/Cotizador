<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
$conexion->set_charset('utf8mb4');

$sql = "SELECT
            c.cpu_name AS cpu,
            tc.ctrltipo_name AS tipo,
            sc.ctrlsubtipo_name AS subtipo,
            t.tension_name AS tension,
            CASE WHEN m.control_encoder = 'SI' THEN 'SI' ELSE 'NO' END AS encoder,
            m.control_potenciadesde AS hp_desde,
            m.control_potenciahasta AS hp_hasta,
            m.control_corriente AS corriente_a,
            COALESCE(co.contactor_name, '') AS contactor,
            COALESCE(cp.contactorpot_name, '') AS contactor_potencial,
            COALESCE(th.termicos_codigo, '') AS termico,
            m.control_codigo AS codigo_equipo,
            COALESCE(lp.precios_descripcion, '') AS descripcion_equipo,
            COALESCE(lp.precios_costo, m.control_precio, 0) AS precio_vigente
        FROM matriz_calculos m
        INNER JOIN cpus c ON c.cpu_id = m.control_cpu
        INNER JOIN tipos_control tc ON tc.ctrltipo_id = m.control_tipo
        INNER JOIN subtipos_control sc ON sc.ctrlsubtipo_id = m.control_subtipo
        INNER JOIN tensiones t ON t.tension_id = m.control_tension
        LEFT JOIN lista_precios lp ON TRIM(lp.precios_codigo) = TRIM(m.control_codigo)
        LEFT JOIN contactores co ON co.contactor_id = m.control_contactor
        LEFT JOIN contactorpot cp ON cp.contactorpot_id = m.control_contactorpot
        LEFT JOIN termicos th ON th.termicos_id = m.control_termicos
        ORDER BY c.cpu_name, tc.ctrltipo_name, sc.ctrlsubtipo_name, t.tension_name,
                 m.control_encoder, m.control_potenciadesde, m.control_potenciahasta, m.control_codigo";

$resultado = $conexion->query($sql);
if (!$resultado) {
    http_response_code(500);
    die('No se pudo exportar la matriz: ' . htmlspecialchars($conexion->error, ENT_QUOTES, 'UTF-8'));
}

$nombre = 'matriz_calculos_revision_' . date('Y-m-d_H-i-s') . '.csv';
header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $nombre . '"');
header('Pragma: no-cache');
header('Expires: 0');

$salida = fopen('php://output', 'w');
if ($salida === false) {
    http_response_code(500);
    die('No se pudo crear el archivo de exportación.');
}

// BOM para que Excel reconozca correctamente acentos y eñes.
fwrite($salida, "\xEF\xBB\xBF");

$encabezados = array(
    'CPU',
    'Tipo',
    'Subtipo',
    'Tension',
    'Encoder',
    'HP desde',
    'HP hasta',
    'Corriente (A)',
    'Contactor',
    'Contactor potencial',
    'Termico',
    'Codigo equipo',
    'Descripcion equipo',
    'Precio vigente',
    'Campo con error',
    'Valor correcto propuesto',
    'Observaciones'
);
fputcsv($salida, $encabezados, ';');

while ($fila = $resultado->fetch_assoc()) {
    $registro = array(
        $fila['cpu'],
        $fila['tipo'],
        $fila['subtipo'],
        $fila['tension'],
        $fila['encoder'],
        number_format((float)$fila['hp_desde'], 2, ',', ''),
        number_format((float)$fila['hp_hasta'], 2, ',', ''),
        number_format((float)$fila['corriente_a'], 2, ',', ''),
        $fila['contactor'],
        $fila['contactor_potencial'],
        $fila['termico'],
        $fila['codigo_equipo'],
        $fila['descripcion_equipo'],
        number_format((float)$fila['precio_vigente'], 2, ',', ''),
        '',
        '',
        ''
    );
    fputcsv($salida, $registro, ';');
}

fclose($salida);
exit;
