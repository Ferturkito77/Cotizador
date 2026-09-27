<?php
require_once __DIR__ . '/schema_guard.php';
/**
 * Parámetros mantenibles del cotizador.
 * v217: centraliza defaults comerciales y reglas transversales sin reescribir históricos.
 */
function psTablaExiste(mysqli $conexion, string $tabla): bool
{
    return esquemaTablaExiste($conexion, $tabla);
}

function parametroComercial(mysqli $conexion, string $clave, float $respaldo = 0.0): float
{
    static $cache = array();
    $clave = strtoupper(trim($clave));
    if ($clave === '') return $respaldo;
    if (array_key_exists($clave, $cache)) return $cache[$clave];
    if (!psTablaExiste($conexion, 'parametros_comerciales')) return $cache[$clave] = $respaldo;
    $st = $conexion->prepare("SELECT parametro_valor FROM parametros_comerciales WHERE parametro_clave=? AND parametro_activo='SI' LIMIT 1");
    if (!$st) return $cache[$clave] = $respaldo;
    $st->bind_param('s', $clave);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $cache[$clave] = ($f && is_numeric($f['parametro_valor'])) ? (float)$f['parametro_valor'] : $respaldo;
}

function automatizacionCotizador(mysqli $conexion, string $clave): ?array
{
    $clave = strtoupper(trim($clave));
    if ($clave === '' || !psTablaExiste($conexion, 'automatizaciones_cotizador')) return null;
    $st = $conexion->prepare("SELECT * FROM automatizaciones_cotizador WHERE regla_clave=? AND regla_activa='SI' LIMIT 1");
    if (!$st) return null;
    $st->bind_param('s', $clave);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $f ?: null;
}
