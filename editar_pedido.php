<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) die('Pedido inválido.');

$sql = "SELECT p.*, c.cotizacion_numero, c.referencia,
               cl.clientes_codigo, cl.clientes_nomfantasia,
               l.lista_nombre
          FROM pedidos p
          JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id
          JOIN clientes cl ON cl.clientes_id=p.cliente_id
          JOIN listas_precios_importaciones l ON l.lista_id=p.lista_id
         WHERE p.pedido_id=? LIMIT 1";
$st = $conexion->prepare($sql);
$st->bind_param('i', $id);
$st->execute();
$pedido = $st->get_result()->fetch_assoc();
$st->close();
if (!$pedido) die('Pedido inexistente.');

$st = $conexion->prepare('SELECT * FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual,pedido_detalle_id');
$st->bind_param('i', $id);
$st->execute();
$r = $st->get_result();
$detalles = array();
while ($x = $r->fetch_assoc()) {
    if (empty($x['modulo'])) {
        $textoModulo = strtoupper(trim(($x['concepto'] ?? '').' '.($x['codigo'] ?? '').' '.($x['descripcion'] ?? '')));
        if (strpos($textoModulo, 'BOTONERA') !== false || strpos($textoModulo, 'SEÑAL') !== false || strpos($textoModulo, 'SENAL') !== false) $x['modulo']='SENALIZACION';
        elseif (strpos($textoModulo, 'IEP') !== false || strpos($textoModulo, 'PREMONT') !== false) $x['modulo']='IEP';
        elseif (strpos($textoModulo, 'ACCESOR') !== false || strpos($textoModulo, 'BARRERA') !== false || strpos($textoModulo, 'PESADOR') !== false || strpos($textoModulo, 'ALARMA') !== false || strpos($textoModulo, 'INTERCOM') !== false) $x['modulo']='ACCESORIOS';
        elseif (strpos($textoModulo, 'REPUEST') !== false) $x['modulo']='REPUESTOS';
        else $x['modulo']='CONTROL';
    }
    $detalles[] = $x;
}
$st->close();

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$error = (string)($_SESSION['pedido_edicion_error'] ?? '');
unset($_SESSION['pedido_edicion_error']);
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Modificar pedido <?= e($pedido['pedido_numero']) ?></title>
<style>
*{box-sizing:border-box}body{font-family:Arial;background:#f4f4f9;margin:18px;color:#202124}.caja{background:#fff;padding:18px;border-radius:8px;max-width:1450px;margin:auto}.menu a,.btn{display:inline-block;padding:8px 12px;margin:2px;background:#343a40;color:#fff;text-decoration:none;border:0;border-radius:5px;font-weight:bold;cursor:pointer}.encabezado{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;background:#f8f9fa;border:1px solid #ddd;border-radius:7px;padding:12px;margin-bottom:16px}.encabezado div{font-size:13px}.revision{display:inline-block;background:#ffc107;padding:5px 9px;border-radius:5px}.modulos-nav{display:flex;gap:7px;flex-wrap:wrap;margin:14px 0}.modulos-nav button{background:#6c757d}.modulos-nav button.activo{background:#0d6efd}.fila-oculta{display:none}.etiqueta-modulo{font-size:11px;font-weight:bold;color:#495057}.tabla-wrap{overflow-x:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #ddd;padding:7px;text-align:left;vertical-align:top}th{background:#212529;color:#fff}input,textarea,select{width:100%;padding:7px;border:1px solid #aaa;border-radius:4px;font:inherit}input[type=number]{min-width:105px}.codigo{min-width:120px}.concepto{min-width:150px}.descripcion{min-width:280px}.importe{white-space:nowrap;font-weight:bold}.eliminar{background:#dc3545}.agregar{background:#198754}.guardar{background:#0d6efd}.error{background:#fdecec;border:1px solid #f4b6b6;color:#8b0000;padding:10px;border-radius:5px;margin:10px 0}.nota{background:#e8f1ff;border:1px solid #9ec5fe;padding:10px;border-radius:6px;margin:12px 0}.acciones{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.motivo{margin-top:16px}.motivo textarea{min-height:90px}.total-box{text-align:right;font-size:20px;margin:14px 0}.solo-lectura{background:#f1f3f4}@media(max-width:800px){.encabezado{grid-template-columns:1fr}}
</style></head><body><?php require __DIR__ . '/menu.php'; ?><div class="caja">
<div class="menu"><a href="pedidos.php">VOLVER A PEDIDOS</a><a href="ver_pedido.php?id=<?= (int)$id ?>">VER PEDIDO</a></div>
<h1>Modificar pedido <?= e($pedido['pedido_numero']) ?> <span class="revision">REVISIÓN <?= (int)($pedido['revision'] ?? 0) ?></span></h1>
<div class="nota">Se cargaron directamente los renglones guardados en el pedido. El número de pedido y la obra/referencia no se modifican. Al guardar se crea una nueva revisión y se generan las hojas para Producción y Administración.</div>
<?php if($error!==''):?><div class="error"><?= e($error) ?></div><?php endif;?>
<div class="encabezado">
<div><b>Número de pedido</b><br><?= e($pedido['pedido_numero']) ?></div>
<div><b>Obra / referencia</b><br><?= e($pedido['referencia'] ?: '—') ?></div>
<div><b>Cliente</b><br><?= e($pedido['clientes_codigo'].' — '.$pedido['clientes_nomfantasia']) ?></div>
<div><b>Cotización origen</b><br><?= e($pedido['cotizacion_numero']) ?></div>
<div><b>Lista</b><br><?= e($pedido['lista_nombre']) ?></div>
<div><b>Estado actual</b><br><?= e($pedido['estado']) ?></div>
</div>
<form method="post" action="guardar_modificacion_pedido.php" id="formPedido"><?=automacCsrfInput()?>
<input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
<input type="hidden" name="revision_base" value="<?= (int)($pedido['revision'] ?? 0) ?>">
<div class="modulos-nav" id="modulosNav"><button type="button" class="btn activo" data-filtro="TODOS">TODOS</button><button type="button" class="btn" data-filtro="CONTROL">CONTROL</button><button type="button" class="btn" data-filtro="SENALIZACION">SEÑALIZACIÓN</button><button type="button" class="btn" data-filtro="IEP">IEP</button><button type="button" class="btn" data-filtro="ACCESORIOS">ACCESORIOS</button><button type="button" class="btn" data-filtro="REPUESTOS">REPUESTOS</button></div><div class="tabla-wrap"><table id="tablaDetalle"><thead><tr><th>Módulo</th><th>Concepto</th><th>Código</th><th>Descripción</th><th>Cantidad</th><th>Precio unitario</th><th>Importe</th><th></th></tr></thead><tbody>
<?php foreach($detalles as $i=>$d): ?>
<tr data-modulo="<?= e($d['modulo']) ?>">
<td><select class="modulo" name="modulo[]" required><?php foreach(array('CONTROL'=>'Control','SENALIZACION'=>'Señalización','IEP'=>'IEP','ACCESORIOS'=>'Accesorios','REPUESTOS'=>'Repuestos') as $vm=>$lm):?><option value="<?= $vm ?>"<?= $d['modulo']===$vm?' selected':'' ?>><?= $lm ?></option><?php endforeach;?></select></td>
<td><input class="concepto" name="concepto[]" maxlength="150" required value="<?= e($d['concepto']) ?>"></td>
<td><input class="codigo" name="codigo[]" maxlength="100" value="<?= e($d['codigo']) ?>"></td>
<td><textarea class="descripcion" name="descripcion[]" maxlength="500"><?= e($d['descripcion']) ?></textarea><input type="hidden" name="formula_aplicada[]" value="<?= e($d['formula_aplicada']) ?>"></td>
<td><input class="cantidad" type="number" name="cantidad[]" min="0" step="0.0001" required value="<?= e(rtrim(rtrim(number_format((float)$d['cantidad'],4,'.',''),'0'),'.')) ?>"></td>
<td><input class="precio" type="number" name="precio_unitario[]" min="0" step="1" required value="<?= e((string)ceil((float)$d['precio_unitario'])) ?>"></td>
<td class="importe">$ <span class="importeFila">0</span></td>
<td><button type="button" class="btn eliminar" onclick="eliminarFila(this)">Quitar</button></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="acciones"><button type="button" class="btn agregar" onclick="agregarFila()">AGREGAR RENGLÓN</button></div>
<div class="total-box"><b>Total vigente: $ <span id="totalPedido">0,00</span></b></div>
<div class="motivo"><label for="motivo"><b>Detalle de la modificación *</b></label><textarea id="motivo" name="motivo_modificacion" maxlength="2000" required placeholder="Indique claramente qué se modifica y por qué. Esta descripción aparecerá en las hojas de Producción y Administración."></textarea></div>
<div class="acciones"><button type="submit" class="btn guardar">GUARDAR NUEVA REVISIÓN</button><a class="btn" href="ver_pedido.php?id=<?= (int)$id ?>">CANCELAR</a></div>
</form></div>
<script>
function numero(v){const n=parseFloat(String(v||'').replace(',','.'));return Number.isFinite(n)?n:0}
function moneda(v){return Math.ceil(v).toLocaleString('es-AR',{minimumFractionDigits:0,maximumFractionDigits:0})}
function recalcular(){let total=0;document.querySelectorAll('#tablaDetalle tbody tr').forEach(tr=>{const c=numero(tr.querySelector('.cantidad')?.value);const p=Math.ceil(numero(tr.querySelector('.precio')?.value));const imp=Math.ceil(c*p);total+=imp;tr.querySelector('.importeFila').textContent=moneda(imp)});document.getElementById('totalPedido').textContent=moneda(total)}
function eliminarFila(btn){if(document.querySelectorAll('#tablaDetalle tbody tr').length<=1){alert('El pedido debe conservar al menos un renglón.');return}if(confirm('¿Quitar este renglón del pedido?')){btn.closest('tr').remove();recalcular()}}
function agregarFila(){const tb=document.querySelector('#tablaDetalle tbody');const tr=document.createElement('tr');tr.dataset.modulo='CONTROL';tr.innerHTML='<td><select class="modulo" name="modulo[]" required><option value="CONTROL">Control</option><option value="SENALIZACION">Señalización</option><option value="IEP">IEP</option><option value="ACCESORIOS">Accesorios</option><option value="REPUESTOS">Repuestos</option></select></td><td><input class="concepto" name="concepto[]" maxlength="150" required></td><td><input class="codigo" name="codigo[]" maxlength="100"></td><td><textarea class="descripcion" name="descripcion[]" maxlength="500"></textarea><input type="hidden" name="formula_aplicada[]" value="Agregado en revisión"></td><td><input class="cantidad" type="number" name="cantidad[]" min="0" step="0.0001" required value="1"></td><td><input class="precio" type="number" name="precio_unitario[]" min="0" step="1" required value="0"></td><td class="importe">$ <span class="importeFila">0</span></td><td><button type="button" class="btn eliminar" onclick="eliminarFila(this)">Quitar</button></td>';tb.appendChild(tr);recalcular()}
document.getElementById('tablaDetalle').addEventListener('input',recalcular);document.getElementById('tablaDetalle').addEventListener('change',function(e){if(e.target.classList.contains('modulo')){e.target.closest('tr').dataset.modulo=e.target.value;aplicarFiltro();}});let filtroModulo='TODOS';function aplicarFiltro(){document.querySelectorAll('#tablaDetalle tbody tr').forEach(tr=>tr.classList.toggle('fila-oculta',filtroModulo!=='TODOS'&&tr.dataset.modulo!==filtroModulo));}document.getElementById('modulosNav').addEventListener('click',function(e){const b=e.target.closest('button[data-filtro]');if(!b)return;filtroModulo=b.dataset.filtro;this.querySelectorAll('button').forEach(x=>x.classList.toggle('activo',x===b));aplicarFiltro();});document.getElementById('formPedido').addEventListener('submit',function(e){if(!confirm('Se guardará una nueva revisión del pedido. ¿Continuar?'))e.preventDefault()});recalcular();
</script></body></html>
