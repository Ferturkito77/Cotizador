<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'control_parametros.php';
require_once 'schema_guard.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function emE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function emFlash($msg,$tipo='ok'){ $_SESSION['em_msg']=$msg; $_SESSION['em_tipo']=$tipo; }
function emGo($cpu){ header('Location: mantenimiento_control_editar_modelo.php?cpu='.(int)$cpu); exit; }
function emCol(mysqli $c,string $t,string $col):bool { return ctrlColumnaExiste($c,$t,$col); }

$cpuId=(int)($_GET['cpu']??$_POST['cpu_id']??0);
if(!$cpuId){ header('Location: mantenimiento_control.php?tab=catalogos'); exit; }

$requisitos = ctrlTablaExiste($conexion,'control_cpu_capacidades') && ctrlTablaExiste($conexion,'control_compatibilidades')
    && emCol($conexion,'cpus','velocidad_max_mmin') && emCol($conexion,'cpus','admite_encoder') && emCol($conexion,'cpus','cpu_matriz_base_id');
if(!$requisitos){
    die('Falta instalar la parametrización de Control v213/v214 o las columnas de compatibilidad de CPU.');
}

if($_SERVER['REQUEST_METHOD']==='POST'){
    $accion=$_POST['accion']??'';
    if($accion==='guardar_modelo'){
        $nombre=trim((string)($_POST['cpu_name']??''));
        $velRaw=trim((string)($_POST['velocidad_max_mmin']??''));
        $vel=$velRaw===''?null:(float)str_replace(',','.',$velRaw);
        $encoder=in_array($_POST['admite_encoder']??'',array('SI','NO'),true)?$_POST['admite_encoder']:'';
        $base=(int)($_POST['cpu_matriz_base_id']??0);
        $activo=($_POST['activo']??'SI')==='NO'?'NO':'SI';
        $mrl=($_POST['admite_mrl']??'NO')==='SI'?'SI':'NO';
        $micro=($_POST['admite_micronivelacion']??'NO')==='SI'?'SI':'NO';
        $sab=($_POST['admite_maniobra_sabatica']??'NO')==='SI'?'SI':'NO';
        $ident=trim((string)($_POST['mrl_identidad']??'AUTOMAC')); if($ident==='')$ident='AUTOMAC';
        $obs=trim((string)($_POST['observaciones']??''));
        $errores=array();
        if($nombre==='')$errores[]='El nombre del modelo es obligatorio.';
        if($vel!==null && $vel<=0)$errores[]='La velocidad máxima debe quedar vacía o ser mayor que cero.';
        if($encoder==='')$errores[]='Defina si admite encoder.';
        if(!$base)$errores[]='Seleccione una matriz base.';
        if(!$errores){
            $st=$conexion->prepare('SELECT cpu_id FROM cpus WHERE UPPER(TRIM(cpu_name))=UPPER(TRIM(?)) AND cpu_id<>? LIMIT 1');
            $st->bind_param('si',$nombre,$cpuId);$st->execute();if($st->get_result()->fetch_row())$errores[]='Ya existe otro modelo con ese nombre.';$st->close();
            $st=$conexion->prepare('SELECT cpu_id FROM cpus WHERE cpu_id=? LIMIT 1');$st->bind_param('i',$base);$st->execute();if(!$st->get_result()->fetch_row())$errores[]='La matriz base seleccionada no existe.';$st->close();
        }
        if($errores){ emFlash(implode(' ',array_unique($errores)),'error'); emGo($cpuId); }
        $conexion->begin_transaction();
        try{
            $st=$conexion->prepare('UPDATE cpus SET cpu_name=?,velocidad_max_mmin=?,admite_encoder=?,cpu_matriz_base_id=? WHERE cpu_id=? LIMIT 1');
            $st->bind_param('sdsii',$nombre,$vel,$encoder,$base,$cpuId); if(!$st->execute())throw new Exception($st->error); $st->close();
            $st=$conexion->prepare("INSERT INTO control_cpu_capacidades(cpu_id,activo,admite_mrl,admite_micronivelacion,admite_maniobra_sabatica,mrl_identidad,observaciones)
                VALUES(?,?,?,?,?,?,?) ON DUPLICATE KEY UPDATE activo=VALUES(activo),admite_mrl=VALUES(admite_mrl),admite_micronivelacion=VALUES(admite_micronivelacion),admite_maniobra_sabatica=VALUES(admite_maniobra_sabatica),mrl_identidad=VALUES(mrl_identidad),observaciones=VALUES(observaciones)");
            $st->bind_param('issssss',$cpuId,$activo,$mrl,$micro,$sab,$ident,$obs); if(!$st->execute())throw new Exception($st->error); $st->close();
            $conexion->commit(); emFlash('Modelo actualizado correctamente.');
        }catch(Throwable $e){$conexion->rollback();emFlash('No se pudo actualizar el modelo: '.$e->getMessage(),'error');}
        emGo($cpuId);
    }
    if($accion==='agregar_compat'){
        $tipo=(int)($_POST['ctrltipo_id']??0);$sub=(int)($_POST['ctrlsubtipo_id']??0);$obs=trim((string)($_POST['compat_observaciones']??''));
        if(!$tipo||!$sub){emFlash('Seleccione tipo y subtipo.','error');emGo($cpuId);}
        $st=$conexion->prepare("INSERT INTO control_compatibilidades(cpu_id,ctrltipo_id,ctrlsubtipo_id,activo,observaciones) VALUES(?,?,?,'SI',?) ON DUPLICATE KEY UPDATE activo='SI',observaciones=VALUES(observaciones)");
        $st->bind_param('iiis',$cpuId,$tipo,$sub,$obs);$st->execute();$st->close();emFlash('Compatibilidad habilitada.');emGo($cpuId);
    }
    if($accion==='toggle_compat'){
        $id=(int)($_POST['compatibilidad_id']??0);$act=($_POST['activo']??'NO')==='SI'?'SI':'NO';
        $st=$conexion->prepare('UPDATE control_compatibilidades SET activo=? WHERE compatibilidad_id=? AND cpu_id=?');$st->bind_param('sii',$act,$id,$cpuId);$st->execute();$st->close();emFlash($act==='SI'?'Compatibilidad activada.':'Compatibilidad desactivada.');emGo($cpuId);
    }
}

$st=$conexion->prepare("SELECT c.cpu_id,c.cpu_name,c.velocidad_max_mmin,c.admite_encoder,COALESCE(NULLIF(c.cpu_matriz_base_id,0),c.cpu_id) cpu_matriz_base_id,
       b.cpu_name base_nombre,cap.activo,cap.admite_mrl,cap.admite_micronivelacion,cap.admite_maniobra_sabatica,cap.mrl_identidad,cap.observaciones
       FROM cpus c LEFT JOIN cpus b ON b.cpu_id=COALESCE(NULLIF(c.cpu_matriz_base_id,0),c.cpu_id)
       LEFT JOIN control_cpu_capacidades cap ON cap.cpu_id=c.cpu_id WHERE c.cpu_id=? LIMIT 1");
$st->bind_param('i',$cpuId);$st->execute();$modelo=$st->get_result()->fetch_assoc();$st->close();
if(!$modelo){header('Location: mantenimiento_control.php?tab=catalogos');exit;}
$cap=ctrlCpuCapacidad($conexion,$cpuId);$modelo=array_merge($modelo,$cap);
$msg=$_SESSION['em_msg']??'';$msgTipo=$_SESSION['em_tipo']??'ok';unset($_SESSION['em_msg'],$_SESSION['em_tipo']);
$cpus=$conexion->query('SELECT cpu_id,cpu_name FROM cpus ORDER BY cpu_name');
$tipos=$conexion->query("SELECT t.ctrltipo_id,t.ctrltipo_name FROM tipos_control t LEFT JOIN control_tipo_capacidades tc ON tc.ctrltipo_id=t.ctrltipo_id WHERE COALESCE(tc.activo,'SI')='SI' ORDER BY t.ctrltipo_id");
$subs=$conexion->query('SELECT ctrlsubtipo_id,ctrlsubtipo_name FROM subtipos_control ORDER BY ctrlsubtipo_name');
$compat=$conexion->query("SELECT cc.*,t.ctrltipo_name,s.ctrlsubtipo_name FROM control_compatibilidades cc JOIN tipos_control t ON t.ctrltipo_id=cc.ctrltipo_id JOIN subtipos_control s ON s.ctrlsubtipo_id=cc.ctrlsubtipo_id WHERE cc.cpu_id=".$cpuId." ORDER BY t.ctrltipo_id,s.ctrlsubtipo_name");
$r=$conexion->query('SELECT COUNT(*) c FROM matriz_calculos WHERE control_cpu='.$cpuId);$filasPropias=$r?(int)$r->fetch_assoc()['c']:0;
$baseId=(int)$modelo['cpu_matriz_base_id'];$r=$conexion->query('SELECT COUNT(*) c FROM matriz_calculos WHERE control_cpu='.$baseId);$filasBase=$r?(int)$r->fetch_assoc()['c']:0;
$r=$conexion->query("SELECT COUNT(*) c FROM control_compatibilidades WHERE cpu_id=$cpuId AND activo='SI'");$compatActivas=$r?(int)$r->fetch_assoc()['c']:0;
$usaPropia=$filasPropias>0;
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Editar modelo de Control · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
main{max-width:1450px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:12px;padding:16px;margin-bottom:14px}.head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:10px}.btn{display:inline-block;padding:8px 11px;border:0;border-radius:8px;background:#0f5f9c;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.sec{background:#edf3f7;color:#234}.msg{padding:10px;border-radius:8px;margin:10px 0}.ok{background:#e8f7ef}.error{background:#fdecec}.warn{background:#fff7df;border:1px solid #eed69a;padding:12px;border-radius:9px}.mini{font-size:12px;color:#667085}.summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:8px}.summary>div{padding:10px;background:#f7f9fb;border-radius:8px}.summary strong{font-size:20px}.tablewrap{overflow:auto;max-height:520px;border:1px solid #e0e6ec;border-radius:8px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:7px;border-bottom:1px solid #e5ebf0;text-align:left}th{background:#243746;color:#fff;position:sticky;top:0}input,select,textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid #cbd5df;border-radius:7px;background:#fff}label{font-size:12px;font-weight:700;color:#475467}.inline{display:flex;gap:7px;align-items:end;flex-wrap:wrap}.inline label{min-width:180px}.tag{padding:3px 7px;border-radius:999px;background:#eef3f7}.off{opacity:.55}@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style></head><body><?php require 'menu.php';?><main><section class="card"><div class="head"><div><h1 style="margin:0">Editar modelo: <?=emE($modelo['cpu_name'])?></h1><p class="mini">Edición centralizada de identidad, capacidades, matriz base y compatibilidades. Las filas económicas/técnicas se editan desde Matriz principal para conservar sus validaciones.</p></div><div><a class="btn sec" href="mantenimiento_control.php?tab=catalogos">Volver</a><a class="btn" href="mantenimiento_control.php?tab=principal&cpu=<?=$cpuId?>">Ver matriz</a></div></div><?php if($msg):?><div class="msg <?=emE($msgTipo)?>"><?=emE($msg)?></div><?php endif;?></section>
<section class="card"><div class="summary"><div><span class="mini">Estado</span><br><strong><?=emE($modelo['activo']??'SI')?></strong></div><div><span class="mini">Compatibilidades activas</span><br><strong><?=$compatActivas?></strong></div><div><span class="mini">Filas propias</span><br><strong><?=$filasPropias?></strong></div><div><span class="mini">Matriz base</span><br><strong><?=emE($modelo['base_nombre']?:$modelo['cpu_name'])?></strong><div class="mini"><?=$filasBase?> filas</div></div><div><span class="mini">Motor</span><br><strong><?=$usaPropia?'PROPIA':'HEREDADA'?></strong></div></div><?php if($usaPropia):?><p class="mini">El motor prioriza las <?=$filasPropias?> filas propias de este modelo. La matriz base queda como referencia/fallback solo si se eliminan todas las filas propias.</p><?php else:?><p class="warn">Este modelo no tiene filas propias y actualmente utiliza la matriz base <strong><?=emE($modelo['base_nombre'])?></strong>.</p><?php endif;?></section>
<section class="card"><h2>1. Datos y capacidades</h2><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_modelo"><input type="hidden" name="cpu_id" value="<?=$cpuId?>"><div class="grid"><label>Nombre del modelo<input name="cpu_name" maxlength="50" required value="<?=emE($modelo['cpu_name'])?>"></label><label>Matriz base<select name="cpu_matriz_base_id" required><?php while($r=$cpus->fetch_assoc()):?><option value="<?=(int)$r['cpu_id']?>"<?=((int)$modelo['cpu_matriz_base_id']===(int)$r['cpu_id'])?' selected':''?>><?=emE($r['cpu_name'])?></option><?php endwhile;?></select></label><label>Velocidad máxima m/min<input type="number" min="1" step="0.01" name="velocidad_max_mmin" value="<?=emE($modelo['velocidad_max_mmin'])?>" placeholder="Vacío = sin tope"></label><label>Admite encoder<select name="admite_encoder"><option value="NO"<?=$modelo['admite_encoder']==='NO'?' selected':''?>>NO</option><option value="SI"<?=$modelo['admite_encoder']==='SI'?' selected':''?>>SI</option></select></label><label>Estado<select name="activo"><option value="SI"<?=($modelo['activo']??'SI')==='SI'?' selected':''?>>ACTIVO</option><option value="NO"<?=($modelo['activo']??'SI')==='NO'?' selected':''?>>INACTIVO</option></select></label><label>Admite MRL<select name="admite_mrl"><option value="NO"<?=$modelo['admite_mrl']==='NO'?' selected':''?>>NO</option><option value="SI"<?=$modelo['admite_mrl']==='SI'?' selected':''?>>SI</option></select></label><label>Micronivelación<select name="admite_micronivelacion"><option value="NO"<?=$modelo['admite_micronivelacion']==='NO'?' selected':''?>>NO</option><option value="SI"<?=$modelo['admite_micronivelacion']==='SI'?' selected':''?>>SI</option></select></label><label>Maniobra sabática<select name="admite_maniobra_sabatica"><option value="NO"<?=$modelo['admite_maniobra_sabatica']==='NO'?' selected':''?>>NO</option><option value="SI"<?=$modelo['admite_maniobra_sabatica']==='SI'?' selected':''?>>SI</option></select></label><label>Identidad gabinete MRL<input name="mrl_identidad" value="<?=emE($modelo['mrl_identidad']??'AUTOMAC')?>"></label><label style="grid-column:1/-1">Observaciones<textarea name="observaciones" rows="2"><?=emE($modelo['observaciones']??'')?></textarea></label></div><p><button class="btn" type="submit">Guardar modelo</button></p></form><p class="mini">Cambiar el nombre o las capacidades afecta nuevas cotizaciones. Los documentos históricos conservan sus datos guardados.</p></section>
<section class="card"><div class="head"><div><h2 style="margin:0">2. Tipo + Subtipo permitidos</h2><p class="mini">Activar o desactivar una compatibilidad no elimina filas de matriz ni históricos.</p></div></div><form method="post" class="inline"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="agregar_compat"><input type="hidden" name="cpu_id" value="<?=$cpuId?>"><label>Tipo<select name="ctrltipo_id" required><option value="">Seleccione</option><?php while($r=$tipos->fetch_assoc()):?><option value="<?=(int)$r['ctrltipo_id']?>"><?=emE($r['ctrltipo_name'])?></option><?php endwhile;?></select></label><label>Subtipo<select name="ctrlsubtipo_id" required><option value="">Seleccione</option><?php while($r=$subs->fetch_assoc()):?><option value="<?=(int)$r['ctrlsubtipo_id']?>"><?=emE($r['ctrlsubtipo_name'])?></option><?php endwhile;?></select></label><label>Observación<input name="compat_observaciones"></label><button class="btn" type="submit">Habilitar combinación</button></form><div class="tablewrap" style="margin-top:12px"><table><thead><tr><th>Tipo</th><th>Subtipo</th><th>Estado</th><th>Observación</th><th>Acción</th></tr></thead><tbody><?php if($compat&&$compat->num_rows):while($r=$compat->fetch_assoc()):?><tr class="<?=$r['activo']==='SI'?'':'off'?>"><td><strong><?=emE($r['ctrltipo_name'])?></strong></td><td><?=emE($r['ctrlsubtipo_name'])?></td><td><span class="tag"><?=emE($r['activo'])?></span></td><td><?=emE($r['observaciones'])?></td><td><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="toggle_compat"><input type="hidden" name="cpu_id" value="<?=$cpuId?>"><input type="hidden" name="compatibilidad_id" value="<?=(int)$r['compatibilidad_id']?>"><input type="hidden" name="activo" value="<?=$r['activo']==='SI'?'NO':'SI'?>"><button class="btn <?=$r['activo']==='SI'?'sec':''?>" type="submit"><?=$r['activo']==='SI'?'Desactivar':'Activar'?></button></form></td></tr><?php endwhile;else:?><tr><td colspan="5">No hay compatibilidades configuradas.</td></tr><?php endif;?></tbody></table></div></section>
<section class="card"><h2>3. Matriz económica/técnica</h2><p>Filas propias: <strong><?=$filasPropias?></strong>. Matriz base: <strong><?=emE($modelo['base_nombre'])?></strong> (<?=$filasBase?> filas).</p><a class="btn" href="mantenimiento_control.php?tab=principal&cpu=<?=$cpuId?>">Buscar y editar combinaciones de este modelo</a><a class="btn sec" href="administrar_matriz.php#formulario-configuracion">Agregar combinación</a><p class="mini">La edición detallada de tensión, potencia, contactores, térmicos y código Bejerman sigue usando el editor histórico para conservar controles de duplicados y consistencia.</p></section>
<section class="card"><h2>4. Dependencias</h2><p class="mini">Antes de desactivar el modelo, revisá sus reglas relacionadas. Esta vista no borra nada.</p><a class="btn sec" href="limites_paradas.php">Límites de paradas</a><a class="btn sec" href="mantenimiento_control_compatibilidades.php">Compatibilidades globales</a><a class="btn sec" href="diagnostico_catalogos.php">Diagnóstico de integridad</a></section>
</main></body></html>
