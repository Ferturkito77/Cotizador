<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'cliente_especificaciones.php';
header('Content-Type: application/json; charset=utf-8');
$clienteId=(int)($_GET['cliente_id']??0);
if($clienteId<=0){echo json_encode(array('ok'=>true,'cantidad'=>0,'items'=>array()),JSON_UNESCAPED_UNICODE);exit;}
$items=especificacionesTecnicasCliente($conexion,$clienteId,true);
$salida=array();
foreach($items as $x){$salida[]=array('id'=>(int)$x['especificacion_id'],'tipo'=>$x['tipo'],'categoria'=>$x['categoria'],'titulo'=>$x['titulo'],'detalle'=>$x['detalle'],'fecha'=>$x['fecha_creacion'],'usuario'=>$x['usuario_nombre']);}
echo json_encode(array('ok'=>true,'cantidad'=>count($salida),'items'=>$salida),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
?>
