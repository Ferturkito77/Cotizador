<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
require_once 'auditoria_actividad.php';
asegurarAuditoriaActividad($conexion);
$mensajeMantenimiento='';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='limpiar_antigua'){
    $eliminadas=auditoriaEliminarAnterioresA3Anios($conexion,200000);
    $mensajeMantenimiento='Limpieza realizada: '.number_format($eliminadas,0,',','.').' registros con más de 3 años eliminados.';
}

$desde=trim((string)($_GET['desde']??date('Y-m-d')));
$hasta=trim((string)($_GET['hasta']??date('Y-m-d')));
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$desde))$desde=date('Y-m-d');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$hasta))$hasta=date('Y-m-d');
$uid=max(0,(int)($_GET['usuario_id']??0));
$modulo=strtoupper(trim((string)($_GET['modulo']??'')));
$q=trim((string)($_GET['q']??''));

$usuarios=array();$r=$conexion->query("SELECT usuario_id,usuario_nombre,usuario_login FROM usuarios ORDER BY usuario_nombre,usuario_login");if($r)while($x=$r->fetch_assoc())$usuarios[]=$x;
$mods=array();$r=$conexion->query("SELECT DISTINCT modulo FROM auditoria_actividad WHERE modulo<>'' ORDER BY modulo");if($r)while($x=$r->fetch_assoc())$mods[]=$x['modulo'];

$sql="SELECT * FROM auditoria_actividad WHERE fecha BETWEEN ? AND ?";$params=array($desde.' 00:00:00',$hasta.' 23:59:59');$types='ss';
if($uid>0){$sql.=" AND usuario_id=?";$params[]=$uid;$types.='i';}
if($modulo!==''){$sql.=" AND modulo=?";$params[]=$modulo;$types.='s';}
if($q!==''){$sql.=" AND (accion LIKE ? OR detalle LIKE ? OR referencia LIKE ? OR usuario_nombre LIKE ?)";$like='%'.$q.'%';array_push($params,$like,$like,$like,$like);$types.='ssss';}
$sql.=" ORDER BY fecha DESC,actividad_id DESC LIMIT 2000";
$st=$conexion->prepare($sql);$st->bind_param($types,...$params);$st->execute();$res=$st->get_result();$filas=array();while($x=$res->fetch_assoc())$filas[]=$x;$st->close();

$hoy=date('Y-m-d');
$totalAuditoria=auditoriaCantidad($conexion);
$kpi=array('acciones'=>0,'usuarios'=>0);$r=$conexion->query("SELECT COUNT(*) acciones,COUNT(DISTINCT usuario_id) usuarios FROM auditoria_actividad WHERE fecha>='{$hoy} 00:00:00'");if($r)$kpi=$r->fetch_assoc();
function ae($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Historial de actividad</title><style>
*{box-sizing:border-box}body{margin:0;background:#eef3f6;font-family:Arial,sans-serif;color:#17324d}.wrap{max-width:1550px;margin:auto;padding:20px}.hero{display:flex;justify-content:space-between;align-items:end;gap:12px;flex-wrap:wrap}.hero h1{margin:0;font-size:28px}.sub{font-size:12px;color:#6d7f8d;margin:5px 0 14px}.panel,.kpi{background:#fff;border:1px solid #dce5ea;border-radius:13px;box-shadow:0 5px 18px rgba(23,50,77,.045)}.filters{padding:12px;display:flex;gap:8px;align-items:end;flex-wrap:wrap}.filters label{display:block;font-size:10px;font-weight:900;text-transform:uppercase;color:#607584;margin-bottom:4px}.filters input,.filters select{height:38px;border:1px solid #cbd8df;border-radius:7px;padding:0 9px}.btn{height:38px;border:0;border-radius:8px;padding:0 13px;background:#1f6feb;color:#fff;font-weight:800;cursor:pointer}.kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:13px 0}.kpi{padding:14px}.kpi small{display:block;color:#6d7f8d;font-size:10px;font-weight:900;text-transform:uppercase}.kpi strong{display:block;font-size:25px;color:#087a4a;margin-top:6px}.tablewrap{overflow:auto;max-height:68vh}table{width:100%;border-collapse:collapse;font-size:12px}th{position:sticky;top:0;background:#edf3f6;text-align:left;padding:9px;white-space:nowrap;border-bottom:1px solid #d7e1e7}td{padding:9px;border-bottom:1px solid #edf1f3;vertical-align:top}.user{font-weight:800}.muted{font-size:10px;color:#6d7f8d}.pill{display:inline-block;border-radius:999px;padding:4px 7px;background:#e8f5ee;color:#087a4a;font-size:9px;font-weight:900}.detail{max-width:520px;white-space:normal}.ref{font-family:Consolas,monospace;font-size:10px;color:#5d7180}@media(max-width:800px){.kpis{grid-template-columns:1fr}.wrap{padding:10px}}
</style></head><body><?php require __DIR__.'/menu.php';?><main class="wrap"><div class="hero"><div><h1>Historial de actividad de usuarios</h1><p class="sub">Auditoría diaria de las acciones realizadas dentro del cotizador. Los cálculos automáticos internos no se registran.</p></div></div>
<form class="panel filters" method="get"><div><label>Desde</label><input type="date" name="desde" value="<?=ae($desde)?>"></div><div><label>Hasta</label><input type="date" name="hasta" value="<?=ae($hasta)?>"></div><div><label>Usuario</label><select name="usuario_id"><option value="0">Todos</option><?php foreach($usuarios as $u):?><option value="<?=(int)$u['usuario_id']?>"<?=$uid===(int)$u['usuario_id']?' selected':''?>><?=ae($u['usuario_nombre'].' · '.$u['usuario_login'])?></option><?php endforeach;?></select></div><div><label>Módulo</label><select name="modulo"><option value="">Todos</option><?php foreach($mods as $m):?><option<?=$modulo===$m?' selected':''?>><?=ae($m)?></option><?php endforeach;?></select></div><div style="min-width:260px;flex:1"><label>Buscar acción / referencia</label><input style="width:100%" name="q" value="<?=ae($q)?>" placeholder="pedido, cliente, modificación..."></div><button class="btn">Filtrar</button></form>
<?php if($mensajeMantenimiento):?><div class="panel" style="padding:10px 12px;margin-top:10px;background:#e9f7ef;color:#14623f;font-weight:800"><?=ae($mensajeMantenimiento)?></div><?php endif;?>
<div class="panel" style="padding:10px 12px;margin-top:10px;display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap"><div><strong>Mantenimiento del historial</strong><div class="sub" style="margin:2px 0 0">Retención automática: 3 años. La limpieza se ejecuta en pequeños bloques y esta pantalla permite una limpieza manual mayor.</div></div><div style="display:flex;gap:7px;flex-wrap:wrap"><a class="btn" style="text-decoration:none;display:inline-flex;align-items:center" href="auditoria_exportar.php?<?=ae(http_build_query(array('desde'=>$desde,'hasta'=>$hasta,'usuario_id'=>$uid,'modulo'=>$modulo,'q'=>$q)))?>">Exportar filtro CSV</a><form method="post" onsubmit="return confirm('¿Eliminar los registros de auditoría con más de 3 años?')"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="limpiar_antigua"><button class="btn" style="background:#667785">Limpiar &gt; 3 años</button></form></div></div>
<div class="kpis"><div class="kpi"><small>Acciones hoy</small><strong><?=number_format((int)($kpi['acciones']??0),0,',','.')?></strong></div><div class="kpi"><small>Usuarios con actividad hoy</small><strong><?=(int)($kpi['usuarios']??0)?></strong></div><div class="kpi"><small>Resultados del filtro</small><strong><?=count($filas)?></strong></div><div class="kpi"><small>Total almacenado</small><strong><?=number_format($totalAuditoria,0,',','.')?></strong><span class="muted">Retención: 3 años</span></div></div>
<section class="panel"><div class="tablewrap"><table><thead><tr><th>Fecha / hora</th><th>Usuario</th><th>Módulo</th><th>Acción</th><th>Detalle</th><th>Referencia</th><th>Página</th></tr></thead><tbody><?php if(!$filas):?><tr><td colspan="7" style="text-align:center;padding:30px;color:#6d7f8d">No hay actividad para estos filtros.</td></tr><?php else:foreach($filas as $f):?><tr><td><?=date('d/m/Y H:i:s',strtotime($f['fecha']))?></td><td><span class="user"><?=ae($f['usuario_nombre'])?></span><span class="muted"><?=ae($f['usuario_login'].' · '.$f['usuario_rol'])?></span></td><td><span class="pill"><?=ae($f['modulo'])?></span></td><td><strong><?=ae($f['accion'])?></strong></td><td class="detail"><?=ae($f['detalle'])?></td><td class="ref"><?=ae($f['referencia']?:'—')?></td><td><span class="muted"><?=ae($f['pagina'])?> · <?=ae($f['metodo'])?></span></td></tr><?php endforeach;endif;?></tbody></table></div></section></main></body></html>
