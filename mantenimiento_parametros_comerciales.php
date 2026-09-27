<?php
require_once 'auth.php';require_once 'conexion.php';require_once 'parametros_sistema.php';exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
function mpcE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$mensaje='';$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        if(!psTablaExiste($conexion,'parametros_comerciales')) throw new RuntimeException('Falta la tabla parametros_comerciales. Importe la migración v217.');
        $clave=strtoupper(trim((string)($_POST['clave']??'')));
        $valor=(float)($_POST['valor']??0);
        if($clave===''||$valor<0||$valor>100) throw new RuntimeException('Valor inválido. Para descuentos use un porcentaje entre 0 y 100.');
        $st=$conexion->prepare("UPDATE parametros_comerciales SET parametro_valor=? WHERE parametro_clave=?");
        $st->bind_param('ds',$valor,$clave);$st->execute();$st->close();$mensaje='Parámetro actualizado.';
    }catch(Throwable $e){$error=$e->getMessage();}
}
$filas=array();if(psTablaExiste($conexion,'parametros_comerciales')){$r=$conexion->query("SELECT * FROM parametros_comerciales ORDER BY FIELD(parametro_modulo,'CONTROL','SENALIZACION','ACCESORIOS','REPUESTOS','GENERAL'),parametro_id");if($r)while($x=$r->fetch_assoc())$filas[]=$x;}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Condiciones comerciales</title><link rel="stylesheet" href="automac-ui.css"><style>.wrap{max-width:1300px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dde5ea;border-radius:14px;padding:18px;margin-bottom:14px}.table{width:100%;border-collapse:collapse}.table th,.table td{padding:9px;border-bottom:1px solid #e4eaee;text-align:left}.table th{background:#eef4f8}.table input{width:110px;padding:7px}.btn{padding:8px 11px;border:0;border-radius:8px;background:#0f5f9c;color:#fff;font-weight:800}.ok{background:#e7f7ee;color:#17603a;padding:10px;border-radius:9px}.err{background:#fde9e9;color:#922;padding:10px;border-radius:9px}.note{color:#64748b}</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Condiciones comerciales</h1><p class="note">Valores predeterminados para documentos nuevos. Las cotizaciones y pedidos ya emitidos conservan los descuentos guardados en su histórico.</p></section><?php if($mensaje):?><div class="ok"><?=mpcE($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=mpcE($error)?></div><?php endif;?><section class="card"><?php if(!$filas):?><p>Falta importar <strong>migracion_parametros_automatizaciones_v217.sql</strong>.</p><?php else:?><table class="table"><tr><th>Módulo</th><th>Parámetro</th><th>Valor</th><th>Acción</th></tr><?php foreach($filas as $f):?><tr><td><?=mpcE($f['parametro_modulo'])?></td><td><strong><?=mpcE($f['parametro_nombre'])?></strong><br><small><?=mpcE($f['parametro_clave'])?></small></td><td colspan="2"><form method="post" style="display:flex;gap:8px;align-items:center"><?= automacCsrfInput() ?><input type="hidden" name="clave" value="<?=mpcE($f['parametro_clave'])?>"><input type="number" name="valor" min="0" max="100" step="0.01" value="<?=mpcE($f['parametro_valor'])?>">% <button class="btn">Guardar</button></form></td></tr><?php endforeach;?></table><?php endif;?></section></main></body></html>
