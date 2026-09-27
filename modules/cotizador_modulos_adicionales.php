    <div id="modulo_accesorios" class="modulo-cotizador accesorios-flujo-v463">
      <div class="modulo-inclusion">
        <label class="checkbox-label modulo-inclusion-label-v382"><input type="checkbox" id="incluir_accesorios" value="1"<?= (count($itemsModularesGuardados['ACCESORIOS'])>0 || count($catalogoAccesoriosGuardados)>0 || $limiteGuardado) ? ' checked' : '' ?>><span class="modulo-inclusion-text-v383" style="font-family:Arial,Helvetica,sans-serif!important;font-size:11px!important;font-weight:700!important;line-height:1.2!important;letter-spacing:0!important;text-transform:none!important;color:#24384a!important;display:inline-block!important;margin:0!important;padding:0!important;">Incluir Accesorios en el documento</span></label>
        <div class="ayuda">Recorrido continuo: Límites → Accesorios → Cable mallado → Sistema Supervisor. No hace falta abrir secciones.</div>
      </div>
      <div class="control-cabecera accesorios-cabecera-v463"><div><h4>Configuración de accesorios</h4><p>Mismo criterio visual que Control: complete de arriba hacia abajo y agregue solamente lo que corresponda al cliente.</p></div><span class="control-tag">CÁLCULO AUTOMÁTICO</span></div>
      <nav class="control-pasos accesorios-pasos-v463" aria-label="Pasos de accesorios">
        <a href="#acc_panel_limites"><span class="n">1</span>Límites</a><a href="#acc_panel_accesorios"><span class="n">2</span>Accesorios</a><a href="#acc_panel_cable_mallado"><span class="n">3</span>Cable mallado</a><a href="#acc_panel_supervisor"><span class="n">4</span>Supervisor</a>
      </nav>
      <div class="control-guia accesorios-guia-v463"><span><b>1.</b> Límites</span><span><b>2.</b> Accesorios y opcionales</span><span><b>3.</b> Metros de cable</span><span><b>4.</b> Supervisor si corresponde</span></div>
      <div class="ui-operational-toolbar modulo-toolbar-simple"><div class="copy"><strong>Accesorios</strong>El sistema toma las reglas ya definidas y evita pedir datos que puede calcular automáticamente.</div><button type="button" class="ui-price-toggle" data-price-toggle="accesorios" aria-pressed="false" onclick="alternarVistaPrecios('accesorios',this)">Ver precios</button></div>

<?php
  $accesoriosSelectorCatalogo = array();
  foreach($catalogoAccesorios as $acc){
    $clave = (string)($acc['accesorio_clave'] ?? '');
    if(!in_array($clave, array('BOTONERA_FOSO','BOTONERA_INSPECCION','ALARMA_EMERGENCIA_12V','GONG_TECHO_CABINA','GONG_TECHO_CABINA_FUENTE'), true)) continue;
    $accesoriosSelectorCatalogo[] = array('key'=>$clave,'label'=>(string)($acc['accesorio_nombre'] ?? $clave),'tipo'=>'catalogo','panel'=>'acc_panel_accesorios','focus'=>'');
  }
  $accesoriosSelectorCatalogo[] = array('key'=>'SINTETIZADOR_BAFLE','label'=>'Sintetizador de voz en bafle','tipo'=>'catalogo','panel'=>'acc_panel_accesorios','focus'=>'');
  // v463: Barreras y Pesador se cotizan como productos dentro de Accesorios.
  $accesoriosSelectorEspeciales = array(
    array('key'=>'BARRERAS','label'=>'Barreras','tipo'=>'configurable','panel'=>'acc_panel_accesorios','focus'=>'bloque_barreras_v33'),
    array('key'=>'PESADOR','label'=>'Pesador de carga','tipo'=>'configurable','panel'=>'acc_panel_accesorios','focus'=>'pesador_tipo')
  );
  $limiteUnico = null;
  foreach($catalogoLimites as $lim){ $limiteUnico=$lim; break; }
  $limiteCodigoV463 = trim((string)($limiteGuardado['codigo'] ?? ($limiteUnico['limite_codigo'] ?? '')));
  $limiteNombreV463 = trim((string)($limiteGuardado['descripcion'] ?? ($limiteUnico['limite_nombre'] ?? 'Límite c/soporte Honeywell')));
?>
      <div id="items_accesorios" class="accesorios-recorrido-v463">
        <section id="acc_panel_limites" class="control-seccion accesorios-seccion-v463">
          <div class="control-seccion-titulo accesorios-titulo-fijo-v463"><span class="control-titulo-texto"><strong>1. Límites</strong><small>Un único modelo. La cantidad se calcula desde Control, pero sólo se suma al documento cuando usted lo confirma.</small></span><span class="control-seccion-acciones"><span class="control-tag">CANTIDAD AUTOMÁTICA</span></span></div>
          <div class="control-seccion-cuerpo" style="display:block">
            <div class="accesorios-limites-v463">
              <div class="campo"><label>Modelo</label><input type="text" value="<?= escapar($limiteNombreV463) ?>" readonly></div>
              <select id="limite_accesorio_select" onchange="seleccionarLimiteAccesorio()" style="display:none"><option value="">Sin modelo</option><?php foreach($catalogoLimites as $lim): $sel=trim((string)$lim['limite_codigo'])===$limiteCodigoV463; ?><option value="<?= escapar($lim['limite_codigo']) ?>" data-nombre="<?= escapar($lim['limite_nombre']) ?>" data-descripcion="<?= escapar($lim['limite_nombre']) ?>" data-precio-base="<?= escapar($lim['precio']) ?>"<?= $sel?' selected':'' ?>><?= escapar($lim['limite_nombre']) ?></option><?php endforeach; ?></select>
              <div id="cantidad_limites_resumen" class="limite-cantidad-destacada" style="<?= $limiteGuardado?'':'display:none;' ?>"><span>Cantidad</span><strong><span id="cantidad_limites_visible"><?= escapar($limiteGuardado['cantidad']??'1') ?></span> u.</strong></div>
              <div id="estado_limites" class="ayuda"></div>
            </div>
<?php $limitePrecioFinal=(float)($limiteGuardado['precio']??0); $limitePrecioBase=isset($limiteGuardado['precio_base'])&&is_numeric($limiteGuardado['precio_base'])?(float)$limiteGuardado['precio_base']:(($limitePrecioFinal>0&&$factorAccesoriosInicial>0)?$limitePrecioFinal/$factorAccesoriosInicial:(float)($limiteUnico['precio']??0)); $limiteBonificado=!empty($limiteGuardado['bonificado']); ?>
            <div id="fila_limite_accesorio" class="item-modular limite-accesorio accesorio-item" data-selector-key="LIMITES" data-seleccionado="<?= $limiteGuardado?'1':'0' ?>" data-bonificado="<?= $limiteBonificado?'1':'0' ?>" data-precio-base="<?= escapar(number_format($limitePrecioBase,4,'.','')) ?>" style="<?= $limiteGuardado?'display:grid;':'display:none;' ?>grid-template-columns:1fr 110px 110px;gap:8px;padding:0 12px 11px">
              <input data-campo="concepto" value="Límites" type="hidden"><input data-campo="codigo" value="<?= escapar($limiteCodigoV463) ?>" type="hidden"><input data-campo="descripcion" value="<?= escapar($limiteNombreV463) ?>" type="hidden">
              <label>Descripción<input value="<?= escapar($limiteNombreV463) ?>" readonly></label><label>Cantidad<input data-campo="cantidad" type="number" min="1" step="1" value="<?= escapar($limiteGuardado['cantidad']??'1') ?>" oninput="document.getElementById('cantidad_limites_visible').textContent=this.value; actualizarResumenDocumento()"></label><label class="ui-price-detail">Precio final<input class="accesorio-precio-final" value="<?= escapar((string)ceil($limitePrecioFinal)) ?>" readonly></label>
              <input class="accesorio-precio-base" type="hidden" value="<?= escapar(number_format($limitePrecioBase,4,'.','')) ?>"><input type="hidden" data-campo="precio" value="<?= escapar((string)ceil($limitePrecioFinal)) ?>">
            </div>
            <div class="configurable-acciones-v460 acciones-linea-v463"><button type="button" class="btn-confirmar-config-v460" data-confirmar-configurable-v460="LIMITES" onclick="confirmarAccesorioConfigurableV460('LIMITES')">Agregar al documento</button></div>
          </div>
        </section>

        <section id="acc_panel_accesorios" class="control-seccion accesorios-seccion-v463">
          <div class="control-seccion-titulo accesorios-titulo-fijo-v463"><span class="control-titulo-texto"><strong>2. Accesorios</strong><small>Productos directos. Barreras y Pesador aparecen acá cuando se seleccionan.</small></span><span class="control-seccion-acciones"><span class="control-tag">PRODUCTOS</span></span></div>
          <div class="control-seccion-cuerpo" style="display:block">
            <div class="accesorios-selector-gestion accesorios-selector-v463">
              <div class="accesorios-selector-campos">
                <div class="campo campo-accesorio-selector"><label for="accesorio_selector_rapido">Accesorio a cotizar</label><select id="accesorio_selector_rapido" onchange="cambiarAccesorioRapidoV204()"><option value="">Seleccione...</option><?php foreach($accesoriosSelectorCatalogo as $opt): ?><option value="<?= escapar($opt['key']) ?>" data-tipo="<?= escapar($opt['tipo']) ?>" data-panel="<?= escapar($opt['panel']) ?>" data-focus="<?= escapar($opt['focus']) ?>"><?= escapar($opt['label']) ?></option><?php endforeach; ?><?php foreach($accesoriosSelectorEspeciales as $opt): ?><option value="<?= escapar($opt['key']) ?>" data-tipo="<?= escapar($opt['tipo']) ?>" data-panel="<?= escapar($opt['panel']) ?>" data-focus="<?= escapar($opt['focus']) ?>"><?= escapar($opt['label']) ?></option><?php endforeach; ?></select></div>
                <div class="campo campo-accesorio-cantidad" id="campo_accesorio_selector_cantidad"><label for="accesorio_selector_cantidad">Cantidad</label><input type="number" id="accesorio_selector_cantidad" min="1" step="1" value="1"></div>
                <div class="accesorios-selector-acciones"><button type="button" class="btn-finalizar" id="btn_accesorio_agregar" onclick="agregarAccesorioRapido()">Agregar</button><button type="button" class="btn-item-manual-accesorio" onclick="event.stopPropagation();agregarItemModular('accesorios')">+ Ítem manual</button></div>
              </div>
              <div class="accesorios-selector-ayuda">Elegí un producto y agregalo. Si elegís Barreras o Pesador, aparecen solamente los datos necesarios debajo.</div>
              <div id="bloque_pesador_v463" class="accesorio-config-inline-v463" style="display:none"><div class="control-seccion-cuerpo"><div class="accesorios-especiales-v33">                <div class="accesorio-especial-bloque">
                  <strong>Pesador de carga</strong><small>El pesador base pertenece a Accesorios. Si lleva frente, se selecciona y valoriza en Señalización → Botonera de cabina.</small>
                  <div class="especial-campos-grid"><label>Tipo<select id="pesador_tipo" onchange="actualizarEspecialesAccesorios()"><option value="">Seleccione...</option><?php foreach($pesadoresBase as $pb): if(($pb['activo']??'SI')!=='SI')continue; ?><option value="<?= escapar($pb['codigo']) ?>"><?= escapar($pb['nombre']) ?> · <?= escapar($pb['codigo']) ?></option><?php endforeach; ?></select></label></div>
                  <div class="configurable-estado-v460" id="pesador_estado">Seleccione el tipo de pesador.</div>
                  <div class="configurable-acciones-v460"><button type="button" class="btn-confirmar-config-v460" data-confirmar-configurable-v460="PESADOR" onclick="confirmarAccesorioConfigurableV460('PESADOR')">Agregar al documento</button><button type="button" class="btn-cancelar-config-v460" onclick="cancelarConfigurableV460()">Cancelar</button></div>
                </div></div></div>
</div>
              <div id="bloque_barreras_inline_v463" class="accesorio-config-inline-v463" style="display:none"><div class="control-seccion-cuerpo"><div class="accesorios-especiales-v33">                <div class="accesorio-especial-bloque" id="bloque_barreras_v33" style="display:none">
                  <strong>Barreras</strong><small>Disponibles solamente con puerta automática PA.</small>
                  <div class="especial-opciones-grid">
                    <?php foreach($barrerasAccesorios as $ba): if(($ba['activo']??'SI')!=='SI')continue; ?>
                    <label class="especial-check"><input type="checkbox" data-barrera-codigo="<?= escapar($ba['barrera_codigo']) ?>" onchange="actualizarEspecialesAccesorios()"> <span><?= escapar($ba['barrera_nombre']) ?><small><?= escapar($ba['barrera_codigo']) ?></small></span><input class="especial-cantidad-mini" type="number" min="1" step="1" value="1" data-barrera-cantidad="<?= escapar($ba['barrera_codigo']) ?>" oninput="actualizarEspecialesAccesorios()"></label>
                    <?php endforeach; ?>
                  </div>
                  <div class="configurable-acciones-v460"><button type="button" class="btn-confirmar-config-v460" data-confirmar-configurable-v460="BARRERAS" onclick="confirmarAccesorioConfigurableV460('BARRERAS')">Agregar al documento</button><button type="button" class="btn-cancelar-config-v460" onclick="cancelarConfigurableV460()">Cancelar</button></div>
                </div></div></div>
</div>
              <div class="accesorios-selector-resumen"><div class="accesorios-selector-resumen-titulo">Accesorios seleccionados</div><div id="accesorios_selector_lista"><div class="accesorios-selector-vacio">Todavía no hay accesorios seleccionados.</div></div></div>
              <div class="accesorios-condiciones-v201"><button type="button" onclick="abrirEditorAccesorioV201('acc_panel_descuentos','Condiciones comerciales','accesorio_descuento_1')">Condiciones comerciales</button></div>
            </div>
            <div class="accesorios-datos-internos-v463" aria-hidden="true">              <div class="accesorios-lista-compacta">
<?php foreach($catalogoAccesorios as $acc):
  $clave=(string)$acc['accesorio_clave'];
  if(!in_array($clave,array('BOTONERA_FOSO','BOTONERA_INSPECCION','ALARMA_EMERGENCIA_12V','GONG_TECHO_CABINA','GONG_TECHO_CABINA_FUENTE'),true)) continue;
  $guard=$catalogoAccesoriosGuardados[$clave]??null; $seleccionado=$guard!==null;
  $precioFinalGuard=(float)($guard['precio']??0); $precioBaseGuard=$guard['precio_base']??''; $bonificadoGuard=!empty($guard['bonificado']);
  $precioBaseCat=$seleccionado && is_numeric($precioBaseGuard)?(float)$precioBaseGuard:(($seleccionado && $precioFinalGuard>0 && $factorAccesoriosInicial>0)?$precioFinalGuard/$factorAccesoriosInicial:(float)$acc['precio_base']);
  $precioFinalCat=$seleccionado?$precioFinalGuard:ceil($precioBaseCat*$factorAccesoriosInicial);
  $cantidadCat=$guard['cantidad']??'1'; $esEspecial=(($acc['accesorio_tipo']??'')==='CALCULO_ESPECIAL');
?>
                <div class="accesorio-catalogo-card <?= $seleccionado?'seleccionado':'' ?>" data-clave="<?= escapar($clave) ?>">
                  <label class="accesorio-catalogo-selector">
                    <input type="checkbox" class="accesorio-catalogo-check" <?= $seleccionado?'checked':'' ?> onchange="toggleAccesorioCatalogo(this)">
                    <span><strong><?= escapar($acc['accesorio_nombre']) ?></strong><?php if(($acc['accesorio_codigo']??'')!==''): ?><small><?= escapar($acc['accesorio_codigo']) ?></small><?php elseif($esEspecial): ?><small>Cálculo especial</small><?php endif; ?></span>
                  </label>
                  <div class="item-modular accesorio-item accesorio-catalogo-fila" data-seleccionado="<?= $seleccionado?'1':'0' ?>" data-bonificado="<?= $bonificadoGuard?'1':'0' ?>" data-precio-base="<?= escapar(number_format($precioBaseCat,4,'.','')) ?>" style="<?= $seleccionado?'display:grid;':'display:none;' ?>">
                    <input type="hidden" data-campo="concepto" value="<?= escapar($acc['accesorio_nombre']) ?>">
                    <input type="hidden" data-campo="codigo" value="<?= escapar((string)($acc['accesorio_codigo']??'')) ?>">
                    <input type="hidden" data-campo="descripcion" value="<?= escapar($acc['accesorio_nombre']) ?>">
                    <label>Cant.<input data-campo="cantidad" type="number" min="1" step="1" value="<?= escapar((string)$cantidadCat) ?>" oninput="this.closest('.item-modular').dataset.cantidadManualV33='1';actualizarResumenDocumento()"></label>
                    <?php if($esEspecial): ?>
                      <label>Precio base<input class="accesorio-precio-base accesorio-precio-base-visible" type="number" min="0" step="1" value="<?= escapar((string)ceil($precioBaseCat)) ?>" oninput="actualizarPrecioAccesorioDesdeBase(this)" placeholder="Manual"></label>
                      <label class="ui-price-detail">Precio final<input class="accesorio-precio-final" value="<?= escapar((string)ceil($precioFinalCat)) ?>" readonly></label>
                      <span class="accesorio-especial-aviso">Cálculo especial: precio base manual hasta implementar la fórmula.</span>
                    <?php else: ?>
                      <input class="accesorio-precio-base" type="hidden" value="<?= escapar(number_format($precioBaseCat,4,'.','')) ?>">
                      <label class="ui-price-detail">Base<input value="<?= escapar((string)ceil($precioBaseCat)) ?>" readonly></label>
                      <label class="ui-price-detail">Final<input class="accesorio-precio-final" value="<?= escapar((string)ceil($precioFinalCat)) ?>" readonly></label>
                    <?php endif; ?>
                    <input type="hidden" data-campo="precio" value="<?= escapar((string)ceil($precioFinalCat)) ?>">
                  </div>
                </div>
<?php endforeach; ?>
<?php
  $sintBafleGuardado = null;
  if (!empty($accesoriosConfigurablesGuardados['SINT_A7600C']) && is_array($accesoriosConfigurablesGuardados['SINT_A7600C'])) {
      $sintBafleGuardado = $accesoriosConfigurablesGuardados['SINT_A7600C'][0] ?? null;
  }
  $sintBafleSeleccionado = is_array($sintBafleGuardado);
  $sintBaflePrecioFinalGuard = (float)($sintBafleGuardado['precio'] ?? 0);
  $sintBaflePrecioBaseGuard = $sintBafleGuardado['precio_base'] ?? '';
  $sintBaflePrecioLista = (float)($preciosEspeciales['A7600C'] ?? 0) * (float)($utilidadesAccesoriosV224['SINTETIZADORES_VOZ'] ?? 1);
  $sintBaflePrecioBase = $sintBafleSeleccionado && is_numeric($sintBaflePrecioBaseGuard)
      ? (float)$sintBaflePrecioBaseGuard
      : (($sintBafleSeleccionado && $sintBaflePrecioFinalGuard > 0 && $factorAccesoriosInicial > 0)
          ? $sintBaflePrecioFinalGuard / $factorAccesoriosInicial
          : $sintBaflePrecioLista);
  $sintBaflePrecioFinal = $sintBafleSeleccionado ? $sintBaflePrecioFinalGuard : ceil($sintBaflePrecioBase * $factorAccesoriosInicial);
  $sintBafleCantidad = $sintBafleGuardado['cantidad'] ?? '1';
  $sintBafleBonificado = !empty($sintBafleGuardado['bonificado']);
?>
                <div class="accesorio-catalogo-card <?= $sintBafleSeleccionado?'seleccionado':'' ?>" data-clave="SINTETIZADOR_BAFLE">
                  <label class="accesorio-catalogo-selector">
                    <input type="checkbox" class="accesorio-catalogo-check" <?= $sintBafleSeleccionado?'checked':'' ?> onchange="toggleAccesorioCatalogo(this)">
                    <span><strong>Sintetizador de voz en bafle</strong><small>A7600C<?= $sintBaflePrecioBase<=0?' · SIN PRECIO BEJERMAN':'' ?></small></span>
                  </label>
                  <div class="item-modular accesorio-item accesorio-catalogo-fila" data-seleccionado="<?= $sintBafleSeleccionado?'1':'0' ?>" data-bonificado="<?= $sintBafleBonificado?'1':'0' ?>" data-precio-base="<?= escapar(number_format($sintBaflePrecioBase,4,'.','')) ?>" style="<?= $sintBafleSeleccionado?'display:grid;':'display:none;' ?>">
                    <input type="hidden" data-campo="concepto" value="Sintetizador de voz en bafle">
                    <input type="hidden" data-campo="codigo" value="A7600C">
                    <input type="hidden" data-campo="descripcion" value="Sintetizador de voz en bafle">
                    <label>Cant.<input data-campo="cantidad" type="number" min="1" step="1" value="<?= escapar((string)$sintBafleCantidad) ?>" oninput="this.closest('.item-modular').dataset.cantidadManualV33='1';actualizarResumenDocumento()"></label>
                    <input class="accesorio-precio-base" type="hidden" value="<?= escapar(number_format($sintBaflePrecioBase,4,'.','')) ?>">
                    <label class="ui-price-detail">Base<input value="<?= escapar((string)ceil($sintBaflePrecioBase)) ?>" readonly></label>
                    <label class="ui-price-detail">Final<input class="accesorio-precio-final" value="<?= escapar((string)ceil($sintBaflePrecioFinal)) ?>" readonly></label>
                    <input type="hidden" data-campo="precio" value="<?= escapar((string)ceil($sintBaflePrecioFinal)) ?>">
                  </div>
                </div>
              </div>
</div>
          </div>
        </section>

        <section id="acc_panel_cable_mallado" class="control-seccion accesorios-seccion-v463">
          <div class="control-seccion-titulo accesorios-titulo-fijo-v463"><span class="control-titulo-texto"><strong>3. Cable mallado</strong><small>Ingresá los metros. El sistema determina automáticamente sección, código y precio según la corriente.</small></span><span class="control-seccion-acciones"><span class="control-tag">POR METRO</span></span></div>
          <div class="control-seccion-cuerpo">
            <div class="accesorios-especiales-v33"><div class="accesorio-especial-bloque" id="bloque_cable_mallado_v33">
              <strong>Cable mallado</strong><small id="cable_mallado_estado">Con Control incluido, la corriente del variador se completa automáticamente. Sin Control, puede indicarla para cotizar el cable.</small>
              <div class="especial-campos-grid cable-mallado-grid-v459">
                <label>Modelo / código<input id="cable_mallado_modelo" readonly placeholder="Se determina por corriente"></label>
                <label>Corriente desde Control<input id="cable_mallado_corriente" readonly placeholder="Sin dato automático"></label>
                <label id="cable_mallado_corriente_manual_wrap">Corriente del variador (A)<input id="cable_mallado_corriente_manual" type="number" min="0" max="80" step="0.1" value="0" oninput="actualizarEspecialesAccesorios()" placeholder="Se completa desde Control"></label>
                <label>Metros<input id="cable_mallado_metros" type="number" min="0" step="1" value="0" oninput="actualizarEspecialesAccesorios()" placeholder="Ej.: 25"></label>
              </div>
              <div class="cable-mallado-resultado-v459" id="cable_mallado_resultado">Ingrese corriente y metros para cotizar.</div>
              <div class="configurable-acciones-v460"><button type="button" class="btn-confirmar-config-v460" data-confirmar-configurable-v460="CABLE_MALLADO" onclick="confirmarAccesorioConfigurableV460('CABLE_MALLADO')">Agregar al documento</button><button type="button" class="btn-cancelar-config-v460" onclick="cancelarConfigurableV460()">Cancelar</button></div>
            </div></div>
          </div>

        </section>

        <section id="acc_panel_supervisor" class="control-seccion accesorios-seccion-v463">
          <div class="control-seccion-titulo accesorios-titulo-fijo-v463"><span class="control-titulo-texto"><strong>4. Sistema Supervisor</strong><small>Último punto del recorrido. Precio NETO; más de 8 ascensores: CONSULTAR.</small></span><span class="control-seccion-acciones"><span class="control-tag">NETO</span></span></div>
          <div class="control-seccion-cuerpo">
            <div class="especial-campos-grid"><label>Cantidad sistemas<input id="supervisor_cantidad" type="number" min="0" step="1" value="0" oninput="actualizarEspecialesAccesorios()"></label><label>Nº ascensores<input id="supervisor_ascensores" type="number" min="1" step="1" value="1" oninput="actualizarEspecialesAccesorios()"></label><label>Nº baterías<input id="supervisor_baterias" type="number" min="0" step="1" value="0" oninput="actualizarEspecialesAccesorios()"></label><label>Puertos PC<input id="supervisor_puertos" type="number" min="0" step="1" value="0" oninput="actualizarEspecialesAccesorios()"></label></div>
            <div class="accesorios-supervisor-nota" id="supervisor_estado">El Sistema Supervisor toma los precios de la base Bejerman seleccionada y no aplica descuentos.</div>
            <div class="configurable-acciones-v460"><button type="button" class="btn-confirmar-config-v460" data-confirmar-configurable-v460="SUPERVISOR" onclick="confirmarAccesorioConfigurableV460('SUPERVISOR')">Agregar al documento</button><button type="button" class="btn-cancelar-config-v460" onclick="cancelarConfigurableV460()">Cancelar</button></div>
          </div>

        </section>

        <div class="accesorios-editor-secundario-v463 accesorios-acordeon" id="accesorios_editor_contextual"><div class="accesorio-editor-contextual-cabecera" id="accesorio_editor_contextual_cabecera" style="display:none"><span id="accesorio_editor_contextual_titulo">Configuración</span><button type="button" onclick="cerrarEditorAccesorioV201()">Cerrar</button></div>
        <div id="acc_panel_descuentos" class="control-seccion">
          <div class="control-seccion-titulo" onclick="toggleControlSeccion(this)" onkeydown="toggleControlSeccionKey(event,this)" role="button" tabindex="0" aria-expanded="true">
            <span class="control-titulo-texto"><strong>5. Condiciones comerciales</strong><small>Descuentos aplicados a Accesorios.</small></span>
            <span class="control-seccion-acciones"><span class="control-tag">DESCUENTOS</span><span class="control-toggle">⌃</span></span>
          </div>
          <div class="control-seccion-cuerpo">
            <div class="accesorios-descuentos-compacto">
              <div class="campo"><label for="accesorio_descuento_1">Descuento 1 (%)</label><input type="number" name="accesorio_descuento_1" id="accesorio_descuento_1" min="0" max="100" step="1" value="<?= (int)$accesorioDescuento1 ?>" oninput="actualizarDescuentosAccesorios()"></div>
              <div class="campo"><label for="accesorio_descuento_2">Descuento 2 (%)</label><input type="number" name="accesorio_descuento_2" id="accesorio_descuento_2" min="0" max="100" step="1" value="<?= (int)$accesorioDescuento2 ?>" oninput="actualizarDescuentosAccesorios()"></div>
              <div class="campo"><label for="accesorio_descuento_3">Descuento 3 (%)</label><input type="number" name="accesorio_descuento_3" id="accesorio_descuento_3" min="0" max="100" step="1" value="<?= (int)$accesorioDescuento3 ?>" oninput="actualizarDescuentosAccesorios()"></div>
            </div>
          </div>
        </div>


        </div>
        <div id="items_especiales_accesorios_v33"></div>
<?php renderizarAccesoriosGuardados($itemsModularesGuardados['ACCESORIOS'], $factorAccesoriosInicial); ?>
      </div>

      <div class="flujo-acciones"><span class="flujo-estado"><strong>Accesorios:</strong> complete el recorrido de arriba hacia abajo; no hace falta abrir ni cerrar secciones.</span><div class="grupo-derecha tres-acciones"><button type="button" class="btn-volver" onclick="navegarModuloSinIncluir('senalizacion')">← Volver a Señalización</button><button type="button" class="btn-finalizar" onclick="finalizarModuloActual('accesorios')">Terminar cotización y revisar resumen ✓</button><button type="button" class="btn-siguiente" onclick="continuarFlujoModulo('accesorios')">Seguir a IEP →</button></div></div>
    </div>
    <div id="modulo_iep" class="modulo-cotizador"><h3>IEP</h3><div class="modulo-inclusion"><label class="checkbox-label"><input type="checkbox" id="incluir_iep" value="1"<?= count($itemsModularesGuardados['IEP'])>0 ? ' checked' : '' ?>><strong>Incluir IEP en el documento</strong></label><div class="ayuda">Marque esta opción solamente cuando la cotización incluya IEP.</div></div><div class="ui-operational-toolbar"><div class="copy"><strong>Modo operativo · IEP</strong>Cargá concepto, código, cantidad y precio. El total del módulo se ve en el resumen derecho.</div></div><div id="items_iep"><?php renderizarItemsModuloGuardados($itemsModularesGuardados['IEP']); ?></div><div class="acciones-cotizacion"><button type="button" onclick="agregarItemModular('iep')" style="background:#6c757d">AGREGAR OTRO ÍTEM</button></div><div class="flujo-acciones"><span class="flujo-estado"><strong>IEP:</strong> último módulo. Deje marcada la inclusión si corresponde, o desmárquela para indicar que no lleva.</span><div class="grupo-derecha tres-acciones"><button type="button" class="btn-volver" onclick="navegarModuloSinIncluir('accesorios')">← Volver a Accesorios</button><button type="button" class="btn-finalizar" onclick="finalizarFlujoCotizacion()">Terminar cotización y revisar resumen ✓</button><button type="button" class="btn-siguiente" onclick="navegarModuloSinIncluir('repuestos')">Seguir a Repuestos →</button></div></div></div>
    <div id="modulo_repuestos" class="modulo-cotizador"><h3>Repuestos</h3><div class="aviso" style="margin-bottom:12px"><strong>Módulo integrado.</strong> Podés incluir uno o varios repuestos junto con cualquier otro módulo del documento.</div><div class="modulo-inclusion"><label class="checkbox-label"><input type="checkbox" id="incluir_repuestos" value="1"<?= count($itemsModularesGuardados['REPUESTOS'])>0 ? ' checked' : '' ?>><strong>Incluir Repuestos en el documento</strong></label><div class="ayuda">Busque por código o descripción y seleccione los artículos. El resumen derecho mantiene el total actualizado.</div></div><div class="ui-operational-toolbar modulo-toolbar-simple repuestos-toolbar-v165"><div class="copy"><strong>Consulta y selección de repuestos</strong>Escribí un código o descripción para consultar precios. Solo agregá al documento los artículos que necesites.</div></div><div class="repuestos-filtros repuestos-filtros-v165"><div class="repuestos-categoria"><label for="categoria_repuesto">Categoría</label><select id="categoria_repuesto"><option value="">Todas las categorías</option><?php $rcat=$conexion->query("SELECT DISTINCT categoria FROM productos_repuestos WHERE habilitado=1 AND categoria<>'' ORDER BY categoria");if($rcat)while($cat=$rcat->fetch_assoc()):?><option value="<?= escapar($cat['categoria']) ?>"><?= escapar($cat['categoria']) ?></option><?php endwhile;?></select></div><div class="repuestos-buscador"><label for="buscar_repuesto">Buscar producto</label><input type="search" id="buscar_repuesto" autocomplete="off" placeholder="Código o descripción..."><div class="repuestos-lista-info">Escriba al menos dos caracteres o seleccione una categoría.</div></div><div class="repuestos-descuento-consulta" aria-label="Descuento a aplicar al agregar"><strong>Descuento al agregar:</strong><label><input type="radio" name="repuesto_descuento_busqueda" value="0"<?= $defaultRepDescuento===0?' checked':'' ?>> Sin descuento</label><label><input type="radio" name="repuesto_descuento_busqueda" value="15"<?= $defaultRepDescuento===15?' checked':'' ?>> 15%</label><label><input type="radio" name="repuesto_descuento_busqueda" value="30"<?= !in_array($defaultRepDescuento,array(0,15),true)?' checked':'' ?>> 30%</label></div><div id="resultados_repuestos" class="repuestos-resultados" aria-live="polite"><div class="repuestos-resultados-cabecera"><strong>Catálogo de repuestos</strong><span>Busque para consultar precios o agregar artículos.</span></div><div class="repuestos-tabla-scroll"><table class="repuestos-tabla-busqueda"><thead><tr><th>Código</th><th>Descripción</th><th>Categoría</th><th class="precio-col precio-col-0">Precio</th><th class="precio-col precio-col-15">15%</th><th class="precio-col precio-col-30 descuento-activo">30%</th><th>Cantidad</th><th>IVA</th><th>Acción</th></tr></thead><tbody id="repuestos_resultados_body"><tr class="repuestos-vacio-busqueda"><td colspan="9">Seleccioná una categoría o escribí al menos dos caracteres.</td></tr></tbody></table></div></div></div><div id="repuestos_integracion_panel" class="repuestos-integracion-panel" style="display:none"><div class="repuestos-integracion-head"><div><strong>Repuestos relacionados</strong><small>Sugerencias y reglas configuradas desde Mantenimiento.</small></div><span id="repuestos_integracion_estado"></span></div><div id="repuestos_integracion_lista"></div></div><div id="items_repuestos"><section class="repuestos-inventario-seleccion"><div class="repuestos-inventario-title"><div><span>SELECCIONADOS</span><strong>Repuestos seleccionados</strong></div><small>Los precios quedan siempre visibles para facilitar la consulta.</small></div><div class="repuestos-inventario-head"><span>Artículo</span><span>Descuento</span><span>Cantidad</span><span>Precio unitario</span><span>Total</span><span>Acción</span></div><div id="repuestos_seleccionados"><?php renderizarRepuestosGuardados($itemsModularesGuardados['REPUESTOS']); ?><?php $hayRepuestosCatalogo=false; foreach($itemsModularesGuardados['REPUESTOS'] as $itRep){if(!esItemManualRepuesto($itRep)){$hayRepuestosCatalogo=true;break;}} if(!$hayRepuestosCatalogo):?><div class="repuestos-vacio">Todavía no agregó repuestos de catálogo.</div><?php endif;?></div></section><div class="repuestos-manuales"><h4>Ítems adicionales</h4><div class="ayuda">Tres renglones para conceptos especiales. Un ítem adicional solo entra al valorizado cuando tiene concepto, descripción, cantidad y precio completos.</div><?php renderizarRepuestosManualesGuardados($itemsModularesGuardados['REPUESTOS']); ?></div></div><div class="flujo-acciones repuestos-finalizacion"><span class="flujo-estado"><strong>Venta de Repuestos:</strong> al finalizar se abrirá el resumen para verificar cálculos y luego emitir cotización o pedido.</span><div class="grupo-derecha dos-acciones"><button type="button" class="btn-volver" onclick="navegarModuloSinIncluir('iep')">← Volver a IEP</button><button type="button" class="btn-finalizar" onclick="finalizarRepuestos()">Terminar y revisar resumen ✓</button></div></div></div>
