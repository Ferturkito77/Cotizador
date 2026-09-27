<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
require_once 'documentos_modulares.php';
require_once 'integracion_externa.php';
require_once 'documentos_eventos.php';

function piE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function piCondiciones(mysqli $c): array{
    $a=array();
    $r=$c->query("SELECT condicion_pago_id,codigo_bejerman,descripcion FROM condiciones_pago WHERE activo=1 ORDER BY codigo_bejerman,descripcion");
    if($r){while($x=$r->fetch_assoc())$a[]=$x;}
    return $a;
}
function piCondicion(mysqli $c,int $id): ?array{
    if($id<=0)return null;
    $st=$c->prepare("SELECT condicion_pago_id,codigo_bejerman,descripcion FROM condiciones_pago WHERE condicion_pago_id=? AND activo=1 LIMIT 1");
    if(!$st)return null;$st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();return $x?:null;
}
function piClasePotencia($valor): string{
    $v=str_replace(',','.',trim((string)$valor));
    if($v===''||!is_numeric($v))return '';
    $hp=(float)$v;
    if($hp<=7)return 'CH';
    if($hp<=15)return 'ME';
    return 'GR';
}
function piTieneModulo(array $lineas,string $modulo): bool{
    $modulo=strtoupper($modulo);
    foreach($lineas as $l){if(strtoupper(trim((string)($l['modulo']??'')))===$modulo && (float)($l['cantidad']??0)>0)return true;}
    return false;
}
function piCliente(mysqli $c,int $id): array{
    $st=$c->prepare("SELECT clientes_id,clientes_codigo,clientes_nomfantasia,clientes_razonsocial FROM clientes WHERE clientes_id=? LIMIT 1");
    $st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();return $x?:array();
}
function piCotizacion(mysqli $c,int $id): ?array{
    $st=$c->prepare("SELECT c.*, (SELECT pedido_id FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id AND p.estado<>'ANULADO' ORDER BY p.pedido_id DESC LIMIT 1) pedido_id FROM cotizaciones c WHERE c.cotizacion_id=? LIMIT 1");
    $st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();return $x?:null;
}
function piLineasCotizacion(mysqli $c,int $id): array{
    $a=array();$st=$c->prepare("SELECT orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario AS unitario,formula_aplicada AS formula,importe_total AS total FROM cotizaciones_detalle WHERE cotizacion_id=? ORDER BY orden_visual");
    $st->bind_param('i',$id);$st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$a[]=$x;$st->close();return $a;
}
function piCamposIntegracion(mysqli $c,int $pid,string $fecha,string $clase,array $pago): array{
    $d=ieDatosPedido($c,$pid);$form=$d['form'];
    $det=array();$st=$c->prepare("SELECT modulo,cantidad FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual");$st->bind_param('i',$pid);$st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$det[]=$x;$st->close();
    $esObra=$d['tipo_pedido']==='OBRA';
    if($esObra){
        $sen=piTieneModulo($det,'SENALIZACION')?'O':'N';$iep=piTieneModulo($det,'IEP')?'O':'N';
        return array(
            'sigla'=>$d['sigla'],'cliente'=>$d['cliente'],'tipo'=>$d['tipo_control'],'maniobra'=>$d['maniobra'],
            'direccion'=>$d['referencia'],'ingreso'=>$d['fecha_ingreso'],'necesario'=>$fecha,'fecha_entrega'=>$fecha,
            'estado_control'=>'O','clase'=>$clase,'estado_senalizacion'=>$sen,'estado_iep'=>$iep,
            'notas'=>'','faltan_datos'=>'NO','forma_pago_codigo'=>$pago['codigo_bejerman'],'forma_pago_descripcion'=>$pago['descripcion']
        );
    }
    return array(
        'sigla_cliente'=>$d['sigla'],'cliente'=>$d['cliente'],'referencia'=>$d['referencia'],'equivalentes'=>'',
        'fecha_entrega'=>$fecha,'estado_senalizacion'=>'O','tipo_orden'=>'','faltan_datos'=>'NO','notas'=>'',
        'forma_pago_codigo'=>$pago['codigo_bejerman'],'forma_pago_descripcion'=>$pago['descripcion']
    );
}

$token=trim((string)($_GET['token']??$_POST['token']??''));
$cotId=(int)($_GET['cotizacion_id']??$_POST['cotizacion_id']??0);
$origen='';$cab=array();$lineas=array();$form=array();$cliente=array();$esObra=false;$documentoOrigen='';

if($token!==''){
    $draft=$_SESSION['pedido_preintegracion'][$token]??null;
    if(!is_array($draft))die('El pedido pendiente venció o ya fue procesado. Vuelva al cotizador y presione Pedido nuevamente.');
    if(time()-(int)($draft['creado']??0)>7200){unset($_SESSION['pedido_preintegracion'][$token]);die('El pedido pendiente venció. Vuelva al cotizador y presione Pedido nuevamente.');}
    $cab=$draft['cabecera']??array();$lineas=$draft['lineas']??array();$form=$cab['datos_formulario']??array();if(!is_array($form))$form=array();
    $cliente=piCliente($conexion,(int)($cab['cliente_id']??0));$esObra=piTieneModulo($lineas,'CONTROL');$origen='PEDIDO DIRECTO';
}else{
    if($cotId<=0)die('Origen de pedido inválido.');
    $cot=piCotizacion($conexion,$cotId);if(!$cot)die('La cotización no existe.');
    if(($cot['estado']??'')==='ANULADA')die('La cotización está anulada y no puede generar un pedido.');
    if(!empty($cot['pedido_id'])){header('Location:ver_pedido.php?id='.(int)$cot['pedido_id']);exit;}
    $lineas=piLineasCotizacion($conexion,$cotId);$form=json_decode((string)($cot['datos_formulario']??''),true);if(!is_array($form))$form=array();
    $cab=array('cliente_id'=>(int)$cot['cliente_id'],'lista_id'=>(int)$cot['lista_id'],'referencia'=>(string)$cot['referencia'],'total'=>(float)$cot['total'],'datos_formulario'=>$form);
    $cliente=piCliente($conexion,(int)$cot['cliente_id']);$esObra=(strtoupper((string)($cot['cotizacion_tipo']??''))==='CONTROL')||piTieneModulo($lineas,'CONTROL');$origen='COTIZACIÓN';$documentoOrigen=numeroDocumentoVisible((string)($cot['cotizacion_numero']??''));
}

$nombreCliente=trim((string)($cliente['clientes_nomfantasia']??''));if($nombreCliente==='')$nombreCliente=trim((string)($cliente['clientes_razonsocial']??''));
$referencia=trim((string)($cab['referencia']??''));
$solicitante=trim((string)($form['solicitante_cliente']??''));
$potencia=$form['potencia_hp']??'';$clase=$esObra?piClasePotencia($potencia):'';
$condiciones=piCondiciones($conexion);$error='';

if($_SERVER['REQUEST_METHOD']==='POST'){
    automacValidarCsrf(true);
    $referenciaEdit=trim((string)($_POST['referencia']??$referencia));
    $solicitanteEdit=trim((string)($_POST['solicitante_cliente']??$solicitante));
    if(function_exists('mb_substr')){ $referenciaEdit=mb_substr($referenciaEdit,0,150,'UTF-8'); $solicitanteEdit=mb_substr($solicitanteEdit,0,100,'UTF-8'); }
    else { $referenciaEdit=substr($referenciaEdit,0,150); $solicitanteEdit=substr($solicitanteEdit,0,100); }
    $fecha=trim((string)($_POST['fecha_entrega']??''));$pagoId=(int)($_POST['condicion_pago_id']??0);$pago=piCondicion($conexion,$pagoId);
    try{
        if($referenciaEdit==='')throw new RuntimeException('Complete la referencia antes de confirmar y generar el pedido.');
        if($fecha===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha))throw new RuntimeException('Indique la fecha de entrega.');
        if(!$pago)throw new RuntimeException('Seleccione la forma de pago.');
        $archivoCreado='';$pid=0;
        $conexion->begin_transaction();
        try{
            if($token!==''){
                $cabFinal=$cab;
                $cabFinal['referencia']=$referenciaEdit;
                $datos=$form;$datos['solicitante_cliente']=$solicitanteEdit;$datos['referencia_cotizacion']=$referenciaEdit;$datos['fecha_entrega']=$fecha;$datos['condicion_pago_id']=$pagoId;$datos['condicion_pago_codigo']=$pago['codigo_bejerman'];$datos['condicion_pago_descripcion']=$pago['descripcion'];
                $cabFinal['datos_formulario']=$datos;
                $pid=crearPedidoDirectoModular($conexion,$cabFinal,$lineas);
                $stEv=$conexion->prepare('SELECT pedido_numero,pedido_tipo FROM pedidos WHERE pedido_id=? LIMIT 1');if($stEv){$stEv->bind_param('i',$pid);$stEv->execute();$ev=$stEv->get_result()->fetch_assoc();$stEv->close();if($ev)documentoEventoRegistrar($conexion,($ev['pedido_tipo']??'')==='OBRA'?'OBRA':'PEDIDO',$pid,(string)($ev['pedido_numero']??''),'CREACION_DIRECTA','','BORRADOR','Pedido directo confirmado en Integración externa.');}
            }else{
                $cot=piCotizacion($conexion,$cotId);if(!$cot)throw new RuntimeException('La cotización no existe.');if(($cot['estado']??'')==='ANULADA')throw new RuntimeException('La cotización está anulada.');if(!empty($cot['pedido_id']))throw new RuntimeException('La cotización ya fue convertida en pedido.');
                $usuario=$_SESSION['usuario_nombre']??null;$uid=(int)($_SESSION['usuario_id']??0);$tipoPedido=$esObra?'OBRA':'SUMINISTROS';
                $datos=$form;$datos['solicitante_cliente']=$solicitanteEdit;$datos['referencia_cotizacion']=$referenciaEdit;$datos['fecha_entrega']=$fecha;$datos['condicion_pago_id']=$pagoId;$datos['condicion_pago_codigo']=$pago['codigo_bejerman'];$datos['condicion_pago_descripcion']=$pago['descripcion'];$json=json_encode($datos,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
                // La Integración externa funciona como última validación administrativa:
                // lo corregido aquí queda también en la cotización de origen.
                $st=$conexion->prepare("UPDATE cotizaciones SET referencia=?, datos_formulario=? WHERE cotizacion_id=?");
                if(!$st)throw new RuntimeException($conexion->error);$st->bind_param('ssi',$referenciaEdit,$json,$cotId);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
                $st=$conexion->prepare("INSERT INTO pedidos(pedido_tipo,cotizacion_id,cliente_id,lista_id,total,datos_formulario,usuario,usuario_id,referencia) VALUES(?,?,?,?,?,?,?,?,?)");
                $ref=$referenciaEdit;$total=(float)$cot['total'];$st->bind_param('siiidssis',$tipoPedido,$cotId,$cot['cliente_id'],$cot['lista_id'],$total,$json,$usuario,$uid,$ref);if(!$st->execute())throw new RuntimeException($st->error);$pid=$st->insert_id;$st->close();
                $num=siguienteNumeroDocumento($conexion,$esObra?'PEDIDO_OBRA':'PEDIDO_SUMINISTROS');$st=$conexion->prepare('UPDATE pedidos SET pedido_numero=? WHERE pedido_id=?');$st->bind_param('si',$num,$pid);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
                $st=$conexion->prepare("INSERT INTO pedidos_detalle(pedido_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) SELECT ?,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM cotizaciones_detalle WHERE cotizacion_id=?");$st->bind_param('ii',$pid,$cotId);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
                $st=$conexion->prepare("UPDATE cotizaciones SET estado='ACEPTADA' WHERE cotizacion_id=?");if(!$st)throw new RuntimeException($conexion->error);$st->bind_param('i',$cotId);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
                documentoEventoRegistrar($conexion,'COTIZACION',$cotId,(string)($cot['cotizacion_numero']??''),'ACEPTACION',(string)($cot['estado']??''),'ACEPTADA','Convertida a '.($esObra?'obra':'pedido').'.');
                documentoEventoRegistrar($conexion,$esObra?'OBRA':'PEDIDO',$pid,$num,'CREACION_DESDE_COTIZACION','','BORRADOR','Origen '.(string)($cot['cotizacion_numero']??'').'.');
            }
            $datosPedidoFinal=$datos??$form;
            $datosPedidoFinal['solicitante_cliente']=$solicitanteEdit;$datosPedidoFinal['referencia_cotizacion']=$referenciaEdit;
            $jsonPedidoFinal=json_encode($datosPedidoFinal,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
            $st=$conexion->prepare("UPDATE pedidos SET referencia=?,datos_formulario=?,fecha_entrega=?,condicion_pago_id=?,condicion_pago_codigo=?,condicion_pago_descripcion=? WHERE pedido_id=?");
            $codigo=(string)$pago['codigo_bejerman'];$desc=(string)$pago['descripcion'];$st->bind_param('sssissi',$referenciaEdit,$jsonPedidoFinal,$fecha,$pagoId,$codigo,$desc,$pid);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
            $campos=piCamposIntegracion($conexion,$pid,$fecha,$clase,$pago);
            $res=ieGuardarHtml($conexion,$pid,$campos);$archivoCreado=(string)($res['destino']??'');$archivo=(string)($res['archivo']??'');
            $st=$conexion->prepare("UPDATE pedidos SET archivo_integracion=? WHERE pedido_id=?");$st->bind_param('si',$archivo,$pid);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
            $conexion->commit();
            if($token!=='')unset($_SESSION['pedido_preintegracion'][$token]);
            try{generarPdfPedido($conexion,$pid);}catch(Throwable $pdfError){error_log('PDF pedido v410: '.$pdfError->getMessage());}
            header('Location:ver_pedido.php?id='.(int)$pid.'&integracion=1');exit;
        }catch(Throwable $e){$conexion->rollback();if($archivoCreado!==''&&is_file($archivoCreado))@unlink($archivoCreado);throw $e;}
    }catch(Throwable $e){$error=$e->getMessage();}
}
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Integración externa antes del Pedido</title><link rel="stylesheet" href="automac-ui.css"><style>
.pi-wrap{max-width:1050px;margin:22px auto;padding:0 18px 60px}.pi-hero,.pi-card{background:#fff;border:1px solid #dce5ee;border-radius:14px;padding:18px;margin-bottom:14px}.pi-hero{display:flex;justify-content:space-between;gap:18px;align-items:center}.pi-hero h1{margin:0 0 5px;font-size:24px}.pi-hero p{margin:0;color:#64748b}.pi-tag{background:#e8f1ff;color:#16457c;border:1px solid #bfd3ee;border-radius:999px;padding:7px 12px;font-weight:900}.pi-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.pi-field{display:flex;flex-direction:column;gap:5px}.pi-field.full{grid-column:1/-1}.pi-field label{font-size:12px;font-weight:900;color:#475569;text-transform:uppercase}.pi-field input,.pi-field select{height:43px;border:1px solid #cbd6e2;border-radius:8px;padding:8px 10px;background:#fff;font:inherit}.pi-field input[readonly]{background:#f7f9fb}.pi-note{background:#eef7ff;border:1px solid #cfe5f7;color:#295272;border-radius:9px;padding:11px 13px;margin-bottom:14px}.pi-error{background:#fdecec;border:1px solid #efb7b7;color:#8a2222;border-radius:9px;padding:11px 13px;margin-bottom:14px}.pi-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}.pi-btn{border:0;border-radius:8px;padding:11px 16px;text-decoration:none;font-weight:900;cursor:pointer;background:#334155;color:#fff}.pi-btn.primary{background:#198754}.pi-kpi{font-size:13px;color:#64748b;margin-top:5px}.pi-kpi strong{color:#1e293b}@media(max-width:760px){.pi-grid{grid-template-columns:1fr}.pi-field.full{grid-column:auto}.pi-hero{display:block}.pi-tag{display:inline-block;margin-top:10px}}
</style></head><body><?php require 'menu.php';?><main class="pi-wrap"><section class="pi-hero"><div><h1>Integración externa</h1><p>Revisá y corregí los datos administrativos antes de generar el Pedido.</p></div><div class="pi-tag"><?=piE($esObra?'OBRA':'PEDIDO / SUMINISTROS')?></div></section>
<?php if($error!==''):?><div class="pi-error"><?=piE($error)?></div><?php endif;?>
<section class="pi-card"><form method="post"><?=automacCsrfInput()?><?php if($token!==''):?><input type="hidden" name="token" value="<?=piE($token)?>"><?php else:?><input type="hidden" name="cotizacion_id" value="<?=(int)$cotId?>"><?php endif;?><div class="pi-grid"><div class="pi-field"><label>Origen</label><input readonly value="<?=piE($origen.($documentoOrigen!==''?' '.$documentoOrigen:''))?>"></div><div class="pi-field"><label>Cliente</label><input readonly value="<?=piE($nombreCliente)?>"></div><div class="pi-field full"><label>Obra / Referencia *</label><input type="text" name="referencia" maxlength="150" required value="<?=piE($_POST['referencia']??$referencia)?>" placeholder="Ej.: Colombres 360 / Ascensor 1"></div><div class="pi-field full"><label>Solicitante / contacto</label><input type="text" name="solicitante_cliente" maxlength="100" value="<?=piE($_POST['solicitante_cliente']??$solicitante)?>" placeholder="Nombre de la persona que solicita"></div><?php if($esObra):?><div class="pi-field full"><label>Clase</label><input readonly value="<?=piE($clase!==''?$clase:'A confirmar')?>"></div><?php endif;?><div class="pi-field"><label>Fecha de entrega *</label><input type="date" name="fecha_entrega" required value="<?=piE($_POST['fecha_entrega']??'')?>"></div><div class="pi-field"><label>Forma de pago *</label><select name="condicion_pago_id" required><option value="">Seleccione...</option><?php foreach($condiciones as $cp):?><option value="<?=(int)$cp['condicion_pago_id']?>"<?=((int)($_POST['condicion_pago_id']??0)===(int)$cp['condicion_pago_id'])?' selected':''?>><?=piE($cp['codigo_bejerman'].' - '.$cp['descripcion'])?></option><?php endforeach;?></select></div></div><div class="pi-actions"><button class="pi-btn primary" type="submit">CONFIRMAR Y GENERAR PEDIDO</button><a class="pi-btn" href="<?=$token!==''?'index.php':'ver_cotizacion.php?id='.(int)$cotId?>">CANCELAR</a></div></form></section></main></body></html>
