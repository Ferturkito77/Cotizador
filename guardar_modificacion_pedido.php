<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
require_once 'documentos_pdf.php';
require_once 'documentos_eventos.php';

automacExigirPostConCsrf();
$pedidoId = filter_input(INPUT_POST, 'pedido_id', FILTER_VALIDATE_INT);
$revisionBase = filter_input(INPUT_POST, 'revision_base', FILTER_VALIDATE_INT);
$motivo = trim((string)($_POST['motivo_modificacion'] ?? ''));
if (!$pedidoId || $motivo === '') {
    $_SESSION['pedido_edicion_error'] = 'Debe indicar el detalle de la modificación.';
    header('Location: editar_pedido.php?id='.(int)$pedidoId); exit;
}
$modulos=(array)($_POST['modulo']??array());
$conceptos=(array)($_POST['concepto']??array());
$codigos=(array)($_POST['codigo']??array());
$descripciones=(array)($_POST['descripcion']??array());
$cantidades=(array)($_POST['cantidad']??array());
$precios=(array)($_POST['precio_unitario']??array());
$formulas=(array)($_POST['formula_aplicada']??array());
if (count($conceptos)===0) {
    $_SESSION['pedido_edicion_error']='El pedido debe tener al menos un renglón.';
    header('Location: editar_pedido.php?id='.(int)$pedidoId); exit;
}

$nuevos=array(); $total=0.0;
foreach($conceptos as $i=>$concepto){
    $concepto=trim((string)$concepto); if($concepto==='') continue;
    $cantidad=(float)str_replace(',','.',(string)($cantidades[$i]??0));
    $precio=ceil((float)str_replace(',','.',(string)($precios[$i]??0)));
    if($cantidad<0 || $precio<0) die('Cantidad o precio inválido.');
    $importe=ceil($cantidad*$precio); $total+=$importe;
    $modulo=strtoupper(trim((string)($modulos[$i]??'CONTROL'))); if(!in_array($modulo,array('CONTROL','SENALIZACION','IEP','ACCESORIOS','REPUESTOS'),true))$modulo='CONTROL';
    $nuevos[]=array('modulo'=>$modulo,'concepto'=>$concepto,'codigo'=>trim((string)($codigos[$i]??'')),'descripcion'=>trim((string)($descripciones[$i]??'')),'cantidad'=>$cantidad,'precio'=>$precio,'formula'=>trim((string)($formulas[$i]??'')),'importe'=>$importe);
}
if(count($nuevos)===0) die('El pedido debe tener al menos un renglón válido.');
$total=ceil($total);

$conexion->begin_transaction();
try{
    $st=$conexion->prepare('SELECT * FROM pedidos WHERE pedido_id=? FOR UPDATE');
    $st->bind_param('i',$pedidoId);$st->execute();$pedido=$st->get_result()->fetch_assoc();$st->close();
    if(!$pedido) throw new Exception('Pedido inexistente.');
    $revisionAnterior=(int)($pedido['revision']??0);
    if($revisionBase===false || $revisionBase===null || (int)$revisionBase!==$revisionAnterior) throw new Exception('Este pedido fue modificado por otro usuario mientras usted lo estaba editando. Vuelva a abrirlo para trabajar sobre la revisión vigente.');
    $revisionNueva=$revisionAnterior+1;
    $usuario=(string)($_SESSION['usuario_nombre']??'');$usuarioId=(int)($_SESSION['usuario_id']??0);

    $st=$conexion->prepare('INSERT INTO pedidos_revisiones(pedido_id,revision,estado,total,observaciones,datos_formulario,motivo_modificacion,usuario,usuario_id) VALUES(?,?,?,?,?,?,?,?,?)');
    $st->bind_param('iisdssssi',$pedidoId,$revisionAnterior,$pedido['estado'],$pedido['total'],$pedido['observaciones'],$pedido['datos_formulario'],$motivo,$usuario,$usuarioId);
    if(!$st->execute()) throw new Exception($st->error);$revisionId=$st->insert_id;$st->close();

    $st=$conexion->prepare('SELECT orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual,pedido_detalle_id');
    $st->bind_param('i',$pedidoId);$st->execute();$old=$st->get_result();
    $ins=$conexion->prepare('INSERT INTO pedidos_revisiones_detalle(revision_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
    while($d=$old->fetch_assoc()){$ins->bind_param('iissssddsd',$revisionId,$d['orden_visual'],$d['modulo'],$d['concepto'],$d['codigo'],$d['descripcion'],$d['cantidad'],$d['precio_unitario'],$d['formula_aplicada'],$d['importe_total']);if(!$ins->execute())throw new Exception($ins->error);} $ins->close();$st->close();

    $st=$conexion->prepare('DELETE FROM pedidos_detalle WHERE pedido_id=?');$st->bind_param('i',$pedidoId);if(!$st->execute())throw new Exception($st->error);$st->close();
    $ins=$conexion->prepare('INSERT INTO pedidos_detalle(pedido_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
    foreach($nuevos as $i=>$d){$orden=$i+1;$ins->bind_param('iissssddsd',$pedidoId,$orden,$d['modulo'],$d['concepto'],$d['codigo'],$d['descripcion'],$d['cantidad'],$d['precio'],$d['formula'],$d['importe']);if(!$ins->execute())throw new Exception($ins->error);} $ins->close();

    $st=$conexion->prepare('UPDATE pedidos SET revision=?,total=?,fecha_ultima_modificacion=NOW(),usuario_modificacion=?,usuario_modificacion_id=? WHERE pedido_id=?');
    $st->bind_param('idsii',$revisionNueva,$total,$usuario,$usuarioId,$pedidoId);if(!$st->execute())throw new Exception($st->error);$st->close();
    documentoEventoRegistrar($conexion,($pedido['pedido_tipo']??'OBRA')==='OBRA'?'OBRA':'PEDIDO',$pedidoId,(string)($pedido['pedido_numero']??''),'REVISION',(string)($pedido['estado']??''),(string)($pedido['estado']??''),'Revisión '.$revisionNueva.': '.$motivo);
    $conexion->commit();

    try{
        generarPdfPedido($conexion,$pedidoId);
        generarHojasModificacionPedido($conexion,$pedidoId,$revisionNueva,$motivo);
    }catch(Throwable $pdfError){error_log('Documentos revisión pedido: '.$pdfError->getMessage());}
    header('Location: ver_pedido.php?id='.$pedidoId);exit;
}catch(Throwable $e){$conexion->rollback();$_SESSION['pedido_edicion_error']='No se pudo guardar la revisión: '.$e->getMessage();header('Location: editar_pedido.php?id='.$pedidoId);exit;}
