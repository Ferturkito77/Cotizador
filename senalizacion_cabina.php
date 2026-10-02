<?php
require_once __DIR__ . '/parametros_sistema.php';
require_once __DIR__ . '/schema_guard.php';
/*
 * Motor de calculo - Senalizacion / Botonera de Cabina.
 * Todas las reglas comerciales variables se consultan en MySQL.
 * Las 8 tablas de parametrizacion se crean con:
 * migracion_senalizacion_botonera_cabina_8_tablas.sql
 */

function senalTablaExiste($conexion, $tabla) { return esquemaTablaExiste($conexion,(string)$tabla); }

function senalPerfilModelo($conexion,$modeloId){
    $perfil=array(
        'familia_comercial'=>'GENERAL',
        'modo_base'=>'MATRIZ_COMPLETA',
        'tipo_modulo_requerido'=>'',
        'pantalla'=>0,
        'indicador_cabina'=>1,
        'indicador_pulsador'=>1,
        'indicador_exterior_independiente'=>1,
        'regla_adicional_parada'=>'MATRIZ',
        'modelo_adicional_parada'=>'',
        'politica_acabado'=>'COEFICIENTE',
        'acabado'=>'',
        'modo_pulsador_exterior'=>'MATRIZ_COMPLETA',
        'indicador_incluido_descripcion'=>'',
        'separar_indicador_exterior'=>0,
        'requiere_luz_cortesia'=>0
    );
    if((int)$modeloId<=0 || !senalTablaExiste($conexion,'senal_modelos_perfiles')) return $perfil;
    $st=$conexion->prepare('SELECT familia_comercial,modo_base,tipo_modulo_requerido,pantalla,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,modelo_adicional_parada,politica_acabado,acabado,modo_pulsador_exterior,indicador_incluido_descripcion,separar_indicador_exterior,requiere_luz_cortesia FROM senal_modelos_perfiles WHERE modelo_pulsador_id=? AND activo=1 LIMIT 1');
    if(!$st)return $perfil;
    $id=(int)$modeloId;$st->bind_param('i',$id);$st->execute();$fila=$st->get_result()->fetch_assoc();$st->close();
    return $fila?array_merge($perfil,$fila):$perfil;
}

function senalIndicadorPermitidoContexto($conexion,$codigo,$contexto){
    $codigo=strtoupper(trim((string)$codigo));$contexto=strtoupper(trim((string)$contexto));
    if($codigo==='' || !senalTablaExiste($conexion,'senal_indicadores_contextos')) return true;
    $st=$conexion->prepare('SELECT COUNT(*) total, SUM(CASE WHEN contexto=? AND activo=1 THEN 1 ELSE 0 END) permitido FROM senal_indicadores_contextos WHERE UPPER(TRIM(codigo))=?');
    if(!$st) return false;
    $st->bind_param('ss',$contexto,$codigo);$st->execute();$fila=$st->get_result()->fetch_assoc();$st->close();
    return !$fila || (int)$fila['total']===0 || (int)$fila['permitido']>0;
}

function senalAplicarDependenciasDesdeControl($conexion, $post) {
    if (!is_array($post)) return array();

    // Si Señalización usa los datos del Control, la relación por coche se preserva
    // también del lado servidor. Esto evita depender del JavaScript para transportar
    // paradas y nomenclaturas a datos_formulario / pedido / orden de fabricación.
    if (!empty($post['senal_usar_control']) && !empty($post['incluir_control'])) {
        $cantidadEquipos = max(1, (int)($post['cantidad_equipos'] ?? 1));
        $paradasControl = $post['paradas_equipo'] ?? array();
        if (!is_array($paradasControl)) $paradasControl = array($paradasControl);
        $nomenclaturasControl = $post['nomenclatura_equipo'] ?? array();
        if (!is_array($nomenclaturasControl)) $nomenclaturasControl = array($nomenclaturasControl);
        $post['senal_cantidad'] = (string)$cantidadEquipos;
        $post['senal_paradas_equipo'] = array_slice(array_values($paradasControl), 0, $cantidadEquipos);
        if (!empty($post['senal_paradas_equipo'])) $post['senal_paradas'] = (string)$post['senal_paradas_equipo'][0];
        $post['senal_nomenclatura_equipo'] = array();
        for ($i=0; $i<$cantidadEquipos; $i++) {
            $post['senal_nomenclatura_equipo'][] = trim((string)($nomenclaturasControl[$i] ?? ''));
        }
    }

    // Regla funcional Automac: si el Control lleva comunicacion serie en cabina
    // (o una opcion TOTAL que la incluye), Senalizacion debe incluir obligatoriamente
    // la placa A3540. La cantidad es SIEMPRE la cantidad de equipos cotizados.
    // Si una comunicacion serie habia sido agregada automaticamente desde Control
    // y luego el Control deja de requerirla, limpiar tambien del lado servidor.
    // Esto evita que una edicion/revision conserve una cantidad residual (por ejemplo 1)
    // aunque el tipo de comunicacion ya haya vuelto a No.
    $limpiarDependenciaAutomatica = function(array $datos) {
        if (!empty($datos['senal_comunicacion_serie_desde_control'])) {
            $datos['senal_comunicacion_serie_tipo'] = '';
            $datos['senal_cant_comunicacion_serie'] = '0';
            $datos['senal_comunicacion_serie_desde_control'] = '0';
        }
        return $datos;
    };

    if (empty($post['incluir_control'])) return $limpiarDependenciaAutomatica($post);

    $idComSerie = (int)($post['id_comunicacion_serie'] ?? 0);
    if ($idComSerie <= 0) return $limpiarDependenciaAutomatica($post);

    $nombre = '';
    $st = $conexion->prepare('SELECT comserie_name FROM comunicaciones_serie WHERE comserie_id=? LIMIT 1');
    if ($st) {
        $st->bind_param('i', $idComSerie);
        $st->execute();
        $fila = $st->get_result()->fetch_assoc();
        $st->close();
        $nombre = strtoupper(trim((string)($fila['comserie_name'] ?? '')));
    }

    $nombreNormalizado = strtr($nombre, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'));
    $incluyeCabina = strpos($nombreNormalizado, 'CABINA') !== false;
    $esTotal = strpos($nombreNormalizado, 'TOTAL') !== false;
    if (!$incluyeCabina && !$esTotal) return $limpiarDependenciaAutomatica($post);

    $cantidadEquipos = max(1, (int)($post['cantidad_equipos'] ?? 1));
    $post['senal_incluir'] = '1';
    $post['senal_comunicacion_serie_tipo'] = $esTotal ? 'TOTAL' : 'EN CABINA';
    $post['senal_cant_comunicacion_serie'] = (string)$cantidadEquipos;
    $post['senal_comunicacion_serie_desde_control'] = '1';
    return $post;
}

function senalExigirTablas($conexion) {
    $tablas = array(
        'senal_adicional_parada_cabina',
        'senal_llave_ascensorista_cabina',
        'senal_braille_lateral_ascensorista',
        'senal_adic_llave_asc_electromecanico',
        'senal_indicadores_cabina',
        'senal_adic_parada_indicador_cabina',
        'senal_adicionales_especiales_cabina',
        'senal_adicionales_cabina',
        'senal_codigos_cotizacion'
    );
    $faltan = array();
    foreach ($tablas as $t) if (!senalTablaExiste($conexion, $t)) $faltan[] = $t;
    if ($faltan) throw new Exception('Faltan tablas de parametrizacion de Botonera de Cabina: ' . implode(', ', $faltan) . '. Ejecute la migracion SQL.');
}

function senalFechaListaSeleccionada($conexion, $listaId) {
    $listaId=(int)$listaId;
    if($listaId<=0 || !senalTablaExiste($conexion,'listas_precios_importaciones')) return null;
    $st=$conexion->prepare("SELECT COALESCE(lista_fecha_archivo,lista_vigente_desde) AS fecha FROM listas_precios_importaciones WHERE lista_id=? LIMIT 1");
    if(!$st) return null;
    $st->bind_param('i',$listaId); $st->execute(); $f=$st->get_result()->fetch_assoc(); $st->close();
    return !empty($f['fecha']) ? (string)$f['fecha'] : null;
}

function senalUtilidadCodigo($conexion, $codigo) {
    $codigo=trim((string)$codigo);
    if($codigo==='') return null;
    if(senalTablaExiste($conexion,'productos_presupuesto')){
        $st=$conexion->prepare("SELECT utilidad FROM productos_presupuesto WHERE BINARY codigo=BINARY ? AND habilitado=1 AND COALESCE(utilidad,0)>0 LIMIT 1");
        if($st){$st->bind_param('s',$codigo);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();if($f)return (float)$f['utilidad'];}
    }
    if(senalTablaExiste($conexion,'productos_senalizacion')){
        $st=$conexion->prepare("SELECT utilidad FROM productos_senalizacion WHERE BINARY codigo=BINARY ? AND habilitado=1 AND COALESCE(utilidad,0)>0 LIMIT 1");
        if($st){$st->bind_param('s',$codigo);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();if($f)return (float)$f['utilidad'];}
    }
    return null;
}

function senalProductoHistoricoBejerman($conexion, $listaId, $codigo) {
    if(!senalTablaExiste($conexion,'bejerman_productos') || !senalTablaExiste($conexion,'bejerman_listas')) return null;
    $codigo=trim((string)$codigo); if($codigo==='') return null;
    $fechaLimite=senalFechaListaSeleccionada($conexion,$listaId);
    if($fechaLimite){
        $sql="SELECT b.bejerman_lista_id,b.codigo,b.descripcion,b.costo,bl.fecha_lista,bl.archivo_origen,bl.estado
              FROM bejerman_productos b
              INNER JOIN bejerman_listas bl ON bl.bejerman_lista_id=b.bejerman_lista_id
              WHERE BINARY b.codigo=BINARY ? AND b.activo=1 AND b.costo>0 AND bl.estado<>'ANULADA' AND bl.fecha_lista<=?
              ORDER BY bl.fecha_lista DESC,b.bejerman_lista_id DESC LIMIT 1";
        $st=$conexion->prepare($sql); if(!$st)return null; $st->bind_param('ss',$codigo,$fechaLimite);
    }else{
        $sql="SELECT b.bejerman_lista_id,b.codigo,b.descripcion,b.costo,bl.fecha_lista,bl.archivo_origen,bl.estado
              FROM bejerman_productos b
              INNER JOIN bejerman_listas bl ON bl.bejerman_lista_id=b.bejerman_lista_id
              WHERE BINARY b.codigo=BINARY ? AND b.activo=1 AND b.costo>0 AND bl.estado<>'ANULADA'
              ORDER BY bl.fecha_lista DESC,b.bejerman_lista_id DESC LIMIT 1";
        $st=$conexion->prepare($sql); if(!$st)return null; $st->bind_param('s',$codigo);
    }
    $st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    if(!$f)return null;
    $utilidad=senalUtilidadCodigo($conexion,$codigo);
    if($utilidad===null || $utilidad<=0)return null;
    $unitario=ceil((float)$f['costo']*$utilidad);
    if($unitario<=0)return null;
    return array(
        'codigo'=>$codigo,
        'costo'=>(float)$f['costo'],
        'utilidad'=>$utilidad,
        'unitario'=>(float)$unitario,
        'origen_precio'=>'BEJERMAN_HISTORICA',
        'precio_historico'=>true,
        'bejerman_lista_id'=>(int)$f['bejerman_lista_id'],
        'bejerman_fecha'=>(string)$f['fecha_lista'],
        'bejerman_archivo'=>(string)($f['archivo_origen']??''),
        'descripcion_origen'=>(string)($f['descripcion']??'')
    );
}

function senalCodigoCotizacion($conexion,$codigoOriginal){
    $codigoOriginal=trim((string)$codigoOriginal);
    if($codigoOriginal==='') return '';
    // Primero respetar una traduccion especifica de Senalizacion, si existe.
    if(senalTablaExiste($conexion,'senal_codigos_cotizacion')) {
        $st=$conexion->prepare("SELECT codigo_cotizacion FROM senal_codigos_cotizacion WHERE BINARY codigo_original=BINARY ? AND activo=1 LIMIT 1");
        if($st){
            $st->bind_param('s',$codigoOriginal);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
            $codigo=trim((string)($f['codigo_cotizacion']??''));
            if($codigo!=='') return $codigo;
        }
    }
    // v234: Senalizacion debe compartir el mismo mapa de equivalencias que el resto
    // del cotizador. Ejemplo funcional: A3540 (codigo tecnico) -> P3540 (Bejerman).
    if(function_exists('candidatosEquivalentesCodigo')){
        $eq=candidatosEquivalentesCodigo($conexion,$codigoOriginal);
        if(!empty($eq)) return trim((string)$eq[0]);
    }
    return $codigoOriginal;
}

function senalProductoValorizado($conexion, $listaId, $codigo) {
    $codigoOriginal=trim((string)$codigo);
    if($codigoOriginal==='') return null;
    // v234: usar el resolvedor comercial comun (exacto + equivalencias activas).
    // Antes se consultaba solo el codigo exacto de Senalizacion, por lo que A3540
    // no podia resolver P3540 aun cuando equivalencias_codigos estaba configurada.
    $art=precioDeLista($conexion,(int)$listaId,$codigoOriginal);
    if(!$art || (float)($art['precios_costo']??0)<=0) return null;
    $codigoResuelto=trim((string)($art['codigo_resuelto']??$art['precios_codigo']??$codigoOriginal));
    $unitario=ceil((float)$art['precios_costo']);
    return array(
        'codigo'=>$codigoOriginal,
        'codigo_original'=>$codigoOriginal,
        'codigo_cotizacion'=>$codigoResuelto,
        'codigo_resuelto'=>$codigoResuelto,
        'costo'=>$unitario,'utilidad'=>1.0,'unitario'=>$unitario,
        'origen_precio'=>$codigoResuelto!==$codigoOriginal?'EQUIVALENCIA_CODIGO':'LISTA_SELECCIONADA',
        'precio_historico'=>(int)$listaId>0,
        'bejerman_lista_id'=>(int)$listaId>0?(int)$listaId:null,'bejerman_fecha'=>null,'bejerman_archivo'=>null
    );
}

function senalResolverMatrizBase($conexion, $post) {
    $tipoModulo=(int)($post['senal_tipo_modulo']??0);
    $modelo=(int)($post['senal_modelo']??0);
    $tension=(int)($post['senal_tension']??0);
    $color=(int)($post['senal_color']??0);
    $tecla=(int)($post['senal_tecla']??0);
    $tipoPuerta=strtoupper(trim((string)($post['senal_tipo_puerta']??'')));

    // Reglas comerciales de tecla por modelo. Se validan también en servidor para
    // impedir combinaciones inválidas provenientes de plantillas o datos antiguos.
    $teclasPermitidasPorModelo=array(
        1=>array(1),     // A3150: AR/AC (Alto Relieve / Alto Contraste)
        2=>array(1,2),   // A3160: AR/AC o RELIEVE
        3=>array(2),     // A3170: RELIEVE
        4=>array(2),     // A3180: RELIEVE
        5=>array(3)      // A3900: ACERO
    );
    if(isset($teclasPermitidasPorModelo[$modelo]) && !in_array($tecla,$teclasPermitidasPorModelo[$modelo],true)) {
        $nombresModelo=array(1=>'A3150',2=>'A3160',3=>'A3170',4=>'A3180',5=>'A3900',6=>'ROND METAL');
        $nombresTecla=array(1=>'AR/AC',2=>'RELIEVE',3=>'ACERO');
        $permitidas=array_map(function($id) use ($nombresTecla){ return $nombresTecla[$id]??('ID '.$id); },$teclasPermitidasPorModelo[$modelo]);
        throw new Exception('La tecla seleccionada no corresponde al modelo '.($nombresModelo[$modelo]??$modelo).'. Permitida(s): '.implode(' / ',$permitidas).'.');
    }
    // v177: las botoneras ONIX tienen una regla propia de valorización. Para ONIX
    // el precio base depende del modelo y NO de Color / Tecla / Tensión / Bornes.
    // Se conserva Tipo de puerta y el código activo ya parametrizado en la matriz.
    $modeloNombre='';
    $stModelo=$conexion->prepare("SELECT modelo_pulsador_nombre FROM senal_modelos_pulsador WHERE modelo_pulsador_id=? LIMIT 1");
    if($stModelo){
        $stModelo->bind_param('i',$modelo);
        if($stModelo->execute()){
            $rsModelo=$stModelo->get_result();
            if($filaModelo=$rsModelo->fetch_assoc()) $modeloNombre=senalNormalizarClave($filaModelo['modelo_pulsador_nombre']??'');
        }
        $stModelo->close();
    }
    $perfil=senalPerfilModelo($conexion,$modelo);
    $modoBase=strtoupper(trim((string)$perfil['modo_base']));
    if($modoBase==='MATRIZ_COMPLETA'){
        if(strpos($modeloNombre,'ONIX TELEFONICO')!==false)$modoBase='ONIX_TELEFONICO';
        elseif(strpos($modeloNombre,'ONIX INDIVIDUALES')!==false || strpos($modeloNombre,'ONIX PULS')!==false)$modoBase='ONIX_INDIVIDUALES';
        elseif(strpos($modeloNombre,'PANTALLA 21')!==false || strpos($modeloNombre,'PANTALLA TOUCH 21')!==false)$modoBase='PANTALLA';
        elseif($modeloNombre==='METAL')$modoBase='MODELO_PUERTA';
    }
    $onixTipo='';
    if($modoBase==='ONIX_TELEFONICO')$onixTipo='TELEFONICO';
    elseif($modoBase==='ONIX_INDIVIDUALES')$onixTipo='INDIVIDUALES';
    elseif($modoBase==='PANTALLA')$onixTipo='PANTALLA';
    $esPantalla=!empty($perfil['pantalla']) || $modoBase==='PANTALLA';
    $esOnix=$onixTipo!=='';
    $esModeloPuerta=$modoBase==='MODELO_PUERTA';
    $tipoModuloRequerido=strtoupper(trim((string)$perfil['tipo_modulo_requerido']));
    if($tipoModuloRequerido!==''){
        $stReq=$conexion->prepare('SELECT tipo_modulo_id FROM senal_tipos_modulo WHERE UPPER(TRIM(tipo_modulo_nombre))=? LIMIT 1');
        if($stReq){$stReq->bind_param('s',$tipoModuloRequerido);$stReq->execute();$fr=$stReq->get_result()->fetch_assoc();$stReq->close();if($fr)$tipoModulo=(int)$fr['tipo_modulo_id'];}
    }elseif($modoBase==='MODELO_PUERTA' && senalTieneControl($post)){
        $rte=$conexion->query("SELECT tipo_modulo_id FROM senal_tipos_modulo WHERE UPPER(tipo_modulo_nombre)='ELECTRONICO' ORDER BY tipo_modulo_id LIMIT 1");
        if($rte && ($fte=$rte->fetch_assoc()))$tipoModulo=(int)$fte['tipo_modulo_id'];
    }

    // El perfil MODELO_PUERTA comparte la matriz por modelo y puerta sin exponer otros criterios.
    if($esModeloPuerta){
        if(!$modelo||!in_array($tipoPuerta,array('PM','PA'),true)) throw new Exception('Seleccione un modelo y tipo de puerta válidos.');
        if($tipoModuloRequerido!=='' && !$tipoModulo) throw new Exception('El tipo de módulo requerido por el perfil no existe en el catálogo.');
        $sqlModelo="SELECT m.codigo,mp.modelo_pulsador_id,mp.modelo_pulsador_nombre,tm.tension_modulo_nombre,b.borne_nombre,c.color_registro_nombre,t.tecla_nombre,COALESCE(tip.tipo_modulo_nombre,'') AS tipo_modulo_nombre
                    FROM matriz_botoneras_cabina m
                    INNER JOIN senal_modelos_pulsador mp ON mp.modelo_pulsador_id=m.modelo_pulsador_id
                    INNER JOIN senal_tensiones_modulo tm ON tm.tension_modulo_id=m.tension_modulo_id
                    INNER JOIN senal_bornes b ON b.borne_id=m.borne_id
                    INNER JOIN senal_colores_registro c ON c.color_registro_id=m.color_registro_id
                    INNER JOIN senal_teclas t ON t.tecla_id=m.tecla_id
                    LEFT JOIN senal_tipos_modulo tip ON tip.tipo_modulo_id=?
                    WHERE m.tipo_puerta=? AND m.modelo_pulsador_id=? AND m.activo='SI' ORDER BY m.matriz_botonera_id LIMIT 1";
        $stModeloPuerta=$conexion->prepare($sqlModelo);if(!$stModeloPuerta)throw new Exception('No se pudo consultar la matriz de botoneras por modelo y puerta.');
        $stModeloPuerta->bind_param('isi',$tipoModulo,$tipoPuerta,$modelo);$stModeloPuerta->execute();$mat=$stModeloPuerta->get_result()->fetch_assoc();$stModeloPuerta->close();
        if(!$mat)throw new Exception('El modelo no tiene una configuración activa para la puerta seleccionada.');
        $mat['tipo_puerta']=$tipoPuerta;$mat['es_modelo_puerta']=true;$mat['senal_perfil']=$perfil;
        return $mat;
    }

    $borne=0;
    $usaDatosControl=senalTieneControl($post) && !empty($post['senal_usar_control']);

    // v173: la tecnología de la botonera manda sobre la toma de datos y los bornes.
    // ELECTROMECANICO => no toma datos del Control y usa 4B.
    // ELECTRONICO => usa 3B; si no hay Control incluido, no puede tomar datos del Control.
    $tipoModuloNombre='';
    $stTipo=$conexion->prepare("SELECT tipo_modulo_nombre FROM senal_tipos_modulo WHERE tipo_modulo_id=? LIMIT 1");
    if($stTipo){
        $stTipo->bind_param('i',$tipoModulo);
        if($stTipo->execute()){
            $rsTipo=$stTipo->get_result();
            if($filaTipo=$rsTipo->fetch_assoc()) $tipoModuloNombre=senalNormalizarClave($filaTipo['tipo_modulo_nombre']??'');
        }
        $stTipo->close();
    }
    if(senalTieneControl($post) && $tipoModuloNombre!=='ELECTRONICO') throw new Exception('Con Control incluido, Senalizacion solo permite modulos ELECTRONICOS / 3 bornes.');
    if($tipoModuloNombre==='ELECTROMECANICO'){
        $usaDatosControl=false;
        $borne=2;
    } elseif($tipoModuloNombre==='ELECTRONICO'){
        if(!senalTieneControl($post)) $usaDatosControl=false;
        $borne=1;
    } elseif($modelo===5) {
        // Compatibilidad para tipos históricos/no identificados.
        $borne=2;
    } elseif($modelo===6) {
        $borne=($tension===2 ? 1 : 2);
    } elseif(!$usaDatosControl) {
        $borne=(int)($post['senal_borne_manual']??0);
    } else {
        $borne=1;
    }
    if($esOnix){
        if(!$tipoModulo||!$modelo||!in_array($tipoPuerta,array('PM','PA'),true)) {
            throw new Exception('Complete tipo de módulos, puerta y modelo de la botonera ONIX.');
        }
        $sqlOnix="SELECT m.codigo,mp.modelo_pulsador_id,mp.modelo_pulsador_nombre,tm.tension_modulo_nombre,b.borne_nombre,c.color_registro_nombre,t.tecla_nombre,tip.tipo_modulo_nombre
                  FROM matriz_botoneras_cabina m
                  INNER JOIN senal_modelos_pulsador mp ON mp.modelo_pulsador_id=m.modelo_pulsador_id
                  INNER JOIN senal_tensiones_modulo tm ON tm.tension_modulo_id=m.tension_modulo_id
                  INNER JOIN senal_bornes b ON b.borne_id=m.borne_id
                  INNER JOIN senal_colores_registro c ON c.color_registro_id=m.color_registro_id
                  INNER JOIN senal_teclas t ON t.tecla_id=m.tecla_id
                  INNER JOIN senal_tipos_modulo tip ON tip.tipo_modulo_id=?
                  WHERE m.tipo_puerta=? AND m.modelo_pulsador_id=? AND m.activo='SI'
                  ORDER BY m.matriz_botonera_id ASC LIMIT 1";
        $stOnix=$conexion->prepare($sqlOnix);
        if(!$stOnix) throw new Exception('Falta la matriz de botoneras ONIX.');
        $stOnix->bind_param('isi',$tipoModulo,$tipoPuerta,$modelo);
        $stOnix->execute();
        $mat=$stOnix->get_result()->fetch_assoc();
        $stOnix->close();
        if(!$mat) throw new Exception('El modelo ONIX elegido no tiene una configuración activa para la puerta seleccionada.');
        $mat['tipo_puerta']=$tipoPuerta;
        $mat['es_onix']=true;
        $mat['onix_tipo']=$onixTipo;
        $mat['es_pantalla']=$esPantalla;
        $mat['senal_perfil']=$perfil;
        return $mat;
    }

    if(!$tipoModulo||!$modelo||!$tension||!$color||!$tecla||!in_array($tipoPuerta,array('PM','PA'),true)||!in_array($borne,array(1,2),true)) {
        throw new Exception('Complete todos los datos obligatorios de la botonera de cabina.');
    }
    $sql="SELECT m.codigo,mp.modelo_pulsador_id,mp.modelo_pulsador_nombre,tm.tension_modulo_nombre,b.borne_nombre,c.color_registro_nombre,t.tecla_nombre,tip.tipo_modulo_nombre
          FROM matriz_botoneras_cabina m
          INNER JOIN senal_modelos_pulsador mp ON mp.modelo_pulsador_id=m.modelo_pulsador_id
          INNER JOIN senal_tensiones_modulo tm ON tm.tension_modulo_id=m.tension_modulo_id
          INNER JOIN senal_bornes b ON b.borne_id=m.borne_id
          INNER JOIN senal_colores_registro c ON c.color_registro_id=m.color_registro_id
          INNER JOIN senal_teclas t ON t.tecla_id=m.tecla_id
          INNER JOIN senal_tipos_modulo tip ON tip.tipo_modulo_id=?
          WHERE m.tipo_puerta=? AND m.modelo_pulsador_id=? AND m.tension_modulo_id=? AND m.borne_id=? AND m.color_registro_id=? AND m.tecla_id=? AND m.activo='SI' LIMIT 1";
    $st=$conexion->prepare($sql);
    if(!$st) throw new Exception('Falta la matriz de botoneras de cabina.');
    $st->bind_param('isiiiii',$tipoModulo,$tipoPuerta,$modelo,$tension,$borne,$color,$tecla);$st->execute();$mat=$st->get_result()->fetch_assoc();$st->close();
    if(!$mat) throw new Exception('La combinacion elegida no existe en la matriz de botoneras.');
    $mat['tipo_puerta']=$tipoPuerta;
    $mat['senal_perfil']=$perfil;
    return $mat;
}

function senalNormalizarClave($valor) {
    $v = strtoupper(trim((string)$valor));
    $v = strtr($v, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ñ'=>'N'));
    $v = preg_replace('/\s+/', ' ', $v);
    if ($v === 'ROND METAL') $v = 'METAL';
    if (strpos($v,'ONIX TELEFONICO')!==false) $v='ONIX TELEFONICO';
    elseif (strpos($v,'ONIX INDIVIDUALES')!==false || strpos($v,'ONIX PULS')!==false) $v='ONIX INDIVIDUALES';
    elseif (strpos($v,'PANTALLA 21')!==false || strpos($v,'PANTALLA TOUCH 21')!==false) $v='PANTALLA 21';
    return $v;
}

function senalConsultaCodigo($conexion, $tabla, $where) {
    $tabla = preg_replace('/[^a-zA-Z0-9_]/', '', (string)$tabla);
    if (!senalTablaExiste($conexion, $tabla)) throw new Exception('Falta la tabla '.$tabla.'. Ejecute la migracion SQL de Senalizacion.');
    $partes=array();$tipos='';$valores=array();
    foreach($where as $campo=>$valor){
        $campo=preg_replace('/[^a-zA-Z0-9_]/','',(string)$campo);
        $partes[]="UPPER(TRIM($campo))=?";$tipos.='s';$valores[]=senalNormalizarClave($valor);
    }
    $sql='SELECT codigo FROM `'.$tabla.'` WHERE '.implode(' AND ',$partes).' AND activo=1 LIMIT 1';
    $st=$conexion->prepare($sql); if(!$st) throw new Exception('No se pudo consultar '.$tabla.'.');
    $refs=array($tipos);foreach($valores as $k=>$v)$refs[]=&$valores[$k];call_user_func_array(array($st,'bind_param'),$refs);
    $st->execute();$fila=$st->get_result()->fetch_assoc();$st->close();
    return $fila?trim((string)$fila['codigo']):'';
}

function senalCodigoAdicionalParada($conexion, $mat) {
    $modelo=senalNormalizarClave($mat['modelo_pulsador_nombre']??'');
    $perfil=(array)($mat['senal_perfil']??array());
    $regla=strtoupper(trim((string)($perfil['regla_adicional_parada']??'')));
    $modeloReutilizado=senalNormalizarClave($perfil['modelo_adicional_parada']??'');
    if($regla==='REUTILIZAR_MODELO' && $modeloReutilizado!==''){
        $modelo=$modeloReutilizado;
    }elseif($modelo==='METAL'){
        return senalConsultaCodigo($conexion,'senal_adicional_parada_cabina',array('modelo'=>'METAL','tension'=>'24V','bornes'=>'3B','color'=>'BLANCO','tecla'=>'RELIEVE'));
    }
    $tension=senalNormalizarClave($mat['tension_modulo_nombre']??'');
    $bornes=senalNormalizarClave($mat['borne_nombre']??'');
    $color=senalNormalizarClave($mat['color_registro_nombre']??'');
    $tecla=senalNormalizarClave($mat['tecla_nombre']??'');

    $codigo=senalConsultaCodigo($conexion,'senal_adicional_parada_cabina',array(
        'modelo'=>$modelo,'tension'=>$tension,'bornes'=>$bornes,'color'=>$color,'tecla'=>$tecla
    ));
    if($codigo!=='') return $codigo;

    // Respaldo seguro: si la matriz historica tiene un borne inconsistente,
    // se acepta la fila solo cuando modelo+tension+color+tecla identifican
    // una unica parametrizacion activa. El codigo sigue saliendo de MySQL.
    $sql="SELECT codigo, COUNT(*) AS cantidad FROM senal_adicional_parada_cabina
          WHERE UPPER(TRIM(modelo))=? AND UPPER(TRIM(tension))=?
            AND UPPER(TRIM(color))=? AND UPPER(TRIM(tecla))=? AND activo=1
          GROUP BY codigo LIMIT 2";
    $st=$conexion->prepare($sql);
    if(!$st) return '';
    $st->bind_param('ssss',$modelo,$tension,$color,$tecla);
    $st->execute(); $rs=$st->get_result(); $filas=array();
    while($f=$rs->fetch_assoc()) $filas[]=$f;
    $st->close();
    return count($filas)===1 ? trim((string)$filas[0]['codigo']) : '';
}

function senalCodigoLlaveAscensorista($conexion,$tipoLlave,$mat){
    return senalConsultaCodigo($conexion,'senal_llave_ascensorista_cabina',array(
        'tipo_llave'=>$tipoLlave,'modelo_pulsador'=>$mat['modelo_pulsador_nombre']??'',
        'tecla'=>$mat['tecla_nombre']??'','color'=>$mat['color_registro_nombre']??''
    ));
}
function senalCodigoBrailleAscensorista($conexion,$mat){
    return senalConsultaCodigo($conexion,'senal_braille_lateral_ascensorista',array('modelo_pulsador'=>$mat['modelo_pulsador_nombre']??''));
}
function senalCodigoAdicLlaveElectromecanica($conexion,$mat){
    return senalConsultaCodigo($conexion,'senal_adic_llave_asc_electromecanico',array(
        'tipo_llave'=>'ELECTROMECANICO','modelo_pulsador'=>$mat['modelo_pulsador_nombre']??'',
        'tecla'=>$mat['tecla_nombre']??'','color'=>$mat['color_registro_nombre']??''
    ));
}
function senalCodigoIndicador($conexion,$modeloIndicador,$tipoModulos){
    return senalConsultaCodigo($conexion,'senal_indicadores_cabina',array('modelo_indicador'=>$modeloIndicador,'tipo_modulos'=>$tipoModulos));
}

/* v375 - Maestro A4000 / Repetidor A4400 para señalización destinada a otros controles.
 * La pareja se resuelve por familia física del indicador, nunca por el nombre literal completo. */
function senalCanonFamiliaIndicadorV375($valor){
    $v=mb_strtoupper(trim((string)$valor),'UTF-8');
    $v=preg_replace('/\s*\(\s*BASE\s+A4[04]00\s*\)\s*/u',' ',$v);
    $v=str_replace(array('Á','É','Í','Ó','Ú','Ü'),array('A','E','I','O','U','U'),$v);
    $v=preg_replace('/\s+/u',' ',trim($v));
    return $v;
}
function senalRolIndicadorV375($valor,$default='REPETIDOR'){
    $v=mb_strtoupper(trim((string)$valor),'UTF-8');
    if(in_array($v,array('MAESTRO','A4000','AUTONOMO','AUTÓNOMO'),true)) return 'MAESTRO';
    if(in_array($v,array('REPETIDOR','A4400','ELECTRONICO','ELECTRÓNICO'),true)) return 'REPETIDOR';
    return $default;
}
function senalFilaIndicadorPorCodigoV375($conexion,$codigo){
    $codigo=trim((string)$codigo); if($codigo==='') return null;
    $st=$conexion->prepare("SELECT id,modelo_indicador,tipo_modulos,codigo FROM senal_indicadores_cabina WHERE UPPER(TRIM(codigo))=UPPER(TRIM(?)) AND activo=1 ORDER BY orden,id LIMIT 1");
    if(!$st) throw new Exception($conexion->error); $st->bind_param('s',$codigo); $st->execute(); $f=$st->get_result()->fetch_assoc(); $st->close(); return $f?:null;
}
function senalFilaIndicadorPorModeloV375($conexion,$modelo){
    $modelo=trim((string)$modelo); if($modelo==='') return null;
    $st=$conexion->prepare("SELECT id,modelo_indicador,tipo_modulos,codigo FROM senal_indicadores_cabina WHERE UPPER(TRIM(modelo_indicador))=UPPER(TRIM(?)) AND activo=1 ORDER BY orden,id LIMIT 1");
    if(!$st) throw new Exception($conexion->error); $st->bind_param('s',$modelo); $st->execute(); $f=$st->get_result()->fetch_assoc(); $st->close(); return $f?:null;
}
function senalResolverIndicadorRolV375($conexion,$codigoOMod,$rol){
    $rol=senalRolIndicadorV375($rol,'REPETIDOR');
    $objetivo=$rol==='MAESTRO'?'ELECTROMECANICO':'ELECTRONICO';
    $origen=senalFilaIndicadorPorCodigoV375($conexion,$codigoOMod);
    if(!$origen) $origen=senalFilaIndicadorPorModeloV375($conexion,$codigoOMod);
    if(!$origen) throw new Exception('El indicador '.trim((string)$codigoOMod).' no existe o esta inactivo en senal_indicadores_cabina.');
    $familia=senalCanonFamiliaIndicadorV375($origen['modelo_indicador']??'');
    // Beaglebond/ONIX no participa de la lógica A4000/A4400.
    if(strpos($familia,'BEAGLEBOND')!==false) return $origen;
    $rs=$conexion->query("SELECT id,modelo_indicador,tipo_modulos,codigo FROM senal_indicadores_cabina WHERE activo=1 ORDER BY orden,id");
    if($rs) while($f=$rs->fetch_assoc()){
        if(senalNormalizarClave($f['tipo_modulos']??'')!==$objetivo) continue;
        if(senalCanonFamiliaIndicadorV375($f['modelo_indicador']??'')===$familia) return $f;
    }
    throw new Exception('El indicador '.$origen['modelo_indicador'].' no tiene pareja parametrizada como '.($rol==='MAESTRO'?'MAESTRO A4000':'REPETIDOR A4400').' en senal_indicadores_cabina.');
}
function senalParadasMaestroV375($post){
    $p=0;

    // Regla Automac V1.5: si el Maestro A4000 pertenece al indicador de la
    // botonera de cabina, sus paradas son las mismas de esa botonera. No se
    // solicita ni se mantiene una segunda cantidad manual para el autonomo.
    $modeloCab=trim((string)($post['senal_indicador_modelo']??''));
    $cantCab=max(0,(int)($post['senal_indicador_cantidad']??0));
    $rolCab=senalRolIndicadorV375($post['senal_indicador_rol']??'MAESTRO','MAESTRO');
    if($modeloCab!=='' && $cantCab>0 && $rolCab==='MAESTRO'){
        $cantidadBotoneras=max(1,(int)($post['senal_cantidad']??1));
        $paradasBotonera=senalParadasPorBotonera($post,$cantidadBotoneras);
        foreach($paradasBotonera as $valor){
            $p=max(0,(int)$valor);
            if($p>0) break;
        }
    }

    // Compatibilidad: para un Maestro A4000 que NO pertenece a la botonera de
    // cabina (por ejemplo pulsador + indicador / indicador suelto), conservar la
    // cantidad especifica existente.
    if($p<=0) $p=max(0,(int)($post['senal_indicador_maestro_paradas']??0));

    // v379: si el Maestro A4000 esta dentro de un pulsador + indicador,
    // las paradas viajan tambien dentro del JSON del item. Esto permite
    // reabrir/modificar documentos sin depender exclusivamente del campo global.
    if($p<=0){
        $items=senalPulsadoresItemsV188($post);
        if(is_array($items)) foreach($items as $it){
            $fam=strtoupper(trim((string)($it['familia']??'')));
            if(substr($fam,-3)!=='_IP') continue;
            if(senalRolIndicadorV375($it['indicador_rol']??'REPETIDOR')!=='MAESTRO') continue;
            $p=max(0,(int)($it['paradas_maestro']??0));
            if($p>0) break;
        }
    }
    if($p<=0) throw new Exception('Defina la cantidad de paradas del indicador maestro A4000 para calcular APPIND y el material de hueco.');
    return $p;
}
function senalRolesIndicadoresV375($post){
    $roles=array();
    $modelo=trim((string)($post['senal_indicador_modelo']??'')); $cant=max(0,(int)($post['senal_indicador_cantidad']??0));
    if($modelo!=='' && $cant>0) $roles[]=senalRolIndicadorV375($post['senal_indicador_rol']??'MAESTRO','MAESTRO');
    $puls=senalPulsadoresItemsV188($post);
    if(is_array($puls)) foreach($puls as $it){
        $fam=strtoupper(trim((string)($it['familia']??''))); if(substr($fam,-3)!=='_IP') continue;
        if((int)($it['cantidad']??0)<=0 || trim((string)($it['indicador_codigo']??''))==='') continue;
        $def=senalNormalizarClave($it['tipo']??'')==='ELECTROMECANICO'?'MAESTRO':'REPETIDOR';
        $roles[]=senalRolIndicadorV375($it['indicador_rol']??$def,$def);
    }
    $inds=senalIndicadoresExteriorItemsV190($post);
    if(is_array($inds)) foreach($inds as $it){
        if((int)($it['cantidad']??0)<=0 || trim((string)($it['codigo']??''))==='') continue;
        $def=senalNormalizarClave($it['tipo']??'')==='ELECTROMECANICO'?'MAESTRO':'REPETIDOR';
        $roles[]=senalRolIndicadorV375($it['rol']??$def,$def);
    }
    return $roles;
}
function senalValidarMaestroV375($post){
    $esElectromecanico=array_key_exists('senal_tiene_control',$post) ? ((string)$post['senal_tiene_control']!=='1') : !senalTieneControl($post);
    if(!$esElectromecanico) return;
    $roles=senalRolesIndicadoresV375($post); if(!$roles) return;
    $m=0; foreach($roles as $r) if($r==='MAESTRO') $m++;
    if($m===0) throw new Exception('En equipos electromecanicos debe definir un indicador como MAESTRO A4000. Los demas indicadores deben ser REPETIDORES A4400.');
    if($m>1) throw new Exception('Solo puede existir un indicador MAESTRO A4000 por sistema de señalizacion. Marque los restantes como REPETIDOR A4400.');
    senalParadasMaestroV375($post);
}
function senalAgregarAppindMaestroV375(&$lineas,$conexion,$listaId,$modeloMaestro,$cantidad,$post,$ubicacion='indicador maestro'){
    $cantidad=max(0,(int)$cantidad); if($cantidad<=0) return;
    $paradas=senalParadasMaestroV375($post);
    $codApp=senalCodigoAdicParadaIndicador($conexion,$modeloMaestro,'ELECTROMECANICO');
    if($codApp==='') throw new Exception('El indicador maestro '.$modeloMaestro.' no tiene parametrizado APPIND en senal_adic_parada_indicador_cabina.');
    $cantApp=$paradas*$cantidad;
    senalAgregarLinea($lineas,$conexion,$listaId,'Adicional por parada en indicador maestro',$codApp,'APPIND · '.$ubicacion.' A4000',$cantApp,$paradas.' parada(s) × '.$cantidad.' maestro(s) = '.$cantApp);
}
function senalCanonModeloIndicadorAppind($valor){
    // v370: la matriz historica usa nombres cortos (31MM R / 31MM Z), mientras
    // el catalogo puede entregar descripciones largas como 31MM ROJO (BASE A4000).
    // Canonizamos ambos lados para no obligar a duplicar filas en la matriz.
    $v=strtoupper(trim((string)$valor));
    $v=preg_replace('/\([^)]*\)/',' ',$v); // quita aclaraciones: (BASE A4000), etc.
    $v=str_replace(array('Á','É','Í','Ó','Ú','Ü'),array('A','E','I','O','U','U'),$v);
    $v=preg_replace('/\bROJO\b/','R',$v);
    $v=preg_replace('/\bAZUL\b/','Z',$v);
    $v=preg_replace('/\s+/',' ',$v);
    return trim($v);
}
function senalCodigoAdicParadaIndicador($conexion,$modeloIndicador,$tipoModulos){
    // v370: primero coincidencia exacta. Luego coincidencia canonica por modelo,
    // tolerando aliases historicos (R=ROJO, Z=AZUL) y sufijos como (BASE A4000).
    $codigo=senalConsultaCodigo($conexion,'senal_adic_parada_indicador_cabina',array(
        'modelo_indicador'=>$modeloIndicador,
        'tipo_modulos'=>$tipoModulos
    ));
    if($codigo!=='') return $codigo;

    $modelo=senalNormalizarClave($modeloIndicador);
    $sql="SELECT codigo FROM senal_adic_parada_indicador_cabina WHERE UPPER(TRIM(modelo_indicador))=? AND activo=1 ORDER BY id LIMIT 1";
    $st=$conexion->prepare($sql);
    if($st){
        $st->bind_param('s',$modelo);
        $st->execute();
        $fila=$st->get_result()->fetch_assoc();
        $st->close();
        if($fila) return trim((string)$fila['codigo']);
    }

    $canonBuscado=senalCanonModeloIndicadorAppind($modeloIndicador);
    $tipoBuscado=senalNormalizarClave($tipoModulos);
    $rs=$conexion->query("SELECT modelo_indicador,tipo_modulos,codigo FROM senal_adic_parada_indicador_cabina WHERE activo=1 ORDER BY id");
    if($rs){
        $fallback='';
        while($f=$rs->fetch_assoc()){
            if(senalCanonModeloIndicadorAppind($f['modelo_indicador']??'')!==$canonBuscado) continue;
            $cod=trim((string)($f['codigo']??''));
            if($cod==='') continue;
            if(senalNormalizarClave($f['tipo_modulos']??'')===$tipoBuscado) return $cod;
            if($fallback==='') $fallback=$cod;
        }
        if($fallback!=='') return $fallback;
    }
    return '';
}
function senalCodigoAdicionalCabina($conexion,$adicional){
    return senalConsultaCodigo($conexion,'senal_adicionales_cabina',array('adicional'=>$adicional));
}
function senalCodigoEspecialCabina($conexion,$adicional){
    return senalConsultaCodigo($conexion,'senal_adicionales_especiales_cabina',array('adicional'=>$adicional));
}

function senalCoeficienteAcabadoV190($conexion,$acabado,$medidaEspecial,$politica='COEFICIENTE'){
    if(strtoupper(trim((string)$politica))==='INCLUIDO_EN_PRECIO') return 1.0;
    $acabado=strtoupper(trim((string)$acabado)); if($acabado==='')$acabado='ACERO';
    $especial=$medidaEspecial?1:0;
    if(!senalTablaExiste($conexion,'senal_acabados_coeficientes')) throw new Exception('Falta ejecutar la migracion v190 de acabados de Senalizacion.');
    $st=$conexion->prepare("SELECT coeficiente FROM senal_acabados_coeficientes WHERE UPPER(TRIM(acabado))=? AND medida_especial=? AND activo=1 LIMIT 1");
    if(!$st) throw new Exception($conexion->error);$st->bind_param('si',$acabado,$especial);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    if(!$f || $f['coeficiente']===null || (float)$f['coeficiente']<=0) throw new Exception('El coeficiente para tapa '.$acabado.($especial?' con medida especial':'').' esta A DEFINIR.');
    return (float)$f['coeficiente'];
}
function senalTextoAcabadoV190($acabado,$especial){$a=strtoupper(trim((string)$acabado));if($a==='')$a='ACERO';return $a.($especial?' · MEDIDA ESPECIAL':'');}
function senalAgregarLinea(&$lineas, $conexion, $listaId, $concepto, $codigo, $descripcion, $cantidad, $formula='', $bonificado=false, $coeficiente=1.0, $detalleCoef='') {
    $cantidad=(float)$cantidad;
    if ($cantidad <= 0 || trim((string)$codigo)==='') return;
    $prod=senalProductoValorizado($conexion,$listaId,$codigo);
    if(!$prod){$cc=senalCodigoCotizacion($conexion,$codigo);throw new Exception('El codigo '.$codigo.' ('.$concepto.') se cotiza con '.$cc.' y no tiene precio valido en la base seleccionada.');}
    $coeficiente=(float)$coeficiente;if($coeficiente<=0)$coeficiente=1.0;$unitAjustado=$bonificado?0.0:(float)ceil((float)$prod['unitario']*$coeficiente);
    $detalleCoefFormula = $detalleCoef;
    if ($concepto === 'Pulsador exterior simple' && stripos($descripcion, 'ROND METAL') !== false) {
        $descripcion = preg_replace('/^Pulsador exterior simple - ROND METAL(?: - Medida (.+))?$/iu', 'Pulsador simple - Rond Metal - Medida $1', $descripcion);
        $descripcion = preg_replace('/Medida\s*$/u', 'Medida a confirmar', $descripcion);
        $descripcion = preg_replace('/A CONFIRMAR/iu', 'a confirmar', $descripcion);
        $detalleCoef = preg_replace('/\s*×\s*1(?:[,.]0+)?$/u', '', $detalleCoef);
        $detalleCoef = str_ireplace('ACERO', 'Acero', $detalleCoef);
        $descripcion .= ' · ' . $detalleCoef . '.';
        $detalleCoef = '';
    }
    $lineas[]=array(
        'modulo'=>'SENALIZACION','concepto'=>$concepto,'codigo'=>$codigo,'descripcion'=>$descripcion.($detalleCoef!==''?' · '.$detalleCoef:''),
        'cantidad'=>$cantidad,'unitario'=>$unitAjustado,
        'formula'=>($formula!==''?$formula:($cantidad.' unidad(es)')).($detalleCoefFormula!==''?' · '.$detalleCoefFormula:'').($bonificado?' · BONIFICADO':''),
        'total'=>$unitAjustado*$cantidad,
        'precio_referencia'=>$unitAjustado,'bonificado'=>(bool)$bonificado,
        'costo'=>(float)$prod['costo'],'utilidad'=>(float)$prod['utilidad'],
        'origen_precio'=>(string)($prod['origen_precio']??'LISTA_SELECCIONADA'),
        'precio_historico'=>!empty($prod['precio_historico']),
        'bejerman_lista_id'=>$prod['bejerman_lista_id']??null,
        'bejerman_fecha'=>$prod['bejerman_fecha']??null,
        'bejerman_archivo'=>$prod['bejerman_archivo']??null
    );
}


/* v474 - Permite conservar un código comercial/técnico en la cotización y
 * valorizarlo con una referencia Bejerman confirmada. Se usa para A7601C,
 * cuyo precio corresponde a P7600V2. */
function senalAgregarLineaConReferenciaPrecio(&$lineas,$conexion,$listaId,$concepto,$codigoMostrar,$codigoPrecio,$descripcion,$cantidad,$formula=''){
    $cantidad=(float)$cantidad;
    if($cantidad<=0 || trim((string)$codigoMostrar)==='' || trim((string)$codigoPrecio)==='') return;
    $prod=senalProductoValorizado($conexion,$listaId,$codigoPrecio);
    if(!$prod) throw new Exception('El código '.$codigoMostrar.' se valoriza con '.$codigoPrecio.' y esa referencia no tiene precio válido en la base seleccionada.');
    $unitario=(float)$prod['unitario'];
    $lineas[]=array(
        'modulo'=>'SENALIZACION','concepto'=>$concepto,'codigo'=>$codigoMostrar,
        'descripcion'=>$descripcion,'cantidad'=>$cantidad,'unitario'=>$unitario,
        'formula'=>($formula!==''?$formula:($cantidad.' unidad(es)')).' · precio ref. '.$codigoPrecio,
        'total'=>$unitario*$cantidad,'precio_referencia'=>$unitario,'bonificado'=>false,
        'costo'=>(float)$prod['costo'],'utilidad'=>(float)$prod['utilidad'],
        'origen_precio'=>'REFERENCIA_'.$codigoPrecio,'precio_historico'=>!empty($prod['precio_historico']),
        'bejerman_lista_id'=>$prod['bejerman_lista_id']??null,'bejerman_fecha'=>$prod['bejerman_fecha']??null,
        'bejerman_archivo'=>$prod['bejerman_archivo']??null
    );
}

/* v183 - Los pulsadores + IP se presentan y valorizan como un solo item compuesto.
 * Se conservan ambos codigos en la linea para que Produccion/OF identifique los dos
 * componentes, pero comercialmente el unitario es pulsador + indicador. */
function senalAgregarLineaPulsadorConIndicador(&$lineas,$conexion,$listaId,$concepto,$codigoPulsador,$modeloPulsador,$codigoIndicador,$modeloIndicador,$cantidad,$detalleTecnico='',$coefPulsador=1.0,$detalleCoef=''){
    $cantidad=(float)$cantidad;
    $codigoPulsador=trim((string)$codigoPulsador);$codigoIndicador=trim((string)$codigoIndicador);
    if($cantidad<=0 || $codigoPulsador==='' || $codigoIndicador==='') return;
    $p=senalProductoValorizado($conexion,$listaId,$codigoPulsador);
    if(!$p){$cc=senalCodigoCotizacion($conexion,$codigoPulsador);throw new Exception('El codigo '.$codigoPulsador.' ('.$concepto.') se cotiza con '.$cc.' y no tiene precio valido en la base seleccionada.');}
    $i=senalProductoValorizado($conexion,$listaId,$codigoIndicador);
    if(!$i){$cc=senalCodigoCotizacion($conexion,$codigoIndicador);throw new Exception('El codigo '.$codigoIndicador.' (indicador de '.$concepto.') se cotiza con '.$cc.' y no tiene precio valido en la base seleccionada.');}
    $coefPulsador=(float)$coefPulsador;if($coefPulsador<=0)$coefPulsador=1.0;
    $unit=(float)ceil((float)$p['unitario']*$coefPulsador)+(float)$i['unitario'];
    $costo=(float)$p['costo']+(float)$i['costo'];
    $util=$costo>0?$unit/$costo:0.0;
    $historico=!empty($p['precio_historico']) || !empty($i['precio_historico']);
    $descripcion='Modelo pulsador: '.trim((string)$modeloPulsador).' · Indicador: '.trim((string)$modeloIndicador);
    if($detalleTecnico!=='') $descripcion.=' · '.$detalleTecnico;if($detalleCoef!=='')$descripcion.=' · '.$detalleCoef;
    if ($concepto === 'Pulsador exterior simple + IP' && stripos($detalleTecnico, 'ROND METAL') !== false) {
        $medida = 'a confirmar';
        if (preg_match('/Medida\s+([^·]+)/iu', $detalleTecnico, $m)) $medida = strtolower(trim($m[1]));
        $indicador = preg_replace('/\b31\s*mm\b/iu', '31 mm', trim((string)$modeloIndicador));
        $indicador = preg_replace('/\s*-\s*electronico\b/iu', '', $indicador);
        $indicador = preg_replace('/\bBLANCO\b/iu', 'blanco', $indicador);
        $tapa = preg_replace('/\s*×\s*1(?:[,.]0+)?$/u', '', $detalleCoef);
        $descripcion = 'Pulsador simple - Rond Metal + Indicador ' . $indicador . ' · Medida ' . $medida . ' · ' . str_ireplace('ACERO', 'Acero', $tapa) . '.';
    }
    $lineas[]=array(
        'modulo'=>'SENALIZACION','concepto'=>$concepto,
        'codigo'=>$codigoPulsador.' + '.$codigoIndicador,
        'descripcion'=>$descripcion,
        'cantidad'=>$cantidad,'unitario'=>$unit,
        'formula'=>$cantidad.' conjunto(s) · Pulsador '.$codigoPulsador.' + Indicador '.$codigoIndicador,
        'total'=>$unit*$cantidad,'precio_referencia'=>$unit,'bonificado'=>false,
        'costo'=>$costo,'utilidad'=>$util,
        'origen_precio'=>'COMPUESTO_BEJERMAN',
        'precio_historico'=>$historico,
        'bejerman_lista_id'=>$p['bejerman_lista_id']??($i['bejerman_lista_id']??null),
        'bejerman_fecha'=>$p['bejerman_fecha']??($i['bejerman_fecha']??null),
        'bejerman_archivo'=>$p['bejerman_archivo']??($i['bejerman_archivo']??null),
        'componentes'=>array(
            array('codigo'=>$codigoPulsador,'modelo'=>trim((string)$modeloPulsador),'tipo'=>'PULSADOR','unitario'=>(float)$p['unitario']),
            array('codigo'=>$codigoIndicador,'modelo'=>trim((string)$modeloIndicador),'tipo'=>'INDICADOR','unitario'=>(float)$i['unitario'])
        )
    );
}

function senalCantidadSeleccionada($post,$flag,$campoCantidad,$cantidadDefault){
    if(empty($post[$flag])) return 0;
    $v=(int)($post[$campoCantidad]??0);
    return $v>0?$v:max(1,(int)$cantidadDefault);
}

function senalTieneControl($post){
    if(array_key_exists('incluir_control',$post)) return (string)$post['incluir_control']==='1';
    if(array_key_exists('senal_tiene_control',$post)) return (string)$post['senal_tiene_control']==='1';
    return false;
}


function senalSoloComunicacionSerieDesdeControl($post) {
    $desdeControl = !empty($post['senal_comunicacion_serie_desde_control']);
    $tipo = senalNormalizarClave($post['senal_comunicacion_serie_tipo'] ?? '');
    if (!$desdeControl || !in_array($tipo, array('EN CABINA','TOTAL'), true)) return false;

    // Si ya se eligio un modelo de pulsador, existe intencion de cotizar botonera
    // y debe completarse la matriz normalmente. Este modo especial solo corresponde
    // cuando Senalizacion lleva exclusivamente la placa A3540 impuesta por Control.
    if ((int)($post['senal_modelo'] ?? 0) > 0) return false;
    $itemsV188=senalPulsadoresItemsV188($post); if(is_array($itemsV188) && count($itemsV188)>0) return false;

    // No ignorar silenciosamente otros componentes de Senalizacion. Si el usuario
    // selecciono alguno, debe completar tambien la botonera para poder cotizarlo.
    if (trim((string)($post['senal_indicador_modelo'] ?? '')) !== '' && (int)($post['senal_indicador_cantidad'] ?? 0) > 0) return false;
    if (trim((string)($post['senal_llave_ascensorista_tipo'] ?? '')) !== '') return false;
    if (!empty($post['senal_logo_grabado'])) return false;
    if (!empty($post['senal_sint_a7601c']) || !empty($post['senal_sint_a4820sv'])) return false;
    if (trim((string)($post['senal_pesador_frente_codigo'] ?? '')) !== '') return false;
    if (trim((string)($post['senal_control_acceso_tecnologia'] ?? '')) !== '') return false;
    $sel = $post['senal_adicional_sel'] ?? array();
    if (is_array($sel)) {
        foreach ($sel as $v) if (!empty($v)) return false;
    }
    foreach (array(
        'senal_adicional_pano_cantidad','senal_llave_independiente','senal_calado_pesador',
        'senal_intercomunicador','senal_telefono_manos_libres','senal_fuente_intercom',
        'senal_luz_emergencia','senal_acces_voz','senal_botonera_cableada',
        'senal_mensajes_especiales_incluir'
    ) as $campo) {
        if (!empty($post[$campo])) return false;
    }
    return true;
}

function senalCalcularSoloComunicacionSerieDesdeControl($conexion, $listaId, $post) {
    $cantidad = max(1, (int)($post['senal_cant_comunicacion_serie'] ?? $post['cantidad_equipos'] ?? 1));
    $tipo = senalNormalizarClave($post['senal_comunicacion_serie_tipo'] ?? 'EN CABINA');
    $lineas = array();
    senalAgregarLinea(
        $lineas, $conexion, $listaId,
        'Placa de comunicacion serie A3540', 'P3540',
        'Comunicacion serie '.$tipo.' requerida por Control',
        $cantidad, $cantidad.' equipo(s)'
    );
    $total = 0.0;
    foreach ($lineas as $l) $total += (float)$l['total'];
    return array(
        'modo_solo_comunicacion'=>true,
        'matriz'=>array(
            'modelo_pulsador_nombre'=>'Sin botonera',
            'tipo_puerta'=>'—',
            'tension_modulo_nombre'=>'—',
            'borne_nombre'=>'—',
            'color_registro_nombre'=>'—',
            'tecla_nombre'=>'—',
            'tipo_modulo_nombre'=>'—'
        ),
        'lineas'=>$lineas,
        'total_bruto'=>$total,
        'codigo_adicional_parada'=>'',
        'paradas_totales'=>0,
        'paradas_incluidas_base'=>0,
        'paradas_adicionales'=>0,
        'cantidad_botoneras'=>0,
        'caracteristicas'=>array('Señalización sin botonera: solo placa de comunicación serie A3540 requerida por Control.'),
        'tipo_modulos'=>'',
    );
}

function senalParadasPorBotonera(array $post, int $cantidad): array {
    $cantidad = max(1, $cantidad);
    $legacy = max(0, (int)($post['senal_paradas'] ?? 0));
    $raw = $post['senal_paradas_equipo'] ?? array();
    if (!is_array($raw)) $raw = array($raw);
    $salida = array();
    for ($i=0; $i<$cantidad; $i++) {
        $valor = isset($raw[$i]) && trim((string)$raw[$i]) !== '' ? (int)$raw[$i] : $legacy;
        $salida[] = max(0, $valor);
    }
    return $salida;
}

function senalMedidasPorBotonera(array $post, int $cantidad): array {
    $cantidad = max(1, $cantidad);
    $legacy = trim((string)($post['senal_medidas'] ?? ''));
    $raw = $post['senal_medidas_equipo'] ?? array();
    if (!is_array($raw)) $raw = array($raw);
    $salida = array();
    for ($i=0; $i<$cantidad; $i++) {
        $valor = isset($raw[$i]) ? trim((string)$raw[$i]) : '';
        // Compatibilidad histórica: un documento viejo tenía una única medida global.
        if ($valor === '' && empty($raw) && $legacy !== '') $valor = $legacy;
        $salida[] = $valor;
    }
    return $salida;
}


/* v179 - Pulsadores exteriores parametrizados. */
function senalModeloPulsadorNombrePorId($conexion,$id){
    $id=(int)$id; if($id<=0)return '';
    $st=$conexion->prepare("SELECT modelo_pulsador_nombre FROM senal_modelos_pulsador WHERE modelo_pulsador_id=? LIMIT 1");
    if(!$st)return ''; $st->bind_param('i',$id);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    return senalNormalizarClave($f['modelo_pulsador_nombre']??'');
}
function senalPerfilModeloPorNombre($conexion,$nombre){
    $nombre=strtoupper(trim((string)$nombre));
    if($nombre==='METAL')$nombre='ROND METAL';
    $st=$conexion->prepare('SELECT modelo_pulsador_id FROM senal_modelos_pulsador WHERE UPPER(TRIM(modelo_pulsador_nombre))=? LIMIT 1');
    if(!$st)return array();$st->bind_param('s',$nombre);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    return $f?senalPerfilModelo($conexion,(int)$f['modelo_pulsador_id']):array();
}
function senalAgregarPulsadorIndicadorPerfil(&$lineas,$conexion,$listaId,$concepto,$codigoPulsador,$modeloPulsador,$codigoIndicador,$modeloIndicador,$cantidad,$detalleTecnico,$coeficiente,$detalleCoef,$perfil){
    if(empty($perfil['separar_indicador_exterior'])){
        senalAgregarLineaPulsadorConIndicador($lineas,$conexion,$listaId,$concepto,$codigoPulsador,$modeloPulsador,$codigoIndicador,$modeloIndicador,$cantidad,$detalleTecnico,$coeficiente,$detalleCoef);
        return;
    }
    $detallePulsador=trim((string)$detalleTecnico);
    if(strtoupper(trim((string)($perfil['politica_acabado']??'')))==='INCLUIDO_EN_PRECIO'){
        $detallePulsador=trim($detallePulsador.' · Acabado '.(string)($perfil['acabado']??'').' incluido en el precio');
    }
    senalAgregarLinea($lineas,$conexion,$listaId,$concepto,$codigoPulsador,$modeloPulsador.' · '.$detallePulsador,$cantidad,$cantidad.' pulsador(es)',false,$coeficiente,$detalleCoef);
    senalAgregarLinea($lineas,$conexion,$listaId,'Indicador de posición para pulsador exterior',$codigoIndicador,$modeloIndicador.' · '.$detalleTecnico,$cantidad,$cantidad.' indicador(es) asociado(s) al pulsador exterior');
}
function senalResolverPulsadorExterior($conexion,$familia,$post,$matCabina){
    if(!senalTablaExiste($conexion,'senal_pulsadores_exteriores_matriz')) throw new Exception('Falta la matriz de Pulsadores exteriores. Ejecute la migracion v179.');
    $pref='senal_ext_'.strtolower($familia).'_';
    $campoCant=array('SIMPLE'=>'senal_pulsadores_simples_cantidad','SIMPLE_IP'=>'senal_pulsadores_simples_indicador_cantidad','DOBLE'=>'senal_pulsadores_dobles_cantidad','DOBLE_IP'=>'senal_pulsadores_dobles_indicador_cantidad');
    $cantidad=max(0,(int)($post[$campoCant[$familia]??($pref.'cantidad')]??0));
    $agregado=!empty($post[$pref.'agregado']);
    if($cantidad<=0 || !$agregado) return null;
    $mismo=!array_key_exists($pref.'mismo_modelo',$post) || !empty($post[$pref.'mismo_modelo']);
    $modelo=$mismo?senalModeloPulsadorNombrePorId($conexion,(int)($post['senal_modelo']??0)):senalNormalizarClave($post[$pref.'modelo']??'');
    if($modelo==='') throw new Exception('Seleccione el modelo de pulsador exterior para '.str_replace('_',' + ',$familia).'.');
    $tipo=senalNormalizarClave($post[$pref.'tipo']??($matCabina['tipo_modulo_nombre']??''));
    $perfilExterior=$mismo?senalPerfilModelo($conexion,(int)($post['senal_modelo']??0)):senalPerfilModeloPorNombre($conexion,$modelo);
    $modoExterior=strtoupper(trim((string)($perfilExterior['modo_pulsador_exterior']??'')));
    if($modoExterior==='MODELO' || $modelo==='METAL'){
        $tipoRequerido=strtoupper(trim((string)($perfilExterior['tipo_modulo_requerido']??'')));
        if($tipoRequerido!=='' && $tipo!==$tipoRequerido) throw new Exception('El perfil del pulsador exterior requiere módulos '.$tipoRequerido.'.');
        $st=$conexion->prepare('SELECT * FROM senal_pulsadores_exteriores_matriz WHERE familia=? AND UPPER(TRIM(modelo_pulsador))=? AND UPPER(TRIM(tipo_modulos))=? AND activo=1 ORDER BY orden,id LIMIT 1');
        if(!$st)throw new Exception($conexion->error);
        $fam=strtoupper(trim((string)$familia));$modeloDb=$modelo;$tipoDb=$tipo;$st->bind_param('sss',$fam,$modeloDb,$tipoDb);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
        if(!$f)throw new Exception('No existe una combinación exterior activa para '.$modelo.' / '.$fam.' / '.$tipo.'.');
        $f['cantidad']=$cantidad;$f['mismo_modelo']=$mismo;$f['_perfil_modelo']=$perfilExterior;
        $f['es_modelo_perfil']=true;
        return $f;
    }

    $color=senalNormalizarClave($post[$pref.'color']??($matCabina['color_registro_nombre']??''));
    $tension=senalNormalizarClave($post[$pref.'tension']??($matCabina['tension_modulo_nombre']??''));
    $bornes=senalNormalizarClave($post[$pref.'bornes']??($matCabina['borne_nombre']??''));
    if(senalTieneControl($post) && $bornes!=='' && $bornes!=='3B') throw new Exception('Con Control incluido, los pulsadores exteriores solo admiten 3 bornes.');
    $tecla=senalNormalizarClave($post[$pref.'tecla']??($matCabina['tecla_nombre']??''));
    if($tipo===''||$color===''||$tension===''||$bornes===''||$tecla==='') throw new Exception('Complete la combinacion del pulsador exterior '.str_replace('_',' + ',$familia).'.');
    $sql="SELECT * FROM senal_pulsadores_exteriores_matriz WHERE familia=? AND UPPER(TRIM(tipo_modulos))=? AND UPPER(TRIM(modelo_pulsador))=? AND UPPER(TRIM(color_registro))=? AND UPPER(TRIM(tension_modulos))=? AND UPPER(TRIM(bornes))=? AND UPPER(TRIM(tecla_modulos))=? AND activo=1 LIMIT 1";
    $st=$conexion->prepare($sql); if(!$st) throw new Exception($conexion->error);
    $st->bind_param('sssssss',$familia,$tipo,$modelo,$color,$tension,$bornes,$tecla);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    if(!$f) throw new Exception('No existe combinacion en la matriz para '.str_replace('_',' + ',$familia).': '.$tipo.' / '.$modelo.' / '.$color.' / '.$tension.' / '.$bornes.' / '.$tecla.'.');
    $f['cantidad']=$cantidad;$f['mismo_modelo']=$mismo;
    return $f;
}
function senalResolverIndicadorExteriorPulsador($conexion,$familia,$tipoModulos,$codigoIpCabina='',$codigoSeleccionado=''){
    if(!senalTablaExiste($conexion,'senal_pulsadores_exteriores_indicadores')) throw new Exception('Falta la matriz de Indicadores para Pulsadores exteriores. Ejecute la migracion v179.');
    $familia=strtoupper(trim((string)$familia));$tipo=senalNormalizarClave($tipoModulos);
    $codigoSel=strtoupper(trim((string)$codigoSeleccionado));$dep=strtoupper(trim((string)$codigoIpCabina));
    if($codigoSel!==''){
        if(!senalTablaExiste($conexion,'senal_indicadores_cabina')) throw new Exception('Falta la tabla maestra de indicadores.');
        $st=$conexion->prepare("SELECT modelo_indicador AS modelo,tipo_modulos,codigo FROM senal_indicadores_cabina WHERE UPPER(TRIM(tipo_modulos))=? AND UPPER(TRIM(codigo))=? AND activo=1 ORDER BY orden,id LIMIT 1");
        if(!$st) throw new Exception($conexion->error);$st->bind_param('ss',$tipo,$codigoSel);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
        if(!$f) throw new Exception('El indicador exterior seleccionado no existe en la tabla maestra para tipo '.$tipo.'.');
        if(!senalIndicadorPermitidoContexto($conexion,$codigoSel,'PULSADOR_EXTERIOR'))throw new Exception('El indicador seleccionado no está permitido en pulsadores exteriores.');
        return $f;
    }
    // Compatibilidad v179/v180: si no existe selección explícita, intenta heredar el IP de cabina.
    if($dep==='') throw new Exception('Seleccione el Indicador exterior para '.str_replace('_',' + ',$familia).'.');
    $st=$conexion->prepare("SELECT * FROM senal_pulsadores_exteriores_indicadores WHERE familia=? AND UPPER(TRIM(tipo_modulos))=? AND BINARY depende_codigo_ip_cabina=BINARY ? AND activo=1 ORDER BY orden,id LIMIT 1");
    if(!$st) throw new Exception($conexion->error);$st->bind_param('sss',$familia,$tipo,$dep);$st->execute();$f=$st->get_result()->fetch_assoc();$st->close();
    if(!$f) throw new Exception('No existe indicador exterior para '.$familia.' con '.$tipo.' y el indicador de cabina '.$dep.'.');
    if(!senalIndicadorPermitidoContexto($conexion,$f['codigo']??'','PULSADOR_EXTERIOR'))throw new Exception('El indicador seleccionado no está permitido en pulsadores exteriores.');
    return $f;
}


/* v188 - Configuraciones multiples de Pulsadores exteriores. El JSON guarda una
 * fila por configuracion; los campos v179/v187 siguen disponibles como fallback
 * para documentos historicos. */
function senalPulsadoresItemsV188($post){
    if(!array_key_exists('senal_pulsadores_items_json',$post)) return null;
    $raw=trim((string)($post['senal_pulsadores_items_json']??''));
    if($raw==='') return array();
    $items=json_decode($raw,true);
    if(!is_array($items)) throw new Exception('La lista de Pulsadores exteriores no es valida.');
    return array_values(array_filter($items,function($x){return is_array($x) && !empty($x['familia']) && (int)($x['cantidad']??0)>0;}));
}
function senalResolverPulsadorExteriorItemV188($conexion,$item,$post,$matCabina){
    $fam=strtoupper(trim((string)($item['familia']??'')));
    if(!in_array($fam,array('SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'),true)) throw new Exception('Familia de pulsador exterior invalida.');
    $pref='senal_ext_'.strtolower($fam).'_';
    $tmp=$post;
    $campoCant=array('SIMPLE'=>'senal_pulsadores_simples_cantidad','SIMPLE_IP'=>'senal_pulsadores_simples_indicador_cantidad','DOBLE'=>'senal_pulsadores_dobles_cantidad','DOBLE_IP'=>'senal_pulsadores_dobles_indicador_cantidad');
    $tmp[$campoCant[$fam]]=(int)($item['cantidad']??0);$tmp[$pref.'agregado']=1;$tmp[$pref.'mismo_modelo']=!empty($item['mismo_modelo'])?1:0;
    foreach(array('modelo','tipo','color','tension','bornes','tecla') as $k) $tmp[$pref.$k]=(string)($item[$k]??'');
    if(isset($item['indicador_codigo'])) $tmp[$pref.'indicador_codigo']=(string)$item['indicador_codigo'];
    $cfg=senalResolverPulsadorExterior($conexion,$fam,$tmp,$matCabina);
    if($cfg){$cfg['_familia']=$fam;$cfg['_indicador_codigo']=trim((string)($item['indicador_codigo']??''));$cfg['_indicador_modelo']=trim((string)($item['indicador_modelo']??''));$cfg['_indicador_rol']=senalRolIndicadorV375($item['indicador_rol']??(senalNormalizarClave($item['tipo']??'')==='ELECTROMECANICO'?'MAESTRO':'REPETIDOR'));$cfg['_paradas_maestro']=max(0,(int)($item['paradas_maestro']??0));$cfg['_nomenclatura']=trim((string)($item['nomenclatura']??''));$cfg['_medida']=trim((string)($item['medida']??''));$perfil=(array)($cfg['_perfil_modelo']??array());$politica=strtoupper(trim((string)($perfil['politica_acabado']??'COEFICIENTE')));$cfg['_acabado']=$politica==='INCLUIDO_EN_PRECIO'?strtoupper(trim((string)($perfil['acabado']??'ACERO'))):strtoupper(trim((string)($item['acabado']??'ACERO')));$cfg['_politica_acabado']=$politica;$cfg['_medida_especial']=$politica==='INCLUIDO_EN_PRECIO'?false:!empty($item['medida_especial']);}
    return $cfg;
}

/* v190 - Indicadores exteriores individuales: lista de configuraciones con cantidad y medida por item. */
function senalIndicadoresExteriorItemsV190($post){
    if(!array_key_exists('senal_indicadores_exteriores_items_json',$post)) return null;
    $raw=trim((string)($post['senal_indicadores_exteriores_items_json']??''));
    if($raw==='') return array();
    $items=json_decode($raw,true);
    if(!is_array($items)) throw new Exception('La lista de Indicadores exteriores no es valida.');
    return array_values(array_filter($items,function($x){return is_array($x) && trim((string)($x['codigo']??''))!=='' && (int)($x['cantidad']??0)>0;}));
}
function senalAgregarIndicadoresExteriorV190(&$lineas,&$caracteristicas,$conexion,$listaId,$post){
    $items=senalIndicadoresExteriorItemsV190($post);
    if(!is_array($items)) return 0;
    $total=0;
    $esElectromecanico=array_key_exists('senal_tiene_control',$post) ? ((string)$post['senal_tiene_control']!=='1') : !senalTieneControl($post);
    foreach($items as $it){
        $codigo=trim((string)($it['codigo']??''));$cant=max(0,(int)($it['cantidad']??0));$medida=trim((string)($it['medida']??''));$acabado=strtoupper(trim((string)($it['acabado']??'ACERO')));$medEsp=!empty($it['medida_especial']);
        if($codigo===''||$cant<=0)continue;
        if(!senalIndicadorPermitidoContexto($conexion,$codigo,'EXTERIOR_INDEPENDIENTE'))throw new Exception('El indicador '.$codigo.' no está habilitado para indicadores exteriores independientes.');
        $rol=$esElectromecanico?senalRolIndicadorV375($it['rol']??(senalNormalizarClave($it['tipo']??'')==='ELECTROMECANICO'?'MAESTRO':'REPETIDOR')):'REPETIDOR';
        $row=senalResolverIndicadorRolV375($conexion,$codigo,$rol);
        $codigoReal=trim((string)$row['codigo']);$modeloReal=trim((string)$row['modelo_indicador']);$tipoReal=senalNormalizarClave($row['tipo_modulos']??'');
        if(!$esElectromecanico && $tipoReal!=='ELECTRONICO') throw new Exception('Con Control AUTOMAC, los indicadores exteriores solo admiten modelos ELECTRONICOS.');
        $medTxt=$medida!==''?$medida:'A CONFIRMAR';
        $coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp);$detCoef='Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);
        $rolTxt=$esElectromecanico?($rol==='MAESTRO'?'MAESTRO A4000':'REPETIDOR A4400'):'ELECTRONICO';
        senalAgregarLinea($lineas,$conexion,$listaId,'Indicador de posicion exterior',$codigoReal,$modeloReal.' - '.$rolTxt.' - Medida '.$medTxt,$cant,$cant.' unidad(es) · Medida: '.$medTxt,false,$coef,$detCoef);
        if($esElectromecanico && $rol==='MAESTRO') senalAgregarAppindMaestroV375($lineas,$conexion,$listaId,$modeloReal,$cant,$post,'indicador exterior');
        $caracteristicas[]='Indicadores de posicion - '.$modeloReal.' ('.$codigoReal.'): '.$cant.' · '.$rolTxt.' · Medida '.$medTxt.' · Tapa '.senalTextoAcabadoV190($acabado,$medEsp);
        $total+=$cant;
    }
    return $total;
}


/* v180 - Permite cotizar Pulsadores exteriores sin Botonera de cabina.
 * Solo SIMPLE y DOBLE son totalmente independientes. Las familias +IP siguen
 * requiriendo una definicion de indicador de cabina hasta que exista una regla
 * comercial especifica para seleccionar el indicador exterior sin cabina. */
function senalCalcularPulsadoresExterioresSinCabina($conexion,$listaId,$post){
    $lineas=array();$caracteristicas=array();$totalPulsadores=0;
    $familias=array('SIMPLE'=>'Pulsador exterior simple','SIMPLE_IP'=>'Pulsador exterior simple + IP','DOBLE'=>'Pulsador exterior doble','DOBLE_IP'=>'Pulsador exterior doble + IP');
    $itemsV188=senalPulsadoresItemsV188($post);
    if(is_array($itemsV188)){
        foreach($itemsV188 as $item){
            $fam=strtoupper(trim((string)($item['familia']??'')));if(!isset($familias[$fam]))continue;$nombre=$familias[$fam];
            $cfg=senalResolverPulsadorExteriorItemV188($conexion,$item,$post,null);if(!$cfg)continue;
            $cant=(int)$cfg['cantidad'];$totalPulsadores+=$cant;$esMetalExt=senalNormalizarClave($cfg['modelo_pulsador']??'')==='METAL';$modoModeloExt=!empty($cfg['es_modelo_perfil']);
            $desc=$modoModeloExt?($nombre.' - '.$cfg['modelo_pulsador']):($nombre.' - '.$cfg['modelo_pulsador'].' - '.$cfg['color_registro'].' - '.$cfg['tension_modulos'].' - '.$cfg['bornes'].' - '.$cfg['tecla_modulos']);
            $perfilExterior=(array)($cfg['_perfil_modelo']??array());
            if(strtoupper((string)($perfilExterior['politica_acabado']??''))==='INCLUIDO_EN_PRECIO')$desc.=' - Acabado '.(string)($perfilExterior['acabado']??'').' incluido en el precio';
            if(substr($fam,-3)==='_IP'){
                $codigoSel=trim((string)($cfg['_indicador_codigo']??''));
                $rolInd=senalRolIndicadorV375($cfg['_indicador_rol']??'REPETIDOR');
                $filaRol=senalResolverIndicadorRolV375($conexion,$codigoSel,$rolInd);
                $ind=array('codigo'=>$filaRol['codigo'],'modelo'=>$filaRol['modelo_indicador'],'tipo_modulos'=>$filaRol['tipo_modulos']);
                $medida=trim((string)($cfg['_medida']??''));$medTxt=$medida!==''?$medida:'A CONFIRMAR';
                $detalleCompuesto=($esMetalExt?'ROND METAL':($cfg['color_registro'].' · '.$cfg['tension_modulos'].' · '.$cfg['bornes'].' · '.$cfg['tecla_modulos'])).' · Medida '.$medTxt;
                $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$politica=$cfg['_politica_acabado']??'COEFICIENTE';$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp,$politica);$detCoef=strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?'Acabado '.senalTextoAcabadoV190($acabado,false).' incluido en el precio':'Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarPulsadorIndicadorPerfil($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],(string)$cfg['modelo_pulsador'],(string)$ind['codigo'],(string)$ind['modelo'],$cant,$detalleCompuesto,$coef,$detCoef,(array)($cfg['_perfil_modelo']??array()));
                if($rolInd==='MAESTRO') senalAgregarAppindMaestroV375($lineas,$conexion,$listaId,(string)$ind['modelo'],$cant,$post,'indicador en pulsador exterior');
                $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo pulsador '.$cfg['modelo_pulsador'].' · Indicador '.$ind['modelo'].' ('.$ind['codigo'].') · '.($rolInd==='MAESTRO'?'MAESTRO A4000':'REPETIDOR A4400').' · Medida '.$medTxt.(empty($cfg['_perfil_modelo']['separar_indicador_exterior'])?' · conjunto valorizado':' · pulsador e indicador valorizados por separado');
            }else{
                $medida=trim((string)($cfg['_medida']??''));$medTxt=$medida!==''?$medida:'A CONFIRMAR';
                $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$politica=$cfg['_politica_acabado']??'COEFICIENTE';$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp,$politica);$detCoef=strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?'Acabado '.senalTextoAcabadoV190($acabado,false).' incluido en el precio':'Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarLinea($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],$desc.' - Medida '.$medTxt,$cant,$cant.' unidad(es) · Medida: '.$medTxt,false,$coef,$detCoef);
                $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo '.$cfg['modelo_pulsador'].' · Acabado '.(string)($perfilExterior['acabado']??$acabado).(strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?' incluido en el precio':'').' · Medida '.$medTxt.' (modelo exterior independiente; sin botonera de cabina)';
            }
        }
    }else{
        foreach($familias as $fam=>$nombre){
            $cfg=senalResolverPulsadorExterior($conexion,$fam,$post,null);if(!$cfg)continue;
            $cant=(int)$cfg['cantidad'];$totalPulsadores+=$cant;$esMetalExt=!empty($cfg['es_rond_metal']);
            $desc=$esMetalExt?($nombre.' - ROND METAL'):($nombre.' - '.$cfg['modelo_pulsador'].' - '.$cfg['color_registro'].' - '.$cfg['tension_modulos'].' - '.$cfg['bornes'].' - '.$cfg['tecla_modulos']);
            if(substr($fam,-3)==='_IP'){
                $pref='senal_ext_'.strtolower($fam).'_';$codigoSel=trim((string)($post[$pref.'indicador_codigo']??''));
                $ind=senalResolverIndicadorExteriorPulsador($conexion,$fam,$cfg['tipo_modulos']??'','',$codigoSel);
                $detalleCompuesto=$esMetalExt?'ROND METAL':($cfg['color_registro'].' · '.$cfg['tension_modulos'].' · '.$cfg['bornes'].' · '.$cfg['tecla_modulos']);
                $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp);$detCoef='Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarLineaPulsadorConIndicador($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],(string)$cfg['modelo_pulsador'],(string)$ind['codigo'],(string)$ind['modelo'],$cant,$detalleCompuesto,$coef,$detCoef);
                $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo pulsador '.$cfg['modelo_pulsador'].' · Indicador '.$ind['modelo'].' ('.$ind['codigo'].') · conjunto valorizado';
            }else{
                senalAgregarLinea($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],$desc,$cant,$cant.' unidad(es)');
                $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo '.$cfg['modelo_pulsador'].' (modelo exterior independiente; sin botonera de cabina)';
            }
        }
    }
    if($totalPulsadores>0){
        if(!empty($post['senal_pulsador_exterior_llave_bomberos'])) $caracteristicas[]='Pulsadores exteriores - Llave servicio bomberos';
        if(!empty($post['senal_pulsador_exterior_logo'])) $caracteristicas[]='Pulsadores exteriores - Logo';
        if(!empty($post['senal_pulsador_exterior_braille_lateral'])) $caracteristicas[]='Pulsadores exteriores - Braille lateral';
        $acabado=trim((string)($post['senal_pulsador_exterior_acabado']??''));$medidas=trim((string)($post['senal_pulsador_exterior_medidas']??''));
        if($acabado!=='')$caracteristicas[]='Pulsadores exteriores - Acabado: '.$acabado;if($medidas!=='')$caracteristicas[]='Pulsadores exteriores - Medidas: '.$medidas;
    }
    senalAgregarIndicadoresExteriorV190($lineas,$caracteristicas,$conexion,$listaId,$post);
    $total=0.0;foreach($lineas as $l)$total+=(float)$l['total'];
    return array('matriz'=>null,'lineas'=>$lineas,'total_bruto'=>$total,'codigo_adicional_parada'=>'','paradas_totales'=>0,'paradas_por_botonera'=>array(),'medidas_por_botonera'=>array(),'paradas_incluidas_base'=>0,'paradas_adicionales'=>0,'cantidad_botoneras'=>0,'caracteristicas'=>$caracteristicas,'tipo_modulos'=>'','modo_sin_botonera'=>true);
}

function calcularLineasSenalizacionCabina($conexion, $listaId, $post) {
    senalExigirTablas($conexion);
    senalValidarMaestroV375($post);
    if (senalSoloComunicacionSerieDesdeControl($post)) {
        return senalCalcularSoloComunicacionSerieDesdeControl($conexion, $listaId, $post);
    }
    // v180: una cotizacion de Senalizacion puede llevar solo Pulsadores exteriores.
    // En documentos anteriores a v180 la ausencia del campo conserva el comportamiento historico (incluye cabina).
    $incluirBotoneraCabina=!array_key_exists('senal_incluir_botonera_cabina',$post) || !empty($post['senal_incluir_botonera_cabina']);
    // v182: si no existe modelo de cabina pero el usuario ya configuro un pulsador exterior
    // independiente, no bloquear el calculo esperando una botonera que el cliente no lleva.
    $hayExteriorConfigurado=false;
    $itemsExtV188=senalPulsadoresItemsV188($post);
    $itemsIndV190=senalIndicadoresExteriorItemsV190($post);
    if((is_array($itemsExtV188) && count($itemsExtV188)>0) || (is_array($itemsIndV190) && count($itemsIndV190)>0)) $hayExteriorConfigurado=true;
    else foreach(array('simple','simple_ip','doble','doble_ip') as $famExtV182){
        $prefExtV182='senal_ext_'.$famExtV182.'_';
        if(!empty($post[$prefExtV182.'agregado'])){ $hayExteriorConfigurado=true; break; }
    }
    if((int)($post['senal_modelo']??0)<=0 && $hayExteriorConfigurado) $incluirBotoneraCabina=false;
    if(!$incluirBotoneraCabina){
        return senalCalcularPulsadoresExterioresSinCabina($conexion,$listaId,$post);
    }
    $mat=senalResolverMatrizBase($conexion,$post);
    $cantidad=max(1,(int)($post['senal_cantidad']??1));
    $paradasPorBotonera=senalParadasPorBotonera($post,$cantidad);
    $medidasPorBotonera=senalMedidasPorBotonera($post,$cantidad);
    $paradas=$paradasPorBotonera ? max($paradasPorBotonera) : 0; // compatibilidad con salidas históricas de un solo valor
    $tipoModulos=senalNormalizarClave($mat['tipo_modulo_nombre']??'');
    $modelo=senalNormalizarClave($mat['modelo_pulsador_nombre']??'');
    $lineas=array();$caracteristicas=array();

    $perfil=(array)($mat['senal_perfil']??senalPerfilModelo($conexion,(int)($mat['modelo_pulsador_id']??0)));
    $esOnix=!empty($mat['es_onix']);
    $esModeloPuerta=!empty($mat['es_modelo_puerta']);
    $onixTipo=(string)($mat['onix_tipo']??'');
    $esPantalla=!empty($mat['es_pantalla']) || !empty($perfil['pantalla']);
    $indicadorOnixIncluido='';
    if($onixTipo==='TELEFONICO') $indicadorOnixIncluido='Indicador 7 pulg Beaglebond';
    elseif($onixTipo==='INDIVIDUALES') $indicadorOnixIncluido='A4830 Crystal Color';

    if($esModeloPuerta){
        $descBase='Botonera de cabina '.$mat['tipo_puerta'].', modelo '.$mat['modelo_pulsador_nombre'].'.';
        if(strtoupper((string)($perfil['politica_acabado']??''))==='INCLUIDO_EN_PRECIO' && trim((string)($perfil['acabado']??''))!=='') $descBase.=' Acabado '.$perfil['acabado'].' incluido en el precio.';
    } elseif($esOnix){
        $descBase='Botonera de cabina '.$mat['tipo_puerta'].', modelo '.$mat['modelo_pulsador_nombre'].'.';
        if(strtoupper((string)($perfil['politica_acabado']??''))==='INCLUIDO_EN_PRECIO' && trim((string)($perfil['acabado']??''))!=='') $descBase.=' Acabado '.$perfil['acabado'].' incluido en el precio.';
        if($esPantalla) $descBase.=' Sin indicador de posición.';
        elseif(trim((string)($perfil['indicador_incluido_descripcion']??''))!=='') $descBase.=' Indicador incluido: '.$perfil['indicador_incluido_descripcion'].' (incluido en el precio de la botonera, no se valoriza por separado).';
        elseif($indicadorOnixIncluido!=='') $descBase.=' Indicador incluido: '.$indicadorOnixIncluido.' (incluido en el precio de la botonera, no se valoriza por separado).';
    } else {
        $descBase='Botonera de cabina '.$mat['tipo_puerta'].', modelo '.$mat['modelo_pulsador_nombre'].', '.$mat['tension_modulo_nombre'].', '.$mat['borne_nombre'].', registro '.$mat['color_registro_nombre'].', tecla '.$mat['tecla_nombre'].'.';
    }
    $politicaAcabado=strtoupper(trim((string)($perfil['politica_acabado']??'COEFICIENTE')));
    $acabadoCab=$politicaAcabado==='INCLUIDO_EN_PRECIO'?strtoupper(trim((string)($perfil['acabado']??'ACERO'))):strtoupper(trim((string)($post['senal_acabado']??'ACERO')));
    $medEspCab=$politicaAcabado==='INCLUIDO_EN_PRECIO'?false:!empty($post['senal_medida_especial']);
    $coefCab=senalCoeficienteAcabadoV190($conexion,$acabadoCab,$medEspCab,$politicaAcabado);
    $detCoefCab=$politicaAcabado==='INCLUIDO_EN_PRECIO'?'Acabado '.senalTextoAcabadoV190($acabadoCab,false).' incluido en el precio':'Tapa '.senalTextoAcabadoV190($acabadoCab,$medEspCab).' × '.str_replace('.',',',(string)$coefCab);
    senalAgregarLinea($lineas,$conexion,$listaId,'Base botonera de cabina',$mat['codigo'],$descBase,$cantidad,$cantidad.' botonera(s)',false,$coefCab,$detCoefCab);

    // BASE incluye 2 paradas por botonera. El perfil define si se cotiza el adicional.
    $adicionalesPorBotonera=array_map(static function($p){ return max(0,(int)$p-2); },$paradasPorBotonera);
    $paradasAdicionales=array_sum($adicionalesPorBotonera);
    $paradasIncluidasBase=array_sum(array_map(static function($p){ return min(2,max(0,(int)$p)); },$paradasPorBotonera));
    $codParada='';
    $reglaParada=strtoupper(trim((string)($perfil['regla_adicional_parada']??'MATRIZ')));
    $aplicaAdicionalParada=$reglaParada!=='NINGUNA';
    if($paradasAdicionales>0 && $aplicaAdicionalParada){
        $codParada=senalCodigoAdicionalParada($conexion,$mat);
        if($codParada==='') throw new Exception('No existe adicional por parada para '.$mat['modelo_pulsador_nombre'].' / '.$mat['tension_modulo_nombre'].' / '.$mat['borne_nombre'].' / '.$mat['color_registro_nombre'].' / '.$mat['tecla_nombre'].'.');
        $partesFormula=array();
        foreach($paradasPorBotonera as $i=>$p){
            $partesFormula[]='Coche '.($i+1).': ('.(int)$p.' - 2) = '.max(0,(int)$p-2);
        }
        senalAgregarLinea($lineas,$conexion,$listaId,'Adicional por parada en botonera de cabina',$codParada,'Adicional por parada para '.$mat['modelo_pulsador_nombre'],$paradasAdicionales,implode(' + ',$partesFormula).' => '.$paradasAdicionales.' adicional(es)');
    }

    // Indicadores de cabina. En destino electromecánico el usuario define si
    // este indicador es el MAESTRO A4000 o un REPETIDOR A4400.
    $tipoIndicadorDestino=$tipoModulos;
    if(array_key_exists('senal_tiene_control',$post)){
        $tipoIndicadorDestino=((string)$post['senal_tiene_control']==='1')?'ELECTRONICO':'ELECTROMECANICO';
    }
    $modeloIndicador=trim((string)($post['senal_indicador_modelo']??''));
    $cantIndic=max(0,(int)($post['senal_indicador_cantidad']??0));
    if(!empty($post['senal_indicador_sync_botoneras'])) $cantIndic=$cantidad;
    if(!$esOnix && !empty($perfil['indicador_cabina']) && $modeloIndicador!=='' && $cantIndic>0){
        $rolCab=$tipoIndicadorDestino==='ELECTROMECANICO'?senalRolIndicadorV375($post['senal_indicador_rol']??'MAESTRO','MAESTRO'):'REPETIDOR';
        $rowIndic=senalResolverIndicadorRolV375($conexion,$modeloIndicador,$rolCab);
        if(!senalIndicadorPermitidoContexto($conexion,$rowIndic['codigo']??'','CABINA')) throw new Exception('El indicador seleccionado no está permitido en botoneras de cabina.');
        $codIndic=trim((string)$rowIndic['codigo']);$modeloIndicReal=trim((string)$rowIndic['modelo_indicador']);
        $rolTxt=$tipoIndicadorDestino==='ELECTROMECANICO'?($rolCab==='MAESTRO'?'MAESTRO A4000':'REPETIDOR A4400'):'ELECTRONICO';
        senalAgregarLinea($lineas,$conexion,$listaId,'Indicador de posicion',$codIndic,$modeloIndicReal.' - '.$rolTxt,$cantIndic,'Cantidad de indicadores: '.$cantIndic);
        if($tipoIndicadorDestino==='ELECTROMECANICO' && $rolCab==='MAESTRO'){
            senalAgregarAppindMaestroV375($lineas,$conexion,$listaId,$modeloIndicReal,$cantIndic,$post,'indicador de cabina');
        }
    }
    if($esOnix){
        $caracteristicas[]='ONIX: Color, Tecla, Tensión y Bornes no intervienen en el cálculo.';
        if($esPantalla) $caracteristicas[]='Indicador de posición: NO LLEVA.';
        elseif(trim((string)($perfil['indicador_incluido_descripcion']??''))!=='') $caracteristicas[]='Indicador incluido en botonera: '.$perfil['indicador_incluido_descripcion'].' (sin valorización separada).';
        elseif($indicadorOnixIncluido!=='') $caracteristicas[]='Indicador incluido en botonera: '.$indicadorOnixIncluido.' (sin valorización separada).';
    }
    if(trim((string)($post['senal_medidas_calado']??''))!=='') $caracteristicas[]='Medidas del calado: '.trim((string)$post['senal_medidas_calado']);

    // Adicionales simples y especiales administrados desde Mantenimiento -> Señalización.
    // El POST usa claves GRUPO_ID para que nombre/código/orden puedan modificarse sin tocar PHP.
    $selDinamicos=is_array($post['senal_adicional_sel']??null)?$post['senal_adicional_sel']:array();
    $cantDinamicos=is_array($post['senal_adicional_cantidad']??null)?$post['senal_adicional_cantidad']:array();
    $bonifDinamicos=is_array($post['senal_adicional_bonificar']??null)?$post['senal_adicional_bonificar']:array();
    $usaFormatoDinamico=!empty($post['senal_formato_dinamico']) || array_key_exists('senal_adicional_sel',$post);
    $legacy=array(
      'ADICIONAL POR PAÑO'=>array('check'=>'senal_adicional_pano_cantidad','cant'=>'senal_adicional_pano_cantidad','bonif'=>'senal_bonificar_pano'),
      'LLAVE DE SERVICIO INDEPENDIENTE'=>array('check'=>'senal_llave_independiente','cant'=>'senal_cant_llave_independiente'),
      'CALADO P/PESADOR DE CARGA'=>array('check'=>'senal_calado_pesador','cant'=>'senal_cant_calado_pesador'),
      'ADICIONAL POR INTERCOMUNICADOR'=>array('check'=>'senal_intercomunicador','cant'=>'senal_cant_intercomunicador'),
      'ADICIONAL TELEFONO MANOS LIBRES'=>array('check'=>'senal_telefono_manos_libres','cant'=>'senal_cant_telefono_manos_libres'),
      'FUENTE PARA INTERCOMUNICADOR'=>array('check'=>'senal_fuente_intercom','cant'=>'senal_cant_fuente_intercom'),
      'LUZ DE EMERGENCIA'=>array('check'=>'senal_luz_emergencia','cant'=>'senal_cant_luz_emergencia'),
      'ACCESIBILIDAD POR VOZ'=>array('check'=>'senal_acces_voz','cant'=>'senal_cant_acces_voz'),
      'BOTONERA CABLEADA'=>array('check'=>'senal_botonera_cableada','cant'=>'senal_cant_botonera_cableada'),
      'MENSAJES ESPECIALES'=>array('check'=>'senal_mensajes_especiales_incluir','cant'=>'senal_cant_mensajes_especiales')
    );
    $sqlAdic="SELECT id, adicional, codigo, COALESCE(NULLIF(etiqueta,''),adicional) etiqueta, tipo_calculo, cantidad_predeterminada, bonificado_predeterminado, permite_bonificar, 'GENERAL' grupo FROM senal_adicionales_cabina WHERE activo=1 AND visible_cotizador=1 UNION ALL SELECT id, adicional, codigo, COALESCE(NULLIF(etiqueta,''),adicional) etiqueta, tipo_calculo, cantidad_predeterminada, bonificado_predeterminado, permite_bonificar, 'ESPECIAL' grupo FROM senal_adicionales_especiales_cabina WHERE activo=1 AND aplica=1 AND visible_cotizador=1 ORDER BY etiqueta";
    $rAdic=$conexion->query($sqlAdic);
    if(!$rAdic) throw new Exception('No se pudieron leer los adicionales configurados de Señalización. Aplique la migración de mantenimiento v20.');
    while($a=$rAdic->fetch_assoc()){
        $key=(string)$a['grupo'].'_'.(int)$a['id'];
        $seleccionado=!empty($selDinamicos[$key]);
        $cantRaw=$cantDinamicos[$key]??null;
        $bonificado=$seleccionado ? !empty($bonifDinamicos[$key]) : !empty($a['bonificado_predeterminado']);
        if(!$usaFormatoDinamico && isset($legacy[$a['adicional']])){
            $lm=$legacy[$a['adicional']];
            if($a['adicional']==='ADICIONAL POR PAÑO') $seleccionado=((int)($post[$lm['check']]??0)>0);
            else $seleccionado=!empty($post[$lm['check']]);
            if($seleccionado && isset($lm['cant']) && array_key_exists($lm['cant'],$post)) $cantRaw=$post[$lm['cant']];
            if($seleccionado && isset($lm['bonif']) && array_key_exists($lm['bonif'],$post)) $bonificado=!empty($post[$lm['bonif']]);
            elseif($seleccionado) $bonificado=!empty($a['bonificado_predeterminado']);
        }
        if(!$seleccionado) continue;
        if($cantRaw===null || $cantRaw==='') $cantRaw=$a['cantidad_predeterminada'];
        $cant=max(0,(float)$cantRaw);
        if(strtoupper((string)$a['tipo_calculo'])==='POR_BOTONERA') $cant*=$cantidad;
        if($cant<=0) continue;
        if(empty($a['permite_bonificar'])) $bonificado=!empty($a['bonificado_predeterminado']);
        $etiqueta=trim((string)$a['etiqueta']);
        senalAgregarLinea($lineas,$conexion,$listaId,$etiqueta,(string)$a['codigo'],$etiqueta,$cant,$cant.' unidad(es)',$bonificado);
        if($bonificado) $caracteristicas[]=$etiqueta.' bonificado';
    }

    // v169: componentes que pertenecen fisicamente a la botonera de cabina.
    // Todos se valorizan dentro de SENALIZACION y por lo tanto reciben los descuentos de Señalizacion.
    $cantSintA7601=max(0,(int)($post['senal_sint_a7601c_cantidad']??0));
    $cantSintA4820=max(0,(int)($post['senal_sint_a4820sv_cantidad']??0));
    $usarSintA7601=!empty($post['senal_sint_a7601c']) && $cantSintA7601>0;
    $usarSintA4820=!empty($post['senal_sint_a4820sv']) && $cantSintA4820>0;
    $modeloIndicadorSint=trim((string)($post['senal_indicador_modelo']??''));
    $indicadorColorSint=(bool)preg_match('/A?48(?:20|30)/i',$modeloIndicadorSint) || ($esOnix && $onixTipo==='INDIVIDUALES');

    // v474: son dos variantes alternativas del sintetizador de cabina. A4820SV
    // sólo corresponde cuando existe indicador color A4820/A4830.
    if($usarSintA4820 && !$indicadorColorSint){
        throw new Exception('A4820SV sólo corresponde cuando la botonera lleva indicador color A4820/A4830.');
    }
    if($usarSintA7601 && $usarSintA4820){
        throw new Exception('Seleccione una sola variante de sintetizador de voz en cabina: A7601C o A4820SV con indicador color.');
    }
    if($usarSintA7601){
        senalAgregarLineaConReferenciaPrecio($lineas,$conexion,$listaId,'SINTETIZADOR DE VOZ EN CABINA','A7601C','P7600V2','SINTETIZADOR DE VOZ EN CABINA',$cantSintA7601,$cantSintA7601.' unidad(es)');
    }
    if($usarSintA4820){
        senalAgregarLinea($lineas,$conexion,$listaId,'ADICIONAL POR SINTETIZADOR DE VOZ EN INDICADOR COLOR','A4820SV','SINTETIZADOR DE VOZ EN A4820/A4830',$cantSintA4820,$cantSintA4820.' unidad(es)');
    }

    $frentePesador=trim((string)($post['senal_pesador_frente_codigo']??''));
    $cantFrentePesador=max(0,(int)($post['senal_pesador_frente_cantidad']??0));
    if($frentePesador!=='' && $cantFrentePesador>0){
        $stmtFr=$conexion->prepare("SELECT frente_nombre,codigo FROM accesorios_pesador_frentes WHERE codigo=? AND activo='SI' LIMIT 1");
        $stmtFr->bind_param('s',$frentePesador);$stmtFr->execute();$rf=$stmtFr->get_result();$fr=$rf?$rf->fetch_assoc():null;$stmtFr->close();
        if(!$fr) throw new Exception('El frente de pesador seleccionado no esta habilitado.');
        senalAgregarLinea($lineas,$conexion,$listaId,'Frente de pesador de carga',(string)$fr['codigo'],(string)$fr['frente_nombre'],$cantFrentePesador,$cantFrentePesador.' frente(s)');
    }

    $tecCA=senalNormalizarClave($post['senal_control_acceso_tecnologia']??'');
    if(in_array($tecCA,array('CHIP','TARJETA','TECLADO'),true)){
        $alcCA=senalNormalizarClave($post['senal_control_acceso_alcance']??'PISO_USUARIO');
        $parCA=max(1,min(64,(int)($post['senal_control_acceso_paradas']??$paradas)));
        $stmtCA=$conexion->prepare("SELECT codigo_base,codigo_adicional FROM accesorios_control_acceso_reglas WHERE tecnologia=? AND alcance=? AND activo='SI' LIMIT 1");
        $stmtCA->bind_param('ss',$tecCA,$alcCA);$stmtCA->execute();$rca=$stmtCA->get_result();$regCA=$rca?$rca->fetch_assoc():null;$stmtCA->close();
        if(!$regCA || trim((string)$regCA['codigo_base'])==='') throw new Exception('No existe regla de Control de accesos para '.$tecCA.' / '.$alcCA.'.');
        senalAgregarLinea($lineas,$conexion,$listaId,'Control de accesos - base',(string)$regCA['codigo_base'],'Control de accesos '.$tecCA.' - '.$alcCA,1,'Base del sistema');
        $nCA=$parCA<=16?0:($parCA<=32?1:($parCA<=48?2:3));
        if($nCA>0){
            $codAdCA=trim((string)($regCA['codigo_adicional']??''));
            if($codAdCA==='') throw new Exception('La regla de Control de accesos '.$tecCA.' / '.$alcCA.' no tiene codigo adicional configurado.');
            senalAgregarLinea($lineas,$conexion,$listaId,'Control de accesos - adicional por rango',$codAdCA,'Adicional por cantidad de paradas',$nCA,$parCA.' paradas => '.$nCA.' adicional(es)');
        }
        $claveUsuario=$tecCA==='CHIP'?'CHIP_CONTACTO':($tecCA==='TARJETA'?'TARJETA_PROXIMIDAD':'');
        if($claveUsuario!==''){
            $cantUsr=max(0,(int)($post[$tecCA==='CHIP'?'senal_control_acceso_chips_cantidad':'senal_control_acceso_tarjetas_cantidad']??0));
            if($cantUsr>0){
                $stmtU=$conexion->prepare("SELECT accesorio_codigo,accesorio_nombre FROM accesorios_catalogo WHERE accesorio_clave=? AND accesorio_activo='SI' LIMIT 1");
                $stmtU->bind_param('s',$claveUsuario);$stmtU->execute();$ru=$stmtU->get_result();$usr=$ru?$ru->fetch_assoc():null;$stmtU->close();
                if($usr && trim((string)$usr['accesorio_codigo'])!=='') senalAgregarLinea($lineas,$conexion,$listaId,(string)$usr['accesorio_nombre'],(string)$usr['accesorio_codigo'],(string)$usr['accesorio_nombre'],$cantUsr,$cantUsr.' unidad(es)');
            }
        }
    }

    // Llave ascensorista: codigo de llave + adicional electromecanico + braille lateral cuando corresponda.
    $tipoLlave=senalNormalizarClave($post['senal_llave_ascensorista_tipo']??'');
    $cantLlave=max(0,(int)($post['senal_cant_llave_ascensorista']??$cantidad));
    if($tipoLlave!=='' && $cantLlave>0){
        $codLlave=senalCodigoLlaveAscensorista($conexion,$tipoLlave,$mat);
        if($codLlave==='') throw new Exception('No existe llave ascensorista '.$tipoLlave.' para la combinacion seleccionada.');
        senalAgregarLinea($lineas,$conexion,$listaId,'Llave servicio ascensorista',$codLlave,'Llave ascensorista + modo subir/bajar/completo',$cantLlave,$cantLlave.' llave(s)');
        if($tipoLlave==='ELECTROMECANICO'){
            $codAdic=senalCodigoAdicLlaveElectromecanica($conexion,$mat);
            if($codAdic==='') throw new Exception('Falta el adicional de llave ascensorista electromecanica para la combinacion seleccionada.');
            senalAgregarLinea($lineas,$conexion,$listaId,'Adicional llave ascensorista electromecanica',$codAdic,'Pulsador adicional para llave ascensorista electromecanica',$cantLlave,$cantLlave.' adicional(es)');
        }
        // v178: las botoneras ONIX no llevan Braille lateral, tampoco asociado a llave ascensorista.
        if(!$esOnix){
            $codBraille=senalCodigoBrailleAscensorista($conexion,$mat);
            if($codBraille!=='') senalAgregarLinea($lineas,$conexion,$listaId,'Braille lateral en llave ascensorista',$codBraille,'Braille lateral para llave ascensorista',$cantLlave,$cantLlave.' unidad(es)');
        }
    }

    // Comunicacion serie: EN CABINA o TOTAL llevan P3540.
    $comSerie=senalNormalizarClave($post['senal_comunicacion_serie_tipo']??'');
    if(in_array($comSerie,array('EN CABINA','TOTAL'),true)){
        $cant=max(1,(int)($post['senal_cant_comunicacion_serie']??$cantidad));
        $desdeControl=!empty($post['senal_comunicacion_serie_desde_control']);
        $cod=$desdeControl?'P3540':senalCodigoAdicionalCabina($conexion,$comSerie);
        $concepto=$desdeControl?'Placa de comunicacion serie A3540':'Comunicacion serie - '.$comSerie;
        senalAgregarLinea($lineas,$conexion,$listaId,$concepto,$cod,'Comunicacion serie '.$comSerie,$cant,$cant.' botonera(s)');
    }

    // Caracteristicas sin cargo.
    // v178: las ONIX (Puls. Individuales, Telefónico y Pantalla 21) no llevan Braille interior ni lateral.
    if($esOnix) $caracteristicas[]='ONIX: no lleva Braille interior ni Braille lateral.';
    elseif($modelo==='A3900') $caracteristicas[]='Braille lateral incluido por defecto (A3900)';
    else $caracteristicas[]='Braille interior incluido por defecto';
    if(!empty($post['senal_logo_grabado'])){
        $tipoLogo=trim((string)($post['senal_tipo_logo']??''));
        $caracteristicas[]='Logo en cabina sin cargo'.($tipoLogo!==''?' - '.$tipoLogo:'');
    }
    foreach($medidasPorBotonera as $i=>$medida){
        if($medida!=='') $caracteristicas[]='Medida botonera coche '.($i+1).': '.$medida;
    }
    foreach(array('senal_caracteristicas_especiales'=>'Caracteristicas especiales','senal_mensajes_especiales'=>'Detalle mensajes','senal_acabado'=>'Acabado') as $campo=>$titulo){
        $v=trim((string)($post[$campo]??''));if($v!=='')$caracteristicas[]=$titulo.': '.$v;
    }

    // v188: Pulsadores exteriores admiten multiples configuraciones por familia.
    // Si el JSON v188 no existe se conserva el flujo v179/v187 para historicos.
    $familiasExt=array('SIMPLE'=>'Pulsador exterior simple','SIMPLE_IP'=>'Pulsador exterior simple + IP','DOBLE'=>'Pulsador exterior doble','DOBLE_IP'=>'Pulsador exterior doble + IP');
    $camposCantLegacy=array('SIMPLE'=>'senal_pulsadores_simples_cantidad','SIMPLE_IP'=>'senal_pulsadores_simples_indicador_cantidad','DOBLE'=>'senal_pulsadores_dobles_cantidad','DOBLE_IP'=>'senal_pulsadores_dobles_indicador_cantidad');
    $totalPulsadores=0;$hayPulsadorV179=false;$tipoModulosExt=senalNormalizarClave($mat['tipo_modulo_nombre']??$tipoModulos);
    $codigoIpCabina='';$modeloIpCabina=trim((string)($post['senal_indicador_modelo']??''));if($modeloIpCabina!=='' && !$esOnix)$codigoIpCabina=senalCodigoIndicador($conexion,$modeloIpCabina,$tipoModulosExt);
    $itemsV188=senalPulsadoresItemsV188($post);
    if(is_array($itemsV188)){
        foreach($itemsV188 as $item){
            $fam=strtoupper(trim((string)($item['familia']??'')));if(!isset($familiasExt[$fam]))continue;$nombre=$familiasExt[$fam];
            $cfg=senalResolverPulsadorExteriorItemV188($conexion,$item,$post,$mat);if(!$cfg)continue;
            $hayPulsadorV179=true;$cant=(int)$cfg['cantidad'];$totalPulsadores+=$cant;$esMetalExt=senalNormalizarClave($cfg['modelo_pulsador']??'')==='METAL';$modoModeloExt=!empty($cfg['es_modelo_perfil']);
            $descP=$modoModeloExt?($nombre.' - '.$cfg['modelo_pulsador']):($nombre.' - '.$cfg['modelo_pulsador'].' - '.$cfg['color_registro'].' - '.$cfg['tension_modulos'].' - '.$cfg['bornes'].' - '.$cfg['tecla_modulos']);
            $perfilExterior=(array)($cfg['_perfil_modelo']??array());
            if(strtoupper((string)($perfilExterior['politica_acabado']??''))==='INCLUIDO_EN_PRECIO')$descP.=' - Acabado '.(string)($perfilExterior['acabado']??'').' incluido en el precio';
            if(substr($fam,-3)==='_IP'){
                $codigoIndSel=trim((string)($cfg['_indicador_codigo']??''));
                $rolInd=$tipoIndicadorDestino==='ELECTROMECANICO'?senalRolIndicadorV375($cfg['_indicador_rol']??'REPETIDOR'):'REPETIDOR';
                $filaRol=senalResolverIndicadorRolV375($conexion,$codigoIndSel,$rolInd);
                $ind=array('codigo'=>$filaRol['codigo'],'modelo'=>$filaRol['modelo_indicador'],'tipo_modulos'=>$filaRol['tipo_modulos']);
                $medida=trim((string)($cfg['_medida']??''));$medTxt=$medida!==''?$medida:'A CONFIRMAR';
                $detalleCompuesto=($esMetalExt?'ROND METAL':($cfg['color_registro'].' · '.$cfg['tension_modulos'].' · '.$cfg['bornes'].' · '.$cfg['tecla_modulos'])).' · Medida '.$medTxt;
                $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$politica=$cfg['_politica_acabado']??'COEFICIENTE';$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp,$politica);$detCoef=strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?'Acabado '.senalTextoAcabadoV190($acabado,false).' incluido en el precio':'Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarPulsadorIndicadorPerfil($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],(string)$cfg['modelo_pulsador'],(string)$ind['codigo'],(string)$ind['modelo'],$cant,$detalleCompuesto,$coef,$detCoef,(array)($cfg['_perfil_modelo']??array()));
                if($tipoIndicadorDestino==='ELECTROMECANICO' && $rolInd==='MAESTRO') senalAgregarAppindMaestroV375($lineas,$conexion,$listaId,(string)$ind['modelo'],$cant,$post,'indicador en pulsador exterior');
                $nomIp=trim((string)($cfg['_nomenclatura']??''));$separado=!empty($cfg['_perfil_modelo']['separar_indicador_exterior']);$caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo pulsador '.$cfg['modelo_pulsador'].' · Indicador '.$ind['modelo'].' ('.$ind['codigo'].') · '.($tipoIndicadorDestino==='ELECTROMECANICO'?($rolInd==='MAESTRO'?'MAESTRO A4000':'REPETIDOR A4400'):'ELECTRONICO').($rolInd==='MAESTRO'&&($cfg['_paradas_maestro']??0)>0?' · '.(int)$cfg['_paradas_maestro'].' paradas':'').($nomIp!==''?' · Nomenclatura '.$nomIp:'').' · Medida '.$medTxt.($separado?' · pulsador e indicador valorizados por separado':' · conjunto valorizado').(!empty($cfg['mismo_modelo'])?' · modelo igual a cabina':' · modelo exterior independiente');
            }else{
                $medida=trim((string)($cfg['_medida']??''));$medTxt=$medida!==''?$medida:'A CONFIRMAR';
                $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$politica=$cfg['_politica_acabado']??'COEFICIENTE';$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp,$politica);$detCoef=strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?'Acabado '.senalTextoAcabadoV190($acabado,false).' incluido en el precio':'Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarLinea($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],$descP.' - Medida '.$medTxt,$cant,$cant.' unidad(es) · Medida: '.$medTxt,false,$coef,$detCoef);
                $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo '.$cfg['modelo_pulsador'].' · Acabado '.(string)($perfilExterior['acabado']??$acabado).(strtoupper((string)$politica)==='INCLUIDO_EN_PRECIO'?' incluido en el precio':'').' · Medida '.$medTxt.(!empty($cfg['mismo_modelo'])?' (igual a cabina)':' (modelo exterior independiente)');
            }
        }
    }else{
        foreach($familiasExt as $fam=>$nombre){
            $pref='senal_ext_'.strtolower($fam).'_';$cfg=senalResolverPulsadorExterior($conexion,$fam,$post,$mat);
            if($cfg){
                $hayPulsadorV179=true;$cant=(int)$cfg['cantidad'];$totalPulsadores+=$cant;$esMetalExt=!empty($cfg['es_rond_metal']);
                $descP=$esMetalExt?($nombre.' - ROND METAL'):($nombre.' - '.$cfg['modelo_pulsador'].' - '.$cfg['color_registro'].' - '.$cfg['tension_modulos'].' - '.$cfg['bornes'].' - '.$cfg['tecla_modulos']);
                if(substr($fam,-3)==='_IP'){
                    $codigoIndSel=trim((string)($post[$pref.'indicador_codigo']??''));$ind=senalResolverIndicadorExteriorPulsador($conexion,$fam,$tipoModulosExt,$codigoIpCabina,$codigoIndSel);
                    $detalleCompuesto=$esMetalExt?'ROND METAL':($cfg['color_registro'].' · '.$cfg['tension_modulos'].' · '.$cfg['bornes'].' · '.$cfg['tecla_modulos']);
                    $acabado=$cfg['_acabado']??'ACERO';$medEsp=!empty($cfg['_medida_especial']);$coef=senalCoeficienteAcabadoV190($conexion,$acabado,$medEsp);$detCoef='Tapa '.senalTextoAcabadoV190($acabado,$medEsp).' × '.str_replace('.',',',(string)$coef);senalAgregarLineaPulsadorConIndicador($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],(string)$cfg['modelo_pulsador'],(string)$ind['codigo'],(string)$ind['modelo'],$cant,$detalleCompuesto,$coef,$detCoef);
                    $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo pulsador '.$cfg['modelo_pulsador'].' · Indicador '.$ind['modelo'].' ('.$ind['codigo'].') · conjunto valorizado'.(!empty($cfg['mismo_modelo'])?' · modelo igual a cabina':' · modelo exterior independiente');
                }else{
                    senalAgregarLinea($lineas,$conexion,$listaId,$nombre,(string)$cfg['codigo'],$descP,$cant,$cant.' unidad(es)');
                    $caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' · Modelo '.$cfg['modelo_pulsador'].(!empty($cfg['mismo_modelo'])?' (igual a cabina)':' (modelo exterior independiente)');
                }
            }else{
                $cant=max(0,(int)($post[$camposCantLegacy[$fam]]??0));if($cant>0 && empty($post[$pref.'agregado'])){$totalPulsadores+=$cant;$caracteristicas[]='Pulsadores exteriores - '.$nombre.': '.$cant.' (dato histórico sin matriz v179)';}
            }
        }
    }
    if($totalPulsadores>0){
        $usaCamposV140=array_key_exists('senal_pulsador_exterior_llave_bomberos',$post) || array_key_exists('senal_pulsador_exterior_acabado',$post);
        $llave=$usaCamposV140?!empty($post['senal_pulsador_exterior_llave_bomberos']):!empty($post['senal_elemento_llave_bomberos']);
        $logo=$usaCamposV140?!empty($post['senal_pulsador_exterior_logo']):!empty($post['senal_elemento_logo']);
        $braille=$usaCamposV140?!empty($post['senal_pulsador_exterior_braille_lateral']):!empty($post['senal_elemento_braille_lateral']);
        if($llave) $caracteristicas[]='Pulsadores exteriores - Llave servicio bomberos';
        if($logo) $caracteristicas[]='Pulsadores exteriores - Logo';
        if($braille) $caracteristicas[]='Pulsadores exteriores - Braille lateral';
        $acabadoExterior=trim((string)($usaCamposV140?($post['senal_pulsador_exterior_acabado']??''):($post['senal_elemento_acabado']??'')));
        $medidasExterior=trim((string)($usaCamposV140?($post['senal_pulsador_exterior_medidas']??''):($post['senal_elemento_medidas']??'')));
        if($acabadoExterior!=='') $caracteristicas[]='Pulsadores exteriores - Acabado: '.$acabadoExterior;
        if($medidasExterior!=='') $caracteristicas[]='Pulsadores exteriores - Medidas: '.$medidasExterior;
    }

    // v190: Indicadores exteriores individuales valorizados por código Bejerman.
    $itemsIndV190=senalIndicadoresExteriorItemsV190($post);
    if(is_array($itemsIndV190) && count($itemsIndV190)>0){
        senalAgregarIndicadoresExteriorV190($lineas,$caracteristicas,$conexion,$listaId,$post);
        $acabadoIndicador=trim((string)($post['senal_indicador_exterior_acabado']??''));
        if($acabadoIndicador!=='') $caracteristicas[]='Indicadores de posicion - Acabado: '.$acabadoIndicador;
    }else{
        // Compatibilidad con documentos antiguos (v140-v189): cantidades técnicas sin código inequívoco.
        $tipoLegacy=trim((string)($post['senal_elemento_tipo']??''));
        $legacyIndicador=($tipoLegacy==='INDICADOR_POSICION') || !empty($post['senal_indicador_exterior_incluir']);
        $cantIndicadores=array(
            '18mm V5'=>max(0,(int)($post['senal_indicador_18mm_v5_cantidad']??0)),
            '31mm V5'=>max(0,(int)($post['senal_indicador_31mm_v5_cantidad']??0)),
            'A4610 Crystal Azul XS'=>max(0,(int)($post['senal_indicador_a4610_cantidad']??0)),
            'A4600 Crystal Azul'=>max(0,(int)($post['senal_indicador_a4600_cantidad']??0)),
            'A4810 Crystal Color LT'=>max(0,(int)($post['senal_indicador_a4810_cantidad']??0)),
            'A4830 Crystal Color Slim'=>max(0,(int)($post['senal_indicador_a4830_cantidad']??0)),
            'A7260 IP Panoramic'=>max(0,(int)($post['senal_indicador_a7260_cantidad']??0))
        );
        $totalIndicadores=0;
        foreach($cantIndicadores as $nombre=>$cant){if($cant>0){$caracteristicas[]='Indicadores de posicion - '.$nombre.': '.$cant.' (dato histórico sin código inequívoco)';$totalIndicadores+=$cant;}}
        if($totalIndicadores===0 && $legacyIndicador)$caracteristicas[]='Indicador de posicion exterior (histórico sin tipo/cantidad definidos)';
        if($totalIndicadores>0){$acabadoIndicador=trim((string)($post['senal_indicador_exterior_acabado']??($tipoLegacy==='INDICADOR_POSICION'?($post['senal_elemento_acabado']??''):'')));$medidasIndicador=trim((string)($post['senal_indicador_exterior_medidas']??($tipoLegacy==='INDICADOR_POSICION'?($post['senal_elemento_medidas']??''):'')));if($acabadoIndicador!=='')$caracteristicas[]='Indicadores de posicion - Acabado: '.$acabadoIndicador;if($medidasIndicador!=='')$caracteristicas[]='Indicadores de posicion - Medidas: '.$medidasIndicador;}
    }

    $total=0.0; foreach($lineas as $l)$total+=(float)$l['total'];
    return array('matriz'=>$mat,'lineas'=>$lineas,'total_bruto'=>$total,'codigo_adicional_parada'=>$codParada,'paradas_totales'=>$paradas,'paradas_por_botonera'=>$paradasPorBotonera,'medidas_por_botonera'=>$medidasPorBotonera,'paradas_incluidas_base'=>$paradasIncluidasBase,'paradas_adicionales'=>$paradasAdicionales,'cantidad_botoneras'=>$cantidad,'caracteristicas'=>$caracteristicas,'tipo_modulos'=>$tipoModulos);
}

function aplicarDescuentosSenalizacion($importe, $post) {
    $d1=max(0,min(100,(float)($post['senal_descuento_1']??parametroComercial($conexion,'SENALIZACION_DESCUENTO_1',30))));
    $d2=max(0,min(100,(float)($post['senal_descuento_2']??parametroComercial($conexion,'SENALIZACION_DESCUENTO_2',10))));
    $d3=max(0,min(100,(float)($post['senal_descuento_3']??parametroComercial($conexion,'SENALIZACION_DESCUENTO_3',10))));
    $factor=(1-$d1/100)*(1-$d2/100)*(1-$d3/100);
    return array('d1'=>$d1,'d2'=>$d2,'d3'=>$d3,'factor'=>$factor,'total'=>(float)$importe*$factor);
}
?>
