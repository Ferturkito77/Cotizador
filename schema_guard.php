<?php
/**
 * Verificaciones de esquema en tiempo de ejecución.
 * La aplicación no crea ni altera tablas: los cambios de estructura se aplican mediante migraciones SQL versionadas.
 * v228: cache por request para evitar SHOW TABLES / SHOW COLUMNS repetidos.
 */
function esquemaTablaExiste(mysqli $conexion, string $tabla): bool
{
    static $cache = array();
    $clave = spl_object_id($conexion) . '|' . strtolower($tabla);
    if (array_key_exists($clave, $cache)) return $cache[$clave];

    $stmt = $conexion->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    if (!$stmt) return $cache[$clave] = false;
    $stmt->bind_param('s', $tabla);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $cache[$clave] = $ok;
}

function esquemaColumnaExiste(mysqli $conexion, string $tabla, string $columna): bool
{
    static $cache = array();
    $clave = spl_object_id($conexion) . '|' . strtolower($tabla) . '|' . strtolower($columna);
    if (array_key_exists($clave, $cache)) return $cache[$clave];
    if (!esquemaTablaExiste($conexion, $tabla)) return $cache[$clave] = false;

    $stmt = $conexion->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    if (!$stmt) return $cache[$clave] = false;
    $stmt->bind_param('ss', $tabla, $columna);
    $stmt->execute();
    $ok = (bool)$stmt->get_result()->fetch_row();
    $stmt->close();
    return $cache[$clave] = $ok;
}

function verificarTablaColumnas(mysqli $conexion, string $tabla, array $columnas = array(), string $migracion = ''): void
{
    if (!esquemaTablaExiste($conexion, $tabla)) {
        $msg = "Falta la tabla requerida {$tabla}.";
        if ($migracion !== '') $msg .= " Ejecute {$migracion}.";
        throw new RuntimeException($msg);
    }
    foreach ($columnas as $columna) {
        if (!esquemaColumnaExiste($conexion, $tabla, $columna)) {
            $msg = "Falta la columna requerida {$tabla}.{$columna}.";
            if ($migracion !== '') $msg .= " Ejecute {$migracion}.";
            throw new RuntimeException($msg);
        }
    }
}
