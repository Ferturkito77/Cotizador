<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'repuestos_costos.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
$conexion->set_charset('utf8mb4');
@set_time_limit(300);

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function normalizarTextoPrecio($v){
    $v = trim((string)$v);
    if (substr($v,0,3)==="\xEF\xBB\xBF") $v=substr($v,3);
    if (function_exists('mb_detect_encoding')) {
        $enc=mb_detect_encoding($v,array('UTF-8','Windows-1252','ISO-8859-1'),true);
        if ($enc && $enc!=='UTF-8') $v=mb_convert_encoding($v,'UTF-8',$enc);
    }
    return trim((string)preg_replace('/\s+/u',' ',$v));
}
function normalizarCodigoPrecio($v){ return strtoupper(normalizarTextoPrecio($v)); }
function numeroPrecio($v){
    if (is_numeric($v)) return (float)$v;
    $s=normalizarTextoPrecio($v);
    $s=str_replace(array('$','ARS','USD',' '),'',strtoupper($s));
    $s=preg_replace('/[^0-9,\.\-]/','',$s);
    if ($s==='' || $s==='-') return null;
    $pc=strrpos($s,','); $pp=strrpos($s,'.');
    if ($pc!==false && $pp!==false) {
        if ($pc>$pp) $s=str_replace(',','.',str_replace('.','',$s));
        else $s=str_replace(',','',$s);
    } elseif ($pc!==false) $s=str_replace(',','.',$s);
    return is_numeric($s)?(float)$s:null;
}
function tablaExistePrecio($cn,$tabla){
    $st=$cn->prepare("SELECT COUNT(*) c FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    $st->bind_param('s',$tabla); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    return (int)$r['c']>0;
}
function buscarSoffice(){
    $candidatos=array(
        'soffice', 'libreoffice',
        'C:\\Program Files\\LibreOffice\\program\\soffice.exe',
        'C:\\Program Files (x86)\\LibreOffice\\program\\soffice.exe'
    );
    foreach($candidatos as $c){
        if (strpos($c,':\\')!==false) { if (is_file($c)) return $c; }
        else {
            $cmd=(DIRECTORY_SEPARATOR==='\\'?'where ':'command -v ').escapeshellarg($c).' 2>'.(DIRECTORY_SEPARATOR==='\\'?'NUL':'/dev/null');
            $salida=trim((string)@shell_exec($cmd));
            if ($salida!=='') return strtok($salida,"\r\n");
        }
    }
    return null;
}
function convertirExcelACsv($archivo,$nombreOriginal,&$error){
    $ext=strtolower(pathinfo($nombreOriginal,PATHINFO_EXTENSION));
    if ($ext==='csv') return $archivo;
    if (!in_array($ext,array('xls','xlsx'),true)) { $error='El archivo debe ser XLS, XLSX o CSV.'; return null; }
    $soffice=buscarSoffice();
    if (!$soffice) { $error='No se encontró LibreOffice. Instalalo o agregá soffice.exe al PATH del sistema.'; return null; }
    $dir=sys_get_temp_dir().DIRECTORY_SEPARATOR.'automac_precios_'.bin2hex(random_bytes(6));
    if (!@mkdir($dir,0777,true)) { $error='No se pudo crear la carpeta temporal.'; return null; }
    $copia=$dir.DIRECTORY_SEPARATOR.'Bejerman.'.$ext;
    if (!@copy($archivo,$copia)) { $error='No se pudo preparar el archivo cargado.'; return null; }
    $cmd=escapeshellarg($soffice).' --headless --convert-to csv --outdir '.escapeshellarg($dir).' '.escapeshellarg($copia).' 2>&1';
    $out=array(); $code=0; @exec($cmd,$out,$code);
    $csv=$dir.DIRECTORY_SEPARATOR.'Bejerman.csv';
    if ($code!==0 || !is_file($csv)) { $error='LibreOffice no pudo convertir el archivo. Detalle: '.implode(' ',$out); return null; }
    return $csv;
}
function detectarSeparadorPrecio($ruta){
    $m=(string)file_get_contents($ruta,false,null,0,8192);
    $punt=array(';'=>0,','=>0,"\t"=>0);
    foreach(array_slice(preg_split('/\r\n|\r|\n/',$m),0,12) as $l) foreach($punt as $s=>$x) $punt[$s]+=substr_count($l,$s);
    arsort($punt); return key($punt);
}
function calcularDolarDesdeProductos($productos){
    $ratios=array();
    foreach($productos as $p){
        $costo=(float)($p['costo']??0);
        $usd=(float)($p['usd']??0);
        if($costo>0 && $usd>0){
            $ratio=$costo/$usd;
            if(is_finite($ratio) && $ratio>0) $ratios[]=$ratio;
        }
    }
    if(count($ratios)<3) return 0.0;
    sort($ratios,SORT_NUMERIC);
    $n=count($ratios);
    $mediana=($n%2===1)?$ratios[intdiv($n,2)]:(($ratios[$n/2-1]+$ratios[$n/2])/2);
    $filtrados=array_values(array_filter($ratios,function($r)use($mediana){
        return $r >= $mediana*0.95 && $r <= $mediana*1.05;
    }));
    if(!$filtrados) return round($mediana,2);
    sort($filtrados,SORT_NUMERIC);
    $m=count($filtrados);
    $valor=($m%2===1)?$filtrados[intdiv($m,2)]:(($filtrados[$m/2-1]+$filtrados[$m/2])/2);
    return round($valor,2);
}
function analizarBejerman($ruta,&$errores){
    $sep=detectarSeparadorPrecio($ruta); $fh=fopen($ruta,'rb');
    if(!$fh){$errores[]='No se pudo abrir el CSV convertido.'; return array();}
    $rubro=''; $subrubro=''; $datos=array(); $linea=0; $duplicados=0; $descartados=0;
    while(($row=fgetcsv($fh,0,$sep))!==false){
        $linea++; if (!$row) continue;
        $primero=normalizarTextoPrecio($row[0]??'');
        if ($primero==='') continue;
        if (stripos($primero,'Rubro:')===0){$rubro=normalizarTextoPrecio(substr($primero,6));continue;}
        if (stripos($primero,'Subrubro:')===0){$subrubro=normalizarTextoPrecio(substr($primero,9));continue;}
        $codigo=normalizarCodigoPrecio($primero); $descripcion=normalizarTextoPrecio($row[1]??'');
        $unidad=normalizarTextoPrecio($row[2]??''); $costo=numeroPrecio($row[3]??null); $usd=numeroPrecio($row[8]??null);
        if($codigo==='' || $descripcion==='' || $costo===null || $costo<0){$descartados++;continue;}
        if(isset($datos[$codigo])) $duplicados++;
        $datos[$codigo]=array('codigo'=>$codigo,'descripcion'=>$descripcion,'unidad'=>$unidad,'rubro'=>$rubro,'subrubro'=>$subrubro,'costo'=>$costo,'usd'=>$usd);
    }
    fclose($fh);
    return array('productos'=>array_values($datos),'duplicados'=>$duplicados,'descartados'=>$descartados,'separador'=>$sep);
}
function limpiarArchivoAnalisis(){
    if(!empty($_SESSION['precios_archivo_analisis']) && is_file($_SESSION['precios_archivo_analisis'])) @unlink($_SESSION['precios_archivo_analisis']);
    unset($_SESSION['precios_archivo_analisis'],$_SESSION['precios_resumen_analisis'],$_SESSION['precios_datos_formulario']);
}

$mensaje=''; $tipo=''; $resumen=$_SESSION['precios_resumen_analisis']??null; $resultadoFinal=null;
if(empty($_SESSION['csrf_actualizar_precios'])) $_SESSION['csrf_actualizar_precios']=bin2hex(random_bytes(24));

$faltanTablas=array();
foreach(array('productos_presupuesto','productos_senalizacion','actualizaciones_precios') as $t) if(!tablaExistePrecio($conexion,$t)) $faltanTablas[]=$t;

if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($_SESSION['csrf_actualizar_precios'],$_POST['csrf']??'')){ $mensaje='La sesión del formulario venció.'; $tipo='error'; }
    else {
        $accion=$_POST['accion']??'';
        if($accion==='cancelar'){ limpiarArchivoAnalisis(); $resumen=null; $mensaje='Análisis cancelado.'; $tipo='ok'; }
        elseif($accion==='analizar'){
            limpiarArchivoAnalisis();
            if($faltanTablas){$mensaje='Primero debés importar la migración SQL.';$tipo='error';}
            elseif(empty($_FILES['archivo_bejerman']) || $_FILES['archivo_bejerman']['error']!==UPLOAD_ERR_OK){$mensaje='Seleccioná el archivo exportado desde Bejerman.';$tipo='error';}
            else {
                $fecha=$_POST['fecha_vigencia']??date('Y-m-d'); $mo=numeroPrecio($_POST['valor_mano_obra']??0)??0; $dolar=numeroPrecio($_POST['valor_dolar']??0)??0; $obs=normalizarTextoPrecio($_POST['observaciones']??'');
                $err=''; $csv=convertirExcelACsv($_FILES['archivo_bejerman']['tmp_name'],$_FILES['archivo_bejerman']['name'],$err);
                if(!$csv){$mensaje=$err;$tipo='error';}
                else {
                    $errores=array(); $analisis=analizarBejerman($csv,$errores);
                    if(empty($analisis['productos'])){$mensaje='No se encontraron productos válidos. '.implode(' ',$errores);$tipo='error';}
                    else {
                        $guardar=sys_get_temp_dir().DIRECTORY_SEPARATOR.'automac_analisis_'.bin2hex(random_bytes(12)).'.json';
                        file_put_contents($guardar,json_encode($analisis['productos'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
                        $codigos=array_column($analisis['productos'],'codigo');
                        $tmp='tmp_codigos_'.substr(bin2hex(random_bytes(5)),0,10);
                        if(!$conexion->query("CREATE TEMPORARY TABLE `$tmp` (codigo VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci PRIMARY KEY) ENGINE=MEMORY")){
                            throw new Exception('No se pudo preparar el cruce de códigos: '.$conexion->error);
                        }
                        $st=$conexion->prepare("INSERT IGNORE INTO `$tmp` (codigo) VALUES (?)");
                        if(!$st) throw new Exception('No se pudo preparar la carga temporal: '.$conexion->error);
                        foreach($codigos as $c){$st->bind_param('s',$c);if(!$st->execute())throw new Exception($st->error);} $st->close();
                        $cont=array();
                        foreach(array('productos_repuestos'=>'repuestos','productos_presupuesto'=>'presupuesto','productos_senalizacion'=>'senalizacion') as $tabla=>$clave){
                            $sql="SELECT COUNT(*) total, COALESCE(SUM(CASE WHEN t.codigo IS NULL THEN 1 ELSE 0 END),0) sin_costo FROM `$tabla` p LEFT JOIN `$tmp` t ON BINARY t.codigo=BINARY p.codigo WHERE p.habilitado=1";
                            $q=$conexion->query($sql);
                            if(!$q) throw new Exception('No se pudo cruzar la tabla '.$tabla.': '.$conexion->error);
                            $cont[$clave]=$q->fetch_assoc();
                        }
                        $conexion->query("DROP TEMPORARY TABLE `$tmp`");
                        $dolarDetectado=calcularDolarDesdeProductos($analisis['productos']);
                        $dolarOrigen='manual';
                        if($dolar<=0 && $dolarDetectado>0){$dolar=$dolarDetectado;$dolarOrigen='detectado desde Bejerman';}
                        elseif($dolar<=0){$dolarOrigen='no informado';}
                        $resumen=array('archivo'=>basename($_FILES['archivo_bejerman']['name']),'cantidad'=>count($analisis['productos']),'duplicados'=>$analisis['duplicados'],'descartados'=>$analisis['descartados'],'repuestos'=>$cont['repuestos'],'presupuesto'=>$cont['presupuesto'],'senalizacion'=>$cont['senalizacion'],'dolar_detectado'=>$dolarDetectado,'dolar_origen'=>$dolarOrigen);
                        $_SESSION['precios_archivo_analisis']=$guardar; $_SESSION['precios_resumen_analisis']=$resumen;
                        $_SESSION['precios_datos_formulario']=array('fecha'=>$fecha,'mo'=>$mo,'dolar'=>$dolar,'dolar_origen'=>$dolarOrigen,'observaciones'=>$obs,'archivo'=>basename($_FILES['archivo_bejerman']['name']),'hash'=>hash_file('sha256',$_FILES['archivo_bejerman']['tmp_name']));
                        $mensaje='Archivo analizado. Revisá el resumen antes de confirmar.';$tipo='ok';
                    }
                }
            }
        }
        elseif($accion==='confirmar'){
            $json=$_SESSION['precios_archivo_analisis']??''; $datos=$_SESSION['precios_datos_formulario']??array();
            if(!$json || !is_file($json) || !$datos){$mensaje='El análisis ya no está disponible. Volvé a cargar el archivo.';$tipo='error';}
            elseif((float)($datos['dolar']??0)<=0){$mensaje='No se pudo determinar el valor del dólar. Cancelá el análisis e ingresalo manualmente.';$tipo='error';}
            else {
                $productos=json_decode(file_get_contents($json),true);
                if(!is_array($productos) || !$productos){$mensaje='No se pudo recuperar el análisis.';$tipo='error';}
                else {
                    $fecha=$datos['fecha']; $mo=(float)$datos['mo']; $dolar=(float)$datos['dolar']; $archivo=$datos['archivo']; $hash=$datos['hash']; $obs=$datos['observaciones']; $usuario=$_SESSION['usuario_login']??'';

                    // Evita aplicar dos veces el mismo archivo para la misma fecha.
                    $stDuplicado=$conexion->prepare("SELECT actualizacion_id FROM actualizaciones_precios WHERE hash_archivo=? AND fecha_vigencia=? LIMIT 1");
                    $stDuplicado->bind_param('ss',$hash,$fecha);
                    $stDuplicado->execute();
                    $yaAplicada=$stDuplicado->get_result()->fetch_assoc();
                    $stDuplicado->close();
                    if($yaAplicada){
                        limpiarArchivoAnalisis();
                        $resumen=null;
                        $mensaje='Esta misma base Bejerman ya fue aplicada para la fecha indicada. No se generó otra lista.';
                        $tipo='error';
                    } else {
                    $conexion->begin_transaction();
                    try{
                        $conexion->query("UPDATE bejerman_listas SET estado='HISTORICA' WHERE estado='VIGENTE'");
                        $st=$conexion->prepare("INSERT INTO bejerman_listas (fecha_lista,valor_mano_obra,valor_dolar,usuario_importacion,archivo_origen,estado,observaciones) VALUES (?,?,?,?,?,'VIGENTE',?)");
                        $st->bind_param('sddsss',$fecha,$mo,$dolar,$usuario,$archivo,$obs); if(!$st->execute()) throw new Exception($st->error); $bejId=$st->insert_id; $st->close();
                        $st=$conexion->prepare("INSERT INTO bejerman_productos (bejerman_lista_id,codigo,descripcion,unidad,rubro,subrubro,costo,valor_dolar_articulo,activo) VALUES (?,?,?,?,?,?,?,?,1)");
                        foreach($productos as $p){$usd=$p['usd'];$st->bind_param('isssssdd',$bejId,$p['codigo'],$p['descripcion'],$p['unidad'],$p['rubro'],$p['subrubro'],$p['costo'],$usd);if(!$st->execute())throw new Exception($st->error);} $st->close();

                        $actual=$conexion->query("SELECT lista_id FROM listas_precios_importaciones WHERE lista_estado='VIGENTE' ORDER BY lista_id DESC LIMIT 1");
                        $listaAnterior=$actual&&$actual->num_rows?(int)$actual->fetch_assoc()['lista_id']:0;
                        if($listaAnterior>0){
                            $conexion->query("INSERT INTO listas_precios_historial (lista_id,precios_codigo,precios_descripcion,precios_costo,precios_clasificacion) SELECT $listaAnterior,precios_codigo,precios_descripcion,precios_costo,precios_clasificacion FROM lista_precios ON DUPLICATE KEY UPDATE precios_descripcion=VALUES(precios_descripcion),precios_costo=VALUES(precios_costo),precios_clasificacion=VALUES(precios_clasificacion)");
                        }
                        $conexion->query("UPDATE listas_precios_importaciones SET lista_estado='REEMPLAZADA',lista_fecha_estado=NOW() WHERE lista_estado='VIGENTE'");
                        $nombre='Lista Automac '.date('d/m/Y',strtotime($fecha));
                        $validos=count($productos); $cero=0;
                        $st=$conexion->prepare("INSERT INTO listas_precios_importaciones (lista_nombre,lista_fecha_archivo,lista_vigente_desde,lista_valor_dolar,lista_archivo_origen,lista_hash_archivo,lista_usuario,lista_registros_validos,lista_observaciones,lista_estado,lista_fecha_estado) VALUES (?,?,?,?,?,?,?,?,?,'VIGENTE',NOW())");
                        $st->bind_param('sssdsssis',$nombre,$fecha,$fecha,$dolar,$archivo,$hash,$usuario,$validos,$obs); if(!$st->execute())throw new Exception($st->error); $listaId=$st->insert_id;$st->close();

                        /*
                         * No se vacia lista_precios porque matriz_calculos y termicos
                         * referencian sus codigos mediante claves foraneas. Primero se
                         * anulan todos los precios operativos y luego se recalculan solo
                         * los codigos que tienen costo en la base Bejerman vigente y una
                         * utilidad habilitada. De esta forma nunca sobrevive un precio
                         * heredado de una lista anterior.
                         */
                        if(!$conexion->query("UPDATE lista_precios SET precios_costo=0")) throw new Exception($conexion->error);
                        if(!$conexion->query("UPDATE productos_repuestos SET costo_referencia=0")) throw new Exception($conexion->error);
                        $sqlP="INSERT INTO lista_precios (precios_codigo,precios_descripcion,precios_costo,precios_clasificacion)
                            SELECT p.codigo,COALESCE(NULLIF(b.descripcion,''),p.descripcion),ROUND(b.costo*p.utilidad,4),p.clasificacion
                            FROM productos_presupuesto p INNER JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY p.codigo
                            WHERE p.habilitado=1 AND b.costo>0
                            ON DUPLICATE KEY UPDATE precios_descripcion=VALUES(precios_descripcion),precios_costo=VALUES(precios_costo),precios_clasificacion=VALUES(precios_clasificacion)";
                        if(!$conexion->query($sqlP))throw new Exception($conexion->error); $cantP=$conexion->affected_rows;
                        /*
                         * Señalización conserva la regla comercial histórica.
                         * Si el código también existe en productos_presupuesto, esa utilidad
                         * es la referencia original del cotizador anterior. productos_senalizacion
                         * queda como respaldo para códigos exclusivos del módulo nuevo.
                         * Los precios históricos de Señalización se manejaban a entero superior.
                         */
                        $sqlS="INSERT INTO lista_precios (precios_codigo,precios_descripcion,precios_costo,precios_clasificacion)
                            SELECT s.codigo,COALESCE(NULLIF(b.descripcion,''),s.descripcion),
                                   CEIL(b.costo*COALESCE(NULLIF(p.utilidad,0),s.utilidad)),'NO_EQUIPO'
                            FROM productos_senalizacion s
                            INNER JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY s.codigo
                            LEFT JOIN productos_presupuesto p ON BINARY p.codigo=BINARY s.codigo AND p.habilitado=1
                            WHERE s.habilitado=1 AND b.costo>0
                            ON DUPLICATE KEY UPDATE precios_descripcion=VALUES(precios_descripcion),precios_costo=VALUES(precios_costo)";
                        if(!$conexion->query($sqlS))throw new Exception($conexion->error); $cantS=$conexion->affected_rows;

                        /*
                         * Códigos de Repuestos que también son componentes técnicos del cotizador
                         * (por ejemplo A2142C / H2142SH en rescates MRL) deben tener un precio
                         * operativo en lista_precios. Presupuesto y Señalización tienen prioridad:
                         * Repuestos solo completa códigos que todavía quedaron sin precio.
                         */
                        /*
                         * v162: Repuestos ya no depende de que su codigo exista directamente
                         * en Bejerman. Primero recalculamos costo_referencia segun costo_tipo:
                         * BEJERMAN / FORMULA / FIJO. Las formulas usan la nueva base importada.
                         */
                        $recalculoRepuestos = repCostoRecalcularTodos($conexion, $bejId);
                        $sqlR="INSERT INTO lista_precios (precios_codigo,precios_descripcion,precios_costo,precios_clasificacion)
                            SELECT r.codigo,r.descripcion,CEIL(r.costo_referencia*r.utilidad),'NO_EQUIPO'
                            FROM productos_repuestos r
                            WHERE r.habilitado=1 AND r.costo_referencia>0 AND r.utilidad>0
                            ON DUPLICATE KEY UPDATE
                                precios_descripcion=IF(COALESCE(precios_costo,0)<=0,VALUES(precios_descripcion),precios_descripcion),
                                precios_clasificacion=IF(COALESCE(precios_costo,0)<=0,VALUES(precios_clasificacion),precios_clasificacion),
                                precios_costo=IF(COALESCE(precios_costo,0)<=0,VALUES(precios_costo),precios_costo)";
                        if(!$conexion->query($sqlR))throw new Exception($conexion->error); $cantRLista=$conexion->affected_rows;

                        if(!$conexion->query("INSERT INTO listas_precios_historial (lista_id,precios_codigo,precios_descripcion,precios_costo,precios_clasificacion) SELECT $listaId,precios_codigo,precios_descripcion,precios_costo,precios_clasificacion FROM lista_precios"))throw new Exception($conexion->error);
                        $q=$conexion->query("SELECT
                          (SELECT COUNT(*) FROM productos_presupuesto p LEFT JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY p.codigo WHERE p.habilitado=1 AND b.codigo IS NULL) sp,
                          (SELECT COUNT(*) FROM productos_senalizacion s LEFT JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY s.codigo WHERE s.habilitado=1 AND b.codigo IS NULL) ss,
                          (SELECT COUNT(*) FROM productos_repuestos r LEFT JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY r.codigo WHERE r.habilitado=1 AND b.codigo IS NULL) sr,
                          (SELECT COUNT(*) FROM productos_repuestos r INNER JOIN bejerman_productos b ON b.bejerman_lista_id=$bejId AND BINARY b.codigo=BINARY r.codigo WHERE r.habilitado=1) cr,
                          (SELECT COUNT(*) FROM lista_precios) lp");
                        $c=$q->fetch_assoc();
                        $st=$conexion->prepare("INSERT INTO actualizaciones_precios (bejerman_lista_id,fecha_vigencia,archivo_origen,hash_archivo,usuario,cantidad_bejerman,cantidad_presupuesto,cantidad_senalizacion,cantidad_repuestos,sin_costo_presupuesto,sin_costo_senalizacion,sin_costo_repuestos,observaciones) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
                        $st->bind_param('issssiiiiiiis',$bejId,$fecha,$archivo,$hash,$usuario,$validos,$c['lp'],$cantS,$c['cr'],$c['sp'],$c['ss'],$c['sr'],$obs); if(!$st->execute())throw new Exception($st->error);$st->close();
                        $conexion->commit();
                        $resultadoFinal=array('bejerman'=>$validos,'lista'=>$c['lp'],'repuestos'=>$c['cr'],'sin_p'=>$c['sp'],'sin_s'=>$c['ss'],'sin_r'=>$c['sr'],'lista_id'=>$listaId,'bej_id'=>$bejId);
                        limpiarArchivoAnalisis(); $resumen=null; $mensaje='La base Bejerman vigente fue actualizada correctamente.';$tipo='ok';
                    }catch(Throwable $e){$conexion->rollback();$mensaje='No se aplicaron cambios: '.$e->getMessage();$tipo='error';}
                    }
                }
            }
        }
    }
}

$ultimas=array();
if(tablaExistePrecio($conexion,'actualizaciones_precios')){
    $q=$conexion->query("SELECT a.*,b.estado FROM actualizaciones_precios a LEFT JOIN bejerman_listas b ON b.bejerman_lista_id=a.bejerman_lista_id ORDER BY a.actualizacion_id DESC LIMIT 10");
    if($q) while($r=$q->fetch_assoc())$ultimas[]=$r;
}
$datosForm=$_SESSION['precios_datos_formulario']??array();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizar precios</title>
<style>
body{font-family:Arial,sans-serif;margin:0;background:#eef1f4;color:#1f2937}.wrap{max-width:1180px;margin:24px auto;padding:0 18px}.card{background:#fff;border:1px solid #d8dee6;border-radius:10px;padding:22px;margin-bottom:18px;box-shadow:0 2px 10px rgba(0,0,0,.04)}h1{margin:0 0 8px;font-size:27px}h2{font-size:20px;margin-top:0}.muted{color:#64748b}.grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:15px}.full{grid-column:1/-1}label{display:block;font-weight:700;margin-bottom:6px}input,textarea{box-sizing:border-box;width:100%;padding:10px;border:1px solid #b8c2cf;border-radius:6px;background:#fff}button{padding:11px 17px;border:0;border-radius:6px;font-weight:700;cursor:pointer}.primary{background:#0b63ce;color:white}.success{background:#16803b;color:white}.secondary{background:#64748b;color:white}.alert{padding:12px 15px;border-radius:6px;margin-bottom:16px}.alert.ok{background:#e8f7ed;color:#17652f}.alert.error{background:#fdeaea;color:#9b1c1c}.stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.stat{padding:14px;border-radius:8px;background:#f4f7fa;border:1px solid #dfe5ec}.stat strong{display:block;font-size:23px;margin-top:4px}.warn{background:#fff7df;border-color:#efd58a}.bad{background:#fdecec;border-color:#edb8b8}table{width:100%;border-collapse:collapse;font-size:14px}th,td{text-align:left;padding:9px;border-bottom:1px solid #e5e7eb}th{background:#f6f8fa}.actions{display:flex;gap:10px;margin-top:18px}.flow{background:#f8fafc;padding:13px;border-left:4px solid #0b63ce;line-height:1.65}@media(max-width:800px){.grid,.stats{grid-template-columns:1fr}}
</style></head><body>
<?php include 'menu.php'; ?>
<div class="wrap">
<div class="card"><h1>Base de precios Bejerman</h1><p class="muted">Cargá únicamente el archivo exportado desde Bejerman. Las utilidades permanecen guardadas en el sistema.</p>
<div class="flow"><b>Bejerman.xls</b> → costos vigentes → costo × utilidad de Presupuesto / Señalización / Repuestos → nueva lista vigente.</div></div>
<?php if($mensaje):?><div class="alert <?=h($tipo)?>"><?=h($mensaje)?></div><?php endif;?>
<?php if($faltanTablas):?><div class="card"><div class="alert error">Falta instalar la migración <b>migracion_sistema_precios_bejerman.sql</b>. Tablas faltantes: <?=h(implode(', ',$faltanTablas))?>.</div></div><?php endif;?>
<?php if(!$resumen):?>
<div class="card"><h2>1. Analizar archivo</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_actualizar_precios'])?>"><input type="hidden" name="accion" value="analizar">
<div class="grid"><div class="full"><label>Archivo Bejerman</label><input type="file" name="archivo_bejerman" accept=".xls,.xlsx,.csv" required></div>
<div><label>Fecha de vigencia</label><input type="date" name="fecha_vigencia" value="<?=h(date('Y-m-d'))?>" required></div>
<div><label>Valor del dólar (opcional)</label><input type="text" name="valor_dolar" placeholder="Se detecta automáticamente desde Bejerman"></div>
<div><label>Valor de mano de obra</label><input type="text" name="valor_mano_obra" placeholder="Ej.: 46577"></div>
<div class="full"><label>Observaciones</label><textarea name="observaciones" rows="3"></textarea></div></div>
<div class="actions"><button class="primary" type="submit" <?=$faltanTablas?'disabled':''?>>Analizar archivo</button></div></form></div>
<?php else:?>
<div class="card"><h2>2. Resultado del análisis</h2><p><b>Archivo:</b> <?=h($resumen['archivo'])?> · <b>Fecha:</b> <?=h($datosForm['fecha']??'')?></p>
<div class="stats"><div class="stat"><span>Productos Bejerman</span><strong><?=number_format($resumen['cantidad'],0,',','.')?></strong></div><div class="stat"><span>Códigos duplicados</span><strong><?=number_format($resumen['duplicados'],0,',','.')?></strong></div><div class="stat"><span>Filas descartadas</span><strong><?=number_format($resumen['descartados'],0,',','.')?></strong></div><div class="stat"><span>Valor dólar</span><strong><?=number_format((float)($datosForm['dolar']??0),2,',','.')?></strong><small><?=h($datosForm['dolar_origen']??'')?></small></div></div>
<h3>Cruce con las tablas de utilidades</h3><div class="stats">
<?php foreach(array('repuestos'=>'Repuestos','presupuesto'=>'Presupuesto','senalizacion'=>'Señalización') as $k=>$titulo): $x=$resumen[$k]; $sin=(int)$x['sin_costo'];?>
<div class="stat <?=$sin?'warn':''?>"><span><?=h($titulo)?> configurados</span><strong><?=number_format((int)$x['total'],0,',','.')?></strong><small>Sin costo en Bejerman: <?=number_format($sin,0,',','.')?></small></div><?php endforeach;?></div>
<div class="alert <?=((int)$resumen['presupuesto']['sin_costo']+(int)$resumen['senalizacion']['sin_costo']+(int)$resumen['repuestos']['sin_costo'])?'error':'ok'?>" style="margin-top:15px">Los códigos sin costo no se cargarán con precio cero. Quedarán informados para revisión.</div>
<div class="actions"><form method="post" id="form-confirmar-actualizacion"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_actualizar_precios'])?>"><input type="hidden" name="accion" value="confirmar"><button class="success" id="btn-confirmar-actualizacion" type="submit">Confirmar actualización</button></form><form method="post"><input type="hidden" name="csrf" value="<?=h($_SESSION['csrf_actualizar_precios'])?>"><input type="hidden" name="accion" value="cancelar"><button class="secondary" type="submit">Cancelar</button></form></div></div>
<?php endif;?>
<?php if($resultadoFinal):?><div class="card"><h2>Actualización aplicada</h2><div class="stats"><div class="stat"><span>Costos Bejerman</span><strong><?=number_format($resultadoFinal['bejerman'],0,',','.')?></strong></div><div class="stat"><span>Precios operativos</span><strong><?=number_format($resultadoFinal['lista'],0,',','.')?></strong></div><div class="stat"><span>Repuestos vinculados</span><strong><?=number_format($resultadoFinal['repuestos'],0,',','.')?></strong></div><div class="stat"><span>Actualización vigente</span><strong>#<?=h($resultadoFinal['lista_id'])?></strong></div></div><p>Sin costo: Presupuesto <?=h($resultadoFinal['sin_p'])?>, Señalización <?=h($resultadoFinal['sin_s'])?>, Repuestos <?=h($resultadoFinal['sin_r'])?>.</p></div><?php endif;?>
<div class="card"><h2>Últimas actualizaciones</h2><table><thead><tr><th>Fecha</th><th>Archivo</th><th>Bejerman</th><th>Lista</th><th>Repuestos</th><th>Sin costo</th><th>Usuario</th></tr></thead><tbody><?php if(!$ultimas):?><tr><td colspan="7">Todavía no hay actualizaciones con el nuevo sistema.</td></tr><?php else:foreach($ultimas as $u):?><tr><td><?=h($u['fecha_vigencia'])?></td><td><?=h($u['archivo_origen'])?></td><td><?=number_format($u['cantidad_bejerman'],0,',','.')?></td><td><?=number_format($u['cantidad_presupuesto'],0,',','.')?></td><td><?=number_format($u['cantidad_repuestos'],0,',','.')?></td><td><?=number_format($u['sin_costo_presupuesto']+$u['sin_costo_senalizacion']+$u['sin_costo_repuestos'],0,',','.')?></td><td><?=h($u['usuario'])?></td></tr><?php endforeach;endif;?></tbody></table></div>
</div>
<script>
(function(){
  var form=document.getElementById('form-confirmar-actualizacion');
  var btn=document.getElementById('btn-confirmar-actualizacion');
  if(form && btn){
    form.addEventListener('submit',function(){
      btn.disabled=true;
      btn.textContent='Aplicando actualización...';
    });
  }
})();
</script>
</body></html>
