<?php
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
require_once 'control_parametros.php';

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$id_cpu = filter_input(INPUT_POST, 'id_cpu', FILTER_VALIDATE_INT);
$id_tipo = filter_input(INPUT_POST, 'id_tipo_control', FILTER_VALIDATE_INT);
$lista_id = filter_input(INPUT_POST, 'lista_id', FILTER_VALIDATE_INT);

echo '<option value="">Seleccione un subtipo...</option>';

if (!$id_cpu || !$id_tipo || !$lista_id) {
    exit;
}

/*
 * v118: CLEX y DANGELICA usan la matriz base definida en cpus para obtener
 * tipos/subtipos disponibles, pero mantienen su CPU real para límites y reglas
 * técnicas específicas. Esto evita que el combo de subtipo quede vacío.
 */
$id_cpu_matriz = (int)$id_cpu;
$stmtCpu = $conexion->prepare('SELECT COALESCE(NULLIF(cpu_matriz_base_id,0), cpu_id) AS cpu_matriz_base_id FROM cpus WHERE cpu_id = ? LIMIT 1');
if ($stmtCpu) {
    $stmtCpu->bind_param('i', $id_cpu);
    $stmtCpu->execute();
    $filaCpu = $stmtCpu->get_result()->fetch_assoc();
    $stmtCpu->close();
    if ($filaCpu && (int)$filaCpu['cpu_matriz_base_id'] > 0) {
        $id_cpu_matriz = (int)$filaCpu['cpu_matriz_base_id'];
    }
}

/* v213: la compatibilidad CPU + tipo + subtipo deja de estar hardcodeada.
 * Si la migración v213 está instalada, la fuente es control_compatibilidades.
 * Se conserva el fallback histórico para instalaciones todavía no migradas. */
if (ctrlTablaExiste($conexion, 'control_compatibilidades')) {
    $sql = "SELECT DISTINCT s.ctrlsubtipo_id,s.ctrlsubtipo_name,s.velocidad_max_mmin,s.habilita_mayor_75
            FROM control_compatibilidades cc
            INNER JOIN subtipos_control s ON s.ctrlsubtipo_id=cc.ctrlsubtipo_id
            WHERE cc.cpu_id=? AND cc.ctrltipo_id=? AND cc.activo='SI'
            ORDER BY s.ctrlsubtipo_id";
    $usarCpuReal = true;
} else {
    $sql = "SELECT DISTINCT s.ctrlsubtipo_id,s.ctrlsubtipo_name,s.velocidad_max_mmin,s.habilita_mayor_75
            FROM matriz_calculos m
            INNER JOIN subtipos_control s ON s.ctrlsubtipo_id=m.control_subtipo
            WHERE m.control_cpu=? AND m.control_tipo=?";
    if ((int)$id_tipo === 2) $sql .= " AND s.ctrlsubtipo_id IN (1,2,6)";
    $sql .= " ORDER BY s.ctrlsubtipo_id";
    $usarCpuReal = false;
}

$stmt = $conexion->prepare($sql);

if (!$stmt) {
    echo '<option value="">Error al preparar la consulta</option>';
    exit;
}

$cpuConsulta = $usarCpuReal ? (int)$id_cpu : (int)$id_cpu_matriz;
$stmt->bind_param('ii', $cpuConsulta, $id_tipo);
$stmt->execute();

$resultado = $stmt->get_result();

while ($row = $resultado->fetch_assoc()) {
    $id = (int)$row['ctrlsubtipo_id'];
    $nombreSubtipo = (string)$row['ctrlsubtipo_name'];

    $nombre = htmlspecialchars($nombreSubtipo, ENT_QUOTES, 'UTF-8');
    $max = $row['velocidad_max_mmin'] !== null ? (string)(float)$row['velocidad_max_mmin'] : '';
    $alta = (($row['habilita_mayor_75'] ?? 'NO') === 'SI') ? 'SI' : 'NO';

    echo '<option value="' . $id . '" data-velocidad-max="' . htmlspecialchars($max, ENT_QUOTES, 'UTF-8') . '" data-mayor-75="' . $alta . '">' . $nombre . '</option>';
}

$stmt->close();
?>
