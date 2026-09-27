<?php
function repoPedidoPorIdForUpdate(mysqli $conexion, int $pedidoId): ?array {
    $st=$conexion->prepare('SELECT * FROM pedidos WHERE pedido_id=? FOR UPDATE');
    if(!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i',$pedidoId); $st->execute(); $fila=$st->get_result()->fetch_assoc(); $st->close();
    return $fila ?: null;
}
function repoCotizacionEditableForUpdate(mysqli $conexion, int $cotizacionId): ?array {
    $sql="SELECT c.*, (SELECT COUNT(*) FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id) AS tiene_pedido FROM cotizaciones c WHERE c.cotizacion_id=? FOR UPDATE";
    $st=$conexion->prepare($sql); if(!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i',$cotizacionId); $st->execute(); $fila=$st->get_result()->fetch_assoc(); $st->close();
    return $fila ?: null;
}
