<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
require_once 'senalizacion_cabina.php';
header('Content-Type: application/json; charset=utf-8');
try {
    $codigo=trim((string)($_POST['codigo']??''));
    $listaId=(int)($_POST['lista_id']??0);
    if($codigo===''||$listaId<=0) throw new Exception('Código o base inválida.');
    $p=senalProductoValorizado($conexion,$listaId,$codigo);
    if(!$p) throw new Exception('Sin precio Bejerman válido.');
    echo json_encode(array('ok'=>true,'codigo'=>$codigo,'codigo_cotizacion'=>$p['codigo_cotizacion']??$codigo,'unitario'=>(float)$p['unitario'],'costo'=>(float)$p['costo'],'origen'=>$p['origen_precio']??''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
} catch(Throwable $e) {
    http_response_code(200);
    echo json_encode(array('ok'=>false,'error'=>$e->getMessage()),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
