    <div id="modulo_control" class="modulo-cotizador activo">
    <?php if ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar'): ?>
        <div class="seccion-plantilla" style="background:#e8f1ff;border:1px solid #9ec5fe;border-radius:7px;padding:13px;margin-bottom:14px;">
            <strong><?= $modoPlantilla === 'editar' ? 'Modificar plantilla de control' : 'Nueva plantilla de control' ?></strong>
            <div class="ayuda">Configure el control y guárdelo con un código como 1M, 2M, 3M, etc. La plantilla guarda solamente la configuración y los ítems del control. No guarda cliente, referencia, descuentos, precios ni cantidad de paradas.</div>
        </div>
    <?php else: ?>
        <form id="selector_plantillas_control" class="selector-plantillas-control" method="get" action="index.php#selector_plantillas_control" onsubmit="prepararAplicacionPlantilla();">
            <input type="hidden" name="id_cliente_actual" id="plantilla_cliente_actual">
            <input type="hidden" name="referencia_actual" id="plantilla_referencia_actual">
            <input type="hidden" name="solicitante_actual" id="plantilla_solicitante_actual">
            <input type="hidden" name="lista_id_actual" id="plantilla_lista_actual">
            <input type="hidden" name="descuento_1_actual" id="plantilla_descuento_1_actual">
            <input type="hidden" name="descuento_2_actual" id="plantilla_descuento_2_actual">
            <input type="hidden" name="descuento_3_actual" id="plantilla_descuento_3_actual">
            <div class="campo">
                <label for="aplicar_plantilla">Configuración rápida</label>
                <select name="aplicar_plantilla" id="aplicar_plantilla">
                    <option value="">Plantillas de Control...</option>
                    <?php if ($plantillasDisponibles): while($pl=$plantillasDisponibles->fetch_assoc()): ?>
                    <option value="<?= (int)$pl['plantilla_id'] ?>"<?= $plantillaAplicada && (int)$plantillaAplicada['plantilla_id']===(int)$pl['plantilla_id'] ? ' selected' : '' ?>><?= escapar($pl['plantilla_codigo'].' — '.$pl['plantilla_nombre']) ?></option>
                    <?php endwhile; endif; ?>
                </select>
            </div>
            <button type="submit" class="selector-plantillas-aplicar">APLICAR</button>
            <div class="control-plantilla-links"><span>Completa la configuración técnica del control y aplicá una plantilla guardada.</span><a href="administrar_plantillas.php">Crear / modificar plantillas</a></div>
        </form>
        <?php if ($plantillaAplicada): ?><div class="mensaje" style="background:#d1e7dd;color:#0f5132;">Plantilla aplicada: <strong><?= escapar($plantillaAplicada['plantilla_codigo'].' — '.$plantillaAplicada['plantilla_nombre']) ?></strong>. Puede modificar cualquier dato antes de cotizar.</div><?php endif; ?>
    <?php endif; ?>

    <?php if ($error !== ''): ?>
        <div class="error"><?= escapar($error) ?></div>
    <?php endif; ?>

    <form id="form_cotizador" action="calcular.php" method="POST" target="resultado_calculo"<?= ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar') ? '' : ' onsubmit="prepararCalculoAuxiliar();"' ?>>
        <?=automacCsrfInput()?>
        <input type="hidden" name="document_save_token" value="<?= escapar($documentSaveToken) ?>">
        <input type="hidden" name="modo_auxiliar" value="1">
        <input type="hidden" name="modo_panel" value="1">
        <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?><?php $controlIncluidoInicial = $plantillaAplicada ? true : (isset($datos['incluir_control']) ? !empty($datos['incluir_control']) : (($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) && !empty($datos))); ?><div class="campo campo-adicional-ancho modulo-inclusion"><label class="checkbox-label modulo-inclusion-label-v382"><input type="checkbox" id="incluir_control" value="1"<?= $controlIncluidoInicial ? ' checked' : '' ?>><span class="modulo-inclusion-text-v383" style="font-family:Arial,Helvetica,sans-serif!important;font-size:11px!important;font-weight:700!important;line-height:1.2!important;letter-spacing:0!important;text-transform:none!important;color:#24384a!important;display:inline-block!important;margin:0!important;padding:0!important;">Incluir Control en el documento</span></label><div class="ayuda">Márquelo solamente cuando el documento incluya un control. Puede cotizar o pedir Repuestos, Señalización, IEP o Accesorios sin Control.</div></div><?php endif; ?>
        <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
        <div class="control-cabecera"><div><h4>Configuración del control</h4><p>Mismo flujo visual que Señalización: configure primero el control y los equipos, luego puertas, adicionales y por último descuentos.</p></div><span class="control-tag">CÁLCULO AUTOMÁTICO</span></div>
        <nav class="control-pasos" aria-label="Pasos del control">
          <a href="#control_paso_config"><span class="n">1</span>Control y equipos</a><a href="#control_paso_puertas"><span class="n">2</span>Puertas</a><a href="#control_paso_adicionales"><span class="n">3</span>Adicionales</a><a href="#control_paso_descuentos"><span class="n">4</span>Descuentos</a>
        </nav>
        <div class="control-guia"><span><b>1.</b> Control + equipos</span><span><b>2.</b> Puertas</span><span><b>3.</b> Adicionales</span><span><b>4.</b> Revisar descuentos</span></div>
        <?php endif; ?>
        <input type="hidden" name="cotizacion_id" value="<?= (int)$cotizacionEdicionId ?>">
        <input type="hidden" name="pedido_id" value="<?= (int)$pedidoEdicionId ?>">
        <input type="hidden" name="cotizacion_revision_base" value="<?= (int)($cotizacionEdicion['revision'] ?? 0) ?>">
        <input type="hidden" name="pedido_revision_base" value="<?= (int)($pedidoEdicion['revision'] ?? 0) ?>">
        <?php if ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar'): ?>
            <input type="hidden" name="plantilla_id" value="<?= (int)($plantillaEdicion['plantilla_id'] ?? 0) ?>">
            <div class="seccion-cliente">Identificación de la plantilla</div>
            <div class="campo">
                <label for="plantilla_codigo">Código:</label>
                <input type="text" name="plantilla_codigo" id="plantilla_codigo" maxlength="30" required value="<?= escapar((string)($plantillaEdicion['plantilla_codigo'] ?? ($datos['plantilla_codigo'] ?? ''))) ?>" placeholder="Ej.: 1M">
            </div>
            <div class="campo">
                <label for="plantilla_nombre">Nombre:</label>
                <input type="text" name="plantilla_nombre" id="plantilla_nombre" maxlength="150" required value="<?= escapar((string)($plantillaEdicion['plantilla_nombre'] ?? ($datos['plantilla_nombre'] ?? ''))) ?>" placeholder="Ej.: Modelo 1">
            </div>
            <div class="campo campo-adicional-ancho">
                <label for="plantilla_descripcion">Descripción:</label>
                <input type="text" name="plantilla_descripcion" id="plantilla_descripcion" maxlength="500" value="<?= escapar((string)($plantillaEdicion['plantilla_descripcion'] ?? ($datos['plantilla_descripcion'] ?? ''))) ?>" placeholder="Descripción opcional de la configuración">
            </div>
        <?php endif; ?>
        <?php if ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar'): ?>
            <?php /* La lista se usa solo internamente para cargar los ítems disponibles; no se guarda en la plantilla. */ ?>
            <input type="hidden" name="lista_id" id="lista_id" value="<?= (int)($listaPrecioSeleccionada['lista_id'] ?? 0) ?>">
        <?php endif; ?>
        <div class="control-seccion cerrada" id="control_paso_config">
        <div class="control-seccion-titulo" onclick="toggleControlSeccion(this)" onkeydown="toggleControlSeccionKey(event,this)" role="button" tabindex="0" aria-expanded="false"><span class="control-titulo-texto"><strong>1. Control y equipos</strong><small>CPU, tipo, maniobra, cantidad de coches, paradas y material de hueco.</small></span><span class="control-seccion-acciones"><span class="control-tag">BASE</span><span class="control-toggle">⌃</span></span></div>
        <div class="control-seccion-cuerpo">
        <div class="campo">
            <label for="id_tension">Tensión:</label>
            <select name="id_tension" id="id_tension" required>
                <option value="">Seleccione tensión...</option>
                <?php
                $resultado = $conexion->query('SELECT tension_id, tension_name FROM tensiones ORDER BY tension_id');
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        $selected = valorSeleccionado($datos, 'id_tension', $fila['tension_id']);
                        if ($selected === '' && empty($datos) && $fila['tension_name'] === '3X380') {
                            $selected = ' selected';
                        }
                        echo '<option value="' . (int)$fila['tension_id'] . '"' . $selected . '>'
                            . escapar($fila['tension_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo">
            <label for="id_cpu">CPU:</label>
            <select name="id_cpu" id="id_cpu"
                    onchange="manejarCambioCpu();" required>
                <option value="">Seleccione CPU...</option>
                <?php
                if (ctrlTablaExiste($conexion, 'control_cpu_capacidades')) {
                    $filtroCpuActivo = ctrlColumnaExiste($conexion, 'control_cpu_capacidades', 'activo') ? " WHERE COALESCE(cap.activo,'SI')='SI'" : '';
                    $sqlCpuOpciones = "SELECT c.cpu_id,c.cpu_name,c.velocidad_max_mmin,c.admite_encoder,cap.admite_mrl,cap.admite_micronivelacion,cap.admite_maniobra_sabatica,cap.mrl_identidad FROM cpus c LEFT JOIN control_cpu_capacidades cap ON cap.cpu_id=c.cpu_id" . $filtroCpuActivo . " ORDER BY c.cpu_name";
                } else {
                    $sqlCpuOpciones = "SELECT cpu_id,cpu_name,velocidad_max_mmin,admite_encoder,NULL admite_mrl,NULL admite_micronivelacion,NULL admite_maniobra_sabatica,NULL mrl_identidad FROM cpus ORDER BY cpu_name";
                }
                $resultado = $conexion->query($sqlCpuOpciones);
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        $maxCpu = $fila['velocidad_max_mmin'] !== null ? (string)(float)$fila['velocidad_max_mmin'] : '';
                        $admiteEncoderCpu = isset($fila['admite_encoder']) ? (string)$fila['admite_encoder'] : '';
                        echo '<option value="' . (int)$fila['cpu_id'] . '" data-velocidad-max="' . escapar($maxCpu) . '" data-admite-encoder="' . escapar($admiteEncoderCpu) . '"'
                            . ' data-admite-mrl="' . escapar((string)($fila['admite_mrl'] ?? '')) . '"'
                            . ' data-micronivelacion="' . escapar((string)($fila['admite_micronivelacion'] ?? '')) . '"'
                            . ' data-sabatica="' . escapar((string)($fila['admite_maniobra_sabatica'] ?? '')) . '"'
                            . ' data-mrl-identidad="' . escapar((string)($fila['mrl_identidad'] ?? 'AUTOMAC')) . '"'
                            . valorSeleccionado($datos, 'id_cpu', $fila['cpu_id']) . '>'
                            . escapar($fila['cpu_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="seccion-adicionales">Cantidad de equipos y agrupación</div>
        <div class="campo">
            <label for="cantidad_equipos">Cantidad de equipos cotizados:</label>
            <input type="number" name="cantidad_equipos" id="cantidad_equipos" min="1" max="10" step="1"
                   value="<?= escapar((string)($datos['cantidad_equipos'] ?? '1')) ?>"
                   onchange="generarEquipos(); actualizarTipoAgrupacion();" oninput="generarEquipos(); actualizarTipoAgrupacion();" required>
        </div>
        <div class="campo">
            <label for="id_bateria">Agrupación:</label>
            <select name="id_bateria" id="id_bateria" onchange="actualizarTipoAgrupacion();" required>
                <?php
                $rBaterias = $conexion->query("SELECT bateria_id,bateria_codigo,bateria_nombre FROM baterias WHERE bateria_activa='SI' ORDER BY bateria_orden,bateria_id");
                if (!$rBaterias) die('No se pudo consultar el catálogo de agrupaciones. Ejecute migracion_consolidacion_v59.sql.');
                while ($bat = $rBaterias->fetch_assoc()):
                ?>
                <option value="<?= (int)$bat['bateria_id'] ?>" data-codigo="<?= escapar((string)$bat['bateria_codigo']) ?>"<?= valorSeleccionado($datos, 'id_bateria', $bat['bateria_id']) ?>><?= escapar((string)$bat['bateria_nombre']) ?></option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="campo" id="grupo_total_coches_bateria" style="display:none;">
            <label for="cantidad_total_coches_bateria">Cantidad total de coches de la batería:</label>
            <input type="number" name="cantidad_total_coches_bateria" id="cantidad_total_coches_bateria" min="1" max="8" step="1"
                   value="<?= escapar((string)($datos['cantidad_total_coches_bateria'] ?? '1')) ?>"
                   onchange="actualizarTipoAgrupacion();" required disabled>
        </div>
        <div class="campo" id="grupo_numero_obra_otro" style="display:none;">
            <label for="numero_obra_otro">N.º de obra del otro equipo (opcional):</label>
            <input type="text" name="numero_obra_otro" id="numero_obra_otro" maxlength="100"
                   value="<?= escapar((string)($datos['numero_obra_otro'] ?? '')) ?>"
                   placeholder="Ej.: 45872" disabled>
        </div>
        <div class="campo campo-adicional-ancho" id="grupo_observacion_bateria" style="display:none;">
            <label for="observacion_bateria">Información del otro coche o de la batería (opcional):</label>
            <input type="text" name="observacion_bateria" id="observacion_bateria" maxlength="500"
                   value="<?= escapar((string)($datos['observacion_bateria'] ?? '')) ?>"
                   placeholder="Ej.: segundo coche futuro o equipo existente" disabled>
        </div>
        <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
        <div class="seccion-adicionales paradas-fundamental">CANTIDAD DE PARADAS POR COCHE · DATO OBLIGATORIO PARA EL CÁLCULO</div>
        <div class="mismas-paradas" id="grupo_mismas_paradas">
            <label for="mismas_paradas">
                <input type="checkbox" name="mismas_paradas" id="mismas_paradas" value="SI"<?= valorMarcado($datos, 'mismas_paradas') ?> onchange="aplicarMismasParadas();">
                Todos los coches de la batería tienen la misma cantidad de paradas
            </label>
        </div>
        <div class="equipos-grid" id="equipos_contenedor">
<?php
    // v106: render inicial del dato fundamental de paradas del lado servidor.
    // JavaScript luego puede regenerarlo, pero el campo nunca queda invisible si otro script falla.
    $cantidadEquiposRender = (int)($datos['cantidad_equipos'] ?? 1);
    if ($cantidadEquiposRender < 1) $cantidadEquiposRender = 1;
    if ($cantidadEquiposRender > 10) $cantidadEquiposRender = 10;
    $paradasRender = array_values((array)($datos['paradas_equipo'] ?? (isset($datos['paradas']) ? array($datos['paradas']) : array())));
    $nombresRender = array_values((array)($datos['nomenclatura_equipo'] ?? array()));
    for ($iRender = 0; $iRender < $cantidadEquiposRender; $iRender++):
        $paradaRender = (string)($paradasRender[$iRender] ?? '');
        $nombreRender = (string)($nombresRender[$iRender] ?? '');
?>
            <div class="equipo-card">
                <h3>Coche <?= $iRender + 1 ?></h3>
                <div class="campo">
                    <label>Paradas *</label>
                    <input type="number" name="paradas_equipo[]" min="1" max="64" step="1" required
                           value="<?= escapar($paradaRender) ?>" placeholder="Ej.: 8"
                           oninput="sincronizarParadasBateria(this); programarCalculoTiempoReal(35)"
                           onchange="programarCalculoTiempoReal(20)">
                </div>
                <div class="campo nomenclatura">
                    <label>Nomenclatura de paradas</label>
                    <input type="text" name="nomenclatura_equipo[]" maxlength="500"
                           value="<?= escapar($nombreRender) ?>"
                           placeholder="Ej.: PB al 7 / -1, 0 al 5 / -2, -1, PB al 5, AZ">
                    <small>Manual por coche. Si queda vacía, en fabricación figurará A CONFIRMAR.</small>
                </div>
            </div>
<?php endfor; ?>
        </div>
        <div class="ayuda">En una misma cotización todos los coches comparten los datos técnicos, pero cada coche conserva su cantidad y su nomenclatura de paradas. La nomenclatura es manual y de texto libre; si no se conoce al cotizar, puede dejarse vacía y quedará identificada como A CONFIRMAR en fabricación.</div>
        <?php else: ?>
        <div id="equipos_contenedor" style="display:none;"></div>
        <div class="ayuda">La cantidad y la nomenclatura de paradas se completan al utilizar la plantilla en una cotización.</div>
        <?php endif; ?>

        <div class="campo">
            <label for="id_maniobra">Maniobra:</label>
            <select name="id_maniobra" id="id_maniobra"
                    onchange="actualizarPuertas(); actualizarConfiguracionEspecialControl(); programarCalculoTiempoReal(20);" required>
                <option value="">Seleccione maniobra...</option>
                <?php
                $resultado = $conexion->query('SELECT maniobra_id, maniobra_name FROM maniobras ORDER BY maniobra_id');
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        $esMc = strtoupper(trim($fila['maniobra_name'])) === 'MC';

                        echo '<option value="' . (int)$fila['maniobra_id'] . '"'
                            . ' data-es-mc="' . ($esMc ? '1' : '0') . '"'
                            . valorSeleccionado($datos, 'id_maniobra', $fila['maniobra_id']) . '>'
                            . escapar($fila['maniobra_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <?php
        $configEspecialGuardada = strtoupper(trim((string)($datos['configuracion_especial_control'] ?? '')));
        if ($configEspecialGuardada === '') {
            $configEspecialGuardada = (!empty($datos['doble_acceso']) && strtoupper(trim((string)$datos['doble_acceso'])) === 'SI')
                ? 'DOBLE_ACCESO_SELECTIVO' : 'NORMAL';
        }
        if (!in_array($configEspecialGuardada, array('NORMAL','DOBLE_ACCESO_SELECTIVO','TIP'), true)) $configEspecialGuardada = 'NORMAL';
        $programaTipGuardado = strtoupper(trim((string)($datos['programa_tip'] ?? '')));
        ?>
        <div class="campo">
            <label for="configuracion_especial_control">Configuración especial:</label>
            <select name="configuracion_especial_control" id="configuracion_especial_control" onchange="actualizarConfiguracionEspecialControl(); programarCalculoTiempoReal(20);">
                <option value="NORMAL"<?= $configEspecialGuardada==='NORMAL'?' selected':'' ?>>Normal</option>
                <option value="DOBLE_ACCESO_SELECTIVO"<?= $configEspecialGuardada==='DOBLE_ACCESO_SELECTIVO'?' selected':'' ?>>Doble acceso selectivo</option>
                <option value="TIP"<?= $configEspecialGuardada==='TIP'?' selected':'' ?>>Maniobra TIP</option>
            </select>
            <div class="ayuda">Es una condición adicional del cálculo; no reemplaza la maniobra AS, CS, SD, SAD o MC/MV.</div>
        </div>
        <div class="campo" id="grupo_programa_tip" style="display:<?= $configEspecialGuardada==='TIP'?'block':'none' ?>;">
            <label for="programa_tip">Programa TIP:</label>
            <select name="programa_tip" id="programa_tip" onchange="actualizarResumenControl(); programarCalculoTiempoReal(20);"<?= $configEspecialGuardada==='TIP'?' required':' disabled' ?>>
                <option value="">Seleccione programa TIP...</option>
                <option value="ESPECIAL_ESTANDAR"<?= $programaTipGuardado==='ESPECIAL_ESTANDAR'?' selected':'' ?>>Especial + Estándar</option>
                <option value="ESPECIAL_ESPECIAL"<?= $programaTipGuardado==='ESPECIAL_ESPECIAL'?' selected':'' ?>>Especial + Especial</option>
            </select>
        </div>

        <?php $controlVfLegado = ((int)($datos['id_tipo_control'] ?? 0) === 4 && !array_key_exists('dato_motor_tipo', $datos) && trim((string)($datos['potencia_hp'] ?? '')) !== ''); ?>
        <div class="campo" id="grupo_potencia_hp">
            <label for="potencia_hp">Potencia (HP):</label>
            <input type="number" step="0.01" min="0" name="potencia_hp" id="potencia_hp"
                   value="<?= escapar((string)($datos['potencia_hp'] ?? '')) ?>"
                   placeholder="Ej.: 5,5" required data-vf-legado="<?= $controlVfLegado ? '1' : '0' ?>">
        </div>
        <div class="campo" id="grupo_dato_motor_vf" style="display:none" data-vf-legado="<?= $controlVfLegado ? '1' : '0' ?>">
            <label for="dato_motor_tipo">Dato conocido del motor (VF):</label>
            <select name="dato_motor_tipo" id="dato_motor_tipo" disabled>
                <option value="HP"<?= (($datos['dato_motor_tipo'] ?? 'HP') === 'HP') ? ' selected' : '' ?>>HP</option>
                <option value="AMP"<?= (($datos['dato_motor_tipo'] ?? '') === 'AMP') ? ' selected' : '' ?>>Amp</option>
                <option value="KW"<?= (($datos['dato_motor_tipo'] ?? '') === 'KW') ? ' selected' : '' ?>>kW</option>
            </select>
            <label for="dato_motor_valor">Valor informado:</label>
            <input type="number" step="0.01" min="0.01" name="dato_motor_valor" id="dato_motor_valor" value="<?= escapar((string)($datos['dato_motor_valor'] ?? '')) ?>" disabled>
            <label for="dato_motor_corriente_visible">Corriente técnica normalizada:</label>
            <input type="text" id="dato_motor_corriente_visible" value="<?= escapar((string)($datos['dato_motor_corriente'] ?? '')) ?>" readonly>
            <input type="hidden" name="dato_motor_corriente" id="dato_motor_corriente" value="<?= escapar((string)($datos['dato_motor_corriente'] ?? '')) ?>" disabled>
            <input type="hidden" name="dato_motor_hp_equivalente" id="dato_motor_hp_equivalente" value="<?= escapar((string)($datos['dato_motor_hp_equivalente'] ?? '')) ?>" disabled>
            <label for="id_matriz_variador">Variador seleccionado:</label>
            <select name="id_matriz_variador" id="id_matriz_variador" disabled required>
                <option value="">Seleccione la corriente requerida para buscar variadores</option>
            </select>
            <small>Se puede elegir cualquier fila compatible con corriente nominal igual o superior a la requerida.</small>
        </div>

        <div class="campo">
            <label for="id_tipo_control">Tipo de control:</label>
            <select name="id_tipo_control" id="id_tipo_control"
                    onchange="cargarSubtipos(this.value, document.getElementById('id_subtipo').value); actualizarCentral(); actualizarOpcionesHidraulicas(); actualizarVelocidad(); actualizarPosicionamientoEncoder(); actualizarAdicionalesVisibles();" required>
                <option value="">Seleccione tipo de control...</option>
                <?php
                $sqlTipoOpciones = ctrlTablaExiste($conexion, 'control_tipo_capacidades')
                    ? "SELECT t.ctrltipo_id,t.ctrltipo_name,cap.requiere_central,cap.permite_tandem,cap.requiere_velocidad,cap.velocidad_fija_mmin,cap.familia_rescate,cap.es_mrl FROM tipos_control t LEFT JOIN control_tipo_capacidades cap ON cap.ctrltipo_id=t.ctrltipo_id WHERE COALESCE(cap.activo,'SI')='SI' ORDER BY t.ctrltipo_id"
                    : "SELECT ctrltipo_id,ctrltipo_name,NULL requiere_central,NULL permite_tandem,NULL requiere_velocidad,NULL velocidad_fija_mmin,NULL familia_rescate,NULL es_mrl FROM tipos_control ORDER BY ctrltipo_id";
                $resultado = $conexion->query($sqlTipoOpciones);
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['ctrltipo_id'] . '"'
                            . ' data-requiere-central="' . escapar((string)($fila['requiere_central'] ?? '')) . '"'
                            . ' data-permite-tandem="' . escapar((string)($fila['permite_tandem'] ?? '')) . '"'
                            . ' data-requiere-velocidad="' . escapar((string)($fila['requiere_velocidad'] ?? '')) . '"'
                            . ' data-velocidad-fija="' . escapar((string)($fila['velocidad_fija_mmin'] ?? '')) . '"'
                            . ' data-familia-rescate="' . escapar((string)($fila['familia_rescate'] ?? '')) . '"'
                            . ' data-es-mrl="' . escapar((string)($fila['es_mrl'] ?? '')) . '"'
                            . valorSeleccionado($datos, 'id_tipo_control', $fila['ctrltipo_id']) . '>'
                            . escapar($fila['ctrltipo_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo" id="grupo_tipo_gabinete_mrl" style="display:none;">
            <label for="tipo_gabinete_mrl">Tipo de gabinete MRL:</label>
            <select name="tipo_gabinete_mrl" id="tipo_gabinete_mrl">
                <option value="">Seleccione el tipo de gabinete...</option>
                <option id="opcion_gabinete_mrl_estandar" value="ESTANDAR"<?= valorSeleccionado($datos, 'tipo_gabinete_mrl', 'ESTANDAR') ?>>MRL AUTOMAC</option>
                <option value="WITTUR"<?= valorSeleccionado($datos, 'tipo_gabinete_mrl', 'WITTUR') ?>>MRL Wittur</option>
            </select>
            <div class="ayuda">La opción estándar adopta automáticamente la identidad de la CPU seleccionada: MRL AUTOMAC, MRL CLEX o MRL DANGELICA. Wittur mantiene su gabinete específico.</div>
        </div>

        <div class="campo">
            <label for="id_subtipo">Subtipo:</label>
            <select name="id_subtipo" id="id_subtipo" onchange="actualizarVelocidad(); actualizarAdicionalesVisibles();" required>
                <option value="">Seleccione CPU y tipo de control...</option>
            </select>
        </div>

        <div class="campo" id="grupo_central" style="display:none;">
            <label for="id_central">Central hidráulica:</label>
            <select name="id_central" id="id_central" onchange="actualizarCentral();">
                <option value="">Seleccione central...</option>
                <?php
                $centrales = array();
                $resultado = $conexion->query("SELECT central_id, central_codigo, central_name, central_es_otra FROM centrales WHERE central_activa='SI' ORDER BY central_orden, central_name");
                if (!$resultado) {
                    die('No se pudo consultar el catálogo de centrales. Ejecute migracion_consolidacion_v59.sql.');
                }
                while ($fila = $resultado->fetch_assoc()) {
                    $centrales[] = $fila;
                }
                foreach ($centrales as $fila) {
                    echo '<option value="' . (int)$fila['central_id'] . '"'
                        . ' data-codigo="' . escapar((string)$fila['central_codigo']) . '"'
                        . ' data-es-otra="' . ((int)$fila['central_es_otra'] === 1 ? '1' : '0') . '"'
                        . valorSeleccionado($datos, 'id_central', $fila['central_id']) . '>'
                        . escapar($fila['central_name']) . '</option>';
                }
                ?>
            </select>
        </div>



        <div class="campo" id="grupo_central_otra" style="display:none;">
            <label for="central_otra_nombre">Nombre de la central:</label>
            <input type="text" name="central_otra_nombre" id="central_otra_nombre"
                   maxlength="100"
                   value="<?= escapar((string)($datos['central_otra_nombre'] ?? '')) ?>"
                   placeholder="Ingrese el nombre de la central">
        </div>

        <div class="campo" id="grupo_velocidad" style="display:none;">
            <label for="velocidad_vf">Velocidad del ascensor:</label>
            <select name="velocidad_vf" id="velocidad_vf" onchange="actualizarEncoderObligatorioPorVelocidad(); actualizarVelocidad();">
                <option value="">Seleccione velocidad...</option>
                <option value="30"<?= valorSeleccionado($datos, 'velocidad_vf', '30') ?>>30 m/min</option>
                <option value="45"<?= valorSeleccionado($datos, 'velocidad_vf', '45') ?>>45 m/min</option>
                <option value="60"<?= valorSeleccionado($datos, 'velocidad_vf', '60') ?>>60 m/min</option>
                <option value="75"<?= valorSeleccionado($datos, 'velocidad_vf', '75') ?>>75 m/min</option>
                <option value="90"<?= valorSeleccionado($datos, 'velocidad_vf', '90') ?>>90 m/min</option>
                <option value="105"<?= valorSeleccionado($datos, 'velocidad_vf', '105') ?>>105 m/min</option>
                <option value="120"<?= valorSeleccionado($datos, 'velocidad_vf', '120') ?>>120 m/min</option>
            </select>
        </div>


        <div class="campo">
            <label for="id_material_hueco">Material de hueco:</label>
            <select name="id_material_hueco" id="id_material_hueco" required>
                <option value="">Seleccione material de hueco...</option>
                <?php
                $resultado = $conexion->query('SELECT mathueco_id, mathueco_name FROM materiales_hueco ORDER BY mathueco_id');
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['mathueco_id'] . '"'
                            . valorSeleccionado($datos, 'id_material_hueco', $fila['mathueco_id']) . '>'
                            . escapar($fila['mathueco_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        </div></div>
        <div class="control-seccion control-seccion-puertas cerrada" id="control_paso_puertas">
        <div class="control-seccion-titulo" onclick="toggleControlSeccion(this)" onkeydown="toggleControlSeccionKey(event,this)" role="button" tabindex="0" aria-expanded="false"><span class="control-titulo-texto"><strong>2. Puertas</strong><small>Puertas de cabina, pisos y cantidades asociadas.</small></span><span class="control-seccion-acciones"><span class="control-tag">PUERTAS</span><span class="control-toggle">⌃</span></span></div>
        <div class="control-seccion-cuerpo">

        <div class="campo" id="grupo_ptacabina">
            <label for="id_ptacabina">Puerta de cabina:</label>
            <select name="id_ptacabina" id="id_ptacabina"
                    onchange="actualizarCantidadPuertaCabina(); actualizarAdicionalesVisibles();">
                <option value="">Seleccione puerta de cabina...</option>
                <?php
                $resultado = $conexion->query(
                    "SELECT ptacabina_id, ptacabina_name, ptacabina_pide_cantidad
                     FROM ptacabina
                     ORDER BY ptacabina_id"
                );

                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['ptacabina_id'] . '"'
                            . ' data-pide-cantidad="' . escapar($fila['ptacabina_pide_cantidad']) . '"'
                            . valorSeleccionado($datos, 'id_ptacabina', $fila['ptacabina_id']) . '>'
                            . escapar($fila['ptacabina_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo" id="grupo_cantidad_operadores" style="display:none;">
            <label for="cantidad_operadores">Cantidad de operadores:</label>
            <input type="number" name="cantidad_operadores" id="cantidad_operadores"
                   min="1" step="1"
                   value="<?= escapar((string)($datos['cantidad_operadores'] ?? '')) ?>"
                   placeholder="Ej.: 1"
                   oninput="actualizarAperturasOperadoresV172()" onchange="actualizarAperturasOperadoresV172()">
        </div>

        <div class="campo campo-adicional-ancho operador-aperturas-v172" id="grupo_aperturas_operadores" style="display:none;">
            <label>Distribución de apertura por operador:</label>
            <div id="lista_aperturas_operadores" class="operador-aperturas-lista-v172"></div>
            <div class="ayuda">Dato técnico para Producción. No es obligatorio: si un operador queda sin detalle, se registrará como <strong>A CONFIRMAR</strong>.</div>
        </div>

        <div class="campo" id="grupo_ptapisos">
            <label for="id_ptapisos">Puertas de pisos:</label>
            <select name="id_ptapisos" id="id_ptapisos">
                <option value="">Seleccione puertas de pisos...</option>
                <?php
                $resultado = $conexion->query(
                    "SELECT ptapisos_id, ptapisos_name
                     FROM ptapisos
                     ORDER BY ptapisos_id"
                );

                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['ptapisos_id'] . '"'
                            . valorSeleccionado($datos, 'id_ptapisos', $fila['ptapisos_id']) . '>'
                            . escapar($fila['ptapisos_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo" id="grupo_ptapisos_mc" style="display:none;">
            <label for="id_ptapisos_mc">Puertas pisos MC:</label>
            <select name="id_ptapisos_mc" id="id_ptapisos_mc"
                    onchange="actualizarCantidadPisosMc();">
                <option value="">Seleccione puertas pisos MC...</option>
                <?php
                $resultado = $conexion->query(
                    "SELECT ptapisosmc_id, ptapisosmc_name, ptapisosmc_pide_cantidad
                     FROM ptapisos_mc
                     ORDER BY ptapisosmc_id"
                );

                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['ptapisosmc_id'] . '"'
                            . ' data-pide-cantidad="' . escapar($fila['ptapisosmc_pide_cantidad']) . '"'
                            . valorSeleccionado($datos, 'id_ptapisos_mc', $fila['ptapisosmc_id']) . '>'
                            . escapar($fila['ptapisosmc_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo" id="grupo_cantidad_pisos_mc" style="display:none;">
            <label for="cantidad_pisos_mc">Cantidad puertas pisos MC:</label>
            <input type="number" name="cantidad_pisos_mc" id="cantidad_pisos_mc"
                   min="1" step="1"
                   value="<?= escapar((string)($datos['cantidad_pisos_mc'] ?? '')) ?>"
                   placeholder="Ej.: 1">
        </div>

        <div class="campo" id="grupo_ptacabina_mc" style="display:none;">
            <label for="id_ptacabina_mc">Puertas en cabina MC:</label>
            <select name="id_ptacabina_mc" id="id_ptacabina_mc"
                    onchange="actualizarCantidadCabinaMc(); actualizarAdicionalesVisibles();">
                <option value="">Seleccione puertas en cabina MC...</option>
                <?php
                $resultado = $conexion->query(
                    "SELECT ptacabinamc_id, ptacabinamc_name, ptacabinamc_pide_cantidad
                     FROM ptacabina_mc
                     ORDER BY ptacabinamc_id"
                );

                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['ptacabinamc_id'] . '"'
                            . ' data-pide-cantidad="' . escapar($fila['ptacabinamc_pide_cantidad']) . '"'
                            . valorSeleccionado($datos, 'id_ptacabina_mc', $fila['ptacabinamc_id']) . '>'
                            . escapar($fila['ptacabinamc_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="campo" id="grupo_cantidad_cabina_mc" style="display:none;">
            <label for="cantidad_cabina_mc">Cantidad puertas en cabina MC:</label>
            <input type="number" name="cantidad_cabina_mc" id="cantidad_cabina_mc"
                   min="1" step="1"
                   value="<?= escapar((string)($datos['cantidad_cabina_mc'] ?? '')) ?>"
                   placeholder="Ej.: 1">
        </div>

        </div></div>
        <div class="control-seccion control-seccion-adicionales cerrada" id="control_paso_adicionales">
        <div class="control-seccion-titulo" onclick="toggleControlSeccion(this)" onkeydown="toggleControlSeccionKey(event,this)" role="button" tabindex="0" aria-expanded="false"><span class="control-titulo-texto"><strong>3. Adicionales</strong><small>Comunicación, rescates, protecciones, fuentes, interfases y adicionales manuales.</small></span><span class="control-seccion-acciones"><span class="control-tag">ADICIONALES</span><span class="control-toggle">⌃</span></span></div>
        <div class="control-seccion-cuerpo">

        <div class="campo campo-adicional-ancho">
            <label for="id_comunicacion_serie">Comunicación serie:</label>
            <select name="id_comunicacion_serie" id="id_comunicacion_serie" onchange="sincronizarDatosSenalizacion(true); recalcularControlUniversal(20);">
                <option value="">Sin comunicación serie</option>
                <?php
                $resultado = $conexion->query('SELECT comserie_id, comserie_name FROM comunicaciones_serie ORDER BY comserie_id');
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        echo '<option value="' . (int)$fila['comserie_id'] . '"'
                            . valorSeleccionado($datos, 'id_comunicacion_serie', $fila['comserie_id']) . '>'
                            . escapar($fila['comserie_name']) . '</option>';
                    }
                }
                ?>
            </select>
        </div>

        <div class="control-checklist-grid">
        <div class="campo control-check-item">
            <label class="checkbox-label">
                <input type="checkbox" name="encoder" id="encoder_motor" value="SI"<?= valorMarcado($datos, 'encoder') ?>>
                <strong>Encoder de motor</strong>
            </label>
            <small id="aviso_encoder_velocidad" style="display:none;color:#176b45;font-weight:700;margin-top:4px;">Para velocidades superiores a 75 m/min debe marcar Encoder de motor.</small>
        </div>

        <div class="campo control-check-item" id="grupo_posicionamiento_encoder">
            <label class="checkbox-label">
                <input type="checkbox" name="posicionamiento_encoder" id="posicionamiento_encoder" value="SI"<?= valorMarcado($datos, 'posicionamiento_encoder') ?>>
                <strong>Posicionamiento por encoder</strong>
            </label>
        </div>

        <div class="campo control-check-item">
            <label class="checkbox-label">
                <input type="checkbox" name="agregar_contactorpot" value="SI"<?= valorMarcado($datos, 'agregar_contactorpot') ?>>
                <strong>Agregar contactor de potencial</strong>
            </label>
        </div>

        <div class="campo control-check-item" id="grupo_tandem" style="display:none;">
            <label class="checkbox-label">
                <input type="checkbox" name="es_tandem" id="es_tandem" value="SI"<?= valorMarcado($datos, 'es_tandem') ?>>
                <strong>Sistema tándem</strong>
            </label>
        </div>

        <div class="campo control-check-item" id="grupo_maniobra_sabatica" style="display:none;">
            <label class="checkbox-label">
                <input type="checkbox" name="maniobra_sabatica" id="maniobra_sabatica" value="SI"<?= valorMarcado($datos, 'maniobra_sabatica') ?>>
                <strong>Maniobra sabática estándar</strong>
            </label>
            <div class="ayuda">Disponible según las capacidades configuradas en Mantenimiento → Matrices de Control. La versión completa conserva sus reglas técnicas específicas de cálculo.</div>
        </div>

        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="emergencia_corte" value="SI"<?= valorMarcado($datos, 'emergencia_corte') ?>><strong>Conexión para sistema de emergencia por corte de energía</strong></label></div>
        <div class="campo control-check-item" id="grupo_alimentacion_puerta_vf" style="display:none;"><label class="checkbox-label"><input type="checkbox" name="alimentacion_puerta_vf" id="alimentacion_puerta_vf" value="SI"<?= valorMarcado($datos, 'alimentacion_puerta_vf') ?>><strong>Alimentación para puerta VF</strong></label></div>
        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="forzador_aire" value="SI"<?= valorMarcado($datos, 'forzador_aire') ?>><strong>Forzador de aire</strong></label></div>
        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="luz_cortesia" value="SI"<?= valorMarcado($datos, 'luz_cortesia') ?>><strong>Luz de cortesía</strong></label></div>
        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="llave_ramos" value="SI"<?= valorMarcado($datos, 'llave_ramos') ?>><strong>Llave Ramos Mejía</strong></label></div>
        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="descanso_freno" value="SI"<?= valorMarcado($datos, 'descanso_freno') ?>><strong>Resistencia de descanso para freno</strong></label></div>
        <div class="campo control-check-item"><label class="checkbox-label"><input type="checkbox" name="protector_falta_fase" id="protector_falta_fase" value="SI"<?= valorMarcado($datos, 'protector_falta_fase') ?>><strong>Protector de falta de fase</strong></label></div>
        <div class="campo control-check-item" id="grupo_retorno_bateria_gel" style="display:none;"><label class="checkbox-label"><input type="checkbox" name="retorno_bateria_gel" value="SI"<?= valorMarcado($datos, 'retorno_bateria_gel') ?>><strong>Retorno automático por batería de gel</strong></label></div>
        <div class="campo control-check-item" id="grupo_micronivelacion" style="display:none;"><label class="checkbox-label"><input type="checkbox" name="micronivelacion" value="SI"<?= valorMarcado($datos, 'micronivelacion') ?>><strong>Micronivelación para hidráulicos</strong></label></div>
        </div>

        <div class="campo campo-adicional-ancho" id="grupo_rescate" style="display:none;">
            <label for="id_rescate">Rescate:</label>
            <select name="id_rescate" id="id_rescate">
                <option value="">Sin rescate</option>
                <?php foreach ($rescatesHidraulicos as $rRescate): ?>
                    <option value="<?= (int)$rRescate['rescate_id'] ?>" data-familia="HIDRAULICO" data-clave="<?= escapar($rRescate['rescate_clave']) ?>" data-compatibilidades="<?= escapar(json_encode($rRescate['compatibilidades'] ?? array(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"<?= valorSeleccionado($datos, 'id_rescate', $rRescate['rescate_id']) ?>><?= escapar($rRescate['rescate_nombre']) ?></option>
                <?php endforeach; ?>
                <?php foreach ($rescatesMrl as $rRescate): ?>
                    <option value="<?= (int)$rRescate['rescate_id'] ?>" data-familia="MRL_IMAN" data-clave="<?= escapar($rRescate['rescate_clave']) ?>" data-compatibilidades="<?= escapar(json_encode($rRescate['compatibilidades'] ?? array(), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) ?>"<?= valorSeleccionado($datos, 'id_rescate', $rRescate['rescate_id']) ?>><?= escapar($rRescate['rescate_nombre']) ?></option>
                <?php endforeach; ?>
            </select>
            <div class="ayuda">El rescate y sus componentes se valorizan con los precios de la base Bejerman vigente, según las reglas configuradas para cada opción.</div>
        </div>

        <div class="campo campo-adicional-ancho" id="grupo_adicionales_ucm_mrl" style="display:none;">
            <label class="checkbox-label">
                <input type="checkbox" name="adicional_ucm_mrl" id="adicional_ucm_mrl" value="SI"<?= valorMarcado($datos, 'adicional_ucm_mrl') ?>>
                <strong>Adicional UCM</strong>
            </label>
            <label for="cantidad_fuentes_mrl" style="margin-top:10px;">Fuente para indicadores en todos los pisos MRL — cantidad por equipo:</label>
            <input type="number" name="cantidad_fuentes_mrl" id="cantidad_fuentes_mrl" min="0" max="100" step="1" value="<?= escapar((string)($datos['cantidad_fuentes_mrl'] ?? '0')) ?>">
            <div class="ayuda">Solo para controles MRL. Cada F24V2AMRL alimenta hasta 28 indicadores Alfa. La cantidad ingresada es por equipo.</div>
        </div>

        <div class="campo campo-adicional-ancho">
            <label for="cantidad_fuentes_24v">Fuentes 24 V para indicadores — cantidad por equipo:</label>
            <input type="number" name="cantidad_fuentes_24v" id="cantidad_fuentes_24v" min="0" max="100" step="1" value="<?= escapar((string)($datos['cantidad_fuentes_24v'] ?? '')) ?>">
            <div class="ayuda">La cantidad ingresada se multiplica por la cantidad de equipos cotizados.</div>
        </div>

        <div class="campo campo-adicional-ancho">
            <label for="interfase_tipo">Interfase:</label>
            <select name="interfase_tipo" id="interfase_tipo">
                <option value=""<?= valorSeleccionado($datos, 'interfase_tipo', '') ?>>Sin interfase</option>
                <option value="A7120"<?= valorSeleccionado($datos, 'interfase_tipo', 'A7120') ?>>Interfase A-7120</option>
                <option value="A7120_A7121"<?= valorSeleccionado($datos, 'interfase_tipo', 'A7120_A7121') ?>>Interfase A-7120 + A-7121</option>
            </select>
            <div class="ayuda">Se calcula una interfase por equipo según sus paradas: hasta 8, 16, 24 o 32. La opción A-7120 + A-7121 agrega además una P7121 por equipo.</div>
        </div>

        <div class="campo campo-adicional-ancho">
            <label class="checkbox-label">
                <input type="checkbox" name="fuente_switching_touch" id="fuente_switching_touch" value="SI"<?= valorMarcado($datos, 'fuente_switching_touch') ?>>
                <strong>Fuente switching para botoneras touch</strong>
            </label>
            <div class="ayuda">Agrega una F24V2AR por equipo. También se incorporará automáticamente cuando haya pulsadores simples o dobles y se seleccione una botonera touch compatible.</div>
        </div>

        <div class="adicionales-manuales">
            <div class="titulo-manual">Adicionales manuales (importe por equipo)</div>
            <?php for ($iManual = 1; $iManual <= 3; $iManual++): ?>
                <div class="campo">
                    <label for="adicional_manual_descripcion_<?= $iManual ?>">Descripción adicional <?= $iManual ?>:</label>
                    <input type="text" name="adicional_manual_descripcion_<?= $iManual ?>" id="adicional_manual_descripcion_<?= $iManual ?>" maxlength="200" value="<?= escapar((string)($datos['adicional_manual_descripcion_' . $iManual] ?? '')) ?>" placeholder="Descripción libre">
                </div>
                <div class="campo">
                    <label for="adicional_manual_importe_<?= $iManual ?>">Importe por equipo:</label>
                    <input type="number" name="adicional_manual_importe_<?= $iManual ?>" id="adicional_manual_importe_<?= $iManual ?>" min="0" step="0.01" value="<?= escapar((string)($datos['adicional_manual_importe_' . $iManual] ?? '')) ?>" placeholder="0,00">
                </div>
            <?php endfor; ?>
            <div class="ayuda">Cada importe se multiplica por la cantidad de equipos y se suma al subtotal antes de aplicar los descuentos.</div>
        </div>

        </div></div>
        <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
        <div class="control-seccion cerrada" id="control_paso_descuentos">
        <div class="control-seccion-titulo" onclick="toggleControlSeccion(this)" onkeydown="toggleControlSeccionKey(event,this)" role="button" tabindex="0" aria-expanded="false"><span class="control-titulo-texto"><strong>4. Condiciones comerciales</strong><small>Descuentos del control antes de emitir.</small></span><span class="control-seccion-acciones"><span class="control-tag">DESCUENTOS</span><span class="control-toggle">⌃</span></span></div>
        <div class="control-seccion-cuerpo">
        <?php
        $d1Actual = isset($datos['descuento_1']) ? (int)$datos['descuento_1'] : $defaultControlD1;
        $d2Actual = isset($datos['descuento_2']) ? (int)$datos['descuento_2'] : $defaultControlD2;
        $d3Actual = isset($datos['descuento_3']) ? (int)$datos['descuento_3'] : $defaultControlD3;
        ?>
        <div id="cliente_cecaf_condiciones" class="aviso" style="display:none;margin-bottom:12px;background:#e4f5eb;border:1px solid #b7dfc8;color:#14623f;padding:10px;border-radius:8px;"></div>
        <div class="descuentos-grid">
            <div class="campo">
                <label for="descuento_1">Descuento 1 (%):</label>
                <input type="number" name="descuento_1" id="descuento_1" min="0" max="100" step="1" value="<?= $d1Actual ?>" required>
            </div>
            <div class="campo">
                <label for="descuento_2">Descuento 2 (%):</label>
                <input type="number" name="descuento_2" id="descuento_2" min="0" max="100" step="1" value="<?= $d2Actual ?>" required>
            </div>
            <div class="campo">
                <label for="descuento_3">Descuento 3 (%):</label>
                <input type="number" name="descuento_3" id="descuento_3" min="0" max="100" step="1" value="<?= $d3Actual ?>" required>
            </div>
        </div>
        <div class="ayuda">Los descuentos son números enteros y se aplican sucesivamente. Los valores iniciales provienen de Administración → Condiciones comerciales y pueden modificarse antes de calcular o guardar.</div>
        </div></div>
        <?php endif; ?>

        <div class="acciones-cotizacion">
            <?php if ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar'): ?>
                <button type="submit" formaction="guardar_plantilla.php" formtarget="_self" formnovalidate style="background:#198754">GUARDAR PLANTILLA</button>
                <a class="boton-reset" href="administrar_plantillas.php">CANCELAR</a>
            <?php else: ?>
                <?php if ($cotizacionEdicionId > 0 && !empty($datos['senal_incluir'])): ?>
                <button type="button" onclick="guardarCotizacionCompletaConSenalizacion()" style="background:#0d6efd">GUARDAR CAMBIOS DE COTIZACIÓN</button>
                <?php else: ?>
                <?php if ($pedidoEdicionId > 0): ?>
                <div class="aviso" style="grid-column:1/-1;background:#fff3cd;border:1px solid #ffecb5;color:#664d03;padding:10px;border-radius:6px;">Está modificando el pedido. Termine de revisar los módulos y use el panel derecho para indicar el motivo y guardar la nueva revisión.</div>
                <?php else: ?>
                <?php if ($cotizacionEdicionId > 0): ?>
                <button type="button" class="resumen-accion-emision" data-emision="cotizacion" onclick="guardarDocumentoModular('guardar_cotizacion',this)" style="background:#0d6efd">GUARDAR CAMBIOS DE COTIZACIÓN COMPLETA</button>
                <?php endif; ?>
                <?php endif; ?>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </form>
    </div>

