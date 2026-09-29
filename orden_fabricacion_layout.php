<?php
/* v394 - Presentacion formal compacta. Contenido tecnico y calculos sin cambios. */
require_once __DIR__ . '/orden_fabricacion_tipos.php';
require_once __DIR__ . '/material_hueco_indicador_autonomo.php';

function ofCantidad(float $cantidad): string
{
    if (abs($cantidad - round($cantidad)) < 0.000001) return number_format($cantidad, 0, ',', '.');
    return number_format($cantidad, 2, ',', '.');
}

function ofNormalizar(string $texto): string
{
    $texto = strtoupper(trim($texto));
    $texto = strtr($texto, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'));
    return preg_replace('/\s+/', ' ', $texto) ?: $texto;
}

function ofEsDetalleDesglosadoMaterial(array $detalle): bool
{
    $texto = ofNormalizar((string)($detalle['concepto'] ?? '') . ' ' . (string)($detalle['descripcion'] ?? ''));
    foreach (array(
        'ADICIONAL POR PARADAS', 'ADIC P/PAR', 'CABEZAL', 'SOPORTE',
        'LIMITE C/SOPORTE', 'LIMITES C/SOPORTE', 'MATERIAL DE HUECO', 'RESCATE:'
    ) as $patron) {
        if (strpos($texto, $patron) !== false) return true;
    }
    return false;
}

function ofBuscarDetalle(array $detalles, array $patrones): ?array
{
    /*
     * Primero se busca por el concepto exacto. Esto evita que una palabra
     * incluida dentro de la descripcion general del control (por ejemplo,
     * "termico") haga que se tome la fila BASE en lugar del componente.
     */
    foreach ($detalles as $detalle) {
        $concepto = ofNormalizar((string)($detalle['concepto'] ?? ''));
        foreach ($patrones as $patron) {
            if ($concepto === ofNormalizar($patron)) return $detalle;
        }
    }

    /* Compatibilidad con documentos historicos cuyo concepto no era uniforme. */
    foreach ($detalles as $detalle) {
        $texto = ofNormalizar((string)($detalle['concepto'] ?? '') . ' ' . (string)($detalle['descripcion'] ?? ''));
        foreach ($patrones as $patron) {
            if (strpos($texto, ofNormalizar($patron)) !== false) return $detalle;
        }
    }
    return null;
}

function ofEsDetalleTermico(array $detalle): bool
{
    $concepto = ofNormalizar((string)($detalle['concepto'] ?? ''));
    return in_array($concepto, array('TERMICO', 'TERMICOS'), true);
}

function ofValor(array $datosFormulario, string $clave, string $alternativa = ''): string
{
    $valor = trim((string)($datosFormulario[$clave] ?? ''));
    return $valor !== '' ? $valor : $alternativa;
}

function ofAgregarMaterialesRescateMrl($arg1, $arg2, $arg3 = null, $arg4 = null): array
{
    /*
     * Compatibilidad total entre revisiones:
     * - llamada nueva: ofAgregarMaterialesRescateMrl($conexion, $materialHueco, $detalles, $datosFormulario)
     * - llamada anterior: ofAgregarMaterialesRescateMrl($materialHueco, $detalles, $datosFormulario)
     * Nunca se debe confundir la conexion mysqli con el resultado de Material de Hueco.
     */
    $conexion = null;
    if ($arg1 instanceof mysqli) {
        $conexion = $arg1;
        $materialHueco = $arg2;
        $detalles = is_array($arg3) ? $arg3 : array();
        $datosFormulario = is_array($arg4) ? $arg4 : array();
    } else {
        $materialHueco = $arg1;
        $detalles = is_array($arg2) ? $arg2 : array();
        $datosFormulario = is_array($arg3) ? $arg3 : array();
    }

    /* Preservar SIEMPRE el Material de Hueco calculado originalmente. */
    if (is_object($materialHueco)) $materialHueco = (array)$materialHueco;
    if (!is_array($materialHueco)) {
        $materialHueco = array(
            'titulo' => 'Material de hueco',
            'configuracion' => '',
            'materiales' => array(),
            'advertencias' => array(),
        );
    }
    if (!isset($materialHueco['materiales']) || !is_array($materialHueco['materiales'])) {
        $materialHueco['materiales'] = array();
    }

    $idTipoControl = (int)($datosFormulario['id_tipo_control'] ?? 0);
    $idRescate = (int)($datosFormulario['id_rescate'] ?? 0);
    if ($idTipoControl !== 7 || $idRescate <= 0) return $materialHueco; // solo MRL con rescate

    $cantidadEquipos = max(1, (int)($datosFormulario['cantidad_equipos'] ?? 1));
    $paradas = $datosFormulario['paradas_equipo'] ?? array();
    if (!is_array($paradas)) $paradas = array($paradas);

    /*
     * Componentes fisicos para Produccion:
     * A2142C/H2142SH participan de la valorizacion comercial, pero en la OF
     * se traducen fisicamente a +1 Cabezal A2140 y +1 Soporte H2142S por equipo.
     * 16IMA050 y la UPS se muestran como componentes propios del rescate.
     */
    $cantidades = array();

    if ($conexion instanceof mysqli) {
        $tabla = $conexion->query("SHOW TABLES LIKE 'rescates_componentes'");
        if ($tabla && $tabla->num_rows > 0) {
            $stmt = $conexion->prepare("SELECT componente_codigo, componente_regla, componente_cantidad, componente_orden
                FROM rescates_componentes
                WHERE rescate_id=? AND componente_activo='SI'
                ORDER BY componente_orden, componente_id");
            if ($stmt) {
                $stmt->bind_param('i', $idRescate);
                $stmt->execute();
                $rs = $stmt->get_result();
                while ($comp = $rs->fetch_assoc()) {
                    $codigo = strtoupper(trim((string)$comp['componente_codigo']));
                    if (!in_array($codigo, array('A2142C','H2142SH','16IMA050','UPS0.8KVA'), true)) continue;
                    $base = (float)$comp['componente_cantidad'];
                    if ($base <= 0) continue;
                    if ((string)$comp['componente_regla'] === 'PARADAS_MENOS_2') {
                        $cantidad = 0.0;
                        for ($i=0; $i<$cantidadEquipos; $i++) {
                            $p = isset($paradas[$i]) ? (int)$paradas[$i] : 0;
                            $cantidad += max(0, $p - 2) * $base;
                        }
                    } else {
                        $cantidad = $cantidadEquipos * $base;
                    }
                    if ($cantidad > 0) $cantidades[$codigo] = ($cantidades[$codigo] ?? 0.0) + $cantidad;
                }
                $stmt->close();
            }
        }
    }

    /* Fallback para documentos/revisiones donde no se dispone de la conexion en la llamada. */
    if (!$cantidades) {
        foreach ($detalles as $detalle) {
            $concepto = ofNormalizar((string)($detalle['concepto'] ?? ''));
            $descripcion = (string)($detalle['descripcion'] ?? '');
            $codigoDetalle = strtoupper(trim((string)($detalle['codigo'] ?? '')));
            if (strpos($concepto, 'RESCATE') !== 0 && strpos(strtoupper($descripcion), 'RESCATE') === false) continue;

            $texto = strtoupper($descripcion . ' ' . (string)($detalle['observacion'] ?? ''));
            if ($codigoDetalle === 'UPS0.8KVA' || strpos($texto, 'UPS0.8KVA') !== false || strpos($texto, 'UPS 0,8') !== false) {
                $cantidades['UPS0.8KVA'] = max((float)($detalle['cantidad'] ?? 1), (float)($cantidades['UPS0.8KVA'] ?? 0));
            }
            if (preg_match('/16IMA050\s*X\s*([0-9]+(?:[\.,][0-9]+)?)/i', $texto, $m)) {
                $cantidades['16IMA050'] = (float)str_replace(',', '.', $m[1]);
            } elseif (strpos($texto, '16IMA050') !== false) {
                $total = 0.0;
                for ($i=0; $i<$cantidadEquipos; $i++) {
                    $p = isset($paradas[$i]) ? (int)$paradas[$i] : 0;
                    $total += max(0, $p - 2);
                }
                if ($total > 0) $cantidades['16IMA050'] = $total;
            }
            if (strpos($texto, 'A2142C') !== false) $cantidades['A2142C'] = (float)$cantidadEquipos;
            if (strpos($texto, 'H2142SH') !== false) $cantidades['H2142SH'] = (float)$cantidadEquipos;
        }
    }

    /* Si es rescate MRL, los dos materiales fisicos adicionales son obligatorios por equipo. */
    $extraCabezal = (float)$cantidadEquipos;
    $extraSoporte = (float)$cantidadEquipos;

    $sumarMaterial = function(string $claveObjetivo, string $nombre, float $cantidad, string $observacion) use (&$materialHueco): void {
        if ($cantidad <= 0) return;
        foreach ($materialHueco['materiales'] as &$material) {
            $clave = strtoupper(trim((string)($material['clave'] ?? '')));
            $nom = strtoupper(trim((string)($material['nombre'] ?? '')));
            if ($clave === strtoupper($claveObjetivo) ||
                ($claveObjetivo === 'CABEZAL_A2140' && strpos($nom, 'A2140') !== false && strpos($nom, 'CABEZAL') !== false) ||
                ($claveObjetivo === 'SOPORTE_H2142S' && strpos($nom, 'H2142S') !== false && strpos($nom, 'SOPORTE') !== false)) {
                $material['cantidad'] = (float)($material['cantidad'] ?? 0) + $cantidad;
                $material['observacion'] = trim((string)($material['observacion'] ?? '') . ' ' . $observacion);
                unset($material);
                return;
            }
        }
        unset($material);
        $materialHueco['materiales'][] = array(
            'material_id' => 0,
            'clave' => $claveObjetivo,
            'nombre' => $nombre,
            'unidad' => 'UN',
            'cantidad' => $cantidad,
            'formula' => 'Adicional por rescate MRL',
            'observacion' => $observacion,
        );
    };

    $sumarMaterial('CABEZAL_A2140', 'Cabezal magnético A2140', $extraCabezal, '+ adicional por rescate MRL');
    $sumarMaterial('SOPORTE_H2142S', 'Soporte H2142S', $extraSoporte, '+ adicional por rescate MRL');

    /* Imanes de 5 cm y UPS como renglones propios del rescate. */
    $existentes = array();
    foreach ($materialHueco['materiales'] as $m) {
        $clave = strtoupper((string)($m['clave'] ?? ''));
        $nombre = strtoupper((string)($m['nombre'] ?? ''));
        foreach (array('16IMA050','UPS0.8KVA') as $codigo) {
            if (strpos($clave, $codigo) !== false || strpos($nombre, $codigo) !== false) $existentes[$codigo] = true;
        }
    }

    $nombres = array(
        '16IMA050' => 'Rescate MRL - Imán 6x25 mm L050 mm (16IMA050)',
        'UPS0.8KVA' => 'Rescate MRL - UPS 0,8 KVA (UPS0.8KVA)',
    );
    foreach (array('16IMA050','UPS0.8KVA') as $codigo) {
        if (empty($cantidades[$codigo]) || !empty($existentes[$codigo])) continue;
        $materialHueco['materiales'][] = array(
            'material_id' => 0,
            'clave' => 'RESCATE_MRL_' . $codigo,
            'nombre' => $nombres[$codigo],
            'unidad' => 'UN',
            'cantidad' => (float)$cantidades[$codigo],
            'formula' => 'Componente físico del rescate MRL seleccionado',
            'observacion' => 'Preparar junto con el rescate MRL del pedido',
        );
    }

    return $materialHueco;
}

function ofResumenTecnico(mysqli $conexion, array $pedido, array $detalles, array $datosFormulario): array
{
    $base = ofBuscarDetalle($detalles, array('BASE'));
    if (!$base) $base = $detalles[0] ?? array('descripcion'=>'', 'codigo'=>'');
    $tecnico = pdfDatosTecnicos($conexion, array('datos_formulario' => json_encode($datosFormulario, JSON_UNESCAPED_UNICODE)));
    $tecnico = pdfCompletarDesdeDescripcionBase($base, $tecnico);

    $paradas = pdfTextoParadas((array)($tecnico['paradas'] ?? array()));
    $partesParadasNomenclatura=array();
    foreach((array)($tecnico['paradas']??array()) as $i=>$p){
        $nom=trim((string)(($tecnico['nomenclaturas']??array())[$i]??'')); if($nom==='')$nom='A CONFIRMAR';
        $partesParadasNomenclatura[]='Coche '.($i+1).': '.(int)$p.' paradas - '.$nom;
    }
    $paradasDetalle=$partesParadasNomenclatura?implode(' / ',$partesParadasNomenclatura):$paradas;
    $cantidadEquipos = max(1, (int)($tecnico['cantidad'] ?? 1));
    $encoder = strtoupper(trim((string)($datosFormulario['encoder'] ?? ''))) === 'SI' ? 'Preparado para encoder' : 'Sin encoder';
    $esDatoMotorVF = (int)($datosFormulario['id_tipo_control'] ?? 0) === 4 && array_key_exists('dato_motor_tipo', $datosFormulario);
    $unidadDatoMotor = strtoupper((string)($datosFormulario['dato_motor_tipo'] ?? '')) === 'AMP' ? 'A' : (strtoupper((string)($datosFormulario['dato_motor_tipo'] ?? '')) === 'KW' ? 'kW' : 'HP');
    $datoMotorTexto = $esDatoMotorVF ? trim((string)($datosFormulario['dato_motor_valor'] ?? '')).' '.$unidadDatoMotor : '';
    $corrienteRequeridaTexto = $esDatoMotorVF ? trim((string)($tecnico['corriente_requerida'] ?? '')) : '';
    $motor = trim(implode(' / ', array_filter(array(
        ($tecnico['tipo'] ?? '') !== '' ? (string)$tecnico['tipo'] : '',
        ($tecnico['subtipo'] ?? '') !== '' ? (string)$tecnico['subtipo'] : '',
        $datoMotorTexto !== '' ? 'Dato informado '.$datoMotorTexto : '',
        $corrienteRequeridaTexto !== '' ? 'Requerida '.$corrienteRequeridaTexto.' A' : '',
        ($tecnico['corriente'] ?? '') !== '' ? (string)$tecnico['corriente'] . ' A' : '',
        $encoder,
        ($tecnico['velocidad'] ?? '') !== '' ? (string)$tecnico['velocidad'] . ' m/min' : '',
        ($tecnico['tension'] ?? '') !== '' ? (string)$tecnico['tension'] : '',
    ))));

    $termico = ofBuscarDetalle($detalles, array('TERMICO', 'TÉRMICO'));
    $puertaCabina = (string)($tecnico['puerta_cabina'] ?? '');
    $puertaPisos = (string)($tecnico['puerta_pisos'] ?? '');

    $adicionales = array();
    foreach ($detalles as $detalle) {
        if (ofEsDetalleDesglosadoMaterial($detalle)) continue;
        if (ofEsDetalleTermico($detalle)) continue;
        $concepto = ofNormalizar((string)($detalle['concepto'] ?? ''));
        if ($concepto === 'BASE') continue;
        $descripcion = trim((string)($detalle['descripcion'] ?? ''));
        if ($descripcion === '') continue;
        $adicionales[] = pdfTextoMinusculas($descripcion);
    }
    $adicionales = array_values(array_unique($adicionales));

    $mrlTitulo = '';
    $mrlGabinete = '';
    if ((int)($datosFormulario['id_tipo_control'] ?? 0) === 7 && function_exists('pdfNotaTecnicaMrl')) {
        list($mrlTitulo, $mrlGabinete) = pdfNotaTecnicaMrl(array('datos_formulario' => json_encode($datosFormulario, JSON_UNESCAPED_UNICODE)));
    }

    return array(
        'codigo_control' => trim((string)($base['codigo'] ?? '')),
        'tipo_control' => trim((string)($tecnico['cpu'] ?? '')),
        'cantidad_equipos' => $cantidadEquipos . ($cantidadEquipos === 1 ? ' individual' : ' equipos'),
        'maniobra' => trim((string)($tecnico['maniobra'] ?? '')),
        'configuracion_especial' => trim((string)($tecnico['configuracion_especial'] ?? 'Normal')) . ((string)($tecnico['programa_tip'] ?? '') !== '' ? ' - ' . trim((string)$tecnico['programa_tip']) : ''),
        'paradas' => $paradasDetalle,
        'motor' => $motor,
        'potencia' => $esDatoMotorVF ? $datoMotorTexto : (trim((string)($tecnico['potencia'] ?? '')) !== '' ? trim((string)$tecnico['potencia']) . ' HP' : ''),
        'corriente' => trim((string)($tecnico['corriente'] ?? '')) !== '' ? trim((string)$tecnico['corriente']) . ' A' : 'Sin confirmar',
        'corriente_requerida' => $corrienteRequeridaTexto !== '' ? $corrienteRequeridaTexto . ' A' : '',
        'etiqueta_potencia' => $esDatoMotorVF ? 'Dato del motor' : 'Potencia',
        'contactores' => trim((string)($tecnico['contactor'] ?? '')) !== '' ? trim((string)$tecnico['contactor']) . ' A' : '',
        'termicos' => pdfTextoMinusculas(trim((string)($termico['descripcion'] ?? 'Incluidos segun configuracion'))),
        'puerta_cabina' => $puertaCabina,
        'puerta_pisos' => $puertaPisos,
        'apertura_operadores' => trim((string)($tecnico['apertura_operadores'] ?? '')),
        'servicios' => ofValor($datosFormulario, 'servicios_texto', 'Según pedido confirmado'),
        'descripcion_base' => pdfTextoMinusculas(trim((string)($base['descripcion'] ?? ''))),
        'mrl_titulo' => trim((string)$mrlTitulo),
        'mrl_gabinete' => trim((string)$mrlGabinete),
        'adicionales' => $adicionales,
    );
}

function ofTituloPagina(PdfAutomac $pdf, string $titulo, string $subtitulo = ''): float
{
    /* v424: cabecera ajustada para titulos largos de OF sin solaparse con la pastilla PRODUCCION. */
    $pdf->automacLogo(38, 790, 78);

    $titleX = 122;
    $titleMaxW = 346;
    $titleSize = 16;
    $titleLines = $pdf->wrap($titulo, $titleMaxW, $titleSize);
    while (count($titleLines) > 2 && $titleSize > 11) {
        $titleSize--;
        $titleLines = $pdf->wrap($titulo, $titleMaxW, $titleSize);
    }
    if (count($titleLines) > 2) {
        $titleLines = array_slice($titleLines, 0, 2);
    }

    if (count($titleLines) <= 1) {
        $pdf->colorText($titleX, 807, $titleLines[0] ?? $titulo, $titleSize, true, 18, 50, 91);
        if ($subtitulo !== '') $pdf->colorText($titleX + 1, 790, $subtitulo, 7, true, 92, 110, 126);
    } else {
        $pdf->colorText($titleX, 811, $titleLines[0], $titleSize, true, 18, 50, 91);
        $pdf->colorText($titleX, 797, $titleLines[1], $titleSize, true, 18, 50, 91);
        if ($subtitulo !== '') $pdf->colorText($titleX + 1, 784, $subtitulo, 7, true, 92, 110, 126);
    }

    $pdf->fillColorRect(480, 789, 77, 30, 18, 50, 91);
    $pdf->colorText(493, 801, 'PRODUCCION', 8, true, 255, 255, 255);
    $pdf->fillColorRect(28, 778, 539, 4, 25, 96, 180);
    return 760;
}

function ofFilaDato(PdfAutomac $pdf, float &$y, string $label, string $value, float $labelWidth = 145, float $valueWidth = 374, int $size = 8): void
{
    $lineas = $pdf->wrap(pdfTextoMinusculas($value !== '' ? $value : '-'), $valueWidth - 8, $size);
    $alto = max(18, count($lineas) * 10 + 6);
    $pdf->rect(38, $y - $alto + 4, $labelWidth, $alto, true, 0.94);
    $pdf->rect(38 + $labelWidth, $y - $alto + 4, $valueWidth, $alto);
    $pdf->text(43, $y - 8, $label, $size, true);
    foreach ($lineas as $i => $linea) $pdf->text(38 + $labelWidth + 5, $y - 8 - ($i * 10), $linea, $size);
    $y -= $alto;
}


function ofCampoCompacto(PdfAutomac $pdf, float $x, float $y, float $w, string $label, string $value, float $alto = 28): void
{
    $labelW = 92;
    $font = 8;
    $lineH = 10;
    $pdf->rect($x, $y-$alto, $w, $alto);
    $pdf->fillColorRect($x, $y-$alto, 4, $alto, 19, 135, 83);
    $pdf->rect($x+4, $y-$alto, $labelW-4, $alto, true, 0.965);
    $pdf->text($x+9, $y-17, $label, $font, true);
    $lineas = $pdf->wrap(pdfTextoMinusculas($value !== '' ? $value : '-'), $w-$labelW-12, $font);
    $lineas = array_slice($lineas, 0, 2);
    $inicioY = $y - (($alto - (count($lineas) * $lineH)) / 2) - 7;
    foreach ($lineas as $i => $linea) {
        $pdf->text($x+$labelW+6, $inicioY-($i*$lineH), $linea, $font);
    }
}

function ofCabeceraDocumento(PdfAutomac $pdf, float &$y, array $pedido): void
{
    $numero = numeroDocumentoVisible((string)$pedido['pedido_numero']);
    $revision = (int)($pedido['revision'] ?? 0);
    $cliente = function_exists('pdfNombreCliente') ? pdfNombreCliente($pedido) : strtoupper(trim((string)$pedido['clientes_nomfantasia']));
    $referencia = trim((string)($pedido['referencia'] ?? ''));
    $origen = (string)($pedido['cotizacion_numero'] ?? '') !== '' ? numeroDocumentoVisible((string)$pedido['cotizacion_numero']) : 'Pedido directo';
    $fecha = date('d/m/Y', strtotime((string)$pedido['fecha_creacion']));
    $sigla = strtoupper(trim((string)($pedido['clientes_codigo'] ?? '')));
    $responsable = pdfTextoMinusculas(trim((string)($pedido['responsable_comercial'] ?? '')) ?: '-');

    /* Tarjeta maestra del trabajo. Mantiene todos los datos existentes. */
    $h=86;
    $pdf->roundedRect(38,$y-$h,519,$h,7);
    $pdf->fillColorRect(38,$y-$h,6,$h,25,96,180);

    $pdf->colorText(52,$y-14,'ORDEN / PEDIDO',6,true,83,102,118);
    $pdf->colorText(52,$y-34,$numero,15,true,18,50,91);
    $pdf->colorText(52,$y-51,'REV. '.$revision,7,true,25,96,180);

    $pdf->colorText(146,$y-14,'CLIENTE',6,true,83,102,118);
    $pdf->text(146,$y-30,$cliente !== '' ? $cliente : '-',9,true);
    $pdf->text(146,$y-45,'Sigla: '.($sigla!==''?$sigla:'-'),7);
    $pdf->text(146,$y-59,'Responsable: '.$responsable,7);

    $pdf->colorText(365,$y-14,'FECHA',6,true,83,102,118);
    $pdf->text(365,$y-30,$fecha,9,true);
    $pdf->colorText(438,$y-14,'ORIGEN',6,true,83,102,118);
    $pdf->text(438,$y-30,pdfTextoMinusculas($origen),8,true);

    $pdf->colorText(365,$y-49,'REFERENCIA / OBRA',6,true,83,102,118);
    $refLines=$pdf->wrap(pdfTextoMinusculas($referencia!==''?$referencia:'-'),180,7);
    foreach(array_slice($refLines,0,2) as $i=>$line) $pdf->text(365,$y-64-($i*9),$line,7,$i===0);

    $y -= $h + 12;
}

function ofDibujarPagina1(PdfAutomac $pdf, mysqli $conexion, array $pedido, array $detalles, array $datosFormulario, array $materialHueco): void
{
    $y = ofTituloPagina($pdf, 'ORDEN DE FABRICACION - CONTROLES', 'Hoja 1 de 3 - Datos tecnicos y material de hueco');
    ofCabeceraDocumento($pdf, $y, $pedido);
    $res = ofResumenTecnico($conexion, $pedido, $detalles, $datosFormulario);

    $pdf->fillColorRect(38, $y - 22, 519, 24, 232, 246, 238);
    $pdf->fillColorRect(38, $y - 22, 5, 24, 19, 135, 83);
    $pdf->colorText(50, $y - 14, 'DATOS TECNICOS DEL CONTROL', 10, true, 13, 99, 61);
    $y -= 25;
    $campos = array(
        array('Tipo control', trim($res['tipo_control'] . ($res['codigo_control'] !== '' ? ' - ' . $res['codigo_control'] : ''))),
        array('Ascensores', $res['cantidad_equipos']),
        array('Maniobra', $res['maniobra']), array('Configuración especial', $res['configuracion_especial']),
        array('Paradas', $res['paradas']), array('Motor / tension', $res['motor']),
        array($res['etiqueta_potencia'], $res['potencia']), array('Corriente nominal', $res['corriente']),
        array('Corriente requerida', $res['corriente_requerida']),
        array('Contactores', $res['contactores']), array('Termicos', $res['termicos']),
        array('Puerta cabina', $res['puerta_cabina']), array('Puertas piso', $res['puerta_pisos']),
        array('Apertura operadores', $res['apertura_operadores']), array('Servicios', $res['servicios']),
        array('Configuración MRL', $res['mrl_titulo']),
    );
    for ($i=0; $i<count($campos); $i+=2) {
        ofCampoCompacto($pdf, 38, $y, 255, $campos[$i][0], $campos[$i][1], 28);
        if (isset($campos[$i+1])) ofCampoCompacto($pdf, 302, $y, 255, $campos[$i+1][0], $campos[$i+1][1], 28);
        $y -= 28;
    }
    $adicionales = implode(' / ', array_slice($res['adicionales'], 0, 6));
    if (function_exists('mb_substr')) $adicionales = mb_substr($adicionales, 0, 600, 'UTF-8'); else $adicionales = substr($adicionales, 0, 600);
    ofFilaDato($pdf, $y, 'Adicionales tecnicos', $adicionales !== '' ? $adicionales : 'Sin adicionales tecnicos', 145, 374, 8);
    $y -= 4;
    $pdf->fillColorRect(38, $y - 22, 519, 24, 232, 246, 238);
    $pdf->fillColorRect(38, $y - 22, 5, 24, 19, 135, 83);
    $pdf->colorText(50, $y - 14, 'MATERIAL DE HUECO', 10, true, 13, 99, 61);
    $y -= 25;
    $pdf->text(42, $y, pdfTextoMinusculas((string)($materialHueco['titulo'] ?? 'Material de hueco')), 9, true);
    $y -= 17;
    $x = 38; $totalW = 519; $headerH = 20;
    $pdf->rect($x, $y-$headerH+4, $totalW, $headerH, true, 0.88);
    $pdf->text($x+8, $y-9, 'Cant.', 8, true);
    $pdf->text($x+62, $y-9, 'Verif.', 8, true);
    $pdf->text($x+112, $y-9, 'Descripcion', 8, true);
    $y -= $headerH;
    foreach ((array)($materialHueco['materiales'] ?? array()) as $m) {
        $rowH = 20;
        $pdf->rect($x, $y-$rowH+4, $totalW, $rowH);
        $pdf->line($x+52, $y-$rowH+4, $x+52, $y+4, 0.25);
        $pdf->line($x+102, $y-$rowH+4, $x+102, $y+4, 0.25);
        $pdf->text($x+16, $y-9, mhOfFormatearCantidad((float)$m['cantidad']), 9, true);
        $pdf->rect($x+68, $y-13, 11, 11);
        $pdf->text($x+110, $y-9, pdfTextoMinusculas((string)$m['nombre']), 9);
        $y -= $rowH;
    }
    if (count((array)($materialHueco['materiales'] ?? array())) < 9) {
        $rowH=20; $pdf->rect($x,$y-$rowH+4,$totalW,$rowH);
        $pdf->line($x+52,$y-$rowH+4,$x+52,$y+4,0.25); $pdf->line($x+102,$y-$rowH+4,$x+102,$y+4,0.25);
        $pdf->rect($x+68,$y-13,11,11); $y-=$rowH;
    }

    $boxTop = $y - 10;
    $boxH = 92;
    $pdf->roundedRect(38, $boxTop - $boxH, 250, $boxH, 5);
    $pdf->roundedRect(307, $boxTop - $boxH, 250, $boxH, 5);
    $pdf->rect(38, $boxTop - 20, 250, 20, true, 0.9);
    $pdf->rect(307, $boxTop - 20, 250, 20, true, 0.9);
    $pdf->text(132, $boxTop - 13, 'NOTAS', 9, true);
    $pdf->text(365, $boxTop - 13, 'ESPECIFICACIONES CONTROLES', 9, true);
    for ($i=1; $i<=5; $i++) {
        $yy = $boxTop - 20 - ($i * 12);
        $pdf->line(44, $yy, 282, $yy, 0.25);
        $pdf->line(313, $yy, 551, $yy, 0.25);
    }
    $codeY = $boxTop - $boxH - 14;
    $pdf->roundedRect(38, $codeY - 45, 250, 45, 5);
    $pdf->roundedRect(307, $codeY - 45, 250, 45, 5);
    $pdf->text(88, $codeY - 13, 'CODIGO PLANO MECANICO', 9, true);
    $pdf->text(359, $codeY - 13, 'CODIGO PLANO ELECTRICO', 9, true);
    $pdf->line(38, 54, 557, 54, 0.6);
    $responsableComercial = trim((string)($pedido['responsable_comercial'] ?? ''));
    $pdf->text(42, 39, 'Responsable comercial: ' . pdfTextoMinusculas($responsableComercial !== '' ? $responsableComercial : '____________________'), 8);
    $pdf->text(300, 39, 'Responsable técnico: ____________________', 8);
    $pdf->text(411, 39, 'UE: ________', 8);
}

function ofDibujarPagina2(PdfAutomac $pdf, mysqli $conexion, array $pedido, array $detalles, array $datosFormulario): void
{
    $pdf->newPage();
    $y = ofTituloPagina($pdf, 'PRUEBA DE CONTROLES', 'Hoja 2 de 3 - Seguimiento de produccion');
    $res = ofResumenTecnico($conexion, $pedido, $detalles, $datosFormulario);
    $pdf->text(38, $y, 'Fecha: ' . date('d/m/Y', strtotime((string)$pedido['fecha_creacion'])), 8, true);
    $pdf->text(385, $y, 'Pedido: ' . numeroDocumentoVisible((string)$pedido['pedido_numero']), 8, true);
    $y -= 24;
    foreach (array(
        'Tipo Control' => trim($res['tipo_control'] . ' ' . $res['codigo_control']),
        'Numero de ascensores' => $res['cantidad_equipos'],
        'Maniobra' => $res['maniobra'],
        'Configuracion especial' => $res['configuracion_especial'],
        'Configuracion MRL' => $res['mrl_titulo'],
        'Gabinete MRL' => $res['mrl_gabinete'],
        'Paradas' => $res['paradas'],
        'Tipo y tension de motor' => $res['motor'],
        $res['etiqueta_potencia'] => $res['potencia'],
        'Corriente nominal' => $res['corriente'],
        'Corriente requerida' => $res['corriente_requerida'],
        'Contactores' => $res['contactores'],
        'Puerta de cabina' => $res['puerta_cabina'],
        'Puertas de piso' => $res['puerta_pisos'],
        'Apertura por operador' => $res['apertura_operadores'],
        'Termicos y protecciones' => $res['termicos'],
        'Servicios' => $res['servicios'],
        'Adicionales' => (function_exists('mb_substr') ? mb_substr(implode(' / ', array_slice($res['adicionales'],0,6)),0,450,'UTF-8') : substr(implode(' / ', array_slice($res['adicionales'],0,6)),0,450)),
    ) as $label=>$valor) ofFilaDato($pdf, $y, $label, $valor, 150, 369, 8);

    foreach (array('Observaciones','Chapa Base','Gabinete') as $label) {
        $pdf->text(38, $y - 4, $label . ':', 8, true);
        $pdf->line(155, $y - 6, 557, $y - 6, 0.35);
        $y -= 18;
    }

    $y -= 4;
    $pdf->fillColorRect(38, $y - 22, 519, 22, 232, 246, 238);
    $pdf->fillColorRect(38, $y - 22, 5, 22, 19, 135, 83);
    $pdf->colorText(50, $y - 14, 'SEGUIMIENTO DE PRODUCCION Y NO CONFORMIDADES', 9, true, 13, 99, 61);
    $y -= 26;
    $sectores = array(
        array('DOCUMENTACION ELECTRICA','DE'), array('DOCUMENTACION MECANICA','DX'),
        array('SEPARACION DE MATERIALES','SM'), array('MONTAJE EN CHAPA BASE','M'),
        array('CABLEADO','C'), array('MONTAJE EN GABINETE','MX'),
        array('PRUEBA','P'), array('EMBALAJE','T'),
    );
    $rowH = 39; $x=38;
    $pdf->fillColorRect($x, $y-24, 519, 24, 31, 55, 70);
    $pdf->colorText($x+50,$y-15,'SECTOR',8,true,255,255,255);
    $pdf->colorText($x+162,$y-15,'EST.',8,true,255,255,255);
    $pdf->colorText($x+225,$y-15,'RESPONSABLE',8,true,255,255,255);
    $pdf->colorText($x+375,$y-15,'NO CONFORMIDADES',8,true,255,255,255);
    $y -= 24;
    foreach ($sectores as $s) {
        $pdf->rect($x,$y-$rowH,150,$rowH); $tmp = $y - 12; $pdf->paragraph($x+5,$tmp,$s[0],140,7,9,true);
        $pdf->rect($x+150,$y-$rowH,42,$rowH); $pdf->text($x+164,$y-22,$s[1],8,true);
        $pdf->rect($x+192,$y-$rowH,150,$rowH);
        $pdf->rect($x+342,$y-$rowH,177,$rowH);
        $pdf->text($x+348,$y-13,'[ ] OK   [ ] Observado',7);
        $pdf->line($x+348,$y-25,$x+512,$y-25,0.25);
        $pdf->line($x+348,$y-34,$x+512,$y-34,0.25);
        $y -= $rowH;
    }
    $responsableComercial = trim((string)($pedido['responsable_comercial'] ?? ''));
    $pdf->text(38, 56, 'Responsable comercial: ' . pdfTextoMinusculas($responsableComercial !== '' ? $responsableComercial : '____________________'), 7);
    $pdf->text(365, 56, 'Fecha cierre: ____/____/________', 7);
}

function ofDibujarEtiqueta(PdfAutomac $pdf, float $top, mysqli $conexion, array $pedido, array $detalles, array $datosFormulario, string $copia): void
{
    $res = ofResumenTecnico($conexion, $pedido, $detalles, $datosFormulario);
    $bottom = $top - 350;
    $pdf->roundedRect(38, $bottom, 519, 342, 5);
    $pdf->fillColorRect(38, $top-28, 519, 22, 232, 246, 238);
    $pdf->fillColorRect(38, $top-28, 5, 22, 19, 135, 83);
    $pdf->colorText(50, $top-20, 'OBLEA DE PRODUCCION - CONTROL', 9, true, 13, 99, 61);
    $pdf->text(440, $top-20, $copia, 8, true);
    $y = $top - 46;
    $pdf->text(44,$y,'Fecha: ' . date('d/m/Y', strtotime((string)$pedido['fecha_creacion'])),8,true);
    $pdf->text(360,$y,'Pedido: ' . numeroDocumentoVisible((string)$pedido['pedido_numero']),8,true);
    $y -= 18;
    $pdf->text(44,$y,'Empresa: ' . (function_exists('pdfClienteInterno') ? pdfClienteInterno($pedido) : trim((string)$pedido['clientes_nomfantasia']) . ' (' . strtoupper(trim((string)$pedido['clientes_codigo'])) . ')'),8);
    $y -= 16;
    $pdf->text(44,$y,'Referencia: ' . pdfTextoMinusculas((string)($pedido['referencia'] ?? '')),8);
    $y -= 18;
    $datos = array(
        'Tipo Control'=>$res['tipo_control'].' '.$res['codigo_control'],
        'Numero de ascensores'=>$res['cantidad_equipos'],
        'Maniobra'=>$res['maniobra'], 'Configuracion especial'=>$res['configuracion_especial'], 'Paradas'=>$res['paradas'],
        'Tipo y tension de motor'=>$res['motor'], 'Potencia'=>$res['potencia'],
        'Corriente nominal'=>$res['corriente'], 'Contactores'=>$res['contactores'],
        'Puerta cabina'=>$res['puerta_cabina'], 'Puertas pisos'=>$res['puerta_pisos'],
        'Apertura operadores'=>$res['apertura_operadores'], 'Termicos'=>$res['termicos'], 'Servicios'=>$res['servicios'],
    );
    foreach ($datos as $label=>$valor) {
        $pdf->text(44,$y,$label.':',8,true);
        $lineas=$pdf->wrap(pdfTextoMinusculas($valor!==''?$valor:'-'),355,8);
        foreach ($lineas as $i=>$ln) $pdf->text(180,$y-($i*10),$ln,8);
        $y -= max(16,count($lineas)*10+4);
    }
    $pdf->text(44,$bottom+47,'Observaciones:',7,true); $pdf->line(130,$bottom+45,548,$bottom+45,0.3);
    $pdf->text(44,$bottom+31,'Chapa Base:',7,true); $pdf->line(130,$bottom+29,548,$bottom+29,0.3);
    $pdf->text(44,$bottom+15,'Gabinete:',7,true); $pdf->line(130,$bottom+13,548,$bottom+13,0.3);
}

function ofDibujarPagina3(PdfAutomac $pdf, mysqli $conexion, array $pedido, array $detalles, array $datosFormulario): void
{
    $pdf->newPage();
    $pdf->fillColorRect(28, 784, 539, 40, 245, 247, 249);
    $pdf->fillColorRect(28, 784, 7, 40, 18, 50, 91);
    $pdf->automacLogo(44, 789, 72);
    $pdf->colorText(380, 800, 'OBLEAS DE PRODUCCION', 11, true, 255,255,255);
    ofDibujarEtiqueta($pdf, 782, $conexion, $pedido, $detalles, $datosFormulario, 'COPIA 1');
    ofDibujarEtiqueta($pdf, 420, $conexion, $pedido, $detalles, $datosFormulario, 'COPIA 2');
    $pdf->text(250, 24, 'Hoja 3 de 3', 7);
}

/**
 * Orden operativa para pedidos de suministros/repuestos (serie P.).
 * No contiene datos tecnicos de control, material de hueco ni obleas.
 * Se centra en preparacion, control y despacho de los renglones pedidos.
 */
function ofDibujarOrdenPreparacionSuministros(PdfAutomac $pdf, mysqli $conexion, array $pedido, array $detalles): void
{
    // v372: los pedidos tipo SUMINISTROS (P.) tambien pueden contener
    // senalizacion para otro control/electromecanico. Si existe APPIND,
    // convertirlo a los materiales fisicos que Produccion debe preparar.
    // La funcion deja el detalle sin cambios cuando APPIND no existe.
    $detalles = ofExpandirAppindComoMaterialHueco($conexion, $detalles);

    $pagina = 1;
    $dibujarCabecera = function (bool $continuacion = false) use ($pdf, $pedido, &$pagina): float {
        $subtitulo = $continuacion ? 'Continuacion - Preparacion de materiales' : 'Preparacion · Control · Despacho';
        $y = ofTituloPagina($pdf, 'ORDEN DE SUMINISTROS', $subtitulo);

        $numero = numeroDocumentoVisible((string)($pedido['pedido_numero'] ?? ''));
        $cliente = function_exists('pdfNombreCliente') ? pdfNombreCliente($pedido) : strtoupper(trim((string)($pedido['clientes_nomfantasia'] ?? '')));
        $referencia = trim((string)($pedido['referencia'] ?? ''));
        $origen = trim((string)($pedido['cotizacion_numero'] ?? '')) !== '' ? numeroDocumentoVisible((string)$pedido['cotizacion_numero']) : 'Pedido directo';
        $fecha = !empty($pedido['fecha_creacion']) ? date('d/m/Y', strtotime((string)$pedido['fecha_creacion'])) : '';

        // Banda de datos generales, visualmente separada del picking.
        $pdf->fillColorRect(38, $y - 82, 519, 86, 247, 250, 252);
        $pdf->fillColorRect(38, $y - 82, 6, 86, 15, 123, 77);
        $pdf->colorText(50, $y - 13, 'PEDIDO', 7, true, 93, 111, 123);
        $pdf->text(50, $y - 27, $numero . '  ·  Rev. ' . (int)($pedido['revision'] ?? 0), 12, true);
        $pdf->colorText(190, $y - 13, 'CLIENTE', 7, true, 93, 111, 123);
        $pdf->text(190, $y - 27, $cliente !== '' ? $cliente : '-', 10, true);
        $pdf->colorText(390, $y - 13, 'FECHA', 7, true, 93, 111, 123);
        $pdf->text(390, $y - 27, $fecha, 10, true);
        $pdf->colorText(50, $y - 45, 'REFERENCIA', 7, true, 93, 111, 123);
        $pdf->text(112, $y - 45, pdfTextoMinusculas($referencia !== '' ? $referencia : '-'), 8);
        $pdf->colorText(330, $y - 45, 'ORIGEN', 7, true, 93, 111, 123);
        $pdf->text(374, $y - 45, pdfTextoMinusculas($origen), 8);
        $sigla = strtoupper(trim((string)($pedido['clientes_codigo'] ?? '')));
        $pdf->colorText(50, $y - 64, 'SIGLA', 7, true, 93, 111, 123);
        $pdf->text(88, $y - 64, $sigla !== '' ? $sigla : '-', 8, true);
        $pdf->colorText(190, $y - 64, 'RESPONSABLE COMERCIAL', 7, true, 93, 111, 123);
        $pdf->text(320, $y - 64, pdfTextoMinusculas(trim((string)($pedido['responsable_comercial'] ?? '')) ?: '-'), 8);
        $y -= 100;

        $pdf->fillColorRect(38, $y - 22, 519, 24, 235, 243, 253);
        $pdf->fillColorRect(38, $y - 22, 5, 24, 15, 123, 77);
        $pdf->colorText(50, $y - 14, 'MATERIALES A PREPARAR', 10, true, 25, 86, 158);
        $pdf->colorText(430, $y - 14, 'PICKING / CONTROL', 7, true, 89, 107, 119);
        $y -= 30;

        // Encabezado de tabla con numeracion de renglones y casillas amplias.
        $pdf->fillColorRect(38, $y-24, 519, 24, 30, 51, 66);
        $pdf->colorText(45, $y-15, '#', 8, true, 255,255,255);
        $pdf->colorText(68, $y-15, 'Cant.', 8, true, 255,255,255);
        $pdf->colorText(112, $y-15, 'Codigo', 8, true, 255,255,255);
        $pdf->colorText(205, $y-15, 'Descripcion', 8, true, 255,255,255);
        $pdf->colorText(452, $y-15, 'Prep.', 8, true, 255,255,255);
        $pdf->colorText(505, $y-15, 'Ctrl.', 8, true, 255,255,255);
        $y -= 24;
        return $y;
    };

    $y = $dibujarCabecera(false);
    $item = 0;
    foreach ($detalles as $detalle) {
        $cantidad = (float)($detalle['cantidad'] ?? 0);
        if ($cantidad <= 0) continue;
        $codigo = trim((string)($detalle['codigo'] ?? ''));
        $descripcion = trim((string)($detalle['descripcion'] ?? ''));
        if ($descripcion === '') $descripcion = trim((string)($detalle['concepto'] ?? ''));
        if ($descripcion === '') continue;
        $item++;

        $lineas = $pdf->wrap(pdfTextoMinusculas($descripcion), 232, 8);
        $rowH = max(28, count($lineas) * 10 + 12);
        if ($y - $rowH < 190) {
            $pdf->colorText(470, 28, 'Pagina ' . $pagina, 7, true, 95,111,122);
            $pdf->newPage(); $pagina++; $y = $dibujarCabecera(true);
        }

        $x=38;
        if ($item % 2 === 0) $pdf->fillColorRect($x, $y-$rowH, 519, $rowH, 249, 251, 252);
        $pdf->rect($x,$y-$rowH,519,$rowH);
        foreach (array(25,68,158,405,458) as $offset) $pdf->line($x+$offset,$y-$rowH,$x+$offset,$y,0.25);
        $pdf->colorText($x+8,$y-18,(string)$item,8,true,91,108,119);
        $pdf->text($x+34,$y-18,ofCantidad($cantidad),10,true);
        $pdf->text($x+76,$y-18,$codigo!==''?$codigo:'-',8,true);
        foreach($lineas as $i=>$ln)$pdf->text($x+166,$y-17-($i*10),$ln,8);
        $pdf->rect($x+424,$y-22,15,15);
        $pdf->rect($x+477,$y-22,15,15);
        $y-=$rowH;
    }

    if ($item===0) {
        $pdf->fillColorRect(38,$y-38,519,38,249,251,252); $pdf->rect(38,$y-38,519,38);
        $pdf->text(50,$y-23,'Sin renglones de materiales para preparar.',9,true); $y-=38;
    }

    // Si el checklist no entra completo, pasa a nueva pagina para no cortar firmas.
    if ($y < 330) { $pdf->colorText(470,28,'Pagina '.$pagina,7,true,95,111,122); $pdf->newPage(); $pagina++; $y=$dibujarCabecera(true); }
    $y-=18;
    $pdf->fillColorRect(38,$y-22,519,24,234,246,239); $pdf->fillColorRect(38,$y-22,5,24,17,122,75);
    $pdf->colorText(50,$y-14,'CONTROL FINAL DE PREPARACION Y DESPACHO',10,true,13,99,61); $y-=34;

    $controles=array('Material completo segun pedido','Cantidades verificadas','Codigos / modelos verificados','Embalaje realizado','Documentacion / remito preparado','Pedido listo para despacho');
    for($i=0;$i<count($controles);$i+=2){
        foreach(array(0,1) as $j){$idx=$i+$j;if(!isset($controles[$idx]))continue;$x=$j===0?38:302;$pdf->rect($x,$y-24,255,24);$pdf->rect($x+9,$y-18,13,13);$pdf->text($x+31,$y-14,$controles[$idx],8);}
        $y-=24;
    }
    $y-=12; $pdf->text(38,$y,'OBSERVACIONES',8,true);
    for($i=0;$i<3;$i++)$pdf->line(38,$y-10-($i*16),557,$y-10-($i*16),0.3);

    $pdf->line(38,79,557,79,0.6);
    $responsableComercial=trim((string)($pedido['responsable_comercial']??''));
    $pdf->colorText(42,63,'Responsable comercial',7,true,95,111,122); $pdf->text(42,51,pdfTextoMinusculas($responsableComercial!==''?$responsableComercial:'____________________'),8,true);
    $pdf->colorText(215,63,'Preparado por',7,true,95,111,122); $pdf->text(215,51,'____________________',8);
    $pdf->colorText(365,63,'Controlado por',7,true,95,111,122); $pdf->text(365,51,'____________________',8);
    $pdf->colorText(485,63,'Fecha',7,true,95,111,122); $pdf->text(485,51,'__/__/____',8);
    $pdf->colorText(470,28,'Pagina '.$pagina,7,true,95,111,122);
}



/**
 * OF conjunta de Pulsadores, Senalizacion y Accesorios.
 * Comparte pedido_id y numero de pedido con la OF de Control.
 * Los limites c/soporte se excluyen porque forman parte de Material de Hueco.
 */


/**
 * v366 - Material fisico de hueco para indicadores autonomos/electromecanicos.
 * APPIND ya se valoriza en senalizacion_cabina.php. Esta funcion describe el
 * material que Produccion debe preparar en la OF de Senalizacion.
 */
function ofExpandirAppindComoMaterialHueco(mysqli $conexion, array $detalles): array
{
    $cantAppind = 0.0;
    $cantMaestrosA4000 = 0.0;
    foreach ($detalles as $d) {
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        $concepto = ofNormalizar((string)($d['concepto'] ?? ''));
        $descripcion = ofNormalizar((string)($d['descripcion'] ?? ''));
        $txt = $concepto.' '.$descripcion;
        $cantidad = max(0.0,(float)($d['cantidad'] ?? 0));
        if ($codigo === 'APPIND' || strpos($txt, 'ADICIONAL POR PARADA PARA INDICADOR ELECTROMECANICO') !== false || strpos($txt, 'ADICIONAL POR PARADA EN INDICADOR') !== false) {
            $cantAppind += $cantidad;
            continue;
        }

        /* v398: el juego fisico de material de hueco pertenece al MAESTRO
         * Base A4000, no a todos los indicadores del sistema. Un repetidor
         * Base A4400 nunca suma transformador, iman largo, cabezal ni
         * instructivo. Reconocemos el maestro por la descripcion/rol y, como
         * respaldo para registros anteriores, por los codigos autonomos A40xx. */
        $esIndicador = strpos($txt,'INDICADOR') !== false;
        $esMaestro = strpos($txt,'BASE A4000') !== false
            || strpos($txt,'MAESTRO A4000') !== false
            || (strpos($txt,'MAESTRO') !== false && strpos($txt,'A4000') !== false)
            || ($esIndicador && preg_match('/^A40[0-9A-Z]*/',$codigo));
        $esRepetidor = strpos($txt,'BASE A4400') !== false
            || strpos($txt,'REPETIDOR A4400') !== false
            || (strpos($txt,'REPETIDOR') !== false && strpos($txt,'A4400') !== false);
        if ($esMaestro && !$esRepetidor && $cantidad > 0) $cantMaestrosA4000 += $cantidad;
    }
    if ($cantAppind <= 0) return $detalles;
    if ($cantMaestrosA4000 <= 0) $cantMaestrosA4000 = 1.0;

    $salida = array_values(array_filter($detalles, static function($d){
        $codigo = strtoupper(trim((string)($d['codigo'] ?? '')));
        $txt = ofNormalizar((string)($d['concepto'] ?? '').' '.(string)($d['descripcion'] ?? ''));
        return !($codigo === 'APPIND' || strpos($txt, 'ADICIONAL POR PARADA PARA INDICADOR ELECTROMECANICO') !== false || strpos($txt, 'ADICIONAL POR PARADA EN INDICADOR') !== false);
    }));

    foreach (mhiaMaterialesActivos($conexion) as $m) {
        $base=max(0.0,(float)($m['cantidad_base']??1));
        $regla=strtoupper(trim((string)($m['regla_cantidad']??'POR_INDICADOR')));
        $cantidad = $regla === 'POR_PARADA_APPIND' ? $cantAppind*$base : $cantMaestrosA4000*$base;
        if($cantidad<=0) continue;
        $salida[] = array(
            'concepto'=>'Material de hueco · '.(string)$m['material_descripcion'],
            'codigo'=>(string)$m['material_codigo'],
            'descripcion'=>(string)$m['material_descripcion'],
            'cantidad'=>$cantidad,
            'formula_aplicada'=>$regla === 'POR_PARADA_APPIND' ? 'APPIND × cantidad base de matriz' : 'Maestros Base A4000 × cantidad base de matriz',
            'modulo'=>'SENALIZACION'
        );
    }
    return $salida;
}

function ofMaterialHuecoIndicadorAutonomo(mysqli $conexion, array $datosFormulario): array
{
    $modelo = trim((string)($datosFormulario['senal_indicador_modelo'] ?? ''));
    $cantIndic = max(0, (int)($datosFormulario['senal_indicador_cantidad'] ?? 0));
    if ($modelo === '' || $cantIndic <= 0) return array();

    $esElectronico = null;
    if (array_key_exists('senal_tiene_control', $datosFormulario)) {
        $esElectronico = ((string)$datosFormulario['senal_tiene_control'] === '1');
    }
    if ($esElectronico === null && !empty($datosFormulario['senal_tipo_modulo'])) {
        $idTipo=(int)$datosFormulario['senal_tipo_modulo'];
        $st=$conexion->prepare("SELECT tipo_modulo_nombre FROM senal_tipos_modulo WHERE tipo_modulo_id=? LIMIT 1");
        if($st){$st->bind_param('i',$idTipo);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();$nom=ofNormalizar((string)($f['tipo_modulo_nombre']??''));$esElectronico=strpos($nom,'ELECTROMEC')===false && strpos($nom,'ELECTRON')!==false;}
    }
    if ($esElectronico !== false) return array();

    $cantBot = max(1, (int)($datosFormulario['senal_cantidad'] ?? $cantIndic));
    $paradas = max(0, (int)($datosFormulario['senal_paradas'] ?? 0));
    $porBot = $datosFormulario['senal_paradas_equipo'] ?? array();
    if(!is_array($porBot)) $porBot=array($porBot);
    $porBot=array_values(array_map('intval',$porBot));
    if(!$porBot && $paradas>0) $porBot=array_fill(0,$cantBot,$paradas);
    while(count($porBot)<$cantBot) $porBot[]=$paradas;
    $cantImanesCortos=0;
    if($cantIndic===$cantBot) $cantImanesCortos=array_sum(array_slice($porBot,0,$cantBot));
    else $cantImanesCortos=$paradas*$cantIndic;

    return array(
        array('concepto'=>'Material de hueco · Transformador','codigo'=>'','descripcion'=>'Transformador 220/12V para indicador autónomo','cantidad'=>$cantIndic,'formula_aplicada'=>'1 por indicador/equipo autónomo','modulo'=>'SENALIZACION'),
        array('concepto'=>'Material de hueco · Imanes cortos 5cm','codigo'=>'APPIND','descripcion'=>'Imanes cortos 5 cm · uno por parada','cantidad'=>$cantImanesCortos,'formula_aplicada'=>'1 por parada · valorizado mediante APPIND según modelo','modulo'=>'SENALIZACION'),
        array('concepto'=>'Material de hueco · Imán largo 25cm','codigo'=>'','descripcion'=>'Imán largo 25 cm','cantidad'=>$cantIndic,'formula_aplicada'=>'1 por indicador/equipo autónomo','modulo'=>'SENALIZACION'),
        array('concepto'=>'Material de hueco · Cabezal magnético','codigo'=>'A2142C','descripcion'=>'A2142C Cabezal Magnético · conjunto de 2 cabezales magnéticos + 1 soporte','cantidad'=>$cantIndic,'formula_aplicada'=>'1 conjunto por indicador/equipo autónomo','modulo'=>'SENALIZACION'),
        array('concepto'=>'Material de hueco · Instructivo','codigo'=>'','descripcion'=>'Instructivo de instalación del indicador autónomo / material de hueco','cantidad'=>$cantIndic,'formula_aplicada'=>'1 por indicador/equipo autónomo','modulo'=>'SENALIZACION'),
    );
}

function ofDibujarOrdenSenalizacionAccesorios(PdfAutomac $pdf, mysqli $conexion, array $pedido, array $detalles, array $datosFormulario): void
{
    $detalles = agruparBotoneraCabinaPresentacion($detalles,$datosFormulario);
    // v366: APPIND comercial ya existe en el pedido. En la OF reemplazamos ese
    // renglon abstracto por el material fisico que debe preparar Produccion.
    // v371: APPIND guardado en el pedido es la fuente de verdad para la OF.
    // Si existe, se traduce siempre a material fisico de hueco, sin depender
    // de nombres visibles ni de campos auxiliares del formulario.
    $antesAppind=count($detalles);
    $tieneAppind=false;
    foreach($detalles as $d){
        if(strtoupper(trim((string)($d['codigo']??'')))==='APPIND'){$tieneAppind=true;break;}
    }
    if($tieneAppind){
        $detalles=ofExpandirAppindComoMaterialHueco($conexion,$detalles);
    }else{
        $materialAutonomo=ofMaterialHuecoIndicadorAutonomo($conexion,$datosFormulario);
        if($materialAutonomo){
            foreach($materialAutonomo as $mh)$detalles[]=$mh;
        }
    }
    $modelo = function_exists('pdfConsultaNombre') ? pdfConsultaNombre($conexion, 'senal_modelos_pulsador', 'modelo_pulsador_id', 'modelo_pulsador_nombre', (int)($datosFormulario['senal_modelo'] ?? 0)) : '';
    $color = function_exists('pdfConsultaNombre') ? pdfConsultaNombre($conexion, 'senal_colores_registro', 'color_registro_id', 'color_registro_nombre', (int)($datosFormulario['senal_color'] ?? 0)) : '';
    $tecla = function_exists('pdfConsultaNombre') ? pdfConsultaNombre($conexion, 'senal_teclas', 'tecla_id', 'tecla_nombre', (int)($datosFormulario['senal_tecla'] ?? 0)) : '';
    $paradas = max(0, (int)($datosFormulario['senal_paradas'] ?? 0));
    $incluyeBotoneraCabina=!array_key_exists('senal_incluir_botonera_cabina',$datosFormulario) || !empty($datosFormulario['senal_incluir_botonera_cabina']);
    $cantidadBotoneras = $incluyeBotoneraCabina ? max(1, (int)($datosFormulario['senal_cantidad'] ?? 1)) : 0;
    $paradasPorBotonera=$datosFormulario['senal_paradas_equipo']??array();
    if(!is_array($paradasPorBotonera))$paradasPorBotonera=array($paradasPorBotonera);
    $paradasPorBotonera=array_values(array_map('intval',$paradasPorBotonera));
    if(!$paradasPorBotonera && $paradas>0)$paradasPorBotonera=array_fill(0,$cantidadBotoneras,$paradas);
    while(count($paradasPorBotonera)<$cantidadBotoneras)$paradasPorBotonera[]=$paradas;
    $paradasPorBotonera=array_slice($paradasPorBotonera,0,$cantidadBotoneras);
    $nomenclaturasPorBotonera=$datosFormulario['senal_nomenclatura_equipo']??($datosFormulario['nomenclatura_equipo']??array());
    if(!is_array($nomenclaturasPorBotonera))$nomenclaturasPorBotonera=array($nomenclaturasPorBotonera);
    $nomenclaturasPorBotonera=array_values(array_map(static function($v){return trim((string)$v);},$nomenclaturasPorBotonera));
    while(count($nomenclaturasPorBotonera)<$cantidadBotoneras)$nomenclaturasPorBotonera[]='';
    $nomenclaturasPorBotonera=array_slice($nomenclaturasPorBotonera,0,$cantidadBotoneras);
    $medidasPorBotonera=$datosFormulario['senal_medidas_equipo']??array();
    if(!is_array($medidasPorBotonera))$medidasPorBotonera=array($medidasPorBotonera);
    $medidasPorBotonera=array_values(array_map(static function($v){return trim((string)$v);},$medidasPorBotonera));
    $medidaLegacy=trim((string)($datosFormulario['senal_medidas']??''));
    if(!$medidasPorBotonera && $medidaLegacy!=='')$medidasPorBotonera=array_fill(0,$cantidadBotoneras,$medidaLegacy);
    while(count($medidasPorBotonera)<$cantidadBotoneras)$medidasPorBotonera[]='';
    $medidasPorBotonera=array_slice($medidasPorBotonera,0,$cantidadBotoneras);
    $acabado = trim((string)($datosFormulario['senal_acabado'] ?? ''));

    // Producción necesita una línea inequívoca por coche: misma cantidad de paradas
    // no implica misma nomenclatura. En baterías, nunca agrupar las botoneras.
    if($cantidadBotoneras>1){
        $detalleExpandido=array();
        foreach($detalles as $d){
            if(!empty($d['_botonera_agrupada'])){
                for($i=0;$i<$cantidadBotoneras;$i++){
                    $copia=$d; $copia['cantidad']=1;
                    $p=(int)($paradasPorBotonera[$i]??0);
                    $nom=trim((string)($nomenclaturasPorBotonera[$i]??'')); if($nom==='')$nom='A CONFIRMAR';
                    $med=trim((string)($medidasPorBotonera[$i]??''));
                    $baseDesc=trim((string)($d['descripcion']??'Botonera de cabina'));
                    $baseDesc=preg_replace('/\s*·\s*Coche\s+1.*$/ui','',$baseDesc)?:$baseDesc;
                    $copia['descripcion']='Coche '.($i+1).' - '.$p.' paradas - Nomenclatura: '.$nom.($med!==''?' - Medida: '.$med:'').' - '.$baseDesc;
                    $copia['formula_aplicada']='Botonera individual del coche '.($i+1).' · '.$p.' paradas · '.$nom.($med!==''?' · medida '.$med:'');
                    $detalleExpandido[]=$copia;
                }
            } else $detalleExpandido[]=$d;
        }
        $detalles=$detalleExpandido;
    } elseif($cantidadBotoneras===1) {
        foreach($detalles as &$d){
            if(empty($d['_botonera_agrupada']))continue;
            $nom=trim((string)($nomenclaturasPorBotonera[0]??'')); if($nom==='')$nom='A CONFIRMAR';
            $p=(int)($paradasPorBotonera[0]??$paradas);
            $med=trim((string)($medidasPorBotonera[0]??''));
            $d['descripcion']='Coche 1 - '.$p.' paradas - Nomenclatura: '.$nom.($med!==''?' - Medida: '.$med:'').' - '.trim((string)($d['descripcion']??'Botonera de cabina'));
        }
        unset($d);
    }

    $grupos = array(
        'BOTONERA DE CABINA / SENALIZACION' => array(),
        'PULSADORES EXTERIORES' => array(),
        'INDICADORES, GONG Y FLECHAS' => array(),
        'MATERIAL DE HUECO · INDICADOR AUTONOMO' => array(),
        'ACCESORIOS' => array(),
        'REPUESTOS' => array(),
    );

    foreach ($detalles as $d) {
        if ((float)($d['cantidad'] ?? 0) <= 0) continue;
        $modulo = oftModuloDetalle($d);
        if ($modulo === 'ACCESORIOS') {
            if (!oftEsLimite($d)) $grupos['ACCESORIOS'][] = $d;
            continue;
        }
        if ($modulo === 'REPUESTOS') {
            $grupos['REPUESTOS'][] = $d;
            continue;
        }
        if ($modulo !== 'SENALIZACION') continue;
        $texto = oftNormalizar((string)($d['concepto'] ?? '') . ' ' . (string)($d['descripcion'] ?? ''));
        if (!empty($d['_botonera_agrupada'])) {
            $grupos['BOTONERA DE CABINA / SENALIZACION'][] = $d;
        } elseif (strpos($texto, 'PULSADOR EXTERIOR') !== false || (strpos($texto, 'PULSADOR') !== false && (strpos($texto, 'EXTER') !== false || strpos($texto, 'PISO') !== false))) {
            $grupos['PULSADORES EXTERIORES'][] = $d;
        } elseif (strpos($texto, 'MATERIAL DE HUECO') !== false) {
            $grupos['MATERIAL DE HUECO · INDICADOR AUTONOMO'][] = $d;
        } elseif (strpos($texto, 'INDICADOR') !== false || strpos($texto, 'GONG') !== false || strpos($texto, 'FLECHA') !== false) {
            $grupos['INDICADORES, GONG Y FLECHAS'][] = $d;
        } else {
            $grupos['BOTONERA DE CABINA / SENALIZACION'][] = $d;
        }
    }

    $pagina = 1;
    $cabecera = function(bool $continuacion=false) use ($pdf,$pedido,$modelo,$color,$tecla,$paradas,$paradasPorBotonera,$nomenclaturasPorBotonera,$medidasPorBotonera,$cantidadBotoneras,$acabado,&$pagina): float {
        $y = ofTituloPagina($pdf, 'ORDEN DE FABRICACION - SENALIZACION, ACCESORIOS Y REPUESTOS', $continuacion ? 'Continuacion - mismo pedido' : 'Pulsadores · Senalizacion · Accesorios · Repuestos');
        ofCabeceraDocumento($pdf, $y, $pedido);

        $pdf->fillColorRect(38, $y-22, 519, 24, 255, 245, 228);
        $pdf->fillColorRect(38, $y-22, 5, 24, 193, 116, 0);
        $pdf->colorText(50, $y-14, 'CONFIGURACION DE SENALIZACION', 10, true, 136, 80, 0);
        $y -= 29;

        $partesParadas=array(); foreach($paradasPorBotonera as $i=>$p){if((int)$p>0){$nom=trim((string)($nomenclaturasPorBotonera[$i]??''));if($nom==='')$nom='A CONFIRMAR';$med=trim((string)($medidasPorBotonera[$i]??''));$partesParadas[]='Coche '.($i+1).': '.(int)$p.' paradas ['.$nom.']'.($med!==''?' · medida '.$med:'');}}
        $textoParadas=$partesParadas?implode(' / ',$partesParadas):($paradas>0?$paradas.' paradas [A CONFIRMAR]':'');
        $config = array_filter(array(
            $cantidadBotoneras>0 ? $cantidadBotoneras . ' botonera(s)' : 'Sin botonera de cabina',
            $textoParadas,
            $modelo !== '' ? 'Modelo ' . $modelo : '',
            $color !== '' ? 'Color ' . $color : '',
            $tecla !== '' ? 'Tecla ' . $tecla : '',
            $acabado !== '' ? 'Acabado ' . $acabado : '',
        ));
        $textoConfig = implode(' · ', $config);
        $pdf->rect(38, $y-33, 519, 33);
        $yConfig = $y - 10;
        $pdf->paragraph(47, $yConfig, pdfTextoMinusculas($textoConfig !== '' ? $textoConfig : 'Configuracion segun pedido confirmado.'), 500, 8, 10, false);
        $y -= 43;
        return $y;
    };

    $y = $cabecera(false);
    $itemGlobal = 0;
    $imprimirEncabezado = function(string $titulo) use ($pdf,&$y): void {
        $pdf->fillColorRect(38, $y-21, 519, 22, 241, 244, 246);
        $pdf->fillColorRect(38, $y-21, 4, 22, 193, 116, 0);
        $pdf->colorText(49, $y-13, $titulo, 9, true, 70, 82, 90);
        $y -= 26;
        $pdf->fillColorRect(38, $y-22, 519, 22, 31, 55, 70);
        $pdf->colorText(45,$y-14,'#',7,true,255,255,255);
        $pdf->colorText(69,$y-14,'Cant.',7,true,255,255,255);
        $pdf->colorText(112,$y-14,'Codigo',7,true,255,255,255);
        $pdf->colorText(205,$y-14,'Descripcion / especificacion',7,true,255,255,255);
        $pdf->colorText(510,$y-14,'Verif.',7,true,255,255,255);
        $y -= 22;
    };

    foreach ($grupos as $titulo=>$items) {
        if (!$items) continue;
        if ($y < 180) { $pdf->colorText(472,28,'Pagina '.$pagina,7,true,95,111,122); $pdf->newPage(); $pagina++; $y=$cabecera(true); }
        $imprimirEncabezado($titulo);
        foreach ($items as $d) {
            $desc = trim((string)($d['descripcion'] ?? ''));
            if ($desc === '') $desc = trim((string)($d['concepto'] ?? ''));
            $concepto = trim((string)($d['concepto'] ?? ''));
            $moduloFila = oftModuloDetalle($d);
            if ($moduloFila === 'REPUESTOS') {
                // La OF es un documento de Produccion: no mostrar etiquetas comerciales
                // como "Repuesto" ni el descuento aplicado al cliente.
                $desc = preg_replace('/^\s*repuesto\s*[-:]*\s*(?:descuento\s*\d+(?:[.,]\d+)?\s*%?\s*[-:]*\s*)?/iu', '', $desc) ?? $desc;
                $desc = preg_replace('/^\s*descuento\s*\d+(?:[.,]\d+)?\s*%?\s*[-:]*\s*/iu', '', $desc) ?? $desc;
                $desc = trim($desc);
                if ($desc === '') {
                    $desc = trim((string)($d['descripcion'] ?? ''));
                }
            } elseif (strpos(oftNormalizar($titulo), 'MATERIAL DE HUECO') !== false) {
                // v374: en Material de hueco mostrar solamente la descripcion fisica
                // definida en la matriz. Evita textos repetidos como
                // "Material de hueco · Transformador: Transformador 220/12V".
                $desc = trim((string)($d['descripcion'] ?? $concepto));
            } elseif ($concepto !== '' && oftNormalizar($concepto) !== oftNormalizar($desc)) {
                $desc = $concepto . ': ' . $desc;
            }
            $lineas = $pdf->wrap(pdfTextoMinusculas($desc), 292, 8);
            $rowH = max(25, count($lineas)*10 + 10);
            if ($y-$rowH < 115) {
                $pdf->colorText(472,28,'Pagina '.$pagina,7,true,95,111,122); $pdf->newPage(); $pagina++; $y=$cabecera(true); $imprimirEncabezado($titulo.' (CONT.)');
            }
            $itemGlobal++;
            if ($itemGlobal % 2 === 0) $pdf->fillColorRect(38,$y-$rowH,519,$rowH,250,251,252);
            $pdf->rect(38,$y-$rowH,519,$rowH);
            foreach(array(25,68,158,472) as $off)$pdf->line(38+$off,$y-$rowH,38+$off,$y,0.25);
            $pdf->colorText(46,$y-17,(string)$itemGlobal,8,true,95,111,122);
            $pdf->text(72,$y-17,ofCantidad((float)$d['cantidad']),9,true);
            $pdf->text(112,$y-17,trim((string)($d['codigo']??''))!==''?(string)$d['codigo']:'-',7,true);
            foreach($lineas as $i=>$ln)$pdf->text(202,$y-16-($i*10),$ln,8);
            $pdf->rect(525,$y-20,14,14);
            $y -= $rowH;
        }
        $y -= 10;
    }

    if ($itemGlobal === 0) {
        $pdf->rect(38,$y-42,519,42); $pdf->text(50,$y-25,'Sin renglones de senalizacion, accesorios o repuestos para esta orden.',9,true); $y-=50;
    }

    if ($y < 210) { $pdf->colorText(472,28,'Pagina '.$pagina,7,true,95,111,122); $pdf->newPage(); $pagina++; $y=$cabecera(true); }
    $pdf->fillColorRect(38,$y-21,519,22,241,244,246); $pdf->colorText(50,$y-13,'NOTAS / PREPARACION',9,true,70,82,90); $y-=27;
    for($i=0;$i<4;$i++){$pdf->line(38,$y-($i*16),557,$y-($i*16),0.3);} $y-=74;
    $pdf->roundedRect(38,$y-54,250,54,5); $pdf->roundedRect(307,$y-54,250,54,5);
    $pdf->text(77,$y-15,'CANTIDAD TOTAL DE BULTOS',8,true); $pdf->text(365,$y-15,'UBICACION EN DEPOSITO',8,true);
    $pdf->line(38,79,557,79,0.6);
    $resp=trim((string)($pedido['responsable_comercial']??''));
    $pdf->colorText(42,63,'Responsable comercial',7,true,95,111,122); $pdf->text(42,51,pdfTextoMinusculas($resp!==''?$resp:'____________________'),8,true);
    $pdf->colorText(215,63,'Armado / preparado por',7,true,95,111,122); $pdf->text(215,51,'____________________',8);
    $pdf->colorText(405,63,'Revision',7,true,95,111,122); $pdf->text(405,51,'____________________',8);
    $pdf->colorText(472,28,'Pagina '.$pagina,7,true,95,111,122);
}
