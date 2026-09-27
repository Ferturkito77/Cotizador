<?php
/** Matriz editable de material de hueco para indicadores autonomos/electromecanicos. */
function mhiaAsegurarMatriz(mysqli $conexion): void
{
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'material_hueco_indicador_autonomo', array('material_id','material_clave','material_codigo','material_descripcion','regla_cantidad','cantidad_base','material_orden','material_activo'), 'migracion_mejoras_integrales_v427.sql');

    $defaults = array(
        array('TRANSFORMADOR','','Transformador/es 220/12V','POR_INDICADOR',1,10),
        array('IMAN_CORTO','APPIND','Imanes Cortos 5cm','POR_PARADA_APPIND',1,20),
        array('IMAN_LARGO','','Imanes Largos 25cm','POR_INDICADOR',1,30),
        array('CABEZAL_MAGNETICO','A2142C','A2142C Cabezal Magnético (2 cabezales magnéticos + 1 soporte)','POR_INDICADOR',1,40),
        array('INSTRUCTIVO','','Instructivo','POR_INDICADOR',1,50),
    );
    $stmt=$conexion->prepare("INSERT IGNORE INTO material_hueco_indicador_autonomo
        (material_clave,material_codigo,material_descripcion,regla_cantidad,cantidad_base,material_orden,material_activo)
        VALUES (?,?,?,?,?,?,'SI')");
    if(!$stmt) throw new RuntimeException('No se pudo preparar la matriz de indicador autonomo: '.$conexion->error);
    foreach($defaults as $r){$clave=$r[0];$codigo=$r[1];$desc=$r[2];$regla=$r[3];$cant=(float)$r[4];$orden=(int)$r[5];$stmt->bind_param('ssssdi',$clave,$codigo,$desc,$regla,$cant,$orden);$stmt->execute();}
    $stmt->close();

    // v374: limpiar solamente las descripciones por defecto creadas por v373.
    // Si el usuario ya personalizo una descripcion, no se modifica.
    $migraciones = array(
        array('TRANSFORMADOR','Transformador 220/12V','Transformador/es 220/12V'),
        array('IMAN_CORTO','Imanes cortos 5 cm','Imanes Cortos 5cm'),
        array('IMAN_LARGO','Iman largo 25 cm','Imanes Largos 25cm'),
        array('CABEZAL_MAGNETICO','Cabezal Magnetico - conjunto de 2 cabezales magneticos + 1 soporte','A2142C Cabezal Magnético (2 cabezales magnéticos + 1 soporte)'),
        array('INSTRUCTIVO','Instructivo de instalacion del indicador autonomo / material de hueco','Instructivo'),
    );
    $up=$conexion->prepare("UPDATE material_hueco_indicador_autonomo SET material_descripcion=? WHERE material_clave=? AND material_descripcion=?");
    if($up){
        foreach($migraciones as $m){$clave=$m[0];$anterior=$m[1];$nuevo=$m[2];$up->bind_param('sss',$nuevo,$clave,$anterior);$up->execute();}
        $up->close();
    }
}

function mhiaMaterialesActivos(mysqli $conexion): array
{
    mhiaAsegurarMatriz($conexion);
    $rs=$conexion->query("SELECT material_id,material_clave,material_codigo,material_descripcion,regla_cantidad,cantidad_base,material_orden,material_activo FROM material_hueco_indicador_autonomo WHERE material_activo='SI' ORDER BY material_orden,material_id");
    $out=array(); if($rs){while($r=$rs->fetch_assoc())$out[]=$r;} return $out;
}
