<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

$pedidoClienteBusqueda = isset($_GET['pedido_cliente']) ? trim((string)$_GET['pedido_cliente']) : '';
$referenciaBusqueda = isset($_GET['referencia']) ? trim((string)$_GET['referencia']) : '';
$responsableBusqueda = isset($_GET['responsable']) ? trim((string)$_GET['responsable']) : '';
$pedidoModificado = isset($_GET['modificado']) ? (int)$_GET['modificado'] : 0;

$sql = "SELECT p.*, c.cotizacion_numero, COALESCE(p.referencia,c.referencia) AS referencia,
               cl.clientes_codigo, cl.clientes_nomfantasia,
               l.lista_nombre,l.lista_fecha_archivo,l.lista_estado, COALESCE(u.usuario_nombre,p.usuario,'') AS ejecutado_por
          FROM pedidos p
          LEFT JOIN cotizaciones c ON c.cotizacion_id = p.cotizacion_id
          JOIN clientes cl ON cl.clientes_id = p.cliente_id
          JOIN listas_precios_importaciones l ON l.lista_id = p.lista_id
          LEFT JOIN usuarios u ON u.usuario_id = p.usuario_id";

$condiciones = array();
$parametros = array();
$tipos = '';

if ($pedidoClienteBusqueda !== '') {
    $terminoPedidoCliente = '%' . $pedidoClienteBusqueda . '%';
    if (preg_match('/^\s*(P|#)[\s._-]*0*([0-9]+)\s*$/i', $pedidoClienteBusqueda, $mNumeroPedido)) {
        $prefijoPedido = strtoupper($mNumeroPedido[1]);
        $numeroPedido = (string)((int)$mNumeroPedido[2]);
        $regexpPedido = '^' . preg_quote($prefijoPedido, '/') . '[^0-9]*0*' . preg_quote($numeroPedido, '/') . '$';
        $condiciones[] = "(p.pedido_numero LIKE ? OR p.pedido_numero REGEXP ? OR cl.clientes_codigo LIKE ? OR cl.clientes_nomfantasia LIKE ? OR cl.clientes_razonsocial LIKE ?)";
        array_push($parametros, $terminoPedidoCliente, $regexpPedido, $terminoPedidoCliente, $terminoPedidoCliente, $terminoPedidoCliente);
        $tipos .= 'sssss';
    } else {
        $condiciones[] = "(p.pedido_numero LIKE ? OR cl.clientes_codigo LIKE ? OR cl.clientes_nomfantasia LIKE ? OR cl.clientes_razonsocial LIKE ?)";
        array_push($parametros, $terminoPedidoCliente, $terminoPedidoCliente, $terminoPedidoCliente, $terminoPedidoCliente);
        $tipos .= 'ssss';
    }
}
if ($referenciaBusqueda !== '') {
    $condiciones[] = "COALESCE(p.referencia,c.referencia) LIKE ?";
    $parametros[] = '%' . $referenciaBusqueda . '%';
    $tipos .= 's';
}
if ($responsableBusqueda !== '') {
    $condiciones[] = "COALESCE(u.usuario_nombre,p.usuario,'') LIKE ?";
    $parametros[] = '%' . $responsableBusqueda . '%';
    $tipos .= 's';
}
if ($condiciones) $sql .= ' WHERE ' . implode(' AND ', $condiciones);

$sql .= " ORDER BY p.pedido_id DESC";

if ($parametros) {
    $stmt = $conexion->prepare($sql);
    if (!$stmt) {
        die('No se pudo preparar la búsqueda de pedidos: ' . htmlspecialchars($conexion->error));
    }
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $r = $stmt->get_result();
} else {
    $r = $conexion->query($sql);
}

if (!$r) {
    die('No se pudieron consultar los pedidos: ' . htmlspecialchars($conexion->error));
}

function e($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$detallesPorPedido = array();
$idsPedidos = array();
$filasPedidos = array();
while ($filaPedido = $r->fetch_assoc()) {
    $filasPedidos[] = $filaPedido;
    $idsPedidos[] = (int)$filaPedido['pedido_id'];
}
$filasObras = array();
$filasSuministros = array();
foreach ($filasPedidos as $filaPedido) {
    if (($filaPedido['pedido_tipo'] ?? 'OBRA') === 'SUMINISTROS') {
        $filasSuministros[] = $filaPedido;
    } else {
        $filasObras[] = $filaPedido;
    }
}
if (count($idsPedidos) > 0) {
    $idsSql = implode(',', array_map('intval', $idsPedidos));
    $rd = $conexion->query("SELECT pedido_id, orden_visual, concepto, codigo, descripcion, cantidad, precio_unitario, importe_total FROM pedidos_detalle WHERE pedido_id IN ($idsSql) ORDER BY pedido_id, orden_visual");
    if ($rd) {
        while ($detalle = $rd->fetch_assoc()) {
            $pidDetalle = (int)$detalle['pedido_id'];
            if (!isset($detallesPorPedido[$pidDetalle])) $detallesPorPedido[$pidDetalle] = array();
            $detallesPorPedido[$pidDetalle][] = $detalle;
        }
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Pedidos</title>
<!-- V58: estados compactos y menú superior forzado desde menu.php -->
<style>
body{font-family:Arial;background:#f4f4f9;margin:18px;color:#202124}
.caja{background:white;padding:18px;border-radius:8px;max-width:1300px;margin:auto}
nav{margin-bottom:14px}
nav a,.btn{display:inline-block;padding:8px 12px;margin:2px;background:#343a40;color:white;text-decoration:none;border-radius:5px;border:0;font-weight:bold}
.busqueda{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0 0 16px;padding:14px;background:#f8f9fa;border:1px solid #ddd;border-radius:7px}
.busqueda .campo{flex:1;min-width:230px}.busqueda .campo.referencia{flex:.75}.busqueda .campo.responsable{flex:.65}
.busqueda label{display:block;font-size:13px;font-weight:bold;margin-bottom:5px}
.busqueda input{width:100%;box-sizing:border-box;height:38px;padding:7px 10px;border:1px solid #aaa;border-radius:5px;font-size:14px}
.tabla-wrap{width:100%;overflow-x:hidden}
table{width:100%;border-collapse:collapse;font-size:13px;table-layout:fixed}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:middle;overflow-wrap:anywhere}
th{background:#212529;color:white}
.tabla-pedidos th:nth-child(1),.tabla-pedidos td:nth-child(1){width:8%}
.tabla-pedidos th:nth-child(2),.tabla-pedidos td:nth-child(2){width:15%}
.tabla-pedidos th:nth-child(3),.tabla-pedidos td:nth-child(3){width:10%}
.tabla-pedidos th:nth-child(4),.tabla-pedidos td:nth-child(4){width:8%}
.tabla-pedidos th:nth-child(5),.tabla-pedidos td:nth-child(5){width:11%}
.tabla-pedidos th:nth-child(6),.tabla-pedidos td:nth-child(6){width:11%}
.tabla-pedidos th:nth-child(7),.tabla-pedidos td:nth-child(7){width:9%}
.tabla-pedidos th:nth-child(8),.tabla-pedidos td:nth-child(8){width:10%}
.tabla-pedidos th:nth-child(9),.tabla-pedidos td:nth-child(9){width:18%}
.seccion-listado{margin:14px 0 8px;display:flex;align-items:center;justify-content:space-between;gap:12px}.seccion-listado h2{margin:0;font-size:18px}.seccion-listado .cantidad{font-size:12px;font-weight:700;color:#5c6770;background:#eef3f6;border:1px solid #d8e1e7;padding:4px 8px;border-radius:999px}
.tabs-doc{display:flex;gap:8px;margin:14px 0 8px;border-bottom:1px solid #d8e1e7;padding:0 2px}.tab-doc{appearance:none;border:1px solid #d8e1e7;border-bottom:0;background:#eef3f6;color:#314657;padding:10px 16px;border-radius:9px 9px 0 0;font-weight:800;cursor:pointer}.tab-doc:hover{background:#e4edf2}.tab-doc.activa{background:#fff;color:#0f6d45;position:relative}.tab-doc.activa:after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;background:#fff}.tab-doc .tab-count{display:inline-block;margin-left:7px;min-width:20px;padding:2px 6px;border-radius:999px;background:#dce6ec;color:#435563;font-size:11px}.tab-doc.activa .tab-count{background:#dff3e8;color:#0f6d45}.panel-doc{display:none}.panel-doc.activo{display:block}
.pedido-numero{font-weight:700;white-space:nowrap}
.pedido-meta{display:block;margin-top:3px;font-size:11px;color:#6c757d;line-height:1.25}
.acciones-pedido{display:flex;gap:4px;flex-wrap:wrap;align-items:center}
.acciones-pedido .btn{margin:0;padding:7px 9px;font-size:12px;line-height:1.1;white-space:nowrap}
.acciones-pedido .btn-detalle{background:#198754}
.fila-modificada{background:#fff8e1!important;box-shadow:inset 4px 0 0 #f59e0b}
.aviso-modificacion{margin:0 0 14px;padding:12px 14px;background:#fff8e1;border:1px solid #f6d37a;border-radius:7px;color:#6b4f00;font-weight:700}
.btn-paquete-prod{background:#198754!important}.btn-paquete-admin{background:#0d6efd!important}
.azul{background:#0d6efd}.gris{background:#6c757d}
.sin-resultados{padding:18px;text-align:center;color:#666;border:1px solid #ddd;background:#fafafa}
.resumen-busqueda{margin:0 0 10px;color:#555;font-size:13px}
.detalle-pedido{display:none;background:#f8f9fa}
.detalle-pedido.abierto{display:table-row}
.detalle-contenedor{padding:12px 16px 16px!important}
.detalle-contenedor table{background:#fff;border:1px solid #d7dce1}
.detalle-contenedor th{background:#495057}
.btn-detalle{background:#198754}
.fila-modificada{background:#fff8e1!important;box-shadow:inset 4px 0 0 #f59e0b}
.aviso-modificacion{margin:0 0 14px;padding:12px 14px;background:#fff8e1;border:1px solid #f6d37a;border-radius:7px;color:#6b4f00;font-weight:700}
.btn-paquete-prod{background:#198754!important}.btn-paquete-admin{background:#0d6efd!important}
@media(max-width:800px){.tabla-wrap{overflow-x:auto}.tabla-pedidos{min-width:980px;table-layout:auto}.busqueda .campo{min-width:100%}}
</style>
</head>
<body>
<div class="caja">
<?php require __DIR__ . '/menu.php'; ?>

<h1>Pedidos</h1>

<form method="get" action="pedidos.php" class="busqueda">
    <div class="campo">
        <label for="pedido_cliente">Pedido / cliente</label>
        <input
            type="search"
            id="pedido_cliente"
            name="pedido_cliente"
            value="<?= e($pedidoClienteBusqueda) ?>"
            placeholder="N.º de pedido, código, nombre o razón social"
            autocomplete="off"
        >
    </div>
    <div class="campo referencia">
        <label for="referencia">Referencia / obra</label>
        <input
            type="search"
            id="referencia"
            name="referencia"
            value="<?= e($referenciaBusqueda) ?>"
            placeholder="Ej.: Torre A, Corrientes 1234"
            autocomplete="off"
        >
    </div>
    <div class="campo responsable">
        <label for="responsable">Responsable comercial</label>
        <input
            type="search"
            id="responsable"
            name="responsable"
            value="<?= e($responsableBusqueda) ?>"
            placeholder="Ej.: Fernando"
            autocomplete="off"
        >
    </div>
    <button type="submit" class="btn azul">BUSCAR</button>
    <?php if ($pedidoClienteBusqueda !== '' || $referenciaBusqueda !== '' || $responsableBusqueda !== ''): ?>
        <a class="btn gris" href="pedidos.php">LIMPIAR</a>
    <?php endif; ?>
</form>

<?php if (isset($_GET['anulado_bloqueado'])): ?><div class="aviso-modificacion" style="background:#f8d7da;border-color:#f1aeb5;color:#842029">El documento está anulado y no puede modificarse.</div><?php endif; ?>
<?php if (isset($_GET['anulado'])): ?><div class="aviso-modificacion" style="background:#f8d7da;border-color:#f1aeb5;color:#842029">Documento anulado correctamente.</div><?php endif; ?>
<?php if ($pedidoModificado > 0): ?>
<div class="aviso-modificacion">Pedido actualizado correctamente. Los paquetes de Producción y Administración están disponibles directamente en la fila resaltada.</div>
<?php endif; ?>

<?php if ($pedidoClienteBusqueda !== '' || $referenciaBusqueda !== '' || $responsableBusqueda !== ''): ?>
<p class="resumen-busqueda">Filtros activos:
    <?php if ($pedidoClienteBusqueda !== ''): ?><strong>Pedido / cliente: <?= e($pedidoClienteBusqueda) ?></strong><?php endif; ?>
    <?php if ($referenciaBusqueda !== ''): ?><?= $pedidoClienteBusqueda !== '' ? ' · ' : '' ?><strong>Referencia: <?= e($referenciaBusqueda) ?></strong><?php endif; ?>
    <?php if ($responsableBusqueda !== ''): ?><?= ($pedidoClienteBusqueda !== '' || $referenciaBusqueda !== '') ? ' · ' : '' ?><strong>Responsable: <?= e($responsableBusqueda) ?></strong><?php endif; ?>
</p>
<?php endif; ?>

<?php
function renderPedidosListado(array $filas, string $titulo, string $subtitulo, array $detallesPorPedido, int $pedidoModificado): void {
?>
<div class="seccion-listado">
    <h2><?= e($titulo) ?> <span style="font-weight:400;color:#687782">— <?= e($subtitulo) ?></span></h2>
    <span class="cantidad"><?= count($filas) ?> registro<?= count($filas) === 1 ? '' : 's' ?></span>
</div>
<?php if (count($filas) > 0): ?>
<div class="tabla-wrap">
<table class="tabla-pedidos">
<tr>
    <th>Número</th>
    <th>Cliente</th>
    <th>Fecha</th>
    <th>Base de precios</th>
    <th>Referencia</th>
    <th>Responsable comercial</th>
    <th>Total</th>
    <th>Estado</th>
    <th>Acción</th>
</tr>
<?php foreach ($filas as $x): ?>
<tr<?= $pedidoModificado === (int)$x['pedido_id'] ? ' class="fila-modificada"' : '' ?>>
    <td>
        <span class="pedido-numero"><?= e(numeroDocumentoVisible($x['pedido_numero'])) ?></span>
        <span class="pedido-meta">Rev. <?= (int)($x['revision'] ?? 0) ?> · <?= e($x['cotizacion_numero'] ? 'Cot. ' . numeroDocumentoVisible($x['cotizacion_numero']) : 'Pedido directo') ?></span>
    </td>
    <td title="<?= e($x['clientes_codigo'] . ' — ' . $x['clientes_nomfantasia']) ?>"><span class="am-client"><?= e($x['clientes_nomfantasia']) ?></span></td>
    <td class="am-date"><?= e(date('d/m/Y H:i', strtotime((string)($x['fecha_ultima_modificacion'] ?: $x['fecha_creacion'])))) ?></td>
    <?php $descBaseFila=descripcionBaseBejerman($x); ?><td title="<?= e($descBaseFila) ?>"><span class="am-base">#<?= (int)$x['lista_id'] ?></span></td>
    <td title="<?= e($x['referencia']) ?>"><span class="am-ref"><?= e($x['referencia'] ?: '—') ?></span></td>
    <td><?= e($x['ejecutado_por'] ?: '—') ?></td>
    <td>$<?= number_format(ceil((float)$x['total']),0,',','.') ?></td>
    <td class="am-status-cell"><span class="am-status-chip"><?= e($x['estado']) ?></span></td>
    <td class="am-action-cell">
        <details class="am-row-actions">
            <summary aria-label="Acciones" title="Acciones">⋮</summary>
            <div class="am-row-actions-menu">
                <button type="button" class="btn btn-detalle" onclick="alternarDetalle(<?= (int)$x['pedido_id'] ?>, this)">Mostrar detalle</button>
                <a class="btn azul" href="ver_pedido.php?id=<?= (int)$x['pedido_id'] ?>">Ver pedido</a>
                <?php if (($x['estado']??'')!=='ANULADO'): ?>
                    <a class="btn" style="background:#dc3545" href="index.php?editar_pedido=<?= (int)$x['pedido_id'] ?>">Modificar</a>
                    <a class="btn" style="background:#9b1c2c" href="anular_documento.php?tipo=pedido&id=<?= (int)$x['pedido_id'] ?>">Anular <?= (($x['pedido_tipo']??'OBRA')==='OBRA')?'obra':'pedido' ?></a>
                <?php endif; ?>
                <a class="btn gris" href="generar_pdf_pedido.php?id=<?= (int)$x['pedido_id'] ?>" target="_blank">Abrir PDF</a>
                <?php if (($x['estado']??'')!=='ANULADO' && (int)($x['revision'] ?? 0) > 0): ?>
                    <div class="am-menu-separator"></div>
                    <a class="btn btn-paquete-prod" href="generar_paquete_modificacion_pedido.php?id=<?= (int)$x['pedido_id'] ?>&destino=produccion" target="_blank">Paquete producción</a>
                    <a class="btn btn-paquete-admin" href="generar_paquete_modificacion_pedido.php?id=<?= (int)$x['pedido_id'] ?>&destino=administracion" target="_blank">Paquete administración</a>
                <?php endif; ?>
            </div>
        </details>
    </td>
</tr>
<tr id="detalle_<?= (int)$x['pedido_id'] ?>" class="detalle-pedido"><td colspan="9" class="detalle-contenedor">
<?php $detallesActuales = $detallesPorPedido[(int)$x['pedido_id']] ?? array(); ?>
<?php if (count($detallesActuales) > 0): ?>
<table><tr><th>Concepto</th><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th><th>Importe</th></tr>
<?php foreach ($detallesActuales as $det): ?><tr><td><?= e($det['concepto']) ?></td><td><?= e($det['codigo']) ?></td><td><?= e(descripcionPresentacionRondMetal($det['descripcion'])) ?></td><td><?= number_format((float)$det['cantidad'],2,',','.') ?></td><td>$<?= number_format(ceil((float)$det['precio_unitario']),0,',','.') ?></td><td>$<?= number_format(ceil((float)$det['importe_total']),0,',','.') ?></td></tr><?php endforeach; ?>
</table>
<?php else: ?><div class="sin-resultados">Este pedido no tiene renglones guardados en pedidos_detalle.</div><?php endif; ?>
</td></tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<div class="sin-resultados">No hay registros en este listado para los filtros indicados.</div>
<?php endif; ?>
<?php }

?>
<div class="tabs-doc" role="tablist" aria-label="Tipo de pedido">
    <button type="button" class="tab-doc activa" id="tab-ped-obra" role="tab" aria-selected="true" aria-controls="panel-ped-obra" data-panel="panel-ped-obra">Obras # <span class="tab-count"><?= count($filasObras) ?></span></button>
    <button type="button" class="tab-doc" id="tab-ped-sum" role="tab" aria-selected="false" aria-controls="panel-ped-sum" data-panel="panel-ped-sum">Pedidos P. <span class="tab-count"><?= count($filasSuministros) ?></span></button>
</div>
<div id="panel-ped-obra" class="panel-doc activo" role="tabpanel" aria-labelledby="tab-ped-obra">
<?php renderPedidosListado($filasObras, 'Obras #', 'Control', $detallesPorPedido, $pedidoModificado); ?>
</div>
<div id="panel-ped-sum" class="panel-doc" role="tabpanel" aria-labelledby="tab-ped-sum">
<?php renderPedidosListado($filasSuministros, 'Pedidos P.', 'Suministros', $detallesPorPedido, $pedidoModificado); ?>
</div>
</div>
<script>
(function(){
    const key='automac_pedidos_tab';
    const tabs=[...document.querySelectorAll('.tab-doc[data-panel]')];
    function activar(panelId, guardar=true){
        tabs.forEach(tab=>{
            const activa=tab.dataset.panel===panelId;
            tab.classList.toggle('activa', activa);
            tab.setAttribute('aria-selected', activa?'true':'false');
            const panel=document.getElementById(tab.dataset.panel);
            if(panel) panel.classList.toggle('activo', activa);
        });
        if(guardar){ try{ localStorage.setItem(key,panelId); }catch(e){} }
    }
    tabs.forEach(tab=>tab.addEventListener('click',()=>activar(tab.dataset.panel)));
    try{
        const guardada=localStorage.getItem(key);
        if(guardada && document.getElementById(guardada)) activar(guardada,false);
    }catch(e){}
})();
function alternarDetalle(id, boton){
    const fila=document.getElementById('detalle_'+id);
    if(!fila) return;
    const abrir=!fila.classList.contains('abierto');
    fila.classList.toggle('abierto', abrir);
    boton.textContent=abrir?'Ocultar':'Detalle';
}
</script>
</body>
</html>
<?php
if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
?>
