<?php

function asegurarTablaPlantillasSenalizacion(mysqli $conexion): void
{
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'plantillas_senalizacion', array('plantilla_id','plantilla_codigo','plantilla_nombre','plantilla_descripcion','senal_tipo_modulo','senal_tipo_puerta','senal_modelo','senal_color','senal_tecla','senal_tension','senal_borne_manual','senal_indicador_modelo','plantilla_activa'), 'migracion_senalizacion_plantillas_indicador_v486.sql');
}
function listarPlantillasSenalizacion(mysqli $conexion, bool $soloActivas = true): array
{
    asegurarTablaPlantillasSenalizacion($conexion);
    $where = $soloActivas ? " WHERE plantilla_activa='SI'" : '';
    $rs = $conexion->query("SELECT * FROM plantillas_senalizacion" . $where . " ORDER BY plantilla_codigo, plantilla_id");
    if (!$rs) return array();
    $salida = array();
    while ($fila = $rs->fetch_assoc()) $salida[] = $fila;
    return $salida;
}

function obtenerPlantillaSenalizacion(mysqli $conexion, int $id): ?array
{
    asegurarTablaPlantillasSenalizacion($conexion);
    $st = $conexion->prepare('SELECT * FROM plantillas_senalizacion WHERE plantilla_id=? LIMIT 1');
    if (!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i', $id);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ?: null;
}
