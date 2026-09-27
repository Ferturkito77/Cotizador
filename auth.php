<?php
require_once __DIR__ . '/app_security.php';
require_once __DIR__ . '/observabilidad.php';
automacConfigurarSesionSegura();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function asegurarSistemaUsuarios(mysqli $conexion): void
{
    automacProtegerSolicitudMutante();
    require_once __DIR__ . '/schema_guard.php';
    try {
        verificarTablaColumnas($conexion, 'usuarios', array(
            'usuario_id','usuario_login','usuario_nombre','usuario_password','usuario_rol',
            'usuario_activo','usuario_debe_cambiar_clave'
        ), 'migracion_consolidacion_v59.sql');
    } catch (Throwable $e) {
        die('La base de datos no tiene el esquema requerido: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    /* Seguridad v511: no se crean usuarios ni credenciales automáticamente.
       La creación del primer administrador debe realizarse mediante un proceso de
       instalación controlado, separado de la autenticación normal. */

    /* v363: auditoría global de actividad. Registra sólo acciones/pantallas humanas;
       los endpoints de cálculo automático quedan excluidos. */
    require_once __DIR__ . '/auditoria_actividad.php';
    asegurarAuditoriaActividad($conexion);
    auditoriaRegistrarSolicitudAutomatica($conexion);
}

function usuarioActual(): ?array
{
    if (empty($_SESSION['usuario_id'])) {
        return null;
    }
    return array(
        'id' => (int)$_SESSION['usuario_id'],
        'login' => (string)($_SESSION['usuario_login'] ?? ''),
        'nombre' => (string)($_SESSION['usuario_nombre'] ?? ''),
        'rol' => (string)($_SESSION['usuario_rol'] ?? ''),
        'debe_cambiar_clave' => (string)($_SESSION['usuario_debe_cambiar_clave'] ?? 'NO')
    );
}

function estaAutenticado(): bool
{
    return usuarioActual() !== null;
}

function esRol(string $rol): bool
{
    $u = usuarioActual();
    return $u && $u['rol'] === $rol;
}

function exigirLogin(): void
{
    if (!estaAutenticado()) {
        $destino = basename((string)($_SERVER['REQUEST_URI'] ?? 'index.php'));
        header('Location: login.php?volver=' . urlencode($destino));
        exit;
    }
    if (($_SESSION['usuario_debe_cambiar_clave'] ?? 'NO') === 'SI' && basename($_SERVER['PHP_SELF']) !== 'cambiar_clave.php') {
        header('Location: cambiar_clave.php');
        exit;
    }
}

function exigirRoles(array $roles): void
{
    exigirLogin();
    $rol = (string)($_SESSION['usuario_rol'] ?? '');
    if (!in_array($rol, $roles, true)) {
        http_response_code(403);
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><title>Acceso denegado</title><style>body{font-family:Arial;background:#f4f4f9;padding:40px}.c{max-width:650px;margin:auto;background:#fff;padding:24px;border-radius:8px;border:1px solid #ddd}a{display:inline-block;margin-top:15px;padding:9px 13px;background:#343a40;color:#fff;text-decoration:none;border-radius:5px}</style><div class="c"><h1>Acceso denegado</h1><p>Su usuario no tiene permiso para ingresar a esta sección.</p><a href="inicio.php">Volver al inicio</a></div></html>';
        exit;
    }
}

function redireccionInicioPorRol(): void
{
    exigirLogin();
    if (esRol('TECNICO')) {
        header('Location: ordenes_fabricacion.php');
    } else {
        header('Location: index.php');
    }
    exit;
}

function enlaceUsuarioHtml(): string
{
    $u = usuarioActual();
    if (!$u) return '';
    $nombre = htmlspecialchars($u['nombre'], ENT_QUOTES, 'UTF-8');
    $rol = htmlspecialchars(ucfirst(strtolower($u['rol'])), ENT_QUOTES, 'UTF-8');
    return '<span style="margin-left:auto;padding:8px 10px;background:#e9ecef;border-radius:5px;font-size:12px"><strong>' . $nombre . '</strong> · ' . $rol . '</span>'
        . (esRol('ADMINISTRADOR') ? '<a href="administrar_usuarios.php">Usuarios</a>' : '')
        . '<a href="cambiar_clave.php">Mi contraseña</a><a href="logout.php">Salir</a>';
}
