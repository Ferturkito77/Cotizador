<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
require_once 'material_hueco_indicador_autonomo.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');
mhiaAsegurarMatriz($conexion);

function e($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function volver($mensaje, $tipo='ok') {
    $_SESSION['mh_mensaje'] = $mensaje;
    $_SESSION['mh_tipo'] = $tipo;
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: administrar_material_hueco.php' . ($qs !== '' ? '?' . $qs : ''));
    exit;
}

$tablas = array('material_hueco_materiales_of','material_hueco_configuraciones_of','material_hueco_reglas_of');
foreach ($tablas as $tabla) {
    $r = $conexion->query("SHOW TABLES LIKE '" . $conexion->real_escape_string($tabla) . "'");
    if (!$r || $r->num_rows === 0) {
        die('Falta crear la matriz de material de hueco. Importe primero crear_matriz_material_hueco.sql.');
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = $_POST['accion'] ?? '';
    if ($accion === 'actualizar_indicador_autonomo') {
        $id=filter_input(INPUT_POST,'material_id',FILTER_VALIDATE_INT);
        $codigo=trim((string)($_POST['material_codigo']??''));
        $desc=trim((string)($_POST['material_descripcion']??''));
        $regla=in_array((string)($_POST['regla_cantidad']??''),array('POR_INDICADOR','POR_PARADA_APPIND'),true)?(string)$_POST['regla_cantidad']:'POR_INDICADOR';
        $cant=is_numeric($_POST['cantidad_base']??null)?max(0,(float)$_POST['cantidad_base']):1;
        $orden=(int)($_POST['material_orden']??10);
        $activo=(($_POST['material_activo']??'SI')==='NO')?'NO':'SI';
        if(!$id||$desc==='') volver('Material no valido.','error');
        $st=$conexion->prepare('UPDATE material_hueco_indicador_autonomo SET material_codigo=?,material_descripcion=?,regla_cantidad=?,cantidad_base=?,material_orden=?,material_activo=? WHERE material_id=? LIMIT 1');
        if(!$st) volver('No se pudo preparar la actualizacion: '.$conexion->error,'error');
        $st->bind_param('sssdisi',$codigo,$desc,$regla,$cant,$orden,$activo,$id);
        if(!$st->execute()){ $er=$st->error;$st->close();volver('No se pudo guardar: '.$er,'error'); }
        $st->close(); volver('Material de indicador autonomo actualizado.');
    }
    if ($accion === 'actualizar_regla') {
        $id = filter_input(INPUT_POST, 'regla_id', FILTER_VALIDATE_INT);
        $formula = trim($_POST['regla_formula'] ?? '');
        $observacion = trim($_POST['regla_observacion'] ?? '');
        $activa = ($_POST['regla_activa'] ?? 'SI') === 'NO' ? 'NO' : 'SI';
        if (!$id || $formula === '') volver('La regla o la fórmula no son válidas.', 'error');
        $stmt = $conexion->prepare('UPDATE material_hueco_reglas_of SET regla_formula=?, regla_observacion=?, regla_activa=? WHERE regla_id=? LIMIT 1');
        if (!$stmt) volver('No se pudo preparar la actualización: ' . $conexion->error, 'error');
        $stmt->bind_param('sssi', $formula, $observacion, $activa, $id);
        if (!$stmt->execute()) { $err=$stmt->error; $stmt->close(); volver('No se pudo guardar: '.$err,'error'); }
        $stmt->close();
        volver('Regla actualizada correctamente.');
    }
}

$sistema = isset($_GET['sistema']) && in_array($_GET['sistema'], array('CHAPAS','IMANES','AUTONOMO'), true) ? $_GET['sistema'] : 'CHAPAS';
$pos = isset($_GET['pos']) && in_array($_GET['pos'], array('SI','NO'), true) ? $_GET['pos'] : 'NO';
$tipo = isset($_GET['tipo']) && in_array($_GET['tipo'], array('TODOS','1V','2V','HIDRAULICO','VF_HASTA_75','VF_MAYOR_75'), true) ? $_GET['tipo'] : 'TODOS';

$sistemaConsulta = $sistema === 'AUTONOMO' ? 'CHAPAS' : $sistema;
$sql = "SELECT r.regla_id, r.regla_formula, r.regla_observacion, r.regla_activa,
               c.configuracion_tipo, c.configuracion_descripcion,
               m.material_clave, m.material_nombre, m.material_orden
        FROM material_hueco_reglas_of r
        INNER JOIN material_hueco_configuraciones_of c ON c.configuracion_id=r.configuracion_id
        INNER JOIN material_hueco_materiales_of m ON m.material_id=r.material_id
        WHERE c.configuracion_sistema=? AND c.configuracion_pos_encoder=?";
$tipos = 'ss';
$valores = array($sistemaConsulta, $pos);
if ($tipo !== 'TODOS') { $sql .= ' AND c.configuracion_tipo=?'; $tipos .= 's'; $valores[] = $tipo; }
$sql .= ' ORDER BY FIELD(c.configuracion_tipo,\'1V\',\'2V\',\'HIDRAULICO\',\'VF_HASTA_75\',\'VF_MAYOR_75\'), m.material_orden';
$stmt = $conexion->prepare($sql);
$refs = array($tipos);
foreach ($valores as $k=>$v) $refs[] = &$valores[$k];
call_user_func_array(array($stmt,'bind_param'), $refs);
$stmt->execute();
$reglas = $stmt->get_result();

$mensaje = $_SESSION['mh_mensaje'] ?? '';
$tipoMensaje = $_SESSION['mh_tipo'] ?? 'ok';
unset($_SESSION['mh_mensaje'], $_SESSION['mh_tipo']);

$nombresTipo = array('1V'=>'1 velocidad','2V'=>'2 velocidades','HIDRAULICO'=>'Hidráulico','VF_HASTA_75'=>'VF hasta 75 m/min','VF_MAYOR_75'=>'VF mayor a 75 m/min');
?>
<!DOCTYPE html>
<html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><title>Matriz de material de hueco</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f4f9;color:#202124;margin:18px}.contenedor{max-width:1500px;margin:auto}.menu{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.menu a{padding:9px 13px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;background:#343a40;color:#fff}.menu a.activo{background:#0d6efd}.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:16px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.05)}h1{margin-top:0;font-size:23px}.mensaje{padding:10px 12px;border-radius:5px;margin-bottom:14px}.ok{background:#d1e7dd;color:#0f5132}.error{background:#f8d7da;color:#842029}.filtros{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:10px;align-items:end}.campo label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px}.campo select,.campo input{width:100%;height:34px;padding:5px 7px;border:1px solid #aaa;border-radius:4px}.boton,button{border:0;border-radius:4px;padding:9px 13px;font-weight:bold;cursor:pointer;text-decoration:none}.primario{background:#0d6efd;color:#fff}.verde{background:#198754;color:#fff}.tabla-wrap{overflow:auto;max-height:720px;border:1px solid #ddd}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:7px;border-bottom:1px solid #ddd;vertical-align:top}th{position:sticky;top:0;background:#212529;color:#fff;z-index:2;text-align:left}tr:nth-child(even){background:#f8f9fa}.formula{font-family:Consolas,monospace;font-weight:bold;min-width:220px}.obs{min-width:260px}.estado{width:90px}.acciones{width:90px}.mini{font-size:11px;color:#666}.grupo{background:#e9ecef;font-weight:bold}.inline-form{display:grid;grid-template-columns:minmax(220px,1fr) minmax(260px,1.4fr) 90px 90px;gap:6px;align-items:center}.inline-form input,.inline-form select{width:100%;height:31px;border:1px solid #aaa;border-radius:3px;padding:4px 6px}@media(max-width:900px){.filtros{grid-template-columns:1fr 1fr}.inline-form{grid-template-columns:1fr}.tabla-wrap{max-height:none}}
</style></head><body><div class="contenedor">
<?php require __DIR__ . '/menu.php'; ?>
<h1>Matriz de material de hueco para orden de fabricación</h1>
<?php if($mensaje!==''): ?><div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div><?php endif; ?>
<div class="caja"><form method="get" class="filtros">
<div class="campo"><label>Sistema</label><select name="sistema"><option value="CHAPAS"<?= $sistema==='CHAPAS'?' selected':'' ?>>Chapas</option><option value="IMANES"<?= $sistema==='IMANES'?' selected':'' ?>>Imanes</option><option value="AUTONOMO"<?= $sistema==='AUTONOMO'?' selected':'' ?>>Indicador autónomo</option></select></div>
<div class="campo"><label>Posicionamiento por encoder</label><select name="pos"><option value="NO"<?= $pos==='NO'?' selected':'' ?>>Sin posicionamiento</option><option value="SI"<?= $pos==='SI'?' selected':'' ?>>Con posicionamiento</option></select></div>
<div class="campo"><label>Tipo</label><select name="tipo"><option value="TODOS">Todos</option><?php foreach($nombresTipo as $k=>$n): ?><option value="<?= e($k) ?>"<?= $tipo===$k?' selected':'' ?>><?= e($n) ?></option><?php endforeach; ?></select></div>
<div><button class="primario" type="submit">Ver matriz</button></div></form>
<p class="mini">Variables: N = paradas, C = equipos/controles, CONT = contactores de potencial, RBG = retorno por batería de gel, TP = placas de nivel + entorno, TPN = placas de nivel, LIM = cantidad final de límites (L se conserva como alias), BAN = banquitos y CHR = suma de las cinco familias de chapas.</p></div>
<?php if($sistema==='AUTONOMO'): $mhia=mhiaMaterialesActivos($conexion); $rsTodos=$conexion->query("SELECT * FROM material_hueco_indicador_autonomo ORDER BY material_orden,material_id"); ?>
<div class="caja"><h2>Material de hueco · Indicador autónomo / electromecánico</h2><p class="mini">Esta matriz se usa en la OF de Señalización cuando el pedido contiene APPIND. POR_PARADA_APPIND toma la cantidad APPIND del pedido; POR_INDICADOR multiplica por la cantidad de indicadores autónomos.</p><div class="tabla-wrap"><table><thead><tr><th>Clave</th><th>Código / descripción</th><th>Regla / cantidad / estado</th></tr></thead><tbody>
<?php while($m=$rsTodos->fetch_assoc()): ?><tr><td><strong><?= e($m['material_clave']) ?></strong></td><td><form method="post" class="inline-form"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="actualizar_indicador_autonomo"><input type="hidden" name="material_id" value="<?= (int)$m['material_id'] ?>"><input name="material_codigo" value="<?= e($m['material_codigo']) ?>" placeholder="Código (opcional)"><input name="material_descripcion" value="<?= e($m['material_descripcion']) ?>" required></td><td><div class="inline-form"><select name="regla_cantidad"><option value="POR_INDICADOR"<?= $m['regla_cantidad']==='POR_INDICADOR'?' selected':'' ?>>Por indicador</option><option value="POR_PARADA_APPIND"<?= $m['regla_cantidad']==='POR_PARADA_APPIND'?' selected':'' ?>>Por parada / APPIND</option></select><input type="number" min="0" step="0.01" name="cantidad_base" value="<?= e($m['cantidad_base']) ?>"><input type="number" name="material_orden" value="<?= (int)$m['material_orden'] ?>"><select name="material_activo"><option value="SI"<?= $m['material_activo']==='SI'?' selected':'' ?>>Activa</option><option value="NO"<?= $m['material_activo']==='NO'?' selected':'' ?>>Inactiva</option></select><button class="verde" type="submit">Guardar</button></div></form></td></tr><?php endwhile; ?></tbody></table></div></div>
<?php else: ?>
<div class="caja"><div class="tabla-wrap"><table><thead><tr><th>Configuración</th><th>Material</th><th>Fórmula / observación / estado / acción</th></tr></thead><tbody>
<?php $ultimo=''; while($r=$reglas->fetch_assoc()): $grupo=$r['configuracion_tipo']; ?>
<?php if($grupo!==$ultimo): ?><tr class="grupo"><td colspan="3"><?= e($nombresTipo[$grupo] ?? $grupo) ?> — <?= e($sistema==='CHAPAS'?'Hueco por chapas':'Hueco por imanes') ?> — <?= e($pos==='SI'?'con posicionamiento':'sin posicionamiento') ?></td></tr><?php $ultimo=$grupo; endif; ?>
<tr><td><?= e($nombresTipo[$r['configuracion_tipo']] ?? $r['configuracion_tipo']) ?></td><td><strong><?= e($r['material_nombre']) ?></strong><br><span class="mini"><?= e($r['material_clave']) ?></span></td><td>
<form method="post" class="inline-form"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="actualizar_regla"><input type="hidden" name="regla_id" value="<?= (int)$r['regla_id'] ?>">
<input class="formula" name="regla_formula" value="<?= e($r['regla_formula']) ?>" required>
<input class="obs" name="regla_observacion" value="<?= e($r['regla_observacion']) ?>" placeholder="Observación opcional">
<select class="estado" name="regla_activa"><option value="SI"<?= $r['regla_activa']==='SI'?' selected':'' ?>>Activa</option><option value="NO"<?= $r['regla_activa']==='NO'?' selected':'' ?>>Inactiva</option></select>
<button class="verde acciones" type="submit">Guardar</button></form></td></tr>
<?php endwhile; ?>
<?php if($reglas->num_rows===0): ?><tr><td colspan="3">No hay reglas para el filtro seleccionado.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php endif; ?>
</div></body></html>
