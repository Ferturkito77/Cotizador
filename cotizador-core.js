/* AUTOMAC v431 - JavaScript modularizado. Mantiene nombres globales y orden original. */

/* ---- line-5841 (extraido de index.php, linea original 5841) ---- */
(function(){
  const CLAVE='automac_panel_presupuesto_oculto';
  function aplicarPanelPresupuesto(oculto, desplazar){
    const layout=document.getElementById('cotizador_layout');
    const panel=document.getElementById('panel_calculo_cotizador');
    const boton=document.getElementById('toggle_panel_presupuesto');
    if(!layout||!panel||!boton)return;
    layout.classList.toggle('panel-presupuesto-oculto',oculto);
    panel.classList.toggle('panel-presupuesto-oculto',oculto);
    panel.setAttribute('aria-hidden',oculto?'true':'false');
    document.body.classList.toggle('panel-presupuesto-abierto',!oculto);
    boton.setAttribute('aria-expanded',oculto?'false':'true');
    boton.title=oculto?'Mostrar presupuesto en preparación':'Ocultar presupuesto en preparación';
    const flecha=boton.querySelector('.panel-presupuesto-flecha');
    if(flecha)flecha.textContent=oculto?'‹':'›';
    document.querySelectorAll('.v129-btn-summary,#nav_toggle_resumen').forEach(function(botonResumen){
      botonResumen.textContent=oculto?'Ver resumen':'Ocultar resumen';
      botonResumen.setAttribute('aria-expanded',oculto?'false':'true');
      botonResumen.classList.toggle('is-open',!oculto);
    });
    localStorage.setItem(CLAVE,oculto?'1':'0');
    if(!oculto && desplazar) setTimeout(function(){panel.scrollIntoView({behavior:'smooth',block:'center'});},60);
  }
  window.setPanelPresupuestoOculto=aplicarPanelPresupuesto;
  
document.addEventListener('DOMContentLoaded',function(){
    const boton=document.getElementById('toggle_panel_presupuesto');
    // v138: el panel auxiliar siempre inicia cerrado. Solo una acción explícita del usuario lo abre.
    // Evita el destello del resumen al aplicar una plantilla o recargar la cotización.
    aplicarPanelPresupuesto(true);
    if(boton)boton.addEventListener('click',function(){
      const layout=document.getElementById('cotizador_layout');
      const ocultar=!layout.classList.contains('panel-presupuesto-oculto');
      aplicarPanelPresupuesto(ocultar);
    });
    const navResumen=document.getElementById('nav_toggle_resumen');
    if(navResumen)navResumen.addEventListener('click',function(){
      const layout=document.getElementById('cotizador_layout');
      const estaOculto=!layout || layout.classList.contains('panel-presupuesto-oculto');
      aplicarPanelPresupuesto(!estaOculto);
    });
    const cerrar=document.getElementById('cerrar_panel_presupuesto');
    if(cerrar)cerrar.addEventListener('click',function(){
      const panel=document.getElementById('panel_calculo_cotizador');
      if(panel) panel.classList.remove('modo-resumen-total');
      aplicarPanelPresupuesto(true);
    });
  });
})();


/* ---- line-5893 (extraido de index.php, linea original 5893) ---- */
/* v127 - Capa de experiencia operativa. No calcula ni altera precios. */
(function(){
  const PRICE_KEY='automac_v127_detalle_precios_';
  function btnPrecio(modulo){return document.querySelector('[data-price-toggle="'+modulo+'"]');}
  function aplicarVistaPrecios(modulo, mostrar){
    const el=document.getElementById('modulo_'+modulo);
    const btn=btnPrecio(modulo);
    if(el) el.classList.toggle('mostrar-precios',!!mostrar);
    if(btn){
      btn.setAttribute('aria-pressed',mostrar?'true':'false');
      if(modulo==='senalizacion') btn.textContent=mostrar?'Ocultar panel de precios':'Ver detalle de precios';
      else btn.textContent=mostrar?'Ocultar precios':'Ver precios';
    }
    try{localStorage.setItem(PRICE_KEY+modulo,mostrar?'1':'0');}catch(e){}
  }
  window.alternarVistaPrecios=function(modulo,btn){
    const actual=(btn?.getAttribute('aria-pressed')==='true');
    const nuevo=!actual;
    aplicarVistaPrecios(modulo,nuevo);
    if(modulo==='senalizacion' && typeof window.setPanelPresupuestoOculto==='function') window.setPanelPresupuestoOculto(!nuevo,nuevo);
  };
  function textoSelect(id, fallback){
    const e=document.getElementById(id); if(!e) return fallback||'—';
    const opt=e.options?.[e.selectedIndex]; return (opt?.textContent||e.value||fallback||'—').trim();
  }
  function actualizarContextoDocumento(){
    const cliente=document.getElementById('cliente_busqueda');
    const referencia=document.getElementById('referencia_cotizacion');
    const equipos=document.getElementById('cantidad_equipos');
    const c=document.getElementById('ctx_cliente'),r=document.getElementById('ctx_referencia'),q=document.getElementById('ctx_equipos'),l=document.getElementById('ctx_lista');
    if(c)c.textContent=(cliente?.value||'').trim()||'Cliente pendiente';
    if(r)r.textContent=(referencia?.value||'').trim()||'Sin referencia';
    if(q)q.textContent=equipos?.value ? equipos.value+' '+(Number(equipos.value)===1?'equipo':'equipos') : '—';
    if(l)l.textContent=textoSelect('lista_id','—');
  }
  function actualizarDisponibilidadEmision(){
    const clienteId=Number(document.getElementById('id_cliente')?.value||0);
    const totalTexto=document.getElementById('resumen_total_general')?.textContent||'';
    const total=Number(totalTexto.replace(/[^0-9]/g,''))||0;
    document.querySelectorAll('.resumen-accion-emision').forEach(function(b){
      // El cliente se valida al hacer clic. No deshabilitar el boton por falta de cliente,
      // porque un boton disabled no dispara onclick y el usuario no recibe ningun aviso.
      const calculoOk=total>0;
      b.disabled=!calculoOk;
      b.classList.toggle('ui-no-disponible',!calculoOk);
      if(!calculoOk){
        b.title='Complete al menos un módulo con cálculo válido';
      }else if(clienteId<=0){
        b.title='Falta seleccionar el cliente';
      }else{
        b.title='Documento listo para revisión final';
      }
    });
  }
  window.actualizarUIOperativaV127=function(){actualizarContextoDocumento();actualizarDisponibilidadEmision();};
  document.addEventListener('DOMContentLoaded',function(){
    ['accesorios','repuestos'].forEach(function(m){let v=false;try{v=localStorage.getItem(PRICE_KEY+m)==='1';}catch(e){} aplicarVistaPrecios(m,v);});
    aplicarVistaPrecios('senalizacion',false);
    actualizarContextoDocumento();actualizarDisponibilidadEmision();
    document.addEventListener('input',function(e){
      if(['cliente_busqueda','referencia_cotizacion','cantidad_equipos'].includes(e.target?.id)) actualizarContextoDocumento();
    },true);
    document.addEventListener('change',function(e){
      if(['id_cliente','lista_id','cantidad_equipos'].includes(e.target?.id)) {actualizarContextoDocumento();actualizarDisponibilidadEmision();}
    },true);
    document.addEventListener('click',function(e){
      if(e.target?.closest('.cotizador-modulo-btn,.accesorio-catalogo-check,.repuesto-agregar,.repuesto-quitar,.resumen-acciones')) setTimeout(actualizarDisponibilidadEmision,40);
    },true);
  });
  // El resumen ya es la fuente visual de verdad. Envolvemos solo para refrescar disponibilidad.
  if(typeof window.actualizarResumenDocumento==='function'){
    const original=window.actualizarResumenDocumento;
    window.actualizarResumenDocumento=function(){const r=original.apply(this,arguments);setTimeout(actualizarDisponibilidadEmision,0);return r;};
  }
})();


/* ---- line-5971 (extraido de index.php, linea original 5971) ---- */
/* v129 - App Workbench. Capa estructural de interfaz, sin alterar cálculos. */
(function(){
 const metas={
  control:['01','Control','CPU, maniobra, equipos y configuración técnica','Pendiente'],
  senalizacion:['02','Señalización','Botoneras, indicadores y datos por coche','Pendiente'],
  accesorios:['03','Accesorios','Complementos y especiales del equipo','Pendiente'],
  iep:['04','IEP','Carga rápida de conceptos y cantidades','Carga directa'],
  repuestos:['05','Repuestos','Buscar, seleccionar y cotizar artículos','Integrado']
 };
 function estadoModulo(m){
  const b=document.querySelector('.nav-modulo[data-modulo="'+m+'"] [data-nav-estado]');
  return (b?.textContent||'').trim()||'Pendiente';
 }
 function precioCabeceraModulo(m){
  if(!['control','senalizacion','accesorios'].includes(m)) return metas[m]?.[3]||'';
  const val=Number(window.subtotalesDocumento?.[m]||0);
  if(!(val>0)) return 'PENDIENTE';
  try{return monedaDocumento(val);}catch(e){return '$ '+Math.round(val).toLocaleString('es-AR');}
 }
 function crearCabeceras(){
  Object.entries(metas).forEach(([m,d])=>{
   const el=document.getElementById('modulo_'+m); if(!el||el.querySelector('.v129-module-head'))return;
   const h=document.createElement('div'); h.className='v129-module-head';
   const btnDesglose=(m==='control'||m==='senalizacion'||m==='accesorios')?'<button type="button" class="v329-btn-desglose" data-v329-desglose="'+m+'">Ver desglose</button>':'';
   h.innerHTML='<div class="v129-module-title"><span class="v129-module-icon">'+d[0]+'</span><div><strong>'+d[1]+'</strong><small>'+d[2]+'</small></div></div><div class="v129-module-meta"><span class="v129-pill auto" data-v129-price="'+m+'">'+precioCabeceraModulo(m)+'</span><span class="v129-pill" data-v129-status="'+m+'">'+estadoModulo(m)+'</span>'+btnDesglose+'</div>';
   el.insertBefore(h,el.firstChild);
  });
 }
 function prepararRepuestos(){
  const m=document.getElementById('modulo_repuestos'); if(!m||m.querySelector('.v129-repuestos-workspace'))return;
  const filtros=m.querySelector('.repuestos-filtros'), items=m.querySelector('#items_repuestos'); if(!filtros||!items)return;
  const w=document.createElement('div');w.className='v129-repuestos-workspace';
  filtros.parentNode.insertBefore(w,filtros);w.appendChild(filtros);w.appendChild(items);
  m.querySelectorAll('.repuesto-manual').forEach(actualizarEstadoRepuestoManual);
 }
 function nombreActivo(){
  const b=document.querySelector('.nav-modulo.activo .nav-texto');return (b?.textContent||'Control').trim();
 }
 function sincronizarEspacioActionbar(){
  const bar=document.querySelector('.v129-actionbar');
  if(!bar)return;
  const aplicar=()=>{
   const r=bar.getBoundingClientRect();
   const bottomGap=Math.max(0,window.innerHeight-r.bottom);
   const clearance=Math.ceil(r.height+bottomGap+18);
   document.documentElement.style.setProperty('--am-actionbar-clearance',clearance+'px');
   document.documentElement.style.setProperty('--am-actionbar-height',Math.ceil(r.height)+'px');
  };
  aplicar();
  if(!bar.__amResizeObserver && 'ResizeObserver' in window){
   bar.__amResizeObserver=new ResizeObserver(aplicar);bar.__amResizeObserver.observe(bar);
  }
  if(!window.__amActionbarResizeBound){window.addEventListener('resize',aplicar,{passive:true});window.__amActionbarResizeBound=true;}
 }
 function crearActionbar(){
  if(document.querySelector('.v129-actionbar'))return;
  const bar=document.createElement('div');bar.className='v129-actionbar v143-actionbar';
  bar.innerHTML='<div class="v129-action-status"><strong id="v129_action_modulo">'+nombreActivo()+'</strong><span id="v129_action_estado">Trabajá en el módulo y revisá el total cuando quieras.</span></div><div class="v143-progress" aria-label="Progreso de la cotización"><div class="v143-progress-top"><strong id="v143_progress_label">Inicio</strong><span id="v143_progress_percent">0%</span></div><div class="v143-progress-track"><span id="v143_progress_fill"></span></div><div class="v143-progress-steps"><button type="button" data-v143-step="datos"><span>1</span>Datos</button><button type="button" data-v143-step="modulos"><span>2</span>Módulos</button><button type="button" data-v143-step="calculos"><span>3</span>Cálculos</button><button type="button" data-v143-step="revisar"><span>4</span>Revisar</button></div></div><div class="v130-action-buttons"><button type="button" class="v129-btn-summary" aria-expanded="false">Ver resumen</button><button type="button" class="v129-btn-primary">Terminar y revisar</button></div>';
  const destino=document.querySelector('.form-container');
  if(destino){const subt=destino.querySelector('.cotizador-subtitulo'); if(subt&&subt.nextSibling){destino.insertBefore(bar,subt.nextSibling);}else{destino.insertBefore(bar,destino.firstChild);}}else{document.body.appendChild(bar);}
  bar.querySelector('.v129-btn-summary').addEventListener('click',()=>{
   const layout=document.getElementById('cotizador_layout');
   const estaOculto=!layout || layout.classList.contains('panel-presupuesto-oculto');
   if(typeof window.setPanelPresupuestoOculto==='function')window.setPanelPresupuestoOculto(!estaOculto,estaOculto);
  });
  bar.querySelector('.v129-btn-primary').addEventListener('click',()=>{
   const a=document.querySelector('.modulo-cotizador.activo .btn-finalizar'); if(a)a.click(); else if(typeof window.finalizarFlujoCotizacion==='function')window.finalizarFlujoCotizacion();
  });
  bar.querySelectorAll('[data-v143-step]').forEach(function(b){b.addEventListener('click',function(){
   const paso=this.dataset.v143Step;
   if(paso==='datos'){document.getElementById('datos_generales')?.scrollIntoView({behavior:'smooth',block:'start'});return;}
   if(paso==='modulos'){document.getElementById('navegacion_cotizacion')?.scrollIntoView({behavior:'smooth',block:'start'});return;}
   if(paso==='calculos'){const activo=document.querySelector('.nav-modulo.activo')?.dataset.modulo||'control';document.getElementById('modulo_'+activo)?.scrollIntoView({behavior:'smooth',block:'start'});return;}
   if(paso==='revisar'){if(typeof window.setPanelPresupuestoOculto==='function')window.setPanelPresupuestoOculto(false,true);}
  });});
 }
 function actualizarProgresoV143(){
  const clienteOk=Number(document.getElementById('id_cliente')?.value||0)>0;
  const listaOk=Number(document.getElementById('lista_id')?.value||0)>0;
  const equiposOk=Number(document.getElementById('cantidad_equipos')?.value||0)>0;
  const datosOk=clienteOk&&listaOk&&equiposOk;
  const modulos=['control','senalizacion','accesorios','iep','repuestos'];
  const incluidos=modulos.filter(m=>typeof moduloIncluido==='function'&&moduloIncluido(m));
  const modulosOk=incluidos.length>0;
  const calculosOk=modulosOk&&incluidos.every(m=>Number(window.subtotalesDocumento?.[m]||0)>0);
  const totalTexto=document.getElementById('resumen_total_general')?.textContent||'';
  const total=Number(totalTexto.replace(/[^0-9]/g,''))||0;
  const revisarOk=datosOk&&calculosOk&&total>0;
  const estados=[datosOk,modulosOk,calculosOk,revisarOk];
  const completados=estados.filter(Boolean).length;
  const porcentaje=Math.round((completados/4)*100);
  const fill=document.getElementById('v143_progress_fill');
  const percent=document.getElementById('v143_progress_percent');
  const label=document.getElementById('v143_progress_label');
  if(fill)fill.style.width=porcentaje+'%';
  if(percent)percent.textContent=porcentaje+'%';
  if(label){
    if(revisarOk) label.textContent='Listo para revisar';
    else if(calculosOk) label.textContent='Revisá el documento';
    else if(modulosOk) label.textContent='Completá los cálculos';
    else if(datosOk) label.textContent='Elegí los módulos';
    else label.textContent='Completá cliente y base';
  }
  document.querySelectorAll('[data-v143-step]').forEach((b,i)=>{
    b.classList.toggle('completo',!!estados[i]);
    b.classList.toggle('actual',!estados[i] && estados.slice(0,i).every(Boolean));
  });
 }
 function refrescar(){
  Object.keys(metas).forEach(m=>{
    const t=document.querySelector('[data-v129-status="'+m+'"]');if(t)t.textContent=estadoModulo(m);
    const p=document.querySelector('[data-v129-price="'+m+'"]');if(p)p.textContent=precioCabeceraModulo(m);
  });
  const am=document.querySelector('.nav-modulo.activo')?.dataset.modulo||'control';
  const d=metas[am]||metas.control, tit=document.getElementById('v129_action_modulo'), est=document.getElementById('v129_action_estado');
  if(tit)tit.textContent=d[1]; if(est)est.textContent=estadoModulo(am)+' · '+d[2];
  actualizarProgresoV143();
 }
 document.addEventListener('DOMContentLoaded',()=>{
  document.body.classList.add('ui-v129','ui-v130','ui-v131','ui-v133','ui-v134','ui-v135','ui-v136','ui-v137','ui-v138','ui-v139','ui-v143','ui-v144','ui-v145','ui-v146','ui-v147','ui-v148','ui-v149','ui-v152','ui-v153','ui-v154','ui-v155','ui-v156','ui-v157','ui-v158','ui-v159','ui-v160','ui-v161','ui-v162','ui-v163','ui-v164','ui-v165','ui-v166','ui-v167','ui-v169','ui-v170','ui-v171','ui-v173','ui-v174','ui-v182','ui-v183');crearCabeceras();prepararRepuestos();refrescar();
  document.querySelectorAll('#modulo_accesorios .accesorios-acordeon>.control-seccion').forEach(function(sec){
   sec.classList.add('cerrada');
   const cab=sec.querySelector('.control-seccion-titulo'); if(cab)cab.setAttribute('aria-expanded','false');
  });
  const parametrosInicioV172 = new URLSearchParams(window.location.search);
  const inicioNuevaV172 = parametrosInicioV172.get('inicio_nueva') === '1' || parametrosInicioV172.get('nueva') === '1';
  if(inicioNuevaV172){
   ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){
    if(typeof cerrarContenedoresModulo==='function') cerrarContenedoresModulo(m);
   });
   setTimeout(function(){ if(typeof posicionarModuloArriba==='function') posicionarModuloArriba('control','auto'); },80);
  }
  if(typeof window.setPanelPresupuestoOculto==='function')window.setPanelPresupuestoOculto(true);
  document.addEventListener('click',e=>{if(e.target.closest('.nav-modulo,.cotizador-modulo-btn,.btn-siguiente,.btn-finalizar'))setTimeout(refrescar,35);},true);
  document.addEventListener('change',()=>setTimeout(refrescar,20),true);
  document.addEventListener('input',()=>setTimeout(actualizarProgresoV143,30),true);
 });
 window.refrescarWorkbenchV129=refrescar;
 // Mantener los importes visibles sincronizados con los cálculos automáticos.
 if(typeof window.actualizarResumenDocumento==='function' && !window.__v320ResumenHook){
   const resumenOriginalV320=window.actualizarResumenDocumento;
   window.actualizarResumenDocumento=function(){
     const r=resumenOriginalV320.apply(this,arguments);
     setTimeout(refrescar,0);
     return r;
   };
   window.__v320ResumenHook=true;
 }
})();


/* ---- line-6121 (extraido de index.php, linea original 6121) ---- */
/* v152 - Alturas dinámicas para cabecera fija de datos + módulos. Solo layout. */
(function(){
  function medirCabecerasV152(){
    var datos=document.getElementById('datos_generales');
    var nav=document.getElementById('navegacion_cotizacion');
    var root=document.documentElement;
    if(datos) root.style.setProperty('--am152-data-h', Math.ceil(datos.getBoundingClientRect().height + 10) + 'px');
    if(nav) root.style.setProperty('--am152-nav-h', Math.ceil(nav.getBoundingClientRect().height + 10) + 'px');
  }
  document.addEventListener('DOMContentLoaded',function(){
    medirCabecerasV152();
    var datos=document.getElementById('datos_generales');
    var nav=document.getElementById('navegacion_cotizacion');
    if(window.ResizeObserver){
      var ro=new ResizeObserver(medirCabecerasV152);
      if(datos)ro.observe(datos);
      if(nav)ro.observe(nav);
    }
    window.addEventListener('resize',medirCabecerasV152,{passive:true});
    setTimeout(medirCabecerasV152,120);
  });
})();


/* ---- automac-v193-one-sheet-js (extraido de index.php, linea original 6148) ---- */
/* v194 - corrección de alto de módulos en la hoja única. No altera el modelo de datos. */
(function(){
 const nombres={control:'CONTROL',senalizacion:'SEÑALIZACIÓN',accesorios:'ACCESORIOS',iep:'IEP',repuestos:'REPUESTOS'};
 const resumenBase={
  control:'Control, equipos, puertas y adicionales',
  senalizacion:'Botonera de cabina, pulsadores e indicadores',
  accesorios:'Complementos y opcionales',
  iep:'Instalación eléctrica precableada',
  repuestos:'Repuestos seleccionados y adicionales'
 };
 function monedaDesdeResumen(mod){
  const r=document.getElementById('resumen_'+mod);
  const txt=r?.querySelector('[data-total]')?.textContent?.trim()||'';
  if(/^\$/.test(txt)) return txt;
  const val=Number(window.subtotalesDocumento?.[mod]||0);
  try{return val>0?monedaDocumento(val):'—';}catch(e){return val>0?'$ '+val.toLocaleString('es-AR'):'—';}
 }
 function resumenModulo(mod){
  const b=document.querySelector('.nav-modulo[data-modulo="'+mod+'"] [data-nav-estado]');
  const estado=b?.textContent?.trim()||'';
  if(estado && estado!=='NO INCLUIDO' && estado!=='PENDIENTE' && !estado.startsWith('CALCULADO')) return estado;
  if(estado==='NO INCLUIDO') return 'No incluido · '+resumenBase[mod];
  return resumenBase[mod];
 }
 function refrescarCabeceras(){
  Object.keys(nombres).forEach(function(mod){
   const sec=document.getElementById('modulo_'+mod); if(!sec)return;
   const rs=sec.querySelector('.v193-module-summary'); if(rs)rs.textContent=resumenModulo(mod);
   const rt=sec.querySelector('.v193-module-total'); if(rt)rt.textContent=monedaDesdeResumen(mod);
  });
 }
 function marcarActivo(mod){
  document.querySelectorAll('.cotizador-modulo-btn[data-modulo]').forEach(function(b){
   const on=b.dataset.modulo===mod;b.classList.toggle('activo',on);b.setAttribute('aria-selected',on?'true':'false');
  });
  document.querySelectorAll('.modulo-cotizador').forEach(function(m){m.classList.toggle('activo',m.id==='modulo_'+mod)});
  try{sessionStorage.setItem('cotizador_modulo_activo',mod)}catch(e){}
 }
 function fijarCuerpoModulo(sec,abierto){
  if(!sec)return;
  const body=sec.querySelector(':scope > .v193-module-body');
  if(!body)return;
  body.hidden=!abierto;
  body.setAttribute('aria-hidden',abierto?'false':'true');
  if(abierto){
   body.style.removeProperty('display');body.style.removeProperty('height');body.style.removeProperty('min-height');body.style.removeProperty('max-height');body.style.removeProperty('padding');body.style.removeProperty('margin');body.style.removeProperty('overflow');
  }else{
   body.style.setProperty('display','none','important');body.style.setProperty('height','0','important');body.style.setProperty('min-height','0','important');body.style.setProperty('max-height','0','important');body.style.setProperty('padding','0','important');body.style.setProperty('margin','0','important');body.style.setProperty('overflow','hidden','important');
  }
  sec.style.setProperty('height','auto','important');sec.style.setProperty('min-height','0','important');sec.style.setProperty('max-height','none','important');
  const ico=sec.querySelector(':scope > .v193-module-head .v193-module-toggle');if(ico)ico.textContent=abierto?'−':'+';
 }
 function abrirModulo(mod,scroll){
  const sec=document.getElementById('modulo_'+mod); if(!sec)return;
  sec.classList.remove('v193-module-collapsed');
  fijarCuerpoModulo(sec,true);
  sec.querySelector('.v193-module-head')?.setAttribute('aria-expanded','true');
  marcarActivo(mod);
  if(typeof window.mostrarModuloCotizador==='function') window.mostrarModuloCotizador(mod);
  if(scroll!==false) setTimeout(function(){sec.scrollIntoView({behavior:'smooth',block:'start'})},20);
 }
 function alternarModulo(sec){
  const mod=sec.id.replace('modulo_','');
  const vaCerrar=!sec.classList.contains('v193-module-collapsed');
  if(vaCerrar){sec.classList.add('v193-module-collapsed');fijarCuerpoModulo(sec,false);sec.querySelector('.v193-module-head')?.setAttribute('aria-expanded','false');}
  else abrirModulo(mod,false);
 }
 function construirHoja(){
  Object.keys(nombres).forEach(function(mod){
   const sec=document.getElementById('modulo_'+mod);if(!sec||sec.querySelector(':scope > .v193-module-head'))return;
   const body=document.createElement('div');body.className='v193-module-body';
   while(sec.firstChild) body.appendChild(sec.firstChild);
   const head=document.createElement('div');head.className='v193-module-head';head.setAttribute('role','button');head.setAttribute('tabindex','0');head.setAttribute('aria-expanded','false');
   head.innerHTML='<span class="v193-module-name">'+nombres[mod]+'</span><span class="v193-module-summary"></span><span class="v193-module-total">—</span><span class="v193-module-toggle" aria-hidden="true">+</span>';
   head.addEventListener('click',function(){alternarModulo(sec)});
   head.addEventListener('keydown',function(e){if(e.key==='Enter'||e.key===' '){e.preventDefault();alternarModulo(sec)}});
   sec.appendChild(head);sec.appendChild(body);
   sec.classList.add('v193-module-collapsed');fijarCuerpoModulo(sec,false);
  });
  // Nueva cotizacion: Control listo para empezar. En documentos existentes se abre el modulo recordado.
  let inicial='control';try{inicial=sessionStorage.getItem('cotizador_modulo_activo')||'control'}catch(e){}
  const objetivo=document.getElementById('modulo_'+inicial)||document.getElementById('modulo_control');
  if(objetivo){objetivo.classList.remove('v193-module-collapsed');fijarCuerpoModulo(objetivo,true);objetivo.querySelector('.v193-module-head')?.setAttribute('aria-expanded','true');marcarActivo(inicial)}
  refrescarCabeceras();
 }
 function enlazarIndice(){
  document.querySelectorAll('#navegacion_cotizacion .nav-modulo').forEach(function(btn){
   btn.addEventListener('click',function(e){
    const mod=btn.dataset.modulo;if(!mod)return;
    setTimeout(function(){abrirModulo(mod,true)},0);
   },true);
  });
 }
 function detectarTrabajo(){
  document.addEventListener('pointerdown',function(e){
   const sec=e.target.closest?.('.modulo-cotizador'); if(!sec||e.target.closest('.v193-module-head'))return;
   const mod=sec.id.replace('modulo_','');
   const activo=document.querySelector('.nav-modulo.activo')?.dataset.modulo;
   if(mod && mod!==activo && typeof window.mostrarModuloCotizador==='function') window.mostrarModuloCotizador(mod);
  },true);
 }
 function observarTotales(){
  const targets=['resumen_control','resumen_senalizacion','resumen_accesorios','resumen_iep','resumen_repuestos','resumen_total_general'].map(id=>document.getElementById(id)).filter(Boolean);
  if(!targets.length)return;
  const mo=new MutationObserver(function(){refrescarCabeceras()});targets.forEach(t=>mo.observe(t,{subtree:true,childList:true,characterData:true,attributes:true}));
 }
 document.addEventListener('DOMContentLoaded',function(){
  document.body.classList.add('ui-v193');
  construirHoja();enlazarIndice();detectarTrabajo();observarTotales();
  setTimeout(refrescarCabeceras,250);setTimeout(refrescarCabeceras,900);
  // El calculo auxiliar arranca cerrado y funciona como drawer.
  if(typeof window.setPanelPresupuestoOculto==='function') window.setPanelPresupuestoOculto(true);
 });
 window.abrirModuloV193=abrirModulo;
 window.refrescarCabecerasV193=refrescarCabeceras;
})();


/* ---- automac-v195-compact-rows-js (extraido de index.php, linea original 6267) ---- */
(function(){
 const familias=['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'];
 function pref(f){return 'senal_ext_'+String(f).toLowerCase()+'_';}
 function items(){try{const a=typeof window.itemsPulsadoresV188==='function'?window.itemsPulsadoresV188():[];return Array.isArray(a)?a:[]}catch(e){return []}}
 function resumenFamilia(f){
  const rows=items().filter(x=>x&&x.familia===f);
  if(!rows.length)return 'Sin configuraciones agregadas';
  const unidades=rows.reduce((t,x)=>t+(parseInt(x.cantidad||0,10)||0),0);
  if(rows.length===1){
   const it=rows[0],partes=[unidades+' u.',it.modelo||'',it.color||'',it.tension||'',it.bornes||'',it.indicador_modelo||''].filter(Boolean);
   return partes.join(' · ');
  }
  return rows.length+' configuraciones · '+unidades+' unidades';
 }
 function refrescar(){
  familias.forEach(function(f){
   const card=document.querySelector('.senal-pulsador-card-v179[data-familia="'+f+'"]');if(!card)return;
   let sum=card.querySelector('.v195-puls-summary');
   if(!sum){sum=document.createElement('span');sum.className='v195-puls-summary';const left=card.querySelector('.senal-pulsador-card-head-v179>div:first-child');left?.appendChild(sum)}
   if(sum)sum.textContent=resumenFamilia(f);
   const btn=card.querySelector('.btn-pulsador-agregar-v179');if(btn)btn.textContent=card.classList.contains('v195-row-collapsed')?'Configurar':'Agregar';
  });
 }
 function abrir(card){if(!card)return;card.classList.remove('v195-row-collapsed');refrescar()}
 function cerrar(card){if(!card)return;card.classList.add('v195-row-collapsed');refrescar()}
 function preparar(){
  document.querySelectorAll('.senal-pulsador-card-v179').forEach(function(card){
   card.classList.add('v195-row-collapsed');
   const head=card.querySelector('.senal-pulsador-card-head-v179');
   if(head&&!head.dataset.v195Bound){
    head.dataset.v195Bound='1';head.style.cursor='pointer';
    head.addEventListener('click',function(e){if(e.target.closest('button,input,select,label'))return;card.classList.toggle('v195-row-collapsed');refrescar()});
   }
  });
  refrescar();
 }
 document.addEventListener('click',function(e){
  const btn=e.target.closest('.btn-pulsador-agregar-v179');if(!btn)return;
  const card=btn.closest('.senal-pulsador-card-v179');
  if(card&&card.classList.contains('v195-row-collapsed')){e.preventDefault();e.stopImmediatePropagation();abrir(card);const first=card.querySelector('.senal-pulsador-editor-v179 input:not([type=hidden]),.senal-pulsador-editor-v179 select');setTimeout(()=>first?.focus(),0)}
 },true);
 document.addEventListener('DOMContentLoaded',function(){
  document.body.classList.add('ui-v195');document.body.classList.add('ui-v199');document.body.classList.add('ui-v201');preparar();
  const wrap=function(name,after){const orig=window[name];if(typeof orig!=='function'||orig.__v195)return;const fn=function(){const r=orig.apply(this,arguments);try{after.apply(this,arguments)}catch(e){}return r};fn.__v195=true;window[name]=fn};
  wrap('agregarPulsadorExteriorV179',function(f){const c=document.querySelector('.senal-pulsador-card-v179[data-familia="'+f+'"]');cerrar(c);setTimeout(refrescar,30)});
  wrap('modificarPulsadorExteriorV179',function(f){const c=document.querySelector('.senal-pulsador-card-v179[data-familia="'+f+'"]');cerrar(c);setTimeout(refrescar,30)});
  wrap('quitarPulsadorExteriorV179',function(){setTimeout(refrescar,30)});
  wrap('editarItemPulsadorV188',function(f){const c=document.querySelector('.senal-pulsador-card-v179[data-familia="'+f+'"]');abrir(c)});
  const h=document.getElementById('senal_pulsadores_items_json');if(h)new MutationObserver(refrescar).observe(h,{attributes:true,attributeFilter:['value']});
  setTimeout(refrescar,250);setTimeout(refrescar,900);
 });
 window.refrescarFilasPulsadoresV195=refrescar;
})();


/* ---- automac-v302-js (extraido de index.php, linea original 6327) ---- */
(function(){
  // V1.5: the new workspace owns layout; do not build the retired panels.
  if(document.body.classList.contains('v15-workbench')) return;

  function desktop(){ return window.innerWidth>=980; }
  function prepararV302(){
    if(!desktop()) return;
    document.body.classList.add('ui-v302');
    var layout=document.getElementById('cotizador_layout');
    var form=document.querySelector('.form-container');
    var nav=document.getElementById('navegacion_cotizacion');
    var panel=document.getElementById('panel_calculo_cotizador');
    if(!layout||!form||!nav||!panel) return;

    var work=document.getElementById('v302_workspace');
    var board=document.getElementById('v302_board');
    if(!work){
      work=document.createElement('div'); work.id='v302_workspace';
      board=document.createElement('div'); board.id='v302_board';
      work.appendChild(board);
      work.appendChild(panel);
      form.parentNode.insertBefore(work,form);
    }
    ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(n){
      var m=document.getElementById('modulo_'+n); if(!m) return;
      if(m.parentNode!==board) board.appendChild(m);
      m.classList.add('activo'); m.classList.remove('v193-module-collapsed');
      /* v311: una vez que la UI compacta administra las solapas, no volver a forzar
         display:block sobre todos los modulos. Ese comportamiento hacía reaparecer
         IEP y Repuestos debajo de la cotizacion diaria. */
      if(!document.body.classList.contains('ui-v310') && !document.body.classList.contains('ui-v311')){
        m.style.setProperty('display','block','important');
      }
      var b=m.querySelector(':scope > .v193-module-body'); if(b){ b.style.setProperty('display','block','important'); b.style.removeProperty('height'); b.style.removeProperty('max-height'); }
    });
    panel.classList.remove('panel-presupuesto-oculto'); panel.setAttribute('aria-hidden','false');

    /* Los acordeones dejan de ser obstaculo visual en escritorio. */
    document.querySelectorAll('#modulo_control .control-seccion.cerrada').forEach(function(x){x.classList.remove('cerrada')});
    document.querySelectorAll('#modulo_senalizacion .senal-macro-cerrado').forEach(function(x){x.classList.remove('senal-macro-cerrado')});
    document.querySelectorAll('#modulo_senalizacion .senal-acordeon-cerrado').forEach(function(x){x.classList.remove('senal-acordeon-cerrado')});

    /* Banners de revision no se pierden: pasan al resumen. */
    form.querySelectorAll(':scope > .revision-banner-ui').forEach(function(x){
      var slot=document.getElementById('revision_editor_slot');
      if(slot && x.parentNode!==slot) slot.appendChild(x);
    });
    form.classList.add('v302-vacio');
    ajustarAlto();
  }
  function ajustarAlto(){
    if(!desktop()) return;
    var w=document.getElementById('v302_workspace'); if(!w) return;
    var top=Math.round(w.getBoundingClientRect().top);
    w.style.height=Math.max(420,window.innerHeight-top-8)+'px';
  }
  function mantenerVisible(){
    if(!desktop()) return;
    if(document.body.classList.contains('ui-v310')) return;
    ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(n){var m=document.getElementById('modulo_'+n);if(m){m.classList.add('activo');m.style.setProperty('display','block','important')}});
  }
  function init(){prepararV302();requestAnimationFrame(ajustarAlto);setTimeout(function(){prepararV302();ajustarAlto()},180);setTimeout(function(){mantenerVisible();ajustarAlto()},800)}
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',init); else init();
  window.addEventListener('load',init); window.addEventListener('resize',function(){if(desktop()){init()}else{document.body.classList.remove('ui-v302')}});
  document.addEventListener('click',function(e){if(desktop()&&e.target.closest('.nav-modulo,.cotizador-modulo-btn,.control-seccion-titulo,.senal-seccion-titulo,.senal-macro-toggle')) setTimeout(mantenerVisible,30)},true);
})();


/* ---- automac-v303-js (extraido de index.php, linea original 6396) ---- */
(function(){
  // V1.5: the new workspace owns layout; do not build the retired panels.
  if(document.body.classList.contains('v15-workbench')) return;

 function activar(){
   if(window.innerWidth<1280){document.body.classList.remove('ui-v303');return;}
   document.body.classList.add('ui-v303');
   var layout=document.getElementById('cotizador_layout'); if(layout){layout.style.setProperty('display','block','important');layout.style.setProperty('grid-template-columns','none','important');}
   if(typeof window.refrescarFilasPulsadoresV195==='function'){try{window.refrescarFilasPulsadoresV195()}catch(e){}}
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',activar);else activar();
 window.addEventListener('load',activar);window.addEventListener('resize',activar);
})();


/* ---- automac-v304-js (extraido de index.php, linea original 6412) ---- */
(function(){
  // V1.5: the new workspace owns layout; do not build the retired panels.
  if(document.body.classList.contains('v15-workbench')) return;

  var defs=[
    ['control','1','CONTROL'],
    ['senalizacion','2','SEÑALIZACIÓN'],
    ['accesorios','3','ACCESORIOS'],
    ['iep','4','IEP'],
    ['repuestos','5','REPUESTOS']
  ];
  function instalar(){
    if(window.innerWidth<1280){document.body.classList.remove('ui-v304');return;}
    document.body.classList.add('ui-v304');
    defs.forEach(function(d){
      var mod=document.getElementById('modulo_'+d[0]);
      if(!mod || mod.querySelector(':scope > .v304-module-header')) return;
      var h=document.createElement('div');
      h.className='v304-module-header';
      h.setAttribute('data-modulo',d[0]);
      h.innerHTML='<span class="v304-num">'+d[1]+'</span><span class="v304-title">'+d[2]+'</span><span class="v304-line" aria-hidden="true"></span>';
      mod.insertBefore(h,mod.firstChild);
    });
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',instalar);else instalar();
  window.addEventListener('load',instalar);
  window.addEventListener('resize',instalar);
})();


/* ---- automac-v307-js (extraido de index.php, linea original 6448) ---- */
(function(){
  // V1.5: the new workspace owns layout; do not build the retired panels.
  if(document.body.classList.contains('v15-workbench')) return;

 var moduleNames=['control','senalizacion','accesorios','iep','repuestos'];
 var labels={control:'Control',senalizacion:'Señalización',accesorios:'Accesorios',iep:'IEP',repuestos:'Repuestos'};
 function desktop(){return window.innerWidth>=980;}
 function crearResumen(){
   if(!desktop()) return;
   document.body.classList.add('ui-v307');
   var work=document.getElementById('v302_workspace'); if(!work) return;
   var r=document.getElementById('v307_resumen');
   if(!r){
     r=document.createElement('aside'); r.id='v307_resumen'; r.setAttribute('aria-label','Resumen de cotización');
     r.innerHTML='<div class="v307-resumen-head"><span>6</span>RESUMEN</div>'+moduleNames.map(function(m){return '<div class="v307-fila" data-v307-mod="'+m+'"><span>'+labels[m]+'</span><b>—</b></div>'}).join('')+'<div class="v307-total"><span>TOTAL GENERAL</span><b id="v307_total">$ 0,00</b></div><div class="v307-acciones"></div>';
   }
   if(r.parentNode!==work) work.appendChild(r);
   sincronizarResumen();
 }
 function sincronizarResumen(){
   var r=document.getElementById('v307_resumen'); if(!r)return;
   moduleNames.forEach(function(m){
     var src=document.querySelector('#resumen_'+m+' [data-total]');
     var dst=r.querySelector('[data-v307-mod="'+m+'"] b');
     if(src&&dst){dst.textContent=src.textContent.trim();}
   });
   var total=document.getElementById('resumen_total_general'), dstTotal=document.getElementById('v307_total');
   if(total&&dstTotal)dstTotal.textContent=total.textContent.trim();
   var actions=r.querySelector('.v307-acciones');
   if(actions && !actions.children.length){
     var old=document.querySelector('#panel_calculo_cotizador .resumen-acciones');
     if(old){
       old.querySelectorAll('button').forEach(function(b,i){
         if(i>2)return;
         var c=document.createElement('button'); c.type='button'; c.textContent=b.textContent.trim();
         c.style.background=getComputedStyle(b).backgroundColor||'#176b45';
         c.addEventListener('click',function(){b.click()}); actions.appendChild(c);
       });
     }
   }
 }
 function prepararRepuestos(){
   if(!desktop())return;
   var box=document.getElementById('resultados_repuestos'), input=document.getElementById('buscar_repuesto');
   if(!box||!input)return;
   if(box.parentNode!==document.body) document.body.appendChild(box);
   box.classList.add('v307-floating');
   function pos(){
     if(getComputedStyle(box).display==='none')return;
     var a=input.getBoundingClientRect();
     var w=Math.min(760,window.innerWidth-24);
     var left=Math.min(Math.max(12,a.right-w),window.innerWidth-w-12);
     var top=a.bottom+5;
     if(top+Math.min(430,window.innerHeight*.55)>window.innerHeight-8) top=Math.max(8,a.top-Math.min(430,window.innerHeight*.55)-5);
     box.style.setProperty('left',left+'px','important'); box.style.setProperty('top',top+'px','important');
   }
   input.addEventListener('focus',function(){setTimeout(pos,20)});
   input.addEventListener('input',function(){setTimeout(pos,30)});
   var cat=document.getElementById('categoria_repuesto'); if(cat)cat.addEventListener('change',function(){setTimeout(pos,30)});
   window.addEventListener('resize',pos); document.addEventListener('scroll',pos,true);
   if(window.MutationObserver && !box.dataset.v307obs){
     box.dataset.v307obs='1'; new MutationObserver(function(){requestAnimationFrame(pos)}).observe(box,{attributes:true,attributeFilter:['style','class'],childList:true,subtree:true});
   }
 }
 function init(){
   if(!desktop()){document.body.classList.remove('ui-v307');return;}
   crearResumen(); prepararRepuestos(); sincronizarResumen();
   var old=document.getElementById('panel_calculo_cotizador');
   if(old && window.MutationObserver && !old.dataset.v307sync){old.dataset.v307sync='1';new MutationObserver(function(){requestAnimationFrame(sincronizarResumen)}).observe(old,{subtree:true,childList:true,characterData:true,attributes:true});}
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
 window.addEventListener('load',function(){init();setTimeout(init,300);setTimeout(init,1000)});
 window.addEventListener('resize',init);
})();


/* ---- automac-v501-js (un modulo por vez + navegacion V1 + scroll normal) ---- */
(function(){
  // V1.5: the new workspace owns layout; do not build the retired panels.
  if(document.body.classList.contains('v15-workbench')) return;

  var resumenAbierto=false;
  var moduloActivo='control';
  function desktop(){return window.innerWidth>=980;}
  function mod(nombre){return document.getElementById('modulo_'+nombre);}
  function leerModuloActivo(){
    var b=document.querySelector('#navegacion_cotizacion .cotizador-modulo-btn.activo[data-modulo]');
    if(b&&b.dataset.modulo)return b.dataset.modulo;
    try{return sessionStorage.getItem('cotizador_modulo_activo')||'control';}catch(e){return 'control';}
  }
  function asegurarNavegacion(){
    if(!desktop())return;
    var nav=document.getElementById('navegacion_cotizacion');
    var work=document.getElementById('v302_workspace');
    if(!nav||!work)return;
    if(nav.nextElementSibling!==work && work.parentNode){
      work.parentNode.insertBefore(nav,work);
    }
    nav.style.setProperty('display','block','important');
    nav.style.setProperty('visibility','visible','important');
    nav.style.setProperty('height','auto','important');
    nav.style.setProperty('overflow','visible','important');
    var lista=nav.querySelector('.navegacion-cotizacion-lista');
    if(lista){
      lista.style.setProperty('display','grid','important');
      lista.style.setProperty('grid-template-columns','repeat(5,minmax(0,1fr))','important');
      lista.style.setProperty('visibility','visible','important');
      lista.style.setProperty('height','auto','important');
    }
    nav.querySelectorAll('.cotizador-modulo-btn').forEach(function(b){
      b.style.setProperty('display','flex','important');
      b.style.setProperty('visibility','visible','important');
      b.style.setProperty('pointer-events','auto','important');
    });
  }
  function liberarScroll(){
    if(!desktop())return;
    document.documentElement.style.setProperty('overflow-y','auto','important');
    // UI-02: scrolling belongs to the document so the module bar can stay sticky.
    var scrollV15=document.body.classList.contains('ui-v150');
    document.body.style.setProperty('overflow-y',scrollV15?'visible':'auto','important');
    document.body.style.setProperty('overflow-x',scrollV15?'visible':'hidden','important');
    var work=document.getElementById('v302_workspace');
    var board=document.getElementById('v302_board');
    if(work){
      work.style.setProperty('height','auto','important');
      work.style.setProperty('max-height','none','important');
      work.style.setProperty('min-height','0','important');
      work.style.setProperty('overflow','visible','important');
    }
    if(board){
      board.style.setProperty('height','auto','important');
      board.style.setProperty('max-height','none','important');
      board.style.setProperty('overflow','visible','important');
    }
    var activo=mod(moduloActivo);
    if(activo){
      activo.style.setProperty('height','auto','important');
      activo.style.setProperty('max-height','none','important');
      activo.style.setProperty('overflow','visible','important');
    }
  }
  function aplicarModulo(nombre){
    if(!desktop())return;
    if(['control','senalizacion','accesorios','iep','repuestos'].indexOf(nombre)<0)nombre='control';
    moduloActivo=nombre;
    var board=document.getElementById('v302_board');if(!board)return;
    document.body.classList.add('ui-v310','ui-v311','ui-v500-modulo-unico','ui-v501-scroll-normal');
    document.body.classList.remove('v311-principal','v311-opcionales');
    board.classList.remove('v310-opcionales');
    ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(n){
      var e=mod(n);if(!e)return;
      var visible=n===nombre;
      e.style.setProperty('display',visible?'block':'none','important');
      e.style.setProperty('visibility',visible?'visible':'hidden','important');
      e.setAttribute('aria-hidden',visible?'false':'true');
      e.classList.toggle('activo',visible);
      var body=e.querySelector(':scope > .v193-module-body');
      if(body&&visible){
        body.hidden=false;
        body.setAttribute('aria-hidden','false');
        body.style.setProperty('display','block','important');
        body.style.setProperty('height','auto','important');
        body.style.setProperty('max-height','none','important');
        body.style.setProperty('overflow','visible','important');
      }
    });
    document.querySelectorAll('#navegacion_cotizacion .cotizador-modulo-btn[data-modulo]').forEach(function(b){
      var on=b.dataset.modulo===nombre;
      b.classList.toggle('activo',on);
      b.setAttribute('aria-selected',on?'true':'false');
    });
    try{sessionStorage.setItem('cotizador_modulo_activo',nombre);}catch(e){}
    asegurarNavegacion();
    liberarScroll();
  }
  function aplicarResumen(){
    if(!desktop())return;
    document.body.classList.toggle('v310-resumen-abierto',resumenAbierto);
    var b=document.getElementById('v310_resumen_toggle');
    if(b){b.textContent=resumenAbierto?'Ocultar resumen':'Mostrar resumen';b.setAttribute('aria-expanded',resumenAbierto?'true':'false');}
    liberarScroll();
  }
  function crearUI(){
    if(!desktop())return;
    var work=document.getElementById('v302_workspace');if(!work)return;
    document.body.classList.add('ui-v310','ui-v311','ui-v500-modulo-unico','ui-v501-scroll-normal');
    var tabs=document.getElementById('v310_tabs');if(tabs)tabs.remove();
    var toggle=document.getElementById('v310_resumen_toggle');
    if(!toggle){
      toggle=document.createElement('button');toggle.type='button';toggle.id='v310_resumen_toggle';toggle.setAttribute('aria-controls','v307_resumen');
      toggle.addEventListener('click',function(e){e.preventDefault();e.stopPropagation();resumenAbierto=!resumenAbierto;aplicarResumen();});
      document.body.appendChild(toggle);
    }
    asegurarNavegacion();
    moduloActivo=leerModuloActivo();
    aplicarModulo(moduloActivo);
    aplicarResumen();
    liberarScroll();
  }
  function enlazarNavegacion(){
    var nav=document.getElementById('navegacion_cotizacion');if(!nav||nav.dataset.v501Bound)return;
    nav.dataset.v501Bound='1';
    nav.addEventListener('click',function(e){
      var b=e.target.closest&&e.target.closest('.cotizador-modulo-btn[data-modulo]');if(!b)return;
      e.preventDefault();
      var nombre=b.dataset.modulo||'control';
      aplicarModulo(nombre);
    },false);
  }
  function envolverMostrarModulo(){
    if(typeof window.mostrarModuloCotizador!=='function'||window.mostrarModuloCotizador.__v501)return;
    var original=window.mostrarModuloCotizador;
    var envuelta=function(nombre){
      var r=original.apply(this,arguments);
      if(desktop())aplicarModulo(nombre);
      return r;
    };
    envuelta.__v501=true;
    window.mostrarModuloCotizador=envuelta;
  }
  function reafirmar(){
    if(!desktop())return;
    asegurarNavegacion();
    aplicarModulo(leerModuloActivo());
    liberarScroll();
  }
  function init(){
    if(!desktop()){
      document.body.classList.remove('ui-v500-modulo-unico','ui-v501-scroll-normal');
      return;
    }
    crearUI();enlazarNavegacion();envolverMostrarModulo();
    setTimeout(reafirmar,220);
    setTimeout(reafirmar,900);
    setTimeout(reafirmar,1500);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('load',init);
  window.addEventListener('resize',function(){if(desktop())reafirmar();else document.body.classList.remove('ui-v500-modulo-unico','ui-v501-scroll-normal');});
})();


/* ---- automac-v312-js (extraido de index.php, linea original 6617) ---- */
(function(){
  function sincronizarResumenCompacto(){
    try{ if(typeof actualizarResumenDocumento==='function') actualizarResumenDocumento(); }catch(e){}
    setTimeout(function(){
      try{
        var r=document.getElementById('v307_resumen');
        if(!r)return;
        ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){
          var src=document.querySelector('#resumen_'+m+' [data-total]');
          var dst=r.querySelector('[data-v307-mod="'+m+'"] b');
          if(src&&dst)dst.textContent=src.textContent.trim();
        });
        var srcT=document.getElementById('resumen_total_general'), dstT=document.getElementById('v307_total');
        if(srcT&&dstT)dstT.textContent=srcT.textContent.trim();
      }catch(e){}
    },120);
  }
  window.v313SincronizarResumenCompacto=sincronizarResumenCompacto;
  async function calcularControlManual(){
    var inc=document.getElementById('incluir_control');
    if(inc && !inc.checked){inc.checked=true;inc.dispatchEvent(new Event('change',{bubbles:true}));}
    var b=document.getElementById('v312_calcular_control');
    if(b){b.disabled=true;b.textContent='CALCULANDO...';}
    try{
      await ejecutarCalculoTiempoReal();
      sincronizarResumenCompacto();
      setTimeout(sincronizarResumenCompacto,150);
      setTimeout(sincronizarResumenCompacto,500);
    }finally{
      if(b){b.disabled=false;b.textContent='CALCULAR CONTROL';}
    }
  }
  function crearBoton(){
    if(window.innerWidth<1280)return;
    var mod=document.getElementById('modulo_control');if(!mod)return;
    var b=document.getElementById('v312_calcular_control');
    if(!b){b=document.createElement('button');b.type='button';b.id='v312_calcular_control';b.textContent='CALCULAR CONTROL';b.addEventListener('click',calcularControlManual);mod.appendChild(b);}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',crearBoton);else crearBoton();
  window.addEventListener('load',crearBoton);
})();


/* ---- automac-v317-js (extraido de index.php, linea original 6665) ---- */
(function(){
  var timerAuto=null;
  var calculando=false;

  function limpiarAvisoClienteDeCalculo(){
    var aviso=document.getElementById('aviso_cliente_obligatorio');
    var cliente=document.getElementById('id_cliente');
    var buscador=document.getElementById('cliente_busqueda');
    var campo=buscador ? buscador.closest('.campo') : (cliente ? cliente.closest('.campo') : null);
    /* El cliente solo es obligatorio al emitir. No mostrar advertencias mientras se calcula. */
    if(aviso){
      aviso.classList.remove('visible','v314-calculo-cliente');
      var texto=aviso.querySelector('div');
      if(texto) texto.innerHTML='<strong>Seleccione un cliente para continuar</strong>La cotización o el pedido no se emitirán hasta elegir un cliente. La configuración cargada permanece en pantalla.';
    }
    if(campo) campo.classList.remove('v314-cliente-pendiente','campo-cliente-error');
    if(cliente) cliente.setCustomValidity('');
  }

  async function recalcularAhora(){
    if(calculando) return;
    var inc=document.getElementById('incluir_control');
    if(inc && !inc.checked) return;
    if(typeof formularioListoParaCalcular==='function'){
      var form=document.getElementById('form_cotizador');
      if(!formularioListoParaCalcular(form)) return;
    }
    calculando=true;
    try{
      if(typeof ejecutarCalculoTiempoReal==='function') await ejecutarCalculoTiempoReal();
      if(typeof window.v313SincronizarResumenCompacto==='function'){
        window.v313SincronizarResumenCompacto();
        setTimeout(window.v313SincronizarResumenCompacto,160);
      }
    }catch(e){
      if(e && e.name!=='AbortError') console.error('AUTOMAC v317 cálculo automático:',e);
    }finally{
      calculando=false;
    }
  }

  function programarAuto(ms){
    clearTimeout(timerAuto);
    limpiarAvisoClienteDeCalculo();
    timerAuto=setTimeout(recalcularAhora, Math.max(80, Number(ms)||180));
  }

  function instalar(){
    var modulo=document.getElementById('modulo_control');
    if(!modulo) return;
    limpiarAvisoClienteDeCalculo();

    var btn=document.getElementById('v312_calcular_control');
    if(btn) btn.style.display='none';

    if(!modulo.dataset.v317AutoCalc){
      modulo.dataset.v317AutoCalc='1';
      ['input','change'].forEach(function(tipo){
        modulo.addEventListener(tipo,function(ev){
          var t=ev.target;
          if(!t || t.matches('[data-excluir-calculo-auxiliar="1"]')) return;
          programarAuto(tipo==='input'?220:100);
        },true);
      });
    }

    /* Cambiar cliente puede afectar condiciones comerciales, pero nunca habilita/bloquea el cálculo. */
    var cliente=document.getElementById('id_cliente');
    if(cliente && !cliente.dataset.v317Cliente){
      cliente.dataset.v317Cliente='1';
      var ultimo=cliente.value;
      setInterval(function(){
        if(cliente.value!==ultimo){
          ultimo=cliente.value;
          limpiarAvisoClienteDeCalculo();
          programarAuto(80);
        }
      },220);
    }

    /* Al entrar, calcular apenas estén completos los datos técnicos mínimos. */
    programarAuto(260);
  }

  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',instalar);
  else instalar();
  window.addEventListener('load',instalar);
})();


/* ---- line-6757 (extraido de index.php, linea original 6757) ---- */
window.COTIZADOR_CALCULO_PARALELO_VERSION='v323';
