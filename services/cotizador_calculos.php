<?php
function cotizadorSubtotalLineas(array $lineas): float {
    $total = 0.0;
    foreach ($lineas as $linea) $total += (float)($linea['total'] ?? 0);
    return (float)ceil($total);
}
function cotizadorAplicarFactorLineas(array $lineas, float $factor, float $d1=0, float $d2=0, float $d3=0): array {
    foreach ($lineas as &$linea) {
        $linea['unitario'] = ceil((float)($linea['unitario'] ?? 0) * $factor);
        $linea['total'] = ceil((float)$linea['unitario'] * (float)($linea['cantidad'] ?? 0));
        $linea['formula'] = (string)($linea['formula'] ?? '') . ' · desc. '.number_format($d1,0).'% + '.number_format($d2,0).'% + '.number_format($d3,0).'%';
    }
    unset($linea);
    return $lineas;
}
