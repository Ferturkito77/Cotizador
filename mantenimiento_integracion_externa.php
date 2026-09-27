<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'integracion_externa.php';
function iemE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$mensaje='';$tipo='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $ruta=trim((string)($_POST['ruta']??''));
        $obra=trim((string)($_POST['plantilla_obra']??''));
        $pedido=trim((string)($_POST['plantilla_pedido']??''));
        if($obra===''||$pedido==='') throw new RuntimeException('Las plantillas de nombre no pueden quedar vacías.');
        ieConfigSet($conexion,'integracion_externa_activa',!empty($_POST['activo'])?'SI':'NO','Habilita la exportación HTML de obras # y pedidos P. a una carpeta externa.');
        ieConfigSet($conexion,'integracion_externa_ruta',$ruta,'Carpeta de intercambio utilizada por el sistema externo. Puede ser una ruta local o una carpeta compartida UNC.');
        ieConfigSet($conexion,'integracion_externa_nombre_obra',$obra,'Plantilla de nombre HTML para obras. Variables: {numero}, {documento}, {cliente}, {sigla}, {tipo}.');
        ieConfigSet($conexion,'integracion_externa_nombre_pedido',$pedido,'Plantilla de nombre HTML para pedidos P. Variables: {numero}, {documento}, {cliente}, {sigla}, {tipo}.');
        ieConfigSet($conexion,'integracion_externa_sobrescribir',!empty($_POST['sobrescribir'])?'SI':'NO','Permite reemplazar el mismo archivo de intercambio al volver a confirmarlo.');
        $mensaje='Configuración de integración guardada.';
    }catch(Throwable $e){$mensaje=$e->getMessage();$tipo='error';}
}
$cfg=ieConfig($conexion);
$estadoRuta='Sin configurar';$estadoClase='warn';
if($cfg['ruta']!==''){
    if(is_dir($cfg['ruta']) && is_writable($cfg['ruta'])){$estadoRuta='Carpeta accesible y escribible';$estadoClase='ok';}
    elseif(is_dir($cfg['ruta'])){$estadoRuta='La carpeta existe, pero PHP no tiene permiso de escritura';$estadoClase='error';}
    else{$estadoRuta='La carpeta no existe o PHP/Apache no puede verla';$estadoClase='error';}
}
$ejObra=ieNombreArchivo($cfg,'OBRA','#50987','Bejarano Ascensores','BEJ');
$ejPedido=ieNombreArchivo($cfg,'SUMINISTROS','P.76543','Bejarano Ascensores','BEJ');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Integración externa</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1150px;margin:20px auto;padding:0 18px 60px}.hero,.card{background:#fff;border:1px solid #dde5ea;border-radius:14px;padding:18px;margin-bottom:14px}.hero h1{margin:0 0 6px}.hero p,.hint{color:#64748b}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.campo{display:flex;flex-direction:column;gap:6px}.campo.full{grid-column:1/-1}.campo label{font-weight:800;color:#334155}.campo input[type=text]{height:42px;padding:8px 10px;border:1px solid #cfd8e3;border-radius:8px}.checks{display:flex;gap:20px;flex-wrap:wrap;margin-top:8px}.btn{border:0;background:#0d6efd;color:#fff;font-weight:800;padding:10px 15px;border-radius:8px;cursor:pointer}.msg,.estado{padding:11px 13px;border-radius:9px;margin:12px 0}.ok{background:#e8f7ef;color:#17603a}.warn{background:#fff7df;color:#6d5415}.error{background:#fdecec;color:#8f2020}.ej{font-family:Consolas,monospace;background:#f5f7fa;border:1px solid #e0e6ed;border-radius:8px;padding:10px;word-break:break-all}@media(max-width:760px){.grid{grid-template-columns:1fr}.campo.full{grid-column:auto}}</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Integración externa por archivos HTML</h1><p>Configura la carpeta donde el cotizador dejará los archivos que luego toma el sistema externo.</p></section><?php if($mensaje!==''):?><div class="msg <?=iemE($tipo)?>"><?=iemE($mensaje)?></div><?php endif;?><section class="card"><form method="post"><?= automacCsrfInput() ?><div class="grid"><div class="campo full"><label>Carpeta de salida</label><input type="text" name="ruta" value="<?=iemE($cfg['ruta'])?>" placeholder="Ej.: C:\\Intercambio\\Cotizador o \\\\SERVIDOR\\Intercambio"><span class="hint">La ruta es interpretada por el servidor PHP/XAMPP. Si es una carpeta de red, la cuenta con la que corre Apache debe tener permisos.</span></div><div class="campo"><label>Nombre de archivo para Obra #</label><input type="text" name="plantilla_obra" value="<?=iemE($cfg['plantilla_obra'])?>"></div><div class="campo"><label>Nombre de archivo para Pedido P.</label><input type="text" name="plantilla_pedido" value="<?=iemE($cfg['plantilla_pedido'])?>"></div></div><div class="checks"><label><input type="checkbox" name="activo" value="1"<?=$cfg['activo']?' checked':''?>> Integración activa</label><label><input type="checkbox" name="sobrescribir" value="1"<?=$cfg['sobrescribir']?' checked':''?>> Permitir regenerar/sobrescribir</label></div><p class="hint">Variables disponibles: <strong>{numero}</strong>, <strong>{documento}</strong>, <strong>{cliente}</strong>, <strong>{sigla}</strong>, <strong>{tipo}</strong>.</p><button class="btn" type="submit">GUARDAR CONFIGURACIÓN</button></form></section><section class="card"><h2>Estado de la carpeta</h2><div class="estado <?=iemE($estadoClase)?>"><strong><?=iemE($estadoRuta)?></strong><?php if($cfg['ruta']!==''):?><br><?=iemE($cfg['ruta'])?><?php endif;?></div><h3>Ejemplos con la configuración actual</h3><div class="ej"><?=iemE($ejObra)?></div><div style="height:7px"></div><div class="ej"><?=iemE($ejPedido)?></div></section><section class="card"><h2>Funcionamiento</h2><p class="hint">Cuando se crea una obra <strong>#</strong> o un pedido <strong>P.</strong>, el sistema abre una pantalla de confirmación. Al aceptar, genera un archivo HTML con campos identificados y un bloque de datos estructurado para que el PHP externo pueda leerlos.</p></section></main></body></html>
