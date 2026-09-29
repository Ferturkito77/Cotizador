<?php

function normalizarDatoMotorACorriente($tipo, $valor, $tension)
{
    $tipo = strtoupper(trim((string)$tipo));
    $valorTexto = trim(str_replace(',', '.', (string)$valor));
    if (!in_array($tipo, array('HP', 'AMP', 'KW'), true) || !is_numeric($valorTexto) || (float)$valorTexto <= 0) {
        throw new InvalidArgumentException('Ingrese un tipo y un valor de motor válidos.');
    }

    $tensionTexto = strtoupper(preg_replace('/\s+/', '', trim((string)$tension)));
    if (in_array($tensionTexto, array('1', '220', '3X220', '3*220'), true)) {
        $factorCorriente = 1.8 * 1.8;
    } elseif (in_array($tensionTexto, array('2', '380', '3X380', '3*380'), true)) {
        $factorCorriente = 1.8;
    } else {
        throw new InvalidArgumentException('La tensión debe ser 3x220 V o 3x380 V para normalizar el motor VF.');
    }

    $valorNumerico = (float)$valorTexto;
    $hpEquivalente = null;
    if ($tipo === 'AMP') {
        $corriente = $valorNumerico;
    } else {
        $hpEquivalente = $tipo === 'KW' ? $valorNumerico * 1.341 : $valorNumerico;
        $corriente = $hpEquivalente * $factorCorriente;
    }

    return array(
        'dato_original_tipo' => $tipo,
        'dato_original_valor' => $valorNumerico,
        'hp_equivalente' => $hpEquivalente,
        'corriente_normalizada' => $corriente
    );
}

function controlMotorCpuMatriz(mysqli $conexion, $cpuId)
{
    $cpuId = (int)$cpuId;
    $st = $conexion->prepare('SELECT COALESCE(NULLIF(cpu_matriz_base_id,0),cpu_id) AS cpu_matriz_base_id FROM cpus WHERE cpu_id=? LIMIT 1');
    if (!$st) {
        throw new RuntimeException('No se pudo resolver la matriz base de la CPU.');
    }
    $st->bind_param('i', $cpuId);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$fila) {
        throw new InvalidArgumentException('La CPU seleccionada no existe.');
    }
    $cpuBase = (int)$fila['cpu_matriz_base_id'];
    $stPropia = $conexion->prepare('SELECT 1 FROM matriz_calculos WHERE control_cpu=? AND control_tipo=4 LIMIT 1');
    if ($stPropia) {
        $stPropia->bind_param('i', $cpuId);
        $stPropia->execute();
        $tieneMatrizPropia = (bool)$stPropia->get_result()->fetch_row();
        $stPropia->close();
        if ($tieneMatrizPropia) {
            return $cpuId;
        }
    }
    return $cpuBase;
}

function listarVariadoresValidosPorCorrienteVF(mysqli $conexion, $cpuMatriz, $subtipoId, $tensionId, $encoder, $corriente)
{
    $cpuMatriz = (int)$cpuMatriz;
    $subtipoId = (int)$subtipoId;
    $tensionId = (int)$tensionId;
    $encoder = (string)$encoder === 'SI' ? 'SI' : '';
    $corriente = (float)$corriente;
    if ($cpuMatriz <= 0 || $subtipoId <= 0 || $tensionId <= 0 || $corriente <= 0) {
        return array();
    }

        $sql = "SELECT m.control_id,m.control_subtipo,s.ctrlsubtipo_name,m.control_corriente,m.control_codigo,m.control_potenciadesde,m.control_potenciahasta
            FROM matriz_calculos m
            INNER JOIN subtipos_control s ON s.ctrlsubtipo_id=m.control_subtipo
            WHERE m.control_cpu=? AND m.control_tipo=4 AND m.control_subtipo=? AND m.control_tension=?
              AND COALESCE(NULLIF(TRIM(m.control_encoder),''),'')=?
              AND m.control_corriente>0 AND m.control_corriente>=?
            ORDER BY m.control_corriente ASC,m.control_codigo ASC,m.control_id ASC";
    $st = $conexion->prepare($sql);
    if (!$st) {
        throw new RuntimeException('No se pudieron consultar los variadores compatibles.');
    }
    $st->bind_param('iiisd', $cpuMatriz, $subtipoId, $tensionId, $encoder, $corriente);
    $st->execute();
    $rs = $st->get_result();
    $filas = array();
    while ($fila = $rs->fetch_assoc()) {
        $filas[] = $fila;
    }
    $st->close();
    return $filas;
}

function obtenerVariadorSeleccionadoVF(mysqli $conexion, $matrizId, $cpuMatriz, $subtipoId, $tensionId, $encoder, $corriente)
{
    $matrizId = (int)$matrizId;
    $cpuMatriz = (int)$cpuMatriz;
    $subtipoId = (int)$subtipoId;
    $tensionId = (int)$tensionId;
    $encoder = (string)$encoder === 'SI' ? 'SI' : '';
    $corriente = (float)$corriente;
    if ($matrizId <= 0 || $cpuMatriz <= 0 || $subtipoId <= 0 || $tensionId <= 0 || $corriente <= 0) {
        return null;
    }

        $sql = "SELECT m.*,s.ctrlsubtipo_name
            FROM matriz_calculos m
            INNER JOIN subtipos_control s ON s.ctrlsubtipo_id=m.control_subtipo
            WHERE m.control_id=? AND m.control_cpu=? AND m.control_tipo=4 AND m.control_subtipo=?
              AND m.control_tension=? AND COALESCE(NULLIF(TRIM(m.control_encoder),''),'')=?
              AND m.control_corriente>0 AND m.control_corriente>=? LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) {
        throw new RuntimeException('No se pudo validar el variador seleccionado.');
    }
    $st->bind_param('iiiisd', $matrizId, $cpuMatriz, $subtipoId, $tensionId, $encoder, $corriente);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ?: null;
}