<?php
// v332 - Zona horaria oficial del sistema: Argentina.
date_default_timezone_set('America/Argentina/Buenos_Aires');
/* v427 - Credenciales configurables por entorno. Mantiene compatibilidad local con XAMPP.
 * En producción definir AUTOMAC_DB_HOST, AUTOMAC_DB_USER, AUTOMAC_DB_PASS y AUTOMAC_DB_NAME. */
$dbHost = getenv('AUTOMAC_DB_HOST') !== false ? (string)getenv('AUTOMAC_DB_HOST') : 'localhost';
$dbUser = getenv('AUTOMAC_DB_USER') !== false ? (string)getenv('AUTOMAC_DB_USER') : 'root';
$dbPass = getenv('AUTOMAC_DB_PASS') !== false ? (string)getenv('AUTOMAC_DB_PASS') : '';
$dbName = getenv('AUTOMAC_DB_NAME') !== false ? (string)getenv('AUTOMAC_DB_NAME') : 'cotizador_nuevo';
$conexion = new mysqli($dbHost, $dbUser, $dbPass, $dbName);

if ($conexion->connect_error) {
    die('Error de conexión a MySQL: ' . $conexion->connect_error);
}

if (!$conexion->set_charset('utf8mb4')) {
    die('No se pudo configurar UTF-8: ' . $conexion->error);
}

// Mantiene NOW()/CURRENT_TIMESTAMP de MySQL alineados con PHP y la hora local.
if (!$conexion->query("SET time_zone = '-03:00'")) {
    die('No se pudo configurar la zona horaria de MySQL: ' . $conexion->error);
}

// v336 - Politica unica de fechas/versiones.
// Regla del historial:
// - fecha_creacion pertenece siempre al documento original y nunca se reinterpreta.
// - fecha_revision registra el instante en que se hizo una modificacion y nacio la version siguiente.
// - por eso la fecha de una version historica N se obtiene de la modificacion anterior;
//   la primera version conserva siempre fecha_creacion.
// - la version vigente usa la ultima modificacion (o fecha_creacion si nunca se modifico).
function fechaVersionHistoricaCotizacion(mysqli $conexion, int $cotizacionId, int $revision, string $fechaCreacion): string {
    if ($revision <= 1) return $fechaCreacion;
    $revisionAnterior = $revision - 1;
    $st = $conexion->prepare('SELECT fecha_revision FROM cotizaciones_revisiones WHERE cotizacion_id=? AND revision=? ORDER BY revision_id DESC LIMIT 1');
    if (!$st) return $fechaCreacion;
    $st->bind_param('ii', $cotizacionId, $revisionAnterior);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($fila['fecha_revision']) ? (string)$fila['fecha_revision'] : $fechaCreacion;
}

function fechaVersionActualCotizacion(mysqli $conexion, int $cotizacionId, string $fechaCreacion): string {
    $st = $conexion->prepare('SELECT fecha_revision FROM cotizaciones_revisiones WHERE cotizacion_id=? ORDER BY revision DESC, revision_id DESC LIMIT 1');
    if (!$st) return $fechaCreacion;
    $st->bind_param('i', $cotizacionId);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($fila['fecha_revision']) ? (string)$fila['fecha_revision'] : $fechaCreacion;
}

function fechaVersionHistoricaPedido(mysqli $conexion, int $pedidoId, int $revision, string $fechaCreacion): string {
    // Los pedidos nacen en revision 0. Esa fecha es inmutable.
    if ($revision <= 0) return $fechaCreacion;
    $revisionAnterior = $revision - 1;
    $st = $conexion->prepare('SELECT fecha_revision FROM pedidos_revisiones WHERE pedido_id=? AND revision=? ORDER BY revision_id DESC LIMIT 1');
    if (!$st) return $fechaCreacion;
    $st->bind_param('ii', $pedidoId, $revisionAnterior);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return !empty($fila['fecha_revision']) ? (string)$fila['fecha_revision'] : $fechaCreacion;
}

function fechaVersionActualPedido(array $pedido): string {
    $revision = (int)($pedido['revision'] ?? 0);
    if ($revision <= 0) return (string)($pedido['fecha_creacion'] ?? '');
    $ultima = trim((string)($pedido['fecha_ultima_modificacion'] ?? ''));
    return $ultima !== '' ? $ultima : (string)($pedido['fecha_creacion'] ?? '');
}

// La OF no posee una revision independiente: sigue exactamente la revision del pedido.
// fecha_paso_produccion es la fecha original de la primera OF y debe conservarse para siempre.
function fechaOriginalOrdenFabricacion(array $pedido): string {
    $of = trim((string)($pedido['fecha_paso_produccion'] ?? ''));
    if ($of !== '') return $of;
    return (string)($pedido['fecha_creacion'] ?? '');
}

function fechaVersionActualOrdenFabricacion(array $pedido): string {
    $original = fechaOriginalOrdenFabricacion($pedido);
    $mod = trim((string)($pedido['fecha_ultima_modificacion'] ?? ''));
    if ($mod === '') return $original;
    // Si el pedido fue revisado antes de pasar por primera vez a Produccion,
    // la OF nace en fecha_paso_produccion. Solo una modificacion posterior
    // a esa primera OF crea una nueva version de la orden.
    $to = strtotime($original);
    $tm = strtotime($mod);
    if ($to && $tm) return $tm > $to ? $mod : $original;
    return $mod !== '' ? $mod : $original;
}

function fechaHoraAr($valor): string {
    $valor=trim((string)$valor);
    if($valor==='') return '—';
    $ts=strtotime($valor);
    return $ts ? date('d/m/Y H:i:s',$ts) : $valor;
}


// v337 - Etiquetas y motivos asociados a la VERSION que nace con cada cambio.
// Las tablas *_revisiones guardan la foto de la version que se reemplaza y,
// en motivo_modificacion, el cambio que genera la version siguiente.
function etiquetaVersionCotizacion(int $revision): string {
    return $revision <= 1 ? 'Original' : 'Revisión '.($revision - 1);
}

function etiquetaVersionPedido(int $revision): string {
    return $revision <= 0 ? 'Original' : 'Revisión '.$revision;
}

function motivoVersionCotizacion(mysqli $conexion, int $cotizacionId, int $revision): string {
    if ($revision <= 1) return 'Versión original';
    $origen = $revision - 1;
    $st = $conexion->prepare('SELECT motivo_modificacion FROM cotizaciones_revisiones WHERE cotizacion_id=? AND revision=? ORDER BY revision_id DESC LIMIT 1');
    if (!$st) return '—';
    $st->bind_param('ii', $cotizacionId, $origen);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    $motivo = trim((string)($fila['motivo_modificacion'] ?? ''));
    return $motivo !== '' ? $motivo : 'Modificación sin detalle';
}

function motivoVersionPedido(mysqli $conexion, int $pedidoId, int $revision): string {
    if ($revision <= 0) return 'Versión original';
    $origen = $revision - 1;
    $st = $conexion->prepare('SELECT motivo_modificacion FROM pedidos_revisiones WHERE pedido_id=? AND revision=? ORDER BY revision_id DESC LIMIT 1');
    if (!$st) return '—';
    $st->bind_param('ii', $pedidoId, $origen);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    $motivo = trim((string)($fila['motivo_modificacion'] ?? ''));
    return $motivo !== '' ? $motivo : 'Modificación sin detalle';
}

?>
