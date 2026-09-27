<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'documentos_storage.php';

$mensaje=''; $error='';
$rutaActual = documentosRaiz($conexion);

if ($_SERVER['REQUEST_METHOD']==='POST') {
    $accion=(string)($_POST['accion']??'');
    $ruta=trim((string)($_POST['documentos_raiz']??''));
    if ($ruta==='') $error='Indique una carpeta para los documentos.';
    elseif (strpos($ruta, "\0")!==false) $error='La ruta indicada no es valida.';
    else {
        if ($accion==='probar') {
            [$ok,$detalle]=documentosProbarRuta($conexion,$ruta);
            if ($ok) $mensaje=$detalle; else $error=$detalle;
            $rutaActual=$ruta;
        } elseif ($accion==='guardar') {
            [$ok,$detalle]=documentosProbarRuta($conexion,$ruta);
            if (!$ok) $error='No se guardo la configuracion: '.$detalle;
            else {
                try {
                    documentosGuardarConfiguracion($conexion,'documentos_raiz',$ruta,'Carpeta raiz del archivo general de documentos PDF');
                    $rutaActual=$ruta;
                    $mensaje='Ubicacion guardada correctamente. Los nuevos documentos se archivaran tambien en esta carpeta.';
                } catch(Throwable $e){$error=$e->getMessage();}
            }
        }
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Archivo de documentos</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f7fa;margin:18px;color:#183047}.caja{background:#fff;padding:20px;border-radius:10px;max-width:1000px;margin:auto}.card{border:1px solid #d5e0eb;border-radius:10px;padding:18px;margin-top:16px;background:#fbfdff}label{font-weight:700;display:block;margin-bottom:7px}input[type=text]{width:100%;box-sizing:border-box;padding:11px 12px;border:1px solid #aebfd0;border-radius:7px;font-size:15px}.acciones{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.btn{border:0;border-radius:7px;padding:10px 15px;font-weight:700;cursor:pointer}.primario{background:#1d3f73;color:#fff}.secundario{background:#eaf1f8;color:#173a69}.ok{background:#e8f5ed;color:#176336;padding:11px;border-radius:7px;margin:10px 0}.err{background:#fdecec;color:#9c2525;padding:11px;border-radius:7px;margin:10px 0}.mini{font-size:13px;color:#607386;line-height:1.5}.ruta{font-family:Consolas,monospace;background:#edf2f7;padding:3px 6px;border-radius:4px}
</style></head><body><div class="caja"><?php require __DIR__.'/menu.php'; ?>
<h1>Archivo general de documentos</h1>
<p class="mini">El Cotizador conserva la copia web dentro de <span class="ruta">pdf/</span> y, ademas, archiva una segunda copia oficial en la ubicacion configurada. La ruta corresponde a la PC o servidor donde corre XAMPP/PHP.</p>
<?php if($mensaje):?><div class="ok"><?=htmlspecialchars($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=htmlspecialchars($error)?></div><?php endif;?>
<div class="card"><form method="post"><?= automacCsrfInput() ?>
<label for="documentos_raiz">Carpeta raiz</label>
<input type="text" id="documentos_raiz" name="documentos_raiz" value="<?=htmlspecialchars($rutaActual,ENT_QUOTES,'UTF-8')?>" placeholder="C:\Cotizador\Documentos">
<p class="mini">Ejemplos: <span class="ruta">C:\Cotizador\Documentos</span>, <span class="ruta">D:\Automac\Documentos</span> o una carpeta compartida como <span class="ruta">\\SERVIDOR\Cotizador\Documentos</span>.</p>
<div class="acciones"><button class="btn secundario" type="submit" name="accion" value="probar">PROBAR UBICACION</button><button class="btn primario" type="submit" name="accion" value="guardar">GUARDAR UBICACION</button></div>
</form></div>
<div class="card"><strong>Estructura automatica</strong><p class="mini">Los documentos nuevos se ordenan por tipo, ano y numero. Ejemplo:</p><div class="ruta">Cotizaciones\2026\C.64\...pdf</div><br><div class="ruta">Pedidos\2026\P.10\...pdf</div><br><div class="ruta">Ordenes_Fabricacion\2026\P.10\...pdf</div>
<p class="mini">Los PDFs historicos que ya existen no se mueven automaticamente. Se mantiene compatibilidad total con sus rutas actuales.</p></div>
</div></body></html>
