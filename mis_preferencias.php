<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirLogin();
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'documentos_storage.php';

$u = usuarioActual();
$uid = (int)($u['id'] ?? 0);
$mensaje = '';
$error = '';

$defaults = array(
    'mail_asunto_cotizacion' => 'Cotización Automac {numero}{revision_texto}',
    'mail_cuerpo_cotizacion' => "Hola,\n\nAdjuntamos la cotización {numero}{revision_texto} para su consideración.\n\nSaludos,\n{usuario}\nAutomac",
    'mail_asunto_pedido' => 'Pedido Automac {numero}{revision_texto}',
    'mail_cuerpo_pedido' => "Hola,\n\nAdjuntamos el pedido {numero}{revision_texto}.\n\nSaludos,\n{usuario}\nAutomac",
    'whatsapp_cuerpo_cotizacion' => 'Hola. Te envío la cotización {numero}{revision_texto} de Automac. A continuación adjunto el PDF.',
    'whatsapp_cuerpo_pedido' => 'Hola. Te envío el pedido {numero}{revision_texto} de Automac. A continuación adjunto el PDF.'
);

// Compatibilidad con v84/v85: si existía texto de Gmail de cotización, usarlo como valor inicial.
$legacyAsunto = documentosObtenerConfiguracion($conexion, 'mail_asunto_usuario_' . $uid, $defaults['mail_asunto_cotizacion']);
$legacyCuerpo = documentosObtenerConfiguracion($conexion, 'mail_cuerpo_usuario_' . $uid, $defaults['mail_cuerpo_cotizacion']);

$valores = array(
    'mail_asunto_cotizacion' => documentosObtenerConfiguracion($conexion, 'mail_asunto_cotizacion_usuario_' . $uid, $legacyAsunto),
    'mail_cuerpo_cotizacion' => documentosObtenerConfiguracion($conexion, 'mail_cuerpo_cotizacion_usuario_' . $uid, $legacyCuerpo),
    'mail_asunto_pedido' => documentosObtenerConfiguracion($conexion, 'mail_asunto_pedido_usuario_' . $uid, $defaults['mail_asunto_pedido']),
    'mail_cuerpo_pedido' => documentosObtenerConfiguracion($conexion, 'mail_cuerpo_pedido_usuario_' . $uid, $defaults['mail_cuerpo_pedido']),
    'whatsapp_cuerpo_cotizacion' => documentosObtenerConfiguracion($conexion, 'whatsapp_cuerpo_cotizacion_usuario_' . $uid, $defaults['whatsapp_cuerpo_cotizacion']),
    'whatsapp_cuerpo_pedido' => documentosObtenerConfiguracion($conexion, 'whatsapp_cuerpo_pedido_usuario_' . $uid, $defaults['whatsapp_cuerpo_pedido'])
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    foreach ($valores as $k => $v) $valores[$k] = trim((string)($_POST[$k] ?? ''));
    foreach ($valores as $k => $v) {
        if ($v === '') { $error = 'Ningún texto predeterminado puede quedar vacío.'; break; }
    }
    if ($error === '') {
        try {
            foreach ($valores as $k => $v) {
                documentosGuardarConfiguracion($conexion, $k . '_usuario_' . $uid, $v, 'Preferencia de envío del usuario ' . $uid);
            }
            $mensaje = 'Preferencias guardadas correctamente.';
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

$rutaArchivo = documentosRaiz($conexion);
function prefE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mis preferencias</title><style>
body{font-family:Arial,sans-serif;background:#f4f7fa;margin:18px;color:#183047}.caja{background:#fff;padding:20px;border-radius:10px;max-width:1150px;margin:auto}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.card{border:1px solid #d5e0eb;border-radius:10px;padding:18px;background:#fbfdff}.card.full{grid-column:1/-1}label{font-weight:700;display:block;margin:12px 0 7px}input[type=text],textarea{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #aebfd0;border-radius:7px;font:inherit}textarea{min-height:145px;resize:vertical}.btn{border:0;border-radius:7px;padding:10px 15px;font-weight:700;cursor:pointer;background:#1d3f73;color:#fff}.ok{background:#e8f5ed;color:#176336;padding:11px;border-radius:7px;margin:10px 0}.err{background:#fdecec;color:#9c2525;padding:11px;border-radius:7px;margin:10px 0}.mini{font-size:13px;color:#607386;line-height:1.55}.ruta{font-family:Consolas,monospace;background:#edf2f7;padding:5px 7px;border-radius:4px;display:inline-block;max-width:100%;overflow-wrap:anywhere}.tokens{display:flex;gap:7px;flex-wrap:wrap;margin-top:8px}.token{font-family:Consolas,monospace;background:#eaf1f8;color:#173a69;padding:4px 7px;border-radius:5px;font-size:12px}.sub{border-top:1px solid #e1e8ef;margin-top:16px;padding-top:12px}@media(max-width:800px){.grid{grid-template-columns:1fr}.card.full{grid-column:auto}}
</style></head><body><div class="caja"><?php require __DIR__.'/menu.php';?><h1>Mis preferencias</h1><p class="mini">Configuración personal para el envío de cotizaciones, revisiones y pedidos por Gmail o WhatsApp Web.</p>
<?php if($mensaje):?><div class="ok"><?=prefE($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=prefE($error)?></div><?php endif;?>
<div class="grid"><div class="card"><h2 style="margin-top:0">Archivo oficial</h2><p class="mini">Los PDF emitidos se archivan automáticamente en la PC o servidor donde corre el Cotizador.</p><div class="ruta"><?=prefE($rutaArchivo)?></div><p class="mini">La ubicación es general. Solo un administrador puede cambiarla desde <b>Administración → Archivo de documentos</b>.</p></div>
<div class="card"><h2 style="margin-top:0">PDF descargados</h2><p class="mini">Cuando presionás <b>Descargar PDF</b>, la copia se guarda en la carpeta de descargas configurada en el navegador.</p><div class="ruta">Descargas del navegador</div><p class="mini">El PDF se adjunta manualmente en Gmail o WhatsApp Web.</p></div>
<form method="post" class="card full"><?= automacCsrfInput() ?><h2 style="margin-top:0">Textos predeterminados de envío</h2><p class="mini">Variables disponibles:</p><div class="tokens"><span class="token">{tipo}</span><span class="token">{numero}</span><span class="token">{revision}</span><span class="token">{revision_texto}</span><span class="token">{cliente}</span><span class="token">{referencia}</span><span class="token">{usuario}</span></div>
<div class="grid" style="margin-top:12px"><div><h3>Cotizaciones / revisiones</h3><label>Asunto Gmail</label><input type="text" name="mail_asunto_cotizacion" value="<?=prefE($valores['mail_asunto_cotizacion'])?>"><label>Mensaje Gmail</label><textarea name="mail_cuerpo_cotizacion"><?=prefE($valores['mail_cuerpo_cotizacion'])?></textarea><label>Mensaje WhatsApp</label><textarea name="whatsapp_cuerpo_cotizacion"><?=prefE($valores['whatsapp_cuerpo_cotizacion'])?></textarea></div>
<div><h3>Pedidos / modificaciones</h3><label>Asunto Gmail</label><input type="text" name="mail_asunto_pedido" value="<?=prefE($valores['mail_asunto_pedido'])?>"><label>Mensaje Gmail</label><textarea name="mail_cuerpo_pedido"><?=prefE($valores['mail_cuerpo_pedido'])?></textarea><label>Mensaje WhatsApp</label><textarea name="whatsapp_cuerpo_pedido"><?=prefE($valores['whatsapp_cuerpo_pedido'])?></textarea></div></div>
<div style="margin-top:16px"><button class="btn" type="submit">GUARDAR PREFERENCIAS</button></div></form></div></div></body></html>
