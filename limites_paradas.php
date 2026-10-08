<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function tablaExiste($c,$t){$s=$c->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1");$s->bind_param('s',$t);$s->execute();$ok=(bool)$s->get_result()->fetch_row();$s->close();return $ok;}
function limiteTecnicoOperativo($r){
  $modelo=(string)($r['modelo_control']??'');$exp=(string)($r['expansion']??'');$grupo=(string)($r['grupo']??'');
  $modelos=array('A6220V5'=>1,'A6300V4'=>2,'A6700V2'=>3,'CLEX'=>4,'CD2203 (Dangélica)'=>5);
  if(!isset($modelos[$modelo])) return null;
  // Para nuevas cotizaciones se considera siempre CON EXPANSIÓN. En modelos sin variante de expansión en el cuadro, NA es operativa.
  if(!($exp==='SI' || $exp==='NA')) return null;
  if(!in_array($grupo,array('ESTANDAR','DOBLE_ACCESO_SELECTIVO','TIP'),true)) return null;
  $mani=(string)($r['maniobra']??'');$maniobras=array('AS'=>3,'CS'=>4,'MC/MV'=>5,'SD'=>1,'SAD'=>2);
  if(!isset($maniobras[$mani])) return null;
  $com=(string)($r['comunicacion']??'NINGUNA');$comMap=array('NINGUNA'=>null,'CABINA'=>1,'TOTAL'=>3);
  if(!array_key_exists($com,$comMap)) return null;
  if(in_array($grupo,array('DOBLE_ACCESO_SELECTIVO','TIP'),true) && !in_array($mani,array('SD','SAD'),true)) return null;
  if($grupo==='TIP') return array('cpu'=>$modelos[$modelo],'maniobra'=>$maniobras[$mani],'comserie'=>$comMap[$com],'doble'=>null,'tip'=>(string)($r['programa_tip']??''));
  return array('cpu'=>$modelos[$modelo],'maniobra'=>$maniobras[$mani],'comserie'=>$comMap[$com],'doble'=>$grupo==='DOBLE_ACCESO_SELECTIVO'?'SI':null);
}
function sincronizarLimiteOperativo($c,$r,$max){
  $m=limiteTecnicoOperativo($r); if(!$m) return false;
  $valor=$max===null?0:(int)$max;
  $sql="SELECT Vparadas_id FROM limites_paradas WHERE Vparadas_cpu=? AND Vparadas_maniobra=? AND Vparadas_comserie <=> ? AND Vparadas_dobleacceso <=> ? LIMIT 1";
  $st=$c->prepare($sql);$st->bind_param('iiis',$m['cpu'],$m['maniobra'],$m['comserie'],$m['doble']);$st->execute();$row=$st->get_result()->fetch_assoc();$st->close();
  if($row){$up=$c->prepare('UPDATE limites_paradas SET Vparadas_paradasmax=? WHERE Vparadas_id=?');$id=(int)$row['Vparadas_id'];$up->bind_param('ii',$valor,$id);$up->execute();$up->close();}
  else{$in=$c->prepare('INSERT INTO limites_paradas(Vparadas_cpu,Vparadas_maniobra,Vparadas_comserie,Vparadas_dobleacceso,Vparadas_paradasmax) VALUES(?,?,?,?,?)');$in->bind_param('iiisi',$m['cpu'],$m['maniobra'],$m['comserie'],$m['doble'],$valor);$in->execute();$in->close();}
  return true;
}
$tecnicaOk=tablaExiste($conexion,'limites_paradas_tecnicos');
$histOk=tablaExiste($conexion,'limites_paradas_tecnicos_historial');
$msg='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST' && $tecnicaOk){
  try{
    $id=(int)($_POST['tecnico_id']??0);$motivo=trim((string)($_POST['motivo']??''));$nota=trim((string)($_POST['nota']??''));$raw=trim((string)($_POST['paradas_max']??''));
    if($id<=0) throw new Exception('Registro técnico inválido.');
    if($motivo==='') throw new Exception('Indique el motivo de la modificación.');
    if($raw===''){$nuevo=null;}elseif(ctype_digit($raw)){$nuevo=(int)$raw;}else{throw new Exception('El máximo debe ser un entero o quedar vacío para una combinación no disponible.');}
    $st=$conexion->prepare('SELECT * FROM limites_paradas_tecnicos WHERE tecnico_id=? LIMIT 1');$st->bind_param('i',$id);$st->execute();$ant=$st->get_result()->fetch_assoc();$st->close();if(!$ant)throw new Exception('La variante ya no existe.');
    $conexion->begin_transaction();
    $up=$conexion->prepare('UPDATE limites_paradas_tecnicos SET paradas_max=?,nota=? WHERE tecnico_id=?');
    $up->bind_param('isi',$nuevo,$nota,$id);$up->execute();$up->close();
    if($histOk){
      $usuario=$_SESSION['usuario_nombre']??($_SESSION['usuario']??'');$a=$ant['paradas_max']===null?null:(int)$ant['paradas_max'];$na=(string)($ant['nota']??'');
      $hi=$conexion->prepare('INSERT INTO limites_paradas_tecnicos_historial(tecnico_id,paradas_max_anterior,paradas_max_nuevo,nota_anterior,nota_nueva,motivo,usuario) VALUES(?,?,?,?,?,?,?)');
      $hi->bind_param('iiissss',$id,$a,$nuevo,$na,$nota,$motivo,$usuario);$hi->execute();$hi->close();
    }
    $sincronizado=($ant['grupo']??'')==='TIP'?false:sincronizarLimiteOperativo($conexion,$ant,$nuevo);
    $conexion->commit();$msg=($ant['grupo']??'')==='TIP'?'Límite TIP actualizado. El cotizador v103 lo lee directamente desde la tabla técnica.':($sincronizado?'Límite actualizado y sincronizado con la regla operativa CON EXPANSIÓN.':'Límite técnico de referencia actualizado.');
  }catch(Throwable $x){if($conexion->errno===0){/* noop */}else{@$conexion->rollback();}$err=$x->getMessage();}
}
$grupo=trim((string)($_GET['grupo']??''));$modelo=trim((string)($_GET['modelo']??''));
$filas=[];$modelos=[];
if($tecnicaOk){
  $rm=$conexion->query("SELECT DISTINCT modelo_control FROM limites_paradas_tecnicos WHERE activo='SI' ORDER BY orden,modelo_control");while($rm&&$x=$rm->fetch_assoc())$modelos[]=$x['modelo_control'];
  $w=["activo='SI'"];$p=[];$t='';if($grupo!==''){$w[]='grupo=?';$p[]=$grupo;$t.='s';}if($modelo!==''){$w[]='modelo_control=?';$p[]=$modelo;$t.='s';}$w[]="expansion IN ('SI','NA')";
  $q='SELECT * FROM limites_paradas_tecnicos WHERE '.implode(' AND ',$w).' ORDER BY CASE grupo WHEN \'ESTANDAR\' THEN 1 WHEN \'DOBLE_ACCESO_SELECTIVO\' THEN 2 ELSE 3 END, orden, modelo_control, expansion, programa_tip, comunicacion, maniobra';
  $st=$conexion->prepare($q);if($p)$st->bind_param($t,...$p);$st->execute();$rr=$st->get_result();while($x=$rr->fetch_assoc())$filas[]=$x;$st->close();
}
$operativas=[];
$ro=$conexion->query("SELECT lp.*,c.cpu_name,m.maniobra_name,cs.comserie_name FROM limites_paradas lp JOIN cpus c ON c.cpu_id=lp.Vparadas_cpu JOIN maniobras m ON m.maniobra_id=lp.Vparadas_maniobra LEFT JOIN comunicaciones_serie cs ON cs.comserie_id=lp.Vparadas_comserie ORDER BY c.cpu_id,m.maniobra_id,lp.Vparadas_comserie,lp.Vparadas_dobleacceso");while($ro&&$x=$ro->fetch_assoc())$operativas[]=$x;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Límites de paradas</title>
<style>*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f3f6f9;margin:18px;color:#1d2939}.wrap{max-width:1600px;margin:auto}.head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;flex-wrap:wrap}.head h1{margin:0;font-size:24px;color:#17324d}.head p{margin:6px 0;color:#667085}.card{background:#fff;border:1px solid #d7e0e7;border-radius:10px;padding:14px;margin:14px 0}.alert{padding:11px;border-radius:7px;margin:10px 0}.okm{background:#e9f6ef;color:#12633e}.err{background:#fdecec;color:#922}.warn{background:#fff6dc;color:#805600}.filters{display:grid;grid-template-columns:repeat(3,minmax(180px,1fr)) auto;gap:8px;align-items:end}.field label{display:block;font-size:11px;font-weight:800;color:#475467;margin-bottom:4px}.field select,.field input{width:100%;padding:8px;border:1px solid #b9c5cf;border-radius:6px;background:#fff}.btn{border:0;border-radius:6px;padding:9px 12px;background:#0b5cab;color:white;font-weight:800;cursor:pointer;text-decoration:none;display:inline-block}.btn.sec{background:#566573}.tablewrap{overflow:auto;max-height:720px;border:1px solid #d7e0e7;border-radius:7px}table{border-collapse:collapse;width:100%;font-size:12px;white-space:nowrap}th,td{padding:8px;border-bottom:1px solid #e6ebef;border-right:1px solid #eef2f5;text-align:left}th{position:sticky;top:0;background:#17324d;color:#fff;z-index:2}tbody tr:nth-child(even){background:#f9fbfc}.tag{display:inline-block;padding:3px 7px;border-radius:999px;background:#edf2f7;color:#344054;font-weight:700}.max{font-size:14px;font-weight:900;color:#0b5cab}.na{color:#98a2b3}.edit{display:none;background:#f6f9fc}.edit.open{display:table-row}.editform{display:grid;grid-template-columns:130px 1fr 1fr auto;gap:8px;align-items:end;min-width:760px}.editform label{font-size:10px;font-weight:800;color:#475467;display:block}.editform input{width:100%;padding:7px;border:1px solid #b9c5cf;border-radius:5px}.sectiontitle{display:flex;justify-content:space-between;align-items:center;gap:10px}.sectiontitle h2{margin:0;font-size:18px;color:#17324d}.small{font-size:11px;color:#667085}.source{font-size:12px;color:#475467;background:#eef4fa;padding:8px 10px;border-radius:6px}.grupo{font-weight:900}.tipprog{font-size:10px;color:#475467;white-space:normal;max-width:200px}@media(max-width:900px){.filters{grid-template-columns:1fr 1fr}.editform{grid-template-columns:1fr;min-width:500px}}</style></head><body><div class="wrap"><?php require __DIR__.'/menu.php';?>
<div class="head"><div><h1>Límites de paradas por control</h1><p>Controles disponibles: <strong>A6220V5, A6300V4, A6700V2, CLEX y DANGELICA</strong>. CLEX/DANGELICA usan matriz base A6300V4, pero estos límites de paradas y maniobras siguen siendo propios.</p></div><a class="btn sec" href="administrar_matriz.php">Volver a Cálculos de Control</a></div>
<div class="source"><strong>Fuente técnica:</strong> Información Técnica Interna “Cantidad de paradas según control”, IT 3 19 r1, N.Mod. 14. MC (Montacoches) y MV (Montavehículos) se muestran como la misma maniobra funcional. El nombre técnico SAD corresponde al registro histórico SDA del sistema. <strong>Regla operativa:</strong> A6220V5, A6300V4 y A6700V2 siempre CON EXPANSIÓN. CLEX y DANGELICA conservan las variantes y máximos específicos del cuadro técnico; su matriz económica/base puede heredarse de A6300V4 sin alterar estos límites.</div>
<?php if($msg):?><div class="alert okm"><?=e($msg)?></div><?php endif;?><?php if($err):?><div class="alert err"><?=e($err)?></div><?php endif;?>
<?php if(!$tecnicaOk):?><div class="alert warn"><strong>Falta ejecutar la migración v100.</strong> La tabla operativa sigue disponible abajo, pero la referencia técnica completa todavía no fue creada.</div><?php else:?>
<div class="card"><form class="filters" method="get"><div class="field"><label>Grupo</label><select name="grupo"><option value="">Todos</option><option value="ESTANDAR"<?=$grupo==='ESTANDAR'?' selected':''?>>Estándar</option><option value="DOBLE_ACCESO_SELECTIVO"<?=$grupo==='DOBLE_ACCESO_SELECTIVO'?' selected':''?>>Doble acceso selectivo</option><option value="TIP"<?=$grupo==='TIP'?' selected':''?>>Maniobra TIP</option></select></div><div class="field"><label>Modelo</label><select name="modelo"><option value="">Todos</option><?php foreach($modelos as $m):?><option<?= $modelo===$m?' selected':''?>><?=e($m)?></option><?php endforeach;?></select></div><div class="field"><label>Expansión</label><input value="Siempre CON EXPANSIÓN" readonly></div><button class="btn">Filtrar</button></form></div>
<div class="card"><div class="sectiontitle"><h2>Cuadro técnico interno</h2><span class="small"><?=count($filas)?> variantes</span></div><p class="small">“—” significa que el cuadro técnico no habilita / no informa esa combinación. Para las CPU Automac vigentes A6220V5, A6300V4 y A6700V2 solo se muestran y utilizan filas CON EXPANSIÓN. Los modelos externos cuyo cuadro no distingue expansión pueden conservar NA. Al editar Estándar/Doble acceso se mantiene sincronizada la tabla histórica <code>limites_paradas</code>; TIP se usa directamente desde esta tabla técnica.</p><div class="tablewrap"><table><thead><tr><th>Uso</th><th>Grupo</th><th>Modelo</th><th>Expansión</th><th>Programa TIP</th><th>Maniobra</th><th>Comunicación</th><th>Máximo</th><th>Nota</th><th></th></tr></thead><tbody><?php foreach($filas as $r):$id=(int)$r['tecnico_id'];$op=limiteTecnicoOperativo($r)!==null;?><tr><td><span class="tag"><?= $op?'OPERATIVA':'REFERENCIA'?></span></td><td class="grupo"><?=e(str_replace('_',' ',$r['grupo']))?></td><td><strong><?=e($r['modelo_control'])?></strong></td><td><?= $r['expansion']==='SI'?'Con expansión':($r['expansion']==='NO'?'Sin expansión':'—')?></td><td class="tipprog"><?=e($r['programa_tip']?:'—')?></td><td><span class="tag"><?=e($r['maniobra'])?></span></td><td><?=e($r['comunicacion'])?></td><td class="max"><?= $r['paradas_max']===null?'<span class="na">—</span>':(int)$r['paradas_max']?></td><td class="tipprog"><?=e($r['nota']??'')?></td><td><button type="button" class="btn" onclick="toggleEdit(<?=$id?>)">Editar</button></td></tr><tr id="edit<?=$id?>" class="edit"><td colspan="10"><form method="post" class="editform"><?= automacCsrfInput() ?><input type="hidden" name="tecnico_id" value="<?=$id?>"><div><label>Máximo (vacío = —)</label><input name="paradas_max" value="<?=e($r['paradas_max'])?>" inputmode="numeric"></div><div><label>Nota técnica</label><input name="nota" value="<?=e($r['nota']??'')?>"></div><div><label>Motivo obligatorio</label><input name="motivo" required placeholder="Ej.: corrección según revisión técnica"></div><button class="btn" type="submit">Guardar cambio</button></form></td></tr><?php endforeach;?></tbody></table></div></div>
<?php endif;?>
<div class="card"><div class="sectiontitle"><h2>Tabla operativa usada actualmente por el cálculo</h2><span class="small">limites_paradas · <?=count($operativas)?> filas</span></div><p class="small">Desde v103 el cálculo de Control consulta directamente el cuadro técnico CON EXPANSIÓN, incluyendo Doble acceso selectivo y TIP. Esta tabla se conserva por compatibilidad con instalaciones/documentos anteriores. Las variantes SIN EXPANSIÓN no participan en nuevas cotizaciones.</p><div class="tablewrap" style="max-height:460px"><table><thead><tr><th>ID</th><th>CPU</th><th>Maniobra</th><th>Comunicación serie</th><th>Doble acceso</th><th>Máximo</th></tr></thead><tbody><?php foreach($operativas as $r):?><tr><td><?= (int)$r['Vparadas_id']?></td><td><strong><?=e($r['cpu_name'])?></strong></td><td><?=e($r['maniobra_name'])?></td><td><?=e($r['comserie_name']??'Sin serie')?></td><td><?=strtoupper((string)$r['Vparadas_dobleacceso'])==='SI'?'Sí':'No'?></td><td class="max"><?= (int)$r['Vparadas_paradasmax']?></td></tr><?php endforeach;?></tbody></table></div></div>
</div><script>function toggleEdit(id){const x=document.getElementById('edit'+id);if(x)x.classList.toggle('open');}</script></body></html>