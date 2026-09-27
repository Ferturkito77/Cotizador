<?php
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
require_once 'observabilidad.php';
function drE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$registros = automacObsLeer('performance', 250);
$porRuta = array();
foreach ($registros as $r) {
    $ruta=(string)($r['ruta']??'desconocido');
    if(!isset($porRuta[$ruta]))$porRuta[$ruta]=array('n'=>0,'total'=>0.0,'max'=>0.0,'mem'=>0.0);
    $ms=(float)($r['duracion_ms']??0);$mem=(float)($r['memoria_mb']??0);
    $porRuta[$ruta]['n']++;$porRuta[$ruta]['total']+=$ms;$porRuta[$ruta]['max']=max($porRuta[$ruta]['max'],$ms);$porRuta[$ruta]['mem']=max($porRuta[$ruta]['mem'],$mem);
}
uasort($porRuta, function($a,$b){ return $b['max'] <=> $a['max']; });
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Rendimiento · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1180px;margin:20px auto;padding:0 18px 50px}.hero,.card{background:#fff;border:1px solid #dfe5ec;border-radius:14px;padding:18px;margin-bottom:14px}.muted{color:#64748b}.table{width:100%;border-collapse:collapse}.table th,.table td{text-align:left;padding:10px;border-bottom:1px solid #e5eaee}.num{text-align:right!important;font-variant-numeric:tabular-nums}.badge{display:inline-block;padding:4px 8px;border-radius:999px;background:#eef4f8;font-weight:700}.note{padding:12px;border-radius:10px;background:#fff9e8;border:1px solid #eed9a6;color:#6d5415}@media(max-width:760px){.table{font-size:12px}}
</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Rendimiento</h1><p class="muted">Mide solicitudes PHP lentas sin modificar cálculos ni consultas. Por defecto registra respuestas de 500 ms o más.</p><div class="note">Para registrar todas las solicitudes temporalmente, definir <strong>AUTOMAC_PERF_LOG_ALL=1</strong>. El log rota automáticamente al alcanzar 2 MB.</div></section><section class="card"><h2>Resumen por pantalla / endpoint</h2><?php if(!$porRuta):?><p class="muted">Todavía no hay solicitudes por encima del umbral. Eso es una buena señal. Usá normalmente el sistema y volvé a revisar.</p><?php else:?><table class="table"><thead><tr><th>Ruta</th><th class="num">Muestras</th><th class="num">Promedio</th><th class="num">Máximo</th><th class="num">Memoria pico</th></tr></thead><tbody><?php foreach($porRuta as $ruta=>$x):?><tr><td><strong><?=drE($ruta)?></strong></td><td class="num"><?=drE($x['n'])?></td><td class="num"><?=drE(number_format($x['total']/$x['n'],1,',','.'))?> ms</td><td class="num"><span class="badge"><?=drE(number_format($x['max'],1,',','.'))?> ms</span></td><td class="num"><?=drE(number_format($x['mem'],2,',','.'))?> MB</td></tr><?php endforeach;?></tbody></table><?php endif;?></section><section class="card"><h2>Últimas mediciones</h2><?php if(!$registros):?><p class="muted">Sin registros.</p><?php else:?><table class="table"><thead><tr><th>Fecha</th><th>Ruta</th><th>Método</th><th class="num">Tiempo</th><th class="num">Memoria</th><th class="num">HTTP</th></tr></thead><tbody><?php foreach(array_slice($registros,0,50) as $r):?><tr><td><?=drE(date('d/m/Y H:i:s',strtotime((string)$r['ts'])))?></td><td><?=drE($r['ruta']??'')?></td><td><?=drE($r['metodo']??'')?></td><td class="num"><?=drE($r['duracion_ms']??0)?> ms</td><td class="num"><?=drE($r['memoria_mb']??0)?> MB</td><td class="num"><?=drE($r['status']??'')?></td></tr><?php endforeach;?></tbody></table><?php endif;?></section></main></body></html>
