<?php

function asegurarTablaPlantillasControles(mysqli $conexion): void
{
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'plantillas_controles', array(
        'plantilla_id','plantilla_codigo','plantilla_nombre','plantilla_descripcion',
        'plantilla_configuracion','plantilla_activa'
    ), 'migracion_consolidacion_v59.sql');
}

function camposExcluidosPlantilla(): array
{
    return array(
        'modo_auxiliar', 'modo_panel', 'accion_comercial',
        'plantilla_id', 'plantilla_codigo', 'plantilla_nombre', 'plantilla_descripcion',
        'lista_id', 'id_cliente', 'referencia_cotizacion',
        'descuento_1', 'descuento_2', 'descuento_3',
        'adicional_manual_descripcion_1', 'adicional_manual_importe_1',
        'adicional_manual_descripcion_2', 'adicional_manual_importe_2',
        'adicional_manual_descripcion_3', 'adicional_manual_importe_3'
    );
}

function extraerConfiguracionPlantilla(array $origen): array
{
    $excluidos = array_flip(camposExcluidosPlantilla());
    $configuracion = array();

    foreach ($origen as $campo => $valor) {
        if (isset($excluidos[$campo])) {
            continue;
        }
        if (is_array($valor)) {
            $configuracion[$campo] = array_values(array_map(static function ($item) {
                return is_scalar($item) ? trim((string)$item) : '';
            }, $valor));
        } elseif (is_scalar($valor)) {
            $configuracion[$campo] = trim((string)$valor);
        }
    }

    return $configuracion;
}

function obtenerPlantillaControl(mysqli $conexion, int $plantillaId): ?array
{
    $stmt = $conexion->prepare("SELECT plantilla_id, plantilla_codigo, plantilla_nombre, plantilla_descripcion, plantilla_configuracion, plantilla_activa, plantilla_creada, plantilla_modificada FROM plantillas_controles WHERE plantilla_id = ? LIMIT 1");
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la plantilla: ' . $conexion->error);
    }
    $stmt->bind_param('i', $plantillaId);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$fila) {
        return null;
    }

    $configuracion = json_decode((string)$fila['plantilla_configuracion'], true);
    $fila['configuracion'] = is_array($configuracion) ? $configuracion : array();
    return $fila;
}
