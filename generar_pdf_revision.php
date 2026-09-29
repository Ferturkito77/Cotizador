<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);
require_once 'documentos_pdf.php';
require_once 'documentos_storage.php';

$tipo = strtolower(trim((string)($_GET['tipo'] ?? '')));
$documentoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$revision = filter_input(INPUT_GET, 'revision', FILTER_VALIDATE_INT);
if (!in_array($tipo, array('cotizacion','pedido'), true) || !$documentoId || $revision === false || $revision === null) {
    http_response_code(400);
    die('Solicitud de revisión inválida.');
}

function revPdfValor(array $a, string $k, $default='') { return array_key_exists($k,$a) ? $a[$k] : $default; }
function revPdfCliente(array $r): string {
    $codigo=trim((string)revPdfValor($r,'clientes_codigo',''));
    $nombre=trim((string)(revPdfValor($r,'clientes_nomfantasia','') ?: revPdfValor($r,'clientes_razonsocial','')));
    return trim($codigo . ($codigo!=='' && $nombre!=='' ? ' - ' : '') . $nombre) ?: '-';
}

if ($tipo === 'cotizacion') {
    $sql = "SELECT r.*, c.cotizacion_numero AS numero_documento, c.cotizacion_tipo AS tipo_documento,
                   cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,
                   COALESCE(u.usuario_nombre,r.usuario,'') AS responsable
            FROM cotizaciones_revisiones r
            JOIN cotizaciones c ON c.cotizacion_id=r.cotizacion_id
            LEFT JOIN clientes cl ON cl.clientes_id=r.cliente_id
            LEFT JOIN usuarios u ON u.usuario_id=r.usuario_id
            WHERE r.cotizacion_id=? AND r.revision=? LIMIT 1";
    $tablaDetalle='cotizaciones_revisiones_detalle';
    $titulo='COTIZACION HISTORICA';
    $carpeta='cotizaciones';
} else {
    $sql = "SELECT r.*, p.pedido_numero AS numero_documento, p.pedido_tipo AS tipo_documento,
                   COALESCE(r.referencia,p.referencia,'') AS referencia,
                   cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,
                   l.lista_nombre,l.lista_fecha_archivo AS lista_fecha,
                   COALESCE(u.usuario_nombre,r.usuario,'') AS responsable
            FROM pedidos_revisiones r
            JOIN pedidos p ON p.pedido_id=r.pedido_id
            LEFT JOIN clientes cl ON cl.clientes_id=COALESCE(r.cliente_id,p.cliente_id)
            LEFT JOIN listas_precios_importaciones l ON l.lista_id=COALESCE(r.lista_id,p.lista_id)
            LEFT JOIN usuarios u ON u.usuario_id=r.usuario_id
            WHERE r.pedido_id=? AND r.revision=? LIMIT 1";
    $tablaDetalle='pedidos_revisiones_detalle';
    $titulo='PEDIDO HISTORICO';
    $carpeta='pedidos';
}

$st=$conexion->prepare($sql);
if(!$st) die('No se pudo preparar la consulta histórica.');
$st->bind_param('ii',$documentoId,$revision); $st->execute();
$doc=$st->get_result()->fetch_assoc(); $st->close();
if(!$doc){ http_response_code(404); die('La revisión solicitada no existe.'); }

$st=$conexion->prepare("SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM {$tablaDetalle} WHERE revision_id=? ORDER BY orden_visual,revision_detalle_id");
$revisionId=(int)$doc['revision_id']; $st->bind_param('i',$revisionId); $st->execute();
$rs=$st->get_result(); $items=array(); while($x=$rs->fetch_assoc()) $items[]=$x; $st->close();

$numero=numeroDocumentoVisible((string)$doc['numero_documento']);
$etiquetaVersion = $tipo==='pedido' ? etiquetaVersionPedido((int)$revision) : etiquetaVersionCotizacion((int)$revision);
$motivoVersion = $tipo==='pedido' ? motivoVersionPedido($conexion,(int)$documentoId,(int)$revision) : motivoVersionCotizacion($conexion,(int)$documentoId,(int)$revision);
$nombreSeguro=pdfNombreDocumento($tipo==='pedido'?'PEDIDO':'COTIZACION',(string)$doc['numero_documento'],$doc,(string)revPdfValor($doc,'referencia',''),$revision,'HISTORICO');
$rel='pdf/revisiones/'.$carpeta.'/'.$nombreSeguro;
$destino=__DIR__.'/'.$rel;

$pdf=new PdfAutomac(); $y=800;
$pdf->fillColorRect(32,778,531,44,20,43,82);
$pdf->fillColorRect(32,778,8,44,29,63,115);
$pdf->automacLogo(48, 783, 86);
$pdf->colorText(315,795,$titulo,13,true,255,255,255);
$y=758;
$pdf->fillColorRect(38,$y-108,519,124,255,247,220);
$pdf->colorText(42,$y,'DOCUMENTO HISTORICO - SOLO LECTURA',10,true,145,94,0);
$pdf->text(360,$y,$etiquetaVersion,10,true);
$y-=17;
$pdf->text(42,$y,($tipo==='pedido'?'Pedido: ':'Cotizacion: ').$numero,10,true);
$fechaVersionDocumento=(string)($doc['fecha_revision']??'');
if($tipo==='cotizacion'){
    $stF=$conexion->prepare('SELECT fecha_creacion FROM cotizaciones WHERE cotizacion_id=?');$stF->bind_param('i',$documentoId);$stF->execute();$fBase=$stF->get_result()->fetch_assoc();$stF->close();
    $fechaVersionDocumento=fechaVersionHistoricaCotizacion($conexion,(int)$documentoId,(int)$revision,(string)($fBase['fecha_creacion']??''));
}else{
    $stF=$conexion->prepare('SELECT fecha_creacion FROM pedidos WHERE pedido_id=?');$stF->bind_param('i',$documentoId);$stF->execute();$fBase=$stF->get_result()->fetch_assoc();$stF->close();
    $fechaVersionDocumento=fechaVersionHistoricaPedido($conexion,(int)$documentoId,(int)$revision,(string)($fBase['fecha_creacion']??''));
}
$pdf->text(360,$y,'Fecha de versión: '.date('d/m/Y H:i',strtotime($fechaVersionDocumento)),8,false);
$y-=16;
$pdf->text(42,$y,'Cliente: '.pdfTextoNominal(pdfNombreCliente($doc)),9,false);
$y-=14;
$pdf->text(42,$y,'Solicitante: '.pdfTextoNominal((pdfSolicitanteCliente($doc) ?: '-')),9,false);
$y-=14;
$pdf->text(42,$y,'Nro. cliente Bejerman: '.pdfNumeroCliente($doc).'    Telefono: '.pdfTelefonoCliente($doc),8,false);
$y-=13;
$pdf->text(42,$y,'Email: '.pdfEmailCliente($doc),8,false);
$y-=13;
$pdf->text(42,$y,'Referencia: '.pdfTextoNominal(((string)revPdfValor($doc,'referencia','') ?: '-')),9,false);
$y-=15;
$pdf->text(42,$y,'Responsable comercial: '.pdfTextoNominal(((string)revPdfValor($doc,'responsable','') ?: '-')),9,false);
$y-=15;
$base=trim((string)revPdfValor($doc,'lista_nombre',''));
if($base!=='') $pdf->text(42,$y,'Base de precios: '.pdfTextoMinusculas($base).(!empty($doc['lista_fecha'])?' - '.$doc['lista_fecha']:''),8,false);
$y-=25;

$cols=array(
 array('label'=>'Modulo','w'=>62), array('label'=>'Cant.','w'=>38,'align'=>'center'),
 array('label'=>'Codigo','w'=>72), array('label'=>'Descripcion','w'=>224),
 array('label'=>'Unitario','w'=>58,'align'=>'right'), array('label'=>'Total','w'=>58,'align'=>'right')
);
$pdf->tableHeader($y,$cols);
foreach($items as $it){
 $cant=abs((float)$it['cantidad']-round((float)$it['cantidad']))<0.0001?number_format((float)$it['cantidad'],0,',','.'):number_format((float)$it['cantidad'],2,',','.');
 $descripcion=pdfTextoMinusculas(trim((string)$it['concepto'].': '.(string)$it['descripcion']));
 $pdf->tableRow($y,$cols,array(pdfTextoMinusculas((string)$it['modulo']),$cant,$it['codigo'],$descripcion,pdfDinero((float)$it['precio_unitario']),pdfDinero((float)$it['importe_total'])),8);
}
$y-=8; if($y<115){$pdf->newPage();$y=800;}
$pdf->fillColorRect(350,$y-27,203,32,232,246,238);$y-=18;
$pdf->colorText(382,$y,'TOTAL HISTORICO',11,true,13,99,61);
$pdf->colorText(487,$y,pdfDinero((float)$doc['total']),11,true,13,99,61);
$y-=30;
$motivo=trim((string)$motivoVersion);
if($motivo!==''){
 $pdf->text(42,$y,'Motivo de la revision',10,true);$y-=15;
 $pdf->paragraph(48,$y,pdfTextoMinusculas($motivo),500,8,11,false);
}
$pdf->line(42,58,553,58,.5);
$pdf->text(42,43,'AUTOMAC - Copia historica de revision',7,false);
$pdf->text(410,43,$etiquetaVersion.' - No editable',7,false);
$pdf->output($destino);
documentosArchivarSinInterrumpir($conexion,$destino,$tipo==='pedido'?'Revisiones_Pedidos':'Revisiones_Cotizaciones',numeroDocumentoVisible((string)$doc['numero_documento']));
if (!empty($_GET['descargar'])) {
    if (!is_file($destino)) { http_response_code(404); die('El PDF histórico no está disponible.'); }
    header('Content-Type: application/pdf');
    header('Content-Length: '.filesize($destino));
    header('Content-Disposition: attachment; filename="'.str_replace('"','',basename($destino)).'"');
    header('X-Content-Type-Options: nosniff');
    readfile($destino); exit;
}
header('Location: '.$rel); exit;
