<?php
/* v232 detalle textos: concepto/formula_aplicada normalizados para evitar CONCAT/LIKE con collation binaria. */
require_once 'auth.php';
require_once 'conexion.php';
exigirRoles(array('ADMINISTRADOR'));

function ddE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ddClase($v){return preg_replace('/[^A-Z0-9_-]+/','-',strtoupper(trim((string)$v)));}

$vista=(string)($_GET['vista']??'tablas');
$phpFiles=glob(__DIR__.'/*.php')?:array();
$contenidos=array();
foreach($phpFiles as $f)$contenidos[basename($f)]=(string)@file_get_contents($f);

$tablas=array();
$r=$conexion->query('SHOW TABLES');
if($r)while($x=$r->fetch_row())$tablas[]=(string)$x[0];
sort($tablas);

$retiradosV228=array(
 'menu1.php'=>'Menú histórico sin referencias entrantes; reemplazado por menu.php.',
 'menu2.php'=>'Menú histórico sin referencias entrantes; reemplazado por menu.php.',
 'calcular_senalizacion_revisado.php'=>'Wrapper histórico sin referencias entrantes; el flujo vigente usa calcular_senalizacion.php.',
 'mantenimiento_control.php.bak'=>'Copia de desarrollo, no ejecutable por la aplicación.',
 'mantenimiento_control.php.pre_v212'=>'Copia previa de desarrollo, no ejecutable por la aplicación.'
);

$backupsConfirmadosV231=array(
 'backup_historial_senal_pre_fix_20260809',
 'backup_lista_precios_senal_pre_fix_20260809',
 'backup_productos_senalizacion_pre_fix_20260809',
 'material_hueco_reglas_of_respaldo_20260805',
 'matriz_calculos_respaldo_20260804_2102'
);

$tableAudit=array();
foreach($tablas as $t){
    $refs=array();
    foreach($contenidos as $fn=>$txt)if(stripos($txt,$t)!==false)$refs[]=$fn;
    $backup=(bool)preg_match('/(^backup_|_respaldo_|_backup_)/i',$t);
    $backupSinUso=$backup && count($refs)===0;
    $legacyArchivada=($t==='legacy_limites_reglas_v217');
    $legacyVieja=($t==='limites_reglas');
    $backupConfirmado=in_array($t,$backupsConfirmadosV231,true) && count($refs)===0;
    if($legacyArchivada)$estado='LEGACY CONFIRMADA';
    elseif($legacyVieja)$estado='LEGACY';
    elseif($backupConfirmado)$estado='LISTA PARA RETIRO';
    elseif($backupSinUso)$estado='ARCHIVABLE';
    elseif($backup)$estado='RESPALDO';
    else $estado=count($refs)===0?'REVISAR':'ACTIVA';
    $tableAudit[]=array('tabla'=>$t,'refs'=>$refs,'backup'=>$backup,'legacy_archivada'=>$legacyArchivada,'legacy_vieja'=>$legacyVieja,'estado'=>$estado,'backup_sin_uso'=>$backupSinUso,'backup_confirmado'=>$backupConfirmado);
}

$fileAudit=array();
foreach($contenidos as $fn=>$txt){
    if($fn==='diagnostico_depuracion.php')continue;
    $refs=array();
    foreach($contenidos as $other=>$otxt){if($other!==$fn && stripos($otxt,$fn)!==false)$refs[]=$other;}
    $entry=(bool)preg_match('/^(index|login|logout|inicio|mantenimiento|cotizaciones|pedidos|ordenes_fabricacion|comercial|sistema_comercial|administrar_precios|administrar_clientes|administrar_usuarios|mis_preferencias|cambiar_clave)\.php$/i',$fn);
    $estado=(count($refs)===0&&!$entry)?'REVISAR':'ACTIVO';
    $fileAudit[]=array('archivo'=>$fn,'refs'=>$refs,'entry'=>$entry,'estado'=>$estado,'size'=>strlen($txt),'select_all'=>substr_count(strtoupper($txt),'SELECT *'));
}
usort($fileAudit,function($a,$b){return strcmp($a['archivo'],$b['archivo']);});

/* Integridad SQL: diagnóstico informativo, sin ALTER automático. */
$collationIssues=array();
$qColl=$conexion->query("SELECT column_name,COUNT(DISTINCT collation_name) AS cant,
 GROUP_CONCAT(DISTINCT collation_name ORDER BY collation_name SEPARATOR ' | ') AS collations,
 GROUP_CONCAT(CONCAT(table_name,'.',column_name,'=',collation_name) ORDER BY table_name SEPARATOR ' ; ') AS detalle
 FROM information_schema.columns
 WHERE table_schema=DATABASE() AND collation_name IS NOT NULL
   AND (LOWER(column_name) LIKE '%codigo%' OR LOWER(column_name) IN ('descripcion','categoria','modelo_pulsador','tipo_modulos','origen_valor'))
 GROUP BY column_name HAVING COUNT(DISTINCT collation_name)>1 ORDER BY column_name");
if($qColl)while($x=$qColl->fetch_assoc())$collationIssues[]=$x;

$sinPk=array();
$qPk=$conexion->query("SELECT t.table_name,t.engine,t.table_rows FROM information_schema.tables t
 WHERE t.table_schema=DATABASE() AND t.table_type='BASE TABLE'
 AND NOT EXISTS (SELECT 1 FROM information_schema.statistics s WHERE s.table_schema=t.table_schema AND s.table_name=t.table_name AND s.index_name='PRIMARY')
 ORDER BY t.table_name");
if($qPk)while($x=$qPk->fetch_assoc())$sinPk[]=$x;

$engineIssues=array();
$qEng=$conexion->query("SELECT table_name,engine,table_rows FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND COALESCE(engine,'')<>'InnoDB' ORDER BY table_name");
if($qEng)while($x=$qEng->fetch_assoc())$engineIssues[]=$x;

$codigoSinIndice=array();
$qIdx=$conexion->query("SELECT c.table_name,c.column_name,t.table_rows
 FROM information_schema.columns c
 JOIN information_schema.tables t ON t.table_schema=c.table_schema AND t.table_name=c.table_name
 WHERE c.table_schema=DATABASE() AND t.table_type='BASE TABLE' AND t.table_rows>=100
   AND LOWER(c.column_name) IN ('codigo','accesorio_codigo','limite_codigo','codigo_origen','codigo_destino')
   AND NOT EXISTS (
      SELECT 1 FROM information_schema.statistics s
      WHERE s.table_schema=c.table_schema AND s.table_name=c.table_name AND s.column_name=c.column_name
   )
 ORDER BY t.table_rows DESC,c.table_name");
if($qIdx)while($x=$qIdx->fetch_assoc())$codigoSinIndice[]=$x;

$indicesHistoricosEsperados=array(
 'cotizaciones_detalle'=>'idx_cotdet_codigo',
 'cotizaciones_revisiones_detalle'=>'idx_cotrevdet_codigo',
 'pedidos_detalle'=>'idx_peddet_codigo',
 'pedidos_revisiones_detalle'=>'idx_pedrevdet_codigo',
 'senal_pulsadores_exteriores_matriz'=>'idx_senal_puls_ext_codigo'
);
$indicesHistoricosFaltantes=array();
foreach($indicesHistoricosEsperados as $tablaIdx=>$indiceIdx){
    $st=$conexion->prepare('SELECT 1 FROM information_schema.statistics WHERE table_schema=DATABASE() AND table_name=? AND index_name=? LIMIT 1');
    if($st){$st->bind_param('ss',$tablaIdx,$indiceIdx);$st->execute();$ok=(bool)$st->get_result()->fetch_row();$st->close();if(!$ok)$indicesHistoricosFaltantes[]=array('tabla'=>$tablaIdx,'indice'=>$indiceIdx);}
}

$collationObjetivo='utf8mb4_unicode_ci';
$columnasCodigoCriticas=array(
 'equivalencias_codigos'=>array('codigo_origen','codigo_destino'),
 'cotizaciones_detalle'=>array('codigo'),
 'cotizaciones_revisiones_detalle'=>array('codigo'),
 'pedidos_detalle'=>array('codigo'),
 'pedidos_revisiones_detalle'=>array('codigo')
);
$columnasCriticasFueraObjetivo=array();
foreach($columnasCodigoCriticas as $tablaC=>$colsC){foreach($colsC as $colC){
    $st=$conexion->prepare('SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    if($st){$st->bind_param('ss',$tablaC,$colC);$st->execute();$rr=$st->get_result()->fetch_assoc();$st->close();if($rr && strcasecmp((string)$rr['collation_name'],$collationObjetivo)!==0)$columnasCriticasFueraObjetivo[]=array('tabla'=>$tablaC,'columna'=>$colC,'collation'=>$rr['collation_name']);}
}}

$columnasTextoV230=array(
 'cotizaciones_detalle'=>array('descripcion'),
 'cotizaciones_revisiones_detalle'=>array('descripcion'),
 'pedidos_detalle'=>array('descripcion'),
 'pedidos_revisiones_detalle'=>array('descripcion'),
 'senal_adic_llave_asc_electromecanico'=>array('modelo_pulsador'),
 'senal_braille_lateral_ascensorista'=>array('modelo_pulsador'),
 'senal_llave_ascensorista_cabina'=>array('modelo_pulsador'),
 'senal_adic_parada_indicador_cabina'=>array('tipo_modulos'),
 'senal_indicadores_cabina'=>array('tipo_modulos')
);
$columnasTextoV230FueraObjetivo=array();
foreach($columnasTextoV230 as $tablaC=>$colsC){foreach($colsC as $colC){
    $st=$conexion->prepare('SELECT collation_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    if($st){$st->bind_param('ss',$tablaC,$colC);$st->execute();$rr=$st->get_result()->fetch_assoc();$st->close();if($rr && strcasecmp((string)$rr['collation_name'],$collationObjetivo)!==0)$columnasTextoV230FueraObjetivo[]=array('tabla'=>$tablaC,'columna'=>$colC,'collation'=>$rr['collation_name']);}
}}

$largest=$fileAudit;
usort($largest,function($a,$b){return $b['size']<=>$a['size'];});
$largest=array_slice($largest,0,12);

$hard=array();
$hayParamCom=in_array('parametros_comerciales',$tablas,true);
$hayAutomatizaciones=in_array('automatizaciones_cotizador',$tablas,true);
$hayRepIntegracion=in_array('repuestos_reglas_integracion',$tablas,true);
$indexTxt=$contenidos['index.php']??'';
$hard[]=array('origen'=>'index.php + repuestos_integracion.php','tema'=>'Repuestos integrados con otros módulos','tratamiento'=>'Reglas mantenibles; venta conjunta habilitada','resuelto'=>$hayRepIntegracion && strpos($indexTxt,'repuestos_integracion.php')!==false);
$hard[]=array('origen'=>'automatizaciones_cotizador','tema'=>'Luz de emergencia → Alarma de emergencia','tratamiento'=>'Cantidad MISMA copia la cantidad de origen','resuelto'=>$hayAutomatizaciones);
$hard[]=array('origen'=>'parametros_comerciales','tema'=>'Condiciones y descuentos comerciales','tratamiento'=>'Parámetros centralizados en base','resuelto'=>$hayParamCom);
$hard[]=array('origen'=>'calcular_limites_accesorios.php','tema'=>'Cantidad física de límites','tratamiento'=>'Fuente exclusiva: limites_cantidad_reglas; separada de limites_paradas','resuelto'=>strpos($contenidos['calcular_limites_accesorios.php']??'','limites_cantidad_reglas')!==false);
$hard[]=array('origen'=>'index.php','tema'=>'Familia de rescate por tipo de control','tratamiento'=>'Se lee desde control_tipo_capacidades; v229 elimina fallback por IDs numéricos','resuelto'=>strpos($indexTxt,"tipo.value === '3'")===false);
$hard[]=array('origen'=>'index.php','tema'=>'Texto de descuentos predeterminados','tratamiento'=>'Ya no fija 30 + 10 + 10 en la ayuda; los valores provienen de parametros_comerciales','resuelto'=>strpos($indexTxt,'aparecen por defecto como 30 + 10 + 10')===false);
$hard[]=array('origen'=>'index.php','tema'=>'Capacidad de maniobra sabática','tratamiento'=>'La visibilidad depende de capacidades configuradas; v229 elimina texto fijo A6300V4/A6700V2','resuelto'=>strpos($indexTxt,'Solo A6300V4/A6700V2')===false);


/* v231: auditoría funcional estática de flujo completo. No genera documentos ni altera datos. */
$flujoChecks=array();
$agregarFlujo=function($area,$control,$ok,$detalle='') use (&$flujoChecks){$flujoChecks[]=array('area'=>$area,'control'=>$control,'ok'=>(bool)$ok,'detalle'=>$detalle);};
$archivosCriticos=array(
 'Cotizador'=>'index.php','Cálculo Control'=>'calcular.php','Cálculo Señalización'=>'calcular_senalizacion.php',
 'Cantidad límites'=>'calcular_limites_accesorios.php','Integración Repuestos'=>'repuestos_integracion.php',
 'PDF cotización'=>'generar_pdf_cotizacion.php','Revisiones'=>'ver_revision_cotizacion.php',
 'Crear pedido'=>'crear_pedido.php','PDF pedido'=>'generar_pdf_pedido.php','Órdenes'=>'ordenes_fabricacion.php',
 'PDF OF'=>'generar_pdf_orden_fabricacion.php'
);
foreach($archivosCriticos as $area=>$archivo)$agregarFlujo($area,'Archivo operativo',isset($contenidos[$archivo]),$archivo);
$tablasCriticas=array('cotizaciones','cotizaciones_detalle','cotizaciones_revisiones','cotizaciones_revisiones_detalle','pedidos','pedidos_detalle','pedidos_revisiones','pedidos_revisiones_detalle','repuestos_reglas_integracion','automatizaciones_cotizador','limites_cantidad_reglas','matriz_calculos','productos_repuestos','productos_senalizacion');
foreach($tablasCriticas as $tablaF)$agregarFlujo('Base de datos','Tabla crítica',in_array($tablaF,$tablas,true),$tablaF);
$agregarFlujo('Repuestos','Consumidor de reglas',isset($contenidos['index.php']) && strpos($contenidos['index.php'],'repuestos_integracion.php')!==false,'index.php incluye repuestos_integracion.php');
$agregarFlujo('Límites físicos','Fuente correcta',isset($contenidos['calcular_limites_accesorios.php']) && strpos($contenidos['calcular_limites_accesorios.php'],'limites_cantidad_reglas')!==false && strpos($contenidos['calcular_limites_accesorios.php'],'FROM limites_paradas')===false,'No mezclar con límites de paradas');
$agregarFlujo('Señalización','Motor vigente',isset($contenidos['calcular_senalizacion.php']),'calcular_senalizacion.php');
$flujoFallos=count(array_filter($flujoChecks,function($x){return !$x['ok'];}));
$phpSinEntrada=count(array_filter($fileAudit,function($x){return $x['estado']==='REVISAR';}));

/* Hardcode técnico residual detectado: se informa y NO se elimina hasta parametrizar. */
$hard[]=array('origen'=>'calcular.php','tema'=>'Mapeo técnico CPU → modelo de límites de paradas','tratamiento'=>'Todavía existe un mapa técnico explícito para A6220V5/A6300V4/A6700V2/CLEX/DANGELICA. Mantener hasta parametrizar modelo_control/expansion en base.','resuelto'=>false);
$hard[]=array('origen'=>'limites_paradas.php','tema'=>'Mapeo técnico de modelos de límites','tratamiento'=>'La sincronización operativa todavía traduce nombres de modelos a CPU. No retirar ni generalizar sin migración de datos.','resuelto'=>false);

if(isset($_GET['exportar'])&&$_GET['exportar']==='csv'){
 header('Content-Type:text/csv; charset=UTF-8');
 header('Content-Disposition: attachment; filename="plan_depuracion_v231.csv"');
 $o=fopen('php://output','w');
 fputcsv($o,array('TIPO','NOMBRE','ESTADO','REFERENCIAS/DETALLE'));
 foreach($tableAudit as $x)fputcsv($o,array('TABLA',$x['tabla'],$x['estado'],implode(' | ',$x['refs'])));
 foreach($fileAudit as $x)fputcsv($o,array('ARCHIVO',$x['archivo'],$x['estado'],implode(' | ',$x['refs'])));
 foreach($retiradosV228 as $n=>$m)fputcsv($o,array('ARCHIVO',$n,'RETIRADO V228',$m));
 foreach($collationIssues as $x)fputcsv($o,array('COLLATION',$x['column_name'],'REVISAR',$x['detalle']));
 fclose($o);exit;
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Depuración del sistema</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1580px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dde5ea;border-radius:14px;padding:18px;margin-bottom:14px}.hero h1{margin:0 0 5px}.hero p{margin:0;color:#64748b}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}.tabs a,.btn{padding:9px 12px;border-radius:8px;text-decoration:none;font-weight:800;background:#eef4f8;color:#234}.tabs a.on,.btn{background:#0f5f9c;color:#fff}.table{width:100%;border-collapse:collapse;font-size:14px}.table th,.table td{padding:10px;border-bottom:1px solid #e4eaee;text-align:left;vertical-align:top}.table th{background:#eef4f8;position:sticky;top:0}.scroll{overflow:auto;max-height:68vh}.pill{display:inline-block;border-radius:999px;padding:5px 8px;font-weight:800;font-size:11px;white-space:nowrap}.ACTIVA,.ACTIVO,.RESUELTO{background:#e7f7ee;color:#17603a}.RESPALDO,.LEGACY{background:#fff1d6;color:#805900}.ARCHIVABLE{background:#eef3f7;color:#465d70}.LISTA-PARA-RETIRO{background:#e8f7ef;color:#17603a}.LEGACY-CONFIRMADA,.RETIRADO-V228{background:#e8eef6;color:#274b70}.REVISAR{background:#fde9e9;color:#922}.refs,.muted{color:#64748b;word-break:break-word}.warn{background:#fff8e7;border:1px solid #ead9a7;border-radius:10px;padding:11px;color:#6d5415;margin:10px 0}.ok{color:#17603a;font-weight:800}.metric-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px;margin:12px 0}.metric{border:1px solid #dde5ea;border-radius:12px;padding:13px;background:#f8fbfd}.metric strong{font-size:24px;display:block;color:#173b5d}.good{background:#eef8f2;border-left:4px solid #16865a;border-radius:9px;padding:12px;margin:10px 0}.bad{background:#fff1f1;border-left:4px solid #b33;border-radius:9px;padding:12px;margin:10px 0}code{font-size:.92em}
</style></head><body><?php require 'menu.php';?><main class="wrap">
<section class="hero"><h1>Diagnóstico → Depuración y optimización</h1><p>Auditoría final de tablas, código, integridad SQL y flujo funcional. No se ejecutan acciones destructivas desde esta pantalla.</p><div class="tabs">
<a class="<?=$vista==='tablas'?'on':''?>" href="?vista=tablas">Tablas</a><a class="<?=$vista==='codigo'?'on':''?>" href="?vista=codigo">Código PHP</a><a class="<?=$vista==='sql'?'on':''?>" href="?vista=sql">Integridad SQL</a><a class="<?=$vista==='rendimiento'?'on':''?>" href="?vista=rendimiento">Optimización</a><a class="<?=$vista==='hardcode'?'on':''?>" href="?vista=hardcode">Reglas</a><a class="<?=$vista==='flujo'?'on':''?>" href="?vista=flujo">Prueba integral</a><a class="btn" href="?exportar=csv">Exportar CSV</a></div></section>

<?php if($vista==='tablas'):?>
<section class="card"><h2>Tablas de la base (<?=count($tableAudit)?>)</h2><div class="warn"><strong>Política:</strong> primero clasificar y verificar. No se ejecutan DROP/DELETE desde esta pantalla.</div><div class="scroll"><table class="table"><tr><th>Tabla</th><th>Estado</th><th>Referencias</th><th>Tratamiento</th></tr><?php foreach($tableAudit as $x):?><tr><td><strong><?=ddE($x['tabla'])?></strong></td><td><span class="pill <?=ddClase($x['estado'])?>"><?=ddE($x['estado'])?></span></td><td class="refs"><?=ddE($x['refs']?implode(', ',$x['refs']):'Sin referencia literal detectada')?></td><td><?php if($x['legacy_archivada']):?><strong>Legacy confirmada / no operativa.</strong> Conservar hasta respaldo externo y depuración final.<?php elseif($x['legacy_vieja']):?>Tabla vieja de límites: archivar antes de retirar.<?php elseif(!empty($x['backup_confirmado'])):?><strong>Confirmada v231: sin consumidor operativo detectado.</strong> Lista para retirar de producción únicamente después de respaldo externo.<?php elseif(!empty($x['backup_sin_uso'])):?><strong>Sin consumidor literal detectado.</strong> Archivable después de verificar backup externo.<?php elseif($x['backup']):?>Respaldo interno: comparar con respaldo externo antes de retirar.<?php elseif(!$x['refs']):?>Revisar consultas dinámicas, migraciones e históricos.<?php else:?><span class="ok">Conservar</span><?php endif;?></td></tr><?php endforeach;?></table></div></section>

<?php elseif($vista==='codigo'):?>
<section class="card"><h2>Código PHP operativo (<?=count($fileAudit)?>)</h2><div class="good"><strong>Retiro confirmado v228 (conservado en v229):</strong> se quitaron del paquete operativo 3 PHP legacy y 2 copias de desarrollo. No se modificaron históricos ni base.</div><table class="table"><tr><th>Archivo retirado</th><th>Motivo</th></tr><?php foreach($retiradosV228 as $n=>$m):?><tr><td><span class="pill RETIRADO-V228">RETIRADO V228</span> <strong><?=ddE($n)?></strong></td><td><?=ddE($m)?></td></tr><?php endforeach;?></table><div class="good"><strong>Auditoría v231:</strong> PHP operativos sin referencia entrante no explicada: <strong><?=$phpSinEntrada?></strong>. Si este valor es 0, no hay nuevos candidatos automáticos de retiro.</div><h3>Archivos restantes</h3><div class="scroll"><table class="table"><tr><th>Archivo</th><th>Estado</th><th>Referenciado desde</th><th>Tamaño</th></tr><?php foreach($fileAudit as $x):?><tr><td><strong><?=ddE($x['archivo'])?></strong></td><td><span class="pill <?=ddClase($x['estado'])?>"><?=ddE($x['estado'])?></span></td><td class="refs"><?=ddE($x['refs']?implode(', ',$x['refs']):'Sin referencia literal entrante; verificar URL/AJAX antes de retirar')?></td><td><?=number_format($x['size']/1024,1,',','.')?> KB</td></tr><?php endforeach;?></table></div></section>

<?php elseif($vista==='sql'):?>
<section class="card"><h2>Integridad SQL</h2><div class="metric-grid"><div class="metric"><span>Collations mixtas relevantes</span><strong><?=count($collationIssues)?></strong></div><div class="metric"><span>Tablas sin PK</span><strong><?=count($sinPk)?></strong></div><div class="metric"><span>Tablas fuera de InnoDB</span><strong><?=count($engineIssues)?></strong></div><div class="metric"><span>Códigos grandes sin índice</span><strong><?=count($codigoSinIndice)?></strong></div><div class="metric"><span>Índices críticos v229/v230 faltantes</span><strong><?=count($indicesHistoricosFaltantes)?></strong></div><div class="metric"><span>Códigos críticos fuera de unicode_ci</span><strong><?=count($columnasCriticasFueraObjetivo)?></strong></div></div>
<h3>Collations mixtas</h3><?php if(!$collationIssues):?><div class="good">No se detectaron mezclas relevantes en columnas de códigos/catálogos.</div><?php else:?><div class="bad">Estas diferencias pueden provocar errores “Illegal mix of collations” al hacer JOIN o comparar columnas. Se informan; no se alteran automáticamente.</div><div class="scroll"><table class="table"><tr><th>Columna</th><th>Collations</th><th>Detalle</th></tr><?php foreach($collationIssues as $x):?><tr><td><strong><?=ddE($x['column_name'])?></strong></td><td><?=ddE($x['collations'])?></td><td class="refs"><?=ddE($x['detalle'])?></td></tr><?php endforeach;?></table></div><?php endif;?>
<h3>Tablas sin clave primaria</h3><?php if(!$sinPk):?><div class="good">Todas las tablas base tienen PRIMARY KEY.</div><?php else:?><table class="table"><tr><th>Tabla</th><th>Motor</th><th>Filas estimadas</th></tr><?php foreach($sinPk as $x):?><tr><td><?=ddE($x['table_name'])?></td><td><?=ddE($x['engine'])?></td><td><?=ddE($x['table_rows'])?></td></tr><?php endforeach;?></table><?php endif;?>
<h3>Normalización v229 + v230</h3><?php if(!$indicesHistoricosFaltantes && !$columnasCriticasFueraObjetivo && !$columnasTextoV230FueraObjetivo && !$collationIssues):?><div class="good">La normalización v229 + v230 de códigos, textos relacionados e índices críticos ya está aplicada.</div><?php else:?><div class="warn"><strong>Migración pendiente:</strong> ejecute primero <code>migracion_integridad_sql_v229.sql</code> si aún falta y luego <code>migracion_integridad_sql_v230.sql</code>. v230 normaliza las collations residuales de descripción/modelo_pulsador/tipo_modulos y agrega el índice de pulsadores exteriores, sin recalcular documentos.</div><?php if($columnasCriticasFueraObjetivo):?><table class="table"><tr><th>Tabla</th><th>Columna</th><th>Collation actual</th><th>Objetivo</th></tr><?php foreach($columnasCriticasFueraObjetivo as $x):?><tr><td><?=ddE($x['tabla'])?></td><td><?=ddE($x['columna'])?></td><td><?=ddE($x['collation'])?></td><td>utf8mb4_unicode_ci</td></tr><?php endforeach;?></table><?php endif;?><?php if($columnasTextoV230FueraObjetivo):?><table class="table"><tr><th>Tabla</th><th>Columna texto v230</th><th>Collation actual</th><th>Objetivo</th></tr><?php foreach($columnasTextoV230FueraObjetivo as $x):?><tr><td><?=ddE($x['tabla'])?></td><td><?=ddE($x['columna'])?></td><td><?=ddE($x['collation'])?></td><td>utf8mb4_unicode_ci</td></tr><?php endforeach;?></table><?php endif;?><?php endif;?>
<h3>Columnas de código sin índice en tablas grandes</h3><?php if(!$codigoSinIndice):?><div class="good">No se detectaron candidatos evidentes.</div><?php else:?><table class="table"><tr><th>Tabla</th><th>Columna</th><th>Filas estimadas</th></tr><?php foreach($codigoSinIndice as $x):?><tr><td><?=ddE($x['table_name'])?></td><td><?=ddE($x['column_name'])?></td><td><?=ddE($x['table_rows'])?></td></tr><?php endforeach;?></table><?php endif;?>
</section>

<?php elseif($vista==='rendimiento'):?>
<section class="card"><h2>Optimización aplicada y candidatos</h2><div class="good"><strong>Aplicado v228:</strong> <code>schema_guard.php</code> cachea existencia de tablas/columnas durante cada request; el cargador de matrices especiales de <code>index.php</code> reutiliza resultados dentro del request; <code>repuestos_integracion.php</code> evita SELECT * y separa observaciones de regla/producto.</div><div class="metric-grid"><div class="metric"><span>PHP operativos</span><strong><?=count($fileAudit)+1?></strong></div><div class="metric"><span>PHP legacy retirados</span><strong>3</strong></div><div class="metric"><span>Copias de desarrollo retiradas</span><strong>2</strong></div><div class="metric"><span>Tablas de respaldo detectadas</span><strong><?=count(array_filter($tableAudit,function($x){return $x['backup'];}))?></strong></div></div>
<h3>PHP de mayor tamaño</h3><table class="table"><tr><th>Archivo</th><th>Tamaño</th><th>SELECT * detectados</th><th>Observación</th></tr><?php foreach($largest as $x):?><tr><td><strong><?=ddE($x['archivo'])?></strong></td><td><?=number_format($x['size']/1024,1,',','.')?> KB</td><td><?=ddE($x['select_all'])?></td><td><?=($x['size']>250000?'Candidato a modularización futura; no dividir sin pruebas funcionales.':'Sin acción urgente.')?></td></tr><?php endforeach;?></table>
<div class="warn"><strong>v231:</strong> con Integridad SQL en cero, la prioridad pasa a retiro controlado de backups confirmados y prueba integral del flujo. No se eliminan tablas automáticamente.</div></section>

<?php elseif($vista==='flujo'):?>
<section class="card"><h2>Prueba integral estática</h2><div class="metric-grid"><div class="metric"><span>Controles realizados</span><strong><?=count($flujoChecks)?></strong></div><div class="metric"><span>Fallos detectados</span><strong><?=$flujoFallos?></strong></div><div class="metric"><span>PHP sin entrada no explicada</span><strong><?=$phpSinEntrada?></strong></div></div><?php if($flujoFallos===0):?><div class="good">La estructura necesaria para Cotización → revisión → pedido → orden → PDF está presente. Falta únicamente la prueba manual con datos reales, porque este diagnóstico no crea ni modifica documentos.</div><?php else:?><div class="bad">Hay dependencias críticas faltantes. Revisar antes de emitir documentos.</div><?php endif;?><table class="table"><tr><th>Estado</th><th>Área</th><th>Control</th><th>Detalle</th></tr><?php foreach($flujoChecks as $x):?><tr><td><span class="pill <?=$x['ok']?'ACTIVO':'REVISAR'?>"><?=$x['ok']?'OK':'FALTA'?></span></td><td><?=ddE($x['area'])?></td><td><?=ddE($x['control'])?></td><td class="refs"><?=ddE($x['detalle'])?></td></tr><?php endforeach;?></table><h3>Prueba manual recomendada</h3><div class="warn">Crear una cotización de prueba con Control + Señalización + Accesorios + Repuestos, guardar, generar PDF, crear revisión, convertir a pedido, generar revisión de pedido y OF/PDF. Confirmar que los importes históricos no cambian al reabrir.</div></section>

<?php else:?>
<section class="card"><h2>Reglas y parametrización</h2><table class="table"><tr><th>Estado</th><th>Tema</th><th>Origen</th><th>Tratamiento</th></tr><?php foreach($hard as $x):?><tr><td><span class="pill <?=$x['resuelto']?'RESUELTO':'REVISAR'?>"><?=$x['resuelto']?'RESUELTO':'PENDIENTE'?></span></td><td><?=ddE($x['tema'])?></td><td><?=ddE($x['origen'])?></td><td><?=ddE($x['tratamiento'])?></td></tr><?php endforeach;?></table></section>
<?php endif;?>
<section class="card"><h2>Política de limpieza</h2><p>Detectar → verificar dependencias directas y dinámicas → validar históricos/documentos → respaldar → retirar únicamente lo confirmado. Los documentos históricos no se recalculan ni se reescriben.</p></section>
</main></body></html>
