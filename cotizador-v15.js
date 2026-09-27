/* AUTOMAC V1.5 - workspace based on existing named templates.
 * This module owns presentation, not prices. Existing form nodes, names, values,
 * submit handlers, validation, calculation endpoints and permissions are retained.
 * No controls are cloned, disabled or removed merely to simplify the screen.
 */
(function () {
  'use strict';
  if (!document.body.classList.contains('v15-workbench') || window.__v15Workbench) return;
  window.__v15Workbench = true;
  var names = {control:'Control + adicionales',senalizacion:'Se\u00f1alizaci\u00f3n + adicionales',accesorios:'Accesorios',iep:'IEP',repuestos:'Repuestos'};
  var order = ['control','senalizacion','accesorios','iep','repuestos'];
  var remote = {}, revisions = {control:0,senalizacion:0};
  var built = false, scheduled = 0, signature = {}, templateUI = {};
  function id(v) { return document.getElementById(v); }
  function q(s,r) { return (r || document).querySelector(s); }
  function all(s,r) { return Array.from((r || document).querySelectorAll(s)); }
  function node(tag,cls,txt) { var e=document.createElement(tag); if(cls)e.className=cls; if(txt!==undefined)e.textContent=txt; return e; }
  function text(e,v) { if(e && e.textContent!==String(v))e.textContent=String(v); }
  function attr(e,k,v) { if(e && e.getAttribute(k)!==String(v))e.setAttribute(k,String(v)); }
  function val(selector) { var e=q(selector);return e?String(e.value||''):''; }
  function selectedText(selector) { var e=q(selector); if(!e)return '';if(e.tagName==='SELECT'){var o=e.options[e.selectedIndex];return o && (e.value || /^\s*Sin\b/i.test(o.textContent))?o.textContent.trim():'';}return String(e.value||'').trim(); }
  function included(m) { var c=id(m==='control'?'incluir_control':m==='senalizacion'?'senal_incluir_cotizacion':'incluir_'+m);return !!(c&&c.checked); }
  function money(n) { return typeof window.monedaDocumento==='function'?window.monedaDocumento(n):'$ '+Number(n).toLocaleString('es-AR'); }
  function button(label,fn,cls) { var e=node('button',cls||'v15-link',label);e.type='button';if(fn)e.addEventListener('click',fn);return e; }
  function toggleDetails(title,extra) {var d=node('details','v15-details');var s=node('summary');s.appendChild(node('span','',title));if(extra)s.appendChild(node('small','v15-details-meta',extra));d.appendChild(s);return d;}
  function field(selector) {var e=q(selector);return e?e.closest('.campo'):null;}
  function place(e,parent) {if(e&&parent&&e.parentNode!==parent)parent.appendChild(e);}
  function schedule() {if(!scheduled)scheduled=requestAnimationFrame(refresh);}
  function current() {return q('#navegacion_cotizacion .activo[data-modulo]')?.dataset.modulo||'control';}
  function moduleShow(m) {
    order.forEach(function(k){var e=id('modulo_'+k);if(!e)return;var on=k===m;e.classList.toggle('activo',on);e.style.setProperty('display',on?'block':'none','important');e.style.setProperty('visibility',on?'visible':'hidden','important');attr(e,'aria-hidden',!on);});
    all('#navegacion_cotizacion [data-modulo]').forEach(function(b){var on=b.dataset.modulo===m;b.classList.toggle('activo',on);attr(b,'aria-selected',on);});
    try{sessionStorage.setItem('cotizador_modulo_activo',m);}catch(e){}
    schedule();
  }
  function go(m) {if(typeof window.mostrarModuloCotizador==='function')window.mostrarModuloCotizador(m);moduleShow(m);}
  if(typeof window.actualizarResumenControl!=='function')window.actualizarResumenControl=function(){if(typeof window.actualizarResumenDocumento==='function')window.actualizarResumenDocumento();};
  function state(m) {
    if(!included(m))return {key:'off',label:'No incluido'};
    var n=Number(window.subtotalesDocumento?.[m]||0);
    var st=remote[m];
    if(st && (st.key==='pending'||st.key==='error'))return st;
    if(m==='control') {
      var native=window.estadoCalculoDocumento?.control;
      if(native==='error')return {key:'error',label:'Sin precio / revisar'};
      if(native==='pendiente')return {key:'pending',label:'Pendiente de c\u00e1lculo'};
    }
    if(m==='iep' && n<=0)return {key:'pending',label:'Pendiente de dise\u00f1o'};
    // Match the existing selected-row boundary used by recopilarModuloLibre.
    // Empty manual placeholders are not products. Explicitly bonified accessories
    // can have a zero sale price, while an ordinary selected row cannot.
    if(m==='accesorios'||m==='repuestos'){
      var scope='#items_'+m+' .item-modular'+(m==='accesorios'?', #items_especiales_accesorios_v33 .item-modular':'');
      var rows=all(scope).filter(function(e){return e.dataset.seleccionado!=='0' && ['concepto','codigo','descripcion'].some(function(k){return String(e.querySelector('[data-campo="'+k+'"]')?.value||'').trim()!=='';});});
      var bad=rows.find(function(e){return !(Number(e.querySelector('[data-campo="cantidad"]')?.value||0)>0) || (!(Number(e.querySelector('[data-campo="precio"]')?.value||0)>0) && !(m==='accesorios'&&e.dataset.bonificado==='1'));});
      if(bad)return {key:'error',label:'Revisar precio / cantidad'};
      if(rows.length && rows.every(function(e){return m==='accesorios'&&e.dataset.bonificado==='1';}))return {key:'ok',label:'Bonificado'};
    }
    if(n>0 || st?.key==='ok')return {key:'ok',label:'Calculado'};
    return {key:'pending',label:'Pendiente de c\u00e1lculo'};
  }
  function hookCalculations() {
    // Observe existing calls. Do not replace their request handling or formulas.
    var original=window.solicitarCalculoAuxiliarRemoto;
    if(typeof original==='function'&&!original.__v15){
      var wrapped=async function(m){
        var ticket=(remote[m]?.ticket||0)+1,rev=revisions[m]||0;
        remote[m]={key:'pending',label:'Calculando...',ticket:ticket};schedule();
        try {
          var ok=await original.apply(this,arguments);
          if(remote[m]?.ticket===ticket && revisions[m]===rev){remote[m]={key:ok?'ok':'error',label:ok?'Calculado':'Sin precio / revisar',ticket:ticket};schedule();}
          return ok;
        } catch(e) {if(remote[m]?.ticket===ticket){remote[m]={key:'error',label:'No se pudo calcular',ticket:ticket};schedule();}throw e;}
      };
      wrapped.__v15=true;window.solicitarCalculoAuxiliarRemoto=wrapped;
    }
    ['programarCalculoTiempoReal','programarCalculoSenalizacion'].forEach(function(fn,i){
      var f=window[fn],m=i?'senalizacion':'control';if(typeof f!=='function'||f.__v15)return;
      var w=function(){if(included(m)){revisions[m]++;remote[m]={key:'pending',label:'Pendiente de c\u00e1lculo',ticket:remote[m]?.ticket||0};schedule();}return f.apply(this,arguments);};w.__v15=true;window[fn]=w;
    });
  }
  function geometry() {
    var h=q('.automac-topbar');var bottom=h?Math.max(0,Math.ceil(h.getBoundingClientRect().bottom)):0;
    document.documentElement.style.setProperty('--v15-top',bottom+'px');
    var nav=id('navegacion_cotizacion');if(nav){nav.style.setProperty('display','block','important');nav.style.setProperty('visibility','visible','important');}var nh=nav?Math.ceil(nav.getBoundingClientRect().height):62;
    document.documentElement.style.setProperty('--v15-nav',nh+'px');
  }
  function templatePicker(m,sourceId,selectId) {
    var source=id(sourceId),select=id(selectId),module=id('modulo_'+m);if(!source||!select||templateUI[m])return;
    var submit=q('button',source),manage=q('a[href]',source);if(!submit)return;
    var chosen=String(select.value||'');
    var box=node('section','v15-template');box.id='v15_template_'+m;
    var head=node('div','v15-template-head');
    var copy=node('div');copy.appendChild(node('span','v15-kicker','EMPEZ\u00c1 CON UNA PLANTILLA'));
    var label=node('strong','v15-template-current',chosen?select.options[select.selectedIndex].textContent:'Eleg\u00ed tu plantilla de '+(m==='control'?'Control':'Se\u00f1alizaci\u00f3n'));
    copy.appendChild(label);head.appendChild(copy);
    var toggle=button(chosen?'Cambiar':'Ocultar',function(){catalog.hidden=!catalog.hidden;text(toggle,catalog.hidden?'Elegir / cambiar':'Ocultar');attr(toggle,'aria-expanded',!catalog.hidden);},'v15-btn v15-btn-outline');head.appendChild(toggle);box.appendChild(head);
    var catalog=node('div','v15-template-catalog');catalog.hidden=!!chosen;
    var search=node('input','v15-template-search');search.type='search';search.setAttribute('data-excluir-calculo-auxiliar','1');search.addEventListener('change',function(ev){ev.stopPropagation();});search.placeholder='Buscar por c\u00f3digo o nombre...';search.setAttribute('aria-label','Buscar plantilla de '+m);catalog.appendChild(search);
    var list=node('div','v15-template-list');catalog.appendChild(list);
    var empty=node('p','v15-empty','No hay plantillas para esta b\u00fasqueda.');empty.hidden=true;catalog.appendChild(empty);
    var options=Array.from(select.options).filter(function(o){return o.value!=='';});
    options.forEach(function(o){
      var card=button('',function(){
        select.value=o.value;
        text(label,o.textContent.trim());chosen=o.value;catalog.hidden=true;text(toggle,'Cambiar');attr(toggle,'aria-expanded','false');
        if(m==='senalizacion'){
          submit.click();
          // The existing application sets dependent fields asynchronously.
          setTimeout(function(){captureSignature(m);var d=id('v15_signal_config');if(d)d.open=signalMissing();id('modulo_'+m).scrollIntoView({block:'start',behavior:'instant'});schedule();},550);
        } else {
          submit.click();
        }
      },'v15-template-option');
      var parts=o.textContent.trim().split(/\s+[\u2014\u2013]\s+/);
      card.appendChild(node('span','v15-template-code',parts.length>1?parts[0]:'PLANTILLA'));
      card.appendChild(node('strong','',parts.length>1?parts.slice(1).join(' - '):parts[0]));
      card.appendChild(node('span','v15-template-use','Usar \u2192'));
      card.dataset.search=o.textContent.toLowerCase();card.dataset.value=o.value;list.appendChild(card);
    });
    search.addEventListener('input',function(ev){ev.stopPropagation();var term=search.value.toLowerCase().trim(),count=0;all('.v15-template-option',list).forEach(function(b){b.hidden=b.dataset.search.indexOf(term)<0;if(!b.hidden)count++;});empty.hidden=count>0;});
    var footer=node('div','v15-template-foot');
    footer.appendChild(button('Configurar sin plantilla',function(){catalog.hidden=true;text(toggle,'Elegir / cambiar');var d=id(m==='control'?'v15_control_config':'v15_signal_config');if(d){d.open=true;d.scrollIntoView({block:'nearest'});}schedule();}));
    if(manage){var link=node('a','v15-link','Administrar plantillas');link.href=manage.getAttribute('href');footer.appendChild(link);}catalog.appendChild(footer);box.appendChild(catalog);
    var status=node('div','v15-template-note');status.id='v15_template_note_'+m;box.appendChild(status);
    source.classList.add('v15-template-source');
    var banner=q('.v15-module-head',module);if(banner)banner.insertAdjacentElement('afterend',box);else module.insertBefore(box,module.firstChild);
    attr(toggle,'aria-expanded',!catalog.hidden);templateUI[m]={box:box,label:label,select:select,reset:function(){
      chosen='';select.value='';text(label,'Elegí tu plantilla de '+(m==='control'?'Control':'Señalización'));
      catalog.hidden=false;text(toggle,'Ocultar');attr(toggle,'aria-expanded','true');search.value='';
      all('.v15-template-option',list).forEach(function(b){b.hidden=false;});empty.hidden=true;
    }};
  }
  var controlFields=[['CPU','#id_cpu'],['Maniobra','#id_maniobra'],['Potencia (HP)','#potencia_hp'],['Tipo','#id_tipo_control'],['Puerta cabina','#id_ptacabina'],['Puertas pisos','#id_ptapisos'],['Material de hueco','#id_material_hueco'],['Tensi\u00f3n','#id_tension']];
  var signalFields=[['M\u00f3dulos','#senal_tipo_modulo'],['Puerta','#senal_tipo_puerta'],['Pulsador','#senal_modelo'],['Color','[name="senal_color"]'],['Tecla','#senal_tecla'],['Tensi\u00f3n','#senal_tension'],['Bornes','#senal_borne_manual'],['Indicador','#senal_indicador_modelo']];
  function overview(fields,idValue) {var box=node('div','v15-config-overview');box.id=idValue;fields.forEach(function(d){var c=node('div','v15-overview-item');c.dataset.v15Field=d[1];c.appendChild(node('span','',d[0]));c.appendChild(node('strong','','Sin definir'));box.appendChild(c);});return box;}
  function configSignature(m) {return (m==='control'?controlFields:signalFields).map(function(d){return val(d[1]);}).join('|');}
  function captureSignature(m) {signature[m]=configSignature(m);}
  function controlMissing() {return ['#id_cpu','#id_tension','#id_maniobra','#id_tipo_control','#id_subtipo','#potencia_hp'].some(function(s){var e=q(s);return e && e.required && !e.disabled && !e.value;});}
  function signalMissing() {return ['#senal_tipo_modulo','#senal_tipo_puerta','#senal_modelo','[name="senal_color"]','#senal_tecla','#senal_tension'].some(function(s){var e=q(s);return e && !e.disabled && !e.value;});}
  function simplifyControl() {
    var f=id('form_cotizador'),config=id('control_paso_config'),doors=id('control_paso_puertas');if(!f||!config||id('v15_control_job'))return;
    var job=node('section','v15-card v15-job');job.id='v15_control_job';job.appendChild(node('h3','','Datos de esta obra'));job.appendChild(node('p','v15-help','Equipos y paradas de esta cotizaci\u00f3n.'));
    var body=node('div','v15-job-fields');job.appendChild(body);
    [field('#cantidad_equipos'),field('#id_bateria'),id('grupo_total_coches_bateria'),id('grupo_numero_obra_otro'),id('grupo_observacion_bateria'),id('grupo_mismas_paradas'),id('equipos_contenedor')].forEach(function(e){place(e,body);});
    f.insertBefore(job,config);
    var cfg=node('section','v15-card');cfg.id='v15_control_card';cfg.appendChild(node('h3','','Configuraci\u00f3n del Control'));
    cfg.appendChild(overview(controlFields,'v15_control_overview'));
    var d=toggleDetails('Revisar / modificar configuraci\u00f3n','CPU, maniobra, puertas y opciones t\u00e9cnicas');d.id='v15_control_config';d.open=controlMissing();if(val('#aplicar_plantilla'))d.dataset.v15AutoClose='1';d.querySelector('summary').addEventListener('click',function(){delete d.dataset.v15AutoClose;});
    f.insertBefore(cfg,config);cfg.appendChild(d);place(config,d);place(doors,d);
    [config,doors].forEach(function(e){if(e){e.classList.remove('cerrada');e.classList.add('v15-config-section');}});
    all('.seccion-adicionales',config).forEach(function(e){e.classList.add('v15-obsolete-label');});
    var ad=id('control_paso_adicionales');if(ad){
      ad.classList.add('v15-card','v15-additional-card');ad.classList.remove('cerrada');
      text(q('.control-titulo-texto strong',ad),'Adicionales de Control');
      var content=q('.control-seccion-cuerpo',ad);
      var extra=toggleDetails('Comunicaci\u00f3n, rescates y opciones especiales','Fuentes, interfases e importes manuales');extra.id='v15_control_extras';
      var exbody=node('div','v15-extra-fields');extra.appendChild(exbody);
      Array.from(content.children).forEach(function(e){if(!e.matches('.v325-add-panel,.control-checklist-grid'))place(e,exbody);});
      content.appendChild(extra);
      extra.open=all('input:not([type="hidden"]),select',exbody).some(function(e){return e.type==='checkbox'?e.checked:!!e.value&&e.value!=='0';});
    }
    var commercial=id('control_paso_descuentos');if(commercial){var cd=toggleDetails('Condiciones comerciales','Descuentos del Control');cd.classList.add('v15-commercial');f.insertBefore(cd,commercial);cd.appendChild(commercial);commercial.classList.remove('cerrada');}
    captureSignature('control');
  }
  function simplifySignal() {
    var form=id('form_senalizacion'),tech=id('v346_tech_panel');if(!form||id('v15_signal_config'))return;
    if(!tech)return;
    var card=node('section','v15-card');card.id='v15_signal_card';card.appendChild(node('h3','','Configuraci\u00f3n de la botonera'));
    card.appendChild(overview(signalFields,'v15_signal_overview'));
    var d=toggleDetails('Revisar / modificar configuraci\u00f3n','Botonera, pulsadores y datos t\u00e9cnicos');d.id='v15_signal_config';d.open=signalMissing();card.appendChild(d);
    var simple=id('v344_botonera_simple');form.insertBefore(card,simple||form.firstChild);d.appendChild(tech);
    tech.style.setProperty('display','block','important');
    // Keep the original cabin container as the functional boundary. The V1
    // cabin checkbox enables/disables its descendants for exterior-only quotes.
    var cabin=id('senal_datos_cabina_v181');
    var including=id('senal_incluir_botonera_cabina')?.closest('.senal-incluir-cabina-v181');
    if(including){including.classList.add('v15-cabin-choice');form.insertBefore(including,card);}
    if(cabin){form.insertBefore(cabin,card);cabin.insertBefore(card,cabin.firstChild);}
    var job=node('section','v15-card');job.id='v15_signal_job';job.appendChild(node('h3','','Botoneras y paradas'));
    var sync=id('v344_control_link');if(sync)job.appendChild(sync);
    var jobFields=q('.senal-base-renglon-sync',tech);if(jobFields)job.appendChild(jobFields);
    card.parentNode.insertBefore(job,card);
    var ind=id('v344_step_indicator');if(ind){ind.classList.add('v15-card');card.insertAdjacentElement('afterend',ind);}
    var addons=id('v327_botonera_panel');if(addons){addons.classList.add('v15-card');text(q('.v344-step-title',addons),'Adicionales de Se\u00f1alizaci\u00f3n');(ind||card).insertAdjacentElement('afterend',addons);}
    var legacy=id('senal_contenedor_botonera_cabina');if(legacy)legacy.classList.add('v15-retired-cabina');
    [['senal_contenedor_pulsadores_exteriores','Pulsadores exteriores'],['senal_contenedor_indicadores','Indicadores exteriores'],['senal_paso_comercial','Condiciones comerciales']].forEach(function(def){
      var section=id(def[0]);if(!section)return;
      var wrapper=toggleDetails(def[1],'Mostrar / ajustar');wrapper.classList.add('v15-signal-optional');form.insertBefore(wrapper,section);wrapper.appendChild(section);
      section.classList.remove('senal-macro-cerrado','senal-acordeon-cerrado');
      wrapper.open=false;
    });
    captureSignature('senalizacion');
  }
  function setupSummary(panel) {
    if(!panel||id('v15_emission_status'))return;
    var summary=q('.resumen-documento',panel);if(!summary)return;
    text(q('.panel-calculo-cabecera h3',summary),'Resumen de cotizaci\u00f3n');text(q('.panel-resumen-ayuda',summary),'Todo lo que lleva este documento.');
    var status=node('div','v15-emission-status');status.id='v15_emission_status';summary.insertBefore(status,q('.resumen-modulo-fila',summary));
    order.forEach(function(m){var row=id('resumen_'+m);if(!row)return;var anchor=q('.resumen-total-general',summary);if(anchor)summary.insertBefore(row,anchor);row.classList.add('v15-summary-row');text(q('span',row),names[m]);var original=q('[data-total]',row);if(original)original.classList.add('v15-source-value');
      var right=node('div','v15-summary-value');right.appendChild(node('strong','v15-row-amount','\u2014'));right.appendChild(node('small','v15-row-state','No incluido'));row.appendChild(right);
      var goButton=button('Ver',function(){go(m);id('modulo_'+m).scrollIntoView({block:'start',behavior:'smooth'});},'v15-summary-edit');goButton.setAttribute('aria-label','Ver '+names[m]);row.appendChild(goButton);
    });
    var total=node('div','v15-total');total.appendChild(node('span','','Total del documento'));var amount=node('strong','','\u2014');amount.id='v15_total_actual';total.appendChild(amount);var caption=node('small','','');caption.id='v15_total_caption';total.appendChild(caption);
    var old=q('.resumen-total-general',summary);status.insertAdjacentElement('afterend',total);
    var alerts=node('div','v15-summary-alerts');alerts.id='v15_summary_alerts';total.insertAdjacentElement('afterend',alerts);
    var foot=node('p','v15-help','La emisi\u00f3n conserva las validaciones de V1.');summary.appendChild(foot);
    var detail=id('v15_detalle_calculo');if(detail){detail.classList.remove('abierto');}
    var inspector=q('.v15-detalle-toggle',panel);if(inspector)text(inspector,'Ver desglose del m\u00f3dulo');
  }
  function installWorkspace() {
    var layout=id('cotizador_layout'),source=q('.form-container'),panel=id('panel_calculo_cotizador');if(!layout||!source||!panel||id('v15_workspace'))return;
    var w=node('div','v15-workspace');w.id='v15_workspace';var main=node('main','v15-main');main.id='v15_main';var aside=node('aside','v15-sidebar');aside.id='v15_sidebar';aside.setAttribute('aria-label','Resumen y emisi\u00f3n');w.appendChild(main);w.appendChild(aside);source.parentNode.insertBefore(w,source);
    order.forEach(function(m){var mod=id('modulo_'+m);if(!mod)return;place(mod,main);var body=q(':scope > .v193-module-body',mod);if(body){body.hidden=false;body.style.setProperty('display','block','important');}
      var h=node('header','v15-module-head');var copy=node('div');copy.appendChild(node('span','v15-kicker','COTIZACI\u00d3N POR M\u00d3DULOS'));copy.appendChild(node('h2','',names[m]));h.appendChild(copy);h.appendChild(node('span','v15-module-state','No incluido'));mod.insertBefore(h,mod.firstChild);
    });
    place(panel,aside);source.classList.add('v15-retired-form');panel.classList.remove('panel-presupuesto-oculto');panel.setAttribute('aria-hidden','false');layout.classList.remove('panel-presupuesto-oculto');
    var inc=q('#modulo_iep .modulo-inclusion');if(inc)inc.classList.add('v15-source-inclusion');
    var iep=id('modulo_iep');if(iep){var note=node('div','v15-iep-note','IEP est\u00e1 pendiente de dise\u00f1o. No se incorporan reglas ni plantillas nuevas.');q('.v15-module-head',iep).insertAdjacentElement('afterend',note);}
    setupSummary(panel);
    // The upper menu is not rebuilt, re-labelled or re-linked.
    window.setPanelPresupuestoOculto=function(oculto,desplazar){if(!oculto){if(window.innerWidth<1000 || desplazar)aside.scrollIntoView({block:'start',behavior:'smooth'});}};
    var old=window.mostrarModuloCotizador;if(typeof old==='function'&&!old.__v15){var fn=function(m){var r=old.apply(this,arguments);moduleShow(m);return r;};fn.__v15=true;window.mostrarModuloCotizador=fn;}
    moduleShow(current());
  }
  function selectedAdditionalStates() {
    // Only report a module-level result; never fabricate an individual price.
    var map={control:'control',senal:'senalizacion'};
    Object.keys(map).forEach(function(k){var m=map[k],st=state(m);all('#v325_'+k+'_selected .v325-selected-row, '+(k==='senal'?'#v327_botonera_rows .v348-item':'#v15_no_element')).forEach(function(row){
      var s=q('.v15-line-status',row);if(!s){s=node('small','v15-line-status');row.insertBefore(s,row.lastChild);}text(s,st.key==='ok'?'C\u00e1lculo del m\u00f3dulo actualizado':st.key==='off'?'M\u00f3dulo no incluido':st.key==='error'?'Revisar: m\u00f3dulo sin precio':'Pendiente de c\u00e1lculo');attr(s,'data-state',st.key);
    });});
  }
  function refresh() {
    scheduled=0;if(!built)return;
    text(q('#panel_calculo_cotizador .panel-calculo-cabecera h3'),'Resumen de cotizaci\u00f3n');
    text(q('#panel_calculo_cotizador .panel-resumen-ayuda'),'Todo lo que lleva este documento.');
    var cfg=id('v15_control_config');if(cfg&&cfg.dataset.v15AutoClose==='1'&&!controlMissing()){cfg.open=false;delete cfg.dataset.v15AutoClose;}

    var problems=[],count=0,total=0;
    order.forEach(function(m){var st=state(m),n=Number(window.subtotalesDocumento?.[m]||0),mod=id('modulo_'+m),row=id('resumen_'+m);
      if(st.key!=='off'){count++;if(st.key!=='ok')problems.push(names[m]+': '+st.label.toLowerCase());else total+=n;}
      var pill=mod&&q('.v15-module-state',mod);text(pill,st.label);attr(pill,'data-state',st.key);
      var tab=q('#navegacion_cotizacion [data-modulo="'+m+'"]');attr(tab,'data-v15-state',st.key);
      if(row){attr(row,'data-state',st.key);text(q('.v15-row-state',row),st.label);text(q('.v15-row-amount',row),st.key==='ok'?money(n):'\u2014');}
    });
    var stateBox=id('v15_emission_status');text(stateBox,problems.length?'Hay datos por revisar':count?'C\u00e1lculos actualizados':'Eleg\u00ed qu\u00e9 vas a cotizar');attr(stateBox,'data-state',problems.length?'pending':count?'ok':'off');
    text(id('v15_total_actual'),problems.length?'Pendiente':count?money(total):'\u2014');text(id('v15_total_caption'),problems.length?'El total no est\u00e1 completo. Revis\u00e1 las advertencias.':count?'Incluye los m\u00f3dulos calculados seleccionados.':'Ning\u00fan m\u00f3dulo incluido.');
    var a=id('v15_summary_alerts'),contents=problems.join('|');if(a&&a.dataset.content!==contents){a.dataset.content=contents;a.replaceChildren();problems.forEach(function(p){a.appendChild(node('p','',p));});}if(a)a.hidden=!problems.length;
    all('.v15-overview-item').forEach(function(e){var t=selectedText(e.dataset.v15Field);text(q('strong',e),t||'Sin definir');e.classList.toggle('v15-missing',!t);});
    ['control','senalizacion'].forEach(function(m){if(!templateUI[m])return;var sel=templateUI[m].select,note=id('v15_template_note_'+m);var has=!!sel.value;var nativeNote=m==='senalizacion'?id('senal_plantilla_estado'):null;var appliedMessage=nativeNote?nativeNote.textContent.trim():'';var changed=signature[m] && signature[m]!==configSignature(m);text(note,appliedMessage|| (has?(changed?'Configuraci\u00f3n ajustada para esta obra. La plantilla original no cambia.':'Plantilla aplicada. Pod\u00e9s ajustar la configuraci\u00f3n sin modificarla.'):'Tambi\u00e9n pod\u00e9s completar la configuraci\u00f3n manualmente.'));});
    var blocked=problems.length>0||count===0;
    all('#panel_calculo_cotizador .resumen-acciones button').forEach(function(b){
      if(blocked){if(!b.disabled)b.disabled=true;attr(b,'data-v15-blocked','1');attr(b,'title',problems[0]||'Inclu\u00ed al menos un m\u00f3dulo');}
      else if(b.getAttribute('data-v15-blocked')==='1'){b.removeAttribute('data-v15-blocked');b.disabled=false;}
    });
    selectedAdditionalStates();
  }
  window.v15ReiniciarNuevaCotizacion=function(){
    revisions.control++;revisions.senalizacion++;remote={};
    Object.keys(templateUI).forEach(function(m){templateUI[m].reset();captureSignature(m);});
    var cfg=id('v15_control_config');if(cfg){cfg.open=controlMissing();delete cfg.dataset.v15AutoClose;}
    schedule();
  };
  function revealInvalid(e) {
    if(!e.target || !e.target.closest)return;
    var target=e.target,mod=target.closest('.modulo-cotizador');if(mod)go(mod.id.replace('modulo_',''));
    for(var p=target.parentElement;p;p=p.parentElement)if(p.tagName==='DETAILS')p.open=true;
  }
  function init() {
    if(!id('modulo_control')||typeof modoPlantillaActivo!=='undefined'&&modoPlantillaActivo)return;
    installWorkspace();
    templatePicker('control','selector_plantillas_control','aplicar_plantilla');
    templatePicker('senalizacion','selector_plantillas_senalizacion','senal_plantilla_rapida');
    simplifyControl();simplifySignal();built=true;geometry();schedule();
    document.documentElement.setAttribute('data-v15-interfaz','PLANTILLAS-03');
  }
  hookCalculations();
  function ready(){init();setTimeout(init,650);setTimeout(init,1600);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',ready);else ready();
  window.addEventListener('load',ready);
  window.addEventListener('resize',function(){geometry();schedule();});
  document.addEventListener('invalid',revealInvalid,true);
  document.addEventListener('change',schedule,true);
  document.addEventListener('input',schedule,true);
  document.addEventListener('click',function(e){
    var tab=e.target.closest?.('#navegacion_cotizacion [data-modulo]');if(tab){setTimeout(function(){moduleShow(tab.dataset.modulo);},0);}
    var emit=e.target.closest?.('#panel_calculo_cotizador .resumen-acciones button');
    if(emit){var bad=order.some(function(m){var s=state(m);return s.key!=='off'&&s.key!=='ok';});if(bad){e.preventDefault();e.stopImmediatePropagation();schedule();}}
    schedule();
  },true);
  var setupObserver=function(){var panel=id('panel_calculo_cotizador');if(panel&&!panel.dataset.v15Observer){panel.dataset.v15Observer='1';new MutationObserver(function(muts){if(muts.some(function(m){return !m.target.closest?.('.v15-row-amount,.v15-row-state,.v15-total,.v15-summary-alerts,.v15-emission-status');}))schedule();}).observe(panel,{subtree:true,childList:true,characterData:true,attributes:true,attributeFilter:['disabled']});}};
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',setupObserver);else setupObserver();
})();
