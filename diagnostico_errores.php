<?php
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
require_once 'observabilidad.php';
function deE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$registros=automacObsLeer('errors',250);
$conteo=array();foreach($registros as $r){$n=(string)($r['nivel']??'OTRO');$conteo[$n]=($conteo[$n]??0)+1;}arsort($conteo);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Errores técnicos · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1180px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:14px;padding:18px;margin-bottom:14px}.muted{color:#64748b}.chips{display:flex;gap:8px;flex-wrap:wrap}.chip{padding:5px 9px;border-radius:999px;background:#eef4f8;font-weight:700}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:10px;border-bottom:1px solid #e5eaee;vertical-align:top}.msg{max-width:560px;word-break:break-word}.empty{padding:18px;background:#edf6f1;border-radius:10px;color:#17603a;font-weight:700}@media(max-width:760px){.table{font-size:12px}}
</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Errores técnicos</h1><p class="muted">Registro de advertencias y errores PHP. No reemplaza la visualización normal del error: solamente deja evidencia técnica para diagnóstico.</p><?php if($conteo):?><div class="chips"><?php foreach($conteo as $n=>$c):?><span class="chip"><?=deE($n)?>: <?=deE($c)?></span><?php endforeach;?></div><?php endif;?></section><section class="card"><?php if(!$registros):?><div class="empty">No hay errores técnicos registrados.</div><?php else:?><table class="table"><thead><tr><th>Fecha</th><th>Nivel</th><th>Ruta</th><th>Archivo</th><th>Mensaje</th></tr></thead><tbody><?php foreach(array_slice($registros,0,100) as $r):?><tr><td><?=deE(date('d/m/Y H:i:s',strtotime((string)($r['ts']??''))))?></td><td><strong><?=deE($r['nivel']??'')?></strong></td><td><?=deE($r['ruta']??'')?></td><td><?=deE(($r['archivo']??'').' : '.($r['linea']??''))?></td><td class="msg"><?=deE($r['mensaje']??'')?></td></tr><?php endforeach;?></tbody></table><?php endif;?></section></main></body></html>
