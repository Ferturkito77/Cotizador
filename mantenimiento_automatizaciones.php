<?php
require_once 'auth.php';
require_once 'conexion.php';
require_once 'parametros_sistema.php';
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}

function auE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function auTablaExiste($c,$t){return function_exists('psTablaExiste')?psTablaExiste($c,$t):false;}

$mensaje='';$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(isset($_POST['guardar_automatizacion'])){
            if(!auTablaExiste($conexion,'automatizaciones_cotizador')) throw new RuntimeException('Falta automatizaciones_cotizador. Importe la migración v217.');
            $id=(int)($_POST['regla_id']??0);
            $destino=trim((string)($_POST['item_destino']??''));
            $modo=(string)($_POST['modo_cantidad']??'MISMA');
            $activa=isset($_POST['regla_activa'])?'SI':'NO';
            if($id<=0||$destino==='') throw new RuntimeException('Regla inválida.');
            if(!in_array($modo,array('MISMA','FIJA','MANUAL'),true)) $modo='MISMA';
            $fija=null;
            if($modo==='FIJA') $fija=max(1,(float)($_POST['cantidad_fija']??1));
            $st=$conexion->prepare("UPDATE automatizaciones_cotizador SET item_destino=?,modo_cantidad=?,cantidad_fija=?,regla_activa=? WHERE regla_id=?");
            if(!$st) throw new RuntimeException($conexion->error);
            $st->bind_param('ssdsi',$destino,$modo,$fija,$activa,$id);
            if(!$st->execute()) throw new RuntimeException($st->error);
            $st->close();
            $mensaje='Automatización actualizada.';
        } elseif(isset($_POST['guardar_limite_regla'])){
            if(!auTablaExiste($conexion,'limites_cantidad_reglas')) throw new RuntimeException('Falta limites_cantidad_reglas. Importe la migración v207.');
            $id=(int)($_POST['regla_id']??0);
            $familia=strtoupper(trim((string)($_POST['familia_control']??'')));
            $subRaw=trim((string)($_POST['ctrlsubtipo_id']??''));
            $sub=$subRaw===''?null:(int)$subRaw;
            $contactor=(string)($_POST['contactor_potencial']??'NO');
            $vde=(float)str_replace(',','.',(string)($_POST['velocidad_desde']??0));
            $vha=(float)str_replace(',','.',(string)($_POST['velocidad_hasta']??210));
            $rbg=(string)($_POST['retorno_bateria_gel']??'NA');
            $cant=max(0,(int)($_POST['cantidad_limites']??0));
            $orden=(int)($_POST['regla_orden']??0);
            $activa=isset($_POST['regla_activa'])?'SI':'NO';
            if($familia===''||!in_array($contactor,array('SI','NO'),true)||!in_array($rbg,array('SI','NO','NA'),true)||$cant<=0||$vha<$vde) throw new RuntimeException('Complete correctamente la regla de cantidad de límites.');
            if($familia!=='HIDRAULICO') $rbg='NA';
            if($id>0){
                $st=$conexion->prepare('UPDATE limites_cantidad_reglas SET familia_control=?,ctrlsubtipo_id=?,contactor_potencial=?,velocidad_desde=?,velocidad_hasta=?,retorno_bateria_gel=?,cantidad_limites=?,regla_orden=?,regla_activa=? WHERE regla_id=?');
                if(!$st) throw new RuntimeException($conexion->error);
                $st->bind_param('sisddsiisi',$familia,$sub,$contactor,$vde,$vha,$rbg,$cant,$orden,$activa,$id);
            } else {
                $st=$conexion->prepare('INSERT INTO limites_cantidad_reglas(familia_control,ctrlsubtipo_id,contactor_potencial,velocidad_desde,velocidad_hasta,retorno_bateria_gel,cantidad_limites,regla_orden,regla_activa) VALUES(?,?,?,?,?,?,?,?,?)');
                if(!$st) throw new RuntimeException($conexion->error);
                $st->bind_param('sisddsiis',$familia,$sub,$contactor,$vde,$vha,$rbg,$cant,$orden,$activa);
            }
            if(!$st->execute()) throw new RuntimeException($st->error);
            $st->close();
            $mensaje=$id>0?'Regla de límites actualizada.':'Nueva regla de límites creada.';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$subtipos=array();
if(auTablaExiste($conexion,'subtipos_control')){$r=$conexion->query('SELECT ctrlsubtipo_id,ctrlsubtipo_name FROM subtipos_control ORDER BY ctrlsubtipo_name');if($r)while($x=$r->fetch_assoc())$subtipos[]=$x;}
$limites=array();
if(auTablaExiste($conexion,'limites_cantidad_reglas')){
    $sql="SELECT l.*,s.ctrlsubtipo_name FROM limites_cantidad_reglas l LEFT JOIN subtipos_control s ON s.ctrlsubtipo_id=l.ctrlsubtipo_id ORDER BY l.familia_control,l.ctrlsubtipo_id,l.velocidad_desde,l.contactor_potencial,l.retorno_bateria_gel,l.regla_orden";
    $r=$conexion->query($sql);if($r)while($x=$r->fetch_assoc())$limites[]=$x;
}
$automatizaciones=array();
if(auTablaExiste($conexion,'automatizaciones_cotizador')){$r=$conexion->query('SELECT * FROM automatizaciones_cotizador ORDER BY regla_prioridad,regla_id');if($r)while($x=$r->fetch_assoc())$automatizaciones[]=$x;}
$equiv=array();
if(auTablaExiste($conexion,'equivalencias_codigos')){$r=$conexion->query('SELECT * FROM equivalencias_codigos ORDER BY codigo_origen LIMIT 300');if($r)while($x=$r->fetch_assoc())$equiv[]=$x;}
$repuestosIntegrados=strpos((string)@file_get_contents(__DIR__.'/index.php'),'Cotización independiente.')===false;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Relaciones y Automatizaciones</title><link rel="stylesheet" href="automac-ui.css">
<style>
.wrap{max-width:1580px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dde5ea;border-radius:14px;padding:18px;margin-bottom:14px}.hero h1{margin:0 0 6px}.hero p{margin:0;color:#64748b}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(390px,1fr));gap:12px}.status{display:inline-block;border-radius:999px;padding:5px 9px;font-size:12px;font-weight:800}.ok{background:#e8f7ef;color:#17603a}.warn{background:#fff4d8;color:#855c00}.table{width:100%;border-collapse:collapse;font-size:13px}.table th,.table td{padding:9px;border-bottom:1px solid #e4eaee;text-align:left;vertical-align:top}.table th{background:#eef4f8;position:sticky;top:0;z-index:1}.scroll{overflow:auto;max-height:520px}.note{padding:12px;border-radius:9px;background:#f5f8fa;color:#526474;margin-top:10px}.btn{display:inline-block;padding:9px 12px;border:0;border-radius:8px;background:#0f5f9c;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn-sec{background:#eef4f8;color:#173b5d}.msg{padding:10px;border-radius:9px;background:#e8f7ef;color:#17603a;margin-bottom:12px}.err{padding:10px;border-radius:9px;background:#fde9e9;color:#922;margin-bottom:12px}.inline{display:flex;gap:9px;flex-wrap:wrap;align-items:end}.inline label{font-weight:700;font-size:12px}.inline input,.inline select{padding:7px 8px;border:1px solid #ccd8e0;border-radius:7px}.qty-fixed[hidden]{display:none!important}.limit-form{display:grid;grid-template-columns:1.15fr 1.2fr .75fr .7fr .7fr .9fr .65fr .65fr auto;gap:6px;align-items:end}.limit-form input,.limit-form select{width:100%;box-sizing:border-box;padding:6px;border:1px solid #ccd8e0;border-radius:6px}.new-rule{background:#f8fafb;border:1px solid #dce5ea;border-radius:10px;padding:12px;margin:12px 0}.mini{font-size:12px;color:#657684}.friendly{font-weight:700;color:#173b5d}@media(max-width:1100px){.limit-form{grid-template-columns:1fr 1fr 1fr}.grid{grid-template-columns:1fr}}
</style></head><body><?php require 'menu.php';?><main class="wrap">
<section class="hero"><h1>Relaciones y Automatizaciones</h1><p>Reglas transversales mantenibles del cotizador. Las cantidades automáticas se muestran según su comportamiento real.</p></section>
<?php if($mensaje):?><div class="msg"><?=auE($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=auE($error)?></div><?php endif;?>
<div class="grid">
<section class="card"><h2>Automatizaciones entre módulos</h2><span class="status <?=$automatizaciones?'ok':'warn'?>"><?=$automatizaciones?'EN BASE DE DATOS':'MIGRACIÓN v217 PENDIENTE'?></span>
<?php if($automatizaciones):?><table class="table"><tr><th>Regla</th><th>Configuración</th></tr><?php foreach($automatizaciones as $a):?><tr><td><strong><?=auE($a['regla_nombre'])?></strong><br><?=auE($a['modulo_origen'].' · '.$a['item_origen'])?><br>→ <?=auE($a['modulo_destino'])?></td><td><form method="post" class="auto-form"><?= automacCsrfInput() ?><input type="hidden" name="guardar_automatizacion" value="1"><input type="hidden" name="regla_id" value="<?=(int)$a['regla_id']?>"><div class="inline"><label>Destino<br><input name="item_destino" value="<?=auE($a['item_destino'])?>"></label><label>Cantidad<br><select name="modo_cantidad" class="modo-cantidad"><option value="MISMA"<?=$a['modo_cantidad']==='MISMA'?' selected':''?>>Misma que origen</option><option value="FIJA"<?=$a['modo_cantidad']==='FIJA'?' selected':''?>>Fija</option><option value="MANUAL"<?=$a['modo_cantidad']==='MANUAL'?' selected':''?>>Manual</option></select></label><label class="qty-fixed"<?=$a['modo_cantidad']==='FIJA'?'':' hidden'?>>Cantidad fija<br><input type="number" min="1" step="1" name="cantidad_fija" value="<?=auE($a['cantidad_fija']??1)?>" style="width:90px"></label><label><input type="checkbox" name="regla_activa"<?=$a['regla_activa']==='SI'?' checked':''?>> Activa</label><button class="btn">Guardar</button></div><?php if($a['modo_cantidad']==='MISMA'):?><div class="mini">La cantidad del destino copia exactamente la cantidad seleccionada en el origen.</div><?php endif;?></form></td></tr><?php endforeach;?></table><?php else:?><div class="note">Importe migracion_parametros_automatizaciones_v217.sql.</div><?php endif;?></section>

<section class="card"><h2>Repuestos integrados</h2><span class="status <?=$repuestosIntegrados?'ok':'warn'?>"><?=$repuestosIntegrados?'INTEGRADOS':'REVISAR'?></span><p>Repuestos puede convivir con Control, Señalización, Accesorios e IEP o venderse solo.</p><p class="mini">Esta tarjeta valida que el flujo principal ya no fuerce una cotización exclusiva de Repuestos.</p></section>

<section class="card" id="limites" style="grid-column:1/-1"><h2>Cantidad física de límites</h2><span class="status <?=$limites?'ok':'warn'?>"><?=$limites?'PARAMETRIZADA':'TABLA NO DETECTADA'?></span><p>Fuente única: <strong>limites_cantidad_reglas</strong>. No usa ni modifica <strong>limites_paradas</strong>.</p>
<?php if($limites):?>
<details class="new-rule"><summary><strong>+ Nueva regla de cantidad de límites</strong></summary><form method="post" class="limit-form" style="margin-top:10px"><?= automacCsrfInput() ?><input type="hidden" name="guardar_limite_regla" value="1"><input type="hidden" name="regla_id" value="0"><label>Tipo/familia<input name="familia_control" required placeholder="HIDRAULICO"></label><label>Subtipo<select name="ctrlsubtipo_id"><option value="">Todos los subtipos</option><?php foreach($subtipos as $s):?><option value="<?=(int)$s['ctrlsubtipo_id']?>"><?=auE($s['ctrlsubtipo_name'])?></option><?php endforeach;?></select></label><label>Contactor<select name="contactor_potencial"><option>NO</option><option>SI</option></select></label><label>Vel. desde<input type="number" step="0.01" name="velocidad_desde" value="0"></label><label>Vel. hasta<input type="number" step="0.01" name="velocidad_hasta" value="210"></label><label>Retorno batería<select name="retorno_bateria_gel"><option value="NA">No aplica</option><option value="NO">No</option><option value="SI">Sí</option></select></label><label>Cant.<input type="number" min="1" name="cantidad_limites" required></label><label>Orden<input type="number" name="regla_orden" value="999"></label><label><input type="checkbox" name="regla_activa" checked> Activa<br><button class="btn" style="margin-top:4px">Agregar</button></label></form></details>
<div class="scroll"><table class="table"><tr><th>Tipo / familia</th><th>Subtipo</th><th>Contactor</th><th>Velocidad</th><th>Retorno batería</th><th>Cantidad</th><th>Estado / edición</th></tr><?php foreach($limites as $r):?><tr><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="guardar_limite_regla" value="1"><input type="hidden" name="regla_id" value="<?=(int)$r['regla_id']?>"><td><input name="familia_control" value="<?=auE($r['familia_control'])?>" style="width:130px"></td><td><select name="ctrlsubtipo_id"><option value="">Todos</option><?php foreach($subtipos as $s):?><option value="<?=(int)$s['ctrlsubtipo_id']?>"<?=((int)($r['ctrlsubtipo_id']??0)===(int)$s['ctrlsubtipo_id'])?' selected':''?>><?=auE($s['ctrlsubtipo_name'])?></option><?php endforeach;?></select><?php if(!empty($r['ctrlsubtipo_name'])):?><div class="mini friendly"><?=auE($r['ctrlsubtipo_name'])?></div><?php endif;?></td><td><select name="contactor_potencial"><option value="NO"<?=$r['contactor_potencial']==='NO'?' selected':''?>>No</option><option value="SI"<?=$r['contactor_potencial']==='SI'?' selected':''?>>Sí</option></select></td><td><input type="number" step="0.01" name="velocidad_desde" value="<?=auE($r['velocidad_desde'])?>" style="width:82px"> a <input type="number" step="0.01" name="velocidad_hasta" value="<?=auE($r['velocidad_hasta'])?>" style="width:82px"></td><td><select name="retorno_bateria_gel"><option value="NA"<?=$r['retorno_bateria_gel']==='NA'?' selected':''?>>No aplica</option><option value="NO"<?=$r['retorno_bateria_gel']==='NO'?' selected':''?>>No</option><option value="SI"<?=$r['retorno_bateria_gel']==='SI'?' selected':''?>>Sí</option></select></td><td><input type="number" min="1" name="cantidad_limites" value="<?=(int)$r['cantidad_limites']?>" style="width:70px"><input type="hidden" name="regla_orden" value="<?=(int)$r['regla_orden']?>"></td><td><label><input type="checkbox" name="regla_activa"<?=$r['regla_activa']==='SI'?' checked':''?>> Activa</label> <button class="btn">Guardar</button></td></form></tr><?php endforeach;?></table></div>
<?php else:?><div class="note">Importe migracion_limites_cantidad_v207.sql.</div><?php endif;?></section>

<section class="card"><h2>Equivalencias de códigos</h2><span class="status ok"><?=count($equiv)?> relaciones</span><div class="scroll"><?php if($equiv):?><table class="table"><tr><th>Origen</th><th>Destino</th><th>Activa</th></tr><?php foreach($equiv as $r):?><tr><td><?=auE($r['codigo_origen']??'')?></td><td><?=auE($r['codigo_destino']??'')?></td><td><?=auE($r['equivalencia_activa']??'')?></td></tr><?php endforeach;?></table><?php endif;?></div></section>
</div></main>
<script>document.querySelectorAll('.auto-form').forEach(function(f){var s=f.querySelector('.modo-cantidad'),q=f.querySelector('.qty-fixed');if(!s||!q)return;function sync(){q.hidden=s.value!=='FIJA';var i=q.querySelector('input');if(i)i.disabled=s.value!=='FIJA';}s.addEventListener('change',sync);sync();});</script>
</body></html>
