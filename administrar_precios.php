<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function redirigirPrecios($mensaje, $tipo = 'ok') {
    $_SESSION['precios_mensaje'] = $mensaje;
    $_SESSION['precios_tipo'] = $tipo;
    header('Location: administrar_precios.php');
    exit;
}

function normalizarTexto($valor) {
    $valor = (string)$valor;
    if (substr($valor, 0, 3) === "\xEF\xBB\xBF") {
        $valor = substr($valor, 3);
    }
    if (function_exists('mb_detect_encoding')) {
        $codificacion = mb_detect_encoding($valor, array('UTF-8', 'Windows-1252', 'ISO-8859-1'), true);
        if ($codificacion && $codificacion !== 'UTF-8') {
            $valor = mb_convert_encoding($valor, 'UTF-8', $codificacion);
        }
    }
    $valor = trim($valor);
    $valor = preg_replace('/\s+/u', ' ', $valor);
    return $valor === null ? '' : $valor;
}

function normalizarCodigo($valor) {
    return strtoupper(normalizarTexto($valor));
}

function numeroCsv($valor) {
    if (is_int($valor) || is_float($valor)) {
        return (float)$valor;
    }

    $texto = normalizarTexto($valor);
    if ($texto === '') {
        return null;
    }

    $texto = str_replace(array('$', 'ARS', 'USD', ' '), '', strtoupper($texto));
    $texto = preg_replace('/[^0-9,\.\-]/', '', $texto);

    if ($texto === '' || $texto === '-') {
        return null;
    }

    $ultimaComa = strrpos($texto, ',');
    $ultimoPunto = strrpos($texto, '.');

    if ($ultimaComa !== false && $ultimoPunto !== false) {
        if ($ultimaComa > $ultimoPunto) {
            $texto = str_replace('.', '', $texto);
            $texto = str_replace(',', '.', $texto);
        } else {
            $texto = str_replace(',', '', $texto);
        }
    } elseif ($ultimaComa !== false) {
        $decimales = strlen($texto) - $ultimaComa - 1;
        if ($decimales >= 1 && $decimales <= 4 && substr_count($texto, ',') === 1) {
            $texto = str_replace(',', '.', $texto);
        } else {
            $texto = str_replace(',', '', $texto);
        }
    } elseif ($ultimoPunto !== false) {
        if (substr_count($texto, '.') > 1) {
            $texto = str_replace('.', '', $texto);
        } else {
            $decimales = strlen($texto) - $ultimoPunto - 1;
            if ($decimales === 3 && strlen(substr($texto, 0, $ultimoPunto)) >= 1) {
                $texto = str_replace('.', '', $texto);
            }
        }
    }

    return is_numeric($texto) ? (float)$texto : null;
}

function fechaCsv($valor) {
    $texto = normalizarTexto($valor);
    if ($texto === '') {
        return null;
    }

    foreach (array('d/m/Y', 'd-m-Y', 'Y-m-d', 'd/m/y', 'd-m-y') as $formato) {
        $fecha = DateTime::createFromFormat($formato, $texto);
        if ($fecha instanceof DateTime) {
            $errores = DateTime::getLastErrors();
            if ($errores === false || ($errores['warning_count'] === 0 && $errores['error_count'] === 0)) {
                return $fecha->format('Y-m-d');
            }
        }
    }

    return null;
}

function detectarSeparador($ruta) {
    $muestra = file_get_contents($ruta, false, null, 0, 8192);
    if ($muestra === false) {
        return ';';
    }

    $lineas = preg_split('/\r\n|\r|\n/', $muestra);
    $puntajes = array(';' => 0, ',' => 0, "\t" => 0);

    foreach (array_slice($lineas, 0, 10) as $linea) {
        if (trim($linea) === '') {
            continue;
        }
        foreach ($puntajes as $separador => $puntaje) {
            $puntajes[$separador] += substr_count($linea, $separador);
        }
    }

    arsort($puntajes);
    $separador = key($puntajes);
    return $puntajes[$separador] > 0 ? $separador : ';';
}

function asegurarTablas($conexion) {
    require_once __DIR__ . '/schema_guard.php';
    try {
        verificarTablaColumnas($conexion, 'listas_precios_importaciones', array(
            'lista_id','lista_nombre','lista_fecha_archivo','lista_vigente_desde','lista_archivo_origen',
            'lista_hash_archivo','lista_estado','lista_anulada_motivo','lista_fecha_estado'
        ), 'migracion_consolidacion_v59.sql');
        verificarTablaColumnas($conexion, 'listas_precios_historial', array(
            'historial_id','lista_id','precios_codigo','precios_descripcion','precios_costo','precios_clasificacion'
        ), 'migracion_consolidacion_v59.sql');
    } catch (Throwable $e) {
        die('La base de precios requiere actualizar el esquema: ' . e($e->getMessage()));
    }
}


asegurarTablas($conexion);

if (empty($_SESSION['csrf_precios'])) {
    $_SESSION['csrf_precios'] = bin2hex(random_bytes(24));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf_precios'], $_POST['csrf'] ?? '')) {
        redirigirPrecios('La sesión del formulario venció. Volvé a intentar.', 'error');
    }

    $accion = $_POST['accion'] ?? '';

    if ($accion === 'cambiar_estado_lista') {
        $listaId = filter_input(INPUT_POST, 'lista_id', FILTER_VALIDATE_INT);
        $estado = $_POST['lista_estado'] ?? '';
        $permitidos = array('VIGENTE', 'REEMPLAZADA', 'ANULADA');
        if (!$listaId || !in_array($estado, $permitidos, true)) {
            redirigirPrecios('Lista o estado inválido.', 'error');
        }

        $motivo = normalizarTexto($_POST['motivo'] ?? '');
        if ($estado === 'ANULADA' && $motivo === '') {
            redirigirPrecios('Para anular una lista debe indicar el motivo.', 'error');
        }

        $stmtActual = $conexion->prepare('SELECT lista_estado FROM listas_precios_importaciones WHERE lista_id=? LIMIT 1');
        $stmtActual->bind_param('i', $listaId);
        $stmtActual->execute();
        $filaActual = $stmtActual->get_result()->fetch_assoc();
        $stmtActual->close();
        if (!$filaActual) redirigirPrecios('La lista indicada no existe.', 'error');

        if ($filaActual['lista_estado'] === 'VIGENTE' && $estado !== 'VIGENTE') {
            $rVigentes = $conexion->query("SELECT COUNT(*) AS cantidad FROM listas_precios_importaciones WHERE lista_estado='VIGENTE'");
            $cantidadVigentes = $rVigentes ? (int)$rVigentes->fetch_assoc()['cantidad'] : 0;
            if ($cantidadVigentes <= 1) {
                redirigirPrecios('Antes de quitar la única lista vigente, marque otra lista como VIGENTE.', 'error');
            }
        }

        $conexion->begin_transaction();
        try {
            if ($estado === 'VIGENTE') {
                if (!$conexion->query("UPDATE listas_precios_importaciones SET lista_estado='REEMPLAZADA', lista_fecha_estado=NOW() WHERE lista_estado='VIGENTE' AND lista_id<>".(int)$listaId)) {
                    throw new Exception($conexion->error);
                }
                $motivo = '';
            } elseif ($estado !== 'ANULADA') {
                $motivo = '';
            }

            $stmt = $conexion->prepare('UPDATE listas_precios_importaciones SET lista_estado=?, lista_anulada_motivo=?, lista_fecha_estado=NOW() WHERE lista_id=? LIMIT 1');
            if (!$stmt) throw new Exception($conexion->error);
            $stmt->bind_param('ssi', $estado, $motivo, $listaId);
            if (!$stmt->execute()) throw new Exception($stmt->error);
            $stmt->close();

            $conexion->commit();
            redirigirPrecios('Estado de la lista actualizado a '.$estado.'.');
        } catch (Throwable $e) {
            $conexion->rollback();
            redirigirPrecios('No se pudo cambiar el estado: '.$e->getMessage(), 'error');
        }
    }

    if ($accion === 'analizar') {
        if (!isset($_FILES['archivo_lista']) || $_FILES['archivo_lista']['error'] !== UPLOAD_ERR_OK) {
            redirigirPrecios('Seleccione un archivo CSV válido.', 'error');
        }

        $original = basename($_FILES['archivo_lista']['name']);
        $extension = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            redirigirPrecios('Esta versión admite únicamente archivos CSV. Abra el Excel y guárdelo como CSV UTF-8.', 'error');
        }

        if ($_FILES['archivo_lista']['size'] > 15 * 1024 * 1024) {
            redirigirPrecios('El archivo supera el máximo permitido de 15 MB.', 'error');
        }

        $tmp = $_FILES['archivo_lista']['tmp_name'];
        $separador = detectarSeparador($tmp);
        $manejador = fopen($tmp, 'rb');
        if (!$manejador) {
            redirigirPrecios('No se pudo abrir el archivo CSV.', 'error');
        }

        $registros = array();
        $duplicados = array();
        $descartados = 0;
        $filaNumero = 0;
        $fechaArchivo = null;
        $valorDolar = null;

        while (($fila = fgetcsv($manejador, 0, $separador)) !== false) {
            $filaNumero++;
            if ($filaNumero > 20000) {
                fclose($manejador);
                redirigirPrecios('El CSV supera el máximo de 20.000 filas.', 'error');
            }

            foreach ($fila as $indice => $celda) {
                $fila[$indice] = normalizarTexto($celda);
            }

            if ($filaNumero === 1) {
                if (isset($fila[1])) {
                    $fechaArchivo = fechaCsv($fila[1]);
                }
                if (isset($fila[8])) {
                    $valorDolar = numeroCsv($fila[8]);
                }
            }

            $codigo = isset($fila[0]) ? normalizarCodigo($fila[0]) : '';
            $descripcion = isset($fila[1]) ? normalizarTexto($fila[1]) : '';
            $precio = isset($fila[2]) ? numeroCsv($fila[2]) : null;

            if ($codigo === '' && $descripcion === '' && $precio === null) {
                continue;
            }

            if (in_array($codigo, array('CODIGO', 'CÓDIGO', 'ARTICULO', 'ARTÍCULO', 'COD'), true)) {
                $descartados++;
                continue;
            }

            /* Es válido cualquier ítem de la lista, no solo controles, siempre que tenga código, descripción y precio. */
            if ($codigo === '' || $descripcion === '' || $precio === null || $precio < 0) {
                $descartados++;
                continue;
            }

            if (isset($registros[$codigo])) {
                $duplicados[$codigo] = true;
            }

            $registros[$codigo] = array(
                'codigo' => $codigo,
                'descripcion' => $descripcion,
                'precio' => round($precio, 4)
            );
        }
        fclose($manejador);

        if (!$registros) {
            redirigirPrecios('No se encontraron ítems válidos con código, descripción y precio en las columnas A, B y C del CSV.', 'error');
        }

        $articulosActuales = array();
        $listaVigenteId = 0;
        $rListaVigente = $conexion->query("SELECT lista_id FROM listas_precios_importaciones WHERE lista_estado='VIGENTE' ORDER BY lista_vigente_desde DESC, lista_id DESC LIMIT 1");
        if ($rListaVigente && ($fListaVigente = $rListaVigente->fetch_assoc())) {
            $listaVigenteId = (int)$fListaVigente['lista_id'];
            $stmtActuales = $conexion->prepare("SELECT precios_codigo, precios_descripcion, precios_costo, precios_clasificacion FROM listas_precios_historial WHERE lista_id=? ORDER BY precios_codigo");
            if ($stmtActuales) {
                $stmtActuales->bind_param('i', $listaVigenteId);
                $stmtActuales->execute();
                $resActuales = $stmtActuales->get_result();
                while ($fa = $resActuales->fetch_assoc()) $articulosActuales[normalizarCodigo($fa['precios_codigo'])] = $fa;
                $stmtActuales->close();
            }
        }
        $faltantes = array();
        foreach ($articulosActuales as $codigoActual => $artActual) if (!isset($registros[$codigoActual])) $faltantes[$codigoActual] = $artActual;
        $nuevosComparacion = array();
        foreach ($registros as $codigoNuevo => $artNuevo) if (!isset($articulosActuales[$codigoNuevo])) $nuevosComparacion[$codigoNuevo] = $artNuevo;

        $carpeta = __DIR__ . '/uploads_listas';
        if (!is_dir($carpeta) && !mkdir($carpeta, 0775, true)) {
            redirigirPrecios('No se pudo crear la carpeta uploads_listas.', 'error');
        }

        $nombreSeguro = date('Ymd_His') . '_' . preg_replace('/[^A-Za-z0-9._-]/', '_', $original);
        $destino = $carpeta . '/' . $nombreSeguro;
        if (!move_uploaded_file($tmp, $destino)) {
            redirigirPrecios('No se pudo guardar temporalmente el archivo.', 'error');
        }

        $_SESSION['lista_preview'] = array(
            'archivo' => $nombreSeguro,
            'archivo_original' => $original,
            'hash' => hash_file('sha256', $destino),
            'fecha_archivo' => $fechaArchivo,
            'valor_dolar' => $valorDolar,
            'separador' => $separador === "\t" ? 'TAB' : $separador,
            'descartados' => $descartados,
            'duplicados' => array_keys($duplicados),
            'registros' => array_values($registros),
            'faltantes' => $faltantes,
            'nuevos_comparacion' => array_values($nuevosComparacion),
            'lista_vigente_id' => $listaVigenteId
        );

        header('Location: administrar_precios.php#confirmar');
        exit;
    }

    if ($accion === 'cancelar_preview') {
        unset($_SESSION['lista_preview']);
        redirigirPrecios('Importación cancelada.', 'ok');
    }

    if ($accion === 'confirmar') {
        $preview = $_SESSION['lista_preview'] ?? null;
        if (!$preview || empty($preview['registros'])) {
            redirigirPrecios('No existe una lista analizada para importar.', 'error');
        }

        $vigenteDesde = $_POST['vigente_desde'] ?? '';
        $fechaTest = DateTime::createFromFormat('Y-m-d', $vigenteDesde);
        if (!$fechaTest || $fechaTest->format('Y-m-d') !== $vigenteDesde) {
            redirigirPrecios('Indique una fecha de vigencia válida.', 'error');
        }

        $fechaArchivoFormulario = $_POST['fecha_archivo'] ?? '';
        $fechaArchivo = null;
        if ($fechaArchivoFormulario !== '') {
            $fechaArchivoTest = DateTime::createFromFormat('Y-m-d', $fechaArchivoFormulario);
            if (!$fechaArchivoTest || $fechaArchivoTest->format('Y-m-d') !== $fechaArchivoFormulario) {
                redirigirPrecios('Indique una fecha de lista válida.', 'error');
            }
            $fechaArchivo = $fechaArchivoFormulario;
        }

        $dolarTexto = $_POST['valor_dolar'] ?? '';
        $dolar = $dolarTexto === '' ? null : numeroCsv($dolarTexto);
        if ($dolarTexto !== '' && ($dolar === null || $dolar < 0)) {
            redirigirPrecios('El valor del dólar no es válido.', 'error');
        }

        $nombreLista = normalizarTexto($_POST['nombre_lista'] ?? '');
        if ($nombreLista === '') {
            $nombreLista = 'Lista ' . $vigenteDesde;
        }
        $observaciones = normalizarTexto($_POST['observaciones'] ?? '');

        $stmtHash = $conexion->prepare("SELECT lista_id FROM listas_precios_importaciones WHERE lista_hash_archivo = ? AND lista_estado <> 'ANULADA' LIMIT 1");
        if (!$stmtHash) {
            redirigirPrecios('No se pudo verificar el archivo: ' . $conexion->error, 'error');
        }
        $stmtHash->bind_param('s', $preview['hash']);
        $stmtHash->execute();
        $yaImportada = $stmtHash->get_result()->fetch_assoc();
        $stmtHash->close();
        if ($yaImportada) {
            redirigirPrecios('Este mismo archivo ya fue importado anteriormente y esa importación no está anulada.', 'error');
        }


        $registrosImportar = $preview['registros'];
        $faltantesPreview = $preview['faltantes'] ?? array();
        $accionesFaltantes = isset($_POST['faltante_accion']) && is_array($_POST['faltante_accion']) ? $_POST['faltante_accion'] : array();
        $preciosFaltantes = isset($_POST['faltante_precio']) && is_array($_POST['faltante_precio']) ? $_POST['faltante_precio'] : array();
        foreach ($faltantesPreview as $codigoFaltante => $artFaltante) {
            $accionFaltante = $accionesFaltantes[$codigoFaltante] ?? 'mantener';
            if ($accionFaltante === 'omitir') continue;
            $precioFaltante = (float)$artFaltante['precios_costo'];
            if ($accionFaltante === 'manual') {
                $precioManual = numeroCsv($preciosFaltantes[$codigoFaltante] ?? '');
                if ($precioManual === null || $precioManual <= 0) redirigirPrecios('Debe ingresar un precio manual válido para ' . $codigoFaltante . '.', 'error');
                $precioFaltante = (float)$precioManual;
            }
            $registrosImportar[] = array(
                'codigo' => $codigoFaltante,
                'descripcion' => $artFaltante['precios_descripcion'],
                'precio' => $precioFaltante,
                'clasificacion_forzada' => $artFaltante['precios_clasificacion'] ?: 'SIN_CLASIFICAR'
            );
        }

        $conexion->begin_transaction();
        try {
            $usuario = $_SESSION['usuario_nombre'] ?? null;
            $cero = 0;
            $validos = count($registrosImportar);
            $descartados = (int)$preview['descartados'];

            if (!$conexion->query("UPDATE listas_precios_importaciones SET lista_estado='REEMPLAZADA', lista_fecha_estado=NOW() WHERE lista_estado='VIGENTE'")) {
                throw new Exception($conexion->error);
            }

            $stmtLista = $conexion->prepare("INSERT INTO listas_precios_importaciones
                (lista_nombre, lista_fecha_archivo, lista_vigente_desde, lista_valor_dolar,
                 lista_archivo_origen, lista_hash_archivo, lista_usuario,
                 lista_registros_validos, lista_registros_nuevos, lista_registros_actualizados,
                 lista_registros_descartados, lista_observaciones, lista_estado, lista_fecha_estado)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'VIGENTE', NOW())");
            if (!$stmtLista) {
                throw new Exception($conexion->error);
            }
            $stmtLista->bind_param('sssdsssiiiis', $nombreLista, $fechaArchivo, $vigenteDesde, $dolar,
                $preview['archivo_original'], $preview['hash'], $usuario,
                $validos, $cero, $cero, $descartados, $observaciones);
            if (!$stmtLista->execute()) {
                throw new Exception($stmtLista->error);
            }
            $listaId = $stmtLista->insert_id;
            $stmtLista->close();

            $stmtExiste = $conexion->prepare('SELECT precios_clasificacion FROM lista_precios WHERE TRIM(precios_codigo) = ? LIMIT 1');
            $stmtActualizar = $conexion->prepare('UPDATE lista_precios SET precios_descripcion = ?, precios_costo = ? WHERE TRIM(precios_codigo) = ? LIMIT 1');
            $stmtInsertar = $conexion->prepare("INSERT INTO lista_precios (precios_codigo, precios_descripcion, precios_costo, precios_clasificacion) VALUES (?, ?, ?, 'SIN_CLASIFICAR')");
            $stmtHistorial = $conexion->prepare('INSERT INTO listas_precios_historial (lista_id, precios_codigo, precios_descripcion, precios_costo, precios_clasificacion) VALUES (?, ?, ?, ?, ?)');
            if (!$stmtExiste || !$stmtActualizar || !$stmtInsertar || !$stmtHistorial) {
                throw new Exception($conexion->error);
            }

            $nuevos = 0;
            $actualizados = 0;

            foreach ($registrosImportar as $r) {
                $codigo = $r['codigo'];
                $descripcion = $r['descripcion'];
                $precio = (float)$r['precio'];

                $stmtExiste->bind_param('s', $codigo);
                $stmtExiste->execute();
                $actual = $stmtExiste->get_result()->fetch_assoc();

                if ($actual) {
                    $clasificacion = $r['clasificacion_forzada'] ?? ($actual['precios_clasificacion'] ?: 'SIN_CLASIFICAR');
                    $stmtActualizar->bind_param('sds', $descripcion, $precio, $codigo);
                    if (!$stmtActualizar->execute()) {
                        throw new Exception($stmtActualizar->error);
                    }
                    $actualizados++;
                } else {
                    $clasificacion = $r['clasificacion_forzada'] ?? 'SIN_CLASIFICAR';
                    $stmtInsertar->bind_param('ssd', $codigo, $descripcion, $precio);
                    if (!$stmtInsertar->execute()) {
                        throw new Exception($stmtInsertar->error);
                    }
                    $nuevos++;
                }

                $stmtHistorial->bind_param('issds', $listaId, $codigo, $descripcion, $precio, $clasificacion);
                if (!$stmtHistorial->execute()) {
                    throw new Exception($stmtHistorial->error);
                }
            }

            $stmtExiste->close();
            $stmtActualizar->close();
            $stmtInsertar->close();
            $stmtHistorial->close();

            $stmtTotales = $conexion->prepare('UPDATE listas_precios_importaciones SET lista_registros_nuevos = ?, lista_registros_actualizados = ? WHERE lista_id = ?');
            if (!$stmtTotales) {
                throw new Exception($conexion->error);
            }
            $stmtTotales->bind_param('iii', $nuevos, $actualizados, $listaId);
            if (!$stmtTotales->execute()) {
                throw new Exception($stmtTotales->error);
            }
            $stmtTotales->close();

            $conexion->commit();
            unset($_SESSION['lista_preview']);
            redirigirPrecios("Lista completa importada correctamente: $validos ítems, $nuevos nuevos y $actualizados actualizados.", 'ok');
        } catch (Throwable $ex) {
            $conexion->rollback();
            redirigirPrecios('La importación fue cancelada sin modificar precios: ' . $ex->getMessage(), 'error');
        }
    }
}

$mensaje = $_SESSION['precios_mensaje'] ?? '';
$tipoMensaje = $_SESSION['precios_tipo'] ?? 'ok';
unset($_SESSION['precios_mensaje'], $_SESSION['precios_tipo']);
$preview = $_SESSION['lista_preview'] ?? null;
$historial = $conexion->query('SELECT * FROM listas_precios_importaciones ORDER BY lista_vigente_desde DESC, lista_id DESC LIMIT 100');
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administrar lista de precios</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f4f9;color:#202124;margin:18px}.contenedor{max-width:1350px;margin:auto}.menu-principal{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.menu-principal a{padding:9px 13px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;background:#343a40;color:#fff}.menu-principal a.activo{background:#0d6efd}.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06)}h1,h2{margin-top:0}.mensaje{padding:11px;border-radius:5px;margin-bottom:14px}.mensaje.ok{background:#d1e7dd;color:#0f5132}.mensaje.error{background:#f8d7da;color:#842029}.grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.campo label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px}.campo input,.campo textarea{width:100%;padding:7px;border:1px solid #9aa0a6;border-radius:4px}.ancho{grid-column:span 2}.total{grid-column:1/-1}.acciones{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.boton,button{border:0;border-radius:5px;padding:10px 14px;font-weight:bold;text-decoration:none;cursor:pointer}.azul{background:#0d6efd;color:#fff}.verde{background:#198754;color:#fff}.gris{background:#6c757d;color:#fff}.indicadores{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.indicador{padding:10px 14px;background:#f8f9fa;border:1px solid #ddd;border-radius:6px}.indicador strong{display:block;font-size:20px}.tabla-wrap{overflow:auto;max-height:480px;border:1px solid #ddd}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px;border-bottom:1px solid #ddd;text-align:left;white-space:nowrap}th{background:#212529;color:#fff;position:sticky;top:0}.aviso{background:#fff3cd;border:1px solid #e6c45c;padding:11px;border-radius:5px;margin-bottom:12px}.mini{font-size:12px;color:#5f6368}.error-precio{color:#b02a37;font-weight:bold}.pasos{line-height:1.55}.pasos strong{color:#202124}@media(max-width:800px){.grid{grid-template-columns:1fr 1fr}.ancho{grid-column:span 2}}@media(max-width:520px){.grid{grid-template-columns:1fr}.ancho{grid-column:span 1}}
</style>
<script>
function actualizarPrecioManual(select){const input=select.closest('tr').querySelector('.precio-faltante');input.disabled=select.value!=='manual';if(!input.disabled)input.focus();}
function accionTodosFaltantes(valor){document.querySelectorAll('.accion-faltante').forEach(function(s){s.value=valor;actualizarPrecioManual(s);});}
</script>
</head>
<body><div class="contenedor">
<?php require __DIR__ . '/menu.php'; ?>
<h1>Administrar lista de precios</h1>
<?php if ($mensaje !== ''): ?><div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div><?php endif; ?>

<section class="caja">
<h2>Preparar el archivo recibido</h2>
<div class="pasos">
<strong>En Excel:</strong> abra la lista, elija <em>Archivo → Guardar como</em> y seleccione <strong>CSV UTF-8 (delimitado por comas)</strong>. Excel puede guardarlo con punto y coma según la configuración regional; el sistema detecta ambos formatos automáticamente.
</div>
</section>

<section class="caja">
<h2>1. Analizar CSV</h2>
<p>Se importa <strong>todo ítem</strong> que tenga código, descripción y precio en las columnas A, B y C, sin limitarlo a controles. Se eliminan filas vacías, espacios sobrantes y columnas adicionales. La base no se modifica hasta confirmar la vista previa.</p>
<form method="post" enctype="multipart/form-data" class="grid"><?= automacCsrfInput() ?>
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_precios']) ?>">
<input type="hidden" name="accion" value="analizar">
<div class="campo ancho"><label>Archivo de lista (.csv)</label><input type="file" name="archivo_lista" accept=".csv,text/csv" required></div>
<div class="acciones"><button class="azul" type="submit">Analizar y limpiar</button></div>
</form>
</section>

<?php if ($preview): ?>
<section class="caja" id="confirmar">
<h2>2. Confirmar nueva lista</h2>
<div class="indicadores">
<div class="indicador"><strong><?= count($preview['registros']) ?></strong>Ítems válidos</div>
<div class="indicador"><strong><?= (int)$preview['descartados'] ?></strong>Filas descartadas</div>
<div class="indicador"><strong><?= count($preview['duplicados']) ?></strong>Códigos duplicados</div>
<div class="indicador"><strong><?= e($preview['separador']) ?></strong>Separador detectado</div>
<div class="indicador"><strong><?= count($preview['faltantes'] ?? array()) ?></strong>Faltan respecto de la vigente</div>
<div class="indicador"><strong><?= count($preview['nuevos_comparacion'] ?? array()) ?></strong>Nuevos respecto de la vigente</div>
</div>
<div class="aviso"><strong>Importación completa:</strong> se incorporan controles, térmicos, contactores, puertas, adicionales y cualquier otro artículo que tenga código, descripción y precio. La clasificación EQUIPO / NO_EQUIPO solo sirve para administrar la matriz de controles y no excluye artículos de la lista de precios.</div>
<form method="post" class="grid"><?= automacCsrfInput() ?>
<input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_precios']) ?>">
<input type="hidden" name="accion" value="confirmar">
<div class="campo ancho"><label>Nombre de la lista</label><input type="text" name="nombre_lista" maxlength="150" value="Lista <?= e($preview['fecha_archivo'] ?: date('Y-m-d')) ?>"></div>
<div class="campo"><label>Fecha de la lista</label><input type="date" name="fecha_archivo" value="<?= e($preview['fecha_archivo'] ?: '') ?>"></div>
<div class="campo"><label>Vigente desde *</label><input type="date" name="vigente_desde" value="<?= e($preview['fecha_archivo'] ?: date('Y-m-d')) ?>" required></div>
<div class="campo"><label>Valor del dólar</label><input type="text" name="valor_dolar" value="<?= $preview['valor_dolar'] !== null ? e(number_format($preview['valor_dolar'], 2, ',', '.')) : '' ?>" placeholder="Ej.: 1530,00"></div>
<div class="campo total"><label>Observaciones</label><textarea name="observaciones" rows="2" placeholder="Ej.: Lista recibida por correo; vigencia acordada desde..."></textarea></div>
<?php if (!empty($preview['faltantes'])): ?>
<div class="total aviso">
<strong>Artículos que estaban en la lista vigente y no aparecen en la nueva.</strong>
<div class="acciones" style="margin-top:8px">
<button type="button" class="gris" onclick="accionTodosFaltantes('mantener')">Mantener todos con el último precio</button>
<button type="button" class="azul" onclick="accionTodosFaltantes('manual')">Cargar a mano el precio actual</button>
<button type="button" style="background:#dc3545;color:#fff" onclick="accionTodosFaltantes('omitir')">No incorporar ninguno</button>
</div>
</div>
<div class="total tabla-wrap"><table><thead><tr><th>Código</th><th>Descripción</th><th>Último precio</th><th>Acción</th><th>Precio manual</th></tr></thead><tbody>
<?php foreach ($preview['faltantes'] as $codigoF => $artF): ?>
<tr><td><?= e($codigoF) ?></td><td><?= e($artF['precios_descripcion']) ?></td><td>$<?= number_format((float)$artF['precios_costo'],2,',','.') ?></td><td>
<select class="accion-faltante" name="faltante_accion[<?= e($codigoF) ?>]" onchange="actualizarPrecioManual(this)"><option value="mantener">Mantener último precio</option><option value="manual">Cargar a mano</option><option value="omitir">No incorporar</option></select>
</td><td><input class="precio-faltante" type="text" name="faltante_precio[<?= e($codigoF) ?>]" placeholder="Precio actual" disabled></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>
<div class="acciones"><button class="verde" type="submit">Confirmar e importar</button></div>
</form>
<form method="post" style="margin-top:8px"><?= automacCsrfInput() ?><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_precios']) ?>"><input type="hidden" name="accion" value="cancelar_preview"><button class="gris" type="submit">Cancelar vista previa</button></form>
<h3>Vista previa de los primeros 100 registros</h3>
<div class="tabla-wrap"><table><thead><tr><th>Código</th><th>Descripción limpia</th><th>Precio</th><th>Observación</th></tr></thead><tbody>
<?php foreach (array_slice($preview['registros'], 0, 100) as $r): ?>
<tr><td><?= e($r['codigo']) ?></td><td><?= e($r['descripcion']) ?></td><td>$<?= number_format((float)$r['precio'], 2, ',', '.') ?></td><td>Se importará</td></tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if ($preview['duplicados']): ?><p class="mini">Duplicados detectados; se conserva la última aparición: <?= e(implode(', ', array_slice($preview['duplicados'], 0, 30))) ?></p><?php endif; ?>
</section>
<?php endif; ?>

<section class="caja">
<h2>Historial de importaciones</h2>
<div class="tabla-wrap"><table><thead><tr><th>ID</th><th>Nombre</th><th>Vigente desde</th><th>Fecha de la lista</th><th>Dólar</th><th>Archivo</th><th>Válidos</th><th>Nuevos</th><th>Actualizados</th><th>Descartados</th><th>Importado</th><th>Estado</th><th>Acción</th></tr></thead><tbody>
<?php if ($historial && $historial->num_rows): while($h=$historial->fetch_assoc()): ?>
<tr><td><?= (int)$h['lista_id'] ?></td><td><?= e($h['lista_nombre']) ?></td><td><?= e($h['lista_vigente_desde']) ?></td><td><?= e($h['lista_fecha_archivo']) ?></td><td><?= $h['lista_valor_dolar'] !== null ? '$'.number_format((float)$h['lista_valor_dolar'],2,',','.') : '-' ?></td><td><?= e($h['lista_archivo_origen']) ?></td><td><?= (int)$h['lista_registros_validos'] ?></td><td><?= (int)$h['lista_registros_nuevos'] ?></td><td><?= (int)$h['lista_registros_actualizados'] ?></td><td><?= (int)$h['lista_registros_descartados'] ?></td><td><?= e($h['lista_fecha_importacion']) ?></td><td><strong><?= e($h['lista_estado'] ?? 'VIGENTE') ?></strong></td><td><form method="post" style="display:flex;gap:4px"><?= automacCsrfInput() ?><input type="hidden" name="csrf" value="<?= e($_SESSION['csrf_precios']) ?>"><input type="hidden" name="accion" value="cambiar_estado_lista"><input type="hidden" name="lista_id" value="<?= (int)$h['lista_id'] ?>"><select name="lista_estado"><option>VIGENTE</option><option>REEMPLAZADA</option><option>ANULADA</option></select><input type="text" name="motivo" placeholder="Motivo" style="width:130px"><button class="azul" type="submit">Aplicar</button></form></td></tr>
<?php endwhile; else: ?><tr><td colspan="13">Todavía no se importaron listas.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
</div></body></html>
