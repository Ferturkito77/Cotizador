<?php
/** Exportacion AU-01. Se recibe el mismo informe validado de la pantalla.
 * No hay consultas nuevas, datos historicos ajenos al periodo ni librerias externas.
 */
if (!defined('AUTOMAC_ACTIVIDAD_USUARIOS')) { http_response_code(404); exit; }

function auXml(string $s): string
{
    $s = preg_replace('/[^\x{0009}\x{000A}\x{000D}\x{0020}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    return htmlspecialchars($s === null ? '' : $s, ENT_QUOTES | ENT_XML1 | ENT_SUBSTITUTE, 'UTF-8');
}

function auXTexto(string $ref, string $texto, int $estilo = 0): string
{
    // inlineStr impide que nombres que empiezan con = o + se ejecuten como formula.
    return '<c r="'.$ref.'" s="'.$estilo.'" t="inlineStr"><is><t xml:space="preserve">'.auXml($texto).'</t></is></c>';
}
function auXNumero(string $ref, int $numero, int $estilo = 4, string $formula = ''): string
{
    return '<c r="'.$ref.'" s="'.$estilo.'">'.($formula !== '' ? '<f>'.auXml($formula).'</f>' : '').'<v>'.$numero.'</v></c>';
}
function auTextoFila(array $fila): string
{
    return $fila['nombre'] . "\n" . $fila['detalle'];
}
function auLen(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s,'UTF-8') : preg_match_all('/./us',$s,$m);
}

function auXlsx(array $informe): string
{
    $rowXml=''; $merges=array();
    $titulos=array(
        1=>'ACTIVIDAD POR USUARIO - AUTOMAC',
        2=>'Período: '.$informe['filtros']['periodo'],
        3=>'Usuario: '.$informe['usuario_etiqueta'],
        4=>'Consultado: '.$informe['generado'],
        5=>'Cantidad de documentos. Sin anulados, borradores ni documentos sin número.'
    );
    foreach($titulos as $n=>$s){
        $height=$n===1?34:($n===3?32:23);
        $rowXml.='<row r="'.$n.'" ht="'.$height.'" customHeight="1">'.auXTexto('A'.$n,$s,$n===1?1:2).'</row>';
        $merges[]='A'.$n.':F'.$n;
    }
    $headers=array('Usuario','C.
Control','R.
Suministros','Total
presupuestos','Obras #','Pedidos P.');
    $rowXml.='<row r="7" ht="38" customHeight="1">';
    foreach($headers as $k=>$s) $rowXml.=auXTexto(chr(65+$k).'7',$s,3);
    $rowXml.='</row>';
    $n=8;
    foreach($informe['filas'] as $fila){
        $height=max(36,(int)ceil(auLen($fila['nombre'])/40)*16+(int)ceil(auLen($fila['detalle'])/40)*15+8);
        $rowXml.='<row r="'.$n.'" ht="'.$height.'" customHeight="1">'.auXTexto('A'.$n,auTextoFila($fila),0);
        $rowXml.=auXNumero('B'.$n,$fila['c']).auXNumero('C'.$n,$fila['r']);
        $rowXml.=auXNumero('D'.$n,$fila['presupuestos'],5,'SUM(B'.$n.':C'.$n.')');
        $rowXml.=auXNumero('E'.$n,$fila['obras']).auXNumero('F'.$n,$fila['p']).'</row>';
        $n++;
    }
    $lastData=$n-1;
    if(!$informe['filas']){
        $rowXml.='<row r="8" ht="30" customHeight="1">'.auXTexto('A8','Sin actividad para estos filtros.',2).'</row>';
        $merges[]='A8:F8';$n=9;
    }
    $rowXml.='<row r="'.$n.'" ht="30" customHeight="1">'.auXTexto('A'.$n,'TOTAL DEL PERÍODO',6);
    foreach(array('c','r','presupuestos','obras','p') as $k=>$col){
        $letter=chr(66+$k);
        $formula=$lastData>=8?'SUM('.$letter.'8:'.$letter.$lastData.')':'0';
        $rowXml.=auXNumero($letter.$n,$informe['totales'][$col],7,$formula);
    }
    $rowXml.='</row>';$n+=2;
    $notas=auCriterios();
    if($informe['sin_asociar']>0) array_unshift($notas,'Atención: '.$informe['sin_asociar'].' documentos sin cuenta asociada se muestran por separado. No se atribuyen por coincidencia de nombre.');
    foreach($notas as $s){
        $height=max(28,(int)ceil(auLen($s)/128)*15+10);
        $rowXml.='<row r="'.$n.'" ht="'.$height.'" customHeight="1">'.auXTexto('A'.$n,$s,2).'</row>';
        $merges[]='A'.$n.':F'.$n;$n++;
    }
    $xml='<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $ns='http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $mergeXml='<mergeCells count="'.count($merges).'">';foreach($merges as $m)$mergeXml.='<mergeCell ref="'.$m.'"/>';$mergeXml.='</mergeCells>';
    $cols='';foreach(array(46,16,18,21,16,20) as $i=>$w)$cols.='<col min="'.($i+1).'" max="'.($i+1).'" width="'.$w.'" customWidth="1"/>';
    $sheet=$xml.'<worksheet xmlns="'.$ns.'"><sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><dimension ref="A1:F'.($n-1).'"/>'
        .'<sheetViews><sheetView workbookViewId="0" showGridLines="0"><pane ySplit="7" topLeftCell="A8" activePane="bottomLeft" state="frozen"/><selection pane="bottomLeft" activeCell="A8" sqref="A8"/></sheetView></sheetViews>'
        .'<sheetFormatPr defaultRowHeight="21"/><cols>'.$cols.'</cols><sheetData>'.$rowXml.'</sheetData>'
        .'<autoFilter ref="A7:F'.max(7,$lastData).'"/>'.$mergeXml
        .'<printOptions horizontalCentered="1"/><pageMargins left="0.35" right="0.35" top="0.5" bottom="0.5" header="0.2" footer="0.2"/><pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/>'
        .'<headerFooter><oddFooter>&amp;LAUTOMAC - Comercial&amp;RPágina &amp;P de &amp;N</oddFooter></headerFooter></worksheet>';
    // Imported values green; derived totals black. Labels and notes subdued.
    $styles=$xml.'<styleSheet xmlns="'.$ns.'"><fonts count="5">'
        .'<font><sz val="11"/><color rgb="FF166145"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="19"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .'<font><sz val="10"/><color rgb="FF526778"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font>'
        .'<font><b/><sz val="11"/><color rgb="FF000000"/><name val="Calibri"/></font></fonts>'
        .'<fills count="4"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF234E74"/><bgColor indexed="64"/></patternFill></fill><fill><patternFill patternType="solid"><fgColor rgb="FFE6F1EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        .'<borders count="3"><border/><border><bottom style="hair"><color rgb="FFDCE5EA"/></bottom></border><border><top style="thin"><color rgb="FF91BAA6"/></top></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="8">'
        .'<xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" indent="1"/></xf>'
        .'<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="0" fontId="3" fillId="2" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>'
        .'<xf numFmtId="3" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="3" fontId="4" fillId="3" borderId="1" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'<xf numFmtId="0" fontId="4" fillId="3" borderId="2" xfId="0" applyAlignment="1"><alignment vertical="center"/></xf>'
        .'<xf numFmtId="3" fontId="4" fillId="3" borderId="2" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment horizontal="right" vertical="center"/></xf>'
        .'</cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    $relsNs='http://schemas.openxmlformats.org/package/2006/relationships';
    $docRel='http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $parts=array(
        '[Content_Types].xml'=>$xml.'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/></Types>',
        '_rels/.rels'=>$xml.'<Relationships xmlns="'.$relsNs.'"><Relationship Id="rId1" Type="'.$docRel.'/officeDocument" Target="xl/workbook.xml"/></Relationships>',
        'xl/workbook.xml'=>$xml.'<workbook xmlns="'.$ns.'" xmlns:r="'.$docRel.'"><bookViews><workbookView/></bookViews><sheets><sheet name="Actividad por usuario" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm.Print_Titles" localSheetId="0">&apos;Actividad por usuario&apos;!$1:$7</definedName><definedName name="_xlnm.Print_Area" localSheetId="0">&apos;Actividad por usuario&apos;!$A$1:$F$'.($n-1).'</definedName></definedNames><calcPr calcId="124519" fullCalcOnLoad="1"/></workbook>',
        'xl/_rels/workbook.xml.rels'=>$xml.'<Relationships xmlns="'.$relsNs.'"><Relationship Id="rId1" Type="'.$docRel.'/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="'.$docRel.'/styles" Target="styles.xml"/></Relationships>',
        'xl/styles.xml'=>$styles,'xl/worksheets/sheet1.xml'=>$sheet
    );
    $base=tempnam(sys_get_temp_dir(),'au_excel_');
    if($base===false)throw new RuntimeException('No se puede escribir en la carpeta temporal.');
    $path=$base.'.zip';
    try {
        if(class_exists('ZipArchive')){
            $z=new ZipArchive();if($z->open($path,ZipArchive::CREATE|ZipArchive::OVERWRITE)!==true)throw new RuntimeException('No se pudo crear Excel.');
            try{foreach($parts as $name=>$part){if(!$z->addFromString($name,$part))throw new RuntimeException('No se pudo escribir Excel.');}}finally{$cerrado=$z->close();}
            if(!$cerrado)throw new RuntimeException('No se pudo cerrar Excel.');
        } elseif(class_exists('PharData')) {
            // PharData crea ZIP de datos aun con phar.readonly=1; no crea ejecutables.
            $z=new PharData($path,0,null,Phar::ZIP);
            foreach($parts as $name=>$part)$z[$name]=$part;
            unset($z);
        } else {
            throw new RuntimeException('Falta ZIP/Phar en PHP. Puede usar CSV o PDF.');
        }
        if(!is_file($path)||filesize($path)===0)throw new RuntimeException('Excel vacio.');
        return $path;
    } catch(Throwable $e){if(is_file($path))@unlink($path);throw $e;} finally{@unlink($base);}
}

function auCsvSeguro(string $s): string
{
    // Defensa contra formulas tambien si hay espacios, BOM o controles delante.
    if(preg_match('/^[\s\x{FEFF}]*[=+@-]/u',$s)||preg_match('/^[\t\r\n]/',$s))return "'".$s;
    return $s;
}
function auCsvFila($f, array $campos): void
{
    // CSV con comillas duplicadas (sin depender del escape vacio de PHP >= 7.4).
    $salida = array();
    foreach ($campos as $campo) $salida[] = '"' . str_replace('"', '""', (string)$campo) . '"';
    $linea = implode(';', $salida) . "\r\n";
    if (fwrite($f, $linea) !== strlen($linea)) throw new RuntimeException('No se pudo escribir CSV.');
}
function auCsv(array $informe): string
{
    $tmp=tempnam(sys_get_temp_dir(),'au_csv_');if($tmp===false)throw new RuntimeException('Sin carpeta temporal.');
    $f=fopen($tmp,'wb');if(!$f){@unlink($tmp);throw new RuntimeException('No se pudo crear CSV.');}
    try{
        fwrite($f,"\xEF\xBB\xBF");
        $meta=array(array('ACTIVIDAD POR USUARIO - AUTOMAC'),array('Periodo',$informe['filtros']['periodo']),array('Usuario',$informe['usuario_etiqueta']),array('Consultado',$informe['generado']),array());
        foreach($meta as $r)auCsvFila($f,array_map('auCsvSeguro',$r));
        auCsvFila($f,array('Usuario','Cuenta / identificacion','C. Control','R. Suministros','Total presupuestos','Obras #','Pedidos P.'));
        foreach($informe['filas'] as $r){
            auCsvFila($f,array(auCsvSeguro($r['nombre']),auCsvSeguro($r['detalle']),$r['c'],$r['r'],$r['presupuestos'],$r['obras'],$r['p']));
        }
        $t=$informe['totales'];auCsvFila($f,array('TOTAL DEL PERIODO','',$t['c'],$t['r'],$t['presupuestos'],$t['obras'],$t['p']));
        auCsvFila($f,array());foreach(auCriterios() as $s)auCsvFila($f,array($s));
    } catch(Throwable $e){fclose($f);@unlink($tmp);throw $e;}
    fclose($f);return $tmp;
}

function auPdfPie(PdfAutomac $pdf,int $pagina): void
{
    $pdf->line(42,53,553,53,.4);$pdf->colorText(42,39,'AUTOMAC | Comercial - Actividad por usuario',8,false,82,103,120);
    $pdf->text(494,39,'Página '.$pagina,8);
}
function auPdfCabecera(PdfAutomac $pdf,array $informe): float
{
    $pdf->automacLogo(42,784,80);
    $pdf->colorText(143,808,'ACTIVIDAD POR USUARIO',17,true,35,78,116);
    $pdf->colorText(143,790,'Presupuestos, obras y pedidos de suministros',9,false,82,103,120);
    $y=753.0;
    $pdf->paragraph(42,$y,'Período: '.$informe['filtros']['periodo'],511,10,14,true);
    $pdf->paragraph(42,$y,'Usuario: '.$informe['usuario_etiqueta'],511,10,14);
    $pdf->text(42,$y,'Consultado: '.$informe['generado'],8);$y-=21;
    return $y;
}
function auPdfColumnas(PdfAutomac $pdf,float &$y): void
{
    $w=array(170,57,65,89,60,70);$labels=array(array('Usuario'),array('C.','Control'),array('R.','Suministros'),array('Total','presupuestos'),array('Obras','#'),array('Pedidos','P.'));
    $pdf->fillColorRect(42,$y-35,511,37,35,78,116);$x=42;
    foreach($w as $i=>$width){foreach($labels[$i] as $j=>$line)$pdf->colorText($x+6,$y-12-$j*11,$line,8,true,255,255,255);$x+=$width;}
    $y-=37;
}
function auPdfLineas(PdfAutomac $pdf,string $s,float $w): array
{
    $lines=array();foreach(explode("\n",$s) as $part){foreach($pdf->wrap($part,$w,9) as $line)$lines[]=$line;}
    return $lines?:array('');
}
function auPdf(array $informe): string
{
    require_once __DIR__.'/documentos_pdf.php';
    $pdf=new PdfAutomac();$pagina=1;$y=auPdfCabecera($pdf,$informe);
    $t=$informe['totales'];
    $pdf->fillColorRect(42,$y-42,511,43,230,241,235);
    foreach(array(array('Presupuestos',$t['presupuestos']),array('Obras #',$t['obras']),array('Pedidos P.',$t['p'])) as $i=>$k){
        $x=52+$i*171;$pdf->colorText($x,$y-13,$k[0],9,true,23,81,58);$pdf->colorText($x,$y-33,number_format($k[1],0,',','.'),16,true,23,81,58);
    }
    $y-=60;auPdfColumnas($pdf,$y);
    $filas=$informe['filas'];
    if(!$filas)$filas[]=array_merge(auCeros(),array('nombre'=>'Sin actividad para estos filtros.','detalle'=>''));
    $filas[]=array_merge($t,array('nombre'=>'TOTAL DEL PERÍODO','detalle'=>'','total'=>true));
    $widths=array(170,57,65,89,60,70);
    foreach($filas as $ix=>$r){
        $lines=auPdfLineas($pdf,trim(auTextoFila($r)),158);$h=max(32,count($lines)*12+12);
        if($y-$h<92){auPdfPie($pdf,$pagina);$pdf->newPage();$pagina++;$y=auPdfCabecera($pdf,$informe);auPdfColumnas($pdf,$y);}
        $total=!empty($r['total']);
        if($total)$pdf->fillColorRect(42,$y-$h+2,511,$h,230,241,235);
        elseif($ix%2===1)$pdf->fillColorRect(42,$y-$h+2,511,$h,247,250,252);
        foreach($lines as $j=>$line)$pdf->text(48,$y-14-$j*12,$line,9,$total||$j===0);
        $x=42+$widths[0];
        foreach(array('c','r','presupuestos','obras','p') as $i=>$col){
            $s=number_format($r[$col],0,',','.');$tx=$x+$widths[$i+1]-8-strlen($s)*9*.52;
            $pdf->text($tx,$y-($h/2)+2,$s,9,$total||$col==='presupuestos');$x+=$widths[$i+1];
        }
        $pdf->line(42,$y-$h+2,553,$y-$h+2,.25);$y-=$h;
    }
    $y-=22;
    $notas=auCriterios();
    if($informe['sin_asociar']>0)array_unshift($notas,'Atención: '.$informe['sin_asociar'].' documentos sin cuenta de usuario asociada. Se incluyen en el total, separados de las cuentas actuales.');
    foreach($notas as $s){
        foreach($pdf->wrap($s,511,8) as $line){
            if($y<89){auPdfPie($pdf,$pagina);$pdf->newPage();$pagina++;$y=auPdfCabecera($pdf,$informe);}
            $pdf->colorText(42,$y,$line,8,false,82,103,120);$y-=11;
        }$y-=6;
    }
    auPdfPie($pdf,$pagina);
    $tmp=tempnam(sys_get_temp_dir(),'au_pdf_');if($tmp===false)throw new RuntimeException('Sin carpeta temporal.');
    try{$pdf->output($tmp);if(!filesize($tmp))throw new RuntimeException('PDF vacio.');return $tmp;}catch(Throwable $e){@unlink($tmp);throw $e;}
}

function auExportar(array $informe,string $formato): void
{
    $tipos=array('xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','pdf'=>'application/pdf','csv'=>'text/csv; charset=UTF-8');
    if(!isset($tipos[$formato]))throw new InvalidArgumentException('Formato no admitido.');
    $tmp=$formato==='xlsx'?auXlsx($informe):($formato==='pdf'?auPdf($informe):auCsv($informe));
    try{
        $f=$informe['filtros'];$nombre='Actividad_por_usuario_'.$f['desde'].'_a_'.$f['hasta'].'_'.$f['usuario'].'.'.$formato;
        if(headers_sent())throw new RuntimeException('La respuesta ya fue iniciada. No se puede descargar un archivo.');
        header('Content-Type: '.$tipos[$formato]);header('Content-Disposition: attachment; filename="'.$nombre.'"');
        header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store, max-age=0');
        header('Pragma: no-cache');header('Content-Length: '.filesize($tmp));readfile($tmp);
    } finally {@unlink($tmp);}
}
