<?php
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
header('Content-Type: application/json; charset=UTF-8');

$idCliente = filter_input(INPUT_GET, 'id_cliente', FILTER_VALIDATE_INT);
if (!$idCliente) {
    echo json_encode(array('ok' => false, 'error' => 'Cliente inválido'));
    exit;
}

$sql = "SELECT clientes_id, clientes_codigo, clientes_nomfantasia, clientes_razonsocial, clientes_numero_bejerman, clientes_telefono, clientes_emails, clientes_socio_cecaf_numero, clientes_d1, clientes_d2, clientes_d3 FROM clientes WHERE clientes_id = ? AND clientes_habilitado = 'SI' LIMIT 1";
$stmt = $conexion->prepare($sql);
if (!$stmt) {
    echo json_encode(array('ok' => false, 'error' => $conexion->error));
    exit;
}
$stmt->bind_param('i', $idCliente);
$stmt->execute();
$cliente = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$cliente) {
    echo json_encode(array('ok' => false, 'error' => 'Cliente no encontrado o deshabilitado'));
    exit;
}
$cliente['ok'] = true;
echo json_encode($cliente);
?>
