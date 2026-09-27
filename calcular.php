<?php

function formatoPrecioEntero($valor) { return number_format(ceil((float)$valor), 0, ',', '.'); }
session_start();

include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
$conexion->set_charset('utf8mb4');
require_once 'sistema_comercial.php';
require_once 'control_parametros.php';
require_once 'parametros_sistema.php';
require_once 'documentos_pdf.php';
require_once 'modulos_libres.php';
require_once 'documentos_modulares.php';
require_once 'senalizacion_cabina.php';
require_once 'documentos_eventos.php';
asegurarSistemaComercial($conexion);
automacExigirPostConCsrf();

function volverConError($mensaje)
{
    $_SESSION['form_data'] = $_POST;

    /*
     * El cálculo se muestra dentro de un iframe en index.php.
     * En modo auxiliar no se redirige nuevamente a index.php porque eso
     * duplicaría todo el cotizador dentro del área de resultados.
     */
    $modoAuxiliar = isset($_POST['modo_auxiliar']) && $_POST['modo_auxiliar'] === '1';

    if ($modoAuxiliar) {
        http_response_code(422);
        $mensajeSeguro = htmlspecialchars((string)$mensaje, ENT_QUOTES, 'UTF-8');
        echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1.0">'
            . '<title>No se pudo calcular</title><style>'
            . 'body{font-family:Arial,sans-serif;background:#fff;margin:0;padding:12px;color:#202124}'
            . '.error-calculo{background:#fdecec;border:1px solid #f4b6b6;color:#842029;border-radius:6px;padding:12px;font-size:13px}'
            . '.error-calculo strong{display:block;margin-bottom:5px}'
            . '</style></head><body><div class="error-calculo">'
            . '<strong>No se pudo realizar el cálculo.</strong>' . $mensajeSeguro
            . '</div></body></html>';
        exit;
    }

    $_SESSION['form_error'] = $mensaje;
    header('Location: index.php');
    exit;
}

function escapar($valor)
{
    return htmlspecialchars($valor ?? '', ENT_QUOTES, 'UTF-8');
}

function buscarAdicionalPrecio($conexion, $codigo)
{
    $codigo = trim((string)$codigo);
    if ($codigo === '') volverConError('La opción seleccionada no tiene un código adicional configurado.');
    global $listaId;
    $adicional = precioDeLista($conexion, $listaId, $codigo);
    if (!$adicional) volverConError('El código adicional ' . $codigo . ' no existe en la base Bejerman vigente.');
    if (!isset($adicional['precios_costo']) || $adicional['precios_costo'] === null || (float)$adicional['precios_costo'] <= 0) {
        volverConError('El código adicional ' . $codigo . ' todavía no tiene un precio válido en la base Bejerman vigente.');
    }
    return $adicional;
}


function buscarAdicionalConfigurado($conexion, $clave)
{
    $stmt = $conexion->prepare("SELECT adicional_codigo, adicional_descripcion FROM adicionales WHERE adicional_clave = ? AND adicional_activo = 'SI' LIMIT 1");
    if (!$stmt) {
        volverConError('No se pudo consultar la tabla adicionales. Importe primero el SQL entregado: ' . $conexion->error);
    }
    $stmt->bind_param('s', $clave);
    $stmt->execute();
    $config = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$config) {
        volverConError('No existe un adicional activo configurado para ' . $clave . '.');
    }
    return buscarAdicionalPrecio($conexion, $config['adicional_codigo']);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

/* Resolver una sola vez la lista elegida antes de iniciar cualquier cálculo. */
$modoPanel = isset($_POST['modo_panel']) && $_POST['modo_panel'] === '1';
/* HF1: el panel de cálculo nunca puede ejecutar una acción comercial, aunque el
 * navegador haya conservado accidentalmente un input oculto de un intento previo. */
$accionComercial = $modoPanel ? '' : ($_POST['accion_comercial'] ?? '');
$cotizacionEdicionId = filter_input(INPUT_POST, 'cotizacion_id', FILTER_VALIDATE_INT);
$cotizacionEdicionId = ($cotizacionEdicionId === false || $cotizacionEdicionId === null) ? 0 : (int)$cotizacionEdicionId;
$pedidoEdicionId = filter_input(INPUT_POST, 'pedido_id', FILTER_VALIDATE_INT);
$pedidoEdicionId = ($pedidoEdicionId === false || $pedidoEdicionId === null) ? 0 : (int)$pedidoEdicionId;

/* Consumir el token antes de procesar una emisión o revisión. PHP serializa la sesión,
 * por lo que un segundo POST con el mismo token queda bloqueado de forma segura. */
if (in_array($accionComercial, array('guardar_cotizacion','guardar_revision_pedido','generar_pedido_directo'), true)) {
    $saveToken = trim((string)($_POST['document_save_token'] ?? ''));
    $tokens = $_SESSION['document_save_tokens'] ?? array();
    if ($saveToken === '' || !is_array($tokens) || !array_key_exists($saveToken, $tokens)) {
        if ($accionComercial === 'guardar_revision_pedido' && $pedidoEdicionId > 0) { header('Location: pedidos.php'); exit; }
        if ($accionComercial === 'guardar_cotizacion' && $cotizacionEdicionId > 0) { header('Location: ver_cotizacion.php?id='.$cotizacionEdicionId); exit; }
        volverConError('La operación ya fue procesada o la sesión del formulario venció. Vuelva a abrir el documento antes de guardar.');
    }
    unset($_SESSION['document_save_tokens'][$saveToken]);
}
if (in_array($accionComercial, array('guardar_cotizacion','guardar_revision_pedido','generar_pedido_directo'), true)) {
    $modoPanel = false;
}

$listaSeleccionada = obtenerListaSeleccionada($conexion,(int)($_POST['lista_id']??0));
if (!$listaSeleccionada) {
    volverConError('No existe una base Bejerman vigente disponible para calcular.');
}
$listaId = (int)$listaSeleccionada['lista_id'];

$idCliente = filter_input(INPUT_POST, 'id_cliente', FILTER_VALIDATE_INT);
$idCliente = ($idCliente === false || $idCliente === null) ? 0 : (int)$idCliente;
$accionRequiereCliente = in_array($accionComercial, array('guardar_cotizacion','guardar_revision_pedido','generar_pedido_directo'), true);
if ($accionRequiereCliente && $idCliente <= 0) { volverConError('Seleccione un cliente antes de emitir la cotización o generar el pedido.'); }
$referenciaCotizacion = isset($_POST['referencia_cotizacion']) ? trim($_POST['referencia_cotizacion']) : '';
if ($accionComercial === 'generar_pedido_directo' && $referenciaCotizacion === '') { volverConError('Complete la referencia antes de generar el pedido.'); }
$solicitanteCliente = trim((string)($_POST['solicitante_cliente'] ?? ''));
if (function_exists('mb_substr')) $solicitanteCliente = mb_substr($solicitanteCliente, 0, 100, 'UTF-8'); else $solicitanteCliente = substr($solicitanteCliente, 0, 100);
$_POST['solicitante_cliente'] = $solicitanteCliente;
$descuento1 = filter_input(INPUT_POST, 'descuento_1', FILTER_VALIDATE_INT);
$descuento2 = filter_input(INPUT_POST, 'descuento_2', FILTER_VALIDATE_INT);
$descuento3 = filter_input(INPUT_POST, 'descuento_3', FILTER_VALIDATE_INT);

$descuento1 = ($descuento1 === false || $descuento1 === null) ? (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_1', 30)) : (int)$descuento1;
$descuento2 = ($descuento2 === false || $descuento2 === null) ? (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_2', 10)) : (int)$descuento2;
$descuento3 = ($descuento3 === false || $descuento3 === null) ? (int)round(parametroComercial($conexion, 'CONTROL_DESCUENTO_3', 10)) : (int)$descuento3;

$idCpu = filter_input(INPUT_POST, 'id_cpu', FILTER_VALIDATE_INT);
$idTipo = filter_input(INPUT_POST, 'id_tipo_control', FILTER_VALIDATE_INT);
$idManiobra = filter_input(INPUT_POST, 'id_maniobra', FILTER_VALIDATE_INT);
$idSubtipo = filter_input(INPUT_POST, 'id_subtipo', FILTER_VALIDATE_INT);
$idTension = filter_input(INPUT_POST, 'id_tension', FILTER_VALIDATE_INT);
$idCentral = filter_input(INPUT_POST, 'id_central', FILTER_VALIDATE_INT);
$idCentral = ($idCentral === false || $idCentral === null) ? null : (int)$idCentral;
$centralOtraNombre = isset($_POST['central_otra_nombre']) ? trim($_POST['central_otra_nombre']) : '';
$velocidadVF = isset($_POST['velocidad_vf']) ? trim($_POST['velocidad_vf']) : '';
$idMaterialHueco = filter_input(INPUT_POST, 'id_material_hueco', FILTER_VALIDATE_INT);
$cantidadEquipos = filter_input(INPUT_POST, 'cantidad_equipos', FILTER_VALIDATE_INT);
$idBateria = filter_input(INPUT_POST, 'id_bateria', FILTER_VALIDATE_INT);
$idRescate = filter_input(INPUT_POST, 'id_rescate', FILTER_VALIDATE_INT);
$idRescate = ($idRescate === false || $idRescate === null) ? 0 : (int)$idRescate;
$cantidadTotalCochesBateria = filter_input(INPUT_POST, 'cantidad_total_coches_bateria', FILTER_VALIDATE_INT);

// v124: respaldo defensivo para modificaciones de pedidos. Si el navegador omitió el
// campo de cantidad total de coches (por ejemplo porque quedó disabled durante una
// transición de interfaz), recuperar EXACTAMENTE el valor previamente persistido. No se
// deduce ni se inventa ninguna cantidad; si tampoco existe en el histórico, la validación
// normal de batería seguirá deteniendo el cálculo.
if (($cantidadTotalCochesBateria === false || $cantidadTotalCochesBateria === null) && $pedidoEdicionId > 0) {
    $stBateriaPrev = $conexion->prepare("SELECT p.datos_formulario AS pedido_datos, c.datos_formulario AS cotizacion_datos FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id WHERE p.pedido_id=? LIMIT 1");
    if ($stBateriaPrev) {
        $stBateriaPrev->bind_param('i', $pedidoEdicionId);
        if ($stBateriaPrev->execute()) {
            $filaBateriaPrev = $stBateriaPrev->get_result()->fetch_assoc();
            $datosCotPrev = json_decode((string)($filaBateriaPrev['cotizacion_datos'] ?? ''), true);
            $datosPedPrev = json_decode((string)($filaBateriaPrev['pedido_datos'] ?? ''), true);
            if (!is_array($datosCotPrev)) $datosCotPrev = array();
            if (!is_array($datosPedPrev)) $datosPedPrev = array();
            $datosPreviosBateria = array_replace($datosCotPrev, $datosPedPrev);
            $valorPrevioBateria = $datosPreviosBateria['cantidad_total_coches_bateria'] ?? null;
            if (is_numeric($valorPrevioBateria) && (int)$valorPrevioBateria > 0) {
                $cantidadTotalCochesBateria = (int)$valorPrevioBateria;
                $_POST['cantidad_total_coches_bateria'] = (string)$cantidadTotalCochesBateria;
            }
        }
        $stBateriaPrev->close();
    }
}
$numeroObraOtro = isset($_POST['numero_obra_otro']) ? trim((string)$_POST['numero_obra_otro']) : '';
$observacionBateria = isset($_POST['observacion_bateria']) ? trim((string)$_POST['observacion_bateria']) : '';
$paradasEntrada = isset($_POST['paradas_equipo']) && is_array($_POST['paradas_equipo']) ? $_POST['paradas_equipo'] : array();
$nomenclaturasEntrada = isset($_POST['nomenclatura_equipo']) && is_array($_POST['nomenclatura_equipo']) ? $_POST['nomenclatura_equipo'] : array();
$paradasPorEquipo = array();
$nomenclaturasPorEquipo = array();
$potencia = filter_input(INPUT_POST, 'potencia_hp', FILTER_VALIDATE_FLOAT);

$comSerie = filter_input(INPUT_POST, 'id_comunicacion_serie', FILTER_VALIDATE_INT);
$comSerie = ($comSerie === false || $comSerie === null) ? null : (int)$comSerie;

// v92: la dependencia obligatoria Control -> Senalizacion se fuerza del lado servidor
// solamente al EMITIR/GUARDAR el documento. En el calculo auxiliar de Control no debe
// inyectarse una Senalizacion todavia incompleta, porque eso haria fallar el calculo
// del Control con el mensaje "Complete todos los datos obligatorios de la botonera".
// La interfaz v91 sigue marcando Senalizacion/comunicacion serie en tiempo real; al
// guardar, esta regla vuelve a imponerse aqui para que no pueda omitirse.
if ($accionComercial !== '') {
    $_POST = senalAplicarDependenciasDesdeControl($conexion, $_POST);
}

$configuracionEspecialControl = strtoupper(trim((string)($_POST['configuracion_especial_control'] ?? '')));
if ($configuracionEspecialControl === '') {
    // Compatibilidad con documentos históricos anteriores a v102.
    $configuracionEspecialControl = (isset($_POST['doble_acceso']) && $_POST['doble_acceso'] === 'SI') ? 'DOBLE_ACCESO_SELECTIVO' : 'NORMAL';
}
if (!in_array($configuracionEspecialControl, array('NORMAL','DOBLE_ACCESO_SELECTIVO','TIP'), true)) {
    volverConError('La configuración especial seleccionada no es válida.');
}
$programaTip = strtoupper(trim((string)($_POST['programa_tip'] ?? '')));
if ($configuracionEspecialControl === 'TIP') {
    if (!in_array($programaTip, array('ESPECIAL_ESTANDAR','ESPECIAL_ESPECIAL'), true)) {
        volverConError('Seleccione el programa de Maniobra TIP: Especial + Estándar o Especial + Especial.');
    }
} else {
    $programaTip = '';
}
$dobleAcceso = $configuracionEspecialControl === 'DOBLE_ACCESO_SELECTIVO' ? 'SI' : null;
$_POST['configuracion_especial_control'] = $configuracionEspecialControl;
if ($programaTip !== '') $_POST['programa_tip'] = $programaTip; else unset($_POST['programa_tip']);
if ($dobleAcceso === 'SI') $_POST['doble_acceso'] = 'SI'; else unset($_POST['doble_acceso']);
$esTandem = isset($_POST['es_tandem']) && $_POST['es_tandem'] === 'SI' ? 'SI' : '';
$maniobraSabatica = isset($_POST['maniobra_sabatica']) && $_POST['maniobra_sabatica'] === 'SI';
$encoder = isset($_POST['encoder']) && $_POST['encoder'] === 'SI' ? 'SI' : '';
$posicionamientoEncoder = isset($_POST['posicionamiento_encoder']) && $_POST['posicionamiento_encoder'] === 'SI' ? 'SI' : '';
$agregarContactorPotencial = isset($_POST['agregar_contactorpot']) && $_POST['agregar_contactorpot'] === 'SI';
$emergenciaCorte = isset($_POST['emergencia_corte']) && $_POST['emergencia_corte'] === 'SI';
$alimentacionPuertaVf = isset($_POST['alimentacion_puerta_vf']) && $_POST['alimentacion_puerta_vf'] === 'SI';
$forzadorAire = isset($_POST['forzador_aire']) && $_POST['forzador_aire'] === 'SI';
// v177: cualquier botonera ONIX (Individuales, Telefónica o Pantalla 21) exige
// Luz de cortesía en Control. Se refuerza en servidor para no depender del JavaScript.
if (!empty($_POST['senal_incluir']) && !empty($_POST['senal_modelo'])) {
    $senalModeloId = (int)$_POST['senal_modelo'];
    $senalModeloNombre = '';
    $stOnix = $conexion->prepare("SELECT modelo_pulsador_nombre FROM senal_modelos_pulsador WHERE modelo_pulsador_id=? LIMIT 1");
    if ($stOnix) {
        $stOnix->bind_param('i', $senalModeloId);
        if ($stOnix->execute()) {
            $filaOnix = $stOnix->get_result()->fetch_assoc();
            $senalModeloNombre = strtoupper(trim((string)($filaOnix['modelo_pulsador_nombre'] ?? '')));
            $senalModeloNombre = strtr($senalModeloNombre, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'));
        }
        $stOnix->close();
    }
    if (strpos($senalModeloNombre, 'ONIX TELEFONICO') !== false ||
        strpos($senalModeloNombre, 'ONIX INDIVIDUALES') !== false ||
        strpos($senalModeloNombre, 'ONIX PULS') !== false ||
        strpos($senalModeloNombre, 'PANTALLA 21') !== false ||
        strpos($senalModeloNombre, 'PANTALLA TOUCH 21') !== false) {
        $_POST['luz_cortesia'] = 'SI';
    }
}
$luzCortesia = isset($_POST['luz_cortesia']) && $_POST['luz_cortesia'] === 'SI';
$llaveRamos = isset($_POST['llave_ramos']) && $_POST['llave_ramos'] === 'SI';
$descansoFreno = isset($_POST['descanso_freno']) && $_POST['descanso_freno'] === 'SI';
$protectorFaltaFaseSeleccionado = isset($_POST['protector_falta_fase']) && $_POST['protector_falta_fase'] === 'SI';
$retornoBateriaGel = isset($_POST['retorno_bateria_gel']) && $_POST['retorno_bateria_gel'] === 'SI';
$micronivelacion = isset($_POST['micronivelacion']) && $_POST['micronivelacion'] === 'SI';
$fuenteSwitchingTouchManual = isset($_POST['fuente_switching_touch']) && $_POST['fuente_switching_touch'] === 'SI';
$interfaseTipo = isset($_POST['interfase_tipo']) ? trim((string)$_POST['interfase_tipo']) : '';
if (!in_array($interfaseTipo, array('', 'A7120', 'A7120_A7121'), true)) {
    volverConError('La opción de interfase seleccionada no es válida.');
}
$tipoGabineteMrl = isset($_POST['tipo_gabinete_mrl']) ? strtoupper(trim((string)$_POST['tipo_gabinete_mrl'])) : '';
if (!in_array($tipoGabineteMrl, array('', 'ESTANDAR', 'WITTUR'), true)) {
    volverConError('El tipo de gabinete MRL seleccionado no es válido.');
}
$adicionalUcmMrl = isset($_POST['adicional_ucm_mrl']) && $_POST['adicional_ucm_mrl'] === 'SI';
$cantidadFuentesMrl = filter_input(INPUT_POST, 'cantidad_fuentes_mrl', FILTER_VALIDATE_INT);
$cantidadFuentesMrl = ($cantidadFuentesMrl === false || $cantidadFuentesMrl === null) ? 0 : (int)$cantidadFuentesMrl;
if ($cantidadFuentesMrl < 0 || $cantidadFuentesMrl > 100) {
    volverConError('La cantidad de fuentes para indicadores MRL debe estar entre 0 y 100.');
}
$cantidadFuentes24v = filter_input(INPUT_POST, 'cantidad_fuentes_24v', FILTER_VALIDATE_INT);
$cantidadFuentes24v = ($cantidadFuentes24v === false || $cantidadFuentes24v === null) ? 0 : (int)$cantidadFuentes24v;
if ($cantidadFuentes24v < 0 || $cantidadFuentes24v > 100) {
    volverConError('La cantidad de fuentes de 24 V debe estar entre 0 y 100.');
}


/* Hasta tres adicionales manuales: descripción libre e importe por equipo. */
$adicionalesManuales = array();
$precioAdicionalesManualesTotal = 0.0;
for ($iManual = 1; $iManual <= 3; $iManual++) {
    $campoDescripcion = 'adicional_manual_descripcion_' . $iManual;
    $campoImporte = 'adicional_manual_importe_' . $iManual;
    $descripcionManual = isset($_POST[$campoDescripcion]) ? trim((string)$_POST[$campoDescripcion]) : '';
    $importeTexto = isset($_POST[$campoImporte]) ? trim((string)$_POST[$campoImporte]) : '';

    $descripcionManual = preg_replace('/\s+/u', ' ', $descripcionManual);
    $descripcionManual = $descripcionManual === null ? '' : trim($descripcionManual);

    if ($descripcionManual === '' && $importeTexto === '') {
        continue;
    }
    if ($descripcionManual === '') {
        volverConError('Debe escribir la descripción del adicional manual ' . $iManual . '.');
    }
    if ($importeTexto === '') {
        volverConError('Debe indicar el importe por equipo del adicional manual ' . $iManual . '.');
    }

    $importeNormalizado = str_replace(',', '.', $importeTexto);
    if (!is_numeric($importeNormalizado)) {
        volverConError('El importe del adicional manual ' . $iManual . ' no es válido.');
    }
    $importeUnitarioManual = (float)$importeNormalizado;
    if ($importeUnitarioManual <= 0 || $importeUnitarioManual > 999999999999.99) {
        volverConError('El importe del adicional manual ' . $iManual . ' debe ser mayor que cero.');
    }

    $totalManual = $importeUnitarioManual * $cantidadEquipos;
    $adicionalesManuales[] = array(
        'numero' => $iManual,
        'descripcion' => $descripcionManual,
        'cantidad' => $cantidadEquipos,
        'unitario' => $importeUnitarioManual,
        'total' => $totalManual
    );
    $precioAdicionalesManualesTotal += $totalManual;
}


$idPuertaCabina = filter_input(INPUT_POST, 'id_ptacabina', FILTER_VALIDATE_INT);
$idPuertaCabina = ($idPuertaCabina === false || $idPuertaCabina === null) ? 0 : (int)$idPuertaCabina;

$idPuertasPisos = filter_input(INPUT_POST, 'id_ptapisos', FILTER_VALIDATE_INT);
$idPuertasPisos = ($idPuertasPisos === false || $idPuertasPisos === null) ? 0 : (int)$idPuertasPisos;

$cantidadOperadores = filter_input(INPUT_POST, 'cantidad_operadores', FILTER_VALIDATE_INT);
$cantidadOperadores = ($cantidadOperadores === false || $cantidadOperadores === null) ? 0 : (int)$cantidadOperadores;

/* v172: distribución de pisos por operador. Es información técnica no excluyente.
 * Para dos o más operadores se persiste una entrada por operador; si el usuario
 * no conoce el dato se documenta explícitamente como A CONFIRMAR. */
$aperturasOperadoresEntrada = isset($_POST['operador_aperturas']) && is_array($_POST['operador_aperturas'])
    ? $_POST['operador_aperturas']
    : array();
if ($cantidadOperadores >= 2) {
    $aperturasOperadores = array();
    for ($iOperador = 0; $iOperador < $cantidadOperadores; $iOperador++) {
        $apertura = trim((string)($aperturasOperadoresEntrada[$iOperador] ?? ''));
        if ($apertura === '') $apertura = 'A CONFIRMAR';
        $aperturasOperadores[] = $apertura;
    }
    $_POST['operador_aperturas'] = $aperturasOperadores;
} else {
    unset($_POST['operador_aperturas']);
}

$idPuertasPisosMc = filter_input(INPUT_POST, 'id_ptapisos_mc', FILTER_VALIDATE_INT);
$idPuertasPisosMc = ($idPuertasPisosMc === false || $idPuertasPisosMc === null) ? 0 : (int)$idPuertasPisosMc;

$cantidadPisosMc = filter_input(INPUT_POST, 'cantidad_pisos_mc', FILTER_VALIDATE_INT);
$cantidadPisosMc = ($cantidadPisosMc === false || $cantidadPisosMc === null) ? 0 : (int)$cantidadPisosMc;

$idPuertaCabinaMc = filter_input(INPUT_POST, 'id_ptacabina_mc', FILTER_VALIDATE_INT);
$idPuertaCabinaMc = ($idPuertaCabinaMc === false || $idPuertaCabinaMc === null) ? 0 : (int)$idPuertaCabinaMc;

$cantidadCabinaMc = filter_input(INPUT_POST, 'cantidad_cabina_mc', FILTER_VALIDATE_INT);
$cantidadCabinaMc = ($cantidadCabinaMc === false || $cantidadCabinaMc === null) ? 0 : (int)$cantidadCabinaMc;

if (!$idCpu || !$idTipo || !$idManiobra || !$idSubtipo || !$idTension || !$idMaterialHueco || !$cantidadEquipos || !$idBateria || $potencia === false) {
    volverConError('Faltan datos obligatorios o alguno de los valores recibidos no es válido.');
}

if ($cantidadEquipos < 1 || $cantidadEquipos > 10 || $potencia < 0) {
    volverConError('La cantidad de equipos debe estar entre 1 y 10 y la potencia no puede ser negativa.');
}
$stmtTipoBateria = $conexion->prepare("SELECT bateria_id,bateria_codigo,bateria_nombre FROM baterias WHERE bateria_id=? AND bateria_activa='SI' LIMIT 1");
if (!$stmtTipoBateria) volverConError('No se pudo consultar el catálogo de agrupaciones. Ejecute migracion_consolidacion_v59.sql.');
$stmtTipoBateria->bind_param('i', $idBateria);
$stmtTipoBateria->execute();
$filaTipoBateria = $stmtTipoBateria->get_result()->fetch_assoc();
$stmtTipoBateria->close();
if (!$filaTipoBateria) volverConError('La agrupación seleccionada no existe o está deshabilitada.');
$tipoBateriaCodigo = strtoupper(trim((string)$filaTipoBateria['bateria_codigo']));
$tipoBateriaNombre = trim((string)$filaTipoBateria['bateria_nombre']);
$esBateriaIndividual = $tipoBateriaCodigo === 'INDIVIDUAL';
$esBateriaActual = $tipoBateriaCodigo === 'BATERIA';

if ($esBateriaIndividual) {
    $cantidadTotalCochesBateria = $cantidadEquipos;
    $numeroObraOtro = '';
    $observacionBateria = '';
} else {
    if ($cantidadTotalCochesBateria === false || $cantidadTotalCochesBateria === null) {
        volverConError('Debe indicar la cantidad total de coches de la batería.');
    }
    $cantidadTotalCochesBateria = (int)$cantidadTotalCochesBateria;

    /* Límites técnicos de batería por CPU. */
    $maxCochesBateriaPorCpu = array(
        1 => 2, // A6220V5
        2 => 4, // A6300V4
        3 => 8, // A6700V2
        4 => 4, // CLEX: misma configuración que A6300V4
        5 => 4  // DANGELICA: misma configuración que A6300V4
    );
    $maxCochesBateria = $maxCochesBateriaPorCpu[(int)$idCpu] ?? 0;
    if ($maxCochesBateria === 0) {
        volverConError('La CPU seleccionada no tiene una configuración de batería definida.');
    }
    if ($cantidadTotalCochesBateria < 1 || $cantidadTotalCochesBateria > $maxCochesBateria) {
        volverConError('La cantidad total de coches admitida para la CPU seleccionada es de 1 a ' . $maxCochesBateria . '.');
    }
    if ($cantidadTotalCochesBateria < $cantidadEquipos) {
        volverConError('La cantidad total de coches de la batería no puede ser menor que la cantidad de equipos cotizados.');
    }
    $numeroObraOtro = preg_replace('/\s+/u', ' ', $numeroObraOtro);
    $numeroObraOtro = $numeroObraOtro === null ? '' : trim($numeroObraOtro);
    $observacionBateria = preg_replace('/\s+/u', ' ', $observacionBateria);
    $observacionBateria = $observacionBateria === null ? '' : trim($observacionBateria);
    if (!$esBateriaActual) {
        $numeroObraOtro = '';
    }
}
if (count($paradasEntrada) !== $cantidadEquipos) {
    volverConError('Debe indicar la cantidad de paradas de cada coche.');
}
for ($i = 0; $i < $cantidadEquipos; $i++) {
    $paradasCoche = filter_var($paradasEntrada[$i], FILTER_VALIDATE_INT);
    if ($paradasCoche === false || $paradasCoche < 1) {
        volverConError('La cantidad de paradas del coche ' . ($i + 1) . ' no es válida.');
    }
    $paradasPorEquipo[] = (int)$paradasCoche;
    $nomenclatura = isset($nomenclaturasEntrada[$i]) ? trim((string)$nomenclaturasEntrada[$i]) : '';

    /*
     * La nomenclatura es un dato informativo opcional de texto libre.
     * Puede escribirse como "PB al 7", "SS, PB, 1 a 6" o de cualquier otra forma.
     * No se intenta contar, separar ni reinterpretar las paradas ingresadas.
     */
    $nomenclatura = preg_replace('/\s+/u', ' ', $nomenclatura);
    $nomenclatura = $nomenclatura === null ? '' : trim($nomenclatura);
    $nomenclaturasPorEquipo[] = $nomenclatura;
}
$totalParadas = array_sum($paradasPorEquipo);
$paradas = $paradasPorEquipo[0];


/* El cliente no condiciona el cálculo. Solo se valida si fue seleccionado o si la acción comercial lo exige. */
$cliente = array(
    'clientes_codigo' => '',
    'clientes_nomfantasia' => '',
    'clientes_razonsocial' => '',
    'clientes_numero_bejerman' => '',
    'clientes_telefono' => '',
    'clientes_emails' => ''
);
if ($idCliente > 0) {
    $sqlCliente = "SELECT clientes_codigo, clientes_nomfantasia, clientes_razonsocial, clientes_numero_bejerman, clientes_telefono, clientes_emails
                   FROM clientes
                   WHERE clientes_id = ? AND clientes_habilitado = 'SI'
                   LIMIT 1";
    $stmtCliente = $conexion->prepare($sqlCliente);
    if (!$stmtCliente) {
        volverConError('No se pudo preparar la consulta del cliente: ' . $conexion->error);
    }
    $stmtCliente->bind_param('i', $idCliente);
    $stmtCliente->execute();
    $clienteEncontrado = $stmtCliente->get_result()->fetch_assoc();
    $stmtCliente->close();
    if (!$clienteEncontrado) {
        volverConError('El cliente seleccionado no existe o está deshabilitado.');
    }
    $cliente = $clienteEncontrado;
}

/* Recuperar la maniobra para aplicar la lógica especial de puertas MC. */
$sqlManiobra = "SELECT maniobra_name
                FROM maniobras
                WHERE maniobra_id = ?
                LIMIT 1";

$stmtManiobra = $conexion->prepare($sqlManiobra);

if (!$stmtManiobra) {
    volverConError('No se pudo consultar la maniobra: ' . $conexion->error);
}

$stmtManiobra->bind_param('i', $idManiobra);
$stmtManiobra->execute();
$maniobra = $stmtManiobra->get_result()->fetch_assoc();
$stmtManiobra->close();

if (!$maniobra) {
    volverConError('La maniobra seleccionada no existe.');
}

$nombreManiobra = trim($maniobra['maniobra_name']);
$esManiobraMc = strtoupper($nombreManiobra) === 'MC';

// v97: compatibilidad CPU/velocidad/encoder centralizada en cpus.
$stmtCpuCompat = $conexion->prepare('SELECT cpu_name, velocidad_max_mmin, admite_encoder, cpu_matriz_base_id FROM cpus WHERE cpu_id = ? LIMIT 1');
if (!$stmtCpuCompat) volverConError('No se pudo consultar la compatibilidad de la CPU. Ejecute migracion_v97_compatibilidad_cpu.sql.');
$stmtCpuCompat->bind_param('i', $idCpu);
$stmtCpuCompat->execute();
$filaCpuCompat = $stmtCpuCompat->get_result()->fetch_assoc();
$stmtCpuCompat->close();
if (!$filaCpuCompat) volverConError('La CPU seleccionada no existe.');
$nombreCpuCompat = trim((string)$filaCpuCompat['cpu_name']);
$velocidadMaxCpu = $filaCpuCompat['velocidad_max_mmin'] !== null ? (float)$filaCpuCompat['velocidad_max_mmin'] : null;
$admiteEncoderCpu = isset($filaCpuCompat['admite_encoder']) ? (string)$filaCpuCompat['admite_encoder'] : '';
$idCpuMatrizBase = isset($filaCpuCompat['cpu_matriz_base_id']) && (int)$filaCpuCompat['cpu_matriz_base_id'] > 0 ? (int)$filaCpuCompat['cpu_matriz_base_id'] : (int)$idCpu;
// v215: si el modelo tiene filas propias en matriz_calculos, tienen prioridad. La matriz base queda solo como fallback.
$idCpuMatriz = (int)$idCpu;
$stMatrizPropia = $conexion->prepare('SELECT 1 FROM matriz_calculos WHERE control_cpu=? LIMIT 1');
if ($stMatrizPropia) {
    $stMatrizPropia->bind_param('i', $idCpu);
    $stMatrizPropia->execute();
    $tieneMatrizPropia = (bool)$stMatrizPropia->get_result()->fetch_row();
    $stMatrizPropia->close();
    if (!$tieneMatrizPropia) $idCpuMatriz = $idCpuMatrizBase;
} else {
    $idCpuMatriz = $idCpuMatrizBase;
}
$usaMatrizCpuBase = $idCpuMatriz !== (int)$idCpu;
$cpuCapV213 = ctrlCpuCapacidad($conexion, (int)$idCpu);
$tipoCapV213 = ctrlTipoCapacidad($conexion, (int)$idTipo);
if (($cpuCapV213['activo'] ?? 'SI') !== 'SI') volverConError('El modelo de Control seleccionado está inactivo.');

/* v133: snapshot documental MRL. Se persiste en datos_formulario para que cada
 * documento conserve exactamente la leyenda técnica emitida, sin recalcular históricos. */
if ($idTipo === 7) {
    $cpuMrlMayus = strtoupper($nombreCpuCompat);
    $mrlIdentidadV213 = strtoupper(trim((string)($cpuCapV213['mrl_identidad'] ?? 'AUTOMAC')));
    if ($tipoGabineteMrl === 'WITTUR') {
        $mrlConfiguracionDocumento = 'CONFIGURACION MRL WITTUR';
        $mrlGabineteDocumento = 'Gabinete aproximado 400x2200x240 mm en pintura epoxy gris. Visor y espacio para palanca de freno manual / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /';
    } elseif ($mrlIdentidadV213 === 'CLEX') {
        $mrlConfiguracionDocumento = 'CONFIGURACION MRL CLEX';
        $mrlGabineteDocumento = 'Gabinete aproximado 460x2000x200 mm en pintura epoxy gris / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /';
    } elseif ($mrlIdentidadV213 === 'DANGELICA') {
        $mrlConfiguracionDocumento = 'CONFIGURACION MRL DANGELICA';
        $mrlGabineteDocumento = 'Gabinete aproximado 460x2000x200 mm en pintura epoxy gris / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /';
    } else {
        $mrlConfiguracionDocumento = 'CONFIGURACION MRL AUTOMAC';
        $mrlGabineteDocumento = 'Gabinete aproximado 460x2000x200 mm en pintura epoxy gris / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /';
    }
    $_POST['mrl_configuracion_documento'] = $mrlConfiguracionDocumento;
    $_POST['mrl_gabinete_documento'] = $mrlGabineteDocumento;
} else {
    unset($_POST['mrl_configuracion_documento'], $_POST['mrl_gabinete_documento']);
}

if ($encoder === 'SI' && $admiteEncoderCpu === 'NO') {
    volverConError('La CPU ' . $nombreCpuCompat . ' no soporta encoder de motor.');
}

$stmtSubtipoNombre = $conexion->prepare('SELECT ctrlsubtipo_name, velocidad_max_mmin, habilita_mayor_75 FROM subtipos_control WHERE ctrlsubtipo_id = ? LIMIT 1');
if (!$stmtSubtipoNombre) volverConError('No se pudo consultar el subtipo y su compatibilidad de velocidad. Ejecute migracion_v96_compatibilidad_variadores.sql.');
$stmtSubtipoNombre->bind_param('i', $idSubtipo);
$stmtSubtipoNombre->execute();
$filaSubtipoNombre = $stmtSubtipoNombre->get_result()->fetch_assoc();
$stmtSubtipoNombre->close();
$nombreSubtipo = $filaSubtipoNombre ? trim($filaSubtipoNombre['ctrlsubtipo_name']) : '';
$esArranqueSuave = strpos(strtoupper($nombreSubtipo), 'ARRANQUE SUAVE') !== false;

// v96: compatibilidad velocidad/variador centralizada en subtipos_control.
if ($velocidadVF !== '' && in_array($idTipo, array(4,5,6,7), true)) {
    $velocidadNumerica = (float)$velocidadVF;
    $velocidadMaxima = isset($filaSubtipoNombre['velocidad_max_mmin']) && $filaSubtipoNombre['velocidad_max_mmin'] !== null
        ? (float)$filaSubtipoNombre['velocidad_max_mmin'] : null;
    $habilitaMayor75 = (($filaSubtipoNombre['habilita_mayor_75'] ?? 'NO') === 'SI');

    if ($velocidadMaxima !== null && $velocidadNumerica > $velocidadMaxima) {
        volverConError('El variador ' . $nombreSubtipo . ' admite hasta ' . rtrim(rtrim(number_format($velocidadMaxima, 2, '.', ''), '0'), '.') . ' m/min. Seleccione un variador compatible con la velocidad elegida.');
    }
    if ($velocidadNumerica > 75.0 && !$habilitaMayor75) {
        volverConError('Para velocidades superiores a 75 m/min solo se permite INVT GD390L o Yaskawa L1000.');
    }
    if ($velocidadMaxCpu !== null && $velocidadNumerica > $velocidadMaxCpu) {
        volverConError('La CPU ' . $nombreCpuCompat . ' admite hasta ' . rtrim(rtrim(number_format($velocidadMaxCpu, 2, '.', ''), '0'), '.') . ' m/min. Para 90 m/min o más utilice A6700V2.');
    }
    // A más de 75 m/min el encoder de motor es obligatorio, pero v115 deja la
    // selección en manos del usuario: no se auto-agrega silenciosamente.
    if ($velocidadNumerica > 75.0) {
        if ($admiteEncoderCpu === 'NO') volverConError('La CPU ' . $nombreCpuCompat . ' no soporta encoder y no puede utilizarse por encima de 75 m/min.');
        if ($encoder !== 'SI') volverConError('Para velocidades superiores a 75 m/min debe seleccionar Encoder de motor.');
    }
}

$nombreComSerie = '';
if ($comSerie) {
    $stmtComSerie = $conexion->prepare('SELECT comserie_name FROM comunicaciones_serie WHERE comserie_id = ? LIMIT 1');
    if (!$stmtComSerie) volverConError('No se pudo consultar la comunicación serie: ' . $conexion->error);
    $stmtComSerie->bind_param('i', $comSerie);
    $stmtComSerie->execute();
    $filaComSerie = $stmtComSerie->get_result()->fetch_assoc();
    $stmtComSerie->close();
    $nombreComSerie = $filaComSerie ? trim($filaComSerie['comserie_name']) : '';
}
$comSerieTotal = strpos(strtoupper($nombreComSerie), 'TOTAL') !== false;


if ($descuento1 < 0 || $descuento1 > 100 ||
    $descuento2 < 0 || $descuento2 > 100 ||
    $descuento3 < 0 || $descuento3 > 100) {
    volverConError('Los descuentos deben ser números enteros entre 0 y 100.');
}

/* La central es obligatoria solamente para controles hidráulicos (tipo 3). */
if (($tipoCapV213['requiere_central'] ?? 'NO') === 'SI' && !$idCentral) {
    volverConError('Debe seleccionar la central hidráulica.');
}

$centralCatalogo = null;
if (($tipoCapV213['requiere_central'] ?? 'NO') === 'SI') {
    $stmtCentralCatalogo = $conexion->prepare("SELECT central_id,central_codigo,central_name,central_es_otra,central_adicional_clave FROM centrales WHERE central_id=? AND central_activa='SI' LIMIT 1");
    if (!$stmtCentralCatalogo) volverConError('No se pudo consultar el catálogo de centrales. Ejecute migracion_consolidacion_v59.sql.');
    $stmtCentralCatalogo->bind_param('i', $idCentral);
    $stmtCentralCatalogo->execute();
    $centralCatalogo = $stmtCentralCatalogo->get_result()->fetch_assoc();
    $stmtCentralCatalogo->close();
    if (!$centralCatalogo) volverConError('La central hidráulica seleccionada no existe o está deshabilitada.');
    if ((int)$centralCatalogo['central_es_otra'] === 1 && $centralOtraNombre === '') {
        volverConError('Debe ingresar el nombre de la central hidráulica cuando selecciona OTRA.');
    }
} else {
    $idCentral = null;
    $centralOtraNombre = '';
}

if ($esTandem === 'SI' && (($tipoCapV213['permite_tandem'] ?? 'NO') !== 'SI')) {
    volverConError('La configuración Tándem no está habilitada para este tipo de control.');
}

/* v213: compatibilidad MRL parametrizada por CPU. */
if ($idTipo === 7 && (($cpuCapV213['admite_mrl'] ?? 'NO') !== 'SI')) {
    volverConError('La CPU ' . $nombreCpuCompat . ' no está habilitada para MRL en Mantenimiento > Matrices de Control.');
}
if (!ctrlCpuAdmiteCompatibilidad($conexion, (int)$idCpu, (int)$idTipo, (int)$idSubtipo)) {
    volverConError('La combinación modelo + tipo + subtipo no está habilitada en Mantenimiento > Matrices de Control.');
}
if ($idTipo === 7 && !in_array($tipoGabineteMrl, array('ESTANDAR', 'WITTUR'), true)) {
    volverConError('Debe seleccionar el tipo de gabinete MRL: Automac / Dangelica / CLEX o Wittur.');
}
if ($idTipo !== 7) {
    $tipoGabineteMrl = '';
}

$velocidadCalculo = 0.0;
$requiereVelocidadTipo = (($tipoCapV213['requiere_velocidad'] ?? 'NO') === 'SI');
if ($requiereVelocidadTipo) {
    if ($velocidadVF === '' || !is_numeric($velocidadVF)) volverConError('Debe seleccionar la velocidad del equipo.');
    $velocidadCalculo = (float)$velocidadVF;
} else {
    $velocidadFijaTipo = $tipoCapV213['velocidad_fija_mmin'] ?? null;
    $velocidadCalculo = $velocidadFijaTipo !== null ? (float)$velocidadFijaTipo : 0.0;
    $velocidadVF = '';
}


/* v97: límite de velocidad de la CPU leído desde la base de cálculo. */
if (in_array($idTipo, array(4,5,6,7), true) && $velocidadMaxCpu !== null && $velocidadCalculo > $velocidadMaxCpu) {
    volverConError('La CPU ' . $nombreCpuCompat . ' admite una velocidad máxima de ' . number_format($velocidadMaxCpu, 0, ',', '.') . ' m/min. Para 90 m/min o más utilice A6700V2.');
}

$centralNombre = '';
$centralAdicionalClave = '';
if ($idTipo === 3 && $centralCatalogo) {
    $centralNombre = trim((string)$centralCatalogo['central_name']);
    $centralAdicionalClave = trim((string)($centralCatalogo['central_adicional_clave'] ?? ''));
    if ((int)$centralCatalogo['central_es_otra'] === 1) {
        $centralNombre = 'OTRA: ' . $centralOtraNombre;
    }
}

/* Validar y valorizar el posicionamiento por encoder. Es independiente del encoder de motor. */
$adicionalPosicionamientoEncoder = null;
$precioPosicionamientoEncoderUnitario = 0.0;
$precioPosicionamientoEncoderTotal = 0.0;

if ($posicionamientoEncoder === 'SI') {
    $sqlPermitePosicionamiento = "SELECT posenc_id
                                  FROM posicionamiento_encoder_permitido
                                  WHERE posenc_cpu = ?
                                    AND posenc_tipo = ?
                                    AND posenc_habilitado = 'SI'
                                  LIMIT 1";
    $stmtPosicionamiento = $conexion->prepare($sqlPermitePosicionamiento);
    if (!$stmtPosicionamiento) {
        volverConError('No se pudo validar el posicionamiento por encoder. Importe primero las tablas entregadas: ' . $conexion->error);
    }
    $stmtPosicionamiento->bind_param('ii', $idCpu, $idTipo);
    $stmtPosicionamiento->execute();
    $permitePosicionamiento = $stmtPosicionamiento->get_result()->fetch_assoc();
    $stmtPosicionamiento->close();

    if (!$permitePosicionamiento) {
        volverConError('La CPU y el tipo de control seleccionados no permiten posicionamiento por encoder.');
    }

    $adicionalPosicionamientoEncoder = buscarAdicionalConfigurado($conexion, 'POSICIONAMIENTO_ENCODER');
    $precioPosicionamientoEncoderUnitario = (float)$adicionalPosicionamientoEncoder['precios_costo'];
    $precioPosicionamientoEncoderTotal = $precioPosicionamientoEncoderUnitario * $cantidadEquipos;
}

/* Validar límite de paradas. v103: fuente operativa técnica; CPU Automac vigentes siempre CON EXPANSIÓN. */
$paradasMaximas = null;
$notaLimiteParadas = '';
$modeloTecnicoPorCpu = array(
    1 => array('modelo'=>'A6220V5','expansion'=>'SI'),
    2 => array('modelo'=>'A6300V4','expansion'=>'SI'),
    3 => array('modelo'=>'A6700V2','expansion'=>'SI'),
    4 => array('modelo'=>'CLEX','expansion'=>'NA'),
    5 => array('modelo'=>'CD2203 (Dangélica)','expansion'=>'SI')
);
$maniobraTecnica = strtoupper(trim((string)$nombreManiobra));
if ($maniobraTecnica === 'SDA') $maniobraTecnica = 'SAD';
if (in_array($maniobraTecnica, array('MC','MV','MONTACOCHES','MONTAVEHICULOS','MONTAVEHÍCULOS'), true)) $maniobraTecnica = 'MC/MV';
$grupoLimite = $configuracionEspecialControl === 'TIP' ? 'TIP' : ($configuracionEspecialControl === 'DOBLE_ACCESO_SELECTIVO' ? 'DOBLE_ACCESO_SELECTIVO' : 'ESTANDAR');
$comunicacionTecnica = 'NINGUNA';
if ($comSerie) {
    $comMayusLimite = strtoupper(trim((string)$nombreComSerie));
    if (strpos($comMayusLimite, 'TOTAL') !== false) $comunicacionTecnica = 'TOTAL';
    elseif (strpos($comMayusLimite, 'CABINA') !== false) $comunicacionTecnica = 'CABINA';
    else volverConError('La comunicación serie seleccionada no tiene una variante definida en la tabla técnica de límites de paradas.');
}
if ($configuracionEspecialControl !== 'NORMAL' && !in_array($maniobraTecnica, array('SD','SAD'), true)) {
    volverConError(($configuracionEspecialControl === 'TIP' ? 'Maniobra TIP' : 'Doble acceso selectivo') . ' solo tiene límites técnicos definidos para maniobras SD o SAD.');
}
$cfgModelo = $modeloTecnicoPorCpu[(int)$idCpu] ?? null;
$tablaTecnicaExiste = esquemaTablaExiste($conexion,'limites_paradas_tecnicos');
if ($tablaTecnicaExiste && $cfgModelo) {
    $sqlLimiteTecnico = "SELECT paradas_max,nota FROM limites_paradas_tecnicos
                         WHERE activo='SI' AND grupo=? AND modelo_control=? AND expansion=?
                           AND maniobra=? AND comunicacion=? AND programa_tip=? LIMIT 1";
    $stmtLimiteTecnico = $conexion->prepare($sqlLimiteTecnico);
    if (!$stmtLimiteTecnico) volverConError('No se pudo preparar la consulta técnica de límites: ' . $conexion->error);
    $programaConsulta = $configuracionEspecialControl === 'TIP' ? $programaTip : '';
    $stmtLimiteTecnico->bind_param('ssssss', $grupoLimite, $cfgModelo['modelo'], $cfgModelo['expansion'], $maniobraTecnica, $comunicacionTecnica, $programaConsulta);
    $stmtLimiteTecnico->execute();
    $limiteTecnico = $stmtLimiteTecnico->get_result()->fetch_assoc();
    $stmtLimiteTecnico->close();
    if (!$limiteTecnico) volverConError('No existe un límite técnico CON EXPANSIÓN para la combinación seleccionada.');
    if ($limiteTecnico['paradas_max'] === null) volverConError('La combinación seleccionada no está habilitada por el cuadro técnico de límites de paradas.');
    $paradasMaximas = (int)$limiteTecnico['paradas_max'];
    $notaLimiteParadas = trim((string)($limiteTecnico['nota'] ?? ''));
} else {
    // Respaldo compatible para instalaciones que todavía no ejecutaron v100. TIP requiere obligatoriamente la tabla técnica.
    if ($configuracionEspecialControl === 'TIP') volverConError('Para utilizar Maniobra TIP debe ejecutar la migración de límites técnicos v100/v102.');
    $sqlLimite = "SELECT Vparadas_paradasmax FROM limites_paradas
                  WHERE Vparadas_cpu=? AND Vparadas_maniobra=? AND Vparadas_comserie <=> ? AND Vparadas_dobleacceso <=> ? LIMIT 1";
    $stmt = $conexion->prepare($sqlLimite);
    if (!$stmt) volverConError('No se pudo preparar la consulta de límites: ' . $conexion->error);
    $stmt->bind_param('iiis', $idCpu, $idManiobra, $comSerie, $dobleAcceso);
    $stmt->execute(); $limite=$stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$limite) volverConError('No existe un límite de paradas para la combinación seleccionada.');
    $paradasMaximas=(int)$limite['Vparadas_paradasmax'];
}
if ($paradasMaximas === 0) volverConError('La combinación seleccionada no está habilitada para paradas.');
foreach ($paradasPorEquipo as $indiceCoche => $paradasCoche) {
    if ($paradasCoche > $paradasMaximas) {
        volverConError('El coche ' . ($indiceCoche + 1) . ' tiene ' . $paradasCoche . ' paradas y supera el máximo permitido de ' . $paradasMaximas . '.');
    }
}

/* Buscar equipo */
$sqlMatriz = "SELECT
                m.control_codigo,
                m.control_precio,
                m.control_corriente,
                m.control_contactor,
                m.control_contactorpot,
                m.control_encoder,
                m.control_potenciadesde,
                m.control_potenciahasta,
                p.precios_descripcion,
                p.precios_costo
              FROM matriz_calculos m
              LEFT JOIN lista_precios p
                ON p.precios_codigo = m.control_codigo
              WHERE m.control_cpu = ?
                AND m.control_tipo = ?
                AND m.control_subtipo = ?
                AND m.control_tension = ?
                AND COALESCE(NULLIF(TRIM(m.control_encoder), ''), '') = ?
                AND ? > m.control_potenciadesde
                AND ? <= m.control_potenciahasta
              ORDER BY m.control_potenciadesde DESC
              LIMIT 1";

$stmt = $conexion->prepare($sqlMatriz);

if (!$stmt) {
    volverConError('No se pudo preparar la consulta de la matriz: ' . $conexion->error);
}

$stmt->bind_param('iiiisdd', $idCpuMatriz, $idTipo, $idSubtipo, $idTension, $encoder, $potencia, $potencia);
$stmt->execute();
$equipo = $stmt->get_result()->fetch_assoc();
$equipo = aplicarPrecioVersion($conexion, $listaId, $equipo, 'control_codigo');
$stmt->close();

if (!$equipo) {
    volverConError('No se encontró una configuración para los valores seleccionados' . ($usaMatrizCpuBase ? ' usando la matriz base configurada para la CPU ' . $nombreCpuCompat : '') . '.');
}

/* Obtener la descripción del contactor.
 * El contactor ya está incluido en el precio base: solo se muestra.
 */
$contactorNombre = '';
$idContactor = isset($equipo['control_contactor']) ? (int)$equipo['control_contactor'] : 0;

if ($idContactor > 0) {
    $sqlContactor = "SELECT contactor_name
                     FROM contactores
                     WHERE contactor_id = ?
                     LIMIT 1";

    $stmtContactor = $conexion->prepare($sqlContactor);

    if (!$stmtContactor) {
        volverConError('No se pudo preparar la consulta del contactor: ' . $conexion->error);
    }

    $stmtContactor->bind_param('i', $idContactor);
    $stmtContactor->execute();
    $filaContactor = $stmtContactor->get_result()->fetch_assoc();
    $stmtContactor->close();

    if ($filaContactor) {
        $contactorNombre = $filaContactor['contactor_name'];
    }
}

/* Obtener el contactor de potencial.
 * Es un adicional: se suma solo si el código tiene un precio mayor a cero.
 * Si todavía no tiene precio definido, la cotización continúa y lo informa.
 */
$contactorPotencial = null;
$precioContactorPotencial = 0.0;
$precioContactorPotencialDefinido = false;
$idContactorPotencial = $agregarContactorPotencial && isset($equipo['control_contactorpot'])
    ? (int)$equipo['control_contactorpot']
    : 0;

if ($idContactorPotencial > 0) {
    $sqlContactorPotencial = "SELECT
                                cp.contactorpot_id,
                                cp.contactorpot_name,
                                cp.contactorpot_codigo,
                                p.precios_descripcion,
                                p.precios_costo
                             FROM contactorpot cp
                             LEFT JOIN lista_precios p
                               ON TRIM(p.precios_codigo) = TRIM(cp.contactorpot_codigo)
                             WHERE cp.contactorpot_id = ?
                             LIMIT 1";

    $stmtContactorPotencial = $conexion->prepare($sqlContactorPotencial);

    if (!$stmtContactorPotencial) {
        volverConError('No se pudo preparar la consulta del contactor de potencial: ' . $conexion->error);
    }

    $stmtContactorPotencial->bind_param('i', $idContactorPotencial);
    $stmtContactorPotencial->execute();
    $contactorPotencial = $stmtContactorPotencial->get_result()->fetch_assoc();
    $contactorPotencial = aplicarPrecioVersion($conexion, $listaId, $contactorPotencial, 'contactorpot_codigo');
    $stmtContactorPotencial->close();

    if ($contactorPotencial &&
        isset($contactorPotencial['precios_costo']) &&
        $contactorPotencial['precios_costo'] !== null &&
        (float)$contactorPotencial['precios_costo'] > 0) {
        $precioContactorPotencial = (float)$contactorPotencial['precios_costo'];
        $precioContactorPotencialDefinido = true;
    }
}

/* Buscar térmico solo cuando corresponde.
 * Tipo 4 = VF, por lo tanto no lleva térmico.
 */
$termico = null;
$precioTermicoUnitario = 0.0;
$cantidadTermicos = 0;
$precioTermico = 0.0;

if ($idTipo !== 4) {
    $sqlTermico = "SELECT
                      t.termicos_codigo,
                      t.termicos_precio,
                      p.precios_descripcion,
                      p.precios_costo
                   FROM termicos t
                   LEFT JOIN lista_precios p
                     ON p.precios_codigo = t.termicos_codigo
                   WHERE t.termicos_tipo = ?
                     AND COALESCE(NULLIF(TRIM(t.termicos_tandem), ''), '') = ?
                     AND ? > t.termicos_potde
                     AND ? <= t.termicos_pothasta
                   ORDER BY t.termicos_potde DESC
                   LIMIT 1";

    $stmt = $conexion->prepare($sqlTermico);

    if (!$stmt) {
        volverConError('No se pudo preparar la consulta del térmico: ' . $conexion->error);
    }

    $stmt->bind_param('isdd', $idTipo, $esTandem, $potencia, $potencia);
    $stmt->execute();
    $termico = $stmt->get_result()->fetch_assoc();
    $termico = aplicarPrecioVersion($conexion, $listaId, $termico, 'termicos_codigo');
    $stmt->close();

    $cantidadTermicos = ($idTipo === 2) ? 2 : 1;

    if ($termico) {
        $precioTermicoUnitario = isset($termico['precios_costo']) && $termico['precios_costo'] !== null
            ? (float)$termico['precios_costo']
            : (float)$termico['termicos_precio'];

        $precioTermico = $precioTermicoUnitario * $cantidadTermicos;
    }
}

$precioBase = isset($equipo['precios_costo']) && $equipo['precios_costo'] !== null
    ? (float)$equipo['precios_costo']
    : (float)$equipo['control_precio'];


/* Configuración Tándem: suma un segundo control calculado como base menos el código de la tabla adicxtandem. */
$adicionalTandem = null;
$precioCodigoTandem = 0.0;
$precioAdicionalTandem = 0.0;
if ($esTandem === 'SI') {
    $sqlTandem = "SELECT at.adicxtandem_codigo,
                         lp.precios_descripcion,
                         lp.precios_costo
                  FROM adicxtandem at
                  LEFT JOIN lista_precios lp
                    ON TRIM(lp.precios_codigo) = TRIM(at.adicxtandem_codigo)
                  WHERE at.adicxtandem_cpu = ?
                    AND at.adicxtandem_subtipo = ?
                    AND at.adicxtandem_tandem = 'SI'
                  LIMIT 1";
    $stmtTandem = $conexion->prepare($sqlTandem);
    if (!$stmtTandem) {
        volverConError('No se pudo consultar la tabla de Tándem: ' . $conexion->error);
    }
    $stmtTandem->bind_param('ii', $idCpu, $idSubtipo);
    $stmtTandem->execute();
    $adicionalTandem = $stmtTandem->get_result()->fetch_assoc();
    $adicionalTandem = aplicarPrecioVersion($conexion, $listaId, $adicionalTandem, 'adicxtandem_codigo');
    $stmtTandem->close();

    if (!$adicionalTandem) {
        volverConError('No existe una configuración Tándem para la CPU y el subtipo seleccionados.');
    }
    if (!isset($adicionalTandem['precios_costo']) || $adicionalTandem['precios_costo'] === null || (float)$adicionalTandem['precios_costo'] <= 0) {
        volverConError('El código Tándem ' . $adicionalTandem['adicxtandem_codigo'] . ' no tiene un precio válido en la base Bejerman vigente.');
    }

    $precioCodigoTandem = (float)$adicionalTandem['precios_costo'];
    $precioAdicionalTandem = $precioBase - $precioCodigoTandem;
    if ($precioAdicionalTandem < 0) {
        volverConError('El precio del código Tándem es mayor que el precio base del control. Revise la base Bejerman vigente.');
    }
}

/* Adicional exclusivo para central ROJAS en equipos hidráulicos. */
$adicionalCentral = null;
$precioAdicionalCentral = 0.0;

if ($idTipo === 3 && $centralAdicionalClave !== '') {
    $codigoAdicionalCentral = buscarAdicionalConfigurado($conexion, $centralAdicionalClave)['precios_codigo'];
    $sqlAdicionalCentral = "SELECT precios_codigo, precios_descripcion, precios_costo
                            FROM lista_precios
                            WHERE precios_codigo = ?
                            LIMIT 1";

    $stmt = $conexion->prepare($sqlAdicionalCentral);

    if (!$stmt) {
        volverConError('No se pudo preparar la consulta del adicional de central hidráulica: ' . $conexion->error);
    }

    $stmt->bind_param('s', $codigoAdicionalCentral);
    $stmt->execute();
    $adicionalCentral = $stmt->get_result()->fetch_assoc();
    $adicionalCentral = aplicarPrecioVersion($conexion, $listaId, $adicionalCentral, 'precios_codigo');
    $stmt->close();

    if (!$adicionalCentral) {
        volverConError('No se encontró el código adicional de la central seleccionada en la base Bejerman vigente.');
    }

    $precioAdicionalCentral = (float)$adicionalCentral['precios_costo'];
}

/* Material de hueco: adicional por parada. */
$sqlAdicParada = "SELECT
                    a.adicxparada_codigo,
                    p.precios_descripcion,
                    p.precios_costo
                  FROM adicxparada a
                  LEFT JOIN lista_precios p
                    ON TRIM(p.precios_codigo) = TRIM(a.adicxparada_codigo)
                  WHERE a.adicxparada_tipoctrl = ?
                    AND a.adicxparada_hueco = ?
                    AND ((? = 'SI' AND UPPER(TRIM(COALESCE(a.adicxparada_posxenc, ''))) IN ('SI','X','1'))
                          OR (? = '' AND UPPER(TRIM(COALESCE(a.adicxparada_posxenc, ''))) NOT IN ('SI','X','1')))
                    AND ? >= a.adicxparada_velde
                    AND ? <= a.adicxparada_velhasta
                  ORDER BY a.adicxparada_velde ASC
                  LIMIT 1";
$stmt = $conexion->prepare($sqlAdicParada);
if (!$stmt) {
    volverConError('No se pudo preparar la consulta del adicional por parada: ' . $conexion->error);
}
$stmt->bind_param('iissdd', $idTipo, $idMaterialHueco, $posicionamientoEncoder, $posicionamientoEncoder, $velocidadCalculo, $velocidadCalculo);
$stmt->execute();
$adicionalParada = $stmt->get_result()->fetch_assoc();
$adicionalParada = aplicarPrecioVersion($conexion, $listaId, $adicionalParada, 'adicxparada_codigo');
$stmt->close();

if (!$adicionalParada) {
    volverConError('No existe un adicional por parada para la combinación de tipo de control, material de hueco, velocidad y encoder seleccionada.');
}
if (!isset($adicionalParada['precios_costo']) || $adicionalParada['precios_costo'] === null) {
    volverConError('El código ' . $adicionalParada['adicxparada_codigo'] . ' no tiene precio definido en la base Bejerman vigente.');
}
$precioAdicionalParadaUnitario = (float)$adicionalParada['precios_costo'];
$precioAdicionalParadas = $precioAdicionalParadaUnitario * $totalParadas;

/* Material de hueco: cabezales y soportes, una unidad por equipo. */
$sqlCabysop = "SELECT
                 c.cabysop_codigo,
                 p.precios_descripcion,
                 p.precios_costo
               FROM cabysop c
               LEFT JOIN lista_precios p
                 ON TRIM(p.precios_codigo) = TRIM(c.cabysop_codigo)
               WHERE c.cabysop_tipoctrl = ?
                 AND c.cabysop_hueco = ?
                 AND ((? = 'SI' AND UPPER(TRIM(COALESCE(c.cabysop_posxenc, ''))) IN ('SI','X','1'))
                       OR (? = '' AND UPPER(TRIM(COALESCE(c.cabysop_posxenc, ''))) NOT IN ('SI','X','1')))
                 AND ? >= c.cabysop_velde
                 AND ? <= c.cabysop_velhasta
               ORDER BY c.cabysop_velde ASC
               LIMIT 1";
$stmt = $conexion->prepare($sqlCabysop);
if (!$stmt) {
    volverConError('No se pudo preparar la consulta de cabezales y soportes: ' . $conexion->error);
}
$stmt->bind_param('iissdd', $idTipo, $idMaterialHueco, $posicionamientoEncoder, $posicionamientoEncoder, $velocidadCalculo, $velocidadCalculo);
$stmt->execute();
$cabezalSoporte = $stmt->get_result()->fetch_assoc();
$cabezalSoporte = aplicarPrecioVersion($conexion, $listaId, $cabezalSoporte, 'cabysop_codigo');
$stmt->close();

if (!$cabezalSoporte) {
    volverConError('No existe un adicional de cabezales y soportes para la combinación seleccionada.');
}
if (!isset($cabezalSoporte['precios_costo']) || $cabezalSoporte['precios_costo'] === null) {
    volverConError('El código ' . $cabezalSoporte['cabysop_codigo'] . ' no tiene precio definido en la base Bejerman vigente.');
}
$precioCabezalSoporte = (float)$cabezalSoporte['precios_costo'];

/* Puertas normales y puertas especiales para maniobra MC. */
$puertaCabina = null;
$puertasPisos = null;
$puertasPisosMc = null;
$puertaCabinaMc = null;

$adicionalPuertaCabina = null;
$adicionalPuertasPisos = null;
$adicionalPuertasPisosMc = null;
$adicionalPuertaCabinaMc = null;

$precioPuertaCabinaUnitario = 0.0;
$precioPuertaCabina = 0.0;
$precioPuertasPisos = 0.0;
$precioPuertasPisosMcUnitario = 0.0;
$precioPuertasPisosMc = 0.0;
$precioPuertaCabinaMcUnitario = 0.0;
$precioPuertaCabinaMc = 0.0;

if (!$esManiobraMc) {
    if (!$idPuertaCabina || !$idPuertasPisos) {
        volverConError('Debe seleccionar la puerta de cabina y las puertas de pisos.');
    }

    $sqlPuertaCabina = "SELECT
                            ptacabina_id,
                            ptacabina_name,
                            ptacabina_codigo,
                            ptacabina_pide_cantidad
                        FROM ptacabina
                        WHERE ptacabina_id = ?
                        LIMIT 1";

    $stmtPuerta = $conexion->prepare($sqlPuertaCabina);

    if (!$stmtPuerta) {
        volverConError('No se pudo consultar la puerta de cabina: ' . $conexion->error);
    }

    $stmtPuerta->bind_param('i', $idPuertaCabina);
    $stmtPuerta->execute();
    $puertaCabina = $stmtPuerta->get_result()->fetch_assoc();
    $stmtPuerta->close();

    if (!$puertaCabina) {
        volverConError('La puerta de cabina seleccionada no existe.');
    }

    if ($puertaCabina['ptacabina_pide_cantidad'] === 'SI') {
        if ($cantidadOperadores < 1) {
            volverConError('Debe indicar una cantidad válida de operadores.');
        }

        $adicionalPuertaCabina = buscarAdicionalPrecio(
            $conexion,
            $puertaCabina['ptacabina_codigo']
        );

        $precioPuertaCabinaUnitario = (float)$adicionalPuertaCabina['precios_costo'];
        $precioPuertaCabina = $precioPuertaCabinaUnitario * $cantidadOperadores;
    } else {
        $cantidadOperadores = 0;
    }

    $sqlPuertasPisos = "SELECT
                            ptapisos_id,
                            ptapisos_name,
                            ptapisos_codigo
                        FROM ptapisos
                        WHERE ptapisos_id = ?
                        LIMIT 1";

    $stmtPuerta = $conexion->prepare($sqlPuertasPisos);

    if (!$stmtPuerta) {
        volverConError('No se pudieron consultar las puertas de pisos: ' . $conexion->error);
    }

    $stmtPuerta->bind_param('i', $idPuertasPisos);
    $stmtPuerta->execute();
    $puertasPisos = $stmtPuerta->get_result()->fetch_assoc();
    $stmtPuerta->close();

    if (!$puertasPisos) {
        volverConError('Las puertas de pisos seleccionadas no existen.');
    }

    if (!empty($puertasPisos['ptapisos_codigo'])) {
        $adicionalPuertasPisos = buscarAdicionalPrecio(
            $conexion,
            $puertasPisos['ptapisos_codigo']
        );

        $precioPuertasPisos = (float)$adicionalPuertasPisos['precios_costo'];
    }
} else {
    if (!$idPuertasPisosMc || !$idPuertaCabinaMc) {
        volverConError('Debe seleccionar las puertas pisos MC y las puertas en cabina MC.');
    }

    $sqlPisosMc = "SELECT
                        ptapisosmc_id,
                        ptapisosmc_name,
                        ptapisosmc_codigo,
                        ptapisosmc_pide_cantidad
                   FROM ptapisos_mc
                   WHERE ptapisosmc_id = ?
                   LIMIT 1";

    $stmtPuerta = $conexion->prepare($sqlPisosMc);

    if (!$stmtPuerta) {
        volverConError('No se pudieron consultar las puertas pisos MC: ' . $conexion->error);
    }

    $stmtPuerta->bind_param('i', $idPuertasPisosMc);
    $stmtPuerta->execute();
    $puertasPisosMc = $stmtPuerta->get_result()->fetch_assoc();
    $stmtPuerta->close();

    if (!$puertasPisosMc) {
        volverConError('La opción de puertas pisos MC seleccionada no existe.');
    }

    if ($puertasPisosMc['ptapisosmc_pide_cantidad'] === 'SI') {
        if ($cantidadPisosMc < 1) {
            volverConError('Debe indicar la cantidad de puertas pisos MC.');
        }

        $adicionalPuertasPisosMc = buscarAdicionalPrecio(
            $conexion,
            $puertasPisosMc['ptapisosmc_codigo']
        );

        $precioPuertasPisosMcUnitario = (float)$adicionalPuertasPisosMc['precios_costo'];
        $precioPuertasPisosMc = $precioPuertasPisosMcUnitario * $cantidadPisosMc;
    } else {
        $cantidadPisosMc = 0;
    }

    $sqlCabinaMc = "SELECT
                        ptacabinamc_id,
                        ptacabinamc_name,
                        ptacabinamc_codigo,
                        ptacabinamc_pide_cantidad
                    FROM ptacabina_mc
                    WHERE ptacabinamc_id = ?
                    LIMIT 1";

    $stmtPuerta = $conexion->prepare($sqlCabinaMc);

    if (!$stmtPuerta) {
        volverConError('No se pudo consultar la puerta en cabina MC: ' . $conexion->error);
    }

    $stmtPuerta->bind_param('i', $idPuertaCabinaMc);
    $stmtPuerta->execute();
    $puertaCabinaMc = $stmtPuerta->get_result()->fetch_assoc();
    $stmtPuerta->close();

    if (!$puertaCabinaMc) {
        volverConError('La opción de puerta en cabina MC seleccionada no existe.');
    }

    if ($puertaCabinaMc['ptacabinamc_pide_cantidad'] === 'SI') {
        if ($cantidadCabinaMc < 1) {
            volverConError('Debe indicar la cantidad de puertas en cabina MC.');
        }

        $adicionalPuertaCabinaMc = buscarAdicionalPrecio(
            $conexion,
            $puertaCabinaMc['ptacabinamc_codigo']
        );

        $precioPuertaCabinaMcUnitario = (float)$adicionalPuertaCabinaMc['precios_costo'];
        $precioPuertaCabinaMc = $precioPuertaCabinaMcUnitario * $cantidadCabinaMc;
    } else {
        $cantidadCabinaMc = 0;
    }
}


/* Adicionales generales. Los seleccionables se valorizan una vez por equipo, salvo la cantidad manual de fuentes. */
$otrosAdicionales = array();
$precioOtrosAdicionalesTotal = 0.0;
$agregarOtroAdicional = function ($clave, $cantidad, $nota = '') use ($conexion, &$otrosAdicionales, &$precioOtrosAdicionalesTotal) {
    if ($cantidad <= 0) return;
    $articulo = buscarAdicionalConfigurado($conexion, $clave);
    $unitario = (float)$articulo['precios_costo'];
    $totalLinea = $unitario * $cantidad;
    $otrosAdicionales[] = array(
        'clave' => $clave,
        'codigo' => $articulo['precios_codigo'],
        'descripcion' => $articulo['precios_descripcion'],
        'cantidad' => $cantidad,
        'unitario' => $unitario,
        'total' => $totalLinea,
        'nota' => $nota
    );
    $precioOtrosAdicionalesTotal += $totalLinea;
};

if ($maniobraSabatica) {
    if (($cpuCapV213['admite_maniobra_sabatica'] ?? 'NO') !== 'SI') {
        volverConError('La maniobra sabática no está habilitada para la CPU ' . $nombreCpuCompat . '.');
    }
    $agregarOtroAdicional('MANIOBRA_SABATICA', $cantidadEquipos, 'Maniobra sabática estándar, una unidad por equipo');
}
if ($emergenciaCorte) $agregarOtroAdicional('EMERGENCIA_CORTE', $cantidadEquipos, 'Una unidad por equipo');
if ($forzadorAire) $agregarOtroAdicional('FORZADOR_AIRE', $cantidadEquipos, 'Una unidad por equipo');
if ($luzCortesia) $agregarOtroAdicional('LUZ_CORTESIA', $cantidadEquipos, 'Una unidad por equipo');
if ($llaveRamos) $agregarOtroAdicional('LLAVE_RAMOS', $cantidadEquipos, 'Una unidad por equipo');
if ($descansoFreno) $agregarOtroAdicional('DESCANSO_FRENO', $cantidadEquipos, 'Una unidad por equipo');
if ($cantidadFuentes24v > 0) {
    $cantidadTotalFuentes24v = $cantidadFuentes24v * $cantidadEquipos;
    $notaFuentes24v = $cantidadFuentes24v . ' por equipo × ' . $cantidadEquipos . ' equipos';

    /* Cada fuente F24V2A lleva una térmica 7LTM1. */
    $agregarOtroAdicional(
        'FUENTE_24V',
        $cantidadTotalFuentes24v,
        $notaFuentes24v
    );

    $articuloTermicaFuente24v = buscarAdicionalPrecio($conexion, '7LTM1');
    $precioTermicaFuente24v = (float)$articuloTermicaFuente24v['precios_costo'];
    $totalTermicasFuente24v = $precioTermicaFuente24v * $cantidadTotalFuentes24v;
    $otrosAdicionales[] = array(
        'clave' => 'FUENTE_24V_TERMICA',
        'codigo' => $articuloTermicaFuente24v['precios_codigo'],
        'descripcion' => $articuloTermicaFuente24v['precios_descripcion'],
        'cantidad' => $cantidadTotalFuentes24v,
        'unitario' => $precioTermicaFuente24v,
        'total' => $totalTermicasFuente24v,
        'nota' => 'Una 7LTM1 por cada fuente F24V2A — ' . $notaFuentes24v
    );
    $precioOtrosAdicionalesTotal += $totalTermicasFuente24v;
}

$nombrePuertaCabinaSeleccionada = !$esManiobraMc && $puertaCabina ? strtoupper($puertaCabina['ptacabina_name']) : ($esManiobraMc && $puertaCabinaMc ? strtoupper($puertaCabinaMc['ptacabinamc_name']) : '');
$esPuertaVfCabina = strpos($nombrePuertaCabinaSeleccionada, 'VF') !== false;
if ($alimentacionPuertaVf) {
    if (!$esPuertaVfCabina) volverConError('La alimentación para puerta VF solo puede seleccionarse con una puerta VF en cabina.');
    if ($cantidadOperadores < 1) {
        volverConError('Debe indicar la cantidad de operadores VF por equipo.');
    }
    $cantidadAlimentacionesPuertaVf = $cantidadOperadores * $cantidadEquipos;
    $agregarOtroAdicional(
        'ALIMENTACION_PUERTA_VF',
        $cantidadAlimentacionesPuertaVf,
        $cantidadOperadores . ' operador(es) VF por equipo × ' . $cantidadEquipos . ' equipos'
    );
}
if ($comSerieTotal) $agregarOtroAdicional('CONCENTRADOR_LLAMADAS', $cantidadEquipos, 'Automático por comunicación serie Total');

/* Interfase: una por equipo, valorizada individualmente según las paradas de cada coche. */
if ($interfaseTipo !== '') {
    $cantidadesInterfasePorCodigo = array('ADIC44' => 0, 'ADIC45' => 0, 'ADIC46' => 0, 'ADIC47' => 0);
    foreach ($paradasPorEquipo as $indiceInterfase => $paradasInterfase) {
        if ($paradasInterfase <= 8) {
            $codigoInterfase = 'ADIC44';
        } elseif ($paradasInterfase <= 16) {
            $codigoInterfase = 'ADIC45';
        } elseif ($paradasInterfase <= 24) {
            $codigoInterfase = 'ADIC46';
        } elseif ($paradasInterfase <= 32) {
            $codigoInterfase = 'ADIC47';
        } else {
            volverConError('La interfase está definida hasta 32 paradas. El equipo ' . ($indiceInterfase + 1) . ' tiene ' . $paradasInterfase . ' paradas.');
        }
        $cantidadesInterfasePorCodigo[$codigoInterfase]++;
    }

    foreach ($cantidadesInterfasePorCodigo as $codigoInterfase => $cantidadInterfase) {
        if ($cantidadInterfase <= 0) continue;
        $articuloInterfase = buscarAdicionalPrecio($conexion, $codigoInterfase);
        $unitarioInterfase = (float)$articuloInterfase['precios_costo'];
        $totalInterfase = $unitarioInterfase * $cantidadInterfase;
        $otrosAdicionales[] = array(
            'clave' => 'INTERFASE',
            'codigo' => $articuloInterfase['precios_codigo'],
            'descripcion' => $articuloInterfase['precios_descripcion'],
            'cantidad' => $cantidadInterfase,
            'unitario' => $unitarioInterfase,
            'total' => $totalInterfase,
            'nota' => $cantidadInterfase . ' equipo(s) dentro del rango de paradas correspondiente'
        );
        $precioOtrosAdicionalesTotal += $totalInterfase;
    }

    if ($interfaseTipo === 'A7120_A7121') {
        $articuloA7121 = buscarAdicionalPrecio($conexion, 'P7121');
        $unitarioA7121 = (float)$articuloA7121['precios_costo'];
        $totalA7121 = $unitarioA7121 * $cantidadEquipos;
        $otrosAdicionales[] = array(
            'clave' => 'INTERFASE_A7121',
            'codigo' => $articuloA7121['precios_codigo'],
            'descripcion' => $articuloA7121['precios_descripcion'],
            'cantidad' => $cantidadEquipos,
            'unitario' => $unitarioA7121,
            'total' => $totalA7121,
            'nota' => 'Una P7121 por equipo para la opción Interfase A-7120 + A-7121'
        );
        $precioOtrosAdicionalesTotal += $totalA7121;
    }
}

/* Fuente switching para botoneras touch: una F24V2AR por equipo.
 * Por ahora puede seleccionarse manualmente. La detección automática queda preparada
 * para los campos de botoneras y pulsadores que se incorporarán en la próxima etapa.
 */
$cantidadPulsadoresTouch = 0;
$camposCantidadPulsadoresTouch = array(
    'cantidad_pulsadores_simples_cabina',
    'cantidad_pulsadores_dobles_cabina',
    'cantidad_pulsadores_simples_pisos',
    'cantidad_pulsadores_dobles_pisos'
);
foreach ($camposCantidadPulsadoresTouch as $campoPulsadorTouch) {
    if (!isset($_POST[$campoPulsadorTouch]) || $_POST[$campoPulsadorTouch] === '') continue;
    $valorPulsadorTouch = filter_var($_POST[$campoPulsadorTouch], FILTER_VALIDATE_INT);
    if ($valorPulsadorTouch === false || $valorPulsadorTouch < 0) {
        volverConError('La cantidad de pulsadores simples y dobles no es válida.');
    }
    $cantidadPulsadoresTouch += (int)$valorPulsadorTouch;
}

$nombreBotoneraTouch = '';
foreach (array('botonera_modelo_nombre', 'botonera_nombre', 'nombre_botonera') as $campoNombreBotonera) {
    if (isset($_POST[$campoNombreBotonera]) && trim((string)$_POST[$campoNombreBotonera]) !== '') {
        $nombreBotoneraTouch = trim((string)$_POST[$campoNombreBotonera]);
        break;
    }
}
$nombreBotoneraTouchNormalizado = function_exists('mb_strtoupper')
    ? mb_strtoupper($nombreBotoneraTouch, 'UTF-8')
    : strtoupper($nombreBotoneraTouch);
$botonerasTouchCompatibles = array(
    'PANTALLA TOUCH 21 PULG. NEGRO',
    'ONIX TELEFONICO NEGRO',
    'ONIX TELEFÓNICO NEGRO',
    'ONIX PULS. INDIVIDUALES NEGRO',
    'ONIX PULS. INDIVIDUALES BLANCO'
);
$fuenteSwitchingTouchAutomatica = $cantidadPulsadoresTouch > 0
    && in_array($nombreBotoneraTouchNormalizado, $botonerasTouchCompatibles, true);
$fuenteSwitchingTouch = $fuenteSwitchingTouchManual || $fuenteSwitchingTouchAutomatica;

if ($fuenteSwitchingTouch) {
    $articuloFuenteTouch = buscarAdicionalPrecio($conexion, 'F24V2AR');
    $unitarioFuenteTouch = (float)$articuloFuenteTouch['precios_costo'];
    $totalFuenteTouch = $unitarioFuenteTouch * $cantidadEquipos;
    $motivoFuenteTouch = $fuenteSwitchingTouchAutomatica
        ? 'Automático: botonera touch compatible con pulsadores simples o dobles'
        : 'Seleccionado manualmente';
    $otrosAdicionales[] = array(
        'clave' => 'FUENTE_SWITCHING_TOUCH',
        'codigo' => $articuloFuenteTouch['precios_codigo'],
        'descripcion' => $articuloFuenteTouch['precios_descripcion'],
        'cantidad' => $cantidadEquipos,
        'unitario' => $unitarioFuenteTouch,
        'total' => $totalFuenteTouch,
        'nota' => 'Una fuente por equipo × ' . $cantidadEquipos . ' equipo(s). ' . $motivoFuenteTouch
    );
    $precioOtrosAdicionalesTotal += $totalFuenteTouch;
}

if ($retornoBateriaGel && $idTipo !== 3) {
    volverConError('El retorno automático por batería de gel solo está disponible para controles hidráulicos.');
}
if ($retornoBateriaGel) {
    $agregarOtroAdicional('RETORNO_BATERIA_GEL', $cantidadEquipos, '1 retorno automático por cada equipo hidráulico');
}

if ($micronivelacion) {
    if (($tipoCapV213['requiere_central'] ?? 'NO') !== 'SI') {
        volverConError('La micronivelación solo está disponible para tipos de Control configurados como hidráulicos.');
    }
    if (($cpuCapV213['admite_micronivelacion'] ?? 'NO') !== 'SI') {
        volverConError('La micronivelación no está habilitada para la CPU ' . $nombreCpuCompat . '.');
    }
    $agregarOtroAdicional('MICRONIVELACION', $cantidadEquipos, 'Una unidad por cada equipo hidráulico');
}

$protectorFaltaFaseIncluido = $esArranqueSuave;
if (!$protectorFaltaFaseIncluido && $protectorFaltaFaseSeleccionado) {
    $agregarOtroAdicional('PROTECTOR_FALTA_FASE', $cantidadEquipos, 'Una unidad por equipo');
}


/* Rescates configurables. Cada componente toma su precio desde la base Bejerman vigente. */
$rescateSeleccionado = null;
$familiaRescate = '';
$componentesRescate = array();
$precioRescateTotal = 0.0;
if ($idRescate > 0) {
    $tablaRescates = esquemaTablaExiste($conexion,'rescates_opciones');
    $tablaComponentesRescate = esquemaTablaExiste($conexion,'rescates_componentes');
    if (!$tablaRescates || !$tablaComponentesRescate) {
        volverConError('Falta instalar la tabla de rescates. Importe migracion_rescates_2026-07-30.sql.');
    }

    $stmtRescate = $conexion->prepare("SELECT rescate_id, rescate_clave, rescate_nombre, rescate_familia FROM rescates_opciones WHERE rescate_id=? AND rescate_activo='SI' LIMIT 1");
    if (!$stmtRescate) volverConError('No se pudo consultar el rescate seleccionado: ' . $conexion->error);
    $stmtRescate->bind_param('i', $idRescate);
    $stmtRescate->execute();
    $rescateSeleccionado = $stmtRescate->get_result()->fetch_assoc();
    $stmtRescate->close();
    if (!$rescateSeleccionado) volverConError('El rescate seleccionado no existe o está deshabilitado.');

    $familiaRescate = $rescateSeleccionado['rescate_familia'];
    $descripcionEquipoMayus = strtoupper((string)($equipo['precios_descripcion'] ?? ''));
    $familiaTipoConfigurada = (string)($tipoCapV213['familia_rescate'] ?? '');
    if ($familiaRescate !== '' && $familiaTipoConfigurada !== $familiaRescate) {
        volverConError('El rescate seleccionado no corresponde a la familia de rescate configurada para este tipo de Control.');
    }

    /* Rescate integral INVT: el código se determina automáticamente por la corriente del variador. */
    if ($rescateSeleccionado['rescate_clave'] === 'INVT_INTEGRAL_SIN_UPS') {
        $subtipoMayus = strtoupper((string)$nombreSubtipo);
        $esInvt = strpos($subtipoMayus, 'INVT GD300L') !== false || strpos($subtipoMayus, 'INVT GD390L') !== false;
        if (!$esInvt) {
            volverConError('El rescate integral solamente está disponible para variadores INVT GD300L o INVT GD390L.');
        }
        $corrienteVariador = (int)round((float)($equipo['control_corriente'] ?? 0));
        $codigosRescateIntegral = array(
            10 => 'REINTI10',
            14 => 'REINTI14',
            18 => 'REINTI18',
            25 => 'REINTI25',
            32 => 'REINTI32',
            39 => 'REINTI39',
            45 => 'REINTI45',
            60 => 'REINTI60'
        );
        if (!isset($codigosRescateIntegral[$corrienteVariador])) {
            volverConError('No existe un rescate integral configurado para la corriente de ' . $corrienteVariador . ' A del variador seleccionado.');
        }
        $codigoIntegral = $codigosRescateIntegral[$corrienteVariador];
        $articuloIntegral = precioDeLista($conexion, $listaId, $codigoIntegral);
        if (!$articuloIntegral || !isset($articuloIntegral['precios_costo']) || (float)$articuloIntegral['precios_costo'] <= 0) {
            volverConError('El código de rescate integral ' . $codigoIntegral . ' no existe o no tiene precio válido en la base Bejerman vigente.');
        }
        $unitarioIntegral = (float)$articuloIntegral['precios_costo'];
        $totalIntegral = $unitarioIntegral * $cantidadEquipos;
        $componentesRescate[] = array(
            'codigo' => $codigoIntegral,
            'descripcion' => $articuloIntegral['precios_descripcion'],
            'cantidad' => $cantidadEquipos,
            'unitario' => $unitarioIntegral,
            'total' => $totalIntegral,
            'formula' => 'Corriente del variador: ' . $corrienteVariador . ' A; 1 por equipo × ' . $cantidadEquipos . ' equipo(s)'
        );
        $precioRescateTotal = $totalIntegral;
    } else {

    $stmtComp = $conexion->prepare("SELECT componente_codigo, componente_regla, componente_cantidad, componente_orden FROM rescates_componentes WHERE rescate_id=? AND componente_activo='SI' ORDER BY componente_orden, componente_id");
    if (!$stmtComp) volverConError('No se pudieron consultar los componentes del rescate: ' . $conexion->error);
    $stmtComp->bind_param('i', $idRescate);
    $stmtComp->execute();
    $resultadoComp = $stmtComp->get_result();
    while ($comp = $resultadoComp->fetch_assoc()) {
        $codigoComp = trim((string)$comp['componente_codigo']);
        $articuloComp = precioDeLista($conexion, $listaId, $codigoComp);
        if (!$articuloComp || !isset($articuloComp['precios_costo']) || (float)$articuloComp['precios_costo'] <= 0) {
            volverConError('El componente de rescate ' . $codigoComp . ' no existe o no tiene precio válido en la base Bejerman vigente.');
        }
        $reglaComp = $comp['componente_regla'];
        $cantidadBaseComp = (float)$comp['componente_cantidad'];
        if ($reglaComp === 'PARADAS_MENOS_2') {
            $cantidadComp = 0;
            foreach ($paradasPorEquipo as $paradasCocheRescate) {
                $cantidadComp += max(0, (int)$paradasCocheRescate - 2) * $cantidadBaseComp;
            }
            $formulaComp = 'Suma de máximo(0, paradas de cada coche - 2)';
        } else {
            $cantidadComp = $cantidadEquipos * $cantidadBaseComp;
            $formulaComp = number_format($cantidadBaseComp, 2, ',', '.') . ' por equipo × ' . $cantidadEquipos . ' equipo(s)';
        }
        if ($cantidadComp <= 0) continue;
        $unitarioComp = (float)$articuloComp['precios_costo'];
        $totalComp = $unitarioComp * $cantidadComp;
        $componentesRescate[] = array(
            'codigo' => $codigoComp,
            'descripcion' => $articuloComp['precios_descripcion'],
            'cantidad' => $cantidadComp,
            'unitario' => $unitarioComp,
            'total' => $totalComp,
            'formula' => $formulaComp
        );
        $precioRescateTotal += $totalComp;
    }
    $stmtComp->close();
    if (!$componentesRescate) volverConError('El rescate seleccionado no tiene componentes activos configurados.');
    }
}

/*
 * Presentación comercial del rescate. El cálculo auxiliar conserva el desglose
 * técnico por componentes, pero el documento comercial muestra una sola línea
 * de rescate separada del Control. El importe bruto sigue sujeto a los mismos
 * descuentos comerciales del Control, por lo que separar la línea no altera el
 * total final de la cotización.
 */
$codigoComercialRescate = '';
$descripcionComercialRescate = '';
if ($rescateSeleccionado) {
    $mapaCodigosRescate = array(
        'HID_ECO_MIDI' => 'A0750CS',
        'HID_220_SIN_UPS' => 'A6XREMPAH',
        'HID_220_UPS08' => 'A6XREMPAH0.8',
        'HID_VF_SIN_UPS' => 'A6XREMPVFH',
        'HID_VF_UPS08' => 'A6XREMPVFH0.8',
        'HID_COMPLETO_UPS' => 'REINTHIDRA',
        'MRL_AUTO_SIN_UPS' => 'A6XREA',
        'MRL_MANUAL_SIN_UPS' => 'A6XREM',
        'MRL_AUTO_UPS08' => 'A6XREA0.8',
        'MRL_MANUAL_UPS08' => 'A6XREM0.8'
    );
    $claveRescate = (string)($rescateSeleccionado['rescate_clave'] ?? '');
    if ($claveRescate === 'INVT_INTEGRAL_SIN_UPS' && count($componentesRescate) === 1) {
        $codigoComercialRescate = (string)($componentesRescate[0]['codigo'] ?? '');
    } else {
        $codigoComercialRescate = (string)($mapaCodigosRescate[$claveRescate] ?? '');
    }
    if ($codigoComercialRescate === '' && count($componentesRescate) === 1) {
        $codigoComercialRescate = (string)($componentesRescate[0]['codigo'] ?? '');
    }
    $descripcionComercialRescate = trim((string)($rescateSeleccionado['rescate_nombre'] ?? 'Rescate'));
}

/* Adicionales exclusivos para la categoría de control MRL. */
$tiposPermitidosUcmMrl = array(7);
$esControlMrl = in_array((int)$idTipo, $tiposPermitidosUcmMrl, true);
if (($adicionalUcmMrl || $cantidadFuentesMrl > 0) && !$esControlMrl) {
    volverConError('El adicional UCM y la fuente para indicadores en pisos solo corresponden a controles MRL.');
}

$articuloAdicUcm = null;
$articuloDesarmeLimitador = null;
$articuloFuenteMrl = null;
$precioAdicionalUcmUnitario = 0.0;
$precioAdicionalUcmTotal = 0.0;
$precioFuenteMrlUnitario = 0.0;
$cantidadTotalFuentesMrl = 0;
$precioFuentesMrlTotal = 0.0;
$precioUcmFuenteMrlTotal = 0.0;

if ($adicionalUcmMrl) {
    $articuloAdicUcm = buscarAdicionalPrecio($conexion, 'ADICUCM');
    $articuloDesarmeLimitador = buscarAdicionalPrecio($conexion, 'A6XDESREALIMVEL');
    $precioAdicionalUcmUnitario = (float)$articuloAdicUcm['precios_costo'] - (float)$articuloDesarmeLimitador['precios_costo'];
    if ($precioAdicionalUcmUnitario < 0) {
        volverConError('El precio de ADICUCM no puede ser menor que el precio de A6XDESREALIMVEL en la base Bejerman vigente.');
    }
    $precioAdicionalUcmTotal = $precioAdicionalUcmUnitario * $cantidadEquipos;
}

if ($cantidadFuentesMrl > 0) {
    $articuloFuenteMrl = buscarAdicionalPrecio($conexion, 'F24V2AMRL');
    $precioFuenteMrlUnitario = (float)$articuloFuenteMrl['precios_costo'];
    $cantidadTotalFuentesMrl = $cantidadFuentesMrl * $cantidadEquipos;
    $precioFuentesMrlTotal = $precioFuenteMrlUnitario * $cantidadTotalFuentesMrl;
}
$precioUcmFuenteMrlTotal = $precioAdicionalUcmTotal + $precioFuentesMrlTotal;

/* Adicional de batería: el importe calculado es por equipo y se multiplica por los equipos cotizados. */
$adicionalBateria = null;
$precioConexionBateriaBase = 0.0;
$porcentajeBaseBateria = 0.0;
$importePorcentajeBateria = 0.0;
$precioAdicionalBateriaUnitario = 0.0;
$precioAdicionalBateria = 0.0;
$formulaAdicionalBateria = '';
if (!$esBateriaIndividual) {
    $sqlBateria = "SELECT mb.codigo_adicional, lp.precios_descripcion, lp.precios_costo
                   FROM matriz_baterias mb
                   LEFT JOIN lista_precios lp ON TRIM(lp.precios_codigo) = TRIM(mb.codigo_adicional)
                   WHERE mb.cpu_id = ? AND mb.bateria_id = ? AND mb.cantidad_equipos = ?
                   LIMIT 1";
    $stmtBateria = $conexion->prepare($sqlBateria);
    if (!$stmtBateria) {
        volverConError('No se pudo consultar la matriz de baterías. Importe primero el archivo SQL de baterías.');
    }
    $stmtBateria->bind_param('iii', $idCpu, $idBateria, $cantidadTotalCochesBateria);
    $stmtBateria->execute();
    $adicionalBateria = $stmtBateria->get_result()->fetch_assoc();
    $adicionalBateria = aplicarPrecioVersion($conexion, $listaId, $adicionalBateria, 'codigo_adicional');
    $stmtBateria->close();
    if (!$adicionalBateria) {
        volverConError('No existe una configuración para ' . $tipoBateriaNombre . ' con la CPU seleccionada y ' . $cantidadTotalCochesBateria . ' coches.');
    }
    if (!isset($adicionalBateria['precios_costo']) || $adicionalBateria['precios_costo'] === null || (float)$adicionalBateria['precios_costo'] <= 0) {
        volverConError('El código adicional de batería ' . $adicionalBateria['codigo_adicional'] . ' no tiene un precio válido en la base Bejerman vigente.');
    }
    $precioConexionBateriaBase = (float)$adicionalBateria['precios_costo'];

    /*
     * Regla comercial de conexión de batería:
     * 1 coche  -> CXBAT2
     * 2 coches -> CXBAT3
     * 3 coches -> CXBAT4 + 0,5 % de la base unitaria del equipo
     * 4 o más  -> CXBAT4 + 10 % de la base unitaria del equipo
     * El resultado unitario se multiplica por la cantidad de equipos cotizados.
     */
    if ($cantidadTotalCochesBateria === 3) {
        $porcentajeBaseBateria = 0.005;
    } elseif ($cantidadTotalCochesBateria >= 4) {
        $porcentajeBaseBateria = 0.10;
    }

    $importePorcentajeBateria = $precioBase * $porcentajeBaseBateria;
    $precioAdicionalBateriaUnitario = $precioConexionBateriaBase + $importePorcentajeBateria;
    $precioAdicionalBateria = $precioAdicionalBateriaUnitario * $cantidadEquipos;

    if ($porcentajeBaseBateria > 0) {
        $formulaAdicionalBateria = '(CXBAT + ' . number_format($porcentajeBaseBateria * 100, 1, ',', '.') . '% de la base unitaria) × ' . $cantidadEquipos . ' equipo(s)';
    } else {
        $formulaAdicionalBateria = '1 conexión por equipo × ' . $cantidadEquipos . ' equipo(s)';
    }
}

/* Botonera de cabina opcional, agregada desde la solapa Senalizacion. */
$senalizacionIncluida = isset($_POST['senal_incluir']) && $_POST['senal_incluir'] === '1';
$senalizacionLineas = array();
$precioSenalizacionTotal = 0.0;
if ($senalizacionIncluida) {
    try {
        $senalCalc = calcularLineasSenalizacionCabina($conexion, $listaId, $_POST);
        $senalDesc = aplicarDescuentosSenalizacion($senalCalc['total_bruto'], $_POST);
        $factorSenal = (float)$senalDesc['factor'];
        $senalizacionLineas = $senalCalc['lineas'];
        foreach ($senalizacionLineas as &$sl) {
            $sl['unitario'] = ceil((float)$sl['unitario'] * $factorSenal);
            $sl['total'] = ceil((float)$sl['unitario'] * (float)$sl['cantidad']);
            $sl['formula'] = $sl['formula'].' · desc. '.number_format($senalDesc['d1'],0).'% + '.number_format($senalDesc['d2'],0).'% + '.number_format($senalDesc['d3'],0).'%';
        }
        unset($sl);
        $precioSenalizacionTotal = 0.0; foreach ($senalizacionLineas as $slTotal) $precioSenalizacionTotal += (float)$slTotal['total'];
    } catch (Throwable $e) {
        volverConError($e->getMessage());
    }
}

/* Los componentes técnicos comunes y la conexión de batería se valorizan por equipo. */
$subtotalFijoUnitario = $precioBase
    + $precioAdicionalTandem
    + $precioTermico
    + $precioContactorPotencial
    + $precioPosicionamientoEncoderUnitario
    + $precioAdicionalCentral
    + $precioCabezalSoporte
    + $precioPuertaCabina
    + $precioPuertasPisos
    + $precioPuertasPisosMc
    + $precioPuertaCabinaMc;
$subtotalFijosEquipos = $subtotalFijoUnitario * $cantidadEquipos;
try { $lineasModulosLibres = obtenerLineasModulosLibres($_POST); } catch (Throwable $e) { volverConError($e->getMessage()); }
$precioModulosLibresTotal = 0.0; foreach ($lineasModulosLibres as $lm) $precioModulosLibresTotal += (float)$lm['total'];
$subtotal = $subtotalFijosEquipos
    + $precioAdicionalParadas
    + $precioAdicionalBateria
    + $precioOtrosAdicionalesTotal
    + $precioRescateTotal
    + $precioUcmFuenteMrlTotal
    + $precioAdicionalesManualesTotal
    + $precioSenalizacionTotal
    + $precioModulosLibresTotal;

/* Los descuentos generales pertenecen al cálculo del Control.
 * Los módulos libres (Repuestos, Accesorios e IEP) ya llegan con su precio
 * unitario definitivo y no deben recibir nuevamente 30 + 10 + 10. */
$subtotalSujetoDescuentos = max(0.0, $subtotal - $precioModulosLibresTotal - $precioSenalizacionTotal);
$importeDescuento1 = $subtotalSujetoDescuentos * ($descuento1 / 100);
$subtotalDespuesD1 = $subtotalSujetoDescuentos - $importeDescuento1;
$importeDescuento2 = $subtotalDespuesD1 * ($descuento2 / 100);
$subtotalDespuesD2 = $subtotalDespuesD1 - $importeDescuento2;
$importeDescuento3 = $subtotalDespuesD2 * ($descuento3 / 100);
$totalControlDescontado = $subtotalDespuesD2 - $importeDescuento3;
$total = ceil($totalControlDescontado + $precioSenalizacionTotal + $precioModulosLibresTotal);
$subtotal = ceil($subtotal);

/* Fotografía comercial: cada línea queda congelada con la base Bejerman vigente. */
$lineasSnapshot = array();
$agregarSnapshot = function($concepto,$codigo,$descripcion,$cantidad,$unitario,$formula,$importe,$modulo='CONTROL') use (&$lineasSnapshot) {
    if ((float)$importe == 0.0 && trim((string)$codigo)==='') return;
    $lineasSnapshot[] = array('modulo'=>$modulo,'concepto'=>$concepto,'codigo'=>(string)$codigo,'descripcion'=>(string)$descripcion,'cantidad'=>(float)$cantidad,'unitario'=>(float)$unitario,'formula'=>(string)$formula,'total'=>(float)$importe);
};
/* Descripción comercial completa: se congela junto con la cotización y luego se hereda al pedido. */
$consultarNombreComercial = function ($tabla, $campoId, $campoNombre, $id) use ($conexion) {
    $permitidos = array(
        'cpus' => array('cpu_id', 'cpu_name'),
        'tensiones' => array('tension_id', 'tension_name'),
        'materiales_hueco' => array('mathueco_id', 'mathueco_name')
    );
    if (!isset($permitidos[$tabla]) || $permitidos[$tabla][0] !== $campoId || $permitidos[$tabla][1] !== $campoNombre) return '';
    $sql = "SELECT {$campoNombre} AS nombre FROM {$tabla} WHERE {$campoId} = ? LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) return '';
    $id = (int)$id;
    $st->bind_param('i', $id);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    return $fila ? trim((string)$fila['nombre']) : '';
};

$cpuDescripcion = $consultarNombreComercial('cpus', 'cpu_id', 'cpu_name', $idCpu);
$tensionDescripcion = $consultarNombreComercial('tensiones', 'tension_id', 'tension_name', $idTension);
$materialHuecoDescripcion = $consultarNombreComercial('materiales_hueco', 'mathueco_id', 'mathueco_name', $idMaterialHueco);

$numeroLimpio = function ($valor) {
    if (!is_numeric($valor)) return trim((string)$valor);
    return rtrim(rtrim(number_format((float)$valor, 2, '.', ''), '0'), '.');
};

$corrienteDescripcion = $numeroLimpio($equipo['control_corriente'] ?? '');
$contactorDescripcion = trim((string)$contactorNombre);
if ($contactorDescripcion !== '' && preg_match('/(\d+(?:[.,]\d+)?)/', $contactorDescripcion, $mContactor)) {
    $contactorDescripcion = str_replace(',', '.', $mContactor[1]);
}
$velocidadDescripcion = $numeroLimpio($velocidadCalculo);
$potenciaDescripcion = $numeroLimpio($potencia);
$paradasDescripcion = count(array_unique($paradasPorEquipo)) === 1
    ? (string)$paradasPorEquipo[0]
    : implode(' / ', $paradasPorEquipo);

$puertaCabinaDescripcion = '';
$puertaPisosDescripcion = '';
if (!$esManiobraMc) {
    $puertaCabinaDescripcion = trim((string)($puertaCabina['ptacabina_name'] ?? ''));
    $puertaPisosDescripcion = trim((string)($puertasPisos['ptapisos_name'] ?? ''));
} else {
    $puertaCabinaDescripcion = trim((string)($puertaCabinaMc['ptacabinamc_name'] ?? ''));
    $puertaPisosDescripcion = trim((string)($puertasPisosMc['ptapisosmc_name'] ?? ''));
}

$comunicacionDescripcion = 'comunicación paralelo';
if ($nombreComSerie !== '') {
    $comMayus = strtoupper($nombreComSerie);
    if (strpos($comMayus, 'TOTAL') !== false) $comunicacionDescripcion = 'comunicación serie total';
    elseif (strpos($comMayus, 'CABINA') !== false) $comunicacionDescripcion = 'comunicación serie en cabina';
    elseif (strpos($comMayus, 'PISO') !== false) $comunicacionDescripcion = 'comunicación serie en pisos';
    else $comunicacionDescripcion = 'comunicación serie ' . strtolower($nombreComSerie);
}

$textoSinAcentos = function ($texto) {
    $texto = trim((string)$texto);
    if ($texto === '') return '';
    $mapa = array(
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'
    );
    $texto = strtr($texto, $mapa);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return trim((string)$texto);
};

$limpiarDescripcionComponente = function ($texto) use ($textoSinAcentos) {
    $texto = $textoSinAcentos($texto);
    $texto = preg_replace('/\s+/', ' ', $texto);
    return trim((string)$texto, " \t\n\r\0\x0B.,;-");
};

/* Descripcion comercial completa, adaptada al tipo de control. */
$partesDescripcionControl = array();
$partesDescripcionControl[] = 'Control' . ($cpuDescripcion !== '' ? ' ' . $textoSinAcentos($cpuDescripcion) : '');
if ($nombreManiobra !== '') $partesDescripcionControl[] = 'maniobra ' . $textoSinAcentos($nombreManiobra);
if ($configuracionEspecialControl === 'DOBLE_ACCESO_SELECTIVO') $partesDescripcionControl[] = 'doble acceso selectivo';
elseif ($configuracionEspecialControl === 'TIP') $partesDescripcionControl[] = 'maniobra TIP ' . ($programaTip === 'ESPECIAL_ESTANDAR' ? 'especial + estandar' : 'especial + especial');

$esHidraulicoDescripcion = ((int)$idTipo === 3);
$tiposConReguladorDescripcion = array(4, 5, 6, 7);

if ($esHidraulicoDescripcion) {
    $partesDescripcionControl[] = 'para equipo hidraulico';
    if ($centralNombre !== '') $partesDescripcionControl[] = 'central ' . $textoSinAcentos($centralNombre);
} elseif (in_array((int)$idTipo, $tiposConReguladorDescripcion, true) && $nombreSubtipo !== '') {
    $textoRegulador = 'regulador ' . $textoSinAcentos($nombreSubtipo);
    if ($corrienteDescripcion !== '' && (float)$corrienteDescripcion > 0) {
        $textoRegulador .= ' de ' . $corrienteDescripcion . ' A';
    }
    $partesDescripcionControl[] = $textoRegulador;
} elseif ($nombreSubtipo !== '' && stripos($nombreSubtipo, 'DIRECTO') === false) {
    $partesDescripcionControl[] = $textoSinAcentos($nombreSubtipo);
}

if ($contactorDescripcion !== '' && (float)$contactorDescripcion > 0) $partesDescripcionControl[] = 'contactor de ' . $contactorDescripcion . ' A';
if ($potenciaDescripcion !== '') $partesDescripcionControl[] = 'potencia ' . $potenciaDescripcion . ' HP';
if ($tensionDescripcion !== '') $partesDescripcionControl[] = 'alimentacion ' . $textoSinAcentos($tensionDescripcion);
if (!$esHidraulicoDescripcion && $velocidadDescripcion !== '' && (float)$velocidadCalculo > 0) $partesDescripcionControl[] = 'velocidad ' . $velocidadDescripcion . ' m/min';
if ($paradasDescripcion !== '') $partesDescripcionControl[] = 'para ' . $paradasDescripcion . ' paradas';
$partesNomenclaturaControl = array();
foreach ($paradasPorEquipo as $iNom => $pNom) {
    $nomControl = trim((string)($nomenclaturasPorEquipo[$iNom] ?? ''));
    if ($nomControl === '') $nomControl = 'A CONFIRMAR';
    $partesNomenclaturaControl[] = 'Coche ' . ($iNom + 1) . ': ' . $pNom . ' paradas [' . $textoSinAcentos($nomControl) . ']';
}
if ($partesNomenclaturaControl) $partesDescripcionControl[] = 'nomenclatura ' . implode(' / ', $partesNomenclaturaControl);
if ($puertaCabinaDescripcion !== '') $partesDescripcionControl[] = 'puerta de cabina ' . strtolower($textoSinAcentos($puertaCabinaDescripcion));
if ($puertaPisosDescripcion !== '') $partesDescripcionControl[] = 'puertas de piso ' . strtolower($textoSinAcentos($puertaPisosDescripcion));
$partesDescripcionControl[] = $textoSinAcentos($comunicacionDescripcion);

if ((int)$idTipo === 7) {
    if ($tipoGabineteMrl === 'WITTUR') {
        $partesDescripcionControl[] = 'Gabinete aproximado 400x2200x240 mm en pintura epoxy gris. Visor y espacio para palanca de freno manual / Llaves termomagnéticas trifásicas y monofásicas, para iluminación de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con protección y cable blindado, para el hueco /';
    } else {
        $partesDescripcionControl[] = 'Gabinete aproximado 460x2000x200 mm en pintura epoxy gris / Llaves termomagnéticas trifásicas y monofásicas, para iluminación de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con protección y cable blindado, para el hueco /';
    }
}

/* Componentes tecnicos que forman parte real del control. */
if ($termico && $precioTermico > 0) {
    $descripcionTermicoComercial = $limpiarDescripcionComponente($termico['precios_descripcion'] ?? '');
    if ($descripcionTermicoComercial !== '') $partesDescripcionControl[] = strtolower($descripcionTermicoComercial);
}
if ($precioContactorPotencialDefinido && $contactorPotencial) {
    $descripcionContactorPotencial = $limpiarDescripcionComponente($contactorPotencial['precios_descripcion'] ?? '');
    if ($descripcionContactorPotencial !== '') $partesDescripcionControl[] = strtolower($descripcionContactorPotencial);
}
if ($adicionalCentral && !$esHidraulicoDescripcion) {
    $partesDescripcionControl[] = strtolower($limpiarDescripcionComponente($adicionalCentral['precios_descripcion'] ?? ''));
}
if ($precioPosicionamientoEncoderTotal > 0 && $adicionalPosicionamientoEncoder) {
    $partesDescripcionControl[] = strtolower($limpiarDescripcionComponente($adicionalPosicionamientoEncoder['precios_descripcion'] ?? 'posicionamiento por encoder'));
}
if ($precioAdicionalTandem > 0) $partesDescripcionControl[] = 'configuracion tandem';
if ($adicionalUcmMrl) $partesDescripcionControl[] = 'adicional UCM';
if ($cantidadFuentesMrl > 0) $partesDescripcionControl[] = 'fuente para indicadores MRL';

foreach ($otrosAdicionales as $lineaDescripcion) {
    $claveDescripcion = strtoupper(trim((string)($lineaDescripcion['clave'] ?? '')));
    /* Evitar repetir elementos ya expresados o detalles propios de otros modulos. */
    if (in_array($claveDescripcion, array('FUENTE_24V_TERMICA'), true)) continue;
    $descripcionAdicional = $limpiarDescripcionComponente($lineaDescripcion['descripcion'] ?? '');
    if ($descripcionAdicional !== '') $partesDescripcionControl[] = strtolower($descripcionAdicional);
}

/* Eliminar repeticiones conservando el orden. */
$partesUnicasDescripcion = array();
$clavesPartesDescripcion = array();
foreach ($partesDescripcionControl as $parteDescripcion) {
    $parteDescripcion = trim((string)$parteDescripcion);
    if ($parteDescripcion === '') continue;
    $claveParte = strtolower($parteDescripcion);
    if (isset($clavesPartesDescripcion[$claveParte])) continue;
    $clavesPartesDescripcion[$claveParte] = true;
    $partesUnicasDescripcion[] = $parteDescripcion;
}
$descripcionComercialControl = implode(', ', $partesUnicasDescripcion) . '.';
$agregarSnapshot('Base',$equipo['control_codigo']??'',$descripcionComercialControl,$cantidadEquipos,$precioBase,$cantidadEquipos.' equipo(s)',$precioBase*$cantidadEquipos);
if ($rescateSeleccionado && $precioRescateTotal > 0) {
    $formulaRescateComercial = array();
    foreach ($componentesRescate as $compRescate) {
        $formulaRescateComercial[] = trim((string)$compRescate['codigo']) . ' x ' . rtrim(rtrim(number_format((float)$compRescate['cantidad'], 2, '.', ''), '0'), '.');
    }
    $cantidadRescateComercial = max(1, $cantidadEquipos);
    $agregarSnapshot(
        'Rescate',
        $codigoComercialRescate,
        $descripcionComercialRescate,
        $cantidadRescateComercial,
        $precioRescateTotal / $cantidadRescateComercial,
        'Componentes: ' . implode(' + ', $formulaRescateComercial),
        $precioRescateTotal,
        'RESCATE'
    );
}
foreach ($senalizacionLineas as $sl) $agregarSnapshot($sl['concepto'],$sl['codigo'],$sl['descripcion'],$sl['cantidad'],$sl['unitario'],$sl['formula'],$sl['total'],'SENALIZACION');
foreach($lineasModulosLibres as $lm) $agregarSnapshot($lm['concepto'],$lm['codigo'],$lm['descripcion'],$lm['cantidad'],$lm['unitario'],$lm['formula'],$lm['total'],$lm['modulo']);
$agregarSnapshot('Adicional por paradas',$adicionalParada['adicxparada_codigo']??'',$adicionalParada['precios_descripcion']??'',$totalParadas,$precioAdicionalParadaUnitario,$totalParadas.' paradas totales',$precioAdicionalParadas);
$agregarSnapshot('Cabezales y soportes',$cabezalSoporte['cabysop_codigo']??'',$cabezalSoporte['precios_descripcion']??'',$cantidadEquipos,$precioCabezalSoporte,'1 por equipo',$precioCabezalSoporte*$cantidadEquipos);
if ($adicionalUcmMrl) $agregarSnapshot('Adicional UCM MRL', 'ADICUCM - A6XDESREALIMVEL', 'Diferencia por adicional UCM', $cantidadEquipos, $precioAdicionalUcmUnitario, '(ADICUCM - A6XDESREALIMVEL) × ' . $cantidadEquipos . ' equipo(s)', $precioAdicionalUcmTotal);
if ($cantidadFuentesMrl > 0) $agregarSnapshot('Fuente indicadores MRL', 'F24V2AMRL', $articuloFuenteMrl['precios_descripcion'] ?? '', $cantidadTotalFuentesMrl, $precioFuenteMrlUnitario, $cantidadFuentesMrl . ' por equipo × ' . $cantidadEquipos . ' equipo(s)', $precioFuentesMrlTotal);
if($adicionalBateria)$agregarSnapshot('Conexión batería',$adicionalBateria['codigo_adicional'],$adicionalBateria['precios_descripcion'],$cantidadEquipos,$precioAdicionalBateriaUnitario,$formulaAdicionalBateria,$precioAdicionalBateria);
if($adicionalCentral)$agregarSnapshot('Central ' . $centralNombre,$adicionalCentral['precios_codigo'],$adicionalCentral['precios_descripcion'],$cantidadEquipos,$precioAdicionalCentral,'1 por equipo',$precioAdicionalCentral*$cantidadEquipos);
if($termico&&$precioTermico>0)$agregarSnapshot('Térmico',$termico['termicos_codigo'],$termico['precios_descripcion'],$cantidadTermicos*$cantidadEquipos,$precioTermicoUnitario,$cantidadTermicos.' por equipo',$precioTermico*$cantidadEquipos);
if($precioAdicionalTandem>0)$agregarSnapshot('Tándem',$adicionalTandem['adicxtandem_codigo'],$adicionalTandem['precios_descripcion'],$cantidadEquipos,$precioAdicionalTandem,'1 por equipo',$precioAdicionalTandem*$cantidadEquipos);
if($precioPosicionamientoEncoderTotal>0)$agregarSnapshot('Posicionamiento encoder',$adicionalPosicionamientoEncoder['precios_codigo'],$adicionalPosicionamientoEncoder['precios_descripcion'],$cantidadEquipos,$precioPosicionamientoEncoderUnitario,'1 por equipo',$precioPosicionamientoEncoderTotal);
if($precioContactorPotencialDefinido)$agregarSnapshot('Contactor potencial',$contactorPotencial['contactorpot_codigo'],$contactorPotencial['precios_descripcion'],$cantidadEquipos,$precioContactorPotencial,'1 por equipo',$precioContactorPotencial*$cantidadEquipos);
if($adicionalPuertaCabina)$agregarSnapshot('Puerta cabina',$adicionalPuertaCabina['precios_codigo'],$adicionalPuertaCabina['precios_descripcion'],$cantidadOperadores*$cantidadEquipos,$precioPuertaCabinaUnitario,$cantidadOperadores.' por equipo',$precioPuertaCabina*$cantidadEquipos);
if($adicionalPuertasPisos)$agregarSnapshot('Puertas pisos',$adicionalPuertasPisos['precios_codigo'],$adicionalPuertasPisos['precios_descripcion'],$cantidadEquipos,$precioPuertasPisos,'1 por equipo',$precioPuertasPisos*$cantidadEquipos);
if($adicionalPuertasPisosMc)$agregarSnapshot('Puertas pisos MC',$adicionalPuertasPisosMc['precios_codigo'],$adicionalPuertasPisosMc['precios_descripcion'],$cantidadEquipos,$precioPuertasPisosMc,'1 por equipo',$precioPuertasPisosMc*$cantidadEquipos);
if($adicionalPuertaCabinaMc)$agregarSnapshot('Puerta cabina MC',$adicionalPuertaCabinaMc['precios_codigo'],$adicionalPuertaCabinaMc['precios_descripcion'],$cantidadEquipos,$precioPuertaCabinaMc,'1 por equipo',$precioPuertaCabinaMc*$cantidadEquipos);
foreach($otrosAdicionales as $l)$agregarSnapshot('Adicional',$l['codigo'],$l['descripcion'],$l['cantidad'],$l['unitario'],$l['nota'],$l['total']);
foreach($adicionalesManuales as $l)$agregarSnapshot('Adicional manual','',$l['descripcion'],$l['cantidad'],$l['unitario'],'Importe por equipo',$l['total']);

/* Orden comercial fijo: primero todo Control y luego los demás módulos completos. */
$ordenModulos = array('CONTROL'=>10, 'RESCATE'=>15, 'SENALIZACION'=>20, 'IEP'=>30, 'ACCESORIOS'=>40, 'REPUESTOS'=>50);
foreach ($lineasSnapshot as $indiceLinea => &$lineaOrdenable) {
    $lineaOrdenable['_orden_original'] = $indiceLinea;
}
unset($lineaOrdenable);
usort($lineasSnapshot, function(array $a, array $b) use ($ordenModulos): int {
    $ordenA = $ordenModulos[strtoupper((string)($a['modulo'] ?? 'CONTROL'))] ?? 999;
    $ordenB = $ordenModulos[strtoupper((string)($b['modulo'] ?? 'CONTROL'))] ?? 999;
    if ($ordenA === $ordenB) return ($a['_orden_original'] ?? 0) <=> ($b['_orden_original'] ?? 0);
    return $ordenA <=> $ordenB;
});
foreach ($lineasSnapshot as &$lineaOrdenable) unset($lineaOrdenable['_orden_original']);
unset($lineaOrdenable);

if ($accionComercial === 'generar_pedido_directo') {
    /* v410: el Pedido ya no se crea de inmediato. Primero se abre Integración externa
     * para confirmar fecha de entrega y condición de pago. Recién al confirmar esa
     * pantalla se crea el Pedido y se genera su PDF con esos datos. */
    $cab=array('cliente_id'=>$idCliente,'lista_id'=>$listaId,'lista_nombre'=>$listaSeleccionada['lista_nombre'],'lista_fecha'=>$listaSeleccionada['lista_fecha_archivo'],'lista_valor_dolar'=>$listaSeleccionada['lista_valor_dolar'],'referencia'=>$referenciaCotizacion,'subtotal'=>$subtotal,'descuento_1'=>$descuento1,'descuento_2'=>$descuento2,'descuento_3'=>$descuento3,'total'=>$total,'datos_formulario'=>$_POST);
    $token=bin2hex(random_bytes(16));
    if (!isset($_SESSION['pedido_preintegracion']) || !is_array($_SESSION['pedido_preintegracion'])) $_SESSION['pedido_preintegracion']=array();
    $_SESSION['pedido_preintegracion'][$token]=array('creado'=>time(),'cabecera'=>$cab,'lineas'=>$lineasSnapshot);
    header('Location: pedido_preintegracion.php?token='.rawurlencode($token)); exit;
}

if ($accionComercial === 'guardar_revision_pedido') {
    if ($pedidoEdicionId <= 0) volverConError('Pedido inválido para modificar.');
    $motivoModificacion = trim((string)($_POST['motivo_modificacion_pedido'] ?? ''));
    if ($motivoModificacion === '') volverConError('Debe indicar qué se modifica en el pedido.');
    $conexion->begin_transaction();
    try {
        $usuario=$_SESSION['usuario_nombre']??null; $usuarioId=(int)($_SESSION['usuario_id']??0); $datosJson=json_encode($_POST,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $st=$conexion->prepare('SELECT * FROM pedidos WHERE pedido_id=? FOR UPDATE'); $st->bind_param('i',$pedidoEdicionId); $st->execute(); $pedidoAnterior=$st->get_result()->fetch_assoc(); $st->close();
        if(!$pedidoAnterior) throw new Exception('El pedido no existe.');
        $revisionBase=(int)($_POST['pedido_revision_base']??-1);
        $revisionAnterior=(int)($pedidoAnterior['revision']??0);
        if($revisionBase < 0 || $revisionBase !== $revisionAnterior) throw new Exception('Este pedido fue modificado por otro usuario mientras usted lo estaba editando. Vuelva a abrir el pedido para trabajar sobre la revisión vigente.');
        $revisionNueva=$revisionAnterior+1;
        $st=$conexion->prepare('INSERT INTO pedidos_revisiones(pedido_id,revision,cliente_id,lista_id,referencia,estado,total,observaciones,datos_formulario,motivo_modificacion,fecha_revision,usuario,usuario_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $fechaRevision=date('Y-m-d H:i:s');
        $refAnterior=(string)($pedidoAnterior['referencia']??'');
        $clienteAnterior=(int)($pedidoAnterior['cliente_id']??0); $listaAnterior=(int)($pedidoAnterior['lista_id']??0);
        $st->bind_param('iiiissdsssssi',$pedidoEdicionId,$revisionAnterior,$clienteAnterior,$listaAnterior,$refAnterior,$pedidoAnterior['estado'],$pedidoAnterior['total'],$pedidoAnterior['observaciones'],$pedidoAnterior['datos_formulario'],$motivoModificacion,$fechaRevision,$usuario,$usuarioId);
        if(!$st->execute()) throw new Exception($st->error); $revisionId=$st->insert_id; $st->close();
        $st=$conexion->prepare('INSERT INTO pedidos_revisiones_detalle(revision_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) SELECT ?,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM pedidos_detalle WHERE pedido_id=?');
        $st->bind_param('ii',$revisionId,$pedidoEdicionId); if(!$st->execute()) throw new Exception($st->error); $st->close();
        $st=$conexion->prepare('UPDATE pedidos SET cliente_id=?,lista_id=?,total=?,datos_formulario=?,revision=?,fecha_ultima_modificacion=NOW(),usuario_modificacion=?,usuario_modificacion_id=?,pdf_archivo=NULL,pdf_fecha=NULL WHERE pedido_id=?');
        $st->bind_param('iidsisii',$idCliente,$listaId,$total,$datosJson,$revisionNueva,$usuario,$usuarioId,$pedidoEdicionId); if(!$st->execute()) throw new Exception($st->error); $st->close();
        $st=$conexion->prepare('DELETE FROM pedidos_detalle WHERE pedido_id=?'); $st->bind_param('i',$pedidoEdicionId); if(!$st->execute()) throw new Exception($st->error); $st->close();
        $st=$conexion->prepare('INSERT INTO pedidos_detalle(pedido_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach($lineasSnapshot as $i=>$l){$o=$i+1;$m=$l['modulo']??'CONTROL';$st->bind_param('iissssddsd',$pedidoEdicionId,$o,$m,$l['concepto'],$l['codigo'],$l['descripcion'],$l['cantidad'],$l['unitario'],$l['formula'],$l['total']);if(!$st->execute())throw new Exception($st->error);} $st->close();
        $conexion->commit();
        documentoEventoRegistrar($conexion,'PEDIDO',$pedidoEdicionId,(string)($pedidoAnterior['pedido_numero']??''),'REVISION',(string)($pedidoAnterior['estado']??''),(string)($pedidoAnterior['estado']??''),'Revisión '.(int)$revisionNueva.': '.$motivoModificacion);
        try { generarPdfPedido($conexion,$pedidoEdicionId); generarHojasModificacionPedido($conexion,$pedidoEdicionId,$revisionNueva,$motivoModificacion); } catch(Throwable $pdfError){ error_log('PDF revisión pedido: '.$pdfError->getMessage()); }
        header('Location: ver_pedido.php?id=' . (int)$pedidoEdicionId); exit;
    } catch(Throwable $e){ $conexion->rollback(); volverConError('No se pudo modificar el pedido: '.$e->getMessage()); }
}

if ($accionComercial === 'guardar_cotizacion') {
    $conexion->begin_transaction();
    try {
        $usuario=$_SESSION['usuario_nombre']??null; $usuarioId=(int)($_SESSION['usuario_id']??0); $datosJson=json_encode($_POST,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $lf=$listaSeleccionada['lista_fecha_archivo']; $ld=$listaSeleccionada['lista_valor_dolar'];
        if ($cotizacionEdicionId > 0) {
            $verificar=$conexion->prepare("SELECT c.cotizacion_id, (SELECT COUNT(*) FROM pedidos p WHERE p.cotizacion_id=c.cotizacion_id) AS tiene_pedido FROM cotizaciones c WHERE c.cotizacion_id=? FOR UPDATE");
            $verificar->bind_param('i',$cotizacionEdicionId); $verificar->execute(); $existente=$verificar->get_result()->fetch_assoc(); $verificar->close();
            if(!$existente) throw new Exception('La cotización a modificar no existe.');
            if((int)($existente['tiene_pedido']??0)>0) throw new Exception('La cotización ya fue convertida en pedido y no puede modificarse. Modifique el pedido para generar una nueva revisión.');
            /* Antes de reemplazar la cotización se guarda una fotografía completa de la revisión vigente. */
            $cotId=$cotizacionEdicionId;
            $snap=$conexion->prepare('SELECT * FROM cotizaciones WHERE cotizacion_id=? FOR UPDATE');
            $snap->bind_param('i',$cotId); $snap->execute(); $cotAnterior=$snap->get_result()->fetch_assoc(); $snap->close();
            if(!$cotAnterior) throw new Exception('No se pudo leer la revisión anterior de la cotización.');
            $revisionBase=(int)($_POST['cotizacion_revision_base']??-1);
            $revisionActual=(int)($cotAnterior['revision']??0);
            if($revisionBase < 0 || $revisionBase !== $revisionActual) throw new Exception('Esta cotización fue modificada por otro usuario mientras usted la estaba editando. Vuelva a abrir la cotización para trabajar sobre la revisión vigente.');
            $motivoCotizacion=trim((string)($_POST['motivo_modificacion_cotizacion']??''));
            if($motivoCotizacion==='') throw new Exception('Debe indicar qué se modificó en la cotización.');
            $fechaRevision=date('Y-m-d H:i:s');
            $rev=$conexion->prepare('INSERT INTO cotizaciones_revisiones(cotizacion_id,revision,cliente_id,lista_id,lista_nombre,lista_fecha,lista_valor_dolar,referencia,estado,subtotal,descuento_1,descuento_2,descuento_3,total,datos_formulario,motivo_modificacion,fecha_revision,usuario,usuario_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $rev->bind_param('iiiissdssdddddssssi', $cotId,$cotAnterior['revision'],$cotAnterior['cliente_id'],$cotAnterior['lista_id'],$cotAnterior['lista_nombre'],$cotAnterior['lista_fecha'],$cotAnterior['lista_valor_dolar'],$cotAnterior['referencia'],$cotAnterior['estado'],$cotAnterior['subtotal'],$cotAnterior['descuento_1'],$cotAnterior['descuento_2'],$cotAnterior['descuento_3'],$cotAnterior['total'],$cotAnterior['datos_formulario'],$motivoCotizacion,$fechaRevision,$cotAnterior['usuario'],$cotAnterior['usuario_id']);
            if(!$rev->execute()) throw new Exception($rev->error); $revisionCotId=$rev->insert_id; $rev->close();
            $revDet=$conexion->prepare('INSERT INTO cotizaciones_revisiones_detalle(revision_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) SELECT ?,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM cotizaciones_detalle WHERE cotizacion_id=?');
            $revDet->bind_param('ii',$revisionCotId,$cotId); if(!$revDet->execute()) throw new Exception($revDet->error); $revDet->close();
            $stmt=$conexion->prepare("UPDATE cotizaciones SET cliente_id=?,lista_id=?,lista_nombre=?,lista_fecha=?,lista_valor_dolar=?,referencia=?,subtotal=?,descuento_1=?,descuento_2=?,descuento_3=?,total=?,datos_formulario=?,revision=revision+1,estado='EMITIDA',usuario=?,usuario_id=?,pdf_archivo=NULL,pdf_fecha=NULL WHERE cotizacion_id=?");
            $stmt->bind_param('iissdsdddddssii',$idCliente,$listaId,$listaSeleccionada['lista_nombre'],$lf,$ld,$referenciaCotizacion,$subtotal,$descuento1,$descuento2,$descuento3,$total,$datosJson,$usuario,$usuarioId,$cotId);
            if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
            $stmt=$conexion->prepare('DELETE FROM cotizaciones_detalle WHERE cotizacion_id=?'); $stmt->bind_param('i',$cotId); if(!$stmt->execute()) throw new Exception($stmt->error); $stmt->close();
        } else {
            $stmt=$conexion->prepare("INSERT INTO cotizaciones(cotizacion_tipo,cliente_id,lista_id,lista_nombre,lista_fecha,lista_valor_dolar,referencia,subtotal,descuento_1,descuento_2,descuento_3,total,datos_formulario,usuario,usuario_id) VALUES('CONTROL',?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
            $stmt->bind_param('iissdsdddddssi',$idCliente,$listaId,$listaSeleccionada['lista_nombre'],$lf,$ld,$referenciaCotizacion,$subtotal,$descuento1,$descuento2,$descuento3,$total,$datosJson,$usuario,$usuarioId);
            if(!$stmt->execute()) throw new Exception($stmt->error); $cotId=$stmt->insert_id; $stmt->close();
            $numero=siguienteNumeroDocumento($conexion, 'COTIZACION_CONTROL'); $stmt=$conexion->prepare('UPDATE cotizaciones SET cotizacion_numero=? WHERE cotizacion_id=?'); $stmt->bind_param('si',$numero,$cotId); $stmt->execute(); $stmt->close();
        }
        $stmt=$conexion->prepare('INSERT INTO cotizaciones_detalle(cotizacion_id,orden_visual,modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total) VALUES(?,?,?,?,?,?,?,?,?,?)');
        foreach($lineasSnapshot as $i=>$l){$o=$i+1;$m=$l['modulo']??'CONTROL';$stmt->bind_param('iissssddsd',$cotId,$o,$m,$l['concepto'],$l['codigo'],$l['descripcion'],$l['cantidad'],$l['unitario'],$l['formula'],$l['total']);if(!$stmt->execute())throw new Exception($stmt->error);} $stmt->close();
        $conexion->commit();
        $numeroEvento='';
        $stEvento=$conexion->prepare('SELECT cotizacion_numero FROM cotizaciones WHERE cotizacion_id=? LIMIT 1');
        if($stEvento){$stEvento->bind_param('i',$cotId);$stEvento->execute();$filaEvento=$stEvento->get_result()->fetch_assoc();$stEvento->close();$numeroEvento=(string)($filaEvento['cotizacion_numero']??'');}
        if($cotizacionEdicionId>0){
            documentoEventoRegistrar($conexion,'COTIZACION',$cotId,$numeroEvento,'REVISION',(string)($cotAnterior['estado']??''),'EMITIDA','Revisión '.(int)($revisionActual+1).': '.$motivoCotizacion);
        }else{
            documentoEventoRegistrar($conexion,'COTIZACION',$cotId,$numeroEvento,'CREACION','','EMITIDA','Cotización de control emitida.');
        }
        try {
            generarPdfCotizacion($conexion, (int)$cotId);
            if ($cotizacionEdicionId > 0) generarHojasModificacionCotizacion($conexion, (int)$cotId, $revisionActual + 1, $motivoCotizacion);
        } catch (Throwable $pdfError) { error_log('PDF cotización: ' . $pdfError->getMessage()); }
        header('Location: ver_cotizacion.php?id='.$cotId.'&emitida=1'); exit;
    } catch(Throwable $e){$conexion->rollback();volverConError('No se pudo guardar la cotización: '.$e->getMessage());}
}


/* Guardar la cotización correcta para poder modificarla después. */
$_SESSION['form_data'] = $_POST;
unset($_SESSION['form_error']);

if ($modoPanel) {
    $totalesOtros = array();
    foreach ($otrosAdicionales as $lineaPanel) {
        $totalesOtros[$lineaPanel['clave']] = ($totalesOtros[$lineaPanel['clave']] ?? 0) + (float)$lineaPanel['total'];
    }
    $sumaOtros = function (array $claves) use ($totalesOtros) {
        $suma = 0.0;
        foreach ($claves as $clave) $suma += (float)($totalesOtros[$clave] ?? 0);
        return $suma;
    };
    $importePuertas = ($precioPuertaCabina + $precioPuertaCabinaMc) * $cantidadEquipos
        + $sumaOtros(array('ALIMENTACION_PUERTA_VF'));
    $importePatin = ($precioPuertasPisos + $precioPuertasPisosMc) * $cantidadEquipos;
    $importeTandem = $precioAdicionalTandem * $cantidadEquipos;
    $importeMicronivelacion = $sumaOtros(array('MICRONIVELACION'));
    $importeInterfase = $sumaOtros(array('INTERFASE', 'INTERFASE_A7121'));
    $importeFuenteSwitchingTouch = $sumaOtros(array('FUENTE_SWITCHING_TOUCH'));

    /* Detalle técnico y comercial para explicar exactamente cómo se forma la cotización. */
    $variadorDescripcion = '';
    if ((int)$idTipo === 4) {
        $variadorDescripcion = preg_replace('/L$/i', '', $nombreSubtipo) . ' de ' . rtrim(rtrim(number_format((float)$equipo['control_corriente'], 2, '.', ''), '0'), '.') . ' A';
    }

    $detallesCalculoPanel = array();
    $agregarDetallePanel = function ($concepto, $codigo, $descripcion, $cantidad, $unitario, $formula, $total) use (&$detallesCalculoPanel) {
        if ((float)$total == 0.0 && trim((string)$codigo) === '') return;
        $detallesCalculoPanel[] = array(
            'concepto' => $concepto,
            'codigo' => trim((string)$codigo),
            'descripcion' => trim((string)$descripcion),
            'cantidad' => (float)$cantidad,
            'unitario' => (float)$unitario,
            'formula' => trim((string)$formula),
            'total' => (float)$total
        );
    };

    $agregarDetallePanel('Base', $equipo['control_codigo'] ?? '', $descripcionComercialControl, $cantidadEquipos, $precioBase, ($usaMatrizCpuBase ? 'Matriz base A6300V4 · ' : '') . $cantidadEquipos . ' equipo(s)', $precioBase * $cantidadEquipos);
    $agregarDetallePanel('Adicional por paradas', $adicionalParada['adicxparada_codigo'] ?? '', $adicionalParada['precios_descripcion'] ?? '', $totalParadas, $precioAdicionalParadaUnitario, $totalParadas . ' paradas totales', $precioAdicionalParadas);
    $agregarDetallePanel('Cabezales y soportes', $cabezalSoporte['cabysop_codigo'] ?? '', $cabezalSoporte['precios_descripcion'] ?? '', $cantidadEquipos, $precioCabezalSoporte, '1 por equipo × ' . $cantidadEquipos, $precioCabezalSoporte * $cantidadEquipos);
    /* Mostrar el rescate como una sola solución comercial, aunque internamente sume varios códigos. */
    if ($rescateSeleccionado && $componentesRescate) {
        $codigosRescatePanel = array();
        $partesRescatePanel = array();
        foreach ($componentesRescate as $compRescate) {
            $codigosRescatePanel[] = $compRescate['codigo'];
            $partesRescatePanel[] = $compRescate['codigo']
                . ' ($' . formatoPrecioEntero($compRescate['unitario'])
                . ' × ' . number_format($compRescate['cantidad'], 0, ',', '.') . ')';
        }
        $tituloFamiliaRescatePanel = $familiaRescate === 'HIDRAULICO'
            ? 'Rescate hidráulico'
            : 'Rescate MRL o imán permanente';
        $agregarDetallePanel(
            $tituloFamiliaRescatePanel . ': ' . ($rescateSeleccionado['rescate_nombre'] ?? ''),
            implode(' + ', $codigosRescatePanel),
            'Solución compuesta por ' . count($componentesRescate) . ' artículo(s) de la base Bejerman vigente.',
            1,
            $precioRescateTotal,
            implode(' + ', $partesRescatePanel),
            $precioRescateTotal
        );
    }
    if ($adicionalUcmMrl) {
        $agregarDetallePanel('Adicional UCM para MRL', 'ADICUCM - A6XDESREALIMVEL', 'Diferencia entre Adicional UCM y Desarme/Rearme del limitador de velocidad', $cantidadEquipos, $precioAdicionalUcmUnitario, '$' . formatoPrecioEntero((float)$articuloAdicUcm['precios_costo']) . ' - $' . formatoPrecioEntero((float)$articuloDesarmeLimitador['precios_costo']) . '; por equipo × ' . $cantidadEquipos, $precioAdicionalUcmTotal);
    }
    if ($cantidadFuentesMrl > 0) {
        $agregarDetallePanel('Fuente para indicadores en pisos MRL', 'F24V2AMRL', $articuloFuenteMrl['precios_descripcion'] ?? '', $cantidadTotalFuentesMrl, $precioFuenteMrlUnitario, $cantidadFuentesMrl . ' fuente(s) por equipo × ' . $cantidadEquipos . ' equipo(s)', $precioFuentesMrlTotal);
    }
    if ($adicionalBateria) $agregarDetallePanel('Conexión de batería', $adicionalBateria['codigo_adicional'] ?? '', $adicionalBateria['precios_descripcion'] ?? '', $cantidadEquipos, $precioAdicionalBateriaUnitario, $formulaAdicionalBateria, $precioAdicionalBateria);
    if ($adicionalCentral) $agregarDetallePanel('Central ' . $centralNombre, $adicionalCentral['precios_codigo'] ?? '', $adicionalCentral['precios_descripcion'] ?? '', $cantidadEquipos, $precioAdicionalCentral, '1 por equipo × ' . $cantidadEquipos, $precioAdicionalCentral * $cantidadEquipos);
    if ($termico && $precioTermico > 0) $agregarDetallePanel('Térmico', $termico['termicos_codigo'] ?? '', $termico['precios_descripcion'] ?? '', $cantidadTermicos * $cantidadEquipos, $precioTermicoUnitario, $cantidadTermicos . ' por equipo × ' . $cantidadEquipos, $precioTermico * $cantidadEquipos);
    if ($precioAdicionalTandem > 0) $agregarDetallePanel('Tándem', $adicionalTandem['adicxtandem_codigo'] ?? '', $adicionalTandem['precios_descripcion'] ?? '', $cantidadEquipos, $precioAdicionalTandem, '1 adicional Tándem por equipo', $precioAdicionalTandem * $cantidadEquipos);
    if ($precioPosicionamientoEncoderTotal > 0) $agregarDetallePanel('Posicionamiento por encoder', $adicionalPosicionamientoEncoder['precios_codigo'] ?? '', $adicionalPosicionamientoEncoder['precios_descripcion'] ?? '', $cantidadEquipos, $precioPosicionamientoEncoderUnitario, '1 por equipo × ' . $cantidadEquipos, $precioPosicionamientoEncoderTotal);
    if ($precioContactorPotencialDefinido) $agregarDetallePanel('Contactor de potencial', $contactorPotencial['contactorpot_codigo'] ?? '', $contactorPotencial['precios_descripcion'] ?? '', $cantidadEquipos, $precioContactorPotencial, '1 por equipo × ' . $cantidadEquipos, $precioContactorPotencial * $cantidadEquipos);
    if ($adicionalPuertaCabina) $agregarDetallePanel('Puerta de cabina', $adicionalPuertaCabina['precios_codigo'] ?? '', $adicionalPuertaCabina['precios_descripcion'] ?? '', $cantidadOperadores * $cantidadEquipos, $precioPuertaCabinaUnitario, $cantidadOperadores . ' operador(es) por equipo × ' . $cantidadEquipos, $precioPuertaCabina * $cantidadEquipos);
    if ($adicionalPuertasPisos) $agregarDetallePanel('Puertas de pisos / patín', $adicionalPuertasPisos['precios_codigo'] ?? '', $adicionalPuertasPisos['precios_descripcion'] ?? '', $cantidadEquipos, $precioPuertasPisos, '1 por equipo × ' . $cantidadEquipos, $precioPuertasPisos * $cantidadEquipos);
    if ($adicionalPuertasPisosMc) $agregarDetallePanel('Puertas de pisos MC', $adicionalPuertasPisosMc['precios_codigo'] ?? '', $adicionalPuertasPisosMc['precios_descripcion'] ?? '', $cantidadEquipos, $precioPuertasPisosMc, '1 por equipo × ' . $cantidadEquipos, $precioPuertasPisosMc * $cantidadEquipos);
    if ($adicionalPuertaCabinaMc) $agregarDetallePanel('Puerta de cabina MC', $adicionalPuertaCabinaMc['precios_codigo'] ?? '', $adicionalPuertaCabinaMc['precios_descripcion'] ?? '', $cantidadEquipos, $precioPuertaCabinaMc, '1 por equipo × ' . $cantidadEquipos, $precioPuertaCabinaMc * $cantidadEquipos);
    foreach ($otrosAdicionales as $lineaDetalle) {
        if (in_array($lineaDetalle['clave'], array('INTERFASE', 'INTERFASE_A7121'), true)) {
            $conceptoLineaDetalle = 'Interfase';
        } elseif ($lineaDetalle['clave'] === 'FUENTE_SWITCHING_TOUCH') {
            $conceptoLineaDetalle = 'Fuente switching para botoneras touch';
        } else {
            $conceptoLineaDetalle = 'Adicional';
        }
        $agregarDetallePanel($conceptoLineaDetalle, $lineaDetalle['codigo'], $lineaDetalle['descripcion'], $lineaDetalle['cantidad'], $lineaDetalle['unitario'], $lineaDetalle['nota'], $lineaDetalle['total']);
    }
    foreach ($adicionalesManuales as $manualDetalle) {
        $agregarDetallePanel('Adicional manual', '', $manualDetalle['descripcion'], $manualDetalle['cantidad'], $manualDetalle['unitario'], '$' . formatoPrecioEntero($manualDetalle['unitario']) . ' × ' . $cantidadEquipos . ' equipos', $manualDetalle['total']);
    }

    $itemsPanel = array(
        array('Base', $precioBase * $cantidadEquipos, 'ok'),
        array('Adicional por paradas', $precioAdicionalParadas, 'ok'),
        array('Adicional cabezales y soportes', $precioCabezalSoporte * $cantidadEquipos, 'ok'),
        array('Conexión', $precioAdicionalBateria, 'ok'),
        array('Adicional central ' . ($centralNombre !== '' ? $centralNombre : 'hidráulica'), $precioAdicionalCentral * $cantidadEquipos, 'ok'),
        array('Térmico', $precioTermico * $cantidadEquipos, 'ok'),
        array('Maniobra sabática', $sumaOtros(array('MANIOBRA_SABATICA')), 'ok'),
        array('Falta de fase', $sumaOtros(array('PROTECTOR_FALTA_FASE')), $protectorFaltaFaseIncluido ? 'incluido' : 'ok'),
        array('Conexión sistema de emergencia', $sumaOtros(array('EMERGENCIA_CORTE')), 'ok'),
        array('Descanso para freno', $sumaOtros(array('DESCANSO_FRENO')), 'ok'),
        array('Rescate con batería de gel', $sumaOtros(array('RETORNO_BATERIA_GEL')), 'ok'),
        array('Rescates hidráulicos', $familiaRescate === 'HIDRAULICO' ? $precioRescateTotal : 0.0, 'ok'),
        array('Rescates MRL o imán permanente', $familiaRescate === 'MRL_IMAN' ? $precioRescateTotal : 0.0, 'ok'),
        array('UCM + fuente en pisos para MRL', $precioUcmFuenteMrlTotal, 'ok'),
        array('Puerta automática + alimentación VF', $importePuertas, 'ok'),
        array('Forzador de aire / luz de cortesía', $sumaOtros(array('FORZADOR_AIRE','LUZ_CORTESIA')), 'ok'),
        array('Patín Otis', $importePatin, 'ok'),
        array('Fuentes 24 V para indicadores', $sumaOtros(array('FUENTE_24V','FUENTE_24V_TERMICA')), 'ok'),
        array('Concentrador de llamadas', $sumaOtros(array('CONCENTRADOR_LLAMADAS')), 'ok'),
        array('Interfase', $importeInterfase, 'ok'),
        array('Fuente switching botoneras touch', $importeFuenteSwitchingTouch, 'ok'),
        array('IEP en control / llave Ramos Mejía', $sumaOtros(array('LLAVE_RAMOS')), 'ok'),
        array('Posicionamiento por encoder', $precioPosicionamientoEncoderTotal, 'ok'),
        array('Contactor de potencial', $precioContactorPotencial * $cantidadEquipos, $idContactorPotencial > 0 && !$precioContactorPotencialDefinido ? 'pendiente' : 'ok'),
        array('Tándem', $importeTandem, 'ok'),
        array('Micronivelación', $importeMicronivelacion, 'ok')
    );
    $manualesPorNumero = array();
    foreach ($adicionalesManuales as $manualPanel) {
        $manualesPorNumero[(int)$manualPanel['numero']] = $manualPanel;
    }
    for ($iManualPanel = 1; $iManualPanel <= 3; $iManualPanel++) {
        $manualPanel = $manualesPorNumero[$iManualPanel] ?? null;
        $itemsPanel[] = array(
            $manualPanel ? $manualPanel['descripcion'] : 'Adicional manual ' . $iManualPanel,
            $manualPanel ? (float)$manualPanel['total'] : 0.0,
            'ok',
            $manualPanel ? ('$' . formatoPrecioEntero((float)$manualPanel['unitario']) . ' × ' . (int)$cantidadEquipos . ' equipos') : ''
        );
    }
    ?><!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0"><style>
    *{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;padding:8px;background:#fff;color:#202124;font-size:11px}
    table{width:100%;border-collapse:collapse}td{border-bottom:1px solid #e5e7eb;padding:5px 4px;vertical-align:top}.n{width:22px;color:#6b7280;text-align:right}.concepto{line-height:1.2}.importe{text-align:right;white-space:nowrap;font-weight:bold}.pendiente{color:#9a6700;font-size:10px}.incluido{color:#0f6b3d;font-size:10px}.resumen{margin-top:10px;border-top:2px solid #343a40;padding-top:7px}.fila-resumen{display:flex;justify-content:space-between;padding:4px 2px}.total{font-size:14px;color:#198754;font-weight:bold;border-top:1px solid #ccc;margin-top:3px;padding-top:7px}.nota{font-size:10px;color:#6b7280;margin:7px 2px 0}.tecnico{border:1px solid #b8c7dc;background:#eef5ff;border-radius:6px;padding:8px;margin-bottom:9px}.tecnico strong{display:block;font-size:12px;margin-bottom:3px}.separador{font-weight:bold;font-size:12px;margin:12px 0 5px;border-bottom:2px solid #343a40;padding-bottom:4px}.detalle{border:1px solid #e1e5ea;border-radius:5px;padding:6px;margin-bottom:5px;background:#fafbfc}.detalle-titulo{font-weight:bold}.detalle-meta{font-size:10px;color:#4b5563;margin-top:2px;line-height:1.35}.detalle-total{font-weight:bold;color:#0f5132;margin-top:2px}
    </style></head><body data-corriente-variador="<?= escapar((string)($equipo['control_corriente'] ?? '')) ?>">
    <div class="tecnico"><strong>Configuración cotizada</strong>
    <div><strong style="display:inline">Base de precios:</strong> Bejerman vigente — <?= escapar($listaSeleccionada['lista_vigente_desde']) ?></div>
    <div>Equipo: <?= escapar($equipo['control_codigo'] ?? '') ?> — <?= escapar($equipo['precios_descripcion'] ?? '') ?></div>
    <?php if ($variadorDescripcion !== ''): ?><div><strong style="display:inline">Variador:</strong> <?= escapar($variadorDescripcion) ?></div><?php endif; ?>
    <div>Equipos: <?= (int)$cantidadEquipos ?> | Paradas: <?= escapar(implode(' / ', $paradasPorEquipo)) ?><?php if (!$esBateriaIndividual): ?> | <?= escapar($tipoBateriaNombre) ?><?php endif; ?></div>
    </div>
    <div class="separador">Resumen de los 26 conceptos</div><table><tbody>
    <?php foreach ($itemsPanel as $indicePanel => $itemPanel): ?>
    <tr><td class="n"><?= $indicePanel + 1 ?>.</td><td class="concepto"><?= escapar($itemPanel[0]) ?><?php if ($itemPanel[2] === 'pendiente'): ?><div class="pendiente">SIN DEFINIR AÚN</div><?php elseif ($itemPanel[2] === 'incluido'): ?><div class="incluido">Incluido en Arranque Suave</div><?php elseif (!empty($itemPanel[3])): ?><div class="incluido"><?= escapar($itemPanel[3]) ?></div><?php endif; ?></td><td class="importe"><?php if ($itemPanel[1] === null): ?>—<?php else: ?>$<?= formatoPrecioEntero((float)$itemPanel[1]) ?><?php endif; ?></td></tr>
    <?php endforeach; ?>
    </tbody></table>
    <div class="separador">Cómo se realizó el cálculo</div>
    <?php foreach ($detallesCalculoPanel as $detallePanel): ?>
        <div class="detalle">
            <div class="detalle-titulo"><?= escapar($detallePanel['concepto']) ?><?php if ($detallePanel['codigo'] !== ''): ?> — <?= escapar($detallePanel['codigo']) ?><?php endif; ?></div>
            <?php if ($detallePanel['descripcion'] !== ''): ?><div class="detalle-meta"><?= escapar($detallePanel['descripcion']) ?></div><?php endif; ?>
            <div class="detalle-meta">Cantidad: <?= number_format($detallePanel['cantidad'], 0, ',', '.') ?> | Unitario: $<?= formatoPrecioEntero($detallePanel['unitario']) ?></div>
            <?php if ($detallePanel['formula'] !== ''): ?><div class="detalle-meta">Cálculo: <?= escapar($detallePanel['formula']) ?></div><?php endif; ?>
            <div class="detalle-total">Total: $<?= formatoPrecioEntero($detallePanel['total']) ?></div>
        </div>
    <?php endforeach; ?>
    <div class="resumen">
    <div class="fila-resumen"><span>Subtotal sin descuentos</span><strong>$<?= formatoPrecioEntero($subtotal) ?></strong></div>
    <div class="fila-resumen"><span>Descuento 1 (<?= number_format($descuento1,0,',','.') ?>%)</span><span>-$<?= formatoPrecioEntero($importeDescuento1) ?></span></div>
    <div class="fila-resumen"><span>Descuento 2 (<?= number_format($descuento2,0,',','.') ?>%)</span><span>-$<?= formatoPrecioEntero($importeDescuento2) ?></span></div>
    <div class="fila-resumen"><span>Descuento 3 (<?= number_format($descuento3,0,',','.') ?>%)</span><span>-$<?= formatoPrecioEntero($importeDescuento3) ?></span></div>
    <div class="fila-resumen total"><span>Total final</span><span>$<?= formatoPrecioEntero($total) ?></span></div>
    </div><div class="nota">Los descuentos se aplican después de sumar los 27 conceptos. Los ítems pendientes no modifican el subtotal.</div></body></html><?php
    exit;
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resultado de la cotización</title>
<style>
body{font-family:Arial,sans-serif;background:#f4f4f9;margin:40px}
.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:25px;max-width:700px}
.total{font-size:1.4em;color:#28a745}
.advertencia{color:#9a6700}
.acciones{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}
.boton{display:inline-block;padding:11px 18px;border-radius:5px;text-decoration:none;font-weight:bold}
.boton-nuevo{background:#28a745;color:#fff}
.boton-modificar{background:#0d6efd;color:#fff}
.boton:hover{opacity:.9}
</style>
</head>
<body data-corriente-variador="<?= escapar((string)($equipo['control_corriente'] ?? '')) ?>">
<div class="caja">
<h1>Resultado del cálculo</h1>

<p><strong>Cliente:</strong> <?= escapar($cliente['clientes_nomfantasia'] ?: $cliente['clientes_razonsocial']) ?></p>
<?php if ($referenciaCotizacion !== ''): ?>
<p><strong>Referencia:</strong> <?= escapar($referenciaCotizacion) ?></p>
<?php endif; ?>

<p><strong>Descripción base:</strong> <?= escapar($descripcionComercialControl) ?></p>
<?php if ($usaMatrizCpuBase): ?><p class="advertencia"><strong>Matriz de cálculo:</strong> la CPU <?= escapar($nombreCpuCompat) ?> utiliza la matriz base A6300V4; conserva sus límites propios de paradas y maniobras.</p><?php endif; ?>
<p><strong>Código:</strong> <?= escapar($equipo['control_codigo']) ?></p>
<p><strong>Rango aplicado:</strong> <?= escapar((string)$equipo['control_potenciadesde']) ?> a <?= escapar((string)$equipo['control_potenciahasta']) ?> HP</p>
<p><strong>Potencia seleccionada:</strong> <?= number_format((float)$potencia, 2, ',', '.') ?> HP</p>
<p><strong>Cantidad de equipos cotizados:</strong> <?= (int)$cantidadEquipos ?></p>
<p><strong>Agrupación:</strong> <?= escapar($tipoBateriaNombre) ?></p>
<?php if (!$esBateriaIndividual): ?>
<p><strong>Cantidad total de coches de la batería:</strong> <?= (int)$cantidadTotalCochesBateria ?></p>
<?php if ($esBateriaActual && $numeroObraOtro !== ''): ?>
<p><strong>N.º de obra del otro equipo:</strong> <?= escapar($numeroObraOtro) ?></p>
<?php endif; ?>
<?php if ($observacionBateria !== ''): ?>
<p><strong>Información de la batería:</strong> <?= escapar($observacionBateria) ?></p>
<?php endif; ?>
<?php endif; ?>
<?php foreach ($paradasPorEquipo as $indiceCoche => $paradasCoche): ?>
<p><strong>Coche <?= $indiceCoche + 1 ?>:</strong> <?= (int)$paradasCoche ?> paradas de un máximo de <?= $paradasMaximas ?> — <strong>Nomenclatura:</strong> <?= !empty($nomenclaturasPorEquipo[$indiceCoche]) ? escapar($nomenclaturasPorEquipo[$indiceCoche]) : 'A CONFIRMAR' ?></p>
<?php endforeach; ?>

<?php if ($contactorNombre !== ''): ?>
<p><strong>Contactor:</strong> <?= escapar($contactorNombre) ?> A</p>
<?php elseif ($idContactor > 0): ?>
<p class="advertencia"><strong>Contactor:</strong> ID <?= $idContactor ?> sin descripción en la tabla contactores.</p>
<?php endif; ?>

<?php if ($agregarContactorPotencial && $idContactorPotencial <= 0): ?>
    <p class="advertencia"><strong>Contactor de potencial:</strong> esta configuración no tiene un contactor de potencial definido en la matriz.</p>
<?php elseif ($idContactorPotencial > 0): ?>
    <?php if ($contactorPotencial): ?>
        <p><strong>Contactor de potencial:</strong> <?= escapar($contactorPotencial['contactorpot_name']) ?> A</p>
        <?php if (!empty($contactorPotencial['contactorpot_codigo'])): ?>
            <p><strong>Código contactor de potencial:</strong> <?= escapar($contactorPotencial['contactorpot_codigo']) ?></p>
        <?php endif; ?>
    <?php else: ?>
        <p class="advertencia"><strong>Contactor de potencial:</strong> ID <?= $idContactorPotencial ?> sin descripción en la tabla contactorpot.</p>
    <?php endif; ?>
<?php endif; ?>

<?php if ($idTipo === 4): ?>
<p><strong>Corriente del variador:</strong> <?= escapar((string)$equipo['control_corriente']) ?> A</p>
<?php endif; ?>

<?php if ($idTipo === 3): ?>
<p><strong>Central hidráulica:</strong> <?= escapar($centralNombre) ?></p>
<?php endif; ?>

<?php if (in_array($idTipo, array(4, 5, 6, 7), true)): ?>
<p><strong>Velocidad:</strong> <?= escapar($velocidadVF) ?> m/min</p>
<?php endif; ?>
<p><strong>Material de hueco:</strong> <?= $idMaterialHueco === 1 ? 'IMANES Y CABEZALES MAGNÉTICOS' : 'PLACAS Y CABEZALES INFRARROJOS' ?></p>

<p><strong>Maniobra:</strong> <?= escapar($nombreManiobra) ?></p>
<p><strong>Configuración especial:</strong> <?= $configuracionEspecialControl==='DOBLE_ACCESO_SELECTIVO'?'Doble acceso selectivo':($configuracionEspecialControl==='TIP'?'Maniobra TIP':'Normal') ?><?= $configuracionEspecialControl==='TIP' ? ' — ' . ($programaTip==='ESPECIAL_ESTANDAR'?'Especial + Estándar':'Especial + Especial') : '' ?></p>
<p><strong>Límite técnico:</strong> <?= (int)$paradasMaximas ?> paradas<?= $notaLimiteParadas!=='' ? ' — '.escapar($notaLimiteParadas) : '' ?></p>

<p><strong>Encoder de motor:</strong> <?= $encoder === 'SI' ? 'Sí' : 'No' ?></p>
<?php if ($posicionamientoEncoder === 'SI'): ?>
<p><strong>Posicionamiento por encoder:</strong> Sí — <?= escapar($adicionalPosicionamientoEncoder['precios_codigo']) ?>, una vez por equipo.</p>
<?php else: ?>
<p><strong>Posicionamiento por encoder:</strong> No.</p>
<?php endif; ?>

<?php if (!$esManiobraMc): ?>
<p><strong>Puerta de cabina:</strong> <?= escapar($puertaCabina['ptacabina_name']) ?></p>
<?php if ($cantidadOperadores > 0): ?>
<p><strong>Operadores de cabina por coche:</strong> <?= (int)$cantidadOperadores ?> (total: <?= (int)($cantidadOperadores * $cantidadEquipos) ?>)</p>
<?php endif; ?>
<p><strong>Puertas de pisos:</strong> <?= escapar($puertasPisos['ptapisos_name']) ?></p>
<?php else: ?>
<p><strong>Puertas pisos MC:</strong> <?= escapar($puertasPisosMc['ptapisosmc_name']) ?></p>
<?php if ($cantidadPisosMc > 0): ?>
<p><strong>Puertas pisos MC por coche:</strong> <?= (int)$cantidadPisosMc ?> (total: <?= (int)($cantidadPisosMc * $cantidadEquipos) ?>)</p>
<?php endif; ?>
<p><strong>Puertas en cabina MC:</strong> <?= escapar($puertaCabinaMc['ptacabinamc_name']) ?></p>
<?php if ($cantidadCabinaMc > 0): ?>
<p><strong>Puertas en cabina MC por coche:</strong> <?= (int)$cantidadCabinaMc ?> (total: <?= (int)($cantidadCabinaMc * $cantidadEquipos) ?>)</p>
<?php endif; ?>
<?php endif; ?>

<?php if ($idTipo === 4): ?>
<p><strong>Térmico:</strong> No aplica para equipos VF.</p>
<?php elseif ($termico): ?>
<p><strong>Térmico:</strong> <?= escapar($termico['termicos_codigo']) ?> — <?= escapar($termico['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Térmicos por coche:</strong> <?= $cantidadTermicos ?> (total: <?= (int)($cantidadTermicos * $cantidadEquipos) ?>)</p>
<p><strong>Precio unitario del térmico:</strong> $<?= formatoPrecioEntero($precioTermicoUnitario) ?></p>
<?php else: ?>
<p class="advertencia"><strong>Térmico:</strong> no se encontró para esta configuración.</p>
<?php endif; ?>

<hr>
<h2>Detalle comercial en el orden definido</h2>

<h3>1. Base</h3>
<p><strong>Precio base por coche:</strong> $<?= formatoPrecioEntero($precioBase) ?> × <?= (int)$cantidadEquipos ?></p>
<p><strong>Total base:</strong> $<?= formatoPrecioEntero($precioBase * $cantidadEquipos) ?></p>

<h3>2. Adicional por paradas</h3>
<p><strong>Código:</strong> <?= escapar($adicionalParada['adicxparada_codigo']) ?> — <?= escapar($adicionalParada['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Precio unitario:</strong> $<?= formatoPrecioEntero($precioAdicionalParadaUnitario) ?></p>
<p><strong>Total de paradas del conjunto:</strong> <?= (int)$totalParadas ?></p>
<p><strong>Total adicional por paradas:</strong> $<?= formatoPrecioEntero($precioAdicionalParadas) ?></p>

<h3>3. Adicional cabezales y soportes</h3>
<p><strong>Código:</strong> <?= escapar($cabezalSoporte['cabysop_codigo']) ?> — <?= escapar($cabezalSoporte['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Precio por coche:</strong> $<?= formatoPrecioEntero($precioCabezalSoporte) ?> × <?= (int)$cantidadEquipos ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioCabezalSoporte * $cantidadEquipos) ?></p>

<h3>4. Conexión</h3>
<?php if ($adicionalBateria): ?>
<p><strong><?= escapar($tipoBateriaNombre) ?>:</strong> <?= escapar($adicionalBateria['codigo_adicional']) ?> — <?= escapar($adicionalBateria['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Precio del código de conexión:</strong> $<?= formatoPrecioEntero($precioConexionBateriaBase) ?></p>
<?php if ($importePorcentajeBateria > 0): ?>
<p><strong>Adicional sobre la base:</strong> <?= number_format($porcentajeBaseBateria * 100, 1, ',', '.') ?>% = $<?= formatoPrecioEntero($importePorcentajeBateria) ?></p>
<?php endif; ?>
<p><strong>Total conexión de batería:</strong> $<?= formatoPrecioEntero($precioAdicionalBateria) ?> (por equipo)</p>
<?php else: ?>
<p><strong>Individual:</strong> no lleva adicional de conexión de batería.</p>
<?php endif; ?>

<h3>5. Adicional central <?= escapar($centralNombre !== '' ? $centralNombre : 'hidráulica') ?></h3>
<?php if ($adicionalCentral): ?>
<p><strong>Código:</strong> <?= escapar($adicionalCentral['precios_codigo']) ?> — <?= escapar($adicionalCentral['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Precio por coche:</strong> $<?= formatoPrecioEntero($precioAdicionalCentral) ?> × <?= (int)$cantidadEquipos ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioAdicionalCentral * $cantidadEquipos) ?></p>
<?php else: ?>
<p>No corresponde para la central seleccionada.</p>
<?php endif; ?>

<h3>6. Térmico</h3>
<?php if ($idTipo === 4): ?>
<p>No aplica para equipos VF.</p>
<?php elseif ($termico): ?>
<p><strong>Código:</strong> <?= escapar($termico['termicos_codigo']) ?> — <?= escapar($termico['precios_descripcion'] ?? 'Sin descripción') ?></p>
<p><strong>Cantidad por coche:</strong> <?= (int)$cantidadTermicos ?>; total: <?= (int)($cantidadTermicos * $cantidadEquipos) ?></p>
<p><strong>Precio unitario:</strong> $<?= formatoPrecioEntero($precioTermicoUnitario) ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioTermico * $cantidadEquipos) ?></p>
<?php else: ?>
<p class="advertencia">No se encontró un térmico para esta configuración.</p>
<?php endif; ?>

<h3>14. Adicional UCM + fuente en todos los pisos para MRL</h3>
<?php if ($adicionalUcmMrl): ?>
<p><strong>Adicional UCM:</strong> ADICUCM ($<?= formatoPrecioEntero((float)$articuloAdicUcm['precios_costo']) ?>) − A6XDESREALIMVEL ($<?= formatoPrecioEntero((float)$articuloDesarmeLimitador['precios_costo']) ?>) = $<?= formatoPrecioEntero($precioAdicionalUcmUnitario) ?> por equipo.</p>
<p><strong>Total UCM:</strong> $<?= formatoPrecioEntero($precioAdicionalUcmTotal) ?></p>
<?php else: ?>
<p>Adicional UCM no seleccionado.</p>
<?php endif; ?>
<?php if ($cantidadFuentesMrl > 0): ?>
<p><strong>Fuente para indicadores:</strong> F24V2AMRL — <?= escapar($articuloFuenteMrl['precios_descripcion'] ?? '') ?></p>
<p><strong>Cantidad:</strong> <?= (int)$cantidadFuentesMrl ?> por equipo × <?= (int)$cantidadEquipos ?> equipo(s) = <?= (int)$cantidadTotalFuentesMrl ?> fuente(s).</p>
<p><strong>Total fuentes:</strong> $<?= formatoPrecioEntero($precioFuentesMrlTotal) ?></p>
<?php else: ?>
<p>No se agregaron fuentes F24V2AMRL.</p>
<?php endif; ?>

<h3>15. Puerta automática + adicional alimentación puerta VF</h3>
<?php if ($adicionalPuertaCabina): ?>
<p><strong>Puerta de cabina:</strong> <?= escapar($puertaCabina['ptacabina_name']) ?></p>
<p><strong>Código:</strong> <?= escapar($adicionalPuertaCabina['precios_codigo']) ?> — <?= escapar($adicionalPuertaCabina['precios_descripcion']) ?></p>
<p><strong>Precio unitario:</strong> $<?= formatoPrecioEntero($precioPuertaCabinaUnitario) ?> × <?= (int)$cantidadOperadores ?> por coche × <?= (int)$cantidadEquipos ?> coches</p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioPuertaCabina * $cantidadEquipos) ?></p>
<?php elseif ($adicionalPuertaCabinaMc): ?>
<p><strong>Puerta de cabina MC:</strong> <?= escapar($puertaCabinaMc['ptacabinamc_name']) ?></p>
<p><strong>Código:</strong> <?= escapar($adicionalPuertaCabinaMc['precios_codigo']) ?> — <?= escapar($adicionalPuertaCabinaMc['precios_descripcion']) ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioPuertaCabinaMc * $cantidadEquipos) ?></p>
<?php else: ?>
<p>No corresponde un adicional de puerta de cabina para la opción seleccionada.</p>
<?php endif; ?>

<h3>14. Forzador de aire / luz de cortesía</h3><p>Se detallan y valorizan en la sección de adicionales implementados.</p>

<h3>15. Patín Otis</h3>
<?php if ($adicionalPuertasPisos): ?>
<p><strong>Puerta de piso:</strong> <?= escapar($puertasPisos['ptapisos_name']) ?></p>
<p><strong>Código:</strong> <?= escapar($adicionalPuertasPisos['precios_codigo']) ?> — <?= escapar($adicionalPuertasPisos['precios_descripcion']) ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioPuertasPisos * $cantidadEquipos) ?> (por coche, sin cantidad por paradas)</p>
<?php elseif ($adicionalPuertasPisosMc): ?>
<p><strong>Puertas de piso MC:</strong> <?= escapar($puertasPisosMc['ptapisosmc_name']) ?></p>
<p><strong>Código:</strong> <?= escapar($adicionalPuertasPisosMc['precios_codigo']) ?> — <?= escapar($adicionalPuertasPisosMc['precios_descripcion']) ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioPuertasPisosMc * $cantidadEquipos) ?></p>
<?php else: ?>
<p>No corresponde un adicional de puerta de piso para la opción seleccionada.</p>
<?php endif; ?>

<h3>20. Interfase</h3>
<?php if ($interfaseTipo !== ''): ?>
<p><strong>Opción:</strong> <?= $interfaseTipo === 'A7120_A7121' ? 'Interfase A-7120 + A-7121' : 'Interfase A-7120' ?></p>
<?php foreach ($otrosAdicionales as $lineaInterfase): ?>
<?php if (in_array($lineaInterfase['clave'], array('INTERFASE', 'INTERFASE_A7121'), true)): ?>
<p><strong><?= escapar($lineaInterfase['codigo']) ?> — <?= escapar($lineaInterfase['descripcion']) ?></strong><br>
Cantidad: <?= (int)$lineaInterfase['cantidad'] ?> × $<?= formatoPrecioEntero($lineaInterfase['unitario']) ?> = <strong>$<?= formatoPrecioEntero($lineaInterfase['total']) ?></strong><br>
<span class="mini"><?= escapar($lineaInterfase['nota']) ?></span></p>
<?php endif; ?>
<?php endforeach; ?>
<p><strong>Total Interfase:</strong> $<?= formatoPrecioEntero($sumaOtros(array('INTERFASE', 'INTERFASE_A7121'))) ?></p>
<?php else: ?>
<p>Interfase no seleccionada.</p>
<?php endif; ?>

<h3>21. Fuente switching para botoneras touch</h3>
<?php if ($fuenteSwitchingTouch): ?>
<?php foreach ($otrosAdicionales as $lineaFuenteTouch): ?>
<?php if ($lineaFuenteTouch['clave'] === 'FUENTE_SWITCHING_TOUCH'): ?>
<p><strong><?= escapar($lineaFuenteTouch['codigo']) ?> — <?= escapar($lineaFuenteTouch['descripcion']) ?></strong><br>
Cantidad: <?= (int)$lineaFuenteTouch['cantidad'] ?> × $<?= formatoPrecioEntero($lineaFuenteTouch['unitario']) ?> = <strong>$<?= formatoPrecioEntero($lineaFuenteTouch['total']) ?></strong><br>
<span class="mini"><?= escapar($lineaFuenteTouch['nota']) ?></span></p>
<?php endif; ?>
<?php endforeach; ?>
<?php else: ?>
<p>No seleccionada y sin activación automática.</p>
<?php endif; ?>

<h3>23. Posicionamiento por encoder</h3>
<?php if ($posicionamientoEncoder === 'SI'): ?>
<p><strong>Código:</strong> <?= escapar($adicionalPosicionamientoEncoder['precios_codigo']) ?> — <?= escapar($adicionalPosicionamientoEncoder['precios_descripcion']) ?></p>
<p><strong>Precio por coche:</strong> $<?= formatoPrecioEntero($precioPosicionamientoEncoderUnitario) ?> × <?= (int)$cantidadEquipos ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioPosicionamientoEncoderTotal) ?></p>
<?php else: ?>
<p>No seleccionado.</p>
<?php endif; ?>

<h3>24. Contactor de potencial</h3>
<?php if ($idContactorPotencial > 0): ?>
<?php if ($precioContactorPotencialDefinido): ?>
<p><strong>Precio por coche:</strong> $<?= formatoPrecioEntero($precioContactorPotencial) ?> × <?= (int)$cantidadEquipos ?></p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($precioContactorPotencial * $cantidadEquipos) ?></p>
<?php else: ?>
<p class="advertencia"><strong>SIN DEFINIR AÚN:</strong> el contactor fue seleccionado pero no tiene precio válido.</p>
<?php endif; ?>
<?php else: ?>
<p>No seleccionado.</p>
<?php endif; ?>

<h3>25. Tándem</h3>
<?php if ($adicionalTandem): ?>
<p><strong>Configuración Tándem por coche:</strong> $<?= formatoPrecioEntero($precioBase) ?> − <?= escapar($adicionalTandem['adicxtandem_codigo']) ?> ($<?= formatoPrecioEntero($precioCodigoTandem) ?>) = $<?= formatoPrecioEntero($precioAdicionalTandem) ?></p>
<p><strong>Total adicional Tándem:</strong> $<?= formatoPrecioEntero($precioAdicionalTandem * $cantidadEquipos) ?> (× <?= (int)$cantidadEquipos ?>)</p>
<?php else: ?>
<p>Tándem no seleccionado.</p>
<?php endif; ?>
<hr>
<h3>26. Micronivelación</h3>
<?php if ($micronivelacion): ?>
<p><strong>Código:</strong> GCMMICRO — Micronivelación para hidráulico</p>
<p><strong>Cantidad:</strong> <?= (int)$cantidadEquipos ?> unidad(es), una por equipo hidráulico.</p>
<p><strong>Total:</strong> $<?= formatoPrecioEntero($sumaOtros(array('MICRONIVELACION'))) ?></p>
<?php else: ?>
<p>Micronivelación no seleccionada.</p>
<?php endif; ?>
<hr>
<h2>Adicionales implementados</h2>
<?php if ($otrosAdicionales): ?>
<?php foreach ($otrosAdicionales as $lineaAdicional): ?>
<p><strong><?= escapar($lineaAdicional['codigo']) ?> — <?= escapar($lineaAdicional['descripcion']) ?></strong><br>
Cantidad: <?= (int)$lineaAdicional['cantidad'] ?> × $<?= formatoPrecioEntero($lineaAdicional['unitario']) ?> = <strong>$<?= formatoPrecioEntero($lineaAdicional['total']) ?></strong>
<?php if ($lineaAdicional['nota'] !== ''): ?><br><span class="mini"><?= escapar($lineaAdicional['nota']) ?></span><?php endif; ?></p>
<?php endforeach; ?>
<?php else: ?><p>No se seleccionaron otros adicionales.</p><?php endif; ?>
<?php if ($protectorFaltaFaseIncluido): ?>
<p><strong>Protector de falta de fase:</strong> incluido en el subtipo Arranque Suave; no suma A2350V2.3C.</p>
<?php endif; ?>
<hr>
<h2>Adicionales manuales</h2>
<?php if ($adicionalesManuales): ?>
<?php foreach ($adicionalesManuales as $lineaManual): ?>
<p><strong><?= escapar($lineaManual['descripcion']) ?></strong><br>
Cantidad: <?= (int)$lineaManual['cantidad'] ?> equipos × $<?= formatoPrecioEntero($lineaManual['unitario']) ?> = <strong>$<?= formatoPrecioEntero($lineaManual['total']) ?></strong></p>
<?php endforeach; ?>
<?php else: ?><p>No se ingresaron adicionales manuales.</p><?php endif; ?>
<hr>
<p><strong>Subtotal sin descuentos:</strong> $<?= formatoPrecioEntero($subtotal) ?></p>
<?php if ($descuento1 > 0): ?>
<p><strong>Descuento 1 (<?= number_format($descuento1, 0, ',', '.') ?> %):</strong> -$<?= formatoPrecioEntero($importeDescuento1) ?></p>
<?php endif; ?>
<?php if ($descuento2 > 0): ?>
<p><strong>Descuento 2 (<?= number_format($descuento2, 0, ',', '.') ?> %):</strong> -$<?= formatoPrecioEntero($importeDescuento2) ?></p>
<?php endif; ?>
<?php if ($descuento3 > 0): ?>
<p><strong>Descuento 3 (<?= number_format($descuento3, 0, ',', '.') ?> %):</strong> -$<?= formatoPrecioEntero($importeDescuento3) ?></p>
<?php endif; ?>
<hr>
<p class="total"><strong>Total final:</strong> $<?= formatoPrecioEntero($total) ?></p>

<div class="acciones">
<a class="boton boton-nuevo" href="index.php?nueva=1">NUEVA COTIZACIÓN</a>
<a class="boton boton-modificar" href="index.php">MODIFICAR COTIZACIÓN</a>
</div>
</div>
</body>
</html>
