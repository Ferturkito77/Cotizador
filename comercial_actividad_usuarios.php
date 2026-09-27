<?php
/** Vista y exportaciones del informe, solo a traves de comercial.php. */
if (!defined('AUTOMAC_ACTIVIDAD_USUARIOS')) { http_response_code(404); exit; }
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'GET') {
    header('Allow: GET'); http_response_code(405); exit('Este informe admite consultas GET.');
}
require_once __DIR__ . '/comercial_actividad_datos.php';
$errorActividad = '';
$usuariosActividad = array();
$informeActividad = null;
$exportActividad = '';
$camposActividad = array('desde'=>date('Y-m-01'),'hasta'=>date('Y-m-d'),'usuario'=>'todos');
foreach ($camposActividad as $campo => $valor) {
    if (isset($_GET[$campo]) && is_string($_GET[$campo])) $camposActividad[$campo] = trim($_GET[$campo]);
}
try {
    $exportActividad = auParametro($_GET, 'exportar', '');
    if (!in_array($exportActividad, array('','xlsx','pdf','csv'), true)) throw new InvalidArgumentException('Formato de exportación no admitido.');
    $usuariosActividad = auUsuarios($conexion);
    $filtrosActividad = auFiltros($_GET);
    $informeActividad = auInforme($conexion, $filtrosActividad, $usuariosActividad);
    if ($exportActividad !== '') {
        require_once __DIR__ . '/comercial_actividad_exportar.php';
        auExportar($informeActividad, $exportActividad);
        exit;
    }
} catch (InvalidArgumentException $e) {
    $errorActividad = $e->getMessage(); http_response_code(400);
} catch (Throwable $e) {
    error_log('AUTOMAC actividad por usuario: ' . $e->getMessage());
    $errorActividad = $exportActividad !== '' ? 'No se pudo generar la exportación. No se entregó un archivo incompleto. Vuelva al informe e intente otro formato.' : 'No se pudo consultar la actividad. No se muestran ceros porque el cálculo no está confirmado. Revise la conexión o el registro de errores del servidor.';
    http_response_code(500);
}
header('Cache-Control: private, no-store, max-age=0');
$tabsActividad = array('dashboard'=>'Dashboard','actividad_usuarios'=>'Actividad por usuario','seguimiento'=>'Seguimiento','clientes'=>'Clientes','productos'=>'Productos','tendencias'=>'Tendencias','oportunidades'=>'Oportunidades');
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actividad por usuario | Comercial AUTOMAC</title>
<style>body{margin:0;font-family:Arial,Helvetica,sans-serif;background:#f3f6f8;color:#19344a}</style></head><body>
<?php require __DIR__ . '/menu.php'; ?>
<style>
html body.automac-app #au_informe{max-width:1480px;margin:0 auto;padding:24px;box-sizing:border-box;font:15px/1.45 Arial,Helvetica,sans-serif;color:#19344a}
#au_informe *{box-sizing:border-box}#au_informe h1{font-size:28px!important;line-height:1.2;margin:0 0 5px!important;color:#19344a!important}#au_informe h2{font-size:20px!important;margin:0!important;line-height:1.3}#au_informe p{margin:6px 0 0;font-size:14px!important;color:#526778}
#au_informe .au-cabecera{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:14px}#au_informe .au-revision{font-size:12px;color:#526778;white-space:nowrap}
#au_informe .au-tabs{display:flex;flex-wrap:wrap;gap:7px;margin:16px 0 20px}#au_informe .au-tabs a{font-size:14px!important;font-weight:700;text-decoration:none;border:1px solid #d5e1e8;border-radius:7px;background:#e7edf2;color:#294b68;padding:9px 13px;line-height:1.4}#au_informe .au-tabs .au-activa{background:#234e74;color:#fff;border-color:#234e74}
#au_informe .au-panel{background:#fff;border:1px solid #d7e2e9;border-radius:10px;padding:20px;margin-bottom:18px;min-width:0}
#au_informe .au-filtros{display:grid;grid-template-columns:180px 180px minmax(230px,1fr) auto;align-items:end;gap:16px}#au_informe .au-campo label{display:block!important;font:700 14px/1.4 Arial!important;color:#304e64!important;margin:0 0 6px!important;text-transform:none!important}
html body.automac-app #au_informe .au-campo input,html body.automac-app #au_informe .au-campo select{display:block;width:100%!important;height:44px!important;min-height:44px!important;font:400 16px/1.3 Arial!important;border:1px solid #b9cbd8!important;background:#fff!important;border-radius:6px!important;padding:8px 11px!important;color:#1c394e!important;max-width:100%;box-shadow:none!important}#au_informe select option{font-size:16px!important}
#au_informe .au-acciones{display:flex;flex-wrap:wrap;align-items:center;gap:8px}html body.automac-app #au_informe .au-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #c4d5e1!important;border-radius:6px!important;padding:10px 14px!important;min-height:42px!important;height:auto!important;font:700 14px/1.35 Arial!important;background:#fff!important;color:#275574!important;cursor:pointer;text-decoration:none;white-space:normal;text-align:center}html body.automac-app #au_informe .au-btn.au-primary{background:#176247!important;border-color:#176247!important;color:#fff!important}#au_informe .au-btn:focus-visible,#au_informe a:focus-visible{outline:3px solid #2b74bc;outline-offset:2px}
#au_informe .au-estado-filtros{margin:14px 0 0;color:#4c6274;font-size:14px;display:flex;flex-wrap:wrap;gap:8px 24px}#au_informe .au-estado-filtros strong{color:#233f55}
#au_informe .au-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px;margin-bottom:18px}#au_informe .au-kpi{background:#fff;padding:17px 20px;border:1px solid #d7e2e9;border-radius:9px}#au_informe .au-kpi span{font-size:14px;font-weight:700;color:#36576f}#au_informe .au-kpi strong{display:block;font-size:30px;line-height:1.25;margin:8px 0 4px;color:#176247}#au_informe .au-kpi small{font:13px/1.4 Arial!important;color:#526778}
#au_informe .au-toolbar{display:flex;gap:18px;justify-content:space-between;align-items:center;flex-wrap:wrap;margin-bottom:16px}#au_informe .au-tablewrap{overflow:auto;max-height:65vh;border:1px solid #d5e1e8;border-radius:7px}#au_informe table{border-collapse:separate;border-spacing:0;width:100%;min-width:760px;table-layout:fixed;font:15px/1.4 Arial!important}#au_informe caption{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}
#au_informe th,#au_informe td{padding:13px 15px!important;vertical-align:middle!important;border:0;border-bottom:1px solid #e4ebf0;white-space:normal!important;overflow-wrap:anywhere;text-overflow:clip!important;font-size:15px!important;color:#203f56}#au_informe thead th{position:sticky;top:0;z-index:2;background:#edf3f7;font-weight:700;line-height:1.35;color:#234860}#au_informe th small{display:block;font:12px/1.35 Arial!important;font-weight:400!important;margin-top:4px}#au_informe th:first-child,#au_informe td:first-child{text-align:left!important}#au_informe .au-num{text-align:right!important;font-variant-numeric:tabular-nums}#au_informe .au-total-col{background:#f1f8f4;font-weight:700}#au_informe tbody tr:hover td{background:#f6f9fc}#au_informe td strong{font-size:15px!important}#au_informe td small{display:block;font:12px/1.4 Arial!important;color:#647584;margin-top:4px}#au_informe tfoot th,#au_informe tfoot td{font-weight:700;background:#e6f1eb;color:#154934;border-top:2px solid #b2d3c2;border-bottom:0}
#au_informe .au-alert{border-radius:7px;padding:12px 16px;margin-bottom:16px;background:#fff7e4;border:1px solid #edd7a2;color:#785417;font-size:14px}#au_informe .au-alert.au-error{background:#fff1ef;border-color:#e7bcb6;color:#963f31}#au_informe .au-empty{padding:18px;margin-bottom:14px;border:1px dashed #c1d3df;background:#f6fafc;border-radius:7px;font-size:15px}
#au_informe .au-notas{margin-top:18px;border-top:1px solid #e1e8ed;padding-top:14px}#au_informe .au-notas summary{font-size:14px;font-weight:700;cursor:pointer;color:#36576f}#au_informe .au-notas p{margin:10px 0;font-size:13px!important}#au_informe .au-fuente{font-size:12px;color:#607686;margin-top:12px}
@media(max-width:980px){html body.automac-app #au_informe{padding:18px 14px}#au_informe .au-filtros{grid-template-columns:1fr 1fr}#au_informe .au-campo.au-usuario{grid-column:1/-1}#au_informe .au-kpis{gap:8px}#au_informe .au-kpi{padding:14px}#au_informe .au-tablewrap{max-height:none}}
@media(max-width:540px){#au_informe .au-filtros,#au_informe .au-kpis{grid-template-columns:1fr}#au_informe .au-cabecera{align-items:start}#au_informe .au-panel{padding:15px}#au_informe .au-revision{display:none}}
@media print{.automac-shell,.automac-topbar,.automac-sidebar,.automac-shell-overlay,#au_informe .au-tabs,#au_informe .au-filtros,#au_informe .au-acciones,#au_informe .au-revision{display:none!important}body.automac-app{padding:0!important}#au_informe{max-width:none!important;padding:0!important}#au_informe .au-tablewrap{overflow:visible;max-height:none}#au_informe table{min-width:0}#au_informe thead{display:table-header-group}#au_informe tfoot{display:table-row-group}#au_informe tr{break-inside:avoid}}
</style>
<main id="au_informe">
    <div class="au-cabecera"><div><h1>Comercial</h1><p>Actividad por usuario: presupuestos, obras y pedidos del período.</p></div><span class="au-revision">Informe AU-01</span></div>
    <nav class="au-tabs" aria-label="Secciones de Comercial">
    <?php foreach ($tabsActividad as $key => $label):
        $qTab = array('tab'=>$key,'desde'=>$camposActividad['desde'],'hasta'=>$camposActividad['hasta']);
        if ($key==='actividad_usuarios') $qTab['usuario']=$camposActividad['usuario'];
    ?>
        <a href="<?=auEsc('comercial.php?'.http_build_query($qTab))?>" class="<?=$key==='actividad_usuarios'?'au-activa':''?>" <?=$key==='actividad_usuarios'?'aria-current="page"':''?>><?=auEsc($label)?></a>
    <?php endforeach; ?>
    </nav>
    <section class="au-panel" aria-label="Filtros de actividad">
        <form id="au_filtros" class="au-filtros" action="comercial.php" method="get">
            <input type="hidden" name="tab" value="actividad_usuarios">
            <div class="au-campo"><label for="au_desde">Desde</label><input id="au_desde" type="date" name="desde" value="<?=auEsc($camposActividad['desde'])?>" required></div>
            <div class="au-campo"><label for="au_hasta">Hasta</label><input id="au_hasta" type="date" name="hasta" value="<?=auEsc($camposActividad['hasta'])?>" required></div>
            <div class="au-campo au-usuario"><label for="au_usuario">Usuario registrado en el documento</label><select id="au_usuario" name="usuario">
                <option value="todos" <?=$camposActividad['usuario']==='todos'?'selected':''?>>Todos los usuarios</option>
                <?php foreach ($usuariosActividad as $u): $uid=(string)$u['usuario_id']; ?>
                <option value="<?=auEsc($uid)?>" <?=$camposActividad['usuario']===$uid?'selected':''?>><?=auEsc(auNombreUsuario($u).' | '.$u['usuario_login'].($u['usuario_activo']==='SI'?'':' (inactivo)'))?></option>
                <?php endforeach; ?>
                <option value="sin_usuario" <?=$camposActividad['usuario']==='sin_usuario'?'selected':''?>>Sin usuario asociado (datos históricos)</option>
            </select></div>
            <div class="au-acciones"><button class="au-btn au-primary" type="submit">Consultar</button><a class="au-btn" href="comercial.php?tab=actividad_usuarios">Limpiar</a></div>
        </form>
        <?php if ($informeActividad): ?><div class="au-estado-filtros"><span>Período consultado: <strong><?=auEsc($filtrosActividad['periodo'])?></strong></span><span>Usuario: <strong><?=auEsc($informeActividad['usuario_etiqueta'])?></strong></span></div><?php endif; ?>
    </section>
    <?php if ($errorActividad !== ''): ?><div class="au-alert au-error" role="alert"><?=auEsc($errorActividad)?></div><?php endif; ?>
    <?php if ($informeActividad && $errorActividad === ''): $t=$informeActividad['totales']; ?>
    <div class="au-kpis">
        <article class="au-kpi"><span>Total presupuestos</span><strong><?=number_format($t['presupuestos'],0,',','.')?></strong><small>C. Control + R. Suministros</small></article>
        <article class="au-kpi"><span>Obras #</span><strong><?=number_format($t['obras'],0,',','.')?></strong><small>Pedidos clasificados como obra</small></article>
        <article class="au-kpi"><span>Pedidos P.</span><strong><?=number_format($t['p'],0,',','.')?></strong><small>Pedidos de suministros</small></article>
    </div>
    <section class="au-panel">
        <div class="au-toolbar"><div><h2>Documentos por usuario</h2><p>Sin anulados ni borradores. Cada documento principal se cuenta una vez.</p></div>
            <div class="au-acciones" aria-label="Exportar el informe consultado">
                <a class="au-btn au-primary" href="<?=auEsc(auUrl($filtrosActividad,'xlsx'))?>">Exportar Excel</a>
                <a class="au-btn" href="<?=auEsc(auUrl($filtrosActividad,'pdf'))?>">Exportar PDF</a>
                <a class="au-btn" href="<?=auEsc(auUrl($filtrosActividad,'csv'))?>">CSV</a>
            </div>
        </div>
        <?php if ($t['presupuestos']+$t['obras']+$t['p']===0): ?><div class="au-empty" role="status">No hay documentos válidos en el período y usuario seleccionados.</div><?php endif; ?>
        <?php if ($informeActividad['sin_asociar']>0): ?><div class="au-alert">Hay <?=number_format($informeActividad['sin_asociar'],0,',','.')?> documentos sin una cuenta de usuario asociada. Se incluyen en el total y se muestran separados; no se asignan por coincidencia de nombre.</div><?php endif; ?>
        <div class="au-tablewrap" tabindex="0" role="region" aria-label="Tabla de actividad por usuario">
            <table id="au_tabla"><caption>Cantidad de documentos por usuario del <?=auEsc($filtrosActividad['periodo'])?></caption>
                <colgroup><col style="width:30%"><col style="width:13%"><col style="width:14%"><col style="width:17%"><col style="width:13%"><col style="width:13%"></colgroup>
                <thead><tr><th scope="col">Usuario</th><th scope="col" class="au-num">C.<small>Presupuestos<br>de Control</small></th><th scope="col" class="au-num">R.<small>Presupuestos<br>de suministros</small></th><th scope="col" class="au-num">Total<br>presupuestos<small>C. + R.</small></th><th scope="col" class="au-num">Obras #<small>Pedidos<br>de obra</small></th><th scope="col" class="au-num">Pedidos P.<small>Suministros</small></th></tr></thead>
                <tbody><?php foreach ($informeActividad['filas'] as $fila): ?><tr>
                    <td><strong><?=auEsc($fila['nombre'])?></strong><small><?=auEsc($fila['detalle'])?></small></td>
                    <?php foreach (array('c','r','presupuestos','obras','p') as $col): ?><td class="au-num <?=$col==='presupuestos'?'au-total-col':''?>"><?=number_format($fila[$col],0,',','.')?></td><?php endforeach; ?>
                </tr><?php endforeach; ?>
                <?php if (!$informeActividad['filas']): ?><tr><td colspan="6">No hay registros para este filtro.</td></tr><?php endif; ?></tbody>
                <tfoot><tr><th scope="row">TOTAL DEL PERÍODO</th><?php foreach(array('c','r','presupuestos','obras','p') as $col): ?><td class="au-num"><?=number_format($t[$col],0,',','.')?></td><?php endforeach; ?></tr></tfoot>
            </table>
        </div>
        <div id="au_filtros_aviso" class="au-alert" role="status" hidden>Los filtros cambiaron. Pulse Consultar para actualizar el informe y habilitar las exportaciones.</div>
        <div class="au-fuente">Consultado: <?=auEsc($informeActividad['generado'])?>. Las exportaciones incluyen el período y el usuario consultados. Después de cambiar un filtro, pulse Consultar.</div>
        <details class="au-notas"><summary>Cómo se cuentan los documentos</summary><?php foreach(auCriterios() as $criterio): ?><p><?=auEsc($criterio)?></p><?php endforeach; ?></details>
    </section>
    <?php endif; ?>
</main>

<script>
(function(){
    var f=document.getElementById('au_filtros');
    if(!f)return;
    var inicial={desde:f.elements.desde.value,hasta:f.elements.hasta.value,usuario:f.elements.usuario.value};
    var links=document.querySelectorAll('#au_informe .au-toolbar .au-acciones a');
    var aviso=document.getElementById('au_filtros_aviso');
    function revisar(){
        var distinto=Object.keys(inicial).some(function(k){return f.elements[k].value!==inicial[k];});
        Array.prototype.forEach.call(links,function(a){
            if(!a.dataset.auHref)a.dataset.auHref=a.getAttribute('href');
            if(distinto){a.removeAttribute('href');a.setAttribute('aria-disabled','true');a.setAttribute('tabindex','-1');a.style.opacity='.5';}
            else{a.setAttribute('href',a.dataset.auHref);a.removeAttribute('aria-disabled');a.removeAttribute('tabindex');a.style.opacity='';}
        });
        if(aviso)aviso.hidden=!distinto;
    }
    f.addEventListener('input',revisar);f.addEventListener('change',revisar);
})();
</script>
</body></html>
