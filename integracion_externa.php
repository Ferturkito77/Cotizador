<?php
require_once __DIR__ . '/schema_guard.php';

function ieTablaConfiguracionExiste(mysqli $conexion): bool
{
    return esquemaTablaExiste($conexion, 'configuracion_sistema');
}

function ieConfigGet(mysqli $conexion, string $clave, string $default = ''): string
{
    if (!ieTablaConfiguracionExiste($conexion)) return $default;
    $st = $conexion->prepare('SELECT config_valor FROM configuracion_sistema WHERE config_clave=? LIMIT 1');
    if (!$st) return $default;
    $st->bind_param('s', $clave);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ? (string)$fila['config_valor'] : $default;
}

function ieConfigSet(mysqli $conexion, string $clave, string $valor, string $descripcion): void
{
    if (!ieTablaConfiguracionExiste($conexion)) {
        throw new RuntimeException('Falta la tabla configuracion_sistema.');
    }
    $st = $conexion->prepare("INSERT INTO configuracion_sistema(config_clave,config_valor,config_descripcion,config_actualizado) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE config_valor=VALUES(config_valor),config_descripcion=VALUES(config_descripcion),config_actualizado=NOW()");
    if (!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('sss', $clave, $valor, $descripcion);
    if (!$st->execute()) throw new RuntimeException($st->error);
    $st->close();
}

function ieConfig(mysqli $conexion): array
{
    return array(
        'activo' => strtoupper(ieConfigGet($conexion, 'integracion_externa_activa', 'SI')) === 'SI',
        'ruta' => trim(ieConfigGet($conexion, 'integracion_externa_ruta', '')),
        'plantilla_obra' => ieConfigGet($conexion, 'integracion_externa_nombre_obra', '#{numero} - {cliente}.html'),
        'plantilla_pedido' => ieConfigGet($conexion, 'integracion_externa_nombre_pedido', 'P.{numero} - {cliente}.html'),
        'sobrescribir' => strtoupper(ieConfigGet($conexion, 'integracion_externa_sobrescribir', 'SI')) === 'SI',
    );
}

function ieNombreSeguro(string $valor): string
{
    $valor = preg_replace('/[\\\\\/:*?"<>|]+/u', '-', $valor);
    $valor = preg_replace('/\s+/u', ' ', trim((string)$valor));
    $valor = trim($valor, " .\t\n\r\0\x0B");
    return $valor !== '' ? $valor : 'Sin nombre';
}

function ieNumeroSolo(string $documento): string
{
    $digitos = preg_replace('/\D+/', '', $documento);
    return $digitos !== '' ? (string)((int)$digitos) : '';
}

function ieNombreArchivo(array $config, string $tipoPedido, string $documento, string $cliente, string $sigla): string
{
    $esObra = strtoupper($tipoPedido) === 'OBRA';
    $tpl = $esObra ? (string)$config['plantilla_obra'] : (string)$config['plantilla_pedido'];
    $numero = ieNumeroSolo($documento);
    $reemplazos = array(
        '{numero}' => $numero,
        '{documento}' => $documento,
        '{cliente}' => ieNombreSeguro($cliente),
        '{sigla}' => ieNombreSeguro($sigla),
        '{tipo}' => $esObra ? 'OBRA' : 'PEDIDO',
    );
    $nombre = strtr($tpl, $reemplazos);
    if (!preg_match('/\.html?$/i', $nombre)) $nombre .= '.html';
    return ieNombreSeguro($nombre);
}

function ieUnirRuta(string $ruta, string $archivo): string
{
    $ruta = rtrim(trim($ruta), "\\/");
    if ($ruta === '') return $archivo;
    $sep = (strpos($ruta, '\\') !== false && strpos($ruta, '/') === false) ? '\\' : DIRECTORY_SEPARATOR;
    return $ruta . $sep . $archivo;
}

function ieValorTabla(mysqli $conexion, string $tabla, string $campoId, $id, string $campoNombre): string
{
    $id = (int)$id;
    if ($id <= 0 || !esquemaTablaExiste($conexion, $tabla)) return '';
    $permitidas = array(
        'cpus' => array('cpu_id','cpu_name'),
        'tipos_control' => array('ctrltipo_id','ctrltipo_name'),
        'subtipos_control' => array('ctrlsubtipo_id','ctrlsubtipo_name'),
    );
    if (!isset($permitidas[$tabla]) || !in_array($campoId, $permitidas[$tabla], true) || !in_array($campoNombre, $permitidas[$tabla], true)) return '';
    $sql = "SELECT `$campoNombre` AS valor FROM `$tabla` WHERE `$campoId`=? LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) return '';
    $st->bind_param('i', $id);
    $st->execute();
    $f = $st->get_result()->fetch_assoc();
    $st->close();
    return $f ? (string)$f['valor'] : '';
}

function ieDatosPedido(mysqli $conexion, int $pedidoId): array
{
    $sql = "SELECT p.*, COALESCE(NULLIF(p.referencia,''),c.referencia,'') AS referencia_integracion,
                   cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,
                   c.cotizacion_numero
            FROM pedidos p
            LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id
            JOIN clientes cl ON cl.clientes_id=p.cliente_id
            WHERE p.pedido_id=? LIMIT 1";
    $st = $conexion->prepare($sql);
    $st->bind_param('i', $pedidoId);
    $st->execute();
    $p = $st->get_result()->fetch_assoc();
    $st->close();
    if (!$p) throw new RuntimeException('Pedido inexistente.');

    $form = json_decode((string)($p['datos_formulario'] ?? ''), true);
    if (!is_array($form)) $form = array();
    $tipoControl = ieValorTabla($conexion, 'tipos_control', 'ctrltipo_id', $form['id_tipo_control'] ?? 0, 'ctrltipo_name');
    $cpu = ieValorTabla($conexion, 'cpus', 'cpu_id', $form['id_cpu'] ?? 0, 'cpu_name');
    $subtipoControl = ieValorTabla($conexion, 'subtipos_control', 'ctrlsubtipo_id', $form['id_subtipo'] ?? 0, 'ctrlsubtipo_name');

    $descripcionControl = '';
    $st = $conexion->prepare("SELECT descripcion FROM pedidos_detalle WHERE pedido_id=? AND UPPER(modulo)='CONTROL' ORDER BY orden_visual LIMIT 1");
    if ($st) {
        $st->bind_param('i', $pedidoId); $st->execute(); $f=$st->get_result()->fetch_assoc(); $st->close();
        if ($f) $descripcionControl = trim((string)$f['descripcion']);
    }
    $maniobra = trim(implode(' ', array_filter(array($tipoControl, $subtipoControl))));
    if ($maniobra === '') $maniobra = $descripcionControl;

    $cliente = trim((string)($p['clientes_nomfantasia'] ?? ''));
    if ($cliente === '') $cliente = trim((string)($p['clientes_razonsocial'] ?? ''));
    if ($cliente === '') $cliente = trim((string)($p['clientes_codigo'] ?? ''));

    return array(
        'pedido' => $p,
        'form' => $form,
        'documento' => (string)($p['pedido_numero'] ?? ''),
        'tipo_pedido' => strtoupper((string)($p['pedido_tipo'] ?? 'OBRA')),
        'sigla' => trim((string)($p['clientes_codigo'] ?? '')),
        'cliente' => $cliente,
        'referencia' => trim((string)($p['referencia_integracion'] ?? '')),
        // Para la integración de Obra, "Tipo" es la CPU. El tipo y subtipo técnicos
        // se conservan por separado para construir la maniobra histórica.
        'tipo_control' => $cpu,
        'subtipo' => $tipoControl,
        'subtipo_control' => $subtipoControl,
        'maniobra' => $maniobra,
        'descripcion_control' => $descripcionControl,
        'fecha_ingreso' => !empty($p['fecha_creacion']) ? date('Y-m-d', strtotime((string)$p['fecha_creacion'])) : date('Y-m-d'),
    );
}

function ieHtmlDocumento(string $tipoPedido, string $documento, array $campos): string
{
    $e = static function($v){ return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
    $esObra = strtoupper($tipoPedido) === 'OBRA';
    $titulo = $esObra ? 'Datos de obra' : 'Datos de pedido';
    $etiquetasObra = array(
        'sigla'=>'Sigla','cliente'=>'Cliente','tipo'=>'Tipo','maniobra'=>'Maniobra','direccion'=>'Dirección',
        'ingreso'=>'Ingreso','necesario'=>'Necesario / Fecha de entrega','fecha_entrega'=>'Fecha de entrega',
        'estado_control'=>'Estado Control','clase'=>'Clase','estado_senalizacion'=>'Estado Señalización','estado_iep'=>'Estado IEP','notas'=>'Notas','faltan_datos'=>'Faltan Datos','usuario'=>'Usuario'
    );
    $etiquetasPedido = array(
        'sigla_cliente'=>'Sigla Cliente','cliente'=>'Cliente','referencia'=>'Referencia','equivalentes'=>'Equivalentes',
        'fecha_entrega'=>'Fecha de entrega',
        'estado_senalizacion'=>'Estado Señalización','tipo_orden'=>'Tipo de orden','faltan_datos'=>'Faltan Datos','notas'=>'Notas','usuario'=>'Usuario'
    );
    $etiquetas = $esObra ? $etiquetasObra : $etiquetasPedido;
    unset($campos['subtipo'], $campos['forma_pago_codigo'], $campos['forma_pago_descripcion']);
    $payload = array('tipo'=>$esObra?'OBRA':'PEDIDO','documento'=>$documento) + $campos;
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    $jsonSeguro = str_ireplace('</script', '<\/script', (string)$json);
    $filas='';
    foreach($etiquetas as $clave=>$etiqueta){
        $valor=$campos[$clave]??'';
        $filas.='<tr><th>'.$e($etiqueta).'</th><td id="'.$e($clave).'" data-automac-field="'.$e($clave).'">'.$e($valor).'</td></tr>';
    }
    return "<!doctype html>\n"
        . "<html lang=\"es\">\n"
        . "<head>\n"
        . "<meta charset=\"utf-8\">\n"
        . "<meta name=\"generator\" content=\"Cotizador Automac\">\n"
        . '<meta name="automac-tipo" content="'.$e($esObra?'OBRA':'PEDIDO').'">' . "\n"
        . '<meta name="automac-documento" content="'.$e($documento).'">' . "\n"
        . '<title>'.$e($documento.' - '.$titulo).'</title>' . "\n"
        . "<style>body{font-family:Arial,sans-serif;color:#1f2937;margin:28px}.cab{margin-bottom:18px}.cab h1{font-size:22px;margin:0 0 6px}.cab p{margin:0;color:#64748b}table{border-collapse:collapse;width:100%;max-width:900px}th,td{border:1px solid #d8dee6;padding:9px 11px;text-align:left;vertical-align:top}th{width:230px;background:#f3f6f9}td{white-space:pre-wrap}</style>\n"
        . "</head>\n<body>\n"
        . '<div class="cab"><h1>'.$e($documento).' · '.$e($titulo).'</h1><p>Archivo de intercambio generado por Cotizador Automac</p></div>' . "\n"
        . '<table data-automac-documento="'.$e($documento).'">'.$filas.'</table>' . "\n"
        . '<script type="application/json" id="automac-data">'.$jsonSeguro.'</script>' . "\n"
        . "</body>\n</html>\n";
}

function ieGuardarHtml(mysqli $conexion, int $pedidoId, array $campos): array
{
    $datos = ieDatosPedido($conexion, $pedidoId);
    $cfg = ieConfig($conexion);
    if (!$cfg['activo']) throw new RuntimeException('La integración externa está desactivada en Administración.');
    if (trim($cfg['ruta']) === '') throw new RuntimeException('No está configurada la carpeta de salida de la integración externa.');
    if (!is_dir($cfg['ruta'])) throw new RuntimeException('La carpeta configurada no existe o PHP no puede acceder a ella: '.$cfg['ruta']);
    if (!is_writable($cfg['ruta'])) throw new RuntimeException('La carpeta configurada no tiene permiso de escritura para PHP/Apache: '.$cfg['ruta']);

    // Auditoría del archivo de intercambio: registrar el usuario que efectúa el pase a Producción.
    // Se agrega tanto al HTML visible como al JSON embebido para OBRA (#) y PEDIDO (P.).
    $usuarioIntercambio = '';
    if (function_exists('usuarioActual')) {
        $u = usuarioActual();
        if (is_array($u)) {
            $usuarioIntercambio = trim((string)($u['nombre'] ?? ''));
            if ($usuarioIntercambio === '') $usuarioIntercambio = trim((string)($u['login'] ?? ''));
        }
    }
    if ($usuarioIntercambio === '' && session_status() === PHP_SESSION_ACTIVE) {
        $usuarioIntercambio = trim((string)($_SESSION['usuario_nombre'] ?? ''));
        if ($usuarioIntercambio === '') $usuarioIntercambio = trim((string)($_SESSION['usuario_login'] ?? ''));
    }
    $campos['usuario'] = $usuarioIntercambio !== '' ? $usuarioIntercambio : 'NO IDENTIFICADO';

    $archivo = ieNombreArchivo($cfg, $datos['tipo_pedido'], $datos['documento'], $datos['cliente'], $datos['sigla']);
    $destino = ieUnirRuta($cfg['ruta'], $archivo);
    if (file_exists($destino) && !$cfg['sobrescribir']) throw new RuntimeException('El archivo ya existe y la configuración no permite sobrescribirlo: '.$archivo);
    $html = ieHtmlDocumento($datos['tipo_pedido'], $datos['documento'], $campos);
    $tmp = $destino . '.tmp-' . getmypid() . '-' . bin2hex(random_bytes(3));
    if (@file_put_contents($tmp, $html, LOCK_EX) === false) throw new RuntimeException('No se pudo escribir el archivo temporal en la carpeta configurada.');
    if (file_exists($destino) && $cfg['sobrescribir']) @unlink($destino);
    if (!@rename($tmp, $destino)) { @unlink($tmp); throw new RuntimeException('No se pudo publicar el archivo HTML definitivo.'); }
    return array('archivo'=>$archivo,'destino'=>$destino,'bytes'=>filesize($destino) ?: strlen($html));
}
