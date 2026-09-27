<?php
require_once 'sistema_comercial.php';
require_once 'documentos_pdf.php';

function insertarDetalleCotizacionModular(mysqli $conexion, int $cotId, array $lineas): void
{
    $st=$conexion->prepare('INSERT INTO cotizaciones_detalle(cotizacion_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
    if(!$st) throw new Exception($conexion->error);
    foreach($lineas as $i=>$l){
        $o=$i+1; $m=$l['modulo']??'CONTROL'; $c=$l['concepto']; $co=$l['codigo']; $d=$l['descripcion']; $q=(float)$l['cantidad']; $u=(float)$l['unitario']; $f=$l['formula']; $t=(float)$l['total'];
        $st->bind_param('iissssddsd',$cotId,$o,$m,$c,$co,$d,$q,$u,$f,$t);
        if(!$st->execute()) throw new Exception($st->error);
    }
    $st->close();
}

function crearPedidoDirectoModular(mysqli $conexion, array $cabecera, array $lineas): int
{
    $usuario=$_SESSION['usuario_nombre']??null; $usuarioId=(int)($_SESSION['usuario_id']??0);
    $datosJson=json_encode($cabecera['datos_formulario']??array(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $tieneControl=false; foreach($lineas as $l){if(($l['modulo']??'')==='CONTROL'){$tieneControl=true;break;}}
    $tipoCot=$tieneControl?'CONTROL':'SUMINISTROS'; $tipoPedido=$tieneControl?'OBRA':'SUMINISTROS';
    $st=$conexion->prepare("INSERT INTO cotizaciones(cotizacion_numero,cotizacion_tipo,cliente_id,lista_id,lista_nombre,lista_fecha,lista_valor_dolar,referencia,estado,subtotal,descuento_1,descuento_2,descuento_3,total,datos_formulario,usuario,usuario_id) VALUES(NULL,?,?,?,?,?,?,?,'ACEPTADA',?,?,?,?,?,?,?,?)");
    $st->bind_param('siissdsddddsssi',$tipoCot,$cabecera['cliente_id'],$cabecera['lista_id'],$cabecera['lista_nombre'],$cabecera['lista_fecha'],$cabecera['lista_valor_dolar'],$cabecera['referencia'],$cabecera['subtotal'],$cabecera['descuento_1'],$cabecera['descuento_2'],$cabecera['descuento_3'],$cabecera['total'],$datosJson,$usuario,$usuarioId);
    if(!$st->execute()) throw new Exception($st->error); $cotId=$st->insert_id; $st->close();
    insertarDetalleCotizacionModular($conexion,$cotId,$lineas);
    $st=$conexion->prepare("INSERT INTO pedidos(pedido_tipo,cotizacion_id,cliente_id,lista_id,origen,referencia,total,datos_formulario,usuario,usuario_id) VALUES(?,?,?,?, 'DIRECTO',?,?,?,?,?)");
    $st->bind_param('siiisdssi',$tipoPedido,$cotId,$cabecera['cliente_id'],$cabecera['lista_id'],$cabecera['referencia'],$cabecera['total'],$datosJson,$usuario,$usuarioId);
    if(!$st->execute()) throw new Exception($st->error); $pid=$st->insert_id; $st->close();
    $numero=siguienteNumeroDocumento($conexion,$tieneControl?'PEDIDO_OBRA':'PEDIDO_SUMINISTROS');
    $st=$conexion->prepare('UPDATE pedidos SET pedido_numero=? WHERE pedido_id=?'); $st->bind_param('si',$numero,$pid); if(!$st->execute())throw new Exception($st->error); $st->close();
    $st=$conexion->prepare('INSERT INTO pedidos_detalle(pedido_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
    if(!$st) throw new Exception($conexion->error);
    foreach($lineas as $i=>$l){$o=$i+1;$m=$l['modulo']??'CONTROL';$c=$l['concepto'];$co=$l['codigo'];$d=$l['descripcion'];$q=(float)$l['cantidad'];$u=(float)$l['unitario'];$f=$l['formula'];$t=(float)$l['total'];$st->bind_param('iissssddsd',$pid,$o,$m,$c,$co,$d,$q,$u,$f,$t);if(!$st->execute())throw new Exception($st->error);} $st->close();
    return $pid;
}
?>
