<?php
/* v38 - Pedido en una linea + Botonera comercial agrupada (base + paradas + indicador). */
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'sistema_comercial.php';
require_once 'orden_fabricacion_tipos.php';
asegurarSistemaUsuarios($conexion);
asegurarSistemaComercial($conexion);
exigirRoles(array('ADMINISTRADOR','TECNICO','COMERCIAL'));
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cantidadOrden($valor){$n=(float)$valor;return abs($n-round($n))<0.000001?number_format($n,0,',','.'):number_format($n,2,',','.');}
function datosFormularioOrden(array $pedido): array { $d=json_decode((string)($pedido['datos_formulario']??''),true); return is_array($d)?$d:array(); }
function numeroPedidoOrdenVisible($numero,$tipo){
    $visible=numeroDocumentoVisible($numero);
    $tipo=strtoupper(trim((string)$tipo));
    if($tipo==='SUMINISTROS'){
        if(preg_match('/^P\./i',$visible)) return $visible;
        $digitos=preg_replace('/\D+/','',$visible);
        return $digitos!==''?'P.'.(string)((int)$digitos):$visible;
    }
    if($tipo==='OBRA'){
        if(strpos($visible,'#')===0) return $visible;
        $digitos=preg_replace('/\D+/','',$visible);
        return $digitos!==''?'#'.(string)((int)$digitos):$visible;
    }
    return $visible;
}

$buscar=trim((string)($_GET['buscar']??''));$like='%'.$buscar.'%';
$sql="SELECT p.pedido_id,p.pedido_numero,p.pedido_tipo,p.revision,p.estado,p.fecha_creacion,p.fecha_ultima_modificacion,p.fecha_paso_produccion,p.datos_formulario,COALESCE(p.referencia,c.referencia) AS referencia,cl.clientes_codigo,cl.clientes_nomfantasia,COALESCE(u.usuario_nombre,p.usuario,'') AS responsable_comercial FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id JOIN clientes cl ON cl.clientes_id=p.cliente_id LEFT JOIN usuarios u ON u.usuario_id=p.usuario_id";
if($buscar!==''){$sql.=" WHERE p.pedido_numero LIKE ? OR cl.clientes_codigo LIKE ? OR cl.clientes_nomfantasia LIKE ? OR COALESCE(p.referencia,c.referencia) LIKE ?";}$sql.=' ORDER BY p.pedido_id DESC';
$st=$conexion->prepare($sql);if($buscar!=='')$st->bind_param('ssss',$like,$like,$like,$like);$st->execute();$r=$st->get_result();
$pedidos=array();$ids=array();while($x=$r->fetch_assoc()){$pedidos[]=$x;$ids[]=(int)$x['pedido_id'];}
$detalles=array();if($ids){$lista=implode(',',array_map('intval',$ids));$rd=$conexion->query("SELECT pedido_id,modulo,concepto,codigo,descripcion,cantidad,formula_aplicada FROM pedidos_detalle WHERE pedido_id IN ($lista) ORDER BY pedido_id,orden_visual,pedido_detalle_id");if($rd){while($d=$rd->fetch_assoc()){$pid=(int)$d['pedido_id'];if(!isset($detalles[$pid]))$detalles[$pid]=array();$detalles[$pid][]=$d;}}}

$totalControles=0;$totalSenal=0;$totalSuministros=0;
foreach($pedidos as $pedido){$pid=(int)$pedido['pedido_id'];foreach(oftTiposDisponibles($pedido,$detalles[$pid]??array()) as $tipo){if($tipo==='CONTROL')$totalControles++;elseif($tipo==='SENALIZACION_ACCESORIOS')$totalSenal++;else$totalSuministros++;}}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Órdenes de producción</title><style>
:root{--ink:#183446;--navy:#183446;--green:#138754;--blue:#2675d8;--amber:#b56a00;--bg:#f2f6f8;--line:#dce6eb;--muted:#6f818d;--white:#fff}
body{font-family:Arial,sans-serif;background:var(--bg);margin:0;color:var(--ink)}.c{max-width:1440px;margin:0 auto;padding:28px 28px 48px}.hero{display:flex;align-items:center;justify-content:space-between;gap:22px;margin-bottom:20px}.hero-copy{display:flex;align-items:center;gap:14px}.hero-mark{width:48px;height:48px;border-radius:14px;background:var(--navy);display:grid;place-items:center;color:#fff;font-size:21px;font-weight:900;box-shadow:0 10px 26px rgba(24,52,70,.14)}.hero h1{margin:0 0 5px;font-size:29px;letter-spacing:-.035em}.hero p{margin:0;color:var(--muted);font-size:13px}.stats{display:flex;gap:10px;flex-wrap:wrap}.stat{min-width:145px;padding:11px 14px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 24px rgba(20,45,62,.05)}.stat strong{display:block;font-size:22px;line-height:1;color:var(--ink);margin-bottom:5px}.stat small{color:#71828d;font-weight:800}.stat.control{border-top:3px solid var(--green)}.stat.senal{border-top:3px solid var(--amber)}.stat.suministro{border-top:3px solid var(--blue)}
.bus{display:flex;gap:9px;margin:18px 0;padding:10px;background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:0 8px 24px rgba(20,45,62,.045)}.bus input{flex:1;min-width:260px;height:44px;padding:0 15px;border:1px solid #cfdbe2;border-radius:10px;font-size:14px;outline:0;background:#fbfdfe}.bus input:focus{border-color:var(--green);box-shadow:0 0 0 3px rgba(19,135,84,.1);background:#fff}.btn,button{display:inline-flex;align-items:center;justify-content:center;gap:6px;padding:10px 13px;border:0;border-radius:9px;font-weight:800;cursor:pointer;text-decoration:none;font-size:11px;letter-spacing:.015em}.bus button{background:var(--navy);color:#fff;min-width:96px}.bus .limpiar{background:#eef2f4;color:#445866}
.panel{background:#fff;border:1px solid var(--line);border-radius:16px;overflow:hidden;box-shadow:0 12px 32px rgba(20,45,62,.055)}.tabla{overflow:auto}table{width:100%;border-collapse:separate;border-spacing:0}th,td{padding:13px 12px;border-bottom:1px solid #e8eef1;text-align:left;vertical-align:middle}th{position:sticky;top:0;z-index:1;background:var(--navy);color:#fff;font-size:10px;text-transform:uppercase;letter-spacing:.06em;white-space:nowrap}.fila-principal:hover{background:#f8fbfc}.fila-principal.inicio-pedido td{border-top:4px solid #eef3f5}.doc{display:flex;align-items:center;gap:10px;font-weight:900;white-space:nowrap}.doc-icon{display:grid;place-items:center;width:36px;height:36px;border-radius:11px;font-size:14px;box-shadow:inset 0 0 0 1px rgba(0,0,0,.03)}.doc-icon.control{background:#e6f5ed;color:#107044}.doc-icon.senal{background:#fff2df;color:#9b5a00}.doc-icon.suministro{background:#e8f1fd;color:#1768c7}.mismo-pedido{display:block;margin-top:3px;font-size:9px;color:#80909a;font-weight:700}.tipo-badge,.estado{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:9px;font-weight:900;white-space:nowrap;letter-spacing:.02em}.tipo-badge.control{background:#e9f6ef;color:#0d7043}.tipo-badge.senal{background:#fff3df;color:#955800}.tipo-badge.suministro{background:#eaf2fd;color:#1764bd}.estado{background:#eef5f2;color:#116d43}.ref{max-width:220px}.muted{color:#748691;font-size:12px}.acciones{display:flex;gap:6px;flex-wrap:nowrap;min-width:max-content}.acciones .btn{padding:8px 10px;white-space:nowrap}.azul{background:#e9f2ff;color:#1766c1}.verde{background:#e8f6ee;color:#0d7144}.gris{background:#eef2f4;color:#465c69}.rojo{background:#ffeaed;color:#b92334}.acciones .btn:hover{filter:brightness(.96)}th:nth-child(2),td:nth-child(2){white-space:normal}.ordenes-pedido{display:flex;gap:8px;flex-wrap:wrap}.orden-pack{display:flex;align-items:center;gap:5px;padding:5px 7px;border:1px solid #dce6eb;border-radius:10px;background:#fbfdfe}.orden-pack .tipo-badge{margin-right:2px}.acciones-generales{display:flex;gap:6px;flex-wrap:wrap}
/* v89: el menu de acciones se posiciona respecto de la ventana para que no sea recortado por el contenedor de la tabla. */
.am-row-actions-menu.am-of-floating{position:fixed!important;right:auto!important;top:auto;bottom:auto;z-index:20000!important;max-height:min(72vh,520px);overflow:auto}
.detalle{display:none;background:#f7fafb}.detalle.abierto{display:table-row}.detalle>td{padding:16px 18px}.detalle-card{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:#fff}.detalle-card table th{position:static;background:#526877}.detalle-card table td{padding:10px 11px;font-size:12px}.vacio{padding:20px;text-align:center;color:#71828d}.empty{padding:46px;text-align:center;color:#6f818d}.empty strong{display:block;color:#27404f;font-size:17px;margin-bottom:5px}
@media(max-width:1000px){.hero{align-items:flex-start;flex-direction:column}.c{padding:18px 14px 34px}.stats{width:100%}.stat{flex:1}.acciones{flex-wrap:wrap}.bus{flex-wrap:wrap}.bus input{width:100%}}@media(max-width:600px){.hero h1{font-size:24px}.hero-mark{display:none}.stat{min-width:calc(50% - 6px)}.bus>*{width:100%}}
</style></head><body><?php require __DIR__.'/menu.php'; ?><main class="c">
<div class="hero"><div class="hero-copy"><div class="hero-mark">OF</div><div><h1>Órdenes de producción</h1><p>Un mismo pedido puede tener OF de Control y OF de Señalización / Accesorios.</p></div></div><div class="stats"><div class="stat control"><strong><?=$totalControles?></strong><small>OF Control</small></div><div class="stat senal"><strong><?=$totalSenal?></strong><small>OF Señalización</small></div><div class="stat suministro"><strong><?=$totalSuministros?></strong><small>Preparación / despacho</small></div></div></div>
<form class="bus"><input name="buscar" value="<?=e($buscar)?>" placeholder="Buscar por pedido, cliente o referencia"><button>BUSCAR</button><?php if($buscar!==''):?><a class="btn limpiar" href="ordenes_fabricacion.php">LIMPIAR</a><?php endif;?></form>
<div class="panel am-list-card"><div class="tabla"><table class="am-data-list">
<thead><tr><th>Pedido</th><th>Orden disponible</th><th>Rev.</th><th>Cliente</th><th>Referencia</th><th>Responsable</th><th>Estado</th><th>Fechas OF</th><th>Acción</th></tr></thead><tbody>
<?php foreach($pedidos as $x):$pid=(int)$x['pedido_id'];$tipos=oftTiposDisponibles($x,$detalles[$pid]??array());if(!$tipos)continue;?>
<tr class="fila-principal inicio-pedido">
<td><span class="am-doc-link"><?=e(numeroPedidoOrdenVisible($x['pedido_numero'],$x['pedido_tipo']??''))?></span></td>
<td><div class="ordenes-pedido"><?php foreach($tipos as $tipo):$claseTipo=$tipo==='CONTROL'?'control':($tipo==='SENALIZACION_ACCESORIOS'?'senal':'suministro');?><span class="tipo-badge <?=$claseTipo?>"><?=e(oftEtiquetaTipo($tipo))?></span><?php endforeach;?></div></td>
<td><strong><?= (int)($x['revision']??0) ?></strong></td>
<td title="<?=e($x['clientes_codigo'].' — '.$x['clientes_nomfantasia'])?>"><span class="am-client"><?=e($x['clientes_nomfantasia'])?></span></td>
<td title="<?=e($x['referencia']?:'—')?>"><span class="am-ref"><?=e($x['referencia']?:'—')?></span></td>
<td><?=e($x['responsable_comercial']?:'—')?></td>
<td><span class="estado am-status-chip"><?=e($x['estado'])?></span></td>
<td class="am-date"><strong><?=e(date('d/m/y H:i',strtotime(fechaVersionActualOrdenFabricacion($x))))?></strong><?php $fo=fechaOriginalOrdenFabricacion($x); $fv=fechaVersionActualOrdenFabricacion($x); if($fo!==$fv):?><span class="mismo-pedido">Original: <?=e(date('d/m/y H:i',strtotime($fo)))?></span><?php endif;?></td>
<td class="am-action-cell"><details class="am-row-actions"><summary aria-label="Acciones" title="Acciones">⋮</summary><div class="am-row-actions-menu">
<?php foreach($tipos as $tipo):$sufijo=oftSufijoUrl($tipo);$dd=oftFiltrarDetalles($detalles[$pid]??array(),$tipo);if($tipo==='SENALIZACION_ACCESORIOS')$dd=expandirBotonerasFabricacion($dd,datosFormularioOrden($x));$detalleId='detalle_'.$pid.'_'.$sufijo;?><div class="am-menu-label"><?=e(oftEtiquetaTipo($tipo))?></div><button type="button" class="btn verde" onclick="alternarDetalle('<?=e($detalleId)?>',this)">Mostrar detalle</button><a class="btn azul" href="ver_orden_fabricacion.php?id=<?=$pid?>&tipo=<?=e($sufijo)?>">Abrir orden</a><a class="btn gris" href="generar_pdf_orden_fabricacion.php?id=<?=$pid?>&tipo=<?=e($sufijo)?>" target="_blank">Abrir PDF</a><div class="am-menu-separator"></div><?php endforeach;?><?php if(esRol('ADMINISTRADOR')):?><a class="btn rojo" href="index.php?editar_pedido=<?=$pid?>">Modificar pedido</a><?php endif;?></div></details></td>
</tr>
<?php foreach($tipos as $tipo):$sufijo=oftSufijoUrl($tipo);$dd=oftFiltrarDetalles($detalles[$pid]??array(),$tipo);if($tipo==='SENALIZACION_ACCESORIOS')$dd=expandirBotonerasFabricacion($dd,datosFormularioOrden($x));$detalleId='detalle_'.$pid.'_'.$sufijo;?><tr id="<?=e($detalleId)?>" class="detalle"><td colspan="9"><div class="detalle-card"><div style="padding:10px 12px;font-weight:900"><?=e(oftEtiquetaTipo($tipo))?> · <?=e(numeroPedidoOrdenVisible($x['pedido_numero'],$x['pedido_tipo']??''))?></div><?php if($dd):?><table><thead><tr><th>Módulo</th><th>Concepto</th><th>Código</th><th>Descripción técnica</th><th>Cantidad</th><th>Observación / fórmula</th></tr></thead><tbody><?php foreach($dd as $d):?><tr><td><?=e(oftModuloDetalle($d))?></td><td><?=e($d['concepto'])?></td><td><strong><?=e($d['codigo']?:'—')?></strong></td><td><?=e($d['descripcion'])?></td><td><strong><?=cantidadOrden($d['cantidad'])?></strong></td><td class="muted"><?=e($d['formula_aplicada']??'')?></td></tr><?php endforeach;?></tbody></table><?php else:?><div class="vacio">Esta orden no tiene renglones aplicables.</div><?php endif;?></div></td></tr><?php endforeach;?>
<?php endforeach;?>
</tbody></table></div></div></main><script>
function alternarDetalle(id,b){const f=document.getElementById(id);if(!f)return;const abrir=!f.classList.contains('abierto');f.classList.toggle('abierto',abrir);b.textContent=abrir?'Ocultar':'Detalle';}

/* v89 - Evita que el menu Accion quede cortado por .tabla/.panel. */
(function(){
  function ubicarMenu(det){
    if(!det || !det.open) return;
    const boton=det.querySelector('summary');
    const menu=det.querySelector('.am-row-actions-menu');
    if(!boton || !menu) return;
    menu.classList.add('am-of-floating');
    menu.style.visibility='hidden';
    menu.style.left='0px';
    menu.style.top='0px';
    const br=boton.getBoundingClientRect();
    const mr=menu.getBoundingClientRect();
    const margen=8;
    let left=br.right-mr.width;
    left=Math.max(margen,Math.min(left,window.innerWidth-mr.width-margen));
    const espacioAbajo=window.innerHeight-br.bottom-margen;
    const espacioArriba=br.top-margen;
    let top;
    if(espacioAbajo>=mr.height || espacioAbajo>=espacioArriba){
      top=Math.min(br.bottom+4,window.innerHeight-mr.height-margen);
    }else{
      top=Math.max(margen,br.top-mr.height-4);
    }
    menu.style.left=Math.round(left)+'px';
    menu.style.top=Math.round(Math.max(margen,top))+'px';
    menu.style.visibility='visible';
  }

  document.querySelectorAll('.am-row-actions').forEach(function(det){
    det.addEventListener('toggle',function(){
      if(det.open){
        document.querySelectorAll('.am-row-actions[open]').forEach(function(otro){if(otro!==det)otro.open=false;});
        requestAnimationFrame(function(){ubicarMenu(det);});
      }else{
        const menu=det.querySelector('.am-row-actions-menu');
        if(menu){menu.classList.remove('am-of-floating');menu.style.left='';menu.style.top='';menu.style.visibility='';}
      }
    });
  });

  window.addEventListener('resize',function(){document.querySelectorAll('.am-row-actions[open]').forEach(ubicarMenu);});
  document.addEventListener('scroll',function(){document.querySelectorAll('.am-row-actions[open]').forEach(ubicarMenu);},true);
})();
</script></body></html>
