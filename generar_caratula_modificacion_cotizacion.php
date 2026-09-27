<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'sistema_comercial.php';
require_once 'documentos_pdf.php';
asegurarSistemaUsuarios($conexion);
asegurarSistemaComercial($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL','TECNICO'));
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
$destino=strtolower(trim((string)($_GET['destino']??'')));
if(!$id || !in_array($destino,array('produccion','administracion'),true)){http_response_code(400);die('Carátula inválida.');}
try{
    $salidas=generarHojasModificacionCotizacion($conexion,(int)$id);
    $clave=$destino==='produccion'?'PRODUCCION':'ADMINISTRACION';
    if(empty($salidas[$clave])) throw new RuntimeException('No se pudo generar la carátula.');
    header('Location: '.$salidas[$clave]); exit;
}catch(Throwable $e){http_response_code(500);die('No se pudo generar la carátula: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
