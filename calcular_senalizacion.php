<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
$conexion->set_charset('utf8mb4');
require_once 'sistema_comercial.php';
require_once 'senalizacion_cabina.php';
asegurarSistemaComercial($conexion);
function e($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function fallo($m){http_response_code(422);echo '<!doctype html><html><head><meta charset="utf-8"><style>body{font-family:Arial;padding:12px}.e{background:#fdecec;border:1px solid #f4b6b6;color:#842029;padding:12px;border-radius:8px}</style></head><body><div class="e"><b>No se pudo calcular.</b><br>'.e($m).'</div></body></html>';exit;}
function money($v){return '$ '.number_format(ceil((float)$v),0,',','.');}
if($_SERVER['REQUEST_METHOD']!=='POST') fallo('Solicitud invalida.');
$listaId=(int)($_POST['senal_lista_id']??0);$clienteId=(int)($_POST['senal_cliente_id']??0);
// v324: Cliente opcional para el cálculo. Solo la base de precios es obligatoria.
if(!$listaId) fallo('Seleccione una base de precios.');
try{
  $calc=calcularLineasSenalizacionCabina($conexion,$listaId,$_POST);
  $desc=aplicarDescuentosSenalizacion($calc['total_bruto'],$_POST);
}catch(Throwable $x){fallo($x->getMessage());}
/* Total comercial definitivo: cada unitario descontado se redondea hacia arriba,
 * igual que al emitir la cotizacion/pedido. */
$totalSenalizacionEntero = 0.0;
foreach ($calc['lineas'] as $lineaEntera) {
  $unitarioEntero = ceil((float)$lineaEntera['unitario'] * (float)$desc['factor']);
  $totalSenalizacionEntero += ceil($unitarioEntero * (float)$lineaEntera['cantidad']);
}
$totalSenalizacionEntero = ceil($totalSenalizacionEntero);
$lineaBase = null; $lineaParada = null; $lineaIndicador = null; $lineaAppind = null;
$cantidadHistoricos = 0;
foreach($calc['lineas'] as $lineaResumen){
  if(!empty($lineaResumen['precio_historico'])) $cantidadHistoricos++;
  $conceptoResumen = (string)($lineaResumen['concepto'] ?? '');
  if($conceptoResumen === 'Base botonera de cabina') $lineaBase = $lineaResumen;
  elseif($conceptoResumen === 'Adicional por parada en botonera de cabina') $lineaParada = $lineaResumen;
  elseif($conceptoResumen === 'Indicador de posicion') $lineaIndicador = $lineaResumen;
  elseif(strtoupper(trim((string)($lineaResumen['codigo'] ?? ''))) === 'APPIND') $lineaAppind = $lineaResumen;
}
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<style>
*{box-sizing:border-box}
html,body{margin:0;min-height:100%;background:#fff}
html{overflow-y:auto;scrollbar-gutter:stable}
body{font-family:Arial,sans-serif;padding:8px;color:#202124;font-size:11px}
table{width:100%;border-collapse:collapse}
td{border-bottom:1px solid #e5e7eb;padding:5px 4px;vertical-align:top}
.n{width:22px;color:#6b7280;text-align:right}
.concepto{line-height:1.25}
.importe{text-align:right;white-space:nowrap;font-weight:bold}
.meta{font-size:10px;color:#4b5563;margin-top:2px;line-height:1.35}
.estado{font-size:9px;color:#0f6b3d;margin-top:3px;font-weight:bold}
.historico{color:#775900}.bonificado{color:#12643f}
.tecnico{border:1px solid #b8c7dc;background:#eef5ff;border-radius:6px;padding:8px;margin-bottom:9px}
.tecnico strong{display:block;font-size:12px;margin-bottom:3px}
.separador{font-weight:bold;font-size:12px;margin:12px 0 5px;border-bottom:2px solid #343a40;padding-bottom:4px}
.detalle{border:1px solid #e1e5ea;border-radius:5px;padding:6px;margin-bottom:5px;background:#fafbfc}
.detalle-titulo{font-weight:bold}
.detalle-meta{font-size:10px;color:#4b5563;margin-top:2px;line-height:1.35}
.detalle-total{font-weight:bold;color:#0f5132;margin-top:2px}
.aviso{background:#fff8e6;border:1px solid #f1d38a;color:#6f5311;border-radius:6px;padding:8px;margin:8px 0;font-size:10px;line-height:1.4}
.resumen{margin-top:10px;border-top:2px solid #343a40;padding-top:7px}
.fila-resumen{display:flex;justify-content:space-between;gap:8px;padding:4px 2px}
.total{font-size:14px;color:#198754;font-weight:bold;border-top:1px solid #ccc;margin-top:3px;padding-top:7px}
.nota{font-size:10px;color:#6b7280;margin:7px 2px 0}
</style>
</head>
<body data-total-senalizacion="<?= e((string)$totalSenalizacionEntero) ?>">
<div class="tecnico">
  <strong>Configuración cotizada</strong>
  <?php if(!empty($calc['modo_solo_comunicacion'])): ?>
    <div>Solo comunicación serie requerida por Control.</div>
    <div>Sin botonera de cabina · P3540 × <?= number_format((float)($calc['lineas'][0]['cantidad'] ?? 0),0,',','.') ?></div>
  <?php elseif(!empty($calc['modo_sin_botonera'])): ?>
    <div>Señalización sin botonera de cabina · componentes independientes.</div>
    <div>Puede incluir pulsadores exteriores e indicadores sueltos. Cada elemento se valoriza contra la base Bejerman seleccionada.</div>
  <?php else: ?>
    <div><?=e($calc['matriz']['modelo_pulsador_nombre'])?> · <?=e($calc['matriz']['tipo_puerta'])?> · <?=e($calc['matriz']['tension_modulo_nombre'])?> · <?=e($calc['matriz']['borne_nombre'])?> · <?=e($calc['matriz']['color_registro_nombre'])?> · <?=e($calc['matriz']['tecla_nombre'])?></div>
    <?php $ppb=array_values((array)($calc['paradas_por_botonera']??array())); $ppbTxt=array(); foreach($ppb as $i=>$p)$ppbTxt[]='C'.($i+1).': '.(int)$p; ?>
    <div>Botoneras: <?= number_format((float)($lineaBase['cantidad'] ?? 0),0,',','.') ?> | Paradas por coche: <?= e($ppbTxt?implode(' / ',$ppbTxt):(string)(int)$calc['paradas_totales']) ?></div>
  <?php endif; ?>
</div>

<?php if(empty($calc['modo_solo_comunicacion']) && empty($calc['modo_sin_botonera'])): ?>
<div class="separador">Cómo se realizó el cálculo de botonera</div>
<div class="detalle">
  <div class="detalle-titulo">Base de botonera<?php if($lineaBase): ?> — <?= e($lineaBase['codigo']) ?><?php endif; ?></div>
  <div class="detalle-meta">La base incluye hasta 2 paradas por botonera.</div>
  <?php if($lineaBase): ?>
  <div class="detalle-meta">Cantidad: <?= number_format((float)$lineaBase['cantidad'],0,',','.') ?> | Unitario: <?= money($lineaBase['unitario']) ?></div>
  <div class="detalle-total">Total: <?= money($lineaBase['total']) ?></div>
  <?php endif; ?>
</div>
<div class="detalle">
  <div class="detalle-titulo">Adicional por parada<?php if($lineaParada): ?> — <?= e($lineaParada['codigo']) ?><?php endif; ?></div>
  <?php $ppb=array_values((array)($calc['paradas_por_botonera']??array())); $ppbTxt=array(); foreach($ppb as $i=>$p)$ppbTxt[]='C'.($i+1).': '.(int)$p; ?>
  <div class="detalle-meta">Paradas por coche: <?= e($ppbTxt?implode(' / ',$ppbTxt):(string)(int)$calc['paradas_totales']) ?> | Paradas cubiertas por las bases: <?= (int)$calc['paradas_incluidas_base'] ?> | Adicionales totales: <?= (int)$calc['paradas_adicionales'] ?></div>
  <?php if((int)$calc['paradas_adicionales']>0 && $lineaParada): ?>
    <div class="detalle-meta">Cantidad total: <?= number_format((float)$lineaParada['cantidad'],0,',','.') ?> | Unitario: <?= money($lineaParada['unitario']) ?></div>
    <?php if(trim((string)($lineaParada['formula'] ?? ''))!==''): ?><div class="detalle-meta">Cálculo: <?= e($lineaParada['formula']) ?></div><?php endif; ?>
    <div class="detalle-total">Total: <?= money($lineaParada['total']) ?></div>
  <?php else: ?>
    <div class="detalle-meta">No corresponde adicional: la base cubre las paradas indicadas.</div>
    <div class="detalle-total">Total: $ 0</div>
  <?php endif; ?>
</div>
<?php if($lineaIndicador && $lineaAppind): ?>
<div class="detalle" style="border-color:#efd28a;background:#fffaf0">
  <div class="detalle-titulo">Material de hueco / APPIND — <?= e($lineaAppind['codigo']) ?></div>
  <div class="detalle-meta">Indicador autónomo/electromecánico. Los imanes cortos de 5 cm se calculan a razón de 1 por parada.</div>
  <div class="detalle-meta">Cantidad: <?= number_format((float)$lineaAppind['cantidad'],0,',','.') ?> parada(s) | Unitario APPIND: <?= money($lineaAppind['unitario']) ?></div>
  <?php if(trim((string)($lineaAppind['formula'] ?? ''))!==''): ?><div class="detalle-meta">Cálculo: <?= e($lineaAppind['formula']) ?></div><?php endif; ?>
  <div class="detalle-total">Total APPIND: <?= money($lineaAppind['total']) ?></div>
  <div class="detalle-meta">En la OF de Señalización se traduce a material de hueco: transformador 220/12V, imanes cortos 5 cm, imán largo 25 cm, A2142C e instructivo.</div>
</div>
<?php endif; ?>
<?php elseif(!empty($calc['modo_solo_comunicacion'])): ?>
<div class="separador">Cómo se realizó el cálculo</div>
<div class="detalle">
  <div class="detalle-titulo">Placa de comunicación serie — P3540</div>
  <div class="detalle-meta">La comunicación serie en cabina fue seleccionada en Control. No se exige una botonera cuando Señalización solo lleva esta placa.</div>
  <?php if(!empty($calc['lineas'][0])): ?>
  <div class="detalle-meta">Cantidad: <?= number_format((float)$calc['lineas'][0]['cantidad'],0,',','.') ?> | Unitario: <?= money($calc['lineas'][0]['unitario']) ?></div>
  <div class="detalle-total">Total: <?= money($calc['lineas'][0]['total']) ?></div>
  <?php endif; ?>
</div>
<?php elseif(!empty($calc['modo_sin_botonera'])): ?>
<div class="separador">Cómo se realizó el cálculo</div>
<div class="detalle">
  <div class="detalle-titulo">Señalización sin botonera de cabina</div>
  <div class="detalle-meta">Se valorizan directamente los pulsadores exteriores y/o indicadores sueltos agregados.</div>
  <div class="detalle-meta">En modo electromecánico, el Maestro A4000 genera APPIND/material de hueco y los demás indicadores quedan como Repetidores A4400.</div>
  <div class="detalle-total">Total bruto: <?= money($calc['total_bruto'] ?? 0) ?></div>
</div>
<?php endif; ?>

<?php if($cantidadHistoricos>0): ?><div class="aviso"><b>Atención:</b> <?= (int)$cantidadHistoricos ?> concepto(s) usaron el último costo Bejerman histórico válido del mismo código porque no tenían precio válido en la base seleccionada.</div><?php endif; ?>

<div class="separador">Desglose completo de Señalización</div>
<table><tbody>
<?php foreach($calc['lineas'] as $i=>$l): ?>
<tr>
  <td class="n"><?= $i+1 ?>.</td>
  <td class="concepto">
    <?=e($l['concepto'])?><?php if(trim((string)$l['codigo'])!==''): ?> — <?=e($l['codigo'])?><?php endif; ?>
    <div class="meta">Cantidad: <?=number_format((float)$l['cantidad'],0,',','.')?> | Unitario: <?=money($l['unitario'])?></div>
    <?php if(trim((string)($l['formula'] ?? ''))!==''): ?><div class="meta">Cálculo: <?=e($l['formula'])?></div><?php endif; ?>
    <?php if(!empty($l['bonificado'])): ?><div class="estado bonificado">BONIFICADO · referencia <?=money($l['precio_referencia']??0)?></div><?php elseif(!empty($l['precio_historico'])): ?><div class="estado historico">PRECIO HISTÓRICO · Bejerman <?=e($l['bejerman_fecha']??'')?><?php if(!empty($l['bejerman_lista_id'])): ?> · base #<?= (int)$l['bejerman_lista_id'] ?><?php endif; ?></div><?php endif; ?>
  </td>
  <td class="importe"><?=money($l['total'])?></td>
</tr>
<?php endforeach; ?>
<?php if(empty($calc['modo_solo_comunicacion']) && (int)$calc['paradas_adicionales']===0): ?>
<tr><td class="n">—</td><td class="concepto">Adicional por parada<div class="meta">No corresponde: la base incluye hasta 2 paradas.</div></td><td class="importe">$ 0</td></tr>
<?php endif; ?>
</tbody></table>

<?php if(!empty($calc['caracteristicas'])): ?>
<div class="separador">Características incluidas / sin cargo</div>
<?php foreach($calc['caracteristicas'] as $c): ?><div class="detalle-meta">• <?=e($c)?></div><?php endforeach; ?>
<?php endif; ?>

<div class="resumen">
  <div class="fila-resumen"><span>Subtotal señalización</span><strong><?=money($calc['total_bruto'])?></strong></div>
  <div class="fila-resumen"><span>Descuentos</span><span><?=number_format($desc['d1'],0)?>% + <?=number_format($desc['d2'],0)?>% + <?=number_format($desc['d3'],0)?>%</span></div>
  <div class="fila-resumen total"><span>Total señalización</span><span><?=money($totalSenalizacionEntero)?></span></div>
</div>
<div class="nota">El detalle se desplaza con la barra vertical normal, igual que el cálculo de Control.</div>
</body>
</html>
