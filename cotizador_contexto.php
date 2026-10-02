<?php
/** AUTOMAC v432 - Preparacion de contexto del cotizador.
 * Se incluye desde index.php y comparte su mismo scope.
 */
require_once __DIR__ . '/cotizador_inicio_nueva.php';
asegurarEspecificacionesTecnicasClientes($conexion);
asegurarSistemaComercial($conexion);
asegurarTablaPlantillasControles($conexion);
asegurarTablaPlantillasSenalizacion($conexion);
$plantillasSenalizacionDisponibles = listarPlantillasSenalizacion($conexion, true);

// v217: parámetros comerciales y automatizaciones mantenibles.
$descuentosIniciales = cotizadorDescuentosIniciales($conexion);
[$defaultControlD1, $defaultControlD2, $defaultControlD3] = $descuentosIniciales['control'];
[$defaultSenalD1, $defaultSenalD2, $defaultSenalD3] = $descuentosIniciales['senalizacion'];
[$defaultAccD1, $defaultAccD2, $defaultAccD3] = $descuentosIniciales['accesorios'];
$defaultRepDescuento = $descuentosIniciales['repuestos'];
$reglaAlarmaEmergenciaV217 = automatizacionCotizador($conexion, 'LUZ_EMERGENCIA_ALARMA_12V');

/* Nueva cotización: limpiar la sesión antes de generar el token de esta página. */
if (isset($_GET['nueva']) && $_GET['nueva'] === '1') {
    cotizadorLimpiarSesionNueva();
}

/* Token de un solo uso para impedir guardados duplicados por doble clic o reenvío del navegador. */
$documentSaveToken = cotizadorCrearTokenGuardado();

$cotizacionEdicionId = 0;
$cotizacionEdicion = null;
$pedidoEdicionId = 0;
$pedidoEdicion = null;
$pedidoConfiguracionNoDisponible = false;
if (isset($_GET['editar_pedido'])) {
    $pedidoEdicionId = (int)filter_input(INPUT_GET, 'editar_pedido', FILTER_VALIDATE_INT);
    if ($pedidoEdicionId > 0) {
        $stmtPedido = $conexion->prepare("SELECT p.*, COALESCE(NULLIF(p.referencia,''), c.referencia, '') AS referencia_edicion, c.datos_formulario AS cotizacion_datos, c.descuento_1 AS cotizacion_descuento_1, c.descuento_2 AS cotizacion_descuento_2, c.descuento_3 AS cotizacion_descuento_3 FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id WHERE p.pedido_id=? LIMIT 1");
        $stmtPedido->bind_param('i', $pedidoEdicionId);
        $stmtPedido->execute();
        $pedidoEdicion = $stmtPedido->get_result()->fetch_assoc();
        $stmtPedido->close();
        if (!$pedidoEdicion) die('El pedido que desea modificar no existe.');
        if (($pedidoEdicion['estado'] ?? '') === 'ANULADO') { header('Location: pedidos.php?anulado_bloqueado=1'); exit; }
        // v124: al modificar un pedido, usar la cotización origen como respaldo campo a campo.
        // Antes se elegía el JSON del pedido completo y sólo se recurría a la cotización si
        // aquel estaba totalmente vacío. Una revisión parcial podía conservar casi toda la
        // configuración pero perder un dato de batería (por ejemplo cantidad_total_coches_bateria).
        // La cotización es sólo respaldo: los valores propios del pedido siempre prevalecen.
        $datosPedido = json_decode(trim((string)($pedidoEdicion['datos_formulario'] ?? '')), true);
        $datosCotizacionOrigen = json_decode(trim((string)($pedidoEdicion['cotizacion_datos'] ?? '')), true);
        if (!is_array($datosPedido)) $datosPedido = array();
        if (!is_array($datosCotizacionOrigen)) $datosCotizacionOrigen = array();
        $datosGuardados = array_replace($datosCotizacionOrigen, $datosPedido);
        if (count($datosGuardados) === 0) {
            /* Los pedidos históricos creados antes de guardar la configuración completa
             * pueden tener datos_formulario = 0, vacío o NULL. En ese caso solo es
             * posible recuperar cabecera, cliente, lista, referencia y descuentos. */
            $datosGuardados = array();
            $pedidoConfiguracionNoDisponible = true;
        }
        $datosGuardados['id_cliente'] = (int)$pedidoEdicion['cliente_id'];
        $datosGuardados['lista_id'] = (int)$pedidoEdicion['lista_id'];
        $datosGuardados['referencia_cotizacion'] = (string)($pedidoEdicion['referencia_edicion'] ?? '');
        $datosGuardados['descuento_1'] = (int)($pedidoEdicion['cotizacion_descuento_1'] ?? ($datosGuardados['descuento_1'] ?? 0));
        $datosGuardados['descuento_2'] = (int)($pedidoEdicion['cotizacion_descuento_2'] ?? ($datosGuardados['descuento_2'] ?? 0));
        $datosGuardados['descuento_3'] = (int)($pedidoEdicion['cotizacion_descuento_3'] ?? ($datosGuardados['descuento_3'] ?? 0));
        $_SESSION['form_data'] = $datosGuardados;
    }
}

if (isset($_GET['editar_cotizacion'])) {
    $cotizacionEdicionId = (int)filter_input(INPUT_GET, 'editar_cotizacion', FILTER_VALIDATE_INT);
    if ($cotizacionEdicionId > 0) {
        $stmtEdicion = $conexion->prepare("SELECT c.*, (SELECT COUNT(*) FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id AND p.estado<>'ANULADO') AS tiene_pedido FROM cotizaciones c WHERE c.cotizacion_id=? LIMIT 1");
        $stmtEdicion->bind_param('i', $cotizacionEdicionId);
        $stmtEdicion->execute();
        $cotizacionEdicion = $stmtEdicion->get_result()->fetch_assoc();
        $stmtEdicion->close();
        if (!$cotizacionEdicion) {
            die('La cotización que desea modificar no existe.');
        }
        if (($cotizacionEdicion['estado'] ?? '') === 'ANULADA') { header('Location: cotizaciones.php?anulada_bloqueada=1'); exit; }
        /* v48: una cotización convertida en pedido queda congelada como documento histórico.
         * Toda modificación posterior debe hacerse sobre el pedido y generar una revisión. */
        if ((int)($cotizacionEdicion['tiene_pedido'] ?? 0) > 0) {
            header('Location: cotizaciones.php?bloqueada=1');
            exit;
        }
        $datosGuardados = json_decode((string)$cotizacionEdicion['datos_formulario'], true);
        if (!is_array($datosGuardados)) $datosGuardados = array();
        $datosGuardados['id_cliente'] = (int)$cotizacionEdicion['cliente_id'];
        $datosGuardados['lista_id'] = (int)$cotizacionEdicion['lista_id'];
        $datosGuardados['referencia_cotizacion'] = (string)$cotizacionEdicion['referencia'];
        $datosGuardados['descuento_1'] = (int)$cotizacionEdicion['descuento_1'];
        $datosGuardados['descuento_2'] = (int)$cotizacionEdicion['descuento_2'];
        $datosGuardados['descuento_3'] = (int)$cotizacionEdicion['descuento_3'];
        $_SESSION['form_data'] = $datosGuardados;
    }
}



$datos = $_SESSION['form_data'] ?? [];
if (isset($_GET['modo_precios']) && in_array($_GET['modo_precios'], array('MANTENER','ACTUALIZAR'), true)) { $datos['modo_precios_documento']=$_GET['modo_precios']; $_SESSION['form_data']=$datos; }
$error = $_SESSION['form_error'] ?? '';

/* Estado modular guardado: permite reconstruir el documento completo al modificar
 * una cotización o un pedido, incluidos IEP, Accesorios y Repuestos. */
$itemsModularesGuardados = array('ACCESORIOS'=>array(), 'IEP'=>array(), 'REPUESTOS'=>array());
$modsGuardados = (array)($datos['modulo_item_modulo'] ?? array());
$conceptosGuardados = (array)($datos['modulo_item_concepto'] ?? array());
$codigosGuardados = (array)($datos['modulo_item_codigo'] ?? array());
$descripcionesGuardadas = (array)($datos['modulo_item_descripcion'] ?? array());
$cantidadesGuardadas = (array)($datos['modulo_item_cantidad'] ?? array());
$preciosGuardados = (array)($datos['modulo_item_precio'] ?? array());
$preciosBaseGuardados = (array)($datos['modulo_item_precio_base'] ?? array());
$bonificadosGuardados = (array)($datos['modulo_item_bonificado'] ?? array());
$totalItemsGuardados = max(count($modsGuardados), count($conceptosGuardados), count($codigosGuardados), count($descripcionesGuardadas), count($cantidadesGuardadas), count($preciosGuardados), count($preciosBaseGuardados), count($bonificadosGuardados));
for ($iGuardado=0; $iGuardado<$totalItemsGuardados; $iGuardado++) {
    $modGuardado = strtoupper(trim((string)($modsGuardados[$iGuardado] ?? '')));
    if (!isset($itemsModularesGuardados[$modGuardado])) continue;
    $itemsModularesGuardados[$modGuardado][] = array(
        'concepto'=>(string)($conceptosGuardados[$iGuardado] ?? ''),
        'codigo'=>(string)($codigosGuardados[$iGuardado] ?? ''),
        'descripcion'=>(string)($descripcionesGuardadas[$iGuardado] ?? ''),
        'cantidad'=>(string)($cantidadesGuardadas[$iGuardado] ?? '1'),
        'precio'=>(string)($preciosGuardados[$iGuardado] ?? ''),
        'precio_base'=>(string)($preciosBaseGuardados[$iGuardado] ?? ''),
        'bonificado'=>!empty($bonificadosGuardados[$iGuardado]) ? '1' : '0'
    );
}


/* v461: compatibilidad con cotizaciones/pedidos ya emitidos. Algunas versiones
 * anteriores valorizaban los accesorios especiales en el detalle pero no los
 * incluían en el snapshot modular datos_formulario. Al editar, el detalle
 * histórico completa únicamente las líneas faltantes de ACCESORIOS. */
$detalleEdicionTablaV461 = '';
$detalleEdicionCampoV461 = '';
$detalleEdicionIdV461 = 0;
if ($cotizacionEdicionId > 0) {
    $detalleEdicionTablaV461 = 'cotizaciones_detalle';
    $detalleEdicionCampoV461 = 'cotizacion_id';
    $detalleEdicionIdV461 = $cotizacionEdicionId;
} elseif ($pedidoEdicionId > 0) {
    $detalleEdicionTablaV461 = 'pedidos_detalle';
    $detalleEdicionCampoV461 = 'pedido_id';
    $detalleEdicionIdV461 = $pedidoEdicionId;
}
if ($detalleEdicionIdV461 > 0) {
    $firmasV461 = array();
    foreach ($itemsModularesGuardados['ACCESORIOS'] as $itV461) {
        $firmasV461[strtoupper(trim((string)($itV461['codigo'] ?? ''))) . '|' . strtoupper(trim((string)($itV461['concepto'] ?? '')))] = true;
    }
    $sqlV461 = "SELECT concepto,codigo,descripcion,cantidad,precio_unitario FROM {$detalleEdicionTablaV461} WHERE {$detalleEdicionCampoV461}=? AND UPPER(COALESCE(modulo,''))='ACCESORIOS' ORDER BY orden_visual, detalle_id";
    $stV461 = $conexion->prepare($sqlV461);
    if ($stV461) {
        $stV461->bind_param('i', $detalleEdicionIdV461);
        $stV461->execute();
        $rsV461 = $stV461->get_result();
        while ($rV461 = $rsV461->fetch_assoc()) {
            $firmaV461 = strtoupper(trim((string)($rV461['codigo'] ?? ''))) . '|' . strtoupper(trim((string)($rV461['concepto'] ?? '')));
            if (isset($firmasV461[$firmaV461])) continue;
            $itemsModularesGuardados['ACCESORIOS'][] = array(
                'concepto'=>(string)($rV461['concepto'] ?? ''),
                'codigo'=>(string)($rV461['codigo'] ?? ''),
                'descripcion'=>(string)($rV461['descripcion'] ?? ''),
                'cantidad'=>(string)($rV461['cantidad'] ?? '1'),
                'precio'=>(string)($rV461['precio_unitario'] ?? '0'),
                'precio_base'=>(string)($rV461['precio_unitario'] ?? '0'),
                'bonificado'=>stripos((string)($rV461['descripcion'] ?? ''), 'BONIFICADO') !== false ? '1' : '0'
            );
            $firmasV461[$firmaV461] = true;
        }
        $stV461->close();
    }
}

/* Catálogo extensible de límites. La estructura se administra mediante migraciones SQL. */
require_once __DIR__ . '/schema_guard.php';
try {
    verificarTablaColumnas($conexion, 'limites', array('limite_id','limite_nombre','limite_codigo','limite_activo','limite_orden'), 'migracion_consolidacion_v59.sql');
} catch (Throwable $e) {
    die('Falta preparar el catálogo de límites: ' . escapar($e->getMessage()));
}

$limiteGuardado = null;
$accesoriosGenericosGuardados = array();
foreach ($itemsModularesGuardados['ACCESORIOS'] as $itemAcc) {
    if ($limiteGuardado === null && esItemLimiteAccesorio($itemAcc)) $limiteGuardado = $itemAcc;
    else $accesoriosGenericosGuardados[] = $itemAcc;
}
$itemsModularesGuardados['ACCESORIOS'] = $accesoriosGenericosGuardados;

$accesorioDescuento1 = isset($datos['accesorio_descuento_1']) ? (int)$datos['accesorio_descuento_1'] : $defaultAccD1;
$accesorioDescuento2 = isset($datos['accesorio_descuento_2']) ? (int)$datos['accesorio_descuento_2'] : $defaultAccD2;
$accesorioDescuento3 = isset($datos['accesorio_descuento_3']) ? (int)$datos['accesorio_descuento_3'] : $defaultAccD3;
$factorAccesoriosInicial = factorDescuentoAccesoriosServidor($accesorioDescuento1, $accesorioDescuento2, $accesorioDescuento3);

/* Subtotales persistidos del documento en edición. Se cargan antes de cualquier
 * recálculo auxiliar para que el resumen lateral nunca arranque vacío o en
 * "pendiente" por el simple hecho de abrir otra pestaña del cotizador. */
$subtotalesInicialesDocumento = array('CONTROL'=>0.0,'SENALIZACION'=>0.0,'ACCESORIOS'=>0.0,'IEP'=>0.0,'REPUESTOS'=>0.0);
if ($cotizacionEdicionId > 0) {
    $stSub = $conexion->prepare("SELECT UPPER(COALESCE(NULLIF(modulo,''),'CONTROL')) AS modulo, SUM(importe_total) AS total FROM cotizaciones_detalle WHERE cotizacion_id=? GROUP BY UPPER(COALESCE(NULLIF(modulo,''),'CONTROL'))");
    if ($stSub) {
        $stSub->bind_param('i',$cotizacionEdicionId); $stSub->execute(); $rsSub=$stSub->get_result();
        while($rSub=$rsSub->fetch_assoc()){ $mSub=(string)$rSub['modulo']; if(isset($subtotalesInicialesDocumento[$mSub])) $subtotalesInicialesDocumento[$mSub]=(float)$rSub['total']; }
        $stSub->close();
    }
} elseif ($pedidoEdicionId > 0) {
    $stSub = $conexion->prepare("SELECT UPPER(COALESCE(NULLIF(modulo,''),'CONTROL')) AS modulo, SUM(importe_total) AS total FROM pedidos_detalle WHERE pedido_id=? GROUP BY UPPER(COALESCE(NULLIF(modulo,''),'CONTROL'))");
    if ($stSub) {
        $stSub->bind_param('i',$pedidoEdicionId); $stSub->execute(); $rsSub=$stSub->get_result();
        while($rSub=$rsSub->fetch_assoc()){ $mSub=(string)$rSub['modulo']; if(isset($subtotalesInicialesDocumento[$mSub])) $subtotalesInicialesDocumento[$mSub]=(float)$rSub['total']; }
        $stSub->close();
    }
}

$listaVigente = obtenerListaVigente($conexion);
$listasBejermanDisponibles = function_exists('obtenerListasBejermanDisponibles') ? obtenerListasBejermanDisponibles($conexion) : array_filter(array($listaVigente));
$editandoDocumento = $cotizacionEdicionId > 0 || $pedidoEdicionId > 0;
$listaDocumentoId = (int)($datos['lista_id'] ?? 0);
$modoPreciosDocumento = isset($datos['modo_precios_documento']) ? (string)$datos['modo_precios_documento'] : ($editandoDocumento ? 'MANTENER' : 'ACTUALIZAR');
$listaSolicitadaId = ($editandoDocumento && $modoPreciosDocumento === 'MANTENER') ? $listaDocumentoId : (int)($listaVigente['lista_id'] ?? 0);
$listaPrecioSeleccionada = obtenerListaSeleccionada($conexion, $listaSolicitadaId);
if ($listaPrecioSeleccionada) { $datos['lista_id'] = (int)$listaPrecioSeleccionada['lista_id']; }
$baseDocumentoEsHistorica = $editandoDocumento && $listaDocumentoId > 0 && (int)($listaVigente['lista_id'] ?? 0) !== $listaDocumentoId;
$catalogoLimites = array();
// Solo se ofrecen limites ACTIVOS en nuevas selecciones. Se consulta el estado
// y se vuelve a validar en PHP para evitar que un dato invalido/legacy aparezca.
$resLimites = $conexion->query("SELECT limite_codigo, limite_nombre, limite_activo FROM limites WHERE UPPER(TRIM(limite_activo))='SI' ORDER BY limite_orden, limite_nombre");
if ($resLimites) {
    while ($lim = $resLimites->fetch_assoc()) {
        if (strtoupper(trim((string)($lim['limite_activo'] ?? 'NO'))) !== 'SI') continue;
        $precioLim = precioDeLista($conexion, (int)($listaPrecioSeleccionada['lista_id'] ?? 0), (string)$lim['limite_codigo']);
        $lim['precio'] = (float)($precioLim['precios_costo'] ?? 0);
        $lim['descripcion_precio'] = (string)($precioLim['precios_descripcion'] ?? $lim['limite_nombre']);
        $catalogoLimites[] = $lim;
    }
}

/* Catálogo general de Accesorios. Los activos se ofrecen directamente en el cotizador.
 * CODIGO_DIRECTO toma el precio comercial de la lista seleccionada. Si el código contiene
 * varios artículos unidos por + (ej. A7250GTC+F12V1A), se suman sus precios.
 * CALCULO_ESPECIAL queda seleccionable y permite ingresar el precio base manualmente hasta
 * que implementemos su fórmula específica. */
$catalogoAccesorios = array();
$catalogoAccesoriosGuardados = array();
$accesoriosRestantesGuardados = array();
$resAccCat = $conexion->query("SELECT * FROM accesorios_catalogo WHERE UPPER(TRIM(accesorio_activo))='SI' ORDER BY accesorio_orden, accesorio_nombre");
if ($resAccCat) {
    while ($acc = $resAccCat->fetch_assoc()) {
        if (($acc['accesorio_tipo'] ?? '') === 'LIMITES') continue;
        $acc['precio_base'] = 0.0;
        $acc['precio_disponible'] = false;
        if (($acc['accesorio_tipo'] ?? '') === 'CODIGO_DIRECTO' && trim((string)($acc['accesorio_codigo'] ?? '')) !== '') {
            $partes = array_values(array_filter(array_map('trim', explode('+', (string)$acc['accesorio_codigo']))));
            $suma = 0.0; $todos = count($partes) > 0;
            foreach ($partes as $codigoParte) {
                $artParte = precioDeLista($conexion, (int)($listaPrecioSeleccionada['lista_id'] ?? 0), $codigoParte);
                $pv = (float)($artParte['precios_costo'] ?? 0);
                if ($pv <= 0) { $todos = false; break; }
                $suma += $pv;
            }
            if ($todos && $suma > 0) { $utilidadAcc = isset($acc['accesorio_utilidad']) && is_numeric($acc['accesorio_utilidad']) ? max(0.0001,(float)$acc['accesorio_utilidad']) : 1.0; $acc['precio_base'] = $suma * $utilidadAcc; $acc['precio_disponible'] = true; }
        }
        $catalogoAccesorios[] = $acc;
    }
}
$utilidadesAccesoriosV224 = array();
foreach ($catalogoAccesorios as $accU) {
    $utilidadesAccesoriosV224[(string)($accU['accesorio_clave'] ?? '')] = isset($accU['accesorio_utilidad']) && is_numeric($accU['accesorio_utilidad']) ? max(0.0001,(float)$accU['accesorio_utilidad']) : 1.0;
}
// La utilidad de Límites se toma del catálogo aunque el modelo físico esté en la tabla limites.
$utilidadLimitesV224 = 1.0;
if (isset($utilidadesAccesoriosV224['LIMITES_SOPORTE'])) {
    $utilidadLimitesV224 = (float)$utilidadesAccesoriosV224['LIMITES_SOPORTE'];
} else {
    if (esquemaColumnaExiste($conexion,'accesorios_catalogo','accesorio_utilidad')) {
        $qUtilLimV224 = $conexion->query("SELECT accesorio_utilidad FROM accesorios_catalogo WHERE accesorio_clave='LIMITES_SOPORTE' LIMIT 1");
        if ($qUtilLimV224 && ($uLimV224=$qUtilLimV224->fetch_assoc()) && is_numeric($uLimV224['accesorio_utilidad'] ?? null)) $utilidadLimitesV224=max(0.0001,(float)$uLimV224['accesorio_utilidad']);
    }
}
foreach ($catalogoLimites as &$limV224) {
    if (isset($limV224['precio']) && is_numeric($limV224['precio'])) $limV224['precio'] = (float)$limV224['precio'] * $utilidadLimitesV224;
}
unset($limV224);

/* Relaciona líneas guardadas con el catálogo para reconstruir cotizaciones en edición. */
$indicesConsumidos = array();
foreach ($catalogoAccesorios as $acc) {
    $claveCatalogoAcc = strtoupper(trim((string)($acc['accesorio_clave'] ?? '')));
    // v461: los configurables se restauran por su lógica específica. No deben
    // consumirse como accesorios genéricos al abrir una cotización existente.
    if (in_array($claveCatalogoAcc, array('SINTETIZADORES_VOZ','BARRERAS','LIMITES_SOPORTE','SISTEMAS_SUPERVISORES','PESADOR_CARGA','CABLE_MALLADO'), true)) {
        continue;
    }
    $encontrado = null;
    foreach ($itemsModularesGuardados['ACCESORIOS'] as $idxAcc => $itemAcc) {
        if (isset($indicesConsumidos[$idxAcc])) continue;
        $codigoItem = strtoupper(trim((string)($itemAcc['codigo'] ?? '')));
        $conceptoItem = strtoupper(trim((string)($itemAcc['concepto'] ?? '')));
        $nombreAcc = strtoupper(trim((string)($acc['accesorio_nombre'] ?? '')));
        $codigoAcc = strtoupper(trim((string)($acc['accesorio_codigo'] ?? '')));
        $coincide = ($codigoAcc !== '' && $codigoItem === $codigoAcc) || ($codigoAcc === '' && $conceptoItem === $nombreAcc);
        if ($coincide) { $encontrado = $itemAcc; $indicesConsumidos[$idxAcc] = true; break; }
    }
    if ($encontrado !== null) $catalogoAccesoriosGuardados[(string)$acc['accesorio_clave']] = $encontrado;
}
foreach ($itemsModularesGuardados['ACCESORIOS'] as $idxAcc => $itemAcc) {
    if (!isset($indicesConsumidos[$idxAcc])) $accesoriosRestantesGuardados[] = $itemAcc;
}
$itemsModularesGuardados['ACCESORIOS'] = $accesoriosRestantesGuardados;

/* v33 - Parametrización de los cálculos especiales de Accesorios. */
$sintetizadoresVoz=cargarTablaAccesorioEspecial($conexion,'accesorios_sintetizadores_voz','orden, sintetizador_id');
$barrerasAccesorios=cargarTablaAccesorioEspecial($conexion,'accesorios_barreras','orden, barrera_id');
$supervisorReglas=cargarTablaAccesorioEspecial($conexion,'accesorios_supervisor_reglas','ascensores_desde');
$supervisorComponentes=cargarTablaAccesorioEspecial($conexion,'accesorios_supervisor_componentes','componente_clave');
$cablesMallados=cargarTablaAccesorioEspecial($conexion,'accesorios_cable_mallado','corriente_desde');
$pesadoresBase=cargarTablaAccesorioEspecial($conexion,'accesorios_pesador_base','tipo');
$pesadoresFrentes=cargarTablaAccesorioEspecial($conexion,'accesorios_pesador_frentes','orden');
$controlAccesoReglas=cargarTablaAccesorioEspecial($conexion,'accesorios_control_acceso_reglas','tecnologia, alcance');


/* v461 - Reconstrucción de accesorios configurables al modificar documentos.
 * El detalle modular guardado es la fuente histórica del documento emitido.
 * Se separan estas líneas para que el editor específico recupere su estado y
 * no vuelva a ofrecer como "nuevo" un accesorio ya cotizado. */
$accesoriosConfigurablesGuardados = array(
    'SINT_A7600C' => array(),
    'BARRERAS' => array(),
    'PESADOR' => null,
    'SUPERVISOR' => null,
    'CABLE_MALLADO' => null
);
$codigosBarrerasV461 = array();
foreach ($barrerasAccesorios as $rV461) {
    $cV461 = strtoupper(trim((string)($rV461['barrera_codigo'] ?? '')));
    if ($cV461 !== '') $codigosBarrerasV461[$cV461] = true;
}
$codigosPesadoresV461 = array();
foreach ($pesadoresBase as $rV461) {
    $cV461 = strtoupper(trim((string)($rV461['codigo'] ?? '')));
    if ($cV461 !== '') $codigosPesadoresV461[$cV461] = true;
}
$codigosCableV461 = array();
foreach ($cablesMallados as $rV461) {
    $cV461 = strtoupper(trim((string)($rV461['codigo'] ?? '')));
    if ($cV461 !== '') $codigosCableV461[$cV461] = true;
}
$restantesAccesoriosV461 = array();
foreach ($itemsModularesGuardados['ACCESORIOS'] as $itV461) {
    $codV461 = strtoupper(trim((string)($itV461['codigo'] ?? '')));
    $conV461 = strtoupper(trim((string)($itV461['concepto'] ?? '')));
    $desV461 = strtoupper(trim((string)($itV461['descripcion'] ?? '')));
    $textoV461 = $conV461 . ' ' . $desV461;
    if ($codV461 === 'A7600C' || strpos($textoV461, 'SINTETIZADOR') !== false && strpos($textoV461, 'BAFLE') !== false) {
        $accesoriosConfigurablesGuardados['SINT_A7600C'][] = $itV461;
        continue;
    }
    if (isset($codigosBarrerasV461[$codV461]) || strpos($textoV461, 'BARRERA') !== false) {
        $accesoriosConfigurablesGuardados['BARRERAS'][] = $itV461;
        continue;
    }
    if (isset($codigosPesadoresV461[$codV461]) || strpos($textoV461, 'PESADOR DE CARGA') !== false) {
        $accesoriosConfigurablesGuardados['PESADOR'] = $itV461;
        continue;
    }
    if (isset($codigosCableV461[$codV461]) || strpos($textoV461, 'CABLE MALLADO') !== false) {
        $accesoriosConfigurablesGuardados['CABLE_MALLADO'] = $itV461;
        continue;
    }
    if (strpos($textoV461, 'SISTEMA SUPERVISOR') !== false || strpos($textoV461, 'SUPERVISOR NETO') !== false) {
        $accesoriosConfigurablesGuardados['SUPERVISOR'] = $itV461;
        continue;
    }
    $restantesAccesoriosV461[] = $itV461;
}
$itemsModularesGuardados['ACCESORIOS'] = $restantesAccesoriosV461;
$senalPulsadoresExtMatriz=cargarTablaAccesorioEspecial($conexion,'senal_pulsadores_exteriores_matriz','familia, orden, id');
$senalPulsadoresExtIndicadores=cargarTablaAccesorioEspecial($conexion,'senal_pulsadores_exteriores_indicadores','familia, orden, id');
$senalIndicadoresExteriorCatalogo=cargarTablaAccesorioEspecial($conexion,'senal_indicadores_cabina','orden, id');
$senalIndicadoresExteriorIndependientes=array();
if(esquemaTablaExiste($conexion,'senal_indicadores_contextos')){
    $contextosPorCodigo=array();$contextosConfigurados=array();
    $rc=$conexion->query('SELECT codigo,contexto,activo FROM senal_indicadores_contextos ORDER BY orden,codigo');
    if($rc)while($cx=$rc->fetch_assoc()){$key=strtoupper(trim((string)$cx['codigo']));$contextosConfigurados[$key]=true;if((int)$cx['activo']===1)$contextosPorCodigo[$key][strtoupper(trim((string)$cx['contexto']))]=true;}
    foreach($senalIndicadoresExteriorCatalogo as &$indicadorCatalogo){$key=strtoupper(trim((string)($indicadorCatalogo['codigo']??'')));$indicadorCatalogo['contextos_configurados']=isset($contextosConfigurados[$key])?1:0;$indicadorCatalogo['contextos_permitidos']=array_keys($contextosPorCodigo[$key]??array());if(empty($contextosConfigurados[$key])||isset($contextosPorCodigo[$key]['EXTERIOR_INDEPENDIENTE']))$senalIndicadoresExteriorIndependientes[]=$indicadorCatalogo;}
    unset($indicadorCatalogo);
}else{$senalIndicadoresExteriorIndependientes=$senalIndicadoresExteriorCatalogo;}
$senalAcabadosCoeficientes=cargarTablaAccesorioEspecial($conexion,'senal_acabados_coeficientes','acabado, medida_especial');

if(esquemaTablaExiste($conexion,'senal_modelos_perfiles')){
    $perfilesExteriores=array();$rp=$conexion->query("SELECT m.modelo_pulsador_nombre,p.modo_base,p.tipo_modulo_requerido,p.indicador_pulsador,p.modo_pulsador_exterior,p.separar_indicador_exterior,p.politica_acabado,p.acabado FROM senal_modelos_pulsador m INNER JOIN senal_modelos_perfiles p ON p.modelo_pulsador_id=m.modelo_pulsador_id AND p.activo=1");
    if($rp)while($perfilExt=$rp->fetch_assoc()){$nombre=strtoupper(trim((string)$perfilExt['modelo_pulsador_nombre']));$perfilesExteriores[$nombre]=$perfilExt;if($nombre==='ROND METAL')$perfilesExteriores['METAL']=$perfilExt;}
    foreach($senalPulsadoresExtMatriz as &$filaPulsadorExterior){$nombre=strtoupper(trim((string)($filaPulsadorExterior['modelo_pulsador']??'')));if(isset($perfilesExteriores[$nombre]))$filaPulsadorExterior['_perfil_modelo']=$perfilesExteriores[$nombre];}
    unset($filaPulsadorExterior);
}

$codigosEspeciales=array('A7601C','A7600C','A4820SV','A2164C','A21642RC','A2167C','BMX174C','A68S32.2','A68S32.4','A68S32.6','A68S32.8','A68S63.XX','A6811C','7C4NMALLA','7C6NMALLA','7C10N/MALLA','7C16NMALLA','A2800C','A2803C','A2804C','A2802C','A2807C','A2808C','A3700CCP','A3700CCB','A3700CPP','A3700CPB','A3700X','A3710CTP','A3710CTB','A3710X','A3700LU','A3701TUHW');
$preciosEspeciales=array();
foreach($codigosEspeciales as $codigoEsp){
    $pp=precioDeLista($conexion,(int)($listaPrecioSeleccionada['lista_id']??0),$codigoEsp);
    $preciosEspeciales[$codigoEsp]=$pp?(float)($pp['precios_costo']??0):0.0;
}

$modoPlantilla = (string)($_GET['modo_plantilla'] ?? '');
$plantillaEdicion = null;
$plantillaAplicada = null;

if (isset($_GET['aplicar_plantilla'])) {
    $plantillaIdAplicar = filter_input(INPUT_GET, 'aplicar_plantilla', FILTER_VALIDATE_INT);
    if ($plantillaIdAplicar) {
        $plantillaAplicada = obtenerPlantillaControl($conexion, (int)$plantillaIdAplicar);
        if ($plantillaAplicada) {
            /* La plantilla modifica solamente la configuracion tecnica.
             * La cabecera comercial ingresada por el usuario debe conservarse. */
            $cabeceraActual = array(
                'id_cliente' => (int)($_GET['id_cliente_actual'] ?? ($datos['id_cliente'] ?? 0)),
                'referencia_cotizacion' => (string)($_GET['referencia_actual'] ?? ($datos['referencia_cotizacion'] ?? '')),
                'solicitante_cliente' => (string)($_GET['solicitante_actual'] ?? ($datos['solicitante_cliente'] ?? '')),
                'lista_id' => (int)($_GET['lista_id_actual'] ?? ($datos['lista_id'] ?? 0)),
                'descuento_1' => (int)($_GET['descuento_1_actual'] ?? ($datos['descuento_1'] ?? 0)),
                'descuento_2' => (int)($_GET['descuento_2_actual'] ?? ($datos['descuento_2'] ?? 0)),
                'descuento_3' => (int)($_GET['descuento_3_actual'] ?? ($datos['descuento_3'] ?? 0))
            );
            $datos = array_merge($datos, $plantillaAplicada['configuracion'], $cabeceraActual);
            $_SESSION['form_data'] = $datos;
        } else {
            $error = 'La plantilla seleccionada no existe.';
        }
    }
}

if ($modoPlantilla === 'editar') {
    $plantillaIdEditar = filter_input(INPUT_GET, 'plantilla_id', FILTER_VALIDATE_INT);
    if ($plantillaIdEditar) {
        $plantillaEdicion = obtenerPlantillaControl($conexion, (int)$plantillaIdEditar);
        if ($plantillaEdicion) {
            $datos = array_merge($datos, $plantillaEdicion['configuracion']);
        } else {
            $error = 'La plantilla que desea modificar no existe.';
            $modoPlantilla = '';
        }
    }
}

$plantillasDisponibles = $conexion->query("SELECT plantilla_id, plantilla_codigo, plantilla_nombre FROM plantillas_controles WHERE plantilla_activa='SI' ORDER BY plantilla_codigo, plantilla_id");

unset($_SESSION['form_error']);


/* Combinaciones habilitadas para el adicional de posicionamiento por encoder. */
$posicionamientoEncoderPermitido = array();
$tablaPosicionamientoExiste = esquemaTablaExiste($conexion,'posicionamiento_encoder_permitido');
if ($tablaPosicionamientoExiste) {
    $resultadoPosicionamiento = $conexion->query(
        "SELECT posenc_cpu, posenc_tipo
         FROM posicionamiento_encoder_permitido
         WHERE posenc_habilitado = 'SI'"
    );
    if ($resultadoPosicionamiento) {
        while ($filaPosicionamiento = $resultadoPosicionamiento->fetch_assoc()) {
            $clavePosicionamiento = (int)$filaPosicionamiento['posenc_cpu'] . ':' . (int)$filaPosicionamiento['posenc_tipo'];
            $posicionamientoEncoderPermitido[$clavePosicionamiento] = true;
        }
    }
}
$rescatesHidraulicos = array();
$rescatesMrl = array();
$compatibilidadesRescate = array();
$tablaCompatibilidadesRescate = esquemaTablaExiste($conexion,'rescates_compatibilidades');
if ($tablaCompatibilidadesRescate) {
    $rc = $conexion->query("SELECT rescate_id,tipo_control_id,subtipo_control_id,corriente_clave FROM rescates_compatibilidades WHERE activo='SI' ORDER BY orden,compatibilidad_id");
    if ($rc) while ($filaCompatibilidad = $rc->fetch_assoc()) {
        $compatibilidadesRescate[(int)$filaCompatibilidad['rescate_id']][] = array(
            'tipo'=>(int)$filaCompatibilidad['tipo_control_id'],
            'subtipo'=>$filaCompatibilidad['subtipo_control_id'] === null ? 0 : (int)$filaCompatibilidad['subtipo_control_id'],
            'corriente'=>$filaCompatibilidad['corriente_clave'] === null ? 0 : (int)$filaCompatibilidad['corriente_clave']
        );
    }
}
$tablaRescatesExiste = esquemaTablaExiste($conexion,'rescates_opciones');
if ($tablaRescatesExiste) {
    $rr = $conexion->query("SELECT rescate_id, rescate_clave, rescate_nombre, rescate_familia FROM rescates_opciones WHERE rescate_activo='SI' ORDER BY rescate_familia, rescate_orden, rescate_id");
    if ($rr) while ($filaRescate = $rr->fetch_assoc()) {
        $filaRescate['compatibilidades'] = $compatibilidadesRescate[(int)$filaRescate['rescate_id']] ?? array();
        if ($filaRescate['rescate_familia'] === 'HIDRAULICO') $rescatesHidraulicos[] = $filaRescate;
        if ($filaRescate['rescate_familia'] === 'MRL_IMAN') $rescatesMrl[] = $filaRescate;
    }
}

$forzarPanelPresupuestoOculto = !empty($_SESSION['forzar_panel_presupuesto_oculto']);
unset($_SESSION['forzar_panel_presupuesto_oculto']);
