<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$mensaje=''; $tipo='ok';
$series=array(
    'COTIZACION_CONTROL'=>array('titulo'=>'Cotizaciones con control','prefijo'=>'C.','destino'=>'Al confirmarse generan una obra con formato #10, #11, #12…'),
    'COTIZACION_SUMINISTROS'=>array('titulo'=>'Cotizaciones sin control','prefijo'=>'R.','destino'=>'Repuestos, señalización, accesorios o IEP vendidos sin control'),
    'PEDIDO_OBRA'=>array('titulo'=>'Obras / pedidos con control','prefijo'=>'#','destino'=>'Siempre que el pedido incluya al menos un control'),
    'PEDIDO_SUMINISTROS'=>array('titulo'=>'Pedidos sin control','prefijo'=>'P.','destino'=>'Repuestos, señalización, accesorios o IEP sin control')
);
if($_SERVER['REQUEST_METHOD']==='POST'){
    automacValidarCsrf();
    $conexion->begin_transaction();
    try{
        foreach($series as $clave=>$cfg){
            $campo='ultimo_'.$clave;
            $valor=filter_input(INPUT_POST,$campo,FILTER_VALIDATE_INT);
            if($valor===false || $valor===null || $valor<0 || $valor>999999999) throw new Exception('Todos los últimos números deben ser enteros entre 0 y 999.999.999.');
            $st=$conexion->prepare('INSERT INTO numeracion_documentos(numeracion_serie,numeracion_ultimo) VALUES(?,?) ON DUPLICATE KEY UPDATE numeracion_ultimo=VALUES(numeracion_ultimo)');
            $st->bind_param('si',$clave,$valor); if(!$st->execute()) throw new Exception($st->error); $st->close();
        }
        $conexion->commit(); $mensaje='Numeraciones actualizadas correctamente.';
    }catch(Throwable $ex){$conexion->rollback();$mensaje=$ex->getMessage();$tipo='error';}
}
$actuales=array();
$r=$conexion->query('SELECT numeracion_serie,numeracion_ultimo FROM numeracion_documentos');
while($r && $f=$r->fetch_assoc()) $actuales[$f['numeracion_serie']]=(int)$f['numeracion_ultimo'];
function mostrarNumero($prefijo,$n){return $prefijo.(string)(int)$n;}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Numeración de documentos</title><style>
*{box-sizing:border-box}body{font-family:Arial;background:#f4f4f9;margin:18px;color:#202124}.c{max-width:1050px;margin:auto}.menu{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:14px}.menu a,button{padding:9px 12px;border:0;border-radius:5px;background:#343a40;color:#fff;text-decoration:none;font-weight:bold;font-size:12px;cursor:pointer}.menu .activo,button{background:#0d6efd}.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px}.msg{padding:10px;border-radius:5px;margin-bottom:12px}.ok{background:#d1e7dd;color:#0f5132}.error{background:#f8d7da;color:#842029}.fila{display:grid;grid-template-columns:1.4fr 180px 180px 1.5fr;gap:12px;align-items:center;padding:12px 0;border-bottom:1px solid #ddd}.fila input{width:100%;height:36px;padding:6px}.mini{font-size:12px;color:#666}.ej{font-weight:bold}.acciones{margin-top:18px}@media(max-width:800px){.fila{grid-template-columns:1fr}.cab{display:none}}</style></head><body><div class="c">
<?php require __DIR__ . '/menu.php'; ?>
<h1>Numeración de documentos</h1>
<?php if($mensaje!==''):?><div class="msg <?=e($tipo)?>"><?=e($mensaje)?></div><?php endif;?>
<section class="caja"><p>Ingresá el <strong>último número utilizado</strong>. El próximo documento tomará el siguiente correlativo. Para <strong>C.</strong>, <strong>R.</strong> y <strong>P.</strong> una anulación no modifica la numeración. En <strong>obras #</strong>, si se anula la última obra vigente, el contador retrocede y ese número queda disponible para la próxima obra. No se agregan ceros a la izquierda.</p>
<form method="post"><?=automacCsrfInput()?><div class="fila cab"><strong>Serie</strong><strong>Último usado</strong><strong>Próximo</strong><strong>Uso</strong></div>
<?php foreach($series as $clave=>$cfg):$ultimo=$actuales[$clave]??0;$proximo=min(999999999,$ultimo+1);?>
<div class="fila"><div><strong><?=e($cfg['titulo'])?></strong><div class="mini">Formato <?=e($cfg['prefijo'])?>NÚMERO ENTERO</div></div><div><input type="number" min="0" max="999999999" name="ultimo_<?=e($clave)?>" value="<?=$ultimo?>" required></div><div class="ej"><?=e(mostrarNumero($cfg['prefijo'],$proximo))?></div><div class="mini"><?=e($cfg['destino'])?></div></div>
<?php endforeach;?>
<div class="acciones"><button type="submit">GUARDAR NUMERACIONES</button></div></form></section></div></body></html>
