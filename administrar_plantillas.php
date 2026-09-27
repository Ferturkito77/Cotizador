<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'plantillas_controles.php';
$conexion->set_charset('utf8mb4');

$mensaje = (string)($_SESSION['plantilla_mensaje'] ?? '');
$error = '';
unset($_SESSION['plantilla_mensaje']);

try {
    asegurarTablaPlantillasControles($conexion);

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion'] ?? '') === 'eliminar') {
        $id = filter_input(INPUT_POST, 'plantilla_id', FILTER_VALIDATE_INT);
        if (!$id) throw new RuntimeException('La plantilla seleccionada no es válida.');
        $stmt = $conexion->prepare('DELETE FROM plantillas_controles WHERE plantilla_id=?');
        if (!$stmt) throw new RuntimeException($conexion->error);
        $stmt->bind_param('i', $id);
        if (!$stmt->execute()) throw new RuntimeException($stmt->error);
        $stmt->close();
        $mensaje = 'Plantilla eliminada correctamente.';
    }
} catch (Throwable $e) {
    $error = $e->getMessage();
}

$plantillas = $conexion->query("SELECT plantilla_id, plantilla_codigo, plantilla_nombre, plantilla_descripcion, plantilla_activa, plantilla_creada, plantilla_modificada FROM plantillas_controles ORDER BY plantilla_codigo, plantilla_id");
function e($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Plantillas de controles</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f4f9;margin:18px;color:#202124}.contenedor{max-width:1180px;margin:auto}.menu{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:16px}.menu a{background:#343a40;color:#fff;text-decoration:none;padding:10px 13px;border-radius:5px;font-size:13px;font-weight:bold}.menu a.activo{background:#0d6efd}.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;box-shadow:0 2px 8px rgba(0,0,0,.06)}h1{margin-top:0}.acciones-superiores{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-bottom:16px}.boton,button{border:0;border-radius:5px;padding:10px 14px;font-weight:bold;text-decoration:none;cursor:pointer}.primario{background:#0d6efd;color:#fff}.editar{background:#198754;color:#fff}.eliminar{background:#dc3545;color:#fff}.mensaje{padding:11px;border-radius:5px;margin-bottom:14px}.ok{background:#d1e7dd;color:#0f5132}.error{background:#f8d7da;color:#842029}.tabla-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:13px}th,td{border-bottom:1px solid #ddd;padding:10px;text-align:left;vertical-align:top}th{background:#f1f3f5}.acciones{display:flex;gap:7px;flex-wrap:wrap}.acciones form{margin:0}.vacio{padding:24px;text-align:center;color:#666}.codigo{font-weight:bold;font-size:15px}.descripcion{color:#5f6368;margin-top:4px;max-width:520px}
</style></head><body><div class="contenedor">
<?php require __DIR__ . '/menu.php'; ?>
<div class="caja"><div class="acciones-superiores"><div><h1>Plantillas de controles</h1><div>Creá modelos como 1M, 2M, 3M y aplicalos después desde el cotizador.</div></div><a class="boton primario" href="index.php?modo_plantilla=nueva">NUEVA PLANTILLA</a></div>
<?php if($mensaje!==''):?><div class="mensaje ok"><?=e($mensaje)?></div><?php endif;?><?php if($error!==''):?><div class="mensaje error"><?=e($error)?></div><?php endif;?>
<div class="tabla-wrap"><table><thead><tr><th>Código</th><th>Nombre y descripción</th><th>Modificada</th><th>Acciones</th></tr></thead><tbody>
<?php if($plantillas && $plantillas->num_rows): while($p=$plantillas->fetch_assoc()):?>
<tr><td class="codigo"><?=e($p['plantilla_codigo'])?></td><td><strong><?=e($p['plantilla_nombre'])?></strong><?php if($p['plantilla_descripcion']!==''):?><div class="descripcion"><?=e($p['plantilla_descripcion'])?></div><?php endif;?></td><td><?=e($p['plantilla_modificada'])?></td><td><div class="acciones"><a class="boton editar" href="index.php?modo_plantilla=editar&amp;plantilla_id=<?=(int)$p['plantilla_id']?>">Modificar</a><a class="boton primario" href="index.php?aplicar_plantilla=<?=(int)$p['plantilla_id']?>">Usar</a><form method="post" data-confirmacion="<?= e('¿Eliminar definitivamente la plantilla '.$p['plantilla_codigo'].'?') ?>" onsubmit="return confirm(this.dataset.confirmacion);"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="plantilla_id" value="<?=(int)$p['plantilla_id']?>"><button class="eliminar" type="submit">Eliminar</button></form></div></td></tr>
<?php endwhile; else:?><tr><td colspan="4" class="vacio">Todavía no hay plantillas creadas.</td></tr><?php endif;?>
</tbody></table></div></div></div></body></html>
