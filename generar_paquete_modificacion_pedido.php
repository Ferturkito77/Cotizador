<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'sistema_comercial.php';
require_once 'documentos_pdf.php';
require_once 'documentos_storage.php';
require_once 'material_hueco_of.php';
require_once 'orden_fabricacion_tipos.php';
require_once 'orden_fabricacion_layout.php';

asegurarSistemaUsuarios($conexion);
asegurarSistemaComercial($conexion);
exigirRoles(array('ADMINISTRADOR','TECNICO','COMERCIAL'));

$pedidoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$destino = strtolower(trim((string)($_GET['destino'] ?? '')));
if (!$pedidoId || !in_array($destino, array('administracion','produccion'), true)) {
    http_response_code(400); die('Paquete de modificación inválido.');
}

function pmDinero(float $v): string { return '$ ' . number_format(ceil($v), 0, ',', '.'); }

function pmCargarPedido(mysqli $conexion, int $pedidoId): array
{
    $sql = "SELECT p.*, c.cotizacion_numero, c.subtotal, c.descuento_1, c.descuento_2, c.descuento_3,
                   COALESCE(p.referencia,c.referencia) referencia,
                   cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,
                   COALESCE(u.usuario_nombre,p.usuario_modificacion,p.usuario,'') responsable_comercial
              FROM pedidos p
              LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id
              JOIN clientes cl ON cl.clientes_id=p.cliente_id
              LEFT JOIN usuarios u ON u.usuario_id=COALESCE(p.usuario_modificacion_id,p.usuario_id)
             WHERE p.pedido_id=? LIMIT 1";
    $st=$conexion->prepare($sql); if(!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i',$pedidoId); $st->execute(); $p=$st->get_result()->fetch_assoc(); $st->close();
    if(!$p) throw new RuntimeException('Pedido inexistente.');
    return $p;
}

function pmCargarDetalles(mysqli $conexion, int $pedidoId): array
{
    $st=$conexion->prepare("SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual,pedido_detalle_id");
    if(!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i',$pedidoId); $st->execute(); $r=$st->get_result(); $d=array();
    while($x=$r->fetch_assoc()) $d[]=$x; $st->close(); return $d;
}

function pmMotivoActual(mysqli $conexion, int $pedidoId, int $revision): string
{
    $revBuscada=max(0,$revision-1);
    $st=$conexion->prepare("SELECT motivo_modificacion FROM pedidos_revisiones WHERE pedido_id=? AND revision=? ORDER BY revision_id DESC LIMIT 1");
    if($st){$st->bind_param('ii',$pedidoId,$revBuscada);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();if($r && trim((string)$r['motivo_modificacion'])!=='') return trim((string)$r['motivo_modificacion']);}
    $st=$conexion->prepare("SELECT motivo_modificacion FROM pedidos_revisiones WHERE pedido_id=? ORDER BY revision_id DESC LIMIT 1");
    if($st){$st->bind_param('i',$pedidoId);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();if($r) return trim((string)$r['motivo_modificacion']);}
    return 'Modificación de pedido';
}

function pmHojaModificacion(PdfAutomac $pdf, array $p, string $motivo, string $copia): void
{
    $y=800;
    $pdf->fillColorRect(32,778,531,44,20,43,82); $pdf->fillColorRect(32,778,8,44,29,63,115);
    $pdf->automacLogo(48, 783, 86);
    $pdf->colorText(278,794,'HOJA DE MODIFICACIÓN DE PEDIDO',12,true,255,255,255);
    $y=752;
    $pdf->fillColorRect(42,$y-25,511,30,241,244,246);
    $pdf->colorText(50,$y-15,'COPIA: '.strtoupper($copia),12,true,23,43,58); $y-=45;
    $numero=numeroDocumentoVisible((string)$p['pedido_numero']);
    $pdf->text(42,$y,'Pedido: '.$numero,11,true); $pdf->text(350,$y,'Revisión vigente: '.(int)$p['revision'],11,true); $y-=20;
    $fecha=(string)($p['fecha_ultima_modificacion'] ?: $p['fecha_creacion']);
    $pdf->text(42,$y,'Fecha de modificación: '.date('d/m/Y H:i',strtotime($fecha)),9,false); $y-=17;
    $pdf->text(42,$y,'Cliente: '.pdfClienteInterno($p),9,false); $y-=16;
    $pdf->text(42,$y,'Obra / referencia: '.((string)$p['referencia'] ?: '-'),9,false); $y-=17;
    $pdf->text(42,$y,'Modificado por: '.((string)$p['responsable_comercial'] ?: '-'),9,false); $y-=30;
    $pdf->fillColorRect(42,$y-20,511,24,232,246,238); $pdf->colorText(50,$y-13,'DETALLE DE LA MODIFICACIÓN',10,true,13,99,61); $y-=34;
    foreach($pdf->wrap($motivo,485,10) as $linea){$pdf->text(50,$y,$linea,10,false);$y-=16;}
    $y-=35;
    $pdf->line(42,$y,250,$y,.5); $pdf->line(345,$y,553,$y,.5); $y-=14;
    $pdf->text(95,$y,'Firma Producción',8,false); $pdf->text(402,$y,'Firma Administración',8,false);
    $pdf->line(42,58,553,58,.5); $pdf->text(42,43,'AUTOMAC - Modificación de pedido',7,false);
}

function pmItemsValorizados(mysqli $conexion, array $p, array $detalles): array
{
    $items=pdfItemsComerciales($conexion,$p,$detalles);
    $protegida=0.0;$descontable=0.0;$indices=array();
    foreach($items as $i=>$item){
        $imp=(float)($item['total']??0); if($imp<=0) continue;
        $m=strtoupper(trim((string)($item['modulo']??'CONTROL')));
        if(in_array($m,array('REPUESTOS','ACCESORIOS','IEP','SENALIZACION'),true)) $protegida+=$imp;
        else {$descontable+=$imp;$indices[]=$i;}
    }
    $total=ceil((float)$p['total']); $final=ceil(max(0.0,$total-$protegida));
    if($descontable>0 && $indices){$factor=$final/$descontable;$ac=0.0;$ultimo=end($indices);reset($indices);foreach($indices as $i){$cant=max(.000001,(float)($items[$i]['cantidad']??1));if($i===$ultimo)$tf=round($final-$ac,2);else{$tf=round((float)$items[$i]['total']*$factor,2);$ac+=$tf;}$items[$i]['total']=$tf;$items[$i]['unitario']=round($tf/$cant,2);}}
    return $items;
}

function pmHojaAdministracion(PdfAutomac $pdf, mysqli $conexion, array $p, array $detalles): void
{
    $pdf->newPage(); $y=800;
    $pdf->fillColorRect(32,778,531,44,20,43,82);$pdf->fillColorRect(32,778,8,44,29,63,115);
    $pdf->automacLogo(48, 783, 86);$pdf->colorText(330,794,'PEDIDO ACTUALIZADO - VALORIZADO',11,true,255,255,255);
    $y=752;$pdf->text(42,$y,'Pedido: '.numeroDocumentoVisible((string)$p['pedido_numero']),11,true);$pdf->text(420,$y,'Revisión: '.(int)$p['revision'],10,true);$y-=18;
    $pdf->text(42,$y,'Cliente: '.pdfNombreCliente($p),9,false);$y-=14;
    $pdf->text(42,$y,'Nro. cliente Bejerman: '.pdfNumeroCliente($p).'    Telefono: '.pdfTelefonoCliente($p),8,false);$y-=13;
    $pdf->text(42,$y,'Email: '.pdfEmailCliente($p),8,false);$y-=13;
    $pdf->text(42,$y,'Referencia: '.((string)$p['referencia']?:'-'),9,false);$y-=24;
    $cols=array(array('label'=>'Cant.','w'=>42,'align'=>'center'),array('label'=>'Código','w'=>78),array('label'=>'Descripción','w'=>265),array('label'=>'Unitario','w'=>63,'align'=>'right'),array('label'=>'Total','w'=>63,'align'=>'right'));
    $pdf->tableHeader($y,$cols);
    foreach(pmItemsValorizados($conexion,$p,$detalles) as $it){$cant=abs((float)$it['cantidad']-round((float)$it['cantidad']))<.0001?number_format((float)$it['cantidad'],0,',','.'):number_format((float)$it['cantidad'],2,',','.');$pdf->tableRow($y,$cols,array($cant,(string)$it['codigo'],pdfTextoMinusculas((string)$it['descripcion']),pmDinero((float)$it['unitario']),pmDinero((float)$it['total'])),8);}
    if($y<120){$pdf->newPage();$y=800;}
    $y-=12;$pdf->fillColorRect(350,$y-28,203,34,232,246,238);$pdf->colorText(385,$y-18,'TOTAL GENERAL',11,true,13,99,61);$pdf->colorText(480,$y-18,pmDinero((float)$p['total']),11,true,13,99,61);
    $pdf->line(42,58,553,58,.5);$pdf->text(42,43,'AUTOMAC - Copia para Administración',7,false);
}

function pmAgregarProduccion(PdfAutomac $pdf, mysqli $conexion, array $p, array $detalles): void
{
    $datos=json_decode((string)($p['datos_formulario']??''),true); if(!is_array($datos))$datos=array();
    $tipos=oftTiposDisponibles($p,$detalles);
    foreach($tipos as $tipo){
        $filtrados=oftFiltrarDetalles($detalles,$tipo);
        $pdf->newPage();
        if($tipo==='CONTROL'){
            $mh=calcularMaterialHuecoOrden($conexion,$datos);
            ofDibujarPagina1($pdf,$conexion,$p,$filtrados,$datos,$mh);
            ofDibujarPagina2($pdf,$conexion,$p,$filtrados,$datos);
            ofDibujarPagina3($pdf,$conexion,$p,$filtrados,$datos);
        } elseif($tipo==='SENALIZACION_ACCESORIOS') {
            ofDibujarOrdenSenalizacionAccesorios($pdf,$conexion,$p,$filtrados,$datos);
        } elseif($tipo==='SUMINISTROS') {
            ofDibujarOrdenPreparacionSuministros($pdf,$conexion,$p,$filtrados);
        }
    }
}

try{
    $p=pmCargarPedido($conexion,(int)$pedidoId); $revision=(int)($p['revision']??0);
    if($revision<=0) throw new RuntimeException('El pedido todavía no tiene modificaciones registradas.');
    $detalles=pmCargarDetalles($conexion,(int)$pedidoId); if(!$detalles) throw new RuntimeException('El pedido no tiene detalle.');
    $motivo=pmMotivoActual($conexion,(int)$pedidoId,$revision);
    $pdf=new PdfAutomac();
    pmHojaModificacion($pdf,$p,$motivo,$destino==='administracion'?'Administración':'Producción');
    if($destino==='administracion') pmHojaAdministracion($pdf,$conexion,$p,$detalles); else pmAgregarProduccion($pdf,$conexion,$p,$detalles);
    $dir=__DIR__.'/pdf/modificaciones';if(!is_dir($dir)&&!mkdir($dir,0775,true)&&!is_dir($dir))throw new RuntimeException('No se pudo crear la carpeta de modificaciones.');
    $nombre=pdfNombreDocumento('PEDIDO',(string)$p['pedido_numero'],$p,(string)($p['referencia']??''),$revision,'MODIFICACION '.strtoupper($destino));
    $rel='pdf/modificaciones/'.$nombre;$abs=__DIR__.'/'.$rel;$pdf->output($abs);documentosArchivarSinInterrumpir($conexion,$abs,'Modificaciones',numeroDocumentoVisible((string)$p['pedido_numero']));header('Location: '.$rel);exit;
}catch(Throwable $e){http_response_code(500);die('No se pudo generar el paquete de modificación: '.htmlspecialchars($e->getMessage(),ENT_QUOTES,'UTF-8'));}
