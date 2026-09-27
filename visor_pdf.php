<?php
require_once __DIR__ . '/conexion.php';
require_once __DIR__ . '/auth.php';
if (function_exists('exigirLogin')) { exigirLogin(); }
$paginaMenuActual = basename(__FILE__);
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Visor PDF - AUTOMAC</title>
<link rel="stylesheet" href="automac-ui.css?v=20260831-v409">
<style>
.pdf-viewer-page{max-width:980px;margin:26px auto;padding:0 20px 40px}.pdf-viewer-card{background:#fff;border:1px solid #d7e0e7;border-radius:16px;box-shadow:0 10px 28px rgba(21,47,72,.08);overflow:hidden}.pdf-viewer-head{display:flex;align-items:center;gap:14px;padding:18px 20px;background:linear-gradient(135deg,#173f70,#245f9e);color:#fff}.pdf-viewer-icon{display:grid;place-items:center;width:46px;height:46px;border-radius:12px;background:rgba(255,255,255,.16);font-size:25px}.pdf-viewer-head h1{margin:0;font-size:22px}.pdf-viewer-head p{margin:4px 0 0;font-size:13px;opacity:.88}.pdf-viewer-body{padding:22px}.pdf-drop{display:grid;place-items:center;min-height:220px;padding:28px;border:2px dashed #b9c9d6;border-radius:14px;background:#f8fbfd;text-align:center;transition:.15s}.pdf-drop.drag{border-color:#176b45;background:#eef8f3}.pdf-drop strong{font-size:18px;color:#17324d}.pdf-drop p{margin:7px 0 16px;color:#65798a;font-size:13px}.pdf-file{display:none}.pdf-actions{display:flex;gap:10px;justify-content:center;flex-wrap:wrap}.pdf-btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;min-height:44px;padding:9px 18px;border:0;border-radius:9px;background:#176b45!important;color:#fff!important;font:inherit;font-size:14px;font-weight:800;line-height:1.1;text-decoration:none!important;cursor:pointer}.pdf-btn:hover{filter:brightness(.94);color:#fff!important}.pdf-btn.secondary{background:#173f70!important;color:#fff!important}.pdf-btn:disabled{background:#91a3b6!important;color:#fff!important;opacity:1;cursor:not-allowed}.pdf-btn svg{width:16px;height:16px;stroke:currentColor;fill:none;stroke-width:2;flex:0 0 auto}.pdf-selected{display:none;margin-top:16px;padding:11px 13px;border:1px solid #cfe0d7;border-radius:9px;background:#f0f8f4;color:#244b38;font-size:13px}.pdf-selected.visible{display:block}.pdf-note{margin-top:18px;padding:13px 15px;border-radius:10px;background:#fff8e6;border:1px solid #efd48a;color:#6d571d;font-size:12px;line-height:1.5}.pdf-privacy{margin-top:12px;color:#6c7c88;font-size:11px;text-align:center}
</style>
</head>
<body>
<?php include __DIR__ . '/menu.php'; ?>
<main class="pdf-viewer-page">
  <section class="pdf-viewer-card">
    <div class="pdf-viewer-head">
      <div class="pdf-viewer-icon" aria-hidden="true">▱</div>
      <div><h1>Visor de PDF histórico</h1><p>Abrí una cotización u obra anterior en otra pestaña y recotizala manualmente con el sistema nuevo.</p></div>
    </div>
    <div class="pdf-viewer-body">
      <div id="pdf_drop" class="pdf-drop">
        <div>
          <strong>Seleccioná o arrastrá un PDF</strong>
          <p>El documento se abre con el visor PDF del navegador. Ahí podés rotar, ampliar y desplazarte.</p>
          <div class="pdf-actions">
            <label class="pdf-btn" for="pdf_file"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4z"/><path d="M8 2v4M16 2v4M8 18h8"/></svg> Elegir PDF</label>
            <button type="button" id="pdf_open" class="pdf-btn secondary" disabled><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14 3h7v7"/><path d="M10 14 21 3"/><path d="M21 14v6a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1h6"/></svg> Abrir en otra pestaña</button>
          </div>
          <input id="pdf_file" class="pdf-file" type="file" accept="application/pdf,.pdf">
        </div>
      </div>
      <div id="pdf_selected" class="pdf-selected"></div>
      <div class="pdf-note"><strong>Forma de trabajo sugerida:</strong> dejá el PDF abierto en una pestaña y el Cotizador en otra. Podés achicar ambas ventanas o alternar con Alt+Tab mientras cargás los datos.</div>
      <div class="pdf-privacy">El archivo no se importa a la base ni se interpreta: se abre localmente en tu navegador.</div>
    </div>
  </section>
</main>
<script>
(function(){
  const input=document.getElementById('pdf_file');
  const openBtn=document.getElementById('pdf_open');
  const drop=document.getElementById('pdf_drop');
  const selected=document.getElementById('pdf_selected');
  let file=null;
  let url=null;
  function setFile(f){
    if(!f) return;
    if(f.type && f.type!=='application/pdf' && !/\.pdf$/i.test(f.name||'')){alert('Seleccioná un archivo PDF.');return;}
    file=f;
    if(url) URL.revokeObjectURL(url);
    url=URL.createObjectURL(file);
    openBtn.disabled=false;
    selected.textContent='PDF seleccionado: '+file.name;
    selected.classList.add('visible');
  }
  input.addEventListener('change',()=>setFile(input.files && input.files[0]));
  openBtn.addEventListener('click',function(){
    if(!url) return;
    const w=window.open(url,'_blank');
    if(!w) alert('El navegador bloqueó la nueva pestaña. Permití ventanas emergentes para este sitio.');
  });
  ['dragenter','dragover'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.add('drag');}));
  ['dragleave','drop'].forEach(ev=>drop.addEventListener(ev,e=>{e.preventDefault();drop.classList.remove('drag');}));
  drop.addEventListener('drop',e=>setFile(e.dataTransfer.files && e.dataTransfer.files[0]));
  window.addEventListener('beforeunload',()=>{if(url)URL.revokeObjectURL(url);});
})();
</script>
</body>
</html>
