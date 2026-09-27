<?php
session_start(); include 'conexion.php'; require_once 'auth.php'; asegurarSistemaUsuarios($conexion); exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php'; asegurarSistemaComercial($conexion); require_once 'documentos_pdf.php';
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT); if(!$id) die('Pedido inválido.');
try{$rel=generarPdfPedido($conexion,(int)$id);header('Location: '.$rel);exit;}catch(Throwable $e){http_response_code(500);die('No se pudo generar el PDF: '.htmlspecialchars($e->getMessage()));}
