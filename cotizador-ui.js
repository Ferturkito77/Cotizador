/* AUTOMAC v431 - JavaScript modularizado. Mantiene nombres globales y orden original. */

/* ---- automac-v391-js (extraido de index.php, linea original 7670) ---- */
(function(){
  var svg={
    control:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1z"></path></svg>',
    senalizacion:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 8h6M9 12h6"></path><circle cx="12" cy="17" r="1"></circle></svg>',
    accesorios:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L4 17l3 3 5.7-5.7a4 4 0 0 0 5-5l-2.2 2.2-3-3 2.2-2.2z"></path><path d="M4.5 4.5l5 5"></path></svg>',
    iep:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7l8-4 8 4-8 4-8-4z"></path><path d="M4 7v10l8 4 8-4V7M12 11v10"></path></svg>',
    repuestos:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.3 2.3-3-3 2.3-2.3z"></path></svg>',
    principal:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="3" width="7" height="7" rx="1"></rect><rect x="14" y="3" width="7" height="7" rx="1"></rect><rect x="3" y="14" width="7" height="7" rx="1"></rect><rect x="14" y="14" width="7" height="7" rx="1"></rect></svg>',
    opcionales:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3v18M3 12h18"></path><circle cx="12" cy="12" r="9"></circle></svg>'
  };
  function iconos(){
    ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){
      var h=document.querySelector('#modulo_'+m+' > .v304-module-header');
      if(!h||h.querySelector('.v391-module-icon'))return;
      var s=document.createElement('span');s.className='v391-module-icon';s.setAttribute('aria-hidden','true');s.innerHTML=svg[m];h.insertBefore(s,h.firstChild);
    });
    var p=document.getElementById('v310_tab_principal'),o=document.getElementById('v310_tab_opcionales');
    if(p&&!p.querySelector('.v391-tab-icon'))p.insertAdjacentHTML('afterbegin','<span class="v391-tab-icon" aria-hidden="true">'+svg.principal+'</span>');
    if(o&&!o.querySelector('.v391-tab-icon'))o.insertAdjacentHTML('afterbegin','<span class="v391-tab-icon" aria-hidden="true">'+svg.opcionales+'</span>');
  }
  var v395Liberado=false;
  function uiProductivaLista(){
    if(document.body.classList.contains('v15-workbench'))return !!document.getElementById('v15_workspace');
    if(window.innerWidth<1280) return true;
    return !!(
      document.body.classList.contains('ui-v310') &&
      document.getElementById('v310_tabs') &&
      document.getElementById('v302_workspace') &&
      document.getElementById('v302_board')
    );
  }
  function liberarVista(){
    if(v395Liberado)return;
    if(!uiProductivaLista())return;
    v395Liberado=true;
    iconos();
    requestAnimationFrame(function(){
      document.documentElement.classList.remove('automac-v391-cargando');
    });
  }
  function esperarUIProductiva(){
    var intentos=0;
    function comprobar(){
      intentos++;
      if(uiProductivaLista()){liberarVista();return;}
      if(intentos<80){requestAnimationFrame(comprobar);return;}
      /* Salvaguarda: nunca dejar la página oculta si otro script falla. */
      document.documentElement.classList.remove('automac-v391-cargando');
    }
    requestAnimationFrame(comprobar);
  }
  function iniciar(){
    document.body.classList.add('ui-v391');
    iconos();
    esperarUIProductiva();
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
  window.addEventListener('load',function(){iniciar();setTimeout(function(){iconos();liberarVista();},160);});
})();


/* ---- automac-v392-js (extraido de index.php, linea original 7731) ---- */
(function(){
  var resumenIconos={
    control:'<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="3"></circle><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1-2.8 2.8-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21h-4v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1L4.2 17l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3v-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9L4.2 7 7 4.2l.1.1a1.7 1.7 0 0 0 1.9.3 1.7 1.7 0 0 0 1-1.5V3h4v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1L19.8 7l-.1.1a1.7 1.7 0 0 0-.3 1.9 1.7 1.7 0 0 0 1.5 1h.1v4h-.1a1.7 1.7 0 0 0-1.5 1z"></path></svg>',
    senalizacion:'<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="5" y="3" width="14" height="18" rx="3"></rect><path d="M9 8h6M9 12h6"></path><circle cx="12" cy="17" r="1"></circle></svg>',
    accesorios:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L4 17l3 3 5.7-5.7a4 4 0 0 0 5-5l-2.2 2.2-3-3 2.2-2.2z"></path><path d="M4.5 4.5l5 5"></path></svg>',
    iep:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7l8-4 8 4-8 4-8-4z"></path><path d="M4 7v10l8 4 8-4V7M12 11v10"></path></svg>',
    repuestos:'<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5 5L3 18l3 3 6.7-6.7a4 4 0 0 0 5-5l-2.3 2.3-3-3 2.3-2.3z"></path></svg>'
  };
  function decorarResumen(){
    var r=document.getElementById('v307_resumen'); if(!r)return false;
    Object.keys(resumenIconos).forEach(function(m){
      var row=r.querySelector('[data-v307-mod="'+m+'"]'); if(!row)return;
      var label=row.querySelector('span'); if(!label||label.querySelector('.v392-summary-icon'))return;
      var i=document.createElement('span');i.className='v392-summary-icon';i.setAttribute('aria-hidden','true');i.innerHTML=resumenIconos[m];label.insertBefore(i,label.firstChild);
    });
    var acts=r.querySelectorAll('.v307-acciones button');
    acts.forEach(function(b,idx){
      if(b.dataset.v392Decorado)return;
      var txt=(b.textContent||'').trim();
      var pedido=/PEDIDO/i.test(txt), cot=/COTIZ/i.test(txt);
      if(!pedido&&!cot)return;
      b.dataset.v392Decorado='1';
      var titulo=txt;
      var sub=pedido?'Convertir en pedido inmediato':'Generar el documento comercial';
      var icon=pedido?'🛒':'▤';
      b.innerHTML='<span class="v392-cta-icon" aria-hidden="true">'+icon+'</span><span class="v392-cta-title">'+titulo+'</span><span class="v392-cta-sub">'+sub+'</span>';
    });
    return true;
  }
  function decorarBotones(){
    document.querySelectorAll('button').forEach(function(b){
      if(b.closest('#v307_resumen'))return;
      var t=(b.textContent||'').replace(/\s+/g,' ').trim().toUpperCase();
      if(!t)return;
      if(t==='APLICAR') b.dataset.v392Icon='✓';
      else if(t==='AGREGAR'||t.indexOf('+ ÍTEM MANUAL')>=0||t.indexOf('AGREGAR OTRO')>=0) b.dataset.v392Icon='+';
      else if(t==='MODIFICAR'||t.indexOf('EDITAR CONFIGURACIÓN')>=0) b.dataset.v392Icon='✎';
      else if(t==='QUITAR') b.dataset.v392Icon='×';
      else if(t.indexOf('VER DESGLOSE')>=0) b.dataset.v392Icon='▤';
    });
  }
  function iniciar(){
    document.body.classList.add('ui-v392');
    decorarBotones();decorarResumen();
    [120,350,800,1400].forEach(function(ms){setTimeout(function(){decorarBotones();decorarResumen();},ms);});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',iniciar);else iniciar();
  window.addEventListener('load',iniciar);
  document.addEventListener('change',function(){setTimeout(decorarResumen,80)},false);
})();


/* ---- automac-v394-js (extraido de index.php, linea original 7787) ---- */
(function(){
  if(document.body.classList.contains('v15-workbench')) return;

  function desktop(){return window.innerWidth>=980;}
  function totalActual(){
    var src=document.getElementById('resumen_total_general');
    var dst=document.getElementById('v307_total');
    var txt=(dst&&dst.textContent.trim())||(src&&src.textContent.trim())||'$ 0,00';
    return txt;
  }
  function crearMini(){
    if(!desktop())return;
    document.body.classList.add('ui-v394');
    var mini=document.getElementById('v394_resumen_mini');
    if(!mini){
      mini=document.createElement('div');
      mini.id='v394_resumen_mini';
      mini.setAttribute('role','status');
      mini.innerHTML='<span class="v394-mini-label">Total general</span><strong class="v394-mini-total">$ 0,00</strong><button type="button" class="v394-mini-btn" title="Abrir resumen" aria-label="Abrir resumen">▤</button>';
      document.body.appendChild(mini);
      mini.querySelector('.v394-mini-btn').addEventListener('click',function(){
        var toggle=document.getElementById('v310_resumen_toggle');
        if(toggle)toggle.click();
      });
    }
    actualizarMini();
  }
  function actualizarMini(){
    var mini=document.getElementById('v394_resumen_mini');if(!mini)return;
    var t=mini.querySelector('.v394-mini-total');if(t)t.textContent=totalActual();
  }
  function observar(){
    var targets=[document.getElementById('resumen_total_general'),document.getElementById('v307_total')].filter(Boolean);
    if(!targets.length)return;
    var obs=new MutationObserver(function(){requestAnimationFrame(actualizarMini)});
    targets.forEach(function(t){if(!t.dataset.v394Obs){t.dataset.v394Obs='1';obs.observe(t,{subtree:true,childList:true,characterData:true});}});
  }
  function init(){
    if(!desktop()){
      document.body.classList.remove('ui-v394');
      var mini=document.getElementById('v394_resumen_mini');if(mini)mini.remove();
      return;
    }
    crearMini();observar();actualizarMini();
    setTimeout(function(){crearMini();observar();actualizarMini();},180);
    setTimeout(actualizarMini,700);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('load',init);
  window.addEventListener('resize',init);
  document.addEventListener('change',function(){if(desktop())setTimeout(actualizarMini,80)},true);
})();


/* ---- automac-v406-ui-unica-js (extraido de index.php, linea original 7841) ---- */
(function(){
  if(document.body.classList.contains('v15-workbench')) return;

  function asegurar(){
    document.body.classList.add('ui-v310','ui-v311');
    /* La UI vieja queda escondida siempre. */
    ['navegacion_cotizacion'].forEach(function(id){var e=document.getElementById(id);if(e)e.style.setProperty('display','none','important');});
    document.querySelectorAll('.cotizador-flujo-wrap,.flujo-cotizacion,.cotizador-contexto-actual').forEach(function(e){e.style.setProperty('display','none','important');});
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',asegurar);else asegurar();
  window.addEventListener('load',asegurar);
  window.addEventListener('resize',function(){asegurar();});
})();


/* ---- automac-v379-indicadores-layout-js (extraido de index.php, linea original 7864) ---- */
(function(){
  const fams=['SIMPLE_IP','DOBLE_IP'];
  const byId=id=>document.getElementById(id);
  const pref=f=>'senal_ext_'+String(f).toLowerCase()+'_';
  function esElectromecanico(){const t=tipoModuloSenalizacionActual();return t.includes('ELECTROMEC');}
  function rolFam(f){return String(byId(pref(f)+'indicador_rol')?.value||'REPETIDOR').toUpperCase();}
  function globalParadas(){return byId('senal_indicador_maestro_paradas');}
  function instalarFam(f){
    const p=pref(f), editor=byId(p+'editor'), grid=editor?.querySelector('.senal-pulsador-grid-v179');
    if(!grid)return;
    const ind=byId(p+'indicador_codigo')?.closest('.campo');
    if(!byId(p+'paradas')){
      const d=document.createElement('div');
      d.className='campo v379-ip-paradas-wrap';d.id=p+'paradas_wrap';
      d.innerHTML='<label>Paradas del sistema</label><input type="number" min="1" step="1" id="'+p+'paradas" placeholder="Ej.: 9"><small>Obligatorio solo si este indicador es Maestro A4000.</small>';
      if(ind)ind.after(d);else grid.appendChild(d);
      d.querySelector('input').addEventListener('input',function(){
        if(rolFam(f)==='MAESTRO'){
          const g=globalParadas();if(g)g.value=this.value;
          if(typeof programarCalculoSenalizacion==='function')programarCalculoSenalizacion(100);
        }
      });
    }
    if(!byId(p+'nomenclatura')){
      const d=document.createElement('div');
      d.className='campo v379-ip-nomenclatura-wrap';d.id=p+'nomenclatura_wrap';
      d.innerHTML='<label>Nomenclatura de paradas</label><input type="text" maxlength="250" id="'+p+'nomenclatura" placeholder="Ej.: Pb, 1, 2, 3, 4"><small>Identificación de pisos/paradas del sistema.</small>';
      const med=byId(p+'medida')?.closest('.campo');
      if(med)med.before(d);else grid.appendChild(d);
    }
    if(!byId(p+'v379_help')){
      const h=document.createElement('div');h.id=p+'v379_help';h.className='v379-ip-help';
      h.innerHTML='<strong>Indicador:</strong> Maestro A4000 genera la señal y lleva material de hueco; Repetidor/esclavo A4400 toma la señal del maestro. Medida y acabado corresponden al frente del conjunto.';
      grid.appendChild(h);
    }
    refrescarFam(f);
  }
  function refrescarFam(f){
    const p=pref(f), mae=esElectromecanico()&&rolFam(f)==='MAESTRO', w=byId(p+'paradas_wrap'), inp=byId(p+'paradas');
    if(w)w.style.display=mae?'':'none';
    if(mae&&inp&&!inp.value){const g=globalParadas();if(g?.value)inp.value=g.value;}
  }
  function sincronizarMaestro(f){
    if(!esElectromecanico()||rolFam(f)!=='MAESTRO')return true;
    const p=pref(f), n=byId(p+'paradas'), g=globalParadas();
    if(!n||Number(n.value||0)<1){alert('Defina la cantidad de paradas del indicador Maestro A4000.');n?.focus();return false;}
    if(g)g.value=n.value;
    return true;
  }
  function envolver(){
    if(window.__v379_wrapped)return;window.__v379_wrapped=true;
    const oldCap=window.capturarItemPulsadorV188;
    if(typeof oldCap==='function')window.capturarItemPulsadorV188=function(f){
      if(fams.includes(String(f).toUpperCase())&&!sincronizarMaestro(String(f).toUpperCase()))return null;
      const it=oldCap.apply(this,arguments);if(!it)return it;
      const F=String(f).toUpperCase();
      if(fams.includes(F)){
        const p=pref(F);
        it.paradas_maestro=Math.max(0,parseInt(byId(p+'paradas')?.value||'0',10)||0);
        it.nomenclatura=String(byId(p+'nomenclatura')?.value||'').trim();
      }
      return it;
    };
    const oldEdit=window.editarItemPulsadorV188;
    if(typeof oldEdit==='function')window.editarItemPulsadorV188=function(f,idx){
      const F=String(f).toUpperCase();
      const it=typeof itemsPulsadoresV188==='function'?itemsPulsadoresV188()[idx]:null;
      const r=oldEdit.apply(this,arguments);instalarFam(F);
      if(it&&fams.includes(F)){
        const p=pref(F), pa=byId(p+'paradas'), no=byId(p+'nomenclatura');
        if(pa)pa.value=it.paradas_maestro||'';if(no)no.value=it.nomenclatura||'';
        if(String(it.indicador_rol||'').toUpperCase()==='MAESTRO'&&pa?.value){const g=globalParadas();if(g)g.value=pa.value;}
      }
      refrescarFam(F);return r;
    };
    const oldRender=window.renderItemsPulsadoresV188;
    if(typeof oldRender==='function')window.renderItemsPulsadoresV188=function(){
      const r=oldRender.apply(this,arguments);
      fams.forEach(F=>{
        const p=pref(F), cont=byId(p+'items_list'), its=(typeof itemsPulsadoresV188==='function'?itemsPulsadoresV188():[]).filter(x=>String(x.familia||'').toUpperCase()===F);
        const rows=cont?[...cont.children]:[];
        rows.forEach((row,i)=>{const it=its[i];if(!it)return;const sm=row.querySelector('small');if(sm){let extra='';if(it.nomenclatura)extra+=' · Nomenclatura: '+it.nomenclatura;if(String(it.indicador_rol||'').toUpperCase()==='MAESTRO'&&it.paradas_maestro)extra+=' · '+it.paradas_maestro+' paradas';if(extra&&!sm.textContent.includes('Nomenclatura:'))sm.textContent+=extra;}});
      });
      return r;
    };
  }
  document.addEventListener('change',function(e){
    const id=String(e.target?.id||'');
    fams.forEach(F=>{const p=pref(F);if(id===p+'indicador_rol'||id==='senal_usar_control')setTimeout(()=>refrescarFam(F),0);});
  });
  function init(){fams.forEach(instalarFam);envolver();setTimeout(()=>fams.forEach(instalarFam),250);setTimeout(()=>fams.forEach(instalarFam),900);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('load',init);
})();


/* ---- automac-v390-uiux-js (extraido de index.php, linea original 7966) ---- */
(function(){
  const defs={
    control:{title:'Control',sub:'CPU, maniobra, equipos y configuración técnica',inc:'incluir_control'},
    senalizacion:{title:'Señalización',sub:'Botoneras, indicadores y datos por coche',inc:'senal_incluir_cotizacion'},
    accesorios:{title:'Accesorios',sub:'Complementos y especiales del equipo',inc:'incluir_accesorios'},
    iep:{title:'IEP',sub:'Ítems especiales del proyecto',inc:'incluir_iep'},
    repuestos:{title:'Repuestos',sub:'Venta integrada o independiente',inc:'incluir_repuestos'}
  };
  function desktop(){return window.innerWidth>=980;}
  function qs(s,r){return (r||document).querySelector(s);}
  function textTotal(mod){
    const e=qs('#resumen_'+mod+' [data-total]');
    return e ? e.textContent.trim() : '—';
  }
  function estado(mod){
    const id=defs[mod]?.inc, c=id?document.getElementById(id):null;
    return c&&c.checked?'INCLUIDO':'NO INCLUIDO';
  }
  function mejorarCabeceras(){
    if(!desktop())return;
    Object.keys(defs).forEach(function(mod){
      const h=qs('#modulo_'+mod+' > .v304-module-header'); if(!h)return;
      if(!h.querySelector('.v390-head-copy')){
        const old=h.querySelector('.v304-title');
        const copy=document.createElement('div'); copy.className='v390-head-copy';
        copy.innerHTML='<strong>'+defs[mod].title+'</strong><small>'+defs[mod].sub+'</small>';
        if(old)old.after(copy); else h.appendChild(copy);
        const meta=document.createElement('div'); meta.className='v390-head-meta';
        meta.innerHTML='<span class="v390-head-state"></span><span class="v390-head-price"></span>';
        h.appendChild(meta);
      }
      const st=h.querySelector('.v390-head-state'), pr=h.querySelector('.v390-head-price');
      const s=estado(mod); if(st){st.textContent=s;st.classList.toggle('incluido',s==='INCLUIDO');}
      if(pr)pr.textContent=textTotal(mod);
    });
  }
  function validarCampo(el){
    if(!el || el.disabled || el.type==='hidden')return;
    const required=el.required || el.getAttribute('aria-required')==='true';
    if(!required)return;
    const vacio=(el.type==='checkbox')?!el.checked:String(el.value||'').trim()==='';
    el.classList.toggle('v390-invalid',vacio);
  }
  function validacionVisual(){
    document.querySelectorAll('#datos_generales [required],#v302_board [required]').forEach(function(el){
      if(el.dataset.v390Validate)return;el.dataset.v390Validate='1';
      el.addEventListener('blur',function(){validarCampo(el)});
      el.addEventListener('change',function(){validarCampo(el)});
      el.addEventListener('input',function(){if(el.classList.contains('v390-invalid'))validarCampo(el)});
    });
  }
  function ayudasBusqueda(){
    const ids=['accesorio_selector_rapido','senal_indicador_modelo','senal_indicador_ext_modelo_v190'];
    ids.forEach(function(id){
      const s=document.getElementById(id);if(!s)return;
      s.setAttribute('title','Búsqueda rápida: abra el selector y escriba las primeras letras del modelo.');
      s.setAttribute('aria-keyshortcuts',id==='accesorio_selector_rapido'?'Alt+A':'Alt+I');
    });
    const cli=document.getElementById('cliente_busqueda');if(cli)cli.setAttribute('aria-keyshortcuts','Control+K');
    const rep=document.getElementById('buscar_repuesto');if(rep)rep.setAttribute('aria-keyshortcuts','Alt+R');
  }
  function focusId(id){const e=document.getElementById(id);if(e&&e.offsetParent!==null){e.focus();return true}return false;}
  function atajos(){
    if(document.body.dataset.v390Shortcuts)return;document.body.dataset.v390Shortcuts='1';
    document.addEventListener('keydown',function(e){
      if(e.ctrlKey&&!e.altKey&&String(e.key).toLowerCase()==='k'){if(focusId('cliente_busqueda'))e.preventDefault();return;}
      if(e.altKey&&!e.ctrlKey){
        const k=String(e.key).toLowerCase();
        if(k==='a'&&focusId('accesorio_selector_rapido'))e.preventDefault();
        else if(k==='i'&&(focusId('senal_indicador_ext_modelo_v190')||focusId('senal_indicador_modelo')))e.preventDefault();
        else if(k==='r'&&focusId('buscar_repuesto'))e.preventDefault();
      }
    });
  }
  function ordenTabNatural(){
    /* No usamos tabindex positivo: conservamos el orden DOM Control -> Señalización -> Accesorios,
       evitando saltos artificiales y preservando todos los handlers originales. */
    document.querySelectorAll('#v302_board button,#v302_board input,#v302_board select,#v302_board textarea').forEach(function(el){
      if(el.getAttribute('tabindex') && Number(el.getAttribute('tabindex'))>0) el.setAttribute('tabindex','0');
    });
  }
  function observarResumen(){
    const old=document.getElementById('panel_calculo_cotizador');
    const compact=document.getElementById('v307_resumen');
    const target=old||compact;if(!target||target.dataset.v390Observer)return;
    target.dataset.v390Observer='1';
    new MutationObserver(function(){requestAnimationFrame(mejorarCabeceras)}).observe(target,{subtree:true,childList:true,characterData:true,attributes:true});
  }
  function init(){
    if(!desktop()){document.body.classList.remove('ui-v390');return;}
    document.body.classList.add('ui-v390');
    mejorarCabeceras();validacionVisual();ayudasBusqueda();atajos();ordenTabNatural();observarResumen();
    setTimeout(mejorarCabeceras,200);setTimeout(mejorarCabeceras,800);
  }
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('load',init);
  window.addEventListener('resize',init);
  document.addEventListener('change',function(e){
    if(e.target&&Object.values(defs).some(d=>d.inc===e.target.id))setTimeout(mejorarCabeceras,20);
  },true);
})();
