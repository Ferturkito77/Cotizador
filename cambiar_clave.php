<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirLogin();
$error='';$ok='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    automacValidarCsrf();
    $actual=(string)($_POST['actual']??'');$nueva=(string)($_POST['nueva']??'');$repetir=(string)($_POST['repetir']??'');
    $id=(int)$_SESSION['usuario_id'];
    $st=$conexion->prepare('SELECT usuario_password FROM usuarios WHERE usuario_id=?');$st->bind_param('i',$id);$st->execute();$fila=$st->get_result()->fetch_assoc();$st->close();
    if(!$fila||!password_verify($actual,$fila['usuario_password']))$error='La contraseña actual no es correcta.';
    elseif(strlen($nueva)<10)$error='La nueva contraseña debe tener al menos 10 caracteres.';
    elseif(!preg_match('/[A-Za-z]/',$nueva)||!preg_match('/[0-9]/',$nueva))$error='La nueva contraseña debe combinar letras y números.';
    elseif($nueva!==$repetir)$error='Las nuevas contraseñas no coinciden.';
    else{$hash=password_hash($nueva,PASSWORD_DEFAULT);$st=$conexion->prepare("UPDATE usuarios SET usuario_password=?,usuario_debe_cambiar_clave='NO',usuario_modificado=NOW() WHERE usuario_id=?");$st->bind_param('si',$hash,$id);$st->execute();$st->close();$_SESSION['usuario_debe_cambiar_clave']='NO';$ok='Contraseña actualizada correctamente.';}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><title>Cambiar contraseña</title><style>body{font-family:Arial;background:#f4f4f9;margin:25px}.c{max-width:520px;margin:auto;background:#fff;padding:22px;border-radius:8px;border:1px solid #ddd}label{display:block;font-weight:bold;margin:12px 0 4px}input{width:100%;height:36px;padding:6px;box-sizing:border-box}.btn,a{display:inline-block;margin-top:15px;padding:9px 13px;border:0;border-radius:5px;background:#0d6efd;color:#fff;text-decoration:none;font-weight:bold}.gris{background:#6c757d}.msg{padding:10px;border-radius:5px}.e{background:#f8d7da;color:#842029}.o{background:#d1e7dd;color:#0f5132}</style></head><body><?php require __DIR__ . '/menu.php'; ?><div class="c"><h1>Cambiar contraseña</h1><?php if($error):?><div class="msg e"><?=htmlspecialchars($error)?></div><?php endif;?><?php if($ok):?><div class="msg o"><?=htmlspecialchars($ok)?></div><?php endif;?><form method="post"><?=automacCsrfInput()?><label>Contraseña actual</label><input type="password" name="actual" required><label>Nueva contraseña</label><input type="password" name="nueva" required><label>Repetir nueva contraseña</label><input type="password" name="repetir" required><button class="btn">GUARDAR</button> <a class="gris" href="inicio.php">VOLVER</a></form></div></body></html>
