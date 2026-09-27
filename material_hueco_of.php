<?php
/**
 * Cálculo de materiales de hueco para órdenes de fabricación.
 * Usa exclusivamente las configuraciones, materiales y reglas almacenadas en la base.
 */

function mhOfNumero(array $datos, string $clave, float $predeterminado = 0.0): float
{
    if (!isset($datos[$clave]) || $datos[$clave] === '' || !is_numeric($datos[$clave])) {
        return $predeterminado;
    }
    return (float)$datos[$clave];
}

function mhOfVerdadero(array $datos, string $clave): bool
{
    if (!isset($datos[$clave])) {
        return false;
    }
    $valor = strtoupper(trim((string)$datos[$clave]));
    return in_array($valor, array('SI', 'SÍ', '1', 'X', 'TRUE', 'ON'), true);
}

function mhOfTokenizar(string $formula): array
{
    $formula = strtoupper(trim($formula));
    $tokens = array();
    $longitud = strlen($formula);
    $i = 0;

    while ($i < $longitud) {
        $caracter = $formula[$i];
        if (ctype_space($caracter)) {
            $i++;
            continue;
        }
        if (strpos('+-*/()', $caracter) !== false) {
            $tokens[] = $caracter;
            $i++;
            continue;
        }
        if (ctype_digit($caracter) || $caracter === '.' || $caracter === ',') {
            $inicio = $i;
            while ($i < $longitud && (ctype_digit($formula[$i]) || $formula[$i] === '.' || $formula[$i] === ',')) {
                $i++;
            }
            $numero = str_replace(',', '.', substr($formula, $inicio, $i - $inicio));
            if (!is_numeric($numero)) {
                throw new RuntimeException('Número inválido en fórmula: ' . $numero);
            }
            $tokens[] = (float)$numero;
            continue;
        }
        if (ctype_alpha($caracter) || $caracter === '_') {
            $inicio = $i;
            while ($i < $longitud && (ctype_alnum($formula[$i]) || $formula[$i] === '_')) {
                $i++;
            }
            $tokens[] = substr($formula, $inicio, $i - $inicio);
            continue;
        }
        throw new RuntimeException('Carácter no permitido en fórmula: ' . $caracter);
    }

    return $tokens;
}

function mhOfEvaluarFormula(string $formula, array $variables): float
{
    $tokens = mhOfTokenizar($formula);
    $posicion = 0;

    $parseExpresion = null;
    $parseTermino = null;
    $parseFactor = null;

    $parseFactor = function () use (&$tokens, &$posicion, &$parseExpresion, &$parseFactor, $variables): float {
        if (!array_key_exists($posicion, $tokens)) {
            throw new RuntimeException('Fórmula incompleta.');
        }
        $token = $tokens[$posicion];

        if ($token === '+') {
            $posicion++;
            return $parseFactor();
        }
        if ($token === '-') {
            $posicion++;
            return -$parseFactor();
        }
        if ($token === '(') {
            $posicion++;
            $valor = $parseExpresion();
            if (!isset($tokens[$posicion]) || $tokens[$posicion] !== ')') {
                throw new RuntimeException('Falta cerrar un paréntesis.');
            }
            $posicion++;
            return $valor;
        }
        if (is_float($token) || is_int($token)) {
            $posicion++;
            return (float)$token;
        }
        if (is_string($token) && preg_match('/^[A-Z_][A-Z0-9_]*$/', $token)) {
            $posicion++;
            if (!array_key_exists($token, $variables)) {
                throw new RuntimeException('Variable no definida en fórmula: ' . $token);
            }
            return (float)$variables[$token];
        }
        throw new RuntimeException('Elemento inválido en fórmula.');
    };

    $parseTermino = function () use (&$tokens, &$posicion, &$parseFactor): float {
        $valor = $parseFactor();
        while (isset($tokens[$posicion]) && ($tokens[$posicion] === '*' || $tokens[$posicion] === '/')) {
            $operador = $tokens[$posicion++];
            $derecha = $parseFactor();
            if ($operador === '*') {
                $valor *= $derecha;
            } else {
                if (abs($derecha) < 0.000000001) {
                    throw new RuntimeException('División por cero en fórmula.');
                }
                $valor /= $derecha;
            }
        }
        return $valor;
    };

    $parseExpresion = function () use (&$tokens, &$posicion, &$parseTermino): float {
        $valor = $parseTermino();
        while (isset($tokens[$posicion]) && ($tokens[$posicion] === '+' || $tokens[$posicion] === '-')) {
            $operador = $tokens[$posicion++];
            $derecha = $parseTermino();
            $valor = $operador === '+' ? $valor + $derecha : $valor - $derecha;
        }
        return $valor;
    };

    $resultado = $parseExpresion();
    if ($posicion !== count($tokens)) {
        throw new RuntimeException('La fórmula contiene elementos sin procesar.');
    }
    return $resultado;
}

function mhOfTipoConfiguracion(int $tipoControl, float $velocidad): string
{
    if ($tipoControl === 1) return '1V';
    if ($tipoControl === 2) return '2V';
    if ($tipoControl === 3) return 'HIDRAULICO';
    return $velocidad <= 75.0 ? 'VF_HASTA_75' : 'VF_MAYOR_75';
}

function mhOfLimiteSeleccionado(array $datos): ?array
{
    $mods=(array)($datos['modulo_item_modulo']??array());
    $conceptos=(array)($datos['modulo_item_concepto']??array());
    $codigos=(array)($datos['modulo_item_codigo']??array());
    $descripciones=(array)($datos['modulo_item_descripcion']??array());
    $cantidades=(array)($datos['modulo_item_cantidad']??array());
    $total=max(count($mods),count($conceptos),count($codigos),count($cantidades));
    for($i=0;$i<$total;$i++){
        if(strtoupper(trim((string)($mods[$i]??'')))!=='ACCESORIOS') continue;
        $concepto=strtoupper(trim((string)($conceptos[$i]??'')));
        $codigo=trim((string)($codigos[$i]??''));
        if($concepto==='LÍMITES'||$concepto==='LIMITES'||stripos($codigo,'HLGLLA')===0||stripos($codigo,'HXCK')===0){
            return array('codigo'=>$codigo,'descripcion'=>(string)($descripciones[$i]??''),'cantidad'=>max(0,(float)($cantidades[$i]??0)));
        }
    }
    return null;
}

function mhOfFormatearCantidad(float $cantidad): string
{
    if (abs($cantidad - round($cantidad)) < 0.000001) {
        return number_format($cantidad, 0, ',', '.');
    }
    return number_format($cantidad, 2, ',', '.');
}

/**
 * @return array{titulo:string,configuracion:string,materiales:array<int,array<string,mixed>>,advertencias:array<int,string>}
 */
function calcularMaterialHuecoOrden(mysqli $conexion, array $datos): array
{
    $idMaterialHueco = (int)mhOfNumero($datos, 'id_material_hueco');
    $tipoControl = (int)mhOfNumero($datos, 'id_tipo_control');
    $cantidadEquipos = max(1, (int)mhOfNumero($datos, 'cantidad_equipos', 1));
    $velocidad = mhOfNumero($datos, 'velocidad_vf', 0.0);
    if ($tipoControl === 1) $velocidad = 45.0;
    elseif ($tipoControl === 2) $velocidad = 60.0;

    $paradas = array();
    if (isset($datos['paradas_equipo']) && is_array($datos['paradas_equipo'])) {
        foreach ($datos['paradas_equipo'] as $valor) {
            if (is_numeric($valor) && (float)$valor > 0) $paradas[] = (float)$valor;
        }
    }
    if (!$paradas && isset($datos['paradas']) && is_numeric($datos['paradas'])) {
        $paradas[] = (float)$datos['paradas'];
    }
    $totalParadas = array_sum($paradas);
    if ($totalParadas <= 0) {
        $totalParadas = (float)$cantidadEquipos;
    }
    $paradasPromedio = $totalParadas / $cantidadEquipos;

    $sistema = $idMaterialHueco === 1 ? 'IMANES' : 'CHAPAS';
    $tipoConfiguracion = mhOfTipoConfiguracion($tipoControl, $velocidad);
    $posicionamiento = mhOfVerdadero($datos, 'posicionamiento_encoder') ? 'SI' : 'NO';

    $stmt = $conexion->prepare(
        "SELECT configuracion_id, configuracion_descripcion
           FROM material_hueco_configuraciones_of
          WHERE configuracion_sistema = ?
            AND configuracion_tipo = ?
            AND configuracion_pos_encoder = ?
            AND configuracion_activa = 'SI'
            AND (configuracion_velocidad_desde IS NULL OR ? >= configuracion_velocidad_desde)
            AND (configuracion_velocidad_hasta IS NULL OR ? <= configuracion_velocidad_hasta)
          ORDER BY configuracion_id
          LIMIT 1"
    );
    if (!$stmt) {
        throw new RuntimeException('No se pudo consultar la configuración de material de hueco: ' . $conexion->error);
    }
    $stmt->bind_param('sssdd', $sistema, $tipoConfiguracion, $posicionamiento, $velocidad, $velocidad);
    $stmt->execute();
    $configuracion = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$configuracion) {
        return array(
            'titulo' => $sistema === 'IMANES' ? 'Imanes y cabezales magnéticos' : 'Placas y cabezales infrarrojos',
            'configuracion' => '',
            'materiales' => array(),
            'advertencias' => array('No existe una configuración activa de material de hueco para los datos técnicos del pedido.')
        );
    }

    $stmt = $conexion->prepare(
        "SELECT r.regla_formula, r.regla_observacion, r.regla_orden_calculo,
                m.material_id, m.material_clave, m.material_nombre, m.material_unidad, m.material_orden
           FROM material_hueco_reglas_of r
           INNER JOIN material_hueco_materiales_of m ON m.material_id = r.material_id
          WHERE r.configuracion_id = ?
            AND r.regla_activa = 'SI'
            AND m.material_activo = 'SI'
          ORDER BY r.regla_orden_calculo, m.material_orden, r.regla_id"
    );
    if (!$stmt) {
        throw new RuntimeException('No se pudieron consultar las reglas de material de hueco: ' . $conexion->error);
    }
    $configuracionId = (int)$configuracion['configuracion_id'];
    $stmt->bind_param('i', $configuracionId);
    $stmt->execute();
    $resultado = $stmt->get_result();

    $variables = array(
        'N' => $paradasPromedio,
        'C' => (float)$cantidadEquipos,
        'CONT' => mhOfVerdadero($datos, 'agregar_contactorpot') ? (float)$cantidadEquipos : 0.0,
        'RBG' => mhOfVerdadero($datos, 'retorno_bateria_gel') ? (float)$cantidadEquipos : 0.0,
        'TP' => 0.0,
        'TPN' => 0.0,
        'L' => 0.0,
        'LIM' => 0.0,
        'BAN' => 0.0,
        'CHR' => 0.0,
    );

    $materiales = array();
    $limiteSeleccionado = mhOfLimiteSeleccionado($datos);
    $cantidadesPorClave = array();
    $advertencias = array();

    while ($regla = $resultado->fetch_assoc()) {
        $clavesPlacas = array('PLACA_NIVEL_15_CORTO','PLACA_NIVEL_15_LARGO','PLACA_NIVEL_45_CORTO','PLACA_ENTORNO_15_CORTO','PLACA_ENTORNO_15_LARGO');
        $clavesNivel = array('PLACA_NIVEL_15_CORTO','PLACA_NIVEL_15_LARGO','PLACA_NIVEL_45_CORTO');

        $totalPlacas = 0.0;
        $totalNivel = 0.0;
        foreach ($cantidadesPorClave as $clave => $cantidad) {
            if (in_array($clave, $clavesPlacas, true)) $totalPlacas += $cantidad;
            if (in_array($clave, $clavesNivel, true)) $totalNivel += $cantidad;
        }
        $variables['TP'] = $cantidadEquipos > 0 ? $totalPlacas / $cantidadEquipos : 0.0;
        $variables['TPN'] = $cantidadEquipos > 0 ? $totalNivel / $cantidadEquipos : 0.0;
        $variables['CHR'] = $variables['TP'];

        try {
            $cantidad = mhOfEvaluarFormula((string)$regla['regla_formula'], $variables);
        } catch (Throwable $e) {
            $advertencias[] = $regla['material_nombre'] . ': ' . $e->getMessage();
            continue;
        }
        $cantidad = max(0.0, round($cantidad, 6));
        if (strpos((string)$regla['material_clave'], 'LIMITE_') === 0) {
            if (!$limiteSeleccionado) {
                $variables['L'] = 0.0;
                $variables['LIM'] = 0.0;
                continue;
            }
            if ((float)$limiteSeleccionado['cantidad'] > 0) {
                $cantidad = (float)$limiteSeleccionado['cantidad'];
            }
            if (trim((string)$limiteSeleccionado['descripcion']) !== '') {
                $regla['material_nombre'] = $limiteSeleccionado['descripcion'];
            }
            /* L y LIM representan la cantidad final de límites, incluida una edición manual. */
            $variables['L'] = $cantidad;
            $variables['LIM'] = $cantidad;
        }
        if ($cantidad <= 0.000001) {
            continue;
        }

        $clave = (string)$regla['material_clave'];
        $cantidadesPorClave[$clave] = $cantidad;
        if ($clave === 'BANQUITO_CHAPAS' || $clave === 'BANQUITO_IMANES') {
            $variables['BAN'] = $cantidad;
        }

        $materiales[] = array(
            'material_id' => (int)$regla['material_id'],
            'clave' => $clave,
            'nombre' => (string)$regla['material_nombre'],
            'unidad' => (string)$regla['material_unidad'],
            'cantidad' => $cantidad,
            'formula' => (string)$regla['regla_formula'],
            'observacion' => (string)($regla['regla_observacion'] ?? ''),
        );
    }
    $stmt->close();

    return array(
        'titulo' => $sistema === 'IMANES' ? 'Imanes y cabezales magnéticos' : 'Placas y cabezales infrarrojos',
        'configuracion' => (string)$configuracion['configuracion_descripcion'],
        'materiales' => $materiales,
        'advertencias' => $advertencias,
    );
}
