<?php
function documentoConsumirTokenGuardado(string $token, string $accion, int $pedidoId=0, int $cotizacionId=0): void {
    $tokens = $_SESSION['document_save_tokens'] ?? array();
    if ($token === '' || !is_array($tokens) || !array_key_exists($token, $tokens)) {
        if ($accion === 'guardar_revision_pedido' && $pedidoId > 0) { header('Location:pedidos.php'); exit; }
        if ($accion === 'guardar_cotizacion' && $cotizacionId > 0) { header('Location:ver_cotizacion.php?id='.$cotizacionId); exit; }
        throw new RuntimeException('La operación ya fue procesada o la sesión del formulario venció. Vuelva a abrir el cotizador.');
    }
    unset($_SESSION['document_save_tokens'][$token]);
}
