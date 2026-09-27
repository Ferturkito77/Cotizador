<?php
// v362 · aviso de agenda comercial por responsable asignado.
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
if (!isset($conexion) || !($conexion instanceof mysqli) || empty($_SESSION['usuario_id'])) return;
require_once __DIR__.'/comercial_crm.php';
try { asegurarComercialCrm($conexion); } catch(Throwable $e) { return; }
$uid=(int)$_SESSION['usuario_id'];
$nombre=(string)($_SESSION['usuario_nombre']??'');
$sql="SELECT
 (SELECT COUNT(*) FROM comercial_seguimientos WHERE estado='PENDIENTE' AND responsable_id=? AND proximo_contacto IS NOT NULL AND proximo_contacto<NOW()) vencidos_seg,
 (SELECT COUNT(*) FROM comercial_oportunidades WHERE estado NOT IN('GANADA','PERDIDA') AND responsable_id=? AND proximo_contacto IS NOT NULL AND proximo_contacto<NOW()) vencidos_op,
 (SELECT COUNT(*) FROM comercial_seguimientos WHERE estado='PENDIENTE' AND responsable_id=? AND DATE(proximo_contacto)=CURDATE()) hoy_seg,
 (SELECT COUNT(*) FROM comercial_oportunidades WHERE estado NOT IN('GANADA','PERDIDA') AND responsable_id=? AND DATE(proximo_contacto)=CURDATE()) hoy_op";
$st=$conexion->prepare($sql); if(!$st)return; $st->bind_param('iiii',$uid,$uid,$uid,$uid);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();
$v=(int)$x['vencidos_seg']+(int)$x['vencidos_op'];$h=(int)$x['hoy_seg']+(int)$x['hoy_op'];
if($v+$h<=0)return;
?>
<style>.agenda-cotizador-v362{max-width:1880px;margin:8px auto 4px;padding:8px 12px;border:1px solid #e8c77a;border-left:4px solid #d99000;border-radius:9px;background:#fff8e6;color:#614300;display:flex;align-items:center;justify-content:space-between;gap:12px;font:600 12px Arial,sans-serif}.agenda-cotizador-v362 strong{font-size:13px}.agenda-cotizador-v362 a{display:inline-flex;padding:6px 10px;border-radius:7px;background:#176b45;color:#fff!important;text-decoration:none;font-weight:800;white-space:nowrap}</style>
<div class="agenda-cotizador-v362"><div><strong>Agenda comercial · <?=htmlspecialchars($nombre,ENT_QUOTES,'UTF-8')?></strong> · <?php if($v):?><span><?=$v?> vencido<?=$v===1?'':'s'?></span><?php endif;?><?php if($v&&$h):?> · <?php endif;?><?php if($h):?><span><?=$h?> para hoy</span><?php endif;?></div><a href="comercial.php?tab=seguimiento&responsable=<?=$uid?>">Ver mi agenda</a></div>
