<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
require_once 'auditoria_actividad.php';
require_once 'documentos_eventos.php';

function adE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function adNumeroEnteroObra($numero): int {
    return preg_match('/^#([0-9]+)$/', trim((string)$numero), $m) ? (int)$m[1] : 0;
}
function adRecalcularNumeracionObras(mysqli $conexion, int $numeroAnulado): void {
    if ($numeroAnulado <= 0) return;
    $st=$conexion->prepare("SELECT numeracion_ultimo FROM numeracion_documentos WHERE numeracion_serie='PEDIDO_OBRA' FOR UPDATE");
    $st->execute(); $fila=$st->get_result()->fetch_assoc(); $st->close();
    $ultimo=(int)($fila['numeracion_ultimo']??0);
    if ($ultimo !== $numeroAnulado) return; // Solo retrocede si se anuló la última obra de la serie.
    $r=$conexion->query("SELECT MAX(CAST(SUBSTRING(pedido_numero,2) AS UNSIGNED)) AS maximo FROM pedidos WHERE pedido_tipo='OBRA' AND estado<>'ANULADO' AND pedido_numero REGEXP '^#[0-9]+$'");
    $maximo=$r ? (int)($r->fetch_assoc()['maximo']??0) : 0;
    $st=$conexion->prepare("UPDATE numeracion_documentos SET numeracion_ultimo=? WHERE numeracion_serie='PEDIDO_OBRA'");
    $st->bind_param('i',$maximo); $st->execute(); $st->close();
}

$tipo=strtolower(trim((string)($_REQUEST['tipo']??'')));
$id=(int)($_REQUEST['id']??0);
if(!in_array($tipo,array('cotizacion','pedido'),true) || $id<=0) die('Documento inválido.');

$doc=null;
if($tipo==='cotizacion'){
    $st=$conexion->prepare("SELECT c.*,cl.clientes_nomfantasia,(SELECT COUNT(*) FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id AND p.estado<>'ANULADO') AS pedidos_activos FROM cotizaciones c JOIN clientes cl ON cl.clientes_id=c.cliente_id WHERE c.cotizacion_id=? LIMIT 1");
}else{
    $st=$conexion->prepare("SELECT p.*,c.cotizacion_numero,c.estado AS cotizacion_estado,cl.clientes_nomfantasia FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id JOIN clientes cl ON cl.clientes_id=p.cliente_id WHERE p.pedido_id=? LIMIT 1");
}
$st->bind_param('i',$id); $st->execute(); $doc=$st->get_result()->fetch_assoc(); $st->close();
if(!$doc) die('El documento no existe.');

$error='';
if($_SERVER['REQUEST_METHOD']==='POST'){
    automacValidarCsrf();
    $motivo=trim((string)($_POST['motivo']??''));
    $reabrir=isset($_POST['reabrir_cotizacion']) && $_POST['reabrir_cotizacion']==='1';
    if($motivo==='') $error='Indique el motivo de la anulación.';
    elseif(mb_strlen($motivo)>500) $error='El motivo no puede superar 500 caracteres.';
    elseif(($doc['estado']??'')==='ANULADO' || ($doc['estado']??'')==='ANULADA') $error='El documento ya está anulado.';
    elseif($tipo==='cotizacion' && (int)($doc['pedidos_activos']??0)>0) $error='La cotización tiene un pedido/obra vigente. Anule primero ese pedido/obra.';
    else{
        $uid=(int)($_SESSION['usuario_id']??0); $unombre=(string)($_SESSION['usuario_nombre']??$_SESSION['usuario']??'');
        $conexion->begin_transaction();
        try{
            if($tipo==='cotizacion'){
                $st=$conexion->prepare("UPDATE cotizaciones SET estado='ANULADA',anulacion_fecha=NOW(),anulacion_motivo=?,anulacion_usuario=?,anulacion_usuario_id=? WHERE cotizacion_id=? AND estado<>'ANULADA'");
                $st->bind_param('ssii',$motivo,$unombre,$uid,$id); if(!$st->execute()) throw new RuntimeException($st->error); $st->close();
                $numero=(string)$doc['cotizacion_numero'];
                documentoEventoRegistrar($conexion,'COTIZACION',$id,$numero,'ANULACION',(string)($doc['estado']??''),'ANULADA',$motivo);
                auditoriaRegistrar($conexion,'Anuló cotización','COTIZADOR','Motivo: '.$motivo,$numero);
                $conexion->commit();
                header('Location: cotizaciones.php?anulada=1'); exit;
            }

            $numero=(string)$doc['pedido_numero'];
            $numeroObra=($doc['pedido_tipo']==='OBRA') ? adNumeroEnteroObra($numero) : 0;
            $st=$conexion->prepare("UPDATE pedidos SET estado='ANULADO',anulacion_fecha=NOW(),anulacion_motivo=?,anulacion_usuario=?,anulacion_usuario_id=?,fecha_ultima_modificacion=NOW(),usuario_modificacion=?,usuario_modificacion_id=? WHERE pedido_id=? AND estado<>'ANULADO'");
            $st->bind_param('ssisii',$motivo,$unombre,$uid,$unombre,$uid,$id); if(!$st->execute()) throw new RuntimeException($st->error); $st->close();

            if($numeroObra>0) adRecalcularNumeracionObras($conexion,$numeroObra);

            if($reabrir && ($doc['origen']??'')==='COTIZACION' && (int)($doc['cotizacion_id']??0)>0){
                $cid=(int)$doc['cotizacion_id'];
                $st=$conexion->prepare("UPDATE cotizaciones SET estado='EMITIDA' WHERE cotizacion_id=? AND estado='ACEPTADA'");
                $st->bind_param('i',$cid); if(!$st->execute()) throw new RuntimeException($st->error); $st->close();
            }
            documentoEventoRegistrar($conexion,($doc['pedido_tipo']==='OBRA')?'OBRA':'PEDIDO',$id,$numero,'ANULACION',(string)($doc['estado']??''),'ANULADO',$motivo.($reabrir?' · cotización reabierta':''));
            auditoriaRegistrar($conexion,'Anuló '.(($doc['pedido_tipo']==='OBRA')?'obra':'pedido'),'PEDIDOS','Motivo: '.$motivo.($reabrir?' · cotización reabierta':''),$numero);
            $conexion->commit();
            header('Location: pedidos.php?anulado=1'); exit;
        }catch(Throwable $e){
            $conexion->rollback(); $error='No se pudo anular el documento: '.$e->getMessage();
        }
    }
}
$numero=$tipo==='cotizacion'?(string)$doc['cotizacion_numero']:(string)$doc['pedido_numero'];
$esObra=$tipo==='pedido' && ($doc['pedido_tipo']??'')==='OBRA';
$titulo=$tipo==='cotizacion'?'Anular cotización':($esObra?'Anular obra':'Anular pedido');
$volver=$tipo==='cotizacion'?'cotizaciones.php':'pedidos.php';
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=adE($titulo)?></title><style>
body{font-family:Arial;background:#f4f4f9;margin:18px;color:#202124}.c{max-width:760px;margin:auto}.card{background:#fff;border:1px solid #ddd;border-radius:10px;padding:20px}.alerta{background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:12px;border-radius:7px;margin-bottom:14px}.error{background:#f8d7da;border:1px solid #f1aeb5;color:#842029;padding:12px;border-radius:7px;margin-bottom:14px}label{display:block;font-weight:bold;margin:14px 0 6px}textarea{width:100%;min-height:95px;padding:10px;box-sizing:border-box;border:1px solid #aaa;border-radius:6px;font:inherit}.check{display:flex;gap:9px;align-items:flex-start;font-weight:normal}.check input{margin-top:3px}.acciones{display:flex;gap:8px;margin-top:18px}.btn{display:inline-block;padding:10px 14px;border:0;border-radius:6px;text-decoration:none;font-weight:bold;cursor:pointer}.rojo{background:#dc3545;color:#fff}.gris{background:#6c757d;color:#fff}.dato{padding:10px 0;border-bottom:1px solid #eee}.dato strong{display:inline-block;min-width:130px}
</style></head><body><div class="c"><?php require __DIR__.'/menu.php'; ?><div class="card"><h1><?=adE($titulo)?> <?=adE(numeroDocumentoVisible($numero))?></h1>
<?php if($error!==''):?><div class="error"><?=adE($error)?></div><?php endif;?>
<div class="dato"><strong>Cliente</strong><?=adE($doc['clientes_nomfantasia']??'')?></div><div class="dato"><strong>Estado actual</strong><?=adE($doc['estado']??'')?></div>
<?php if($esObra):?><div class="alerta"><strong>Numeración # reutilizable.</strong> Si esta es la última obra vigente de la serie, su número volverá a quedar disponible y será tomado por la próxima obra.</div><?php endif;?>
<?php if($tipo==='cotizacion' && (int)($doc['pedidos_activos']??0)>0):?><div class="error">Esta cotización tiene un pedido/obra vigente. Debe anular primero ese documento.</div><?php else:?><form method="post"><?=automacCsrfInput()?><input type="hidden" name="tipo" value="<?=adE($tipo)?>"><input type="hidden" name="id" value="<?=$id?>"><label for="motivo">Motivo de anulación *</label><textarea id="motivo" name="motivo" maxlength="500" required></textarea>
<?php if($tipo==='pedido' && ($doc['origen']??'')==='COTIZACION' && !empty($doc['cotizacion_numero'])):?><label class="check"><input type="checkbox" name="reabrir_cotizacion" value="1"><span>Reabrir la cotización <?=adE(numeroDocumentoVisible($doc['cotizacion_numero']))?> para poder corregirla y generar un nuevo pedido/obra.</span></label><?php endif;?>
<div class="acciones"><button class="btn rojo" type="submit" onclick="return confirm('¿Confirma la anulación de <?=adE(numeroDocumentoVisible($numero))?>?');">CONFIRMAR ANULACIÓN</button><a class="btn gris" href="<?=adE($volver)?>">CANCELAR</a></div></form><?php endif;?></div></div></body></html>
