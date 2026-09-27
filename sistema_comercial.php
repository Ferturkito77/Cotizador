<?php
/* v38 - Pedido en una linea + Botonera comercial agrupada (base + paradas + indicador). */
function asegurarSistemaComercial($conexion) {
    require_once __DIR__ . '/schema_guard.php';
    $requeridas = array(
        'numeracion_documentos' => array('numeracion_serie','numeracion_ultimo'),
        'cotizaciones' => array('cotizacion_id','cotizacion_numero','cotizacion_tipo','cliente_id','lista_id','estado','anulacion_fecha','anulacion_motivo','anulacion_usuario_id','datos_formulario','usuario_id','pdf_archivo','pdf_fecha'),
        'cotizaciones_detalle' => array('detalle_id','cotizacion_id','orden_visual','modulo','concepto','codigo','descripcion','cantidad','precio_unitario','importe_total'),
        'pedidos' => array('pedido_id','pedido_numero','pedido_tipo','cotizacion_id','cliente_id','lista_id','origen','referencia','revision','estado','anulacion_fecha','anulacion_motivo','anulacion_usuario_id','datos_formulario','usuario_id','pdf_archivo','pdf_fecha'),
        'pedidos_detalle' => array('pedido_detalle_id','pedido_id','orden_visual','modulo','concepto','codigo','descripcion','cantidad','precio_unitario','importe_total'),
        'equivalencias_codigos' => array('equivalencia_id','codigo_origen','codigo_destino','equivalencia_activa'),
        'cotizaciones_revisiones' => array('revision_id','cotizacion_id','revision','cliente_id','lista_id'),
        'cotizaciones_revisiones_detalle' => array('revision_detalle_id','revision_id','modulo'),
        'pedidos_revisiones' => array('revision_id','pedido_id','revision','cliente_id','lista_id','referencia'),
        'listas_precios_importaciones' => array('lista_id','lista_estado','lista_anulada_motivo','lista_fecha_estado')
    );
    try {
        foreach ($requeridas as $tabla => $columnas) {
            verificarTablaColumnas($conexion, $tabla, $columnas, 'migracion_anulaciones_documentos_v411.sql');
        }
    } catch (Throwable $e) {
        die('El sistema comercial requiere actualizar la base de datos: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
    }

    /* Datos base: DML permitido. La estructura vive exclusivamente en SQL. */
    $conexion->query("INSERT IGNORE INTO numeracion_documentos(numeracion_serie,numeracion_ultimo) VALUES ('COTIZACION_CONTROL',0),('COTIZACION_SUMINISTROS',0),('PEDIDO_OBRA',0),('PEDIDO_SUMINISTROS',0)");
}

function obtenerListaVigente($conexion) {
    // v233: cache por request; la lista vigente no cambia durante una misma petición.
    static $cache = array();
    $k = spl_object_id($conexion);
    if (array_key_exists($k, $cache)) return $cache[$k];
    $r=$conexion->query("SELECT * FROM listas_precios_importaciones WHERE COALESCE(lista_estado,'VIGENTE')='VIGENTE' ORDER BY lista_vigente_desde DESC, lista_id DESC LIMIT 1");
    return $cache[$k] = ($r ? $r->fetch_assoc() : null);
}
function obtenerListasBejermanDisponibles($conexion): array {
    $filas=array();
    $r=$conexion->query("SELECT * FROM listas_precios_importaciones WHERE COALESCE(lista_estado,'VIGENTE')<>'ANULADA' ORDER BY CASE WHEN COALESCE(lista_estado,'VIGENTE')='VIGENTE' THEN 0 ELSE 1 END, lista_vigente_desde DESC, lista_id DESC");
    if ($r) while($x=$r->fetch_assoc()) $filas[]=$x;
    return $filas;
}
function obtenerListaSeleccionada($conexion, $listaId=0) {
    /*
     * Para documentos nuevos se utiliza la última base Bejerman vigente.
     * Al modificar un documento histórico se permite conservar la base con la
     * que fue emitido, siempre que no haya sido anulada.
     */
    $listaId=(int)$listaId;
    if ($listaId>0) {
        $st=$conexion->prepare("SELECT * FROM listas_precios_importaciones WHERE lista_id=? AND COALESCE(lista_estado,'VIGENTE')<>'ANULADA' LIMIT 1");
        if (!$st) return null;
        $st->bind_param('i',$listaId); $st->execute(); $fila=$st->get_result()->fetch_assoc(); $st->close();
        return $fila ?: null;
    }
    return obtenerListaVigente($conexion);
}
function descripcionBaseBejerman($lista): string {
    if (!$lista) return 'Base Bejerman no disponible';
    $nombre=trim((string)($lista['lista_nombre']??''));
    if ($nombre==='') $nombre='Bejerman';
    $fecha=trim((string)($lista['lista_fecha_archivo']??$lista['lista_vigente_desde']??''));
    $estado=strtoupper(trim((string)($lista['lista_estado']??'VIGENTE')));
    return $nombre.' · actualización #'.(int)$lista['lista_id'].($fecha!==''?' · '.$fecha:'').($estado!=='VIGENTE'?' · '.$estado:'');
}
function modeloVariadorDeLista($conexion, $listaId=0) {
    /*
     * Determina el modelo usando la base Bejerman realmente seleccionada.
     * Para documentos históricos consulta su historial, evitando mezclarlo con
     * la lista vigente al cargar los subtipos.
     */
    $listaId=(int)$listaId;
    $vigente=obtenerListaVigente($conexion);
    $vigenteId=(int)($vigente['lista_id']??0);

    if ($listaId>0 && $listaId!==$vigenteId) {
        $stmt=$conexion->prepare("SELECT
            SUM(CASE WHEN UPPER(COALESCE(precios_descripcion,'')) LIKE '%GD390%' OR UPPER(COALESCE(precios_codigo,'')) REGEXP 'GD39' THEN 1 ELSE 0 END) AS cantidad_gd390,
            SUM(CASE WHEN UPPER(COALESCE(precios_descripcion,'')) LIKE '%GD300%' THEN 1 ELSE 0 END) AS cantidad_gd300
            FROM listas_precios_historial
            WHERE lista_id=? AND COALESCE(precios_costo,0) > 0");
        if ($stmt) {
            $stmt->bind_param('i',$listaId);
            $stmt->execute();
            $fila=$stmt->get_result()->fetch_assoc();
            $stmt->close();
        } else {
            $fila=array();
        }
    } else {
        $r=$conexion->query("SELECT
            SUM(CASE WHEN UPPER(COALESCE(precios_descripcion,'')) LIKE '%GD390%' OR UPPER(COALESCE(precios_codigo,'')) REGEXP 'GD39' THEN 1 ELSE 0 END) AS cantidad_gd390,
            SUM(CASE WHEN UPPER(COALESCE(precios_descripcion,'')) LIKE '%GD300%' THEN 1 ELSE 0 END) AS cantidad_gd300
            FROM lista_precios
            WHERE COALESCE(precios_costo,0) > 0");
        $fila=$r ? $r->fetch_assoc() : array();
    }

    $gd390=(int)($fila['cantidad_gd390'] ?? 0);
    $gd300=(int)($fila['cantidad_gd300'] ?? 0);
    if ($gd390 > 0 && $gd390 >= $gd300) return 'GD390';
    return $gd300 > 0 ? 'GD300' : 'GD390';
}
function precioExactoDeLista($conexion,$listaId,$codigo) {
    // v233: un mismo código se consulta varias veces al renderizar Control/Accesorios.
    // Cachear por conexión + lista + código evita round-trips repetidos sin cambiar reglas ni históricos.
    static $cache = array();
    $listaId=(int)$listaId;
    $codigo=trim((string)$codigo);
    $k=spl_object_id($conexion).'|'.$listaId.'|'.strtoupper($codigo);
    if (array_key_exists($k,$cache)) return $cache[$k];
    $vigente=obtenerListaVigente($conexion);
    $vigenteId=(int)($vigente['lista_id']??0);
    if ($listaId>0 && $listaId!==$vigenteId) {
        $stmt=$conexion->prepare("SELECT precios_codigo, precios_descripcion, precios_costo, precios_clasificacion FROM listas_precios_historial WHERE lista_id=? AND TRIM(precios_codigo)=TRIM(?) AND COALESCE(precios_costo,0)>0 LIMIT 1");
        if (!$stmt) return $cache[$k]=null;
        $stmt->bind_param('is',$listaId,$codigo);
    } else {
        $stmt=$conexion->prepare("SELECT precios_codigo, precios_descripcion, precios_costo, precios_clasificacion FROM lista_precios WHERE TRIM(precios_codigo)=TRIM(?) AND COALESCE(precios_costo,0)>0 LIMIT 1");
        if (!$stmt) return $cache[$k]=null;
        $stmt->bind_param('s',$codigo);
    }
    $stmt->execute(); $fila=$stmt->get_result()->fetch_assoc(); $stmt->close(); return $cache[$k]=($fila?:null);
}
function candidatosEquivalentesCodigo($conexion,$codigo) {
    static $cache = array();
    $codigo=trim((string)$codigo);
    $k=spl_object_id($conexion).'|'.strtoupper($codigo);
    if (array_key_exists($k,$cache)) return $cache[$k];
    $candidatos=array();
    $stmt=$conexion->prepare("SELECT codigo_destino AS codigo FROM equivalencias_codigos WHERE equivalencia_activa='SI' AND TRIM(codigo_origen)=TRIM(?) UNION SELECT codigo_origen AS codigo FROM equivalencias_codigos WHERE equivalencia_activa='SI' AND TRIM(codigo_destino)=TRIM(?)");
    if ($stmt) {
        $stmt->bind_param('ss',$codigo,$codigo); $stmt->execute(); $r=$stmt->get_result();
        while($x=$r->fetch_assoc()) $candidatos[]=trim($x['codigo']);
        $stmt->close();
    }
    /*
     * Compatibilidad automática entre códigos históricos GD300 y actuales GD390.
     * Los amperajes comerciales del GD390 no son una simple inserción de "9"
     * en todos los casos (24->27, 32->34, 39->40 y 45->48), por eso se usa el
     * mapeo exacto validado contra la base Bejerman.
     */
    $mapaGd300Gd390=array(
        '314'=>'3914',
        '318'=>'3918',
        '324'=>'3927',
        '332'=>'3934',
        '339'=>'3940',
        '345'=>'3948',
        '360'=>'3960'
    );
    if (preg_match('/^(A6[37]3GD)(314|318|324|332|339|345|360)(M|EM|IP)$/i',$codigo,$m)) {
        $clave=$m[2];
        if (isset($mapaGd300Gd390[$clave])) $candidatos[]=$m[1].$mapaGd300Gd390[$clave].$m[3];
    }
    $mapaGd390Gd300=array_flip($mapaGd300Gd390);
    if (preg_match('/^(A6[37]3GD)(3914|3918|3927|3934|3940|3948|3960)(M|EM|IP)$/i',$codigo,$m)) {
        $clave=$m[2];
        if (isset($mapaGd390Gd300[$clave])) $candidatos[]=$m[1].$mapaGd390Gd300[$clave].$m[3];
    }
    return $cache[$k]=array_values(array_unique(array_filter($candidatos)));
}
function precioDeLista($conexion,$listaId,$codigo) {
    static $cache = array();
    $codigo=trim((string)$codigo);
    $k=spl_object_id($conexion).'|'.(int)$listaId.'|'.strtoupper($codigo);
    if (array_key_exists($k,$cache)) return $cache[$k];
    $fila=precioExactoDeLista($conexion,$listaId,$codigo);
    if ($fila) { $fila['codigo_solicitado']=$codigo; $fila['codigo_resuelto']=$fila['precios_codigo']; return $cache[$k]=$fila; }
    foreach (candidatosEquivalentesCodigo($conexion,$codigo) as $alternativo) {
        $fila=precioExactoDeLista($conexion,$listaId,$alternativo);
        if ($fila) { $fila['codigo_solicitado']=$codigo; $fila['codigo_resuelto']=$fila['precios_codigo']; return $cache[$k]=$fila; }
    }
    return $cache[$k]=null;
}
function aplicarPrecioVersion($conexion,$listaId,$fila,$campoCodigo) {
    if (!$fila || empty($fila[$campoCodigo])) return $fila;
    $codigoOriginal=$fila[$campoCodigo];
    $p=precioDeLista($conexion,$listaId,$codigoOriginal);
    if ($p) {
        $fila['codigo_matriz']=$codigoOriginal;
        $fila[$campoCodigo]=$p['precios_codigo'];
        $fila['precios_descripcion']=$p['precios_descripcion'];
        $fila['precios_costo']=$p['precios_costo'];
        $fila['codigo_equivalente_usado']=trim($codigoOriginal)!==trim($p['precios_codigo']);
    } else { $fila['precios_descripcion']=null; $fila['precios_costo']=null; $fila['codigo_equivalente_usado']=false; }
    return $fila;
}

/**
 * Normaliza números heredados para mostrarlos sin ceros a la izquierda.
 * No altera el identificador interno ni el valor almacenado.
 * Ejemplos: R.00.004 -> R.4, C.00.019 -> C.19, #00.009 -> #9.
 */
function numeroDocumentoVisible($numero): string
{
    $valor = trim((string)$numero);
    if ($valor === '') return '';

    if (preg_match('/^([CRP])([0-9._-]+)$/i', $valor, $m)) {
        $digitos = preg_replace('/\D+/', '', $m[2]);
        if ($digitos !== '') return strtoupper($m[1]) . '.' . (string)((int)$digitos);
    }
    if (preg_match('/^#([0-9._-]+)$/', $valor, $m)) {
        $digitos = preg_replace('/\D+/', '', $m[1]);
        if ($digitos !== '') return '#' . (string)((int)$digitos);
    }
    return $valor;
}

/**
 * Devuelve el siguiente número correlativo de una serie documental.
 * El correlativo se guarda y se muestra como número entero, sin ceros a la izquierda.
 * Ejemplo: 20 -> C.20, 5 -> R.5, 10 -> #10 o 5 -> P.5.
 * Debe invocarse dentro de una transacción para mantener la correlatividad.
 */
function siguienteNumeroDocumento(mysqli $conexion, string $serie): string
{
    $series = array(
        'COTIZACION_CONTROL' => 'C.',
        'COTIZACION_SUMINISTROS' => 'R.',
        'PEDIDO_OBRA' => '#',
        'PEDIDO_SUMINISTROS' => 'P.'
    );
    if (!isset($series[$serie])) {
        throw new InvalidArgumentException('Serie documental inválida.');
    }

    $stmt = $conexion->prepare("INSERT IGNORE INTO numeracion_documentos(numeracion_serie,numeracion_ultimo) VALUES(?,0)");
    $stmt->bind_param('s', $serie);
    $stmt->execute();
    $stmt->close();

    $stmt = $conexion->prepare("SELECT numeracion_ultimo FROM numeracion_documentos WHERE numeracion_serie=? FOR UPDATE");
    $stmt->bind_param('s', $serie);
    $stmt->execute();
    $fila = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    if (!$fila) {
        throw new RuntimeException('No se pudo leer la numeración documental.');
    }

    $siguiente = (int)$fila['numeracion_ultimo'] + 1;
    if ($siguiente > 999999999) {
        throw new RuntimeException('La serie documental superó el máximo permitido.');
    }

    $stmt = $conexion->prepare("UPDATE numeracion_documentos SET numeracion_ultimo=? WHERE numeracion_serie=?");
    $stmt->bind_param('is', $siguiente, $serie);
    $stmt->execute();
    $stmt->close();

    return $series[$serie] . (string)$siguiente;
}


/**
 * Presentacion comercial de Botonera de Cabina.
 * Mantiene las lineas tecnicas almacenadas para trazabilidad/calculo, pero hacia
 * cliente, pedido y fabricacion presenta un unico renglon compuesto por:
 * BASE + adicional por paradas + indicador de posicion.
 */
function agruparBotoneraCabinaPresentacion(array $detalles, array $datosFormulario = array()): array
{
    $norm = static function($v): string {
        $v = strtoupper(trim((string)$v));
        $v = strtr($v, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'));
        return preg_replace('/\s+/u', ' ', $v) ?: '';
    };
    $idxBase = null; $idxParada = null; $idxIndicador = null;
    foreach ($detalles as $i=>$d) {
        $c = $norm($d['concepto'] ?? '');
        if ($c === 'BASE BOTONERA DE CABINA' && $idxBase === null) $idxBase = $i;
        elseif ($c === 'ADICIONAL POR PARADA EN BOTONERA DE CABINA' && $idxParada === null) $idxParada = $i;
        elseif ($c === 'INDICADOR DE POSICION' && $idxIndicador === null) $idxIndicador = $i;
    }
    if ($idxBase === null) return $detalles;

    $base = $detalles[$idxBase];
    $total = (float)($base['importe_total'] ?? $base['total'] ?? 0);
    if ($idxParada !== null) $total += (float)($detalles[$idxParada]['importe_total'] ?? $detalles[$idxParada]['total'] ?? 0);
    if ($idxIndicador !== null) $total += (float)($detalles[$idxIndicador]['importe_total'] ?? $detalles[$idxIndicador]['total'] ?? 0);
    $cantidad = max(1.0, (float)($base['cantidad'] ?? 1));

    $desc = rtrim(trim((string)($base['descripcion'] ?? '')), '. ');
    $paradasPorBotonera=$datosFormulario['senal_paradas_equipo']??array();
    if(!is_array($paradasPorBotonera))$paradasPorBotonera=array($paradasPorBotonera);
    $paradasPorBotonera=array_values(array_filter(array_map('intval',$paradasPorBotonera),static function($v){return $v>0;}));
    $nomenclaturasPorBotonera=$datosFormulario['senal_nomenclatura_equipo']??($datosFormulario['nomenclatura_equipo']??array());
    if(!is_array($nomenclaturasPorBotonera))$nomenclaturasPorBotonera=array($nomenclaturasPorBotonera);
    $nomenclaturasPorBotonera=array_values(array_map(static function($v){return trim((string)$v);},$nomenclaturasPorBotonera));
    $medidasPorBotonera=$datosFormulario['senal_medidas_equipo']??array();
    if(!is_array($medidasPorBotonera))$medidasPorBotonera=array($medidasPorBotonera);
    $medidasPorBotonera=array_values(array_map(static function($v){return trim((string)$v);},$medidasPorBotonera));
    if(!$medidasPorBotonera && trim((string)($datosFormulario['senal_medidas']??''))!==''){
        $medidasPorBotonera=array_fill(0,max(1,(int)$cantidad),trim((string)$datosFormulario['senal_medidas']));
    }
    $paradasTotales = 0;
    if ($idxParada !== null) {
        $formulaParada = (string)($detalles[$idxParada]['formula_aplicada'] ?? $detalles[$idxParada]['formula'] ?? '');
        if (preg_match('/\((\d+)\s*-\s*2\)\s*paradas/ui', $formulaParada, $mParadas)) $paradasTotales = (int)$mParadas[1];
    }
    if($paradasPorBotonera){
        $unicas=array_values(array_unique($paradasPorBotonera));
        if(count($unicas)===1){
            $paradasTotales=(int)$unicas[0];
        } else {
            $partes=array(); foreach($paradasPorBotonera as $i=>$p){
                $nom=trim((string)($nomenclaturasPorBotonera[$i]??'')); if($nom==='')$nom='A CONFIRMAR';
                $partes[]='Coche '.($i+1).' '.(int)$p.'P ['.$nom.']';
            }
            $desc .= ' · '.implode(' / ',$partes);
        }
    }
    if ($paradasTotales > 0 && stripos($desc, $paradasTotales . 'P') === false) {
        if (preg_match('/^Botonera de cabina\b/ui', $desc)) $desc = preg_replace('/^Botonera de cabina\b/ui', 'Botonera de cabina ' . $paradasTotales . 'P', $desc, 1);
        else $desc = 'Botonera de cabina ' . $paradasTotales . 'P - ' . $desc;
    }
    if($paradasPorBotonera && count(array_unique($paradasPorBotonera))===1){
        $partesNom=array();
        foreach($paradasPorBotonera as $i=>$p){
            $nom=trim((string)($nomenclaturasPorBotonera[$i]??'')); if($nom==='')$nom='A CONFIRMAR';
            $partesNom[]='Coche '.($i+1).': '.$nom;
        }
        if(count($partesNom)===1 && $paradasTotales>0) {
            $desc=preg_replace('/\b'.(int)$paradasTotales.'P\b/u', (int)$paradasTotales.'P ('.strtoupper($nomenclaturasPorBotonera[0] ?: 'A CONFIRMAR').'),', $desc, 1);
        } elseif($partesNom) $desc .= ' · '.implode(' / ',$partesNom);
    }
    if($medidasPorBotonera){
        $partesMed=array();
        foreach($medidasPorBotonera as $i=>$medida){
            $medida=trim((string)$medida);
            if($medida!=='') $partesMed[]='Coche '.($i+1).' medida '.$medida;
        }
        if($partesMed) $desc .= ' · '.implode(' / ',$partesMed);
    }
    if ($idxIndicador !== null) {
        $ind = $detalles[$idxIndicador];
        $indDesc = trim((string)($ind['descripcion'] ?? ''));
        if ($indDesc === '') $indDesc = trim((string)($ind['codigo'] ?? ''));
        if ($indDesc !== '') $desc .= ' + IP ' . $indDesc;

        /*
         * Codigo comercial compuesto de botonera.
         * Las lineas tecnicas siguen conservando BASE + adicional de paradas + indicador
         * para calculo y trazabilidad. Al agrupar para cliente se muestra el codigo
         * historico/comercial que representa la botonera con el indicador incorporado.
         * Casos validados contra presupuestos reales 151778 y 151838.
         */
        $descNorm = $norm($desc);
        $indNorm = $norm($indDesc);
        if (strpos($indNorm, '31MM V5R') !== false) {
            if (strpos($descNorm, 'MODELO A3160') !== false) {
                $base['codigo'] = 'A316M2RBA020UM';
            } elseif (strpos($descNorm, 'MODELO A3180') !== false) {
                $base['codigo'] = 'A318M2RGA020UM';
            }
        }
    }
    $desc = preg_replace('/\s*×\s*1(?:[,.]0+)?/u', '', $desc);
    $desc = preg_replace('/\s*-\s*electronico\b/iu', '', $desc);
    $desc = str_ireplace(array('modelo rond metal', 'tapa acero', '31mm', 'pb al 4'), array('modelo Rond Metal', 'Tapa Acero', '31 mm', 'PB al 4'), $desc);
    $desc = str_replace('. · ', ' · ', $desc);
    if ($desc !== '') $desc .= '.';

    $base['modulo'] = 'SENALIZACION';
    $base['concepto'] = 'Botonera de cabina';
    $base['descripcion'] = $desc;
    $base['cantidad'] = $cantidad;
    $base['precio_unitario'] = ceil($total / $cantidad);
    $base['unitario'] = ceil($total / $cantidad);
    $base['importe_total'] = ceil($total);
    $base['total'] = ceil($total);
    $base['formula_aplicada'] = 'Precio conjunto de botonera de cabina';
    $base['formula'] = 'Precio conjunto de botonera de cabina';
    $base['_botonera_agrupada'] = true;

    $salida = array();
    foreach ($detalles as $i=>$d) {
        if ($i === $idxBase) { $salida[] = $base; continue; }
        if ($i === $idxParada || $i === $idxIndicador) continue;
        $salida[] = $d;
    }
    return $salida;
}


/**
 * Presentación exclusiva de fabricación: si las botoneras de una batería tienen
 * distintas paradas, las separa por coche para impedir que Producción interprete
 * una cantidad agrupada como si todas fueran iguales. No modifica importes guardados.
 */
function expandirBotonerasFabricacion(array $detalles, array $datosFormulario = array()): array
{
    $agrupados = agruparBotoneraCabinaPresentacion($detalles, $datosFormulario);
    $cantidad = max(1, (int)($datosFormulario['senal_cantidad'] ?? 1));
    $legacy = max(0, (int)($datosFormulario['senal_paradas'] ?? 0));
    $paradas = $datosFormulario['senal_paradas_equipo'] ?? array();
    if (!is_array($paradas)) $paradas = array($paradas);
    $paradas = array_values(array_map('intval', $paradas));
    if (!$paradas && $legacy > 0) $paradas = array_fill(0, $cantidad, $legacy);
    while (count($paradas) < $cantidad) $paradas[] = $legacy;
    $paradas = array_slice($paradas, 0, $cantidad);

    $nomenclaturas = $datosFormulario['senal_nomenclatura_equipo'] ?? ($datosFormulario['nomenclatura_equipo'] ?? array());
    if (!is_array($nomenclaturas)) $nomenclaturas = array($nomenclaturas);
    $nomenclaturas = array_values(array_map(static function($v){ return trim((string)$v); }, $nomenclaturas));
    while (count($nomenclaturas) < $cantidad) $nomenclaturas[] = '';
    $nomenclaturas = array_slice($nomenclaturas, 0, $cantidad);
    $medidas = $datosFormulario['senal_medidas_equipo'] ?? array();
    if (!is_array($medidas)) $medidas = array($medidas);
    $medidas = array_values(array_map(static function($v){ return trim((string)$v); }, $medidas));
    $medidaLegacy = trim((string)($datosFormulario['senal_medidas'] ?? ''));
    if (!$medidas && $medidaLegacy !== '') $medidas = array_fill(0, $cantidad, $medidaLegacy);
    while (count($medidas) < $cantidad) $medidas[] = '';
    $medidas = array_slice($medidas, 0, $cantidad);

    // En fabricación, una batería se detalla siempre por coche. Aunque dos coches
    // tengan igual cantidad de paradas, su nomenclatura física puede ser distinta.
    if ($cantidad <= 1) {
        foreach ($agrupados as &$d) {
            if (empty($d['_botonera_agrupada'])) continue;
            $nom = trim((string)($nomenclaturas[0] ?? ''));
            if ($nom === '') $nom = 'A CONFIRMAR';
            $p = (int)($paradas[0] ?? $legacy);
            $med = trim((string)($medidas[0] ?? ''));
            $d['descripcion'] = 'Coche 1 - '.$p.' paradas - Nomenclatura: '.$nom.($med!==''?' - Medida: '.$med:'').' - '.trim((string)($d['descripcion'] ?? 'Botonera de cabina'));
            $d['formula_aplicada'] = 'Botonera individual del coche 1 · '.$p.' paradas · '.$nom.($med!==''?' · medida '.$med:'');
            $d['formula'] = $d['formula_aplicada'];
        }
        unset($d);
        return $agrupados;
    }

    $salida = array();
    foreach ($agrupados as $d) {
        if (empty($d['_botonera_agrupada'])) { $salida[] = $d; continue; }
        $baseDesc = trim((string)($d['descripcion'] ?? 'Botonera de cabina'));
        $baseDesc = preg_replace('/\s*·\s*Coche\s+1.*$/ui','',$baseDesc) ?: $baseDesc;
        for ($i=0; $i<$cantidad; $i++) {
            $copia = $d;
            $p = (int)($paradas[$i] ?? 0);
            $nom = trim((string)($nomenclaturas[$i] ?? ''));
            if ($nom === '') $nom = 'A CONFIRMAR';
            $med = trim((string)($medidas[$i] ?? ''));
            $copia['cantidad'] = 1;
            $copia['descripcion'] = 'Coche '.($i+1).' - '.$p.' paradas - Nomenclatura: '.$nom.($med!==''?' - Medida: '.$med:'').' - '.$baseDesc;
            $copia['formula_aplicada'] = 'Botonera individual del coche '.($i+1).' · '.$p.' paradas · '.$nom.($med!==''?' · medida '.$med:'');
            $copia['formula'] = $copia['formula_aplicada'];
            $salida[] = $copia;
        }
    }
    return $salida;
}

