<button type="button" id="toggle_panel_presupuesto" class="panel-presupuesto-toggle" aria-controls="panel_calculo_cotizador" aria-expanded="false" title="Mostrar presupuesto en preparación">
    <span class="panel-presupuesto-flecha" aria-hidden="true">›</span>
    <span class="panel-presupuesto-texto">Presupuesto</span>
</button>
<aside id="panel_calculo_cotizador" class="panel-calculo panel-presupuesto-oculto" aria-live="polite" aria-hidden="true">
    <div class="resumen-documento">
      <div class="panel-calculo-cabecera">
        <div><h3>RESUMEN DE LA COTIZACIÓN</h3><div class="panel-resumen-ayuda">Verificá módulos, alertas y total antes de emitir.</div></div>
        <button type="button" class="panel-calculo-cerrar" id="cerrar_panel_presupuesto" aria-label="Cerrar panel de cálculo" title="Cerrar panel de cálculo">×</button>
      </div>
      <?php $resumenControlIncluido = isset($controlIncluidoInicial) ? $controlIncluidoInicial : true; ?><div id="resumen_control" class="resumen-modulo-fila <?= $resumenControlIncluido ? 'incluido' : 'no-incluido' ?>"><span>Control</span><span data-total><?= $resumenControlIncluido ? 'Incluido / pendiente de cálculo' : 'No incluido' ?></span></div>
      <div id="resumen_senalizacion" class="resumen-modulo-fila no-incluido"><span>Señalización</span><span data-total>No incluido</span></div>
      <div id="resumen_iep" class="resumen-modulo-fila no-incluido"><span>IEP</span><span data-total>No incluido</span></div>
      <div id="resumen_accesorios" class="resumen-modulo-fila no-incluido"><span>Accesorios</span><span data-total>No incluido</span></div>
      <div id="resumen_repuestos" class="resumen-modulo-fila no-incluido"><span>Repuestos</span><span data-total>No incluido</span></div>
      <div class="resumen-total-general"><span><small>Total provisional</small>TOTAL GENERAL</span><span id="resumen_total_general">$ 0,00</span></div>
      <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
        <?php if ($pedidoEdicionId > 0): ?>
        <div class="resumen-edicion-pedido" style="margin-top:14px;padding:12px;border:1px solid #f1aeb5;background:#fff5f5;border-radius:10px;">
          <div style="font-weight:800;color:#842029;margin-bottom:7px;">MODIFICANDO PEDIDO <?= escapar($pedidoEdicion['pedido_numero']) ?></div>
          <div class="resumen-acciones" style="margin-top:9px;">
            <button type="button" onclick="guardarRevisionPedidoCompleto(this)" style="background:#dc3545;width:100%;">ACTUALIZAR PEDIDO · NUEVA REVISIÓN</button>
          </div>
        </div>
        <?php else: ?>
        <div class="resumen-acciones">
          <button type="button" class="resumen-accion-emision" data-emision="cotizacion" onclick="var c=document.getElementById('id_cliente'),b=document.getElementById('cliente_busqueda'); if(!c||!String(c.value||'').trim()){alert('Falta seleccionar el CLIENTE. Seleccione un cliente antes de emitir la cotización.'); if(b){b.focus();b.scrollIntoView({behavior:'smooth',block:'center'});} return false;} return guardarDocumentoModular('guardar_cotizacion',this)" style="background:#0d6efd"><?= $cotizacionEdicionId > 0 ? 'GUARDAR COTIZACIÓN' : 'EMITIR COTIZACIÓN' ?></button>
          <?php if ($cotizacionEdicionId <= 0): ?><button type="button" class="resumen-accion-emision" data-emision="pedido" onclick="var c=document.getElementById('id_cliente'),b=document.getElementById('cliente_busqueda'); if(!c||!String(c.value||'').trim()){alert('Falta seleccionar el CLIENTE. Seleccione un cliente antes de generar el pedido.'); if(b){b.focus();b.scrollIntoView({behavior:'smooth',block:'center'});} return false;} return guardarDocumentoModular('generar_pedido_directo',this)" style="background:#198754">PEDIDO DIRECTO</button><?php endif; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <button type="button" class="v15-detalle-toggle" onclick="document.getElementById('v15_detalle_calculo').classList.toggle('abierto');this.textContent=document.getElementById('v15_detalle_calculo').classList.contains('abierto')?'Ocultar detalle de cálculo':'Ver detalle de cálculo';">Ver detalle de cálculo</button>
    <div id="v15_detalle_calculo" class="v15-detalle-calculo">
    <div id="panel_desglose_control">
    <h2 id="titulo_desglose_calculo">Cálculo de Control</h2>
    <div id="estado_calculo" class="panel-estado">Complete los datos obligatorios para calcular</div>

    <div id="calculo_espera" class="calculo-espera">
        <ol class="lista-conceptos">
            <li>Base</li>
            <li>Adicional por paradas</li>
            <li>Adicional cabezales y soportes</li>
            <li>Conexión</li>
            <li>Adicional central Rojas</li>
            <li>Térmico</li>
            <li>Maniobra sabática</li>
            <li>Falta de fase</li>
            <li>Conexión para sistema de emergencia por corte de energía</li>
            <li>Descanso para freno</li>
            <li>Rescate con batería de gel</li>
            <li>Rescates hidráulicos</li>
            <li>Rescates MRL o imán permanente</li>
            <li>Adicional UCM + fuente en todos los pisos para MRL</li>
            <li>Puerta automática + adicional alimentación puerta VF</li>
            <li>Forzador de aire / luz de cortesía</li>
            <li>Patín Otis</li>
            <li>Fuente 24 V para indicadores + 1 térmica</li>
            <li>Concentrador de llamadas</li>
            <li>Interfase</li>
            <li>Fuente switching para botoneras touch</li>
            <li>IEP en control / llave Ramos Mejía</li>
            <li>Posicionamiento por encoder</li>
            <li>Contactor de potencial</li>
            <li>Tándem</li>
            <li>Micronivelación</li>
            <li>Adicional manual 1</li>
            <li>Adicional manual 2</li>
            <li>Adicional manual 3</li>
        </ol>
    </div>
    <iframe id="resultado_calculo" name="resultado_calculo" class="resultado-calculo" scrolling="yes" title="Resultado del cálculo auxiliar" onload="resultadoSenalizacionCargado();"></iframe>
    </div>
    <div id="panel_desglose_accesorios" class="panel-desglose-accesorios">
      <h2>Cálculo de Accesorios</h2>
      <div id="estado_calculo_accesorios" class="panel-estado">Seleccione accesorios para ver el detalle</div>
      <div id="desglose_accesorios_lista" class="desglose-accesorios-lista"><div class="desglose-accesorios-vacio">Todavía no hay accesorios seleccionados.</div></div>
      <div class="desglose-accesorios-total"><span>TOTAL ACCESORIOS</span><span id="desglose_accesorios_total">$ 0</span></div>
    </div>
    <div id="panel_desglose_modular" class="panel-desglose-accesorios" style="display:none">
      <h2 id="desglose_modular_titulo">Cálculo del módulo</h2>
      <div id="estado_calculo_modular" class="panel-estado">Cargue ítems para ver el cálculo</div>
      <div id="desglose_modular_lista" class="desglose-accesorios-lista"><div class="desglose-accesorios-vacio">Todavía no hay ítems cargados.</div></div>
      <div class="desglose-accesorios-total"><span>TOTAL MÓDULO</span><span id="desglose_modular_total">$ 0</span></div>
    </div>
    </div>
</aside>