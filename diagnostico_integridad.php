<?php
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
require_once 'schema_guard.php';

function diEsc($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function diTabla(mysqli $c, $t){ return esquemaTablaExiste($c, (string)$t); }
function diCol(mysqli $c, $t, $col){ return esquemaColumnaExiste($c, (string)$t, (string)$col); }
function diScalar(mysqli $c, $sql, $campo='c'){
    $r=$c->query($sql);
    if(!$r) return null;
    $x=$r->fetch_assoc();
    return array_key_exists($campo,$x) ? $x[$campo] : null;
}
function diConfig(mysqli $c, $clave, $default=''){
    if(!diTabla($c,'configuracion_sistema')) return $default;
    $st=$c->prepare('SELECT config_valor FROM configuracion_sistema WHERE config_clave=? LIMIT 1');
    if(!$st) return $default;
    $st->bind_param('s',$clave);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();
    return $x ? (string)$x['config_valor'] : $default;
}
function diPhpFiles($base){
    $out=array();
    $it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base,FilesystemIterator::SKIP_DOTS));
    foreach($it as $f){
        if(!$f->isFile()) continue;
        if(strtolower($f->getExtension())!=='php') continue;
        $p=$f->getPathname();
        if(strpos($p,DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR)!==false) continue;
        $out[]=$p;
    }
    sort($out);
    return $out;
}
function diRel($p){ return ltrim(str_replace(array(__DIR__.'/',__DIR__.'\\'),'',$p),'\\/'); }
function diPathStatus($ruta){
    $ruta=trim((string)$ruta);
    if($ruta==='') return array('estado'=>'WARN','detalle'=>'No configurada.');
    if(!file_exists($ruta)) return array('estado'=>'ERROR','detalle'=>'La ruta no existe en el servidor PHP.');
    if(!is_dir($ruta)) return array('estado'=>'ERROR','detalle'=>'La ruta existe pero no es una carpeta.');
    if(!is_readable($ruta)) return array('estado'=>'ERROR','detalle'=>'PHP no puede leer la carpeta.');
    if(!is_writable($ruta)) return array('estado'=>'WARN','detalle'=>'PHP puede leerla pero no escribir en ella.');
    return array('estado'=>'OK','detalle'=>'Existe y PHP puede leer/escribir.');
}

$checks=array();
function diAdd(&$checks,$grupo,$nombre,$estado,$valor='',$detalle='',$accion=''){
    $checks[]=array('grupo'=>$grupo,'nombre'=>$nombre,'estado'=>$estado,'valor'=>$valor,'detalle'=>$detalle,'accion'=>$accion);
}
function diAddCount(&$checks,$grupo,$nombre,$valor,$detalle='',$accion='',$nivel='ERROR'){
    if($valor===null){ diAdd($checks,$grupo,$nombre,'WARN','—','No se pudo ejecutar el control. '.$detalle,$accion); return; }
    $n=(int)$valor;
    diAdd($checks,$grupo,$nombre,$n===0?'OK':$nivel,(string)$n,$n===0?'Sin inconsistencias detectadas.':$detalle,$accion);
}

/* ENTORNO */
$phpOk=version_compare(PHP_VERSION,'7.3.0','>=');
diAdd($checks,'Entorno','Versión PHP compatible',$phpOk?'OK':'ERROR',PHP_VERSION,$phpOk?'PHP 7.3 o superior.':'La aplicación requiere como mínimo PHP 7.3.','Usar PHP 7.3+ y validar antes de producción.');
diAdd($checks,'Entorno','Extensión mysqli',extension_loaded('mysqli')?'OK':'ERROR',extension_loaded('mysqli')?'Activa':'Falta',extension_loaded('mysqli')?'Disponible para la conexión MySQL/MariaDB.':'Sin mysqli el cotizador no puede conectarse a la base.');
diAdd($checks,'Entorno','UTF-8 multibyte (mbstring)',extension_loaded('mbstring')?'OK':'WARN',extension_loaded('mbstring')?'Activa':'No instalada',extension_loaded('mbstring')?'Disponible.':'El sistema tiene fallbacks, pero conviene instalar mbstring en producción.');
diAdd($checks,'Entorno','Zona horaria PHP',date_default_timezone_get()==='America/Argentina/Buenos_Aires'?'OK':'WARN',date_default_timezone_get(),'La aplicación espera America/Argentina/Buenos_Aires.');
$strict=(string)ini_get('session.use_strict_mode');
$httpOnly=(string)ini_get('session.cookie_httponly');
$onlyCookies=(string)ini_get('session.use_only_cookies');
diAdd($checks,'Seguridad','Sesión endurecida',($strict==='1'&&$httpOnly==='1'&&$onlyCookies==='1')?'OK':'WARN','strict='.$strict.' · httponly='.$httpOnly.' · onlycookies='.$onlyCookies,'Configuración efectiva de la sesión actual.','Mantener use_strict_mode, httponly y only_cookies habilitados.');

/* ESQUEMA BASICO */
$tablasCriticas=array('clientes','cotizaciones','cotizaciones_detalle','cotizaciones_revisiones','cotizaciones_revisiones_detalle','pedidos','pedidos_detalle','pedidos_revisiones','pedidos_revisiones_detalle','numeracion_documentos','configuracion_sistema','usuarios','lista_precios','matriz_calculos');
$faltan=array();foreach($tablasCriticas as $t){if(!diTabla($conexion,$t))$faltan[]=$t;}
diAdd($checks,'Esquema','Tablas críticas presentes',empty($faltan)?'OK':'ERROR',empty($faltan)?count($tablasCriticas).' / '.count($tablasCriticas):(count($tablasCriticas)-count($faltan)).' / '.count($tablasCriticas),empty($faltan)?'El núcleo del esquema está presente.':'Faltan: '.implode(', ',$faltan),'No continuar con cambios estructurales hasta completar el esquema.');
$noInno=diScalar($conexion,"SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE' AND engine<>'InnoDB'");
diAddCount($checks,'Esquema','Tablas fuera de InnoDB',$noInno,'Hay tablas sin soporte transaccional InnoDB.','Revisar antes de reforzar integridad/concurrencia.','WARN');
$sinPk=diScalar($conexion,"SELECT COUNT(*) c FROM information_schema.tables t WHERE t.table_schema=DATABASE() AND t.table_type='BASE TABLE' AND NOT EXISTS (SELECT 1 FROM information_schema.table_constraints tc WHERE tc.table_schema=t.table_schema AND tc.table_name=t.table_name AND tc.constraint_type='PRIMARY KEY')");
diAddCount($checks,'Esquema','Tablas sin clave primaria',$sinPk,'Hay tablas base sin PRIMARY KEY.','Definir PK sólo después de revisar datos existentes.','WARN');

/* DOCUMENTOS E HISTORICOS */
if(diTabla($conexion,'cotizaciones')&&diTabla($conexion,'clientes')) diAddCount($checks,'Documentos','Cotizaciones sin cliente válido',diScalar($conexion,"SELECT COUNT(*) c FROM cotizaciones x LEFT JOIN clientes c ON c.clientes_id=x.cliente_id WHERE c.clientes_id IS NULL"),'Hay cotizaciones cuyo cliente no existe.','No borrar: identificar origen y preservar históricos.');
if(diTabla($conexion,'pedidos')&&diTabla($conexion,'clientes')) diAddCount($checks,'Documentos','Pedidos sin cliente válido',diScalar($conexion,"SELECT COUNT(*) c FROM pedidos x LEFT JOIN clientes c ON c.clientes_id=x.cliente_id WHERE c.clientes_id IS NULL"),'Hay pedidos cuyo cliente no existe.','No borrar: identificar origen y preservar históricos.');
if(diTabla($conexion,'cotizaciones_detalle')&&diTabla($conexion,'cotizaciones')) diAddCount($checks,'Documentos','Detalle de cotización huérfano',diScalar($conexion,"SELECT COUNT(*) c FROM cotizaciones_detalle d LEFT JOIN cotizaciones x ON x.cotizacion_id=d.cotizacion_id WHERE x.cotizacion_id IS NULL"),'Hay líneas sin cabecera de cotización.','Investigar antes de agregar FK o depurar.');
if(diTabla($conexion,'pedidos_detalle')&&diTabla($conexion,'pedidos')) diAddCount($checks,'Documentos','Detalle de pedido huérfano',diScalar($conexion,"SELECT COUNT(*) c FROM pedidos_detalle d LEFT JOIN pedidos x ON x.pedido_id=d.pedido_id WHERE x.pedido_id IS NULL"),'Hay líneas sin cabecera de pedido.','Investigar antes de agregar FK o depurar.');
if(diTabla($conexion,'cotizaciones_revisiones')&&diTabla($conexion,'cotizaciones')) diAddCount($checks,'Históricos','Revisiones de cotización huérfanas',diScalar($conexion,"SELECT COUNT(*) c FROM cotizaciones_revisiones r LEFT JOIN cotizaciones x ON x.cotizacion_id=r.cotizacion_id WHERE x.cotizacion_id IS NULL"),'Hay snapshots de revisión sin cotización vigente.','Revisar antes de crear FKs.');
if(diTabla($conexion,'cotizaciones_revisiones_detalle')&&diTabla($conexion,'cotizaciones_revisiones')) diAddCount($checks,'Históricos','Detalle de revisión de cotización huérfano',diScalar($conexion,"SELECT COUNT(*) c FROM cotizaciones_revisiones_detalle d LEFT JOIN cotizaciones_revisiones r ON r.revision_id=d.revision_id WHERE r.revision_id IS NULL"),'Hay líneas históricas sin cabecera de revisión.','Revisar antes de crear FKs.');
if(diTabla($conexion,'pedidos_revisiones')&&diTabla($conexion,'pedidos')) diAddCount($checks,'Históricos','Revisiones de pedido huérfanas',diScalar($conexion,"SELECT COUNT(*) c FROM pedidos_revisiones r LEFT JOIN pedidos p ON p.pedido_id=r.pedido_id WHERE p.pedido_id IS NULL"),'Hay snapshots de pedido sin pedido vigente.','Revisar integridad histórica.');
if(diTabla($conexion,'pedidos_revisiones_detalle')&&diTabla($conexion,'pedidos_revisiones')) diAddCount($checks,'Históricos','Detalle de revisión de pedido huérfano',diScalar($conexion,"SELECT COUNT(*) c FROM pedidos_revisiones_detalle d LEFT JOIN pedidos_revisiones r ON r.revision_id=d.revision_id WHERE r.revision_id IS NULL"),'Hay líneas históricas sin cabecera de revisión.','Revisar integridad histórica.');
if(diTabla($conexion,'cotizaciones_revisiones')) diAddCount($checks,'Históricos','Revisiones de cotización duplicadas',diScalar($conexion,"SELECT COUNT(*) c FROM (SELECT cotizacion_id,revision FROM cotizaciones_revisiones GROUP BY cotizacion_id,revision HAVING COUNT(*)>1) z"),'Existen dos snapshots con el mismo número de revisión.','No agregar restricciones hasta resolver duplicados.');
if(diTabla($conexion,'pedidos_revisiones')) diAddCount($checks,'Históricos','Revisiones de pedido duplicadas',diScalar($conexion,"SELECT COUNT(*) c FROM (SELECT pedido_id,revision FROM pedidos_revisiones GROUP BY pedido_id,revision HAVING COUNT(*)>1) z"),'Existen dos snapshots con el mismo número de revisión.','Evaluar UNIQUE(pedido_id, revision) sólo si este control da cero.','WARN');
if(diTabla($conexion,'pedidos')) diAddCount($checks,'Documentos','Pedidos activos duplicados por cotización',diScalar($conexion,"SELECT COUNT(*) c FROM (SELECT cotizacion_id FROM pedidos WHERE cotizacion_id IS NOT NULL AND estado<>'ANULADO' GROUP BY cotizacion_id HAVING COUNT(*)>1) z"),'Hay más de un pedido activo para una misma cotización.','Debe quedar en cero antes de producción.');

/* CONTROL - relaciones que hoy son parcialmente lógicas */
$relChecks=array(
 array('control_compatibilidades','cpu_id','cpus','cpu_id','Compatibilidades con CPU inexistente'),
 array('control_compatibilidades','ctrltipo_id','tipos_control','ctrltipo_id','Compatibilidades con tipo inexistente'),
 array('control_compatibilidades','ctrlsubtipo_id','subtipos_control','ctrlsubtipo_id','Compatibilidades con subtipo inexistente'),
 array('control_cpu_capacidades','cpu_id','cpus','cpu_id','Capacidades de CPU huérfanas'),
 array('control_tipo_capacidades','ctrltipo_id','tipos_control','ctrltipo_id','Capacidades de tipo huérfanas')
);
foreach($relChecks as $rc){
    list($ta,$ca,$tb,$cb,$nom)=$rc;
    if(diTabla($conexion,$ta)&&diTabla($conexion,$tb)&&diCol($conexion,$ta,$ca)&&diCol($conexion,$tb,$cb)){
        $sql="SELECT COUNT(*) c FROM `{$ta}` a LEFT JOIN `{$tb}` b ON b.`{$cb}`=a.`{$ca}` WHERE a.`{$ca}` IS NOT NULL AND b.`{$cb}` IS NULL";
        diAddCount($checks,'Control / matrices',$nom,diScalar($conexion,$sql),'Hay referencias lógicas a registros que ya no existen.','Corregir datos antes de evaluar nuevas FKs.','WARN');
    }
}

/* CONFIGURACION DE RUTAS */
$docRuta=diConfig($conexion,'documentos_raiz','');
$docEstado=diPathStatus($docRuta);
diAdd($checks,'Configuración','Archivo general de documentos',$docEstado['estado'],$docRuta!==''?$docRuta:'Sin configurar',$docEstado['detalle'],'Configurar desde Administración > Archivo de documentos.');
$intActiva=strtoupper(trim(diConfig($conexion,'integracion_externa_activa','NO')))==='SI';
$intRuta=diConfig($conexion,'integracion_externa_ruta','');
if($intActiva){$s=diPathStatus($intRuta);diAdd($checks,'Configuración','Ruta de integración externa',$s['estado'],$intRuta!==''?$intRuta:'Sin configurar',$s['detalle'],'Configurar una ruta válida para la PC/servidor actual.');}
else{diAdd($checks,'Configuración','Integración externa','OK','Desactivada','No requiere una carpeta accesible mientras permanezca desactivada.');}

/* SEGURIDAD Y MANTENIBILIDAD ESTATICA - SOLO LECTURA */
$authTxt=@file_get_contents(__DIR__.DIRECTORY_SEPARATOR.'auth.php');
$seedHard=($authTxt!==false && strpos($authTxt,'Seed funcional')!==false && strpos($authTxt,'INSERT INTO usuarios')!==false);
diAdd($checks,'Seguridad','Administrador bootstrap embebido',$seedHard?'WARN':'OK',$seedHard?'Detectado':'No detectado',$seedHard?'auth.php contiene lógica de alta automática de un administrador predefinido.':'No se detectó seed administrativo en auth.php.','La autenticación normal no debe crear usuarios automáticamente.');

$phpFiles=diPhpFiles(__DIR__);
$postSinCsrf=array();$migraciones=array();$rutasWindows=array();
foreach($phpFiles as $pf){
    $rel=diRel($pf);
    $txt=@file_get_contents($pf);if($txt===false)continue;
    if(strpos($txt,'$_POST')!==false && strpos($txt,'automacExigirPostConCsrf')===false && strpos($txt,'automacValidarCsrf')===false){
        if(!in_array($rel,array('login.php'),true)) $postSinCsrf[]=$rel;
    }
    if($rel!=='diagnostico_integridad.php'){
        if(preg_match_all('/\b(?:migracion_[A-Za-z0-9_.-]+|COTIZADOR_AUTOMAC_[A-Za-z0-9_.-]+\.sql)/i',$txt,$mm)){
            foreach($mm[0] as $m){ if(substr(strtolower($m),-4)!=='.sql') $m.='.sql'; $migraciones[$m]=true; }
        }
        if(preg_match_all('/[A-Z]:\\\\[^\r\n\'\"]+/',$txt,$rp)) foreach($rp[0] as $r) $rutasWindows[$rel.'|'.$r]=true;
    }
}
sort($postSinCsrf);$migNombres=array_keys($migraciones);sort($migNombres);$migFaltan=array();foreach($migNombres as $m){if(!file_exists(__DIR__.DIRECTORY_SEPARATOR.$m))$migFaltan[]=$m;}
diAdd($checks,'Seguridad','POST sin CSRF explícito',count($postSinCsrf)===0?'OK':'WARN',(string)count($postSinCsrf),count($postSinCsrf)?'Revisar: '.implode(', ',array_slice($postSinCsrf,0,14)).(count($postSinCsrf)>14?' …':''):'Todos los PHP con $_POST detectados usan protección CSRF explícita.','No asumir vulnerabilidad: revisar endpoint por endpoint y agregar token donde modifique datos.');
diAdd($checks,'Instalación','Migraciones referenciadas pero ausentes',count($migFaltan)===0?'OK':'WARN',(string)count($migFaltan),count($migFaltan)?'Faltan en el paquete: '.implode(', ',array_slice($migFaltan,0,12)).(count($migFaltan)>12?' …':''):'No se detectaron referencias a migraciones ausentes.','Consolidar una instalación base actual; no reconstruir producción desde migraciones históricas dispersas.');
diAdd($checks,'Mantenibilidad','Referencias a rutas Windows en PHP',count($rutasWindows)===0?'OK':'WARN',(string)count($rutasWindows),count($rutasWindows)?'Hay rutas absolutas o ejemplos Windows en el código. Deben clasificarse entre autodetección, ejemplo y dependencia real.':'No se detectaron rutas Windows literales.','Parametrizar sólo las dependencias reales; no cambiar ejemplos/autodetección sin necesidad.');

/* SALUD */
$salud=array('tablas'=>0,'db_mb'=>0.0,'version_db'=>'—','php'=>PHP_VERSION,'php_files'=>count($phpFiles),'warnings'=>0,'errors'=>0);
$r=$conexion->query("SELECT COUNT(*) tablas, COALESCE(SUM(data_length+index_length),0)/1024/1024 db_mb FROM information_schema.tables WHERE table_schema=DATABASE()");
if($r && ($x=$r->fetch_assoc())){$salud['tablas']=(int)$x['tablas'];$salud['db_mb']=(float)$x['db_mb'];}
$r=$conexion->query('SELECT VERSION() v');if($r&&($x=$r->fetch_assoc()))$salud['version_db']=(string)$x['v'];
foreach($checks as $c){if($c['estado']==='ERROR')$salud['errors']++;elseif($c['estado']==='WARN')$salud['warnings']++;}
$estadoGlobal=$salud['errors']>0?'ERROR':($salud['warnings']>0?'WARN':'OK');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Diagnóstico técnico V1</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1240px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:14px;padding:18px;margin-bottom:14px}.hero h1{margin:0 0 5px}.hero p{margin:4px 0;color:#64748b}.summary{font-size:18px;font-weight:800;margin-top:10px}.summary.OK{color:#17603a}.summary.WARN{color:#8a6400}.summary.ERROR{color:#9b2c2c}.health{display:grid;grid-template-columns:repeat(auto-fit,minmax(145px,1fr));gap:10px;margin-top:14px}.health>div{background:#f7f9fb;border:1px solid #e5eaee;border-radius:10px;padding:11px}.health b{display:block;font-size:18px;margin-top:3px}.health span{font-size:11px;text-transform:uppercase;letter-spacing:.06em;color:#64748b}.group{margin:22px 0 8px;color:#526474;text-transform:uppercase;font-size:12px;letter-spacing:.08em}.row{display:grid;grid-template-columns:minmax(220px,1.1fr) 95px minmax(170px,.8fr) minmax(280px,1.6fr);gap:12px;padding:12px;border-bottom:1px solid #e5eaee;align-items:start}.badge{display:inline-block;border-radius:999px;padding:4px 9px;font-weight:800;font-size:12px}.badge.OK{background:#e8f5ee;color:#17603a}.badge.WARN{background:#fff4d6;color:#7a5600}.badge.ERROR{background:#fde9e9;color:#922}.value{font-family:Consolas,monospace;font-size:12px;word-break:break-word}.note{color:#475569;font-size:13px;line-height:1.35}.action{display:block;color:#64748b;margin-top:5px;font-size:12px}.legend{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.foot{color:#64748b;font-size:13px}@media(max-width:900px){.row{grid-template-columns:1fr}.row>*{min-width:0}}
</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Diagnóstico técnico V1</h1><p>Auditoría de solo lectura. No crea, borra ni modifica tablas, documentos, precios ni configuraciones.</p><div class="summary <?=diEsc($estadoGlobal)?>"><?php if($estadoGlobal==='OK'):?>Sistema sin alertas en los controles ejecutados<?php elseif($estadoGlobal==='WARN'):?>Sin errores críticos · <?=diEsc($salud['warnings'])?> puntos requieren revisión<?php else:?><?=diEsc($salud['errors'])?> errores críticos · <?=diEsc($salud['warnings'])?> advertencias<?php endif;?></div><div class="health"><div><span>PHP</span><b><?=diEsc($salud['php'])?></b></div><div><span>MariaDB/MySQL</span><b><?=diEsc($salud['version_db'])?></b></div><div><span>Tablas</span><b><?=diEsc($salud['tablas'])?></b></div><div><span>Tamaño DB</span><b><?=diEsc(number_format($salud['db_mb'],1,',','.'))?> MB</b></div><div><span>PHP auditados</span><b><?=diEsc($salud['php_files'])?></b></div><div><span>Advertencias</span><b><?=diEsc($salud['warnings'])?></b></div><div><span>Errores</span><b><?=diEsc($salud['errors'])?></b></div></div><div class="legend"><span class="badge OK">OK</span><span class="badge WARN">REVISAR</span><span class="badge ERROR">ERROR</span></div></section><section class="card">
<?php $g='';foreach($checks as $c):if($g!==$c['grupo']):$g=$c['grupo'];?><h2 class="group"><?=diEsc($g)?></h2><?php endif;?><div class="row"><strong><?=diEsc($c['nombre'])?></strong><div><span class="badge <?=diEsc($c['estado'])?>"><?=diEsc($c['estado']==='WARN'?'REVISAR':$c['estado'])?></span></div><div class="value"><?=diEsc($c['valor'])?></div><div class="note"><?=diEsc($c['detalle'])?><?php if($c['accion']!==''):?><span class="action"><strong>Acción:</strong> <?=diEsc($c['accion'])?></span><?php endif;?></div></div><?php endforeach;?>
</section><section class="card foot"><strong>Criterio:</strong> un aviso “REVISAR” no significa que V1 esté fallando. Marca un punto de mantenibilidad, seguridad o portabilidad que conviene estudiar antes de cambiar estructura. Los históricos nunca se corrigen automáticamente desde este diagnóstico.</section></main></body></html>
