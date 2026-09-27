<?php
session_start(); include 'conexion.php'; require_once 'auth.php'; asegurarSistemaUsuarios($conexion); exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php'; require_once 'cotizador_bootstrap.php'; asegurarSistemaComercial($conexion); require_once 'modulos_libres.php'; require_once 'documentos_modulares.php'; require_once 'senalizacion_cabina.php'; require_once 'documentos_eventos.php';
automacExigirPostConCsrf();
function volver($m){$_SESSION['form_error']=$m;$_SESSION['form_data']=$_POST;header('Location:index.php');exit;}
if($_SERVER['REQUEST_METHOD']!=='POST') volver('Solicitud inválida.');
$accion=(string)($_POST['accion_comercial']??''); try{cotizadorValidarAccion($accion,array('guardar_cotizacion','generar_pedido_directo','guardar_revision_pedido'));}catch(InvalidArgumentException $e){volver($e->getMessage());}
$pedidoEdicionId=(int)($_POST['pedido_id']??0);
$cotizacionEdicionId=(int)($_POST['cotizacion_id']??0);
$saveToken=trim((string)($_POST['document_save_token']??''));
try{ documentoConsumirTokenGuardado($saveToken,$accion,$pedidoEdicionId,$cotizacionEdicionId); }catch(RuntimeException $e){ volver($e->getMessage()); }
$cliente=(int)($_POST['id_cliente']??0); $referenciaDocumento=trim((string)($_POST['referencia_cotizacion']??'')); try{cotizadorValidarCliente($cliente);cotizadorValidarReferenciaPedidoDirecto($accion,$referenciaDocumento);}catch(InvalidArgumentException $e){volver($e->getMessage());}
$solicitanteCliente=trim((string)($_POST['solicitante_cliente']??'')); if(function_exists('mb_substr'))$solicitanteCliente=mb_substr($solicitanteCliente,0,100,'UTF-8');else$solicitanteCliente=substr($solicitanteCliente,0,100); $_POST['solicitante_cliente']=$solicitanteCliente;
$lista=obtenerListaSeleccionada($conexion,(int)($_POST['lista_id']??0)); if(!$lista)volver('No hay una base Bejerman vigente disponible.'); $listaId=(int)$lista['lista_id'];
$d1=0;$d2=0;$d3=0;$lineas=array();
// v91: misma normalizacion defensiva. En documentos sin Control no modifica nada.
$_POST = senalAplicarDependenciasDesdeControl($conexion, $_POST);
try{
 if(!empty($_POST['senal_incluir'])){
  $sc=calcularLineasSenalizacionCabina($conexion,$listaId,$_POST);
  $sd=aplicarDescuentosSenalizacion($sc['total_bruto'],$_POST);
  $factor=(float)$sd['factor'];
  $lineas=array_merge($lineas,cotizadorAplicarFactorLineas($sc['lineas'],$factor,(float)$sd['d1'],(float)$sd['d2'],(float)$sd['d3']));
 }
 $lineas=array_merge($lineas,obtenerLineasModulosLibres($_POST)); if(!$lineas)throw new Exception('Incluya al menos un módulo con valor.');
 $subtotal=cotizadorSubtotalLineas($lineas);$t=$subtotal;
 $cab=array('cliente_id'=>$cliente,'lista_id'=>$listaId,'lista_nombre'=>$lista['lista_nombre'],'lista_fecha'=>$lista['lista_fecha_archivo'],'lista_valor_dolar'=>$lista['lista_valor_dolar'],'referencia'=>trim((string)($_POST['referencia_cotizacion']??'')),'subtotal'=>$subtotal,'descuento_1'=>$d1,'descuento_2'=>$d2,'descuento_3'=>$d3,'total'=>$t,'datos_formulario'=>$_POST);
 $conexion->begin_transaction();
 if($accion==='guardar_revision_pedido'){
  if($pedidoEdicionId<=0) throw new Exception('Pedido inválido para modificar.');
  $motivoModificacion=trim((string)($_POST['motivo_modificacion_pedido']??''));
  if($motivoModificacion==='') throw new Exception('Debe indicar qué se modifica en el pedido.');
  $usuario=$_SESSION['usuario_nombre']??null;$usuarioId=(int)($_SESSION['usuario_id']??0);$datosJson=json_encode($_POST,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  $pedidoAnterior=repoPedidoPorIdForUpdate($conexion,$pedidoEdicionId);
  if(!$pedidoAnterior) throw new Exception('El pedido no existe.');
  $revisionBase=(int)($_POST['pedido_revision_base']??-1);
  $revisionAnterior=(int)($pedidoAnterior['revision']??0);
  if($revisionBase<0||$revisionBase!==$revisionAnterior) throw new Exception('Este pedido fue modificado por otro usuario mientras usted lo estaba editando. Vuelva a abrir el pedido para trabajar sobre la revisión vigente.');
  $revisionNueva=$revisionAnterior+1;
  $fechaRevision=date('Y-m-d H:i:s');$refAnterior=(string)($pedidoAnterior['referencia']??'');$clienteAnterior=(int)($pedidoAnterior['cliente_id']??0);$listaAnterior=(int)($pedidoAnterior['lista_id']??0);
  $st=$conexion->prepare('INSERT INTO pedidos_revisiones(pedido_id,revision,cliente_id,lista_id,referencia,estado,total,observaciones,datos_formulario,motivo_modificacion,fecha_revision,usuario,usuario_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $st->bind_param('iiiissdsssssi',$pedidoEdicionId,$revisionAnterior,$clienteAnterior,$listaAnterior,$refAnterior,$pedidoAnterior['estado'],$pedidoAnterior['total'],$pedidoAnterior['observaciones'],$pedidoAnterior['datos_formulario'],$motivoModificacion,$fechaRevision,$usuario,$usuarioId);if(!$st->execute())throw new Exception($st->error);$revisionId=$st->insert_id;$st->close();
  $st=$conexion->prepare('INSERT INTO pedidos_revisiones_detalle(revision_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) SELECT ?,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM pedidos_detalle WHERE pedido_id=?');$st->bind_param('ii',$revisionId,$pedidoEdicionId);if(!$st->execute())throw new Exception($st->error);$st->close();
  $st=$conexion->prepare('UPDATE pedidos SET cliente_id=?,lista_id=?,total=?,datos_formulario=?,revision=?,fecha_ultima_modificacion=NOW(),usuario_modificacion=?,usuario_modificacion_id=?,pdf_archivo=NULL,pdf_fecha=NULL WHERE pedido_id=?');$st->bind_param('iidsisii',$cliente,$listaId,$t,$datosJson,$revisionNueva,$usuario,$usuarioId,$pedidoEdicionId);if(!$st->execute())throw new Exception($st->error);$st->close();
  $st=$conexion->prepare('DELETE FROM pedidos_detalle WHERE pedido_id=?');$st->bind_param('i',$pedidoEdicionId);if(!$st->execute())throw new Exception($st->error);$st->close();
  $st=$conexion->prepare('INSERT INTO pedidos_detalle(pedido_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
  foreach($lineas as $i=>$l){$o=$i+1;$m=$l['modulo']??'CONTROL';$c=$l['concepto'];$co=$l['codigo'];$d=$l['descripcion'];$q=(float)$l['cantidad'];$u=(float)$l['unitario'];$f=$l['formula'];$tt=(float)$l['total'];$st->bind_param('iissssddsd',$pedidoEdicionId,$o,$m,$c,$co,$d,$q,$u,$f,$tt);if(!$st->execute())throw new Exception($st->error);} $st->close();
  $conexion->commit();
  documentoEventoRegistrar($conexion,'PEDIDO',$pedidoEdicionId,(string)($pedidoAnterior['pedido_numero']??''),'REVISION',(string)($pedidoAnterior['estado']??''),(string)($pedidoAnterior['estado']??''),'Revisión '.(int)$revisionNueva.': '.$motivoModificacion);
  try{generarPdfPedido($conexion,$pedidoEdicionId);generarHojasModificacionPedido($conexion,$pedidoEdicionId,$revisionNueva,$motivoModificacion);}catch(Throwable $e){error_log('PDF revisión pedido modular: '.$e->getMessage());}
  header('Location:ver_pedido.php?id='.(int)$pedidoEdicionId);exit;
 }
 if($accion==='generar_pedido_directo'){
  /* v410: primero Integración externa; el pedido se crea al confirmar fecha/pago. */
  $conexion->rollback();
  $token=bin2hex(random_bytes(16));
  if(!isset($_SESSION['pedido_preintegracion'])||!is_array($_SESSION['pedido_preintegracion']))$_SESSION['pedido_preintegracion']=array();
  $_SESSION['pedido_preintegracion'][$token]=array('creado'=>time(),'cabecera'=>$cab,'lineas'=>$lineas);
  header('Location:pedido_preintegracion.php?token='.rawurlencode($token));exit;
 }
 $usuario=$_SESSION['usuario_nombre']??null;$uid=(int)($_SESSION['usuario_id']??0);$json=json_encode($_POST,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);$lf=$lista['lista_fecha_archivo'];$ld=$lista['lista_valor_dolar'];$ref=$cab['referencia'];
 if($cotizacionEdicionId>0){
  $anterior=repoCotizacionEditableForUpdate($conexion,$cotizacionEdicionId);
  if(!$anterior) throw new Exception('La cotización a modificar no existe.');
  if((int)($anterior['tiene_pedido']??0)>0) throw new Exception('La cotización ya fue convertida en pedido y no puede modificarse. Modifique el pedido para generar una nueva revisión.');
  $revisionBase=(int)($_POST['cotizacion_revision_base']??-1);
  $revisionActual=(int)($anterior['revision']??0);
  if($revisionBase<0||$revisionBase!==$revisionActual) throw new Exception('Esta cotización fue modificada por otro usuario mientras usted la estaba editando. Vuelva a abrir la cotización para trabajar sobre la revisión vigente.');
  $motivo=trim((string)($_POST['motivo_modificacion_cotizacion']??''));
  if($motivo==='') throw new Exception('Debe indicar qué se modificó en la cotización.');
  $fechaRevision=date('Y-m-d H:i:s');
  $rev=$conexion->prepare('INSERT INTO cotizaciones_revisiones(cotizacion_id,revision,cliente_id,lista_id,lista_nombre,lista_fecha,lista_valor_dolar,referencia,estado,subtotal,descuento_1,descuento_2,descuento_3,total,datos_formulario,motivo_modificacion,fecha_revision,usuario,usuario_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
  $rev->bind_param('iiiissdssdddddssssi',$cotizacionEdicionId,$anterior['revision'],$anterior['cliente_id'],$anterior['lista_id'],$anterior['lista_nombre'],$anterior['lista_fecha'],$anterior['lista_valor_dolar'],$anterior['referencia'],$anterior['estado'],$anterior['subtotal'],$anterior['descuento_1'],$anterior['descuento_2'],$anterior['descuento_3'],$anterior['total'],$anterior['datos_formulario'],$motivo,$fechaRevision,$anterior['usuario'],$anterior['usuario_id']);
  if(!$rev->execute()) throw new Exception($rev->error);$revisionId=$rev->insert_id;$rev->close();
  $revDet=$conexion->prepare('INSERT INTO cotizaciones_revisiones_detalle(revision_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) SELECT ?,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM cotizaciones_detalle WHERE cotizacion_id=?');
  $revDet->bind_param('ii',$revisionId,$cotizacionEdicionId);if(!$revDet->execute())throw new Exception($revDet->error);$revDet->close();
  $st=$conexion->prepare("UPDATE cotizaciones SET cotizacion_tipo='SUMINISTROS',cliente_id=?,lista_id=?,lista_nombre=?,lista_fecha=?,lista_valor_dolar=?,referencia=?,subtotal=?,descuento_1=?,descuento_2=?,descuento_3=?,total=?,datos_formulario=?,revision=revision+1,estado='EMITIDA',usuario=?,usuario_id=?,pdf_archivo=NULL,pdf_fecha=NULL WHERE cotizacion_id=?");
  $st->bind_param('iissdsdddddssii',$cliente,$listaId,$lista['lista_nombre'],$lf,$ld,$ref,$subtotal,$d1,$d2,$d3,$t,$json,$usuario,$uid,$cotizacionEdicionId);if(!$st->execute())throw new Exception($st->error);$st->close();
  $st=$conexion->prepare('DELETE FROM cotizaciones_detalle WHERE cotizacion_id=?');$st->bind_param('i',$cotizacionEdicionId);if(!$st->execute())throw new Exception($st->error);$st->close();
  insertarDetalleCotizacionModular($conexion,$cotizacionEdicionId,$lineas);$cid=$cotizacionEdicionId;
 }else{
  $st=$conexion->prepare("INSERT INTO cotizaciones(cotizacion_tipo,cliente_id,lista_id,lista_nombre,lista_fecha,lista_valor_dolar,referencia,subtotal,descuento_1,descuento_2,descuento_3,total,datos_formulario,usuario,usuario_id) VALUES('SUMINISTROS',?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
  $st->bind_param('iissdsdddddssi',$cliente,$listaId,$lista['lista_nombre'],$lf,$ld,$ref,$subtotal,$d1,$d2,$d3,$t,$json,$usuario,$uid);if(!$st->execute())throw new Exception($st->error);$cid=$st->insert_id;$st->close();
  $num=siguienteNumeroDocumento($conexion,'COTIZACION_SUMINISTROS');$st=$conexion->prepare('UPDATE cotizaciones SET cotizacion_numero=? WHERE cotizacion_id=?');$st->bind_param('si',$num,$cid);$st->execute();$st->close();insertarDetalleCotizacionModular($conexion,$cid,$lineas);
 }
 $conexion->commit();$numeroEvento='';$stEvento=$conexion->prepare('SELECT cotizacion_numero FROM cotizaciones WHERE cotizacion_id=? LIMIT 1');if($stEvento){$stEvento->bind_param('i',$cid);$stEvento->execute();$filaEvento=$stEvento->get_result()->fetch_assoc();$stEvento->close();$numeroEvento=(string)($filaEvento['cotizacion_numero']??'');}if($cotizacionEdicionId>0){documentoEventoRegistrar($conexion,'COTIZACION',$cid,$numeroEvento,'REVISION',(string)($anterior['estado']??''),'EMITIDA','Revisión '.(int)($revisionActual+1).': '.$motivo);}else{documentoEventoRegistrar($conexion,'COTIZACION',$cid,$numeroEvento,'CREACION','','EMITIDA','Cotización de suministros emitida.');}try{generarPdfCotizacion($conexion,$cid);if($cotizacionEdicionId>0)generarHojasModificacionCotizacion($conexion,$cid,$revisionActual+1,$motivo);}catch(Throwable $e){error_log($e->getMessage());}header('Location:ver_cotizacion.php?id='.$cid.'&emitida=1');exit;
}catch(Throwable $e){if($conexion->errno===0){} try{$conexion->rollback();}catch(Throwable $x){} volver($e->getMessage());}
?>
