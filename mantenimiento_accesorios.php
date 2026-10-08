<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once __DIR__ . '/schema_guard.php';
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function maE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function maTablaExiste(mysqli $c,string $t): bool { return esquemaTablaExiste($c,$t); }
function maColumnaExiste(mysqli $c,string $t,string $col): bool { return esquemaColumnaExiste($c,$t,$col); }
function maColumnas(mysqli $c,string $t): array { $out=[]; if(!maTablaExiste($c,$t)) return $out; $te=$c->real_escape_string($t); $r=$c->query("SHOW FULL COLUMNS FROM `$te`"); if($r) while($x=$r->fetch_assoc()) $out[]=$x; return $out; }
function maPk(array $cols): ?string { foreach($cols as $x) if(($x['Key']??'')==='PRI') return (string)$x['Field']; return null; }
function maEsAuto(array $col): bool { return stripos((string)($col['Extra']??''),'auto_increment')!==false; }
function maTipoLabel($tipo){ $m=['CODIGO_DIRECTO'=>'Código directo','CALCULO_ESPECIAL'=>'Cálculo especial','LIMITES'=>'Límites']; return $m[$tipo]??$tipo; }
function maModoLabel($v){$m=['MANUAL'=>'Manual','FIJA'=>'Cantidad fija','POR_EQUIPO'=>'Por equipo','POR_PARADA'=>'Por parada','MATRIZ'=>'Calculada por matriz','AUTOMATICA'=>'Automática / otra regla']; return $m[$v]??$v;}
function maCodigoBejerman(mysqli $c,string $codigo): array {
    $codigo=trim($codigo); if($codigo==='') return ['estado'=>'—','costo'=>0,'descripcion'=>''];
    $partes=array_values(array_filter(array_map('trim',explode('+',$codigo)))); $suma=0; $desc=[];
    foreach($partes as $p){
        $st=$c->prepare("SELECT bp.descripcion,bp.costo FROM bejerman_productos bp INNER JOIN bejerman_listas bl ON bl.bejerman_lista_id=bp.bejerman_lista_id WHERE bl.estado='VIGENTE' AND bp.codigo=? AND bp.activo=1 ORDER BY bp.bejerman_producto_id DESC LIMIT 1");
        if(!$st) return ['estado'=>'REVISAR','costo'=>0,'descripcion'=>''];
        $st->bind_param('s',$p); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
        if(!$r) return ['estado'=>'NO EXISTE','costo'=>0,'descripcion'=>''];
        $suma+=(float)($r['costo']??0); $desc[]=(string)($r['descripcion']??'');
    }
    return ['estado'=>$suma>0?'OK':'PRECIO 0','costo'=>$suma,'descripcion'=>implode(' + ',$desc)];
}
function maValidarCodigosFila(mysqli $c,array $valores): void {
    foreach($valores as $k=>$v){
        if(stripos((string)$k,'codigo')===false) continue;
        $v=trim((string)$v); if($v==='' || strtoupper($v)==='CONSULTAR') continue;
        $chk=maCodigoBejerman($c,$v);
        if($chk['estado']==='NO EXISTE') throw new RuntimeException("El código $v no existe en la Base Bejerman vigente.");
    }
}
function maUsoHistorico(mysqli $c,string $codigo): int {
    $codigo=trim($codigo); if($codigo==='') return 0; $n=0;
    foreach(['cotizaciones_detalle','cotizaciones_revisiones_detalle','pedidos_detalle','pedidos_revisiones_detalle'] as $t){
        if(!maTablaExiste($c,$t)) continue; $st=$c->prepare("SELECT COUNT(*) n FROM `$t` WHERE TRIM(codigo)=TRIM(?)"); if(!$st)continue; $st->bind_param('s',$codigo);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();$n+=(int)($r['n']??0);
    }
    return $n;
}

$tablasEspeciales=[
 'accesorios_sintetizadores_voz'=>'Sintetizadores de voz',
 'accesorios_barreras'=>'Barreras',
 'accesorios_supervisor_reglas'=>'Supervisor · reglas',
 'accesorios_supervisor_componentes'=>'Supervisor · componentes',
 'accesorios_cable_mallado'=>'Cable mallado',
 'accesorios_pesador_base'=>'Pesadores · base',
 'accesorios_pesador_frentes'=>'Pesadores · frentes',
 'accesorios_control_acceso_reglas'=>'Control de accesos'
];
$mensaje=''; $error='';
$catalogoExtendido=maColumnaExiste($conexion,'accesorios_catalogo','accesorio_utilidad');

if($_SERVER['REQUEST_METHOD']==='POST'){
 try{
  $accion=(string)($_POST['accion']??'');
  if($accion==='guardar_catalogo'){
    $id=(int)($_POST['accesorio_id']??0); $clave=strtoupper(trim((string)($_POST['accesorio_clave']??''))); $clave=preg_replace('/[^A-Z0-9_]+/','_',$clave);
    $nombre=trim((string)($_POST['accesorio_nombre']??'')); $tipo=strtoupper(trim((string)($_POST['accesorio_tipo']??'CODIGO_DIRECTO'))); $codigo=strtoupper(trim((string)($_POST['accesorio_codigo']??'')));
    $orden=(int)($_POST['accesorio_orden']??0); $activo=isset($_POST['accesorio_activo'])?'SI':'NO'; $permitidos=['CODIGO_DIRECTO','CALCULO_ESPECIAL','LIMITES'];
    if(!in_array($tipo,$permitidos,true)) throw new RuntimeException('Tipo de accesorio inválido.'); if($clave==='') throw new RuntimeException('Complete la clave técnica.');
    if($tipo==='CODIGO_DIRECTO' && $codigo==='') throw new RuntimeException('Los accesorios de código directo requieren código Bejerman.');
    if($codigo!==''){ $chk=maCodigoBejerman($conexion,$codigo); if($chk['estado']==='NO EXISTE') throw new RuntimeException('El código '.$codigo.' no existe en la Base Bejerman vigente.'); if($nombre==='')$nombre=$chk['descripcion']; }
    if($nombre==='') throw new RuntimeException('Complete el nombre del accesorio.');
    if($catalogoExtendido){
      $cat=trim((string)($_POST['accesorio_categoria']??'General')); if($cat==='')$cat='General';
      $util=(float)str_replace(',','.',(string)($_POST['accesorio_utilidad']??1)); if($util<=0)$util=1;
      $modo=(string)($_POST['accesorio_modo_cantidad']??'MANUAL'); if(!in_array($modo,['MANUAL','FIJA','POR_EQUIPO','POR_PARADA','MATRIZ','AUTOMATICA'],true))$modo='MANUAL';
      $cant=max(0.01,(float)str_replace(',','.',(string)($_POST['accesorio_cantidad_base']??1))); $editable=isset($_POST['accesorio_cantidad_editable'])?'SI':'NO'; $obs=trim((string)($_POST['accesorio_observaciones']??''));
      if($id>0){$st=$conexion->prepare("UPDATE accesorios_catalogo SET accesorio_clave=?,accesorio_nombre=?,accesorio_categoria=?,accesorio_tipo=?,accesorio_codigo=NULLIF(?,''),accesorio_utilidad=?,accesorio_modo_cantidad=?,accesorio_cantidad_base=?,accesorio_cantidad_editable=?,accesorio_activo=?,accesorio_orden=?,accesorio_observaciones=? WHERE accesorio_id=?");$st->bind_param('sssssdsdssisi',$clave,$nombre,$cat,$tipo,$codigo,$util,$modo,$cant,$editable,$activo,$orden,$obs,$id);}else{$st=$conexion->prepare("INSERT INTO accesorios_catalogo(accesorio_clave,accesorio_nombre,accesorio_categoria,accesorio_tipo,accesorio_codigo,accesorio_utilidad,accesorio_modo_cantidad,accesorio_cantidad_base,accesorio_cantidad_editable,accesorio_activo,accesorio_orden,accesorio_observaciones) VALUES(?,?,?,?,NULLIF(?,''),?,?,?,?,?,?,?)");$st->bind_param('sssssdsdssis',$clave,$nombre,$cat,$tipo,$codigo,$util,$modo,$cant,$editable,$activo,$orden,$obs);}
    } else {
      if($id>0){$st=$conexion->prepare("UPDATE accesorios_catalogo SET accesorio_clave=?,accesorio_nombre=?,accesorio_tipo=?,accesorio_codigo=NULLIF(?,''),accesorio_activo=?,accesorio_orden=? WHERE accesorio_id=?");$st->bind_param('sssssii',$clave,$nombre,$tipo,$codigo,$activo,$orden,$id);}else{$st=$conexion->prepare("INSERT INTO accesorios_catalogo(accesorio_clave,accesorio_nombre,accesorio_tipo,accesorio_codigo,accesorio_activo,accesorio_orden) VALUES(?,?,?,NULLIF(?,''),?,?)");$st->bind_param('sssssi',$clave,$nombre,$tipo,$codigo,$activo,$orden);}
    }
    if(!$st || !$st->execute()) throw new RuntimeException($st?$st->error:$conexion->error); $st->close(); $mensaje=$id>0?'Accesorio actualizado.':'Accesorio agregado.';
  }
  elseif($accion==='guardar_limite'){
    $id=(int)($_POST['limite_id']??0); $nombre=trim((string)($_POST['limite_nombre']??'')); $codigo=strtoupper(trim((string)($_POST['limite_codigo']??''))); $orden=(int)($_POST['limite_orden']??0); $activo=isset($_POST['limite_activo'])?'SI':'NO';
    if($nombre===''||$codigo==='') throw new RuntimeException('Complete nombre y código del límite.'); $chk=maCodigoBejerman($conexion,$codigo); if($chk['estado']==='NO EXISTE')throw new RuntimeException('El código '.$codigo.' no existe en Bejerman.');
    if($id>0){$st=$conexion->prepare('UPDATE limites SET limite_nombre=?,limite_codigo=?,limite_activo=?,limite_orden=? WHERE limite_id=?');$st->bind_param('sssii',$nombre,$codigo,$activo,$orden,$id);}else{$st=$conexion->prepare('INSERT INTO limites(limite_nombre,limite_codigo,limite_activo,limite_orden) VALUES(?,?,?,?)');$st->bind_param('sssi',$nombre,$codigo,$activo,$orden);} if(!$st->execute())throw new RuntimeException($st->error);$st->close();$mensaje=$id>0?'Modelo de límite actualizado.':'Modelo de límite agregado.';
  }
  elseif($accion==='guardar_especial'){
    $tabla=(string)($_POST['tabla']??''); if(!isset($tablasEspeciales[$tabla])||!maTablaExiste($conexion,$tabla)) throw new RuntimeException('Matriz especial inválida.');
    $cols=maColumnas($conexion,$tabla); $pk=maPk($cols); if(!$pk)throw new RuntimeException('La matriz no tiene clave primaria identificable.'); $id=(string)($_POST['pk_valor']??''); $valores=(array)($_POST['v']??[]); maValidarCodigosFila($conexion,$valores);
    $permitidas=[];$nullable=[]; foreach($cols as $col){$f=(string)$col['Field']; if($f===$pk||maEsAuto($col))continue; $permitidas[$f]=$col; $nullable[$f]=(($col['Null']??'NO')==='YES');}
    $datos=[]; foreach($permitidas as $f=>$col){ if(array_key_exists($f,$valores)){$v=$valores[$f]; if($nullable[$f] && trim((string)$v)==='')$v=null; $datos[$f]=$v;} }
    if(!$datos)throw new RuntimeException('No hay campos para guardar.');
    if($id!==''){
      $sets=[];$params=[]; foreach($datos as $f=>$v){$sets[]="`$f`=?";$params[]=$v;} $params[]=$id; $sql="UPDATE `$tabla` SET ".implode(',',$sets)." WHERE `$pk`=?"; $st=$conexion->prepare($sql); $types=str_repeat('s',count($params)); $st->bind_param($types,...$params);
    } else {
      $fs=array_keys($datos);$params=array_values($datos);$sql="INSERT INTO `$tabla` (`".implode('`,`',$fs)."`) VALUES (".implode(',',array_fill(0,count($fs),'?')).")";$st=$conexion->prepare($sql);$types=str_repeat('s',count($params));$st->bind_param($types,...$params);
    }
    if(!$st->execute())throw new RuntimeException($st->error);$st->close();$mensaje='Matriz especial actualizada.';
  }
 } catch(Throwable $e){$error=$e->getMessage();}
}

$catalogo=[]; if(maTablaExiste($conexion,'accesorios_catalogo')){$r=$conexion->query('SELECT * FROM accesorios_catalogo ORDER BY accesorio_orden,accesorio_nombre');if($r)while($x=$r->fetch_assoc())$catalogo[]=$x;}
$limites=[]; if(maTablaExiste($conexion,'limites')){$r=$conexion->query('SELECT * FROM limites ORDER BY limite_orden,limite_nombre');if($r)while($x=$r->fetch_assoc())$limites[]=$x;}
$automatizaciones=[]; if(maTablaExiste($conexion,'automatizaciones_cotizador')){$r=$conexion->query("SELECT * FROM automatizaciones_cotizador WHERE modulo_origen='ACCESORIOS' OR modulo_destino='ACCESORIOS' ORDER BY regla_prioridad,regla_id");if($r)while($x=$r->fetch_assoc())$automatizaciones[]=$x;}
$tab=$_GET['tab']??'catalogo';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Matrices de Accesorios</title><link rel="stylesheet" href="automac-ui.css">
<style>
.wrap{max-width:1580px;margin:20px auto;padding:0 18px 50px}.hero,.panel{background:#fff;border:1px solid #dbe5eb;border-radius:16px;padding:20px;margin-bottom:14px}.hero h1{margin:0 0 5px}.hero p,.muted{color:#64748b}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 18px}.tabs a{padding:10px 14px;border-radius:10px;background:#eef4f7;color:#173b5d;text-decoration:none;font-weight:800}.tabs a.on{background:#173b5d;color:#fff}.msg,.err,.warn{padding:11px 13px;border-radius:10px;margin:10px 0}.msg{background:#e8f7ef;color:#17603a}.err{background:#fde9e9;color:#8f2727}.warn{background:#fff5d9;color:#7a5700}.btn{border:0;border-radius:9px;background:#0f6b9f;color:#fff;font-weight:800;padding:9px 12px;cursor:pointer}.btn.green{background:#0f8a55}.btn.soft{background:#edf3f7;color:#173b5d}.gridform{display:grid;grid-template-columns:1fr 1.6fr 1fr 1fr 1fr .8fr .8fr auto;gap:8px;align-items:end}.gridform input,.gridform select,.gridform textarea,.rowform input,.rowform select,.rowform textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid #cfdce4;border-radius:8px;background:#fff}.gridform label,.rowform label{font-size:12px;font-weight:800;color:#445767}.table{width:100%;border-collapse:collapse;font-size:13px}.table th,.table td{padding:9px;border-bottom:1px solid #e3eaee;text-align:left;vertical-align:top}.table th{background:#eef4f7}.off{opacity:.52}.badge{display:inline-block;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:800}.ok{background:#e5f6ed;color:#126a3e}.bad{background:#fdeaea;color:#9a2929}.neutral{background:#edf2f5;color:#536777}.scroll{overflow:auto;max-height:620px}.special-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(360px,1fr));gap:12px}.special{background:#fff;border:1px solid #dbe5eb;border-radius:14px;padding:14px}.special h3{margin:0 0 4px}.special details{border-top:1px solid #e5ebef;padding:9px 0}.rowform{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:9px}.field-check{display:flex;align-items:center;gap:7px}.field-check input{width:auto}.dep{display:grid;grid-template-columns:1.2fr .8fr .7fr .8fr .8fr;gap:8px;padding:10px 0;border-bottom:1px solid #e5ebef}.kpi{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px}.kpi div{background:#f7fafb;border:1px solid #e0e8ed;border-radius:12px;padding:13px}.kpi strong{display:block;font-size:22px;color:#173b5d}@media(max-width:1100px){.gridform{grid-template-columns:1fr 1fr}.rowform{grid-template-columns:1fr 1fr}.dep{grid-template-columns:1fr 1fr}}
</style></head><body><?php require 'menu.php';?><main class="wrap">
<section class="hero"><h1>Matrices de Accesorios</h1><p>Alta, edición, reglas, utilidad y dependencias del módulo Accesorios. La cantidad física de límites se mantiene separada de Límites de paradas.</p></section>
<?php if($mensaje):?><div class="msg"><?=maE($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=maE($error)?></div><?php endif;?>
<?php if(!$catalogoExtendido):?><div class="warn"><strong>Parametrización v224 pendiente.</strong> Importe <code>migracion_mantenimiento_accesorios_v224.sql</code> para habilitar Categoría, Utilidad y comportamiento de cantidad. El mantenimiento básico sigue funcionando.</div><?php endif;?>
<nav class="tabs">
<?php foreach(['catalogo'=>'Catálogo general','limites'=>'Límites','especiales'=>'Matrices especiales','reglas'=>'Reglas automáticas','dependencias'=>'Dependencias'] as $k=>$v):?><a class="<?=$tab===$k?'on':''?>" href="?tab=<?=$k?>"><?=maE($v)?></a><?php endforeach;?>
</nav>

<?php if($tab==='catalogo'):?>
<section class="panel"><h2>Catálogo general</h2><p class="muted">Para códigos directos se valida Base Bejerman. <strong>Utilidad 1,10</strong> significa costo × 1,10 antes de los descuentos comerciales del módulo.</p>
<form method="post" class="gridform"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_catalogo"><label>Clave técnica<input name="accesorio_clave" required placeholder="NUEVO_ACCESORIO"></label><label>Nombre<input name="accesorio_nombre" required></label><?php if($catalogoExtendido):?><label>Categoría<input name="accesorio_categoria" value="General"></label><?php endif;?><label>Tipo<select name="accesorio_tipo"><option value="CODIGO_DIRECTO">Código directo</option><option value="CALCULO_ESPECIAL">Cálculo especial</option><option value="LIMITES">Límites</option></select></label><label>Código Bejerman<input name="accesorio_codigo"></label><?php if($catalogoExtendido):?><label>Utilidad<input type="number" step="0.01" min="0.01" name="accesorio_utilidad" value="1.00"></label><label>Comportamiento<select name="accesorio_modo_cantidad"><option value="MANUAL">Manual</option><option value="FIJA">Fija</option><option value="POR_EQUIPO">Por equipo</option><option value="POR_PARADA">Por parada</option><option value="MATRIZ">Matriz</option><option value="AUTOMATICA">Automática</option></select></label><?php endif;?><label>Orden<input type="number" name="accesorio_orden" value="200"></label><label class="field-check"><input type="checkbox" name="accesorio_activo" checked> Activo</label><?php if($catalogoExtendido):?><input type="hidden" name="accesorio_cantidad_base" value="1"><input type="hidden" name="accesorio_cantidad_editable" value="SI"><input type="hidden" name="accesorio_observaciones" value=""><?php endif;?><button class="btn green">+ Nuevo</button></form>
<div class="scroll" style="margin-top:16px"><table class="table"><thead><tr><th>Accesorio</th><th>Categoría / tipo</th><th>Código / Bejerman</th><?php if($catalogoExtendido):?><th>Utilidad</th><th>Cantidad</th><?php endif;?><th>Orden</th><th>Activo</th><th></th></tr></thead><tbody>
<?php foreach($catalogo as $x): $fid='a'.(int)$x['accesorio_id'];$chk=maCodigoBejerman($conexion,(string)($x['accesorio_codigo']??''));?>
<tr class="<?=$x['accesorio_activo']==='SI'?'':'off'?>"><td><form id="<?=$fid?>" method="post"><input type="hidden" name="accion" value="guardar_catalogo"><input type="hidden" name="accesorio_id" value="<?=(int)$x['accesorio_id']?>"><input name="accesorio_nombre" value="<?=maE($x['accesorio_nombre'])?>"><div class="muted"><input name="accesorio_clave" value="<?=maE($x['accesorio_clave'])?>"></div></form></td><td><?php if($catalogoExtendido):?><input form="<?=$fid?>" name="accesorio_categoria" value="<?=maE($x['accesorio_categoria']??'General')?>"><?php endif;?><select form="<?=$fid?>" name="accesorio_tipo"><option value="CODIGO_DIRECTO"<?=$x['accesorio_tipo']==='CODIGO_DIRECTO'?' selected':''?>>Código directo</option><option value="CALCULO_ESPECIAL"<?=$x['accesorio_tipo']==='CALCULO_ESPECIAL'?' selected':''?>>Cálculo especial</option><option value="LIMITES"<?=$x['accesorio_tipo']==='LIMITES'?' selected':''?>>Límites</option></select></td><td><input form="<?=$fid?>" name="accesorio_codigo" value="<?=maE($x['accesorio_codigo']??'')?>"><span class="badge <?=$chk['estado']==='OK'?'ok':($chk['estado']==='—'?'neutral':'bad')?>"><?=maE($chk['estado'])?></span><?php if($chk['costo']>0):?><div class="muted">Costo vigente: $ <?=number_format($chk['costo'],2,',','.')?></div><?php endif;?></td><?php if($catalogoExtendido):?><td><input form="<?=$fid?>" type="number" step="0.01" min="0.01" name="accesorio_utilidad" value="<?=maE($x['accesorio_utilidad']??1)?>"></td><td><select form="<?=$fid?>" name="accesorio_modo_cantidad"><?php foreach(['MANUAL','FIJA','POR_EQUIPO','POR_PARADA','MATRIZ','AUTOMATICA'] as $m):?><option value="<?=$m?>"<?=($x['accesorio_modo_cantidad']??'MANUAL')===$m?' selected':''?>><?=maE(maModoLabel($m))?></option><?php endforeach;?></select><input form="<?=$fid?>" type="number" step="0.01" min="0.01" name="accesorio_cantidad_base" value="<?=maE($x['accesorio_cantidad_base']??1)?>"><label class="field-check"><input form="<?=$fid?>" type="checkbox" name="accesorio_cantidad_editable"<?=($x['accesorio_cantidad_editable']??'SI')==='SI'?' checked':''?>> editable</label><input form="<?=$fid?>" type="hidden" name="accesorio_observaciones" value="<?=maE($x['accesorio_observaciones']??'')?>"></td><?php endif;?><td><input form="<?=$fid?>" type="number" name="accesorio_orden" value="<?=(int)$x['accesorio_orden']?>"></td><td><input form="<?=$fid?>" type="checkbox" name="accesorio_activo"<?=$x['accesorio_activo']==='SI'?' checked':''?>></td><td><button form="<?=$fid?>" class="btn">Guardar</button></td></tr>
<?php endforeach;?></tbody></table></div></section>

<?php elseif($tab==='limites'):?>
<section class="panel"><h2>Modelos de límites físicos</h2><p class="muted">Estos son los artículos físicos que se ofrecen como límite. La <strong>cantidad</strong> se calcula desde <code>limites_cantidad_reglas</code>; no usa <code>limites_paradas</code>.</p><p><a class="btn soft" href="mantenimiento_automatizaciones.php#limites">Editar reglas de cantidad</a></p>
<form method="post" class="gridform" style="grid-template-columns:2fr 1fr 90px 100px auto"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_limite"><label>Nombre<input name="limite_nombre" required></label><label>Código Bejerman<input name="limite_codigo" required></label><label>Orden<input type="number" name="limite_orden" value="100"></label><label class="field-check"><input type="checkbox" name="limite_activo" checked> Activo</label><button class="btn green">Agregar</button></form>
<table class="table" style="margin-top:14px"><tr><th>Nombre</th><th>Código</th><th>Bejerman</th><th>Uso histórico</th><th>Orden</th><th>Activo</th><th></th></tr><?php foreach($limites as $x):$fid='l'.(int)$x['limite_id'];$chk=maCodigoBejerman($conexion,$x['limite_codigo']);?><tr class="<?=$x['limite_activo']==='SI'?'':'off'?>"><td><form id="<?=$fid?>" method="post"><input type="hidden" name="accion" value="guardar_limite"><input type="hidden" name="limite_id" value="<?=(int)$x['limite_id']?>"><input name="limite_nombre" value="<?=maE($x['limite_nombre'])?>"></form></td><td><input form="<?=$fid?>" name="limite_codigo" value="<?=maE($x['limite_codigo'])?>"></td><td><span class="badge <?=$chk['estado']==='OK'?'ok':'bad'?>"><?=maE($chk['estado'])?></span></td><td><?=maUsoHistorico($conexion,$x['limite_codigo'])?></td><td><input form="<?=$fid?>" type="number" name="limite_orden" value="<?=(int)$x['limite_orden']?>"></td><td><input form="<?=$fid?>" type="checkbox" name="limite_activo"<?=$x['limite_activo']==='SI'?' checked':''?>></td><td><button form="<?=$fid?>" class="btn">Guardar</button></td></tr><?php endforeach;?></table></section>

<?php elseif($tab==='especiales'):?>
<section class="panel"><h2>Matrices especiales</h2><p class="muted">Editor técnico de las tablas que configuran Sintetizadores, Barreras, Supervisor, Cable mallado, Pesadores y Control de accesos. Los campos de código se validan contra Bejerman al guardar.</p><div class="special-grid">
<?php foreach($tablasEspeciales as $t=>$label):$cols=maColumnas($conexion,$t);$pk=maPk($cols);?>
<div class="special"><h3><?=maE($label)?></h3><?php if(!$cols):?><div class="warn">Tabla <code><?=maE($t)?></code> no encontrada en esta base.</div><?php else:$r=$conexion->query("SELECT * FROM `$t` LIMIT 200");$rows=[];if($r)while($x=$r->fetch_assoc())$rows[]=$x;?><div class="muted"><code><?=maE($t)?></code> · <?=count($rows)?> filas mostradas</div>
<details><summary><strong>+ Nueva fila</strong></summary><form method="post" class="rowform"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_especial"><input type="hidden" name="tabla" value="<?=maE($t)?>"><input type="hidden" name="pk_valor" value=""><?php foreach($cols as $c):$f=$c['Field'];if($f===$pk||maEsAuto($c))continue;?><label><?=maE($f)?><?php if(strpos((string)$c['Type'],'enum(')===0):preg_match_all("/'([^']*)'/",(string)$c['Type'],$mm);?><select name="v[<?=maE($f)?>]"><?php foreach($mm[1] as $op):?><option value="<?=maE($op)?>"><?=maE($op)?></option><?php endforeach;?></select><?php else:?><input name="v[<?=maE($f)?>]" value="<?=maE($c['Default']??'')?>"><?php endif;?></label><?php endforeach;?><button class="btn green">Agregar</button></form></details>
<?php foreach($rows as $row):?><details><summary><?=maE($pk?($row[$pk]??''):'')?> · <?=maE(implode(' · ',array_slice(array_values(array_filter($row,function($v){return $v!==null&&$v!=='';})),1,3)))?></summary><form method="post" class="rowform"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_especial"><input type="hidden" name="tabla" value="<?=maE($t)?>"><input type="hidden" name="pk_valor" value="<?=maE($pk?$row[$pk]:'')?>"><?php foreach($cols as $c):$f=$c['Field'];if($f===$pk||maEsAuto($c))continue;$val=$row[$f]??'';?><label><?=maE($f)?><?php if(strpos((string)$c['Type'],'enum(')===0):preg_match_all("/'([^']*)'/",(string)$c['Type'],$mm);?><select name="v[<?=maE($f)?>]"><?php foreach($mm[1] as $op):?><option value="<?=maE($op)?>"<?=$val===$op?' selected':''?>><?=maE($op)?></option><?php endforeach;?></select><?php else:?><input name="v[<?=maE($f)?>]" value="<?=maE($val)?>"><?php endif;?></label><?php endforeach;?><button class="btn">Guardar</button></form></details><?php endforeach;?>
<?php endif;?></div><?php endforeach;?></div></section>

<?php elseif($tab==='reglas'):?>
<section class="panel"><h2>Reglas automáticas vinculadas a Accesorios</h2><p class="muted">Estas reglas son transversales. La edición completa se realiza en Relaciones y Automatizaciones.</p><p><a class="btn" href="mantenimiento_automatizaciones.php">Abrir Relaciones y Automatizaciones</a></p><?php if(!$automatizaciones):?><div class="warn">No se detectaron automatizaciones relacionadas con Accesorios.</div><?php else:?><table class="table"><tr><th>Regla</th><th>Origen</th><th>Destino</th><th>Acción</th><th>Cantidad</th><th>Estado</th></tr><?php foreach($automatizaciones as $a):?><tr><td><?=maE($a['regla_nombre'])?></td><td><?=maE($a['modulo_origen'].' · '.$a['item_origen'])?></td><td><?=maE($a['modulo_destino'].' · '.$a['item_destino'])?></td><td><?=maE($a['accion'])?></td><td><?=maE($a['modo_cantidad'])?></td><td><span class="badge <?=$a['regla_activa']==='SI'?'ok':'neutral'?>"><?=maE($a['regla_activa'])?></span></td></tr><?php endforeach;?></table><?php endif;?></section>

<?php elseif($tab==='dependencias'):?>
<section class="panel"><h2>Dependencias e integridad</h2><p class="muted">Antes de desactivar o cambiar códigos, revise uso histórico, estado Bejerman y automatizaciones asociadas.</p><div class="kpi"><div><span>Accesorios catálogo</span><strong><?=count($catalogo)?></strong></div><div><span>Modelos de límites</span><strong><?=count($limites)?></strong></div><div><span>Automatizaciones</span><strong><?=count($automatizaciones)?></strong></div><div><span>Matrices especiales presentes</span><strong><?=count(array_filter(array_keys($tablasEspeciales), function($t) use ($conexion){ return maTablaExiste($conexion,$t); }))?></strong></div></div><div style="margin-top:16px"><div class="dep"><strong>Accesorio</strong><strong>Código</strong><strong>Bejerman</strong><strong>Históricos</strong><strong>Estado</strong></div><?php foreach($catalogo as $a):$codigo=(string)($a['accesorio_codigo']??'');$chk=maCodigoBejerman($conexion,$codigo);?><div class="dep"><div><strong><?=maE($a['accesorio_nombre'])?></strong><div class="muted"><?=maE($a['accesorio_clave'])?></div></div><div><?=maE($codigo?:'—')?></div><div><span class="badge <?=$chk['estado']==='OK'?'ok':($chk['estado']==='—'?'neutral':'bad')?>"><?=maE($chk['estado'])?></span></div><div><?=maUsoHistorico($conexion,$codigo)?></div><div><?=maE($a['accesorio_activo'])?></div></div><?php endforeach;?></div></section>
<?php endif;?>
</main></body></html>
