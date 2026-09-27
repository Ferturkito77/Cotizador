<?php
/** AUTOMAC V1/V1.5 - Actividad por usuario, revision AU-01.
 * Solo SELECT. Mismo modelo de datos para pantalla y exportaciones.
 * Compatible con PHP 7.3 y mysqli/mysqlnd, como el resto de V1.
 */
if (!defined('AUTOMAC_ACTIVIDAD_USUARIOS')) {
    http_response_code(404);
    exit;
}

function auEsc($value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function auParametro(array $entrada, string $clave, string $defecto): string
{
    if (!array_key_exists($clave, $entrada)) return $defecto;
    if (!is_string($entrada[$clave])) throw new InvalidArgumentException('Formato de filtro inválido: ' . $clave . '.');
    return trim($entrada[$clave]);
}

function auFecha(string $valor, string $nombre): DateTimeImmutable
{
    if (!preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2}$/D', $valor)) {
        throw new InvalidArgumentException('Complete una fecha válida en ' . $nombre . '.');
    }
    $fecha = DateTimeImmutable::createFromFormat('!Y-m-d', $valor);
    if (!$fecha || $fecha->format('Y-m-d') !== $valor || (int)$fecha->format('Y') < 1000 || (int)$fecha->format('Y') > 9998) {
        throw new InvalidArgumentException('La fecha de ' . $nombre . ' no es válida.');
    }
    return $fecha;
}

function auFiltros(array $entrada): array
{
    $desde = auFecha(auParametro($entrada, 'desde', date('Y-m-01')), 'Desde');
    $hasta = auFecha(auParametro($entrada, 'hasta', date('Y-m-d')), 'Hasta');
    if ($desde > $hasta) throw new InvalidArgumentException('Desde no puede ser posterior a Hasta. Revise el período.');
    $usuario = auParametro($entrada, 'usuario', 'todos');
    if ($usuario !== 'todos' && $usuario !== 'sin_usuario') {
        if (!preg_match('/^[1-9][0-9]{0,9}$/D', $usuario) || (string)(int)$usuario !== $usuario) {
            throw new InvalidArgumentException('Seleccione un usuario válido.');
        }
    }
    return array(
        'desde' => $desde->format('Y-m-d'), 'hasta' => $hasta->format('Y-m-d'),
        'inicio' => $desde->format('Y-m-d') . ' 00:00:00',
        // Limite superior exclusivo: incluye TODO el ultimo dia.
        'fin' => $hasta->modify('+1 day')->format('Y-m-d') . ' 00:00:00',
        'periodo' => $desde->format('d/m/Y') . ' al ' . $hasta->format('d/m/Y'),
        'usuario' => $usuario
    );
}

function auConsultar(mysqli $conexion, string $sql, array $parametros = array(), string $tipos = ''): array
{
    $stmt = $conexion->prepare($sql);
    if (!$stmt) throw new RuntimeException('No se pudo preparar el informe.');
    try {
        if ($parametros && !$stmt->bind_param($tipos, ...$parametros)) {
            throw new RuntimeException('No se pudieron aplicar los filtros.');
        }
        if (!$stmt->execute()) throw new RuntimeException('No se pudo consultar la actividad.');
        $res = $stmt->get_result();
        if (!$res) throw new RuntimeException('No se pudo leer la actividad.');
        $filas = array();
        while ($fila = $res->fetch_assoc()) $filas[] = $fila;
        $res->free();
        return $filas;
    } finally {
        $stmt->close();
    }
}

function auUsuarios(mysqli $conexion): array
{
    // Incluye inactivos para no perder la historia. No usa responsable de CRM.
    return auConsultar($conexion, "SELECT usuario_id, usuario_nombre, usuario_login, usuario_activo
        FROM usuarios ORDER BY usuario_nombre, usuario_login, usuario_id");
}

function auNombreUsuario(array $u): string
{
    $nombre = trim((string)$u['usuario_nombre']);
    return $nombre !== '' ? $nombre : (trim((string)$u['usuario_login']) !== '' ? (string)$u['usuario_login'] : 'Usuario #' . (int)$u['usuario_id']);
}

function auCeros(): array
{
    return array('c' => 0, 'r' => 0, 'presupuestos' => 0, 'obras' => 0, 'p' => 0);
}

function auInforme(mysqli $conexion, array $filtros, array $usuarios): array
{
    $mapa = array();
    foreach ($usuarios as $u) $mapa[(int)$u['usuario_id']] = $u;
    $seleccion = $filtros['usuario'];
    if ($seleccion !== 'todos' && $seleccion !== 'sin_usuario' && !isset($mapa[(int)$seleccion])) {
        throw new InvalidArgumentException('El usuario seleccionado ya no está disponible. Seleccione Todos o Sin usuario asociado.');
    }
    $partes = array(); $params = array(); $tipos = '';
    foreach (array('cotizaciones', 'pedidos') as $tabla) {
        $esCot = $tabla === 'cotizaciones';
        $numero = $esCot ? 'cotizacion_numero' : 'pedido_numero';
        $tipo = $esCot ? 'cotizacion_tipo' : 'pedido_tipo';
        $estado = $esCot ? "d.estado NOT IN ('ANULADA','BORRADOR')" : "d.estado <> 'ANULADO'";
        $columnas = $esCot
            ? "CASE WHEN d.$tipo='CONTROL' THEN 1 ELSE 0 END AS c, CASE WHEN d.$tipo='SUMINISTROS' THEN 1 ELSE 0 END AS r, 0 AS obras, 0 AS p"
            : "0 AS c, 0 AS r, CASE WHEN d.$tipo='OBRA' THEN 1 ELSE 0 END AS obras, CASE WHEN d.$tipo='SUMINISTROS' THEN 1 ELSE 0 END AS p";
        $whereUsuario = '';
        $params[] = $filtros['inicio']; $params[] = $filtros['fin']; $tipos .= 'ss';
        if ($seleccion === 'sin_usuario') {
            $whereUsuario = ' AND NOT EXISTS (SELECT 1 FROM usuarios u WHERE u.usuario_id=d.usuario_id AND d.usuario_id>0)';
        } elseif ($seleccion !== 'todos') {
            $whereUsuario = ' AND d.usuario_id=?'; $params[] = (int)$seleccion; $tipos .= 'i';
        }
        // Sin JOIN con detalles/revisiones: cada fila principal cuenta UNA vez.
        $partes[] = "SELECT CASE WHEN d.usuario_id>0 THEN d.usuario_id ELSE 0 END AS uid,
            CASE WHEN d.usuario_id>0 THEN '' ELSE COALESCE(TRIM(d.usuario),'') END AS legado,
            COALESCE(TRIM(d.usuario),'') AS nombre_guardado, $columnas
            FROM $tabla d WHERE d.$numero IS NOT NULL AND TRIM(d.$numero)<>''
            AND $estado AND d.fecha_creacion>=? AND d.fecha_creacion<?$whereUsuario";
    }
    $sql = "SELECT uid, legado, MAX(nombre_guardado) AS nombre_guardado,
        SUM(c) AS c, SUM(r) AS r, SUM(obras) AS obras, SUM(p) AS p
        FROM (" . implode(' UNION ALL ', $partes) . ') AS docs GROUP BY uid, legado';
    $datos = auConsultar($conexion, $sql, $params, $tipos);
    $filas = array();
    // Los usuarios sin movimiento tambien se muestran (0 no significa error).
    foreach ($mapa as $id => $u) {
        if ($seleccion === 'sin_usuario' || ($seleccion !== 'todos' && (int)$seleccion !== $id)) continue;
        $filas['id:' . $id] = array_merge(auCeros(), array(
            'nombre' => auNombreUsuario($u), 'detalle' => 'ID ' . $id . ' | ' . (string)$u['usuario_login'] . ($u['usuario_activo'] === 'SI' ? '' : ' | Inactivo'),
            'id' => $id, 'asociado' => true
        ));
    }
    $sinAsociar = 0;
    foreach ($datos as $dato) {
        $id = (int)$dato['uid']; $asociado = $id > 0 && isset($mapa[$id]);
        $clave = $id > 0 ? 'id:' . $id : 'legado:' . (string)$dato['legado'];
        if (!isset($filas[$clave])) {
            $nombre = trim((string)$dato['nombre_guardado']);
            $filas[$clave] = array_merge(auCeros(), array(
                'nombre' => $nombre !== '' ? $nombre : ($id > 0 ? 'Usuario #' . $id : 'Sin usuario asociado'),
                'detalle' => $id > 0 ? 'ID ' . $id . ' | Cuenta no disponible' : 'Sin ID de usuario | Nombre histórico',
                'id' => $id, 'asociado' => false
            ));
        }
        foreach (array('c','r','obras','p') as $col) $filas[$clave][$col] += (int)$dato[$col];
        if (!$asociado) $sinAsociar += (int)$dato['c'] + (int)$dato['r'] + (int)$dato['obras'] + (int)$dato['p'];
    }
    $totales = auCeros(); $conActividad = 0;
    foreach ($filas as &$fila) {
        $fila['presupuestos'] = $fila['c'] + $fila['r'];
        foreach (array_keys($totales) as $col) $totales[$col] += $fila[$col];
        if ($fila['presupuestos'] + $fila['obras'] + $fila['p'] > 0) $conActividad++;
    }
    unset($fila);
    $filas = array_values($filas);
    usort($filas, function (array $a, array $b): int {
        // Orden alfabetico; nunca mezcla dos cuentas con el mismo nombre.
        $cmp = strnatcasecmp($a['nombre'], $b['nombre']);
        return $cmp !== 0 ? $cmp : strcmp($a['detalle'], $b['detalle']);
    });
    $etiqueta = $seleccion === 'todos' ? 'Todos los usuarios' : ($seleccion === 'sin_usuario' ? 'Sin usuario asociado' : auNombreUsuario($mapa[(int)$seleccion]) . ' (ID ' . (int)$seleccion . ')');
    return array('filtros' => $filtros, 'usuario_etiqueta' => $etiqueta, 'filas' => $filas, 'totales' => $totales,
        'sin_asociar' => $sinAsociar, 'con_actividad' => $conActividad, 'generado' => date('d/m/Y H:i:s'));
}

function auUrl(array $filtros, string $formato = ''): string
{
    $q = array('tab' => 'actividad_usuarios', 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta'], 'usuario' => $filtros['usuario']);
    if ($formato !== '') $q['exportar'] = $formato;
    return 'comercial.php?' . http_build_query($q, '', '&', PHP_QUERY_RFC3986);
}

function auCriterios(): array
{
    return array(
        'Fuente: tablas cotizaciones, pedidos y usuarios de la base del cotizador. Se cuenta por fecha_creacion, desde las 00:00 del primer día hasta el final del último día.',
        'C.: presupuestos de Control. R.: presupuestos de suministros (pueden incluir Señalización, Accesorios o Repuestos). Total presupuestos = C. + R.',
        'Obras #: pedidos de tipo OBRA. Pedidos P.: pedidos de tipo SUMINISTROS. Incluye pedidos directos y provenientes de cotización.',
        'Cada documento principal numerado se cuenta una vez. Se excluyen anulados, borradores y documentos sin número. Las revisiones y las líneas de detalle no suman documentos.',
        'Usuario: el usuario_id guardado en el documento, no el responsable actual del cliente o de la agenda. Los nombres sin ID no se atribuyen automáticamente a cuentas; se muestran por separado.',
        'Las cuentas inactivas se conservan. Un presupuesto convertido y su pedido son dos documentos distintos, contados en su propia fecha. No es un conteo de equipos ni de órdenes de fabricación.',
        'Pantalla y exportación usan el mismo criterio. Si se crean o anulan documentos entre una consulta y la descarga, los resultados pueden actualizarse.'
    );
}
