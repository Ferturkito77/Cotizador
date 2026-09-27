<?php
function cotizadorValidarAccion(string $accion, array $permitidas): void {
    if (!in_array($accion, $permitidas, true)) throw new InvalidArgumentException('Acción inválida.');
}
function cotizadorValidarCliente(int $clienteId): void {
    if ($clienteId <= 0) throw new InvalidArgumentException('Seleccione un cliente antes de emitir el documento.');
}
function cotizadorValidarReferenciaPedidoDirecto(string $accion, string $referencia): void {
    if ($accion === 'generar_pedido_directo' && trim($referencia) === '') throw new InvalidArgumentException('Complete la referencia antes de generar el pedido.');
}
