/* AUTOMAC v431 - JavaScript modularizado. Mantiene nombres globales y orden original. */

/* ---- automac-v377-paradas-indicador-suelto-js (extraido de index.php, linea original 7438) ---- */
(function(){
  const byId=id=>document.getElementById(id);
  function esElectromecanico(){const t=tipoModuloSenalizacionActual();return t.includes('ELECTROMEC');}
  function itemSueltoEsMaestro(it){
    if(!it)return false;
    const rol=String(it.rol||'').toUpperCase();
    const tipo=String(it.tipo||'').toUpperCase();
    const codigo=String(it.codigo||'').toUpperCase();
    return rol==='MAESTRO'||tipo==='ELECTROMECANICO'||/^A40/.test(codigo);
  }
  function hayMaestroSueltoGuardado(){
    try{return typeof itemsIndicadoresExteriorV190==='function'&&itemsIndicadoresExteriorV190().some(itemSueltoEsMaestro);}catch(_){return false;}
  }
  function editorSueltoEsMaestro(){
    const rol=String(byId('senal_indicador_ext_rol_v375')?.value||'').toUpperCase();
    const tipo=String(byId('senal_indicador_ext_modelo_v190')?.selectedOptions?.[0]?.dataset?.tipo||'').toUpperCase();
    return rol==='MAESTRO'||tipo==='ELECTROMECANICO';
  }
  function cabinaEsMaestro(){
    const modelo=String(byId('senal_indicador_modelo')?.value||'').trim();
    const rol=String(byId('senal_indicador_rol')?.value||'MAESTRO').toUpperCase();
    return modelo!==''&&rol==='MAESTRO';
  }
  function ubicarCampo(enCabina){
    const wrap=byId('v375_maestro_paradas_global');
    if(!wrap)return;
    if(enCabina){
      const base=byId('senal_paso_indicador_cuerpo');
      if(base&&wrap.parentElement!==base)base.appendChild(wrap);
    }else{
      const grid=byId('senal_indicador_ext_editor_v190')?.querySelector('.senal-pulsador-campos-v179');
      if(grid&&wrap.parentElement!==grid){
        const cantidad=byId('senal_indicador_ext_cantidad_v190')?.closest('.campo');
        if(cantidad&&cantidad.parentElement===grid)cantidad.after(wrap); else grid.prepend(wrap);
      }
    }
    const strong=wrap.querySelector('strong');
    const small=wrap.querySelector('small');
    const input=byId('senal_indicador_maestro_paradas');
    if(strong)strong.textContent='Paradas del sistema · Maestro A4000';
    if(small)small.textContent='Obligatorio para el indicador autónomo. Define APPIND y el material de hueco.';
    if(input)input.placeholder='Ej.: 9';
  }
  function refrescarCampo(){
    const wrap=byId('v375_maestro_paradas_global');
    const input=byId('senal_indicador_maestro_paradas');
    if(!wrap)return;
    const maestroCabina=esElectromecanico()&&cabinaEsMaestro();
    const maestroSuelto=esElectromecanico()&&(editorSueltoEsMaestro()||hayMaestroSueltoGuardado());
    ubicarCampo(maestroCabina);
    const mostrar=maestroCabina||maestroSuelto;
    wrap.classList.toggle('visible',mostrar);
    if(input){
      input.required=mostrar;
      input.disabled=false;
    }
  }
  const oldAgregar=window.agregarIndicadorExteriorV190;
  if(typeof oldAgregar==='function'){
    window.agregarIndicadorExteriorV190=function(){
      if(esElectromecanico()&&editorSueltoEsMaestro()){
        const p=byId('senal_indicador_maestro_paradas');
        if(!p||Number(p.value||0)<1){
          alert('Defina la cantidad de paradas del indicador maestro A4000.');
          refrescarCampo();
          p?.focus();
          return;
        }
      }
      const r=oldAgregar.apply(this,arguments);
      setTimeout(refrescarCampo,0);
      return r;
    };
  }
  const oldModificar=window.modificarIndicadorExteriorV190;
  if(typeof oldModificar==='function'){
    window.modificarIndicadorExteriorV190=function(){
      if(esElectromecanico()&&editorSueltoEsMaestro()){
        const p=byId('senal_indicador_maestro_paradas');
        if(!p||Number(p.value||0)<1){alert('Defina la cantidad de paradas del indicador maestro A4000.');refrescarCampo();p?.focus();return;}
      }
      const r=oldModificar.apply(this,arguments);setTimeout(refrescarCampo,0);return r;
    };
  }
  const oldEliminar=window.eliminarIndicadorExteriorV190;
  if(typeof oldEliminar==='function')window.eliminarIndicadorExteriorV190=function(){const r=oldEliminar.apply(this,arguments);setTimeout(refrescarCampo,0);return r;};
  const oldEditar=window.editarIndicadorExteriorV190;
  if(typeof oldEditar==='function')window.editarIndicadorExteriorV190=function(){const r=oldEditar.apply(this,arguments);setTimeout(refrescarCampo,0);return r;};

  document.addEventListener('change',function(e){
    if(['senal_indicador_rol','senal_indicador_modelo','senal_indicador_ext_rol_v375','senal_indicador_ext_modelo_v190','senal_usar_control'].includes(e.target?.id))setTimeout(refrescarCampo,0);
  });
  document.addEventListener('input',function(e){if(e.target?.id==='senal_indicador_maestro_paradas'&&typeof programarCalculoSenalizacion==='function')programarCalculoSenalizacion(100);});
  function init(){setTimeout(refrescarCampo,0);setTimeout(refrescarCampo,350);setTimeout(refrescarCampo,1000);}
  if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
  window.addEventListener('load',init);
})();


/* ---- automac-v378-indicador-suelto-completo-js (extraido de index.php, linea original 7530) ---- */
(function(){
 const byId=id=>document.getElementById(id);
 function esElectromecanico(){const t=tipoModuloSenalizacionActual();return t.includes('ELECTROMEC');}
 function asegurarUI(){
   const grid=byId('senal_indicador_ext_editor_v190')?.querySelector('.senal-pulsador-campos-v179');
   if(!grid)return;
   const rol=byId('senal_indicador_ext_rol_v375');
   if(rol){
     const opRep=[...rol.options].find(o=>o.value==='REPETIDOR');
     if(opRep)opRep.textContent='Repetidor / esclavo · Base A4400';
     const opMae=[...rol.options].find(o=>o.value==='MAESTRO');
     if(opMae)opMae.textContent='Maestro · Base A4000';
   }
   if(!byId('senal_indicador_ext_nomenclatura_v378')){
     const d=document.createElement('div');
     d.className='campo v378-second-row';d.id='senal_indicador_ext_nomenclatura_wrap_v378';
     d.innerHTML='<label>Nomenclatura de paradas</label><input type="text" id="senal_indicador_ext_nomenclatura_v378" maxlength="250" placeholder="Ej.: Pb, 1, 2, 3, 4"><small>Identificación de pisos/paradas que debe mostrar el sistema.</small>';
     const med=byId('senal_indicador_ext_medida_v190')?.closest('.campo');
     if(med)med.before(d);else grid.appendChild(d);
   }
   const med=byId('senal_indicador_ext_medida_v190')?.closest('.campo');if(med)med.classList.add('v378-second-row');
   const ac=byId('senal_indicador_ext_acabado_v190');if(ac){
     const aw=ac.closest('.campo');if(aw)aw.classList.add('v378-second-row');
     if(![...ac.options].some(o=>o.value==='ESPECIAL')){const o=document.createElement('option');o.value='ESPECIAL';o.textContent='Especial / a definir';ac.appendChild(o);}
     const lab=aw?.querySelector('label');if(lab)lab.textContent='Frente / acabado';
   }
   const me=byId('senal_indicador_ext_medida_especial_v190')?.closest('.senal-option-card');if(me)me.classList.add('v378-second-row');
   if(!byId('v378_indicador_suelto_ayuda')){
     const h=document.createElement('div');h.id='v378_indicador_suelto_ayuda';h.className='v378-ayuda';
     h.innerHTML='<strong>Electromecánico:</strong> Maestro A4000 genera la señal y lleva material de hueco; Repetidor/esclavo A4400 toma la señal del maestro. Las paradas son obligatorias solamente para el maestro.';
     const acts=grid.querySelector('.senal-pulsador-actions-v179');if(acts)acts.after(h);else grid.appendChild(h);
   }
   refrescarParadas();
 }
 function refrescarParadas(){
   const rol=String(byId('senal_indicador_ext_rol_v375')?.value||'').toUpperCase();
   const wrap=byId('v375_maestro_paradas_global');
   if(wrap)wrap.classList.toggle('visible',esElectromecanico()&&rol==='MAESTRO');
 }
 function envolverFunciones(){
   if(window.__v378_wrapped)return;window.__v378_wrapped=true;
   const oldCap=window.capturarIndicadorExteriorV190;
   if(typeof oldCap==='function')window.capturarIndicadorExteriorV190=function(){
     const it=oldCap.apply(this,arguments);if(!it)return it;
     it.nomenclatura=String(byId('senal_indicador_ext_nomenclatura_v378')?.value||'').trim();
     if(String(it.acabado||'').toUpperCase()==='ESPECIAL')it.acabado_especial=1;
     return it;
   };
   const oldEdit=window.editarIndicadorExteriorV190;
   if(typeof oldEdit==='function')window.editarIndicadorExteriorV190=function(i){
     const it=(typeof itemsIndicadoresExteriorV190==='function'?itemsIndicadoresExteriorV190()[i]:null);
     const r=oldEdit.apply(this,arguments);
     asegurarUI();
     const n=byId('senal_indicador_ext_nomenclatura_v378');if(n)n.value=it?.nomenclatura||'';
     if(it?.acabado==='ESPECIAL'){const ac=byId('senal_indicador_ext_acabado_v190');if(ac)ac.value='ESPECIAL';}
     refrescarParadas();return r;
   };
   const oldRender=window.renderIndicadoresExteriorV190;
   if(typeof oldRender==='function')window.renderIndicadoresExteriorV190=function(){
     const r=oldRender.apply(this,arguments);
     const rows=[...document.querySelectorAll('#senal_indicadores_ext_items_list_v190>div')];
     const items=typeof itemsIndicadoresExteriorV190==='function'?itemsIndicadoresExteriorV190():[];
     rows.forEach((row,i)=>{const it=items[i];if(!it)return;const sm=row.querySelector('small');if(sm&&it.nomenclatura)sm.innerHTML+=' · Nomenclatura: '+String(it.nomenclatura).replace(/</g,'&lt;').replace(/>/g,'&gt;');});
     return r;
   };
   const oldAdd=window.agregarIndicadorExteriorV190;
   if(typeof oldAdd==='function')window.agregarIndicadorExteriorV190=function(){
     const r=oldAdd.apply(this,arguments);
     const n=byId('senal_indicador_ext_nomenclatura_v378');if(n)n.value='';return r;
   };
 }
 document.addEventListener('change',e=>{if(e.target?.id==='senal_indicador_ext_rol_v375')setTimeout(refrescarParadas,0);});
 function init(){asegurarUI();envolverFunciones();setTimeout(()=>{asegurarUI();refrescarParadas();},250);setTimeout(asegurarUI,900);}
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',init);else init();
 window.addEventListener('load',init);
})();


/* ---- automac-v384-inclusion-top-align-js (extraido de index.php, linea original 7617) ---- */
(function(){
  function alinearInclusiones(){
    // UI-02: los checks internos ya no son controles visibles; no reubicarlos.
    if(document.body.classList.contains('ui-v150')) return;
    var control=document.getElementById('modulo_control');
    var incControl=document.getElementById('incluir_control');
    var selControl=document.getElementById('selector_plantillas_control');
    if(control && incControl && selControl){
      var box=incControl.closest('.modulo-inclusion');
      if(box && box.parentElement!==control) control.insertBefore(box,selControl);
      else if(box && box.nextElementSibling!==selControl) control.insertBefore(box,selControl);
    }

    var senal=document.getElementById('modulo_senalizacion');
    var incSenal=document.getElementById('senal_incluir_cotizacion');
    var selSenal=document.getElementById('selector_plantillas_senalizacion');
    if(senal && incSenal && selSenal){
      var boxS=incSenal.closest('.modulo-inclusion');
      if(boxS && boxS.parentElement!==senal) senal.insertBefore(boxS,selSenal);
      else if(boxS && boxS.nextElementSibling!==selSenal) senal.insertBefore(boxS,selSenal);
    }
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',alinearInclusiones);
  else alinearInclusiones();
  window.addEventListener('load',function(){ setTimeout(alinearInclusiones,50); });
})();


/* ---- automac-v385-inclusion-first-js (extraido de index.php, linea original 7646) ---- */
(function(){
  function moverPrimero(moduloId, checkboxId){
    var modulo=document.getElementById(moduloId);
    var checkbox=document.getElementById(checkboxId);
    if(!modulo||!checkbox)return;
    var box=checkbox.closest('.modulo-inclusion');
    if(!box)return;
    if(modulo.firstElementChild!==box){
      modulo.insertBefore(box,modulo.firstElementChild);
    }
  }
  function aplicar(){
    moverPrimero('modulo_control','incluir_control');
    moverPrimero('modulo_senalizacion','senal_incluir_cotizacion');
    moverPrimero('modulo_accesorios','incluir_accesorios');
  }
  if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',aplicar);
  else aplicar();
  window.addEventListener('load',function(){aplicar();setTimeout(aplicar,80);});
})();
