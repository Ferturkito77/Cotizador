<?php
session_start();
if (!empty($_SESSION['usuario_id'])) {
    require_once 'conexion.php';
    require_once 'auditoria_actividad.php';
    asegurarAuditoriaActividad($conexion);
    auditoriaRegistrar($conexion,'Cierre de sesión','SISTEMA','El usuario cerró su sesión.');
}
$_SESSION = array();
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();
header('Location: login.php');
exit;
