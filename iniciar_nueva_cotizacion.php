<?php
/** Inicio de una cotizacion nueva sin generar HTML ni navegar. */
ob_start();
$respuestaJsonEnviada = false;
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responderInicioNueva(int $codigo, array $datos): void
{
    global $respuestaJsonEnviada;
    $respuestaJsonEnviada = true;
    ob_end_clean();
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

register_shutdown_function(static function () use (&$respuestaJsonEnviada): void {
    if ($respuestaJsonEnviada) return;
    ob_end_clean();
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(array('ok' => false, 'error' => 'No se pudo iniciar la cotización.'));
});

if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    responderInicioNueva(405, array('ok' => false, 'error' => 'Método no permitido.'));
}

try {
    require_once __DIR__ . '/auth.php';
    if (!automacMismoOrigen()) {
        responderInicioNueva(403, array('ok' => false, 'error' => 'Solicitud rechazada por seguridad.'));
    }
    $usuario = usuarioActual();
    if (!$usuario) {
        responderInicioNueva(401, array('ok' => false, 'error' => 'Debe iniciar sesión.'));
    }
    if ($usuario['debe_cambiar_clave'] === 'SI' || !in_array($usuario['rol'], array('ADMINISTRADOR', 'COMERCIAL'), true)) {
        responderInicioNueva(403, array('ok' => false, 'error' => 'Acceso denegado.'));
    }
    if (!automacValidarCsrf(false)) {
        responderInicioNueva(419, array('ok' => false, 'error' => 'La sesión del formulario venció o la solicitud no es válida.'));
    }

    require_once __DIR__ . '/conexion.php';
    require_once __DIR__ . '/sistema_comercial.php';
    require_once __DIR__ . '/parametros_sistema.php';
    require_once __DIR__ . '/cotizador_inicio_nueva.php';

    $descuentos = cotizadorDescuentosIniciales($conexion);
    $listaVigente = obtenerListaVigente($conexion);
    $base = obtenerListaSeleccionada($conexion, (int)($listaVigente['lista_id'] ?? 0));
    $tension = $conexion->query("SELECT tension_id FROM tensiones WHERE tension_name='3X380' ORDER BY tension_id LIMIT 1");
    $bateria = $conexion->query("SELECT bateria_id FROM baterias WHERE bateria_activa='SI' ORDER BY bateria_orden,bateria_id LIMIT 1");

    cotizadorLimpiarSesionNueva();
    $documentSaveToken = cotizadorCrearTokenGuardado();
    // La pagina completa consume esta marca durante el mismo request; aqui la
    // intencion viaja en JSON para que el cliente futuro cierre el panel.
    unset($_SESSION['forzar_panel_presupuesto_oculto']);

    responderInicioNueva(200, array(
        'ok' => true,
        'document_save_token' => $documentSaveToken,
        'base_vigente' => array(
            'lista_id' => (int)($base['lista_id'] ?? 0),
            'descripcion' => descripcionBaseBejerman($base)
        ),
        'descuentos' => $descuentos,
        'defaults' => array(
            'modo_precios_documento' => 'ACTUALIZAR',
            'id_tension' => (int)($tension ? ($tension->fetch_assoc()['tension_id'] ?? 0) : 0),
            'id_bateria' => (int)($bateria ? ($bateria->fetch_assoc()['bateria_id'] ?? 0) : 0),
            'cantidad_equipos' => 1,
            'repuesto_descuento_busqueda' => in_array($descuentos['repuestos'], array(0, 15), true) ? $descuentos['repuestos'] : 30,
            'forzar_panel_presupuesto_oculto' => true
        )
    ));
} catch (Throwable $error) {
    error_log('Inicio de nueva cotizacion: ' . $error->getMessage());
    responderInicioNueva(500, array('ok' => false, 'error' => 'No se pudo iniciar la cotización.'));
}
