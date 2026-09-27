<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

$clienteBusqueda = isset($_GET['cliente']) ? trim((string)$_GET['cliente']) : '';
$referenciaBusqueda = isset($_GET['referencia']) ? trim((string)$_GET['referencia']) : '';
$responsableBusqueda = isset($_GET['responsable']) ? trim((string)$_GET['responsable']) : '';

$sql = "SELECT c.*, cl.clientes_codigo, cl.clientes_nomfantasia, COALESCE(u.usuario_nombre,c.usuario,'') AS ejecutado_por,
               COALESCE((SELECT MAX(cr.fecha_revision) FROM cotizaciones_revisiones cr WHERE cr.cotizacion_id=c.cotizacion_id), c.fecha_creacion) AS fecha_version_actual,
               (SELECT pedido_id FROM pedidos p WHERE p.cotizacion_id = c.cotizacion_id AND p.estado<>'ANULADO' ORDER BY p.pedido_id DESC LIMIT 1) AS pedido_id,
               (SELECT pedido_numero FROM pedidos p WHERE p.cotizacion_id = c.cotizacion_id AND p.estado<>'ANULADO' ORDER BY p.pedido_id DESC LIMIT 1) AS pedido_numero
          FROM cotizaciones c
          JOIN clientes cl ON cl.clientes_id = c.cliente_id
          LEFT JOIN usuarios u ON u.usuario_id = c.usuario_id
          LEFT JOIN listas_precios_importaciones l ON l.lista_id=c.lista_id
         WHERE c.cotizacion_numero IS NOT NULL";

$condiciones = array();
$parametros = array();
$tipos = '';
if ($clienteBusqueda !== '') {
    $condiciones[] = "(cl.clientes_codigo LIKE ? OR cl.clientes_nomfantasia LIKE ? OR cl.clientes_razonsocial LIKE ?)";
    $terminoCliente = '%' . $clienteBusqueda . '%';
    array_push($parametros, $terminoCliente, $terminoCliente, $terminoCliente);
    $tipos .= 'sss';
}
if ($referenciaBusqueda !== '') {
    $condiciones[] = "c.referencia LIKE ?";
    $parametros[] = '%' . $referenciaBusqueda . '%';
    $tipos .= 's';
}
if ($responsableBusqueda !== '') {
    $condiciones[] = "COALESCE(u.usuario_nombre,c.usuario,'') LIKE ?";
    $parametros[] = '%' . $responsableBusqueda . '%';
    $tipos .= 's';
}
if ($condiciones) $sql .= ' AND ' . implode(' AND ', $condiciones);
$sql .= " ORDER BY c.cotizacion_id DESC";

if ($parametros) {
    $stmt = $conexion->prepare($sql);
    if (!$stmt) die('No se pudo preparar la búsqueda de cotizaciones: ' . htmlspecialchars($conexion->error));
    $stmt->bind_param($tipos, ...$parametros);
    $stmt->execute();
    $r = $stmt->get_result();
} else {
    $r = $conexion->query($sql);
}

if (!$r) {
    die('No se pudieron consultar las cotizaciones: ' . htmlspecialchars($conexion->error));
}

function e($valor)
{
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

$filasControl = array();
$filasSuministros = array();
while ($fila = $r->fetch_assoc()) {
    if (($fila['cotizacion_tipo'] ?? 'CONTROL') === 'SUMINISTROS') {
        $filasSuministros[] = $fila;
    } else {
        $filasControl[] = $fila;
    }
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Cotizaciones</title>
<style>
body{font-family:Arial;background:#f4f4f9;margin:18px;color:#202124}
.caja{background:white;padding:18px;border-radius:8px;max-width:1300px;margin:auto}
nav{margin-bottom:14px}
nav a,.btn{display:inline-block;padding:8px 12px;margin:2px;background:#343a40;color:white;text-decoration:none;border-radius:5px;border:0;font-weight:bold}
.busqueda{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin:0 0 16px;padding:14px;background:#f8f9fa;border:1px solid #ddd;border-radius:7px}
.busqueda .campo{flex:1;min-width:230px}.busqueda .campo.referencia{flex:.75}.busqueda .campo.responsable{flex:.65}
.busqueda label{display:block;font-size:13px;font-weight:bold;margin-bottom:5px}
.busqueda input{width:100%;box-sizing:border-box;height:38px;padding:7px 10px;border:1px solid #aaa;border-radius:5px;font-size:14px}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;vertical-align:middle}
.seccion-listado{margin:14px 0 8px;display:flex;align-items:center;justify-content:space-between;gap:12px}.seccion-listado h2{margin:0;font-size:18px}.seccion-listado .cantidad{font-size:12px;font-weight:700;color:#5c6770;background:#eef3f6;border:1px solid #d8e1e7;padding:4px 8px;border-radius:999px}
.tabs-doc{display:flex;gap:8px;margin:14px 0 8px;border-bottom:1px solid #d8e1e7;padding:0 2px}.tab-doc{appearance:none;border:1px solid #d8e1e7;border-bottom:0;background:#eef3f6;color:#314657;padding:10px 16px;border-radius:9px 9px 0 0;font-weight:800;cursor:pointer}.tab-doc:hover{background:#e4edf2}.tab-doc.activa{background:#fff;color:#0f6d45;position:relative}.tab-doc.activa:after{content:'';position:absolute;left:0;right:0;bottom:-1px;height:2px;background:#fff}.tab-doc .tab-count{display:inline-block;margin-left:7px;min-width:20px;padding:2px 6px;border-radius:999px;background:#dce6ec;color:#435563;font-size:11px}.tab-doc.activa .tab-count{background:#dff3e8;color:#0f6d45}.panel-doc{display:none}.panel-doc.activo{display:block}
th{background:#212529;color:white}
.verde{background:#198754}.azul{background:#0d6efd}.gris{background:#6c757d}.rojo{background:#dc3545}
.sin-resultados{padding:18px;text-align:center;color:#666;border:1px solid #ddd;background:#fafafa}
.resumen-busqueda{margin:0 0 10px;color:#555;font-size:13px}
.am-pedido-link{display:inline-block;margin-top:4px;padding:3px 7px;border-radius:999px;background:#eaf1fb;color:#173a69;text-decoration:none;font-size:11px;font-weight:700;white-space:nowrap;border:1px solid #c9d8ec}.am-pedido-link:hover{background:#dce8f7}.am-pedido-link strong{font-weight:800}
@media(max-width:800px){.tabla-wrap{overflow-x:auto}.busqueda .campo{min-width:100%}}
</style>
</head>
<body>
<div class="caja">
<?php require __DIR__ . '/menu.php'; ?>

<h1>Cotizaciones</h1>

<?php if (isset($_GET['anulada_bloqueada'])): ?><div style="margin:0 0 14px;padding:11px 14px;border:1px solid #f1aeb5;background:#f8d7da;border-radius:7px;color:#842029;font-weight:bold">La cotización está anulada y no puede modificarse.</div><?php endif; ?>
<?php if (isset($_GET['anulada'])): ?><div style="margin:0 0 14px;padding:11px 14px;border:1px solid #badbcc;background:#d1e7dd;border-radius:7px;color:#0f5132;font-weight:bold">Cotización anulada correctamente.</div><?php endif; ?>
<?php if (isset($_GET['bloqueada'])): ?>
<div style="margin:0 0 14px;padding:11px 14px;border:1px solid #f0c36d;background:#fff7df;border-radius:7px;color:#6b4d00;font-weight:bold">
    Esta cotización ya fue convertida en pedido y quedó cerrada. Para realizar cambios, modifique el pedido correspondiente.
</div>
<?php endif; ?>

<form method="get" action="cotizaciones.php" class="busqueda">
    <div class="campo">
        <label for="cliente">Cliente</label>
        <input type="search" id="cliente" name="cliente" value="<?= e($clienteBusqueda) ?>" placeholder="Código, nombre o razón social" autocomplete="off">
    </div>
    <div class="campo referencia">
        <label for="referencia">Referencia / obra</label>
        <input type="search" id="referencia" name="referencia" value="<?= e($referenciaBusqueda) ?>" placeholder="Ej.: Torre A, Corrientes 1234" autocomplete="off">
    </div>
    <div class="campo responsable">
        <label for="responsable">Responsable comercial</label>
        <input type="search" id="responsable" name="responsable" value="<?= e($responsableBusqueda) ?>" placeholder="Ej.: Fernando" autocomplete="off">
    </div>
    <button type="submit" class="btn azul">BUSCAR</button>
    <?php if ($clienteBusqueda !== '' || $referenciaBusqueda !== '' || $responsableBusqueda !== ''): ?>
        <a class="btn gris" href="cotizaciones.php">LIMPIAR</a>
    <?php endif; ?>
</form>

<?php if ($clienteBusqueda !== '' || $referenciaBusqueda !== '' || $responsableBusqueda !== ''): ?>
<p class="resumen-busqueda">Filtros activos:
    <?php if ($clienteBusqueda !== ''): ?><strong>Cliente: <?= e($clienteBusqueda) ?></strong><?php endif; ?>
    <?php if ($referenciaBusqueda !== ''): ?><?= $clienteBusqueda !== '' ? ' · ' : '' ?><strong>Referencia: <?= e($referenciaBusqueda) ?></strong><?php endif; ?>
    <?php if ($responsableBusqueda !== ''): ?><?= ($clienteBusqueda !== '' || $referenciaBusqueda !== '') ? ' · ' : '' ?><strong>Responsable: <?= e($responsableBusqueda) ?></strong><?php endif; ?>
</p>
<?php endif; ?>

<?php
function renderCotizacionesListado(array $filas, string $titulo, string $subtitulo): void {
?>
<div class="seccion-listado">
    <h2><?= e($titulo) ?> <span style="font-weight:400;color:#687782">— <?= e($subtitulo) ?></span></h2>
    <span class="cantidad"><?= count($filas) ?> registro<?= count($filas) === 1 ? '' : 's' ?></span>
</div>
<?php if (count($filas) > 0): ?>
<div class="tabla-wrap">
<table>
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
<tr>
    <td><?= e(numeroDocumentoVisible($x['cotizacion_numero'])) ?></td>
    <td title="<?= e($x['clientes_codigo'] . ' — ' . $x['clientes_nomfantasia']) ?>"><span class="am-client"><?= e($x['clientes_nomfantasia']) ?></span></td>
    <td class="am-date"><?= e(date('d/m/Y H:i', strtotime((string)$x['fecha_version_actual']))) ?></td>
    <?php $descBaseFila=descripcionBaseBejerman(array('lista_id'=>$x['lista_id'],'lista_nombre'=>$x['lista_nombre'],'lista_fecha_archivo'=>$x['lista_fecha'],'lista_estado'=>'VIGENTE')); ?>
    <td title="<?= e($descBaseFila) ?>"><span class="am-base">#<?= (int)$x['lista_id'] ?></span></td>
    <td title="<?= e($x['referencia']) ?>"><span class="am-ref"><?= e($x['referencia'] ?: '—') ?></span></td>
    <td><?= e($x['ejecutado_por'] ?: '—') ?></td>
    <td>$<?= number_format(ceil((float)$x['total']),0,',','.') ?></td>
    <td class="am-status-cell">
        <span class="am-status-chip"><?= e($x['estado']) ?></span>
        <?php if (!empty($x['pedido_id'])): ?>
            <br><a class="am-pedido-link" href="ver_pedido.php?id=<?= (int)$x['pedido_id'] ?>" title="Abrir pedido asociado">Pedido <strong><?= e(numeroDocumentoVisible($x['pedido_numero'])) ?></strong></a>
        <?php endif; ?>
    </td>
    <td class="am-action-cell">
        <details class="am-row-actions">
            <summary aria-label="Acciones" title="Acciones">⋮</summary>
            <div class="am-row-actions-menu">
                <a class="btn azul" href="ver_cotizacion.php?id=<?= (int)$x['cotizacion_id'] ?>">Ver cotización</a>
                <a class="btn gris" href="generar_pdf_cotizacion.php?id=<?= (int)$x['cotizacion_id'] ?>" target="_blank">Abrir PDF</a>
                <?php if (($x['estado']??'')==='ANULADA'): ?>
                    <span class="am-menu-label">Cotización anulada</span>
                <?php elseif (!$x['pedido_id']): ?>
                    <a class="btn azul" href="index.php?editar_cotizacion=<?= (int)$x['cotizacion_id'] ?>">Modificar</a>
                    <a class="btn verde" href="crear_pedido.php?cotizacion_id=<?= (int)$x['cotizacion_id'] ?>">Generar pedido</a>
                    <a class="btn rojo" href="anular_documento.php?tipo=cotizacion&id=<?= (int)$x['cotizacion_id'] ?>">Anular cotización</a>
                <?php else: ?>
                    <span class="am-menu-label">Cotización cerrada</span>
                    <a class="btn verde" href="ver_pedido.php?id=<?= (int)$x['pedido_id'] ?>">Ver Pedido <?= e(numeroDocumentoVisible($x['pedido_numero'])) ?></a>
                <?php endif; ?>
            </div>
        </details>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php else: ?>
<div class="sin-resultados">No hay registros en este listado para los filtros indicados.</div>
<?php endif; ?>
<?php }

?>
<div class="tabs-doc" role="tablist" aria-label="Tipo de cotización">
    <button type="button" class="tab-doc activa" id="tab-cot-c" role="tab" aria-selected="true" aria-controls="panel-cot-c" data-panel="panel-cot-c">Cotizaciones C. <span class="tab-count"><?= count($filasControl) ?></span></button>
    <button type="button" class="tab-doc" id="tab-cot-r" role="tab" aria-selected="false" aria-controls="panel-cot-r" data-panel="panel-cot-r">Cotizaciones R. <span class="tab-count"><?= count($filasSuministros) ?></span></button>
</div>
<div id="panel-cot-c" class="panel-doc activo" role="tabpanel" aria-labelledby="tab-cot-c">
<?php renderCotizacionesListado($filasControl, 'Cotizaciones C.', 'Control'); ?>
</div>
<div id="panel-cot-r" class="panel-doc" role="tabpanel" aria-labelledby="tab-cot-r">
<?php renderCotizacionesListado($filasSuministros, 'Cotizaciones R.', 'Repuestos / suministros'); ?>
</div>
</div>
<script>
(function(){
    const key='automac_cotizaciones_tab';
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
</script>
</body>
</html>
<?php
if (isset($stmt) && $stmt instanceof mysqli_stmt) {
    $stmt->close();
}
?>
