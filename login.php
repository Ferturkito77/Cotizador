<?php
require_once __DIR__ . '/app_security.php';
automacConfigurarSesionSegura();
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
if (estaAutenticado()) redireccionInicioPorRol();
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    automacValidarCsrf(true);
    $login = strtolower(trim((string)($_POST['usuario'] ?? '')));
    $clave = (string)($_POST['clave'] ?? '');
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64, 'UTF-8');

    // v427 - Rate limiting persistente por usuario + IP.
    $bloqueado = false;
    $tieneRateLimit = function_exists('esquemaTablaExiste') && esquemaTablaExiste($conexion,'login_intentos');
    $st = $tieneRateLimit ? $conexion->prepare("SELECT COUNT(*) intentos FROM login_intentos WHERE usuario_login=? AND ip=? AND exitoso=0 AND fecha>=DATE_SUB(NOW(), INTERVAL 15 MINUTE)") : false;
    if ($st) {
        $st->bind_param('ss', $login, $ip); $st->execute();
        $bloqueado = ((int)($st->get_result()->fetch_assoc()['intentos'] ?? 0) >= 6);
        $st->close();
    }
    if ($bloqueado) {
        $error = 'Demasiados intentos fallidos. Esperá 15 minutos antes de volver a intentar.';
    } else {
        $stmt = $conexion->prepare("SELECT * FROM usuarios WHERE usuario_login=? AND usuario_activo='SI' LIMIT 1");
        $stmt->bind_param('s', $login);
        $stmt->execute();
        $usuario = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        $okLogin = $usuario && password_verify($clave, $usuario['usuario_password']);

        $st = $tieneRateLimit ? $conexion->prepare("INSERT INTO login_intentos(usuario_login,ip,exitoso) VALUES(?,?,?)") : false;
        if ($st) { $exito = $okLogin ? 1 : 0; $st->bind_param('ssi',$login,$ip,$exito); @$st->execute(); $st->close(); }

        if ($okLogin) {
            session_regenerate_id(true);
            $_SESSION['usuario_id'] = (int)$usuario['usuario_id'];
            $_SESSION['usuario_login'] = $usuario['usuario_login'];
            $_SESSION['usuario_nombre'] = $usuario['usuario_nombre'];
            $_SESSION['usuario_rol'] = $usuario['usuario_rol'];
            $_SESSION['usuario_debe_cambiar_clave'] = $usuario['usuario_debe_cambiar_clave'];
            $id = (int)$usuario['usuario_id'];
            $st = $conexion->prepare("UPDATE usuarios SET usuario_ultimo_acceso=NOW() WHERE usuario_id=?");
            if ($st) { $st->bind_param('i',$id); $st->execute(); $st->close(); }
            $st = $tieneRateLimit ? $conexion->prepare("DELETE FROM login_intentos WHERE usuario_login=? AND ip=? AND exitoso=0") : false;
            if ($st) { $st->bind_param('ss',$login,$ip); $st->execute(); $st->close(); }
            require_once __DIR__ . '/auditoria_actividad.php';
            asegurarAuditoriaActividad($conexion);
            auditoriaRegistrar($conexion, 'Inicio de sesión', 'SISTEMA', 'Ingreso correcto al cotizador.');
            redireccionInicioPorRol();
        }
        $error = 'Usuario o contraseña incorrectos.';
    }
}
$ipActual = (string)($_SERVER['REMOTE_ADDR'] ?? '');
$recuperacionLocalDisponible = in_array($ipActual, array('127.0.0.1', '::1'), true);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ingreso al cotizador</title><link rel="stylesheet" href="automac-ui.css?v=20260819-v131"><style>*{box-sizing:border-box}:root{--g:#117a4b;--n:#172b3a}body{font-family:Inter,Segoe UI,Arial,sans-serif;background:radial-gradient(circle at 20% 20%,#dcefe5,transparent 38%),linear-gradient(135deg,#eef4f1,#e5ebef);margin:0;min-height:100vh;display:grid;place-items:center;color:#1d2935}.caja{width:min(440px,92vw);background:rgba(255,255,255,.96);border:1px solid #d5e0e4;border-radius:20px;padding:34px;box-shadow:0 24px 70px rgba(23,43,58,.18)}.caja:before{content:'A';display:grid;place-items:center;width:52px;height:52px;border-radius:15px;background:linear-gradient(135deg,#117a4b,#65b889);color:#fff;font-weight:900;font-size:27px;margin-bottom:18px;box-shadow:0 10px 25px rgba(17,122,75,.25)}h1{margin:0 0 4px;font-size:27px;letter-spacing:.05em;color:var(--n)}.sub{color:#667684;margin:0 0 24px}.campo{margin-bottom:15px}label{display:block;font-weight:700;font-size:12px;margin-bottom:6px;color:#344654}input{width:100%;height:45px;padding:9px 12px;border:1px solid #c7d3da;border-radius:10px;font-size:15px;outline:none}input:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(17,122,75,.13)}button{width:100%;height:45px;border:0;border-radius:10px;background:var(--g);color:#fff;font-weight:800;font-size:13px;cursor:pointer;box-shadow:0 9px 20px rgba(17,122,75,.22)}.error{background:#fff0f1;color:#842029;padding:11px;border:1px solid #f1c4c8;border-radius:9px;margin-bottom:14px}.recuperar{margin:14px 0 0;text-align:center;font-size:13px}.recuperar a{color:#117a4b;text-decoration:none;font-weight:700}.recuperar a:hover{text-decoration:underline}</style></head><body class="automac-login-v131"><div class="caja"><h1>AUTOMAC</h1><p class="sub">Ingreso al cotizador</p><?php if($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?><form method="post"><?= automacCsrfInput() ?><div class="campo"><label>Usuario</label><input name="usuario" autocomplete="username" required autofocus></div><div class="campo"><label>Contraseña</label><input type="password" name="clave" autocomplete="current-password" required></div><button>INGRESAR</button></form><?php if ($recuperacionLocalDisponible): ?><p class="recuperar"><a href="recuperar_admin.php">Olvidé mi contraseña de administrador</a></p><?php endif; ?></div></body></html>
