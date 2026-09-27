<?php
/* v38 - Pedido en una linea + Botonera comercial agrupada (base + paradas + indicador). */
session_start();
include 'conexion.php';

require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    die('Cotización inválida.');
}

$st = $conexion->prepare("SELECT c.*, cl.clientes_codigo, cl.clientes_nomfantasia, cl.clientes_emails, cl.clientes_telefono, COALESCE(u.usuario_nombre,c.usuario,'') AS ejecutado_por,
    (SELECT pedido_id FROM pedidos p WHERE p.cotizacion_id = c.cotizacion_id LIMIT 1) AS pedido_id,
    (SELECT pedido_numero FROM pedidos p WHERE p.cotizacion_id = c.cotizacion_id LIMIT 1) AS pedido_numero
    FROM cotizaciones c
    JOIN clientes cl ON cl.clientes_id = c.cliente_id
    LEFT JOIN usuarios u ON u.usuario_id = c.usuario_id
    LEFT JOIN listas_precios_importaciones l ON l.lista_id=c.lista_id
    WHERE c.cotizacion_id = ?");
$st->bind_param('i', $id);
$st->execute();
$c = $st->get_result()->fetch_assoc();
$st->close();

if (!$c) {
    die('Cotización inexistente.');
}

$fechaVersionActual = fechaVersionActualCotizacion($conexion, (int)$id, (string)($c['fecha_creacion'] ?? ''));

require_once __DIR__ . '/envio_documentos.php';

$st = $conexion->prepare('SELECT * FROM cotizaciones_detalle WHERE cotizacion_id = ? ORDER BY orden_visual');
$st->bind_param('i', $id);
$st->execute();
$rrDetalle = $st->get_result();
$detallesVista = array(); while ($filaDetalle = $rrDetalle->fetch_assoc()) $detallesVista[] = $filaDetalle;
$datosAgrupacion=json_decode((string)($c['datos_formulario']??''),true);if(!is_array($datosAgrupacion))$datosAgrupacion=array();
$solicitanteCliente=trim((string)($datosAgrupacion['solicitante_cliente']??''));
$detallesVista = agruparBotoneraCabinaPresentacion($detallesVista,$datosAgrupacion);

$numeroVisible = numeroDocumentoVisible((string)$c['cotizacion_numero']);
$envioDoc = envioDocPreparar($conexion, array(
    'tipo' => 'cotizacion',
    'numero' => $numeroVisible,
    'revision' => (int)($c['revision'] ?? 0),
    'cliente_id' => (int)($c['cliente_id'] ?? 0),
    'cliente' => trim((string)($c['clientes_nomfantasia'] ?? $c['clientes_codigo'] ?? '')),
    'email' => trim((string)($c['clientes_emails'] ?? '')),
    'telefono' => trim((string)($c['clientes_telefono'] ?? '')),
    'referencia' => trim((string)($c['referencia'] ?? '')),
    'usuario' => trim((string)($_SESSION['usuario_nombre'] ?? '')),
    'pdf_url' => 'descargar_pdf_cotizacion.php?id=' . (int)$id
));
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars(numeroDocumentoVisible($c['cotizacion_numero'])) ?></title>
<style>
body{font-family:Arial,sans-serif;background:#f4f4f9;margin:18px;color:#202124}
.caja{background:#fff;padding:18px;border-radius:8px;max-width:1300px;margin:auto}
nav{margin-bottom:14px}
nav a,.btn{display:inline-block;padding:9px 13px;margin:2px;text-decoration:none;border-radius:5px;border:0;font-weight:bold;cursor:pointer}
nav a{background:#343a40;color:#fff}
.btn-gris{background:#6c757d;color:#fff}
.btn-azul{background:#0d6efd;color:#fff}
.btn-verde{background:#198754;color:#fff}
.aviso{background:#d1e7dd;color:#0f5132;border:1px solid #badbcc;border-radius:6px;padding:12px 14px;margin:14px 0 18px;font-weight:bold}
.estado{display:inline-block;background:#e9ecef;color:#343a40;border-radius:999px;padding:5px 10px;font-size:13px;margin-left:8px;vertical-align:middle}
table{width:100%;border-collapse:collapse;font-size:13px}
th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left}
th{background:#212529;color:#fff}
.total{font-size:20px;color:#198754}
.acciones{display:flex;gap:10px;flex-wrap:wrap;margin-top:22px;padding-top:16px;border-top:1px solid #ddd}
.nota{font-size:13px;color:#5f6368;margin-top:8px}
</style>
</head>
<body>
<div class="caja">
<?php require __DIR__ . '/menu.php'; ?>

<?php if(($c['estado']??'')==='ANULADA'): ?><div class="aviso" style="background:#f8d7da;border-color:#f1aeb5;color:#842029"><strong>Cotización anulada.</strong><?php if(!empty($c['anulacion_motivo'])): ?> Motivo: <?= htmlspecialchars($c['anulacion_motivo']) ?><?php endif; ?></div><?php else: ?><div class="aviso">Cotización guardada correctamente.</div><?php endif; ?>
<?php envioDocRender($envioDoc); ?>

<h1>
    <?= htmlspecialchars(numeroDocumentoVisible($c['cotizacion_numero'])) ?>
    <span class="estado"><?= htmlspecialchars($c['estado']) ?></span> <span class="estado"><?= htmlspecialchars(etiquetaVersionCotizacion((int)$c['revision'])) ?></span>
</h1>

<p><b>Cliente:</b> <?= htmlspecialchars($c['clientes_codigo'] . ' — ' . $c['clientes_nomfantasia']) ?></p>
<p><b>Solicitante:</b> <?= htmlspecialchars($solicitanteCliente !== '' ? $solicitanteCliente : '—') ?></p>
<p><b>Base de precios:</b> <?= htmlspecialchars(descripcionBaseBejerman($c)) ?></p>
<p><b>Referencia:</b> <?= htmlspecialchars($c['referencia']) ?></p>
<p><b>Responsable comercial:</b> <?= htmlspecialchars($c['ejecutado_por'] ?: '—') ?></p>

<table>
<tr><th>Concepto</th><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Unitario</th><th>Fórmula</th><th>Total</th></tr>
<?php foreach ($detallesVista as $x): ?>
<tr>
    <td><?= htmlspecialchars($x['concepto']) ?></td>
    <td><?= htmlspecialchars($x['codigo']) ?></td>
    <td><?= htmlspecialchars($x['descripcion']) ?></td>
    <td><?= number_format($x['cantidad'], 2, ',', '.') ?></td>
    <td>$<?= number_format(ceil((float)$x['precio_unitario']), 0, ',', '.') ?></td>
    <td><?= htmlspecialchars($x['formula_aplicada']) ?></td>
    <td>$<?= number_format(ceil((float)$x['importe_total']), 0, ',', '.') ?></td>
</tr>
<?php endforeach; ?>
</table>

<p>Subtotal: $<?= number_format(ceil((float)$c['subtotal']), 0, ',', '.') ?></p>
<p class="total"><b>Total: $<?= number_format(ceil((float)$c['total']), 0, ',', '.') ?></b></p>

<h2 style="margin-top:28px">Historial de revisiones</h2>
<table>
<tr><th>Revisión</th><th>Fecha</th><th>Motivo</th><th>Usuario</th><th>Detalle</th><th>PDF</th></tr>
<tr><td><strong><?= htmlspecialchars(etiquetaVersionCotizacion((int)$c['revision'])) ?> (actual)</strong></td><td><?= htmlspecialchars(fechaHoraAr($fechaVersionActual)) ?></td><td><?= nl2br(htmlspecialchars(motivoVersionCotizacion($conexion,(int)$id,(int)$c['revision']))) ?></td><td><?= htmlspecialchars($c['ejecutado_por'] ?: '—') ?></td><td><span class="btn btn-verde">Vista actual</span></td><td><a class="btn btn-azul" href="generar_pdf_cotizacion.php?id=<?= $id ?>" target="_blank">VER PDF</a></td></tr>
<?php $hr=$conexion->prepare('SELECT revision,fecha_revision,motivo_modificacion,usuario FROM cotizaciones_revisiones WHERE cotizacion_id=? ORDER BY revision DESC'); $hr->bind_param('i',$id); $hr->execute(); $hist=$hr->get_result(); while($h=$hist->fetch_assoc()): $revHist=(int)$h['revision']; $fechaHist=fechaVersionHistoricaCotizacion($conexion,(int)$id,$revHist,(string)$c['fecha_creacion']); $motivoHist=motivoVersionCotizacion($conexion,(int)$id,$revHist); ?>
<tr><td><?= htmlspecialchars(etiquetaVersionCotizacion($revHist)) ?></td><td><?= htmlspecialchars(fechaHoraAr($fechaHist)) ?></td><td><?= nl2br(htmlspecialchars($motivoHist)) ?></td><td><?= htmlspecialchars($h['usuario'] ?: '—') ?></td><td><a class="btn btn-azul" href="ver_revision_cotizacion.php?cotizacion_id=<?= $id ?>&revision=<?= $revHist ?>">VER VERSIÓN</a></td><td><a class="btn btn-verde" href="generar_pdf_revision.php?tipo=cotizacion&id=<?= $id ?>&revision=<?= $revHist ?>" target="_blank">VER PDF</a></td></tr>
<?php endwhile; $hr->close(); ?>
</table>

<div class="acciones">
    <a class="btn btn-gris" href="cotizaciones.php">VOLVER A COTIZACIONES</a>
    <a class="btn btn-azul" href="index.php?nueva=1">NUEVA COTIZACIÓN</a>
    <?php if (empty($c['pedido_id'])): ?><a class="btn btn-azul" href="index.php?editar_cotizacion=<?= $id ?>">MODIFICAR COTIZACIÓN</a><?php endif; ?>
    <a class="btn btn-azul" href="generar_pdf_cotizacion.php?id=<?= $id ?>" target="_blank">VER / DESCARGAR PDF</a>
    <?php if ((int)($c['revision'] ?? 0) > 0): ?>
        <a class="btn btn-verde" href="generar_caratula_modificacion_cotizacion.php?id=<?= $id ?>&destino=produccion" target="_blank">CARÁTULA PRODUCCIÓN</a>
        <a class="btn btn-verde" href="generar_caratula_modificacion_cotizacion.php?id=<?= $id ?>&destino=administracion" target="_blank">CARÁTULA ADMINISTRACIÓN</a>
    <?php endif; ?>
    <?php if (empty($c['pedido_id'])): ?>
        <a class="btn btn-verde" href="crear_pedido.php?cotizacion_id=<?= $id ?>">GENERAR PEDIDO</a>
    <?php else: ?>
        <a class="btn btn-verde" href="ver_pedido.php?id=<?= (int)$c['pedido_id'] ?>">VER PEDIDO <?= htmlspecialchars(numeroDocumentoVisible($c['pedido_numero'])) ?></a>
    <?php endif; ?>
</div>
<p class="nota">Al generar el Pedido primero se abre Integración externa para confirmar fecha de entrega y forma de pago.</p>
</div>
</body>
</html>
