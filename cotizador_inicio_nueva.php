<?php
/** Estado de inicio compartido por la pagina y el endpoint JSON. */

function cotizadorDescuentosIniciales(mysqli $conexion): array
{
    $control = array(
        (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_1', 30)),
        (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_2', 10)),
        (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_3', 10))
    );

    return array(
        'control' => $control,
        'senalizacion' => array(
            (int)round(parametroComercial($conexion, 'SENALIZACION_DESCUENTO_1', $control[0])),
            (int)round(parametroComercial($conexion, 'SENALIZACION_DESCUENTO_2', $control[1])),
            (int)round(parametroComercial($conexion, 'SENALIZACION_DESCUENTO_3', $control[2]))
        ),
        'accesorios' => array(
            (int)round(parametroComercial($conexion, 'ACCESORIOS_DESCUENTO_1', $control[0])),
            (int)round(parametroComercial($conexion, 'ACCESORIOS_DESCUENTO_2', $control[1])),
            (int)round(parametroComercial($conexion, 'ACCESORIOS_DESCUENTO_3', $control[2]))
        ),
        'repuestos' => (int)round(parametroComercial($conexion, 'REPUESTOS_DESCUENTO_PREDETERMINADO', 30))
    );
}

function cotizadorLimpiarSesionNueva(): void
{
    unset($_SESSION['form_data'], $_SESSION['form_error']);
    $_SESSION['forzar_panel_presupuesto_oculto'] = true;
}

function cotizadorCrearTokenGuardado(): string
{
    if (!isset($_SESSION['document_save_tokens']) || !is_array($_SESSION['document_save_tokens'])) {
        $_SESSION['document_save_tokens'] = array();
    }
    $ahoraToken = time();
    foreach ($_SESSION['document_save_tokens'] as $tokenGuardado => $creadoEn) {
        if (($ahoraToken - (int)$creadoEn) > 7200) unset($_SESSION['document_save_tokens'][$tokenGuardado]);
    }
    while (count($_SESSION['document_save_tokens']) >= 20) array_shift($_SESSION['document_save_tokens']);
    $documentSaveToken = bin2hex(random_bytes(24));
    $_SESSION['document_save_tokens'][$documentSaveToken] = $ahoraToken;
    return $documentSaveToken;
}
