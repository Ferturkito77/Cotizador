<?php
require_once __DIR__ . '/app_security.php';
automacConfigurarSesionSegura();
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
if (!in_array($ip, array('127.0.0.1', '::1'), true)) {
    http_response_code(403);
    exit('La recuperación de administrador solo está disponible desde la computadora local.');
}

$error = '';
$ok = '';
$login = strtolower(trim((string)($_POST['usuario'] ?? 'famari')));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    automacValidarCsrf(true);
    $clave1 = (string)($_POST['clave_nueva'] ?? '');
    $clave2 = (string)($_POST['clave_repetir'] ?? '');

    if ($login === '' || $clave1 === '' || $clave2 === '') {
        $error = 'Completá todos los campos.';
    } elseif ($clave1 !== $clave2) {
        $error = 'Las contraseñas no coinciden.';
    } elseif (strlen($clave1) < 10 || !preg_match('/[A-Za-z]/', $clave1) || !preg_match('/[0-9]/', $clave1)) {
        $error = 'La contraseña debe tener al menos 10 caracteres e incluir letras y números.';
    } else {
        $st = $conexion->prepare("SELECT usuario_id FROM usuarios WHERE usuario_login=? AND usuario_rol='ADMINISTRADOR' AND usuario_activo='SI' LIMIT 1");
        if (!$st) {
            $error = 'No se pudo validar el usuario administrador.';
        } else {
            $st->bind_param('s', $login);
            $st->execute();
            $usuario = $st->get_result()->fetch_assoc();
            $st->close();

            if (!$usuario) {
                $error = 'No se encontró un administrador activo con ese usuario.';
            } else {
                $hash = password_hash($clave1, PASSWORD_DEFAULT);
                $id = (int)$usuario['usuario_id'];
                $st = $conexion->prepare("UPDATE usuarios SET usuario_password=?, usuario_debe_cambiar_clave='NO', usuario_modificado=NOW() WHERE usuario_id=? AND usuario_rol='ADMINISTRADOR'");
                if (!$st) {
                    $error = 'No se pudo preparar el cambio de contraseña.';
                } else {
                    $st->bind_param('si', $hash, $id);
                    $st->execute();
                    $afectadas = $st->affected_rows;
                    $st->close();

                    if ($afectadas < 0) {
                        $error = 'No se pudo actualizar la contraseña.';
                    } else {
                        // Mantiene el bloqueo de 15 minutos como regla general,
                        // pero limpia los fallos de este administrador/IP tras un reset local válido.
                        $tieneRateLimit = function_exists('esquemaTablaExiste') && esquemaTablaExiste($conexion, 'login_intentos');
                        $ipDb = mb_substr($ip, 0, 64, 'UTF-8');
                        $st = $tieneRateLimit ? $conexion->prepare("DELETE FROM login_intentos WHERE usuario_login=? AND ip=? AND exitoso=0") : false;
                        if ($st) {
                            $st->bind_param('ss', $login, $ipDb);
                            $st->execute();
                            $st->close();
                        }
                        $ok = 'Contraseña actualizada. Ya podés ingresar con la nueva contraseña.';
                    }
                }
            }
        }
    }
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recuperar administrador</title><link rel="stylesheet" href="automac-ui.css?v=20260819-v131"><style>*{box-sizing:border-box}:root{--g:#117a4b;--n:#172b3a}body{font-family:Inter,Segoe UI,Arial,sans-serif;background:radial-gradient(circle at 20% 20%,#dcefe5,transparent 38%),linear-gradient(135deg,#eef4f1,#e5ebef);margin:0;min-height:100vh;display:grid;place-items:center;color:#1d2935}.caja{width:min(480px,92vw);background:rgba(255,255,255,.96);border:1px solid #d5e0e4;border-radius:20px;padding:34px;box-shadow:0 24px 70px rgba(23,43,58,.18)}h1{margin:0 0 6px;font-size:25px;color:var(--n)}.sub{color:#667684;margin:0 0 22px;line-height:1.45}.campo{margin-bottom:15px}label{display:block;font-weight:700;font-size:12px;margin-bottom:6px;color:#344654}input{width:100%;height:45px;padding:9px 12px;border:1px solid #c7d3da;border-radius:10px;font-size:15px;outline:none}input:focus{border-color:var(--g);box-shadow:0 0 0 3px rgba(17,122,75,.13)}button{width:100%;height:45px;border:0;border-radius:10px;background:var(--g);color:#fff;font-weight:800;font-size:13px;cursor:pointer}.error,.ok{padding:11px;border-radius:9px;margin-bottom:14px}.error{background:#fff0f1;color:#842029;border:1px solid #f1c4c8}.ok{background:#eaf7ef;color:#176b40;border:1px solid #b9dfc7}.volver{margin:14px 0 0;text-align:center;font-size:13px}.volver a{color:#117a4b;text-decoration:none;font-weight:700}.nota{font-size:12px;color:#667684;margin-top:8px;line-height:1.4}</style></head><body><div class="caja"><h1>Recuperar administrador</h1><p class="sub">Disponible únicamente desde esta computadora. El bloqueo normal de 6 intentos durante 15 minutos se mantiene para el inicio de sesión.</p><?php if($error): ?><div class="error"><?=htmlspecialchars($error)?></div><?php endif; ?><?php if($ok): ?><div class="ok"><?=htmlspecialchars($ok)?></div><?php endif; ?><form method="post"><?= automacCsrfInput() ?><div class="campo"><label>Usuario administrador</label><input name="usuario" value="<?=htmlspecialchars($login)?>" autocomplete="username" required autofocus></div><div class="campo"><label>Nueva contraseña</label><input type="password" name="clave_nueva" autocomplete="new-password" required></div><div class="campo"><label>Repetir contraseña</label><input type="password" name="clave_repetir" autocomplete="new-password" required></div><button>GUARDAR NUEVA CONTRASEÑA</button><div class="nota">Mínimo 10 caracteres, con letras y números.</div></form><p class="volver"><a href="login.php">Volver al ingreso</a></p></div></body></html>
