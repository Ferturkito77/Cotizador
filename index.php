<?php
session_start();

if (file_exists('conexion.php')) {
    include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
} else {
    die('Error: No se encuentra el archivo conexion.php');
}

$conexion->set_charset('utf8mb4');
require_once 'sistema_comercial.php';
require_once 'plantillas_controles.php';
require_once 'plantillas_senalizacion.php';
require_once 'control_parametros.php';
require_once 'parametros_sistema.php';
require_once 'cliente_especificaciones.php';
require_once __DIR__ . '/cotizador_helpers.php';
require_once __DIR__ . '/cotizador_contexto.php';
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cotizador de Ascensores</title>

    <!-- AUTOMAC v391 - evitar FOUC/flash de interfaces anteriores -->
    <script>
    document.documentElement.classList.add('automac-v391-cargando');
    try{document.documentElement.classList.add('automac-ui-unica');}catch(e){}
    (function(){
      function liberarPrecarga(){
        if(document.body?.classList.contains('v15-workbench') && !document.getElementById('v15_workspace')){
          document.querySelector('#cotizador_layout > .form-container')?.classList.remove('v15-retired-form','v302-vacio');
        }
        document.documentElement.classList.remove('automac-v391-cargando');
      }
      setTimeout(liberarPrecarga,2500);
      window.addEventListener('pageshow',liberarPrecarga);
    })();
    </script>
    <style id="automac-v391-preload">
    /* La precarga oculta sólo el formulario original; nunca el body completo. */
    @media (min-width:980px){
      html.automac-v391-cargando #cotizador_layout > .form-container{opacity:0!important;visibility:hidden!important;}
    }
    </style>

    <link rel="stylesheet" href="cotizador-ui.css?v=489">
    <link rel="stylesheet" href="cotizador-ui-polish.css?v=487">
    <script>
        let secuenciaSubtiposNueva = 0;
        async function cargarSubtipos(idTipoControl, idSubtipoPreferido = '', limpiarTipoSiNoDisponible = false) {
            const secuenciaActual = ++secuenciaSubtiposNueva;
            const selectCpu = document.getElementById('id_cpu');
            const selectSubtipo = document.getElementById('id_subtipo');

            if (!selectCpu.value || !idTipoControl) {
                selectSubtipo.innerHTML = '<option value="">Seleccione CPU y tipo de control...</option>';
                programarCalculoTiempoReal(100);
                return;
            }

            /*
             * Al cambiar la CPU o el tipo se vuelve a consultar la lista de subtipos.
             * Conservamos el subtipo actual cuando también existe para la nueva combinación.
             */
            const subtipoAnterior = String(idSubtipoPreferido || selectSubtipo.value || '');
            selectSubtipo.innerHTML = '<option value="">Cargando subtipos...</option>';

            const datos = new FormData();
            datos.append('id_cpu', selectCpu.value);
            datos.append('id_tipo_control', idTipoControl);
            datos.append('lista_id', document.getElementById('lista_id').value);

            try {
                const respuesta = await fetch('get_subtipos.php?_=' + Date.now(), {
                    method: 'POST',
                    body: datos,
                    cache: 'no-store'
                });

                if (!respuesta.ok) {
                    throw new Error('Respuesta HTTP ' + respuesta.status);
                }

                const opciones = await respuesta.text();
                if (secuenciaActual !== secuenciaSubtiposNueva) return;
                selectSubtipo.innerHTML = opciones;

                const opcionesValidas = Array.from(selectSubtipo.options).filter(function (opcion) {
                    return String(opcion.value || '') !== '';
                });
                const existeAnterior = subtipoAnterior !== '' && opcionesValidas.some(function (opcion) {
                    return String(opcion.value) === subtipoAnterior;
                });

                if (opcionesValidas.length === 0 && limpiarTipoSiNoDisponible) {
                    const tipoControl = document.getElementById('id_tipo_control');
                    if (tipoControl) tipoControl.value = '';
                    selectSubtipo.innerHTML = '<option value="">Seleccione primero una combinación válida...</option>';
                    actualizarCentral();
                    actualizarOpcionesHidraulicas();
                    actualizarVelocidad();
                    actualizarPosicionamientoEncoder();
                    actualizarAdicionalesVisibles();
                    actualizarTipoAgrupacion();
                    invalidarCalculoAuxiliar('La CPU seleccionada no dispone del tipo de control anterior. Seleccione una configuración válida.');
                    return;
                }

                if (existeAnterior) {
                    selectSubtipo.value = subtipoAnterior;
                } else if (opcionesValidas.length === 1) {
                    /* Si la combinación tiene un único subtipo, se selecciona automáticamente. */
                    selectSubtipo.value = String(opcionesValidas[0].value);
                } else {
                    /* Si hay varias alternativas incompatibles, se exige una elección real. */
                    selectSubtipo.value = '';
                }

                actualizarAdicionalesVisibles();
                recalcularControlUniversal();
            } catch (error) {
                if (secuenciaActual !== secuenciaSubtiposNueva) return;
                console.error(error);
                selectSubtipo.innerHTML = '<option value="">Error al cargar subtipos</option>';
            }
        }

        function actualizarCentral() {
            const tipoControl = document.getElementById('id_tipo_control');
            const grupoCentral = document.getElementById('grupo_central');
            const central = document.getElementById('id_central');
            const grupoOtra = document.getElementById('grupo_central_otra');
            const centralOtra = document.getElementById('central_otra_nombre');
            const opTipo = tipoControl && tipoControl.selectedIndex >= 0 ? tipoControl.options[tipoControl.selectedIndex] : null;
            const esHidraulico = opTipo ? String(opTipo.dataset.requiereCentral || '') === 'SI' : (tipoControl && tipoControl.value === '3');
            const opcionCentral = central ? central.options[central.selectedIndex] : null;
            const esOtra = esHidraulico && opcionCentral && opcionCentral.dataset.esOtra === '1';

            if (!grupoCentral || !central) {
                return;
            }

            grupoCentral.style.display = esHidraulico ? 'block' : 'none';
            central.required = esHidraulico;

            if (grupoOtra && centralOtra) {
                grupoOtra.style.display = esOtra ? 'block' : 'none';
                centralOtra.required = esOtra;
            }

            if (!esHidraulico) {
                central.value = '';
                if (centralOtra) {
                    centralOtra.value = '';
                }
            } else if (!esOtra && centralOtra) {
                centralOtra.value = '';
            }
        }

        function actualizarVelocidad() {
            const tipoControl = document.getElementById('id_tipo_control');
            const grupoVelocidad = document.getElementById('grupo_velocidad');
            const velocidad = document.getElementById('velocidad_vf');
            const subtipo = document.getElementById('id_subtipo');
            const opTipo = tipoControl && tipoControl.selectedIndex >= 0 ? tipoControl.options[tipoControl.selectedIndex] : null;
            const requiereVelocidad = opTipo ? String(opTipo.dataset.requiereVelocidad || '') === 'SI' : (tipoControl && ['4','5','6','7'].includes(tipoControl.value));

            if (!grupoVelocidad || !velocidad) {
                return;
            }

            grupoVelocidad.style.display = requiereVelocidad ? 'block' : 'none';
            velocidad.required = requiereVelocidad;

            if (!requiereVelocidad) {
                velocidad.value = '';
                return;
            }

            // v97: la velocidad debe ser compatible simultáneamente con variador y CPU.
            const opcionSubtipo = subtipo && subtipo.selectedIndex >= 0 ? subtipo.options[subtipo.selectedIndex] : null;
            const maxTexto = opcionSubtipo ? String(opcionSubtipo.dataset.velocidadMax || '') : '';
            const velocidadMaxVariador = maxTexto !== '' ? Number(maxTexto) : null;
            const cpu = document.getElementById('id_cpu');
            const opcionCpu = cpu && cpu.selectedIndex >= 0 ? cpu.options[cpu.selectedIndex] : null;
            const maxCpuTexto = opcionCpu ? String(opcionCpu.dataset.velocidadMax || '') : '';
            const velocidadMaxCpu = maxCpuTexto !== '' ? Number(maxCpuTexto) : null;

            Array.from(velocidad.options).forEach(function(opcion) {
                if (!opcion.value) return;
                const v = Number(opcion.value);
                const excedeVariador = velocidadMaxVariador !== null && Number.isFinite(velocidadMaxVariador) && v > velocidadMaxVariador;
                const excedeCpu = velocidadMaxCpu !== null && Number.isFinite(velocidadMaxCpu) && v > velocidadMaxCpu;
                opcion.disabled = excedeVariador || excedeCpu;
            });

            if (velocidad.value) {
                const actual = Number(velocidad.value);
                const invalidaVariador = velocidadMaxVariador !== null && Number.isFinite(velocidadMaxVariador) && actual > velocidadMaxVariador;
                const invalidaCpu = velocidadMaxCpu !== null && Number.isFinite(velocidadMaxCpu) && actual > velocidadMaxCpu;
                if (invalidaVariador || invalidaCpu) velocidad.value = '';
            }
            actualizarEncoderObligatorioPorVelocidad();
        }

        function actualizarEncoderObligatorioPorVelocidad() {
            const velocidad = document.getElementById('velocidad_vf');
            const encoder = document.querySelector('input[name="encoder"]');
            const aviso = document.getElementById('aviso_encoder_velocidad');
            const cpu = document.getElementById('id_cpu');
            const tipoControl = document.getElementById('id_tipo_control');
            if (!encoder) return;

            const opTipo = tipoControl && tipoControl.selectedIndex >= 0 ? tipoControl.options[tipoControl.selectedIndex] : null;
            const tipoUsaEncoder = opTipo ? String(opTipo.dataset.requiereVelocidad || '') === 'SI' : (tipoControl && ['4','5','6','7'].includes(String(tipoControl.value || '')));
            const opcionCpu = cpu && cpu.selectedIndex >= 0 ? cpu.options[cpu.selectedIndex] : null;
            const admiteEncoder = opcionCpu ? String(opcionCpu.dataset.admiteEncoder || '') : '';
            const cpuNoAdmiteEncoder = admiteEncoder === 'NO';
            const obligatorioVelocidad = tipoUsaEncoder && velocidad && Number(velocidad.value || 0) > 75;

            // v115: el encoder nunca se auto-tilda ni queda deshabilitado.
            // El usuario lo selecciona cuando corresponde y el servidor valida la obligatoriedad.
            encoder.disabled = false;
            encoder.dataset.encoderObligatorio = obligatorioVelocidad ? '1' : '0';
            encoder.dataset.encoderIncompatible = cpuNoAdmiteEncoder ? '1' : '0';

            // Al pasar desde una configuracion con encoder a 1V/2V/hidraulico, o a una CPU
            // que no lo soporta, limpiamos el valor arrastrado para no bloquear el calculo.
            if (!tipoUsaEncoder || cpuNoAdmiteEncoder) {
                encoder.checked = false;
            }

            if (aviso) {
                if (cpuNoAdmiteEncoder) {
                    aviso.textContent = 'La CPU seleccionada no soporta encoder de motor.';
                    aviso.style.display = 'block';
                    aviso.style.color = '#a12b2b';
                } else if (obligatorioVelocidad) {
                    aviso.textContent = 'Para velocidades superiores a 75 m/min debe marcar Encoder de motor.';
                    aviso.style.display = 'block';
                    aviso.style.color = '#a15a00';
                } else {
                    aviso.style.display = 'none';
                }
            }
        }

        function opcionSeleccionadaPideCantidad(selectId) {
            const select = document.getElementById(selectId);

            if (!select || select.selectedIndex < 0) {
                return false;
            }

            const opcion = select.options[select.selectedIndex];

            return opcion && opcion.dataset.pideCantidad === 'SI';
        }

        function configurarCantidadPuerta(selectId, grupoId, inputId, activo) {
            const grupo = document.getElementById(grupoId);
            const input = document.getElementById(inputId);
            const pideCantidad = activo && opcionSeleccionadaPideCantidad(selectId);

            if (!grupo || !input) {
                return;
            }

            grupo.style.display = pideCantidad ? 'block' : 'none';
            input.required = pideCantidad;
            input.disabled = !pideCantidad;
        }

        const aperturasOperadoresInicialesV172 = <?= json_encode(array_values(is_array($datos['operador_aperturas'] ?? null) ? $datos['operador_aperturas'] : array()), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
        const cacheAperturasOperadoresV172 = aperturasOperadoresInicialesV172.slice();

        function actualizarAperturasOperadoresV172() {
            const cantidadInput = document.getElementById('cantidad_operadores');
            const grupo = document.getElementById('grupo_aperturas_operadores');
            const lista = document.getElementById('lista_aperturas_operadores');
            if (!cantidadInput || !grupo || !lista) return;

            lista.querySelectorAll('input[data-operador-apertura]').forEach(function(input){
                const idx = parseInt(input.dataset.operadorApertura || '-1', 10);
                if (idx >= 0) cacheAperturasOperadoresV172[idx] = input.value;
            });

            const cantidad = Math.max(0, parseInt(cantidadInput.value || '0', 10) || 0);
            const habilitado = !cantidadInput.disabled && cantidad >= 2;
            grupo.style.display = habilitado ? 'block' : 'none';
            lista.innerHTML = '';
            if (!habilitado) return;

            for (let i = 0; i < cantidad; i++) {
                const fila = document.createElement('label');
                fila.className = 'operador-apertura-fila-v172';
                const titulo = document.createElement('span');
                titulo.textContent = 'Operador ' + (i + 1) + ' · Pisos donde abre';
                const input = document.createElement('input');
                input.type = 'text';
                input.name = 'operador_aperturas[' + i + ']';
                input.dataset.operadorApertura = String(i);
                input.value = String(cacheAperturasOperadoresV172[i] || '');
                input.placeholder = 'Ej.: PB, 1, 2, 3 · si no se conoce: A CONFIRMAR';
                input.autocomplete = 'off';
                input.addEventListener('input', function(){ cacheAperturasOperadoresV172[i] = input.value; });
                fila.appendChild(titulo);
                fila.appendChild(input);
                lista.appendChild(fila);
            }
        }

        function actualizarCantidadPuertaCabina(activo = true) {
            configurarCantidadPuerta(
                'id_ptacabina',
                'grupo_cantidad_operadores',
                'cantidad_operadores',
                activo
            );
            actualizarAperturasOperadoresV172();
        }

        function actualizarCantidadPisosMc(activo = true) {
            configurarCantidadPuerta(
                'id_ptapisos_mc',
                'grupo_cantidad_pisos_mc',
                'cantidad_pisos_mc',
                activo
            );
        }

        function actualizarCantidadCabinaMc(activo = true) {
            configurarCantidadPuerta(
                'id_ptacabina_mc',
                'grupo_cantidad_cabina_mc',
                'cantidad_cabina_mc',
                activo
            );
        }

        function actualizarPuertas() {
            const maniobra = document.getElementById('id_maniobra');

            const grupoCabina = document.getElementById('grupo_ptacabina');
            const grupoPisos = document.getElementById('grupo_ptapisos');
            const grupoPisosMc = document.getElementById('grupo_ptapisos_mc');
            const grupoCabinaMc = document.getElementById('grupo_ptacabina_mc');

            const puertaCabina = document.getElementById('id_ptacabina');
            const puertasPisos = document.getElementById('id_ptapisos');
            const puertasPisosMc = document.getElementById('id_ptapisos_mc');
            const puertaCabinaMc = document.getElementById('id_ptacabina_mc');

            if (
                !maniobra ||
                !grupoCabina ||
                !grupoPisos ||
                !grupoPisosMc ||
                !grupoCabinaMc ||
                !puertaCabina ||
                !puertasPisos ||
                !puertasPisosMc ||
                !puertaCabinaMc
            ) {
                return;
            }

            let esMc = false;

            if (maniobra.selectedIndex >= 0) {
                const opcion = maniobra.options[maniobra.selectedIndex];
                esMc = opcion && opcion.dataset.esMc === '1';
            }

            grupoCabina.style.display = esMc ? 'none' : 'block';
            grupoPisos.style.display = esMc ? 'none' : 'block';
            grupoPisosMc.style.display = esMc ? 'block' : 'none';
            grupoCabinaMc.style.display = esMc ? 'block' : 'none';

            puertaCabina.disabled = esMc;
            puertasPisos.disabled = esMc;
            puertaCabina.required = !esMc;
            puertasPisos.required = !esMc;

            puertasPisosMc.disabled = !esMc;
            puertaCabinaMc.disabled = !esMc;
            puertasPisosMc.required = esMc;
            puertaCabinaMc.required = esMc;

            actualizarCantidadPuertaCabina(!esMc);
            actualizarCantidadPisosMc(esMc);
            actualizarCantidadCabinaMc(esMc);
        }

        function actualizarConfiguracionEspecialControl() {
            const sel = document.getElementById('configuracion_especial_control');
            const grupoTip = document.getElementById('grupo_programa_tip');
            const programaTip = document.getElementById('programa_tip');
            if (!sel || !grupoTip || !programaTip) return;
            const esTip = sel.value === 'TIP';
            grupoTip.style.display = esTip ? 'block' : 'none';
            programaTip.disabled = !esTip;
            programaTip.required = esTip;
            if (!esTip) programaTip.value = '';
            actualizarResumenControl();
        }

        function prepararAplicacionPlantilla() {
            const copiar = (origenId, destinoId) => {
                const origen = document.getElementById(origenId);
                const destino = document.getElementById(destinoId);
                if (destino) destino.value = origen ? origen.value : '';
            };
            copiar('id_cliente', 'plantilla_cliente_actual');
            copiar('referencia_cotizacion', 'plantilla_referencia_actual');
            copiar('solicitante_cliente', 'plantilla_solicitante_actual');
            copiar('lista_id', 'plantilla_lista_actual');
            copiar('descuento_1', 'plantilla_descuento_1_actual');
            copiar('descuento_2', 'plantilla_descuento_2_actual');
            copiar('descuento_3', 'plantilla_descuento_3_actual');
        }

        function cargarCliente() {
            const cliente=document.getElementById('id_cliente');
            const aviso=document.getElementById('cliente_cecaf_aviso');
            const avisoCond=document.getElementById('cliente_cecaf_condiciones');
            if(aviso){aviso.classList.remove('visible');aviso.textContent='';}
            if(avisoCond){avisoCond.style.display='none';avisoCond.textContent='';}
            if(!cliente || !cliente.value){ if(typeof cargarEspecificacionesTecnicasCliente==='function') cargarEspecificacionesTecnicasCliente(0); return; }
            if(typeof cargarEspecificacionesTecnicasCliente==='function') cargarEspecificacionesTecnicasCliente(cliente.value);
            fetch('get_cliente.php?id_cliente='+encodeURIComponent(cliente.value),{credentials:'same-origin'})
              .then(r=>r.json()).then(data=>{
                if(!data || !data.ok) return;
                const nro=String(data.clientes_socio_cecaf_numero||'').trim();
                if(aviso && nro!==''){
                    aviso.innerHTML='<strong>SOCIO CECAF · Nº '+escaparHtml(nro)+'</strong><br>Revisar/aplicar el descuento correspondiente antes de emitir.';
                    aviso.classList.add('visible');
                }
                if(avisoCond && nro!==''){
                    avisoCond.innerHTML='<strong>Cliente socio CECAF Nº '+escaparHtml(nro)+'.</strong> Revisar/aplicar el descuento CECAF correspondiente antes de emitir.';
                    avisoCond.style.display='block';
                }
            }).catch(()=>{});
        }


        function normalizarBusquedaCliente(texto) {
            return String(texto ?? '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase().trim();
        }

        function seleccionarClienteCotizador(id,nombre) {
            const buscador=document.getElementById('cliente_busqueda');
            const oculto=document.getElementById('id_cliente');
            const resultados=document.getElementById('cliente_resultados');
            if(!buscador || !oculto) return;
            buscador.value=String(nombre||'');
            oculto.value=String(id||'');
            if(resultados){resultados.classList.remove('visible');resultados.innerHTML='';}
            ocultarAvisoClienteObligatorio();
            cargarCliente();
            if(typeof actualizarUIOperativaV127==='function') actualizarUIOperativaV127();
        }

        function filtrarClientesCotizador() {
            const buscador=document.getElementById('cliente_busqueda');
            const oculto=document.getElementById('id_cliente');
            const resultados=document.getElementById('cliente_resultados');
            if(!buscador || !oculto || !resultados) return;
            const q=normalizarBusquedaCliente(buscador.value);
            oculto.value='';
            if(typeof actualizarUIOperativaV127==='function') actualizarUIOperativaV127();
            if(!q){resultados.classList.remove('visible');resultados.innerHTML='';return;}
            const lista=(window.clientesBusquedaCotizador||[]).filter(function(c){
                return normalizarBusquedaCliente(c.busqueda).includes(q);
            }).slice(0,15);
            resultados.innerHTML='';
            if(!lista.length){
                const vacio=document.createElement('div');vacio.className='cliente-sin-resultados';vacio.textContent='No se encontraron clientes.';resultados.appendChild(vacio);
            }else{
                lista.forEach(function(c){
                    const b=document.createElement('button');b.type='button';b.className='cliente-resultado';b.textContent=c.nombre;
                    b.addEventListener('mousedown',function(ev){ev.preventDefault();seleccionarClienteCotizador(c.id,c.nombre);});
                    resultados.appendChild(b);
                });
            }
            resultados.classList.add('visible');
        }

        function seleccionarClienteDesdeBusqueda() {
            // Compatibilidad con llamadas existentes: valida el nombre visible exacto,
            // pero las búsquedas por sigla / Nº Bejerman se resuelven en el panel propio.
            const buscador=document.getElementById('cliente_busqueda');
            const oculto=document.getElementById('id_cliente');
            if(!buscador || !oculto) return;
            const valor=normalizarBusquedaCliente(buscador.value);
            if(!valor) return;
            const coincidencias=(window.clientesBusquedaCotizador||[]).filter(c=>normalizarBusquedaCliente(c.nombre)===valor);
            if(coincidencias.length===1) seleccionarClienteCotizador(coincidencias[0].id,coincidencias[0].nombre);
        }

        function escaparHtml(texto) {
            return String(texto ?? '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        function actualizarTipoAgrupacion() {
            const cantidadCotizada = Number(document.getElementById('cantidad_equipos')?.value || 1);
            const bateria = document.getElementById('id_bateria');
            const grupoTotal = document.getElementById('grupo_total_coches_bateria');
            const totalCoches = document.getElementById('cantidad_total_coches_bateria');
            const grupoObra = document.getElementById('grupo_numero_obra_otro');
            const numeroObra = document.getElementById('numero_obra_otro');
            const grupoObs = document.getElementById('grupo_observacion_bateria');
            const observacion = document.getElementById('observacion_bateria');
            const grupoMismasParadas = document.getElementById('grupo_mismas_paradas');
            const checkMismasParadas = document.getElementById('mismas_paradas');
            if (!bateria || !grupoTotal || !totalCoches || !grupoObra || !numeroObra || !grupoObs || !observacion) return;

            const opcionBateria = bateria.options[bateria.selectedIndex];
            const codigoBateria = opcionBateria ? String(opcionBateria.dataset.codigo || '') : '';
            const esIndividual = codigoBateria === 'INDIVIDUAL';
            const esBateria = codigoBateria === 'BATERIA';

            grupoTotal.style.display = esIndividual ? 'none' : 'block';
            totalCoches.disabled = esIndividual;
            totalCoches.required = !esIndividual;

            if (esIndividual) {
                totalCoches.value = String(Math.max(1, cantidadCotizada));
            } else {
                const cpuId = String(document.getElementById('id_cpu')?.value || '');
                const maximosPorCpu = { '1': 2, '2': 4, '3': 8, '4': 4, '5': 4 };
                const maximo = maximosPorCpu[cpuId] || 1;
                const minimo = Math.max(1, cantidadCotizada);
                const actual = Number(totalCoches.value || 0);

                totalCoches.min = String(minimo);
                totalCoches.max = String(maximo);

                if (!Number.isInteger(actual) || actual < minimo || actual > maximo) {
                    totalCoches.value = String(Math.min(minimo, maximo));
                }
            }

            grupoObra.style.display = esBateria ? 'block' : 'none';
            numeroObra.disabled = !esBateria;
            if (!esBateria) numeroObra.value = '';

            grupoObs.style.display = esIndividual ? 'none' : 'block';
            observacion.disabled = esIndividual;
            if (esIndividual) observacion.value = '';

            if (grupoMismasParadas) grupoMismasParadas.style.display = !esIndividual && cantidadCotizada > 1 ? 'block' : 'none';
            if (checkMismasParadas && esIndividual) checkMismasParadas.checked = false;
            aplicarMismasParadas();
        }

        function sincronizarParadasBateria(origen) {
            const check = document.getElementById('mismas_paradas');
            const inputs = Array.from(document.querySelectorAll('input[name="paradas_equipo[]"]'));
            if (check && check.checked && inputs.length > 0 && origen === inputs[0]) {
                inputs.slice(1).forEach(function (input) { input.value = inputs[0].value; });
            }
            // v108: Paradas es un dato de cálculo. Cualquier modificación debe refrescar
            // inmediatamente el cálculo auxiliar, tenga o no activada la copia a otros coches.
            programarCalculoTiempoReal(35);
        }

        function aplicarMismasParadas() {
            const check = document.getElementById('mismas_paradas');
            const inputs = Array.from(document.querySelectorAll('input[name="paradas_equipo[]"]'));
            if (!check || inputs.length === 0) return;
            const copiar = check.checked;
            if (copiar) {
                inputs.slice(1).forEach(function (input) {
                    input.value = inputs[0].value;
                    input.readOnly = true;
                    input.classList.add('paradas-copiadas');
                });
            } else {
                inputs.slice(1).forEach(function (input) {
                    input.readOnly = false;
                    input.classList.remove('paradas-copiadas');
                });
            }
            programarCalculoTiempoReal(80);
        }

        function generarEquipos(limpiarGuardados = false) {
            const inputCantidad = document.getElementById('cantidad_equipos');
            const contenedor = document.getElementById('equipos_contenedor');
            if (!inputCantidad || !contenedor) return;

            const modoPlantillaActivo = <?= ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar') ? 'true' : 'false' ?>;
            if (modoPlantillaActivo) {
                contenedor.innerHTML = '';
                actualizarTipoAgrupacion();
                return;
            }

            let cantidad = parseInt(inputCantidad.value || '1', 10);
            if (!Number.isInteger(cantidad) || cantidad < 1) cantidad = 1;
            if (cantidad > 10) cantidad = 10;
            inputCantidad.value = cantidad;

            const paradasGuardadas = limpiarGuardados ? [] : <?= json_encode(array_values((array)($datos['paradas_equipo'] ?? (isset($datos['paradas']) ? array($datos['paradas']) : array())))) ?>;
            const nomenclaturasGuardadas = limpiarGuardados ? [] : <?= json_encode(array_values((array)($datos['nomenclatura_equipo'] ?? array()))) ?>;
            const actualesParadas = Array.from(contenedor.querySelectorAll('input[name="paradas_equipo[]"]')).map(i => i.value);
            const actualesNombres = Array.from(contenedor.querySelectorAll('input[name="nomenclatura_equipo[]"]')).map(i => i.value);

            let html = '';
            for (let i = 0; i < cantidad; i++) {
                const paradas = actualesParadas[i] ?? paradasGuardadas[i] ?? '';
                const nomenclatura = actualesNombres[i] ?? nomenclaturasGuardadas[i] ?? '';
                html += '<div class="equipo-card">' +
                    '<h3>Coche ' + (i + 1) + '</h3>' +
                    '<div class="campo"><label>Paradas *</label>' +
                    '<input type="number" name="paradas_equipo[]" min="1" max="64" step="1" required value="' + escaparHtml(paradas) + '" placeholder="Ej.: 8" oninput="sincronizarParadasBateria(this); programarCalculoTiempoReal(35)" onchange="programarCalculoTiempoReal(20)"></div>' +
                    '<div class="campo nomenclatura"><label>Nomenclatura de paradas</label>' +
                    '<input type="text" name="nomenclatura_equipo[]" maxlength="500" ' +
                    'value="' + escaparHtml(nomenclatura) + '" placeholder="Ej.: PB al 7 / -1, 0 al 5 / -2, -1, PB al 5, AZ">' +
                    '<small>Manual por coche. Si queda vacío, en fabricación figurará A CONFIRMAR.</small></div>' +
                    '</div>';
            }
            contenedor.innerHTML = html;
            actualizarTipoAgrupacion();
            aplicarMismasParadas();
        }

        // v106: respaldo aislado para que Paradas siempre se muestre aunque falle otra inicialización.
        document.addEventListener('DOMContentLoaded', function () {
            try {
                const c = document.getElementById('equipos_contenedor');
                const plantilla = <?= ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar') ? 'true' : 'false' ?>;
                if (!plantilla && c && !c.querySelector('input[name=\"paradas_equipo[]\"]')) generarEquipos();
            } catch (e) { console.error('No se pudo inicializar cantidad de paradas', e); }
        });

        function actualizarOpcionesHidraulicas() {
            const tipoControl = document.getElementById('id_tipo_control');
            const grupoTandem = document.getElementById('grupo_tandem');
            const checkTandem = document.getElementById('es_tandem');
            const opTipoTandem = tipoControl && tipoControl.selectedIndex >= 0 ? tipoControl.options[tipoControl.selectedIndex] : null;
            const permiteTandem = opTipoTandem ? String(opTipoTandem.dataset.permiteTandem || '') === 'SI' : (tipoControl && tipoControl.value === '3');

            if (grupoTandem) grupoTandem.style.display = permiteTandem ? 'block' : 'none';
            if (checkTandem) {
                checkTandem.disabled = !permiteTandem;
                if (!permiteTandem) checkTandem.checked = false;
            }
        }


        function actualizarPosicionamientoEncoder() {
            const cpu = document.getElementById('id_cpu');
            const tipo = document.getElementById('id_tipo_control');
            const check = document.getElementById('posicionamiento_encoder');
            const grupo = document.getElementById('grupo_posicionamiento_encoder');
            const permitidos = <?= json_encode(array_keys($posicionamientoEncoderPermitido), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

            if (!cpu || !tipo || !check || !grupo) return;

            const clave = String(cpu.value || '') + ':' + String(tipo.value || '');
            const habilitado = cpu.value !== '' && tipo.value !== '' && permitidos.includes(clave);

            check.disabled = !habilitado;
            grupo.style.opacity = habilitado ? '1' : '.55';
            if (!habilitado) check.checked = false;
        }


        function textoSeleccionado(id) {
            const select = document.getElementById(id);
            if (!select || select.selectedIndex < 0) return '';
            return String(select.options[select.selectedIndex].text || '').toUpperCase();
        }

        function actualizarRescates() {
            const tipo = document.getElementById('id_tipo_control');
            const grupo = document.getElementById('grupo_rescate');
            const select = document.getElementById('id_rescate');
            if (!tipo || !grupo || !select) return;
            const opTipo = tipo && tipo.selectedIndex >= 0 ? tipo.options[tipo.selectedIndex] : null;
            const familia = opTipo ? String(opTipo.dataset.familiaRescate || '') : ''; // v229: sin fallback por IDs; la familia se mantiene en control_tipo_capacidades
            const tiposMrlIman = Array.from(tipo.options).filter(o => String(o.dataset.familiaRescate || '') === 'MRL_IMAN').map(o => String(o.value));
            const subtipoTexto = textoSeleccionado('id_subtipo');
            const esInvt = subtipoTexto.includes('INVT GD300L') || subtipoTexto.includes('INVT GD390L');
            grupo.style.display = familia ? 'block' : 'none';
            Array.from(select.options).forEach(function(op) {
                if (!op.value) { op.hidden = false; op.disabled = false; return; }
                let visible = op.dataset.familia === familia;
                if (op.dataset.clave === 'INVT_INTEGRAL_SIN_UPS') {
                    visible = visible && tiposMrlIman.includes(tipo.value) && esInvt;
                }
                op.hidden = !visible;
                op.disabled = !visible;
            });
            const seleccion = select.selectedOptions[0];
            if (!familia || (seleccion && seleccion.value && seleccion.disabled)) select.value = '';
        }

        function actualizarAdicionalesVisibles() {
            actualizarRescates();
            const tipo = document.getElementById('id_tipo_control');
            const cpuSeleccionada = document.getElementById('id_cpu');
            const opTipoAd = tipo && tipo.selectedIndex >= 0 ? tipo.options[tipo.selectedIndex] : null;
            const esHidraulico = opTipoAd ? String(opTipoAd.dataset.requiereCentral || '') === 'SI' : (tipo && tipo.value === '3');

            const grupoRetorno = document.getElementById('grupo_retorno_bateria_gel');
            const checkRetorno = grupoRetorno ? grupoRetorno.querySelector('input[type="checkbox"]') : null;
            if (grupoRetorno) grupoRetorno.style.display = esHidraulico ? 'block' : 'none';
            if (checkRetorno) {
                checkRetorno.disabled = !esHidraulico;
                if (!esHidraulico) checkRetorno.checked = false;
            }

            const opCpuAd = cpuSeleccionada && cpuSeleccionada.selectedIndex >= 0 ? cpuSeleccionada.options[cpuSeleccionada.selectedIndex] : null;
            const permiteMicronivelacion = esHidraulico && opCpuAd && String(opCpuAd.dataset.micronivelacion || '') === 'SI';
            const grupoMicronivelacion = document.getElementById('grupo_micronivelacion');
            const checkMicronivelacion = grupoMicronivelacion
                ? grupoMicronivelacion.querySelector('input[type="checkbox"]')
                : null;
            if (grupoMicronivelacion) grupoMicronivelacion.style.display = permiteMicronivelacion ? 'block' : 'none';
            if (checkMicronivelacion) {
                checkMicronivelacion.disabled = !permiteMicronivelacion;
                if (!permiteMicronivelacion) checkMicronivelacion.checked = false;
            }

            const nombrePuerta = textoSeleccionado('id_ptacabina') + ' ' + textoSeleccionado('id_ptacabina_mc');
            const puertaVf = nombrePuerta.includes('VF');
            const grupoAlimentacion = document.getElementById('grupo_alimentacion_puerta_vf');
            const checkAlimentacion = document.getElementById('alimentacion_puerta_vf');
            if (grupoAlimentacion) grupoAlimentacion.style.display = puertaVf ? 'block' : 'none';
            if (checkAlimentacion) {
                checkAlimentacion.disabled = !puertaVf;
                if (!puertaVf) checkAlimentacion.checked = false;
            }

            const cpu = document.getElementById('id_cpu');
            const grupoSabatica = document.getElementById('grupo_maniobra_sabatica');
            const checkSabatica = document.getElementById('maniobra_sabatica');
            const opCpuSab = cpu && cpu.selectedIndex >= 0 ? cpu.options[cpu.selectedIndex] : null;
            const permiteSabatica = opCpuSab ? String(opCpuSab.dataset.sabatica || '') === 'SI' : (cpu && ['2','3'].includes(cpu.value));
            if (grupoSabatica) grupoSabatica.style.display = permiteSabatica ? 'block' : 'none';
            if (checkSabatica) {
                checkSabatica.disabled = !permiteSabatica;
                if (!permiteSabatica) checkSabatica.checked = false;
            }

            const esMrl = opTipoAd ? String(opTipoAd.dataset.esMrl || '') === 'SI' : (tipo && tipo.value === '7');

            const grupoGabineteMrl = document.getElementById('grupo_tipo_gabinete_mrl');
            const tipoGabineteMrl = document.getElementById('tipo_gabinete_mrl');
            const opcionGabineteEstandar = document.getElementById('opcion_gabinete_mrl_estandar');
            if (grupoGabineteMrl) grupoGabineteMrl.style.display = esMrl ? 'block' : 'none';
            if (tipoGabineteMrl) {
                tipoGabineteMrl.disabled = !esMrl;
                tipoGabineteMrl.required = !!esMrl;
                if (!esMrl) tipoGabineteMrl.value = '';
            }
            if (opcionGabineteEstandar) {
                const identidadMrl = opCpuAd ? String(opCpuAd.dataset.mrlIdentidad || 'AUTOMAC').toUpperCase() : 'AUTOMAC';
                opcionGabineteEstandar.textContent = 'MRL ' + identidadMrl;
            }

            const grupoUcmMrl = document.getElementById('grupo_adicionales_ucm_mrl');
            const checkUcmMrl = document.getElementById('adicional_ucm_mrl');
            const cantidadFuentesMrl = document.getElementById('cantidad_fuentes_mrl');
            if (grupoUcmMrl) grupoUcmMrl.style.display = esMrl ? 'block' : 'none';
            if (checkUcmMrl) {
                checkUcmMrl.disabled = !esMrl;
                if (!esMrl) checkUcmMrl.checked = false;
            }
            if (cantidadFuentesMrl) {
                cantidadFuentesMrl.disabled = !esMrl;
                if (!esMrl) cantidadFuentesMrl.value = '0';
            }

            const subtipo = document.getElementById('id_subtipo');
            const checkFalta = document.getElementById('protector_falta_fase');
            const incluido = subtipo && textoSeleccionado('id_subtipo').includes('ARRANQUE SUAVE');
            if (checkFalta) {
                checkFalta.checked = incluido ? true : checkFalta.checked;
                checkFalta.disabled = incluido;
                checkFalta.dataset.incluido = incluido ? 'SI' : 'NO';
            }
        }

        const modoPlantillaActivo = <?= ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar') ? 'true' : 'false' ?>;
        let temporizadorCalculo = null;
        let calculoEnCurso = false;
        let recalculoPendiente = false;
        let watchdogCalculoAuxiliar = null;
        let secuenciaCalculoAuxiliar = 0;

        // v121: único punto para informar el estado visible del cálculo auxiliar.
        // Evita que Control y Señalización escriban mensajes/estados de forma distinta.
        function actualizarEstadoCalculoAuxiliar(tipo, mensaje) {
            const estado = document.getElementById('estado_calculo');
            if (!estado) return;
            estado.dataset.estado = tipo || 'pendiente';
            estado.textContent = mensaje || '';
            estado.classList.toggle('actualizando', tipo === 'calculando');
            estado.classList.toggle('estado-ok', tipo === 'ok');
            estado.classList.toggle('estado-aviso', tipo === 'pendiente' || tipo === 'aviso');
            estado.classList.toggle('estado-error', tipo === 'error');
        }

        function marcarEstadoControlDocumento(estado, mensaje = '') {
            window.estadoCalculoDocumento = window.estadoCalculoDocumento || {};
            window.estadoCalculoDocumento.control = estado || 'pendiente';
            if ((estado === 'pendiente' || estado === 'error') && window.subtotalesDocumento) {
                window.subtotalesDocumento.control = 0;
            }
            if (typeof actualizarResumenDocumento === 'function') actualizarResumenDocumento();
            if (typeof actualizarGuiaInterfazCotizador === 'function') actualizarGuiaInterfazCotizador();
            if (mensaje) actualizarEstadoCalculoAuxiliar(estado === 'error' ? 'error' : 'calculando', mensaje);
        }

        function invalidarCalculoAuxiliar(mensaje = 'Seleccione una configuración válida para la CPU elegida.') {
            clearTimeout(temporizadorCalculo);
            recalculoPendiente = false;
            calculoEnCurso = false;
            marcarEstadoControlDocumento('pendiente');

            const espera = document.getElementById('calculo_espera');
            const marco = document.getElementById('resultado_calculo');
            const estado = document.getElementById('estado_calculo');

            if (marco) {
                marco.style.display = 'none';
                marco.removeAttribute('src');
                try {
                    marco.contentWindow.document.open();
                    marco.contentWindow.document.write('');
                    marco.contentWindow.document.close();
                } catch (error) {
                    /* El iframe puede no estar todavía inicializado. */
                }
            }
            if (espera) espera.style.display = 'block';
            actualizarEstadoCalculoAuxiliar('pendiente', mensaje);
        }

        async function manejarCambioCpu() {
            invalidarCalculoAuxiliar();

            const cpu = document.getElementById('id_cpu');
            const tipo = document.getElementById('id_tipo_control');
            const subtipo = document.getElementById('id_subtipo');
            if (!cpu || !tipo || !subtipo) return;

            const opCpu = cpu.selectedIndex >= 0 ? cpu.options[cpu.selectedIndex] : null;
            const opTipo = tipo.selectedIndex >= 0 ? tipo.options[tipo.selectedIndex] : null;
            const esMrl = opTipo ? String(opTipo.dataset.esMrl || '') === 'SI' : String(tipo.value) === '7';
            const cpuAdmiteMrl = opCpu ? String(opCpu.dataset.admiteMrl || '') === 'SI' : true;

            if (esMrl && !cpuAdmiteMrl) {
                tipo.value = '';
                subtipo.innerHTML = '<option value="">Seleccione CPU y tipo de control...</option>';
                actualizarCentral();
                actualizarOpcionesHidraulicas();
                actualizarVelocidad();
                actualizarPosicionamientoEncoder();
                actualizarAdicionalesVisibles();
                actualizarTipoAgrupacion();
                invalidarCalculoAuxiliar('La CPU seleccionada no está habilitada para MRL en Mantenimiento > Matrices de Control.');
                return;
            }

            await cargarSubtipos(tipo.value, subtipo.value, true);
            actualizarVelocidad();
            actualizarEncoderObligatorioPorVelocidad();
            actualizarPosicionamientoEncoder();
            actualizarAdicionalesVisibles();
            actualizarTipoAgrupacion();
        }

        function prepararCalculoAuxiliar() {
            const espera = document.getElementById('calculo_espera');
            const marco = document.getElementById('resultado_calculo');
            const estado = document.getElementById('estado_calculo');
            if (espera) espera.style.display = 'none';
            if (marco) marco.style.display = 'block';
            actualizarEstadoCalculoAuxiliar('calculando', 'Actualizando cálculo...');
            calculoEnCurso = true;
            const secuencia = ++secuenciaCalculoAuxiliar;
            clearTimeout(watchdogCalculoAuxiliar);
            // v116: NO reintentar automáticamente mientras una solicitud sigue en curso.
            // El reintento anterior podía abortar una consulta lenta y generar un bucle
            // permanente de "Actualizando cálculo...". Solo informamos si demora; el
            // próximo cambio del usuario dispara un cálculo nuevo normalmente.
            watchdogCalculoAuxiliar = setTimeout(function () {
                if (!calculoEnCurso || secuencia !== secuenciaCalculoAuxiliar) return;
                actualizarEstadoCalculoAuxiliar('calculando', 'Calculando...');
            }, 8000);
        }

        function ajustarAltoCalculo() {
            const estado = document.getElementById('estado_calculo');
            clearTimeout(watchdogCalculoAuxiliar);
            calculoEnCurso = false;
            actualizarEstadoCalculoAuxiliar('ok', 'Actualizado automáticamente');
            if (recalculoPendiente) {
                recalculoPendiente = false;
                programarCalculoTiempoReal(150);
            }
        }

        function formularioListoParaCalcular(formulario) {
            if (!formulario) return false;
            // v111: validar solamente lo que realmente necesita la configuracion ACTUAL.
            // En particular, velocidad_vf se oculta y se vacia para hidraulicos y otros
            // controles no VF; exigirla aqui bloqueaba el calculo auxiliar aunque el calculo
            // final de calcular.php fuera perfectamente valido.
            const idsBase = ['id_tension','id_cpu','cantidad_equipos','id_maniobra','potencia_hp','id_tipo_control','id_subtipo','id_material_hueco'];
            for (const id of idsBase) {
                const campo = document.getElementById(id);
                if (!campo || campo.disabled) continue;
                const valor = String(campo.value ?? '').trim();
                if (valor === '') return false;
                if (campo.type === 'number' && Number(valor) <= 0) return false;
            }

            const tipoControl = String(document.getElementById('id_tipo_control')?.value || '');
            // VF / iman permanente / Roomless / MRL son los tipos que requieren velocidad.
            if (['4','5','6','7'].includes(tipoControl)) {
                const velocidad = document.getElementById('velocidad_vf');
                if (!velocidad || String(velocidad.value || '').trim() === '') return false;
            }
            // El hidraulico requiere central, pero no velocidad VF.
            if (tipoControl === '3') {
                const central = document.getElementById('id_central');
                if (central && !central.disabled && String(central.value || '').trim() === '') return false;
                const opcionCentral = central && central.selectedIndex >= 0 ? central.options[central.selectedIndex] : null;
                if (opcionCentral && opcionCentral.dataset.esOtra === '1') {
                    const otra = document.getElementById('central_otra_nombre');
                    if (!otra || String(otra.value || '').trim() === '') return false;
                }
            }

            const paradas = Array.from(formulario.querySelectorAll('input[name="paradas_equipo[]"]'));
            if (paradas.length === 0) return false;
            return paradas.every(function (campo) {
                const n = Number(campo.value || 0);
                return Number.isFinite(n) && n >= 1;
            });
        }

        // v323: cada modulo usa su propio canal de calculo. Control y Senalizacion
        // pueden recalcular en paralelo sin abortarse mutuamente.
        const calculoAuxiliarRemoto = {
            estados: {
                control: { controlador: null, secuencia: 0 },
                senalizacion: { controlador: null, secuencia: 0 }
            }
        };

        async function solicitarCalculoAuxiliarRemoto(modulo, url, datosEnvio) {
            // HF1: un cálculo auxiliar jamás debe viajar como emisión/revisión.
            // Si una validación comercial previa dejó clones ocultos en el formulario,
            // eliminamos explícitamente la acción y el token antes del fetch para que
            // calcular.php no consuma el token de guardado por error.
            if (datosEnvio && typeof datosEnvio.delete === 'function') {
                datosEnvio.delete('accion_comercial');
                datosEnvio.delete('document_save_token');
                datosEnvio.set('modo_panel', '1');
            }
            const marco = document.getElementById('resultado_calculo');
            const estado = document.getElementById('estado_calculo');
            const estadoModulo = calculoAuxiliarRemoto.estados[modulo] || (calculoAuxiliarRemoto.estados[modulo] = { controlador: null, secuencia: 0 });
            if (estadoModulo.controlador) {
                try { estadoModulo.controlador.abort(); } catch (e) {}
            }
            const controlador = new AbortController();
            estadoModulo.controlador = controlador;
            const numeroSolicitud = ++estadoModulo.secuencia;
            if (marco) {
                marco.dataset.calculoSolicitado = modulo;
                marco.dataset.calculoSecuencia = String(numeroSolicitud);
            }
            if (modulo === 'control') marcarEstadoControlDocumento('pendiente', 'Recalculando Control...');
            prepararCalculoAuxiliar();
            try {
                const respuesta = await fetch(url, {method:'POST',body:datosEnvio,signal:controlador.signal,credentials:'same-origin',cache:'no-store'});
                const html = await respuesta.text();
                if (numeroSolicitud !== estadoModulo.secuencia) return false;
                window.detallesCalculoModulo = window.detallesCalculoModulo || {};
                window.detallesCalculoModulo[modulo] = html;
                clearTimeout(watchdogCalculoAuxiliar);
                calculoEnCurso = false;

                // v313: tomar el subtotal directamente de la respuesta HTTP. La interfaz
                // compacta ya no puede depender del onload/contenido del iframe oculto para
                // actualizar el resumen. El iframe queda solo como inspector de detalle.
                let totalRespuesta = null;
                let docRespuesta = null;
                let textoRespuesta = html;
                try {
                    docRespuesta = new DOMParser().parseFromString(html, 'text/html');
                    textoRespuesta = (docRespuesta && docRespuesta.body) ? docRespuesta.body.innerText : html;
                    if (modulo === 'senalizacion' && docRespuesta && docRespuesta.body) {
                        const dato = String(docRespuesta.body.dataset.totalSenalizacion || '').trim();
                        if (dato !== '') {
                            const n = Number(dato);
                            if (Number.isFinite(n) && n >= 0) totalRespuesta = n;
                        }
                    }
                    if (totalRespuesta === null) totalRespuesta = importeDesdeTexto(textoRespuesta);
                } catch (e) {
                    totalRespuesta = importeDesdeTexto(String(html || '').replace(/<[^>]*>/g, ' '));
                }

                if (respuesta.ok && totalRespuesta !== null && Number.isFinite(totalRespuesta) && totalRespuesta >= 0) {
                    if (modulo === 'control' || modulo === 'senalizacion') {
                        window.subtotalesDocumento[modulo] = totalRespuesta;
                    }
                    if (modulo === 'control' || modulo === 'senalizacion') {
                        window.estadoCalculoDocumento = window.estadoCalculoDocumento || {};
                        window.estadoCalculoDocumento[modulo] = 'valido';
                    }
                    // v465: cuando Control termina de calcular, recalcular inmediatamente
                    // la cantidad fisica de Limites con la configuracion tecnica ya estabilizada.
                    if (modulo === 'control' && typeof programarCantidadLimites === 'function') {
                        programarCantidadLimites(40);
                    }
                    // v467: reutilizar la corriente que ya devolvio calcular.php. Esto evita
                    // depender de una segunda consulta mientras los combos tecnicos aun se estan estabilizando.
                    if (modulo === 'control') {
                        // v469: calcular.php expone la corriente como dato estructurado.
                        // Es más robusto que intentar leerla del texto visible del detalle.
                        let corrienteControl = 0;
                        try {
                            const datoCorriente = String(docRespuesta?.body?.dataset?.corrienteVariador || '').trim().replace(',', '.');
                            corrienteControl = Number(datoCorriente) || 0;
                        } catch (e) {}
                        if (corrienteControl <= 0) {
                            const mCorriente = String(textoRespuesta || '').match(/Corriente\s+del\s+variador\s*:\s*([0-9]+(?:[.,][0-9]+)?)/i);
                            if (mCorriente) corrienteControl = Number(String(mCorriente[1]).replace(',', '.')) || 0;
                        }
                        if (corrienteControl > 0) {
                            corrienteVariadorV33 = corrienteControl;
                            const campoCorriente = document.getElementById('cable_mallado_corriente');
                            if (campoCorriente) campoCorriente.value = corrienteControl + ' A';
                            if (typeof actualizarEspecialesAccesorios === 'function') actualizarEspecialesAccesorios();
                        } else if (typeof actualizarCorrienteVariadorV33 === 'function') {
                            setTimeout(actualizarCorrienteVariadorV33, 80);
                        }
                    }
                    actualizarResumenDocumento();
                    if (typeof window.v313SincronizarResumenCompacto === 'function') window.v313SincronizarResumenCompacto();
                }

                if (marco) {
                    marco.style.display = 'block';
                    if ('srcdoc' in marco) marco.srcdoc = html;
                    else { try { const doc=marco.contentWindow.document; doc.open(); doc.write(html); doc.close(); } catch(e){} }
                }
                if (respuesta.ok && totalRespuesta !== null) {
                    actualizarEstadoCalculoAuxiliar('ok', 'Actualizado automáticamente');
                } else if (respuesta.ok) {
                    if (modulo === 'control') marcarEstadoControlDocumento('error');
                    if (modulo === 'senalizacion') {
                        window.subtotalesDocumento.senalizacion = 0;
                        window.estadoCalculoDocumento.senalizacion = 'error';
                        actualizarResumenDocumento();
                    }
                    actualizarEstadoCalculoAuxiliar('error', 'El cálculo respondió, pero no se pudo leer el total');
                } else {
                    if (modulo === 'control') marcarEstadoControlDocumento('error');
                    if (modulo === 'senalizacion') {
                        window.subtotalesDocumento.senalizacion = 0;
                        window.estadoCalculoDocumento.senalizacion = 'error';
                        actualizarResumenDocumento();
                    }
                    actualizarEstadoCalculoAuxiliar('error', 'SIN PRECIO / configuración no calculada');
                }
                return respuesta.ok && totalRespuesta !== null;
            } catch (error) {
                if (error && error.name === 'AbortError') return false;
                if (numeroSolicitud !== estadoModulo.secuencia) return false;
                clearTimeout(watchdogCalculoAuxiliar);
                calculoEnCurso = false;
                if (modulo === 'control') marcarEstadoControlDocumento('error');
                if (modulo === 'senalizacion') {
                    window.subtotalesDocumento.senalizacion = 0;
                    window.estadoCalculoDocumento.senalizacion = 'error';
                    actualizarResumenDocumento();
                }
                actualizarEstadoCalculoAuxiliar('error', 'No se pudo actualizar el cálculo auxiliar');
                return false;
            }
        }

        function asegurarDatoFormulario(formData, id, nombre = null) {
            const campo = document.getElementById(id);
            if (!campo || !campo.name) return;
            const clave = nombre || campo.name;
            if (campo.type === 'checkbox') {
                formData.set(clave, campo.checked ? (campo.value || '1') : '0');
            } else if (campo.type === 'radio') {
                const seleccionado = document.querySelector('input[name="' + CSS.escape(campo.name) + '"]:checked');
                if (seleccionado) formData.set(clave, seleccionado.value);
            } else {
                formData.set(clave, campo.value ?? '');
            }
        }

        async function ejecutarCalculoTiempoReal(forzar = false) {
            const botonActivo = document.querySelector('.cotizador-modulo-btn.activo');
            if (!forzar && !document.body.classList.contains('ui-v310') && botonActivo && botonActivo.dataset.modulo !== 'control') return;
            const formulario = document.getElementById('form_cotizador');
            const estado = document.getElementById('estado_calculo');
            if (!formulario) return;
            if (modoPlantillaActivo) {
                invalidarCalculoAuxiliar('La plantilla guarda la configuración y los ítems. El cálculo se realizará al aplicarla en una cotización.');
                return;
            }
            const incluirControl = document.getElementById('incluir_control');
            if (incluirControl && !incluirControl.checked) return;

            if (!formularioListoParaCalcular(formulario)) {
                invalidarCalculoAuxiliar('Complete los datos obligatorios de Control para actualizar el cálculo.');
                return;
            }

            // El cálculo auxiliar de Control viaja aislado. Señalización, Accesorios, IEP y
            // Repuestos tienen su propio subtotal y no deben contaminar ni bloquear este request.
            // FormData toma una fotografía del formulario DESPUÉS de que terminaron los
            // handlers de los combos. Los campos técnicos fundamentales se fuerzan desde el
            // DOM, incluso si momentáneamente están disabled por una regla de interfaz.
            const datosEnvio = new FormData(formulario);
            [
                'id_tension','id_cpu','cantidad_equipos','id_bateria','cantidad_total_coches_bateria',
                'numero_obra_otro','observacion_bateria','id_maniobra','potencia_hp',
                'id_tipo_control','id_subtipo','id_material_hueco','id_central',
                'central_otra_nombre','velocidad_vf','id_comunicacion_serie',
                'configuracion_especial_control','programa_tip','tipo_gabinete_mrl'
            ].forEach(function(id){ asegurarDatoFormulario(datosEnvio, id); });

            // Encoder puede quedar disabled cuando es obligatorio por velocidad; FormData
            // normal no lo enviaría. En el cálculo auxiliar debe viajar su estado real.
            const encoder = document.querySelector('input[name="encoder"]');
            if (encoder) datosEnvio.set('encoder', encoder.checked ? 'SI' : 'NO');

            await solicitarCalculoAuxiliarRemoto('control', 'calcular.php', datosEnvio);
        }

        function programarCalculoTiempoReal(demora = 160, forzar = false) {
            clearTimeout(temporizadorCalculo);
            temporizadorCalculo = setTimeout(function () { ejecutarCalculoTiempoReal(forzar); }, demora);
        }

        // v116: un solo recálculo estable por interacción. Los reintentos múltiples y el
        // MutationObserver de versiones anteriores podían mantener el panel recalculando
        // indefinidamente. Los handlers asíncronos (CPU/tipo/subtipo/etc.) ya disparan su
        // propio cálculo cuando terminan de actualizar dependencias.
        let temporizadorCalculoControlUniversal = null;
        function recalcularControlUniversal(demora = 90, forzar = false) {
            if (modoPlantillaActivo) return;
            const activo = document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo;
            if (!forzar && !document.body.classList.contains('ui-v310') && activo && activo !== 'control') return;
            ++calculoAuxiliarRemoto.estados.control.secuencia;
            if (calculoAuxiliarRemoto.estados.control.controlador) calculoAuxiliarRemoto.estados.control.controlador.abort();
            marcarEstadoControlDocumento('pendiente', 'Recalculando Control...');
            clearTimeout(temporizadorCalculoControlUniversal);
            temporizadorCalculoControlUniversal = setTimeout(function () {
                programarCalculoTiempoReal(20, forzar);
            }, Math.max(0, Number(demora) || 0));
        }

        // v136: la navegación de módulos es superior y siempre visible; ya no existe panel lateral colapsable.
        function setNavegacionCotizacionOculta() {
            const layout = document.getElementById('cotizador_layout');
            if (layout) layout.classList.remove('nav-cotizacion-oculta');
        }
        function alternarNavegacionCotizacion() { setNavegacionCotizacionOculta(false); }
        function contraerNavegacionAlCotizar() { setNavegacionCotizacionOculta(false); }

        function mostrarModuloCotizador(nombreModulo) {
            const botones = document.querySelectorAll('.cotizador-modulo-btn');
            const modulos = document.querySelectorAll('.modulo-cotizador');
            const panel = document.getElementById('panel_calculo_cotizador');
            const layout = document.querySelector('.cotizador-layout');

            // v196: al volver a trabajar sobre un módulo, el panel recupera su función
            // de cálculo auxiliar del módulo activo. El modo resumen total se usa solo
            // al finalizar/revisar la cotización completa.
            if (panel) panel.classList.remove('modo-resumen-total');
            const tituloResumen = document.querySelector('#panel_calculo_cotizador .resumen-documento h3');
            const ayudaResumen = document.querySelector('#panel_calculo_cotizador .panel-resumen-ayuda');
            if (tituloResumen) tituloResumen.textContent = 'DETALLE AVANZADO DEL CÁLCULO';
            if (ayudaResumen) ayudaResumen.textContent = 'El importe de cada módulo ya se muestra en la navegación superior.';

            botones.forEach(function (boton) {
                const activo = boton.dataset.modulo === nombreModulo;
                boton.classList.toggle('activo', activo);
                boton.setAttribute('aria-selected', activo ? 'true' : 'false');
            });
            modulos.forEach(function (modulo) {
                modulo.classList.toggle('activo', modulo.id === 'modulo_' + nombreModulo);
            });

            const tieneCalculoLateral = true;
            if (panel) panel.style.display = '';
            if (layout) layout.classList.remove('modulo-sin-calculo');

            const tituloPanel = document.getElementById('titulo_desglose_calculo');
            const esperaControl = document.getElementById('calculo_espera');
            const marcoCalculo = document.getElementById('resultado_calculo');
            if (nombreModulo === 'senalizacion') {
                if (tituloPanel) tituloPanel.textContent = 'Cálculo de Señalización';
                if (esperaControl) esperaControl.style.display = 'none';
                if (marcoCalculo) marcoCalculo.style.display = '';
                programarCalculoSenalizacion(150);
            } else if (nombreModulo === 'control') {
                if (tituloPanel) tituloPanel.textContent = 'Cálculo de Control';
                if (esperaControl) esperaControl.style.display = '';
                if (marcoCalculo) marcoCalculo.style.display = '';
                programarCalculoTiempoReal(150);
            } else if (['accesorios','iep','repuestos'].includes(nombreModulo)) {
                if (marcoCalculo) marcoCalculo.style.display = 'none';
                if (esperaControl) esperaControl.style.display = 'none';
            }
            actualizarVisibilidadDesgloseControl();
            actualizarResumenDocumento();
            try { sessionStorage.setItem('cotizador_modulo_activo', nombreModulo); } catch (e) {}
            actualizarEstadoFlujo();
        }

        // v171: cada módulo se presenta desde arriba y con sus contenedores cerrados.
        function cerrarContenedoresModulo(nombreModulo){
            const modulo=document.getElementById('modulo_'+nombreModulo);
            if(!modulo) return;
            modulo.querySelectorAll('.control-seccion').forEach(function(sec){
                sec.classList.add('cerrada');
                const cab=sec.querySelector('.control-seccion-titulo');
                if(cab) cab.setAttribute('aria-expanded','false');
            });
            modulo.querySelectorAll('.senal-seccion').forEach(function(sec){
                sec.classList.add('senal-acordeon-cerrado');
                const cab=sec.querySelector('.senal-seccion-titulo');
                if(cab){ cab.classList.add('is-collapsed'); cab.setAttribute('aria-expanded','false'); }
            });
            modulo.querySelectorAll('.senal-contenedor-macro').forEach(function(sec){
                sec.classList.add('senal-macro-cerrado');
                const cab=sec.querySelector('.senal-contenedor-macro-cabecera');
                if(cab){
                    cab.setAttribute('aria-expanded','false');
                    const icono=cab.querySelector('.senal-macro-toggle-icon');
                    if(icono) icono.textContent='⌄';
                }
            });
            modulo.querySelectorAll('.accesorios-tab-panel').forEach(function(panel){ panel.classList.remove('activo'); });
            modulo.querySelectorAll('.accesorios-tab-btn').forEach(function(btn){ btn.classList.remove('activo'); btn.setAttribute('aria-expanded','false'); });

            // v482: en escritorio Accesorios usa el mismo criterio visual continuo que Control y Señalización.
            // Se mantienen todos sus contenedores abiertos; no se altera ninguna lógica de cálculo.
            if(nombreModulo==='accesorios' && window.matchMedia && window.matchMedia('(min-width:1100px)').matches){
                modulo.querySelectorAll('.control-seccion').forEach(function(sec){
                    sec.classList.remove('cerrada');
                    const cab=sec.querySelector('.control-seccion-titulo');
                    if(cab) cab.setAttribute('aria-expanded','true');
                });
            }
        }
        function posicionarModuloArriba(nombreModulo, comportamiento='auto'){
            const modulo=document.getElementById('modulo_'+nombreModulo);
            if(!modulo) return;
            const top=Math.max(0, modulo.getBoundingClientRect().top + window.scrollY - 12);
            window.scrollTo({top:top,left:0,behavior:comportamiento});
        }
        function prepararVistaInicialModulo(nombreModulo, comportamiento='auto'){
            cerrarContenedoresModulo(nombreModulo);
            requestAnimationFrame(function(){ posicionarModuloArriba(nombreModulo, comportamiento); });
        }

        function seleccionarModuloFlujo(nombreModulo){
            if(nombreModulo === 'senalizacion' && typeof sincronizarComunicacionSerieControlSenalizacion==='function'){
                sincronizarComunicacionSerieControlSenalizacion();
                sincronizarDatosSenalizacion(true);
            }
            mostrarModuloCotizador(nombreModulo);
            contraerNavegacionAlCotizar();
            // v172: cambiar de módulo NO reinicia acordeones ni posición.
            // El reset visual completo queda reservado exclusivamente para + Nueva.
        }
        function alternarModuloDesdeNavegacion(nombreModulo){
            // V1.5: navegar y cotizar son acciones distintas.
            // La barra superior SOLO abre el módulo. Nunca incluye ni excluye
            // contenido comercial por el hecho de visitarlo.
            seleccionarModuloFlujo(nombreModulo);
            actualizarEstadoFlujo();
            return false;
        }
        function salirModoRepuestos(){
            // v217: Repuestos ya no es un modo exclusivo. Navegar a otro módulo conserva su inclusión.
            document.body.classList.remove('modo-repuestos-independiente');
        }
        function activarModoRepuestos(){
            // Compatibilidad con enlaces antiguos: sólo navega. La inclusión se activa al editar/agregar.
            mostrarModuloCotizador('repuestos');
            contraerNavegacionAlCotizar();
            prepararVistaInicialModulo('repuestos','auto');
        }
        function actualizarGuiaInterfazCotizador(){
            const activo=document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo || 'control';
            const datos={
                control:['Control','Configurá CPU, maniobra, equipos, paradas y adicionales. El importe se calcula automáticamente.'],
                senalizacion:['Señalización','Configurá botoneras, indicadores y adicionales. Si Control exige comunicación serie, se incorpora automáticamente.'],
                accesorios:['Accesorios','Seleccioná complementos y cantidades. Los precios detallados quedan ocultos en modo operativo y el total se mantiene en el panel.'],
                iep:['IEP','Cargá los ítems IEP que correspondan y verificá el subtotal antes de emitir.'],
                repuestos:['Repuestos','Buscá y seleccioná repuestos por código o descripción. Trabajá con cantidades y descuentos; el total queda siempre en el resumen.']
            };
            const titulo=document.getElementById('cotizador_contexto_titulo');
            const ayuda=document.getElementById('cotizador_contexto_ayuda');
            if(titulo) titulo.textContent='Estás cotizando: '+(datos[activo]?.[0]||activo);
            if(ayuda) ayuda.textContent=datos[activo]?.[1]||'';

            ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){
                const incluido=moduloIncluido(m);
                const importe=incluidoSeguro(m) ? Number(window.subtotalesDocumento?.[m]||0) : 0;
                const estadoModulo=String(window.estadoCalculoDocumento?.[m]||'');
                document.querySelectorAll('.cotizador-modulo-btn[data-modulo="'+m+'"]').forEach(function(b){
                    const nodo=b.querySelector('[data-nav-estado]');
                    b.classList.toggle('incluido-nav', incluido);
                    b.classList.toggle('error-calculo', incluido && estadoModulo==='error');
                    if(!nodo) return;
                    b.classList.toggle('pendiente', incluido && (importe<=0 || estadoModulo==='pendiente'));
                    if(incluido && estadoModulo==='error') nodo.textContent='SIN PRECIO · NO CALCULADO';
                    else if(incluido && estadoModulo==='pendiente') nodo.textContent='RECALCULANDO...';
                    else if(incluido && importe>0) nodo.innerHTML='CALCULADO<br><span class="nav-importe">'+monedaDocumento(importe)+'</span>';
                    else if(incluido) nodo.textContent='INCLUIDO · PENDIENTE';
                    else nodo.textContent='NO INCLUIDO';
                });
            });
        }
        function incluidoSeguro(modulo){ return typeof moduloIncluido==='function' ? moduloIncluido(modulo) : false; }

        function actualizarEstadoFlujo(){
            ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){
                const incluido=moduloIncluido(m);
                document.querySelectorAll('.cotizador-modulo-btn[data-modulo="'+m+'"]').forEach(function(b){
                    b.classList.toggle('incluido-nav',incluido);
                    b.classList.toggle('completado',incluido && !b.classList.contains('activo'));
                    b.classList.toggle('omitido',!incluido && !b.classList.contains('activo') && m!=='control');
                });
            });
            actualizarGuiaInterfazCotizador();
        }
        function incluirModuloAlComenzarEdicion(nombreModulo) {
            const ids = {
                control: 'incluir_control',
                senalizacion: 'senal_incluir_cotizacion',
                accesorios: 'incluir_accesorios',
                iep: 'incluir_iep',
                repuestos: 'incluir_repuestos'
            };
            const checkbox = document.getElementById(ids[nombreModulo] || '');
            if (!checkbox || checkbox.checked || checkbox.dataset.autoInclusionUsada === '1') return;

            checkbox.checked = true;
            checkbox.dataset.autoInclusionUsada = '1';
            checkbox.dispatchEvent(new Event('change', {bubbles:true}));
            actualizarEstadoFlujo();
            actualizarResumenDocumento();
        }
        function prepararControlParaFinalizar(){
            const incluir=document.getElementById('incluir_control');
            if(incluir)incluir.checked=true;
            const form=document.getElementById('form_cotizador');
            if(form && !form.reportValidity()){ alert('Complete los datos obligatorios del Control antes de continuar.'); return false; }
            programarCalculoTiempoReal(50);
            if(typeof programarCantidadLimites==='function') programarCantidadLimites(90);
            actualizarResumenDocumento();
            return true;
        }
        function marcarModuloParaContinuar(modulo){
            const ids={senalizacion:'senal_incluir_cotizacion',accesorios:'incluir_accesorios',iep:'incluir_iep'};
            const control=document.getElementById(ids[modulo]||'');
            if(control && !control.checked){
                control.checked=true;
                control.dispatchEvent(new Event('change',{bubbles:true}));
            }
            actualizarEstadoFlujo();
            actualizarResumenDocumento();
        }
        function continuarDesdeControl(){
            if(!prepararControlParaFinalizar()) return false;
            marcarModuloParaContinuar('senalizacion');
            seleccionarModuloFlujo('senalizacion');
            return false;
        }
        function finalizarDesdeControl(){
            if(!prepararControlParaFinalizar()) return false;
            if(typeof sincronizarComunicacionSerieControlSenalizacion==='function' && sincronizarComunicacionSerieControlSenalizacion()){
                seleccionarModuloFlujo('senalizacion');
                document.getElementById('modulo_senalizacion')?.scrollIntoView({behavior:'smooth',block:'start'});
                alert('La comunicacion serie en cabina del Control requiere Senalizacion. Revise la botonera: la comunicacion serie ya esta marcada y la cantidad se igualo automaticamente a la cantidad de equipos.');
                return false;
            }
            abrirResumenParaConfirmar();
            return false;
        }
        function prepararModuloFlujo(modulo){
            if(modulo==='senalizacion'){
                const incluir=document.getElementById('senal_incluir_cotizacion')?.checked;
                if(incluir){
                    const form=document.getElementById('form_senalizacion');
                    if(!prepararSenalizacion() || !form || !form.reportValidity()) return false;
                    establecerInclusionSenalizacion(true); programarCalculoSenalizacion(80);
                } else quitarSenalizacionDeCotizacion();
                return true;
            }
            if(modulo==='accesorios'){
                const incluir=document.getElementById('incluir_accesorios')?.checked;
                if(incluir && incluirModuloLibre('accesorios')===false && !moduloIncluido('accesorios')) return false;
                if(!incluir) quitarModuloLibre('accesorios');
                return true;
            }
            if(modulo==='iep'){
                const incluir=document.getElementById('incluir_iep')?.checked;
                if(incluir && incluirModuloLibre('iep')===false && !moduloIncluido('iep')) return false;
                if(!incluir) quitarModuloLibre('iep');
                return true;
            }
            return true;
        }
        function continuarFlujoModulo(modulo){
            if(!prepararModuloFlujo(modulo)) return false;
            const siguiente=modulo==='senalizacion'?'accesorios':'iep';
            marcarModuloParaContinuar(siguiente);
            seleccionarModuloFlujo(siguiente);
            actualizarEstadoFlujo(); return false;
        }
        function navegarModuloSinIncluir(modulo){
            seleccionarModuloFlujo(modulo);
            actualizarEstadoFlujo();
            return false;
        }
        function abrirResumenParaConfirmar(){
            // v196: "Terminar cotización y revisar resumen" debe mostrar el documento
            // completo, no el desglose del último módulo activo.
            actualizarResumenDocumento(); actualizarEstadoFlujo();
            const panel=document.getElementById('panel_calculo_cotizador');
            if(panel){
                panel.classList.add('modo-resumen-total');
                const titulo=panel.querySelector('.resumen-documento h3');
                const ayuda=panel.querySelector('.panel-resumen-ayuda');
                if(titulo) titulo.textContent='RESUMEN TOTAL DE LA COTIZACIÓN';
                if(ayuda) ayuda.textContent='Totales de todos los módulos incluidos en este documento.';
            }
            if(typeof window.setPanelPresupuestoOculto==='function') window.setPanelPresupuestoOculto(false, true);
            if(panel){panel.classList.remove('flujo-final-resaltado');void panel.offsetWidth;panel.classList.add('flujo-final-resaltado');panel.scrollTop=0;panel.scrollIntoView({behavior:'smooth',block:'center'});}
        }
        function finalizarModuloActual(modulo){
            if(!prepararModuloFlujo(modulo)) return false;
            abrirResumenParaConfirmar();
            return false;
        }
        function finalizarFlujoCotizacion(){
            return finalizarModuloActual('iep');
        }
        function validarFinalizacionRepuestos(){
            const filas=[...document.querySelectorAll('#items_repuestos .item-modular')];
            const hay=filas.some(function(f){if(f.classList.contains('repuesto-manual'))return repuestoManualCompleto(f);return !!((f.querySelector('[data-campo="codigo"]')?.value||'').trim()||(f.querySelector('[data-campo="descripcion"]')?.value||'').trim()||Number(f.querySelector('[data-campo="precio"]')?.value||0)>0);});
            if(!hay){alert('Agregue al menos un Repuesto antes de finalizar.');return false;}
            activarModoRepuestos();
            actualizarResumenDocumento();
            return true;
        }
        function finalizarRepuestos(){
            if(!validarFinalizacionRepuestos()) return false;
            abrirResumenParaConfirmar();
            return false;
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (!modoPlantillaActivo) {
                let moduloInicial = 'control';
                const editandoDocumento = <?= ($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) ? 'true' : 'false' ?>;
                if(editandoDocumento){
                    // Al reabrir un documento no heredamos la última pestaña de otra cotización.
                    // Abrimos el primer módulo realmente incluido para que el cálculo auxiliar sea útil desde el inicio.
                    if(document.getElementById('incluir_control')?.checked) moduloInicial='control';
                    else if(document.getElementById('senal_incluir_cotizacion')?.checked) moduloInicial='senalizacion';
                    else if(document.getElementById('incluir_accesorios')?.checked) moduloInicial='accesorios';
                    else if(document.getElementById('incluir_iep')?.checked) moduloInicial='iep';
                    else if(document.getElementById('incluir_repuestos')?.checked) moduloInicial='repuestos';
                } else {
                    try { moduloInicial = sessionStorage.getItem('cotizador_modulo_activo') || 'control'; } catch (e) {}
                    if(moduloInicial==='repuestos' && !document.getElementById('incluir_repuestos')?.checked) moduloInicial='control';
                }
                mostrarModuloCotizador(moduloInicial);
            }

            const tipoGuardado = <?= json_encode((string)($datos['id_tipo_control'] ?? '')) ?>;
            const subtipoGuardado = <?= json_encode((string)($datos['id_subtipo'] ?? '')) ?>;

            if (tipoGuardado) {
                cargarSubtipos(tipoGuardado, subtipoGuardado).then(function(){
                    // v44: al reabrir una cotizacion/pedido con Control, esperar a que el subtipo
                    // guardado quede restaurado antes de disparar el primer calculo auxiliar.
                    // Antes el calculo inicial podia ejecutarse mientras el select aun decia
                    // "Cargando subtipos..." y quedaba vacio hasta que el usuario tocaba un campo.
                    if (<?= ($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) ? 'true' : 'false' ?>
                        && document.getElementById('incluir_control')?.checked) {
                        setTimeout(function(){
                            mostrarModuloCotizador('control');
                            programarCalculoTiempoReal(40);
                        }, 80);
                    }
                });
            }

            actualizarCentral();
            actualizarOpcionesHidraulicas();
            actualizarVelocidad();
            actualizarPuertas();
            actualizarConfiguracionEspecialControl();
            generarEquipos();
            actualizarTipoAgrupacion();
            actualizarPosicionamientoEncoder();
            actualizarAdicionalesVisibles();

            const formulario = document.getElementById('form_cotizador');
            if (formulario && !modoPlantillaActivo) {
                // v116: recálculo por interacción real del usuario.
                // - select/checkbox/radio: al cambiar
                // - number/text/textarea: mientras escribe (debounce) y al perder foco
                // Sin MutationObserver: los cambios internos de opciones no deben crear loops.
                const moduloControl = document.getElementById('modulo_control');
                const esCampoCalculable = function(objetivo) {
                    return !!(objetivo && objetivo.matches &&
                        objetivo.matches('select,input,textarea') &&
                        !objetivo.matches('[type="submit"],[type="button"],[type="hidden"],[data-excluir-calculo-auxiliar="1"]'));
                };
                if (moduloControl) {
                    moduloControl.addEventListener('change', function(evento){
                        if (esCampoCalculable(evento.target)) recalcularControlUniversal(20);
                    }, true);
                    moduloControl.addEventListener('input', function(evento){
                        if (!esCampoCalculable(evento.target)) return;
                        if (evento.target.matches('input[type="text"],input[type="number"],input[type="search"],textarea')) {
                            recalcularControlUniversal(180);
                        }
                    }, true);
                    moduloControl.addEventListener('focusout', function(evento){
                        if (esCampoCalculable(evento.target)) recalcularControlUniversal(20);
                    }, true);
                }
                recalcularControlUniversal(80);
                // v44: respaldo para edicion de documentos. Si la configuracion ya estaba
                // completa pero una carga asincrona demoro el primer intento, reintentar una vez.
                if (<?= ($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) ? 'true' : 'false' ?>
                    && document.getElementById('incluir_control')?.checked) {
                    setTimeout(function(){
                        if (document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo === 'control') {
                            programarCalculoTiempoReal(20);
                        }
                    }, 700);
                }
            } else if (modoPlantillaActivo) {
                invalidarCalculoAuxiliar('La plantilla guarda la configuración y los ítems. El cálculo se realizará al aplicarla en una cotización.');
            }
        });
    </script>

<script>
let temporizadorCalculoSenalizacion = null;
let solicitudSenalizacion = null;
let secuenciaSenalizacion = 0;

function sincronizarComunicacionSerieControlSenalizacion(){
  const controlIncluido=document.getElementById('incluir_control');
  const comCtrl=document.getElementById('id_comunicacion_serie');
  const comSenal=document.getElementById('senal_comunicacion_serie_tipo');
  const cantComSenal=document.querySelector('[name="senal_cant_comunicacion_serie"]');
  const desdeCtrl=document.getElementById('senal_comunicacion_serie_desde_control');
  const incluirSenal=document.getElementById('senal_incluir_cotizacion');
  const cantidadEquipos=document.getElementById('cantidad_equipos');
  if(!comCtrl || !comSenal) return false;

  const texto=(comCtrl.selectedIndex>=0?String(comCtrl.options[comCtrl.selectedIndex].text||''):'')
    .toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  const controlActivo=!controlIncluido || controlIncluido.checked;
  const esTotal=texto.includes('TOTAL');
  const aplica=controlActivo && (texto.includes('CABINA') || esTotal) && !!comCtrl.value;

  if(aplica){
    const cantidad=Math.max(1,parseInt(cantidadEquipos?.value||'1',10)||1);
    comSenal.value=esTotal?'TOTAL':'EN CABINA';
    comSenal.style.pointerEvents='none';
    comSenal.style.backgroundColor='#eef3f8';
    comSenal.setAttribute('aria-disabled','true');
    comSenal.title='Automatico: requerido por la comunicacion serie seleccionada en Control';
    if(cantComSenal){
      cantComSenal.value=String(cantidad);
      cantComSenal.readOnly=true;
      cantComSenal.dataset.manual='';
      cantComSenal.style.backgroundColor='#eef3f8';
      cantComSenal.title='Automatico: igual a la cantidad de equipos cotizados';
    }
    if(desdeCtrl) desdeCtrl.value='1';
    if(incluirSenal){
      incluirSenal.checked=true;
      incluirSenal.disabled=true;
      incluirSenal.title='Obligatorio porque el Control lleva comunicacion serie en cabina';
      incluirSenal.closest('.modulo-inclusion')?.classList.add('senal-inclusion-obligatoria-control');
    }
    return true;
  }

  // Solo liberamos/limpiamos los campos si estaban siendo gobernados automaticamente desde Control.
  if(desdeCtrl && desdeCtrl.value==='1'){
    desdeCtrl.value='0';
    comSenal.style.pointerEvents='';
    comSenal.style.backgroundColor='';
    comSenal.removeAttribute('aria-disabled');
    comSenal.title='';

    // Si la cantidad habia sido impuesta automaticamente por Control, al quitar
    // la comunicacion serie debe desaparecer tambien. No dejamos un "1" residual
    // que pueda confundirse con una seleccion manual de Senalizacion.
    if(cantComSenal){
      cantComSenal.readOnly=false;
      cantComSenal.style.backgroundColor='';
      cantComSenal.title='';
      if(cantComSenal.dataset.manual!=='1'){
        cantComSenal.value='0';
        cantComSenal.dataset.manual='';
      }
    }
    if(incluirSenal){
      incluirSenal.disabled=false;
      incluirSenal.title='';
      incluirSenal.closest('.modulo-inclusion')?.classList.remove('senal-inclusion-obligatoria-control');
    }
    // Si el valor habia sido impuesto por Control, vuelve a No. Luego el usuario puede elegir manualmente.
    comSenal.value='';

    // La dependencia Control -> Senalizacion cambio: recalcular inmediatamente
    // el modulo y el resumen lateral con el estado ya limpio.
    if(typeof actualizarObligatoriedadBotoneraSenalizacion==='function') actualizarObligatoriedadBotoneraSenalizacion();
    if(typeof programarCalculoSenalizacion==='function') programarCalculoSenalizacion(20);
    if(typeof actualizarResumenDocumento==='function') actualizarResumenDocumento();
  }
  return false;
}

function sincronizarDatosSenalizacion(silencioso){
  const usar=document.getElementById('senal_usar_control');
  const tipo=document.getElementById('senal_tipo_modulo');
  const puerta=document.getElementById('senal_tipo_puerta');
  const lista=document.getElementById('lista_id');
  const cliente=document.getElementById('id_cliente');
  const referencia=document.getElementById('referencia_cotizacion');
  const listaOculta=document.getElementById('senal_lista_id');
  const clienteOculto=document.getElementById('senal_cliente_id');
  const referenciaOculta=document.getElementById('senal_referencia');

  if(listaOculta) listaOculta.value=lista ? lista.value : '';
  if(clienteOculto) clienteOculto.value=cliente ? cliente.value : '';
  if(referenciaOculta) referenciaOculta.value=referencia ? referencia.value : '';
  const controlIncluido=document.getElementById('incluir_control');
  const senalTieneControl=document.getElementById('senal_tiene_control');
  // v412: senal_tiene_control representa la tecnologia de destino de la señalizacion,
  // no si el documento incluye un Control nuevo. ELECTRONICO=1 / ELECTROMECANICO=0.
  const tipoModuloTxt=tipo&&tipo.selectedIndex>=0 ? String(tipo.options[tipo.selectedIndex].text||'').toUpperCase() : '';
  if(senalTieneControl) senalTieneControl.value=tipoModuloTxt.includes('ELECTRON')&&!tipoModuloTxt.includes('ELECTROMEC') ? '1' : '0';

  // Esta dependencia debe aplicarse aun antes de que Cliente/Base esten completos,
  // para que al elegir comunicacion serie en Control ya quede visible y obligatoria en Senalizacion.
  sincronizarComunicacionSerieControlSenalizacion();

  // v324: Señalización no depende del Cliente para calcular.
  // Solo requiere una base de precios; el Cliente se valida recién al emitir/guardar.
  if(!listaOculta || !listaOculta.value){
    if(!silencioso) alert('Seleccione una base de precios para calcular Señalización.');
    return false;
  }

  // v412: si el documento incluye Control, la señalizacion debe ser ELECTRONICA.
  // Si NO incluye Control, el usuario define el destino simplemente desde Módulos:
  // ELECTROMECANICO o ELECTRONICO (control ya instalado).
  if(controlIncluido&&controlIncluido.checked){
    if(usar){usar.disabled=false;usar.title='Usar los datos del Control incluido en esta cotizacion.';}
    forzarSenalElectronicaConControlV190();
  }else{
    // v413: al trabajar sin Control, liberar SIEMPRE las opciones que pudieron
    // quedar bloqueadas por haber tenido Control incluido anteriormente.
    liberarSenalElectronicaSinControlV190();
    if(usar){usar.checked=false;usar.disabled=true;usar.title='No hay Control incluido: seleccione ELECTROMECANICO o ELECTRONICO en Módulos.';}
  }
  aplicarReglaTipoModuloSenalizacion();
  if(usar && usar.checked){
    if(tipo) tipo.value='1';
    const pc=document.getElementById('id_ptacabina');
    const txt=pc&&pc.selectedIndex>=0 ? pc.options[pc.selectedIndex].text.toUpperCase() : '';
    if(puerta){
      if(txt.includes('MANUAL')) puerta.value='PM';
      else if(txt && !txt.includes('SELECCIONE')) puerta.value='PA';
    }
    const paradasControl=Array.from(document.querySelectorAll('[name="paradas_equipo[]"]')).map(i=>String(i.value||'').trim());
    const nomenclaturasControl=Array.from(document.querySelectorAll('[name="nomenclatura_equipo[]"]')).map(i=>String(i.value||'').trim());
    const paradasSenal=document.getElementById('senal_paradas');
    if(paradasControl.length && paradasSenal && paradasControl[0]!=='') paradasSenal.value=paradasControl[0]; // compatibilidad histórica
    const cantEquipos=document.getElementById('cantidad_equipos');
    const cantSenal=document.querySelector('[name="senal_cantidad"]');
    if(cantEquipos && cantSenal && Number(cantEquipos.value)>0) {
      cantSenal.value=cantEquipos.value;
      sincronizarCantidadesAdicionalesSenalizacion();
      generarParadasSenalizacion(paradasControl,nomenclaturasControl);
    }
  }

  actualizarResumenComunSenalizacion();
  actualizarControlAccesoSenalV169();
  sincronizarEspecialesV169DesdeControl();
  sincronizarAdicionalSintetizadorIndicadorV169();
  actualizarBornesSenalizacion();
  actualizarTensionSenalizacion();
  actualizarObligatoriedadBotoneraSenalizacion();
  return true;
}

function obtenerParadasSenalizacion(){
  const form=document.getElementById('form_senalizacion');
  if(!form) return [];
  return Array.from(form.querySelectorAll('input[name="senal_paradas_equipo[]"]'))
    .map(i=>Math.max(0,parseInt(i.value||'0',10)||0));
}
function obtenerNomenclaturasSenalizacion(){
  const form=document.getElementById('form_senalizacion');
  if(!form) return [];
  return Array.from(form.querySelectorAll('input[name="senal_nomenclatura_equipo[]"]'))
    .map(i=>String(i.value||'').trim());
}
function obtenerMedidasSenalizacion(){
  const form=document.getElementById('form_senalizacion');
  if(!form) return [];
  return Array.from(form.querySelectorAll('input[name="senal_medidas_equipo[]"]'))
    .map(i=>String(i.value||'').trim());
}
function sincronizarMedidasLegacySenalizacion(){
  const medidas=obtenerMedidasSenalizacion();
  const legacy=document.getElementById('senal_medidas');
  if(legacy) legacy.value=medidas.length ? String(medidas[0]||'') : '';
}
function actualizarEstadoNomenclaturaSenalizacion(input){
  if(!input) return;
  const estado=input.parentElement?.querySelector('.senal-nomenclatura-estado');
  if(!estado) return;
  const confirmado=String(input.value||'').trim()!=='';
  estado.textContent=confirmado?'Nomenclatura confirmada':'A CONFIRMAR';
  estado.classList.toggle('confirmada',confirmado);
  estado.classList.toggle('pendiente',!confirmado);
}
function generarParadasSenalizacion(valoresForzados,nomenclaturasForzadas){
  const form=document.getElementById('form_senalizacion');
  const cont=document.getElementById('senal_paradas_equipos');
  const cantidadInput=form?.querySelector('[name="senal_cantidad"]');
  if(!form||!cont||!cantidadInput) return;
  const cantidad=Math.max(1,parseInt(cantidadInput.value||'1',10)||1);
  const usar=document.getElementById('senal_usar_control');
  const controlIncluido=document.getElementById('incluir_control');
  const usaControl=!!(usar&&usar.checked);
  const actualesParadas=Array.from(cont.querySelectorAll('input[name="senal_paradas_equipo[]"]')).map(i=>i.value);
  const actualesNomenclaturas=Array.from(cont.querySelectorAll('input[name="senal_nomenclatura_equipo[]"]')).map(i=>i.value);
  const actualesMedidas=Array.from(cont.querySelectorAll('input[name="senal_medidas_equipo[]"]')).map(i=>i.value);
  const controlParadas=Array.from(document.querySelectorAll('#form_cotizador input[name="paradas_equipo[]"]')).map(i=>i.value);
  const controlNomenclaturas=Array.from(document.querySelectorAll('#form_cotizador input[name="nomenclatura_equipo[]"]')).map(i=>i.value);
  const guardadasParadas=window.senalParadasGuardadasV122||[];
  const guardadasNomenclaturas=window.senalNomenclaturasGuardadasV123||[];
  const guardadasMedidas=window.senalMedidasGuardadasV125||[];
  const legacyMedida=String(document.getElementById('senal_medidas')?.value||'');
  const forzadasParadas=Array.isArray(valoresForzados)?valoresForzados:[];
  const forzadasNomenclaturas=Array.isArray(nomenclaturasForzadas)?nomenclaturasForzadas:[];
  const legacy=String(document.getElementById('senal_paradas')?.value||'');
  let html='';
  for(let i=0;i<cantidad;i++){
    let v=usaControl?(forzadasParadas[i]??controlParadas[i]??''):(actualesParadas[i]??guardadasParadas[i]??legacy);
    let n=usaControl?(forzadasNomenclaturas[i]??controlNomenclaturas[i]??''):(actualesNomenclaturas[i]??guardadasNomenclaturas[i]??'');
    let m=actualesMedidas[i]??guardadasMedidas[i]??legacyMedida;
    if(v===undefined||v===null)v='';
    if(n===undefined||n===null)n='';
    if(m===undefined||m===null)m='';
    const estadoNomenclatura=String(n).trim()!==''?'Nomenclatura confirmada':'A CONFIRMAR';
    html+='<div class="senal-parada-coche">'+
      '<div class="senal-coche-cabecera"><strong>Coche '+(i+1)+'</strong><span>'+(usaControl?'Sincronizado con Control':'Configuración manual')+'</span></div>'+ 
      '<label class="senal-coche-campo senal-coche-paradas"><span>Paradas</span>'+ 
      '<input type="number" name="senal_paradas_equipo[]" min="1" max="64" step="1" required value="'+escaparHtml(v)+'" '+(usaControl?'readonly':'')+' oninput="sincronizarParadasLegacySenalizacion(); actualizarResumenRapidoSenalizacion(); programarCalculoSenalizacion(80)">'+ 
      '<small>'+(usaControl?'Desde Control':'Cantidad para esta botonera')+'</small></label>'+ 
      '<label class="senal-coche-campo senal-coche-nomenclatura"><span>Nomenclatura</span>'+ 
      '<input type="text" name="senal_nomenclatura_equipo[]" maxlength="500" value="'+escaparHtml(n)+'" '+(usaControl?'readonly':'')+' placeholder="Ej.: PB al 7 / -1, 0 al 5" oninput="actualizarEstadoNomenclaturaSenalizacion(this); actualizarResumenRapidoSenalizacion()">'+ 
      '<small class="senal-nomenclatura-estado '+(String(n).trim()!==''?'confirmada':'pendiente')+'">'+estadoNomenclatura+'</small></label>'+ 
      '<label class="senal-coche-campo senal-coche-medida"><span>Medida (Ancho × Alto)</span>'+ 
      '<input type="text" name="senal_medidas_equipo[]" maxlength="120" value="'+escaparHtml(m)+'" placeholder="Ej.: 180 x 1000 mm" oninput="sincronizarMedidasLegacySenalizacion(); actualizarResumenRapidoSenalizacion()">'+ 
      '<small>Medida propia de este coche</small></label>'+ 
      '</div>';
  }
  cont.innerHTML=html;
  sincronizarParadasLegacySenalizacion();
  sincronizarMedidasLegacySenalizacion();
  actualizarResumenRapidoSenalizacion();
}
function sincronizarParadasLegacySenalizacion(){
  const vals=obtenerParadasSenalizacion();
  const legacy=document.getElementById('senal_paradas');
  if(legacy&&vals.length) legacy.value=String(vals[0]);
}

const senalCantidadesInicialesNuevaV161 = <?= ($cotizacionEdicionId <= 0 && $pedidoEdicionId <= 0) ? 'true' : 'false' ?>;
function cantidadInicialSenalDesdeControlV161(){
  const control=document.getElementById('incluir_control');
  if(!control || !control.checked) return 0;
  return Math.max(0,parseInt(document.getElementById('cantidad_equipos')?.value||'0',10)||0);
}
function sincronizarCantidadesInicialesSenalizacionV161(){
  // Solo gobierna valores iniciales de documentos nuevos. Históricos conservan el snapshot guardado.
  if(!senalCantidadesInicialesNuevaV161) return;
  const cantidad=cantidadInicialSenalDesdeControlV161();
  document.querySelectorAll('#form_senalizacion .senal-cant-adic').forEach(c=>{
    if(c.dataset.manual==='1' || c.readOnly) return;
    c.value=String(cantidad);
  });
  const indicador=document.getElementById('senal_indicador_cantidad');
  const modeloIndicador=document.getElementById('senal_indicador_modelo');
  const botoneras=Math.max(1,parseInt(document.querySelector('[name="senal_cantidad"]')?.value||'1',10)||1);
  if(indicador && indicador.dataset.manual!=='1') indicador.value=(modeloIndicador && modeloIndicador.value) ? String(botoneras) : '0';
  if(typeof actualizarResumenRapidoSenalizacion==='function') actualizarResumenRapidoSenalizacion();
  if(typeof sincronizarEspecialesV169DesdeControl==='function') sincronizarEspecialesV169DesdeControl();
}
function sincronizarCantidadesAdicionalesSenalizacion(){
  if(senalCantidadesInicialesNuevaV161){
    sincronizarCantidadesInicialesSenalizacionV161();
    return;
  }
  // Compatibilidad histórica: conservar el comportamiento previo para documentos existentes.
  const base=Math.max(1,parseInt(document.querySelector('[name="senal_cantidad"]')?.value||'1',10)||1);
  document.querySelectorAll('.senal-cant-adic[data-sync-botoneras="1"]').forEach(c=>{
    if(c.dataset.manual==='1') return;
    const tipo=String(c.dataset.tipoCalculo||'').toUpperCase();
    const predeterminada=parseFloat(c.dataset.cantidadPredeterminada||'0')||0;
    c.value=(tipo==='POR_BOTONERA' || predeterminada>0) ? base : 0;
  });
  const modeloIndicador=document.getElementById('senal_indicador_modelo');
  const cantidadIndicador=document.getElementById('senal_indicador_cantidad');
  if(modeloIndicador && cantidadIndicador && modeloIndicador.value && cantidadIndicador.dataset.manual!=='1') cantidadIndicador.value=base;
}
function marcarCantidadSenalManual(el){if(el)el.dataset.manual='1';}
function marcarCantidadIndicadorManual(el){if(el)el.dataset.manual='1';}
function normalizarIndicadorSenalizacion(){
  const modelo=document.getElementById('senal_indicador_modelo');
  const cantidad=document.getElementById('senal_indicador_cantidad');
  const botoneras=Math.max(1,parseInt(document.querySelector('[name="senal_cantidad"]')?.value||'1',10)||1);
  if(!modelo||!cantidad) return;
  if(!modelo.value){ cantidad.value='0'; cantidad.dataset.manual=''; }
  else if(cantidad.dataset.manual!=='1'){
    cantidad.value=String(botoneras);
  }
  actualizarResumenRapidoSenalizacion();
  sincronizarAdicionalSintetizadorIndicadorV169();
  programarCalculoSenalizacion(80);
  actualizarEspecialesAccesorios();
}
function prepararSenalizacion(){
  const ok=sincronizarDatosSenalizacion(false);
  actualizarObligatoriedadBotoneraSenalizacion();
  return ok;
}

function senalizacionSoloComunicacionSerieDesdeControl(){
  const desdeCtrl=document.getElementById('senal_comunicacion_serie_desde_control');
  const tipo=document.getElementById('senal_comunicacion_serie_tipo');
  const modelo=document.getElementById('senal_modelo');
  if(!desdeCtrl || desdeCtrl.value!=='1' || !tipo || !['EN CABINA','TOTAL'].includes(tipo.value) || (modelo && modelo.value)) return false;

  // El modo sin botonera solo es valido cuando no hay otros componentes de
  // Señalizacion seleccionados. Si los hay, se exige completar la botonera.
  const form=document.getElementById('form_senalizacion');
  if(!form) return false;
  try{const items=JSON.parse(document.getElementById('senal_pulsadores_items_json')?.value||'[]');if(Array.isArray(items)&&items.length>0)return false;}catch(_){}
  const indModelo=String(form.querySelector('[name="senal_indicador_modelo"]')?.value||'').trim();
  const indCant=parseInt(form.querySelector('[name="senal_indicador_cantidad"]')?.value||'0',10)||0;
  if(indModelo && indCant>0) return false;
  if(String(form.querySelector('[name="senal_llave_ascensorista_tipo"]')?.value||'').trim()) return false;
  if(form.querySelector('[name="senal_logo_grabado"]:checked')) return false;
  if(form.querySelector('[name="senal_sint_a7601c"]:checked') || form.querySelector('[name="senal_sint_a4820sv"]:checked')) return false;
  if(String(form.querySelector('[name="senal_pesador_frente_codigo"]')?.value||'').trim()) return false;
  if(String(form.querySelector('[name="senal_control_acceso_tecnologia"]')?.value||'').trim()) return false;
  if(form.querySelector('[name^="senal_adicional_sel["]:checked')) return false;
  return true;
}

function actualizarObligatoriedadBotoneraSenalizacion(){
  const form=document.getElementById('form_senalizacion');
  if(!form) return false;
  const soloComunicacion=senalizacionSoloComunicacionSerieDesdeControl();
  const incluirCabina=document.getElementById('senal_incluir_botonera_cabina')?.checked!==false;
  const botoneraOpcional=soloComunicacion || !incluirCabina;
  const selectores=[
    '[name="senal_cantidad"]',
    '[name="senal_tipo_modulo"]',
    '[name="senal_tipo_puerta"]',
    '[name="senal_modelo"]',
    '[name="senal_color"]',
    '[name="senal_tecla"]',
    '[name="senal_tension"]'
  ];
  selectores.forEach(function(selector){
    const campo=form.querySelector(selector);
    if(!campo) return;
    if(campo.dataset.requiredBotoneraOriginal===undefined){
      campo.dataset.requiredBotoneraOriginal=campo.required?'1':'0';
    }
    campo.required = botoneraOpcional ? false : campo.dataset.requiredBotoneraOriginal==='1';
  });
  form.querySelectorAll('[name="senal_paradas_equipo[]"]').forEach(function(campo){
    if(campo.dataset.requiredBotoneraOriginal===undefined) campo.dataset.requiredBotoneraOriginal=campo.required?'1':'0';
    campo.required=botoneraOpcional?false:campo.dataset.requiredBotoneraOriginal==='1';
  });
  const base=document.getElementById('senal_paso_base');
  if(base) base.classList.toggle('senal-base-opcional-comunicacion',botoneraOpcional);
  return botoneraOpcional;
}

function formularioSenalizacionListo(){
  const form=document.getElementById('form_senalizacion');
  if(!form || !sincronizarDatosSenalizacion(true)) return false;
  actualizarObligatoriedadBotoneraSenalizacion();
  if(senalizacionSoloComunicacionSerieDesdeControl()) return true;
  const requeridos=form.querySelectorAll('[required]:not(:disabled)');
  for(const campo of requeridos){
    if(String(campo.value || '').trim()==='' || !campo.checkValidity()) return false;
  }
  return true;
}

function escribirResultadoSenalizacion(html){
  const marco=document.getElementById('resultado_calculo');
  if(!marco) return;
  marco.style.display='block';
  if('srcdoc' in marco) marco.srcdoc=html;
  else {
    try {
      const doc=marco.contentWindow.document;
      doc.open(); doc.write(html); doc.close();
    } catch(error) {}
  }
}

async function ejecutarCalculoSenalizacion(){
  // v322: en la mesa compacta Señalización está visible junto con Control.
  // No depender de la navegación antigua para permitir el cálculo automático.
  const form=document.getElementById('form_senalizacion');
  const estado=document.getElementById('estado_calculo');
  const espera=document.getElementById('calculo_espera');
  const marco=document.getElementById('resultado_calculo');
  if(espera) espera.style.display='none';
  if(!formularioSenalizacionListo()){
    ++calculoAuxiliarRemoto.estados.senalizacion.secuencia;
    window.subtotalesDocumento.senalizacion=0;
    window.estadoCalculoDocumento.senalizacion='pendiente';
    actualizarResumenDocumento();
    if(estado){estado.textContent='Complete los datos obligatorios de la configuración seleccionada';estado.classList.remove('actualizando');}
    if(marco){marco.style.display='none';marco.removeAttribute('srcdoc');}
    return;
  }
  const accion=document.getElementById('senal_accion'); if(accion) accion.value='calcular';
  const datosEnvio=new FormData(form);
  await solicitarCalculoAuxiliarRemoto('senalizacion','calcular_senalizacion.php',datosEnvio);
}
function resultadoSenalizacionCargado(){
  const marco=document.getElementById('resultado_calculo');
  const estado=document.getElementById('estado_calculo');
  if(!marco || !estado) return;
  try{
    const texto=(marco.contentDocument && marco.contentDocument.body)
      ? marco.contentDocument.body.innerText.trim() : '';
    // Restaurado al comportamiento estable de v64: el load inicial vacio del
    // iframe no se considera un error y nunca dispara reintentos automáticos.
    if(!texto){
      if(marco.dataset.calculoSolicitado==='control'){
        clearTimeout(watchdogCalculoAuxiliar);
        calculoEnCurso=false;
        if(recalculoPendiente){recalculoPendiente=false;programarCalculoTiempoReal(30);}
      }
      return;
    }
    actualizarEstadoCalculoAuxiliar(texto.includes('No se pudo calcular') ? 'error' : 'ok',
      texto.includes('No se pudo calcular') ? 'Revise la combinación seleccionada' : 'Actualizado automáticamente');
    ajustarAltoCalculo();
    capturarTotalIframe();
  }catch(error){
    actualizarEstadoCalculoAuxiliar('ok', 'Cálculo actualizado');
  }
}

function programarCalculoSenalizacion(demora=350){
  ++calculoAuxiliarRemoto.estados.senalizacion.secuencia;
  if(calculoAuxiliarRemoto.estados.senalizacion.controlador) calculoAuxiliarRemoto.estados.senalizacion.controlador.abort();
  window.subtotalesDocumento.senalizacion=0;
  window.estadoCalculoDocumento.senalizacion='pendiente';
  actualizarResumenDocumento();
  clearTimeout(temporizadorCalculoSenalizacion);
  temporizadorCalculoSenalizacion=setTimeout(ejecutarCalculoSenalizacion,demora);
}

function establecerInclusionSenalizacion(incluir){
  const campo=document.getElementById('senal_incluir_cotizacion');
  const estado=document.getElementById('senal_estado_inclusion');
  if(campo) campo.checked=!!incluir;
  if(estado){
    estado.textContent=incluir ? 'Señalización incluida en el documento.' : 'Señalización no incluida en el documento.';
    estado.style.background=incluir ? '#d1e7dd' : '#f8f9fa';
    estado.style.borderColor=incluir ? '#badbcc' : '#d7dce1';
  }
  actualizarResumenDocumento();
}

function incluirSenalizacionEnCotizacion(){
  const form=document.getElementById('form_senalizacion');
  if(!prepararSenalizacion() || !form || !form.reportValidity()) return false;
  establecerInclusionSenalizacion(true);
  programarCalculoSenalizacion(80);
  actualizarResumenDocumento();
  return false;
}

function quitarSenalizacionDeCotizacion(){
  establecerInclusionSenalizacion(false);
  window.subtotalesDocumento.senalizacion=0;
  actualizarResumenDocumento();
  return false;
}

document.addEventListener('DOMContentLoaded',function(){
  const form=document.getElementById('form_senalizacion');
  if(form){
    actualizarObligatoriedadBotoneraSenalizacion();
    form.addEventListener('change',function(){
      actualizarObligatoriedadBotoneraSenalizacion();
    });
  }
});

window.subtotalesDocumento={control:<?= json_encode((float)$subtotalesInicialesDocumento['CONTROL']) ?>,senalizacion:<?= json_encode((float)$subtotalesInicialesDocumento['SENALIZACION']) ?>,accesorios:<?= json_encode((float)$subtotalesInicialesDocumento['ACCESORIOS']) ?>,iep:<?= json_encode((float)$subtotalesInicialesDocumento['IEP']) ?>,repuestos:<?= json_encode((float)$subtotalesInicialesDocumento['REPUESTOS']) ?>};
window.estadoCalculoDocumento={control:Number(window.subtotalesDocumento.control||0)>0?'valido':'pendiente'};

function importeDesdeTexto(texto){
  const t=String(texto||'');
  const patrones=[
    /Total\s+señalización\s*:?\s*\$?\s*([0-9.]+(?:,[0-9]{1,2})?)/i,
    /Total\s+senalizacion\s*:?\s*\$?\s*([0-9.]+(?:,[0-9]{1,2})?)/i,
    /Total\s+final\s*:?\s*\$?\s*([0-9.]+(?:,[0-9]{1,2})?)/i,
    /TOTAL\s+FINAL\s*:?\s*\$?\s*([0-9.]+(?:,[0-9]{1,2})?)/,
    /Total\s*:?\s*\$?\s*([0-9.]+(?:,[0-9]{1,2})?)/i
  ];
  for(const patron of patrones){
    const m=t.match(patron);
    if(m&&m[1]) return Number(m[1].replace(/\./g,'').replace(',','.'))||0;
  }
  return null;
}
function monedaDocumento(v){return '$ '+Math.ceil(Number(v||0)).toLocaleString('es-AR',{minimumFractionDigits:0,maximumFractionDigits:0});}
function actualizarDesgloseAccesorios(){
  const caja=document.getElementById('desglose_accesorios_lista');
  const totalNodo=document.getElementById('desglose_accesorios_total');
  const estado=document.getElementById('estado_calculo_accesorios');
  if(!caja||!totalNodo)return;
  const filas=[]; let total=0;
  document.querySelectorAll('#items_accesorios .item-modular').forEach(function(f){
    if(f.dataset.seleccionado==='0' || f.style.display==='none') return;
    const concepto=(f.querySelector('[data-campo="concepto"]')?.value||'Accesorio').trim();
    const codigo=(f.querySelector('[data-campo="codigo"]')?.value||'').trim();
    const descripcion=(f.querySelector('[data-campo="descripcion"]')?.value||'').trim();
    const cantidad=Math.max(0,Number(f.querySelector('[data-campo="cantidad"]')?.value||0));
    const unitario=Math.ceil(Number(f.querySelector('[data-campo="precio"]')?.value||0));
    if(!(concepto||codigo||descripcion) || cantidad<=0) return;
    const subtotal=Math.ceil(cantidad*unitario); total+=subtotal;
    filas.push({concepto,codigo,descripcion,cantidad,unitario,subtotal});
  });
  if(!filas.length){
    caja.innerHTML='<div class="desglose-accesorios-vacio">Todavía no hay accesorios seleccionados.</div>';
    if(estado)estado.textContent='Seleccione accesorios para ver el detalle';
  }else{
    caja.innerHTML=filas.map(function(it){
      const detalle=[it.codigo, it.cantidad+' × '+monedaDocumento(it.unitario)].filter(Boolean).join(' · ');
      return '<div class="desglose-accesorio-fila"><div><strong>'+escaparRep(it.concepto)+'</strong><small>'+escaparRep(detalle)+'</small></div><div class="desglose-accesorio-importe">'+monedaDocumento(it.subtotal)+'</div></div>';
    }).join('');
    if(estado)estado.textContent=filas.length+' ítem'+(filas.length===1?'':'s')+' seleccionado'+(filas.length===1?'':'s');
  }
  totalNodo.textContent=monedaDocumento(total);
}

function repuestoManualCompleto(f){
  if(!f || !f.classList.contains('repuesto-manual')) return true;
  const concepto=(f.querySelector('[data-campo="concepto"]')?.value||'').trim();
  const descripcion=(f.querySelector('[data-campo="descripcion"]')?.value||'').trim();
  const cantidad=Math.max(0,Math.round(Number(f.querySelector('[data-campo="cantidad"]')?.value||0)));
  const precio=Math.max(0,Number(f.querySelector('[data-campo="precio"]')?.value||0));
  return concepto!=='' && descripcion!=='' && cantidad>0 && precio>0;
}
function actualizarEstadoRepuestoManual(f){
  if(!f || !f.classList.contains('repuesto-manual')) return;
  const tieneAlgo=[...f.querySelectorAll('[data-campo]')].some(i=>i.type!=='hidden' && String(i.value||'').trim()!=='' && !(i.dataset.campo==='cantidad' && String(i.value)==='1') && !(i.dataset.campo==='precio' && Number(i.value||0)===0));
  f.classList.toggle('manual-incompleto',tieneAlgo && !repuestoManualCompleto(f));
  f.classList.toggle('manual-completo',repuestoManualCompleto(f));
}

function actualizarDesgloseModuloLocal(modulo){
  const panel=document.getElementById('panel_desglose_modular');
  const titulo=document.getElementById('desglose_modular_titulo');
  const estado=document.getElementById('estado_calculo_modular');
  const caja=document.getElementById('desglose_modular_lista');
  const totalNodo=document.getElementById('desglose_modular_total');
  if(!panel||!titulo||!estado||!caja||!totalNodo)return;
  const nombre=modulo==='iep'?'IEP':'Repuestos'; titulo.textContent='Cálculo de '+nombre;
  const filas=[]; let total=0;
  document.querySelectorAll('#items_'+modulo+' .item-modular').forEach(function(f){
    if(f.dataset.seleccionado==='0'||f.style.display==='none')return;
    if(modulo==='repuestos' && f.classList.contains('repuesto-manual') && !repuestoManualCompleto(f)) return;
    const concepto=(f.querySelector('[data-campo="concepto"]')?.value||nombre).trim();
    const codigo=(f.querySelector('[data-campo="codigo"]')?.value||'').trim();
    const descripcion=(f.querySelector('[data-campo="descripcion"]')?.value||'').trim();
    const inputCantidad=f.querySelector('[data-campo="cantidad"]');
    const cantidad=modulo==='repuestos'?Math.max(1,Math.round(Number(inputCantidad?.value||1))):Math.max(0,Number(inputCantidad?.value||0));
    const unitario=Math.ceil(Math.max(0,Number(f.querySelector('[data-campo="precio"]')?.value||0)));
    if(!(concepto||codigo||descripcion)||cantidad<=0)return;
    const subtotal=Math.ceil(cantidad*unitario); total+=subtotal;
    filas.push({concepto,codigo,descripcion,cantidad,unitario,subtotal});
  });
  if(!filas.length){caja.innerHTML='<div class="desglose-accesorios-vacio">Todavía no hay ítems cargados.</div>';estado.textContent='Cargue ítems para ver el cálculo';}
  else {caja.innerHTML=filas.map(function(it){const detalle=[it.codigo,it.cantidad+' × '+monedaDocumento(it.unitario),it.descripcion].filter(Boolean).join(' · ');return '<div class="desglose-accesorio-fila"><div><strong>'+escaparRep(it.concepto)+'</strong><small>'+escaparRep(detalle)+'</small></div><div class="desglose-accesorio-importe">'+monedaDocumento(it.subtotal)+'</div></div>';}).join('');estado.textContent=filas.length+' ítem'+(filas.length===1?'':'s')+' calculado'+(filas.length===1?'':'s');}
  totalNodo.textContent=monedaDocumento(total);
}

function moduloLibreSubtotal(modulo){
  let total=0;
  document.querySelectorAll('#items_'+modulo+' .item-modular').forEach(f=>{
    if(f.dataset.seleccionado==='0') return;
    if(modulo==='repuestos' && f.classList.contains('repuesto-manual') && !repuestoManualCompleto(f)) return;
    const inputCantidad=f.querySelector('[data-campo="cantidad"]');
    const c=modulo==='repuestos'?normalizarCantidadRepuesto(inputCantidad,false):Number(inputCantidad?.value||0);
    const p=Number(f.querySelector('[data-campo="precio"]')?.value||0);
    total+=c*p;
  });
  return total;
}
function moduloIncluido(nombre){
  if(nombre==='control') return !!document.getElementById('incluir_control')?.checked;
  if(nombre==='senalizacion') return !!document.getElementById('senal_incluir_cotizacion')?.checked;
  return !!document.getElementById('incluir_'+nombre)?.checked;
}
function actualizarEstadoModuloLibre(modulo,incluir){
  const check=document.getElementById('incluir_'+modulo);
  if(check) check.checked=!!incluir;
  const estado=document.getElementById('estado_'+modulo);
  if(estado){estado.textContent=incluir?modulo.toUpperCase()+' incluido en el documento.':modulo.toUpperCase()+' no incluido en el documento.';estado.classList.toggle('incluido',!!incluir);}
  window.subtotalesDocumento[modulo]=incluir?moduloLibreSubtotal(modulo):0;
  actualizarResumenDocumento();
}
function incluirModuloLibre(modulo){
  const filas=[...document.querySelectorAll('#items_'+modulo+' .item-modular')];
  const alguno=filas.some(f=>{if(f.dataset.seleccionado==='0')return false;const c=f.querySelector('[data-campo="concepto"]')?.value.trim()||'';const co=f.querySelector('[data-campo="codigo"]')?.value.trim()||'';const d=f.querySelector('[data-campo="descripcion"]')?.value.trim()||'';const p=Number(f.querySelector('[data-campo="precio"]')?.value||0);return !!(c||co||d||p);});
  if(!alguno){alert('Complete al menos un ítem de '+modulo.toUpperCase()+'.');return false;}
  actualizarEstadoModuloLibre(modulo,true);return false;
}
function quitarModuloLibre(modulo){actualizarEstadoModuloLibre(modulo,false);return false;}
function capturarTotalIframe(){
  const marco=document.getElementById('resultado_calculo'); if(!marco) return;
  try{
    const cuerpo=marco.contentDocument?.body;
    const texto=cuerpo?.innerText||'';
    // El resultado se asigna al modulo que ORIGINO la solicitud, no al modulo
    // que casualmente este visible cuando termina la respuesta del iframe.
    const solicitado=String(marco.dataset.calculoSolicitado||'');
    const activo=document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo||'';
    const destino=(solicitado==='control'||solicitado==='senalizacion')?solicitado:activo;
    let total=null;
    if(destino==='senalizacion' && cuerpo){
      const totalDato=String(cuerpo.dataset.totalSenalizacion||'').trim();
      if(totalDato!==''){
        const n=Number(totalDato);
        if(Number.isFinite(n) && n>=0) total=n;
      }
    }
    if(total===null) total=importeDesdeTexto(texto);
    // Una carga vacia, intermedia o stale del iframe NO puede borrar un subtotal
    // valido ya calculado. Solo actualizamos cuando la respuesta contiene un total
    // reconocible del modulo que origino la solicitud.
    if(total!==null && Number.isFinite(total) && total>=0 && (destino==='control'||destino==='senalizacion')){
      window.subtotalesDocumento[destino]=total;
    }
  }catch(e){}
  actualizarResumenDocumento();
}
function actualizarVisibilidadDesgloseControl(){
  const activo=document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo||'control';
  const panelPrincipal=document.getElementById('panel_calculo_cotizador');
  const panelControl=document.getElementById('panel_desglose_control');
  const panelAcc=document.getElementById('panel_desglose_accesorios');
  const panelLocal=document.getElementById('panel_desglose_modular');

  // v148: el inspector lateral pertenece exclusivamente al modulo activo.
  // Usamos prioridad !important porque v139 contiene reglas de layout/scroll
  // que forzaban display:flex y hacian coexistir dos desgloses visualmente.
  if(panelPrincipal) panelPrincipal.dataset.moduloActivo=activo;
  if(panelControl) panelControl.style.setProperty('display',(activo==='control'||activo==='senalizacion')?'flex':'none','important');
  if(panelAcc) panelAcc.style.setProperty('display',activo==='accesorios'?'flex':'none','important');
  if(panelLocal) panelLocal.style.setProperty('display',(activo==='iep'||activo==='repuestos')?'flex':'none','important');

  if(activo==='accesorios') actualizarDesgloseAccesorios();
  if(activo==='iep'||activo==='repuestos') actualizarDesgloseModuloLocal(activo);
}
function actualizarResumenDocumento(){actualizarVisibilidadDesgloseControl();
  // V1.5: la inclusión comercial refleja contenido real, no la pestaña visitada.
  // Control/Señalización se incluyen en cuanto existe un subtotal calculado.
  ['control','senalizacion'].forEach(function(m){
    const idCheck=m==='control'?'incluir_control':'senal_incluir_cotizacion';
    const check=document.getElementById(idCheck);
    const subtotal=Number(window.subtotalesDocumento[m]||0);
    if(check && subtotal>0 && !check.checked) check.checked=true;
  });

  // Los módulos libres quedan incluidos sólo cuando contienen al menos un ítem
  // válido con precio. Un ítem expresamente bonificado también cuenta aunque sea $0.
  ['accesorios','iep','repuestos'].forEach(function(m){
    const check=document.getElementById('incluir_'+m);
    const selector=m==='accesorios'
      ? '#items_accesorios .item-modular, #items_especiales_accesorios_v33 .item-modular'
      : '#items_'+m+' .item-modular';
    const filas=[...document.querySelectorAll(selector)].filter(function(f){
      if(f.dataset.seleccionado==='0') return false;
      if(m==='repuestos' && f.classList.contains('repuesto-manual') && !repuestoManualCompleto(f)) return false;
      const concepto=(f.querySelector('[data-campo="concepto"]')?.value||'').trim();
      const codigo=(f.querySelector('[data-campo="codigo"]')?.value||'').trim();
      const descripcion=(f.querySelector('[data-campo="descripcion"]')?.value||'').trim();
      const cantidad=Number(f.querySelector('[data-campo="cantidad"]')?.value||0);
      const precio=Number(f.querySelector('[data-campo="precio"]')?.value||0);
      const bonificado=f.dataset.bonificado==='1';
      return !!(concepto||codigo||descripcion) && cantidad>0 && (precio>0 || bonificado);
    });
    const debeIncluir=filas.length>0;
    if(check && check.checked!==debeIncluir) check.checked=debeIncluir;
    window.subtotalesDocumento[m]=debeIncluir?moduloLibreSubtotal(m):0;
  });
  const nombres={control:'Control',senalizacion:'Señalización',iep:'IEP',accesorios:'Accesorios',repuestos:'Repuestos'};
  let total=0;
  Object.keys(nombres).forEach(m=>{
    const incluido=moduloIncluido(m);
    const importe=incluido?(window.subtotalesDocumento[m]||0):0;
    total+=importe;
    const fila=document.getElementById('resumen_'+m);
    if(fila){
      fila.classList.toggle('incluido',incluido);
      fila.classList.toggle('no-incluido',!incluido);
      fila.classList.toggle('calculado',incluido && importe>0);
      fila.classList.toggle('pendiente-calculo',incluido && !(importe>0));
      const valor=fila.querySelector('[data-total]');
      if(valor)valor.textContent=incluido?(importe>0?monedaDocumento(importe):'Pendiente de cálculo'):((m==='senalizacion'||m==='accesorios'||m==='iep')?'No lleva':'No incluido');
    }
  });
  const totalNodo=document.getElementById('resumen_total_general');if(totalNodo)totalNodo.textContent=monedaDocumento(total);
  actualizarDesgloseAccesorios();
  if(typeof actualizarListaAccesoriosCompacta==='function') actualizarListaAccesoriosCompacta();
  if(typeof actualizarEstadoFlujo==='function') actualizarEstadoFlujo();
  else if(typeof actualizarGuiaInterfazCotizador==='function') actualizarGuiaInterfazCotizador();
}

function actualizarResumenComunSenalizacion(){
  const cliente=document.getElementById('id_cliente');
  const lista=document.getElementById('lista_id');
  const referencia=document.getElementById('referencia_cotizacion');
  const clienteBusqueda=document.getElementById('cliente_busqueda');
  const textoCliente=clienteBusqueda&&clienteBusqueda.value ? clienteBusqueda.value : 'Sin seleccionar';
  const textoLista=lista&&lista.value ? 'Bejerman vigente' : 'No disponible';
  const caja=document.getElementById('senal_datos_comunes');
  if(caja) caja.innerHTML='<strong>Cliente:</strong> '+textoCliente+'<br><strong>Base de precios:</strong> '+textoLista+'<br><strong>Referencia:</strong> '+(referencia&&referencia.value?referencia.value:'-');
}

function copiarCamposSenalizacion(destino,clase){
  const form=document.getElementById('form_senalizacion');
  if(!form||!destino)return;
  Array.from(form.elements).forEach(function(e){
    if(!e.name || !e.name.startsWith('senal_') || e.name==='senal_incluir') return;
    const i=document.createElement('input');i.type='hidden';i.name=e.name;i.className=clase||'senal-clonado';
    if(e.type==='checkbox') i.value=e.checked?'1':'0'; else if(e.type==='radio'){if(!e.checked)return;i.value=e.value;} else i.value=e.value;
    destino.appendChild(i);
  });
}

function prepararModulosParaCalculoAuxiliar(destino){
  if(!destino) return;
  destino.querySelectorAll('.calculo-modular-clonado').forEach(x=>x.remove());
  const add=(n,v)=>{const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;i.className='calculo-modular-clonado';destino.appendChild(i);};
  const incluirControl=document.getElementById('incluir_control');
  add('incluir_control',(!incluirControl||incluirControl.checked)?'1':'0');

  const incluirSenal=document.getElementById('senal_incluir_cotizacion');
  const formSenal=document.getElementById('form_senalizacion');
  if(incluirSenal && incluirSenal.checked && formSenal && formularioSenalizacionListo()){
    add('senal_incluir','1');
    copiarCamposSenalizacion(destino,'calculo-modular-clonado');
  } else {
    add('senal_incluir','0');
  }

  ['ACCESORIOS','IEP','REPUESTOS'].forEach(modulo=>{
    const clave=modulo.toLowerCase();
    const incluir=document.getElementById('incluir_'+clave);
    if(!incluir || !incluir.checked) return;
    document.querySelectorAll(modulo==='ACCESORIOS' ? '#items_accesorios .item-modular, #items_especiales_accesorios_v33 .item-modular' : '#items_'+clave+' .item-modular').forEach(f=>{
      if(f.dataset.seleccionado==='0') return;
      if(modulo==='REPUESTOS' && f.classList.contains('repuesto-manual') && !repuestoManualCompleto(f)) return;
      const concepto=f.querySelector('[data-campo="concepto"]')?.value.trim()||'';
      const codigo=f.querySelector('[data-campo="codigo"]')?.value.trim()||'';
      const descripcion=f.querySelector('[data-campo="descripcion"]')?.value.trim()||'';
      const inputCantidad=f.querySelector('[data-campo="cantidad"]');
      const cantidad=cantidadModuloParaGuardar(modulo,inputCantidad);
      const precio=f.querySelector('[data-campo="precio"]')?.value||'';
      const precioBase=(modulo==='ACCESORIOS'?(f.dataset.precioBase||f.querySelector('.accesorio-precio-base')?.value||precio):precio);
      if(!(concepto||codigo||descripcion||Number(precio)>0)) return;
      add('modulo_item_modulo[]',modulo);
      add('modulo_item_concepto[]',concepto||modulo);
      add('modulo_item_codigo[]',codigo);
      add('modulo_item_descripcion[]',descripcion);
      add('modulo_item_cantidad[]',cantidad||'1');
      add('modulo_item_precio[]',precio||'0');
      add('modulo_item_precio_base[]',precioBase||precio||'0');
    });
  });
}

function limpiarClonesModulares(destino){destino.querySelectorAll('.modulo-clonado,.senal-clonado,.accion-integrada-clonada,.calculo-modular-clonado').forEach(x=>x.remove());}
function clonarOculto(destino,n,v){const i=document.createElement('input');i.type='hidden';i.name=n;i.value=v;i.className='modulo-clonado';destino.appendChild(i);}
function recopilarModuloLibre(destino,modulo){
  const incluir=document.getElementById('incluir_'+modulo.toLowerCase());
  if(!incluir || !incluir.checked) return true;
  const filas=document.querySelectorAll(modulo==='ACCESORIOS' ? '#items_accesorios .item-modular, #items_especiales_accesorios_v33 .item-modular' : '#items_'+modulo.toLowerCase()+' .item-modular'); let validos=0;
  filas.forEach(f=>{
    if(f.dataset.seleccionado==='0')return;
    if(modulo==='REPUESTOS'&&f.classList.contains('repuesto-manual')&&!repuestoManualCompleto(f))return;
    const concepto=f.querySelector('[data-campo="concepto"]')?.value.trim()||'';
    const codigo=f.querySelector('[data-campo="codigo"]')?.value.trim()||'';
    const descripcion=f.querySelector('[data-campo="descripcion"]')?.value.trim()||'';
    const inputCantidad=f.querySelector('[data-campo="cantidad"]');
    const cantidad=cantidadModuloParaGuardar(modulo,inputCantidad);
    const precio=f.querySelector('[data-campo="precio"]')?.value||'';
    const precioBase=(modulo==='ACCESORIOS'?(f.dataset.precioBase||f.querySelector('.accesorio-precio-base')?.value||precio):precio);
    const bonificado=(modulo==='ACCESORIOS'&&f.dataset.bonificado==='1')?'1':'0';
    if(concepto||codigo||descripcion||Number(precio)>0||bonificado==='1'){
      clonarOculto(destino,'modulo_item_modulo[]',modulo);
      clonarOculto(destino,'modulo_item_concepto[]',concepto||modulo);
      clonarOculto(destino,'modulo_item_codigo[]',codigo);
      clonarOculto(destino,'modulo_item_descripcion[]',descripcion);
      clonarOculto(destino,'modulo_item_cantidad[]',cantidad||'1');
      clonarOculto(destino,'modulo_item_precio[]',precio||'0');
      clonarOculto(destino,'modulo_item_precio_base[]',precioBase||precio||'0');
      clonarOculto(destino,'modulo_item_bonificado[]',bonificado);
      validos++;
    }
  });
  if(validos===0){alert('Agregue al menos un ítem en '+modulo+'.');mostrarModuloCotizador(modulo.toLowerCase());return false;} return true;
}
function ocultarAvisoClienteObligatorio(){
  const aviso=document.getElementById('aviso_cliente_obligatorio');
  const cliente=document.getElementById('id_cliente');
  const campo=cliente ? cliente.closest('.campo') : null;
  if(aviso) aviso.classList.remove('visible');
  if(campo) campo.classList.remove('campo-cliente-error');
  if(cliente) cliente.setCustomValidity('');
}

function validarClienteBotonEmision(ev,boton){
  const cliente=document.getElementById('id_cliente');
  const buscador=document.getElementById('cliente_busqueda');
  const idCliente=cliente ? String(cliente.value||'').trim() : '';
  const nombreCliente=buscador ? String(buscador.value||'').trim() : '';
  if(idCliente!=='' && nombreCliente!=='') return true;

  if(ev){ ev.preventDefault(); ev.stopPropagation(); }
  if(cliente) cliente.value='';
  const aviso=document.getElementById('aviso_cliente_obligatorio');
  if(aviso) aviso.classList.add('visible');
  const campo=buscador ? buscador.closest('.campo') : (cliente ? cliente.closest('.campo') : null);
  if(campo) campo.classList.add('campo-cliente-error');

  alert('Falta seleccionar el CLIENTE. Seleccione un cliente antes de emitir la cotización o generar el pedido.');
  if(buscador){
    buscador.focus({preventScroll:true});
    buscador.scrollIntoView({behavior:'smooth',block:'center'});
  }
  return false;
}
function validarClienteAntesDeEmitir(){
  const cliente=document.getElementById('id_cliente');
  const buscador=document.getElementById('cliente_busqueda');
  const idValido=!!(cliente && String(cliente.value||'').trim());
  const nombreVisible=!!(buscador && String(buscador.value||'').trim());
  if(idValido && nombreVisible) {
    if(cliente) cliente.setCustomValidity('');
    if(buscador) buscador.setCustomValidity('');
    ocultarAvisoClienteObligatorio();
    return true;
  }
  const aviso=document.getElementById('aviso_cliente_obligatorio');
  const campo=cliente ? cliente.closest('.campo') : null;
  if(aviso) aviso.classList.add('visible');
  if(campo) campo.classList.add('campo-cliente-error');
  alert('Falta seleccionar el nombre del cliente. Elija un cliente de la lista antes de emitir la cotización o generar el pedido.');
  if(cliente) cliente.setCustomValidity('Seleccione un cliente antes de emitir la cotización o generar el pedido.');
  if(buscador){
    buscador.focus({preventScroll:true});
    buscador.scrollIntoView({behavior:'smooth',block:'center'});
    buscador.setCustomValidity('Seleccione un cliente de la lista.');
    buscador.reportValidity();
    setTimeout(()=>buscador.setCustomValidity(''),1500);
  }
  return false;
}
let documentoGuardandose=false;
function bloquearAccionesDocumento(botonActivo){
  const botones=document.querySelectorAll('button[onclick*="guardarDocumentoModular"],button[onclick*="guardarRevisionPedidoCompleto"]');
  botones.forEach(function(b){
    b.disabled=true;
    if(!b.dataset.textoOriginal) b.dataset.textoOriginal=b.textContent;
    if(b===botonActivo) b.textContent='GUARDANDO...';
  });
}
function guardarDocumentoModular(accion,botonActivo){
  if(documentoGuardandose) return false;
  // v417: cliente se valida PRIMERO al emitir cotizacion o pedido directo.
  // Evita que cualquier otra validacion detenga el flujo sin avisar por falta de cliente.
  if((accion==='guardar_cotizacion' || accion==='generar_pedido_directo') && !validarClienteAntesDeEmitir()) return false;
  if(accion==='guardar_cotizacion' && <?= (int)$cotizacionEdicionId ?> > 0){
    const motivoCot=document.getElementById('motivo_modificacion_cotizacion');
    if(!motivoCot || !motivoCot.value.trim()){
      if(motivoCot){
        motivoCot.setCustomValidity('Indique qué se modificó antes de guardar la nueva revisión.');
        motivoCot.reportValidity();
        motivoCot.focus({preventScroll:true});
        motivoCot.scrollIntoView({behavior:'smooth',block:'center'});
      } else alert('Indique qué se modificó antes de guardar la nueva revisión.');
      return false;
    }
    motivoCot.setCustomValidity('');
  }
  const destino=document.getElementById('form_cotizador'); const origen=document.getElementById('form_senalizacion'); if(!destino)return false;
  // v124: antes de una emisión/revisión, normalizar la agrupación para asegurar que
  // cantidad_total_coches_bateria quede habilitada y viaje en el POST cuando corresponde.
  // No inferimos el dato: sólo preservamos el valor visible/guardado de la batería.
  if(typeof actualizarTipoAgrupacion==='function') actualizarTipoAgrupacion();
  const agrupacion=document.getElementById('id_bateria');
  const totalBateria=document.getElementById('cantidad_total_coches_bateria');
  const codigoAgrupacion=agrupacion&&agrupacion.selectedIndex>=0 ? String(agrupacion.options[agrupacion.selectedIndex].dataset.codigo||'') : '';
  if(codigoAgrupacion && codigoAgrupacion!=='INDIVIDUAL' && totalBateria){
    totalBateria.disabled=false;
    totalBateria.required=true;
    const cantidadCotizada=Math.max(1,parseInt(document.getElementById('cantidad_equipos')?.value||'1',10)||1);
    const totalActual=parseInt(totalBateria.value||'0',10)||0;
    if(totalActual<cantidadCotizada){
      totalBateria.setCustomValidity('Indique la cantidad total de coches de la batería. Debe ser igual o mayor que los equipos cotizados.');
      totalBateria.reportValidity();
      totalBateria.focus({preventScroll:true});
      totalBateria.scrollIntoView({behavior:'smooth',block:'center'});
      return false;
    }
    totalBateria.setCustomValidity('');
  }
  if(accion==='generar_pedido_directo'){
    const referenciaPedido=document.getElementById('referencia_cotizacion');
    if(!referenciaPedido || !referenciaPedido.value.trim()){
      alert('Falta completar la referencia. La referencia es obligatoria para generar un pedido.');
      if(referenciaPedido){
        referenciaPedido.setCustomValidity('Complete la referencia antes de generar el pedido.');
        referenciaPedido.reportValidity();
        referenciaPedido.focus({preventScroll:true});
        referenciaPedido.scrollIntoView({behavior:'smooth',block:'center'});
        setTimeout(()=>referenciaPedido.setCustomValidity(''),1500);
      }
      return false;
    }
    referenciaPedido.setCustomValidity('');
  }
  const supAsc=Number(document.getElementById('supervisor_ascensores')?.value||0),supCant=Number(document.getElementById('supervisor_cantidad')?.value||0);
  if(supCant>=1&&supAsc>8){mostrarModuloCotizador('accesorios');toggleControlSeccionPorId('acc_panel_supervisor');alert('Sistema Supervisor con más de 8 ascensores: CONSULTAR. Debe revisarse o cotizarse de forma especial antes de emitir.');return false;}
  const especialSinPrecio=[...document.querySelectorAll('[data-sint-codigo]:checked')].find(ch=>precioEspV33(ch.dataset.sintCodigo)<=0);
  if(especialSinPrecio){mostrarModuloCotizador('accesorios');alert('El código '+especialSinPrecio.dataset.sintCodigo+' no tiene precio disponible en la base Bejerman seleccionada. No se puede emitir hasta cargarlo o actualizar la base.');return false;}
  const caIncl=document.getElementById('senal_control_acceso_incluir');
  const caTec=document.getElementById('senal_control_acceso_tecnologia');
  const caCant=document.getElementById('senal_control_acceso_cantidad');
  if(caIncl?.checked && !String(caTec?.value||'').trim()){
    mostrarModuloCotizador('senalizacion');
    if(typeof abrirContenedorSenalizacion==='function')abrirContenedorSenalizacion('senal_contenedor_botonera_cabina','senal_macro_cabina_body');
    alert('Control de accesos está agregado. Elegí la tecnología antes de emitir.');
    caTec?.focus({preventScroll:true});caTec?.scrollIntoView({behavior:'smooth',block:'center'});
    return false;
  }
  if(caIncl?.checked && Number(caCant?.value||0)<=0){
    mostrarModuloCotizador('senalizacion');
    alert('Indique la cantidad de Control de accesos.');
    caCant?.focus({preventScroll:true});caCant?.scrollIntoView({behavior:'smooth',block:'center'});
    return false;
  }
  limpiarClonesModulares(destino); const incluirControl=document.getElementById('incluir_control'); let llevaControl=!incluirControl||incluirControl.checked;
  // v217: Repuestos puede convivir con Control, Señalización, Accesorios e IEP.
  clonarOculto(destino,'incluir_control',llevaControl?'1':'0');
  const incluir=document.getElementById('senal_incluir_cotizacion');
  if(incluir&&incluir.checked){if(!origen||!prepararSenalizacion()||!origen.reportValidity()){mostrarModuloCotizador('senalizacion');return false;}clonarOculto(destino,'senal_incluir','1');copiarCamposSenalizacion(destino,'modulo-clonado');}else clonarOculto(destino,'senal_incluir','0');
  if(!recopilarModuloLibre(destino,'ACCESORIOS')||!recopilarModuloLibre(destino,'IEP')||!recopilarModuloLibre(destino,'REPUESTOS'))return false;
  const tieneOtro=(incluir&&incluir.checked)||['accesorios','iep','repuestos'].some(m=>document.getElementById('incluir_'+m)?.checked);
  if(!llevaControl&&!tieneOtro){alert('Incluya al menos un módulo.');return false;}
  destino.target='_self'; destino.onsubmit=null;
  if(llevaControl){
    const estadoControlActual=String(window.estadoCalculoDocumento?.control||'pendiente');
    if(estadoControlActual!=='valido' || Number(window.subtotalesDocumento?.control||0)<=0){
      mostrarModuloCotizador('control');
      const textoEstado=estadoControlActual==='error'
        ? 'Control tiene una configuración SIN PRECIO o que no pudo calcularse. Revise los datos antes de emitir.'
        : 'Control todavía está pendiente de cálculo. Espere a que aparezca CALCULADO antes de emitir.';
      alert(textoEstado);
      return false;
    }
    if(!destino.reportValidity()){
      mostrarModuloCotizador('control');
      alert('Control está incluido en el documento, pero faltan datos obligatorios. Complete el Control o desmarque "Incluir Control en el documento".');
      return false;
    }
    destino.action='calcular.php';
  }
  else {const comunes=['lista_id','id_cliente'];for(const id of comunes){const e=document.getElementById(id);if(!e||!e.value){alert('Seleccione un cliente antes de continuar.');return false;}}destino.action='guardar_modular_sin_control.php';}
  // HF1: recién después de TODAS las validaciones agregamos la acción comercial.
  // Así un reportValidity() fallido no deja una acción de guardado residual que pueda
  // contaminar el siguiente cálculo automático.
  clonarOculto(destino,'accion_comercial',accion);
  const modoPanelFinal=destino.querySelector('[name="modo_panel"]');
  if(modoPanelFinal) modoPanelFinal.value='0';
  documentoGuardandose=true;
  bloquearAccionesDocumento(botonActivo);
  HTMLFormElement.prototype.submit.call(destino); return false;
}
function guardarCotizacionIntegrada(botonActivo){return guardarDocumentoModular('guardar_cotizacion',botonActivo);}

function guardarCotizacionCompletaConSenalizacion(){
  establecerInclusionSenalizacion(true);
  return guardarCotizacionIntegrada();
}

function guardarRevisionPedidoCompleto(botonActivo){
  const motivo=document.getElementById('motivo_modificacion_pedido');
  if(!motivo) return false;
  if(!motivo.value.trim()){
    motivo.setCustomValidity('Indique el detalle de la modificación antes de actualizar el pedido.');
    motivo.reportValidity();
    motivo.focus({preventScroll:true});
    motivo.scrollIntoView({behavior:'smooth',block:'center'});
    return false;
  }
  motivo.setCustomValidity('');
  return guardarDocumentoModular('guardar_revision_pedido',botonActivo);
}

function toggleControlSeccion(cabecera){
  const seccion=cabecera?.closest('.control-seccion'); if(!seccion) return;
  const cerrada=seccion.classList.toggle('cerrada');
  cabecera.setAttribute('aria-expanded',cerrada?'false':'true');
}
function toggleControlSeccionKey(event,cabecera){
  if(event.key==='Enter'||event.key===' '){event.preventDefault();toggleControlSeccion(cabecera);}
}
function abrirControlSeccion(id){
  const sec=document.getElementById(id); if(!sec) return;
  sec.classList.remove('cerrada'); const cab=sec.querySelector('.control-seccion-titulo'); if(cab)cab.setAttribute('aria-expanded','true');
}
function toggleControlSeccionPorId(id){
  const sec=document.getElementById(id); if(!sec) return;
  const cab=sec.querySelector('.control-seccion-titulo'); if(cab) toggleControlSeccion(cab);
}
function actualizarResumenRapidoControl(){
  const form=document.getElementById('form_cotizador'); if(!form) return;
  const texto=(id)=>{const e=document.getElementById(id);return e&&e.selectedIndex>=0?String(e.options[e.selectedIndex].text||'').trim():'';};
  const cantidad=Math.max(1,parseInt(document.getElementById('cantidad_equipos')?.value||'1',10)||1);
  const checks=[...form.querySelectorAll('#control_paso_adicionales input[type="checkbox"]:checked')].length;
  const selectExtras=[...form.querySelectorAll('#control_paso_adicionales select')].filter(s=>String(s.value||'').trim()!=='').length;
  const cantExtras=[...form.querySelectorAll('#control_paso_adicionales input[type="number"]')].filter(i=>Number(i.value||0)>0 && !String(i.name||'').startsWith('adicional_manual_importe_')).length;
  const cpu=texto('id_cpu')||'CPU sin configurar';
  const tipo=texto('id_tipo_control')||'Tipo sin configurar';
  const paradasInputs=[...form.querySelectorAll('input[name="paradas_equipo[]"]')];
  const paradasVals=paradasInputs.map(i=>String(i.value||'').trim()).filter(Boolean);
  let resumenParadas='Falta completar';
  if(paradasVals.length===cantidad){const unicas=[...new Set(paradasVals)];resumenParadas=(unicas.length===1?unicas[0]:paradasVals.join(' / '))+' parada'+(unicas.length===1 && unicas[0]==='1'?'':'s');}
  const maniobraBase=texto('id_maniobra');
  const cfgSel=document.getElementById('configuracion_especial_control');
  const cfgTxt=cfgSel && cfgSel.selectedIndex>=0 ? String(cfgSel.options[cfgSel.selectedIndex].text||'') : '';
  const maniobra=(maniobraBase||'Maniobra sin configurar') + (cfgTxt && cfgTxt!=='Normal' ? ' · '+cfgTxt : '');
  const resumen=document.getElementById('control_resumen_texto');
  if(resumen) resumen.textContent=[cpu,tipo,cantidad+' equipo'+(cantidad===1?'':'s'),'Paradas: '+resumenParadas,'Maniobra: '+maniobra,'Adicionales: '+(checks+selectExtras+cantExtras)].join(' · ');
}
function tipoOnixSenalizacionActual(){
  const modelo=document.getElementById('senal_modelo');
  if(!modelo || modelo.selectedIndex<0) return '';
  const txt=String(modelo.options[modelo.selectedIndex]?.textContent||'').trim().toUpperCase()
    .normalize('NFD').replace(/[\u0300-\u036f]/g,'');
  if(txt.includes('ONIX TELEFONICO')) return 'TELEFONICO';
  if(txt.includes('ONIX INDIVIDUALES') || txt.includes('ONIX PULS')) return 'INDIVIDUALES';
  if(txt.includes('PANTALLA 21') || txt.includes('PANTALLA TOUCH 21')) return 'PANTALLA_21';
  return '';
}
function aplicarReglaOnixSenalizacion(){
  const tipo=tipoOnixSenalizacionActual();
  const esOnix=tipo!=='';
  const modeloSel=document.getElementById('senal_modelo');
  const modeloTxt=String(modeloSel?.selectedOptions?.[0]?.textContent||'').toUpperCase();
  const esRond=modeloTxt.includes('ROND METAL');
  const moduloWrap=document.getElementById('senal_tipo_modulo_wrap');
  if(moduloWrap) moduloWrap.style.display=esRond?'none':'';
  const detalle=document.getElementById('senal_detalle_convencional');
  if(detalle){
    const ocultarDetalle=esOnix||esRond;
    detalle.style.display=ocultarDetalle?'none':'';
    detalle.querySelectorAll('select,input').forEach(function(campo){
      if(campo.dataset.onixDisabledOriginal===undefined) campo.dataset.onixDisabledOriginal=campo.disabled?'1':'0';
      campo.disabled=ocultarDetalle ? true : campo.dataset.onixDisabledOriginal==='1';
      if(ocultarDetalle) campo.required=false;
      else if(campo.dataset.requiredBotoneraOriginal==='1') campo.required=true;
    });
  }

  const modeloInd=document.getElementById('senal_indicador_modelo');
  const cantInd=document.getElementById('senal_indicador_cantidad');
  const wrapModelo=document.getElementById('senal_indicador_modelo_wrap');
  const wrapCant=document.getElementById('senal_indicador_cantidad_wrap');
  const info=document.getElementById('senal_onix_indicador_info');
  const ayuda=document.getElementById('senal_indicador_codigo_ayuda');
  if(esOnix){
    if(modeloInd){ modeloInd.value=''; modeloInd.disabled=true; }
    if(cantInd){ cantInd.value='0'; cantInd.disabled=true; }
    if(wrapModelo) wrapModelo.style.display='none';
    if(wrapCant) wrapCant.style.display='none';
    if(ayuda) ayuda.style.display='none';
    if(info){
      let texto='';
      if(tipo==='PANTALLA_21') texto='<strong>Indicador de posición:</strong> no lleva.';
      if(tipo==='TELEFONICO') texto='<strong>Indicador incluido:</strong> 7 pulg Beaglebond · incluido en la botonera, no se suma al precio.';
      if(tipo==='INDIVIDUALES') texto='<strong>Indicador incluido:</strong> A4830 Crystal Color · incluido en la botonera, no se suma al precio.';
      info.innerHTML=texto;
      info.style.display='block';
    }
  }else{
    if(modeloInd) modeloInd.disabled=false;
    if(cantInd) cantInd.disabled=false;
    if(wrapModelo) wrapModelo.style.display='';
    if(wrapCant) wrapCant.style.display='';
    if(ayuda) ayuda.style.display='';
    if(info){ info.style.display='none'; info.innerHTML=''; }
  }

  // v178: las ONIX no llevan Braille interior ni Braille lateral.
  document.querySelectorAll('.senal-braille-auto').forEach(function(item){
    item.style.display=esOnix?'none':'';
    item.setAttribute('aria-hidden',esOnix?'true':'false');
  });

  // Toda ONIX indicada por Producción requiere Luz de cortesía en Control.
  const luz=document.querySelector('#form_cotizador input[name="luz_cortesia"]');
  if(luz){
    if(esOnix){
      if(!luz.checked){ luz.checked=true; luz.dataset.onixAuto='1'; }
    }else if(luz.dataset.onixAuto==='1'){
      luz.checked=false;
      delete luz.dataset.onixAuto;
    }
  }
  actualizarResumenRapidoSenalizacion();
  programarCalculoSenalizacion(80);
}
document.addEventListener('DOMContentLoaded',aplicarReglaOnixSenalizacion);

function actualizarTeclaSenalizacion(){
  const modelo=document.getElementById('senal_modelo');
  const tecla=document.getElementById('senal_tecla');
  if(!modelo||!tecla) return;

  // Reglas comerciales de tecla por modelo:
  // A3150 = AR/AC; A3160 = AR/AC o RELIEVE; A3170/A3180 = RELIEVE; A3900 = ACERO.
  // v188: ROND METAL no configura ni valida tecla.
  const reglas={
    '1':['1'],
    '2':['1','2'],
    '3':['2'],
    '4':['2'],
    '5':['3']
  };
  const permitidas=reglas[String(modelo.value||'')] || null;

  Array.from(tecla.options).forEach(function(op){
    if(op.value===''){ op.hidden=!!permitidas; op.disabled=!!permitidas; return; }
    const ok=!permitidas || permitidas.includes(String(op.value));
    op.hidden=!ok;
    op.disabled=!ok;
  });

  if(permitidas){
    if(!permitidas.includes(String(tecla.value||''))) tecla.value=permitidas[0];
    const unica=permitidas.length===1;
    tecla.style.pointerEvents=unica?'none':'';
    tecla.style.backgroundColor=unica?'#f1f3f4':'';
    tecla.setAttribute('aria-readonly',unica?'true':'false');
    tecla.tabIndex=unica?-1:0;
    tecla.title=unica?'Tecla determinada automaticamente por el modelo':'';
  } else {
    tecla.style.pointerEvents='';
    tecla.style.backgroundColor='';
    tecla.removeAttribute('aria-readonly');
    tecla.tabIndex=0;
    tecla.title='';
  }
}

function tipoModuloSenalizacionActual(){
  const tipo=document.getElementById('senal_tipo_modulo');
  if(!tipo || tipo.selectedIndex<0) return '';
  return String(tipo.options[tipo.selectedIndex].text||'').trim().toUpperCase();
}

function senalControlIncluidoV190(){return !!document.getElementById('incluir_control')?.checked;}
function forzarSenalElectronicaConControlV190(){if(!senalControlIncluidoV190())return;const tipo=document.getElementById('senal_tipo_modulo');if(tipo){const op=Array.from(tipo.options).find(o=>String(o.textContent||'').toUpperCase().includes('ELECTRON'));if(op)tipo.value=op.value;Array.from(tipo.options).forEach(o=>{if(o.value)o.disabled=!String(o.textContent||'').toUpperCase().includes('ELECTRON');});}const borne=document.getElementById('senal_borne_manual');if(borne){const op3=Array.from(borne.options).find(o=>String(o.textContent||'').toUpperCase().includes('3'));if(op3)borne.value=op3.value;Array.from(borne.options).forEach(o=>{if(o.value)o.disabled=!String(o.textContent||'').toUpperCase().includes('3');});}filtrarIndicadoresExteriorControlV190();}
function liberarSenalElectronicaSinControlV190(){if(senalControlIncluidoV190())return;const tipo=document.getElementById('senal_tipo_modulo');if(tipo)Array.from(tipo.options).forEach(o=>o.disabled=false);const borne=document.getElementById('senal_borne_manual');if(borne)Array.from(borne.options).forEach(o=>o.disabled=false);filtrarIndicadoresExteriorControlV190();}
function filtrarIndicadoresExteriorControlV190(){
  // v366: el tipo de indicador exterior sigue el destino real de la Senalizacion,
  // no si el modulo Control forma parte o no del documento.
  const tipoTxt=tipoModuloSenalizacionActual();
  const electronico=tipoTxt.includes('ELECTRON')&&!tipoTxt.includes('ELECTROMEC');
  const buscado=electronico?'ELECTRON':'ELECTROMEC';
  const sel=document.getElementById('senal_indicador_ext_modelo_v190');
  if(sel){
    Array.from(sel.options).forEach(o=>{
      if(!o.value){o.disabled=false;o.hidden=false;return;}
      const ok=String(o.dataset.tipo||'').toUpperCase().includes(buscado);
      o.disabled=!ok;o.hidden=!ok;
    });
    if(sel.value&&sel.selectedOptions[0]?.disabled)sel.value='';
  }
  ['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(f=>refrescarPulsadorExteriorV179(f,false));
}
function filtrarIndicadoresPorTipoSenalizacion(){
  const tipoTxt=tipoModuloSenalizacionActual();
  const selector=document.getElementById('senal_indicador_modelo');
  if(!selector) return;
  let tipoTabla='';
  if(tipoTxt.includes('ELECTROMEC')) tipoTabla='ELECTROMECANICO';
  else if(tipoTxt.includes('ELECTRON')) tipoTabla='ELECTRONICO';

  let seleccionValida=false;
  Array.from(selector.options).forEach(function(op){
    if(!op.value){ op.hidden=false; op.disabled=false; return; }
    const tipos=String(op.dataset.tipos||'').toUpperCase().split('|').map(x=>x.trim()).filter(Boolean);
    const visible=!tipoTabla || tipos.includes(tipoTabla);
    op.hidden=!visible;
    op.disabled=!visible;
    if(visible && op.selected) seleccionValida=true;
  });

  if(selector.value && !seleccionValida){
    selector.value='';
    normalizarIndicadorSenalizacion();
  }
}

document.addEventListener('DOMContentLoaded',function(){
  filtrarIndicadoresPorTipoSenalizacion();
});

function aplicarReglaTipoModuloSenalizacion(){
  // v412: el selector Módulos define la tecnologia de la señalizacion cuando no se cotiza Control.
  // Si se incluye Control, ELECTRONICO queda forzado porque trabaja con el Control cotizado.
  const usar=document.getElementById('senal_usar_control');
  const tipo=document.getElementById('senal_tipo_modulo');
  const controlIncluido=senalControlIncluidoV190();
  if(controlIncluido&&tipo){
    const op=Array.from(tipo.options).find(o=>{const t=String(o.textContent||'').toUpperCase();return t.includes('ELECTRON')&&!t.includes('ELECTROMEC');});
    if(op) tipo.value=op.value;
    Array.from(tipo.options).forEach(o=>{
      if(!o.value) return;
      const t=String(o.textContent||'').toUpperCase();
      o.disabled=!(t.includes('ELECTRON')&&!t.includes('ELECTROMEC'));
    });
  }else if(tipo){
    // v413: sin Control, ambos destinos son válidos. No permitir que otra rutina
    // deje ELECTRONICO deshabilitado por un estado anterior del formulario.
    Array.from(tipo.options).forEach(o=>{ o.disabled=false; o.hidden=false; });
    tipo.style.pointerEvents='';
    tipo.removeAttribute('aria-disabled');
    tipo.tabIndex=0;
  }
  const tipoTxt=tipoModuloSenalizacionActual();
  const electronico=tipoTxt.includes('ELECTRON')&&!tipoTxt.includes('ELECTROMEC');
  if(usar){
    if(controlIncluido){
      usar.disabled=false;
      usar.title='Usar los datos del Control incluido en esta cotizacion.';
    }else{
      usar.checked=false;
      usar.disabled=true;
      usar.title='Sin Control incluido: elija ELECTROMECANICO o ELECTRONICO en Módulos.';
    }
  }
  const hidden=document.getElementById('senal_tiene_control');
  if(hidden) hidden.value=electronico?'1':'0';
  const estado=document.getElementById('v365_tipo_control_senal');
  if(estado){
    // v415: esta tarjeta informa el origen de los datos, no la tecnologia elegida.
    // ELECTRONICO / ELECTROMECANICO se define exclusivamente en el selector Modulos.
    if(controlIncluido){
      estado.innerHTML='<strong>CONTROL AUTOMAC INCLUIDO</strong><span>Puede usar los datos del Control cotizado o ajustar la configuración manualmente.</span>';
      estado.classList.add('v365-electronico');
      estado.classList.remove('v365-electromecanico');
    }else{
      estado.innerHTML='<strong>OTRO CONTROL</strong><span>Configure la señalización según el equipo existente. El tipo se elige abajo en Módulos.</span>';
      estado.classList.remove('v365-electronico','v365-electromecanico');
    }
  }
  filtrarIndicadoresPorTipoSenalizacion();
  filtrarIndicadoresExteriorControlV190();
}

function actualizarBornesSenalizacion(){
  const mod=document.getElementById('senal_modelo');
  const info=document.getElementById('senal_borne_info');
  const selector=document.getElementById('senal_borne_manual');
  const usar=document.getElementById('senal_usar_control');
  const controlIncluido=document.getElementById('incluir_control');
  const tension=document.getElementById('senal_tension');
  if(!mod||!selector) return;

  aplicarReglaTipoModuloSenalizacion();
  const tipoTxt=tipoModuloSenalizacionActual();
  const esElectromecanico=tipoTxt.includes('ELECTROMEC');
  const esElectronico=tipoTxt.includes('ELECTRON');
  const usaDatosControl=!!(usar && usar.checked);
  let automatico=false;
  let mensaje='Seleccione 3B o 4B según la botonera a cotizar';

  // v173: la tecnología de la botonera define los bornes.
  if(esElectromecanico){
    selector.value='2';
    automatico=true;
    mensaje='4B fijo para botonera electromecánica';
  } else if(esElectronico){
    selector.value='1';
    automatico=true;
    mensaje=usaDatosControl
      ? '3B automático al usar datos del Control'
      : '3B fijo para botonera electrónica';
  } else if(mod.value==='5'){
    // Compatibilidad para tipos históricos/no identificados.
    selector.value='2';
    automatico=true;
    mensaje='4B fijo para A3900';
  } else if(mod.value==='6'){
    const txt=tension&&tension.selectedIndex>=0 ? String(tension.options[tension.selectedIndex].text||'').trim().toUpperCase() : '';
    selector.value=(txt==='24V' ? '1' : '2');
    automatico=true;
    mensaje=(txt==='24V' ? '3B fijo para ROND METAL 24V' : '4B fijo para ROND METAL');
  } else if(usaDatosControl){
    selector.value='1';
    automatico=true;
    mensaje='3B automático al usar datos del Control';
  }

  selector.disabled=automatico;
  selector.style.pointerEvents=automatico?'none':'';
  selector.style.backgroundColor=automatico?'#f1f3f4':'';
  selector.setAttribute('aria-readonly',automatico?'true':'false');
  selector.tabIndex=automatico?-1:0;
  if(info) info.textContent=mensaje;
}

document.addEventListener('DOMContentLoaded', function(){ actualizarTeclaSenalizacion(); });
document.addEventListener('DOMContentLoaded', function(){ seleccionarClienteDesdeBusqueda(); cargarCliente(); });

function actualizarTensionSenalizacion(){
  const tension=document.getElementById('senal_tension');
  const info=document.getElementById('senal_tension_info');
  const usar=document.getElementById('senal_usar_control');
  const controlIncluido=document.getElementById('incluir_control');
  if(!tension) return;

  const usaDatosControl=!!(usar && usar.checked && controlIncluido && controlIncluido.checked);
  if(usaDatosControl){
    const opcion24=Array.from(tension.options).find(function(op){
      return String(op.textContent || '').trim().toUpperCase()==='24V';
    });
    if(opcion24) tension.value=opcion24.value;
    tension.style.pointerEvents='none';
    tension.style.backgroundColor='#f1f3f4';
    tension.setAttribute('aria-disabled','true');
    tension.tabIndex=-1;
    if(info) info.textContent='24V automático al usar datos del Control';
    actualizarBornesSenalizacion();
  } else {
    tension.style.pointerEvents='';
    tension.style.backgroundColor='';
    tension.removeAttribute('aria-disabled');
    tension.tabIndex=0;
    if(info) info.textContent='Seleccione la tensión correspondiente';
  }
}

function actualizarResumenRapidoSenalizacion(){
  const form=document.getElementById('form_senalizacion'); if(!form)return;
  const txt=(sel)=>{const e=form.querySelector(sel);return e&&e.selectedIndex>=0?String(e.options[e.selectedIndex].text||'').trim():'';};
  const val=(sel)=>String(form.querySelector(sel)?.value||'').trim();
  const poner=(id,texto)=>{const e=document.getElementById(id);if(e)e.textContent=texto||'-';};
  const cantidad=Math.max(1,parseInt(val('[name="senal_cantidad"]')||'1',10)||1);
  const paradasLista=obtenerParadasSenalizacion();
  const adicionales=paradasLista.reduce((acc,p)=>acc+Math.max(0,p-2),0);
  const modeloBase=txt('[name="senal_modelo"]');
  const esRondBase=normalizarTextoPulsadorV179(modeloBase)==='METAL';
  const base=(esRondBase?[txt('[name="senal_tipo_puerta"]'),modeloBase]:[txt('[name="senal_tipo_puerta"]'),modeloBase,txt('[name="senal_tension"]'),txt('[name="senal_borne_manual"]'),txt('[name="senal_color"]')]).filter(Boolean).join(' · ');
  const indModelo=val('[name="senal_indicador_modelo"]'); const indCant=Math.max(0,parseInt(val('[name="senal_indicador_cantidad"]')||'0',10)||0);
  const checks=[...form.querySelectorAll('.senal-check input[type="checkbox"]:checked:not([name="senal_bonificar_pano"])')].length;
  poner('senal_chip_cantidad',cantidad+' botonera'+(cantidad===1?'':'s'));
  poner('senal_chip_base',base||'Sin configurar');
  const textoParadas=paradasLista.length?(new Set(paradasLista)).size===1?(paradasLista[0]+' c/u'):paradasLista.map((p,i)=>'C'+(i+1)+': '+p).join(' · '):'-';
  poner('senal_chip_paradas',textoParadas+' · '+adicionales+' adicionales');
  poner('senal_chip_indicador',indCant>0&&indModelo?indCant+' × '+indModelo:'Sin indicador');
  poner('senal_chip_adicionales',checks+' seleccionado'+(checks===1?'':'s'));
}


function toggleSeccionSenal(cabecera,idCuerpo){
  const cuerpo=document.getElementById(idCuerpo); if(!cuerpo||!cabecera) return;
  const seccion=cabecera.closest('.senal-seccion'); if(!seccion) return;
  const cerrada=seccion.classList.toggle('senal-acordeon-cerrado');
  cabecera.classList.toggle('is-collapsed',cerrada);
  cabecera.setAttribute('aria-expanded',cerrada?'false':'true');
}
function toggleSeccionSenalKey(event,cabecera,idCuerpo){
  if(event.key==='Enter' || event.key===' '){ event.preventDefault(); toggleSeccionSenal(cabecera,idCuerpo); }
}
function toggleContenedorSenalizacion(cabecera,idCuerpo){
  const cuerpo=document.getElementById(idCuerpo); if(!cuerpo||!cabecera) return;
  const contenedor=cabecera.closest('.senal-contenedor-macro'); if(!contenedor) return;
  const cerrado=contenedor.classList.toggle('senal-macro-cerrado');
  cabecera.setAttribute('aria-expanded',cerrado?'false':'true');
  const icono=cabecera.querySelector('.senal-macro-toggle-icon');
  if(icono) icono.textContent=cerrado?'⌄':'⌃';
}
function toggleContenedorSenalizacionKey(event,cabecera,idCuerpo){
  if(event.key==='Enter' || event.key===' '){ event.preventDefault(); toggleContenedorSenalizacion(cabecera,idCuerpo); }
}
function abrirContenedorSenalizacion(idContenedor,idCuerpo){
  const contenedor=document.getElementById(idContenedor); if(!contenedor) return;
  const cabecera=contenedor.querySelector('.senal-contenedor-macro-cabecera');
  if(contenedor.classList.contains('senal-macro-cerrado')){
    contenedor.classList.remove('senal-macro-cerrado');
    if(cabecera){cabecera.setAttribute('aria-expanded','true');const icono=cabecera.querySelector('.senal-macro-toggle-icon');if(icono)icono.textContent='⌃';}
  }
  setTimeout(()=>contenedor.scrollIntoView({behavior:'smooth',block:'start'}),20);
}
function actualizarCantidadesSenalizacionExterior(){
  const suma=(nombres)=>nombres.reduce((t,n)=>t+(parseInt(document.querySelector('[name="'+n+'"]')?.value||'0',10)||0),0);
  const items=(window.__senalPulsItemsV188&&Array.isArray(window.__senalPulsItemsV188))?window.__senalPulsItemsV188:null;
  const p=items?items.reduce((t,x)=>t+(parseInt(x.cantidad||0,10)||0),0):suma(['senal_pulsadores_simples_cantidad','senal_pulsadores_simples_indicador_cantidad','senal_pulsadores_dobles_cantidad','senal_pulsadores_dobles_indicador_cantidad']);
  const inds=(window.__senalIndicadoresExtV190&&Array.isArray(window.__senalIndicadoresExtV190))?window.__senalIndicadoresExtV190:null;
  const i=inds?inds.reduce((t,x)=>t+(parseInt(x.cantidad||0,10)||0),0):suma(['senal_indicador_18mm_v5_cantidad','senal_indicador_31mm_v5_cantidad','senal_indicador_a4610_cantidad','senal_indicador_a4600_cantidad','senal_indicador_a4810_cantidad','senal_indicador_a4830_cantidad','senal_indicador_a7260_cantidad']);
  const bp=document.getElementById('senal_total_pulsadores'); if(bp) bp.textContent=p+' unidades';
  const bi=document.getElementById('senal_total_indicadores'); if(bi) bi.textContent=i+' unidades';
}

/* v181 - Botonera de cabina opcional. */
function actualizarInclusionBotoneraCabinaV181(recalcular){
  const chk=document.getElementById('senal_incluir_botonera_cabina');
  const incluir=chk?chk.checked:true;
  const datos=document.getElementById('senal_datos_cabina_v181');
  if(datos){
    datos.style.display=incluir?'':'none';
    datos.querySelectorAll('input,select,textarea,button').forEach(el=>{
      if(incluir){
        if(el.dataset.v181Disabled==='1'){el.disabled=false;delete el.dataset.v181Disabled;}
      }else if(!el.disabled){el.dataset.v181Disabled='1';el.disabled=true;}
    });
  }
  ['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(f=>{
    const p=prefPulsadorV179(f),m=document.getElementById(p+'mismo_modelo'),w=document.getElementById(p+'modelo_wrap');
    if(!m)return;
    if(!incluir){
      m.checked=false;m.disabled=true;if(w)w.style.display='';
      visibilidadConfiguracionPulsadorExteriorV358(f,true);
    }else{
      m.disabled=false;
      visibilidadConfiguracionPulsadorExteriorV358(f,!m.checked);
    }
    refrescarPulsadorExteriorV179(f,false);
    visibilidadConfiguracionPulsadorExteriorV358(f,false);
  });
  if(recalcular)programarCalculoSenalizacion(100);
}
/* v179 - Configurador de Pulsadores exteriores. */
function normalizarTextoPulsadorV179(v){
  let x=String(v||'').trim().toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s+/g,' ').replace('ROND METAL','METAL');if(x.includes('ONIX TELEFONICO'))x='ONIX TELEFONICO';else if(x.includes('ONIX INDIVIDUALES')||x.includes('ONIX PULS'))x='ONIX INDIVIDUALES';else if(x.includes('PANTALLA 21')||x.includes('PANTALLA TOUCH 21'))x='PANTALLA 21';return x;
}
function matrizPulsadoresV179(){
  if(window.__senalPulsExtMatrizV179) return window.__senalPulsExtMatrizV179;
  const e=document.getElementById('senal_pulsadores_matriz_json');
  try{window.__senalPulsExtMatrizV179=JSON.parse(e?.textContent||'[]');}catch(_){window.__senalPulsExtMatrizV179=[];}
  return window.__senalPulsExtMatrizV179;
}
function matrizIndicadoresPulsadoresV181(){
  if(window.__senalPulsExtIndicadoresV181) return window.__senalPulsExtIndicadoresV181;
  const e=document.getElementById('senal_pulsadores_indicadores_json');
  try{window.__senalPulsExtIndicadoresV181=JSON.parse(e?.textContent||'[]');}catch(_){window.__senalPulsExtIndicadoresV181=[];}
  return window.__senalPulsExtIndicadoresV181;
}
function matrizIndicadoresMaestraV190(){if(window.__senalIndicadoresMaestraV190)return window.__senalIndicadoresMaestraV190;try{window.__senalIndicadoresMaestraV190=JSON.parse(document.getElementById('senal_indicadores_maestros_json')?.textContent||'[]');}catch(_){window.__senalIndicadoresMaestraV190=[];}return window.__senalIndicadoresMaestraV190;}
function formatearPrecioPulsadorV182(v){
  const n=Number(v||0);return n>0?'$ '+Math.ceil(n).toLocaleString('es-AR'):'SIN PRECIO';
}
function coeficientesAcabadoV190(){if(window.__senalCoefAcabV190)return window.__senalCoefAcabV190;try{window.__senalCoefAcabV190=JSON.parse(document.getElementById('senal_acabados_coeficientes_json')?.textContent||'[]');}catch(_){window.__senalCoefAcabV190=[];}return window.__senalCoefAcabV190;}
function coeficienteAcabadoV190(acabado,especial){const a=String(acabado||'ACERO').trim().toUpperCase();const e=especial?1:0;const r=coeficientesAcabadoV190().find(x=>String(x.acabado||'').trim().toUpperCase()===a && Number(x.medida_especial||0)===e && Number(x.activo||0)!==0);const c=r&&r.coeficiente!==null&&r.coeficiente!==''?Number(r.coeficiente):NaN;return Number.isFinite(c)&&c>0?c:null;}
function aplicarCoefPreviewV190(base,acabado,especial){const c=coeficienteAcabadoV190(acabado,especial);if(c===null)return {ok:false,total:0,coef:null};return {ok:true,total:Math.ceil(Number(base||0)*c),coef:c};}
async function actualizarPrecioPulsadorV182(fam,codigo){
  const pref=prefPulsadorV179(fam), out=document.getElementById(pref+'precio_preview'), detalle=document.getElementById(pref+'precio_detalle');
  if(!out)return;
  const solicitud=Number(out.dataset.precioSolicitud||0)+1;
  out.dataset.precioSolicitud=String(solicitud);
  out.dataset.unitario='0';
  codigo=String(codigo||'').trim();
  if(!codigo || codigo==='A RESOLVER' || codigo.includes('COMPLETE') || codigo.includes('SIN COMBIN')){out.textContent='A RESOLVER';out.dataset.unitario='0';out.classList.remove('sin-precio');if(detalle)detalle.textContent='Antes de descuentos de Señalización';return;}
  const lista=document.getElementById('senal_lista_id')?.value||document.getElementById('lista_id')?.value||'';
  out.textContent='Consultando...';out.classList.remove('sin-precio');
  const consultar=async(cod)=>{const fd=new FormData();fd.append('codigo',cod);fd.append('lista_id',lista);const r=await fetch('precio_pulsador_exterior.php',{method:'POST',body:fd,credentials:'same-origin'});return await r.json();};
  try{
    const jp=await consultar(codigo);
    if(Number(out.dataset.precioSolicitud)!==solicitud)return;
    if(!jp.ok){out.textContent='SIN PRECIO BEJERMAN';out.dataset.unitario='0';out.classList.add('sin-precio');if(detalle)detalle.textContent='Código pulsador '+codigo+' sin precio';return;}
    const acabado=String(document.getElementById(pref+'acabado')?.value||'ACERO');const especial=!!document.getElementById(pref+'medida_especial')?.checked;const ap=aplicarCoefPreviewV190(Number(jp.unitario||0),acabado,especial);if(!ap.ok){out.textContent='COEFICIENTE A DEFINIR';out.dataset.unitario='0';out.classList.add('sin-precio');if(detalle)detalle.textContent='Acabado '+acabado+(especial?' · medida especial':'')+' sin coeficiente definido';return;}let total=ap.total, texto='Pulsador '+formatearPrecioPulsadorV182(ap.total)+' ('+acabado+(especial?' especial':'')+' × '+ap.coef.toLocaleString('es-AR')+')';
    if(String(fam).endsWith('_IP')){
      const codInd=String(document.getElementById(pref+'indicador_codigo')?.value||'').trim();
      if(!codInd){out.textContent='FALTA INDICADOR';out.dataset.unitario='0';out.classList.add('sin-precio');if(detalle)detalle.textContent='Seleccione el indicador exterior';return;}
      const ji=await consultar(codInd);
      if(Number(out.dataset.precioSolicitud)!==solicitud)return;
      if(!ji.ok){out.textContent='SIN PRECIO INDICADOR';out.dataset.unitario='0';out.classList.add('sin-precio');if(detalle)detalle.textContent='Indicador '+codInd+' sin precio Bejerman';return;}
      total+=Number(ji.unitario||0);texto+=' + Indicador '+formatearPrecioPulsadorV182(ji.unitario);
    }
    out.textContent=formatearPrecioPulsadorV182(total);out.dataset.unitario=String(total);out.classList.remove('sin-precio');
    if(detalle)detalle.textContent=texto+' · antes de descuentos de Señalización';
  }catch(_){if(Number(out.dataset.precioSolicitud)!==solicitud)return;out.textContent='NO DISPONIBLE';out.dataset.unitario='0';out.classList.add('sin-precio');if(detalle)detalle.textContent='No fue posible consultar Bejerman';}
}
function codigoIndicadorCabinaV181(){
  const modelo=document.getElementById('senal_indicador_modelo')?.value||'';
  if(!modelo)return '';
  const tipo=tipoCabinaV179();
  const rows=matrizIndicadoresPulsadoresV181();
  const match=rows.find(r=>normalizarTextoPulsadorV179(r.tipo_modulos)===tipo && String(r.depende_codigo_ip_cabina||'').toUpperCase()===String(modelo).toUpperCase());
  return match?String(match.depende_codigo_ip_cabina||''):'';
}
function refrescarIndicadorExteriorV181(fam,usuario){
  if(!String(fam).endsWith('_IP'))return;
  const pref=prefPulsadorV179(fam), sel=document.getElementById(pref+'indicador_codigo'), tipoSel=document.getElementById(pref+'tipo'), preview=document.getElementById(pref+'indicador_modelo_preview');if(!sel)return;
  let tipo=normalizarTextoPulsadorV179(tipoSel?.value||tipoCabinaV179());if(senalControlIncluidoV190())tipo='ELECTRONICO';
  let rows=matrizIndicadoresMaestraV190().filter(r=>Number(r.activo)!==0 && (!tipo||normalizarTextoPulsadorV179(r.tipo_modulos)===tipo));
  const actual=String(sel.value||sel.dataset.valor||'').toUpperCase();
  sel.innerHTML='<option value="">Seleccione...</option>'+rows.map(r=>'<option value="'+String(r.codigo||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'">'+String(r.modelo_indicador||'')+' · '+String(r.codigo||'')+'</option>').join('');
  if(actual&&[...sel.options].some(o=>String(o.value).toUpperCase()===actual))sel.value=[...sel.options].find(o=>String(o.value).toUpperCase()===actual).value;else sel.value='';sel.dataset.valor='';
  const r=rows.find(x=>String(x.codigo||'')===String(sel.value||''));if(preview)preview.textContent=r?(String(r.modelo_indicador||'')+' · '+String(r.codigo||'')+' · incluido en el precio compuesto del pulsador + IP'):'Seleccione un indicador de la tabla maestra.';
  const codigoPul=String(document.getElementById(pref+'codigo_preview')?.textContent||'').trim();actualizarPrecioPulsadorV182(fam,codigoPul);if(usuario)programarCalculoSenalizacion(100);
}
function prefPulsadorV179(fam){ return 'senal_ext_'+String(fam).toLowerCase()+'_'; }
function textoSelectV179(id){const e=document.getElementById(id);return e&&e.selectedIndex>=0?String(e.options[e.selectedIndex].textContent||'').replace(/\s*\(DISCONTINUADO\)\s*/i,'').trim():'';}
function modeloCabinaV179(){return normalizarTextoPulsadorV179(textoSelectV179('senal_modelo'));}
function tipoCabinaV179(){return normalizarTextoPulsadorV179(textoSelectV179('senal_tipo_modulo'));}
function colorCabinaV179(){return normalizarTextoPulsadorV179(document.querySelector('[name="senal_color"]')?.selectedOptions?.[0]?.textContent||'');}
function tensionCabinaV179(){return normalizarTextoPulsadorV179(textoSelectV179('senal_tension'));}
function bornesCabinaV179(){return normalizarTextoPulsadorV179(textoSelectV179('senal_borne_manual'));}
function teclaCabinaV179(){return normalizarTextoPulsadorV179(textoSelectV179('senal_tecla'));}
function setOpcionesPulsadorV179(sel,vals,preferido){
  if(!sel)return '';
  const actual=normalizarTextoPulsadorV179(preferido||sel.value||sel.dataset.valor||'');
  const unicos=[...new Set(vals.map(normalizarTextoPulsadorV179).filter(Boolean))];
  sel.innerHTML='<option value="">Seleccione...</option>'+unicos.map(v=>'<option value="'+v.replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'">'+v+'</option>').join('');
  if(actual && unicos.includes(actual)) sel.value=actual; else if(unicos.length===1) sel.value=unicos[0]; else sel.value='';
  sel.dataset.valor='';
  return sel.value;
}
function aplicarReglaRondMetalExteriorV187(fam,modeloNormalizado){
  const pref=prefPulsadorV179(fam);
  const esMetal=normalizarTextoPulsadorV179(modeloNormalizado)==='METAL';
  const editor=document.getElementById(pref+'editor');
  if(editor){
    editor.querySelectorAll('.senal-ext-detalle-v187').forEach(function(w){
      w.style.display=esMetal?'none':'';
      w.querySelectorAll('select,input').forEach(function(c){
        if(esMetal){c.dataset.rondMetalDisabled='1';c.disabled=true;}
        else if(c.dataset.rondMetalDisabled==='1'){c.disabled=false;delete c.dataset.rondMetalDisabled;}
      });
    });
    const tipoWrap=editor.querySelector('.senal-ext-tipo-v188');
    if(tipoWrap) tipoWrap.style.display=(esMetal && !String(fam).endsWith('_IP'))?'none':'';
  }
  return esMetal;
}
function refrescarPulsadorExteriorV179(fam,usuario){
  const pref=prefPulsadorV179(fam), mismo=document.getElementById(pref+'mismo_modelo');
  const modeloSel=document.getElementById(pref+'modelo'),tipoSel=document.getElementById(pref+'tipo'),colorSel=document.getElementById(pref+'color'),tensionSel=document.getElementById(pref+'tension'),bornesSel=document.getElementById(pref+'bornes'),teclaSel=document.getElementById(pref+'tecla');
  let rows=matrizPulsadoresV179().filter(r=>String(r.familia||'').toUpperCase()===String(fam).toUpperCase() && Number(r.activo)!==0);if(senalControlIncluidoV190())rows=rows.filter(r=>normalizarTextoPulsadorV179(r.tipo_modulos)==='ELECTRONICO' && normalizarTextoPulsadorV179(r.bornes)==='3B');
  const modelCab=modeloCabinaV179();
  let modeloElegido='';
  if(mismo?.checked){
    modeloElegido=modelCab;
    rows=rows.filter(r=>normalizarTextoPulsadorV179(r.modelo_pulsador)===modelCab);
  }else{
    const modelos=[...new Set(rows.map(r=>r.modelo_pulsador))];
    const mv=setOpcionesPulsadorV179(modeloSel,modelos,modeloSel?.value||modeloSel?.dataset.valor);
    modeloElegido=normalizarTextoPulsadorV179(mv);
    if(mv)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.modelo_pulsador)===modeloElegido);
  }

  const esMetal=aplicarReglaRondMetalExteriorV187(fam,modeloElegido);
  const defaultTipo=usuario?tipoSel?.value:(tipoSel?.dataset.valor||tipoCabinaV179());
  const tv=setOpcionesPulsadorV179(tipoSel,rows.map(r=>r.tipo_modulos),defaultTipo);
  if(tv)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.tipo_modulos)===normalizarTextoPulsadorV179(tv));

  if(!esMetal){
    const cv=setOpcionesPulsadorV179(colorSel,rows.map(r=>r.color_registro),usuario?colorSel?.value:(colorSel?.dataset.valor||colorCabinaV179())); if(cv)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.color_registro)===normalizarTextoPulsadorV179(cv));
    const teV=setOpcionesPulsadorV179(tensionSel,rows.map(r=>r.tension_modulos),usuario?tensionSel?.value:(tensionSel?.dataset.valor||tensionCabinaV179())); if(teV)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.tension_modulos)===normalizarTextoPulsadorV179(teV));
    const bv=setOpcionesPulsadorV179(bornesSel,rows.map(r=>r.bornes),usuario?bornesSel?.value:(bornesSel?.dataset.valor||bornesCabinaV179())); if(bv)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.bornes)===normalizarTextoPulsadorV179(bv));
    const kv=setOpcionesPulsadorV179(teclaSel,rows.map(r=>r.tecla_modulos),usuario?teclaSel?.value:(teclaSel?.dataset.valor||teclaCabinaV179())); if(kv)rows=rows.filter(r=>normalizarTextoPulsadorV179(r.tecla_modulos)===normalizarTextoPulsadorV179(kv));
  }else{
    // v187: ROND METAL tiene códigos propios. Color/Tensión/Bornes/Tecla no forman
    // parte de la selección comercial del pulsador; solo se conserva Tipo para +IP.
    const codigos=[...new Set(rows.map(r=>String(r.codigo||'').trim()).filter(Boolean))];
    if(codigos.length>1){
      // Las filas históricas pueden diferir solo por tecnología pero apuntan al mismo
      // código ROND METAL después de la migración v186.
      rows=rows.filter(r=>String(r.codigo||'').trim()===codigos[0]);
    }
  }

  const codigo=document.getElementById(pref+'codigo_preview');
  const codigosFinales=[...new Set(rows.map(r=>String(r.codigo||'').trim()).filter(Boolean))];
  const codigoResuelto=codigosFinales.length===1?codigosFinales[0]:(rows.length?'COMPLETE COMBINACIÓN':'SIN COMBINACIÓN');
  if(codigo)codigo.textContent=codigoResuelto;
  actualizarPrecioPulsadorV182(fam,codigoResuelto);
  if(String(fam).endsWith('_IP'))refrescarIndicadorExteriorV181(fam,usuario);
  visibilidadConfiguracionPulsadorExteriorV358(fam,false);
  if(usuario)programarCalculoSenalizacion(120);
}
/* v358 - Si el pulsador exterior mantiene la configuracion de cabina, los
   campos tecnicos repetidos quedan ocultos. Los valores siguen presentes y
   se actualizan desde cabina para no alterar el calculo. */
function visibilidadConfiguracionPulsadorExteriorV358(fam, prepararEdicion=false){
  const pref=prefPulsadorV179(fam);
  const mismo=document.getElementById(pref+'mismo_modelo');
  const editor=document.getElementById(pref+'editor');
  if(!editor || !mismo)return;
  const modelo=document.getElementById(pref+'modelo_wrap');
  const tipo=editor.querySelector('.senal-ext-tipo-v188');
  const detalles=[...editor.querySelectorAll('.senal-ext-detalle-v187')];
  const ocultar=!!mismo.checked;

  if(ocultar){
    if(modelo)modelo.style.display='none';
    if(tipo)tipo.style.display='none';
    detalles.forEach(el=>el.style.display='none');
    editor.classList.add('v358-mismo-cabina');
  }else if(prepararEdicion){
    if(modelo)modelo.style.display='';
    if(tipo)tipo.style.display='';
    detalles.forEach(el=>el.style.display='');
    editor.classList.remove('v358-mismo-cabina');
  }else{
    editor.classList.remove('v358-mismo-cabina');
  }
}
function cambiarModeloExteriorV179(fam){
  const pref=prefPulsadorV179(fam),m=document.getElementById(pref+'mismo_modelo'),w=document.getElementById(pref+'modelo_wrap');
  /* Al destildar primero se liberan visualmente los campos para que el
     refresco pueda aplicar luego reglas particulares como ROND METAL. */
  visibilidadConfiguracionPulsadorExteriorV358(fam,!m?.checked);
  if(w)w.style.display=m?.checked?'none':'';
  refrescarPulsadorExteriorV179(fam,false);
  visibilidadConfiguracionPulsadorExteriorV358(fam,false);
  programarCalculoSenalizacion(100);
}
/* v188 - Cada familia puede contener multiples configuraciones simultaneas. */
function itemsPulsadoresV188(){
  if(Array.isArray(window.__senalPulsItemsV188))return window.__senalPulsItemsV188;
  const h=document.getElementById('senal_pulsadores_items_json');
  let arr=[];try{const x=JSON.parse(h?.value||'[]');if(Array.isArray(x))arr=x;}catch(_){arr=[];}
  window.__senalPulsItemsV188=arr;window.__senalPulsEditV188={};return arr;
}
function guardarItemsPulsadoresV188(recalcular=true){
  const h=document.getElementById('senal_pulsadores_items_json');const arr=itemsPulsadoresV188();if(h)h.value=JSON.stringify(arr);
  const campos={SIMPLE:'senal_pulsadores_simples_cantidad',SIMPLE_IP:'senal_pulsadores_simples_indicador_cantidad',DOBLE:'senal_pulsadores_dobles_cantidad',DOBLE_IP:'senal_pulsadores_dobles_indicador_cantidad'};
  Object.entries(campos).forEach(([fam,id])=>{const a=document.getElementById(prefPulsadorV179(fam)+'agregado');if(a)a.value=arr.some(x=>x.familia===fam)?'1':'0';});
  renderItemsPulsadoresV188();actualizarCantidadesSenalizacionExterior();sincronizarInclusionCabinaPorExteriorV183();if(recalcular)programarCalculoSenalizacion(80);
}
function etiquetaItemPulsadorV188(it){
  const fam={SIMPLE:'Simple',SIMPLE_IP:'Simple + IP',DOBLE:'Doble',DOBLE_IP:'Doble + IP'}[it.familia]||it.familia;
  let partes=[it.cantidad+' × '+fam,it.modelo||''];
  if(normalizarTextoPulsadorV179(it.modelo)!=='METAL')partes.push(it.color||'',it.tension||'',it.bornes||'',it.tecla||'');
  if(String(it.familia).endsWith('_IP'))partes.push('Indicador '+(it.indicador_modelo||it.indicador_codigo||''));
  partes.push('Medida '+(String(it.medida||'').trim()||'A CONFIRMAR'));partes.push('Tapa '+(it.acabado||'ACERO')+(it.medida_especial?' · ESPECIAL':''));
  return partes.filter(Boolean).join(' · ');
}
function renderItemsPulsadoresV188(){
  const arr=itemsPulsadoresV188();['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(fam=>{
    const cont=document.getElementById(prefPulsadorV179(fam)+'items_list');if(!cont)return;
    const rows=arr.map((it,idx)=>({it,idx})).filter(x=>x.it.familia===fam);
    if(!rows.length){cont.innerHTML='<div style="padding:9px 11px;border:1px dashed #cbd8d1;border-radius:9px;color:#6b7785;font-size:11px">Todavía no hay configuraciones agregadas.</div>';return;}
    cont.innerHTML='<div style="font-size:10px;font-weight:900;color:#526273;margin-bottom:6px;text-transform:uppercase">Configuraciones agregadas</div>'+rows.map(({it,idx})=>'<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;margin-top:5px;border:1px solid #cfe5d8;border-radius:9px;background:#f7fcf9"><div><strong style="display:block;color:#17324d;font-size:12px">'+escHtmlV188(etiquetaItemPulsadorV188(it))+'</strong><small style="color:#667085">Código técnico: '+escHtmlV188(it.codigo||'A RESOLVER')+(Number(it.precio_unitario||0)>0?' · Unitario: '+formatearPrecioPulsadorV182(it.precio_unitario):'')+'</small></div><div style="display:flex;gap:5px"><button type="button" onclick="editarItemPulsadorV188(\''+fam+'\','+idx+')">Editar</button><button type="button" onclick="eliminarItemPulsadorV188('+idx+')">Quitar</button></div></div>').join('');
  });
}
function escHtmlV188(v){return String(v??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function capturarItemPulsadorV188(fam){
  const pref=prefPulsadorV179(fam),qmap={SIMPLE:'senal_pulsadores_simples_cantidad',SIMPLE_IP:'senal_pulsadores_simples_indicador_cantidad',DOBLE:'senal_pulsadores_dobles_cantidad',DOBLE_IP:'senal_pulsadores_dobles_indicador_cantidad'};
  const cantidad=parseInt(document.getElementById(qmap[fam])?.value||'0',10)||0;if(cantidad<=0){alert('Ingrese una cantidad mayor que 0.');return null;}
  const mismo=!!document.getElementById(pref+'mismo_modelo')?.checked;
  const modelo=mismo?modeloCabinaV179():normalizarTextoPulsadorV179(document.getElementById(pref+'modelo')?.value||'');
  if(!modelo){alert('Seleccione el modelo del pulsador exterior.');return null;}
  const codigo=String(document.getElementById(pref+'codigo_preview')?.textContent||'').trim();
  if(!codigo || /A RESOLVER|COMPLETE|SIN COMBINACI/i.test(codigo)){alert('Complete la configuración hasta obtener un código válido.');return null;}
  const tipo=normalizarTextoPulsadorV179(document.getElementById(pref+'tipo')?.value||'');
  const esMetal=modelo==='METAL';
  let indicador_codigo='',indicador_modelo='';
  if(String(fam).endsWith('_IP')){const sel=document.getElementById(pref+'indicador_codigo');indicador_codigo=String(sel?.value||'').trim();indicador_modelo=String(sel?.selectedOptions?.[0]?.textContent||'').split(' · ')[0].trim();if(!indicador_codigo){alert('Seleccione el indicador exterior.');return null;}}
  const precio_unitario=Number(document.getElementById(pref+'precio_preview')?.dataset.unitario||0);
  const medida=String(document.getElementById(pref+'medida')?.value||'').trim();const acabado=String(document.getElementById(pref+'acabado')?.value||'ACERO').trim().toUpperCase();const medida_especial=!!document.getElementById(pref+'medida_especial')?.checked;
  return {familia:fam,cantidad,mismo_modelo:mismo?1:0,modelo,tipo,color:esMetal?'':normalizarTextoPulsadorV179(document.getElementById(pref+'color')?.value||''),tension:esMetal?'':normalizarTextoPulsadorV179(document.getElementById(pref+'tension')?.value||''),bornes:esMetal?'':normalizarTextoPulsadorV179(document.getElementById(pref+'bornes')?.value||''),tecla:esMetal?'':normalizarTextoPulsadorV179(document.getElementById(pref+'tecla')?.value||''),codigo,indicador_codigo,indicador_modelo,precio_unitario,medida,acabado,medida_especial:medida_especial?1:0};
}
function agregarPulsadorExteriorV179(fam){
  const modeloCab=document.getElementById('senal_modelo'),incluirCab=document.getElementById('senal_incluir_botonera_cabina');
  if(incluirCab && (!modeloCab || !String(modeloCab.value||'').trim())){incluirCab.checked=false;actualizarInclusionBotoneraCabinaV181(false);}
  const it=capturarItemPulsadorV188(fam);if(!it)return;itemsPulsadoresV188().push(it);delete window.__senalPulsEditV188[fam];guardarItemsPulsadoresV188(true);const qmap={SIMPLE:'senal_pulsadores_simples_cantidad',SIMPLE_IP:'senal_pulsadores_simples_indicador_cantidad',DOBLE:'senal_pulsadores_dobles_cantidad',DOBLE_IP:'senal_pulsadores_dobles_indicador_cantidad'};const q=document.getElementById(qmap[fam]);if(q)q.value='1';
}
function modificarPulsadorExteriorV179(fam){
  const idx=window.__senalPulsEditV188?.[fam];if(!Number.isInteger(idx)){alert('Seleccione Editar en una configuración agregada.');return;}
  const it=capturarItemPulsadorV188(fam);if(!it)return;itemsPulsadoresV188()[idx]=it;delete window.__senalPulsEditV188[fam];guardarItemsPulsadoresV188(true);
}
function quitarPulsadorExteriorV179(fam){
  const arr=itemsPulsadoresV188(),idx=window.__senalPulsEditV188?.[fam];const indices=arr.map((x,i)=>x.familia===fam?i:-1).filter(i=>i>=0);
  if(Number.isInteger(idx)){arr.splice(idx,1);delete window.__senalPulsEditV188[fam];guardarItemsPulsadoresV188(true);return;}
  if(indices.length===1){arr.splice(indices[0],1);guardarItemsPulsadoresV188(true);return;}
  if(indices.length>1){alert('Elegí Editar en el renglón que querés quitar.');return;}
}
function editarItemPulsadorV188(fam,idx){
  const it=itemsPulsadoresV188()[idx];if(!it||it.familia!==fam)return;window.__senalPulsEditV188[fam]=idx;const pref=prefPulsadorV179(fam);
  const mismo=document.getElementById(pref+'mismo_modelo');if(mismo)mismo.checked=!!Number(it.mismo_modelo);
  const mw=document.getElementById(pref+'modelo_wrap');if(mw)mw.style.display=mismo?.checked?'none':'';
  const ms=document.getElementById(pref+'modelo');if(ms){ms.dataset.valor=it.modelo||'';ms.value=it.modelo||'';}
  ['tipo','color','tension','bornes','tecla'].forEach(k=>{const e=document.getElementById(pref+k);if(e)e.dataset.valor=it[k]||'';});
  const qmap={SIMPLE:'senal_pulsadores_simples_cantidad',SIMPLE_IP:'senal_pulsadores_simples_indicador_cantidad',DOBLE:'senal_pulsadores_dobles_cantidad',DOBLE_IP:'senal_pulsadores_dobles_indicador_cantidad'};const q=document.getElementById(qmap[fam]);if(q)q.value=it.cantidad||0;
  const ind=document.getElementById(pref+'indicador_codigo');if(ind)ind.dataset.valor=it.indicador_codigo||'';
  const med=document.getElementById(pref+'medida');if(med)med.value=it.medida||'';const ac=document.getElementById(pref+'acabado');if(ac)ac.value=it.acabado||'ACERO';const me=document.getElementById(pref+'medida_especial');if(me)me.checked=!!it.medida_especial;
  refrescarPulsadorExteriorV179(fam,false);const card=document.getElementById(pref+'editor');card?.scrollIntoView({behavior:'smooth',block:'center'});
}
function eliminarItemPulsadorV188(idx){const arr=itemsPulsadoresV188();if(idx<0||idx>=arr.length)return;const fam=arr[idx].familia;arr.splice(idx,1);if(window.__senalPulsEditV188?.[fam]===idx)delete window.__senalPulsEditV188[fam];guardarItemsPulsadoresV188(true);}
function migrarLegacyPulsadoresV188(){
  const h=document.getElementById('senal_pulsadores_items_json');if(!h)return;const teniaGuardado=String(h.value||'').trim()!=='';itemsPulsadoresV188();if(teniaGuardado)return;
  ['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(fam=>{const pref=prefPulsadorV179(fam),a=document.getElementById(pref+'agregado');if(a?.value!=='1')return;const it=capturarItemPulsadorV188(fam);if(it)window.__senalPulsItemsV188.push(it);});
  guardarItemsPulsadoresV188(false);
}
function sincronizarPulsadoresExteriorConCabinaV179(){['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(f=>{const p=prefPulsadorV179(f);if(document.getElementById(p+'mismo_modelo')?.checked)refrescarPulsadorExteriorV179(f,false);});}
function sincronizarInclusionCabinaPorExteriorV183(){
  const modelo=document.getElementById('senal_modelo'),chk=document.getElementById('senal_incluir_botonera_cabina');if(!chk || (modelo && String(modelo.value||'').trim()))return;
  // v376: los indicadores sueltos tambien constituyen una cotizacion valida de Senalizacion
  // sin botonera de cabina. Antes solo los pulsadores desactivaban la exigencia de botonera,
  // por lo que el calculo automatico quedaba en PENDIENTE cuando se cotizaban solo indicadores.
  const hayIndicadores=(typeof itemsIndicadoresExteriorV190==='function' && itemsIndicadoresExteriorV190().length>0);
  const hay=hayIndicadores || itemsPulsadoresV188().length>0 || ['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].some(f=>document.getElementById(prefPulsadorV179(f)+'agregado')?.value==='1');
  if(hay&&chk.checked){chk.checked=false;actualizarInclusionBotoneraCabinaV181(false);}
}
document.addEventListener('DOMContentLoaded',function(){if(senalControlIncluidoV190())forzarSenalElectronicaConControlV190();else liberarSenalElectronicaSinControlV190();['SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'].forEach(f=>refrescarPulsadorExteriorV179(f,false));migrarLegacyPulsadoresV188();renderItemsPulsadoresV188();itemsIndicadoresExteriorV190();renderIndicadoresExteriorV190();filtrarIndicadoresExteriorControlV190();actualizarPrecioIndicadorExteriorV190();sincronizarInclusionCabinaPorExteriorV183();actualizarInclusionBotoneraCabinaV181(false);actualizarCantidadesSenalizacionExterior();});
/* v190 - Indicadores exteriores individuales: un combo, multiples renglones, cantidad y medida por item. */
function itemsIndicadoresExteriorV190(){
  if(Array.isArray(window.__senalIndicadoresExtV190))return window.__senalIndicadoresExtV190;
  const h=document.getElementById('senal_indicadores_exteriores_items_json');let arr=[];try{const x=JSON.parse(h?.value||'[]');if(Array.isArray(x))arr=x;}catch(_){arr=[];}
  window.__senalIndicadoresExtV190=arr;window.__senalIndicadorEditV190=null;return arr;
}
function guardarIndicadoresExteriorV190(recalcular=true){const h=document.getElementById('senal_indicadores_exteriores_items_json');if(h)h.value=JSON.stringify(itemsIndicadoresExteriorV190());renderIndicadoresExteriorV190();actualizarCantidadesSenalizacionExterior();if(recalcular)programarCalculoSenalizacion(80);}
function renderIndicadoresExteriorV190(){const c=document.getElementById('senal_indicadores_ext_items_list_v190');if(!c)return;const a=itemsIndicadoresExteriorV190();if(!a.length){c.innerHTML='<div style="padding:9px 11px;border:1px dashed #cbd8d1;border-radius:9px;color:#6b7785;font-size:11px">Todavía no hay indicadores agregados.</div>';return;}c.innerHTML='<div style="font-size:10px;font-weight:900;color:#526273;margin-bottom:6px;text-transform:uppercase">Indicadores agregados</div>'+a.map((it,i)=>'<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 10px;margin-top:5px;border:1px solid #cfe5d8;border-radius:9px;background:#f7fcf9"><div><strong style="display:block;color:#17324d;font-size:12px">'+escHtmlV188((it.cantidad||0)+' × '+(it.modelo||'')+' · '+(it.tipo||'')+' · Medida '+(String(it.medida||'').trim()||'A CONFIRMAR')+' · Tapa '+(it.acabado||'ACERO')+(it.medida_especial?' ESPECIAL':''))+'</strong><small style="color:#667085">Código técnico: '+escHtmlV188(it.codigo||'A RESOLVER')+(Number(it.precio_unitario||0)>0?' · Unitario: '+formatearPrecioPulsadorV182(it.precio_unitario):'')+'</small></div><div style="display:flex;gap:5px"><button type="button" onclick="editarIndicadorExteriorV190('+i+')">Editar</button><button type="button" onclick="eliminarIndicadorExteriorV190('+i+')">Quitar</button></div></div>').join('');}
async function actualizarPrecioIndicadorExteriorV190(){const sel=document.getElementById('senal_indicador_ext_modelo_v190'),out=document.getElementById('senal_indicador_ext_precio_v190'),det=document.getElementById('senal_indicador_ext_precio_detalle_v190');if(!out)return;out.dataset.unitario='0';const codigo=String(sel?.value||'').trim();if(!codigo){out.textContent='A RESOLVER';if(det)det.textContent='Antes de descuentos de Señalización';return;}out.textContent='Consultando...';try{const fd=new FormData();fd.append('codigo',codigo);fd.append('lista_id',String(document.getElementById('lista_id')?.value||''));const r=await fetch('precio_pulsador_exterior.php',{method:'POST',body:fd});const j=await r.json();if(!j.ok)throw new Error(j.error||'Sin precio');const acabado=String(document.getElementById('senal_indicador_ext_acabado_v190')?.value||'ACERO'),especial=!!document.getElementById('senal_indicador_ext_medida_especial_v190')?.checked,ap=aplicarCoefPreviewV190(Number(j.unitario||0),acabado,especial);if(!ap.ok){out.textContent='COEFICIENTE A DEFINIR';out.dataset.unitario='0';if(det)det.textContent='Acabado '+acabado+(especial?' · medida especial':'')+' sin coeficiente definido';return;}out.textContent=formatearPrecioPulsadorV182(ap.total);out.dataset.unitario=String(ap.total||0);if(det)det.textContent='Código '+codigo+' · '+acabado+(especial?' especial':'')+' × '+ap.coef.toLocaleString('es-AR')+' · antes de descuentos';}catch(e){out.textContent='SIN PRECIO BEJERMAN';if(det)det.textContent=e.message||'Sin precio válido';}}
function capturarIndicadorExteriorV190(){const sel=document.getElementById('senal_indicador_ext_modelo_v190');const op=sel?.selectedOptions?.[0];const codigo=String(sel?.value||'').trim();if(!codigo||!op){alert('Seleccione un modelo de indicador.');return null;}const cantidad=parseInt(document.getElementById('senal_indicador_ext_cantidad_v190')?.value||'0',10)||0;if(cantidad<=0){alert('Ingrese una cantidad mayor que 0.');return null;}const modelo=String(op.dataset.modelo||'').trim(),tipo=String(op.dataset.tipo||'').trim(),medida=String(document.getElementById('senal_indicador_ext_medida_v190')?.value||'').trim(),acabado=String(document.getElementById('senal_indicador_ext_acabado_v190')?.value||'ACERO').trim().toUpperCase(),medida_especial=!!document.getElementById('senal_indicador_ext_medida_especial_v190')?.checked,precio_unitario=Number(document.getElementById('senal_indicador_ext_precio_v190')?.dataset.unitario||0);return {codigo,modelo,tipo,cantidad,medida,acabado,medida_especial:medida_especial?1:0,precio_unitario};}
function agregarIndicadorExteriorV190(){const it=capturarIndicadorExteriorV190();if(!it)return;const modeloCab=document.getElementById('senal_modelo'),incluirCab=document.getElementById('senal_incluir_botonera_cabina');if(incluirCab&&(!modeloCab||!String(modeloCab.value||'').trim())){incluirCab.checked=false;actualizarInclusionBotoneraCabinaV181(false);}itemsIndicadoresExteriorV190().push(it);window.__senalIndicadorEditV190=null;guardarIndicadoresExteriorV190(true);sincronizarInclusionCabinaPorExteriorV183();const q=document.getElementById('senal_indicador_ext_cantidad_v190');if(q)q.value='1';const m=document.getElementById('senal_indicador_ext_medida_v190');if(m)m.value='';}
function editarIndicadorExteriorV190(i){const it=itemsIndicadoresExteriorV190()[i];if(!it)return;window.__senalIndicadorEditV190=i;const sel=document.getElementById('senal_indicador_ext_modelo_v190');if(sel)sel.value=it.codigo||'';const q=document.getElementById('senal_indicador_ext_cantidad_v190');if(q)q.value=it.cantidad||1;const m=document.getElementById('senal_indicador_ext_medida_v190');if(m)m.value=it.medida||'';const ac=document.getElementById('senal_indicador_ext_acabado_v190');if(ac)ac.value=it.acabado||'ACERO';const me=document.getElementById('senal_indicador_ext_medida_especial_v190');if(me)me.checked=!!it.medida_especial;actualizarPrecioIndicadorExteriorV190();document.getElementById('senal_indicador_ext_editor_v190')?.scrollIntoView({behavior:'smooth',block:'center'});}
function modificarIndicadorExteriorV190(){const i=window.__senalIndicadorEditV190;if(!Number.isInteger(i)){alert('Seleccione Editar en un indicador agregado.');return;}const it=capturarIndicadorExteriorV190();if(!it)return;itemsIndicadoresExteriorV190()[i]=it;window.__senalIndicadorEditV190=null;sincronizarInclusionCabinaPorExteriorV183();guardarIndicadoresExteriorV190(true);}
function eliminarIndicadorExteriorV190(i){const a=itemsIndicadoresExteriorV190();if(i<0||i>=a.length)return;a.splice(i,1);window.__senalIndicadorEditV190=null;guardarIndicadoresExteriorV190(true);}
function abrirSeccionSenal(idSeccion){
  const seccion=document.getElementById(idSeccion); if(!seccion) return;
  const cabecera=seccion.querySelector('.senal-seccion-titulo');
  if(seccion.classList.contains('senal-acordeon-cerrado') && cabecera){
    seccion.classList.remove('senal-acordeon-cerrado');
    cabecera.classList.remove('is-collapsed');
    cabecera.setAttribute('aria-expanded','true');
  }
}
function toggleSeccionSenalPorId(idSeccion){
  const seccion=document.getElementById(idSeccion); if(!seccion) return;
  const cabecera=seccion.querySelector('.senal-seccion-titulo');
  const cuerpo=seccion.querySelector('.senal-seccion-cuerpo');
  if(cabecera && cuerpo) toggleSeccionSenal(cabecera,cuerpo.id);
}
function toggleAccesoriosTab(idPanel,boton){
  const panel=document.getElementById(idPanel); if(!panel||!boton) return;
  const estabaActivo=panel.classList.contains('activo');
  document.querySelectorAll('.accesorios-tab-panel').forEach(function(p){p.classList.remove('activo');});
  document.querySelectorAll('.accesorios-tab-btn').forEach(function(b){b.classList.remove('activo');b.setAttribute('aria-expanded','false');});
  if(!estabaActivo){panel.classList.add('activo');boton.classList.add('activo');boton.setAttribute('aria-expanded','true');}
}

document.addEventListener('DOMContentLoaded',function(){
  const toggleNav=document.getElementById('toggle_navegacion_cotizacion');
  if(toggleNav) toggleNav.addEventListener('click',alternarNavegacionCotizacion);
  try{
    const guardadoNav=localStorage.getItem('automac_nav_cotizacion_oculta');
    setNavegacionCotizacionOculta(guardadoNav===null ? true : guardadoNav==='1',false);
  }catch(e){ setNavegacionCotizacionOculta(true,false); }
  const parametrosInicio = new URLSearchParams(window.location.search);
  const inicioNueva = parametrosInicio.get('inicio_nueva') === '1' || parametrosInicio.get('nueva') === '1';
  if(inicioNueva){
    try{
      seleccionarModuloFlujo('control');
      setNavegacionCotizacionOculta(false,false);
      ['control','senalizacion','accesorios','iep','repuestos'].forEach(function(m){ cerrarContenedoresModulo(m); });
      posicionarModuloArriba('control','auto');
    }catch(e){}
    window.scrollTo({top:0,left:0,behavior:'auto'});
    const cliente=document.getElementById('id_cliente');
    if(cliente){
      setTimeout(function(){
        window.scrollTo({top:0,left:0,behavior:'auto'});
        const buscador=document.getElementById('cliente_busqueda');
        if(buscador){try{ buscador.focus({preventScroll:true}); }catch(e){ buscador.focus(); }}
      },60);
    }
    if(window.history && window.history.replaceState){
      window.history.replaceState({},document.title,'index.php');
    }
  }

  const form=document.getElementById('form_senalizacion');
  if(form){form.addEventListener('input',actualizarResumenRapidoSenalizacion);form.addEventListener('change',actualizarResumenRapidoSenalizacion);actualizarResumenRapidoSenalizacion();}
  const control=document.getElementById('incluir_control'); if(control)control.addEventListener('change',function(){if(control.checked)forzarSenalElectronicaConControlV190();else liberarSenalElectronicaSinControlV190();sincronizarDatosSenalizacion(true);actualizarResumenRapidoSenalizacion();});
  sincronizarComunicacionSerieControlSenalizacion();
  const formControl=document.getElementById('form_cotizador'); if(formControl){formControl.addEventListener('input',actualizarResumenRapidoControl);formControl.addEventListener('change',actualizarResumenRapidoControl);actualizarResumenRapidoControl();}
  actualizarAperturasOperadoresV172();
  document.querySelectorAll('.control-pasos a').forEach(function(enlace){enlace.addEventListener('click',function(ev){ev.preventDefault();const id=(this.getAttribute('href')||'').replace('#','');if(id)toggleControlSeccionPorId(id);});});
  document.querySelectorAll('.senal-pasos a').forEach(function(enlace){enlace.addEventListener('click',function(ev){ev.preventDefault();const id=(this.getAttribute('href')||'').replace('#','');if(id)toggleSeccionSenalPorId(id);});});
});
</script>

















<script id="automac-v329-desglose-js">
(function(){
  window.detallesCalculoModulo=window.detallesCalculoModulo||{};
  function asegurarDrawer(){
    let ov=document.getElementById('v329_desglose_overlay');
    if(ov)return ov;
    ov=document.createElement('div');ov.id='v329_desglose_overlay';ov.className='v329-desglose-overlay';
    ov.innerHTML='<div class="v329-desglose-drawer" role="dialog" aria-modal="true"><div class="v329-desglose-head"><div><strong id="v329_desglose_titulo">Desglose</strong><small>Detalle del último cálculo automático</small></div><button type="button" class="v329-desglose-cerrar" aria-label="Cerrar">×</button></div><div class="v329-desglose-body" id="v329_desglose_body"></div></div>';
    document.body.appendChild(ov);
    ov.querySelector('.v329-desglose-cerrar').addEventListener('click',()=>ov.classList.remove('abierto'));
    ov.addEventListener('click',e=>{if(e.target===ov)ov.classList.remove('abierto')});
    document.addEventListener('keydown',e=>{if(e.key==='Escape')ov.classList.remove('abierto')});
    return ov;
  }
  function abrir(modulo){
    const ov=asegurarDrawer(), body=document.getElementById('v329_desglose_body'), tit=document.getElementById('v329_desglose_titulo');
    const nombres={control:'Control',senalizacion:'Señalización',accesorios:'Accesorios'};
    const nombre=nombres[modulo]||'Módulo';
    tit.textContent='Desglose de '+nombre;
    body.innerHTML='';

    if(modulo==='accesorios'){
      if(typeof actualizarDesgloseAccesorios==='function') actualizarDesgloseAccesorios();
      const origen=document.getElementById('panel_desglose_accesorios');
      if(origen){
        const copia=origen.cloneNode(true);
        copia.removeAttribute('id');
        copia.style.display='flex';
        copia.style.flexDirection='column';
        copia.style.height='100%';
        copia.style.overflow='auto';
        body.appendChild(copia);
      }else{
        body.innerHTML='<div class="v329-desglose-vacio"><div><strong>Aún no hay accesorios calculados.</strong><br>Agregá accesorios para generar el desglose.</div></div>';
      }
      ov.classList.add('abierto');
      return;
    }

    const html=(window.detallesCalculoModulo||{})[modulo]||'';
    if(!html){body.innerHTML='<div class="v329-desglose-vacio"><div><strong>Aún no hay un cálculo disponible.</strong><br>Completá los datos del módulo y el cálculo automático generará el desglose.</div></div>'}
    else {const fr=document.createElement('iframe');fr.className='v329-desglose-frame';fr.title='Desglose de '+nombre;fr.srcdoc=html;body.appendChild(fr)}
    ov.classList.add('abierto');
  }
  document.addEventListener('click',function(e){const b=e.target.closest('[data-v329-desglose]');if(!b)return;e.preventDefault();e.stopPropagation();abrir(b.dataset.v329Desglose)},true);
})();
</script>

























<!-- AUTOMAC v365: tipo de control para Senalizacion -->



<!-- AUTOMAC v392 - interfaz visual maxima, mantiene toda la logica -->







</head>
<body class="<?= ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar') ? 'v15-workbench ui-v310 ' : '' ?>ui-v150 ui-v453 ui-v129 ui-v130 ui-v131 ui-v133 ui-v134 ui-v135 ui-v136 ui-v137 ui-v138 ui-v139 ui-v143 ui-v144 ui-v145 ui-v146 ui-v147 ui-v148 ui-v149 ui-v152 ui-v153 ui-v154 ui-v155 ui-v156 ui-v157 ui-v158 ui-v159 ui-v160 ui-v161 ui-v162 ui-v163 ui-v164 ui-v165 ui-v166 ui-v167 ui-v169 ui-v170 ui-v171 ui-v173 ui-v174 ui-v182 ui-v183 ui-v187 ui-v188 ui-v190 ui-v193 ui-v195 ui-v199 ui-v201 ui-v219 ui-v220">
<div class="contenedor-pagina">
<?php require __DIR__ . '/menu.php'; ?>
<?php require __DIR__ . '/comercial_alertas_usuario.php'; ?>
<div class="cotizador-layout panel-presupuesto-oculto<?= ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar') ? ' con-navegacion-lateral' : '' ?>" id="cotizador_layout">
    <div id="v15_cabecera" class="v15-titulo-linea"><div><h2 class="cotizador-titulo-principal"><?= ($modoPlantilla === 'nueva' || $modoPlantilla === 'editar') ? 'Plantilla de control' : ($pedidoEdicionId > 0 ? 'Modificar pedido ' . escapar($pedidoEdicion['pedido_numero']) : ($cotizacionEdicionId > 0 ? 'Modificar cotización ' . escapar($cotizacionEdicion['cotizacion_numero']) : 'Nueva cotización')) ?></h2><p class="cotizador-subtitulo">Una plantilla para empezar. Sólo los ajustes de esta obra para cotizar.</p></div><span class="v15-badge">V1.5 &middot; Plantillas</span></div>
<?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
    <section id="datos_generales" class="cabecera-cotizacion" aria-label="Datos generales del documento">
        <div class="cabecera-cotizacion-titulo">
            <h3><?= $pedidoEdicionId > 0 ? 'Datos generales del pedido' : 'Datos generales de la cotización' ?></h3>
        </div>
        <div class="cabecera-cotizacion-grid">
            <div id="aviso_cliente_obligatorio" class="aviso-cliente-obligatorio" role="alert" aria-live="assertive">
                <span aria-hidden="true" style="font-size:20px;line-height:1">⚠</span>
                <div><strong>Seleccione un cliente para continuar</strong>La cotización o el pedido no se emitirán hasta elegir un cliente. La configuración y los repuestos cargados permanecen en pantalla.</div>
            </div>
            <div class="campo">
                <label for="cliente_busqueda">Cliente: <span style="color:#dc3545">*</span></label>
                <?php
                $clienteSeleccionadoId = (int)($datos['id_cliente'] ?? 0);
                $clienteSeleccionadoTexto = '';
                $clientesBusqueda = array();
                $resultado = $conexion->query("SELECT c.clientes_id, c.clientes_codigo, c.clientes_nomfantasia, c.clientes_numero_bejerman, c.clientes_socio_cecaf_numero FROM clientes c WHERE c.clientes_habilitado = 'SI' ORDER BY c.clientes_nomfantasia, c.clientes_codigo");
                if ($resultado) {
                    while ($fila = $resultado->fetch_assoc()) {
                        $nombreVisible = nombrePropioCliente($fila['clientes_nomfantasia']);
                        if ($nombreVisible === '') $nombreVisible = nombrePropioCliente($fila['clientes_codigo']);
                        $textoBusqueda = trim($nombreVisible . ' ' . (string)$fila['clientes_nomfantasia'] . ' ' . (string)$fila['clientes_codigo'] . ' ' . (string)($fila['clientes_numero_bejerman'] ?? ''));
                        $clientesBusqueda[] = array(
                            'id'=>(int)$fila['clientes_id'],
                            'nombre'=>$nombreVisible,
                            'busqueda'=>$textoBusqueda
                        );
                        if ((int)$fila['clientes_id'] === $clienteSeleccionadoId) $clienteSeleccionadoTexto = $nombreVisible;
                    }
                }
                ?>
                <div class="cliente-buscador">
                    <input type="search" id="cliente_busqueda" autocomplete="off" value="<?= escapar($clienteSeleccionadoTexto) ?>" placeholder="Escriba nombre, sigla o Nº Bejerman" oninput="filtrarClientesCotizador()" onfocus="if(this.value.trim()) filtrarClientesCotizador()" onblur="setTimeout(function(){const r=document.getElementById('cliente_resultados');if(r)r.classList.remove('visible');},180)" required>
                    <input type="hidden" name="id_cliente" id="id_cliente" form="form_cotizador" value="<?= $clienteSeleccionadoId > 0 ? $clienteSeleccionadoId : '' ?>">
                    <div id="cliente_resultados" class="cliente-resultados"></div>
                    <script>window.clientesBusquedaCotizador = <?= json_encode($clientesBusqueda, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;</script>
                    <div class="cliente-ayuda">Puede buscar por nombre de fantasía, sigla o número Bejerman. Al seleccionar se muestra solo el nombre.</div>
                    <div id="cliente_cecaf_aviso" class="cliente-cecaf-aviso" aria-live="polite"></div>
                    <div id="cliente_especificaciones_aviso" class="cliente-especificaciones-aviso" aria-live="polite" style="display:none">
                        <div><strong id="cliente_especificaciones_aviso_titulo">⚠ Este cliente tiene especificaciones técnicas</strong><span id="cliente_especificaciones_aviso_texto"></span></div>
                        <button type="button" onclick="abrirEspecificacionesTecnicasCliente()">Ver especificaciones</button>
                    </div>
                </div>
            </div>
            <div class="campo">
                <label for="solicitante_cliente">Solicitante / contacto del cliente:</label>
                <input type="text" name="solicitante_cliente" id="solicitante_cliente" form="form_cotizador" maxlength="100"
                       value="<?= escapar((string)($datos['solicitante_cliente'] ?? '')) ?>"
                       placeholder="Ej.: Ernesto">
                <div class="cliente-ayuda">Dato opcional. Se usa en documentos comerciales y administrativos cuando se informa; no se envía a las OF.</div>
            </div>
            <div class="campo">
                <label for="referencia_cotizacion">Referencia:</label>
                <input type="text" name="referencia_cotizacion" id="referencia_cotizacion" form="form_cotizador" maxlength="150"
                       value="<?= escapar((string)($datos['referencia_cotizacion'] ?? '')) ?>"
                       placeholder="Ej.: Edificio Corrientes 1234"<?= $pedidoEdicionId > 0 ? ' readonly title="El número de obra o referencia no se modifica al revisar un pedido"' : '' ?>>
            </div>
            <div class="campo lista-general fuente-bejerman">
                <input type="hidden" name="modo_precios_documento" id="modo_precios_documento" form="form_cotizador" value="<?= escapar($modoPreciosDocumento) ?>">
                <label for="lista_id">Base de precios Bejerman</label>
                <?php if (!empty($listasBejermanDisponibles)): ?>
                    <select name="lista_id" id="lista_id" form="form_cotizador" onchange="cambiarBaseBejerman(this)">
                        <?php foreach ($listasBejermanDisponibles as $listaDisponible): ?>
                            <?php $idListaDisponible=(int)($listaDisponible['lista_id'] ?? 0); ?>
                            <option value="<?= $idListaDisponible ?>"<?= $idListaDisponible===(int)($listaPrecioSeleccionada['lista_id'] ?? 0)?' selected':'' ?>>
                                <?= escapar(descripcionBaseBejerman($listaDisponible)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="bejerman-activa" id="detalle_base_bejerman"><span class="estado-punto"></span><div><strong><?= $baseDocumentoEsHistorica && $modoPreciosDocumento==='MANTENER' ? 'Base histórica seleccionada' : 'Base seleccionada' ?></strong><small><?= escapar(descripcionBaseBejerman($listaPrecioSeleccionada)) ?></small></div></div>
                    <?php if ($baseDocumentoEsHistorica): ?>
                    <div class="aviso-base-historica">
                        <strong>Este documento fue emitido con una base anterior.</strong>
                        <label><input type="radio" name="selector_modo_precios" value="MANTENER" <?= $modoPreciosDocumento==='MANTENER'?'checked':'' ?> onchange="seleccionarModoPreciosDocumento('MANTENER',<?= (int)$listaDocumentoId ?>)"> Mantener los precios enviados al cliente</label>
                        <label><input type="radio" name="selector_modo_precios" value="ACTUALIZAR" <?= $modoPreciosDocumento==='ACTUALIZAR'?'checked':'' ?> onchange="seleccionarModoPreciosDocumento('ACTUALIZAR',<?= (int)($listaVigente['lista_id']??0) ?>)"> Actualizar con <?= escapar(descripcionBaseBejerman($listaVigente)) ?></label>
                    </div>
                    <?php endif; ?>
                <?php else: ?>
                    <select name="lista_id" id="lista_id" form="form_cotizador" disabled><option value="">Sin bases disponibles</option></select>
                    <div class="bejerman-faltante">No hay una base Bejerman disponible. Un administrador debe cargarla antes de cotizar.</div>
                <?php endif; ?>
                <div class="ayuda">Podés seleccionar la actualización que corresponda. Cada documento conserva la base y los precios exactos utilizados al emitirlo.</div>
            </div>
        </div>
    </section>
<div id="revision_editor_slot" class="revision-editor-slot" aria-live="polite"></div>
<div id="navegacion_cotizacion" class="navegacion-cotizacion navegacion-cotizacion-superior" aria-label="Áreas de la cotización">
    <nav class="navegacion-cotizacion-lista" role="tablist" aria-label="Módulos de cotización">
        <button type="button" class="cotizador-modulo-btn nav-modulo activo" data-modulo="control" role="tab" aria-selected="true" title="1 · Control y equipos" onclick="return alternarModuloDesdeNavegacion('control')"><span class="nav-numero">1</span><span class="nav-contenido"><span class="nav-texto">Control</span><span class="nav-descripcion">Control + adicionales</span><span class="nav-estado" data-nav-estado>Incluido · pendiente</span></span></button>
        <button type="button" class="cotizador-modulo-btn nav-modulo" data-modulo="senalizacion" role="tab" aria-selected="false" title="2 · Señalización y botoneras" onclick="return alternarModuloDesdeNavegacion('senalizacion')"><span class="nav-numero">2</span><span class="nav-contenido"><span class="nav-texto">Señalización</span><span class="nav-descripcion">Señalización + adicionales</span><span class="nav-estado" data-nav-estado>Opcional · no incluido</span></span></button>
        <button type="button" class="cotizador-modulo-btn nav-modulo" data-modulo="accesorios" role="tab" aria-selected="false" title="3 · Accesorios" onclick="return alternarModuloDesdeNavegacion('accesorios')"><span class="nav-numero">3</span><span class="nav-contenido"><span class="nav-texto">Accesorios</span><span class="nav-descripcion">Opcionales y complementos</span><span class="nav-estado" data-nav-estado>Opcional · no incluido</span></span></button>
        <button type="button" class="cotizador-modulo-btn nav-modulo" data-modulo="iep" role="tab" aria-selected="false" title="4 · IEP" onclick="return alternarModuloDesdeNavegacion('iep')"><span class="nav-numero">4</span><span class="nav-contenido"><span class="nav-texto">IEP</span><span class="nav-descripcion">Pendiente de diseño</span><span class="nav-estado" data-nav-estado>Opcional · no incluido</span></span></button>
        <button type="button" class="cotizador-modulo-btn nav-modulo nav-repuestos" data-modulo="repuestos" role="tab" aria-selected="false" title="5 · Repuestos" onclick="return alternarModuloDesdeNavegacion('repuestos')"><span class="nav-numero">5</span><span class="nav-contenido"><span class="nav-texto">Repuestos</span><span class="nav-descripcion">Cotización de repuestos</span><span class="nav-estado" data-nav-estado>Opcional · no incluido</span></span></button>
    </nav>
    <div class="v146-nav-actions" aria-label="Acciones de la cotización">
      <button type="button" id="nav_toggle_resumen" class="v146-btn-summary" aria-controls="panel_calculo_cotizador" aria-expanded="false">Ver resumen</button>
    </div>
</div>
<div class="v15-estado-global" aria-live="polite">
  <div><strong>Flujo simple:</strong> plantilla → ajuste → cálculo → resumen.</div>
  <div class="v15-estado-leyenda"><span class="v15-dot v15-ok"></span>Calculado <span class="v15-dot v15-pending"></span>Pendiente <span class="v15-dot v15-error"></span>Sin precio <span class="v15-dot v15-off"></span>No incluido</div>
</div>
<?php endif; ?>
<div class="form-container">
    <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
    <?php endif; ?>
    <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
    <div class="cotizador-contexto-actual" id="cotizador_contexto_actual" aria-live="polite">
      <div class="cotizador-contexto-icono" aria-hidden="true">●</div>
      <div class="cotizador-contexto-texto"><strong id="cotizador_contexto_titulo">Estás cotizando: Control</strong><span id="cotizador_contexto_ayuda">Configurá CPU, maniobra, equipos, paradas y adicionales. El importe se calcula automáticamente.</span></div>
      <div class="cotizador-contexto-leyenda"><span class="estado-mini calculado">Calculado</span><span class="estado-mini pendiente">Pendiente</span><span class="estado-mini opcional">No incluido</span></div>
    </div>
    <?php endif; ?>
    <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
    <div class="flujo-cotizacion" aria-label="Flujo de cotización">
        <div class="flujo-paso activo"><span>1</span>Datos y base de precios</div>
        <div class="flujo-paso"><span>2</span>Productos y configuración</div>
        <div class="flujo-paso"><span>3</span>Revisar y emitir</div>
    </div>
    <?php endif; ?>
    <?php require __DIR__ . '/modules/cotizador_flujo.php'; ?>

    <?php require __DIR__ . '/modules/cotizador_control.php'; ?>

    <?php if ($modoPlantilla !== 'nueva' && $modoPlantilla !== 'editar'): ?>
    <?php require __DIR__ . '/modules/cotizador_senalizacion.php'; ?>

    <?php require __DIR__ . '/modules/cotizador_modulos_adicionales.php'; ?>
    <?php endif; ?>

</div>
<?php require __DIR__ . '/modules/cotizador_resumen_emision.php'; ?>

</div>
<script>

document.addEventListener('DOMContentLoaded',function(){
  // v161: cantidades iniciales de Señalización siguen a Control solo mientras no sean manuales.
  if(senalCantidadesInicialesNuevaV161){
    sincronizarCantidadesInicialesSenalizacionV161();
    const cantEq=document.getElementById('cantidad_equipos');
    const incCtrl=document.getElementById('incluir_control');
    if(cantEq){
      cantEq.addEventListener('input',sincronizarCantidadesInicialesSenalizacionV161);
      cantEq.addEventListener('change',sincronizarCantidadesInicialesSenalizacionV161);
    }
    if(incCtrl) incCtrl.addEventListener('change',sincronizarCantidadesInicialesSenalizacionV161);
  }
  ['id_cliente','lista_id','referencia_cotizacion','id_ptacabina'].forEach(function(id){
    const e=document.getElementById(id); if(e){e.addEventListener('change',actualizarResumenComunSenalizacion);e.addEventListener('input',actualizarResumenComunSenalizacion);}
  });
  actualizarResumenComunSenalizacion();
  actualizarBornesSenalizacion();
  actualizarTensionSenalizacion();
  const incluirInicial=document.getElementById('senal_incluir_cotizacion');
  establecerInclusionSenalizacion(!!(incluirInicial && incluirInicial.checked));
  // En edición no lanzamos Señalización en paralelo con Control al abrir la página:
  // los subtotales guardados ya reconstruyen el resumen y evitamos que ambos cálculos compitan por el mismo iframe.
  const editandoDocumentoAlCargar = <?= ($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) ? 'true' : 'false' ?>;
  const moduloActivoAlCargar = document.querySelector('.cotizador-modulo-btn.activo')?.dataset.modulo || '';
  if(!editandoDocumentoAlCargar || moduloActivoAlCargar==='senalizacion') programarCalculoSenalizacion(250);
  const control=document.getElementById('incluir_control');if(control)control.addEventListener('change',actualizarResumenDocumento);
  const incluirSenal=document.getElementById('senal_incluir_cotizacion');
  if(incluirSenal)incluirSenal.addEventListener('change',function(){
    if(!this.checked)window.subtotalesDocumento.senalizacion=0;
    else programarCalculoSenalizacion(80);
    actualizarResumenDocumento();
  });
  ['accesorios','iep','repuestos'].forEach(function(m){
    const incluir=document.getElementById('incluir_'+m);
    if(incluir)incluir.addEventListener('change',function(){actualizarEstadoModuloLibre(m,this.checked);});
    document.getElementById('items_'+m)?.addEventListener('input',function(){if(moduloIncluido(m))actualizarResumenDocumento();});
  });

  // v75: incluir automaticamente un modulo cuando el usuario comienza a editarlo.
  // Los eventos programaticos de inicializacion no lo activan. Si el usuario destilda
  // manualmente la inclusion, se respeta esa decision durante la edicion actual.
  const inclusionesPorModulo={control:'incluir_control',senalizacion:'senal_incluir_cotizacion',accesorios:'incluir_accesorios',iep:'incluir_iep',repuestos:'incluir_repuestos'};
  Object.entries(inclusionesPorModulo).forEach(function([modulo,idCheckbox]){
    const contenedor=document.getElementById('modulo_'+modulo);
    const checkbox=document.getElementById(idCheckbox);
    if(!contenedor||!checkbox)return;
    checkbox.addEventListener('change',function(e){
      if(e.isTrusted)checkbox.dataset.autoInclusionUsada='1';
      if(modulo==='control' && document.getElementById('limite_accesorio_select')?.value){
        // Limites puede recalcular su cantidad desde Control, pero no debe
        // agregarse ni incluir Accesorios hasta la confirmacion expresa del usuario.
        if(typeof programarCantidadLimites==='function') programarCantidadLimites(80);
      }
    });
    const comenzarEdicion=function(e){
      if(!e.isTrusted)return;
      const t=e.target;
      if(!t||t===checkbox||t.closest?.('.modulo-inclusion')||t.disabled||t.readOnly)return;
      if(modulo==='repuestos'&&(t.closest?.('.repuestos-filtros')||t.id==='buscar_repuesto'||t.id==='categoria_repuesto'))return;
      incluirModuloAlComenzarEdicion(modulo);
    };
    contenedor.addEventListener('input',comenzarEdicion);
    contenedor.addEventListener('change',comenzarEdicion);
  });
  actualizarDescuentosAccesorios();
  const formularioCotizador=document.getElementById('form_cotizador');
  if(formularioCotizador){
    const afectaLimites=function(e){
      const n=e.target?.name||''; const id=e.target?.id||'';
      // v465: Limites depende de la configuracion final de Control. No depender solo
      // de una lista corta de campos: cualquier cambio tecnico dentro de Control
      // vuelve a programar el calculo, con debounce para no multiplicar requests.
      if(e.target?.closest?.('#modulo_control') || ['id_tipo_control','id_subtipo','cantidad_equipos','velocidad_vf','agregar_contactorpot','retorno_bateria_gel'].includes(n)||['id_tipo_control','id_subtipo','cantidad_equipos','velocidad_vf'].includes(id)) programarCantidadLimites();
      if(id==='velocidad_vf'||n==='velocidad_vf') actualizarEncoderObligatorioPorVelocidad();
      if((id==='encoder_motor'||n==='encoder') && e.target?.dataset?.encoderIncompatible==='1'){e.target.checked=false;}
      if(['id_cpu','id_tipo_control','id_subtipo','id_tension','potencia_hp','encoder_motor'].includes(id)||['id_cpu','id_tipo_control','id_subtipo','id_tension','potencia_hp','encoder'].includes(n)) actualizarCorrienteVariadorV33();
      if(id==='id_comunicacion_serie'||n==='id_comunicacion_serie'||id==='cantidad_equipos'||n==='cantidad_equipos'||n==='paradas_equipo[]'){sincronizarDatosSenalizacion(true);programarCalculoSenalizacion(80);}
      if(['senal_tipo_puerta','senal_indicador_modelo'].includes(id)||n==='senal_tipo_puerta'||n==='senal_indicador_modelo'||(e.target.closest&&e.target.closest('[data-senal-adicional="LUZ DE EMERGENCIA"]'))) actualizarEspecialesAccesorios();
    };
    formularioCotizador.addEventListener('input',afectaLimites);
    formularioCotizador.addEventListener('change',afectaLimites);
  }
  actualizarEncoderObligatorioPorVelocidad();actualizarVelocidad();actualizarCorrienteVariadorV33();actualizarEspecialesAccesorios();
  // v465: el modelo de limite es unico y esta oculto, por lo que no existe ya un
  // cambio de combo que dispare el primer calculo. Inicializarlo al cargar si Control esta incluido.
  if(document.getElementById('limite_accesorio_select')?.value && moduloIncluido('control')) programarCantidadLimites(120);
  document.querySelectorAll('.repuesto-agregado').forEach(actualizarTotalRepuesto);document.querySelectorAll('.repuesto-manual [data-campo="cantidad"]').forEach(actualizarTotalManualRepuesto);actualizarResumenDocumento();
  // En edición, los subtotales CONTROL/SENALIZACION ya vienen del detalle guardado.
  // Así Accesorios o IEP pueden ser la pestaña visible sin borrar los cálculos de los módulos previos.
  if(<?= ($cotizacionEdicionId > 0 || $pedidoEdicionId > 0) ? 'true' : 'false' ?>){
    actualizarResumenDocumento();
  }
});



window.senalParadasGuardadasV122 = <?= json_encode(array_values((array)($datos['senal_paradas_equipo'] ?? array()))) ?>;
window.senalNomenclaturasGuardadasV123 = <?= json_encode(array_values((array)($datos['senal_nomenclatura_equipo'] ?? array()))) ?>;
window.senalMedidasGuardadasV125 = <?= json_encode(array_values((array)($datos['senal_medidas_equipo'] ?? array()))) ?>;
document.addEventListener('DOMContentLoaded',function(){ generarParadasSenalizacion(); });

document.addEventListener('DOMContentLoaded',function(){
  const fs=document.getElementById('form_senalizacion');
  if(fs){
    // v126: los textos tecnicos por coche (nomenclatura y medida) no alteran el precio.
    // No deben disparar el calculo auxiliar mientras el usuario escribe, porque la
    // sincronizacion de Senalizacion puede regenerar las fichas y hacer perder el foco.
    const esCampoTecnicoSinPrecio=function(target){
      if(!target || !target.name) return false;
      return target.name==='senal_nomenclatura_equipo[]' || target.name==='senal_medidas_equipo[]';
    };
    fs.addEventListener('input',function(ev){
      if(esCampoTecnicoSinPrecio(ev.target)) return;
      programarCalculoSenalizacion();
      actualizarEspecialesAccesorios();
    });
    fs.addEventListener('change',function(ev){
      if(esCampoTecnicoSinPrecio(ev.target)) return;
      programarCalculoSenalizacion(180);
      actualizarEspecialesAccesorios();
      if(ev.target.closest&&ev.target.closest('[data-senal-adicional="LUZ DE EMERGENCIA"]')){sincronizarAlarmaEmergenciaDesdeSenalizacionV203();sincronizarSirenaEcoMidi();}
    });
  }
  const usar=document.getElementById('senal_usar_control');
  if(usar) usar.addEventListener('change',function(){sincronizarDatosSenalizacion(true);generarParadasSenalizacion();programarCalculoSenalizacion(100);});
});
</script>
<script>
function factorDescuentosAccesorios(){
  const valor=id=>Math.max(0,Math.min(100,Number(document.getElementById(id)?.value||0)));
  return (1-valor('accesorio_descuento_1')/100)*(1-valor('accesorio_descuento_2')/100)*(1-valor('accesorio_descuento_3')/100);
}
function actualizarFilaAccesorio(fila){
  if(!fila)return;
  const inputBase=fila.querySelector('.accesorio-precio-base');
  const base=Number(inputBase?.value||fila.dataset.precioBase||0);
  fila.dataset.precioBase=String(base);
  const referencia=Math.ceil(base*factorDescuentosAccesorios());
  const bonificado=fila.dataset.bonificado==='1';
  const final=bonificado?0:referencia;
  fila.dataset.precioReferencia=String(referencia);
  const oculto=fila.querySelector('[data-campo="precio"]');
  const visible=fila.querySelector('.accesorio-precio-final');
  if(oculto)oculto.value=String(final);
  if(visible)visible.value=String(final);
}
function filasAccesorioPorClaveV15(clave){
  const k=String(clave||'');
  const norm=normalizarConfigurableV460(k);
  const out=[];
  document.querySelectorAll('#items_accesorios .item-modular, #items_especiales_accesorios_v33 .item-modular').forEach(function(f){
    if(f.dataset.seleccionado==='0' || f.style.display==='none') return;
    let fk=f.dataset.selectorKey||f.dataset.especialClave||f.dataset.manualKey||'';
    // Same catalog key used by the selected-accessories list.
    if(!fk){const card=f.closest('.accesorio-catalogo-card');if(card)fk=card.dataset.clave||'';}
    if(!fk && f.id==='fila_limite_accesorio') fk='LIMITES';
    if(normalizarConfigurableV460(fk)===norm || fk===k) out.push(f);
  });
  return out;
}
function toggleBonificacionAccesorioV15(clave){
  const filas=filasAccesorioPorClaveV15(clave);
  if(!filas.length)return;
  const bonificar=!filas.every(f=>f.dataset.bonificado==='1');
  filas.forEach(function(f){f.dataset.bonificado=bonificar?'1':'0';actualizarFilaAccesorio(f);});
  actualizarResumenDocumento();
  actualizarListaAccesoriosCompacta();
}
function actualizarPrecioAccesorioDesdeBase(input){
  const fila=input.closest('.accesorio-item'); if(!fila)return;
  fila.dataset.precioBase=String(Number(input.value||0));
  actualizarFilaAccesorio(fila); actualizarResumenDocumento();
}
function actualizarPrecioFinalAccesorioManual(input){
  const fila=input.closest('.accesorio-item'); if(!fila)return;
  const final=Math.ceil(Math.max(0,Number(input.value||0)));
  input.value=String(final);
  const factor=factorDescuentosAccesorios();
  const base=factor>0?final/factor:final;
  fila.dataset.precioBase=String(base);
  const inputBase=fila.querySelector('.accesorio-precio-base');
  const oculto=fila.querySelector('[data-campo="precio"]');
  if(inputBase)inputBase.value=base.toFixed(4);
  if(oculto)oculto.value=String(final);
  actualizarResumenDocumento();
}
function actualizarDescuentosAccesorios(){
  document.querySelectorAll('#items_accesorios .accesorio-item:not(.especial-auto)').forEach(actualizarFilaAccesorio);
  actualizarEspecialesAccesorios();
  actualizarResumenDocumento();
}
function toggleAccesorioCatalogo(check){
  const card=check.closest('.accesorio-catalogo-card'); if(!card)return;
  const fila=card.querySelector('.accesorio-catalogo-fila');
  const activo=!!check.checked;
  if(fila && !window.autoSeleccionandoV33){
    if(!activo && fila.dataset.autoV33==='1') fila.dataset.manualNoV33='1';
    if(activo) fila.dataset.manualNoV33='0';
  }
  card.classList.toggle('seleccionado',activo);
  if(fila){fila.dataset.seleccionado=activo?'1':'0';fila.style.display=activo?'grid':'none';if(activo)actualizarFilaAccesorio(fila);}
  if(activo){const inc=document.getElementById('incluir_accesorios');if(inc)inc.checked=true;}
  actualizarResumenDocumento();
}
function seleccionarLimiteAccesorio(){
  const sel=document.getElementById('limite_accesorio_select'); const fila=document.getElementById('fila_limite_accesorio');
  if(!sel||!fila)return; const op=sel.options[sel.selectedIndex]; const activo=!!sel.value; fila.style.display=activo?'grid':'none';
  const previewLimite=activo && window.accesorioConfigPendienteV460==='LIMITES' && fila.dataset.seleccionado!=='1';
  fila.dataset.seleccionado=activo&&!previewLimite?'1':'0';
  fila.dataset.v460Preview=previewLimite?'1':'0';
  fila.querySelector('[data-campo="codigo"]').value=activo?sel.value:'';
  fila.querySelector('[data-campo="descripcion"]').value=activo?(op.dataset.descripcion||op.dataset.nombre||''):'';
  const base=activo?Number(op.dataset.precioBase||0):0;
  fila.dataset.precioBase=String(base);
  const inputBase=fila.querySelector('.accesorio-precio-base'); if(inputBase)inputBase.value=base.toFixed(2);
  actualizarFilaAccesorio(fila);
  const resumenCantidad=document.getElementById('cantidad_limites_resumen'); if(resumenCantidad)resumenCantidad.style.display=activo?'flex':'none';
  if(activo){
    const incluir=document.getElementById('incluir_accesorios'); if(incluir)incluir.checked=true;
    const cantidad=fila.querySelector('[data-campo="cantidad"]');
    if(moduloIncluido('control')){
      fila.dataset.cantidadAutomaticaControl='1';
      if(cantidad)cantidad.readOnly=true;
      programarCantidadLimites(80);
    }else{
      fila.dataset.cantidadAutomaticaControl='0';
      if(cantidad){
        cantidad.readOnly=false;
        const qRapida=Math.max(1,Number(document.getElementById('accesorio_selector_cantidad')?.value||cantidad.value||1));
        cantidad.value=String(qRapida);
        const cantidadVisible=document.getElementById('cantidad_limites_visible'); if(cantidadVisible)cantidadVisible.textContent=String(qRapida);
      }
      const estado=document.getElementById('estado_limites'); if(estado)estado.textContent='Venta independiente: ingrese manualmente la cantidad de límites.';
    }
  }else{
    const estado=document.getElementById('estado_limites');if(estado)estado.textContent='';
  }
  actualizarResumenDocumento();
  if(activo && typeof actualizarListaAccesoriosCompacta==='function') actualizarListaAccesoriosCompacta();
}
function cambiarAccesorioRapidoV204(){
  const op=obtenerOpcionAccesorioRapido();
  const campoCantidad=document.getElementById('campo_accesorio_selector_cantidad');
  const inputCantidad=document.getElementById('accesorio_selector_cantidad');
  const btnAgregar=document.getElementById('btn_accesorio_agregar');
  const bloqueBarr=document.getElementById('bloque_barreras_inline_v463');
  const bloquePes=document.getElementById('bloque_pesador_v463');
  if(bloqueBarr) bloqueBarr.style.display='none';
  if(bloquePes) bloquePes.style.display='none';
  if(!op){
    if(campoCantidad)campoCantidad.style.display='';
    if(inputCantidad){inputCantidad.readOnly=false;inputCantidad.placeholder='';if(!inputCantidad.value)inputCantidad.value='1';}
    if(btnAgregar)btnAgregar.style.display='';
    return;
  }
  const clave=String(op.value||'');
  if(clave==='BARRERAS' || clave==='PESADOR'){
    if(campoCantidad)campoCantidad.style.display='none';
    if(btnAgregar)btnAgregar.style.display='none';
    if(clave==='BARRERAS' && bloqueBarr){bloqueBarr.style.display='block'; const interno=document.getElementById('bloque_barreras_v33'); if(interno)interno.style.display='block';}
    if(clave==='PESADOR' && bloquePes)bloquePes.style.display='block';
    window.accesorioConfigPendienteV460=accesorioConfigurableSeleccionadoV460(clave)?'':clave;
    actualizarAccionConfigurableV460(clave);
    const focusEl=op.dataset.focus?document.getElementById(op.dataset.focus):null;
    if(focusEl)setTimeout(()=>{try{focusEl.focus({preventScroll:true});}catch(e){focusEl.focus();}},0);
    return;
  }
  if(campoCantidad)campoCantidad.style.display='';
  if(inputCantidad){inputCantidad.readOnly=false;inputCantidad.placeholder='';}
  if(btnAgregar)btnAgregar.style.display='';
  seleccionarAccesorioRapidoPorClave(clave);
}
function obtenerOpcionAccesorioRapido(){
  const sel=document.getElementById('accesorio_selector_rapido');
  if(!sel || !sel.value) return null;
  return sel.options[sel.selectedIndex] || null;
}
function cerrarEditorAccesorioV201(limpiarSelector=true){
  document.querySelectorAll('#modulo_accesorios .accesorios-acordeon>.control-seccion').forEach(sec=>sec.classList.remove('v201-editor-activo'));
  if(limpiarSelector) window.accesorioConfigPendienteV460='';
  const cab=document.getElementById('accesorio_editor_contextual_cabecera'); if(cab) cab.style.display='none';
  if(limpiarSelector){
    const sel=document.getElementById('accesorio_selector_rapido');
    if(sel && sel.selectedIndex>=0 && sel.options[sel.selectedIndex]?.hidden) sel.value='';
    const campoCantidad=document.getElementById('campo_accesorio_selector_cantidad'); if(campoCantidad)campoCantidad.style.display='';
    const btnAgregar=document.getElementById('btn_accesorio_agregar'); if(btnAgregar)btnAgregar.style.display='';
  }
}
function abrirEditorAccesorioV201(panelId,titulo,focusId){
  cerrarEditorAccesorioV201();
  const panel=document.getElementById(panelId); if(!panel)return;
  panel.classList.add('v201-editor-activo');
  panel.classList.remove('cerrada');
  const cuerpo=panel.querySelector('.control-seccion-cuerpo'); if(cuerpo)cuerpo.style.display='block';
  const cab=document.getElementById('accesorio_editor_contextual_cabecera'); if(cab) cab.style.display='flex';
  const tit=document.getElementById('accesorio_editor_contextual_titulo'); if(tit) tit.textContent=titulo||'Configuración del accesorio';
  const focusEl=focusId?document.getElementById(focusId):null;
  if(focusEl){setTimeout(()=>{try{focusEl.focus({preventScroll:false});}catch(e){focusEl.focus();}},0);}
}
function abrirPanelAccesorioRapido(panelId,focusId){
  const op=obtenerOpcionAccesorioRapido();
  abrirEditorAccesorioV201(panelId,op?String(op.textContent||'').trim():'Configuración del accesorio',focusId);
}
window.accesorioConfigPendienteV460='';
function normalizarConfigurableV460(clave){
  const k=String(clave||'');
  if(k.startsWith('BARR_')) return 'BARRERAS';
  if(k.startsWith('SINT_')) return 'SINT_A7600C';
  return k;
}
function filasConfigurableV460(clave,soloSeleccionadas=false){
  const norm=normalizarConfigurableV460(clave), out=[];
  if(norm==='LIMITES'){
    const f=document.getElementById('fila_limite_accesorio');
    if(f && (!soloSeleccionadas || f.dataset.seleccionado==='1')) out.push(f);
    return out;
  }
  document.querySelectorAll('#items_especiales_accesorios_v33 [data-especial-clave]').forEach(f=>{
    if(normalizarConfigurableV460(f.dataset.especialClave)!==norm)return;
    if(soloSeleccionadas && f.dataset.seleccionado!=='1')return;
    out.push(f);
  });
  return out;
}
function accesorioConfigurableSeleccionadoV460(clave){
  return filasConfigurableV460(clave,true).length>0;
}
function actualizarAccionConfigurableV460(clave){
  const norm=normalizarConfigurableV460(clave);
  document.querySelectorAll('[data-confirmar-configurable-v460]').forEach(b=>{
    if(b.dataset.confirmarConfigurableV460!==norm)return;
    b.textContent=accesorioConfigurableSeleccionadoV460(norm)?'Guardar cambios':'Agregar al documento';
  });
}
function validarConfigurableV460(clave){
  const norm=normalizarConfigurableV460(clave);
  if(norm==='LIMITES'){
    if(!document.getElementById('limite_accesorio_select')?.value) return 'Seleccione el modelo de límite.';
    const q=Number(document.querySelector('#fila_limite_accesorio [data-campo="cantidad"]')?.value||0);
    if(q<=0) return 'La cantidad de límites debe ser mayor que cero.';
  }
  if(norm==='SINT_A7600C'){
    const ch=document.querySelector('[data-sint-codigo="A7600C"]');
    const q=Number(document.querySelector('[data-sint-cantidad="A7600C"]')?.value||0);
    if(!ch?.checked || q<=0) return 'Seleccione el sintetizador e indique una cantidad.';
  }
  if(norm==='BARRERAS'){
    if((document.getElementById('senal_tipo_puerta')?.value||'')!=='PA') return 'Las barreras sólo pueden cotizarse con puerta automática PA.';
    if(![...document.querySelectorAll('[data-barrera-codigo]')].some(ch=>ch.checked)) return 'Seleccione al menos una barrera.';
  }
  if(norm==='PESADOR' && !document.getElementById('pesador_tipo')?.value) return 'Seleccione el tipo de pesador.';
  if(norm==='SUPERVISOR'){
    if(Number(document.getElementById('supervisor_cantidad')?.value||0)<1) return 'Indique al menos un sistema Supervisor.';
    if(Number(document.getElementById('supervisor_ascensores')?.value||0)<1) return 'Indique la cantidad de ascensores.';
  }
  if(norm==='CABLE_MALLADO'){
    const corriente=Math.max(Number(document.getElementById('cable_mallado_corriente_manual')?.value||0),Number(corrienteVariadorV33||0));
    const metros=Number(document.getElementById('cable_mallado_metros')?.value||0);
    if(corriente<=0) return 'Falta la corriente del variador para determinar el cable.';
    if(metros<=0) return 'Indique los metros de cable mallado.';
  }
  return '';
}
function confirmarAccesorioConfigurableV460(clave){
  const norm=normalizarConfigurableV460(clave);
  actualizarEspecialesAccesorios();
  const error=validarConfigurableV460(norm);
  if(error){alert(error);return;}
  let filas=filasConfigurableV460(norm,false).filter(f=>{
    const q=Number(f.querySelector('[data-campo="cantidad"]')?.value||0);
    const codigo=String(f.querySelector('[data-campo="codigo"]')?.value||'').trim();
    return q>0 && codigo!=='';
  });
  if(norm==='SUPERVISOR') filas=filasConfigurableV460(norm,false).filter(f=>Number(f.querySelector('[data-campo="cantidad"]')?.value||0)>0);
  if(!filas.length){
    alert('No se pudo valorizar este accesorio. Revise la configuración y que sus códigos tengan precio en la base Bejerman seleccionada.');
    return;
  }
  filas.forEach(f=>{f.dataset.seleccionado='1';f.dataset.v460Preview='0';f.style.display='grid';});
  if(norm==='LIMITES'){
    const f=document.getElementById('fila_limite_accesorio');if(f){f.dataset.seleccionado='1';f.style.display='grid';}
  }
  const incluir=document.getElementById('incluir_accesorios');if(incluir)incluir.checked=true;
  window.accesorioConfigPendienteV460='';
  const sel=document.getElementById('accesorio_selector_rapido');if(sel)sel.value='';
  cerrarEditorAccesorioV201(false);
  cambiarAccesorioRapidoV204();
  actualizarResumenDocumento();
  actualizarListaAccesoriosCompacta();
}
function cancelarConfigurableV460(){
  const pendiente=window.accesorioConfigPendienteV460;
  if(pendiente && !accesorioConfigurableSeleccionadoV460(pendiente)) quitarAccesorioRapido(pendiente);
  window.accesorioConfigPendienteV460='';
  const sel=document.getElementById('accesorio_selector_rapido');if(sel)sel.value='';
  cerrarEditorAccesorioV201(false);cambiarAccesorioRapidoV204();actualizarListaAccesoriosCompacta();
}
function seleccionarAccesorioRapidoPorClave(clave){
  const sel=document.getElementById('accesorio_selector_rapido'); if(!sel||!clave)return;
  const valor=String(clave||'').startsWith('BARR_') ? 'BARRERAS' : (String(clave||'').startsWith('SINT_') ? 'SINT_A7600C' : String(clave||''));
  const op=[...sel.options].find(o=>o.value===valor);
  if(op) sel.value=op.value;
  if(valor==='LIMITES'){
    const campoCantidad=document.getElementById('campo_accesorio_selector_cantidad'); if(campoCantidad)campoCantidad.style.display='none';
    const btnAgregar=document.getElementById('btn_accesorio_agregar'); if(btnAgregar)btnAgregar.style.display='none';
  }
  let fila=null;
  if(['BOTONERA_FOSO','BOTONERA_INSPECCION','ALARMA_EMERGENCIA_12V','GONG_TECHO_CABINA','GONG_TECHO_CABINA_FUENTE','SINTETIZADOR_BAFLE'].includes(valor)) fila=document.querySelector('.accesorio-catalogo-card[data-clave="'+valor+'"] .item-modular');
  else if(valor==='LIMITES') fila=document.getElementById('fila_limite_accesorio');
  else fila=document.querySelector('#items_especiales_accesorios_v33 [data-selector-key="'+clave+'"],#items_especiales_accesorios_v33 [data-especial-clave="'+clave+'"]');
  const cant=fila?.querySelector('[data-campo="cantidad"]')?.value;
  const inputCant=document.getElementById('accesorio_selector_cantidad'); if(inputCant&&cant)inputCant.value=String(cant);
}
function agregarAccesorioRapido(){
  const op=obtenerOpcionAccesorioRapido(); if(!op) return;
  const qty=Math.max(1,parseInt(document.getElementById('accesorio_selector_cantidad')?.value||'1',10)||1);
  const tipo=op.dataset.tipo||''; const clave=op.value;
  const incluir=document.getElementById('incluir_accesorios'); if(incluir) incluir.checked=true;
  if(clave==='LIMITES'){
    cambiarAccesorioRapidoV204();
    return;
  }
  if(tipo==='catalogo'){
    const card=document.querySelector('.accesorio-catalogo-card[data-clave="'+clave+'"]');
    const check=card?.querySelector('.accesorio-catalogo-check');
    if(check && !check.checked){check.checked=true;toggleAccesorioCatalogo(check);} 
    const cantidad=card?.querySelector('[data-campo="cantidad"]'); if(cantidad) cantidad.value=String(qty);
    actualizarResumenDocumento();
    const selector=document.getElementById('accesorio_selector_rapido'); if(selector)selector.value='';
    const cantidadRapida=document.getElementById('accesorio_selector_cantidad'); if(cantidadRapida)cantidadRapida.value='1';
    cerrarEditorAccesorioV201(false);
    cambiarAccesorioRapidoV204();
    return;
  }
  abrirPanelAccesorioRapido(op.dataset.panel||'',op.dataset.focus||'');
}
function modificarAccesorioDesdeListaV462(clave){
  const norm=normalizarConfigurableV460(clave);
  if(norm==='LIMITES'){
    document.getElementById('acc_panel_limites')?.scrollIntoView({behavior:'smooth',block:'start'});
    actualizarAccionConfigurableV460('LIMITES');
    return;
  }
  if(norm==='CABLE_MALLADO'){
    document.getElementById('acc_panel_cable_mallado')?.scrollIntoView({behavior:'smooth',block:'start'});
    document.getElementById('cable_mallado_metros')?.focus(); actualizarCorrienteVariadorV33(); actualizarAccionConfigurableV460(norm); return;
  }
  if(norm==='SUPERVISOR'){
    document.getElementById('acc_panel_supervisor')?.scrollIntoView({behavior:'smooth',block:'start'});
    document.getElementById('supervisor_cantidad')?.focus(); actualizarAccionConfigurableV460(norm); return;
  }
  if(norm==='BARRERAS' || norm==='PESADOR'){
    const sel=document.getElementById('accesorio_selector_rapido'); if(sel)sel.value=norm;
    cambiarAccesorioRapidoV204();
    document.getElementById('acc_panel_accesorios')?.scrollIntoView({behavior:'smooth',block:'start'});
    return;
  }
  seleccionarAccesorioRapidoPorClave(clave);
  cambiarAccesorioRapidoV204();
  modificarAccesorioRapido();
}
function modificarAccesorioRapido(){
  const op=obtenerOpcionAccesorioRapido(); if(!op) return;
  const tipo=op.dataset.tipo||''; const clave=op.value;
  if(['BARRERAS','LIMITES','PESADOR','SUPERVISOR','CABLE_MALLADO'].includes(clave)) window.accesorioConfigPendienteV460='';
  if(tipo==='catalogo'){
    const fila=document.querySelector('.accesorio-catalogo-card[data-clave="'+clave+'"] .item-modular');
    if(!fila || fila.dataset.seleccionado==='0'){alert('Primero agregá el accesorio.');return;}
    const cantidad=fila.querySelector('[data-campo="cantidad"]');
    const topCantidad=document.getElementById('accesorio_selector_cantidad');
    const nuevaCantidad=Math.max(1,parseInt(topCantidad?.value||cantidad?.value||'1',10)||1);
    if(cantidad){cantidad.value=String(nuevaCantidad);cantidad.dataset.cantidadManualV33='1';}
    cerrarEditorAccesorioV201();
    actualizarResumenDocumento();
    if(topCantidad){topCantidad.focus();topCantidad.select();}
    return;
  }
  abrirPanelAccesorioRapido(op.dataset.panel||'',op.dataset.focus||'');
}
function quitarAccesorioRapidoDesdeSelector(){
  const op=obtenerOpcionAccesorioRapido(); if(!op) return;
  quitarAccesorioRapido(op.value);
  cerrarEditorAccesorioV201();
}
function quitarAccesorioRapido(clave){
  if(!clave) return;
  if(normalizarConfigurableV460(clave)===window.accesorioConfigPendienteV460) window.accesorioConfigPendienteV460='';
  if(['BOTONERA_FOSO','BOTONERA_INSPECCION','ALARMA_EMERGENCIA_12V','GONG_TECHO_CABINA','GONG_TECHO_CABINA_FUENTE','SINTETIZADOR_BAFLE','CHIP_CONTACTO','TARJETA_PROXIMIDAD'].includes(clave)){
    const card=document.querySelector('.accesorio-catalogo-card[data-clave="'+clave+'"]');
    const check=card?.querySelector('.accesorio-catalogo-check');
    if(check && check.checked){check.checked=false;toggleAccesorioCatalogo(check);} else actualizarResumenDocumento();
    return;
  }
  if(clave==='LIMITES'){
    const sel=document.getElementById('limite_accesorio_select'); if(sel){sel.value='';seleccionarLimiteAccesorio();}
    return;
  }
  if(clave==='PESADOR'){
    const sel=document.getElementById('pesador_tipo'); if(sel){sel.value='';actualizarEspecialesAccesorios();}
    return;
  }
  if(clave==='SUPERVISOR'){
    const c=document.getElementById('supervisor_cantidad'); if(c) c.value='0';
    const a=document.getElementById('supervisor_ascensores'); if(a) a.value='1';
    const b=document.getElementById('supervisor_baterias'); if(b) b.value='0';
    const p=document.getElementById('supervisor_puertos'); if(p) p.value='0';
    actualizarEspecialesAccesorios();
    return;
  }
  if(clave==='CABLE_MALLADO'){
    const m=document.getElementById('cable_mallado_metros'); if(m) m.value='0';
    const cm=document.getElementById('cable_mallado_corriente_manual'); if(cm && corrienteVariadorV33<=0) cm.value='0';
    actualizarEspecialesAccesorios();
    return;
  }
  if(String(clave).startsWith('SINT_')){
    const codigo=String(clave).replace(/^SINT_/,'');
    const check=document.querySelector('[data-sint-codigo="'+codigo+'"]');
    const cantidad=document.querySelector('[data-sint-cantidad="'+codigo+'"]');
    if(check) check.checked=false;
    if(cantidad) cantidad.value='0';
    actualizarEspecialesAccesorios();
    return;
  }
  if(String(clave).startsWith('BARR_')){
    const codigo=String(clave).replace(/^BARR_/,'');
    const check=document.querySelector('[data-barrera-codigo="'+codigo+'"]');
    if(check) check.checked=false;
    actualizarEspecialesAccesorios();
    return;
  }
  if(String(clave).startsWith('MANUAL:')){
    const fila=document.querySelector('#items_accesorios .item-modular[data-manual-key="'+clave+'"]');
    if(fila){fila.dataset.seleccionado='0';fila.style.display='none';actualizarResumenDocumento();}
  }
}
function claveSelectorNormalizadaAccesorioV459(clave){
  const k=String(clave||'');
  if(k.startsWith('BARR_')) return 'BARRERAS';
  if(k.startsWith('SINT_')) return 'SINT_A7600C';
  return k;
}
function actualizarOpcionesAccesoriosDisponiblesV459(claves){
  const sel=document.getElementById('accesorio_selector_rapido'); if(!sel)return;
  const activos=new Set((claves||[]).map(claveSelectorNormalizadaAccesorioV459));
  [...sel.options].forEach(op=>{
    if(!op.value)return;
    op.hidden=activos.has(op.value);
  });
}
function actualizarListaAccesoriosCompacta(){
  const cont=document.getElementById('accesorios_selector_lista'); if(!cont) return;
  const grupos=new Map(); let manualIdx=0;
  document.querySelectorAll('#items_accesorios .item-modular, #items_especiales_accesorios_v33 .item-modular').forEach((f)=>{
    if(f.dataset.seleccionado==='0' || f.style.display==='none') return;
    const concepto=(f.querySelector('[data-campo="concepto"]')?.value||'').trim();
    const codigo=(f.querySelector('[data-campo="codigo"]')?.value||'').trim();
    const descripcion=(f.querySelector('[data-campo="descripcion"]')?.value||'').trim();
    const cantidad=Number(f.querySelector('[data-campo="cantidad"]')?.value||0);
    const precio=Number(f.querySelector('[data-campo="precio"]')?.value||0);
    if(!(concepto||codigo||descripcion) || cantidad<=0) return;
    let clave=f.dataset.selectorKey||f.dataset.especialClave||'';
    const card=f.closest('.accesorio-catalogo-card'); if(!clave && card) clave=card.dataset.clave||'';
    if(!clave && f.id==='fila_limite_accesorio') clave='LIMITES';
    if(!clave){manualIdx+=1;clave='MANUAL:'+manualIdx;f.dataset.manualKey=clave;}
    const norm=normalizarConfigurableV460(clave);
    const esGrupo=['BARRERAS','LIMITES','PESADOR','SUPERVISOR','CABLE_MALLADO'].includes(norm);
    const groupKey=esGrupo?norm:clave;
    if(!grupos.has(groupKey)) grupos.set(groupKey,{clave:groupKey,nombre:esGrupo?({BARRERAS:'Barreras',LIMITES:'Límites',PESADOR:'Pesador de carga',SUPERVISOR:'Sistema Supervisor',CABLE_MALLADO:'Cable mallado'}[norm]):(concepto||'Accesorio'),detalles:[],cantidad:0,total:0,precioUnitario:0,esGrupo,bonificados:0,filas:0});
    const g=grupos.get(groupKey);g.cantidad+=cantidad;g.total+=cantidad*precio;g.precioUnitario=precio;g.filas+=1;if(f.dataset.bonificado==='1')g.bonificados+=1;
    const det=[codigo,descripcion&&descripcion!==concepto?descripcion:''].filter(Boolean).join(' · ');if(det&&!g.detalles.includes(det))g.detalles.push(det);
  });
  const filas=[...grupos.values()];
  actualizarOpcionesAccesoriosDisponiblesV459(filas.map(x=>x.clave));
  if(!filas.length){cont.innerHTML='<div class="accesorios-selector-vacio">Todavía no hay accesorios seleccionados.</div>';return;}
  cont.innerHTML=filas.map(function(it){
    const attrClave=JSON.stringify(String(it.clave)).replace(/"/g,'&quot;');
    const detalleBase=it.detalles.length?it.detalles.join(' + '):'Sin detalle adicional';
    const todoBonificado=it.filas>0&&it.bonificados===it.filas;
    const precioTxt=todoBonificado?' · BONIFICADO · $ 0':(it.esGrupo?(it.total>0?' · Total $ '+Math.ceil(it.total).toLocaleString('es-AR'):''):(it.precioUnitario>0?' · $ '+Math.ceil(it.precioUnitario).toLocaleString('es-AR')+' c/u':''));
    const cantTxt=it.esGrupo&&it.clave==='BARRERAS'?' · '+it.detalles.length+' selección(es)':' · Cant. '+it.cantidad;
    return '<div class="accesorios-selector-fila-v459'+(todoBonificado?' v15-accesorio-bonificado':'')+'"><div class="accesorio-check-v459">✓</div><div class="accesorio-info-v459"><strong>'+escaparRep(it.nombre)+'</strong><small>'+escaparRep(detalleBase+cantTxt+precioTxt)+'</small></div><div class="acciones"><button type="button" class="v15-btn-bonificar" onclick="toggleBonificacionAccesorioV15('+attrClave+')">'+(todoBonificado?'Quitar bonificación':'Bonificar')+'</button><button type="button" class="btn-modificar-v459" onclick="modificarAccesorioDesdeListaV462('+attrClave+')">Modificar</button><button type="button" class="btn-quitar-v459" onclick="quitarAccesorioRapido('+attrClave+')">Quitar</button></div></div>';
  }).join('');
}
let temporizadorCantidadLimites=null;
function programarCantidadLimites(demora=250){
  clearTimeout(temporizadorCantidadLimites);
  temporizadorCantidadLimites=setTimeout(calcularCantidadLimites,demora);
}
async function calcularCantidadLimites(){
  const generacionNueva=window.__cotizadorNuevaGeneracion||0;
  const sel=document.getElementById('limite_accesorio_select'); const estado=document.getElementById('estado_limites'); const form=document.getElementById('form_cotizador');
  if(!sel||!sel.value||!form)return;
  if(!moduloIncluido('control')){
    const cantidad=document.querySelector('#fila_limite_accesorio [data-campo="cantidad"]');
    if(cantidad)cantidad.readOnly=false;
    if(estado)estado.textContent='Cantidad manual porque Control no está incluido.';
    return;
  }
  if(estado)estado.textContent='Calculando cantidad automáticamente según la configuración del Control...';
  try{
    const fd=new FormData(form);
    fd.set('codigo_limite',sel.value);
    fd.set('nombre_limite',sel.options[sel.selectedIndex]?.dataset.nombre||'Límite con soporte');
    const r=await fetch('calcular_limites_accesorios.php',{method:'POST',body:fd});
    const j=await r.json();
    if(generacionNueva!==(window.__cotizadorNuevaGeneracion||0))return;
    if(!j.ok)throw new Error(j.mensaje||'No se pudo calcular');
    const cantidad=document.querySelector('#fila_limite_accesorio [data-campo="cantidad"]');
    if(cantidad){cantidad.value=j.cantidad;cantidad.readOnly=true;cantidad.closest('.item-modular')?.setAttribute('data-cantidad-automatica-control','1');}
    const cantidadVisible=document.getElementById('cantidad_limites_visible'); if(cantidadVisible)cantidadVisible.textContent=j.cantidad;
    const selector=document.getElementById('accesorio_selector_rapido');
    const cantidadRapida=document.getElementById('accesorio_selector_cantidad');
    if(selector?.value==='LIMITES'&&cantidadRapida)cantidadRapida.value=String(j.cantidad);
    if(estado)estado.textContent='Cantidad calculada automáticamente desde Control: '+j.cantidad+' unidades.';
    actualizarResumenDocumento();
  }catch(e){if(generacionNueva!==(window.__cotizadorNuevaGeneracion||0))return;if(estado)estado.textContent=e.message;}
}
function agregarItemModular(modulo){incluirModuloAlComenzarEdicion(String(modulo||'').toLowerCase());const c=document.getElementById('items_'+modulo);if(!c)return;let base=modulo==='accesorios'?c.querySelector('.accesorio-item:not(.limite-accesorio)'):c.querySelector('.item-modular');if(!base&&modulo==='accesorios'){base=document.createElement('div');base.className='item-modular accesorio-item';base.style.cssText='display:grid;gap:8px;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:6px';base.innerHTML='<input data-campo="concepto" placeholder="Concepto"><input data-campo="codigo" placeholder="Código"><input data-campo="descripcion" placeholder="Descripción"><input data-campo="cantidad" type="number" min="0.0001" step="0.0001" value="1" oninput="actualizarResumenDocumento()"><input class="accesorio-precio-final" type="number" min="0" step="1" placeholder="Precio final con descuento" oninput="actualizarPrecioFinalAccesorioManual(this)"><input class="accesorio-precio-base" type="hidden" value="0"><input type="hidden" data-campo="precio" value="0">';c.appendChild(base);return;}if(!base)return;const n=base.cloneNode(true);n.dataset.precioBase='0';n.querySelectorAll('input').forEach(i=>{if(i.dataset.campo==='cantidad')i.value='1';else if(i.classList.contains('accesorio-precio-base')||i.dataset.campo==='precio')i.value='0';else i.value='';});c.appendChild(n);}
let temporizadorBusquedaRepuestos=null;
function escaparRep(t){return String(t??'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#039;');}
function cambiarBaseBejerman(select){
 const listaId=select&&select.value?select.value:'';
 recalcularControlUniversal(80,true);
 const modo=document.getElementById('modo_precios_documento');
 if(modo) modo.value='MANTENER';
 const senal=document.getElementById('senal_lista_id');
 if(senal) senal.value=listaId;
 const detalle=document.querySelector('#detalle_base_bejerman small');
 if(detalle&&select&&select.options.length) detalle.textContent=select.options[select.selectedIndex].text;
 actualizarResumenDocumento();
 if(typeof programarCalculoSenalizacion==='function') programarCalculoSenalizacion(100);
}
function seleccionarModoPreciosDocumento(modo, listaId){
 const modoInput=document.getElementById('modo_precios_documento');
 const listaInput=document.getElementById('lista_id');
 if(modoInput) modoInput.value=modo;
 if(listaInput) listaInput.value=String(listaId||'');
 window.location.href='index.php?'+(<?= (int)$cotizacionEdicionId ?>?'editar_cotizacion=<?= (int)$cotizacionEdicionId ?>':'editar_pedido=<?= (int)$pedidoEdicionId ?>')+'&modo_precios='+encodeURIComponent(modo);
}
function precioRep(v){return '$ '+Math.ceil(Number(v||0)).toLocaleString('es-AR',{minimumFractionDigits:0,maximumFractionDigits:0});}
function codigosRepuestosSeleccionados(){
 const codigos=new Set();
 document.querySelectorAll('#repuestos_seleccionados .repuesto-producto [data-campo="codigo"]').forEach(function(i){
  const codigo=String(i.value||'').trim().toUpperCase();if(codigo)codigos.add(codigo);
 });
 return codigos;
}
function descuentoBusquedaRepuestos(){
 const r=document.querySelector('input[name="repuesto_descuento_busqueda"]:checked');
 return r?String(r.value||'30'):'30';
}
function actualizarColumnasDescuentoRepuestos(){
 const d=descuentoBusquedaRepuestos();
 document.querySelectorAll('#resultados_repuestos .precio-col').forEach(el=>el.classList.remove('descuento-activo'));
 document.querySelectorAll('#resultados_repuestos .precio-col-'+d).forEach(el=>el.classList.add('descuento-activo'));
}
function actualizarEstadoResultadosRepuestos(){
 const seleccionados=codigosRepuestosSeleccionados();
 document.querySelectorAll('#resultados_repuestos .repuesto-agregar[data-codigo]').forEach(function(btn){
  const agregado=seleccionados.has(String(btn.dataset.codigo||'').toUpperCase());
  const sinCosto=btn.dataset.sinCosto==='1';
  btn.disabled=sinCosto||agregado;
  btn.classList.toggle('agregado',agregado);
  btn.textContent=sinCosto?'Sin costo':(agregado?'Agregado':'Agregar');
 });
 actualizarColumnasDescuentoRepuestos();
}
function tablaVaciaRepuestos(mensaje){
 return '<div class="repuestos-resultados-cabecera"><strong>Catálogo de repuestos</strong><span>Busque para consultar precios o agregar artículos.</span></div><div class="repuestos-tabla-scroll"><table class="repuestos-tabla-busqueda"><thead><tr><th>Código</th><th>Descripción</th><th>Categoría</th><th class="precio-col precio-col-0">Precio</th><th class="precio-col precio-col-15">15%</th><th class="precio-col precio-col-30">30%</th><th>Cantidad</th><th>IVA</th><th>Acción</th></tr></thead><tbody id="repuestos_resultados_body"><tr class="repuestos-vacio-busqueda"><td colspan="9">'+escaparRep(mensaje)+'</td></tr></tbody></table></div>';
}
async function buscarRepuestosPredictivo(){
 const input=document.getElementById('buscar_repuesto'),categoria=document.getElementById('categoria_repuesto'),caja=document.getElementById('resultados_repuestos');
 if(!input||!caja)return;
 const q=input.value.trim(),cat=categoria?categoria.value:'';
 caja.style.display='block';
 if(q.length<2&&!cat){caja.innerHTML=tablaVaciaRepuestos('Seleccioná una categoría o escribí al menos dos caracteres.');actualizarColumnasDescuentoRepuestos();return;}
 caja.innerHTML='<div class="repuestos-resultados-cabecera"><strong>Buscando coincidencias...</strong><span>Consultando la lista vigente.</span></div>';
 try{
  const r=await fetch('buscar_repuestos.php?q='+encodeURIComponent(q)+'&categoria='+encodeURIComponent(cat)+'&lista_id='+encodeURIComponent(document.getElementById('lista_id')?.value||''),{cache:'no-store'});if(!r.ok)throw new Error('HTTP '+r.status);
  const data=await r.json();const vistos=new Set();const resultados=(data.resultados||[]).filter(function(p){const codigo=String(p.codigo||'').trim().toUpperCase();if(vistos.has(codigo))return false;vistos.add(codigo);return true;});
  if(!resultados.length){caja.innerHTML=tablaVaciaRepuestos('No se encontraron coincidencias. Probá con menos palabras o cambiá la categoría.');actualizarColumnasDescuentoRepuestos();return;}
  caja.innerHTML='<div class="repuestos-resultados-cabecera"><strong>Productos encontrados</strong><span class="repuestos-resultados-contador">'+resultados.length+' productos</span></div><div class="repuestos-tabla-scroll"><table class="repuestos-tabla-busqueda"><thead><tr><th>Código</th><th>Descripción</th><th>Categoría</th><th class="precio-col precio-col-0">Precio</th><th class="precio-col precio-col-15">15%</th><th class="precio-col precio-col-30">30%</th><th>Cantidad</th><th>IVA</th><th>Acción</th></tr></thead><tbody id="repuestos_resultados_body"></tbody></table></div>';
  const body=caja.querySelector('#repuestos_resultados_body');
  resultados.forEach(function(p){
   const tr=document.createElement('tr');tr.className='repuesto-resultado-fila';
   const sinCosto=!p.costo_disponible;
   const origen='<span class="rep-origen rep-origen-'+escaparRep((p.costo_tipo||'BEJERMAN').toLowerCase())+'">'+escaparRep(p.costo_tipo||'BEJERMAN')+'</span>';
   const formula=p.costo_tipo==='FORMULA'&&p.formula_costo?'<small class="rep-formula-mini">'+escaparRep(p.formula_costo)+'</small>':'';
   tr.innerHTML='<td class="rep-code">'+escaparRep(p.codigo)+'</td><td class="rep-desc">'+escaparRep(p.descripcion)+(p.advertencia?'<small class="repuesto-advertencia">'+escaparRep(p.advertencia)+'</small>':'')+'</td><td class="rep-cat">'+escaparRep(p.categoria||'Sin categoría')+' '+origen+formula+'</td><td class="rep-price precio-col precio-col-0">'+(sinCosto?'—':precioRep(p.precio_base))+'</td><td class="rep-price precio-col precio-col-15">'+(sinCosto?'—':precioRep(p.precio_15))+'</td><td class="rep-price precio-col precio-col-30">'+(sinCosto?'—':precioRep(p.precio_30))+'</td><td><input class="repuesto-cantidad-busqueda" type="number" min="1" step="1" value="1"></td><td class="rep-iva">'+Number(p.iva_porcentaje||0).toLocaleString('es-AR')+'%</td><td class="rep-accion"><button type="button" class="repuesto-agregar" data-codigo="'+escaparRep(p.codigo)+'" data-sin-costo="'+(sinCosto?'1':'0')+'" '+(sinCosto?'disabled title="Producto sin costo vigente"':'')+'>'+(sinCosto?'Sin costo':'Agregar')+'</button></td>';
   const btn=tr.querySelector('.repuesto-agregar');if(btn&&!sinCosto)btn.addEventListener('click',function(){const qty=Math.max(1,Math.round(Number(tr.querySelector('.repuesto-cantidad-busqueda')?.value||1)));agregarRepuestoSeleccionado(p,qty,descuentoBusquedaRepuestos());actualizarEstadoResultadosRepuestos();});
   body.appendChild(tr);
  });
  actualizarEstadoResultadosRepuestos();
 }catch(e){caja.innerHTML=tablaVaciaRepuestos('Error al buscar repuestos. Intente nuevamente.');actualizarColumnasDescuentoRepuestos();}
}
function normalizarCantidadRepuesto(input,actualizar=true){
 const numero=Number(String(input?.value??'').replace(',','.'));
 if(!Number.isFinite(numero)){input.value='1';return 1;}
 const entero=Math.max(1,Math.round(numero));
 if(String(input.value)!==String(entero)) input.value=String(entero);
 const fila=input?.closest('.repuesto-agregado');if(fila)actualizarTotalRepuesto(fila);
 if(actualizar) actualizarResumenDocumento();
 return entero;
}
function cantidadModuloParaGuardar(modulo,input){
 if(modulo==='REPUESTOS') return String(normalizarCantidadRepuesto(input,false));
 return input?.value||'1';
}
function agregarRepuestoSeleccionado(p,cantidadInicial=1,descuentoInicial='30'){
 const c=document.getElementById('repuestos_seleccionados');if(!c)return;
 const codigoNuevo=String(p.codigo||'').trim().toUpperCase();
 const repetido=[...c.querySelectorAll('.repuesto-producto [data-campo="codigo"]')].some(function(x){return String(x.value||'').trim().toUpperCase()===codigoNuevo;});
 if(repetido){actualizarEstadoResultadosRepuestos();return;}
 c.querySelector('.repuestos-vacio')?.remove();
 const d=['0','15','30'].includes(String(descuentoInicial))?String(descuentoInicial):'30';
 const precioSel=Number(d==='15'?p.precio_15:d==='30'?p.precio_30:p.precio_base);
 const precioEntero=Math.ceil(precioSel);
 const f=document.createElement('div');f.className='item-modular repuesto-agregado';f.dataset.precioBase=p.precio_base;f.dataset.precio15=p.precio_15;f.dataset.precio30=p.precio_30;
 const alerta=p.advertencia?'<div class="repuesto-advertencia">'+escaparRep(p.advertencia)+'</div>':'';const obs=p.observaciones?'<small>'+escaparRep(p.observaciones)+'</small>':'';
 const opciones='<option value="0" '+(d==='0'?'selected':'')+'>0 %</option><option value="15" '+(d==='15'?'selected':'')+'>15 %</option><option value="30" '+(d==='30'?'selected':'')+'>30 %</option>';
 f.classList.add('repuesto-producto');f.innerHTML='<div class="repuesto-info"><strong>'+escaparRep(p.codigo)+'</strong><div class="repuesto-desc">'+escaparRep(p.descripcion)+'</div><textarea class="repuesto-desc-editor" rows="4" style="display:none">'+escaparRep(p.descripcion)+'</textarea><button type="button" class="repuesto-editar-desc" onclick="editarDescripcionRepuesto(this)">Editar descripción</button><small>'+escaparRep(p.categoria||'Sin categoría')+' · IVA '+Number(p.iva_porcentaje||0).toLocaleString('es-AR')+'%</small>'+obs+alerta+'</div><div><label>Descuento</label><select class="repuesto-descuento" onchange="actualizarPrecioRepuesto(this)">'+opciones+'</select></div><div><label>Cantidad</label><input data-campo="cantidad" type="number" min="1" step="1" inputmode="numeric" value="'+String(Math.max(1,Math.round(Number(cantidadInicial||1))))+'" oninput="normalizarCantidadRepuesto(this)" onchange="normalizarCantidadRepuesto(this)"></div><div class="ui-price-detail"><label>Precio unitario</label><input class="repuesto-precio-visible" value="'+String(precioEntero)+'" readonly></div><div class="ui-price-detail"><label>Total</label><input class="repuesto-total-visible" value="'+String(precioEntero)+'" readonly></div><button type="button" class="repuesto-quitar" onclick="quitarRepuesto(this)">Quitar</button><input type="hidden" data-campo="concepto" value="Repuesto'+(d==='0'?'':' - descuento '+d+'%')+'"><input type="hidden" data-campo="codigo" value="'+escaparRep(p.codigo)+'"><input type="hidden" data-campo="descripcion" value="'+escaparRep(p.descripcion)+'"><input type="hidden" data-campo="precio" value="'+String(precioEntero)+'">';
 c.appendChild(f);
 actualizarTotalRepuesto(f);
 const buscador=document.getElementById('buscar_repuesto');
 const resultados=document.getElementById('resultados_repuestos');
 if(buscador){buscador.value='';buscador.focus();}
 if(resultados){resultados.innerHTML=tablaVaciaRepuestos('Seleccioná una categoría o escribí al menos dos caracteres.');resultados.style.display='none';}
 incluirModuloAlComenzarEdicion('repuestos');actualizarEstadoResultadosRepuestos();actualizarResumenDocumento();
}
function editarDescripcionRepuesto(btn){
 const fila=btn.closest('.repuesto-agregado');if(!fila)return;
 const vista=fila.querySelector('.repuesto-desc'),editor=fila.querySelector('.repuesto-desc-editor'),oculto=fila.querySelector('[data-campo="descripcion"]');
 if(!vista||!editor||!oculto)return;
 const editando=editor.style.display!=='none';
 if(editando){
  const texto=editor.value.trim();oculto.value=texto;vista.textContent=texto;editor.style.display='none';vista.style.display='block';btn.textContent='Editar descripción';actualizarResumenDocumento();
 }else{
  editor.value=oculto.value;vista.style.display='none';editor.style.display='block';btn.textContent='Guardar descripción';editor.focus();
 }
}


let repIntegracionTimer=null;
let repIntegracionUltimas=new Map();
function clavesAccesoriosSeleccionadosIntegracion(){
 const out=[];document.querySelectorAll('#items_accesorios [data-selector-key][data-seleccionado="1"],#items_especiales_accesorios_v33 [data-selector-key][data-seleccionado="1"]').forEach(function(f){const k=String(f.dataset.selectorKey||'').trim();if(k&&!out.includes(k))out.push(k);});return out;
}
function parametrosIntegracionRepuestos(){
 const q=new URLSearchParams();
 q.set('incluir_control',document.getElementById('incluir_control')?.checked?'1':'0');q.set('cpu_id',document.getElementById('id_cpu')?.value||'0');q.set('tipo_id',document.getElementById('id_tipo_control')?.value||'0');q.set('subtipo_id',document.getElementById('id_subtipo')?.value||'0');q.set('equipos',document.getElementById('cantidad_equipos')?.value||'1');
 q.set('incluir_senalizacion',document.getElementById('senal_incluir_cotizacion')?.checked?'1':'0');q.set('senal_modelo_id',document.getElementById('senal_modelo')?.value||'0');
 q.set('incluir_accesorios',document.getElementById('incluir_accesorios')?.checked?'1':'0');q.set('accesorios',clavesAccesoriosSeleccionadosIntegracion().join(','));q.set('incluir_iep',document.getElementById('incluir_iep')?.checked?'1':'0');q.set('lista_id',document.getElementById('lista_id')?.value||'0');return q;
}
function productoDesdeReglaIntegracion(r){return {codigo:r.codigo,descripcion:r.descripcion,categoria:r.categoria,precio_base:Number(r.precio_base||0),precio_15:Number(r.precio_15||0),precio_30:Number(r.precio_30||0),costo_disponible:!!r.costo_disponible,iva_porcentaje:Number(r.iva_porcentaje||0),observaciones:r.observaciones||'',advertencia:r.advertencia||''};}
function marcarRepuestoIntegracion(codigo,regla,obligatorio){const filas=[...document.querySelectorAll('#repuestos_seleccionados .repuesto-producto')];const f=filas.find(x=>String(x.querySelector('[data-campo="codigo"]')?.value||'').trim().toUpperCase()===String(codigo||'').trim().toUpperCase());if(!f)return;f.dataset.integracionRegla=String(regla||'');f.dataset.integracionAuto='1';f.classList.toggle('repuesto-integracion-obligatorio',!!obligatorio);}
function aplicarReglasIntegracionAutomaticas(reglas){
 const vigentes=new Set();
 reglas.forEach(function(r){if(!['AUTOMATICO','OBLIGATORIO'].includes(String(r.comportamiento||'')))return;vigentes.add(String(r.regla_id));const codigo=String(r.codigo||'').toUpperCase();const ya=codigosRepuestosSeleccionados().has(codigo);if(!ya&&r.costo_disponible){agregarRepuestoSeleccionado(productoDesdeReglaIntegracion(r),r.cantidad,descuentoBusquedaRepuestos());marcarRepuestoIntegracion(codigo,r.regla_id,r.comportamiento==='OBLIGATORIO');}else if(ya){marcarRepuestoIntegracion(codigo,r.regla_id,r.comportamiento==='OBLIGATORIO');const f=[...document.querySelectorAll('#repuestos_seleccionados .repuesto-producto')].find(x=>String(x.querySelector('[data-campo="codigo"]')?.value||'').trim().toUpperCase()===codigo);if(f&&f.dataset.integracionAuto==='1'){const q=f.querySelector('[data-campo="cantidad"]');if(q&&String(r.cantidad_tipo||'')!=='MANUAL'){q.value=String(Math.max(1,Math.round(Number(r.cantidad||1))));normalizarCantidadRepuesto(q);}}}}
 );
 document.querySelectorAll('#repuestos_seleccionados .repuesto-producto[data-integracion-auto="1"]').forEach(function(f){const id=String(f.dataset.integracionRegla||'');if(id&&!vigentes.has(id)){f.remove();}});actualizarResumenDocumento();
}
function renderIntegracionRepuestos(reglas){const panel=document.getElementById('repuestos_integracion_panel'),lista=document.getElementById('repuestos_integracion_lista'),estado=document.getElementById('repuestos_integracion_estado');if(!panel||!lista)return;const sug=reglas.filter(r=>String(r.comportamiento)==='SUGERIR');const otros=reglas.filter(r=>String(r.comportamiento)!=='SUGERIR');if(!reglas.length){panel.style.display='none';lista.innerHTML='';return;}panel.style.display='block';if(estado)estado.textContent=otros.length?otros.length+' automáticas / obligatorias':'';lista.innerHTML='';reglas.forEach(function(r){const fila=document.createElement('div');fila.className='rep-integracion-fila';const tipo=String(r.comportamiento||'SUGERIR');const agregado=codigosRepuestosSeleccionados().has(String(r.codigo||'').toUpperCase());fila.innerHTML='<div class="ri-desc"><strong>'+escaparRep(r.codigo)+' · '+escaparRep(r.descripcion)+'</strong><small>'+escaparRep(r.origen_modulo+' / '+r.origen_tipo+' = '+r.origen_valor)+'</small></div><div><span class="ri-badge ri-'+tipo.toLowerCase()+'">'+tipo+'</span></div><div><strong>'+String(r.cantidad)+' u.</strong></div><div></div>';const ac=fila.lastElementChild;if(tipo==='SUGERIR'){const b=document.createElement('button');b.type='button';b.textContent=agregado?'Agregado':'Agregar';b.disabled=agregado||!r.costo_disponible;b.addEventListener('click',function(){agregarRepuestoSeleccionado(productoDesdeReglaIntegracion(r),r.cantidad,descuentoBusquedaRepuestos());programarIntegracionRepuestos(100);});ac.appendChild(b);}else ac.innerHTML='<small>'+(r.costo_disponible?'Aplicación automática':'Sin costo vigente')+'</small>';lista.appendChild(fila);});}
async function actualizarIntegracionRepuestos(){const generacionNueva=window.__cotizadorNuevaGeneracion||0;try{const res=await fetch('repuestos_integracion.php?'+parametrosIntegracionRepuestos().toString(),{cache:'no-store'});if(!res.ok)return;const data=await res.json();if(generacionNueva!==(window.__cotizadorNuevaGeneracion||0))return;const reglas=data.reglas||[];repIntegracionUltimas=new Map(reglas.map(r=>[String(r.regla_id),r]));aplicarReglasIntegracionAutomaticas(reglas);renderIntegracionRepuestos(reglas);}catch(e){if(generacionNueva===(window.__cotizadorNuevaGeneracion||0))console.warn('Integración de repuestos:',e);}}
function programarIntegracionRepuestos(ms=250){clearTimeout(repIntegracionTimer);repIntegracionTimer=setTimeout(actualizarIntegracionRepuestos,ms);}
document.addEventListener('change',function(e){if(e.target?.matches('#incluir_control,#id_cpu,#id_tipo_control,#id_subtipo,#cantidad_equipos,#senal_incluir_cotizacion,#senal_modelo,#incluir_accesorios,#incluir_iep,#lista_id,#items_accesorios input,#items_accesorios select,#items_especiales_accesorios_v33 input,#items_especiales_accesorios_v33 select'))programarIntegracionRepuestos();});
window.addEventListener('load',function(){programarIntegracionRepuestos(600);});
const reglaAlarmaEmergenciaV217=<?= json_encode($reglaAlarmaEmergenciaV217,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const preciosEspecialesV33=<?= json_encode($preciosEspeciales,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const utilidadesAccesoriosV224=<?= json_encode($utilidadesAccesoriosV224,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
function utilidadAccV224(clave){const u=Number(utilidadesAccesoriosV224[clave]||1);return u>0?u:1;}
const reglasSupervisorV33=<?= json_encode($supervisorReglas,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const reglasCableV33=<?= json_encode($cablesMallados,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const reglasControlAccesoV33=<?= json_encode($controlAccesoReglas,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const configurablesGuardadosV461=<?= json_encode($accesoriosConfigurablesGuardados ?? array(),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
let accesoriosConfigurablesRestauradosV461=false;
function restaurarConfigurablesAccesoriosV461(){
 if(accesoriosConfigurablesRestauradosV461)return;
 accesoriosConfigurablesRestauradosV461=true;
 const cfg=configurablesGuardadosV461||{};
 const sint=[]; // v462: A7600C se restaura como accesorio simple desde PHP.
 const barr=Array.isArray(cfg.BARRERAS)?cfg.BARRERAS:[];
 barr.forEach(function(it){
   const cod=String(it.codigo||'').trim(); if(!cod)return;
   const ch=document.querySelector('[data-barrera-codigo="'+CSS.escape(cod)+'"]');
   const q=document.querySelector('[data-barrera-cantidad="'+CSS.escape(cod)+'"]');
   if(ch)ch.checked=true;
   if(q)q.value=String(Math.max(1,Number(it.cantidad||1)));
 });
 const pes=cfg.PESADOR||null;
 if(pes){const e=document.getElementById('pesador_tipo');if(e)e.value=String(pes.codigo||'');}
 const cable=cfg.CABLE_MALLADO||null;
 if(cable){
   const metros=document.getElementById('cable_mallado_metros');
   if(metros)metros.value=String(Math.max(0,Number(cable.cantidad||0)));
   const codigo=String(cable.codigo||'').trim().toUpperCase();
   const regla=(reglasCableV33||[]).find(r=>String(r.codigo||'').trim().toUpperCase()===codigo);
   const manual=document.getElementById('cable_mallado_corriente_manual');
   if(manual&&regla){
     const d=Number(regla.corriente_desde||0),h=Number(regla.corriente_hasta||0),incD=Number(regla.incluye_desde||0)===1;
     let valor=d;
     if(!incD)valor=d+0.1;
     if(valor<=0&&h>0)valor=Math.min(1,h);
     manual.value=String(valor);
   }
 }
 const sup=cfg.SUPERVISOR||null;
 if(sup){
   const codigo=String(sup.codigo||'');
   const sc=document.getElementById('supervisor_cantidad'),sa=document.getElementById('supervisor_ascensores'),sb=document.getElementById('supervisor_baterias'),sp=document.getElementById('supervisor_puertos');
   if(sc)sc.value='1';
   const regla=(reglasSupervisorV33||[]).find(r=>codigo.toUpperCase().includes(String(r.codigo_software||'').toUpperCase()));
   if(sa&&regla)sa.value=String(Math.max(1,Number(regla.ascensores_desde||1)));
   const mBat=codigo.match(/(\d+)\s*[×xX]\s*A68S63\.XX/i); if(sb)sb.value=mBat?String(Number(mBat[1])):'0';
   const mPto=codigo.match(/(\d+)\s*[×xX]\s*A6811C/i); if(sp)sp.value=mPto?String(Number(mPto[1])):'0';
 }
 if(sint.length||barr.length||pes||cable||sup){actualizarEspecialesAccesorios();}
}
let corrienteVariadorV33=0;
function factorAccesoriosV33(){const d=[1,2,3].map(n=>Math.max(0,Math.min(100,Number(document.getElementById('accesorio_descuento_'+n)?.value||0))));return (1-d[0]/100)*(1-d[1]/100)*(1-d[2]/100);}
function precioEspV33(c){return Number(preciosEspecialesV33[c]||0);}function precioExcelV33(c){return Math.ceil(precioEspV33(c));}
function filaEspecialV33(clave,concepto,codigo,cantidad,precio,precioBase,clase){
  let f=document.querySelector('#items_especiales_accesorios_v33 [data-especial-clave="'+clave+'"]');
  const estabaSeleccionado=f?.dataset.seleccionado==='1';
  if(concepto){document.querySelectorAll('#items_accesorios .item-modular:not([data-especial-clave])').forEach(g=>{const gc=(g.querySelector('[data-campo="concepto"]')?.value||'').trim().toUpperCase();if(gc===String(concepto).trim().toUpperCase()||gc.startsWith(String(concepto).trim().toUpperCase()+' - ')){g.dataset.seleccionado='0';g.style.display='none';}});}
  if(!f){f=document.createElement('div');f.className='item-modular accesorio-item accesorio-catalogo-fila especial-auto';f.dataset.especialClave=clave;f.dataset.selectorKey=clave;f.innerHTML='<input type="hidden" data-campo="concepto"><input type="hidden" data-campo="codigo"><input type="hidden" data-campo="descripcion"><input type="hidden" data-campo="cantidad"><input type="hidden" data-campo="precio"><input type="hidden" class="accesorio-precio-base">';document.getElementById('items_especiales_accesorios_v33')?.appendChild(f);}
  f.style.display='none';f.dataset.seleccionado='0';f.classList.remove('especial-consultar');
  if(!concepto)return f;
  f.querySelector('[data-campo="concepto"]').value=concepto;f.querySelector('[data-campo="codigo"]').value=codigo||'';f.querySelector('[data-campo="descripcion"]').value=concepto;f.querySelector('[data-campo="cantidad"]').value=String(cantidad||1);f.querySelector('.accesorio-precio-base').value=String(Number(precioBase??precio??0));f.dataset.precioBase=String(Number(precioBase??precio??0));f.dataset.precioReferencia=String(Math.ceil(Number(precio||0)));f.querySelector('[data-campo="precio"]').value=f.dataset.bonificado==='1'?'0':String(Math.ceil(Number(precio||0)));
  const norm=normalizarConfigurableV460(clave);
  const esPreview=window.accesorioConfigPendienteV460===norm && !estabaSeleccionado;
  f.dataset.v460Preview=esPreview?'1':'0';f.dataset.seleccionado=esPreview?'0':'1';f.style.display='grid';if(clase)f.classList.add(clase);return f;
}
function quitarEspecialV33(clave){const f=document.querySelector('#items_especiales_accesorios_v33 [data-especial-clave="'+clave+'"]');if(f){f.dataset.seleccionado='0';f.style.display='none';}}
let sirenaEcoMidiSolicitud=0;
async function sincronizarSirenaEcoMidi(){
  const solicitud=++sirenaEcoMidiSolicitud;
  const rescate=document.getElementById('id_rescate')?.selectedOptions?.[0];
  const luz=document.querySelector('[data-senal-adicional="LUZ DE EMERGENCIA"] input[type="checkbox"]');
  const activo=!!luz?.checked && !!document.getElementById('incluir_control')?.checked && rescate?.dataset.clave==='HID_ECO_MIDI';
  const cantidad=Math.max(1,parseInt(document.getElementById('cantidad_equipos')?.value||'1',10)||1);
  let sirenaExistente=false;
  document.querySelectorAll('#items_accesorios .item-modular').forEach(f=>{
    if(String(f.querySelector('[data-campo="codigo"]')?.value||'').trim().toUpperCase()!=='6PS593')return;
    const seleccionar=activo && !sirenaExistente;
    f.dataset.seleccionado=seleccionar?'1':'0';f.style.display=seleccionar?'':'none';
    if(seleccionar){sirenaExistente=true;const q=f.querySelector('[data-campo="cantidad"]');if(q)q.value=String(cantidad);}
  });
  if(!activo){quitarEspecialV33('SIRENA_ECO_MIDI');actualizarResumenDocumento();return;}
  const existente=[...document.querySelectorAll('#items_accesorios .item-modular,#items_repuestos .repuesto-producto')].some(f=>f.dataset.seleccionado!=='0' && String(f.querySelector('[data-campo="codigo"]')?.value||'').trim().toUpperCase()==='6PS593');
  if(existente){quitarEspecialV33('SIRENA_ECO_MIDI');actualizarResumenDocumento();return;}
  try{
    const lista=document.getElementById('lista_id')?.value||'0';
    const respuesta=await fetch('buscar_repuestos.php?'+new URLSearchParams({q:'6PS593',lista_id:lista}),{cache:'no-store'});
    if(!respuesta.ok)return;
    const datos=await respuesta.json();
    if(solicitud!==sirenaEcoMidiSolicitud)return;
    const producto=(datos.resultados||[]).find(p=>String(p.codigo||'').trim().toUpperCase()==='6PS593'&&p.costo_disponible);
    if(!producto)return;
    filaEspecialV33('SIRENA_ECO_MIDI','Sirena automática Eco Midi Supra','6PS593',cantidad,Math.ceil(Number(producto.precio_30)),Number(producto.precio_base));
    const fila=document.querySelector('#items_especiales_accesorios_v33 [data-especial-clave="SIRENA_ECO_MIDI"]');
    if(fila)fila.querySelector('[data-campo="descripcion"]').value='Sirena De A0710';
    actualizarResumenDocumento();
  }catch(e){console.warn('Sirena Eco Midi Supra:',e);}
}
document.addEventListener('change',e=>{if(e.target?.matches('#id_rescate,#id_tipo_control,#id_subtipo,#cantidad_equipos,#incluir_control,#lista_id')){sincronizarSirenaEcoMidi();sincronizarAlarmaEmergenciaDesdeSenalizacionV203();}});
window.addEventListener('load',sincronizarSirenaEcoMidi);
function autoSeleccionarCatalogoV33(clave,cantidad,activar){const card=document.querySelector('.accesorio-catalogo-card[data-clave="'+clave+'"]');if(!card)return;const ch=card.querySelector('.accesorio-catalogo-check');const fila=card.querySelector('.item-modular');if(!ch||!fila)return;if(activar){if(fila.dataset.manualNoV33==='1')return;const eraAuto=fila.dataset.autoV33==='1';if(!ch.checked){window.autoSeleccionandoV33=true;ch.checked=true;toggleAccesorioCatalogo(ch);window.autoSeleccionandoV33=false;fila.dataset.autoV33='1';}const q=fila.querySelector('[data-campo="cantidad"]');if(q&&Number(cantidad)>0&&!eraAuto)q.value=String(cantidad);if(q&&Number(cantidad)>0&&clave==='ALARMA_EMERGENCIA_12V'&&fila.dataset.cantidadManualV33!=='1')q.value=String(cantidad);fila.dataset.autoV33='1';}else if(fila.dataset.autoV33==='1'){window.autoSeleccionandoV33=true;ch.checked=false;toggleAccesorioCatalogo(ch);window.autoSeleccionandoV33=false;fila.dataset.autoV33='0';fila.dataset.manualNoV33='0';}}
function sincronizarAlarmaEmergenciaDesdeSenalizacionV203(){
  // v217: la relación ya no está definida en JavaScript; se carga de automatizaciones_cotizador.
  const regla=reglaAlarmaEmergenciaV217;
  if(!regla || String(regla.regla_activa||'')!=='SI') return;
  const origen=String(regla.item_origen||'').trim();
  const destino=String(regla.item_destino||'').trim();
  if(!origen || !destino) return;
  const luz=document.querySelector('[data-senal-adicional="'+CSS.escape(origen)+'"] input[type="checkbox"]');
  const filaLuz=luz?.closest('[data-senal-adicional]');
  const cantOrigen=Math.max(0,Number(filaLuz?.querySelector('.senal-cant-adic')?.value||0));
  const card=document.querySelector('.accesorio-catalogo-card[data-clave="'+CSS.escape(destino)+'"]');
  const fila=card?.querySelector('.item-modular');
  let cantidad=Math.max(1,cantOrigen);
  if(String(regla.modo_cantidad||'MISMA')==='FIJA') cantidad=Math.max(1,Number(regla.cantidad_fija||1));
  const rescate=document.getElementById('id_rescate')?.selectedOptions?.[0];
  const usarSirena=!!document.getElementById('incluir_control')?.checked && rescate?.dataset.clave==='HID_ECO_MIDI';
  if(luz?.checked && !usarSirena && fila && String(regla.accion||'AGREGAR')==='AGREGAR'){
    fila.dataset.manualNoV33='0';
    fila.dataset.cantidadManualV33='0';
    autoSeleccionarCatalogoV33(destino,cantidad,true);
    const q=fila.querySelector('[data-campo="cantidad"]'); if(q)q.value=String(cantidad);
    fila.dataset.autoV33='1';
    const incluir=document.getElementById('incluir_accesorios'); if(incluir)incluir.checked=true;
    const selector=document.getElementById('accesorio_selector_rapido');
    const cantidadRapida=document.getElementById('accesorio_selector_cantidad');
    if(selector?.value===destino&&cantidadRapida)cantidadRapida.value=String(cantidad);
    actualizarResumenDocumento();
  }else if((!luz?.checked || usarSirena || String(regla.accion||'AGREGAR')!=='AGREGAR') && fila){
    if(!luz?.checked || usarSirena) fila.dataset.autoV33='1';
    autoSeleccionarCatalogoV33(destino,1,false);
  }
}
function cantidadParadasV33(){return Math.max(1,Number(document.getElementById('control_acceso_paradas')?.value||document.getElementById('senal_paradas')?.value||document.querySelector('[name="paradas_equipo[]"]')?.value||1));}
function adicionalesRangoV33(p){return p<=16?0:p<=32?1:p<=48?2:p<=64?3:0;}
async function actualizarCorrienteVariadorV33(){
 const manual=document.getElementById('cable_mallado_corriente_manual');
 const manualWrap=document.getElementById('cable_mallado_corriente_manual_wrap');
 const puedeAuto=moduloIncluido('control');
 if(!puedeAuto){
   corrienteVariadorV33=0;
   if(manual){manual.disabled=false;manual.readOnly=false;}
   if(manualWrap){manualWrap.classList.remove('corriente-auto-v459');manualWrap.style.display='';}
   actualizarEspecialesAccesorios();
   return;
 }
 const fd=new FormData();['id_cpu','id_tipo_control','id_subtipo','id_tension','potencia_hp'].forEach(id=>fd.append(id,document.getElementById(id)?.value||''));const enc=document.querySelector('[name="encoder"]');if(enc?.checked)fd.append('encoder','SI');
 try{const r=await fetch('get_corriente_variador.php?_='+Date.now(),{method:'POST',body:fd,cache:'no-store'});const j=await r.json();const corrienteConsulta=j.ok?Number(j.corriente||0):0;if(corrienteConsulta>0)corrienteVariadorV33=corrienteConsulta;}catch(e){}
 if(manual){manual.disabled=corrienteVariadorV33>0;manual.readOnly=corrienteVariadorV33>0;}
 if(manualWrap){
   manualWrap.classList.toggle('corriente-auto-v459',corrienteVariadorV33>0);
   // v473: con corriente automática desde Control no mostrar un segundo campo redundante.
   // Si no hay dato automático, el campo queda visible como respaldo para cotizaciones sin Control.
   manualWrap.style.display=corrienteVariadorV33>0?'none':'';
 }
 actualizarEspecialesAccesorios();
}
function indicadorColorSintetizadorV474(){
 const selIndicador=document.getElementById('senal_indicador_modelo');
 const modelo=String(selIndicador?.value||'');
 const textoIndicador=String(selIndicador?.selectedOptions?.[0]?.textContent||'');
 // v476: contemplar tanto el valor como el texto visible del selector.
 // Algunos maestros muestran A4820/A4830 en la descripcion aunque el valor cambie de formato.
 if(/A?48(?:20|30)/i.test(modelo+' '+textoIndicador)) return true;
 // Compatibilidad con la cantidad legacy/historica del A4830.
 if(Number(document.querySelector('[name="senal_indicador_a4830_cantidad"]')?.value||0)>0) return true;
 // ONIX INDIVIDUALES lleva A4830 Crystal Color incluido en la botonera.
 const selModelo=document.getElementById('senal_modelo');
 const texto=String(selModelo?.selectedOptions?.[0]?.textContent||'').toUpperCase();
 return texto.includes('ONIX INDIVIDUALES') || texto.includes('ONIX PULS');
}
function actualizarSintetizadoresSenalV474(origen){
 const a7601=document.getElementById('senal_sint_a7601c');
 const a4820=document.getElementById('senal_sint_a4820sv');
 const q7601=document.getElementById('senal_sint_a7601c_cantidad');
 const q4820=document.getElementById('senal_sint_a4820sv_cantidad');
 const compatible=indicadorColorSintetizadorV474();
 const bloque4820=document.getElementById('senal_sint_a4820sv_bloque');
 if(bloque4820) bloque4820.dataset.compatible=compatible?'1':'0';
 if(!compatible && a4820?.checked){a4820.checked=false;a4820.dataset.manual='';}
 if(origen==='A7601C' && a7601?.checked && a4820?.checked){a4820.checked=false;a4820.dataset.manual='';}
 if(origen==='A4820SV' && a4820?.checked && a7601?.checked){a7601.checked=false;a7601.dataset.manual='';}
 const base=Math.max(1,Number(document.querySelector('[name="senal_cantidad"]')?.value||cantidadInicialSenalDesdeControlV161()||1));
 const cantIndic=Math.max(0,Number(document.getElementById('senal_indicador_cantidad')?.value||base));
 if(a7601?.checked && q7601 && q7601.dataset.manual!=='1' && Number(q7601.value||0)<=0) q7601.value=String(base);
 if(a4820?.checked && q4820 && q4820.dataset.manual!=='1') q4820.value=String(cantIndic||base);
}
function sincronizarAdicionalSintetizadorIndicadorV169(){
 // v474: elegir A4820/A4830 NO agrega el sintetizador automáticamente.
 // Sólo habilita esa variante; el usuario decide si la incluye.
 actualizarSintetizadoresSenalV474('INDICADOR');
}
function actualizarControlAccesoSenalV169(){
 const inc=document.getElementById('senal_control_acceso_incluir');
 const activo=!!inc?.checked;
 const t=document.getElementById('senal_control_acceso_tecnologia')?.value||'';
 const qw=document.getElementById('senal_control_cantidad_wrap');
 const aw=document.getElementById('senal_control_alcance_wrap');
 const pw=document.getElementById('senal_control_paradas_wrap');
 const cw=document.getElementById('senal_control_chips_wrap');
 const tw=document.getElementById('senal_control_tarjetas_wrap');
 const configurado=activo&&(t==='CHIP'||t==='TARJETA'||t==='TECLADO');
 const mostrar=(el,on)=>{if(!el)return;el.style.setProperty('display',on?'':'none','important');};
 mostrar(qw,activo);
 mostrar(aw,configurado);
 mostrar(pw,configurado);
 mostrar(cw,configurado&&t==='CHIP');
 mostrar(tw,configurado&&t==='TARJETA');
 if(activo){
   const cant=document.getElementById('senal_control_acceso_cantidad');
   const cantBase=Math.max(1,parseInt(document.querySelector('[name="senal_cantidad"]')?.value||cantidadInicialSenalDesdeControlV161()||'1',10)||1);
   if(cant && cant.dataset.manual!=='1') cant.value=String(cantBase);
 }
 if(configurado){
   const par=document.getElementById('senal_control_acceso_paradas');
   const parBase=Math.max(1,parseInt(document.getElementById('senal_paradas')?.value||'1',10)||1);
   if(par && par.dataset.manual!=='1') par.value=String(parBase);
   const chips=document.getElementById('senal_control_acceso_chips_cantidad');
   const tarjetas=document.getElementById('senal_control_acceso_tarjetas_cantidad');
   if(t==='CHIP' && chips && Number(chips.value||0)<=0) chips.value='1';
   if(t==='TARJETA' && tarjetas && Number(tarjetas.value||0)<=0) tarjetas.value='1';
 }
}
function sincronizarEspecialesV169DesdeControl(){
 if(!senalCantidadesInicialesNuevaV161)return; const q=cantidadInicialSenalDesdeControlV161();
 ['senal_sint_a7601c_cantidad','senal_sint_a4820sv_cantidad','senal_pesador_frente_cantidad'].forEach(id=>{const e=document.getElementById(id);if(e&&e.dataset.manual!=='1')e.value=String(q);});
 const bafle=document.querySelector('[data-sint-cantidad="A7600C"]');if(bafle&&bafle.dataset.manual!=='1')bafle.value=String(q);
 const par=document.getElementById('senal_control_acceso_paradas');if(par&&par.dataset.manual!=='1'){const p=parseInt(document.getElementById('senal_paradas')?.value||'0',10)||1;par.value=String(Math.max(1,p));}
 const cantCA=document.getElementById('senal_control_acceso_cantidad');if(cantCA&&cantCA.dataset.manual!=='1')cantCA.value=String(Math.max(1,q));
}
function actualizarEspecialesAccesorios(){
 const factor=factorAccesoriosV33(); // v34: factor encadenado 30/10/10 editable para especiales
 // Sintetizadores manuales.
 document.querySelectorAll('[data-sint-codigo]').forEach(ch=>{const c=ch.dataset.sintCodigo;if(c!=='A7600C'){quitarEspecialV33('SINT_'+c);return;}const base=precioEspV33(c)*utilidadAccV224('SINTETIZADORES_VOZ'),q=Math.max(0,Number(document.querySelector('[data-sint-cantidad="'+c+'"]')?.value||0));if(ch.checked&&base>0&&q>0)filaEspecialV33('SINT_'+c,'SINTETIZADOR DE VOZ EN BAFLE',c,q,Math.ceil(base*factor),base);else quitarEspecialV33('SINT_'+c);});
 // Barreras solo PA.
 const pa=(document.getElementById('senal_tipo_puerta')?.value||'')==='PA';const bb=document.getElementById('bloque_barreras_v33');if(bb)bb.style.display=pa?'block':'none';document.querySelectorAll('[data-barrera-codigo]').forEach(ch=>{if(!pa)ch.checked=false;const c=ch.dataset.barreraCodigo,b=precioEspV33(c)*utilidadAccV224('BARRERAS'),q=Math.max(1,Number(document.querySelector('[data-barrera-cantidad="'+c+'"]')?.value||1));if(pa&&ch.checked&&b>0)filaEspecialV33('BARR_'+c,ch.closest('label')?.querySelector('span')?.childNodes[0]?.textContent?.trim()||'Barrera',c,q,Math.ceil(b*factor),b);else quitarEspecialV33('BARR_'+c);});
 // Cable mallado: corriente automática desde Control o manual si se cotiza de forma independiente.
 const metros=Math.max(0,Number(document.getElementById('cable_mallado_metros')?.value||0));
 const manualCable=Math.max(0,Number(document.getElementById('cable_mallado_corriente_manual')?.value||0));
 const corrienteCable=corrienteVariadorV33>0?corrienteVariadorV33:manualCable;
 let rc=null;
 if(corrienteCable>0) rc=reglasCableV33.find(r=>{const d=Number(r.corriente_desde),h=Number(r.corriente_hasta);return (Number(r.incluye_desde)?corrienteCable>=d:corrienteCable>d)&&(Number(r.incluye_hasta)?corrienteCable<=h:corrienteCable<h);});
 const mod=document.getElementById('cable_mallado_modelo'),cor=document.getElementById('cable_mallado_corriente'),estadoCable=document.getElementById('cable_mallado_estado'),resultadoCable=document.getElementById('cable_mallado_resultado');
 if(mod)mod.value=rc?(rc.modelo+' · '+rc.codigo):'';
 if(cor)cor.value=corrienteVariadorV33>0?corrienteVariadorV33+' A':'';
 if(rc&&metros>0){
   const b=precioEspV33(rc.codigo),unit=Math.ceil(b*factor);
   if(b>0){
     filaEspecialV33('CABLE_MALLADO','Cable mallado '+rc.modelo,rc.codigo,metros,unit,b);
     if(estadoCable)estadoCable.textContent=(corrienteVariadorV33>0?'Corriente tomada automáticamente del Control: ':'Corriente indicada: ')+corrienteCable+' A.';
     if(resultadoCable)resultadoCable.textContent=rc.modelo+' · '+rc.codigo+' · '+metros+' m · $ '+unit.toLocaleString('es-AR')+' por metro';
   }else{
     quitarEspecialV33('CABLE_MALLADO');
     if(resultadoCable)resultadoCable.textContent='El código '+rc.codigo+' no tiene costo en la base Bejerman seleccionada.';
   }
 }else{
   quitarEspecialV33('CABLE_MALLADO');
   if(estadoCable)estadoCable.textContent=corrienteCable<=0?'Complete un Control para tomar automáticamente la corriente del variador. Si cotiza sin Control, indique la corriente.':'Corriente '+corrienteCable+' A: '+(rc?'ingrese los metros.':'no existe un rango de cable configurado.');
   if(resultadoCable)resultadoCable.textContent=corrienteCable<=0?'Falta corriente para determinar el cable.':(rc?'Faltan los metros a cotizar.':'No hay regla de cable mallado para esa corriente.');
 }
 // Pesador = base; el frente se resuelve en Señalización.
 const pc=document.getElementById('pesador_tipo')?.value||'';
 const pesEstado=document.getElementById('pesador_estado');
 if(pc){
   const base=precioEspV33(pc)*utilidadAccV224('PESADOR_CARGA'),factorPesador=factorDescuentosAccesorios();
   if(base>0){const variante=document.getElementById('pesador_tipo')?.selectedOptions?.[0]?.dataset.tipo||pc;filaEspecialV33('PESADOR','Pesador de carga '+variante,pc,1,Math.ceil(base*factorPesador),base);if(pesEstado)pesEstado.textContent=pc+' · $ '+Math.ceil(base*factorPesador).toLocaleString('es-AR');}
   else{quitarEspecialV33('PESADOR');if(pesEstado)pesEstado.textContent='El código '+pc+' no tiene costo en la base Bejerman seleccionada.';}
 }else{quitarEspecialV33('PESADOR');if(pesEstado)pesEstado.textContent='Seleccione el tipo de pesador.';}
 // v169: Control de accesos se valoriza exclusivamente en Señalización -> Botonera de cabina.
 quitarEspecialV33('CONTROL_ACCESO');
 // Supervisor NETO. Cantidad funciona como activador, igual que Excel; si >8 CONSULTAR.
 const sc=Math.max(0,Number(document.getElementById('supervisor_cantidad')?.value||0)),sa=Math.max(0,Number(document.getElementById('supervisor_ascensores')?.value||0)),sb=Math.max(0,Number(document.getElementById('supervisor_baterias')?.value||0)),sp=Math.max(0,Number(document.getElementById('supervisor_puertos')?.value||0)),se=document.getElementById('supervisor_estado');
 if(sc>=1&&sa>8){filaEspecialV33('SUPERVISOR','Sistema Supervisor - CONSULTAR','CONSULTAR',1,0,0,'especial-consultar');if(se)se.textContent='Más de 8 ascensores: CONSULTAR. No se calcula ni extrapola automáticamente.';}
 else if(sc>=1&&sa>=1){
   const rr=reglasSupervisorV33.find(r=>sa>=Number(r.ascensores_desde)&&sa<=Number(r.ascensores_hasta));
   if(rr){const total=precioExcelV33(rr.codigo_software)+sb*precioExcelV33('A68S63.XX')+sp*precioExcelV33('A6811C');const cod=[rr.codigo_software,sb?sb+' × A68S63.XX':'',sp?sp+' × A6811C':''].filter(Boolean).join(' + ');if(total>0){filaEspecialV33('SUPERVISOR','Sistema Supervisor NETO',cod,1,Math.ceil(total),total);if(se)se.textContent='NETO sin descuentos · '+cod+' · $ '+Math.ceil(total).toLocaleString('es-AR');}else{quitarEspecialV33('SUPERVISOR');if(se)se.textContent='La configuración no tiene precio vigente en Bejerman.';}}
   else{quitarEspecialV33('SUPERVISOR');if(se)se.textContent='No existe una regla de Supervisor para '+sa+' ascensor(es).';}
 }else{quitarEspecialV33('SUPERVISOR');if(se)se.textContent='Indique cantidad de sistemas y cantidad de ascensores.';}
 // Luz emergencia -> sugerencia de alarma 12V 1:1.
 sincronizarAlarmaEmergenciaDesdeSenalizacionV203();
 actualizarResumenDocumento();actualizarDesgloseAccesorios();actualizarListaAccesoriosCompacta();
 ['BARRERAS','LIMITES','PESADOR','SUPERVISOR','CABLE_MALLADO'].forEach(actualizarAccionConfigurableV460);
}
document.addEventListener('input',function(e){if(e.target.classList&&e.target.classList.contains('repuesto-desc-editor')){const fila=e.target.closest('.repuesto-agregado');const oculto=fila?.querySelector('[data-campo="descripcion"]');if(oculto)oculto.value=e.target.value;}});
function actualizarTotalRepuesto(f){const cantidad=Number(f.querySelector('[data-campo="cantidad"]')?.value||1);const precio=Number(f.querySelector('[data-campo="precio"]')?.value||0);const total=f.querySelector('.repuesto-total-visible');if(total)total.value=String(Math.ceil(cantidad*precio));}
function actualizarTotalManualRepuesto(input){const f=input.closest('.repuesto-manual');if(!f)return;const cantidad=Math.max(1,Number(f.querySelector('[data-campo="cantidad"]')?.value||1));const precioInput=f.querySelector('[data-campo="precio"]');const precio=Math.ceil(Math.max(0,Number(precioInput?.value||0)));if(precioInput)precioInput.value=String(precio);const total=f.querySelector('.repuesto-total-manual');if(total)total.value=String(Math.ceil(cantidad*precio));actualizarEstadoRepuestoManual(f);actualizarResumenDocumento();}
function actualizarPrecioRepuesto(sel){const f=sel.closest('.repuesto-agregado');if(!f)return;const d=sel.value;const p=Number(d==='15'?f.dataset.precio15:d==='30'?f.dataset.precio30:f.dataset.precioBase);const pe=Math.ceil(p);f.querySelector('[data-campo="precio"]').value=String(pe);f.querySelector('.repuesto-precio-visible').value=String(pe);f.querySelector('[data-campo="concepto"]').value='Repuesto'+(d==='0'?'':' - descuento '+d+'%');actualizarTotalRepuesto(f);actualizarResumenDocumento();}
function quitarRepuesto(btn){btn.closest('.repuesto-agregado')?.remove();const c=document.getElementById('repuestos_seleccionados');if(c&&!c.querySelector('.repuesto-producto'))c.innerHTML='<div class="repuestos-vacio">Todavía no agregó repuestos de catálogo.</div>';actualizarEstadoResultadosRepuestos();actualizarResumenDocumento();}
document.addEventListener('DOMContentLoaded',()=>{restaurarConfigurablesAccesoriosV461();actualizarListaAccesoriosCompacta();const input=document.getElementById('buscar_repuesto'),categoria=document.getElementById('categoria_repuesto');if(input)input.addEventListener('input',()=>{clearTimeout(temporizadorBusquedaRepuestos);temporizadorBusquedaRepuestos=setTimeout(buscarRepuestosPredictivo,220);});if(categoria)categoria.addEventListener('change',buscarRepuestosPredictivo);document.querySelectorAll('input[name="repuesto_descuento_busqueda"]').forEach(r=>r.addEventListener('change',actualizarColumnasDescuentoRepuestos));actualizarEstadoResultadosRepuestos();});
</script>
</div>

<script src="cotizador-core.js?v=15-plantillas-03"></script>













<!-- AUTOMAC v302 - Mesa compacta de cotizacion. Solo escritorio. -->




<!-- AUTOMAC v303 - Ajuste fino de la mesa + menus -->




<!-- AUTOMAC v304 - Encabezados integrados dentro de cada modulo -->




<!-- AUTOMAC v306 - Correccion controlada sobre v304: tipografia + resumen estable -->

<!-- v306 JS removed in v310: global MutationObserver conflicted with compact tabs -->


<!-- AUTOMAC v307 - resumen independiente + tipografia consistente + buscador de repuestos flotante -->




<!-- AUTOMAC v311 - corrige reaparicion de IEP/Repuestos + mantiene solapas y resumen ocultable -->




<!-- AUTOMAC v312 - boton manual de calculo de Control + compatibilidad con vista compacta -->





<!-- AUTOMAC v317 - cálculo automático independiente del cliente -->







<script src="cotizador-senalizacion-a.js?v=486"></script>





































<!-- AUTOMAC COMPACTO v354 -->
<div id="modal_especificaciones_cliente" role="dialog" aria-modal="true" aria-labelledby="modal_especificaciones_titulo"><div class="box"><div class="top"><div><h3 id="modal_especificaciones_titulo">Especificaciones técnicas del cliente</h3><div style="font-size:11px;color:#6d7f8d;margin-top:3px">Revise estos datos y cierre la ventana para continuar cotizando normalmente.</div></div><button type="button" class="close" onclick="cerrarEspecificacionesTecnicasCliente()">Cerrar</button></div><div id="modal_especificaciones_contenido"></div></div></div>






<!-- AUTOMAC v367 - Señalizacion siempre contenida en su columna -->





<!-- AUTOMAC v375 · indicador maestro A4000 / repetidor A4400 -->

<script id="automac-v375-indicador-maestro-js">
(function(){
 const savedCabRol=<?= json_encode((string)($datos['senal_indicador_rol'] ?? 'MAESTRO'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const savedParadas=<?= json_encode((string)($datos['senal_indicador_maestro_paradas'] ?? ($datos['senal_paradas'] ?? '')),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const fams=['SIMPLE_IP','DOBLE_IP'];
 const pref=f=>'senal_ext_'+String(f).toLowerCase()+'_';
 function byId(i){return document.getElementById(i)}
 function esElectromecanico(){const t=tipoModuloSenalizacionActual();return t.includes('ELECTROMEC')}
 function canon(t){return String(t||'').toUpperCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/\s*\(\s*BASE\s+A4[04]00\s*\)\s*/g,' ').replace(/\s+/g,' ').trim()}
 function tipoPorRol(r){return String(r||'REPETIDOR').toUpperCase()==='MAESTRO'?'ELECTROMECANICO':'ELECTRONICO'}
 function crearSelectRol(id,name,value){const d=document.createElement('div');d.className='campo v375-rol-indicador';d.innerHTML='<label>Función del indicador</label><select id="'+id+'"'+(name?' name="'+name+'"':'')+'><option value="MAESTRO">Maestro · Base A4000</option><option value="REPETIDOR">Repetidor · Base A4400</option></select><small>Solo un maestro A4000 por sistema.</small>';const s=d.querySelector('select');s.value=String(value||'MAESTRO').toUpperCase()==='REPETIDOR'?'REPETIDOR':'MAESTRO';return d}
 function instalar(){
   const modelo=byId('senal_indicador_modelo');
   if(modelo&&!byId('senal_indicador_rol')){const r=crearSelectRol('senal_indicador_rol','senal_indicador_rol',savedCabRol);modelo.closest('.campo')?.after(r);r.querySelector('select').addEventListener('change',()=>{filtrarCabinaV375(true);actualizarParadas();programarCalculoSenalizacion(80)});}
   const base=byId('senal_paso_indicador_cuerpo');
   if(base&&!byId('v375_maestro_paradas_global')){const d=document.createElement('div');d.id='v375_maestro_paradas_global';d.innerHTML='<div><strong>Paradas del sistema autónomo A4000</strong><small>Obligatorio cuando existe un indicador maestro. Define APPIND y el material de hueco.</small></div><input type="number" min="1" step="1" name="senal_indicador_maestro_paradas" id="senal_indicador_maestro_paradas" value="'+String(savedParadas||'').replace(/"/g,'&quot;')+'" placeholder="Paradas">';base.appendChild(d);d.querySelector('input').addEventListener('input',()=>programarCalculoSenalizacion(100));}
   fams.forEach(f=>{const p=pref(f), sel=byId(p+'indicador_codigo');if(sel&&!byId(p+'indicador_rol')){const r=crearSelectRol(p+'indicador_rol','', 'REPETIDOR');sel.closest('.campo')?.before(r);r.querySelector('select').addEventListener('change',()=>{refrescarIndicadorExteriorV181(f,false);actualizarParadas();});}});
   const ext=byId('senal_indicador_ext_modelo_v190');if(ext&&!byId('senal_indicador_ext_rol_v375')){const r=crearSelectRol('senal_indicador_ext_rol_v375','', 'REPETIDOR');ext.closest('.campo')?.before(r);r.querySelector('select').addEventListener('change',()=>{filtrarExtV375(true);actualizarParadas();});}
   filtrarCabinaV375(false);fams.forEach(f=>refrescarIndicadorExteriorV181(f,false));filtrarExtV375(false);actualizarParadas();
 }
 function filtrarCabinaV375(cambiar){const sel=byId('senal_indicador_modelo'), rol=byId('senal_indicador_rol');if(!sel)return;const em=esElectromecanico();if(rol?.closest('.campo'))rol.closest('.campo').style.display=em?'':'none';const target=em?tipoPorRol(rol?.value):'ELECTRONICO';const antes=sel.value, cf=canon(antes);let elegido='';[...sel.options].forEach(o=>{if(!o.value){o.hidden=false;o.disabled=false;return}const tipos=String(o.dataset.tipos||'').toUpperCase().split('|');const ok=tipos.includes(target);o.hidden=!ok;o.disabled=!ok;if(ok&&canon(o.value)===cf)elegido=o.value});if(antes&&sel.selectedOptions[0]?.disabled){sel.value=elegido||''}if(cambiar&&sel.value!==antes)normalizarIndicadorSenalizacion();}
 const oldFiltrar=window.filtrarIndicadoresPorTipoSenalizacion;window.filtrarIndicadoresPorTipoSenalizacion=function(){filtrarCabinaV375(false)};
 const oldRef=window.refrescarIndicadorExteriorV181;window.refrescarIndicadorExteriorV181=function(fam,usuario){if(!String(fam).endsWith('_IP'))return oldRef?.(fam,usuario);const p=pref(fam),sel=byId(p+'indicador_codigo'),preview=byId(p+'indicador_modelo_preview');if(!sel)return;const role=esElectromecanico()?String(byId(p+'indicador_rol')?.value||'REPETIDOR'):'REPETIDOR';if(byId(p+'indicador_rol')?.closest('.campo'))byId(p+'indicador_rol').closest('.campo').style.display=esElectromecanico()?'':'none';const tipo=esElectromecanico()?tipoPorRol(role):'ELECTRONICO';const rows=matrizIndicadoresMaestraV190().filter(r=>Number(r.activo)!==0&&normalizarTextoPulsadorV179(r.tipo_modulos)===tipo&&!String(r.modelo_indicador||'').toUpperCase().includes('BEAGLEBOND'));const actual=String(sel.value||sel.dataset.valor||'').toUpperCase(), oldRow=matrizIndicadoresMaestraV190().find(r=>String(r.codigo||'').toUpperCase()===actual), oldCanon=canon(oldRow?.modelo_indicador||'');sel.innerHTML='<option value="">Seleccione...</option>'+rows.map(r=>'<option value="'+String(r.codigo||'').replace(/&/g,'&amp;').replace(/"/g,'&quot;')+'">'+String(r.modelo_indicador||'')+' · '+String(r.codigo||'')+'</option>').join('');let candidate=rows.find(r=>String(r.codigo||'').toUpperCase()===actual)||rows.find(r=>canon(r.modelo_indicador)===oldCanon);sel.value=candidate?String(candidate.codigo||''):'';sel.dataset.valor='';const rr=rows.find(x=>String(x.codigo||'')===String(sel.value||''));if(preview)preview.textContent=rr?(String(rr.modelo_indicador||'')+' · '+String(rr.codigo||'')+' · '+(role==='MAESTRO'?'maestro A4000':'repetidor A4400')):'Seleccione el indicador.';const codigoPul=String(byId(p+'codigo_preview')?.textContent||'').trim();actualizarPrecioPulsadorV182(fam,codigoPul);if(usuario)programarCalculoSenalizacion(100)};
 const oldCapt=window.capturarItemPulsadorV188;window.capturarItemPulsadorV188=function(fam){const it=oldCapt(fam);if(it&&String(fam).endsWith('_IP'))it.indicador_rol=esElectromecanico()?String(byId(pref(fam)+'indicador_rol')?.value||'REPETIDOR'):'REPETIDOR';return it};
 const oldEdit=window.editarItemPulsadorV188; if(typeof oldEdit==='function')window.editarItemPulsadorV188=function(fam,idx){const arr=itemsPulsadoresV188(),it=arr[idx];oldEdit(fam,idx);if(it&&String(it.familia).endsWith('_IP')){const r=byId(pref(it.familia)+'indicador_rol');if(r)r.value=it.indicador_rol||'REPETIDOR';refrescarIndicadorExteriorV181(it.familia,false)}actualizarParadas()};
 function filtrarExtV375(cambiar){const sel=byId('senal_indicador_ext_modelo_v190'),rol=byId('senal_indicador_ext_rol_v375');if(!sel)return;const em=esElectromecanico();if(rol?.closest('.campo'))rol.closest('.campo').style.display=em?'':'none';const target=em?tipoPorRol(rol?.value):'ELECTRONICO',antes=sel.value,old=sel.selectedOptions[0],cf=canon(old?.dataset.modelo||'');let cand='';[...sel.options].forEach(o=>{if(!o.value){o.hidden=false;o.disabled=false;return}const ok=String(o.dataset.tipo||'').toUpperCase()===target&&!String(o.dataset.modelo||'').toUpperCase().includes('BEAGLEBOND');o.hidden=!ok;o.disabled=!ok;if(ok&&canon(o.dataset.modelo)===cf)cand=o.value});if(antes&&sel.selectedOptions[0]?.disabled)sel.value=cand||'';if(cambiar)actualizarPrecioIndicadorExteriorV190()}
 const oldFilterExt=window.filtrarIndicadoresExteriorControlV190;window.filtrarIndicadoresExteriorControlV190=function(){filtrarExtV375(false);fams.forEach(f=>refrescarIndicadorExteriorV181(f,false))};
 const oldCapExt=window.capturarIndicadorExteriorV190;window.capturarIndicadorExteriorV190=function(){const it=oldCapExt();if(it)it.rol=esElectromecanico()?String(byId('senal_indicador_ext_rol_v375')?.value||'REPETIDOR'):'REPETIDOR';return it};
 const oldEditExt=window.editarIndicadorExteriorV190;window.editarIndicadorExteriorV190=function(i){const it=itemsIndicadoresExteriorV190()[i];oldEditExt(i);const r=byId('senal_indicador_ext_rol_v375');if(r)r.value=it?.rol||'REPETIDOR';filtrarExtV375(false);actualizarParadas()};
 const oldRenderExt=window.renderIndicadoresExteriorV190;window.renderIndicadoresExteriorV190=function(){oldRenderExt();document.querySelectorAll('#senal_indicadores_ext_items_list_v190>div').forEach((row,i)=>{const it=itemsIndicadoresExteriorV190()[i];if(!it)return;const strong=row.querySelector('strong');if(strong&&esElectromecanico())strong.innerHTML+='<br><span class="'+(it.rol==='MAESTRO'?'v375-maestro-chip':'v375-repetidor-chip')+'">'+(it.rol==='MAESTRO'?'MAESTRO · BASE A4000':'REPETIDOR · BASE A4400')+'</span>'})};
 function maestrosActuales(){let n=0;if(esElectromecanico()){if(byId('senal_indicador_modelo')?.value&&String(byId('senal_indicador_rol')?.value||'MAESTRO')==='MAESTRO')n++;try{itemsPulsadoresV188().forEach(it=>{if(String(it.familia||'').endsWith('_IP')&&String(it.indicador_rol||'')==='MAESTRO')n++});itemsIndicadoresExteriorV190().forEach(it=>{if(String(it.rol||'')==='MAESTRO')n++})}catch(_){}}return n}
 function actualizarParadas(){const w=byId('v375_maestro_paradas_global');if(!w)return;w.classList.toggle('visible',esElectromecanico()&&maestrosActuales()>0)}
 const oldGuardarP=window.guardarItemsPulsadoresV188;if(typeof oldGuardarP==='function')window.guardarItemsPulsadoresV188=function(r){oldGuardarP(r);actualizarParadas()};
 const oldGuardarI=window.guardarIndicadoresExteriorV190;if(typeof oldGuardarI==='function')window.guardarIndicadoresExteriorV190=function(r){oldGuardarI(r);actualizarParadas()};
 document.addEventListener('change',e=>{if(e.target?.id==='senal_usar_control'){setTimeout(()=>{filtrarCabinaV375(true);fams.forEach(f=>refrescarIndicadorExteriorV181(f,false));filtrarExtV375(true);actualizarParadas()},0)}if(e.target?.id==='senal_indicador_modelo')actualizarParadas()});
 if(document.readyState==='loading')document.addEventListener('DOMContentLoaded',instalar);else instalar();
 setTimeout(instalar,300);setTimeout(instalar,900);
})();
</script>
<!-- AUTOMAC v375 -->



<!-- AUTOMAC v377 · paradas visibles para indicador suelto maestro A4000 -->

<script src="cotizador-senalizacion-b.js?v=ui02-20260921"></script>
<!-- /AUTOMAC v377 -->


<!-- AUTOMAC v378 · ficha completa indicador suelto -->


<!-- /AUTOMAC v378 -->






<!-- AUTOMAC v384 · alinear inclusiones al inicio de los tres modulos -->



<!-- AUTOMAC v385 · inclusiones realmente al inicio de cada modulo -->



<!-- AUTOMAC v391 - iconos y finalizacion de precarga -->
<script src="cotizador-ui.js?v=15-plantillas-03"></script>





<!-- AUTOMAC v394 - resumen flotante + mini total -->

<!-- /AUTOMAC v394 -->





<!-- V1.5: one workspace and one permanent summary. -->
<link id="v15_estilos_activos" rel="stylesheet" href="cotizador-v15.css?v=15-plantillas-03">
<script src="cotizador-v15.js?v=15-nueva-01"></script>
<script src="cotizador-nueva-sin-recarga.js?v=1"></script>

</body>
</html>

<!-- AUTOMAC v366 · indicadores por destino + material de hueco autonomo en OF -->

<!-- AUTOMAC v379 · indicadores contenidos + ficha IP exterior completa -->


<!-- /AUTOMAC v379 -->

<!-- AUTOMAC v386 - Resumen mas alto y acciones finales jerarquizadas -->


<!-- AUTOMAC v390 - UI/UX productiva, sin cambios de logica -->

<!-- /AUTOMAC v390 -->
