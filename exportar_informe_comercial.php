<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
require_once 'sistema_comercial.php';
asegurarSistemaComercial($conexion);

function fechaValidaInforme(?string $fecha): bool {
    if (!$fecha) return false;
    $d = DateTime::createFromFormat('Y-m-d', $fecha);
    return $d && $d->format('Y-m-d') === $fecha;
}
function xmlEsc(string $v): string { return htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }
function colName(int $n): string {
    $s='';
    while ($n>0) { $n--; $s=chr(65+($n%26)).$s; $n=intdiv($n,26); }
    return $s;
}
function xCell(int $col, int $row, $value, int $style=0, string $type='inlineStr'): string {
    $ref=colName($col).$row;
    if ($type==='n') return '<c r="'.$ref.'" s="'.$style.'"><v>'.(0+$value).'</v></c>';
    return '<c r="'.$ref.'" s="'.$style.'" t="inlineStr"><is><t xml:space="preserve">'.xmlEsc((string)$value).'</t></is></c>';
}
function sheetXml(array $rows, array $widths, array $merges=array(), bool $freeze=true): string {
    $maxCol=1; $xmlRows='';
    foreach ($rows as $r=>$cells) {
        $rowNum=$r+1; $height=$cells['_height'] ?? null; unset($cells['_height']);
        $attrs=$height ? ' ht="'.$height.'" customHeight="1"' : '';
        $xmlRows.='<row r="'.$rowNum.'"'.$attrs.'>';
        foreach ($cells as $c=>$cell) {
            $maxCol=max($maxCol,(int)$c);
            $xmlRows.=xCell((int)$c,$rowNum,$cell['v'] ?? '',$cell['s'] ?? 0,$cell['t'] ?? 'inlineStr');
        }
        $xmlRows.='</row>';
    }
    $cols=''; foreach ($widths as $i=>$w) $cols.='<col min="'.$i.'" max="'.$i.'" width="'.$w.'" customWidth="1"/>';
    $mergeXml=''; if ($merges) { $mergeXml='<mergeCells count="'.count($merges).'">'; foreach($merges as $m)$mergeXml.='<mergeCell ref="'.$m.'"/>'; $mergeXml.='</mergeCells>'; }
    $pane=$freeze?'<sheetViews><sheetView workbookViewId="0"><pane ySplit="4" topLeftCell="A5" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>':'<sheetViews><sheetView workbookViewId="0"/></sheetViews>';
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        .$pane.'<sheetFormatPr defaultRowHeight="18"/><cols>'.$cols.'</cols><sheetData>'.$xmlRows.'</sheetData>'
        .$mergeXml.'<autoFilter ref="A4:'.colName($maxCol).max(4,count($rows)).'"/><pageMargins left="0.35" right="0.35" top="0.55" bottom="0.55" header="0.2" footer="0.2"/>'
        .'</worksheet>';
}
function obtenerDatosTipo(mysqli $conexion, array $cfg, string $inicioSql, string $finSql): array {
    $tabla=$cfg['tabla']; $a=$cfg['alias']; $id=$cfg['campo_id']; $num=$cfg['campo_numero']; $cond=$cfg['condicion'];
    $usuarios=[];
    $sql="SELECT COALESCE(u.usuario_nombre, NULLIF($a.usuario,''), 'Sin responsable') responsable, COUNT(*) cantidad
            FROM $tabla $a LEFT JOIN usuarios u ON u.usuario_id=$a.usuario_id
            WHERE $a.$num IS NOT NULL AND $cond AND $a.fecha_creacion BETWEEN ? AND ?
            GROUP BY COALESCE(u.usuario_nombre, NULLIF($a.usuario,''), 'Sin responsable')
            ORDER BY cantidad DESC, responsable ASC";
    $st=$conexion->prepare($sql);
    if($st){$st->bind_param('ss',$inicioSql,$finSql);$st->execute();$rs=$st->get_result();while($f=$rs->fetch_assoc())$usuarios[]=$f;$st->close();}
    $clientes=[];
    $sql="SELECT cl.clientes_codigo, cl.clientes_nomfantasia, COUNT($a.$id) cantidad, MAX($a.fecha_creacion) ultima_fecha
            FROM clientes cl LEFT JOIN $tabla $a ON $a.cliente_id=cl.clientes_id AND $a.$num IS NOT NULL AND $cond
            GROUP BY cl.clientes_id,cl.clientes_codigo,cl.clientes_nomfantasia HAVING COUNT($a.$id)>0
            ORDER BY cantidad DESC,cl.clientes_nomfantasia ASC";
    if($rs=$conexion->query($sql)){while($f=$rs->fetch_assoc())$clientes[]=$f;}
    $semana=0; foreach($usuarios as $f)$semana+=(int)$f['cantidad'];
    $historico=0; foreach($clientes as $f)$historico+=(int)$f['cantidad'];
    return ['usuarios'=>$usuarios,'clientes'=>$clientes,'semana'=>$semana,'usuarios_activos'=>count($usuarios),'clientes_total'=>count($clientes),'historico'=>$historico];
}

$configTipos=[
 'cotizaciones_control'=>['tabla'=>'cotizaciones','alias'=>'c','campo_id'=>'cotizacion_id','campo_numero'=>'cotizacion_numero','condicion'=>"c.cotizacion_tipo='CONTROL'",'titulo'=>'C. Cotizaciones de controles','corto'=>'C. Cotizaciones'],
 'cotizaciones_repuestos'=>['tabla'=>'cotizaciones','alias'=>'c','campo_id'=>'cotizacion_id','campo_numero'=>'cotizacion_numero','condicion'=>"c.cotizacion_tipo='SUMINISTROS'",'titulo'=>'R. Cotizaciones de repuestos','corto'=>'R. Repuestos'],
 'pedidos_suministros'=>['tabla'=>'pedidos','alias'=>'p','campo_id'=>'pedido_id','campo_numero'=>'pedido_numero','condicion'=>"p.pedido_tipo='SUMINISTROS'",'titulo'=>'P. Pedidos comerciales','corto'=>'P. Pedidos'],
 'pedidos_obras'=>['tabla'=>'pedidos','alias'=>'p','campo_id'=>'pedido_id','campo_numero'=>'pedido_numero','condicion'=>"p.pedido_tipo='OBRA'",'titulo'=>'# Obras','corto'=>'Obras']
];
$semana=$_GET['semana']??'';
if(fechaValidaInforme($semana)){$inicio=new DateTime($semana);$inicio->modify('monday this week');}else{$inicio=new DateTime('monday this week');}
$fin=clone $inicio;$fin->modify('+6 days');
$inicioSql=$inicio->format('Y-m-d').' 00:00:00';$finSql=$fin->format('Y-m-d').' 23:59:59';
$datos=[];foreach($configTipos as $k=>$cfg)$datos[$k]=obtenerDatosTipo($conexion,$cfg,$inicioSql,$finSql);
$formato=strtolower((string)($_GET['formato']??'pdf'));
$base='Informe_Comercial_Semanal_'.$inicio->format('Y-m-d').'_a_'.$fin->format('Y-m-d');

if($formato==='xlsx'){
    if(!class_exists('ZipArchive')){http_response_code(500);exit('La extensión ZIP de PHP es necesaria para generar Excel.');}
    $tmp=tempnam(sys_get_temp_dir(),'automac_xlsx_');@unlink($tmp);$tmp.='.xlsx';
    $zip=new ZipArchive(); if($zip->open($tmp,ZipArchive::CREATE)!==true){http_response_code(500);exit('No se pudo crear el archivo Excel.');}
    $sheetNames=['Resumen semanal'];$sheetXmls=[];
    $rows=[];$merges=[];
    $rows[]=[1=>['v'=>'INFORME COMERCIAL SEMANAL','s'=>1], '_height'=>30];$merges[]='A1:E1';
    $rows[]=[1=>['v'=>'Período','s'=>4],2=>['v'=>$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y'),'s'=>5],4=>['v'=>'Generado','s'=>4],5=>['v'=>date('d/m/Y H:i'),'s'=>5]];
    $rows[]=[];
    $rows[]=[1=>['v'=>'Categoría','s'=>2],2=>['v'=>'Semana','s'=>2],3=>['v'=>'Usuarios activos','s'=>2],4=>['v'=>'Clientes','s'=>2],5=>['v'=>'Histórico','s'=>2]];
    foreach($configTipos as $k=>$cfg){$d=$datos[$k];$rows[]=[1=>['v'=>$cfg['titulo'],'s'=>3],2=>['v'=>$d['semana'],'s'=>6,'t'=>'n'],3=>['v'=>$d['usuarios_activos'],'s'=>6,'t'=>'n'],4=>['v'=>$d['clientes_total'],'s'=>6,'t'=>'n'],5=>['v'=>$d['historico'],'s'=>6,'t'=>'n']];}
    $sheetXmls[]=sheetXml($rows,[1=>34,2=>14,3=>18,4=>14,5=>14],$merges,false);
    foreach($configTipos as $k=>$cfg){
        $sheetNames[]=$cfg['corto'];$d=$datos[$k];$rows=[];$merges=[];
        $rows[]=[1=>['v'=>'INFORME COMERCIAL SEMANAL','s'=>1], '_height'=>30];$merges[]='A1:D1';
        $rows[]=[1=>['v'=>$cfg['titulo'],'s'=>7], '_height'=>24];$merges[]='A2:D2';
        $rows[]=[1=>['v'=>'Período: '.$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y'),'s'=>5]];$merges[]='A3:D3';
        $rows[]=[1=>['v'=>'Responsable comercial','s'=>2],2=>['v'=>'Cantidad semanal','s'=>2]];
        if($d['usuarios'])foreach($d['usuarios'] as $u)$rows[]=[1=>['v'=>$u['responsable'],'s'=>3],2=>['v'=>(int)$u['cantidad'],'s'=>6,'t'=>'n']];else$rows[]=[1=>['v'=>'Sin actividad en la semana','s'=>3],2=>['v'=>0,'s'=>6,'t'=>'n']];
        $rows[]=[];$rows[]=[1=>['v'=>'Código','s'=>2],2=>['v'=>'Cliente','s'=>2],3=>['v'=>'Cantidad histórica','s'=>2],4=>['v'=>'Último documento','s'=>2]];
        if($d['clientes'])foreach($d['clientes'] as $c)$rows[]=[1=>['v'=>$c['clientes_codigo'],'s'=>3],2=>['v'=>$c['clientes_nomfantasia'],'s'=>3],3=>['v'=>(int)$c['cantidad'],'s'=>6,'t'=>'n'],4=>['v'=>$c['ultima_fecha']?date('d/m/Y H:i',strtotime($c['ultima_fecha'])):'','s'=>3]];
        else$rows[]=[1=>['v'=>'','s'=>3],2=>['v'=>'Sin documentos registrados','s'=>3],3=>['v'=>0,'s'=>6,'t'=>'n'],4=>['v'=>'','s'=>3]];
        $sheetXmls[]=sheetXml($rows,[1=>25,2=>38,3=>20,4=>22],$merges,true);
    }
    $contentTypes='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    foreach($sheetXmls as $i=>$x)$contentTypes.='<Override PartName="/xl/worksheets/sheet'.($i+1).'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';$contentTypes.='</Types>';
    $rels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>';
    $workbook='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $wbRels='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach($sheetNames as $i=>$n){$id=$i+1;$workbook.='<sheet name="'.xmlEsc(substr($n,0,31)).'" sheetId="'.$id.'" r:id="rId'.$id.'"/>';$wbRels.='<Relationship Id="rId'.$id.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$id.'.xml"/>';}
    $workbook.='</sheets></workbook>';$wbRels.='<Relationship Id="rId'.(count($sheetNames)+1).'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $styles='<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="4"><font><sz val="10"/><name val="Calibri"/></font><font><b/><sz val="18"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font><font><b/><sz val="12"/><color rgb="FF087A4A"/><name val="Calibri"/></font></fonts><fills count="5"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF173044"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE7F5EE"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF2F6F8"/></patternFill></fill></fills><borders count="2"><border/><border><bottom style="thin"><color rgb="FFD7E1E6"/></bottom></border></borders><cellXfs count="8"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="2" fillId="2" borderId="0" applyAlignment="1"><alignment horizontal="left" vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment vertical="center"/></xf><xf numFmtId="0" fontId="0" fillId="4" borderId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf><xf numFmtId="0" fontId="3" fillId="3" borderId="0" applyAlignment="1"><alignment vertical="center"/></xf></cellXfs></styleSheet>';
    $zip->addFromString('[Content_Types].xml',$contentTypes);$zip->addFromString('_rels/.rels',$rels);$zip->addFromString('xl/workbook.xml',$workbook);$zip->addFromString('xl/_rels/workbook.xml.rels',$wbRels);$zip->addFromString('xl/styles.xml',$styles);
    foreach($sheetXmls as $i=>$xml)$zip->addFromString('xl/worksheets/sheet'.($i+1).'.xml',$xml);$zip->close();
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');header('Content-Disposition: attachment; filename="'.$base.'.xlsx"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;
}

require_once 'documentos_pdf.php';
$pdf=new PdfAutomac();
function pdfHeaderInforme(PdfAutomac $pdf, DateTime $inicio, DateTime $fin, int $pagina): float {
    $pdf->fillColorRect(0,792,595.28,50,20,43,82);
    $pdf->automacLogo(42, 790, 82);
    $pdf->colorText(162,817,'INFORME COMERCIAL SEMANAL',14,true,255,255,255);
    $pdf->colorText(42,776,'Período: '.$inicio->format('d/m/Y').' al '.$fin->format('d/m/Y'),10,true,8,122,74);
    $pdf->text(430,776,'Página '.$pagina,9,false);
    $pdf->line(42,767,553,767,1);
    return 748;
}
function pdfEnsure(PdfAutomac $pdf, float &$y, float $needed, DateTime $inicio, DateTime $fin, int &$pagina): void {
    if($y-$needed<65){$pdf->newPage();$pagina++;$y=pdfHeaderInforme($pdf,$inicio,$fin,$pagina);}
}
$pagina=1;$y=pdfHeaderInforme($pdf,$inicio,$fin,$pagina);
$pdf->colorText(42,$y,'Resumen general',15,true,23,48,68);$y-=22;
$cols=[['label'=>'Categoría','w'=>235],['label'=>'Semana','w'=>68,'align'=>'right'],['label'=>'Usuarios','w'=>68,'align'=>'right'],['label'=>'Clientes','w'=>68,'align'=>'right'],['label'=>'Histórico','w'=>72,'align'=>'right']];
$pdf->tableHeader($y,$cols);
foreach($configTipos as $k=>$cfg){$d=$datos[$k];$pdf->tableRow($y,$cols,[$cfg['titulo'],$d['semana'],$d['usuarios_activos'],$d['clientes_total'],$d['historico']],8);}
$y-=14;
foreach($configTipos as $k=>$cfg){
    $d=$datos[$k];pdfEnsure($pdf,$y,110,$inicio,$fin,$pagina);
    $pdf->fillColorRect(42,$y-20,511,25,231,245,238);$pdf->colorText(50,$y-12,$cfg['titulo'],12,true,8,122,74);$y-=34;
    $pdf->text(42,$y,'Actividad semanal por responsable comercial',10,true);$y-=16;
    $uCols=[['label'=>'Responsable comercial','w'=>410],['label'=>'Cantidad','w'=>101,'align'=>'right']];$pdf->tableHeader($y,$uCols);
    if($d['usuarios'])foreach($d['usuarios'] as $u){pdfEnsure($pdf,$y,32,$inicio,$fin,$pagina);$pdf->tableRow($y,$uCols,[$u['responsable'],$u['cantidad']],8);}else$pdf->tableRow($y,$uCols,['Sin actividad en la semana','0'],8);
    $y-=12;pdfEnsure($pdf,$y,80,$inicio,$fin,$pagina);
    $pdf->text(42,$y,'Totales históricos por cliente',10,true);$y-=16;
    $cCols=[['label'=>'Código','w'=>72],['label'=>'Cliente','w'=>250],['label'=>'Cantidad','w'=>75,'align'=>'right'],['label'=>'Último documento','w'=>114]];$pdf->tableHeader($y,$cCols);
    if($d['clientes'])foreach($d['clientes'] as $c){pdfEnsure($pdf,$y,34,$inicio,$fin,$pagina);$pdf->tableRow($y,$cCols,[$c['clientes_codigo'],$c['clientes_nomfantasia'],$c['cantidad'],$c['ultima_fecha']?date('d/m/Y H:i',strtotime($c['ultima_fecha'])):''],8);}else$pdf->tableRow($y,$cCols,['','Sin documentos registrados','0',''],8);
    $y-=18;
}
$pdf->text(42,36,'Generado el '.date('d/m/Y H:i').' · Responsable: '.($_SESSION['usuario_nombre']??$_SESSION['usuario']??'Usuario'),8,false);
$tmp=tempnam(sys_get_temp_dir(),'automac_pdf_');$pdf->output($tmp);header('Content-Type: application/pdf');header('Content-Disposition: inline; filename="'.$base.'.pdf"');header('Content-Length: '.filesize($tmp));readfile($tmp);@unlink($tmp);exit;
