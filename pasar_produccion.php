<?php
session_start();
require_once 'conexion.php';require_once 'auth.php';asegurarSistemaUsuarios($conexion);exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';asegurarSistemaComercial($conexion);require_once 'integracion_externa.php';require_once 'documentos_eventos.php';
if(strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'))==='POST')automacValidarCsrf(true);
function ppE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ppCondicionesPago(mysqli $c): array{
  $a=array();
  $r=$c->query("SELECT condicion_pago_id,codigo_bejerman,descripcion FROM condiciones_pago WHERE activo=1 ORDER BY codigo_bejerman,descripcion");
  if($r){while($x=$r->fetch_assoc())$a[]=$x;}
  return $a;
}
function ppCondicionPagoActiva(mysqli $c,int $id): ?array{
  if($id<=0)return null;
  $st=$c->prepare('SELECT condicion_pago_id,codigo_bejerman,descripcion FROM condiciones_pago WHERE condicion_pago_id=? AND activo=1 LIMIT 1');
  if(!$st)return null;
  $st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();
  return $x?:null;
}
function ppVerdadero($v): bool{return in_array(strtoupper(trim((string)$v)),array('1','SI','S','TRUE','ON','YES'),true);}
function ppArray($v): array{if(is_array($v))return $v;if(!is_string($v)||trim($v)==='')return array();$j=json_decode($v,true);return is_array($j)?$j:array();}
function ppDetalles(mysqli $c,int $id): array{$a=array();$st=$c->prepare('SELECT modulo,concepto,codigo,descripcion,cantidad FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual');$st->bind_param('i',$id);$st->execute();$r=$st->get_result();while($x=$r->fetch_assoc())$a[]=$x;$st->close();return $a;}
function ppModuloIncluido(array $detalles,string $modulo): bool{foreach($detalles as $x)if(strtoupper(trim((string)($x['modulo']??'')))===$modulo && (float)($x['cantidad']??0)>0)return true;return false;}
function ppNombreTabla(mysqli $c,string $tabla,string $idCampo,string $nombreCampo,$id): string{
  $permitidas=array(
    'cpus'=>array('cpu_id','cpu_name'),'tipos_control'=>array('ctrltipo_id','ctrltipo_name'),
    'subtipos_control'=>array('ctrlsubtipo_id','ctrlsubtipo_name'),'maniobras'=>array('maniobra_id','maniobra_name'),
    'ptacabina'=>array('ptacabina_id','ptacabina_name'),'ptacabina_mc'=>array('ptacabinamc_id','ptacabinamc_name'),
    'senal_modelos_pulsador'=>array('modelo_pulsador_id','modelo_pulsador_nombre'),
    'senal_colores_registro'=>array('color_registro_id','color_registro_nombre')
  );
  $id=(int)$id;if($id<=0||!isset($permitidas[$tabla]))return '';
  if($permitidas[$tabla][0]!==$idCampo||$permitidas[$tabla][1]!==$nombreCampo)return '';
  $st=$c->prepare("SELECT `$nombreCampo` v FROM `$tabla` WHERE `$idCampo`=? LIMIT 1");if(!$st)return '';
  $st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();return $x?trim((string)$x['v']):'';
}
function ppNormalizarEspacios(string $v): string{return trim((string)preg_replace('/\s+/u',' ',$v));}
function ppClasePotencia($valor): string{$v=str_replace(',','.',trim((string)$valor));if($v===''||!is_numeric($v))return ''; $hp=(float)$v; if($hp<=7)return 'CH'; if($hp<=15)return 'ME'; return 'GR';}
function ppTokenPuerta(string $nombre): string{
  $u=strtoupper($nombre);
  if($u==='')return '';
  if(strpos($u,'VF')!==false||strpos($u,'ELECTR')!==false)return 'PAVF';
  if(strpos($u,'3X380')!==false||strpos($u,'PATIN EL')!==false)return 'PAT';
  if(strpos($u,'MANUAL')!==false)return 'PMDC';
  if(strpos($u,'CC ')!==false)return 'PACC';
  return '';
}
function ppTokenHidraulico(string $subtipo): string{
  $u=strtoupper(ppNormalizarEspacios($subtipo));
  if($u==='DIRECTO')return 'HD';
  if(strpos($u,'ARRANQUE SUAVE')!==false)return 'HE';
  if(strpos($u,'EST.TRI')!==false||strpos($u,'EST TRI')!==false||strpos($u,'ESTRELLA')!==false)return 'HET';
  return 'HD';
}
function ppCorrienteMatriz(mysqli $c,array $f): string{
  $cpu=(int)($f['id_cpu']??0);$tipo=(int)($f['id_tipo_control']??0);$sub=(int)($f['id_subtipo']??0);$ten=(int)($f['id_tension']??0);
  $pot=is_numeric($f['potencia_hp']??null)?(float)$f['potencia_hp']:0.0;$enc=trim((string)($f['encoder']??''));if(!$cpu||!$tipo||!$sub||!$ten)return '';
  $base=$cpu;$st=$c->prepare('SELECT COALESCE(NULLIF(cpu_matriz_base_id,0),cpu_id) b FROM cpus WHERE cpu_id=? LIMIT 1');
  if($st){$st->bind_param('i',$cpu);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();if($x&&(int)$x['b']>0)$base=(int)$x['b'];}
  $sql="SELECT control_corriente FROM matriz_calculos WHERE control_cpu=? AND control_tipo=? AND control_subtipo=? AND control_tension=? AND COALESCE(NULLIF(TRIM(control_encoder),''),'')=? AND ?>control_potenciadesde AND ?<=control_potenciahasta ORDER BY control_potenciadesde DESC LIMIT 1";
  $st=$c->prepare($sql);if(!$st)return '';$st->bind_param('iiiisdd',$base,$tipo,$sub,$ten,$enc,$pot,$pot);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();
  if(!$x)return ''; $v=trim((string)($x['control_corriente']??''));if($v==='')return '';if(is_numeric($v))$v=rtrim(rtrim(number_format((float)$v,2,'.',''),'0'),'.');return str_replace('.',',',$v);
}
function ppDescripcionSenalizacion(mysqli $c,array $f,array $detalles): string{
  $incl=ppVerdadero($f['senal_incluir']??'')||ppModuloIncluido($detalles,'SENALIZACION');if(!$incl)return '';
  $modelo=ppNombreTabla($c,'senal_modelos_pulsador','modelo_pulsador_id','modelo_pulsador_nombre',$f['senal_modelo']??0);
  $color=ppNombreTabla($c,'senal_colores_registro','color_registro_id','color_registro_nombre',$f['senal_color']??0);
  $m=strtoupper($modelo);$colorTitulo=$color!==''?mb_convert_case(mb_strtolower($color,'UTF-8'),MB_CASE_TITLE,'UTF-8'):'';
  $suf=array('ROJO'=>'R','AZUL'=>'Z','BLANCO'=>'B');
  if(preg_match('/^A31(50|60|70|80)$/',$m)){
    $forma=array('A3150'=>'Cuad','A3160'=>'Rond','A3170'=>'Oval','A3180'=>'Recta')[$m]??'';
    $cod=$modelo.($suf[strtoupper($color)]??'');return ppNormalizarEspacios($cod.' '.$forma.' '.$colorTitulo);
  }
  if($m==='A3900')return ppNormalizarEspacios('A3900 Antivandálico '.$colorTitulo);
  if($m==='ROND METAL')return 'Rond Métal';
  if($m==='ONIX TELEFONICO')return ppNormalizarEspacios('Onix Telefonico '.$colorTitulo);
  if($m==='ONIX INDIVIDUALES')return ppNormalizarEspacios('Onix Puls. Individuales '.$colorTitulo);
  if($m==='PANTALLA 21”'||$m==='PANTALLA 21"')return ppNormalizarEspacios('Pantalla touch 21 pulg. '.$colorTitulo);
  if($m==='DANGELICA')return ppNormalizarEspacios('Dangelica '.$colorTitulo);
  if($modelo!=='')return ppNormalizarEspacios($modelo.' '.$colorTitulo);
  foreach($detalles as $x)if(strtoupper(trim((string)($x['modulo']??'')))==='SENALIZACION'&&trim((string)($x['descripcion']??''))!=='')return trim((string)$x['descripcion']);
  return 'A CONFIRMAR';
}
function ppDatosProduccionObra(mysqli $c,array $f,array $detalles): array{
  $cpu=ppNombreTabla($c,'cpus','cpu_id','cpu_name',$f['id_cpu']??0);
  $tipo=ppNombreTabla($c,'tipos_control','ctrltipo_id','ctrltipo_name',$f['id_tipo_control']??0);
  $sub=ppNombreTabla($c,'subtipos_control','ctrlsubtipo_id','ctrlsubtipo_name',$f['id_subtipo']??0);
  $man=ppNombreTabla($c,'maniobras','maniobra_id','maniobra_name',$f['id_maniobra']??0);
  $cant=max(1,(int)($f['cantidad_total_coches_bateria']??($f['cantidad_equipos']??1)));
  $pars=array_values(array_filter(array_map('intval',(array)($f['paradas_equipo']??array())), function($v){ return $v>0; }));
  $par='';if($pars){$u=array_values(array_unique($pars));$par=count($u)===1?(string)$u[0]:implode('/',$pars);}
  $corr=ppCorrienteMatriz($c,$f);
  $puerta=ppNombreTabla($c,'ptacabina','ptacabina_id','ptacabina_name',$f['id_ptacabina']??0);if($puerta==='')$puerta=ppNombreTabla($c,'ptacabina_mc','ptacabinamc_id','ptacabinamc_name',$f['id_ptacabina_mc']??0);
  $tokenPuerta=ppTokenPuerta($puerta);$tu=strtoupper($tipo);$accion='';
  if($tu==='HIDRAULICO'||$tu==='HIDRÁULICO')$accion=ppTokenHidraulico($sub);
  elseif($tu==='1V'||$tu==='2V')$accion=$tu;
  elseif($tu==='VF')$accion=$sub!==''?$sub:'VF';
  elseif(strpos($tu,'IMAN')!==false||strpos($tu,'IMÁN')!==false)$accion=ppNormalizarEspacios('I.Permanente '.$sub);
  elseif($tu==='ROOMLESS'||$tu==='MRL')$accion=ppNormalizarEspacios('MRL '.$sub);
  else $accion=ppNormalizarEspacios($tipo.' '.$sub);
  $bloqueFinal='';if($corr!==''||$tokenPuerta!=='')$bloqueFinal='/'.$corr.$tokenPuerta;
  $partes=array_filter(array($man,$cant.'C',$par!==''?$par.'P':'',$accion));$texto=ppNormalizarEspacios(implode(' ',$partes).$bloqueFinal);
  $sl=ppDescripcionSenalizacion($c,$f,$detalles);if($sl!=='')$texto=rtrim($texto,'.').'. SL: '.$sl;
  return array('tipo'=>$cpu,'subtipo'=>$tipo,'subtipo_control'=>$sub,'maniobra'=>$texto,'senalizacion'=>$sl,'corriente'=>$corr,'puerta_token'=>$tokenPuerta);
}
function ppEstados(array $form,array $detalles,bool $esObra): array{
  if(!$esObra)return array('control'=>'','senalizacion'=>'O','iep'=>'','fd_motivos'=>array());
  $senal=ppVerdadero($form['senal_incluir']??'')||ppModuloIncluido($detalles,'SENALIZACION');
  $iep=ppVerdadero($form['iep_incluir']??($form['incluir_iep']??''))||ppModuloIncluido($detalles,'IEP');
  $motivos=array();
  if($senal){
    $cabina=array_key_exists('senal_incluir_botonera_cabina',$form)?ppVerdadero($form['senal_incluir_botonera_cabina']):true;
    if($cabina){$cant=max(1,(int)($form['senal_cantidad']??1));$med=$form['senal_medidas_equipo']??array();if(!is_array($med))$med=array($med);if(!$med && isset($form['senal_medidas']))$med=array($form['senal_medidas']);for($i=0;$i<$cant;$i++)if(trim((string)($med[$i]??''))===''){$motivos[]='medida de botonera de cabina';break;}}
    $puls=ppArray($form['senal_pulsadores_items_json']??'');$hayPuls=false;foreach($puls as $it){if((float)($it['cantidad']??0)>0){$hayPuls=true;if(trim((string)($it['medida']??''))===''){$motivos[]='medida de pulsadores exteriores';break;}}}
    if(!$hayPuls){$tipo=trim((string)($form['senal_pulsador_exterior_tipo']??($form['senal_elemento_tipo']??'')));if($tipo!=='' && stripos($tipo,'PULSADOR')!==false && trim((string)($form['senal_pulsador_exterior_medidas']??($form['senal_elemento_medidas']??'')))==='')$motivos[]='medida de pulsadores exteriores';}
    $inds=ppArray($form['senal_indicadores_exteriores_items_json']??'');$hayInd=false;foreach($inds as $it){if((float)($it['cantidad']??0)>0){$hayInd=true;if(trim((string)($it['medida']??''))===''){$motivos[]='medida de indicadores';break;}}}
    if(!$hayInd){$modelo=trim((string)($form['senal_indicador_exterior_modelo']??''));$tipo=trim((string)($form['senal_elemento_tipo']??''));if(($modelo!==''||stripos($tipo,'INDICADOR')!==false) && trim((string)($form['senal_indicador_exterior_medidas']??($form['senal_elemento_medidas']??'')))==='')$motivos[]='medida de indicadores';}
  }
  return array('control'=>'O','senalizacion'=>$senal?($motivos?'FD':'O'):'N','iep'=>$iep?'O':'N','fd_motivos'=>array_values(array_unique($motivos)));
}
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)$id=filter_input(INPUT_POST,'pedido_id',FILTER_VALIDATE_INT);if(!$id)die('Pedido inválido.');
try{$d=ieDatosPedido($conexion,(int)$id);}catch(Throwable $e){die(ppE($e->getMessage()));}
$p=$d['pedido'];if(($p['estado']??'')==='ANULADO')die('El pedido/obra está anulado y no puede pasar a Producción.');$form=$d['form'];$detalles=ppDetalles($conexion,(int)$id);$esObra=$d['tipo_pedido']==='OBRA';$est=ppEstados($form,$detalles,$esObra);$prod=$esObra?ppDatosProduccionObra($conexion,$form,$detalles):array();
$mensaje='';$tipoMsg='';$resultado=null;
$fecha=(string)($p['fecha_entrega']??'');
$condicionesPago=ppCondicionesPago($conexion);
$condicionPagoId=(int)($p['condicion_pago_id']??0);
$defaults=$esObra?array('sigla'=>$d['sigla'],'cliente'=>$d['cliente'],'tipo'=>(string)($prod['tipo']??$d['tipo_control']),'maniobra'=>(string)($prod['maniobra']??$d['maniobra']),'direccion'=>$d['referencia'],'ingreso'=>$d['fecha_ingreso'],'necesario'=>$fecha,'estado_control'=>$est['control'],'clase'=>ppClasePotencia($form['potencia_hp']??''),'estado_senalizacion'=>$est['senalizacion'],'estado_iep'=>$est['iep'],'notas'=>(string)($p['observaciones']??''),'faltan_datos'=>$est['senalizacion']==='FD'?'SI':'NO'):array('sigla_cliente'=>$d['sigla'],'cliente'=>$d['cliente'],'referencia'=>$d['referencia'],'equivalentes'=>'','estado_senalizacion'=>'O','tipo_orden'=>'','faltan_datos'=>'NO','notas'=>(string)($p['observaciones']??''));
$campos=$defaults;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $fecha=trim((string)($_POST['fecha_entrega']??''));
  $condicionPagoId=(int)($_POST['condicion_pago_id']??0);
  foreach(array_keys($campos) as $k){if(in_array($k,array('estado_control','estado_senalizacion','estado_iep'),true))continue;if($k==='faltan_datos')$campos[$k]=!empty($_POST['faltan_datos'])?'SI':'NO';else $campos[$k]=trim((string)($_POST[$k]??$campos[$k]));}
  $campos['estado_senalizacion']=$est['senalizacion'];if($esObra){$campos['estado_control']='O';$campos['estado_iep']=$est['iep'];$campos['necesario']=$fecha;}else{$campos['estado_senalizacion']='O';}
  try{
    if($fecha===''||!preg_match('/^\d{4}-\d{2}-\d{2}$/',$fecha))throw new RuntimeException('La fecha de entrega es obligatoria.');
    $condicionPago=ppCondicionPagoActiva($conexion,$condicionPagoId);
    if(!$condicionPago)throw new RuntimeException('La condición de pago es obligatoria. Seleccione una condición activa de Mantenimiento.');
    $codigoPago=trim((string)$condicionPago['codigo_bejerman']);
    $descripcionPago=trim((string)$condicionPago['descripcion']);
    $campos['fecha_entrega']=$fecha;
    $campos['condicion_pago_codigo']=$codigoPago;
    $campos['condicion_pago_descripcion']=$descripcionPago;
    $resultado=ieGuardarHtml($conexion,(int)$id,$campos);
    $archivo=(string)$resultado['archivo'];$ec=$esObra?'O':'';$es=$esObra?$est['senalizacion']:'O';$ei=$esObra?$est['iep']:'';
    $st=$conexion->prepare("UPDATE pedidos SET fecha_entrega=?,condicion_pago_id=?,condicion_pago_codigo=?,condicion_pago_descripcion=?,estado_control_produccion=?,estado_senalizacion_produccion=?,estado_iep_produccion=?,fecha_paso_produccion=COALESCE(fecha_paso_produccion,NOW()),archivo_integracion=?,estado='EN_PRODUCCION' WHERE pedido_id=?");
    $st->bind_param('sissssssi',$fecha,$condicionPagoId,$codigoPago,$descripcionPago,$ec,$es,$ei,$archivo,$id);if(!$st->execute())throw new RuntimeException($st->error);$st->close();
    documentoEventoRegistrar($conexion,$esObra?'OBRA':'PEDIDO',(int)$id,(string)$d['documento'],'PASO_PRODUCCION',(string)($p['estado']??''),'EN_PRODUCCION','Archivo: '.$archivo);
    $mensaje='Pedido pasado a Producción y archivo HTML generado correctamente.';$tipoMsg='ok';
  }catch(Throwable $e){$mensaje=$e->getMessage();$tipoMsg='error';}
}
$cfg=ieConfig($conexion);$nombrePrev=ieNombreArchivo($cfg,$d['tipo_pedido'],$d['documento'],$d['cliente'],$d['sigla']);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Paso a Producción <?=ppE(numeroDocumentoVisible($d['documento']))?></title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1050px;margin:20px auto;padding:0 18px 60px}.hero,.card{background:#fff;border:1px solid #dce4ec;border-radius:14px;padding:18px;margin-bottom:14px}.hero{display:flex;justify-content:space-between;gap:18px}.hero h1{margin:0 0 5px}.hero p{margin:0;color:#64748b}.doc{font-size:24px;font-weight:900;color:#17324d}.grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.campo{display:flex;flex-direction:column;gap:5px}.campo.full{grid-column:1/-1}.campo label{font-size:12px;font-weight:900;color:#475569;text-transform:uppercase}.campo input,.campo textarea,.campo select{width:100%;border:1px solid #cad5e1;border-radius:8px;padding:9px 10px;font:inherit;background:#fff}.campo input,.campo select{height:42px}.campo textarea{min-height:90px}.bloque{border:1px solid #d9e2ec;border-radius:11px;padding:14px;margin:12px 0}.bloque h2{font-size:16px;margin:0 0 12px}.estado{font-weight:900;font-size:18px}.o{color:#087f4b}.fd{color:#b45309}.n{color:#64748b}.actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;text-decoration:none;font-weight:800;cursor:pointer;background:#334155;color:#fff}.btn.primary{background:#0d6efd}.msg{padding:11px 13px;border-radius:9px;margin-bottom:13px}.ok{background:#e7f7ef;color:#17603a}.error{background:#fdecec;color:#8f2020}.ruta{font-family:Consolas,monospace;font-size:12px;background:#f6f8fa;border:1px solid #e2e8f0;border-radius:8px;padding:9px;word-break:break-all}.alerta{background:#fff7df;border:1px solid #ead8a5;color:#6d5415;border-radius:9px;padding:10px;margin-top:8px}@media(max-width:700px){.grid{grid-template-columns:1fr}.campo.full{grid-column:auto}.hero{display:block}}</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><div><h1>Confirmar paso a Producción</h1><p>La fecha de entrega y la condición de pago son obligatorias. Los estados se calculan automáticamente.</p></div><div class="doc"><?=ppE(numeroDocumentoVisible($d['documento']))?></div></section><?php if($mensaje):?><div class="msg <?=ppE($tipoMsg)?>"><?=ppE($mensaje)?><?php if($resultado):?><div class="ruta" style="margin-top:8px"><?=ppE($resultado['destino'])?></div><?php endif;?></div><?php endif;?><section class="card"><form method="post"><?=automacCsrfInput()?><input type="hidden" name="pedido_id" value="<?=(int)$id?>"><div class="grid"><div class="campo"><label>Fecha de entrega *</label><input type="date" name="fecha_entrega" value="<?=ppE($fecha)?>" required></div><div class="campo"><label>Condición de pago *</label><select name="condicion_pago_id" required><option value="">Seleccione condición de pago...</option><?php foreach($condicionesPago as $cp):?><option value="<?=(int)$cp['condicion_pago_id']?>"<?=$condicionPagoId===(int)$cp['condicion_pago_id']?' selected':''?>><?=ppE($cp['codigo_bejerman'].' - '.$cp['descripcion'])?></option><?php endforeach;?></select><small>La lista se administra desde Mantenimiento → Condiciones de pago.</small></div></div>
<?php if($esObra):?><div class="grid" style="margin-top:14px"><div class="campo"><label>Sigla</label><input name="sigla" value="<?=ppE($campos['sigla'])?>"></div><div class="campo"><label>Cliente</label><input name="cliente" value="<?=ppE($campos['cliente'])?>"></div></div><div class="bloque"><h2>Control</h2><div class="grid"><div class="campo"><label>Tipo</label><input name="tipo" value="<?=ppE($campos['tipo'])?>"></div><div class="campo full"><label>Maniobra</label><input name="maniobra" value="<?=ppE($campos['maniobra'])?>"><small>Generada automáticamente con maniobra, coches, paradas, accionamiento/subtipo técnico, corriente, puertas y señalización. Puede corregirse antes de confirmar.</small></div><div class="campo full"><label>Dirección / referencia</label><input name="direccion" value="<?=ppE($campos['direccion'])?>"></div><div class="campo"><label>Ingreso</label><input type="date" name="ingreso" value="<?=ppE($campos['ingreso'])?>"></div><div class="campo"><label>Estado Control</label><div class="estado o">O</div></div><div class="campo"><label>Clase</label><input name="clase" value="<?=ppE($campos['clase'])?>" readonly><small>Automática por potencia: ≤7 HP CH · >7 a 15 HP ME · >15 HP GR.</small></div></div></div><div class="bloque"><h2>Señalización</h2><div class="estado <?=strtolower(ppE($est['senalizacion']))?>"><?=ppE($est['senalizacion'])?></div><?php if($est['fd_motivos']):?><div class="alerta"><strong>FD:</strong> falta confirmar <?=ppE(implode(', ',$est['fd_motivos']))?>.</div><?php endif;?></div><div class="bloque"><h2>IEP</h2><div class="estado <?=strtolower(ppE($est['iep']))?>"><?=ppE($est['iep'])?></div></div><div class="campo full"><label>Notas</label><textarea name="notas"><?=ppE($campos['notas'])?></textarea></div><label><input type="checkbox" name="faltan_datos" value="1"<?=$campos['faltan_datos']==='SI'?' checked':''?>> Faltan datos</label>
<?php else:?><div class="grid" style="margin-top:14px"><div class="campo"><label>Sigla Cliente</label><input name="sigla_cliente" value="<?=ppE($campos['sigla_cliente'])?>"></div><div class="campo"><label>Cliente</label><input name="cliente" value="<?=ppE($campos['cliente'])?>"></div><div class="campo full"><label>Referencia</label><input name="referencia" value="<?=ppE($campos['referencia'])?>"></div><div class="campo full"><label>Equivalentes</label><input name="equivalentes" value="<?=ppE($campos['equivalentes'])?>"></div><div class="campo"><label>Estado</label><div class="estado o">O</div></div><div class="campo"><label>Tipo de orden</label><input name="tipo_orden" value="<?=ppE($campos['tipo_orden'])?>"></div><div class="campo full"><label>Notas</label><textarea name="notas"><?=ppE($campos['notas'])?></textarea></div></div><label><input type="checkbox" name="faltan_datos" value="1"<?=$campos['faltan_datos']==='SI'?' checked':''?>> Faltan datos</label><?php endif;?>
<div class="actions"><button class="btn primary" type="submit">CONFIRMAR Y PASAR A PRODUCCIÓN</button><a class="btn" href="ver_pedido.php?id=<?=(int)$id?>">VOLVER AL PEDIDO</a></div></form></section><section class="card"><strong>Archivo previsto</strong><div class="ruta"><?=ppE($nombrePrev)?></div><p style="color:#64748b">Carpeta configurada: <?=ppE($cfg['ruta']?:'NO CONFIGURADA')?></p></section></main></body></html>
