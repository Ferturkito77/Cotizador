<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
require_once 'documentos_pdf.php';

$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);
if(!$id){http_response_code(400);die('Cotizacion invalida.');}
$st=$conexion->prepare('SELECT pdf_archivo FROM cotizaciones WHERE cotizacion_id=? LIMIT 1');
$st->bind_param('i',$id);$st->execute();$fila=$st->get_result()->fetch_assoc();$st->close();
if(!$fila){http_response_code(404);die('Cotizacion inexistente.');}
$directorioPermitido=realpath(__DIR__.'/pdf/cotizaciones');
if($directorioPermitido===false || !is_dir($directorioPermitido)){
    http_response_code(500);die('El directorio de cotizaciones no esta disponible.');
}
$resolverPdf=function($rutaRelativa) use ($directorioPermitido){
    $rutaRelativa=str_replace('\\','/',trim((string)$rutaRelativa));
    if(!preg_match('~^pdf/cotizaciones/[^/]+\.pdf$~iD',$rutaRelativa)) return null;
    $rutaReal=realpath(__DIR__.'/'.$rutaRelativa);
    if($rutaReal===false) return false;
    $prefijo=rtrim($directorioPermitido,DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
    if(!is_file($rutaReal) || strncasecmp($rutaReal,$prefijo,strlen($prefijo))!==0) return null;
    return $rutaReal;
};
$rel=trim((string)($fila['pdf_archivo']??''));
$abs=$rel!==''?$resolverPdf($rel):false;
if($abs===null){http_response_code(404);die('Ruta de PDF no permitida.');}
if($abs===false){
    try{$rel=generarPdfCotizacion($conexion,(int)$id);$abs=$resolverPdf($rel);}
    catch(Throwable $e){http_response_code(500);die('No se pudo generar el PDF: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
}
if(!is_string($abs)){http_response_code(404);die('El archivo PDF no esta disponible o su ruta no esta permitida.');}
header('Content-Type: application/pdf');
header('Content-Length: '.filesize($abs));
header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($abs)).'"');
header('X-Content-Type-Options: nosniff');
readfile($abs);exit;
