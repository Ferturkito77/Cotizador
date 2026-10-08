<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'schema_guard.php';

try {
    verificarTablaColumnas($conexion,'centrales',array('central_id','central_codigo','central_name','central_activa','central_orden','central_es_otra','central_adicional_clave'),'migracion_consolidacion_v59.sql');
    verificarTablaColumnas($conexion,'baterias',array('bateria_id','bateria_codigo','bateria_nombre','bateria_activa','bateria_orden'),'migracion_consolidacion_v59.sql');
} catch (Throwable $e) { die(htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8')); }

function mcE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$ok='';$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    try{
        $accion=(string)($_POST['accion']??'');
        if($accion==='guardar_central'){
            $id=(int)($_POST['central_id']??0);$codigo=strtoupper(trim((string)($_POST['central_codigo']??'')));$nombre=strtoupper(trim((string)($_POST['central_name']??'')));
            $orden=(int)($_POST['central_orden']??0);$activa=isset($_POST['central_activa'])?'SI':'NO';$otra=isset($_POST['central_es_otra'])?1:0;$clave=trim((string)($_POST['central_adicional_clave']??''));
            if($codigo===''||$nombre==='') throw new Exception('Código y nombre son obligatorios.');
            if($id>0){$st=$conexion->prepare('UPDATE centrales SET central_codigo=?,central_name=?,central_activa=?,central_orden=?,central_es_otra=?,central_adicional_clave=NULLIF(?,\'\') WHERE central_id=?');$st->bind_param('sssiisi',$codigo,$nombre,$activa,$orden,$otra,$clave,$id);}else{$st=$conexion->prepare('INSERT INTO centrales(central_codigo,central_name,central_activa,central_orden,central_es_otra,central_adicional_clave) VALUES(?,?,?,?,?,NULLIF(?,\'\'))');$st->bind_param('sssiis',$codigo,$nombre,$activa,$orden,$otra,$clave);}
            $st->execute();$st->close();$ok='Central guardada.';
        } elseif($accion==='guardar_bateria'){
            $id=(int)($_POST['bateria_id']??0);$nombre=strtoupper(trim((string)($_POST['bateria_nombre']??'')));$orden=(int)($_POST['bateria_orden']??0);$activa=isset($_POST['bateria_activa'])?'SI':'NO';
            if(!$id||$nombre==='') throw new Exception('Agrupación inválida.');
            $st=$conexion->prepare('UPDATE baterias SET bateria_nombre=?,bateria_activa=?,bateria_orden=? WHERE bateria_id=?');$st->bind_param('ssii',$nombre,$activa,$orden,$id);$st->execute();$st->close();$ok='Agrupación actualizada.';
        }
    }catch(Throwable $e){$err=$e->getMessage();}
}
$centrales=$conexion->query('SELECT * FROM centrales ORDER BY central_orden,central_name');
$baterias=$conexion->query('SELECT * FROM baterias ORDER BY bateria_orden,bateria_id');
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Configuración de Control</title>
<style>body{font-family:Arial;background:#f4f7f8;color:#183142;margin:0}.wrap{max-width:1250px;margin:18px auto;padding:0 18px}.card{background:white;border:1px solid #dbe3e8;border-radius:12px;padding:16px;margin-bottom:16px}.ok,.err{padding:10px 12px;border-radius:8px;margin-bottom:12px}.ok{background:#e7f7ee;color:#12633b}.err{background:#fdecec;color:#9d2424}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border-bottom:1px solid #e2e8ec;padding:6px 5px;vertical-align:middle}th{text-align:left;background:#f6f9fa}input[type=text],input[type=number]{width:100%;box-sizing:border-box;padding:6px;border:1px solid #cbd6dc;border-radius:5px}.btn{background:#128451;color:white;border:0;border-radius:6px;padding:7px 10px;font-weight:bold}.new{display:grid;grid-template-columns:110px 1fr 80px 70px 150px auto;gap:7px;align-items:end;margin-top:12px}.muted{color:#687b87;font-size:12px}@media(max-width:900px){.grid{grid-template-columns:1fr}.new{grid-template-columns:1fr}}</style></head><body>
<?php require 'menu.php'; ?><main class="wrap"><h1>Configuración de Control</h1><p class="muted">Catálogos técnicos del Control que ya son administrables desde base de datos. En esta etapa se gestionan centrales hidráulicas y agrupaciones/baterías; las fórmulas se mantienen en Cálculos de Control.</p>
<?php if($ok):?><div class="ok"><?=mcE($ok)?></div><?php endif;?><?php if($err):?><div class="err"><?=mcE($err)?></div><?php endif;?>
<div class="grid"><section class="card"><h2>Centrales hidráulicas</h2><table><thead><tr><th>Código</th><th>Nombre</th><th>Orden</th><th>Otra</th><th>Adicional</th><th>Activa</th><th></th></tr></thead><tbody>
<?php while($c=$centrales->fetch_assoc()):?><tr><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_central"><input type="hidden" name="central_id" value="<?=(int)$c['central_id']?>"><td><input name="central_codigo" value="<?=mcE($c['central_codigo'])?>"></td><td><input name="central_name" value="<?=mcE($c['central_name'])?>"></td><td><input type="number" name="central_orden" value="<?=(int)$c['central_orden']?>"></td><td><input type="checkbox" name="central_es_otra"<?=$c['central_es_otra']?' checked':''?>></td><td><input name="central_adicional_clave" value="<?=mcE($c['central_adicional_clave'])?>" placeholder="ej. CENTRAL_ROJAS"></td><td><input type="checkbox" name="central_activa"<?=$c['central_activa']==='SI'?' checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table>
<form method="post" class="new"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_central"><label>Código<input name="central_codigo" required></label><label>Nombre<input name="central_name" required></label><label>Orden<input type="number" name="central_orden" value="50"></label><label>Otra<br><input type="checkbox" name="central_es_otra"></label><label>Clave adicional<input name="central_adicional_clave"></label><label><input type="checkbox" name="central_activa" checked> Activa<br><button class="btn">Agregar</button></label></form>
</section><section class="card"><h2>Agrupaciones / baterías</h2><p class="muted">El código técnico no se edita desde esta pantalla para no romper <code>matriz_baterias</code>.</p><table><thead><tr><th>Código</th><th>Nombre</th><th>Orden</th><th>Activa</th><th></th></tr></thead><tbody>
<?php while($b=$baterias->fetch_assoc()):?><tr><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_bateria"><input type="hidden" name="bateria_id" value="<?=(int)$b['bateria_id']?>"><td><strong><?=mcE($b['bateria_codigo'])?></strong></td><td><input name="bateria_nombre" value="<?=mcE($b['bateria_nombre'])?>"></td><td><input type="number" name="bateria_orden" value="<?=(int)$b['bateria_orden']?>"></td><td><input type="checkbox" name="bateria_activa"<?=$b['bateria_activa']==='SI'?' checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table></section></div>
<section class="card"><h2>¿Dónde se mantiene cada cosa?</h2><p>Use <strong>Repuestos</strong> para dar de alta códigos Bejerman como repuestos, <strong>Accesorios</strong> para accesorios simples, <strong>Señalización</strong> para botoneras/indicadores y <strong>Cálculos de Control</strong> para nuevas combinaciones del motor de cálculo. El diagnóstico permite detectar códigos activos sin precio vigente.</p><a class="btn" href="diagnostico_catalogos.php" style="display:inline-block;text-decoration:none">Abrir diagnóstico</a></section>
</main></body></html>
