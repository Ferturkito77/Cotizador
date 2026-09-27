<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}

function h($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function normalizarTextoHistorico($t){
    $t=str_replace(array("\r","\xC2\xA0"),array("\n"," "),(string)$t);
    $t=preg_replace('/[ \t]+/u',' ',$t);
    $t=preg_replace('/\n{3,}/u',"\n\n",$t);
    return trim($t);
}
function rx($pat,$txt,$idx=1){ return preg_match($pat,$txt,$m)?trim((string)($m[$idx]??'')):''; }
function detectarDocumentoHistorico($texto,$nombre){
    $t=normalizarTextoHistorico($texto); $base=pathinfo($nombre,PATHINFO_FILENAME);
    $tipo='COTIZACION'; $numero='';
    if(preg_match('/#\s*(\d{4,8})\b/u',$t,$m)){ $tipo='OBRA'; $numero='#'.$m[1]; }
    elseif(preg_match('/^\s*(\d{5,8})\b/u',$base,$m)){ $tipo='COTIZACION'; $numero=$m[1]; }
    elseif(preg_match('/\b(\d{5,8})\b/u',$t,$m)){ $numero=$m[1]; }

    $cliente=rx('/Cliente:\s*(.*?)(?:\s+N[°º]?\s*\/\s*Contacto:|\n|$)/iu',$t);
    if($cliente==='') $cliente=rx('/CLIENTE\s*[:\-]?\s*([^\n]+)/iu',$t);
    $referencia=rx('/Referencia:\s*(.*?)(?:\s+Contacto:|\n|$)/iu',$t);
    $nroCliente=''; $contacto='';
    if(preg_match('/N[°º]?\s*\/\s*Contacto:\s*(\d+)\s*\/\s*([^#\n\d]{2,80}?)(?=\s+#?\d{5,8}\b|\n|$)/iu',$t,$m)){
        $nroCliente=trim($m[1]); $contacto=trim($m[2]);
    }
    if($contacto==='') $contacto=rx('/Contacto:\s*([^\/\n]{2,80})/iu',$t);

    $controlModelo=rx('/Control:\s*\d+\s+(A\d{4,5}V\d+|A\d{4,5})\b/iu',$t);
    $codigoControl=rx('/\((A[0-9A-Z]+)\)\s*CONTROL/iu',$t);
    $cantidad=rx('/Control:\s*(\d+)/iu',$t); if($cantidad==='')$cantidad='1';
    $maniobra=rx('/\bindividual\s+X\s+([A-Z]{2,4})\b/iu',$t);
    $paradas=rx('/\b(\d+)P\s*\(([^)]*)\)/iu',$t,1);
    $nomenclatura=rx('/\b\d+P\s*\(([^)]*)\)/iu',$t,1);
    $potencia=rx('/\b([\d,.]+)\s*HP\b/iu',$t);
    $contactor=rx('/\bC:\s*(\d+)A\b/iu',$t);
    $vfModelo=rx('/VF:\s*Regulador\s+([^\(\n]+?)(?=\s*\()/iu',$t);
    $vfCorriente=rx('/Regulador[^\n]*\(([\d,.]+)A\)/iu',$t);
    $velocidad=rx('/\b([\d,.]+)\s*m\/min\b/iu',$t);
    $tension=rx('/\b(3x380v|3X380V|3X380|220V)\b/u',$t);
    $encoder=preg_match('/sin\s+encoder/iu',$t)?'SIN':(preg_match('/con\s+encoder/iu',$t)?'CON':'');
    $ptaCab=rx('/Puertas?\s*Cab:\s*(.*?)(?=\s+Puertas?\s*Piso:|\n)/iu',$t);
    $ptaPiso=rx('/Puertas?\s*Piso:\s*(.*?)(?=\s+Servicios:|\n)/iu',$t);
    $servicios=rx('/Servicios:\s*(.*?)(?=\s+Adicionales:|\s+Mat\.\s*hueco:|\n)/iu',$t);
    $matHueco=rx('/Mat\.\s*hueco:\s*(.*?)(?=\n|\()/iu',$t);
    $botonera=rx('/BOTONERA DE CABINA:\s*Modelo:\s*([^\/\n]+)/iu',$t);
    $pago=rx('/Forma de Pago:\s*(.*?)(?=\n|\$|ENTREGA|SUJETO|Presupuest)/iu',$t);
    $entrega=rx('/ENTREGA ESTIMADA\s*:\s*(.*?)(?=\n|SUJETO)/iu',$t);

    $items=array();
    if(preg_match_all('/(?:^|\n)\s*(\d+)\s*\(([A-Z0-9+]+)\)\s*-\s*(.*?)(?=\s+[\d.]+\s+[\d.]+(?:\n|$))/iu',$t,$mm,PREG_SET_ORDER)){
        foreach($mm as $m){ $items[]=array('cantidad'=>(int)$m[1],'codigo'=>trim($m[2]),'descripcion'=>trim($m[3])); }
    }
    return compact('tipo','numero','cliente','nroCliente','contacto','referencia','controlModelo','codigoControl','cantidad','maniobra','paradas','nomenclatura','potencia','contactor','vfModelo','vfCorriente','velocidad','tension','encoder','ptaCab','ptaPiso','servicios','matHueco','botonera','pago','entrega','items');
}
function buscarClienteHistorico($cn,$d){
    $bej=trim((string)($d['nroCliente']??'')); $nom=trim((string)($d['cliente']??''));
    if($bej!==''){
        $st=$cn->prepare("SELECT clientes_id,clientes_nomfantasia,clientes_numero_bejerman FROM clientes WHERE clientes_numero_bejerman=? LIMIT 1");
        $st->bind_param('s',$bej);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();if($r)return $r;
    }
    if($nom!==''){
        $like='%'.$nom.'%';$st=$cn->prepare("SELECT clientes_id,clientes_nomfantasia,clientes_numero_bejerman FROM clientes WHERE clientes_nomfantasia LIKE ? OR clientes_razonsocial LIKE ? ORDER BY (clientes_nomfantasia=?) DESC LIMIT 1");
        $st->bind_param('sss',$like,$like,$nom);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();if($r)return $r;
    }
    return null;
}

$datos=null;$error='';$pdfRel='';$texto='';
if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='analizar'){
    $texto=trim((string)($_POST['texto_pdf']??''));
    if(!isset($_FILES['pdf']) || $_FILES['pdf']['error']!==UPLOAD_ERR_OK){ $error='Seleccione un archivo PDF.'; }
    elseif(strtolower(pathinfo($_FILES['pdf']['name'],PATHINFO_EXTENSION))!=='pdf'){ $error='El archivo debe ser PDF.'; }
    elseif($_FILES['pdf']['size']>15*1024*1024){$error='El PDF supera 15 MB.';}
    else{
        $dir=__DIR__.DIRECTORY_SEPARATOR.'historicos_pdf'; if(!is_dir($dir))@mkdir($dir,0775,true);
        $safe=preg_replace('/[^A-Za-z0-9._-]+/','_',basename($_FILES['pdf']['name']));
        $dest=bin2hex(random_bytes(8)).'_'.$safe;
        if(move_uploaded_file($_FILES['pdf']['tmp_name'],$dir.DIRECTORY_SEPARATOR.$dest)){
            $pdfRel='historicos_pdf/'.$dest;
            if($texto==='') $error='El PDF se guardó, pero no se pudo extraer texto automáticamente. Pegue el texto en el cuadro de respaldo y vuelva a analizar.';
            else{
                $datos=detectarDocumentoHistorico($texto,$_FILES['pdf']['name']);
                $datos['archivo_original']=$_FILES['pdf']['name'];$datos['pdf_rel']=$pdfRel;$datos['texto']=$texto;
                $datos['cliente_encontrado']=buscarClienteHistorico($conexion,$datos);
                $_SESSION['importacion_historica']=$datos;
            }
        }else $error='No se pudo guardar el PDF histórico.';
    }
}
if(isset($_GET['limpiar'])){unset($_SESSION['importacion_historica']);header('Location: recotizar_historica.php');exit;}
if(!$datos && isset($_SESSION['importacion_historica'])){$datos=$_SESSION['importacion_historica'];$pdfRel=$datos['pdf_rel']??'';$texto=$datos['texto']??'';}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Recotizar PDF histórico</title>
<style>
body{margin:0;background:#eef3f6;font-family:Arial,sans-serif;color:#17324d}.wrap{max-width:1500px;margin:auto;padding:18px}.title{display:flex;justify-content:space-between;align-items:center;margin:0 0 14px}.grid{display:grid;grid-template-columns:minmax(430px,1fr) minmax(480px,1fr);gap:14px}.card{background:#fff;border:1px solid #d7e1e8;border-radius:12px;box-shadow:0 5px 18px rgba(20,50,75,.06);padding:14px}.pdf{width:100%;height:72vh;border:1px solid #ccd8e1;border-radius:8px;background:#f5f7f8}.upload{display:grid;grid-template-columns:1fr auto;gap:8px;margin-bottom:10px}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:0;border-radius:8px;padding:10px 14px;font-weight:800;cursor:pointer;background:#176b45;color:#fff}.btn.blue{background:#173f70}.btn.gray{background:#667786}.badge{display:inline-flex;padding:6px 9px;border-radius:999px;font-size:12px;font-weight:800}.obra{background:#e9f2ff;color:#174f86}.cot{background:#eef8f2;color:#146c43}.fields{display:grid;grid-template-columns:1fr 1fr;gap:8px}.field{border:1px solid #dce5eb;border-radius:8px;padding:8px;background:#fbfcfd}.field b{display:block;font-size:10px;text-transform:uppercase;color:#6b7d8c;margin-bottom:4px}.field span{font-size:14px;font-weight:700}.full{grid-column:1/-1}.items{width:100%;border-collapse:collapse;font-size:12px}.items th,.items td{padding:6px;border-bottom:1px solid #e5ebef;text-align:left}.ok{padding:9px;border-radius:8px;background:#edf8f2;color:#155f3c;margin:8px 0}.warn{padding:9px;border-radius:8px;background:#fff4df;color:#7b5000;margin:8px 0}.fallback{width:100%;min-height:90px;box-sizing:border-box;margin-top:8px} @media(max-width:950px){.grid{grid-template-columns:1fr}.pdf{height:55vh}}
</style></head><body>
<?php require __DIR__.'/menu.php'; ?>
<div class="wrap"><div class="title"><div><h1 style="margin:0">Recotizar PDF histórico</h1><div style="color:#6b7c88">Adjunte el documento viejo, revise lo detectado y cárguelo al cotizador actual.</div></div><?php if($datos):?><a class="btn gray" href="?limpiar=1">Nuevo PDF</a><?php endif;?></div>
<div class="grid"><section class="card">
<form id="formPdf" method="post" enctype="multipart/form-data"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="analizar"><input type="hidden" name="texto_pdf" id="texto_pdf">
<div class="upload"><input type="file" name="pdf" id="pdf" accept="application/pdf" required><button class="btn" type="submit" id="analizar">Analizar PDF</button></div>
<div id="estado" class="warn" style="display:none"></div><textarea id="fallback" class="fallback" placeholder="Respaldo: si el navegador no puede leer el PDF automáticamente, pegue aquí el texto del documento antes de Analizar."></textarea>
</form>
<?php if($pdfRel):?><iframe class="pdf" src="<?=h($pdfRel)?>#toolbar=1"></iframe><?php else:?><div class="pdf" style="display:grid;place-items:center;color:#7a8790">El PDF original aparecerá aquí.</div><?php endif;?>
</section><section class="card">
<?php if($error):?><div class="warn"><?=h($error)?></div><?php endif;?>
<?php if($datos): $esObra=$datos['tipo']==='OBRA'; ?>
<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:10px"><h2 style="margin:0">Datos detectados</h2><span class="badge <?=$esObra?'obra':'cot'?>"><?=$esObra?'OBRA':'COTIZACIÓN HISTÓRICA'?> · <?=h($datos['numero'])?></span></div>
<div class="<?=!empty($datos['cliente_encontrado'])?'ok':'warn'?>"><?php if(!empty($datos['cliente_encontrado'])):?>Cliente encontrado en la base actual: <b><?=h($datos['cliente_encontrado']['clientes_nomfantasia'])?></b>.<?php else:?>No encontré una coincidencia segura de cliente. Podrá seleccionarlo en el cotizador nuevo.<?php endif;?></div>
<div class="fields">
<div class="field"><b>Cliente</b><span><?=h($datos['cliente']?:'A revisar')?></span></div><div class="field"><b>Contacto</b><span><?=h($datos['contacto']?:'A revisar')?></span></div>
<div class="field full"><b>Referencia</b><span><?=h($datos['referencia']?:'A revisar')?></span></div>
<div class="field"><b>Control</b><span><?=h($datos['controlModelo']?:'A revisar')?> <?=h($datos['codigoControl']?'('.$datos['codigoControl'].')':'')?></span></div><div class="field"><b>Maniobra</b><span><?=h($datos['maniobra']?:'A revisar')?></span></div>
<div class="field"><b>Paradas</b><span><?=h($datos['paradas']?:'A revisar')?></span></div><div class="field"><b>Nomenclatura</b><span><?=h($datos['nomenclatura']?:'A revisar')?></span></div>
<div class="field"><b>Potencia</b><span><?=h($datos['potencia']?$datos['potencia'].' HP':'A revisar')?></span></div><div class="field"><b>VF / velocidad</b><span><?=h(trim($datos['vfModelo'].' '.$datos['velocidad'].' m/min'))?></span></div>
<div class="field full"><b>Puertas</b><span>Cabina: <?=h($datos['ptaCab']?:'A revisar')?> · Pisos: <?=h($datos['ptaPiso']?:'A revisar')?></span></div>
<div class="field full"><b>Botonera histórica</b><span><?=h($datos['botonera']?:'No detectada')?></span></div>
</div>
<h3>Ítems reconocidos</h3><table class="items"><thead><tr><th>Cant.</th><th>Código</th><th>Descripción histórica</th></tr></thead><tbody><?php foreach(($datos['items']??array()) as $it):?><tr><td><?=h($it['cantidad'])?></td><td><b><?=h($it['codigo'])?></b></td><td><?=h($it['descripcion'])?></td></tr><?php endforeach;?><?php if(empty($datos['items'])):?><tr><td colspan="3">No se pudieron separar ítems automáticamente. El PDF queda visible para revisión.</td></tr><?php endif;?></tbody></table>
<div class="warn"><b>Importante:</b> se recupera configuración, no precios. El cotizador nuevo recalculará con la lista y matrices actuales. Los datos detectados deben revisarse antes de emitir.</div>
<div style="display:flex;gap:8px;justify-content:flex-end"><a class="btn blue" href="index.php?importar_historica=1">Cargar en cotizador nuevo →</a></div>
<?php else:?><h2>Cómo funciona</h2><p>1. Seleccione el PDF histórico.<br>2. El navegador extrae el texto del PDF.<br>3. El sistema identifica si es <b>Obra (#)</b> o <b>Cotización histórica</b>.<br>4. Revise los datos y abra el cotizador nuevo.</p><div class="ok"><b>Regla de identificación:</b> un número precedido por <b>#</b> se trata como Obra. Un número histórico como <b>152282</b>, sin #, se trata como Cotización.</div><?php endif;?>
</section></div></div>
<script type="module">
const file=document.getElementById('pdf'), hidden=document.getElementById('texto_pdf'), fallback=document.getElementById('fallback'), estado=document.getElementById('estado'), form=document.getElementById('formPdf');
let extraido='';
async function extraer(f){
  if(!f)return;
  estado.style.display='block';estado.textContent='Leyendo texto del PDF…';
  try{
    const pdfjs=await import('https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.min.mjs');
    pdfjs.GlobalWorkerOptions.workerSrc='https://cdn.jsdelivr.net/npm/pdfjs-dist@4.10.38/build/pdf.worker.min.mjs';
    const ab=await f.arrayBuffer(), pdf=await pdfjs.getDocument({data:ab}).promise; let partes=[];
    for(let p=1;p<=pdf.numPages;p++){const page=await pdf.getPage(p);const tc=await page.getTextContent();partes.push(tc.items.map(x=>x.str).join(' '));}
    extraido=partes.join('\n'); hidden.value=extraido; fallback.value=extraido;estado.textContent='Texto detectado. Presione Analizar PDF.';
  }catch(e){extraido='';estado.textContent='No pude leer automáticamente el texto. Pegue el texto del PDF en el cuadro de respaldo y presione Analizar PDF.';}
}
file?.addEventListener('change',()=>extraer(file.files?.[0]));
form?.addEventListener('submit',e=>{if(!hidden.value.trim())hidden.value=fallback.value.trim();});
</script></body></html>
