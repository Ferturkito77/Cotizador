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


/* La clasificación forma parte del esquema versionado; no se altera la base desde PHP. */
require_once __DIR__ . '/schema_guard.php';
try {
    verificarTablaColumnas($conexion, 'lista_precios', array('precios_codigo','precios_descripcion','precios_costo','precios_clasificacion'), 'migracion_consolidacion_v59.sql');
    verificarTablaColumnas($conexion, 'subtipos_control', array('ctrlsubtipo_id','ctrlsubtipo_name','velocidad_max_mmin','habilita_mayor_75'), 'migracion_v96_compatibilidad_variadores.sql');
    verificarTablaColumnas($conexion, 'cpus', array('cpu_id','cpu_name','velocidad_max_mmin','admite_encoder','cpu_matriz_base_id'), 'migracion_v117_clex_dangelica_matriz_base.sql');
} catch (Throwable $e) {
    die('El administrador de cálculos requiere actualizar la base: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

function e($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function redirigir($mensaje, $tipo = 'ok', $ancla = '') {
    $_SESSION['matriz_mensaje'] = $mensaje;
    $_SESSION['matriz_tipo'] = $tipo;

    $destino = 'administrar_matriz.php';
    if ($ancla !== '') {
        $destino .= '#' . ltrim($ancla, '#');
    }

    header('Location: ' . $destino);
    exit;
}

function enteroPost($nombre, $obligatorio = true) {
    $valor = filter_input(INPUT_POST, $nombre, FILTER_VALIDATE_INT);
    if ($valor === false || $valor === null) {
        return $obligatorio ? 0 : null;
    }
    return (int)$valor;
}

function decimalPost($nombre, $obligatorio = true) {
    if (!isset($_POST[$nombre]) || trim($_POST[$nombre]) === '') {
        return $obligatorio ? false : null;
    }
    $valor = str_replace(',', '.', trim($_POST[$nombre]));
    return is_numeric($valor) ? (float)$valor : false;
}

/* Crear o editar tipos de control. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_tipo') {
    $nombre = trim($_POST['nuevo_tipo'] ?? '');
    if ($nombre === '') redirigir('Ingrese el nombre del nuevo tipo.', 'error', 'tipos');
    $stmt=$conexion->prepare('INSERT INTO tipos_control (ctrltipo_name) VALUES (?)');
    if(!$stmt) redirigir('No se pudo preparar el alta del tipo: '.$conexion->error,'error','tipos');
    $stmt->bind_param('s',$nombre);
    if(!$stmt->execute()){ $err=$stmt->errno===1062?'Ese tipo ya existe.':$stmt->error; $stmt->close(); redirigir($err,'error','tipos'); }
    $stmt->close(); redirigir('Tipo creado correctamente: '.$nombre,'ok','tipos');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_tipo') {
    $id=enteroPost('ctrltipo_id'); $nombre=trim($_POST['ctrltipo_name'] ?? '');
    if(!$id || $nombre==='') redirigir('Seleccione un tipo e ingrese un nombre válido.','error','tipos');
    $stmt=$conexion->prepare('UPDATE tipos_control SET ctrltipo_name=? WHERE ctrltipo_id=? LIMIT 1');
    if(!$stmt) redirigir('No se pudo preparar la modificación del tipo: '.$conexion->error,'error','tipos');
    $stmt->bind_param('si',$nombre,$id);
    if(!$stmt->execute()){ $err=$stmt->error; $stmt->close(); redirigir('No se pudo modificar el tipo: '.$err,'error','tipos'); }
    $stmt->close(); redirigir('Tipo actualizado correctamente: '.$nombre,'ok','tipos');
}

/* Crear un nuevo subtipo. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'crear_subtipo') {
    $nombre = isset($_POST['nuevo_subtipo']) ? trim($_POST['nuevo_subtipo']) : '';
    if ($nombre === '') {
        redirigir('Ingrese el nombre del nuevo subtipo.', 'error');
    }

    $stmt = $conexion->prepare('INSERT INTO subtipos_control (ctrlsubtipo_name) VALUES (?)');
    if (!$stmt) {
        redirigir('No se pudo preparar el alta del subtipo: ' . $conexion->error, 'error');
    }
    $stmt->bind_param('s', $nombre);
    if (!$stmt->execute()) {
        $error = $stmt->errno === 1062 ? 'Ese subtipo ya existe.' : $stmt->error;
        $stmt->close();
        redirigir($error, 'error');
    }
    $stmt->close();
    redirigir('Subtipo creado correctamente: ' . $nombre, 'ok', 'subtipos');
}

/* Editar el nombre de un subtipo existente. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_subtipo') {
    $subtipoId = enteroPost('ctrlsubtipo_id');
    $nombre = isset($_POST['ctrlsubtipo_name']) ? trim($_POST['ctrlsubtipo_name']) : '';
    $velocidadMax = decimalPost('velocidad_max_mmin', false);
    $habilitaMayor75 = isset($_POST['habilita_mayor_75']) && $_POST['habilita_mayor_75'] === 'SI' ? 'SI' : 'NO';

    if ($velocidadMax === false || ($velocidadMax !== null && $velocidadMax <= 0)) redirigir('La velocidad máxima debe quedar vacía o ser mayor que cero.', 'error', 'subtipos');
    if (!$subtipoId || $nombre === '') {
        redirigir('Seleccione un subtipo e ingrese un nombre válido.', 'error', 'subtipos');
    }

    $stmt = $conexion->prepare('SELECT ctrlsubtipo_id FROM subtipos_control WHERE ctrlsubtipo_name = ? AND ctrlsubtipo_id <> ? LIMIT 1');
    if (!$stmt) {
        redirigir('No se pudo verificar el nombre del subtipo: ' . $conexion->error, 'error', 'subtipos');
    }
    $stmt->bind_param('si', $nombre, $subtipoId);
    $stmt->execute();
    $duplicado = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if ($duplicado) {
        redirigir('Ya existe otro subtipo con ese nombre.', 'error', 'subtipos');
    }

    $stmt = $conexion->prepare('UPDATE subtipos_control SET ctrlsubtipo_name = ?, velocidad_max_mmin = ?, habilita_mayor_75 = ? WHERE ctrlsubtipo_id = ? LIMIT 1');
    if (!$stmt) {
        redirigir('No se pudo preparar la modificación del subtipo: ' . $conexion->error, 'error', 'subtipos');
    }
    $stmt->bind_param('sdsi', $nombre, $velocidadMax, $habilitaMayor75, $subtipoId);
    if (!$stmt->execute()) {
        $error = $stmt->errno === 1062 ? 'Ya existe otro subtipo con ese nombre.' : $stmt->error;
        $stmt->close();
        redirigir('No se pudo modificar el subtipo: ' . $error, 'error', 'subtipos');
    }
    $stmt->close();
    redirigir('Subtipo actualizado correctamente: ' . $nombre, 'ok', 'subtipos');
}

/* Editar compatibilidad técnica de una CPU. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'editar_cpu_compat') {
    $cpuId = enteroPost('cpu_id');
    $velocidadMax = decimalPost('velocidad_max_mmin', false);
    $admiteEncoder = isset($_POST['admite_encoder']) && in_array($_POST['admite_encoder'], array('SI','NO',''), true) ? $_POST['admite_encoder'] : '';
    if (!$cpuId) redirigir('Seleccione una CPU válida.', 'error', 'cpus');
    if ($velocidadMax === false || ($velocidadMax !== null && $velocidadMax <= 0)) redirigir('La velocidad máxima debe quedar vacía o ser mayor que cero.', 'error', 'cpus');
    $admiteEncoderDb = $admiteEncoder === '' ? null : $admiteEncoder;
    $matrizBaseId = enteroPost('cpu_matriz_base_id');
    if (!$matrizBaseId) $matrizBaseId = $cpuId;
    $stmtBase = $conexion->prepare('SELECT cpu_id FROM cpus WHERE cpu_id=? LIMIT 1');
    if (!$stmtBase) redirigir('No se pudo validar la matriz base de CPU: '.$conexion->error, 'error', 'cpus');
    $stmtBase->bind_param('i',$matrizBaseId); $stmtBase->execute(); $baseValida=$stmtBase->get_result()->fetch_assoc(); $stmtBase->close();
    if (!$baseValida) redirigir('La CPU elegida como matriz base no existe.', 'error', 'cpus');
    $stmt = $conexion->prepare('UPDATE cpus SET velocidad_max_mmin = ?, admite_encoder = ?, cpu_matriz_base_id = ? WHERE cpu_id = ? LIMIT 1');
    if (!$stmt) redirigir('No se pudo preparar la compatibilidad de CPU: '.$conexion->error, 'error', 'cpus');
    $stmt->bind_param('dsii', $velocidadMax, $admiteEncoderDb, $matrizBaseId, $cpuId);
    if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); redirigir('No se pudo actualizar la CPU: '.$err, 'error', 'cpus'); }
    $stmt->close();
    redirigir('Compatibilidad de CPU actualizada.', 'ok', 'cpus');
}

/* Clasificar un artículo de base Bejerman vigente. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'clasificar_precio') {
    $codigoClasificar = isset($_POST['precios_codigo']) ? trim($_POST['precios_codigo']) : '';
    $clasificacion = isset($_POST['precios_clasificacion']) ? trim($_POST['precios_clasificacion']) : '';
    $permitidas = array('SIN_CLASIFICAR', 'EQUIPO', 'NO_EQUIPO');

    if ($codigoClasificar === '' || !in_array($clasificacion, $permitidas, true)) {
        redirigir('No se recibió una clasificación válida.', 'error', 'sin-clasificar');
    }

    $stmt = $conexion->prepare('UPDATE lista_precios SET precios_clasificacion = ? WHERE precios_codigo = ? LIMIT 1');
    if (!$stmt) {
        redirigir('No se pudo preparar la clasificación: ' . $conexion->error, 'error', 'sin-clasificar');
    }
    $stmt->bind_param('ss', $clasificacion, $codigoClasificar);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        redirigir('No se pudo guardar la clasificación: ' . $error, 'error', 'sin-clasificar');
    }
    $stmt->close();

    $texto = $clasificacion === 'EQUIPO' ? 'marcado como EQUIPO' : ($clasificacion === 'NO_EQUIPO' ? 'marcado como NO EQUIPO' : 'devuelto a SIN CLASIFICAR');

    /* Mantener abierta la sección desde la que se realizó la corrección. */
    $origenClasificacion = isset($_POST['origen_clasificacion']) ? trim($_POST['origen_clasificacion']) : 'sin-clasificar';
    if ($origenClasificacion === 'corregir-clasificaciones') {
        $_SESSION['matriz_mensaje'] = $codigoClasificar . ' fue ' . $texto . '.';
        $_SESSION['matriz_tipo'] = 'ok';

        $estadoRetorno = isset($_POST['estado_clasificados']) && in_array($_POST['estado_clasificados'], array('EQUIPO', 'NO_EQUIPO'), true)
            ? $_POST['estado_clasificados'] : 'EQUIPO';
        $filtroRetorno = isset($_POST['f_clasificados']) ? trim($_POST['f_clasificados']) : '';

        $destino = 'administrar_matriz.php?estado_clasificados=' . urlencode($estadoRetorno);
        if ($filtroRetorno !== '') {
            $destino .= '&f_clasificados=' . urlencode($filtroRetorno);
        }
        $destino .= '#corregir-clasificaciones';
        header('Location: ' . $destino);
        exit;
    }

    redirigir($codigoClasificar . ' fue ' . $texto . '.', 'ok', 'sin-clasificar');
}

/* Eliminar una configuración de la matriz. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && $_POST['accion'] === 'eliminar') {
    $controlId = enteroPost('control_id');
    if (!$controlId) {
        redirigir('No se recibió una configuración válida para eliminar.', 'error');
    }

    $stmt = $conexion->prepare('SELECT control_codigo FROM matriz_calculos WHERE control_id = ? LIMIT 1');
    if (!$stmt) {
        redirigir('No se pudo preparar la verificación de la configuración: ' . $conexion->error, 'error');
    }
    $stmt->bind_param('i', $controlId);
    $stmt->execute();
    $filaEliminar = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$filaEliminar) {
        redirigir('La configuración que intentás eliminar ya no existe.', 'error');
    }

    $stmt = $conexion->prepare('DELETE FROM matriz_calculos WHERE control_id = ? LIMIT 1');
    if (!$stmt) {
        redirigir('No se pudo preparar la eliminación: ' . $conexion->error, 'error');
    }
    $stmt->bind_param('i', $controlId);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        redirigir('No se pudo eliminar la configuración: ' . $error, 'error');
    }
    $stmt->close();
    redirigir('Configuración eliminada correctamente: ' . $filaEliminar['control_codigo']);
}

/* Alta o edición de una configuración. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accion']) && in_array($_POST['accion'], array('guardar', 'actualizar'), true)) {
    $accion = $_POST['accion'];
    $controlId = enteroPost('control_id', false);
    $cpu = enteroPost('control_cpu');
    $tipo = enteroPost('control_tipo');
    $subtipo = enteroPost('control_subtipo');
    $tension = enteroPost('control_tension');
    $encoder = isset($_POST['control_encoder']) && $_POST['control_encoder'] === 'SI' ? 'SI' : '';
    $potDesde = decimalPost('control_potenciadesde');
    $potHasta = decimalPost('control_potenciahasta');
    $corriente = decimalPost('control_corriente');
    $contactorPot = enteroPost('control_contactorpot', false);
    $contactor = enteroPost('control_contactor', false);
    $termico = enteroPost('control_termicos', false);
    $codigo = isset($_POST['control_codigo']) ? trim($_POST['control_codigo']) : '';
    $precio = decimalPost('control_precio', false);
    $precio = $precio === null ? 0.0 : $precio;

    if (!$cpu || !$tipo || !$subtipo || !$tension || $potDesde === false || $potHasta === false || $corriente === false || $codigo === '') {
        redirigir('Complete todos los datos obligatorios de la configuración.', 'error');
    }
    if ($potDesde < 0 || $potHasta <= $potDesde) {
        redirigir('El rango de potencia es inválido: HP hasta debe ser mayor que HP desde.', 'error');
    }
    if ($corriente < 0 || $precio < 0) {
        redirigir('La corriente y el precio no pueden ser negativos.', 'error');
    }

    /* El código comercial debe existir. */
    $stmt = $conexion->prepare('SELECT precios_codigo FROM lista_precios WHERE precios_codigo = ? LIMIT 1');
    $stmt->bind_param('s', $codigo);
    $stmt->execute();
    $existeCodigo = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$existeCodigo) {
        redirigir('El código ' . $codigo . ' no existe en lista_precios.', 'error');
    }

    /* Evitar una combinación exactamente duplicada. */
    $sqlDuplicado = "SELECT control_id FROM matriz_calculos
                     WHERE control_cpu = ?
                       AND control_tipo = ?
                       AND control_subtipo = ?
                       AND control_tension = ?
                       AND COALESCE(NULLIF(TRIM(control_encoder), ''), '') = ?
                       AND control_potenciadesde = ?
                       AND control_potenciahasta = ?";
    if ($accion === 'actualizar') {
        $sqlDuplicado .= ' AND control_id <> ?';
    }
    $sqlDuplicado .= ' LIMIT 1';
    $stmt = $conexion->prepare($sqlDuplicado);
    if ($accion === 'actualizar') {
        $stmt->bind_param('iiiisddi', $cpu, $tipo, $subtipo, $tension, $encoder, $potDesde, $potHasta, $controlId);
    } else {
        $stmt->bind_param('iiiisdd', $cpu, $tipo, $subtipo, $tension, $encoder, $potDesde, $potHasta);
    }
    $stmt->execute();
    $duplicado = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if ($duplicado) {
        redirigir('Ya existe una configuración con la misma CPU, tipo, subtipo, tensión, encoder y rango de potencia.', 'error');
    }

    $contactorPot = $contactorPot ?: null;
    $contactor = $contactor ?: null;
    $termico = $termico ?: null;
    $encoderDb = $encoder === 'SI' ? 'SI' : null;

    if ($accion === 'actualizar') {
        if (!$controlId) {
            redirigir('No se recibió el ID de la configuración a actualizar.', 'error');
        }
        $sql = "UPDATE matriz_calculos SET
                    control_cpu = ?, control_tipo = ?, control_subtipo = ?, control_tension = ?,
                    control_encoder = ?, control_potenciadesde = ?, control_potenciahasta = ?,
                    control_corriente = ?, control_contactorpot = ?, control_contactor = ?,
                    control_termicos = ?, control_codigo = ?, control_precio = ?
                WHERE control_id = ?";
        $stmt = $conexion->prepare($sql);
        if (!$stmt) redirigir('No se pudo preparar la actualización: ' . $conexion->error, 'error');
        $stmt->bind_param('iiiisdddiiisdi', $cpu, $tipo, $subtipo, $tension, $encoderDb, $potDesde, $potHasta, $corriente, $contactorPot, $contactor, $termico, $codigo, $precio, $controlId);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            redirigir('No se pudo actualizar: ' . $error, 'error');
        }
        $stmt->close();
        redirigir('Configuración actualizada correctamente.');
    }

    $sql = "INSERT INTO matriz_calculos
            (control_cpu, control_tipo, control_subtipo, control_tension, control_encoder,
             control_potenciadesde, control_potenciahasta, control_corriente,
             control_contactorpot, control_contactor, control_termicos,
             control_codigo, control_precio)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
    $stmt = $conexion->prepare($sql);
    if (!$stmt) redirigir('No se pudo preparar el alta: ' . $conexion->error, 'error');
    $stmt->bind_param('iiiisdddiiisd', $cpu, $tipo, $subtipo, $tension, $encoderDb, $potDesde, $potHasta, $corriente, $contactorPot, $contactor, $termico, $codigo, $precio);
    if (!$stmt->execute()) {
        $error = $stmt->error;
        $stmt->close();
        redirigir('No se pudo guardar: ' . $error, 'error');
    }
    $nuevoId = $stmt->insert_id;
    $stmt->close();
    redirigir('Configuración agregada correctamente. ID: ' . $nuevoId);
}

$mensaje = isset($_SESSION['matriz_mensaje']) ? $_SESSION['matriz_mensaje'] : '';
$tipoMensaje = isset($_SESSION['matriz_tipo']) ? $_SESSION['matriz_tipo'] : 'ok';
unset($_SESSION['matriz_mensaje'], $_SESSION['matriz_tipo']);

/* Catálogos. */
$cpus = $conexion->query('SELECT cpu_id, cpu_name, velocidad_max_mmin, admite_encoder, cpu_matriz_base_id FROM cpus ORDER BY cpu_name');
$cpusGestion = $conexion->query('SELECT c.cpu_id,c.cpu_name,c.velocidad_max_mmin,c.admite_encoder,c.cpu_matriz_base_id,b.cpu_name AS matriz_base_nombre FROM cpus c LEFT JOIN cpus b ON b.cpu_id=c.cpu_matriz_base_id ORDER BY c.cpu_id');
$tipos = $conexion->query('SELECT ctrltipo_id, ctrltipo_name FROM tipos_control ORDER BY ctrltipo_id');
$tiposGestion = $conexion->query("SELECT tc.ctrltipo_id, tc.ctrltipo_name, COUNT(m.control_id) AS cantidad_usos FROM tipos_control tc LEFT JOIN matriz_calculos m ON m.control_tipo=tc.ctrltipo_id GROUP BY tc.ctrltipo_id,tc.ctrltipo_name ORDER BY tc.ctrltipo_id");
$subtipos = $conexion->query('SELECT ctrlsubtipo_id, ctrlsubtipo_name, velocidad_max_mmin, habilita_mayor_75 FROM subtipos_control ORDER BY ctrlsubtipo_name');
$subtiposGestion = $conexion->query("SELECT sc.ctrlsubtipo_id, sc.ctrlsubtipo_name, sc.velocidad_max_mmin, sc.habilita_mayor_75, COUNT(m.control_id) AS cantidad_usos FROM subtipos_control sc LEFT JOIN matriz_calculos m ON m.control_subtipo = sc.ctrlsubtipo_id GROUP BY sc.ctrlsubtipo_id, sc.ctrlsubtipo_name, sc.velocidad_max_mmin, sc.habilita_mayor_75 ORDER BY sc.ctrlsubtipo_name");
$tensiones = $conexion->query('SELECT tension_id, tension_name FROM tensiones ORDER BY tension_id');
$contactores = $conexion->query('SELECT contactor_id, contactor_name FROM contactores ORDER BY contactor_name');
$contactoresPot = $conexion->query('SELECT contactorpot_id, contactorpot_name, contactorpot_codigo FROM contactorpot ORDER BY contactorpot_name');
$termicos = $conexion->query('SELECT termicos_id, termicos_codigo, termicos_potde, termicos_pothasta FROM termicos ORDER BY termicos_codigo, termicos_potde');

/* Registro a editar. */
$editar = null;
$editarId = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($editarId) {
    $stmt = $conexion->prepare('SELECT * FROM matriz_calculos WHERE control_id = ? LIMIT 1');
    $stmt->bind_param('i', $editarId);
    $stmt->execute();
    $editar = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

/* Duplicar una configuración con precio cero para cargar su reemplazo vigente. */
$duplicarId = filter_input(INPUT_GET, 'duplicar', FILTER_VALIDATE_INT);
$duplicandoReemplazo = false;
$codigoOrigenReemplazo = '';
if (!$editar && $duplicarId) {
    $stmt = $conexion->prepare('SELECT * FROM matriz_calculos WHERE control_id = ? LIMIT 1');
    if ($stmt) {
        $stmt->bind_param('i', $duplicarId);
        $stmt->execute();
        $editar = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($editar) {
            $codigoOrigenReemplazo = (string)$editar['control_codigo'];
            unset($editar['control_id']);
            $editar['control_precio'] = 0;
            $duplicandoReemplazo = true;
        }
    }
}

/* Precargar un código pendiente en el formulario de alta. */
$pendienteCodigo = isset($_GET['pendiente_codigo']) ? trim($_GET['pendiente_codigo']) : '';
if (!$editar && $pendienteCodigo !== '') {
    $stmt = $conexion->prepare("SELECT precios_codigo, precios_descripcion, precios_costo
                                FROM lista_precios
                                WHERE precios_codigo = ?
                                  AND precios_clasificacion = 'EQUIPO'
                                LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('s', $pendienteCodigo);
        $stmt->execute();
        $pendienteSeleccionado = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($pendienteSeleccionado) {
            $editar = array(
                'control_codigo' => $pendienteSeleccionado['precios_codigo'],
                'control_corriente' => 0,
                'control_precio' => 0,
                'precios_descripcion_pendiente' => $pendienteSeleccionado['precios_descripcion'],
                'precios_costo_pendiente' => $pendienteSeleccionado['precios_costo']
            );
            $descripcionPendiente = strtoupper($pendienteSeleccionado['precios_descripcion']);
            if (strpos($descripcionPendiente, 'A6220') !== false) $editar['control_cpu'] = 1;
            elseif (strpos($descripcionPendiente, 'A6300') !== false) $editar['control_cpu'] = 2;
            elseif (strpos($descripcionPendiente, 'A6700') !== false) $editar['control_cpu'] = 3;
        }
    }
}

/* Filtros del listado. */
$fCpu = filter_input(INPUT_GET, 'f_cpu', FILTER_VALIDATE_INT);
$fTipo = filter_input(INPUT_GET, 'f_tipo', FILTER_VALIDATE_INT);
$fSubtipo = filter_input(INPUT_GET, 'f_subtipo', FILTER_VALIDATE_INT);
$fCodigo = isset($_GET['f_codigo']) ? trim($_GET['f_codigo']) : '';
$fCpuMatriz = $fCpu;
$fCpuNombre = '';
$fCpuMatrizNombre = '';
if ($fCpu) {
    $stFiltroCpu = $conexion->prepare('SELECT c.cpu_name,c.cpu_matriz_base_id,b.cpu_name AS matriz_base_nombre FROM cpus c LEFT JOIN cpus b ON b.cpu_id=c.cpu_matriz_base_id WHERE c.cpu_id=? LIMIT 1');
    if ($stFiltroCpu) {
        $stFiltroCpu->bind_param('i',$fCpu); $stFiltroCpu->execute(); $fc=$stFiltroCpu->get_result()->fetch_assoc(); $stFiltroCpu->close();
        if ($fc) { $fCpuNombre=(string)$fc['cpu_name']; $fCpuMatriz=(int)($fc['cpu_matriz_base_id']?:$fCpu); $fCpuMatrizNombre=(string)($fc['matriz_base_nombre']?:$fCpuNombre); }
    }
}

$sqlLista = "SELECT m.*, c.cpu_name, tc.ctrltipo_name, sc.ctrlsubtipo_name, t.tension_name,
                    lp.precios_descripcion, lp.precios_costo,
                    co.contactor_name,
                    cp.contactorpot_name,
                    th.termicos_codigo
             FROM matriz_calculos m
             INNER JOIN cpus c ON c.cpu_id = m.control_cpu
             INNER JOIN tipos_control tc ON tc.ctrltipo_id = m.control_tipo
             INNER JOIN subtipos_control sc ON sc.ctrlsubtipo_id = m.control_subtipo
             INNER JOIN tensiones t ON t.tension_id = m.control_tension
             LEFT JOIN lista_precios lp ON lp.precios_codigo = m.control_codigo
             LEFT JOIN contactores co ON co.contactor_id = m.control_contactor
             LEFT JOIN contactorpot cp ON cp.contactorpot_id = m.control_contactorpot
             LEFT JOIN termicos th ON th.termicos_id = m.control_termicos
             WHERE 1=1";
$tiposBind = '';
$valoresBind = array();
if ($fCpu) { $sqlLista .= ' AND m.control_cpu = ?'; $tiposBind .= 'i'; $valoresBind[] = $fCpuMatriz; }
if ($fTipo) { $sqlLista .= ' AND m.control_tipo = ?'; $tiposBind .= 'i'; $valoresBind[] = $fTipo; }
if ($fSubtipo) { $sqlLista .= ' AND m.control_subtipo = ?'; $tiposBind .= 'i'; $valoresBind[] = $fSubtipo; }
if ($fCodigo !== '') { $sqlLista .= ' AND (m.control_codigo LIKE ? OR lp.precios_descripcion LIKE ?)'; $tiposBind .= 'ss'; $buscar = '%' . $fCodigo . '%'; $valoresBind[] = $buscar; $valoresBind[] = $buscar; }
$sqlLista .= ' ORDER BY c.cpu_name, m.control_tipo, sc.ctrlsubtipo_name, t.tension_name, m.control_potenciadesde LIMIT 1000';
$stmtLista = $conexion->prepare($sqlLista);
if ($tiposBind !== '') {
    $refs = array($tiposBind);
    foreach ($valoresBind as $k => $v) { $refs[] = &$valoresBind[$k]; }
    call_user_func_array(array($stmtLista, 'bind_param'), $refs);
}
$stmtLista->execute();
$listado = $stmtLista->get_result();


/* Equipos presentes en lista_precios pero todavía ausentes en matriz_calculos. */
$sqlPendientes = "SELECT lp.precios_codigo, lp.precios_descripcion, lp.precios_costo
                  FROM lista_precios lp
                  LEFT JOIN matriz_calculos m
                    ON TRIM(m.control_codigo) = TRIM(lp.precios_codigo)
                  WHERE m.control_id IS NULL
                    AND lp.precios_clasificacion = 'EQUIPO'
                    AND COALESCE(lp.precios_costo, 0) > 0
                  ORDER BY lp.precios_descripcion, lp.precios_codigo";
$pendientes = $conexion->query($sqlPendientes);
$cantidadPendientes = $pendientes ? $pendientes->num_rows : 0;

/* Configuraciones existentes cuyo artículo quedó sin precio vigente. */
$sqlPendientesReemplazo = "SELECT m.control_id, m.control_codigo, c.cpu_name,
                                  tc.ctrltipo_name, sc.ctrlsubtipo_name,
                                  lp.precios_descripcion, lp.precios_costo
                           FROM matriz_calculos m
                           INNER JOIN cpus c ON c.cpu_id = m.control_cpu
                           INNER JOIN tipos_control tc ON tc.ctrltipo_id = m.control_tipo
                           INNER JOIN subtipos_control sc ON sc.ctrlsubtipo_id = m.control_subtipo
                           INNER JOIN lista_precios lp
                             ON TRIM(lp.precios_codigo) = TRIM(m.control_codigo)
                           WHERE COALESCE(lp.precios_costo, 0) = 0
                           ORDER BY m.control_codigo, c.cpu_name, sc.ctrlsubtipo_name";
$pendientesReemplazo = $conexion->query($sqlPendientesReemplazo);
$cantidadPendientesReemplazo = $pendientesReemplazo ? $pendientesReemplazo->num_rows : 0;

/* Artículos todavía sin clasificación, con búsqueda opcional. */
$fClasificar = isset($_GET['f_clasificar']) ? trim($_GET['f_clasificar']) : '';
$sqlSinClasificar = "SELECT precios_codigo, precios_descripcion, precios_costo
                     FROM lista_precios
                     WHERE precios_clasificacion = 'SIN_CLASIFICAR'";
$stmtSinClasificar = null;
if ($fClasificar !== '') {
    $sqlSinClasificar .= " AND (precios_codigo LIKE ? OR precios_descripcion LIKE ?)";
    $sqlSinClasificar .= " ORDER BY precios_descripcion, precios_codigo LIMIT 500";
    $stmtSinClasificar = $conexion->prepare($sqlSinClasificar);
    $buscarClasificar = '%' . $fClasificar . '%';
    $stmtSinClasificar->bind_param('ss', $buscarClasificar, $buscarClasificar);
    $stmtSinClasificar->execute();
    $sinClasificar = $stmtSinClasificar->get_result();
} else {
    $sqlSinClasificar .= " ORDER BY precios_descripcion, precios_codigo LIMIT 500";
    $sinClasificar = $conexion->query($sqlSinClasificar);
}
$cantidadSinClasificarTotal = 0;
$resCantidadSinClasificar = $conexion->query("SELECT COUNT(*) AS cantidad FROM lista_precios WHERE precios_clasificacion = 'SIN_CLASIFICAR'");
if ($resCantidadSinClasificar) {
    $filaCantidad = $resCantidadSinClasificar->fetch_assoc();
    $cantidadSinClasificarTotal = (int)$filaCantidad['cantidad'];
}

/* Artículos clasificados para poder corregir errores de clasificación. */
$fClasificados = isset($_GET['f_clasificados']) ? trim($_GET['f_clasificados']) : '';
$estadoClasificados = isset($_GET['estado_clasificados']) && in_array($_GET['estado_clasificados'], array('EQUIPO','NO_EQUIPO'), true)
    ? $_GET['estado_clasificados'] : 'EQUIPO';
$sqlClasificados = "SELECT precios_codigo, precios_descripcion, precios_costo, precios_clasificacion
                    FROM lista_precios
                    WHERE precios_clasificacion = ?";
if ($fClasificados !== '') {
    $sqlClasificados .= " AND (precios_codigo LIKE ? OR precios_descripcion LIKE ?)";
}
$sqlClasificados .= " ORDER BY precios_descripcion, precios_codigo LIMIT 500";
$stmtClasificados = $conexion->prepare($sqlClasificados);
if ($fClasificados !== '') {
    $buscarClasificados = '%' . $fClasificados . '%';
    $stmtClasificados->bind_param('sss', $estadoClasificados, $buscarClasificados, $buscarClasificados);
} else {
    $stmtClasificados->bind_param('s', $estadoClasificados);
}
$stmtClasificados->execute();
$clasificados = $stmtClasificados->get_result();

/* Códigos usados por la matriz que no existen en lista_precios. */
$sqlSinPrecio = "SELECT m.control_id, m.control_codigo, c.cpu_name, tc.ctrltipo_name, sc.ctrlsubtipo_name
                 FROM matriz_calculos m
                 INNER JOIN cpus c ON c.cpu_id = m.control_cpu
                 INNER JOIN tipos_control tc ON tc.ctrltipo_id = m.control_tipo
                 INNER JOIN subtipos_control sc ON sc.ctrlsubtipo_id = m.control_subtipo
                 LEFT JOIN lista_precios lp ON TRIM(lp.precios_codigo) = TRIM(m.control_codigo)
                 WHERE lp.precios_codigo IS NULL
                 ORDER BY m.control_codigo";
$sinPrecio = $conexion->query($sqlSinPrecio);
$cantidadSinPrecio = $sinPrecio ? $sinPrecio->num_rows : 0;

function seleccionado($actual, $valor) { return (string)$actual === (string)$valor ? ' selected' : ''; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Cálculos de Control</title>
<style>
*{box-sizing:border-box} body{font-family:Arial,sans-serif;background:#f4f4f9;margin:18px;color:#202124}
.contenedor{max-width:1500px;margin:auto}.barra{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:14px;flex-wrap:wrap}
.tarjeta{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}
h1{font-size:23px;margin:0} h2{font-size:17px;margin:0 0 12px}.grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:10px}
.campo label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px}.campo input,.campo select{width:100%;height:34px;border:1px solid #aaa;border-radius:4px;padding:5px 7px;background:#fff}
.ancho{grid-column:span 2}.acciones{grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;margin-top:5px}
button,.boton{border:0;border-radius:4px;padding:9px 14px;font-weight:bold;cursor:pointer;text-decoration:none;display:inline-block}.primario{background:#198754;color:#fff}.secundario{background:#0d6efd;color:#fff}.gris{background:#6c757d;color:#fff}.claro{background:#e9ecef;color:#222}
.mensaje{padding:10px 12px;border-radius:5px;margin-bottom:14px}.mensaje.ok{background:#e7f7ed;border:1px solid #9ed4ae;color:#175c2c}.mensaje.error{background:#fdecec;border:1px solid #efaaaa;color:#8a1520}
.filtros{display:grid;grid-template-columns:repeat(5,minmax(150px,1fr));gap:8px;align-items:end}.tabla-wrap{overflow:auto;max-height:620px;border:1px solid #ddd}
table{border-collapse:collapse;width:100%;font-size:12px;white-space:nowrap}th,td{border-bottom:1px solid #ddd;border-right:1px solid #eee;padding:7px;text-align:left}th{position:sticky;top:0;background:#212529;color:#fff;z-index:2}tr:nth-child(even){background:#f8f9fa}tr:hover{background:#fff3cd}.precio{font-weight:bold}.mini{font-size:11px;color:#666}.subtipo{display:flex;gap:8px}.subtipo input{flex:1}.peligro{background:#dc3545;color:#fff}.indicadores{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:16px}.indicador{background:#fff;border:1px solid #ddd;border-radius:7px;padding:10px 14px;font-size:13px}.indicador strong{font-size:18px;display:block}.acciones-fila{display:flex;gap:6px;align-items:center}.acciones-fila form{margin:0}.pendiente{background:#fff8e1}.pendiente-reemplazo{background:#fff1e6}.sin-precio{background:#fdecec}.aviso-pendiente{grid-column:1/-1;background:#fff8e1;border:1px solid #e7c65f;border-radius:6px;padding:10px 12px;font-size:13px}.aviso-pendiente strong{display:block;margin-bottom:4px}.clasificacion-acciones{display:flex;gap:6px;flex-wrap:wrap}.equipo{background:#198754;color:#fff}.no-equipo{background:#6c757d;color:#fff}.sin-clasificar-btn{background:#ffc107;color:#212529}.busqueda-clasificar{display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:10px}.busqueda-clasificar .campo{min-width:260px;flex:1}
@media(max-width:900px){.grid{grid-template-columns:repeat(2,1fr)}.filtros{grid-template-columns:repeat(2,1fr)}}@media(max-width:560px){.grid,.filtros{grid-template-columns:1fr}.ancho{grid-column:span 1}}

.grupo-admin{background:#eef3f8;border:1px solid #cad4df;border-radius:8px;padding:12px;margin:14px 0}.grupo-admin h2{margin:0 0 5px;font-size:17px}.grupo-admin p{margin:0;color:#5f6368;font-size:12px}.menu-grupo{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}.menu-grupo button{padding:8px 11px}.tabla-subtipos{max-height:360px;margin-top:12px}.editar-subtipo-form{display:contents}.editar-subtipo-form input[type="text"]{width:100%;min-width:230px;height:32px;border:1px solid #aaa;border-radius:4px;padding:5px 7px}
.menu-principal{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.menu-principal a{display:inline-block;padding:9px 13px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;background:#343a40;color:#fff}.menu-principal a.activo{background:#0d6efd}.menu-principal a:hover{opacity:.88}

.menu-secciones{position:sticky;top:0;z-index:20;display:flex;gap:8px;flex-wrap:wrap;background:#f4f4f9;padding:10px 0;margin-bottom:14px;border-bottom:1px solid #d7d7d7}
.menu-secciones button{background:#343a40;color:#fff;padding:8px 11px;font-size:12px}
.menu-secciones button:hover,.menu-secciones button.activo{background:#0d6efd}
.seccion-plegable{padding:0;overflow:hidden}
.seccion-plegable>.cabecera-seccion{display:flex;justify-content:space-between;align-items:center;gap:12px;width:100%;padding:14px 16px;background:#fff;border:0;color:#202124;text-align:left;font-size:17px;font-weight:bold;cursor:pointer}
.seccion-plegable>.cabecera-seccion:hover{background:#f8f9fa}
.seccion-plegable>.cabecera-seccion .flecha{font-size:14px;transition:transform .2s ease}
.seccion-plegable.abierta>.cabecera-seccion .flecha{transform:rotate(90deg)}
.contenido-seccion{display:none;padding:0 16px 16px}
.seccion-plegable.abierta>.contenido-seccion{display:block}
.seccion-plegable>.cabecera-seccion h2{margin:0;font-size:17px}
@media(max-width:700px){.menu-secciones{position:static}.menu-secciones button{flex:1 1 190px}}
</style>
</head>
<body>
<div style="max-width:1500px;margin:0 auto 14px auto;display:flex;justify-content:flex-end"><a href="exportar_matriz_calculos.php" style="display:inline-block;background:#198754;color:#fff;text-decoration:none;padding:10px 16px;border-radius:7px;font-weight:bold">Exportar matriz para revisión</a></div>
<div class="contenedor">
<?php require __DIR__ . '/menu.php'; ?>
<div class="barra"><h1>Cálculos de Control</h1></div>
<?php if ($mensaje !== ''): ?><div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div><?php endif; ?>
<div class="indicadores">
    <div class="indicador"><strong><?= (int)$listado->num_rows ?></strong>Configuraciones mostradas</div>
    <div class="indicador"><strong><?= (int)$cantidadPendientes ?></strong>Equipos vigentes pendientes</div>
    <div class="indicador"><strong><?= (int)$cantidadPendientesReemplazo ?></strong>Configuraciones con precio 0</div>
    <div class="indicador"><strong><?= (int)$cantidadSinClasificarTotal ?></strong>Artículos sin clasificar</div>
    <div class="indicador"><strong><?= (int)$cantidadSinPrecio ?></strong>Códigos inexistentes</div>
</div>

<div class="grupo-admin"><h2>Administrar tipos de control</h2><p>Alta y edición de los tipos generales: 1 velocidad, 2 velocidades, hidráulico, VF, imán permanente, Roomless y MRL.</p><div class="menu-grupo"><button type="button" data-seccion="tipos">Administrar tipos</button></div></div>
<div class="grupo-admin"><h2>Compatibilidad CPU</h2><p>Velocidad máxima y disponibilidad de encoder para cada CPU.</p><div class="menu-grupo"><button type="button" data-seccion="cpus">CPU / velocidad / encoder</button></div></div>
<div class="grupo-admin"><h2>Límites de paradas</h2><p>Consulta todas las variantes permitidas por modelo de control, maniobra, comunicación serie y doble acceso.</p><div class="menu-grupo"><a class="boton secundario" href="limites_paradas.php">Ver tabla completa de límites</a></div></div>
<div class="grupo-admin"><h2>Administrar subtipos / variadores</h2><p>Alta y edición de los subtipos técnicos y modelos de variador utilizados por las configuraciones.</p><div class="menu-grupo"><button type="button" data-seccion="subtipos">Administrar subtipos</button></div></div>
<div class="grupo-admin"><h2>Administrar matriz de cálculos</h2><p>Configuraciones, clasificación de artículos, equipos pendientes y control de códigos sin precio.</p><nav class="menu-secciones menu-grupo" aria-label="Secciones de matriz">
<button type="button" data-seccion="formulario-configuracion">Nueva configuración</button>
<button type="button" data-seccion="matriz-actual">Matriz actual</button>
<button type="button" data-seccion="articulos-sin-clasificar">Sin clasificar</button>
<button type="button" data-seccion="corregir-clasificaciones">Clasificaciones</button>
<button type="button" data-seccion="equipos-pendientes">Equipos vigentes pendientes</button>
<button type="button" data-seccion="pendientes-reemplazo">Precio 0 / reemplazar</button>
<button type="button" data-seccion="codigos-sin-precio">Códigos inexistentes</button>
</nav></div>

<div class="tarjeta seccion-plegable" id="tipos">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Administrar tipos de control</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<form method="post" class="subtipo"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="crear_tipo"><input type="text" name="nuevo_tipo" maxlength="100" placeholder="Ej.: VF" required><button class="secundario" type="submit">Crear tipo</button></form>
<p class="mini">Editar el nombre no altera las configuraciones existentes porque la matriz conserva el mismo ID del tipo.</p>
<div class="tabla-wrap tabla-subtipos"><table><thead><tr><th>ID</th><th>Nombre del tipo</th><th>Configuraciones que lo usan</th><th>Acción</th></tr></thead><tbody>
<?php if($tiposGestion && $tiposGestion->num_rows>0): while($tg=$tiposGestion->fetch_assoc()): ?>
<tr><td><?= (int)$tg['ctrltipo_id'] ?></td><td><form method="post" class="editar-subtipo-form"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="editar_tipo"><input type="hidden" name="ctrltipo_id" value="<?= (int)$tg['ctrltipo_id'] ?>"><input type="text" name="ctrltipo_name" maxlength="100" value="<?= e($tg['ctrltipo_name']) ?>" required></td><td><?= (int)$tg['cantidad_usos'] ?></td><td><button class="secundario" type="submit">Guardar</button></form></td></tr>
<?php endwhile; else: ?><tr><td colspan="4">No hay tipos cargados.</td></tr><?php endif; ?>
</tbody></table></div></div></div>

<div class="tarjeta seccion-plegable" id="cpus">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Compatibilidad CPU / velocidad / encoder</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">La columna <strong>Matriz base</strong> define de qué CPU se toma la matriz principal de cálculo. CLEX y DANGELICA heredan A6300V4 para el cálculo base, pero conservan sus propios límites de paradas, maniobras, baterías, posicionamiento y demás reglas específicas.</p>
<div class="tabla-wrap tabla-subtipos"><table><thead><tr><th>ID</th><th>CPU</th><th>Matriz base</th><th>Vel. máxima</th><th>Admite encoder</th><th>Acción</th></tr></thead><tbody>
<?php if($cpusGestion && $cpusGestion->num_rows>0): while($cg=$cpusGestion->fetch_assoc()): ?>
<tr><td><?= (int)$cg['cpu_id'] ?></td><td><strong><?= e($cg['cpu_name']) ?></strong></td><td><form method="post" class="editar-subtipo-form"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="editar_cpu_compat"><input type="hidden" name="cpu_id" value="<?= (int)$cg['cpu_id'] ?>"><select name="cpu_matriz_base_id"><?php foreach(array(1=>'A6220V5',2=>'A6300V4',3=>'A6700V2',4=>'CLEX',5=>'DANGELICA') as $bid=>$bn): ?><option value="<?=$bid?>"<?= (int)($cg['cpu_matriz_base_id']??$cg['cpu_id'])===$bid?' selected':'' ?>><?=e($bn)?></option><?php endforeach; ?></select></td><td><input type="number" name="velocidad_max_mmin" min="1" step="0.01" style="width:90px" value="<?= $cg['velocidad_max_mmin'] !== null ? e($cg['velocidad_max_mmin']) : '' ?>" placeholder="Sin tope"></td><td><select name="admite_encoder"><option value=""<?= seleccionado($cg['admite_encoder'] ?? '','') ?>>Sin definir</option><option value="NO"<?= seleccionado($cg['admite_encoder'] ?? '','NO') ?>>NO</option><option value="SI"<?= seleccionado($cg['admite_encoder'] ?? '','SI') ?>>SI</option></select></td><td><button class="primario" type="submit">Guardar</button></form></td></tr>
<?php endwhile; else: ?><tr><td colspan="6">No hay CPU cargadas.</td></tr><?php endif; ?>
</tbody></table></div></div></div>

<div class="tarjeta seccion-plegable" id="subtipos">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Administrar subtipos o variadores</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<form method="post" class="subtipo"><?= automacCsrfInput() ?>
<input type="hidden" name="accion" value="crear_subtipo">
<input type="text" name="nuevo_subtipo" maxlength="100" placeholder="Ej.: MD380LT" required>
<button class="secundario" type="submit">Crear subtipo</button>
</form>
<p class="mini">La compatibilidad de velocidad se administra aquí. Velocidad máxima vacía = sin tope específico. “Mayor a 75” debe quedar en SI solo para variadores habilitados para alta velocidad.</p>

<div class="tabla-wrap tabla-subtipos">
<table>
<thead><tr><th>ID</th><th>Nombre del subtipo / variador</th><th>Vel. máxima</th><th>&gt;75 m/min</th><th>Configuraciones que lo usan</th><th>Acción</th></tr></thead>
<tbody>
<?php if ($subtiposGestion && $subtiposGestion->num_rows > 0): ?>
<?php while ($sg = $subtiposGestion->fetch_assoc()): ?>
<tr>
<td><?= (int)$sg['ctrlsubtipo_id'] ?></td>
<td>
<form method="post" class="editar-subtipo-form"><?= automacCsrfInput() ?>
<input type="hidden" name="accion" value="editar_subtipo">
<input type="hidden" name="ctrlsubtipo_id" value="<?= (int)$sg['ctrlsubtipo_id'] ?>">
<input type="text" name="ctrlsubtipo_name" maxlength="100" value="<?= e($sg['ctrlsubtipo_name']) ?>" required>
</td>
<td><input type="number" name="velocidad_max_mmin" min="1" step="0.01" style="width:90px" value="<?= $sg['velocidad_max_mmin'] !== null ? e($sg['velocidad_max_mmin']) : '' ?>" placeholder="Sin tope"></td>
<td><select name="habilita_mayor_75"><option value="NO"<?= seleccionado($sg['habilita_mayor_75'] ?? 'NO','NO') ?>>NO</option><option value="SI"<?= seleccionado($sg['habilita_mayor_75'] ?? 'NO','SI') ?>>SI</option></select></td>
<td><?= (int)$sg['cantidad_usos'] ?></td>
<td><button class="primario" type="submit">Guardar</button></form></td>
</tr>
<?php endwhile; ?>
<?php else: ?>
<tr><td colspan="6">No hay subtipos cargados.</td></tr>
<?php endif; ?>
</tbody>
</table>
</div>
</div>
</div>

<div class="tarjeta seccion-plegable" id="formulario-configuracion">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2><?php if (isset($editar['control_id'])): ?>Editar configuración ID <?= (int)$editar['control_id'] ?><?php elseif ($duplicandoReemplazo): ?>Completar reemplazo vigente<?php elseif ($pendienteCodigo !== ''): ?>Completar configuración pendiente<?php else: ?>Agregar nueva configuración<?php endif; ?></h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<form method="post" class="grid"><?= automacCsrfInput() ?>
<?php if ($duplicandoReemplazo): ?>
<div class="aviso-pendiente">
<strong>Reemplazo vigente para <?= e($codigoOrigenReemplazo) ?></strong>
Se copiaron los datos técnicos de la configuración discontinuada. Cambiá el subtipo/variador y el código comercial por la versión vigente antes de guardar. La configuración anterior no se modifica.
</div>
<?php endif; ?>
<?php if ($pendienteCodigo !== '' && !isset($editar['control_id'])): ?>
<div class="aviso-pendiente">
<strong>Artículo pendiente: <?= e($editar['control_codigo'] ?? $pendienteCodigo) ?></strong>
<?= e($editar['precios_descripcion_pendiente'] ?? '') ?><br>
Precio vigente: $<?= number_format((float)($editar['precios_costo_pendiente'] ?? 0), 2, ',', '.') ?>. Revisá y completá los datos técnicos antes de guardar.
</div>
<?php endif; ?>
<input type="hidden" name="accion" value="<?= isset($editar['control_id']) ? 'actualizar' : 'guardar' ?>">
<input type="hidden" name="control_id" value="<?= isset($editar['control_id']) ? (int)$editar['control_id'] : '' ?>">
<div class="campo"><label>CPU *</label><select name="control_cpu" required><option value="">Seleccione...</option><?php $cpus->data_seek(0); while($r=$cpus->fetch_assoc()): ?><option value="<?= (int)$r['cpu_id'] ?>"<?= seleccionado($editar['control_cpu'] ?? '', $r['cpu_id']) ?>><?= e($r['cpu_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Tipo de control *</label><select name="control_tipo" required><option value="">Seleccione...</option><?php $tipos->data_seek(0); while($r=$tipos->fetch_assoc()): ?><option value="<?= (int)$r['ctrltipo_id'] ?>"<?= seleccionado($editar['control_tipo'] ?? '', $r['ctrltipo_id']) ?>><?= e($r['ctrltipo_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Subtipo / variador *</label><select name="control_subtipo" required><option value="">Seleccione...</option><?php $subtipos->data_seek(0); while($r=$subtipos->fetch_assoc()): ?><option value="<?= (int)$r['ctrlsubtipo_id'] ?>"<?= seleccionado($editar['control_subtipo'] ?? '', $r['ctrlsubtipo_id']) ?>><?= e($r['ctrlsubtipo_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Tensión *</label><select name="control_tension" required><option value="">Seleccione...</option><?php $tensiones->data_seek(0); while($r=$tensiones->fetch_assoc()): ?><option value="<?= (int)$r['tension_id'] ?>"<?= seleccionado($editar['control_tension'] ?? '', $r['tension_id']) ?>><?= e($r['tension_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>HP desde *</label><input type="number" step="0.01" min="0" name="control_potenciadesde" value="<?= e($editar['control_potenciadesde'] ?? '') ?>" required></div>
<div class="campo"><label>HP hasta *</label><input type="number" step="0.01" min="0.01" name="control_potenciahasta" value="<?= e($editar['control_potenciahasta'] ?? '') ?>" required></div>
<div class="campo"><label>Corriente (A) *</label><input type="number" step="0.01" min="0" name="control_corriente" value="<?= e($editar['control_corriente'] ?? '0') ?>" required></div>
<div class="campo"><label>Encoder del control</label><select name="control_encoder"><option value="">Sin encoder</option><option value="SI"<?= seleccionado($editar['control_encoder'] ?? '', 'SI') ?>>Con encoder</option></select></div>
<div class="campo"><label>Contactor normal</label><select name="control_contactor"><option value="">Sin asignar</option><?php if($contactores){$contactores->data_seek(0);while($r=$contactores->fetch_assoc()): ?><option value="<?= (int)$r['contactor_id'] ?>"<?= seleccionado($editar['control_contactor'] ?? '', $r['contactor_id']) ?>><?= e($r['contactor_name']) ?> A</option><?php endwhile;} ?></select></div>
<div class="campo"><label>Contactor de potencial</label><select name="control_contactorpot"><option value="">Sin asignar</option><?php if($contactoresPot){$contactoresPot->data_seek(0);while($r=$contactoresPot->fetch_assoc()): ?><option value="<?= (int)$r['contactorpot_id'] ?>"<?= seleccionado($editar['control_contactorpot'] ?? '', $r['contactorpot_id']) ?>><?= e($r['contactorpot_name']) ?> — <?= e($r['contactorpot_codigo']) ?></option><?php endwhile;} ?></select></div>
<div class="campo"><label>Térmico asociado</label><select name="control_termicos"><option value="">Sin asignar</option><?php if($termicos){$termicos->data_seek(0);while($r=$termicos->fetch_assoc()): ?><option value="<?= (int)$r['termicos_id'] ?>"<?= seleccionado($editar['control_termicos'] ?? '', $r['termicos_id']) ?>><?= e($r['termicos_codigo']) ?> (<?= e($r['termicos_potde']) ?>–<?= e($r['termicos_pothasta']) ?> HP)</option><?php endwhile;} ?></select></div>
<div class="campo ancho"><label>Código de base Bejerman vigente *</label><input type="text" name="control_codigo" maxlength="40" value="<?= e($editar['control_codigo'] ?? '') ?>" placeholder="Ej.: A62FMO18LA" required></div>
<div class="campo"><label>Precio histórico (solo referencia)</label><input type="number" step="0.01" min="0" name="control_precio" value="<?= e($editar['control_precio'] ?? '0') ?>"></div>
<div class="acciones"><button class="primario" type="submit"><?php if (isset($editar['control_id'])): ?>Guardar modificaciones<?php elseif ($pendienteCodigo !== '' || $duplicandoReemplazo): ?>Guardar nueva configuración<?php else: ?>Agregar configuración<?php endif; ?></button><?php if(isset($editar['control_id']) || $pendienteCodigo !== '' || $duplicandoReemplazo): ?><a class="boton claro" href="administrar_matriz.php">Cancelar</a><?php endif; ?></div>
</form>
</div>
</div>

<div class="tarjeta seccion-plegable" id="matriz-actual">
<button type="button" class="cabecera-seccion" aria-expanded="true"><h2>Matriz actual</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<form method="get" class="filtros">
<div class="campo"><label>CPU</label><select name="f_cpu"><option value="">Todas</option><?php $cpus->data_seek(0); while($r=$cpus->fetch_assoc()): ?><option value="<?= (int)$r['cpu_id'] ?>"<?= seleccionado($fCpu, $r['cpu_id']) ?>><?= e($r['cpu_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Tipo</label><select name="f_tipo"><option value="">Todos</option><?php $tipos->data_seek(0); while($r=$tipos->fetch_assoc()): ?><option value="<?= (int)$r['ctrltipo_id'] ?>"<?= seleccionado($fTipo, $r['ctrltipo_id']) ?>><?= e($r['ctrltipo_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Subtipo</label><select name="f_subtipo"><option value="">Todos</option><?php $subtipos->data_seek(0); while($r=$subtipos->fetch_assoc()): ?><option value="<?= (int)$r['ctrlsubtipo_id'] ?>"<?= seleccionado($fSubtipo, $r['ctrlsubtipo_id']) ?>><?= e($r['ctrlsubtipo_name']) ?></option><?php endwhile; ?></select></div>
<div class="campo"><label>Código o descripción</label><input type="text" name="f_codigo" value="<?= e($fCodigo) ?>" placeholder="MD380, A62..."></div>
<div><button class="secundario" type="submit">Filtrar</button><?php if($fCpu && $fCpuMatriz && $fCpuMatriz!==$fCpu): ?><span class="mini" style="align-self:center"><strong><?=e($fCpuNombre)?></strong> muestra la matriz heredada de <strong><?=e($fCpuMatrizNombre)?></strong>.</span><?php endif; ?> <a class="boton claro" href="administrar_matriz.php">Limpiar</a></div>
</form>
<p class="mini">Se muestran hasta 1.000 registros.</p>
<div class="tabla-wrap"><table><thead><tr><th>ID</th><th>CPU</th><th>Tipo</th><th>Subtipo</th><th>Tensión</th><th>Encoder</th><th>HP desde</th><th>HP hasta</th><th>Corriente</th><th>Contactor</th><th>Cont. potencial</th><th>Térmico</th><th>Código</th><th>Descripción / precio vigente</th><th>Acción</th></tr></thead><tbody>
<?php while($r=$listado->fetch_assoc()): ?><tr>
<td><?= (int)$r['control_id'] ?></td><td><?= e($r['cpu_name']) ?></td><td><?= e($r['ctrltipo_name']) ?></td><td><?= e($r['ctrlsubtipo_name']) ?></td><td><?= e($r['tension_name']) ?></td><td><?= $r['control_encoder']==='SI'?'SI':'NO' ?></td><td><?= e($r['control_potenciadesde']) ?></td><td><?= e($r['control_potenciahasta']) ?></td><td><?= e($r['control_corriente']) ?> A</td><td><?= e($r['contactor_name'] ?? '-') ?></td><td><?= e($r['contactorpot_name'] ?? '-') ?></td><td><?= e($r['termicos_codigo'] ?? '-') ?></td><td><strong><?= e($r['control_codigo']) ?></strong></td><td><?= e($r['precios_descripcion'] ?? 'Código sin descripción') ?><br><span class="precio">$<?= number_format((float)($r['precios_costo'] ?? $r['control_precio']),2,',','.') ?></span></td><td><div class="acciones-fila">
<a class="boton secundario" href="?editar=<?= (int)$r['control_id'] ?>">Editar</a>
<form method="post" data-codigo="<?= e($r['control_codigo']) ?>" onsubmit="return confirmarEliminacion(this.dataset.codigo);">
<?= automacCsrfInput() ?>
<input type="hidden" name="accion" value="eliminar">
<input type="hidden" name="control_id" value="<?= (int)$r['control_id'] ?>">
<button class="peligro" type="submit">Eliminar</button>
</form>
</div></td>
</tr><?php endwhile; ?></tbody></table></div>

</div>
</div>

<div class="tarjeta seccion-plegable" id="articulos-sin-clasificar">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Artículos sin clasificar (<?= (int)$cantidadSinClasificarTotal ?>)</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">Revisá los artículos nuevos o ambiguos. Marcarlos como EQUIPO los habilita para aparecer en “Equipos pendientes de incorporar”. Marcarlos como NO EQUIPO los excluye.</p>
<form method="get" class="busqueda-clasificar">
<div class="campo"><label>Buscar código o descripción</label><input type="text" name="f_clasificar" value="<?= e($fClasificar) ?>" placeholder="Ej.: MD380, tablero, A62..."></div>
<div><button class="secundario" type="submit">Buscar</button> <a class="boton claro" href="administrar_matriz.php#sin-clasificar">Limpiar</a></div>
</form>
<div class="tabla-wrap" id="sin-clasificar"><table><thead><tr><th>Código</th><th>Descripción</th><th>Precio</th><th>Clasificar</th></tr></thead><tbody>
<?php if ($sinClasificar && $sinClasificar->num_rows > 0): ?>
<?php while($sc=$sinClasificar->fetch_assoc()): ?><tr>
<td><strong><?= e($sc['precios_codigo']) ?></strong></td>
<td><?= e($sc['precios_descripcion']) ?></td>
<td class="precio">$<?= number_format((float)$sc['precios_costo'],2,',','.') ?></td>
<td><div class="clasificacion-acciones">
<form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="clasificar_precio"><input type="hidden" name="precios_codigo" value="<?= e($sc['precios_codigo']) ?>"><input type="hidden" name="precios_clasificacion" value="EQUIPO"><button class="equipo" type="submit">Es equipo</button></form>
<form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="clasificar_precio"><input type="hidden" name="precios_codigo" value="<?= e($sc['precios_codigo']) ?>"><input type="hidden" name="precios_clasificacion" value="NO_EQUIPO"><button class="no-equipo" type="submit">No es equipo</button></form>
</div></td>
</tr><?php endwhile; ?>
<?php else: ?><tr><td colspan="4">No hay artículos sin clasificar con ese filtro.</td></tr><?php endif; ?>
</tbody></table></div>
<p class="mini">Se muestran hasta 500 artículos por vez.</p>
</div>
</div>

<div class="tarjeta seccion-plegable" id="corregir-clasificaciones">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Corregir clasificaciones</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">Permite revisar artículos ya marcados y devolverlos a SIN CLASIFICAR o cambiar su estado.</p>
<form method="get" class="busqueda-clasificar">
<div class="campo"><label>Estado</label><select name="estado_clasificados"><option value="EQUIPO"<?= seleccionado($estadoClasificados,'EQUIPO') ?>>Equipos</option><option value="NO_EQUIPO"<?= seleccionado($estadoClasificados,'NO_EQUIPO') ?>>No equipos</option></select></div>
<div class="campo"><label>Buscar</label><input type="text" name="f_clasificados" value="<?= e($fClasificados) ?>" placeholder="Código o descripción"></div>
<div><button class="secundario" type="submit">Ver</button></div>
</form>
<div class="tabla-wrap"><table><thead><tr><th>Código</th><th>Descripción</th><th>Estado actual</th><th>Acciones</th></tr></thead><tbody>
<?php if ($clasificados && $clasificados->num_rows > 0): ?>
<?php while($cl=$clasificados->fetch_assoc()): ?><tr>
<td><strong><?= e($cl['precios_codigo']) ?></strong></td><td><?= e($cl['precios_descripcion']) ?></td><td><?= e($cl['precios_clasificacion']) ?></td>
<td><div class="clasificacion-acciones">
<?php if ($cl['precios_clasificacion'] !== 'EQUIPO'): ?><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="clasificar_precio"><input type="hidden" name="origen_clasificacion" value="corregir-clasificaciones"><input type="hidden" name="estado_clasificados" value="<?= e($estadoClasificados) ?>"><input type="hidden" name="f_clasificados" value="<?= e($fClasificados) ?>"><input type="hidden" name="precios_codigo" value="<?= e($cl['precios_codigo']) ?>"><input type="hidden" name="precios_clasificacion" value="EQUIPO"><button class="equipo" type="submit">Marcar equipo</button></form><?php endif; ?>
<?php if ($cl['precios_clasificacion'] !== 'NO_EQUIPO'): ?><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="clasificar_precio"><input type="hidden" name="origen_clasificacion" value="corregir-clasificaciones"><input type="hidden" name="estado_clasificados" value="<?= e($estadoClasificados) ?>"><input type="hidden" name="f_clasificados" value="<?= e($fClasificados) ?>"><input type="hidden" name="precios_codigo" value="<?= e($cl['precios_codigo']) ?>"><input type="hidden" name="precios_clasificacion" value="NO_EQUIPO"><button class="no-equipo" type="submit">Marcar no equipo</button></form><?php endif; ?>
<form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="clasificar_precio"><input type="hidden" name="origen_clasificacion" value="corregir-clasificaciones"><input type="hidden" name="estado_clasificados" value="<?= e($estadoClasificados) ?>"><input type="hidden" name="f_clasificados" value="<?= e($fClasificados) ?>"><input type="hidden" name="precios_codigo" value="<?= e($cl['precios_codigo']) ?>"><input type="hidden" name="precios_clasificacion" value="SIN_CLASIFICAR"><button class="sin-clasificar-btn" type="submit">Volver a revisar</button></form>
</div></td>
</tr><?php endwhile; ?>
<?php else: ?><tr><td colspan="4">No hay artículos con ese estado y filtro.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</div>

<div class="tarjeta seccion-plegable" id="equipos-pendientes">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Equipos vigentes pendientes de incorporar (<?= (int)$cantidadPendientes ?>)</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">Son artículos vigentes, marcados como EQUIPO, con precio mayor que cero y cuyo código todavía no aparece en matriz_calculos. El botón precarga el formulario; no se guarda nada hasta que revises los datos técnicos y presiones “Guardar nueva configuración”.</p>
<div class="tabla-wrap"><table><thead><tr><th>Código</th><th>Descripción</th><th>Precio vigente</th><th>Acción</th></tr></thead><tbody>
<?php if ($pendientes && $cantidadPendientes > 0): ?>
<?php while($p=$pendientes->fetch_assoc()): ?><tr class="pendiente">
<td><strong><?= e($p['precios_codigo']) ?></strong></td>
<td><?= e($p['precios_descripcion']) ?></td>
<td class="precio">$<?= number_format((float)$p['precios_costo'],2,',','.') ?></td>
<td><a class="boton primario" href="?pendiente_codigo=<?= urlencode($p['precios_codigo']) ?>#formulario-configuracion">Completar configuración</a></td>
</tr><?php endwhile; ?>
<?php else: ?><tr><td colspan="4">No hay equipos pendientes.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</div>

<div class="tarjeta seccion-plegable" id="pendientes-reemplazo">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Configuraciones con precio 0 pendientes de reemplazo (<?= (int)$cantidadPendientesReemplazo ?>)</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">Estas configuraciones ya están en matriz_calculos, pero el artículo quedó discontinuado o sin precio vigente. “Completar reemplazo” copia los datos técnicos en una configuración nueva; la fila histórica no se modifica.</p>
<div class="tabla-wrap"><table><thead><tr><th>ID</th><th>Código discontinuado</th><th>Descripción</th><th>CPU</th><th>Tipo</th><th>Subtipo actual</th><th>Precio</th><th>Acción</th></tr></thead><tbody>
<?php if ($pendientesReemplazo && $cantidadPendientesReemplazo > 0): ?>
<?php while($pr=$pendientesReemplazo->fetch_assoc()): ?><tr class="pendiente-reemplazo">
<td><?= (int)$pr['control_id'] ?></td>
<td><strong><?= e($pr['control_codigo']) ?></strong></td>
<td><?= e($pr['precios_descripcion']) ?></td>
<td><?= e($pr['cpu_name']) ?></td>
<td><?= e($pr['ctrltipo_name']) ?></td>
<td><?= e($pr['ctrlsubtipo_name']) ?></td>
<td class="precio">$<?= number_format((float)$pr['precios_costo'],2,',','.') ?></td>
<td><a class="boton primario" href="?duplicar=<?= (int)$pr['control_id'] ?>#formulario-configuracion">Completar reemplazo</a> <a class="boton secundario" href="?editar=<?= (int)$pr['control_id'] ?>">Editar histórica</a></td>
</tr><?php endwhile; ?>
<?php else: ?><tr><td colspan="8">No hay configuraciones con precio cero.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</div>

<div class="tarjeta seccion-plegable" id="codigos-sin-precio">
<button type="button" class="cabecera-seccion" aria-expanded="false"><h2>Códigos de matriz inexistentes en base Bejerman vigente (<?= (int)$cantidadSinPrecio ?>)</h2><span class="flecha">▶</span></button>
<div class="contenido-seccion">
<p class="mini">Estas configuraciones existen en matriz_calculos, pero su código no existe en lista_precios. Es distinto de un artículo existente con precio cero.</p>
<div class="tabla-wrap"><table><thead><tr><th>ID</th><th>Código</th><th>CPU</th><th>Tipo</th><th>Subtipo</th><th>Acción</th></tr></thead><tbody>
<?php if ($sinPrecio && $cantidadSinPrecio > 0): ?>
<?php while($sp=$sinPrecio->fetch_assoc()): ?><tr class="sin-precio">
<td><?= (int)$sp['control_id'] ?></td><td><strong><?= e($sp['control_codigo']) ?></strong></td><td><?= e($sp['cpu_name']) ?></td><td><?= e($sp['ctrltipo_name']) ?></td><td><?= e($sp['ctrlsubtipo_name']) ?></td>
<td><a class="boton secundario" href="?editar=<?= (int)$sp['control_id'] ?>">Revisar</a></td>
</tr><?php endwhile; ?>
<?php else: ?><tr><td colspan="6">Todos los códigos de la matriz existen en lista_precios.</td></tr><?php endif; ?>
</tbody></table></div>
</div>
</div>
</div>
<script>
function confirmarEliminacion(codigo) {
    return window.confirm('¿Eliminar definitivamente la configuración ' + codigo + '? Esta acción no se puede deshacer.');
}

function abrirSeccion(id, desplazar = true) {
    const secciones = document.querySelectorAll('.seccion-plegable');

    secciones.forEach(function (seccion) {
        const abrir = seccion.id === id;
        seccion.classList.toggle('abierta', abrir);

        const cabecera = seccion.querySelector(':scope > .cabecera-seccion');
        if (cabecera) {
            cabecera.setAttribute('aria-expanded', abrir ? 'true' : 'false');
        }
    });

    document.querySelectorAll('[data-seccion]').forEach(function (boton) {
        boton.classList.toggle('activo', boton.dataset.seccion === id);
    });

    if (desplazar) {
        const destino = document.getElementById(id);
        if (destino) {
            destino.scrollIntoView({behavior: 'smooth', block: 'start'});
        }
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.cabecera-seccion').forEach(function (cabecera) {
        cabecera.addEventListener('click', function () {
            const seccion = cabecera.closest('.seccion-plegable');
            const yaAbierta = seccion.classList.contains('abierta');

            if (yaAbierta) {
                seccion.classList.remove('abierta');
                cabecera.setAttribute('aria-expanded', 'false');

                document.querySelectorAll('[data-seccion]').forEach(function (boton) {
                    boton.classList.remove('activo');
                });
            } else {
                abrirSeccion(seccion.id, false);
            }
        });
    });

    document.querySelectorAll('[data-seccion]').forEach(function (boton) {
        boton.addEventListener('click', function () {
            abrirSeccion(boton.dataset.seccion, true);
        });
    });

    const parametros = new URLSearchParams(window.location.search);
    const hash = window.location.hash.replace('#', '');
    let inicial = 'matriz-actual';

    if (parametros.has('editar') || parametros.has('pendiente_codigo') || parametros.has('duplicar')) {
        inicial = 'formulario-configuracion';
    } else if (hash === 'tipos') {
        inicial = 'tipos';
    } else if (hash === 'subtipos') {
        inicial = 'subtipos';
    } else if (hash === 'sin-clasificar') {
        inicial = 'articulos-sin-clasificar';
    } else if (hash) {
        const destinoHash = document.getElementById(hash);
        if (destinoHash && destinoHash.classList.contains('seccion-plegable')) {
            inicial = hash;
        }
    }

    abrirSeccion(inicial, false);
});
</script>
</body>
</html>
