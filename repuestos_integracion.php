<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once __DIR__ . '/schema_guard.php';
require_once 'repuestos_costos.php';
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
header('Content-Type: application/json; charset=utf-8');
$conexion->set_charset('utf8mb4');

function riTablaExiste(mysqli $c,string $t):bool{ return esquemaTablaExiste($c,$t); }
function riNorm($v):string{return strtoupper(trim((string)$v));}
function riCoincide(string $regla,$id,$nombre=''):bool{
    $r=riNorm($regla); if($r===''||$r==='*')return true;
    return $r===riNorm($id)||($nombre!==''&&$r===riNorm($nombre));
}

if(!riTablaExiste($conexion,'repuestos_reglas_integracion')){
    echo json_encode(array('ok'=>true,'reglas'=>array(),'mensaje'=>'Sin reglas de integración instaladas.'),JSON_UNESCAPED_UNICODE);exit;
}

$ctx=array(
 'incluir_control'=>(int)($_GET['incluir_control']??0)===1,
 'cpu_id'=>(int)($_GET['cpu_id']??0),'cpu_nombre'=>'',
 'tipo_id'=>(int)($_GET['tipo_id']??0),'tipo_nombre'=>'',
 'subtipo_id'=>(int)($_GET['subtipo_id']??0),'subtipo_nombre'=>'',
 'incluir_senalizacion'=>(int)($_GET['incluir_senalizacion']??0)===1,
 'senal_modelo_id'=>(int)($_GET['senal_modelo_id']??0),'senal_modelo_nombre'=>'',
 'incluir_accesorios'=>(int)($_GET['incluir_accesorios']??0)===1,
 'accesorios'=>array_filter(array_map('riNorm',explode(',',(string)($_GET['accesorios']??'')))),
 'incluir_iep'=>(int)($_GET['incluir_iep']??0)===1,
 'equipos'=>max(1,(int)($_GET['equipos']??1)),
 'lista_id'=>(int)($_GET['lista_id']??0),
);
if($ctx['cpu_id']>0){$st=$conexion->prepare("SELECT cpu_name FROM cpus WHERE cpu_id=?");$st->bind_param('i',$ctx['cpu_id']);$st->execute();$ctx['cpu_nombre']=(string)(($st->get_result()->fetch_row()[0]??null)?:'');$st->close();}
if($ctx['tipo_id']>0){$st=$conexion->prepare("SELECT ctrltipo_name FROM tipos_control WHERE ctrltipo_id=?");$st->bind_param('i',$ctx['tipo_id']);$st->execute();$ctx['tipo_nombre']=(string)(($st->get_result()->fetch_row()[0]??null)?:'');$st->close();}
if($ctx['subtipo_id']>0){$st=$conexion->prepare("SELECT ctrlsubtipo_name FROM subtipos_control WHERE ctrlsubtipo_id=?");$st->bind_param('i',$ctx['subtipo_id']);$st->execute();$ctx['subtipo_nombre']=(string)(($st->get_result()->fetch_row()[0]??null)?:'');$st->close();}
if($ctx['senal_modelo_id']>0){$st=$conexion->prepare("SELECT modelo_pulsador_nombre FROM senal_modelos_pulsador WHERE modelo_pulsador_id=?");$st->bind_param('i',$ctx['senal_modelo_id']);$st->execute();$ctx['senal_modelo_nombre']=(string)(($st->get_result()->fetch_row()[0]??null)?:'');$st->close();}

$sql="SELECT r.regla_id,r.origen_modulo,r.origen_tipo,r.origen_valor,r.comportamiento,r.cantidad_tipo,r.cantidad_valor,r.prioridad,r.observaciones AS regla_observaciones,
             p.codigo,p.descripcion,p.categoria,p.utilidad,p.factor_descuento_15,p.factor_descuento_30,p.iva_porcentaje,p.observaciones AS producto_observaciones,p.advertencia,p.habilitado
      FROM repuestos_reglas_integracion r
      INNER JOIN productos_repuestos p ON p.producto_repuesto_id=r.producto_repuesto_id
      WHERE r.activo=1 AND p.habilitado=1
      ORDER BY r.prioridad,r.regla_id";
$rs=$conexion->query($sql);$matches=array();
$ctxCost=repCostoCargarContexto($conexion,0);
while($r=$rs->fetch_assoc()){
    $mod=riNorm($r['origen_modulo']??'GENERAL');$tipo=riNorm($r['origen_tipo']??'MODULO');$valor=(string)($r['origen_valor']??'*');$ok=false;$cantidadOrigen=1;
    if($mod==='GENERAL'){$ok=true;}
    elseif($mod==='CONTROL'&&$ctx['incluir_control']){
      if($tipo==='MODULO')$ok=riCoincide($valor,'CONTROL');
      elseif($tipo==='CPU')$ok=riCoincide($valor,$ctx['cpu_id'],$ctx['cpu_nombre']);
      elseif($tipo==='TIPO_CONTROL')$ok=riCoincide($valor,$ctx['tipo_id'],$ctx['tipo_nombre']);
      elseif($tipo==='SUBTIPO_CONTROL')$ok=riCoincide($valor,$ctx['subtipo_id'],$ctx['subtipo_nombre']);
      $cantidadOrigen=$ctx['equipos'];
    }elseif($mod==='SENALIZACION'&&$ctx['incluir_senalizacion']){
      if($tipo==='MODULO')$ok=riCoincide($valor,'SENALIZACION');
      elseif($tipo==='MODELO_SENALIZACION')$ok=riCoincide($valor,$ctx['senal_modelo_id'],$ctx['senal_modelo_nombre']);
    }elseif($mod==='ACCESORIOS'&&$ctx['incluir_accesorios']){
      if($tipo==='MODULO')$ok=riCoincide($valor,'ACCESORIOS');
      elseif($tipo==='ACCESORIO_CLAVE')$ok=riNorm($valor)==='*'||in_array(riNorm($valor),$ctx['accesorios'],true);
    }elseif($mod==='IEP'&&$ctx['incluir_iep']){
      if($tipo==='MODULO')$ok=riCoincide($valor,'IEP');
    }
    if(!$ok)continue;
    $ct=riNorm($r['cantidad_tipo']??'FIJA');$cv=(float)($r['cantidad_valor']??1);$qty=1;
    if($ct==='FIJA')$qty=max(1,(int)round($cv>0?$cv:1));
    elseif($ct==='POR_EQUIPO')$qty=max(1,$ctx['equipos']*(int)round($cv>0?$cv:1));
    elseif($ct==='MISMA_ORIGEN')$qty=max(1,(int)round($cantidadOrigen));
    elseif($ct==='MANUAL')$qty=max(1,(int)round($cv>0?$cv:1));
    $costo=repCostoDeCodigo((string)$r['codigo'],$ctxCost);
    $util=max(.0001,(float)($r['utilidad']??1));$base=$costo*$util;
    $matches[]=array(
      'regla_id'=>(int)$r['regla_id'],'origen_modulo'=>$mod,'origen_tipo'=>$tipo,'origen_valor'=>$valor,
      'comportamiento'=>riNorm($r['comportamiento']??'SUGERIR'),'cantidad_tipo'=>$ct,'cantidad'=>$qty,
      'codigo'=>(string)$r['codigo'],'descripcion'=>(string)$r['descripcion'],'categoria'=>(string)$r['categoria'],
      'precio_base'=>$base,'precio_15'=>$base*(float)$r['factor_descuento_15'],'precio_30'=>$base*(float)$r['factor_descuento_30'],
      'costo_disponible'=>$costo>0,'iva_porcentaje'=>(float)$r['iva_porcentaje'],'observaciones'=>(string)($r['producto_observaciones']??''),'advertencia'=>(string)($r['advertencia']??''),
      'regla_observaciones'=>(string)($r['regla_observaciones']??'')
    );
}
echo json_encode(array('ok'=>true,'reglas'=>$matches),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
