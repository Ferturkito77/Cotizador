<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
$id=filter_input(INPUT_GET,'cotizacion_id',FILTER_VALIDATE_INT);
if(!$id)$id=filter_input(INPUT_POST,'cotizacion_id',FILTER_VALIDATE_INT);
if(!$id)die('Cotización inválida.');
$st=$conexion->prepare("SELECT estado,(SELECT COUNT(*) FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id AND p.estado<>'ANULADO') AS pedidos_activos FROM cotizaciones c WHERE cotizacion_id=? LIMIT 1");
$st->bind_param('i',$id); $st->execute(); $cot=$st->get_result()->fetch_assoc(); $st->close();
if(!$cot) die('Cotización inexistente.');
if(($cot['estado']??'')==='ANULADA') die('La cotización está anulada y no puede generar un pedido.');
if((int)($cot['pedidos_activos']??0)>0) die('La cotización ya posee un pedido/obra vigente.');
/* v410: toda conversión de Cotización a Pedido pasa primero por Integración externa.
 * Allí se fijan Fecha de entrega y Forma de pago; recién después se crea el Pedido. */
header('Location: pedido_preintegracion.php?cotizacion_id='.(int)$id);
exit;
