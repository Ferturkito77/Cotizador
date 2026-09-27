<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
header('Content-Type: application/json; charset=utf-8');

$texto = trim((string)($_GET['q'] ?? ''));
$categoria = trim((string)($_GET['categoria'] ?? ''));
$listaId = (int)($_GET['lista_id'] ?? 0);
require_once 'sistema_comercial.php';
require_once 'repuestos_costos.php';
$estadoCatalogoV163=repCostoAsegurarCatalogoV163($conexion, (string)($_SESSION['usuario_rol']??'')==='ADMINISTRADOR');
$vigente=obtenerListaVigente($conexion); $vigenteId=(int)($vigente['lista_id']??0);
$usarHistorico=$listaId>0 && $listaId!==$vigenteId;
/* v164: para la lista vigente los costos se resuelven en vivo contra Bejerman.
   Así una fórmula no depende de que costo_referencia haya quedado recalculado previamente. */
$ctxCostoV164 = !$usarHistorico ? repCostoCargarContexto($conexion, 0) : null;
$tokens = preg_split('/\s+/u', $texto, -1, PREG_SPLIT_NO_EMPTY);

if (count($tokens) === 0 && $categoria === '') {
    echo json_encode(array('resultados'=>array()), JSON_UNESCAPED_UNICODE);
    exit;
}

if($usarHistorico){
    /* Histórico: se conserva exactamente la base etiquetada del documento. */
    $select = "SELECT r.producto_repuesto_id,r.codigo,r.descripcion,r.categoria,r.utilidad,r.factor_descuento_15,r.factor_descuento_30,r.observaciones,r.es_neto,r.iva_porcentaje,r.advertencia,
    COALESCE(r.costo_tipo,'BEJERMAN') costo_tipo,
    h.precios_costo AS costo,h.precios_costo AS precio_base,
    (h.precios_costo*r.factor_descuento_15) AS precio_15,
    (h.precios_costo*r.factor_descuento_30) AS precio_30,1 AS costo_disponible
    FROM productos_repuestos r INNER JOIN listas_precios_historial h ON h.lista_id=".$listaId." AND h.precios_codigo=r.codigo";
    $where = array("r.habilitado=1", "h.precios_costo>0");
}else{
    /* v164: la lista vigente se resuelve en vivo contra Bejerman / FORMULA / FIJO. */
    $select = "SELECT r.producto_repuesto_id,r.codigo,r.descripcion,r.categoria,r.utilidad,r.factor_descuento_15,r.factor_descuento_30,r.observaciones,r.es_neto,r.iva_porcentaje,r.advertencia,
    COALESCE(r.costo_tipo,'BEJERMAN') costo_tipo,COALESCE(r.formula_costo,'') formula_costo,
    r.costo_referencia AS costo,0 AS precio_base,0 AS precio_15,0 AS precio_30,0 AS costo_disponible
    FROM productos_repuestos r";
    /* Los productos sin costo también se muestran: pueden existir antes de cargarse en Bejerman. */
    $where = array("r.habilitado=1");
}
$params = array();
$types = '';

if ($categoria !== '') {
    $where[] = "r.categoria = ?";
    $params[] = $categoria;
    $types .= 's';
}
foreach ($tokens as $token) {
    $where[] = "CONCAT_WS(' ',r.codigo,r.descripcion,r.categoria,COALESCE(r.observaciones,''),COALESCE(r.advertencia,'')) LIKE ?";
    $params[] = '%' . $token . '%';
    $types .= 's';
}

$sql = $select . " WHERE " . implode(' AND ', $where)
    . " ORDER BY CASE WHEN UPPER(r.codigo)=UPPER(?) THEN 0 WHEN UPPER(r.codigo) LIKE UPPER(?) THEN 1 ELSE 2 END,r.descripcion LIMIT 50";
$params[] = $texto;
$params[] = $texto . '%';
$types .= 'ss';

$st = $conexion->prepare($sql);
if (!$st) {
    http_response_code(500);
    echo json_encode(array('error'=>$conexion->error));
    exit;
}
$st->bind_param($types, ...$params);
$st->execute();
$rs = $st->get_result();
$out = array();
$stActualizarCostoV164 = (!$usarHistorico) ? $conexion->prepare("UPDATE productos_repuestos SET costo_referencia=? WHERE producto_repuesto_id=?") : null;
while ($x = $rs->fetch_assoc()) {
    foreach (array('utilidad','factor_descuento_15','factor_descuento_30','iva_porcentaje') as $k) $x[$k] = (float)$x[$k];
    if (!$usarHistorico && is_array($ctxCostoV164)) {
        $costoV164 = repCostoDeCodigo((string)$x['codigo'], $ctxCostoV164);
        $x['costo'] = (float)$costoV164;
        $x['precio_base'] = $costoV164 * $x['utilidad'];
        $x['precio_15'] = $x['precio_base'] * $x['factor_descuento_15'];
        $x['precio_30'] = $x['precio_base'] * $x['factor_descuento_30'];
        $x['costo_disponible'] = $costoV164 > 0;
        if ($stActualizarCostoV164) {
            $idRepV164=(int)$x['producto_repuesto_id'];
            $stActualizarCostoV164->bind_param('di',$costoV164,$idRepV164);
            $stActualizarCostoV164->execute();
        }
    } else {
        foreach (array('costo','precio_base','precio_15','precio_30') as $k) $x[$k] = (float)$x[$k];
        $x['costo_disponible'] = (bool)$x['costo_disponible'];
    }
    $x['es_neto'] = (bool)$x['es_neto'];
    $x['encontrado_bejerman'] = $x['costo_disponible'];
    $out[] = $x;
}
if ($stActualizarCostoV164) $stActualizarCostoV164->close();
$st->close();
echo json_encode(array('resultados'=>$out), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
