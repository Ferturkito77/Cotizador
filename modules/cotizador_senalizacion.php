    <div id="modulo_senalizacion" class="modulo-cotizador">
        <div id="senal_datos_comunes" class="aviso" style="margin-bottom:12px;line-height:1.55;">Los datos de cliente, lista y referencia se toman de la cabecera general de la cotización.</div>
        <form id="form_senalizacion" action="calcular_senalizacion.php" method="post" target="resultado_calculo" onsubmit="return prepararSenalizacion();" data-cotizador-version="v140"><?=automacCsrfInput()?>
          <input type="hidden" name="accion_senalizacion" id="senal_accion" value="calcular">
          <input type="hidden" name="senal_formato_dinamico" value="1">
          <input type="hidden" name="senal_lista_id" id="senal_lista_id" value="">
          <input type="hidden" name="senal_cliente_id" id="senal_cliente_id" value="">
          <input type="hidden" name="senal_referencia" id="senal_referencia" value="">
          <input type="hidden" name="senal_tiene_control" id="senal_tiene_control" value="0">
          <input type="hidden" name="senal_comunicacion_serie_desde_control" id="senal_comunicacion_serie_desde_control" value="0">
          <div id="selector_plantillas_senalizacion" class="selector-plantillas-senalizacion">
            <div class="senal-plantilla-campo">
              <label for="senal_plantilla_rapida">Configuración rápida</label>
              <select id="senal_plantilla_rapida">
                <option value="">Plantillas de Señalización...</option>
                <?php foreach ($plantillasSenalizacionDisponibles as $ps): $cfgPs = array(
                  'senal_tipo_modulo'=>(string)$ps['senal_tipo_modulo'],
                  'senal_tipo_puerta'=>(string)$ps['senal_tipo_puerta'],
                  'senal_modelo'=>(string)$ps['senal_modelo'],
                  'senal_color'=>(string)$ps['senal_color'],
                  'senal_tecla'=>(string)$ps['senal_tecla'],
                  'senal_tension'=>(string)$ps['senal_tension'],
                  'senal_borne_manual'=>(string)$ps['senal_borne_manual'],
                  'senal_indicador_modelo'=>(string)($ps['senal_indicador_modelo'] ?? '')
                ); ?>
                <option value="<?= (int)$ps['plantilla_id'] ?>" data-config="<?= escapar(json_encode($cfgPs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"><?= escapar($ps['plantilla_codigo'].' — '.$ps['plantilla_nombre']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="button" onclick="aplicarPlantillaSenalizacionV338()">APLICAR</button>
            <div class="senal-plantilla-links"><span>La plantilla completa la botonera y, si está definido, también el indicador de cabina. Sin plantilla, configure la botonera manualmente.</span><a href="administrar_plantillas_senalizacion.php">Crear / modificar plantillas</a></div>
            <div id="senal_plantilla_estado" class="senal-plantilla-estado"></div>
          </div>
          <div class="modulo-inclusion"><label class="checkbox-label modulo-inclusion-label-v382"><input type="checkbox" id="senal_incluir_cotizacion" value="1"<?= !empty($datos['senal_incluir']) ? ' checked' : '' ?>><span class="modulo-inclusion-text-v383" style="font-family:Arial,Helvetica,sans-serif!important;font-size:11px!important;font-weight:700!important;line-height:1.2!important;letter-spacing:0!important;text-transform:none!important;color:#24384a!important;display:inline-block!important;margin:0!important;padding:0!important;">Incluir Señalización en el documento</span></label><div class="ayuda">Desmárquelo para excluir Señalización. Puede combinarse con cualquier otro módulo o utilizarse sola.</div></div>
          
          <div class="senal-contenedor-macro senal-contenedor-cabina senal-macro-abierto" id="senal_contenedor_botonera_cabina">
          <div class="senal-contenedor-macro-cabecera senal-macro-toggle" onclick="toggleContenedorSenalizacion(this,'senal_macro_cabina_body')" onkeydown="toggleContenedorSenalizacionKey(event,this,'senal_macro_cabina_body')" role="button" tabindex="0" aria-expanded="true"><div><span class="senal-contenedor-kicker">SEÑALIZACIÓN DE CABINA</span><h4>1. Botonera de cabina</h4><p>La plantilla completa la configuración disponible. Lo que falte se completa manualmente aquí; después se revisa el indicador y los accesorios de la botonera.</p></div><div class="senal-macro-toggle-right"><span class="senal-precio-tag">VALORIZADO</span><span class="senal-macro-toggle-icon">⌄</span></div></div>
          <div class="senal-contenedor-macro-cuerpo" id="senal_macro_cabina_body">
          <?php $senalIncluirCabina=!array_key_exists('senal_incluir_botonera_cabina',$datos) || !empty($datos['senal_incluir_botonera_cabina']); ?>
          <div class="senal-incluir-cabina-v181">
            <input type="hidden" name="senal_incluir_botonera_cabina" value="0">
            <label class="senal-option-card senal-option-card-principal"><input type="checkbox" id="senal_incluir_botonera_cabina" name="senal_incluir_botonera_cabina" value="1"<?= $senalIncluirCabina?' checked':'' ?> onchange="actualizarInclusionBotoneraCabinaV181(true)"><span><strong>Incluir botonera de cabina</strong><small>Destildá esta opción si el cliente necesita solamente pulsadores exteriores. Pulsador Simple y Doble pueden cotizarse de forma independiente.</small></span></label>
          </div>
          <div id="senal_datos_cabina_v181">
          <div class="senal-cabecera senal-flujo-cabina-v486"><div><h4>Configuración de la botonera</h4><p><strong>Plantilla:</strong> precarga módulos, puerta, pulsador, color, tecla, tensión, bornes y el indicador si fue guardado. <strong>Sin plantilla:</strong> complete estos mismos datos manualmente.</p></div><span class="senal-precio-tag">PLANTILLA O MANUAL</span></div>

          <div class="senal-seccion senal-acordeon-abierto" id="senal_paso_base">
            <div class="senal-seccion-titulo" onclick="toggleSeccionSenal(this,'senal_paso_base_cuerpo')" onkeydown="toggleSeccionSenalKey(event,this,'senal_paso_base_cuerpo')" role="button" tabindex="0" aria-expanded="true"><span class="senal-titulo-texto"><strong>Configuración principal</strong><small>Botoneras, paradas, módulos, puerta, pulsador, color, tecla, tensión y bornes.</small></span><span class="senal-seccion-acciones"><span class="senal-precio-tag">BASE</span><span class="senal-toggle-icon">⌃</span></span></div>
            <div class="senal-seccion-cuerpo senal-base-tres-renglones" id="senal_paso_base_cuerpo">
              <div class="senal-base-renglon senal-base-renglon-sync">
                <label class="senal-sync-inline"><input type="checkbox" id="senal_usar_control" name="senal_usar_control" value="1"<?= !isset($datos['senal_usar_control']) || !empty($datos['senal_usar_control']) ? ' checked' : '' ?>> <span>Usar datos del Control cotizado</span></label>
                <div class="campo"><label>Botoneras</label><input type="number" name="senal_cantidad" value="<?= escapar((string)($datos['senal_cantidad'] ?? '1')) ?>" min="1" required oninput="sincronizarCantidadesAdicionalesSenalizacion(); generarParadasSenalizacion()"></div>
                <?php if($cotizacionEdicionId <= 0 && $pedidoEdicionId <= 0): ?><input type="hidden" name="senal_indicador_sync_botoneras" value="1"><?php endif; ?>
                <input type="hidden" name="senal_paradas" id="senal_paradas" value="<?= escapar((string)($datos['senal_paradas'] ?? '')) ?>">
                <div class="campo senal-paradas-destacado senal-paradas-por-coche"><div class="senal-coches-titulo"><div><strong>Configuración por coche</strong><small>Paradas, nomenclatura y medida se conservan de forma independiente.</small></div><span>BASE incluye 2 paradas por botonera</span></div><div id="senal_paradas_equipos" class="senal-paradas-grid"></div></div>
              </div>

              <div class="senal-base-renglon senal-base-renglon-modelo">
                <div class="campo" id="senal_tipo_modulo_wrap"><label>Módulos</label><select name="senal_tipo_modulo" id="senal_tipo_modulo" onchange="aplicarReglaTipoModuloSenalizacion(); actualizarBornesSenalizacion(); actualizarTensionSenalizacion(); sincronizarPulsadoresExteriorConCabinaV179(); programarCalculoSenalizacion(80);" required><?php $rs=$conexion->query("SELECT * FROM senal_tipos_modulo ORDER BY tipo_modulo_id");if($rs)while($x=$rs->fetch_assoc()):?><option value="<?= (int)$x['tipo_modulo_id'] ?>"<?= valorSeleccionado($datos, 'senal_tipo_modulo', $x['tipo_modulo_id']) ?>><?= escapar($x['tipo_modulo_nombre']) ?></option><?php endwhile;?></select></div>
                <div class="campo"><label>Puerta</label><select name="senal_tipo_puerta" id="senal_tipo_puerta" required><option value="PM"<?= valorSeleccionado($datos, 'senal_tipo_puerta', 'PM') ?>>PM - Manual</option><option value="PA"<?= valorSeleccionado($datos, 'senal_tipo_puerta', 'PA') ?>>PA - Automática</option></select></div>
                <div class="campo senal-modelo-ancho"><label>Modelo de pulsador</label><select name="senal_modelo" id="senal_modelo" onchange="actualizarTeclaSenalizacion(); actualizarBornesSenalizacion(); aplicarReglaOnixSenalizacion(); sincronizarPulsadoresExteriorConCabinaV179()" required><option value="">Seleccione...</option><?php
                  $sqlModelosPerfil='SELECT m.*,p.familia_comercial perfil_familia,p.modo_base perfil_modo_base,p.tipo_modulo_requerido perfil_tipo_modulo,p.pantalla perfil_pantalla,p.indicador_cabina perfil_indicador_cabina,p.indicador_pulsador perfil_indicador_pulsador,p.indicador_exterior_independiente perfil_indicador_exterior,p.indicador_incluido_descripcion perfil_indicador_incluido,p.requiere_luz_cortesia perfil_requiere_luz,p.politica_acabado perfil_politica_acabado,p.acabado perfil_acabado,p.modo_pulsador_exterior perfil_modo_exterior FROM senal_modelos_pulsador m LEFT JOIN senal_modelos_perfiles p ON p.modelo_pulsador_id=m.modelo_pulsador_id AND p.activo=1 ORDER BY m.modelo_pulsador_id';
                  if(!esquemaTablaExiste($conexion,'senal_modelos_perfiles')) $sqlModelosPerfil='SELECT * FROM senal_modelos_pulsador ORDER BY modelo_pulsador_id';
                  $rs=$conexion->query($sqlModelosPerfil);if($rs)while($x=$rs->fetch_assoc()):
                    $perfilModeloOption=array();
                    if(isset($x['perfil_modo_base'])) $perfilModeloOption=array('familia_comercial'=>(string)$x['perfil_familia'],'modo_base'=>(string)$x['perfil_modo_base'],'tipo_modulo_requerido'=>(string)$x['perfil_tipo_modulo'],'pantalla'=>(int)$x['perfil_pantalla'],'indicador_cabina'=>(int)$x['perfil_indicador_cabina'],'indicador_pulsador'=>(int)$x['perfil_indicador_pulsador'],'indicador_exterior_independiente'=>(int)$x['perfil_indicador_exterior'],'indicador_incluido_descripcion'=>(string)$x['perfil_indicador_incluido'],'requiere_luz_cortesia'=>(int)$x['perfil_requiere_luz'],'politica_acabado'=>(string)$x['perfil_politica_acabado'],'acabado'=>(string)$x['perfil_acabado'],'modo_pulsador_exterior'=>(string)$x['perfil_modo_exterior']);
                ?><option value="<?= (int)$x['modelo_pulsador_id'] ?>" data-perfil="<?= escapar(json_encode($perfilModeloOption,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"<?= valorSeleccionado($datos, 'senal_modelo', $x['modelo_pulsador_id']) ?>><?= escapar($x['modelo_pulsador_nombre'].(((int)$x['discontinuado']===1 || strtoupper((string)$x['discontinuado'])==='SI')?' (DISCONTINUADO)':'')) ?></option><?php endwhile;?></select></div>
              </div>

              <div class="senal-base-renglon senal-base-renglon-detalle" id="senal_detalle_convencional">
                <div class="campo"><label>Color</label><select name="senal_color" onchange="sincronizarPulsadoresExteriorConCabinaV179()" required><option value="">Seleccione...</option><?php $rs=$conexion->query("SELECT * FROM senal_colores_registro ORDER BY color_registro_id");if($rs)while($x=$rs->fetch_assoc()):?><option value="<?= (int)$x['color_registro_id'] ?>"<?= valorSeleccionado($datos, 'senal_color', $x['color_registro_id']) ?>><?= escapar($x['color_registro_nombre']) ?></option><?php endwhile;?></select></div>
                <div class="campo"><label>Tecla</label><select name="senal_tecla" id="senal_tecla" onchange="sincronizarPulsadoresExteriorConCabinaV179();programarCalculoSenalizacion(80)" required><option value="">Seleccione...</option><?php $rs=$conexion->query("SELECT * FROM senal_teclas ORDER BY tecla_id");if($rs)while($x=$rs->fetch_assoc()):?><option value="<?= (int)$x['tecla_id'] ?>"<?= valorSeleccionado($datos, 'senal_tecla', $x['tecla_id']) ?>><?= escapar($x['tecla_nombre']) ?></option><?php endwhile;?></select></div>
                <div class="campo"><label>Tensión</label><select name="senal_tension" id="senal_tension" onchange="actualizarBornesSenalizacion();sincronizarPulsadoresExteriorConCabinaV179()" required><option value="">Seleccione...</option><?php $rs=$conexion->query("SELECT * FROM senal_tensiones_modulo ORDER BY tension_modulo_id");if($rs)while($x=$rs->fetch_assoc()):?><option value="<?= (int)$x['tension_modulo_id'] ?>"<?= valorSeleccionado($datos, 'senal_tension', $x['tension_modulo_id']) ?>><?= escapar($x['tension_modulo_nombre']) ?></option><?php endwhile;?></select></div>
                <div class="campo" id="senal_borne_manual_wrap"><label>Bornes</label><select name="senal_borne_manual" id="senal_borne_manual" onchange="sincronizarPulsadoresExteriorConCabinaV179();programarCalculoSenalizacion(80)"><option value="1"<?= valorSeleccionado($datos, 'senal_borne_manual', '1') ?>>3B</option><option value="2"<?= valorSeleccionado($datos, 'senal_borne_manual', '2') ?>>4B</option></select><small id="senal_borne_info" style="display:block;margin-top:5px;color:#667085;font-size:11px">Seleccione 3B o 4B según la botonera a cotizar</small></div>
                <span id="senal_tension_info" class="senal-info-inline">Automático</span>
              </div>
            </div>
          </div>

          <div class="senal-seccion senal-indicador-destacado" id="senal_paso_indicador">
            <div class="senal-seccion-titulo senal-seccion-titulo-fijo-v486"><span class="senal-titulo-texto"><strong>Indicador de cabina</strong><small>Si la plantilla lo incluye aparece seleccionado. Si no, elija el indicador manualmente o deje Sin indicador.</small></span><span class="senal-seccion-acciones"><span class="senal-precio-tag">INDICADOR</span></span></div>
            <div class="senal-seccion-cuerpo senal-principal-compacto" id="senal_paso_indicador_cuerpo">
              <div class="campo" id="senal_indicador_modelo_wrap"><label>Modelo:</label><select name="senal_indicador_modelo" id="senal_indicador_modelo" onchange="normalizarIndicadorSenalizacion()"><option value="">Sin indicador</option><?php $indicadoresCabinaUi=array();foreach($senalIndicadoresExteriorCatalogo as $indicadorUi){if(empty($indicadorUi['activo']))continue;$contextos=$indicadorUi['contextos_permitidos']??array();if((int)($indicadorUi['contextos_configurados']??0)===1&&!in_array('CABINA',$contextos,true))continue;$modeloUi=(string)$indicadorUi['modelo_indicador'];if(!isset($indicadoresCabinaUi[$modeloUi]))$indicadoresCabinaUi[$modeloUi]=array();$tipoUi=strtoupper(trim((string)$indicadorUi['tipo_modulos']));if($tipoUi!==''&&!in_array($tipoUi,$indicadoresCabinaUi[$modeloUi],true))$indicadoresCabinaUi[$modeloUi][]=$tipoUi;}foreach($indicadoresCabinaUi as $modeloUi=>$tiposUi):?><option value="<?= escapar($modeloUi) ?>" data-tipos="<?= escapar(implode('|',$tiposUi)) ?>"<?= valorSeleccionado($datos,'senal_indicador_modelo',$modeloUi) ?>><?= escapar($modeloUi) ?></option><?php endforeach;?></select></div>
              <div class="campo" id="senal_indicador_cantidad_wrap"><label>Cantidad:</label><input type="number" name="senal_indicador_cantidad" id="senal_indicador_cantidad" min="0" step="1"<?= ($cotizacionEdicionId <= 0 && $pedidoEdicionId <= 0) ? ' readonly title="Igual a la cantidad de botoneras"' : ' oninput="marcarCantidadIndicadorManual(this)"' ?> value="<?= escapar((string)($datos['senal_indicador_cantidad'] ?? '0')) ?>"><small><?= ($cotizacionEdicionId <= 0 && $pedidoEdicionId <= 0) ? 'Automático: igual a Botoneras.' : '' ?></small></div>
              <div class="campo"><label>Medidas del calado:</label><input type="text" name="senal_medidas_calado" value="<?= escapar((string)($datos['senal_medidas_calado'] ?? '')) ?>" placeholder="Ej.: 85 x 45 mm"></div>
              <div class="senal-mini-ayuda" id="senal_onix_indicador_info" style="display:none"></div>
              <div class="senal-mini-ayuda" id="senal_indicador_codigo_ayuda"><strong>Código automático:</strong> se resuelve por modelo de indicador + tipo de módulos.</div>
            </div>
          </div>

          <?php
          $cantSenalDefault=(string)($datos['senal_cantidad'] ?? '1');
          $senalEspecialDefaultAsc='0'; $senalEspecialDefaultCom='0';
          foreach(cargarTablaAccesorioEspecial($conexion,'senal_parametros_cabina','clave') as $cfg){
            if($cfg['clave']==='CANT_LLAVE_ASCENSORISTA') $senalEspecialDefaultAsc=cantidadVisualEntera($cfg['valor']);
            if($cfg['clave']==='CANT_COMUNICACION_SERIE') $senalEspecialDefaultCom=cantidadVisualEntera($cfg['valor']);
          }
          $senalAdicSeleccionados=is_array($datos['senal_adicional_sel']??null)?$datos['senal_adicional_sel']:array();
          $senalAdicCantidades=is_array($datos['senal_adicional_cantidad']??null)?$datos['senal_adicional_cantidad']:array();
          $senalAdicBonificados=is_array($datos['senal_adicional_bonificar']??null)?$datos['senal_adicional_bonificar']:array();
          $senalTieneFormatoDinamico=array_key_exists('senal_adicional_sel',$datos);
          $senalAdicionalesUi=array();
          /* v356: garantiza que Logo A31XXGL exista en la matriz antes de armar la lista. */
          try { senalExigirTablas($conexion); } catch (Throwable $e) { /* el calculo mostrara el error si falta parametrizacion */ }
          $legacyAdic=array(
            'ADICIONAL POR PAÑO'=>array('check'=>'senal_adicional_pano_cantidad','cant'=>'senal_adicional_pano_cantidad','bonif'=>'senal_bonificar_pano'),
            'LLAVE DE SERVICIO INDEPENDIENTE'=>array('check'=>'senal_llave_independiente','cant'=>'senal_cant_llave_independiente'),
            'CALADO P/PESADOR DE CARGA'=>array('check'=>'senal_calado_pesador','cant'=>'senal_cant_calado_pesador'),
            'ADICIONAL POR INTERCOMUNICADOR'=>array('check'=>'senal_intercomunicador','cant'=>'senal_cant_intercomunicador'),
            'ADICIONAL TELEFONO MANOS LIBRES'=>array('check'=>'senal_telefono_manos_libres','cant'=>'senal_cant_telefono_manos_libres'),
            'FUENTE PARA INTERCOMUNICADOR'=>array('check'=>'senal_fuente_intercom','cant'=>'senal_cant_fuente_intercom'),
            'LUZ DE EMERGENCIA'=>array('check'=>'senal_luz_emergencia','cant'=>'senal_cant_luz_emergencia'),
            'ACCESIBILIDAD POR VOZ'=>array('check'=>'senal_acces_voz','cant'=>'senal_cant_acces_voz'),
            'BOTONERA CABLEADA'=>array('check'=>'senal_botonera_cableada','cant'=>'senal_cant_botonera_cableada'),
            'MENSAJES ESPECIALES'=>array('check'=>'senal_mensajes_especiales_incluir','cant'=>'senal_cant_mensajes_especiales'),
            'LOGO GRABADO'=>array('check'=>'senal_logo_grabado','cant'=>'senal_logo_cantidad','bonif'=>'senal_logo_bonificar')
          );
          $qAdic="SELECT id, adicional, codigo, COALESCE(NULLIF(etiqueta,''),adicional) etiqueta, tipo_calculo, cantidad_predeterminada, cantidad_editable, sincronizar_botoneras, bonificado_predeterminado, permite_bonificar, mostrar_codigo, orden, 'GENERAL' grupo FROM senal_adicionales_cabina WHERE activo=1 AND visible_cotizador=1 UNION ALL SELECT id, adicional, codigo, COALESCE(NULLIF(etiqueta,''),adicional) etiqueta, tipo_calculo, cantidad_predeterminada, cantidad_editable, sincronizar_botoneras, bonificado_predeterminado, permite_bonificar, mostrar_codigo, orden, 'ESPECIAL' grupo FROM senal_adicionales_especiales_cabina WHERE activo=1 AND aplica=1 AND visible_cotizador=1 ORDER BY orden, etiqueta";
          if($rAdic=$conexion->query($qAdic)){while($a=$rAdic->fetch_assoc())$senalAdicionalesUi[]=$a;}
          ?>
          <div class="senal-seccion senal-adicionales-destacado senal-acordeon-abierto" id="senal_paso_adicionales">
            <div class="senal-seccion-titulo" onclick="toggleSeccionSenal(this,'senal_paso_adicionales_cuerpo')" onkeydown="toggleSeccionSenalKey(event,this,'senal_paso_adicionales_cuerpo')" role="button" tabindex="0" aria-expanded="true"><span class="senal-titulo-texto"><strong>Accesorios de la botonera</strong><small>Agregue sólo los opcionales que pertenecen a esta botonera de cabina.</small></span><span class="senal-seccion-acciones"><span class="senal-precio-tag">ADICIONALES</span><span class="senal-toggle-icon">⌃</span></span></div>
            <div class="senal-seccion-cuerpo" id="senal_paso_adicionales_cuerpo">
              <div class="senal-adicional-grid-compacta">
                <?php foreach($senalAdicionalesUi as $adic):
                  $aid=(int)$adic['id']; $grupo=(string)$adic['grupo']; $akey=$grupo.'_'.$aid;
                  $seleccionado=!empty($senalAdicSeleccionados[$akey]);
                  $cantidadGuardada=$senalAdicCantidades[$akey]??null;
                  $bonificado=$seleccionado ? !empty($senalAdicBonificados[$akey]) : !empty($adic['bonificado_predeterminado']);
                  if(!$senalTieneFormatoDinamico && isset($legacyAdic[$adic['adicional']])){
                    $lm=$legacyAdic[$adic['adicional']];
                    if($adic['adicional']==='ADICIONAL POR PAÑO') $seleccionado=((int)($datos[$lm['check']]??0)>0);
                    else $seleccionado=!empty($datos[$lm['check']]);
                    if($seleccionado && isset($lm['cant']) && array_key_exists($lm['cant'],$datos)) $cantidadGuardada=$datos[$lm['cant']];
                    if($seleccionado && isset($lm['bonif']) && array_key_exists($lm['bonif'],$datos)) $bonificado=!empty($datos[$lm['bonif']]);
                    elseif(!$seleccionado) $bonificado=!empty($adic['bonificado_predeterminado']);
                  }
                  /* En adicionales no seleccionados, la cantidad visible siempre sale de
                   * Mantenimiento -> Senalizacion. Asi un valor viejo guardado en la sesion
                   * no pisa cambios posteriores de cantidad_predeterminada. */
                  if(!$seleccionado || $cantidadGuardada===null || $cantidadGuardada===''){
                    // v357: si la regla es POR_BOTONERA, la cantidad visible es la cantidad real
                    // de botoneras. Para cantidad directa se conserva el valor predeterminado.
                    if(strtoupper((string)$adic['tipo_calculo'])==='POR_BOTONERA'){
                      $cantidadGuardada=(string)max(1,(int)($datos['senal_cantidad'] ?? 1));
                    }else{
                      $cantidadGuardada=(string)$adic['cantidad_predeterminada'];
                    }
                  }
                  $clase=$bonificado?' senal-bonificado':'';
                ?>
                <div class="senal-check senal-adic-compacto<?=$clase?>" data-senal-adicional="<?=escapar($adic['adicional'])?>">
                  <input type="checkbox" name="senal_adicional_sel[<?=escapar($akey)?>]" value="1"<?=$seleccionado?' checked':''?> onchange="programarCalculoSenalizacion(80)">
                  <label><?=escapar($adic['etiqueta'])?><?php if(!empty($adic['mostrar_codigo'])):?><small><?=escapar($adic['codigo'])?></small><?php endif;?></label>
                  <div class="senal-adic-detalle">
                    <input class="senal-cant-adic" style="width:64px" type="number" name="senal_adicional_cantidad[<?=escapar($akey)?>]" min="0" step="1" value="<?=escapar(cantidadVisualEntera($cantidadGuardada))?>" data-sync-botoneras="<?=(!empty($adic['sincronizar_botoneras']) || strtoupper((string)$adic['tipo_calculo'])==='POR_BOTONERA')?'1':'0'?>" data-tipo-calculo="<?=escapar((string)$adic['tipo_calculo'])?>" data-cantidad-predeterminada="<?=escapar(cantidadVisualEntera($adic['cantidad_predeterminada']))?>" <?=empty($adic['cantidad_editable'])?'readonly':''?> oninput="marcarCantidadSenalManual(this)">
                    <?php if(!empty($adic['permite_bonificar'])):?><label title="Bonificar este adicional sin perder su referencia de precio"><input type="checkbox" name="senal_adicional_bonificar[<?=escapar($akey)?>]" value="1"<?=$bonificado?' checked':''?>> Bonificar</label><?php endif;?>
                  </div>
                </div>
                <?php endforeach;?>
                <div class="senal-check senal-adic-compacto"><label>Ascensorista + Subir/Bajar/Completo</label><div class="senal-adic-detalle"><select name="senal_llave_ascensorista_tipo"><option value="">No</option><option value="ELECTRONICO"<?= valorSeleccionado($datos,'senal_llave_ascensorista_tipo','ELECTRONICO') ?>>Electr.</option><option value="ELECTROMECANICO"<?= valorSeleccionado($datos,'senal_llave_ascensorista_tipo','ELECTROMECANICO') ?>>Electromec.</option></select><input class="senal-cant-adic" type="number" name="senal_cant_llave_ascensorista" min="0" step="1" value="<?= escapar(cantidadVisualEntera($datos['senal_cant_llave_ascensorista'] ?? $senalEspecialDefaultAsc)) ?>" oninput="marcarCantidadSenalManual(this)"></div></div>
                <div class="senal-check senal-adic-compacto" id="senal_comunicacion_serie_bloque"><label>Comunicación serie<small id="senal_comunicacion_serie_info">Automática desde Control cuando corresponde</small></label><div class="senal-adic-detalle"><select name="senal_comunicacion_serie_tipo" id="senal_comunicacion_serie_tipo"><option value="">No</option><option value="EN CABINA"<?= valorSeleccionado($datos,'senal_comunicacion_serie_tipo','EN CABINA') ?>>Cabina</option><option value="TOTAL"<?= valorSeleccionado($datos,'senal_comunicacion_serie_tipo','TOTAL') ?>>Total</option></select><input class="senal-cant-adic" type="number" name="senal_cant_comunicacion_serie" min="0" step="1" value="<?= escapar(cantidadVisualEntera($datos['senal_cant_comunicacion_serie'] ?? $senalEspecialDefaultCom)) ?>" oninput="marcarCantidadSenalManual(this)"></div></div>
                <input type="hidden" name="senal_tipo_logo" value="<?= escapar((string)($datos['senal_tipo_logo'] ?? '')) ?>">
                <div class="senal-check senal-adic-compacto senal-braille-auto"><span>✓</span><label>Braille interior<small>Automático salvo A3900</small></label></div>
                <div class="senal-check senal-adic-compacto senal-braille-auto"><span>✓</span><label>Braille lateral<small>Automático en A3900 / ascensorista cuando corresponde</small></label></div>
              </div>
              <div class="senal-especiales-cabina-v169">
                <div class="senal-especial-v169" id="senal_sint_a7601c_bloque">
                  <div><strong>Sintetizador de voz en cabina</strong><small>A7601C · puede utilizarse con distintos indicadores. Se valoriza con la referencia Bejerman P7600V2 y mantiene el código comercial A7601C.</small></div>
                  <label><input type="checkbox" name="senal_sint_a7601c" id="senal_sint_a7601c" value="1"<?= !empty($datos['senal_sint_a7601c'])?' checked':'' ?> onchange="this.dataset.manual='1';actualizarSintetizadoresSenalV474('A7601C');programarCalculoSenalizacion(80)"> Incluir</label>
                  <input class="senal-cant-adic" type="number" name="senal_sint_a7601c_cantidad" id="senal_sint_a7601c_cantidad" min="0" step="1" value="<?= escapar((string)($datos['senal_sint_a7601c_cantidad'] ?? '0')) ?>" oninput="marcarCantidadSenalManual(this);programarCalculoSenalizacion(80)">
                </div>
                <div class="senal-especial-v169" id="senal_sint_a4820sv_bloque">
                  <div><strong>Sintetizador de voz con indicador color A4820 / A4830</strong><small>A4820SV · es el adicional específico cuando el indicador de cabina es A4820/A4830. No se agrega sólo por elegir el indicador: debe seleccionarse el sintetizador.</small></div>
                  <label><input type="checkbox" name="senal_sint_a4820sv" id="senal_sint_a4820sv" value="1"<?= !empty($datos['senal_sint_a4820sv'])?' checked':'' ?> onchange="this.dataset.manual='1';actualizarSintetizadoresSenalV474('A4820SV');programarCalculoSenalizacion(80)"> Incluir</label>
                  <input class="senal-cant-adic" type="number" name="senal_sint_a4820sv_cantidad" id="senal_sint_a4820sv_cantidad" min="0" step="1" value="<?= escapar((string)($datos['senal_sint_a4820sv_cantidad'] ?? '0')) ?>" oninput="marcarCantidadSenalManual(this);programarCalculoSenalizacion(80)">
                </div>
                <div class="senal-especial-v169">
                  <div><strong>Frente de pesador de carga</strong><small>El pesador base queda en Accesorios; el frente pertenece físicamente a la botonera de cabina.</small></div>
                  <select name="senal_pesador_frente_codigo" id="senal_pesador_frente_codigo" onchange="programarCalculoSenalizacion(80)"><option value="">Sin frente</option><?php foreach($pesadoresFrentes as $pf): if(($pf['activo']??'SI')!=='SI'||empty($pf['codigo']))continue; ?><option value="<?= escapar($pf['codigo']) ?>"<?= (($datos['senal_pesador_frente_codigo']??'')===$pf['codigo'])?' selected':'' ?>><?= escapar($pf['frente_nombre']) ?> · <?= escapar($pf['codigo']) ?></option><?php endforeach; ?></select>
                  <input class="senal-cant-adic" type="number" name="senal_pesador_frente_cantidad" id="senal_pesador_frente_cantidad" min="0" step="1" value="<?= escapar((string)($datos['senal_pesador_frente_cantidad'] ?? '0')) ?>" oninput="marcarCantidadSenalManual(this);programarCalculoSenalizacion(80)">
                </div>
                <?php
                  $senalControlAccesoTecnologia=(string)($datos['senal_control_acceso_tecnologia'] ?? '');
                  $senalControlAccesoIncluido=!empty($datos['senal_control_acceso_incluir']) || $senalControlAccesoTecnologia!=='';
                  $senalControlAccesoCantidad=(int)($datos['senal_control_acceso_cantidad'] ?? 0);
                  if($senalControlAccesoCantidad<=0) $senalControlAccesoCantidad=max(1,(int)($datos['senal_cantidad'] ?? 1));
                ?>
                <div class="senal-especial-v169 senal-control-acceso-v169">
                  <input type="checkbox" name="senal_control_acceso_incluir" id="senal_control_acceso_incluir" value="1"<?= $senalControlAccesoIncluido?' checked':'' ?> hidden>
                  <div class="senal-especial-titulo-v169"><strong>Control de accesos</strong><small>Elegí la tecnología y el alcance. La cantidad corresponde a sistemas/botoneras de cabina y por defecto toma la cantidad de botoneras.</small></div>
                  <label id="senal_control_cantidad_wrap">Cantidad<input name="senal_control_acceso_cantidad" id="senal_control_acceso_cantidad" type="number" min="1" step="1" value="<?= escapar((string)$senalControlAccesoCantidad) ?>" oninput="marcarCantidadSenalManual(this);programarCalculoSenalizacion(80)"></label>
                  <label>Tecnología<select name="senal_control_acceso_tecnologia" id="senal_control_acceso_tecnologia" onchange="actualizarControlAccesoSenalV169();programarCalculoSenalizacion(80)"><option value="">Seleccione tecnología...</option><option value="CHIP"<?= $senalControlAccesoTecnologia==='CHIP'?' selected':'' ?>>Chip de contacto</option><option value="TARJETA"<?= $senalControlAccesoTecnologia==='TARJETA'?' selected':'' ?>>Tarjeta de proximidad</option><option value="TECLADO"<?= $senalControlAccesoTecnologia==='TECLADO'?' selected':'' ?>>Teclado</option></select></label>
                  <label id="senal_control_alcance_wrap">Alcance<select name="senal_control_acceso_alcance" id="senal_control_acceso_alcance" onchange="programarCalculoSenalizacion(80)"><option value="PISO_USUARIO"<?= (($datos['senal_control_acceso_alcance']??'PISO_USUARIO')==='PISO_USUARIO')?' selected':'' ?>>A piso de usuario</option><option value="TODA_BOTONERA"<?= (($datos['senal_control_acceso_alcance']??'')==='TODA_BOTONERA')?' selected':'' ?>>A toda la botonera</option></select></label>
                  <label id="senal_control_paradas_wrap">Paradas<input name="senal_control_acceso_paradas" id="senal_control_acceso_paradas" type="number" min="1" max="64" value="<?= escapar((string)($datos['senal_control_acceso_paradas'] ?? '1')) ?>" oninput="this.dataset.manual='1';programarCalculoSenalizacion(80)"></label>
                  <label id="senal_control_chips_wrap" style="display:none">Chips<input name="senal_control_acceso_chips_cantidad" id="senal_control_acceso_chips_cantidad" type="number" min="0" step="1" value="<?= escapar((string)($datos['senal_control_acceso_chips_cantidad'] ?? '1')) ?>" oninput="programarCalculoSenalizacion(80)"></label>
                  <label id="senal_control_tarjetas_wrap" style="display:none">Tarjetas<input name="senal_control_acceso_tarjetas_cantidad" id="senal_control_acceso_tarjetas_cantidad" type="number" min="0" step="1" value="<?= escapar((string)($datos['senal_control_acceso_tarjetas_cantidad'] ?? '1')) ?>" oninput="programarCalculoSenalizacion(80)"></label>
                  <div class="senal-control-acceso-acciones-v481"><button type="button" id="senal_control_acceso_guardar" class="senal-control-acceso-guardar-v481">✓ Guardar cambios</button></div>
                </div>
              </div>

              <div class="senal-principal-compacto senal-campo-total" style="margin-top:8px">
                <div class="campo senal-campo-ancho"><label>Características especiales:</label><input type="text" name="senal_caracteristicas_especiales" value="<?= escapar((string)($datos['senal_caracteristicas_especiales'] ?? '')) ?>" placeholder="Detalle breve"></div>
                <input type="hidden" id="senal_medidas" name="senal_medidas" value="<?= escapar((string)($datos['senal_medidas'] ?? '')) ?>">
                <?php $senalAcabadoCabina=strtoupper(trim((string)($datos['senal_acabado'] ?? 'ACERO'))); if(!in_array($senalAcabadoCabina,array('ACERO','BRONCE','NEGRO'),true)) $senalAcabadoCabina='ACERO'; ?>
                <div class="campo"><label>Acabado de tapa</label><select name="senal_acabado" id="senal_acabado" onchange="programarCalculoSenalizacion(80)"><option value="ACERO"<?= $senalAcabadoCabina==='ACERO'?' selected':'' ?>>Acero</option><option value="BRONCE"<?= $senalAcabadoCabina==='BRONCE'?' selected':'' ?>>Bronce</option><option value="NEGRO"<?= $senalAcabadoCabina==='NEGRO'?' selected':'' ?>>Negro · A DEFINIR</option></select><small>El coeficiente se administra desde Mantenimiento → Señalización.</small></div>
                <label class="senal-option-card"><input type="checkbox" name="senal_medida_especial" id="senal_medida_especial" value="1"<?= !empty($datos['senal_medida_especial'])?' checked':'' ?> onchange="programarCalculoSenalizacion(80)"><span><strong>Medida especial</strong><small>Solo se considera especial cuando esta opción está marcada.</small></span></label>
                <div class="campo senal-campo-ancho"><label>Detalle mensajes especiales:</label><input type="text" name="senal_mensajes_especiales" value="<?= escapar((string)($datos['senal_mensajes_especiales'] ?? '')) ?>" placeholder="Solo completar si seleccionó Mensajes especiales"></div>
              </div>
            </div>
          </div>

          </div><!-- /senal_macro_cabina_body -->
          </div><!-- /senal_contenedor_botonera_cabina -->

          <?php
          // v141: tres contenedores principales. Pulsadores e indicadores externos
          // guardan cantidades tecnicas independientes; aun no generan codigos/precios.
          // Los campos v140/v132 se siguen leyendo para preservar historicos.
          $senalElementoLegacyTipo=(string)($datos['senal_elemento_tipo'] ?? '');
          $senalPulsadorExteriorTipo=(string)($datos['senal_pulsador_exterior_tipo'] ?? '');
          if($senalPulsadorExteriorTipo==='' && in_array($senalElementoLegacyTipo,array('PULSADORES_SIMPLES','PULSADORES_SIMPLES_INDICADOR','PULSADORES_DOBLES','PULSADORES_DOBLES_INDICADOR'),true)) $senalPulsadorExteriorTipo=$senalElementoLegacyTipo;
          $cantPulsoSimples=max(0,(int)($datos['senal_pulsadores_simples_cantidad'] ?? 0));
          $cantPulsoSimplesIndicador=max(0,(int)($datos['senal_pulsadores_simples_indicador_cantidad'] ?? 0));
          $cantPulsoDobles=max(0,(int)($datos['senal_pulsadores_dobles_cantidad'] ?? 0));
          $cantPulsoDoblesIndicador=max(0,(int)($datos['senal_pulsadores_dobles_indicador_cantidad'] ?? 0));
          $senalPulsadorExteriorLlave=array_key_exists('senal_pulsador_exterior_llave_bomberos',$datos)?!empty($datos['senal_pulsador_exterior_llave_bomberos']):(!empty($datos['senal_elemento_llave_bomberos']) && $senalPulsadorExteriorTipo!=='');
          $senalPulsadorExteriorLogo=array_key_exists('senal_pulsador_exterior_logo',$datos)?!empty($datos['senal_pulsador_exterior_logo']):(!empty($datos['senal_elemento_logo']) && $senalPulsadorExteriorTipo!=='');
          $senalPulsadorExteriorBraille=array_key_exists('senal_pulsador_exterior_braille_lateral',$datos)?!empty($datos['senal_pulsador_exterior_braille_lateral']):(!empty($datos['senal_elemento_braille_lateral']) && $senalPulsadorExteriorTipo!=='');
          $senalPulsadorExteriorAcabado=(string)($datos['senal_pulsador_exterior_acabado'] ?? ($senalPulsadorExteriorTipo!=='' ? ($datos['senal_elemento_acabado'] ?? '') : ''));
          $senalPulsadorExteriorMedidas=(string)($datos['senal_pulsador_exterior_medidas'] ?? ($senalPulsadorExteriorTipo!=='' ? ($datos['senal_elemento_medidas'] ?? '') : ''));
          $legacyIndicador=$senalElementoLegacyTipo==='INDICADOR_POSICION' || !empty($datos['senal_indicador_exterior_incluir']);
          $cantInd18=max(0,(int)($datos['senal_indicador_18mm_v5_cantidad'] ?? 0));
          $cantInd31=max(0,(int)($datos['senal_indicador_31mm_v5_cantidad'] ?? 0));
          $cantIndA4610=max(0,(int)($datos['senal_indicador_a4610_cantidad'] ?? 0));
          $cantIndA4600=max(0,(int)($datos['senal_indicador_a4600_cantidad'] ?? 0));
          $cantIndA4810=max(0,(int)($datos['senal_indicador_a4810_cantidad'] ?? 0));
          $cantIndA4830=max(0,(int)($datos['senal_indicador_a4830_cantidad'] ?? 0));
          $cantIndA7260=max(0,(int)($datos['senal_indicador_a7260_cantidad'] ?? 0));
          $senalIndicadorExteriorAcabado=(string)($datos['senal_indicador_exterior_acabado'] ?? ($senalElementoLegacyTipo==='INDICADOR_POSICION' ? ($datos['senal_elemento_acabado'] ?? '') : ''));
          $senalIndicadorExteriorMedidas=(string)($datos['senal_indicador_exterior_medidas'] ?? ($senalElementoLegacyTipo==='INDICADOR_POSICION' ? ($datos['senal_elemento_medidas'] ?? '') : ''));
          $totalPulsadores=$cantPulsoSimples+$cantPulsoSimplesIndicador+$cantPulsoDobles+$cantPulsoDoblesIndicador;
          $totalIndicadores=$cantInd18+$cantInd31+$cantIndA4610+$cantIndA4600+$cantIndA4810+$cantIndA4830+$cantIndA7260;
          $senalIndicadoresExteriorItemsRaw=(string)($datos['senal_indicadores_exteriores_items_json'] ?? '');
          $senalIndicadoresExteriorItemsDec=$senalIndicadoresExteriorItemsRaw!==''?json_decode($senalIndicadoresExteriorItemsRaw,true):array();
          if(is_array($senalIndicadoresExteriorItemsDec) && count($senalIndicadoresExteriorItemsDec)>0){$totalIndicadores=0;foreach($senalIndicadoresExteriorItemsDec as $itIndTmp){$totalIndicadores+=max(0,(int)($itIndTmp['cantidad']??0));}}
          ?>

                    </div><!-- /senal_datos_cabina_v181 -->

          <div class="senal-contenedor-macro senal-contenedor-exteriores senal-macro-abierto" id="senal_contenedor_pulsadores_exteriores">
            <div class="senal-contenedor-macro-cabecera senal-macro-toggle" onclick="toggleContenedorSenalizacion(this,'senal_macro_pulsadores_body')" onkeydown="toggleContenedorSenalizacionKey(event,this,'senal_macro_pulsadores_body')" role="button" tabindex="0" aria-expanded="true"><div><span class="senal-contenedor-kicker">SEÑALIZACIÓN EXTERIOR</span><h4>2. Pulsadores exteriores</h4><p>Cargá en forma independiente la cantidad necesaria de cada tipo de pulsador.</p></div><div class="senal-macro-toggle-right"><span class="senal-cantidad-resumen" id="senal_total_pulsadores"><?= (int)$totalPulsadores ?> unidades</span><span class="senal-precio-tag">MATRIZ + BEJERMAN</span><span class="senal-macro-toggle-icon">⌄</span></div></div>
            <div class="senal-contenedor-macro-cuerpo" id="senal_macro_pulsadores_body">
              <?php if($senalPulsadorExteriorTipo!=='' && $totalPulsadores===0): ?><div class="senal-mini-ayuda" style="margin-bottom:10px"><strong>Dato histórico:</strong> el documento anterior registra <?= escapar(str_replace('_',' ', $senalPulsadorExteriorTipo)) ?> sin cantidad. Se conserva como dato legacy y no se inventa una cantidad.</div><?php endif; ?>
              <?php
              $familiasPulsExt=array(
                'SIMPLE'=>array('titulo'=>'Pulsador simple','cantidad'=>'senal_pulsadores_simples_cantidad','valor'=>$cantPulsoSimples,'ayuda'=>'Sin indicador incorporado.'),
                'SIMPLE_IP'=>array('titulo'=>'Pulsador simple + IP','cantidad'=>'senal_pulsadores_simples_indicador_cantidad','valor'=>$cantPulsoSimplesIndicador,'ayuda'=>'La presentación del pulsador y su indicador depende del perfil del modelo.'),
                'DOBLE'=>array('titulo'=>'Pulsador doble','cantidad'=>'senal_pulsadores_dobles_cantidad','valor'=>$cantPulsoDobles,'ayuda'=>'Sin indicador incorporado.'),
                'DOBLE_IP'=>array('titulo'=>'Pulsador doble + IP','cantidad'=>'senal_pulsadores_dobles_indicador_cantidad','valor'=>$cantPulsoDoblesIndicador,'ayuda'=>'La presentación del pulsador y su indicador depende del perfil del modelo.')
              );
              ?>
              <div class="senal-pulsadores-v179" id="senal_pulsadores_v179" data-matriz-disponible="<?= empty($senalPulsadoresExtMatriz)?'0':'1' ?>">
                <?php if(empty($senalPulsadoresExtMatriz)): ?><div class="senal-mini-ayuda senal-alerta-matriz"><strong>Falta instalar la matriz v179 de Pulsadores exteriores.</strong> Ejecute el SQL incluido con esta versión antes de cotizar estos ítems.</div><?php endif; ?>
                <?php foreach($familiasPulsExt as $fam=>$cfg):
                  $pref='senal_ext_'.strtolower($fam).'_';
                  $agregado=!empty($datos[$pref.'agregado']);
                  $mismo=!array_key_exists($pref.'mismo_modelo',$datos) || !empty($datos[$pref.'mismo_modelo']);
                  $legacy=((int)$cfg['valor']>0 && !$agregado);
                ?>
                <div class="senal-pulsador-card-v179<?= $agregado?' is-active':'' ?>" data-familia="<?= escapar($fam) ?>">
                  <div class="senal-pulsador-card-head-v179">
                    <div><strong><?= escapar($cfg['titulo']) ?></strong><small><?= escapar($cfg['ayuda']) ?></small><?php if($legacy): ?><small class="senal-legacy-v179">Histórico: cantidad guardada sin matriz.</small><?php endif; ?></div>
                    <div class="senal-pulsador-actions-v179">
                      <button type="button" class="btn-pulsador-agregar-v179" onclick="agregarPulsadorExteriorV179('<?= escapar($fam) ?>')">Agregar</button>
                      <button type="button" onclick="modificarPulsadorExteriorV179('<?= escapar($fam) ?>')">Modificar</button>
                      <button type="button" onclick="quitarPulsadorExteriorV179('<?= escapar($fam) ?>')">Quitar</button>
                    </div>
                  </div>
                  <input type="hidden" name="<?= escapar($pref) ?>agregado" id="<?= escapar($pref) ?>agregado" value="<?= $agregado?'1':'0' ?>">
                  <div class="senal-pulsador-editor-v179" id="<?= escapar($pref) ?>editor">
                    <div class="senal-pulsador-grid-v179">
                      <div class="campo senal-pulsador-cantidad-v179"><label>Cantidad</label><input type="number" name="<?= escapar($cfg['cantidad']) ?>" id="<?= escapar($cfg['cantidad']) ?>" min="0" step="1" value="<?= (int)$cfg['valor'] ?>" oninput="actualizarCantidadesSenalizacionExterior();programarCalculoSenalizacion(100)"></div>
                      <input type="hidden" name="<?= escapar($pref) ?>mismo_modelo" value="0"><label class="senal-sync-inline senal-pulsador-mismo-v179"><input type="checkbox" name="<?= escapar($pref) ?>mismo_modelo" id="<?= escapar($pref) ?>mismo_modelo" value="1"<?= $mismo?' checked':'' ?> onchange="cambiarModeloExteriorV179('<?= escapar($fam) ?>')"> <span>Mantener mismo modelo que en cabina</span></label>
                      <div class="campo" id="<?= escapar($pref) ?>modelo_wrap"<?= $mismo?' style="display:none"':'' ?>><label>Modelo exterior</label><select name="<?= escapar($pref) ?>modelo" id="<?= escapar($pref) ?>modelo" data-valor="<?= escapar((string)($datos[$pref.'modelo'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"><option value="">Seleccione...</option></select></div>
                      <div class="campo senal-ext-tipo-v188"><label>Tipo</label><select name="<?= escapar($pref) ?>tipo" id="<?= escapar($pref) ?>tipo" data-valor="<?= escapar((string)($datos[$pref.'tipo'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"></select></div>
                      <div class="campo senal-ext-detalle-v187" data-ext-detalle="color"><label>Color</label><select name="<?= escapar($pref) ?>color" id="<?= escapar($pref) ?>color" data-valor="<?= escapar((string)($datos[$pref.'color'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"></select></div>
                      <div class="campo senal-ext-detalle-v187" data-ext-detalle="tension"><label>Tensión</label><select name="<?= escapar($pref) ?>tension" id="<?= escapar($pref) ?>tension" data-valor="<?= escapar((string)($datos[$pref.'tension'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"></select></div>
                      <div class="campo senal-ext-detalle-v187" data-ext-detalle="bornes"><label>Bornes</label><select name="<?= escapar($pref) ?>bornes" id="<?= escapar($pref) ?>bornes" data-valor="<?= escapar((string)($datos[$pref.'bornes'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"></select></div>
                      <div class="campo senal-ext-detalle-v187" data-ext-detalle="tecla"><label>Tecla</label><select name="<?= escapar($pref) ?>tecla" id="<?= escapar($pref) ?>tecla" data-valor="<?= escapar((string)($datos[$pref.'tecla'] ?? '')) ?>" onchange="refrescarPulsadorExteriorV179('<?= escapar($fam) ?>',true)"></select></div>
                      <?php if(substr($fam,-3)==='_IP'): ?>
                      <div class="campo senal-pulsador-indicador-v181"><label>Indicador exterior</label><select name="<?= escapar($pref) ?>indicador_codigo" id="<?= escapar($pref) ?>indicador_codigo" data-valor="<?= escapar((string)($datos[$pref.'indicador_codigo'] ?? '')) ?>" onchange="refrescarIndicadorExteriorV181('<?= escapar($fam) ?>',true)"><option value="">Seleccione...</option></select><small id="<?= escapar($pref) ?>indicador_modelo_preview">Forma parte del precio compuesto del pulsador + IP.</small></div>
                      <?php endif; ?>
                      <div class="campo"><label>Medida (Ancho × Alto)</label><input type="text" id="<?= escapar($pref) ?>medida" maxlength="120" placeholder="Ej.: 120 x 240 mm"><small>Si queda vacío, en pedido / OF figurará A CONFIRMAR.</small></div>
                      <div class="campo"><label>Acabado de tapa</label><select id="<?= escapar($pref) ?>acabado" onchange="actualizarPrecioPulsadorV182('<?= escapar($fam) ?>',String(document.getElementById('<?= escapar($pref) ?>codigo_preview')?.textContent||''))"><option value="ACERO">Acero</option><option value="BRONCE">Bronce</option><option value="NEGRO">Negro · A DEFINIR</option></select></div>
                      <label class="senal-option-card"><input type="checkbox" id="<?= escapar($pref) ?>medida_especial" value="1" onchange="actualizarPrecioPulsadorV182('<?= escapar($fam) ?>',String(document.getElementById('<?= escapar($pref) ?>codigo_preview')?.textContent||''))"><span><strong>Medida especial</strong><small>Se aplica el coeficiente especial del acabado.</small></span></label>
                      <div class="senal-pulsador-resuelto-v179"><span>Código</span><strong id="<?= escapar($pref) ?>codigo_preview">A RESOLVER</strong></div>
                      <div class="senal-pulsador-resuelto-v179 senal-pulsador-precio-v182"><span>Precio unitario<?= substr($fam,-3)==='_IP'?' conjunto':'' ?></span><strong id="<?= escapar($pref) ?>precio_preview">A RESOLVER</strong><small id="<?= escapar($pref) ?>precio_detalle">Antes de descuentos de Señalización</small></div>
                    </div>
                  </div>
                  <div id="<?= escapar($pref) ?>items_list" class="senal-pulsador-items-v188" style="margin:10px 12px 12px"></div>
                </div>
                <?php endforeach; ?>
              </div>
              <input type="hidden" name="senal_pulsadores_items_json" id="senal_pulsadores_items_json" value="<?= escapar((string)($datos['senal_pulsadores_items_json'] ?? '')) ?>">
              <script type="application/json" id="senal_pulsadores_matriz_json"><?= json_encode($senalPulsadoresExtMatriz,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
              <script type="application/json" id="senal_pulsadores_indicadores_json"><?= json_encode($senalPulsadoresExtIndicadores,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
              <script type="application/json" id="senal_indicadores_maestros_json"><?= json_encode($senalIndicadoresExteriorCatalogo,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
              <div class="senal-tecnicos-compartidos">
                <label class="senal-option-card"><input type="checkbox" name="senal_pulsador_exterior_llave_bomberos" value="1"<?= $senalPulsadorExteriorLlave?' checked':'' ?>><span><strong>Llave servicio bomberos</strong><small>Característica técnica del conjunto exterior.</small></span></label>
                <label class="senal-option-card"><input type="checkbox" name="senal_pulsador_exterior_logo" value="1"<?= $senalPulsadorExteriorLogo?' checked':'' ?>><span><strong>Logo</strong><small>Registrar presencia de logo.</small></span></label>
                <label class="senal-option-card"><input type="checkbox" name="senal_pulsador_exterior_braille_lateral" value="1"<?= $senalPulsadorExteriorBraille?' checked':'' ?>><span><strong>Braille lateral</strong><small>Registrar requerimiento lateral.</small></span></label>
                <input type="hidden" name="senal_pulsador_exterior_acabado" value="<?= escapar($senalPulsadorExteriorAcabado) ?>">
                <div class="senal-mini-ayuda"><strong>Acabado por ítem:</strong> cada pulsador agregado conserva Acero / Bronce / Negro y si corresponde Medida especial.</div>
                <input type="hidden" name="senal_pulsador_exterior_medidas" value="<?= escapar($senalPulsadorExteriorMedidas) ?>">
                <div class="senal-mini-ayuda"><strong>Valorización automática:</strong> cada combinación resuelve su código desde la matriz y toma el precio de la base Bejerman seleccionada.</div>
              </div>
            </div>
          </div>

          <div class="senal-contenedor-macro senal-contenedor-indicadores senal-macro-abierto" id="senal_contenedor_indicadores">
            <div class="senal-contenedor-macro-cabecera senal-macro-toggle" onclick="toggleContenedorSenalizacion(this,'senal_macro_indicadores_body')" onkeydown="toggleContenedorSenalizacionKey(event,this,'senal_macro_indicadores_body')" role="button" tabindex="0" aria-expanded="true"><div><span class="senal-contenedor-kicker">SEÑALIZACIÓN EXTERIOR</span><h4>3. Indicadores exteriores</h4><p>Elegí un modelo y agregá la cantidad necesaria. Podés combinar varios modelos.</p></div><div class="senal-macro-toggle-right"><span class="senal-cantidad-resumen" id="senal_total_indicadores"><?= (int)$totalIndicadores ?> unidades</span><span class="senal-precio-tag">BEJERMAN</span><span class="senal-macro-toggle-icon">⌄</span></div></div>
            <div class="senal-contenedor-macro-cuerpo" id="senal_macro_indicadores_body">
              <?php if($legacyIndicador && $totalIndicadores===0): ?><div class="senal-mini-ayuda" style="margin-bottom:10px"><strong>Dato histórico:</strong> el documento anterior registra un indicador de posición exterior sin tipo/cantidad definidos. Se conserva sin inferir modelo ni cantidad.</div><?php endif; ?>
              <div class="senal-pulsador-editor-v179" id="senal_indicador_ext_editor_v190">
                <div class="senal-pulsador-campos-v179">
                  <div class="campo senal-campo-ancho"><label>Modelo</label><select id="senal_indicador_ext_modelo_v190" onchange="actualizarPrecioIndicadorExteriorV190()"><option value="">Seleccione...</option><?php foreach($senalIndicadoresExteriorIndependientes as $indExtCat): if(empty($indExtCat['activo'])) continue; ?><option value="<?= escapar((string)$indExtCat['codigo']) ?>" data-modelo="<?= escapar((string)$indExtCat['modelo_indicador']) ?>" data-tipo="<?= escapar((string)$indExtCat['tipo_modulos']) ?>"><?= escapar((string)$indExtCat['modelo_indicador'].' · '.(string)$indExtCat['tipo_modulos'].' · '.(string)$indExtCat['codigo']) ?></option><?php endforeach; ?></select></div>
                  <div class="campo"><label>Cantidad</label><input type="number" id="senal_indicador_ext_cantidad_v190" min="1" step="1" value="1"></div>
                  <div class="campo senal-campo-ancho"><label>Medida (Ancho × Alto)</label><input type="text" id="senal_indicador_ext_medida_v190" maxlength="120" placeholder="Ej.: 120 x 240 mm"><small>Si queda vacío, en pedido / OF figurará A CONFIRMAR.</small></div>
                  <div class="campo"><label>Acabado de tapa</label><select id="senal_indicador_ext_acabado_v190" onchange="actualizarPrecioIndicadorExteriorV190()"><option value="ACERO">Acero</option><option value="BRONCE">Bronce</option><option value="NEGRO">Negro · A DEFINIR</option></select></div>
                  <label class="senal-option-card"><input type="checkbox" id="senal_indicador_ext_medida_especial_v190" value="1" onchange="actualizarPrecioIndicadorExteriorV190()"><span><strong>Medida especial</strong><small>Solo aplica cuando se marca.</small></span></label>
                  <div class="senal-pulsador-resuelto-v179 senal-pulsador-precio-v182"><span>Precio unitario</span><strong id="senal_indicador_ext_precio_v190">A RESOLVER</strong><small id="senal_indicador_ext_precio_detalle_v190">Antes de descuentos de Señalización</small></div>
                  <div class="senal-pulsador-actions-v179"><button type="button" onclick="agregarIndicadorExteriorV190()">Agregar</button><button type="button" onclick="modificarIndicadorExteriorV190()">Modificar</button></div>
                </div>
              </div>
              <div id="senal_indicadores_ext_items_list_v190" class="senal-pulsador-items-v188" style="margin:10px 0 12px"></div>
              <input type="hidden" name="senal_indicadores_exteriores_items_json" id="senal_indicadores_exteriores_items_json" value="<?= escapar($senalIndicadoresExteriorItemsRaw) ?>">
              <script type="application/json" id="senal_acabados_coeficientes_json"><?= json_encode($senalAcabadosCoeficientes,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?></script>
              <div style="display:none" aria-hidden="true">
                <input type="number" name="senal_indicador_18mm_v5_cantidad" value="<?= (int)$cantInd18 ?>"><input type="number" name="senal_indicador_31mm_v5_cantidad" value="<?= (int)$cantInd31 ?>"><input type="number" name="senal_indicador_a4610_cantidad" value="<?= (int)$cantIndA4610 ?>"><input type="number" name="senal_indicador_a4600_cantidad" value="<?= (int)$cantIndA4600 ?>"><input type="number" name="senal_indicador_a4810_cantidad" value="<?= (int)$cantIndA4810 ?>"><input type="number" name="senal_indicador_a4830_cantidad" value="<?= (int)$cantIndA4830 ?>"><input type="number" name="senal_indicador_a7260_cantidad" value="<?= (int)$cantIndA7260 ?>">
                <input type="text" name="senal_indicador_exterior_medidas" value="<?= escapar($senalIndicadorExteriorMedidas) ?>">
              </div>
              <div class="senal-tecnicos-compartidos"><input type="hidden" name="senal_indicador_exterior_acabado" value="<?= escapar($senalIndicadorExteriorAcabado) ?>"><div class="senal-mini-ayuda"><strong>Datos por ítem:</strong> cada indicador agregado conserva cantidad, Medida (Ancho × Alto), Acabado y Medida especial. Si la medida no se informa, queda A CONFIRMAR.</div></div>
            </div>
          </div>

          <input type="hidden" name="senal_elemento_medidas" value="">

          <div class="senal-seccion senal-acordeon-abierto" id="senal_paso_comercial">
            <div class="senal-seccion-titulo" onclick="toggleSeccionSenal(this,'senal_paso_comercial_cuerpo')" onkeydown="toggleSeccionSenalKey(event,this,'senal_paso_comercial_cuerpo')" role="button" tabindex="0" aria-expanded="true"><span class="senal-titulo-texto"><strong>4. Condiciones comerciales</strong><small>Descuentos aplicados a la señalización.</small></span><span class="senal-seccion-acciones"><span class="senal-precio-tag">DESCUENTOS</span><span class="senal-toggle-icon">⌃</span></span></div>
            <div class="senal-seccion-cuerpo senal-descuentos-cuerpo-v485" id="senal_paso_comercial_cuerpo">
              <div class="senal-descuentos-grid-v485">
                <div class="campo"><label>Descuento 1 (%):</label><input type="number" name="senal_descuento_1" value="<?= escapar((string)($datos['senal_descuento_1'] ?? $datos['descuento_1'] ?? (string)$defaultSenalD1)) ?>" min="0" max="100"></div>
                <div class="campo"><label>Descuento 2 (%):</label><input type="number" name="senal_descuento_2" value="<?= escapar((string)($datos['senal_descuento_2'] ?? $datos['descuento_2'] ?? (string)$defaultSenalD2)) ?>" min="0" max="100"></div>
                <div class="campo"><label>Descuento 3 (%):</label><input type="number" name="senal_descuento_3" value="<?= escapar((string)($datos['senal_descuento_3'] ?? $datos['descuento_3'] ?? (string)$defaultSenalD3)) ?>" min="0" max="100"></div>
              </div>
            </div>
          </div>
        </form>
        <div class="flujo-acciones"><span class="flujo-estado"><strong>Señalización:</strong> deje marcada la inclusión si corresponde, o desmárquela para indicar que no lleva.</span><div class="grupo-derecha tres-acciones"><button type="button" class="btn-volver" onclick="navegarModuloSinIncluir('control')">← Volver a Control</button><button type="button" class="btn-finalizar" onclick="finalizarModuloActual('senalizacion')">Terminar cotización y revisar resumen ✓</button><button type="button" class="btn-siguiente" onclick="continuarFlujoModulo('senalizacion')">Seguir a Accesorios →</button></div></div>
    </div>
