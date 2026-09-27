<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
automacExigirPostConCsrf();
require_once 'plantillas_controles.php';

$conexion->set_charset('utf8mb4');

try {
    asegurarTablaPlantillasControles($conexion);

    $plantillaId = filter_input(INPUT_POST, 'plantilla_id', FILTER_VALIDATE_INT);
    $plantillaId = ($plantillaId === false || $plantillaId === null) ? 0 : (int)$plantillaId;
    $codigo = strtoupper(trim((string)($_POST['plantilla_codigo'] ?? '')));
    $nombre = trim((string)($_POST['plantilla_nombre'] ?? ''));
    $descripcion = trim((string)($_POST['plantilla_descripcion'] ?? ''));

    if ($codigo === '' || !preg_match('/^[A-Z0-9._-]{1,30}$/', $codigo)) {
        throw new RuntimeException('El código de la plantilla es obligatorio y solo puede contener letras, números, punto, guion o guion bajo.');
    }
    if ($nombre === '') {
        throw new RuntimeException('Debe indicar el nombre de la plantilla.');
    }

    $configuracion = extraerConfiguracionPlantilla($_POST);
    if (empty($configuracion['id_cpu']) || empty($configuracion['id_tipo_control']) || empty($configuracion['id_maniobra'])) {
        throw new RuntimeException('Para guardar la plantilla debe seleccionar como mínimo CPU, tipo de control y maniobra.');
    }

    $json = json_encode($configuracion, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('No se pudo preparar la configuración de la plantilla.');
    }

    if ($plantillaId > 0) {
        $stmt = $conexion->prepare("UPDATE plantillas_controles SET plantilla_codigo=?, plantilla_nombre=?, plantilla_descripcion=?, plantilla_configuracion=?, plantilla_activa='SI' WHERE plantilla_id=?");
        if (!$stmt) throw new RuntimeException($conexion->error);
        $stmt->bind_param('ssssi', $codigo, $nombre, $descripcion, $json, $plantillaId);
    } else {
        $stmt = $conexion->prepare("INSERT INTO plantillas_controles (plantilla_codigo, plantilla_nombre, plantilla_descripcion, plantilla_configuracion) VALUES (?,?,?,?)");
        if (!$stmt) throw new RuntimeException($conexion->error);
        $stmt->bind_param('ssss', $codigo, $nombre, $descripcion, $json);
    }

    if (!$stmt->execute()) {
        if ((int)$stmt->errno === 1062) {
            throw new RuntimeException('Ya existe una plantilla con el código ' . $codigo . '.');
        }
        throw new RuntimeException('No se pudo guardar la plantilla: ' . $stmt->error);
    }
    $stmt->close();

    $_SESSION['plantilla_mensaje'] = 'Plantilla ' . $codigo . ' guardada correctamente.';
    header('Location: administrar_plantillas.php');
    exit;
} catch (Throwable $e) {
    $_SESSION['form_data'] = $_POST;
    $_SESSION['form_error'] = $e->getMessage();
    $destino = 'index.php?modo_plantilla=' . ($plantillaId > 0 ? 'editar&plantilla_id=' . $plantillaId : 'nueva');
    header('Location: ' . $destino);
    exit;
}
