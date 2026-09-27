/* AUTOMAC v431 - JavaScript modularizado. Mantiene nombres globales y orden original. */

/* ---- automac-v325-selector-adicionales-js (extraido de index.php, linea original 6760) ---- */
(function(){
  function txt(el){return String(el&&el.textContent||'').replace(/\s+/g,' ').trim();}
  function fire(el){
    if(!el)return;
    el.dispatchEvent(new Event('input',{bubbles:true}));
    el.dispatchEvent(new Event('change',{bubbles:true}));
  }
  function ensurePanel(body, kind){
    var id='v325_'+kind+'_panel';
    var p=document.getElementById(id);
    if(p)return p;
    p=document.createElement('div'); p.id=id; p.className='v325-add-panel';
    p.innerHTML='<div class="v325-add-row"><div><label>'+(kind==='control'?'Adicional a cotizar':'Adicional de señalización')+'</label><select id="v325_'+kind+'_select"><option value="">Seleccione...</option></select></div><button type="button" id="v325_'+kind+'_add">Agregar</button></div><div class="v325-selected"><div class="v325-selected-head">Seleccionados</div><div id="v325_'+kind+'_selected"></div></div>';
    body.insertBefore(p,body.firstChild);
    document.getElementById('v325_'+kind+'_add').addEventListener('click',function(){addSelected(kind)});
    return p;
  }
  function getSources(kind){
    if(kind==='control') return Array.from(document.querySelectorAll('#control_paso_adicionales .control-check-item')).filter(function(card){return !!card.querySelector('input[type=checkbox]');});
    return Array.from(document.querySelectorAll('#senal_paso_adicionales_cuerpo .senal-adic-compacto')).filter(function(card){return !!card.querySelector(':scope > input[type=checkbox]');});
  }
  function labelFor(kind,card){
    if(kind==='control') return txt(card.querySelector('.checkbox-label strong')) || txt(card.querySelector('.checkbox-label'));
    var lab=card.querySelector(':scope > label');
    return txt(lab && lab.childNodes[0] ? {textContent:lab.childNodes[0].textContent}:lab) || txt(lab);
  }
  function keyFor(kind,card,idx){
    var cb=card.querySelector('input[type=checkbox]');
    return cb ? (cb.name||cb.id||kind+'_'+idx) : kind+'_'+idx;
  }
  function isApplicable(card){
    if(card.style && card.style.display==='none') return false;
    var id=card.id||'';
    if(id && getComputedStyle(card).display==='none') return false;
    return true;
  }
  function refreshOptions(kind){
    var sel=document.getElementById('v325_'+kind+'_select'); if(!sel)return;
    var old=sel.value;
    sel.innerHTML='<option value="">Seleccione...</option>';
    getSources(kind).forEach(function(card,idx){
      card.classList.add('v325-source');
      card.dataset.v325Key=keyFor(kind,card,idx);
      card.dataset.v325Label=labelFor(kind,card);
      var cb=card.querySelector('input[type=checkbox]');
      if(!cb || cb.checked || !isApplicable(card)) return;
      var op=document.createElement('option');op.value=card.dataset.v325Key;op.textContent=card.dataset.v325Label;sel.appendChild(op);
    });
    if(Array.from(sel.options).some(function(o){return o.value===old}))sel.value=old;
  }
  function selectedCards(kind){
    return getSources(kind).filter(function(card){var cb=card.querySelector('input[type=checkbox]');return cb&&cb.checked;});
  }
  function refreshSelected(kind){
    var wrap=document.getElementById('v325_'+kind+'_selected');if(!wrap)return;
    wrap.innerHTML='';
    var cards=selectedCards(kind);
    if(!cards.length){var e=document.createElement('div');e.className='v325-selected-empty';e.textContent='Todavía no hay adicionales seleccionados.';wrap.appendChild(e);}
    cards.forEach(function(card){
      var row=document.createElement('div');row.className='v325-selected-row';
      var info=document.createElement('div');var strong=document.createElement('strong');strong.textContent=card.dataset.v325Label||labelFor(kind,card);info.appendChild(strong);
      if(kind==='senal'){var d=card.querySelector('label small');if(d){var sm=document.createElement('small');sm.textContent=txt(d);info.appendChild(sm);}}
      var acts=document.createElement('div');acts.className='v325-selected-actions';
      if(kind==='control' || (kind==='senal' && (card.querySelector('input[type=number],select,input[type=text]')))){
        var edit=document.createElement('button');edit.type='button';edit.textContent='Modificar';edit.onclick=function(){
          card.classList.add('v325-config-visible');
          card.scrollIntoView({behavior:'smooth',block:'center'});
        };acts.appendChild(edit);
      }
      var rem=document.createElement('button');rem.type='button';rem.className='v325-remove';rem.textContent='Quitar';rem.onclick=function(){var cb=card.querySelector('input[type=checkbox]');cb.checked=false;card.classList.remove('v325-config-visible');fire(cb);setTimeout(function(){refresh(kind)},40);};acts.appendChild(rem);
      row.appendChild(info);row.appendChild(acts);wrap.appendChild(row);
      if(kind==='senal'){
        var hasConfig=!!card.querySelector('input[type=number],select,input[type=text]');
        card.classList.toggle('v325-config-visible',hasConfig);
      }
    });
    refreshOptions(kind);
  }
  function addSelected(kind){
    var sel=document.getElementById('v325_'+kind+'_select');if(!sel||!sel.value)return;
    var card=getSources(kind).find(function(c){return c.dataset.v325Key===sel.value;});if(!card)return;
    var cb=card.querySelector('input[type=checkbox]');if(!cb)return;
    cb.checked=true;fire(cb);
    if(kind==='senal' && card.querySelector('input[type=number],select,input[type=text]')) card.classList.add('v325-config-visible');
    refresh(kind);
    if(kind==='senal' && card.classList.contains('v325-config-visible'))setTimeout(function(){card.scrollIntoView({behavior:'smooth',block:'center'});},60);
  }
  function refresh(kind){refreshSelected(kind);}
  function installKind(kind){
    var body=kind==='control'?document.querySelector('#control_paso_adicionales .control-seccion-cuerpo'):document.getElementById('senal_paso_adicionales_cuerpo');
    if(!body)return;
    ensurePanel(body,kind);
    getSources(kind).forEach(function(card,idx){card.classList.add('v325-source');card.dataset.v325Key=keyFor(kind,card,idx);card.dataset.v325Label=labelFor(kind,card);});
    refresh(kind);
    if(!body.dataset.v325Events){
      body.dataset.v325Events='1';
      body.addEventListener('change',function(ev){if(kind==='senal' && body.dataset.v348)return;if(ev.target&&ev.target.matches('input[type=checkbox]'))setTimeout(function(){refresh(kind)},25);},true);
    }
  }
  function install(){installKind('control');installKind('senal');
    var c=document.getElementById('modulo_control');if(c&&!c.dataset.v325Refresh){c.dataset.v325Refresh='1';c.addEventListener('change',function(){setTimeout(function(){refreshOptions('control')},80)},true);}
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',install);else install();
  window.addEventListener('load',install);
})();


/* ---- automac-v342-botonera-flujo-js (extraido de index.php, linea original 6870) ---- */
(function(){
  function txt(el){return String(el&&el.textContent||'').replace(/\s+/g,' ').trim();}
  function fire(el){if(!el)return;el.dispatchEvent(new Event('input',{bubbles:true}));el.dispatchEvent(new Event('change',{bubbles:true}));}
  function cards(){return Array.from(document.querySelectorAll('#senal_paso_adicionales_cuerpo .senal-adic-compacto')).filter(function(c){return !!c.querySelector(':scope > input[type=checkbox]');});}
  function labelCard(card){var lab=card.querySelector(':scope > label');if(!lab)return 'Accesorio';var c=lab.cloneNode(true);c.querySelectorAll('small').forEach(function(x){x.remove();});return txt(c);}
  function keyCard(card,i){var cb=card.querySelector(':scope > input[type=checkbox]');return cb?(cb.name||cb.id||('adic_'+i)):('adic_'+i);}
  function active(card){var cb=card.querySelector(':scope > input[type=checkbox]');return !!(cb&&cb.checked);}
  function openSection(sec){if(!sec)return;sec.classList.remove('senal-acordeon-cerrado','v327-hidden','v327-no-items');var h=sec.querySelector('.senal-seccion-titulo');if(h){h.classList.remove('is-collapsed');h.setAttribute('aria-expanded','true');}}
  function prepareFlow(){
    var macro=document.getElementById('senal_contenedor_botonera_cabina');if(macro){macro.classList.remove('senal-macro-cerrado');var mh=macro.querySelector('.senal-contenedor-macro-cabecera');if(mh)mh.setAttribute('aria-expanded','true');}
    openSection(document.getElementById('senal_paso_base'));
    openSection(document.getElementById('senal_paso_indicador'));
    var base=document.getElementById('senal_paso_base');
    if(base&&!document.getElementById('v342_botonera_flow')){
      var flow=document.createElement('div');flow.id='v342_botonera_flow';flow.innerHTML='<div><span>1</span><strong>Base</strong><small>Pulsador y configuración</small></div><div><span>2</span><strong>Indicador</strong><small>Modelo y cantidad</small></div><div><span>3</span><strong>Accesorios</strong><small>Solo lo que necesitás</small></div>';
      base.parentNode.insertBefore(flow,base);
    }
  }
  function ensurePanel(){
    var ind=document.getElementById('senal_paso_indicador');if(!ind)return;
    var p=document.getElementById('v327_botonera_panel');
    if(!p){p=document.createElement('div');p.id='v327_botonera_panel';}
    p.innerHTML='<div class="v327-head"><div><strong>3. Accesorios de botonera</strong><small>Agregá únicamente los opcionales que lleva esta botonera.</small></div></div><div class="v327-add"><div><label for="v327_botonera_select">Accesorio a cotizar</label><select id="v327_botonera_select"><option value="">Seleccione...</option></select></div><button type="button" id="v327_botonera_add">Agregar</button></div><div id="v327_botonera_selected"><div class="v327-selected-head">Accesorios seleccionados</div><div id="v327_botonera_rows"></div></div>';
    ind.parentNode.insertBefore(p,ind.nextSibling);
    document.getElementById('v327_botonera_add').addEventListener('click',add);
    var old=document.getElementById('v325_senal_panel');if(old)old.style.display='none';
  }
  function options(){
    var sel=document.getElementById('v327_botonera_select');if(!sel)return;var old=sel.value;sel.innerHTML='<option value="">Seleccione...</option>';
    cards().forEach(function(card,i){card.dataset.v342Key=keyCard(card,i);card.dataset.v342Label=labelCard(card);if(active(card))return;var op=document.createElement('option');op.value=card.dataset.v342Key;op.textContent=card.dataset.v342Label;sel.appendChild(op);});
    if(Array.from(sel.options).some(function(o){return o.value===old;}))sel.value=old;
  }
  function openCard(card){var sec=document.getElementById('senal_paso_adicionales');openSection(sec);card.classList.add('v325-config-visible');setTimeout(function(){card.scrollIntoView({behavior:'smooth',block:'center'});},40);}
  function add(){var sel=document.getElementById('v327_botonera_select');if(!sel||!sel.value)return;var card=cards().find(function(c){return c.dataset.v342Key===sel.value;});if(!card)return;var cb=card.querySelector(':scope > input[type=checkbox]');if(!cb)return;cb.checked=true;fire(cb);openCard(card);setTimeout(refresh,60);}
  function rows(){
    var wrap=document.getElementById('v327_botonera_rows');if(!wrap)return;wrap.innerHTML='';var count=0;
    cards().forEach(function(card){if(!active(card))return;count++;var r=document.createElement('div');r.className='v327-row';var info=document.createElement('div');info.innerHTML='<strong></strong>';info.querySelector('strong').textContent=card.dataset.v342Label||labelCard(card);var sm=card.querySelector('label small');if(sm){var s=document.createElement('small');s.textContent=txt(sm);info.appendChild(s);}var a=document.createElement('div');a.className='v327-actions';if(card.querySelector('input[type=number],select,input[type=text]')){var m=document.createElement('button');m.type='button';m.textContent='Modificar';m.onclick=function(){openCard(card);};a.appendChild(m);}var q=document.createElement('button');q.type='button';q.className='v327-remove';q.textContent='Quitar';q.onclick=function(){var cb=card.querySelector(':scope > input[type=checkbox]');cb.checked=false;card.classList.remove('v325-config-visible');fire(cb);setTimeout(refresh,50);};a.appendChild(q);r.appendChild(info);r.appendChild(a);wrap.appendChild(r);});
    if(!count){var e=document.createElement('div');e.className='v327-empty';e.textContent='Todavía no agregaste accesorios a la botonera.';wrap.appendChild(e);}
  }
  function visibility(){var any=cards().some(active);var sec=document.getElementById('senal_paso_adicionales');if(sec){sec.classList.toggle('v327-no-items',!any);if(any)openSection(sec);}}
  function refresh(){options();rows();visibility();}
  function bind(){var body=document.getElementById('senal_paso_adicionales_cuerpo');if(body&&!body.dataset.v342){body.dataset.v342='1';body.addEventListener('change',function(ev){if(body.dataset.v348)return;if(ev.target&&ev.target.matches('input[type=checkbox]'))setTimeout(refresh,30);},true);}}
  function init(){if(window.__automacV477V342Init)return;window.__automacV477V342Init=true;prepareFlow();ensurePanel();bind();refresh();}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();window.addEventListener('load',init);
})();


/* ---- automac-v344-botonera-simple-js (extraido de index.php, linea original 6920) ---- */
(function(){
 function byId(id){return document.getElementById(id)}
 function fieldWrap(el){return el?el.closest('.campo'):null}
 function aplicarConfigRapidaV472(tpl){
   if(!tpl)return;
   var step=byId('v344_step_base');
   if(step){step.classList.add('v470-config-rapida');var head=step.querySelector(':scope > .v344-step-head');if(head)head.style.display='none';}
   tpl.classList.add('v470-senal-config-rapida');
   var tplLabel=tpl.querySelector('label[for="senal_plantilla_rapida"]');
   if(tplLabel){tplLabel.textContent='CONFIGURACIÓN RÁPIDA';tplLabel.removeAttribute('aria-hidden');tplLabel.classList.remove('v468-plantilla-label-oculto');}
   var tplBtn=tpl.querySelector('button[type="button"]');
   if(tplBtn)tplBtn.classList.add('selector-plantillas-aplicar');
 }
 function ensureSimple(){
   var form=byId('form_senalizacion'), tpl=byId('selector_plantillas_senalizacion'), base=byId('senal_paso_base');
   if(!form||!tpl||!base)return;
   if(byId('v344_botonera_simple')){aplicarConfigRapidaV472(tpl);return;}
   var simple=document.createElement('div');simple.id='v344_botonera_simple';
   simple.innerHTML=''
    +'<div class="v344-step v470-config-rapida" id="v344_step_base"><div id="v415_template_slot"></div><div id="v344_control_link"></div><div id="v344_tech_slot"></div></div>'
    +'<div class="v344-step" id="v344_step_indicator"><div class="v344-step-head"><div><span class="v344-step-num">2</span><span class="v344-step-title">Indicador de posición</span></div><button type="button" id="v344_indicator_toggle">Medida / detalle</button></div><div id="v344_indicator_row"></div><div id="v344_indicator_extra"></div></div>';
   var inclusion=form.querySelector('.modulo-inclusion');
   if(inclusion) inclusion.insertAdjacentElement('afterend',simple);
   else tpl.insertAdjacentElement('afterend',simple);
   var templateSlot=byId('v415_template_slot');
   if(templateSlot && tpl){
     templateSlot.appendChild(tpl);
     aplicarConfigRapidaV472(tpl);
   }
   var sync=byId('senal_usar_control');
   if(sync){
     var lab=sync.closest('.senal-sync-inline');
     if(lab){
       var tx=lab.querySelector('span');
       if(tx) tx.textContent='Usar datos del Control incluido en esta cotización';
       byId('v344_control_link').appendChild(lab);
     }
     var estado=document.createElement('div');estado.id='v365_tipo_control_senal';estado.className='v365-tipo-control-senal';byId('v344_control_link').appendChild(estado);
   }
   var edit=document.createElement('button');edit.type='button';edit.id='v344_edit_tech';edit.textContent='Editar configuración técnica';
   edit.onclick=function(){base.classList.toggle('v344-tech-open');edit.textContent=base.classList.contains('v344-tech-open')?'Ocultar configuración técnica':'Editar configuración técnica';};
   byId('v344_control_link').appendChild(edit);
   var ind=byId('senal_paso_indicador_cuerpo');
   if(ind){
     var model=byId('senal_indicador_modelo');
     if(!model) model=ind.querySelector('select[name="senal_indicador_modelo"],select');
     var qty=ind.querySelector('input[name="senal_indicador_cantidad"],input[type="number"]');
     var mw=fieldWrap(model),qw=fieldWrap(qty);if(mw)byId('v344_indicator_row').appendChild(mw);if(qw&&qw!==mw)byId('v344_indicator_row').appendChild(qw);
     Array.from(ind.children).forEach(function(ch){if(ch!==mw&&ch!==qw)byId('v344_indicator_extra').appendChild(ch);});
   }
   byId('v344_indicator_toggle').onclick=function(){var x=byId('v344_indicator_extra');x.classList.toggle('v344-open');this.textContent=x.classList.contains('v344-open')?'Ocultar detalle':'Medida / detalle';};
   var acc=byId('v327_botonera_panel');if(acc){
      var h=document.createElement('div');h.className='v344-step-head';h.innerHTML='<div><span class="v344-step-num">3</span><span class="v344-step-title">Accesorios de botonera</span></div>';
      acc.insertBefore(h,acc.firstChild);
      simple.insertAdjacentElement('afterend',acc);
   }
 }
 function repeat(){ensureSimple();aplicarConfigRapidaV472(byId('selector_plantillas_senalizacion'));setTimeout(function(){ensureSimple();aplicarConfigRapidaV472(byId('selector_plantillas_senalizacion'));},150);setTimeout(function(){ensureSimple();aplicarConfigRapidaV472(byId('selector_plantillas_senalizacion'));},500);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',repeat);else repeat();
 window.addEventListener('load',repeat);
})();


/* ---- automac-v330-revision-js (extraido de index.php, linea original 6974) ---- */
(function(){
 function colocarEditorRevision(){
   var slot=document.getElementById('revision_editor_slot');
   var banner=document.querySelector('.revision-cotizacion-ui');
   if(slot&&banner&&banner.parentNode!==slot) slot.appendChild(banner);
 }
 if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',colocarEditorRevision); else colocarEditorRevision();
 window.addEventListener('load',function(){colocarEditorRevision();setTimeout(colocarEditorRevision,250);});
 window.addEventListener('resize',function(){setTimeout(colocarEditorRevision,60);});
})();


/* ---- automac-v359-pedido-revision-js (extraido de index.php, linea original 6987) ---- */
(function(){
 function colocarRevisionPedido(){
   var slot=document.getElementById('revision_editor_slot');
   var banner=document.querySelector('.revision-pedido-ui');
   if(slot&&banner&&banner.parentNode!==slot) slot.appendChild(banner);
 }
 if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',colocarRevisionPedido); else colocarRevisionPedido();
 window.addEventListener('load',function(){colocarRevisionPedido();setTimeout(colocarRevisionPedido,250);});
 window.addEventListener('resize',function(){setTimeout(colocarRevisionPedido,60);});
})();


/* ---- automac-v331-header-js (extraido de index.php, linea original 7000) ---- */
(function(){
  function acomodarCabeceraRevision(){
    var grid=document.querySelector('#datos_generales .cabecera-cotizacion-grid');
    var slot=document.getElementById('revision_editor_slot');
    if(!grid||!slot) return;
    if(slot.parentNode!==grid) grid.appendChild(slot);
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',acomodarCabeceraRevision); else acomodarCabeceraRevision();
  window.addEventListener('load',function(){acomodarCabeceraRevision();setTimeout(acomodarCabeceraRevision,300);});
  window.addEventListener('resize',function(){setTimeout(acomodarCabeceraRevision,50);});
})();


/* ---- automac-v340-plantillas-senalizacion-js (extraido de index.php, linea original 7013) ---- */
// v486 · Plantillas de Señalización. Aplica configuración de botonera y, opcionalmente, indicador de cabina.
function aplicarPlantillaSenalizacionV338(){
  const sel=document.getElementById('senal_plantilla_rapida');
  const estado=document.getElementById('senal_plantilla_estado');
  const op=sel && sel.selectedOptions ? sel.selectedOptions[0] : null;

  const avisar=(texto,error=false)=>{
    if(!estado)return;
    estado.textContent=texto;
    estado.classList.add('visible');
    estado.style.background=error?'#fff3cd':'#e9f7ef';
    estado.style.color=error?'#7a5a00':'#176b45';
  };

  if(!op || !op.value){
    avisar('Seleccione una plantilla de Señalización.',true);
    return;
  }

  let cfg={};
  try{ cfg=JSON.parse(op.getAttribute('data-config')||'{}'); }
  catch(e){ avisar('No se pudo leer la configuración de la plantilla.',true); return; }

  const buscar=(selector)=>document.querySelector(selector);
  const existeOpcion=(el,valor)=>{
    if(!el || el.tagName!=='SELECT') return true;
    return Array.from(el.options).some(o=>String(o.value)===String(valor));
  };
  const asignar=(selector,valor,evento=true)=>{
    const el=buscar(selector);
    if(!el || valor===undefined || valor===null || String(valor)==='') return false;
    if(!existeOpcion(el,valor)) return false;
    el.value=String(valor);
    if(evento){
      el.dispatchEvent(new Event('input',{bubbles:true}));
      el.dispatchEvent(new Event('change',{bubbles:true}));
    }
    return String(el.value)===String(valor);
  };

  // 1) Campos estructurales: estos pueden modificar opciones dependientes.
  asignar('#senal_tipo_modulo',cfg.senal_tipo_modulo,true);
  asignar('#senal_tipo_puerta',cfg.senal_tipo_puerta,true);
  asignar('#senal_modelo',cfg.senal_modelo,true);

  // Ejecuta las mismas reglas que usa la carga manual.
  try{ if(typeof aplicarReglaTipoModuloSenalizacion==='function') aplicarReglaTipoModuloSenalizacion(); }catch(e){}
  try{ if(typeof actualizarTeclaSenalizacion==='function') actualizarTeclaSenalizacion(); }catch(e){}
  try{ if(typeof actualizarTensionSenalizacion==='function') actualizarTensionSenalizacion(); }catch(e){}
  try{ if(typeof actualizarBornesSenalizacion==='function') actualizarBornesSenalizacion(); }catch(e){}

  // 2) Dependientes: se aplican después de que las reglas terminaron de ajustar los combos.
  setTimeout(function(){
    asignar('[name="senal_color"]',cfg.senal_color,true);
    asignar('#senal_tecla',cfg.senal_tecla,true);
    asignar('#senal_tension',cfg.senal_tension,true);

    // Bornes puede ser recreado/forzado por modelo y tensión: se fija al final.
    setTimeout(function(){
      asignar('#senal_borne_manual',cfg.senal_borne_manual,true);

      // El indicador es opcional en la plantilla. Si fue guardado se aplica; si no, queda disponible para elección manual.
      let indicadorAplicado=false;
      if(cfg.senal_indicador_modelo!==undefined && cfg.senal_indicador_modelo!==null && String(cfg.senal_indicador_modelo)!==''){
        indicadorAplicado=asignar('#senal_indicador_modelo',cfg.senal_indicador_modelo,true);
        try{ if(typeof normalizarIndicadorSenalizacion==='function') normalizarIndicadorSenalizacion(); }catch(e){}
      }

      try{ if(typeof sincronizarPulsadoresExteriorConCabinaV179==='function') sincronizarPulsadoresExteriorConCabinaV179(); }catch(e){}
      try{ if(typeof abrirContenedorSenalizacion==='function') abrirContenedorSenalizacion('senal_contenedor_botonera_cabina','senal_macro_cabina_body'); }catch(e){}
      try{ if(typeof programarCalculoSenalizacion==='function') programarCalculoSenalizacion(120); }catch(e){}

      const nombres=[
        ['#senal_tipo_modulo','Módulos'],['#senal_tipo_puerta','Puerta'],['#senal_modelo','Pulsador'],
        ['[name="senal_color"]','Color'],['#senal_tecla','Tecla'],['#senal_tension','Tensión'],['#senal_borne_manual','Bornes']
      ];
      const faltan=nombres.filter(([s])=>{const e=buscar(s);return !e || String(e.value||'')==='';}).map(([,n])=>n);
      if(faltan.length){
        avisar('Plantilla aplicada parcialmente. Revise: '+faltan.join(', ')+'.',true);
      }else{
        avisar('Plantilla aplicada: '+String(op.textContent||'').trim()+(cfg.senal_indicador_modelo?(indicadorAplicado?' · indicador incluido.':' · revise el indicador guardado.'):' · indicador a completar manualmente.') );
      }
    },80);
  },80);
}


/* ---- automac-v346-tech-panel-js (extraido de index.php, linea original 7096) ---- */
(function(){
  function byId(id){return document.getElementById(id);}
  function init(){
    var step=byId('v344_step_base');
    var base=byId('senal_paso_base');
    var body=byId('senal_paso_base_cuerpo');
    var edit=byId('v344_edit_tech');
    var oldInd=byId('senal_paso_indicador');
    if(oldInd) oldInd.style.display='none';
    if(!step||!body) return;

    var panel=byId('v346_tech_panel');
    if(!panel){
      panel=document.createElement('div');
      panel.id='v346_tech_panel';
      panel.className='v346-tech-panel';
      panel.style.display='none';
      var link=byId('v344_control_link');
      if(link && link.parentNode===step) link.insertAdjacentElement('afterend',panel);
      else step.appendChild(panel);
      panel.appendChild(body);
    }
    if(base) base.style.display='none';

    if(edit){
      edit.style.display='inline-flex';
      edit.textContent=panel.style.display==='none'?'Editar configuración técnica':'Ocultar configuración técnica';
      edit.onclick=function(){
        var abrir=panel.style.display==='none';
        panel.style.display=abrir?'block':'none';
        edit.textContent=abrir?'Ocultar configuración técnica':'Editar configuración técnica';
      };
    }

    // Si se aplica una plantilla, la configuración técnica vuelve a quedar cerrada.
    var tplBtn=document.querySelector('#selector_plantillas_senalizacion button');
    if(tplBtn && !tplBtn.dataset.v346){
      tplBtn.dataset.v346='1';
      tplBtn.addEventListener('click',function(){
        setTimeout(function(){
          panel.style.display='none';
          if(edit) edit.textContent='Editar configuración técnica';
        },250);
      });
    }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',function(){init();setTimeout(init,250);});
  else {init();setTimeout(init,250);}
  window.addEventListener('load',function(){init();setTimeout(init,400);});
})();


/* ---- automac-v348-accesorios-botonera-js (extraido de index.php, linea original 7151) ---- */
(function(){
 function txt(el){return String(el&&el.textContent||'').replace(/\s+/g,' ').trim();}
 function fire(el,type){if(!el)return;el.dispatchEvent(new Event(type||'change',{bubbles:true}));}
 function normalCards(){
   return Array.from(document.querySelectorAll('#senal_paso_adicionales_cuerpo .senal-adic-compacto')).filter(function(c){return !!c.querySelector(':scope > input[type=checkbox]');});
 }
 function cardLabel(card){var l=card.querySelector(':scope > label');if(!l)return 'Accesorio';var c=l.cloneNode(true);c.querySelectorAll('small').forEach(function(x){x.remove();});return txt(c);}
 function cardKey(card,i){var cb=card.querySelector(':scope > input[type=checkbox]');return cb?(cb.name||cb.id||('normal_'+i)):('normal_'+i);}
 function specialDefs(){
   return [
    {key:'__sint7601',label:'Sintetizador de voz en cabina · A7601C',block:function(){return document.querySelector('.senal-especial-v169:has(#senal_sint_a7601c)');},active:function(){var e=document.getElementById('senal_sint_a7601c');return !!(e&&e.checked);},activate:function(){var e=document.getElementById('senal_sint_a7601c');if(e){e.checked=true;fire(e);}},deactivate:function(){var e=document.getElementById('senal_sint_a7601c');if(e){e.checked=false;fire(e);}}},
    {key:'__sint4820',label:'Sintetizador con indicador color · A4820SV',available:function(){if(typeof indicadorColorSintetizadorV474==='function')return indicadorColorSintetizadorV474();var e=document.getElementById('senal_indicador_modelo');return /4820|4830/i.test(String(e?.value||'')+' '+String(e?.selectedOptions?.[0]?.textContent||''));},block:function(){return document.querySelector('.senal-especial-v169:has(#senal_sint_a4820sv)');},active:function(){var e=document.getElementById('senal_sint_a4820sv');return !!(e&&e.checked);},activate:function(){var e=document.getElementById('senal_sint_a4820sv');if(e){e.checked=true;fire(e);}},deactivate:function(){var e=document.getElementById('senal_sint_a4820sv');if(e){e.checked=false;fire(e);}}},
    {key:'__pesador',label:'Frente de pesador de carga',block:function(){return document.querySelector('.senal-especial-v169:has(#senal_pesador_frente_codigo)');},active:function(){var e=document.getElementById('senal_pesador_frente_codigo');return !!(e&&String(e.value||'')!=='');},activate:function(){},deactivate:function(){var e=document.getElementById('senal_pesador_frente_codigo');if(e){e.value='';fire(e);}}},
    {key:'__acceso',label:'Control de accesos',block:function(){return document.querySelector('.senal-especial-v169.senal-control-acceso-v169');},active:function(){var e=document.getElementById('senal_control_acceso_incluir');return !!(e&&e.checked);},activate:function(){var e=document.getElementById('senal_control_acceso_incluir');if(e){e.checked=true;fire(e);}},deactivate:function(){var e=document.getElementById('senal_control_acceso_incluir');if(e){e.checked=false;fire(e);}var t=document.getElementById('senal_control_acceso_tecnologia');if(t){t.value='';fire(t);}}}
   ];
 }
 function ensureSpecialContainer(){
   var panel=document.getElementById('v327_botonera_panel'); if(!panel)return null;
   var dest=document.getElementById('v348_special_configs');
   if(!dest){dest=document.createElement('div');dest.id='v348_special_configs';panel.appendChild(dest);}
   specialDefs().forEach(function(d){var b=d.block();if(b&&b.parentNode!==dest)dest.appendChild(b);});
   return dest;
 }
 function selectedNormal(){return normalCards().filter(function(c){var cb=c.querySelector(':scope > input[type=checkbox]');return cb&&cb.checked;});}
 function sourceByKey(k){return normalCards().find(function(c,i){c.dataset.v348Key=cardKey(c,i);return c.dataset.v348Key===k;});}
 function buildOptions(){
   var sel=document.getElementById('v327_botonera_select');if(!sel)return;
   var old=sel.value;sel.innerHTML='<option value="">Seleccione...</option>';
   normalCards().forEach(function(c,i){c.dataset.v348Key=cardKey(c,i);c.dataset.v348Label=cardLabel(c);var cb=c.querySelector(':scope > input[type=checkbox]');if(cb&&cb.checked)return;var o=document.createElement('option');o.value=c.dataset.v348Key;o.textContent=c.dataset.v348Label;sel.appendChild(o);});
   specialDefs().forEach(function(d){if((d.available&&!d.available())||d.active())return;var o=document.createElement('option');o.value=d.key;o.textContent=d.label;sel.appendChild(o);});
   if(Array.from(sel.options).some(function(o){return o.value===old;}))sel.value=old;
 }
 function inputQty(card){return card.querySelector('.senal-cant-adic,input[type=number]');}
 function inputBonus(card){return card.querySelector('input[name^="senal_adicional_bonificar"],input[name="senal_bonificar_pano"]');}
 function normalRow(card){
   var row=document.createElement('div');row.className='v348-row';
   var info=document.createElement('div');info.className='v348-row-info';var s=document.createElement('strong');s.textContent=card.dataset.v348Label||cardLabel(card);info.appendChild(s);var sm=card.querySelector(':scope > label small');if(sm){var x=document.createElement('small');x.textContent=txt(sm);info.appendChild(x);}row.appendChild(info);
   var q=inputQty(card);var qClone=document.createElement('input');qClone.type='number';qClone.className='v348-qty';qClone.min='0';qClone.step='1';qClone.value=q?q.value:'1';qClone.title='Cantidad';qClone.oninput=function(){if(q){q.value=this.value;fire(q,'input');}};row.appendChild(qClone);
   var bonus=inputBonus(card);var bl=document.createElement('label');bl.className='v348-bonus';var bc=document.createElement('input');bc.type='checkbox';bc.checked=!!(bonus&&bonus.checked);bc.disabled=!bonus;bc.onchange=function(){if(bonus){bonus.checked=this.checked;fire(bonus);}};bl.appendChild(bc);bl.appendChild(document.createTextNode('Bonificar'));row.appendChild(bl);
   var acts=document.createElement('div');acts.className='v348-actions';var m=document.createElement('button');m.type='button';m.textContent='Modificar';m.onclick=function(){qClone.focus();qClone.select();};acts.appendChild(m);var r=document.createElement('button');r.type='button';r.className='v348-remove';r.textContent='Quitar';r.onclick=function(){var cb=card.querySelector(':scope > input[type=checkbox]');if(cb){cb.checked=false;fire(cb);}setTimeout(refresh,30);};acts.appendChild(r);row.appendChild(acts);return row;
 }
 function specialRow(d){
   var row=document.createElement('div');row.className='v348-row';var info=document.createElement('div');info.className='v348-row-info';var s=document.createElement('strong');s.textContent=d.label;info.appendChild(s);var sm=document.createElement('small');sm.textContent='Configuración especial';info.appendChild(sm);row.appendChild(info);
   var q=document.createElement('div');q.textContent='';row.appendChild(q);var b=document.createElement('div');row.appendChild(b);
   var acts=document.createElement('div');acts.className='v348-actions';var m=document.createElement('button');m.type='button';m.textContent='Modificar';m.onclick=function(){var block=d.block();if(block){block.classList.add('v348-special-visible');block.scrollIntoView({behavior:'smooth',block:'nearest'});}};acts.appendChild(m);var r=document.createElement('button');r.type='button';r.className='v348-remove';r.textContent='Quitar';r.onclick=function(){d.deactivate();var block=d.block();if(block)block.classList.remove('v348-special-visible');setTimeout(refresh,30);};acts.appendChild(r);row.appendChild(acts);return row;
 }
 function renderRows(){
   var wrap=document.getElementById('v327_botonera_rows');if(!wrap)return;wrap.innerHTML='';var n=0;
   selectedNormal().forEach(function(c){n++;wrap.appendChild(normalRow(c));});
   specialDefs().forEach(function(d){if(d.active()){n++;wrap.appendChild(specialRow(d));}});
   if(!n){var e=document.createElement('div');e.className='v327-empty';e.textContent='Todavía no agregaste accesorios a la botonera.';wrap.appendChild(e);}
 }
 function renderSpecials(){
   var dest=ensureSpecialContainer();if(!dest)return;var any=false;
   specialDefs().forEach(function(d){var b=d.block();if(!b)return;var on=d.active() || b.classList.contains('v348-special-visible');b.classList.toggle('v348-special-visible',on);if(on)any=true;});
   dest.classList.toggle('v348-has-config',any);
 }
 function add(){
   var sel=document.getElementById('v327_botonera_select');if(!sel||!sel.value)return;var k=sel.value;var sp=specialDefs().find(function(d){return d.key===k;});
   if(sp){sp.activate();var block=sp.block();if(block)block.classList.add('v348-special-visible');refresh();if(block)setTimeout(function(){block.scrollIntoView({behavior:'smooth',block:'nearest'});},30);return;}
   var c=sourceByKey(k);if(!c)return;var cb=c.querySelector(':scope > input[type=checkbox]');if(cb){cb.checked=true;fire(cb);}refresh();
 }
 function refresh(){ensureSpecialContainer();buildOptions();renderRows();renderSpecials();normalCards().forEach(function(c){c.classList.remove('v325-config-visible');});}
 function init(){
   if(window.__automacV477V348Init)return;window.__automacV477V348Init=true;
   var panel=document.getElementById('v327_botonera_panel');var btn=document.getElementById('v327_botonera_add');if(!panel||!btn)return;
   ensureSpecialContainer();
   var clone=btn.cloneNode(true);btn.parentNode.replaceChild(clone,btn);clone.addEventListener('click',add);
   var source=document.getElementById('senal_paso_adicionales_cuerpo');if(source&&!source.dataset.v348){source.dataset.v348='1';source.addEventListener('change',function(){setTimeout(refresh,20);},true);source.addEventListener('input',function(){setTimeout(refresh,20);},true);}
   // v476: el A4820SV depende del indicador de cabina, que esta fuera del bloque de accesorios.
   // Al cambiar indicador/modelo de botonera hay que reconstruir inmediatamente el selector "Accesorio a cotizar".
   ['senal_indicador_modelo','senal_modelo'].forEach(function(id){var e=document.getElementById(id);if(e&&!e.dataset.v476SintRefresh){e.dataset.v476SintRefresh='1';e.addEventListener('change',function(){setTimeout(refresh,25);});}});
   refresh();setTimeout(refresh,250);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();window.addEventListener('load',init);
})();


/* ---- automac-v349-accesorios-botonera-js (extraido de index.php, linea original 7227) ---- */
(function(){
 function t(el){return String(el&&el.textContent||'').replace(/\s+/g,' ').trim()}
 function emit(el,type){if(!el)return;el.dispatchEvent(new Event(type||'change',{bubbles:true}))}
 function hardRowLayout(r,q,bl,m,rm){
  r.style.setProperty('display','grid','important');
  r.style.setProperty('grid-template-columns','minmax(90px,1fr) 30px 62px 54px 44px','important');
  r.style.setProperty('gap','3px','important');
  r.style.setProperty('align-items','center','important');
  r.style.setProperty('width','100%','important');
  r.style.setProperty('max-width','100%','important');
  r.style.setProperty('box-sizing','border-box','important');
  r.style.setProperty('overflow','hidden','important');
  q.style.setProperty('width','30px','important');q.style.setProperty('min-width','30px','important');q.style.setProperty('max-width','30px','important');q.style.setProperty('padding','2px 1px','important');q.style.setProperty('text-align','center','important');
  bl.style.setProperty('width','62px','important');bl.style.setProperty('min-width','62px','important');bl.style.setProperty('max-width','62px','important');bl.style.setProperty('font-size','7px','important');bl.style.setProperty('white-space','nowrap','important');bl.style.setProperty('overflow','hidden','important');
  m.style.setProperty('grid-column','4','important');m.style.setProperty('width','54px','important');m.style.setProperty('min-width','54px','important');m.style.setProperty('max-width','54px','important');m.style.setProperty('padding','2px','important');m.style.setProperty('font-size','7px','important');m.style.setProperty('margin','0','important');
  rm.style.setProperty('grid-column','5','important');rm.style.setProperty('width','44px','important');rm.style.setProperty('min-width','44px','important');rm.style.setProperty('max-width','44px','important');rm.style.setProperty('padding','2px','important');rm.style.setProperty('font-size','7px','important');rm.style.setProperty('margin','0','important');
 }
 function cards(){return Array.from(document.querySelectorAll('#senal_paso_adicionales_cuerpo .senal-adic-compacto')).filter(c=>!!c.querySelector(':scope > input[type=checkbox]'))}
 function label(card){var l=card.querySelector(':scope > label');if(!l)return 'Accesorio';var x=l.cloneNode(true);x.querySelectorAll('small').forEach(e=>e.remove());return t(x)}
 function qty(card){return card.querySelector('.senal-cant-adic,input[type=number]')}
 function bonus(card){return card.querySelector('input[name^="senal_adicional_bonificar"],input[name="senal_bonificar_pano"]')}
 function active(card){var c=card.querySelector(':scope > input[type=checkbox]');return !!(c&&c.checked)}
 function key(card,i){var c=card.querySelector(':scope > input[type=checkbox]');return c?(c.name||c.id||('adic_'+i)):('adic_'+i)}
 function defs(){return [
   {key:'__sint7601',label:'Sintetizador de voz en cabina · A7601C',inlineOnly:true,block:()=>document.querySelector('.senal-especial-v169:has(#senal_sint_a7601c)'),toggle:()=>document.getElementById('senal_sint_a7601c'),qty:()=>document.getElementById('senal_sint_a7601c_cantidad')},
   {key:'__sint4820',label:'Sintetizador con indicador color · A4820SV',inlineOnly:true,available:function(){if(typeof indicadorColorSintetizadorV474==='function')return indicadorColorSintetizadorV474();var e=document.getElementById('senal_indicador_modelo');return /4820|4830/i.test(String(e?.value||'')+' '+String(e?.selectedOptions?.[0]?.textContent||''));},block:()=>document.querySelector('.senal-especial-v169:has(#senal_sint_a4820sv)'),toggle:()=>document.getElementById('senal_sint_a4820sv'),qty:()=>document.getElementById('senal_sint_a4820sv_cantidad')},
   {key:'__pesador',label:'Frente de pesador de carga',block:()=>document.querySelector('.senal-especial-v169:has(#senal_pesador_frente_codigo)'),select:()=>document.getElementById('senal_pesador_frente_codigo'),qty:()=>document.getElementById('senal_pesador_frente_cantidad')},
   {key:'__acceso',label:'Control de accesos',block:()=>document.querySelector('.senal-especial-v169.senal-control-acceso-v169'),toggle:()=>document.getElementById('senal_control_acceso_incluir'),select:()=>document.getElementById('senal_control_acceso_tecnologia'),qty:()=>document.getElementById('senal_control_acceso_cantidad')}
 ]}
 function specialActive(d){var c=d.toggle&&d.toggle();if(c)return !!c.checked;var s=d.select&&d.select();return !!(s&&String(s.value||''))}
 function resumenControlAccesoV481(){
   var cant=Math.max(1,parseInt(document.getElementById('senal_control_acceso_cantidad')?.value||'1',10)||1);
   var tec=String(document.getElementById('senal_control_acceso_tecnologia')?.value||'');
   var alc=String(document.getElementById('senal_control_acceso_alcance')?.value||'PISO_USUARIO');
   var par=Math.max(1,parseInt(document.getElementById('senal_control_acceso_paradas')?.value||'1',10)||1);
   var partes=[cant+' sistema'+(cant===1?'':'s')];
   if(tec==='CHIP'){partes.push('Chip de contacto');var ch=Math.max(0,parseInt(document.getElementById('senal_control_acceso_chips_cantidad')?.value||'0',10)||0);partes.push(ch+' chip'+(ch===1?'':'s'));}
   else if(tec==='TARJETA'){partes.push('Tarjeta de proximidad');var ta=Math.max(0,parseInt(document.getElementById('senal_control_acceso_tarjetas_cantidad')?.value||'0',10)||0);partes.push(ta+' tarjeta'+(ta===1?'':'s'));}
   else if(tec==='TECLADO')partes.push('Teclado');
   else partes.push('Falta elegir tecnología');
   partes.push(alc==='TODA_BOTONERA'?'Toda la botonera':'Piso de usuario');
   partes.push(par+' parada'+(par===1?'':'s'));
   return partes.join(' · ');
 }
 function deactivate(d){var c=d.toggle&&d.toggle();if(c){c.checked=false;emit(c)}var s=d.select&&d.select();if(s){s.value='';emit(s)}}
 function configArea(){
   var selected=document.getElementById('v327_botonera_selected');if(!selected)return null;
   var area=document.getElementById('v349_config_area');
   if(!area){area=document.createElement('div');area.id='v349_config_area';selected.appendChild(area)}
   else if(area.parentNode!==selected){selected.appendChild(area)}
   defs().forEach(d=>{var b=d.block();if(b&&b.parentNode!==area){b.classList.add('v349-config-block');area.appendChild(b)}})
   var general=document.querySelector('#senal_paso_adicionales_cuerpo .senal-principal-compacto.senal-campo-total');
   if(general&&general.parentNode!==area){general.classList.add('v349-config-block');general.id='v349_general_config';area.appendChild(general)}
   return area
 }
 function needsGeneral(card){var s=(card.dataset.senalAdicional||'')+' '+label(card);return /PAÑO|MENSAJES/i.test(s)}
 function rowNormal(card){
   var r=document.createElement('div');r.className='v349-row';
   var info=document.createElement('div');info.className='v349-info';var st=document.createElement('strong');st.textContent=label(card);info.appendChild(st);var sm=card.querySelector(':scope > label small');if(sm){var ss=document.createElement('small');ss.textContent=t(sm);info.appendChild(ss)}r.appendChild(info);
   var srcQ=qty(card),q=document.createElement('input');q.type='number';q.className='v349-qty';q.min='0';q.step='1';q.value=srcQ?srcQ.value:'1';q.title='Cantidad';q.oninput=()=>{if(srcQ){srcQ.value=q.value;srcQ.dataset.manual='1';emit(srcQ,'input');setTimeout(render,20)}};r.appendChild(q);
   var srcB=bonus(card),bl=document.createElement('label');bl.className='v349-bonus';var bc=document.createElement('input');bc.type='checkbox';bc.checked=!!(srcB&&srcB.checked);bc.disabled=!srcB;bc.onchange=()=>{if(srcB){srcB.checked=bc.checked;emit(srcB)}};bl.appendChild(bc);bl.appendChild(document.createTextNode('Bonificar'));r.appendChild(bl);
   var m=document.createElement('button');m.type='button';m.className='v349-action';m.textContent='Modificar';m.onclick=()=>{if(needsGeneral(card)){showGeneral(true);document.getElementById('v349_config_area')?.scrollIntoView({behavior:'smooth',block:'nearest'})}else{q.focus();q.select()}};r.appendChild(m);
   var rm=document.createElement('button');rm.type='button';rm.className='v349-action v349-remove';rm.textContent='Quitar';rm.onclick=()=>{var cb=card.querySelector(':scope > input[type=checkbox]');if(cb){cb.checked=false;emit(cb)}setTimeout(refresh,30)};r.appendChild(rm);hardRowLayout(r,q,bl,m,rm);return r
 }
 function rowSpecial(d){
   var r=document.createElement('div');r.className='v349-row';var info=document.createElement('div');info.className='v349-info';var st=document.createElement('strong');st.textContent=d.label;info.appendChild(st);var ss=document.createElement('small');ss.textContent=d.key==='__acceso'?resumenControlAccesoV481():(d.inlineOnly?'Cantidad':'Configuración especial');info.appendChild(ss);r.appendChild(info);
   var srcQ=d.qty&&d.qty(),q=document.createElement('input');q.type='number';q.className='v349-qty';q.min='0';q.step='1';q.value=srcQ?srcQ.value:'1';q.oninput=()=>{if(srcQ){srcQ.value=q.value;srcQ.dataset.manual='1';emit(srcQ,'input');setTimeout(render,20)}};r.appendChild(q);
   var bl=document.createElement('label');bl.className='v349-bonus';var bc=document.createElement('input');bc.type='checkbox';bc.disabled=true;bl.appendChild(bc);bl.appendChild(document.createTextNode('Bonificar'));r.appendChild(bl);
   var m=document.createElement('button');m.type='button';m.className='v349-action';m.textContent='Modificar';m.onclick=()=>{if(d.inlineOnly){q.focus();q.select();}else showSpecial(d,true)};r.appendChild(m);
   var rm=document.createElement('button');rm.type='button';rm.className='v349-action v349-remove';rm.textContent='Quitar';rm.onclick=()=>{deactivate(d);showSpecial(d,false);setTimeout(refresh,30)};r.appendChild(rm);hardRowLayout(r,q,bl,m,rm);return r
 }
 function showSpecial(d,on){var a=configArea(),b=d.block();if(!a||!b)return;if(d.inlineOnly){b.classList.remove('v349-visible','v348-special-visible');updateArea();return;}b.classList.toggle('v349-visible',on);if(on){a.classList.add('v349-visible');setTimeout(()=>b.scrollIntoView({behavior:'smooth',block:'nearest'}),20)}else updateArea()}
 function showGeneral(on){var a=configArea(),g=document.getElementById('v349_general_config');if(!a||!g)return;g.classList.toggle('v349-visible',on);if(on)a.classList.add('v349-visible');else updateArea()}
 function updateArea(){var a=configArea();if(!a)return;var any=!!a.querySelector('.v349-config-block.v349-visible');a.classList.toggle('v349-visible',any)}
 function options(){var s=document.getElementById('v327_botonera_select');if(!s)return;var old=s.value;s.innerHTML='<option value="">Seleccione...</option>';cards().forEach((c,i)=>{c.dataset.v349Key=key(c,i);if(active(c))return;var o=document.createElement('option');o.value=c.dataset.v349Key;o.textContent=label(c);s.appendChild(o)});defs().forEach(d=>{if((d.available&&!d.available())||specialActive(d))return;var o=document.createElement('option');o.value=d.key;o.textContent=d.label;s.appendChild(o)});if(Array.from(s.options).some(o=>o.value===old))s.value=old}
 function render(){var w=document.getElementById('v327_botonera_rows');if(!w)return;w.innerHTML='';var n=0;cards().forEach(c=>{if(active(c)){n++;w.appendChild(rowNormal(c))}});defs().forEach(d=>{if(specialActive(d)){n++;w.appendChild(rowSpecial(d))}});if(!n){var e=document.createElement('div');e.className='v327-empty';e.textContent='Todavía no agregaste accesorios a la botonera.';w.appendChild(e)}}
 function add(){
   var s=document.getElementById('v327_botonera_select');if(!s||!s.value)return;
   var valor=String(s.value||'');
   var d=defs().find(x=>x.key===valor);
   if(d){
     if(valor==='__sint7601'){
       var c7601=document.getElementById('senal_sint_a7601c'),q7601=document.getElementById('senal_sint_a7601c_cantidad');
       if(!c7601)return;
       c7601.checked=true;
       if(q7601&&Number(q7601.value||0)<=0){var base7601=Number(document.querySelector('[name="senal_cantidad"]')?.value||0);if(!(base7601>0)&&typeof cantidadInicialSenalDesdeControlV161==='function')base7601=Number(cantidadInicialSenalDesdeControlV161()||0);q7601.value=String(Math.max(1,base7601||1));q7601.dataset.manual='';}
       if(typeof actualizarSintetizadoresSenalV474==='function')actualizarSintetizadoresSenalV474('A7601C');
       emit(c7601);
     } else if(valor==='__sint4820'){
       if(typeof indicadorColorSintetizadorV474==='function'&&!indicadorColorSintetizadorV474())return;
       var c4820=document.getElementById('senal_sint_a4820sv'),q4820=document.getElementById('senal_sint_a4820sv_cantidad');
       if(!c4820)return;
       c4820.checked=true;
       if(q4820&&Number(q4820.value||0)<=0){var bi=Number(document.getElementById('senal_indicador_cantidad')?.value||0);if(!(bi>0))bi=Number(document.querySelector('[name="senal_cantidad"]')?.value||1);q4820.value=String(Math.max(1,bi||1));q4820.dataset.manual='';}
       if(typeof actualizarSintetizadoresSenalV474==='function')actualizarSintetizadoresSenalV474('A4820SV');
       emit(c4820);
     } else if(valor==='__acceso'){
       var inc=document.getElementById('senal_control_acceso_incluir');
       var sl=document.getElementById('senal_control_acceso_tecnologia');
       var qc=document.getElementById('senal_control_acceso_cantidad');
       if(!inc||!sl)return;
       inc.checked=true;
       var baseCA=Number(document.querySelector('[name="senal_cantidad"]')?.value||0);
       if(!(baseCA>0)&&typeof cantidadInicialSenalDesdeControlV161==='function')baseCA=Number(cantidadInicialSenalDesdeControlV161()||0);
       if(qc&&qc.dataset.manual!=='1')qc.value=String(Math.max(1,baseCA||1));
       if(typeof actualizarControlAccesoSenalV169==='function')actualizarControlAccesoSenalV169();
       emit(inc);
     } else {
       var cc=d.toggle&&d.toggle();if(cc){cc.checked=true;emit(cc)}else{var ss=d.select&&d.select();if(ss&&ss.options.length>1){ss.selectedIndex=1;emit(ss)}}
     }
     if(!d.inlineOnly)showSpecial(d,true);else{var ib=d.block();if(ib)ib.classList.remove('v349-visible','v348-special-visible');}refresh();s.value='';
     if(typeof programarCalculoSenalizacion==='function')programarCalculoSenalizacion(60);
     return;
   }
   var c=cards().find(x=>x.dataset.v349Key===valor);if(!c)return;var cb=c.querySelector(':scope > input[type=checkbox]');if(cb){cb.checked=true;emit(cb)}if(needsGeneral(c))showGeneral(true);refresh()
 }
 function refresh(){var body=document.getElementById('senal_paso_adicionales_cuerpo');if(body)body.dataset.v348='1';configArea();defs().forEach(d=>{if(d.inlineOnly){var b=d.block();if(b)b.classList.remove('v349-visible','v348-special-visible')}});cards().forEach(c=>c.classList.remove('v325-config-visible'));options();render();updateArea()}
 function guardarControlAccesoV481(){
   var inc=document.getElementById('senal_control_acceso_incluir'),tec=document.getElementById('senal_control_acceso_tecnologia'),cant=document.getElementById('senal_control_acceso_cantidad');
   if(!inc?.checked)return;
   if(!String(tec?.value||'')){alert('Elegí la tecnología del Control de accesos.');tec?.focus();return;}
   if(!(Number(cant?.value||0)>0)){alert('Indique la cantidad de Control de accesos.');cant?.focus();return;}
   var d=defs().find(x=>x.key==='__acceso');if(d)showSpecial(d,false);
   refresh();
   if(typeof programarCalculoSenalizacion==='function')programarCalculoSenalizacion(60);
 }
 function init(){if(window.__automacV481V349Init)return;window.__automacV481V349Init=true;var b=document.getElementById('v327_botonera_add'),body=document.getElementById('senal_paso_adicionales_cuerpo');if(!b||!body)return;body.dataset.v348='1';var nb=b.cloneNode(true);b.parentNode.replaceChild(nb,b);nb.addEventListener('click',add);if(!body.dataset.v349){body.dataset.v349='1';body.addEventListener('change',()=>setTimeout(refresh,20),true);body.addEventListener('input',()=>setTimeout(refresh,20),true)}var gb=document.getElementById('senal_control_acceso_guardar');if(gb&&!gb.dataset.v481){gb.dataset.v481='1';gb.addEventListener('click',guardarControlAccesoV481)}refresh();setTimeout(refresh,250)}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();window.addEventListener('load',init)
})();


/* ---- automac-v364-especificaciones-cliente-js (extraido de index.php, linea original 7305) ---- */
window.especificacionesTecnicasClienteActual=[];
function cargarEspecificacionesTecnicasCliente(clienteId){
  const aviso=document.getElementById('cliente_especificaciones_aviso');
  const texto=document.getElementById('cliente_especificaciones_aviso_texto');
  window.especificacionesTecnicasClienteActual=[];
  if(aviso)aviso.style.display='none';
  clienteId=parseInt(clienteId||0,10); if(!clienteId)return;
  fetch('cliente_especificaciones_api.php?cliente_id='+encodeURIComponent(clienteId),{credentials:'same-origin'})
    .then(r=>r.json()).then(data=>{
      if(!data||!data.ok||!Array.isArray(data.items)||!data.items.length)return;
      window.especificacionesTecnicasClienteActual=data.items;
      const oblig=data.items.filter(x=>String(x.tipo||'')==='OBLIGATORIA').length;
      if(texto)texto.textContent=(oblig?oblig+' obligatoria'+(oblig===1?'':'s')+' · ':'')+data.items.length+' especificación'+(data.items.length===1?'':'es')+' activa'+(data.items.length===1?'':'s')+'.';
      if(aviso)aviso.style.display='flex';
    }).catch(()=>{});
}
function abrirEspecificacionesTecnicasCliente(){
  const modal=document.getElementById('modal_especificaciones_cliente'), caja=document.getElementById('modal_especificaciones_contenido');if(!modal||!caja)return;
  const items=window.especificacionesTecnicasClienteActual||[];
  caja.innerHTML=items.length?items.map(x=>'<div class="spec '+escaparHtml(x.tipo||'')+'"><b>'+escaparHtml(x.titulo||'')+'</b><div class="meta">'+escaparHtml(x.tipo||'')+' · '+escaparHtml(x.categoria||'')+'</div><div class="detalle">'+escaparHtml(x.detalle||'')+'</div></div>').join(''):'<div>No hay especificaciones técnicas activas.</div>';
  modal.classList.add('visible');
}
function cerrarEspecificacionesTecnicasCliente(){const m=document.getElementById('modal_especificaciones_cliente');if(m)m.classList.remove('visible');}
document.addEventListener('keydown',e=>{if(e.key==='Escape')cerrarEspecificacionesTecnicasCliente();});


/* ---- automac-v365-senal-tipo-control-js (extraido de index.php, linea original 7333) ---- */
(function(){
 function byId(i){return document.getElementById(i)}
 function avisoIndicador(){
   var usar=byId('senal_usar_control'), wrap=byId('senal_indicador_modelo_wrap'); if(!usar||!wrap)return;
   var a=byId('senal_indicador_autonomo_v365');
   if(!a){a=document.createElement('div');a.id='senal_indicador_autonomo_v365';wrap.appendChild(a);}
   if(usar.checked){a.style.display='none';}
   else{a.style.display='block';a.textContent='Modo otro control / electromecánico: el indicador es autónomo. Se calcula APPIND por parada y en la OF de Señalización salen transformador 220/12V, imanes cortos 5cm, imán largo 25cm, A2142C e instructivo.';}
 }
 function aplicar(recalcular){
   var usar=byId('senal_usar_control'); if(!usar)return;
   if(typeof aplicarReglaTipoModuloSenalizacion==='function') aplicarReglaTipoModuloSenalizacion();
   if(typeof actualizarBornesSenalizacion==='function') actualizarBornesSenalizacion();
   if(typeof actualizarTensionSenalizacion==='function') actualizarTensionSenalizacion();
   if(typeof sincronizarPulsadoresExteriorConCabinaV179==='function') sincronizarPulsadoresExteriorConCabinaV179();
   avisoIndicador();
   if(recalcular && typeof programarCalculoSenalizacion==='function') programarCalculoSenalizacion(80);
 }
 function init(){
   var usar=byId('senal_usar_control'); if(!usar)return;
   usar.disabled=false;
   usar.addEventListener('change',function(){setTimeout(function(){aplicar(true);},0);});
   aplicar(false);
   setTimeout(function(){aplicar(false);},200);
   setTimeout(function(){aplicar(false);},700);
 }
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
 window.addEventListener('load',init);
})();


/* ---- automac-v367-senal-contenida-js (extraido de index.php, linea original 7368) ---- */
(function(){
  function asegurar(){
    var m=document.getElementById('modulo_senalizacion');
    if(!m)return;
    m.style.setProperty('min-width','0','important');
    m.style.setProperty('max-width','100%','important');
    m.style.setProperty('overflow-x','hidden','important');
    var link=document.getElementById('v344_control_link');
    if(link){
      link.style.setProperty('grid-template-columns','minmax(0,1fr)','important');
      link.style.setProperty('min-width','0','important');
      link.style.setProperty('max-width','100%','important');
    }
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',asegurar);else asegurar();
  window.addEventListener('load',asegurar);
  window.addEventListener('resize',asegurar);
})();
