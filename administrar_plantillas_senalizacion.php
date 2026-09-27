<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'plantillas_senalizacion.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');
asegurarTablaPlantillasSenalizacion($conexion);

function psE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function psOptions(mysqli $c, string $sql, string $value, string $label, string $selected=''): string {
    $out=''; $rs=$c->query($sql); if(!$rs)return $out;
    while($r=$rs->fetch_assoc()){
        $v=(string)$r[$value]; $sel=((string)$selected===$v)?' selected':'';
        $out.='<option value="'.psE($v).'"'.$sel.'>'.psE($r[$label]).'</option>';
    }
    return $out;
}

$mensaje=(string)($_SESSION['senal_plantilla_mensaje']??''); unset($_SESSION['senal_plantilla_mensaje']);
$error='';
$editarId=(int)($_GET['editar']??0);
$edicion=$editarId>0?obtenerPlantillaSenalizacion($conexion,$editarId):null;

try{
    if($_SERVER['REQUEST_METHOD']==='POST'){
        $accion=(string)($_POST['accion']??'guardar');
        $id=(int)($_POST['plantilla_id']??0);
        if($accion==='eliminar'){
            if($id<=0) throw new RuntimeException('Plantilla inválida.');
            $st=$conexion->prepare('DELETE FROM plantillas_senalizacion WHERE plantilla_id=?');
            $st->bind_param('i',$id); $st->execute(); $st->close();
            $_SESSION['senal_plantilla_mensaje']='Plantilla eliminada correctamente.';
            header('Location: administrar_plantillas_senalizacion.php'); exit;
        }
        if($accion==='estado'){
            $estado=((string)($_POST['estado']??'SI')==='SI')?'NO':'SI';
            $st=$conexion->prepare('UPDATE plantillas_senalizacion SET plantilla_activa=? WHERE plantilla_id=?');
            $st->bind_param('si',$estado,$id); $st->execute(); $st->close();
            $_SESSION['senal_plantilla_mensaje']='Estado actualizado.';
            header('Location: administrar_plantillas_senalizacion.php'); exit;
        }
        $codigo=strtoupper(trim((string)($_POST['plantilla_codigo']??'')));
        $nombre=trim((string)($_POST['plantilla_nombre']??''));
        $descripcion=trim((string)($_POST['plantilla_descripcion']??''));
        $tipoModulo=trim((string)($_POST['senal_tipo_modulo']??''));
        $puerta=trim((string)($_POST['senal_tipo_puerta']??''));
        $modelo=trim((string)($_POST['senal_modelo']??''));
        $color=trim((string)($_POST['senal_color']??''));
        $tecla=trim((string)($_POST['senal_tecla']??''));
        $tension=trim((string)($_POST['senal_tension']??''));
        $borne=trim((string)($_POST['senal_borne_manual']??''));
        $indicador=trim((string)($_POST['senal_indicador_modelo']??''));
        if($codigo===''||$nombre==='') throw new RuntimeException('Código y nombre son obligatorios.');
        if($tipoModulo===''||$puerta===''||$modelo===''||$color===''||$tecla===''||$tension===''||$borne==='') throw new RuntimeException('Complete los siete datos técnicos de la plantilla.');
        if($id>0){
            $st=$conexion->prepare("UPDATE plantillas_senalizacion SET plantilla_codigo=?,plantilla_nombre=?,plantilla_descripcion=?,senal_tipo_modulo=?,senal_tipo_puerta=?,senal_modelo=?,senal_color=?,senal_tecla=?,senal_tension=?,senal_borne_manual=?,senal_indicador_modelo=? WHERE plantilla_id=?");
            $st->bind_param('sssssssssssi',$codigo,$nombre,$descripcion,$tipoModulo,$puerta,$modelo,$color,$tecla,$tension,$borne,$indicador,$id);
        }else{
            $st=$conexion->prepare("INSERT INTO plantillas_senalizacion (plantilla_codigo,plantilla_nombre,plantilla_descripcion,senal_tipo_modulo,senal_tipo_puerta,senal_modelo,senal_color,senal_tecla,senal_tension,senal_borne_manual,senal_indicador_modelo) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
            $st->bind_param('sssssssssss',$codigo,$nombre,$descripcion,$tipoModulo,$puerta,$modelo,$color,$tecla,$tension,$borne,$indicador);
        }
        if(!$st->execute()) throw new RuntimeException($st->errno===1062?'Ya existe una plantilla con ese código.':$st->error);
        $st->close();
        $_SESSION['senal_plantilla_mensaje']='Plantilla '.$codigo.' guardada correctamente.';
        header('Location: administrar_plantillas_senalizacion.php'); exit;
    }
}catch(Throwable $e){$error=$e->getMessage();}

$plantillas=listarPlantillasSenalizacion($conexion,false);
$sel=function($campo,$default='')use($edicion){return (string)($edicion[$campo]??$default);};
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Plantillas de Señalización · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
*{box-sizing:border-box}.main{padding:24px;max-width:1450px;margin:auto}.hero,.box{background:#fff;border:1px solid #dfe5ec;border-radius:14px;padding:18px;margin-bottom:16px}.hero h1{margin:0 0 5px}.hero p{margin:0;color:#64748b}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.campo label{display:block;font-size:11px;font-weight:800;color:#526474;margin-bottom:5px;text-transform:uppercase}.campo input,.campo select{width:100%;height:40px;border:1px solid #cfd8e3;border-radius:8px;padding:7px 9px;background:#fff}.span2{grid-column:span 2}.span4{grid-column:1/-1}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn,button{display:inline-block;border:0;border-radius:8px;padding:9px 13px;text-decoration:none;font-weight:800;cursor:pointer}.primary{background:#176b45;color:#fff}.secondary{background:#eaf0f5;color:#234}.danger{background:#dc3545;color:#fff}.blue{background:#0d6efd;color:#fff}.msg{padding:10px 12px;border-radius:8px;margin-bottom:12px}.ok{background:#d1e7dd;color:#0f5132}.err{background:#f8d7da;color:#842029}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:9px;border-bottom:1px solid #e2e8ee;text-align:left;vertical-align:top}th{background:#f3f6f8}.config{color:#506273;line-height:1.5}.inactive{opacity:.55}@media(max-width:900px){.form-grid{grid-template-columns:1fr 1fr}.span4{grid-column:1/-1}}@media(max-width:600px){.form-grid{grid-template-columns:1fr}.span2,.span4{grid-column:1}}
</style></head><body><?php require 'menu.php'; ?><main class="main">
<section class="hero"><h1>Plantillas de Señalización</h1><p>Combinaciones rápidas para Botonera de cabina. La plantilla completa la configuración base de la botonera y puede guardar también el Indicador de cabina. Si no usa plantilla, la botonera se configura manualmente en el cotizador.</p></section>
<?php if($mensaje!==''):?><div class="msg ok"><?=psE($mensaje)?></div><?php endif;?><?php if($error!==''):?><div class="msg err"><?=psE($error)?></div><?php endif;?>
<section class="box"><h2 style="margin-top:0"><?=$edicion?'Modificar plantilla':'Nueva plantilla'?></h2><form method="post" class="form-grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="plantilla_id" value="<?= (int)($edicion['plantilla_id']??0) ?>">
<div class="campo"><label>Código</label><input name="plantilla_codigo" maxlength="30" required value="<?=psE($sel('plantilla_codigo'))?>" placeholder="Ej.: ELEC-PM-A3900"></div>
<div class="campo span2"><label>Nombre</label><input name="plantilla_nombre" maxlength="150" required value="<?=psE($sel('plantilla_nombre'))?>" placeholder="Ej.: Electrónica estándar PM"></div>
<div class="campo"><label>Estado</label><input value="<?=psE($sel('plantilla_activa','SI'))?>" readonly></div>
<div class="campo span4"><label>Descripción</label><input name="plantilla_descripcion" maxlength="500" value="<?=psE($sel('plantilla_descripcion'))?>" placeholder="Descripción opcional"></div>
<div class="campo"><label>Módulos</label><select name="senal_tipo_modulo" required><option value="">Seleccione...</option><?=psOptions($conexion,'SELECT tipo_modulo_id,tipo_modulo_nombre FROM senal_tipos_modulo ORDER BY tipo_modulo_id','tipo_modulo_id','tipo_modulo_nombre',$sel('senal_tipo_modulo'))?></select></div>
<div class="campo"><label>Puerta</label><select name="senal_tipo_puerta" required><option value="PM"<?=$sel('senal_tipo_puerta','PM')==='PM'?' selected':''?>>PM - Manual</option><option value="PA"<?=$sel('senal_tipo_puerta')==='PA'?' selected':''?>>PA - Automática</option></select></div>
<div class="campo"><label>Modelo de pulsador</label><select name="senal_modelo" required><option value="">Seleccione...</option><?=psOptions($conexion,'SELECT modelo_pulsador_id,modelo_pulsador_nombre FROM senal_modelos_pulsador ORDER BY modelo_pulsador_id','modelo_pulsador_id','modelo_pulsador_nombre',$sel('senal_modelo'))?></select></div>
<div class="campo"><label>Color</label><select name="senal_color" required><option value="">Seleccione...</option><?=psOptions($conexion,'SELECT color_registro_id,color_registro_nombre FROM senal_colores_registro ORDER BY color_registro_id','color_registro_id','color_registro_nombre',$sel('senal_color'))?></select></div>
<div class="campo"><label>Tecla</label><select name="senal_tecla" required><option value="">Seleccione...</option><?=psOptions($conexion,'SELECT tecla_id,tecla_nombre FROM senal_teclas ORDER BY tecla_id','tecla_id','tecla_nombre',$sel('senal_tecla'))?></select></div>
<div class="campo"><label>Tensión</label><select name="senal_tension" required><option value="">Seleccione...</option><?=psOptions($conexion,'SELECT tension_modulo_id,tension_modulo_nombre FROM senal_tensiones_modulo ORDER BY tension_modulo_id','tension_modulo_id','tension_modulo_nombre',$sel('senal_tension'))?></select></div>
<div class="campo"><label>Bornes</label><select name="senal_borne_manual" required><option value="1"<?=$sel('senal_borne_manual','1')==='1'?' selected':''?>>3B</option><option value="2"<?=$sel('senal_borne_manual')==='2'?' selected':''?>>4B</option></select></div>
<div class="campo span2"><label>Indicador de cabina</label><select name="senal_indicador_modelo"><option value="">Sin indicador / completar manualmente</option><?php $ri=$conexion->query("SELECT DISTINCT modelo_indicador FROM senal_indicadores_cabina WHERE activo=1 AND TRIM(modelo_indicador)<>'' ORDER BY modelo_indicador"); if($ri) while($ii=$ri->fetch_assoc()): $iv=(string)$ii['modelo_indicador']; ?><option value="<?=psE($iv)?>"<?=$sel('senal_indicador_modelo')===$iv?' selected':''?>><?=psE($iv)?></option><?php endwhile; ?></select></div>
<div class="actions span4"><button class="primary" type="submit">GUARDAR PLANTILLA</button><?php if($edicion):?><a class="btn secondary" href="administrar_plantillas_senalizacion.php">CANCELAR</a><?php endif;?></div>
</form></section>
<section class="box"><h2 style="margin-top:0">Plantillas creadas</h2><div class="table-wrap"><table><thead><tr><th>Código</th><th>Nombre</th><th>Configuración</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php if($plantillas): foreach($plantillas as $p):?><tr class="<?=$p['plantilla_activa']==='SI'?'':'inactive'?>"><td><strong><?=psE($p['plantilla_codigo'])?></strong></td><td><strong><?=psE($p['plantilla_nombre'])?></strong><br><span class="config"><?=psE($p['plantilla_descripcion'])?></span></td><td class="config">Módulo <?=psE($p['senal_tipo_modulo'])?> · Puerta <?=psE($p['senal_tipo_puerta'])?> · Modelo <?=psE($p['senal_modelo'])?> · Color <?=psE($p['senal_color'])?> · Tecla <?=psE($p['senal_tecla'])?> · Tensión <?=psE($p['senal_tension'])?> · Bornes <?=psE($p['senal_borne_manual']==='1'?'3B':'4B')?> · Indicador <?=psE(trim((string)($p['senal_indicador_modelo']??''))!==''?(string)$p['senal_indicador_modelo']:'Manual / sin indicador')?></td><td><?=psE($p['plantilla_activa'])?></td><td><div class="actions"><a class="btn blue" href="?editar=<?=(int)$p['plantilla_id']?>">Modificar</a><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="estado"><input type="hidden" name="plantilla_id" value="<?=(int)$p['plantilla_id']?>"><input type="hidden" name="estado" value="<?=psE($p['plantilla_activa'])?>"><button class="secondary" type="submit"><?=$p['plantilla_activa']==='SI'?'Desactivar':'Activar'?></button></form><form method="post" onsubmit="return confirm('¿Eliminar esta plantilla?')"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="plantilla_id" value="<?=(int)$p['plantilla_id']?>"><button class="danger" type="submit">Eliminar</button></form></div></td></tr><?php endforeach; else:?><tr><td colspan="5">Todavía no hay plantillas de Señalización.</td></tr><?php endif;?>
</tbody></table></div></section>
</main></body></html>
