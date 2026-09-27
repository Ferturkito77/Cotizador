<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__.'/conexion.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/material_hueco_of.php';
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));

/**
 * CANTIDAD DE LIMITES PARA ACCESORIOS / MATERIAL DE HUECO.
 *
 * Esta regla es INDEPENDIENTE de:
 * - limites_paradas
 * - limites_paradas_tecnicos
 * - cantidad de paradas del ascensor
 * - formulas de composicion de material_hueco_reglas_of
 *
 * Fuente funcional: tabla tecnica de CANTIDAD DE LIMITES suministrada por Automac.
 * Variables: tipo/subtipo de Control, contactor de potencial, velocidad y,
 * exclusivamente para HIDRAULICO, retorno automatico con bateria de gel.
 */
function nombreCatalogo(mysqli $conexion, string $tabla, string $colId, string $colNombre, int $id): string
{
    if ($id <= 0) return '';
    $sql = "SELECT {$colNombre} nombre FROM {$tabla} WHERE {$colId}=? LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) return '';
    $st->bind_param('i', $id);
    $st->execute();
    $r = $st->get_result()->fetch_assoc();
    $st->close();
    return trim((string)($r['nombre'] ?? ''));
}

function normalizarTextoLimites(string $s): string
{
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'));
    return preg_replace('/\s+/', ' ', $s) ?: $s;
}

/**
 * Resuelve la familia de la tabla de cantidad de limites.
 * Se consulta tanto Tipo como Subtipo. El Tipo define la familia tecnica y el
 * Subtipo se conserva/valida como parte de la configuracion real seleccionada.
 */
function familiaLimitesDesdeControl(mysqli $conexion, array $datos): array
{
    $idTipo = (int)mhOfNumero($datos, 'id_tipo_control');
    $idSubtipo = (int)mhOfNumero($datos, 'id_subtipo');
    $tipoNombre = nombreCatalogo($conexion, 'tipos_control', 'ctrltipo_id', 'ctrltipo_name', $idTipo);
    $subtipoNombre = nombreCatalogo($conexion, 'subtipos_control', 'ctrlsubtipo_id', 'ctrlsubtipo_name', $idSubtipo);
    $tipo = normalizarTextoLimites($tipoNombre);
    $subtipo = normalizarTextoLimites($subtipoNombre);

    // Mapeo directo contra las familias de la tabla tecnica entregada.
    if ($tipo === '1V') $familia = '1V';
    elseif ($tipo === '2V') $familia = '2V';
    elseif (strpos($tipo, 'HIDRAUL') !== false) $familia = 'HIDRAULICO';
    elseif (strpos($tipo, 'IMAN PERMANENTE') !== false) $familia = 'IMAN PERMANENTE';
    elseif (strpos($tipo, 'ROOMLESS') !== false || $tipo === 'MRL') $familia = 'ROOMLESS';
    elseif ($tipo === 'VF') $familia = 'VF';
    else $familia = '';

    if ($familia === '') {
        throw new RuntimeException('El tipo/subtipo de Control seleccionado no tiene una regla de cantidad de limites definida.');
    }
    if ($idSubtipo <= 0 || $subtipoNombre === '') {
        throw new RuntimeException('Seleccione el subtipo del Control para calcular la cantidad de limites.');
    }

    return array(
        'familia'=>$familia,
        'tipo_id'=>$idTipo,
        'tipo'=>$tipoNombre,
        'subtipo_id'=>$idSubtipo,
        'subtipo'=>$subtipoNombre,
    );
}

function calcularCantidadLimitesControl(mysqli $conexion, array $datos): array
{
    $cfg = familiaLimitesDesdeControl($conexion, $datos);
    $familia = $cfg['familia'];
    $idSubtipo = (int)$cfg['subtipo_id'];
    $cantidadEquipos = max(1, (int)mhOfNumero($datos, 'cantidad_equipos', 1));
    $contactor = mhOfVerdadero($datos, 'agregar_contactorpot');
    $retornoGel = mhOfVerdadero($datos, 'retorno_bateria_gel');
    $velocidad = (float)mhOfNumero($datos, 'velocidad_vf', 0.0);

    if ($velocidad < 0 || $velocidad > 210) {
        throw new RuntimeException('La velocidad queda fuera de la tabla tecnica de cantidad de limites (0 a 210 m/min).');
    }

    $contactorDb = $contactor ? 'SI' : 'NO';
    $retornoDb = $familia === 'HIDRAULICO' ? ($retornoGel ? 'SI' : 'NO') : 'NA';

    // Fuente unica de verdad: limites_cantidad_reglas.
    // IMPORTANTE: no consultar limites_paradas ni limites_paradas_tecnicos.
    // Se prioriza una eventual regla especifica del subtipo y, si no existe,
    // se usa la regla general (ctrlsubtipo_id IS NULL) de la tabla tecnica.
    $sql = "SELECT regla_id, ctrlsubtipo_id, velocidad_desde, velocidad_hasta, cantidad_limites
            FROM limites_cantidad_reglas
            WHERE familia_control=?
              AND contactor_potencial=?
              AND retorno_bateria_gel=?
              AND regla_activa='SI'
              AND ? BETWEEN velocidad_desde AND velocidad_hasta
              AND (ctrlsubtipo_id=? OR ctrlsubtipo_id IS NULL)
            ORDER BY (ctrlsubtipo_id IS NOT NULL) DESC, regla_orden ASC, regla_id ASC
            LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) {
        throw new RuntimeException('No existe la tabla limites_cantidad_reglas. Ejecute migracion_limites_cantidad_v207.sql.');
    }
    $st->bind_param('sssdi', $familia, $contactorDb, $retornoDb, $velocidad, $idSubtipo);
    $st->execute();
    $regla = $st->get_result()->fetch_assoc();
    $st->close();

    if (!$regla) {
        throw new RuntimeException('No hay una regla activa de cantidad de limites para la configuracion seleccionada.');
    }

    $cantidadPorEquipo = (int)$regla['cantidad_limites'];
    if ($cantidadPorEquipo < 1) {
        throw new RuntimeException('La regla de cantidad de limites tiene una cantidad invalida.');
    }

    $cantidad = $cantidadPorEquipo * $cantidadEquipos;
    $cfg['cantidad'] = $cantidad;
    $cfg['cantidad_por_equipo'] = $cantidadPorEquipo;
    $cfg['cantidad_equipos'] = $cantidadEquipos;
    $cfg['contactor_potencial'] = $contactorDb;
    $cfg['retorno_bateria_gel'] = $retornoDb;
    $cfg['velocidad'] = $velocidad;
    $cfg['tramo_velocidad'] = number_format((float)$regla['velocidad_desde'], 2, '.', '').'-'.number_format((float)$regla['velocidad_hasta'], 2, '.', '');
    $cfg['regla_id'] = (int)$regla['regla_id'];
    $cfg['regla_subtipo_especifica'] = $regla['ctrlsubtipo_id'] !== null ? 'SI' : 'NO';
    return $cfg;
}

try {
    $datos = $_POST;
    $codigo = trim((string)($datos['codigo_limite'] ?? ''));
    if ($codigo === '') throw new RuntimeException('Seleccione un tipo de limite.');

    $resultado = calcularCantidadLimitesControl($conexion, $datos);

    echo json_encode(array(
        'ok'=>true,
        'cantidad'=>$resultado['cantidad'],
        'regla'=>array(
            'familia'=>$resultado['familia'],
            'tipo'=>$resultado['tipo'],
            'subtipo'=>$resultado['subtipo'],
            'contactor_potencial'=>$resultado['contactor_potencial'],
            'velocidad'=>$resultado['velocidad'],
            'tramo_velocidad'=>$resultado['tramo_velocidad'],
            'retorno_bateria_gel'=>$resultado['retorno_bateria_gel'],
            'cantidad_por_equipo'=>$resultado['cantidad_por_equipo'],
            'cantidad_equipos'=>$resultado['cantidad_equipos'],
            'regla_id'=>$resultado['regla_id'],
            'regla_subtipo_especifica'=>$resultado['regla_subtipo_especifica'],
        ),
    ), JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(array('ok'=>false,'mensaje'=>$e->getMessage()), JSON_UNESCAPED_UNICODE);
}
