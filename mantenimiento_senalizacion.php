<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once __DIR__ . '/schema_guard.php';
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function msE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function msTablaExiste($c,$t){ return esquemaTablaExiste($c,(string)$t); }
function msColumnaExiste($c,$t,$col){ return esquemaColumnaExiste($c,(string)$t,(string)$col); }

function msCodigoExisteBejerman($c,$codigo){
    $codigo=strtoupper(trim((string)$codigo));
    if($codigo==='') return false;
    if(msTablaExiste($c,'bejerman_productos') && msTablaExiste($c,'bejerman_listas')){
        $st=$c->prepare("SELECT 1 FROM bejerman_productos bp INNER JOIN bejerman_listas bl ON bl.bejerman_lista_id=bp.bejerman_lista_id WHERE UPPER(TRIM(bp.codigo))=? AND bp.activo=1 AND bl.estado='VIGENTE' LIMIT 1");
        if($st){ $st->bind_param('s',$codigo); $st->execute(); $r=$st->get_result(); $ok=$r && $r->num_rows>0; $st->close(); if($ok) return true; }
    }
    if(msTablaExiste($c,'lista_precios')){
        $st=$c->prepare("SELECT 1 FROM lista_precios WHERE UPPER(TRIM(precios_codigo))=? LIMIT 1");
        if($st){ $st->bind_param('s',$codigo); $st->execute(); $r=$st->get_result(); $ok=$r && $r->num_rows>0; $st->close(); return $ok; }
    }
    return false;
}
function msModeloDependencias($c,$id,$nombre){
    $out=array('botoneras'=>0,'adicional_parada'=>0,'pulsadores_exteriores'=>0,'llaves'=>0,'braille'=>0);
    if(msTablaExiste($c,'matriz_botoneras_cabina')){ $st=$c->prepare('SELECT COUNT(*) c FROM matriz_botoneras_cabina WHERE modelo_pulsador_id=?'); if($st){$st->bind_param('i',$id);$st->execute();$out['botoneras']=(int)$st->get_result()->fetch_assoc()['c'];$st->close();} }
    foreach(array('senal_adicional_parada_cabina'=>'adicional_parada','senal_pulsadores_exteriores_matriz'=>'pulsadores_exteriores','senal_llave_ascensorista_cabina'=>'llaves','senal_braille_lateral_ascensorista'=>'braille') as $t=>$k){
        if(!msTablaExiste($c,$t)) continue;
        $col=$t==='senal_adicional_parada_cabina'?'modelo':'modelo_pulsador';
        if(!msColumnaExiste($c,$t,$col)) continue;
        $st=$c->prepare("SELECT COUNT(*) c FROM `$t` WHERE UPPER(TRIM(`$col`))=?");
        if($st){$n=strtoupper(trim((string)$nombre));$st->bind_param('s',$n);$st->execute();$out[$k]=(int)$st->get_result()->fetch_assoc()['c'];$st->close();}
    }
    return $out;
}

function msPkTabla($c,$t){
    $t=preg_replace('/[^a-zA-Z0-9_]/','',(string)$t);
    if($t==='') return '';
    // MariaDB/XAMPP: SHOW KEYS admite WHERE, pero no ORDER BY/LIMIT en esta forma.
    // Recorremos las columnas de la PK y elegimos explícitamente Seq_in_index=1.
    $r=$c->query("SHOW KEYS FROM `$t` WHERE Key_name='PRIMARY'");
    if(!$r) return '';
    $primera='';
    $menorSeq=null;
    while($f=$r->fetch_assoc()){
        $col=trim((string)($f['Column_name']??''));
        $seq=(int)($f['Seq_in_index']??0);
        if($col==='') continue;
        if($seq===1) return $col;
        if($menorSeq===null || ($seq>0 && $seq<$menorSeq)){
            $menorSeq=$seq;
            $primera=$col;
        }
    }
    return $primera;
}
function msCatalogoFuentes(){
    return array(
        'BASE_CABINA'=>array('tabla'=>'matriz_botoneras_cabina','codigo'=>'codigo','grupo'=>'Botonera de cabina · BASE'),
        'ADIC_PARADA_CABINA'=>array('tabla'=>'senal_adicional_parada_cabina','codigo'=>'codigo','grupo'=>'Botonera de cabina · Adicional por parada'),
        'INDICADOR_CABINA'=>array('tabla'=>'senal_indicadores_cabina','codigo'=>'codigo','grupo'=>'Indicadores · Cabina'),
        'ADIC_INDICADOR_CABINA'=>array('tabla'=>'senal_adic_parada_indicador_cabina','codigo'=>'codigo','grupo'=>'Indicadores · Adicional por parada'),
        'ADICIONAL_CABINA'=>array('tabla'=>'senal_adicionales_cabina','codigo'=>'codigo','grupo'=>'Accesorios / adicionales · Cabina'),
        'ADICIONAL_ESPECIAL_CABINA'=>array('tabla'=>'senal_adicionales_especiales_cabina','codigo'=>'codigo','grupo'=>'Accesorios / adicionales · Especiales'),
        'LLAVE_ASCENSORISTA'=>array('tabla'=>'senal_llave_ascensorista_cabina','codigo'=>'codigo','grupo'=>'Accesorios / adicionales · Llave ascensorista'),
        'ADIC_LLAVE_ELECTRO'=>array('tabla'=>'senal_adic_llave_asc_electromecanico','codigo'=>'codigo','grupo'=>'Accesorios / adicionales · Llave electromecánica'),
        'BRAILLE_ASCENSORISTA'=>array('tabla'=>'senal_braille_lateral_ascensorista','codigo'=>'codigo','grupo'=>'Accesorios / adicionales · Braille lateral'),
        'PULSADOR_EXTERIOR'=>array('tabla'=>'senal_pulsadores_exteriores_matriz','codigo'=>'codigo','grupo'=>'Pulsadores exteriores'),
        'INDICADOR_EXTERIOR'=>array('tabla'=>'senal_pulsadores_exteriores_indicadores','codigo'=>'codigo','grupo'=>'Indicadores · Pulsador exterior + IP'),
        'ACCESORIOS_CATALOGO'=>array('tabla'=>'accesorios_catalogo','codigo'=>'accesorio_codigo','grupo'=>'Accesorios · Catálogo general'),
        'PESADOR_FRENTES'=>array('tabla'=>'accesorios_pesador_frentes','codigo'=>'codigo','grupo'=>'Accesorios · Frentes de pesador'),
        'CONTROL_ACCESO'=>array('tabla'=>'accesorios_control_acceso_reglas','codigo'=>'codigo_base','grupo'=>'Accesorios · Control de accesos')
    );
}
function msCatalogoTextoFila($fuente,$r){
    $partes=array();
    $preferidos=array(
        'tipo_puerta','modelo_pulsador_nombre','modelo_pulsador','modelo','modelo_indicador','tipo_modulos','familia',
        'tension_modulos','tension','bornes','color_registro','color','tecla_modulos','tecla',
        'adicional','etiqueta','tipo_llave','llave_ascensorista','frente_nombre','tecnologia','alcance',
        'accesorio_nombre','accesorio_clave','accesorio_tipo','depende_codigo_ip_cabina'
    );
    foreach($preferidos as $k){
        if(array_key_exists($k,$r) && trim((string)$r[$k])!=='') $partes[]=$k.': '.trim((string)$r[$k]);
    }
    if(!$partes){
        foreach($r as $k=>$v){
            if(in_array($k,array('id','activo','orden','codigo','codigo_base','accesorio_codigo'),true)) continue;
            if(preg_match('/_id$/',$k)) continue;
            if(trim((string)$v)!=='') $partes[]=$k.': '.trim((string)$v);
            if(count($partes)>=5) break;
        }
    }
    return implode(' · ',$partes);
}
function msCatalogoPrecioMap($c){
    $map=array();
    if(!msTablaExiste($c,'lista_precios')) return $map;
    $r=$c->query("SELECT precios_codigo,precios_descripcion,precios_costo,precios_clasificacion FROM lista_precios");
    if($r) while($x=$r->fetch_assoc()){
        $k=strtoupper(trim((string)$x['precios_codigo']));
        if($k!=='' && !isset($map[$k])) $map[$k]=$x;
    }
    return $map;
}
function msCatalogoBejermanMap($c){
    $map=array();
    if(!msTablaExiste($c,'bejerman_productos') || !msTablaExiste($c,'bejerman_listas')) return $map;
    $r=$c->query("SELECT bp.codigo,bp.descripcion,bp.costo,bp.unidad,bp.rubro,bp.subrubro FROM bejerman_productos bp INNER JOIN bejerman_listas bl ON bl.bejerman_lista_id=bp.bejerman_lista_id WHERE bl.estado='VIGENTE' AND bp.activo=1");
    if($r) while($x=$r->fetch_assoc()){
        $k=strtoupper(trim((string)$x['codigo']));
        if($k!=='' && !isset($map[$k])) $map[$k]=$x;
    }
    return $map;
}
function msCatalogoCargar($c){
    $salida=array();
    foreach(msCatalogoFuentes() as $clave=>$cfg){
        $tabla=$cfg['tabla']; $campo=$cfg['codigo'];
        if(!msTablaExiste($c,$tabla) || !msColumnaExiste($c,$tabla,$campo)) continue;
        $pk=msPkTabla($c,$tabla);
        if($pk==='') continue;
        $sql="SELECT * FROM `".preg_replace('/[^a-zA-Z0-9_]/','',$tabla)."`";
        if($clave==='BASE_CABINA'){
            $sql="SELECT m.*,mp.modelo_pulsador_nombre,tm.tension_modulo_nombre,b.borne_nombre,c.color_registro_nombre,t.tecla_nombre FROM matriz_botoneras_cabina m LEFT JOIN senal_modelos_pulsador mp ON mp.modelo_pulsador_id=m.modelo_pulsador_id LEFT JOIN senal_tensiones_modulo tm ON tm.tension_modulo_id=m.tension_modulo_id LEFT JOIN senal_bornes b ON b.borne_id=m.borne_id LEFT JOIN senal_colores_registro c ON c.color_registro_id=m.color_registro_id LEFT JOIN senal_teclas t ON t.tecla_id=m.tecla_id";
        }
        $r=$c->query($sql); if(!$r) continue;
        while($x=$r->fetch_assoc()){
            $codigo=trim((string)($x[$campo]??''));
            if($codigo==='') continue;
            $activo='';
            foreach(array('activo','accesorio_activo') as $ac){ if(array_key_exists($ac,$x)){ $activo=(string)$x[$ac]; break; } }
            $salida[]=array(
                'fuente'=>$clave,'tabla'=>$tabla,'pk'=>$pk,'id'=>(string)($x[$pk]??''),'campo_codigo'=>$campo,
                'grupo'=>$cfg['grupo'],'criterio'=>msCatalogoTextoFila($clave,$x),'codigo'=>$codigo,'activo'=>$activo
            );
        }
    }
    return $salida;
}

$tab=(string)($_GET['tab']??'reglas_cotizacion');
if($tab==='combinaciones') $tab='base'; // compatibilidad con enlaces históricos
$tabsValidos=array('reglas_cotizacion','resumen','catalogo','modelos','catalogos_basicos','productos','dependencias','base','adicional_parada','indicadores','adicional_indicador','adicionales','auxiliares','pulsadores','pulsadores_exteriores','parametros','acabados');
if(!in_array($tab,$tabsValidos,true)) $tab='reglas_cotizacion';
$mensaje=''; $error='';
$tieneOrdenIndic=msColumnaExiste($conexion,'senal_indicadores_cabina','orden');

if($_SERVER['REQUEST_METHOD']==='POST'){
    $accion=(string)($_POST['accion']??'');
    try{
        if($accion==='guardar_codigo_cotizacion'){
            if(!msTablaExiste($conexion,'senal_codigos_cotizacion')) throw new Exception('Falta ejecutar COTIZADOR_AUTOMAC_v186_senalizacion_codigos.sql.');
            $id=(int)($_POST['id']??0);$codigoCot=strtoupper(trim((string)($_POST['codigo_cotizacion']??'')));
            if($id<=0||$codigoCot==='') throw new Exception('Código de cotización inválido.');
            $st=$conexion->prepare('UPDATE senal_codigos_cotizacion SET codigo_cotizacion=? WHERE id=?');
            if(!$st) throw new Exception($conexion->error);$st->bind_param('si',$codigoCot,$id);$st->execute();$st->close();
            $mensaje='Código Bejerman para cotizar actualizado. El código técnico/original no fue modificado.';$tab='reglas_cotizacion';
        } elseif($accion==='guardar_codigo_catalogo'){
            $fuente=(string)($_POST['fuente']??'');
            $id=(int)($_POST['registro_id']??0);
            $codigo=trim((string)($_POST['codigo_bejerman']??''));
            $fuentes=msCatalogoFuentes();
            if(!isset($fuentes[$fuente])) throw new Exception('Origen de catálogo inválido.');
            if($id<=0 || $codigo==='') throw new Exception('Complete un código Bejerman válido.');
            $cfg=$fuentes[$fuente]; $tabla=$cfg['tabla']; $campo=$cfg['codigo'];
            if(!msTablaExiste($conexion,$tabla) || !msColumnaExiste($conexion,$tabla,$campo)) throw new Exception('La matriz seleccionada no está disponible.');
            $pk=msPkTabla($conexion,$tabla); if($pk==='') throw new Exception('La matriz seleccionada no tiene identificador editable.');
            $tablaSql=preg_replace('/[^a-zA-Z0-9_]/','',$tabla); $campoSql=preg_replace('/[^a-zA-Z0-9_]/','',$campo); $pkSql=preg_replace('/[^a-zA-Z0-9_]/','',$pk);
            $st=$conexion->prepare("UPDATE `$tablaSql` SET `$campoSql`=? WHERE `$pkSql`=? LIMIT 1");
            if(!$st) throw new Exception($conexion->error);
            $st->bind_param('si',$codigo,$id); if(!$st->execute()) throw new Exception($st->error); $st->close();
            $mensaje='Código Bejerman actualizado. Las nuevas cotizaciones usarán '.$codigo.'.';
            $tab='catalogo';
        } elseif($accion==='guardar_acabado_coef'){
            if(!msTablaExiste($conexion,'senal_acabados_coeficientes')) throw new Exception('Falta ejecutar COTIZADOR_AUTOMAC_v190_senalizacion_acabados_indicadores.sql.');
            $id=(int)($_POST['id']??0);$coefRaw=trim((string)($_POST['coeficiente']??''));$coef=$coefRaw===''?null:(float)str_replace(',','.',$coefRaw);$activo=isset($_POST['activo'])?1:0;
            if($id<=0)throw new Exception('Registro de acabado inválido.');
            $st=$conexion->prepare('UPDATE senal_acabados_coeficientes SET coeficiente=?,activo=? WHERE id=?');$st->bind_param('dii',$coef,$activo,$id);$st->execute();$st->close();$mensaje='Coeficiente de acabado actualizado.';$tab='acabados';
        } elseif($accion==='guardar_indicador'){
            $id=(int)($_POST['id']??0); $modelo=trim((string)($_POST['modelo_indicador']??'')); $tipo=strtoupper(trim((string)($_POST['tipo_modulos']??''))); $codigo=strtoupper(trim((string)($_POST['codigo']??''))); $activo=isset($_POST['activo'])?1:0; $orden=(int)($_POST['orden']??0);
            if($modelo===''||$codigo===''||!in_array($tipo,array('ELECTRONICO','ELECTROMECANICO'),true)) throw new Exception('Complete modelo, tipo y código del indicador.');
            if($id>0){
                $sql=$tieneOrdenIndic?'UPDATE senal_indicadores_cabina SET modelo_indicador=?,tipo_modulos=?,codigo=?,activo=?,orden=? WHERE id=?':'UPDATE senal_indicadores_cabina SET modelo_indicador=?,tipo_modulos=?,codigo=?,activo=? WHERE id=?';
                $st=$conexion->prepare($sql); if($tieneOrdenIndic){$st->bind_param('sssiii',$modelo,$tipo,$codigo,$activo,$orden,$id);}else{$st->bind_param('sssii',$modelo,$tipo,$codigo,$activo,$id);} $st->execute(); $st->close();
                $mensaje='Indicador actualizado.';
            } else {
                $sql=$tieneOrdenIndic?'INSERT INTO senal_indicadores_cabina(modelo_indicador,tipo_modulos,codigo,activo,orden) VALUES(?,?,?,?,?)':'INSERT INTO senal_indicadores_cabina(modelo_indicador,tipo_modulos,codigo,activo) VALUES(?,?,?,?)';
                $st=$conexion->prepare($sql); if($tieneOrdenIndic){$st->bind_param('sssii',$modelo,$tipo,$codigo,$activo,$orden);}else{$st->bind_param('sssi',$modelo,$tipo,$codigo,$activo);} $st->execute(); $st->close();
                $mensaje='Indicador agregado.';
            }
            $tab='indicadores';
        } elseif($accion==='guardar_pulsador_exterior_matriz'){
            if(!msTablaExiste($conexion,'senal_pulsadores_exteriores_matriz')) throw new Exception('Falta la tabla senal_pulsadores_exteriores_matriz. Ejecute la migración v179.');
            $id=(int)($_POST['id']??0);$familia=strtoupper(trim((string)($_POST['familia']??'')));$tipo=strtoupper(trim((string)($_POST['tipo_modulos']??'')));$modelo=strtoupper(trim((string)($_POST['modelo_pulsador']??'')));$color=strtoupper(trim((string)($_POST['color_registro']??'')));$tension=strtoupper(trim((string)($_POST['tension_modulos']??'')));$bornes=strtoupper(trim((string)($_POST['bornes']??'')));$tecla=strtoupper(trim((string)($_POST['tecla_modulos']??'')));$codigo=strtoupper(trim((string)($_POST['codigo']??'')));$activo=isset($_POST['activo'])?1:0;$orden=(int)($_POST['orden']??100);
            if(!in_array($familia,array('SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP'),true)||$tipo===''||$modelo===''||$color===''||$tension===''||$bornes===''||$tecla===''||$codigo==='') throw new Exception('Complete todos los campos de la combinación de pulsador exterior.');
            if($id>0){$st=$conexion->prepare('UPDATE senal_pulsadores_exteriores_matriz SET familia=?,tipo_modulos=?,modelo_pulsador=?,color_registro=?,tension_modulos=?,bornes=?,tecla_modulos=?,codigo=?,activo=?,orden=? WHERE id=?');$st->bind_param('ssssssssiii',$familia,$tipo,$modelo,$color,$tension,$bornes,$tecla,$codigo,$activo,$orden,$id);}
            else{$st=$conexion->prepare('INSERT INTO senal_pulsadores_exteriores_matriz(familia,tipo_modulos,modelo_pulsador,color_registro,tension_modulos,bornes,tecla_modulos,codigo,activo,orden) VALUES(?,?,?,?,?,?,?,?,?,?)');$st->bind_param('ssssssssii',$familia,$tipo,$modelo,$color,$tension,$bornes,$tecla,$codigo,$activo,$orden);}
            if(!$st)throw new Exception($conexion->error);$st->execute();$st->close();$mensaje=$id>0?'Combinación exterior actualizada.':'Combinación exterior agregada.';$tab='pulsadores_exteriores';
        } elseif($accion==='guardar_pulsador_exterior_indicador'){
            if(!msTablaExiste($conexion,'senal_pulsadores_exteriores_indicadores')) throw new Exception('Falta la tabla senal_pulsadores_exteriores_indicadores. Ejecute la migración v179.');
            $id=(int)($_POST['id']??0);$familia=strtoupper(trim((string)($_POST['familia']??'')));$modelo=strtoupper(trim((string)($_POST['modelo']??'')));$tipo=strtoupper(trim((string)($_POST['tipo_modulos']??'')));$codigo=strtoupper(trim((string)($_POST['codigo']??'')));$dep=strtoupper(trim((string)($_POST['depende_codigo_ip_cabina']??'')));$activo=isset($_POST['activo'])?1:0;$orden=(int)($_POST['orden']??100);
            if(!in_array($familia,array('SIMPLE_IP','DOBLE_IP'),true)||$modelo===''||$tipo===''||$codigo===''||$dep==='') throw new Exception('Complete todos los campos del indicador exterior.');
            if($id>0){$st=$conexion->prepare('UPDATE senal_pulsadores_exteriores_indicadores SET familia=?,modelo=?,tipo_modulos=?,codigo=?,depende_codigo_ip_cabina=?,activo=?,orden=? WHERE id=?');$st->bind_param('sssssiii',$familia,$modelo,$tipo,$codigo,$dep,$activo,$orden,$id);}
            else{$st=$conexion->prepare('INSERT INTO senal_pulsadores_exteriores_indicadores(familia,modelo,tipo_modulos,codigo,depende_codigo_ip_cabina,activo,orden) VALUES(?,?,?,?,?,?,?)');$st->bind_param('sssssii',$familia,$modelo,$tipo,$codigo,$dep,$activo,$orden);}
            if(!$st)throw new Exception($conexion->error);$st->execute();$st->close();$mensaje=$id>0?'Indicador exterior actualizado.':'Indicador exterior agregado.';$tab='pulsadores_exteriores';
        } elseif($accion==='guardar_modelo'){
            $id=(int)($_POST['modelo_pulsador_id']??0); $nombre=strtoupper(trim((string)($_POST['modelo_pulsador_nombre']??''))); $disc=isset($_POST['discontinuado'])?'SI':'NO';
            if($nombre==='') throw new Exception('Ingrese el nombre del modelo.');
            $stDup=$conexion->prepare('SELECT modelo_pulsador_id FROM senal_modelos_pulsador WHERE UPPER(TRIM(modelo_pulsador_nombre))=? AND modelo_pulsador_id<>? LIMIT 1');
            if($stDup){$stDup->bind_param('si',$nombre,$id);$stDup->execute();if($stDup->get_result()->num_rows>0){$stDup->close();throw new Exception('Ya existe un modelo de pulsador con ese nombre.');}$stDup->close();}
            if($id>0){
                $stOld=$conexion->prepare('SELECT modelo_pulsador_nombre FROM senal_modelos_pulsador WHERE modelo_pulsador_id=?');$stOld->bind_param('i',$id);$stOld->execute();$oldRow=$stOld->get_result()->fetch_assoc();$stOld->close();
                if(!$oldRow) throw new Exception('Modelo inexistente.');
                $nombreAnterior=strtoupper(trim((string)$oldRow['modelo_pulsador_nombre']));
                $conexion->begin_transaction();
                try{
                    $st=$conexion->prepare('UPDATE senal_modelos_pulsador SET modelo_pulsador_nombre=?,discontinuado=? WHERE modelo_pulsador_id=?');$st->bind_param('ssi',$nombre,$disc,$id);$st->execute();$st->close();
                    if($nombreAnterior!==$nombre){
                        $refs=array(
                            array('senal_adicional_parada_cabina','modelo'),
                            array('senal_pulsadores_exteriores_matriz','modelo_pulsador'),
                            array('senal_llave_ascensorista_cabina','modelo_pulsador'),
                            array('senal_adic_llave_asc_electromecanico','modelo_pulsador'),
                            array('senal_braille_lateral_ascensorista','modelo_pulsador')
                        );
                        foreach($refs as $ref){if(!msTablaExiste($conexion,$ref[0])||!msColumnaExiste($conexion,$ref[0],$ref[1]))continue;$tt=preg_replace('/[^a-zA-Z0-9_]/','',$ref[0]);$cc=preg_replace('/[^a-zA-Z0-9_]/','',$ref[1]);$q=$conexion->prepare("UPDATE `$tt` SET `$cc`=? WHERE UPPER(TRIM(`$cc`))=?");$q->bind_param('ss',$nombre,$nombreAnterior);$q->execute();$q->close();}
                    }
                    $conexion->commit();$mensaje='Modelo actualizado y referencias de matrices sincronizadas.';
                }catch(Throwable $e){$conexion->rollback();throw $e;}
            }
            else{$r=$conexion->query('SELECT COALESCE(MAX(modelo_pulsador_id),0)+1 nuevo FROM senal_modelos_pulsador');$nuevo=(int)$r->fetch_assoc()['nuevo'];$st=$conexion->prepare('INSERT INTO senal_modelos_pulsador(modelo_pulsador_id,modelo_pulsador_nombre,discontinuado) VALUES(?,?,?)');$st->bind_param('iss',$nuevo,$nombre,$disc);$st->execute();$st->close();$mensaje='Modelo agregado. Ya puede utilizarlo en nuevas combinaciones de señalización.';}
            $tab='modelos';
        } elseif($accion==='guardar_combinacion'){
            $id=(int)($_POST['matriz_botonera_id']??0); $puerta=strtoupper(trim((string)($_POST['tipo_puerta']??''))); $modelo=(int)($_POST['modelo_pulsador_id']??0); $tension=(int)($_POST['tension_modulo_id']??0); $borne=(int)($_POST['borne_id']??0); $color=(int)($_POST['color_registro_id']??0); $tecla=(int)($_POST['tecla_id']??0); $codigo=strtoupper(trim((string)($_POST['codigo']??''))); $activo=isset($_POST['activo'])?'SI':'NO';
            if(!in_array($puerta,array('PM','PA'),true)||!$modelo||!$tension||!$borne||!$color||!$tecla||$codigo==='') throw new Exception('Complete todos los datos de la combinación.');
            $stDup=$conexion->prepare('SELECT matriz_botonera_id FROM matriz_botoneras_cabina WHERE tipo_puerta=? AND modelo_pulsador_id=? AND tension_modulo_id=? AND borne_id=? AND color_registro_id=? AND tecla_id=? AND matriz_botonera_id<>? LIMIT 1');
            if($stDup){$stDup->bind_param('siiiiii',$puerta,$modelo,$tension,$borne,$color,$tecla,$id);$stDup->execute();if($stDup->get_result()->num_rows>0){$stDup->close();throw new Exception('Ya existe una combinación de botonera con esos mismos criterios.');}$stDup->close();}
            if(!msCodigoExisteBejerman($conexion,$codigo)) throw new Exception('El código '.$codigo.' no existe en la Base Bejerman vigente/lista de precios. Corrija el código antes de guardar.');
            if($id>0){$st=$conexion->prepare('UPDATE matriz_botoneras_cabina SET tipo_puerta=?,modelo_pulsador_id=?,tension_modulo_id=?,borne_id=?,color_registro_id=?,tecla_id=?,codigo=?,activo=? WHERE matriz_botonera_id=?');$st->bind_param('siiiiissi',$puerta,$modelo,$tension,$borne,$color,$tecla,$codigo,$activo,$id);$st->execute();$st->close();$mensaje='Combinación de botonera actualizada.';}
            else{$st=$conexion->prepare('INSERT INTO matriz_botoneras_cabina(tipo_puerta,modelo_pulsador_id,tension_modulo_id,borne_id,color_registro_id,tecla_id,codigo,activo) VALUES(?,?,?,?,?,?,?,?)');$st->bind_param('siiiiiss',$puerta,$modelo,$tension,$borne,$color,$tecla,$codigo,$activo);$st->execute();$st->close();$mensaje='Nueva combinación de botonera agregada.';}
            $tab='base';
        } elseif($accion==='guardar_adicional_parada'){
            $id=(int)($_POST['id']??0);
            $modelo=strtoupper(trim((string)($_POST['modelo']??'')));
            $tension=strtoupper(trim((string)($_POST['tension']??'')));
            $bornes=strtoupper(trim((string)($_POST['bornes']??'')));
            $color=strtoupper(trim((string)($_POST['color']??'')));
            $tecla=strtoupper(trim((string)($_POST['tecla']??'')));
            $codigo=strtoupper(trim((string)($_POST['codigo']??'')));
            $activo=isset($_POST['activo'])?1:0;
            if($modelo===''||$tension===''||$bornes===''||$color===''||$tecla===''||$codigo==='') throw new Exception('Complete todos los campos de la matriz de adicional por parada.');
            if($id>0){
                $st=$conexion->prepare('UPDATE senal_adicional_parada_cabina SET modelo=?,tension=?,bornes=?,color=?,tecla=?,codigo=?,activo=? WHERE id=?');
                $st->bind_param('ssssssii',$modelo,$tension,$bornes,$color,$tecla,$codigo,$activo,$id);
            }else{
                $st=$conexion->prepare('INSERT INTO senal_adicional_parada_cabina(modelo,tension,bornes,color,tecla,codigo,activo) VALUES(?,?,?,?,?,?,?)');
                $st->bind_param('ssssssi',$modelo,$tension,$bornes,$color,$tecla,$codigo,$activo);
            }
            if(!$st) throw new Exception($conexion->error); $st->execute(); $st->close();
            $mensaje=$id>0?'Adicional por parada actualizado.':'Adicional por parada agregado.'; $tab='adicional_parada';
        } elseif($accion==='guardar_adicional_indicador'){
            $id=(int)($_POST['id']??0);
            $modelo=trim((string)($_POST['modelo_indicador']??''));
            $tipo=strtoupper(trim((string)($_POST['tipo_modulos']??'')));
            $codigo=strtoupper(trim((string)($_POST['codigo']??'')));
            $activo=isset($_POST['activo'])?1:0;
            if($modelo===''||$codigo===''||!in_array($tipo,array('ELECTRONICO','ELECTROMECANICO'),true)) throw new Exception('Complete modelo, tipo y código del adicional por parada del indicador.');
            if($id>0){
                $st=$conexion->prepare('UPDATE senal_adic_parada_indicador_cabina SET modelo_indicador=?,tipo_modulos=?,codigo=?,activo=? WHERE id=?');
                $st->bind_param('sssii',$modelo,$tipo,$codigo,$activo,$id);
            }else{
                $st=$conexion->prepare('INSERT INTO senal_adic_parada_indicador_cabina(modelo_indicador,tipo_modulos,codigo,activo) VALUES(?,?,?,?)');
                $st->bind_param('sssi',$modelo,$tipo,$codigo,$activo);
            }
            if(!$st) throw new Exception($conexion->error); $st->execute(); $st->close();
            $mensaje=$id>0?'Adicional por parada de indicador actualizado.':'Adicional por parada de indicador agregado.'; $tab='adicional_indicador';
        } elseif($accion==='guardar_producto_senal'){
            if(!msTablaExiste($conexion,'productos_senalizacion')) throw new Exception('Falta la tabla productos_senalizacion.');
            $id=(int)($_POST['producto_senalizacion_id']??0);$codigo=strtoupper(trim((string)($_POST['codigo']??'')));$descripcion=trim((string)($_POST['descripcion']??''));$utilidad=(float)str_replace(',','.',(string)($_POST['utilidad']??1));$habilitado=isset($_POST['habilitado'])?1:0;
            if($codigo===''||$descripcion===''||$utilidad<=0) throw new Exception('Complete código, descripción y utilidad mayor que cero.');
            if(!msCodigoExisteBejerman($conexion,$codigo)) throw new Exception('El código '.$codigo.' no existe en Base Bejerman/lista vigente.');
            $q=$conexion->prepare('SELECT producto_senalizacion_id FROM productos_senalizacion WHERE UPPER(TRIM(codigo))=? AND producto_senalizacion_id<>? LIMIT 1');$q->bind_param('si',$codigo,$id);$q->execute();if($q->get_result()->num_rows){$q->close();throw new Exception('Ese código ya existe en Productos de Señalización.');}$q->close();
            if($id>0){$q=$conexion->prepare('UPDATE productos_senalizacion SET codigo=?,descripcion=?,utilidad=?,habilitado=? WHERE producto_senalizacion_id=?');$q->bind_param('ssdii',$codigo,$descripcion,$utilidad,$habilitado,$id);}else{$q=$conexion->prepare('INSERT INTO productos_senalizacion(codigo,descripcion,utilidad,habilitado) VALUES(?,?,?,?)');$q->bind_param('ssdi',$codigo,$descripcion,$utilidad,$habilitado);}
            if(!$q)throw new Exception($conexion->error);if(!$q->execute())throw new Exception($q->error);$q->close();$mensaje=$id>0?'Producto de Señalización actualizado.':'Producto de Señalización agregado.';$tab='productos';
        } elseif($accion==='guardar_catalogo_basico'){
            $catalogo=(string)($_POST['catalogo']??'');$id=(int)($_POST['id']??0);$nombre=strtoupper(trim((string)($_POST['nombre']??'')));
            $cfgs=array(
                'tension'=>array('tabla'=>'senal_tensiones_modulo','pk'=>'tension_modulo_id','campo'=>'tension_modulo_nombre','refs'=>array(array('senal_adicional_parada_cabina','tension'),array('senal_pulsadores_exteriores_matriz','tension_modulos'))),
                'borne'=>array('tabla'=>'senal_bornes','pk'=>'borne_id','campo'=>'borne_nombre','refs'=>array(array('senal_adicional_parada_cabina','bornes'),array('senal_pulsadores_exteriores_matriz','bornes'))),
                'color'=>array('tabla'=>'senal_colores_registro','pk'=>'color_registro_id','campo'=>'color_registro_nombre','refs'=>array(array('senal_adicional_parada_cabina','color'),array('senal_pulsadores_exteriores_matriz','color_registro'),array('senal_llave_ascensorista_cabina','color'),array('senal_adic_llave_asc_electromecanico','color'))),
                'tecla'=>array('tabla'=>'senal_teclas','pk'=>'tecla_id','campo'=>'tecla_nombre','refs'=>array(array('senal_adicional_parada_cabina','tecla'),array('senal_pulsadores_exteriores_matriz','tecla_modulos'),array('senal_llave_ascensorista_cabina','tecla'),array('senal_adic_llave_asc_electromecanico','tecla'))),
                'tipo_modulo'=>array('tabla'=>'senal_tipos_modulo','pk'=>'tipo_modulo_id','campo'=>'tipo_modulo_nombre','refs'=>array(array('senal_indicadores_cabina','tipo_modulos'),array('senal_adic_parada_indicador_cabina','tipo_modulos'),array('senal_pulsadores_exteriores_matriz','tipo_modulos'),array('senal_pulsadores_exteriores_indicadores','tipo_modulos'),array('senal_llave_ascensorista_cabina','tipo_llave'),array('senal_adic_llave_asc_electromecanico','tipo_llave')))
            );
            if(!isset($cfgs[$catalogo])||$nombre==='') throw new Exception('Catálogo básico inválido.');
            $cfg=$cfgs[$catalogo];$t=$cfg['tabla'];$pk=$cfg['pk'];$campo=$cfg['campo'];
            $q=$conexion->prepare("SELECT `$pk` FROM `$t` WHERE UPPER(TRIM(`$campo`))=? AND `$pk`<>? LIMIT 1");$q->bind_param('si',$nombre,$id);$q->execute();if($q->get_result()->num_rows){$q->close();throw new Exception('Ya existe ese valor en el catálogo.');}$q->close();
            if($id>0){
                $q=$conexion->prepare("SELECT `$campo` nombre FROM `$t` WHERE `$pk`=?");$q->bind_param('i',$id);$q->execute();$old=$q->get_result()->fetch_assoc();$q->close();if(!$old)throw new Exception('Registro inexistente.');$anterior=strtoupper(trim((string)$old['nombre']));
                $conexion->begin_transaction();try{
                    $q=$conexion->prepare("UPDATE `$t` SET `$campo`=? WHERE `$pk`=?");$q->bind_param('si',$nombre,$id);$q->execute();$q->close();
                    if($anterior!==$nombre){foreach($cfg['refs'] as $ref){if(!msTablaExiste($conexion,$ref[0])||!msColumnaExiste($conexion,$ref[0],$ref[1]))continue;$tt=preg_replace('/[^a-zA-Z0-9_]/','',$ref[0]);$cc=preg_replace('/[^a-zA-Z0-9_]/','',$ref[1]);$q=$conexion->prepare("UPDATE `$tt` SET `$cc`=? WHERE UPPER(TRIM(`$cc`))=?");$q->bind_param('ss',$nombre,$anterior);$q->execute();$q->close();}}
                    $conexion->commit();
                }catch(Throwable $e){$conexion->rollback();throw $e;}
                $mensaje='Catálogo actualizado y referencias textuales sincronizadas.';
            }else{
                $r=$conexion->query("SELECT COALESCE(MAX(`$pk`),0)+1 nuevo FROM `$t`");$nuevo=(int)$r->fetch_assoc()['nuevo'];$q=$conexion->prepare("INSERT INTO `$t`(`$pk`,`$campo`) VALUES(?,?)");$q->bind_param('is',$nuevo,$nombre);$q->execute();$q->close();$mensaje='Nuevo valor agregado al catálogo.';
            }
            $tab='catalogos_basicos';
        } elseif($accion==='guardar_auxiliar'){
            $tipoAux=(string)($_POST['aux_tipo']??'');$id=(int)($_POST['id']??0);$codigo=strtoupper(trim((string)($_POST['codigo']??'')));$activo=isset($_POST['activo'])?1:0;
            if($codigo===''||!msCodigoExisteBejerman($conexion,$codigo)) throw new Exception('Ingrese un código Bejerman vigente para la matriz auxiliar.');
            if($tipoAux==='llave'||$tipoAux==='llave_em'){
                $tabla=$tipoAux==='llave'?'senal_llave_ascensorista_cabina':'senal_adic_llave_asc_electromecanico';
                $tipo=strtoupper(trim((string)($_POST['tipo_llave']??'')));$modelo=strtoupper(trim((string)($_POST['modelo_pulsador']??'')));$tecla=strtoupper(trim((string)($_POST['tecla']??'')));$color=strtoupper(trim((string)($_POST['color']??'')));$asc=isset($_POST['ascensorista'])?1:0;
                if($tipo===''||$modelo===''||$tecla===''||$color==='')throw new Exception('Complete los criterios de la llave.');
                if($id>0){$q=$conexion->prepare("UPDATE `$tabla` SET tipo_llave=?,modelo_pulsador=?,tecla=?,color=?,ascensorista=?,codigo=?,activo=? WHERE id=?");$q->bind_param('ssssissi',$tipo,$modelo,$tecla,$color,$asc,$codigo,$activo,$id);}else{$q=$conexion->prepare("INSERT INTO `$tabla`(tipo_llave,modelo_pulsador,tecla,color,ascensorista,codigo,activo) VALUES(?,?,?,?,?,?,?)");$q->bind_param('ssssisi',$tipo,$modelo,$tecla,$color,$asc,$codigo,$activo);}
            } elseif($tipoAux==='braille'){
                $modelo=strtoupper(trim((string)($_POST['modelo_pulsador']??'')));$asc=isset($_POST['ascensorista'])?1:0;if($modelo==='')throw new Exception('Complete el modelo de pulsador.');
                if($id>0){$q=$conexion->prepare('UPDATE senal_braille_lateral_ascensorista SET modelo_pulsador=?,llave_ascensorista=?,codigo=?,activo=? WHERE id=?');$q->bind_param('sisii',$modelo,$asc,$codigo,$activo,$id);}else{$q=$conexion->prepare('INSERT INTO senal_braille_lateral_ascensorista(modelo_pulsador,llave_ascensorista,codigo,activo) VALUES(?,?,?,?)');$q->bind_param('sisi',$modelo,$asc,$codigo,$activo);}
            } else throw new Exception('Matriz auxiliar inválida.');
            if(!$q)throw new Exception($conexion->error);if(!$q->execute())throw new Exception($q->error);$q->close();$mensaje=$id>0?'Matriz auxiliar actualizada.':'Nueva regla auxiliar agregada.';$tab='auxiliares';
        } elseif($accion==='guardar_parametros'){
            $cantAsc=max(0,(int)($_POST['cant_llave_ascensorista']??0));
            $cantCom=max(0,(int)($_POST['cant_comunicacion_serie']??0));
            $st=$conexion->prepare("INSERT INTO senal_parametros_cabina(clave,valor) VALUES('CANT_LLAVE_ASCENSORISTA',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)"); $v=(string)$cantAsc; $st->bind_param('s',$v); $st->execute(); $st->close();
            $st=$conexion->prepare("INSERT INTO senal_parametros_cabina(clave,valor) VALUES('CANT_COMUNICACION_SERIE',?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)"); $v=(string)$cantCom; $st->bind_param('s',$v); $st->execute(); $st->close();
            $mensaje='Parámetros especiales actualizados.'; $tab='parametros';
        } elseif($accion==='guardar_adicional'){
            $tabla=(string)($_POST['tabla']??''); $id=(int)($_POST['id']??0);
            if(!in_array($tabla,array('senal_adicionales_cabina','senal_adicionales_especiales_cabina'),true)) throw new Exception('Tabla de adicionales inválida.');
            $adicional=strtoupper(trim((string)($_POST['adicional']??''))); $etiqueta=trim((string)($_POST['etiqueta']??'')); $codigo=strtoupper(trim((string)($_POST['codigo']??'')));
            $activo=isset($_POST['activo'])?1:0; $visible=isset($_POST['visible_cotizador'])?1:0; $orden=(int)($_POST['orden']??100);
            $tipo=strtoupper(trim((string)($_POST['tipo_calculo']??'CANTIDAD_DIRECTA'))); if(!in_array($tipo,array('CANTIDAD_DIRECTA','POR_BOTONERA'),true))$tipo='CANTIDAD_DIRECTA';
            $cant=max(0,(float)($_POST['cantidad_predeterminada']??1)); $editable=isset($_POST['cantidad_editable'])?1:0; $sync=isset($_POST['sincronizar_botoneras'])?1:0;
            // v357: POR_BOTONERA siempre usa 1 como factor interno y sincroniza la cantidad visible con la cantidad real de botoneras.
            if($tipo==='POR_BOTONERA'){ $cant=1; $sync=1; }
            $bonif=isset($_POST['bonificado_predeterminado'])?1:0; $permiteBonif=isset($_POST['permite_bonificar'])?1:0; $mostrarCodigo=isset($_POST['mostrar_codigo'])?1:0;
            if($adicional===''||$codigo==='') throw new Exception('Complete adicional y código.'); if($etiqueta==='')$etiqueta=$adicional;
            if($tabla==='senal_adicionales_cabina'){
                if($id>0){
                    $st=$conexion->prepare('UPDATE senal_adicionales_cabina SET adicional=?,etiqueta=?,codigo=?,activo=?,visible_cotizador=?,orden=?,tipo_calculo=?,cantidad_predeterminada=?,cantidad_editable=?,sincronizar_botoneras=?,bonificado_predeterminado=?,permite_bonificar=?,mostrar_codigo=? WHERE id=?');
                    $st->bind_param('sssiiisdiiiiii',$adicional,$etiqueta,$codigo,$activo,$visible,$orden,$tipo,$cant,$editable,$sync,$bonif,$permiteBonif,$mostrarCodigo,$id);
                }else{
                    $st=$conexion->prepare('INSERT INTO senal_adicionales_cabina(adicional,etiqueta,codigo,activo,visible_cotizador,orden,tipo_calculo,cantidad_predeterminada,cantidad_editable,sincronizar_botoneras,bonificado_predeterminado,permite_bonificar,mostrar_codigo) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $st->bind_param('sssiiisdiiiii',$adicional,$etiqueta,$codigo,$activo,$visible,$orden,$tipo,$cant,$editable,$sync,$bonif,$permiteBonif,$mostrarCodigo);
                }
            } else {
                $aplica=isset($_POST['aplica'])?1:0;
                if($id>0){
                    $st=$conexion->prepare('UPDATE senal_adicionales_especiales_cabina SET adicional=?,etiqueta=?,codigo=?,aplica=?,activo=?,visible_cotizador=?,orden=?,tipo_calculo=?,cantidad_predeterminada=?,cantidad_editable=?,sincronizar_botoneras=?,bonificado_predeterminado=?,permite_bonificar=?,mostrar_codigo=? WHERE id=?');
                    $st->bind_param('sssiiiisdiiiiii',$adicional,$etiqueta,$codigo,$aplica,$activo,$visible,$orden,$tipo,$cant,$editable,$sync,$bonif,$permiteBonif,$mostrarCodigo,$id);
                }else{
                    $st=$conexion->prepare('INSERT INTO senal_adicionales_especiales_cabina(adicional,etiqueta,codigo,aplica,activo,visible_cotizador,orden,tipo_calculo,cantidad_predeterminada,cantidad_editable,sincronizar_botoneras,bonificado_predeterminado,permite_bonificar,mostrar_codigo) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
                    $st->bind_param('sssiiiisdiiiii',$adicional,$etiqueta,$codigo,$aplica,$activo,$visible,$orden,$tipo,$cant,$editable,$sync,$bonif,$permiteBonif,$mostrarCodigo);
                }
            }
            if(!$st) throw new Exception($conexion->error); $st->execute(); $st->close(); $mensaje=$id>0?'Adicional actualizado.':'Adicional agregado.'; $tab='adicionales';
        }
    }catch(Throwable $e){$error=$e->getMessage();}
}

$modelos=$conexion->query('SELECT * FROM senal_modelos_pulsador ORDER BY discontinuado,modelo_pulsador_nombre');
$tensiones=$conexion->query('SELECT * FROM senal_tensiones_modulo ORDER BY tension_modulo_id');
$bornes=$conexion->query('SELECT * FROM senal_bornes ORDER BY borne_id');
$colores=$conexion->query('SELECT * FROM senal_colores_registro ORDER BY color_registro_id');
$teclas=$conexion->query('SELECT * FROM senal_teclas ORDER BY tecla_id');
$tiposModQ=$conexion->query('SELECT * FROM senal_tipos_modulo ORDER BY tipo_modulo_id');
$productosSenalQ=msTablaExiste($conexion,'productos_senalizacion')?$conexion->query('SELECT * FROM productos_senalizacion ORDER BY habilitado DESC,codigo'):false;
$mods=array(); while($x=$modelos->fetch_assoc())$mods[]=$x; $tens=array();while($x=$tensiones->fetch_assoc())$tens[]=$x; $bors=array();while($x=$bornes->fetch_assoc())$bors[]=$x; $cols=array();while($x=$colores->fetch_assoc())$cols[]=$x; $tecs=array();while($x=$teclas->fetch_assoc())$tecs[]=$x; $tiposMod=array();while($x=$tiposModQ->fetch_assoc())$tiposMod[]=$x; $productosSenal=array();if($productosSenalQ)while($x=$productosSenalQ->fetch_assoc())$productosSenal[]=$x;
$indicadores=$conexion->query('SELECT * FROM senal_indicadores_cabina ORDER BY tipo_modulos,'.($tieneOrdenIndic?'orden,':'').'modelo_indicador');
$combinaciones=$conexion->query("SELECT m.*,mp.modelo_pulsador_nombre,tm.tension_modulo_nombre,b.borne_nombre,c.color_registro_nombre,t.tecla_nombre FROM matriz_botoneras_cabina m JOIN senal_modelos_pulsador mp ON mp.modelo_pulsador_id=m.modelo_pulsador_id JOIN senal_tensiones_modulo tm ON tm.tension_modulo_id=m.tension_modulo_id JOIN senal_bornes b ON b.borne_id=m.borne_id JOIN senal_colores_registro c ON c.color_registro_id=m.color_registro_id JOIN senal_teclas t ON t.tecla_id=m.tecla_id ORDER BY mp.modelo_pulsador_nombre,m.tipo_puerta,tm.tension_modulo_id,b.borne_id,c.color_registro_id,t.tecla_id");
$adicionalParada=$conexion->query('SELECT * FROM senal_adicional_parada_cabina ORDER BY modelo,tension,bornes,color,tecla,id');
$adicionalIndicador=$conexion->query('SELECT * FROM senal_adic_parada_indicador_cabina ORDER BY tipo_modulos,modelo_indicador,id');
$llavesAscensorista=msTablaExiste($conexion,'senal_llave_ascensorista_cabina')?$conexion->query('SELECT * FROM senal_llave_ascensorista_cabina ORDER BY tipo_llave,modelo_pulsador,tecla,color,id'):false;
$adicLlaveElectro=msTablaExiste($conexion,'senal_adic_llave_asc_electromecanico')?$conexion->query('SELECT * FROM senal_adic_llave_asc_electromecanico ORDER BY tipo_llave,modelo_pulsador,tecla,color,id'):false;
$brailleAsc=msTablaExiste($conexion,'senal_braille_lateral_ascensorista')?$conexion->query('SELECT * FROM senal_braille_lateral_ascensorista ORDER BY modelo_pulsador,id'):false;
$pulsExtMatriz=msTablaExiste($conexion,'senal_pulsadores_exteriores_matriz')?$conexion->query('SELECT * FROM senal_pulsadores_exteriores_matriz ORDER BY familia,orden,id'):false;
$pulsExtIndic=msTablaExiste($conexion,'senal_pulsadores_exteriores_indicadores')?$conexion->query('SELECT * FROM senal_pulsadores_exteriores_indicadores ORDER BY familia,orden,id'):false;
function msContar($c,$tabla){ if(!msTablaExiste($c,$tabla)) return 0; $r=$c->query('SELECT COUNT(*) c FROM `'.preg_replace('/[^a-zA-Z0-9_]/','',$tabla).'`'); return $r?(int)$r->fetch_assoc()['c']:0; }
$conteos=array(
 'base'=>msContar($conexion,'matriz_botoneras_cabina'),
 'adicional_parada'=>msContar($conexion,'senal_adicional_parada_cabina'),
 'indicadores'=>msContar($conexion,'senal_indicadores_cabina'),
 'adicional_indicador'=>msContar($conexion,'senal_adic_parada_indicador_cabina'),
 'adicionales'=>msContar($conexion,'senal_adicionales_cabina')+msContar($conexion,'senal_adicionales_especiales_cabina'),
 'auxiliares'=>msContar($conexion,'senal_llave_ascensorista_cabina')+msContar($conexion,'senal_adic_llave_asc_electromecanico')+msContar($conexion,'senal_braille_lateral_ascensorista'),
 'pulsadores_exteriores'=>msContar($conexion,'senal_pulsadores_exteriores_matriz')+msContar($conexion,'senal_pulsadores_exteriores_indicadores')
);
/* v356: Logo grabado es un adicional parametrizable y valorizado por A31XXGL. */
if(msTablaExiste($conexion,'senal_adicionales_cabina')){
    @$conexion->query("INSERT INTO senal_adicionales_cabina (adicional,etiqueta,codigo,activo,visible_cotizador,orden,tipo_calculo,cantidad_predeterminada,cantidad_editable,sincronizar_botoneras,bonificado_predeterminado,permite_bonificar,mostrar_codigo) SELECT 'LOGO GRABADO','Logo','A31XXGL',1,1,14,'POR_BOTONERA',1,0,0,0,1,1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM senal_adicionales_cabina WHERE UPPER(TRIM(codigo))='A31XXGL' OR UPPER(TRIM(adicional))='LOGO GRABADO')");
}
$adicionalesQ=$conexion->query('SELECT * FROM senal_adicionales_cabina ORDER BY orden,id');
$especialesQ=$conexion->query('SELECT * FROM senal_adicionales_especiales_cabina ORDER BY orden,id');
$adicionalesArr=array(); while($x=$adicionalesQ->fetch_assoc()) $adicionalesArr[]=$x;
$especialesArr=array(); while($x=$especialesQ->fetch_assoc()) $especialesArr[]=$x;
$editarTipo=(string)($_GET['editar_tipo']??'');
$editarId=(int)($_GET['editar_id']??0);
$senalParametros=array('CANT_LLAVE_ASCENSORISTA'=>'0','CANT_COMUNICACION_SERIE'=>'0');
if(msTablaExiste($conexion,'senal_parametros_cabina')){
    if($rp=$conexion->query("SELECT clave,valor FROM senal_parametros_cabina WHERE clave IN ('CANT_LLAVE_ASCENSORISTA','CANT_COMUNICACION_SERIE')")) while($x=$rp->fetch_assoc()) $senalParametros[$x['clave']]=$x['valor'];
}
$registroEditar=null;
if($editarId>0){
    $fuente=$editarTipo==='especial'?$especialesArr:$adicionalesArr;
    foreach($fuente as $x){ if((int)$x['id']===$editarId){$registroEditar=$x;break;} }
}
$reglasCotizacion=array();$reglasQ=trim((string)($_GET['rq']??''));$reglasSector=trim((string)($_GET['rsector']??''));$reglasSectores=array();$reglasPrecioMap=array();$reglasBejermanMap=array();
if($tab==='reglas_cotizacion' && msTablaExiste($conexion,'senal_codigos_cotizacion')){
    $reglasPrecioMap=msCatalogoPrecioMap($conexion);$reglasBejermanMap=msCatalogoBejermanMap($conexion);
    $r=$conexion->query("SELECT * FROM senal_codigos_cotizacion WHERE activo=1 ORDER BY sector,descripcion,codigo_original");
    if($r) while($x=$r->fetch_assoc()){
        $reglasSectores[$x['sector']]=true;
        $texto=strtoupper(implode(' ',array($x['sector'],$x['codigo_original'],$x['codigo_cotizacion'],$x['descripcion'])));
        if($reglasSector!=='' && $x['sector']!==$reglasSector) continue;
        if($reglasQ!=='' && strpos($texto,strtoupper($reglasQ))===false) continue;
        $reglasCotizacion[]=$x;
    }
}

$catalogoFilas=array(); $catalogoPrecioMap=array(); $catalogoBejermanMap=array(); $catalogoGrupos=array();
$catalogoQ=trim((string)($_GET['q']??'')); $catalogoGrupo=trim((string)($_GET['grupo']??''));
if($tab==='catalogo'){
    $catalogoFilas=msCatalogoCargar($conexion);
    $catalogoPrecioMap=msCatalogoPrecioMap($conexion);
    $catalogoBejermanMap=msCatalogoBejermanMap($conexion);
    foreach($catalogoFilas as $f) $catalogoGrupos[$f['grupo']]=true;
    if($catalogoQ!=='' || $catalogoGrupo!==''){
        $catalogoFilas=array_values(array_filter($catalogoFilas,function($f) use($catalogoQ,$catalogoGrupo,$catalogoPrecioMap,$catalogoBejermanMap){
            if($catalogoGrupo!=='' && $f['grupo']!==$catalogoGrupo) return false;
            if($catalogoQ==='') return true;
            $k=strtoupper(trim((string)$f['codigo'])); $p=$catalogoPrecioMap[$k]??array(); $b=$catalogoBejermanMap[$k]??array();
            $texto=$f['grupo'].' '.$f['criterio'].' '.$f['codigo'].' '.($p['precios_descripcion']??'').' '.($b['descripcion']??'');
            return stripos($texto,$catalogoQ)!==false;
        }));
    }
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mantenimiento de señalización</title>
<style>
body{font-family:Arial;background:#f4f6f8;color:#18232d;margin:18px}.wrap{max-width:1500px;background:#fff;padding:18px;border-radius:10px;box-shadow:0 3px 18px #0001}.tabs{display:flex;gap:8px;flex-wrap:wrap;margin:15px 0}.tabs a{padding:9px 14px;border-radius:7px;text-decoration:none;background:#edf2f5;color:#173042;font-weight:700}.tabs a.on{background:#0f8a55;color:#fff}.ok,.err,.aviso{padding:10px 12px;border-radius:7px;margin:10px 0}.ok{background:#dff4e7}.err{background:#fde3e1}.aviso{background:#fff3cd}.grid{display:grid;grid-template-columns:repeat(6,minmax(120px,1fr));gap:8px;align-items:end;margin:10px 0 16px}.grid input,.grid select{width:100%;box-sizing:border-box;padding:7px}.grid button,.btn{background:#0f8a55;color:white;border:0;border-radius:6px;padding:8px 12px;font-weight:bold}.scroll{overflow:auto;max-height:67vh}table{border-collapse:collapse;width:100%;font-size:12px}th,td{padding:6px;border-bottom:1px solid #dde3e7;text-align:left;vertical-align:middle}th{background:#edf2f5;position:sticky;top:0;z-index:1}td input[type=text],td input[type=number],td select{width:100%;min-width:85px;padding:5px;box-sizing:border-box}.estado{font-weight:bold}.off{opacity:.55}.acciones{white-space:nowrap}.seccion{margin-top:22px;border-top:2px solid #edf2f5;padding-top:14px}.mini{font-size:12px;color:#60717e}.toolbar{display:flex;justify-content:space-between;align-items:center;gap:12px;margin:14px 0}.btn-link{display:inline-block;background:#0f8a55;color:#fff;text-decoration:none;border-radius:7px;padding:9px 13px;font-weight:700}.btn-sec{display:inline-block;background:#edf2f5;color:#173042;text-decoration:none;border-radius:7px;padding:8px 11px;font-weight:700}.panel-form{background:#f8fafb;border:1px solid #dce5ea;border-radius:10px;padding:14px;margin:12px 0 18px}.panel-form h3{margin:0 0 5px}.lista-simple th{position:static}.estado-pill{display:inline-block;padding:4px 8px;border-radius:20px;font-size:11px;font-weight:700}.estado-on{background:#dff4e7;color:#0b6e43}.estado-off{background:#eceff1;color:#66757f}.tag{display:inline-block;background:#eef3f6;border-radius:5px;padding:3px 6px;font-size:11px}.acciones a{margin-right:5px}.ayuda{background:#eef8f2;border-left:4px solid #0f8a55;padding:10px 12px;border-radius:6px;margin:10px 0 16px}.nuevo-box{margin:8px 0 16px}.nuevo-box summary{cursor:pointer;display:inline-block;background:#0f8a55;color:#fff;border-radius:7px;padding:9px 13px;font-weight:700;list-style:none}.nuevo-box summary::-webkit-details-marker{display:none}.nuevo-box[open] summary{margin-bottom:10px}.matrix-home{display:grid;grid-template-columns:repeat(3,minmax(220px,1fr));gap:12px;margin:16px 0}.matrix-card{border:1px solid #dce5ea;border-radius:12px;padding:15px;background:#f9fbfc;text-decoration:none;color:#173042}.matrix-card:hover{border-color:#0f8a55;box-shadow:0 5px 18px #0f8a5518}.matrix-card strong{display:block;font-size:16px;margin-bottom:5px}.matrix-card .count{font-size:24px;font-weight:800;color:#0f8a55}.matrix-group{margin-top:22px}.matrix-note{font-size:12px;color:#60717e;margin-top:4px}.code-col{font-family:Consolas,monospace;font-weight:700}.wide-grid{grid-template-columns:repeat(8,minmax(105px,1fr))}@media(max-width:900px){.matrix-home{grid-template-columns:1fr}.grid,.wide-grid{grid-template-columns:1fr 1fr}.scroll{max-height:none}.toolbar{align-items:flex-start;flex-direction:column}}
.catalog-search{display:grid;grid-template-columns:1fr 300px auto;gap:10px;align-items:end;margin:14px 0}.catalog-table .crit{min-width:340px;white-space:normal}.catalog-code input{font-family:Consolas,monospace;font-weight:700}.bej-state{display:inline-block;padding:4px 7px;border-radius:999px;font-size:11px;font-weight:700}.bej-ok{background:#e4f5ea;color:#17643d}.bej-zero{background:#fff2cc;color:#785b00}.bej-missing{background:#fde7e5;color:#9a2d24}.catalog-desc{font-size:11px;color:#64717c;margin-top:3px}</style></head><body><?php require 'menu.php'; ?><div class="wrap">
<h1>Mantenimiento de señalización</h1><p>Centro único para revisar las matrices de Señalización que alimentan el cálculo. Desactivar conserva el historial y evita usar la opción en nuevas configuraciones cuando el motor consulta el estado.</p>
<?php if($mensaje):?><div class="ok"><?=msE($mensaje)?></div><?php endif;?><?php if($error):?><div class="err"><?=msE($error)?></div><?php endif;?>
<?php if(!$tieneOrdenIndic):?><div class="aviso">Falta aplicar la migración v19: la pantalla funciona, pero el orden de indicadores todavía no está disponible.</div><?php endif;?>
<div class="tabs"><a class="<?=$tab==='reglas_cotizacion'?'on':''?>" href="?tab=reglas_cotizacion">Reglas de cotización</a><a class="<?=$tab==='resumen'?'on':''?>" href="?tab=resumen">Mapa de matrices</a><a class="<?=$tab==='catalogo'?'on':''?>" href="?tab=catalogo">Buscar / códigos Bejerman</a><a class="<?=$tab==='base'?'on':''?>" href="?tab=base">Botoneras de cabina</a><a class="<?=$tab==='adicional_parada'?'on':''?>" href="?tab=adicional_parada">Adicional por parada</a><a class="<?=$tab==='indicadores'?'on':''?>" href="?tab=indicadores">Indicadores de posición</a><a class="<?=$tab==='adicional_indicador'?'on':''?>" href="?tab=adicional_indicador">Adic. parada indicador</a><a class="<?=$tab==='adicionales'?'on':''?>" href="?tab=adicionales">Adicionales</a><a class="<?=$tab==='auxiliares'?'on':''?>" href="?tab=auxiliares">Matrices auxiliares</a><a class="<?=$tab==='modelos'?'on':''?>" href="?tab=modelos">Modelos de pulsador</a><a class="<?=$tab==='catalogos_basicos'?'on':''?>" href="?tab=catalogos_basicos">Catálogos básicos</a><a class="<?=$tab==='productos'?'on':''?>" href="?tab=productos">Códigos / productos</a><a class="<?=$tab==='dependencias'?'on':''?>" href="?tab=dependencias">Dependencias</a><a class="<?=$tab==='pulsadores_exteriores'?'on':''?>" href="?tab=pulsadores_exteriores">Pulsadores exteriores</a><a class="<?=$tab==='parametros'?'on':''?>" href="?tab=parametros">Parámetros</a><a class="<?=$tab==='acabados'?'on':''?>" href="?tab=acabados">Acabados / medidas</a></div>

<?php if($tab==='reglas_cotizacion'): ?>
<h2>Reglas de cotización de Señalización</h2>
<div class="ayuda"><strong>Código técnico ≠ Código para cotizar</strong><br>El código original identifica exactamente lo que se fabrica y no se modifica. El código para cotizar indica qué artículo Bejerman aporta el valor. Varias configuraciones pueden usar el mismo código de cotización. Esta tabla se cargó desde revision(2).xls.</div>
<?php if(!msTablaExiste($conexion,'senal_codigos_cotizacion')): ?>
<div class="error">Falta ejecutar <b>COTIZADOR_AUTOMAC_v186_senalizacion_codigos.sql</b>.</div>
<?php else: ?>
<form method="get" class="grid"><input type="hidden" name="tab" value="reglas_cotizacion"><label>Buscar<input name="rq" value="<?=msE($reglasQ)?>" placeholder="Código, modelo, color, descripción..."></label><label>Sector<select name="rsector"><option value="">Todos</option><?php foreach(array_keys($reglasSectores) as $g):?><option value="<?=msE($g)?>" <?=$reglasSector===$g?'selected':''?>><?=msE($g)?></option><?php endforeach;?></select></label><button>Buscar</button></form>
<div class="scroll"><table><thead><tr><th>Sector</th><th>Descripción / combinación</th><th>Código técnico/original</th><th>Código Bejerman para cotizar</th><th>Bejerman vigente</th><th>Precio cotizador</th></tr></thead><tbody>
<?php foreach($reglasCotizacion as $r): $co=strtoupper(trim((string)$r['codigo_cotizacion']));$bj=$reglasBejermanMap[$co]??null;$lp=$reglasPrecioMap[$co]??null;$costo=$bj?(float)($bj['costo']??0):null;$estado=$bj?($costo>0?'OK':'PRECIO 0'):'NO EXISTE';$clase=$estado==='OK'?'bej-ok':($estado==='PRECIO 0'?'bej-zero':'bej-missing'); ?>
<tr><td><?=msE($r['sector'])?></td><td><strong><?=msE($r['descripcion'])?></strong></td><td><span class="tag"><?=msE($r['codigo_original'])?></span></td><td><form method="post" style="display:flex;gap:6px;align-items:center"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_codigo_cotizacion"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><input class="code-col" name="codigo_cotizacion" value="<?=msE($r['codigo_cotizacion'])?>" required><button class="btn">Guardar</button></form></td><td><span class="estado-pill <?=$clase?>"><?=$estado?></span><?php if($bj):?><br><span class="mini"><?=msE($bj['descripcion']??'')?> · $<?=number_format((float)$costo,0,',','.')?></span><?php endif;?></td><td><?=$lp?'$'.number_format((float)$lp['precios_costo'],0,',','.'):'—'?></td></tr>
<?php endforeach; ?>
<?php if(!$reglasCotizacion):?><tr><td colspan="6">No hay reglas con ese filtro.</td></tr><?php endif;?>
</tbody></table></div>
<?php endif; ?>

<?php elseif($tab==='resumen'): ?>
<h2>Mapa de matrices de Señalización</h2>
<div class="ayuda"><strong>Centro de revisión técnica</strong><br>Esta pantalla reúne las matrices que hoy usa el cálculo de Señalización. Cambiar códigos o activar/desactivar filas afecta nuevas cotizaciones; los documentos históricos conservan sus datos guardados. No se modifica ninguna fórmula desde este resumen.</div>
<div class="matrix-home">
<a class="matrix-card" href="?tab=base"><strong>1. BASE de botonera</strong><span class="count"><?=$conteos['base']?></span><div class="matrix-note">Puerta + modelo + tensión + bornes + color + tecla → código BASE.</div></a>
<a class="matrix-card" href="?tab=adicional_parada"><strong>2. Adicional por parada</strong><span class="count"><?=$conteos['adicional_parada']?></span><div class="matrix-note">Modelo + tensión + bornes + color + tecla → código de parada adicional.</div></a>
<a class="matrix-card" href="?tab=indicadores"><strong>3. Indicador de cabina</strong><span class="count"><?=$conteos['indicadores']?></span><div class="matrix-note">Modelo de indicador + tipo de módulos → código base del indicador.</div></a>
<a class="matrix-card" href="?tab=adicional_indicador"><strong>4. Adicional por parada del indicador</strong><span class="count"><?=$conteos['adicional_indicador']?></span><div class="matrix-note">Modelo de indicador + tipo de módulos → código por parada adicional.</div></a>
<a class="matrix-card" href="?tab=adicionales"><strong>5. Adicionales de botonera</strong><span class="count"><?=$conteos['adicionales']?></span><div class="matrix-note">Adicionales generales y especiales con reglas de cantidad/visibilidad.</div></a>
<a class="matrix-card" href="?tab=auxiliares"><strong>6. Matrices auxiliares</strong><span class="count"><?=$conteos['auxiliares']?></span><div class="matrix-note">Llave ascensorista, adicional electromecánico y braille lateral.</div></a>
</div>
<a class="matrix-card" href="?tab=pulsadores_exteriores"><strong>7. Pulsadores exteriores</strong><span class="count"><?=$conteos['pulsadores_exteriores']?></span><div class="matrix-note">4 matrices de pulsadores + 2 matrices de indicadores acompañantes.</div></a>
<a class="matrix-card" href="?tab=catalogo"><strong>8. Buscador / códigos Bejerman</strong><span class="count">⌕</span><div class="matrix-note">Busca en todas las matrices cargadas, muestra el estado del código en Bejerman y permite corregir el código usado para nuevas cotizaciones.</div></a>
<?php elseif($tab==='catalogo'): ?>
<h2>Buscador de lógica y códigos Bejerman de Señalización</h2>
<div class="ayuda"><strong>Vista unificada de lo cargado</strong><br>Acá podés revisar Botonera de cabina, Pulsadores exteriores, Indicadores y Accesorios/adicionales. El campo <b>Código Bejerman</b> es editable y modifica la matriz de origen: las nuevas cotizaciones usarán el código guardado. Los detalles comerciales ya emitidos permanecen guardados y no se reescriben desde esta pantalla.</div>
<form method="get" class="catalog-search">
<input type="hidden" name="tab" value="catalogo">
<label>Buscar por modelo, combinación, accesorio, indicador, código o descripción Bejerman<input name="q" value="<?=msE($catalogoQ)?>" placeholder="Ej. A31512BBA000M, A3160, BLANCO, indicador 18MM..."></label>
<label>Sector<select name="grupo"><option value="">Todos</option><?php foreach(array_keys($catalogoGrupos) as $g):?><option value="<?=msE($g)?>" <?=$catalogoGrupo===$g?'selected':''?>><?=msE($g)?></option><?php endforeach;?></select></label>
<button>Buscar</button>
</form>
<p class="catalog-note">Resultados: <span class="catalog-count"><?=count($catalogoFilas)?></span>. El estado de Bejerman se consulta contra la <b>base Bejerman vigente real</b>. La columna Precio cotizador muestra el valor operativo actual de <code>lista_precios</code> (Bejerman × utilidad/regla ya cargada). <b>PRECIO 0</b> significa que el artículo existe en Bejerman pero su costo es cero; <b>NO EXISTE</b> significa que el código no está en Bejerman vigente.</p>
<div class="scroll"><table class="catalog-table"><thead><tr><th>Sector</th><th>Qué combinación/regla es</th><th>Código Bejerman usado</th><th>Estado Bejerman vigente</th><th>Costo Bejerman</th><th>Precio cotizador</th></tr></thead><tbody>
<?php foreach($catalogoFilas as $f): $codKey=strtoupper(trim((string)$f['codigo'])); $bp=$catalogoPrecioMap[$codKey]??null; $bj=$catalogoBejermanMap[$codKey]??null; $costoBej=$bj?(float)($bj['costo']??0):null; $precioCot=$bp?(float)($bp['precios_costo']??0):null; $estado=$bj?($costoBej>0?'OK':'PRECIO 0'):'NO EXISTE'; $clase=$estado==='OK'?'bej-ok':($estado==='PRECIO 0'?'bej-zero':'bej-missing'); $fid='cat_'.preg_replace('/[^A-Za-z0-9_]/','_',$f['fuente'].'_'.$f['id']); ?>
<tr>
<td><strong><?=msE($f['grupo'])?></strong><?php if($f['activo']!==''):?><div class="mini">Estado matriz: <?=msE($f['activo'])?></div><?php endif;?></td>
<td class="crit"><?=msE($f['criterio']!==''?$f['criterio']:'Registro '.$f['id'])?></td>
<td class="catalog-code"><form id="<?=msE($fid)?>" method="post"><input type="hidden" name="accion" value="guardar_codigo_catalogo"><input type="hidden" name="fuente" value="<?=msE($f['fuente'])?>"><input type="hidden" name="registro_id" value="<?=msE($f['id'])?>"><input type="hidden" name="tab" value="catalogo"><input name="codigo_bejerman" value="<?=msE($f['codigo'])?>" required></form></td>
<td><span class="bej-state <?=$clase?>"><?=$estado?></span><?php if($bj):?><div class="catalog-desc"><?=msE($bj['descripcion']??'')?></div><?php endif;?></td>
<td class="catalog-price"><?=$costoBej!==null?'$ '.number_format($costoBej,2,',','.'):'—'?></td>
<td class="catalog-price"><?=$precioCot!==null && $precioCot>0?'$ '.number_format($precioCot,2,',','.'):'—'?><?php if($bp && !empty($bp['precios_descripcion'])):?><div class="catalog-desc"><?=msE($bp['precios_descripcion'])?></div><?php endif;?></td>
<td><button form="<?=msE($fid)?>" class="btn" type="submit">Guardar código</button></td>
</tr>
<?php endforeach; ?>
<?php if(!$catalogoFilas):?><tr><td colspan="7">No se encontraron registros con esos filtros.</td></tr><?php endif;?>
</tbody></table></div>

<?php elseif($tab==='indicadores'): ?>
<h2>Indicadores de cabina</h2><form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_indicador"><label>Modelo<input name="modelo_indicador" required placeholder="Ej. 31MM V5R"></label><label>Tipo<select name="tipo_modulos"><option>ELECTRONICO</option><option>ELECTROMECANICO</option></select></label><label>Código<input name="codigo" required placeholder="A4431V5X"></label><?php if($tieneOrdenIndic):?><label>Orden<input type="number" name="orden" value="100"></label><?php endif;?><label><input type="checkbox" name="activo" checked> Activo</label><button>Agregar indicador</button></form>
<div class="scroll"><table><thead><tr><th>Modelo</th><th>Tipo</th><th>Código</th><?php if($tieneOrdenIndic):?><th>Orden</th><?php endif;?><th>Activo</th><th></th></tr></thead><tbody><?php while($r=$indicadores->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_indicador"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="modelo_indicador" value="<?=msE($r['modelo_indicador'])?>"></td><td><select name="tipo_modulos"><option<?=$r['tipo_modulos']==='ELECTRONICO'?' selected':''?>>ELECTRONICO</option><option<?=$r['tipo_modulos']==='ELECTROMECANICO'?' selected':''?>>ELECTROMECANICO</option></select></td><td><input name="codigo" value="<?=msE($r['codigo'])?>"></td><?php if($tieneOrdenIndic):?><td><input type="number" name="orden" value="<?=(int)$r['orden']?>"></td><?php endif;?><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table></div>

<?php elseif($tab==='pulsadores_exteriores'): ?>
<h2>Pulsadores exteriores</h2>
<div class="ayuda"><strong>Matrices comerciales v179</strong><br>Cada familia es independiente. El código se resuelve por Tipo + Modelo + Color + Tensión + Bornes + Tecla y el precio se toma de la base Bejerman seleccionada. Desactivar una fila no altera documentos históricos.</div>
<?php if(!msTablaExiste($conexion,'senal_pulsadores_exteriores_matriz')||!msTablaExiste($conexion,'senal_pulsadores_exteriores_indicadores')): ?>
<div class="aviso"><strong>Falta aplicar la migración v179.</strong> Ejecute COTIZADOR_AUTOMAC_v179_pulsadores_exteriores.sql.</div>
<?php else: ?>
<details class="nuevo-box"><summary>+ Nueva combinación de pulsador</summary><div class="panel-form"><form method="post" class="grid wide-grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_pulsador_exterior_matriz"><label>Familia<select name="familia"><option>SIMPLE</option><option>SIMPLE_IP</option><option>DOBLE</option><option>DOBLE_IP</option></select></label><label>Tipo<input name="tipo_modulos" required></label><label>Modelo<input name="modelo_pulsador" required></label><label>Color<input name="color_registro" required></label><label>Tensión<input name="tension_modulos" required></label><label>Bornes<input name="bornes" required></label><label>Tecla<input name="tecla_modulos" required></label><label>Código<input name="codigo" required></label><label>Orden<input type="number" name="orden" value="100"></label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar</button></form></div></details>
<div class="scroll"><table><thead><tr><th>Familia</th><th>Tipo</th><th>Modelo</th><th>Color</th><th>Tensión</th><th>Bornes</th><th>Tecla</th><th>Código</th><th>Orden</th><th>Activa</th><th></th></tr></thead><tbody><?php if($pulsExtMatriz):while($r=$pulsExtMatriz->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_pulsador_exterior_matriz"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><select name="familia"><?php foreach(array('SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP') as $f):?><option<?=$r['familia']===$f?' selected':''?>><?=$f?></option><?php endforeach;?></select></td><td><input name="tipo_modulos" value="<?=msE($r['tipo_modulos'])?>"></td><td><input name="modelo_pulsador" value="<?=msE($r['modelo_pulsador'])?>"></td><td><input name="color_registro" value="<?=msE($r['color_registro'])?>"></td><td><input name="tension_modulos" value="<?=msE($r['tension_modulos'])?>"></td><td><input name="bornes" value="<?=msE($r['bornes'])?>"></td><td><input name="tecla_modulos" value="<?=msE($r['tecla_modulos'])?>"></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="number" name="orden" value="<?=(int)$r['orden']?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;endif;?></tbody></table></div>
<div class="seccion"><h3>Indicadores que acompañan a pulsadores + IP</h3><p class="mini">La relación depende del código del indicador seleccionado en la botonera de cabina y del tipo de módulos.</p>
<details class="nuevo-box"><summary>+ Nuevo indicador exterior</summary><div class="panel-form"><form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_pulsador_exterior_indicador"><label>Familia<select name="familia"><option>SIMPLE_IP</option><option>DOBLE_IP</option></select></label><label>Modelo<input name="modelo" required></label><label>Tipo<input name="tipo_modulos" required></label><label>Código exterior<input name="codigo" required></label><label>Depende de IP cabina<input name="depende_codigo_ip_cabina" required></label><label>Orden<input type="number" name="orden" value="100"></label><label><input type="checkbox" name="activo" checked> Activo</label><button>Agregar</button></form></div></details>
<div class="scroll"><table><thead><tr><th>Familia</th><th>Modelo</th><th>Tipo</th><th>Código exterior</th><th>Depende de IP cabina</th><th>Orden</th><th>Activo</th><th></th></tr></thead><tbody><?php if($pulsExtIndic):while($r=$pulsExtIndic->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_pulsador_exterior_indicador"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><select name="familia"><option<?=$r['familia']==='SIMPLE_IP'?' selected':''?>>SIMPLE_IP</option><option<?=$r['familia']==='DOBLE_IP'?' selected':''?>>DOBLE_IP</option></select></td><td><input name="modelo" value="<?=msE($r['modelo'])?>"></td><td><input name="tipo_modulos" value="<?=msE($r['tipo_modulos'])?>"></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input class="code-col" name="depende_codigo_ip_cabina" value="<?=msE($r['depende_codigo_ip_cabina'])?>"></td><td><input type="number" name="orden" value="<?=(int)$r['orden']?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;endif;?></tbody></table></div></div>
<?php endif; ?>

<?php elseif($tab==='modelos' || $tab==='pulsadores'): ?>
<h2>Modelos de pulsador</h2><div class="ayuda"><strong>Catálogo maestro</strong><br>Puede crear modelos nuevos y editar los existentes. Marcar un modelo como “Discontinuado” lo retira de nuevas configuraciones, pero conserva las matrices y documentos históricos que ya lo utilizan.</div>
<form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_modelo"><label>Nuevo modelo<input name="modelo_pulsador_nombre" required placeholder="Ej. NUEVO MODELO"></label><label><input type="checkbox" name="discontinuado"> Crear discontinuado</label><button>Agregar modelo</button></form>
<div class="scroll"><table><thead><tr><th>ID</th><th>Modelo</th><th>Dependencias</th><th>Estado</th><th></th></tr></thead><tbody><?php foreach($mods as $r): $dep=msModeloDependencias($conexion,(int)$r['modelo_pulsador_id'],$r['modelo_pulsador_nombre']); $totalDep=array_sum($dep); ?><tr class="<?=$r['discontinuado']==='SI'?'off':''?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_modelo"><input type="hidden" name="modelo_pulsador_id" value="<?=(int)$r['modelo_pulsador_id']?>"><td><?=(int)$r['modelo_pulsador_id']?></td><td><input name="modelo_pulsador_nombre" value="<?=msE($r['modelo_pulsador_nombre'])?>"></td><td><strong><?=$totalDep?></strong> referencias<br><span class="mini"><?=$dep['botoneras']?> botoneras · <?=$dep['pulsadores_exteriores']?> puls. ext. · <?=$dep['adicional_parada']?> adic. parada · <?=$dep['llaves']+$dep['braille']?> auxiliares</span></td><td><label><input type="checkbox" name="discontinuado" <?=$r['discontinuado']==='SI'?'checked':''?>> Discontinuado</label></td><td><button class="btn">Guardar</button></td></form></tr><?php endforeach;?></tbody></table></div>

<?php elseif($tab==='catalogos_basicos'): ?>
<h2>Catálogos básicos de Señalización</h2>
<div class="ayuda"><strong>Catálogos maestros</strong><br>Administra tensiones, bornes, colores, teclas y tipos de módulo. Al renombrar un valor, v222 sincroniza las matrices que guardan ese criterio como texto; las relaciones por ID se mantienen automáticamente.</div>
<?php
$catalogosVista=array(
 'tension'=>array('titulo'=>'Tensiones de módulo','pk'=>'tension_modulo_id','campo'=>'tension_modulo_nombre','filas'=>$tens),
 'borne'=>array('titulo'=>'Bornes','pk'=>'borne_id','campo'=>'borne_nombre','filas'=>$bors),
 'color'=>array('titulo'=>'Colores de registro','pk'=>'color_registro_id','campo'=>'color_registro_nombre','filas'=>$cols),
 'tecla'=>array('titulo'=>'Teclas','pk'=>'tecla_id','campo'=>'tecla_nombre','filas'=>$tecs),
 'tipo_modulo'=>array('titulo'=>'Tipos de módulo','pk'=>'tipo_modulo_id','campo'=>'tipo_modulo_nombre','filas'=>$tiposMod)
);
?>
<div class="matrix-home"><?php foreach($catalogosVista as $clave=>$cv):?><div class="matrix-card" style="text-decoration:none"><strong><?=msE($cv['titulo'])?></strong><span class="count"><?=count($cv['filas'])?></span></div><?php endforeach;?></div>
<?php foreach($catalogosVista as $clave=>$cv):?><div class="matrix-group"><h3><?=msE($cv['titulo'])?></h3><form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_catalogo_basico"><input type="hidden" name="catalogo" value="<?=msE($clave)?>"><label>Nuevo valor<input name="nombre" required></label><button>Agregar</button></form><div class="scroll"><table class="lista-simple"><thead><tr><th>ID</th><th>Valor</th><th></th></tr></thead><tbody><?php foreach($cv['filas'] as $r):?><tr><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_catalogo_basico"><input type="hidden" name="catalogo" value="<?=msE($clave)?>"><input type="hidden" name="id" value="<?=(int)$r[$cv['pk']]?>"><td><?=(int)$r[$cv['pk']]?></td><td><input name="nombre" value="<?=msE($r[$cv['campo']])?>"></td><td><button class="btn">Guardar</button></td></form></tr><?php endforeach;?></tbody></table></div></div><?php endforeach;?>

<?php elseif($tab==='productos'): ?>
<h2>Códigos / productos de Señalización</h2>
<div class="ayuda"><strong>Costo × utilidad</strong><br>Esta tabla define la utilidad comercial de los códigos propios de Señalización. El costo se obtiene de la Base Bejerman seleccionada y el motor aplica la utilidad habilitada; no modifica documentos históricos.</div>
<form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_producto_senal"><label>Código Bejerman<input name="codigo" required></label><label style="grid-column:span 2">Descripción<input name="descripcion" required></label><label>Utilidad<input type="number" min="0.01" step="0.01" name="utilidad" value="1.00"></label><label><input type="checkbox" name="habilitado" checked> Habilitado</label><button>Agregar producto</button></form>
<div class="scroll"><table><thead><tr><th>Código</th><th>Descripción</th><th>Utilidad</th><th>Bejerman</th><th>Habilitado</th><th></th></tr></thead><tbody><?php foreach($productosSenal as $r):?><tr class="<?=$r['habilitado']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_producto_senal"><input type="hidden" name="producto_senalizacion_id" value="<?=(int)$r['producto_senalizacion_id']?>"><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input name="descripcion" value="<?=msE($r['descripcion'])?>"></td><td><input type="number" min="0.01" step="0.01" name="utilidad" value="<?=msE($r['utilidad'])?>"></td><td><?=msCodigoExisteBejerman($conexion,$r['codigo'])?'<span class="bej-state bej-ok">OK</span>':'<span class="bej-state bej-missing">NO EXISTE</span>'?></td><td><input type="checkbox" name="habilitado" <?=$r['habilitado']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endforeach;?></tbody></table></div>

<?php elseif($tab==='dependencias'): ?>
<h2>Dependencias de Señalización</h2>
<div class="ayuda"><strong>Impacto antes de modificar</strong><br>Esta vista muestra dónde se reutilizan modelos y códigos. Sirve para evaluar el impacto de un cambio antes de editar o discontinuar una configuración.</div>
<div class="matrix-group"><h3>Dependencias por modelo de pulsador</h3><div class="scroll"><table><thead><tr><th>Modelo</th><th>Botoneras</th><th>Pulsadores exteriores</th><th>Adic. parada</th><th>Auxiliares</th><th>Total</th></tr></thead><tbody><?php foreach($mods as $m):$d=msModeloDependencias($conexion,(int)$m['modelo_pulsador_id'],$m['modelo_pulsador_nombre']);$aux=$d['llaves']+$d['braille'];$tot=array_sum($d);?><tr><td><strong><?=msE($m['modelo_pulsador_nombre'])?></strong><?=$m['discontinuado']==='SI'?'<br><span class="estado-pill estado-off">Discontinuado</span>':''?></td><td><?=$d['botoneras']?></td><td><?=$d['pulsadores_exteriores']?></td><td><?=$d['adicional_parada']?></td><td><?=$aux?></td><td><strong><?=$tot?></strong></td></tr><?php endforeach;?></tbody></table></div></div>
<?php $depRows=msCatalogoCargar($conexion);$porCodigo=array();foreach($depRows as $dr){$k=strtoupper(trim($dr['codigo']));if($k==='')continue;if(!isset($porCodigo[$k]))$porCodigo[$k]=array();$porCodigo[$k][]=$dr;}ksort($porCodigo);?>
<div class="matrix-group"><h3>Códigos reutilizados en matrices</h3><div class="scroll"><table><thead><tr><th>Código</th><th>Usos</th><th>Origen / criterios</th><th>Bejerman</th></tr></thead><tbody><?php foreach($porCodigo as $cod=>$usos):if(count($usos)<2)continue;?><tr><td class="code-col"><?=msE($cod)?></td><td><strong><?=count($usos)?></strong></td><td><?php foreach(array_slice($usos,0,6) as $u):?><div><strong><?=msE($u['grupo'])?></strong> · <?=msE($u['criterio'])?></div><?php endforeach;?><?=count($usos)>6?'<span class="mini">+'.(count($usos)-6).' usos más</span>':''?></td><td><?=msCodigoExisteBejerman($conexion,$cod)?'<span class="bej-state bej-ok">OK</span>':'<span class="bej-state bej-missing">NO EXISTE</span>'?></td></tr><?php endforeach;?></tbody></table></div></div>

<?php elseif($tab==='base'): ?>
<h2>Botoneras de cabina · Matriz base</h2><div class="ayuda"><strong>Crear y editar combinaciones</strong><br>Cada fila define Puerta + Modelo + Tensión + Bornes + Color + Tecla → Código Bejerman. Al guardar se controlan duplicados y se verifica que el código exista. Desactivar una fila evita su uso en nuevas cotizaciones sin borrar históricos.</div><form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_combinacion"><label>Puerta<select name="tipo_puerta"><option>PA</option><option>PM</option></select></label><label>Modelo<select name="modelo_pulsador_id"><?php foreach($mods as $m):?><option value="<?=(int)$m['modelo_pulsador_id']?>"><?=msE($m['modelo_pulsador_nombre'])?></option><?php endforeach;?></select></label><label>Tensión<select name="tension_modulo_id"><?php foreach($tens as $x):?><option value="<?=(int)$x['tension_modulo_id']?>"><?=msE($x['tension_modulo_nombre'])?></option><?php endforeach;?></select></label><label>Borne<select name="borne_id"><?php foreach($bors as $x):?><option value="<?=(int)$x['borne_id']?>"><?=msE($x['borne_nombre'])?></option><?php endforeach;?></select></label><label>Color<select name="color_registro_id"><?php foreach($cols as $x):?><option value="<?=(int)$x['color_registro_id']?>"><?=msE($x['color_registro_nombre'])?></option><?php endforeach;?></select></label><label>Tecla<select name="tecla_id"><?php foreach($tecs as $x):?><option value="<?=(int)$x['tecla_id']?>"><?=msE($x['tecla_nombre'])?></option><?php endforeach;?></select></label><label>Código BASE<input name="codigo" required></label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar combinación</button></form>
<div class="scroll"><table><thead><tr><th>Puerta</th><th>Modelo</th><th>Tensión</th><th>Borne</th><th>Color</th><th>Tecla</th><th>Código Bejerman</th><th>Bejerman</th><th>Activa</th><th></th></tr></thead><tbody><?php while($r=$combinaciones->fetch_assoc()):?><tr class="<?=$r['activo']==='SI'?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_combinacion"><input type="hidden" name="matriz_botonera_id" value="<?=(int)$r['matriz_botonera_id']?>"><td><select name="tipo_puerta"><option<?=$r['tipo_puerta']==='PA'?' selected':''?>>PA</option><option<?=$r['tipo_puerta']==='PM'?' selected':''?>>PM</option></select></td><td><select name="modelo_pulsador_id"><?php foreach($mods as $m):?><option value="<?=(int)$m['modelo_pulsador_id']?>"<?=$r['modelo_pulsador_id']==$m['modelo_pulsador_id']?' selected':''?>><?=msE($m['modelo_pulsador_nombre'])?></option><?php endforeach;?></select></td><td><select name="tension_modulo_id"><?php foreach($tens as $x):?><option value="<?=(int)$x['tension_modulo_id']?>"<?=$r['tension_modulo_id']==$x['tension_modulo_id']?' selected':''?>><?=msE($x['tension_modulo_nombre'])?></option><?php endforeach;?></select></td><td><select name="borne_id"><?php foreach($bors as $x):?><option value="<?=(int)$x['borne_id']?>"<?=$r['borne_id']==$x['borne_id']?' selected':''?>><?=msE($x['borne_nombre'])?></option><?php endforeach;?></select></td><td><select name="color_registro_id"><?php foreach($cols as $x):?><option value="<?=(int)$x['color_registro_id']?>"<?=$r['color_registro_id']==$x['color_registro_id']?' selected':''?>><?=msE($x['color_registro_nombre'])?></option><?php endforeach;?></select></td><td><select name="tecla_id"><?php foreach($tecs as $x):?><option value="<?=(int)$x['tecla_id']?>"<?=$r['tecla_id']==$x['tecla_id']?' selected':''?>><?=msE($x['tecla_nombre'])?></option><?php endforeach;?></select></td><td><input name="codigo" value="<?=msE($r['codigo'])?>"></td><td><?=msCodigoExisteBejerman($conexion,$r['codigo'])?'<span class="bej-state bej-ok">OK</span>':'<span class="bej-state bej-missing">NO EXISTE</span>'?></td><td><input type="checkbox" name="activo" <?=$r['activo']==='SI'?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table></div>

<?php elseif($tab==='adicional_parada'): ?>
<h2>Matriz de adicional por parada de botonera</h2>
<div class="ayuda"><strong>Tabla: senal_adicional_parada_cabina</strong><br>Revisá esta matriz junto con la BASE. Cada fila define el código usado para una parada adicional según modelo, tensión, bornes, color y tecla. No cambia la fórmula del cálculo.</div>
<form method="post" class="grid wide-grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_adicional_parada"><label>Modelo<input name="modelo" required></label><label>Tensión<input name="tension" required></label><label>Bornes<input name="bornes" required></label><label>Color<input name="color" required></label><label>Tecla<input name="tecla" required></label><label>Código<input name="codigo" required></label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar fila</button></form>
<div class="scroll"><table><thead><tr><th>Modelo</th><th>Tensión</th><th>Bornes</th><th>Color</th><th>Tecla</th><th>Código</th><th>Activa</th><th></th></tr></thead><tbody><?php while($r=$adicionalParada->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_adicional_parada"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="modelo" value="<?=msE($r['modelo'])?>"></td><td><input name="tension" value="<?=msE($r['tension'])?>"></td><td><input name="bornes" value="<?=msE($r['bornes'])?>"></td><td><input name="color" value="<?=msE($r['color'])?>"></td><td><input name="tecla" value="<?=msE($r['tecla'])?>"></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table></div>

<?php elseif($tab==='adicional_indicador'): ?>
<h2>Matriz de adicional por parada del indicador de cabina</h2>
<div class="ayuda"><strong>Tabla: senal_adic_parada_indicador_cabina</strong><br>Esta matriz se evalúa después de seleccionar el indicador de cabina. Define el código adicional por parada según modelo de indicador y tipo de módulos.</div>
<form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_adicional_indicador"><label>Modelo indicador<input name="modelo_indicador" required></label><label>Tipo<select name="tipo_modulos"><option>ELECTRONICO</option><option>ELECTROMECANICO</option></select></label><label>Código<input name="codigo" required></label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar fila</button></form>
<div class="scroll"><table><thead><tr><th>Modelo</th><th>Tipo</th><th>Código</th><th>Activa</th><th></th></tr></thead><tbody><?php while($r=$adicionalIndicador->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_adicional_indicador"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="modelo_indicador" value="<?=msE($r['modelo_indicador'])?>"></td><td><select name="tipo_modulos"><option<?=$r['tipo_modulos']==='ELECTRONICO'?' selected':''?>>ELECTRONICO</option><option<?=$r['tipo_modulos']==='ELECTROMECANICO'?' selected':''?>>ELECTROMECANICO</option></select></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;?></tbody></table></div>

<?php elseif($tab==='auxiliares'): ?>
<h2>Matrices auxiliares de botonera</h2>
<div class="ayuda"><strong>Alta y edición completa</strong><br>Llave ascensorista, adicional electromecánico y Braille lateral quedan mantenibles desde esta pantalla. Los códigos se validan contra la Base Bejerman antes de guardar.</div>
<div class="matrix-group"><h3>Llave ascensorista</h3>
<form method="post" class="grid wide-grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="llave"><label>Tipo<select name="tipo_llave"><?php foreach($tiposMod as $tm):?><option><?=msE($tm['tipo_modulo_nombre'])?></option><?php endforeach;?></select></label><label>Modelo<select name="modelo_pulsador"><?php foreach($mods as $m):?><option><?=msE($m['modelo_pulsador_nombre'])?></option><?php endforeach;?></select></label><label>Tecla<select name="tecla"><?php foreach($tecs as $x):?><option><?=msE($x['tecla_nombre'])?></option><?php endforeach;?></select></label><label>Color<select name="color"><?php foreach($cols as $x):?><option><?=msE($x['color_registro_nombre'])?></option><?php endforeach;?></select></label><label>Código<input name="codigo" required></label><label><input type="checkbox" name="ascensorista" checked> Ascensorista</label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar</button></form>
<div class="scroll"><table><thead><tr><th>Tipo</th><th>Modelo</th><th>Tecla</th><th>Color</th><th>Asc.</th><th>Código</th><th>Activo</th><th></th></tr></thead><tbody><?php if($llavesAscensorista):while($r=$llavesAscensorista->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="llave"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="tipo_llave" value="<?=msE($r['tipo_llave'])?>"></td><td><input name="modelo_pulsador" value="<?=msE($r['modelo_pulsador'])?>"></td><td><input name="tecla" value="<?=msE($r['tecla'])?>"></td><td><input name="color" value="<?=msE($r['color'])?>"></td><td><input type="checkbox" name="ascensorista" <?=$r['ascensorista']?'checked':''?>></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;endif;?></tbody></table></div></div>

<div class="matrix-group"><h3>Adicional llave ascensorista electromecánico</h3>
<form method="post" class="grid wide-grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="llave_em"><label>Tipo<input name="tipo_llave" value="ELECTROMECANICO"></label><label>Modelo<select name="modelo_pulsador"><?php foreach($mods as $m):?><option><?=msE($m['modelo_pulsador_nombre'])?></option><?php endforeach;?></select></label><label>Tecla<select name="tecla"><?php foreach($tecs as $x):?><option><?=msE($x['tecla_nombre'])?></option><?php endforeach;?></select></label><label>Color<select name="color"><?php foreach($cols as $x):?><option><?=msE($x['color_registro_nombre'])?></option><?php endforeach;?></select></label><label>Código<input name="codigo" required></label><label><input type="checkbox" name="ascensorista" checked> Ascensorista</label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar</button></form>
<div class="scroll"><table><thead><tr><th>Tipo</th><th>Modelo</th><th>Tecla</th><th>Color</th><th>Asc.</th><th>Código</th><th>Activo</th><th></th></tr></thead><tbody><?php if($adicLlaveElectro):while($r=$adicLlaveElectro->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="llave_em"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="tipo_llave" value="<?=msE($r['tipo_llave'])?>"></td><td><input name="modelo_pulsador" value="<?=msE($r['modelo_pulsador'])?>"></td><td><input name="tecla" value="<?=msE($r['tecla'])?>"></td><td><input name="color" value="<?=msE($r['color'])?>"></td><td><input type="checkbox" name="ascensorista" <?=$r['ascensorista']?'checked':''?>></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;endif;?></tbody></table></div></div>

<div class="matrix-group"><h3>Braille lateral con ascensorista</h3>
<form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="braille"><label>Modelo<select name="modelo_pulsador"><?php foreach($mods as $m):?><option><?=msE($m['modelo_pulsador_nombre'])?></option><?php endforeach;?></select></label><label>Código<input name="codigo" required></label><label><input type="checkbox" name="ascensorista" checked> Llave ascensorista</label><label><input type="checkbox" name="activo" checked> Activa</label><button>Agregar</button></form>
<div class="scroll"><table><thead><tr><th>Modelo</th><th>Llave ascensorista</th><th>Código</th><th>Activo</th><th></th></tr></thead><tbody><?php if($brailleAsc):while($r=$brailleAsc->fetch_assoc()):?><tr class="<?=$r['activo']?'':'off'?>"><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_auxiliar"><input type="hidden" name="aux_tipo" value="braille"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><input name="modelo_pulsador" value="<?=msE($r['modelo_pulsador'])?>"></td><td><input type="checkbox" name="ascensorista" <?=$r['llave_ascensorista']?'checked':''?>></td><td><input class="code-col" name="codigo" value="<?=msE($r['codigo'])?>"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile;endif;?></tbody></table></div></div>

<?php elseif($tab==='acabados'): ?>
<h2>Acabados de tapa y medidas especiales</h2>
<div class="ayuda"><strong>Tabla única de coeficientes</strong><br>Se aplica a Botonera de cabina, Pulsadores exteriores e Indicadores de posición exteriores. La medida es especial solamente cuando el usuario marca la opción. Un coeficiente vacío queda <b>A DEFINIR</b> y bloquea la valorización de ese caso.</div>
<?php if(!msTablaExiste($conexion,'senal_acabados_coeficientes')): ?><div class="err">Falta ejecutar COTIZADOR_AUTOMAC_v190_senalizacion_acabados_indicadores.sql.</div><?php else: $ra=$conexion->query('SELECT * FROM senal_acabados_coeficientes ORDER BY acabado,medida_especial'); ?>
<div class="scroll"><table><thead><tr><th>Acabado</th><th>Medida especial</th><th>Coeficiente</th><th>Estado</th><th></th></tr></thead><tbody><?php while($r=$ra->fetch_assoc()): ?><tr><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_acabado_coef"><input type="hidden" name="id" value="<?=(int)$r['id']?>"><td><strong><?=msE($r['acabado'])?></strong></td><td><?=$r['medida_especial']?'Sí':'No'?></td><td><input name="coeficiente" value="<?=$r['coeficiente']===null?'':msE((string)$r['coeficiente'])?>" placeholder="A DEFINIR"></td><td><input type="checkbox" name="activo" <?=$r['activo']?'checked':''?>></td><td><button class="btn">Guardar</button></td></form></tr><?php endwhile; ?></tbody></table></div><?php endif; ?>
<?php elseif($tab==='parametros'): ?>
<h2>Parámetros especiales de señalización</h2>
<div class="ayuda"><strong>Cantidades predeterminadas</strong><br>Estos valores se usan al iniciar una cotización nueva. Las cantidades guardadas en cotizaciones/pedidos históricos no se modifican.</div>
<div class="panel-form"><form method="post" class="grid"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar_parametros"><label>Servicio ascensorista<input type="number" step="1" min="0" name="cant_llave_ascensorista" value="<?=msE((int)$senalParametros['CANT_LLAVE_ASCENSORISTA'])?>"></label><label>Comunicación serie<input type="number" step="1" min="0" name="cant_comunicacion_serie" value="<?=msE((int)$senalParametros['CANT_COMUNICACION_SERIE'])?>"></label><button>Guardar parámetros</button></form></div>
<?php else: ?>
<h2>Adicionales de botonera</h2>
<div class="ayuda"><strong>¿Qué se administra acá?</strong><br>El listado muestra los adicionales disponibles para nuevas cotizaciones. Para crear uno nuevo use <b>+ Nuevo adicional</b>. Para modificar uno existente use <b>Editar</b>. Desactivar u ocultar no modifica documentos históricos.<br><strong>Logo:</strong> se administra aquí con el código <b>A31XXGL</b>. Puede definir <b>Permite bonificar</b> y <b>Bonificado por defecto</b> como cualquier otro adicional.</div>
<?php
function msCamposAdicional($r=array(),$especial=false){
    $id=(int)($r['id']??0); $tipo=(string)($r['tipo_calculo']??'CANTIDAD_DIRECTA'); ?>
    <input type="hidden" name="accion" value="guardar_adicional"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="tabla" value="<?=$especial?'senal_adicionales_especiales_cabina':'senal_adicionales_cabina'?>">
    <label>Clave técnica<input name="adicional" required value="<?=msE($r['adicional']??'')?>" placeholder="LUZ DE EMERGENCIA"></label>
    <label>Nombre visible<input name="etiqueta" required value="<?=msE($r['etiqueta']??'')?>" placeholder="Luz de emergencia"></label>
    <label>Código<input name="codigo" required value="<?=msE($r['codigo']??'')?>"></label>
    <label>Orden<input type="number" name="orden" value="<?=(int)($r['orden']??100)?>"></label>
    <label>Regla<select name="tipo_calculo" class="js-tipo-calculo-adicional" onchange="msSincronizarReglaBotoneras(this)"><option value="CANTIDAD_DIRECTA"<?=$tipo==='CANTIDAD_DIRECTA'?' selected':''?>>Cantidad directa</option><option value="POR_BOTONERA"<?=$tipo==='POR_BOTONERA'?' selected':''?>>Cantidad × botoneras</option></select></label>
    <label class="js-cant-pred-label">Cant. predeterminada<input type="number" step="1" min="0" name="cantidad_predeterminada" class="js-cant-pred" value="<?=msE($tipo==='POR_BOTONERA'?1:($r['cantidad_predeterminada']??1))?>"><small class="js-cant-pred-ayuda" style="display:block;margin-top:4px;color:#667085"><?=$tipo==='POR_BOTONERA'?'Automática: en el cotizador será igual a la cantidad de botoneras.':''?></small></label>
    <label><input type="checkbox" name="cantidad_editable" <?=!isset($r['cantidad_editable'])||!empty($r['cantidad_editable'])?'checked':''?>> Cantidad editable</label>
    <label><input type="checkbox" name="sincronizar_botoneras" class="js-sync-botoneras" <?=($tipo==='POR_BOTONERA'||!empty($r['sincronizar_botoneras']))?'checked':''?>> Sincronizar con botoneras</label>
    <label><input type="checkbox" name="permite_bonificar" <?=!empty($r['permite_bonificar'])?'checked':''?>> Permite bonificar</label>
    <label><input type="checkbox" name="bonificado_predeterminado" <?=!empty($r['bonificado_predeterminado'])?'checked':''?>> Bonificado por defecto</label>
    <label><input type="checkbox" name="mostrar_codigo" <?=!isset($r['mostrar_codigo'])||!empty($r['mostrar_codigo'])?'checked':''?>> Mostrar código</label>
    <label><input type="checkbox" name="visible_cotizador" <?=!isset($r['visible_cotizador'])||!empty($r['visible_cotizador'])?'checked':''?>> Visible en cotizador</label>
    <?php if($especial):?><label><input type="checkbox" name="aplica" <?=!isset($r['aplica'])||!empty($r['aplica'])?'checked':''?>> Aplica</label><?php endif;?>
    <label><input type="checkbox" name="activo" <?=!isset($r['activo'])||!empty($r['activo'])?'checked':''?>> Activo</label>
<?php }
?>

<?php if($registroEditar && $editarTipo!=='especial'): ?>
<div class="panel-form">
    <div class="toolbar"><div><h3>Editar adicional</h3><span class="mini"><?=msE($registroEditar['etiqueta'])?> · <?=msE($registroEditar['codigo'])?></span></div><a class="btn-sec" href="?tab=adicionales">Cerrar edición</a></div>
    <form method="post" class="grid"><?= automacCsrfInput() ?><?php msCamposAdicional($registroEditar,false);?><button>Guardar cambios</button></form>
</div>
<?php endif; ?>

<div class="toolbar"><div><h3 style="margin:0">Adicionales generales</h3><span class="mini">Los activos y visibles son los que pueden aparecer en la botonera de cabina.</span></div></div>
<details class="nuevo-box"><summary>+ Nuevo adicional</summary><div class="panel-form"><h3>Crear adicional general</h3><form method="post" class="grid"><?= automacCsrfInput() ?><?php msCamposAdicional();?><button>Agregar adicional</button></form></div></details>
<div class="scroll"><table class="lista-simple"><thead><tr><th>Orden</th><th>Nombre</th><th>Código</th><th>Regla</th><th>Cantidad</th><th>Visible</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($adicionalesArr as $r): ?>
<tr class="<?=$r['activo']?'':'off'?>"><td><?=(int)$r['orden']?></td><td><strong><?=msE($r['etiqueta'])?></strong><br><span class="mini"><?=msE($r['adicional'])?></span></td><td><span class="tag"><?=msE($r['codigo'])?></span></td><td><?=msE($r['tipo_calculo']==='POR_BOTONERA'?'Cantidad × botoneras':'Cantidad directa')?></td><td><?=msE($r['cantidad_predeterminada'])?><?=$r['cantidad_editable']?' · editable':''?></td><td><?=$r['visible_cotizador']?'Sí':'No'?></td><td><span class="estado-pill <?=$r['activo']?'estado-on':'estado-off'?>"><?=$r['activo']?'Activo':'Inactivo'?></span></td><td class="acciones"><a class="btn-sec" href="?tab=adicionales&editar_tipo=general&editar_id=<?=(int)$r['id']?>">Editar</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>

<div class="seccion">
<div class="toolbar"><div><h3 style="margin:0">Adicionales especiales</h3><span class="mini">Mensajes especiales y Botonera cableada. Ascensorista, Braille y APPIND siguen administrados por sus matrices específicas.</span></div></div>
<?php if($registroEditar && $editarTipo==='especial'): ?>
<div class="panel-form"><div class="toolbar"><div><h3>Editar adicional especial</h3><span class="mini"><?=msE($registroEditar['etiqueta'])?> · <?=msE($registroEditar['codigo'])?></span></div><a class="btn-sec" href="?tab=adicionales">Cerrar edición</a></div><form method="post" class="grid"><?= automacCsrfInput() ?><?php msCamposAdicional($registroEditar,true);?><button>Guardar cambios</button></form></div>
<?php endif; ?>
<details class="nuevo-box"><summary>+ Nuevo adicional especial</summary><div class="panel-form"><h3>Crear adicional especial</h3><form method="post" class="grid"><?= automacCsrfInput() ?><?php msCamposAdicional(array(),true);?><button>Agregar especial</button></form></div></details>
<div class="scroll"><table class="lista-simple"><thead><tr><th>Orden</th><th>Nombre</th><th>Código</th><th>Regla</th><th>Visible</th><th>Estado</th><th></th></tr></thead><tbody>
<?php foreach($especialesArr as $r): ?>
<tr class="<?=$r['activo']?'':'off'?>"><td><?=(int)$r['orden']?></td><td><strong><?=msE($r['etiqueta'])?></strong><br><span class="mini"><?=msE($r['adicional'])?></span></td><td><span class="tag"><?=msE($r['codigo'])?></span></td><td><?=msE($r['tipo_calculo']==='POR_BOTONERA'?'Cantidad × botoneras':'Cantidad directa')?></td><td><?=$r['visible_cotizador']?'Sí':'No'?></td><td><span class="estado-pill <?=$r['activo']?'estado-on':'estado-off'?>"><?=$r['activo']?'Activo':'Inactivo'?></span></td><td class="acciones"><a class="btn-sec" href="?tab=adicionales&editar_tipo=especial&editar_id=<?=(int)$r['id']?>">Editar</a></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</div>
<?php endif; ?>
</div>
<script id="v357-regla-botoneras">
function msSincronizarReglaBotoneras(sel){
  if(!sel) return;
  const form=sel.closest('form'); if(!form) return;
  const cant=form.querySelector('.js-cant-pred');
  const sync=form.querySelector('.js-sync-botoneras');
  const ayuda=form.querySelector('.js-cant-pred-ayuda');
  const por=String(sel.value||'')==='POR_BOTONERA';
  if(por){
    if(cant){cant.value='1';cant.readOnly=true;cant.title='Factor interno. La cantidad visible en el cotizador será igual a la cantidad de botoneras.';}
    if(sync){sync.checked=true;sync.disabled=true;}
    if(ayuda) ayuda.textContent='Automática: en el cotizador será igual a la cantidad de botoneras.';
  }else{
    if(cant){cant.readOnly=false;cant.title='';}
    if(sync) sync.disabled=false;
    if(ayuda) ayuda.textContent='';
  }
}
document.addEventListener('DOMContentLoaded',()=>document.querySelectorAll('.js-tipo-calculo-adicional').forEach(msSincronizarReglaBotoneras));
</script>
</body></html>
