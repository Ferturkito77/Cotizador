<?php
/*
 * COTIZADOR AUTOMAC v164 - Motor de costos de Repuestos.
 * Fuentes permitidas en productos_repuestos.costo_tipo:
 *   BEJERMAN -> toma el costo del mismo codigo en la base Bejerman elegida.
 *   FORMULA  -> expresion administrativa segura usando BEJ("COD"), REP("COD"), MO, USD y UTIL.
 *   FIJO     -> usa costo_fijo.
 */

function repCostoTipoNormalizar($tipo): string
{
    $tipo = strtoupper(trim((string)$tipo));
    return in_array($tipo, array('BEJERMAN','FORMULA','FIJO'), true) ? $tipo : 'BEJERMAN';
}


function repCostoColumnasDisponibles(mysqli $conexion): array
{
    $out=array();
    $rs=$conexion->query("SHOW COLUMNS FROM productos_repuestos");
    if($rs) while($x=$rs->fetch_assoc()) $out[strtolower((string)$x['Field'])]=true;
    return $out;
}

function repCostoEjecutarMigracionV163(mysqli $conexion): array
{
    $archivo=__DIR__.DIRECTORY_SEPARATOR.'COTIZADOR_AUTOMAC_v163_repuestos_formulas.sql';
    if(!is_file($archivo)) return array('ok'=>false,'ejecutada'=>false,'mensaje'=>'No se encontró el SQL de catálogo v163.');
    $sql=file_get_contents($archivo);
    if($sql===false || trim($sql)==='') return array('ok'=>false,'ejecutada'=>false,'mensaje'=>'El SQL de catálogo v163 está vacío.');
    if(!$conexion->multi_query($sql)) return array('ok'=>false,'ejecutada'=>false,'mensaje'=>$conexion->error);
    do {
        if($res=$conexion->store_result()) $res->free();
        if(!$conexion->more_results()) break;
    } while($conexion->next_result());
    if($conexion->errno) return array('ok'=>false,'ejecutada'=>false,'mensaje'=>$conexion->error);
    return array('ok'=>true,'ejecutada'=>true,'mensaje'=>'Catálogo v163 instalado.');
}

function repCostoAsegurarCatalogoV163(mysqli $conexion, bool $permitirMigrar=true): array
{
    $cols=repCostoColumnasDisponibles($conexion);
    $faltanCols=!isset($cols['costo_tipo']) || !isset($cols['formula_costo']) || !isset($cols['costo_fijo']);
    $total=0; $formulas=0; $pbg2=0;
    if(!$faltanCols){
        if($r=$conexion->query("SELECT COUNT(*) total,SUM(COALESCE(costo_tipo,'')='FORMULA') formulas,SUM(UPPER(codigo)='PGB2') pgb2 FROM productos_repuestos")){
            $x=$r->fetch_assoc(); $total=(int)($x['total']??0); $formulas=(int)($x['formulas']??0); $pbg2=(int)($x['pgb2']??0);
        }
    }
    $necesita=$faltanCols || $total<862 || $formulas<87 || $pbg2<1;
    $migracion=array('ok'=>true,'ejecutada'=>false,'mensaje'=>'');
    if($necesita && $permitirMigrar){
        $migracion=repCostoEjecutarMigracionV163($conexion);
        if($migracion['ok']){
            $cols=repCostoColumnasDisponibles($conexion);
            $r=$conexion->query("SELECT COUNT(*) total,SUM(COALESCE(costo_tipo,'')='FORMULA') formulas,SUM(UPPER(codigo)='PGB2') pgb2 FROM productos_repuestos");
            if($r){$x=$r->fetch_assoc();$total=(int)($x['total']??0);$formulas=(int)($x['formulas']??0);$pbg2=(int)($x['pgb2']??0);}
            repCostoRecalcularTodos($conexion,0);
        }
    }
    return array(
        'ok'=>!$necesita || $migracion['ok'],
        'necesita_migracion'=>$necesita && !$migracion['ejecutada'],
        'migracion_ejecutada'=>$migracion['ejecutada'],
        'mensaje'=>$migracion['mensaje'],
        'total'=>$total,'formulas'=>$formulas,'pbg2'=>$pbg2,
        'columnas_ok'=>isset($cols['costo_tipo'])&&isset($cols['formula_costo'])&&isset($cols['costo_fijo'])
    );
}

function repCostoCargarContexto(mysqli $conexion, int $bejermanListaId = 0): array
{
    if ($bejermanListaId <= 0) {
        $rs = $conexion->query("SELECT bejerman_lista_id,valor_mano_obra,valor_dolar FROM bejerman_listas WHERE estado='VIGENTE' ORDER BY bejerman_lista_id DESC LIMIT 1");
    } else {
        $st = $conexion->prepare("SELECT bejerman_lista_id,valor_mano_obra,valor_dolar FROM bejerman_listas WHERE bejerman_lista_id=? LIMIT 1");
        $st->bind_param('i', $bejermanListaId); $st->execute(); $rs = $st->get_result();
    }
    $lista = $rs ? $rs->fetch_assoc() : null;
    if (isset($st) && $st instanceof mysqli_stmt) $st->close();
    $id = (int)($lista['bejerman_lista_id'] ?? 0);
    $ctx = array(
        'lista_id'=>$id,
        'mano_obra'=>(float)($lista['valor_mano_obra'] ?? 0),
        'dolar'=>(float)($lista['valor_dolar'] ?? 0),
        'bejerman'=>array(),
        'repuestos'=>array(),
        'cache'=>array(),
        'errores'=>array(),
    );
    if ($id > 0) {
        $stp = $conexion->prepare("SELECT codigo,costo FROM bejerman_productos WHERE bejerman_lista_id=? AND activo=1");
        $stp->bind_param('i', $id); $stp->execute(); $rp = $stp->get_result();
        while ($x = $rp->fetch_assoc()) {
            $ctx['bejerman'][strtoupper(trim((string)$x['codigo']))] = (float)$x['costo'];
        }
        $stp->close();
    }
    $rr = $conexion->query("SELECT producto_repuesto_id,codigo,costo_tipo,formula_costo,costo_fijo,utilidad,habilitado FROM productos_repuestos");
    if ($rr) while ($x=$rr->fetch_assoc()) {
        $ctx['repuestos'][strtoupper(trim((string)$x['codigo']))] = $x;
    }
    return $ctx;
}

function repCostoEvaluarAritmetica(string $expr): float
{
    $expr = trim($expr);
    if ($expr === '' || !preg_match('/^[0-9eE+\-*\/().\s]+$/', $expr)) {
        throw new RuntimeException('La formula contiene elementos no permitidos.');
    }
    set_error_handler(function($severity,$message){ throw new RuntimeException($message); });
    try {
        /** @noinspection PhpEvalInspection - la expresion ya fue reducida estrictamente a numeros y operadores. */
        $valor = eval('return (float)(' . $expr . ');');
    } finally {
        restore_error_handler();
    }
    if (!is_finite((float)$valor)) throw new RuntimeException('La formula no produjo un valor finito.');
    return (float)$valor;
}

function repCostoDeCodigo(string $codigo, array &$ctx, array $pila = array()): float
{
    $clave = strtoupper(trim($codigo));
    if ($clave === '') return 0.0;
    if (array_key_exists($clave, $ctx['cache'])) return (float)$ctx['cache'][$clave];
    if (in_array($clave, $pila, true)) {
        $ctx['errores'][$clave] = 'Referencia circular en formula de costo.';
        return 0.0;
    }
    $fila = $ctx['repuestos'][$clave] ?? null;
    if (!$fila || !(int)$fila['habilitado']) return 0.0;
    $pila[] = $clave;
    $tipo = repCostoTipoNormalizar($fila['costo_tipo'] ?? 'BEJERMAN');
    try {
        if ($tipo === 'FIJO') {
            $costo = max(0.0, (float)($fila['costo_fijo'] ?? 0));
        } elseif ($tipo === 'FORMULA') {
            $formula = trim((string)($fila['formula_costo'] ?? ''));
            if ($formula === '') throw new RuntimeException('Formula vacia.');
            $expr = $formula;
            $expr = preg_replace_callback('/BEJ\s*\(\s*["\']([^"\']+)["\']\s*\)/i', function($m) use (&$ctx) {
                $k = strtoupper(trim($m[1]));
                return '(' . sprintf('%.10F', (float)($ctx['bejerman'][$k] ?? 0)) . ')';
            }, $expr);
            $expr = preg_replace_callback('/REP\s*\(\s*["\']([^"\']+)["\']\s*\)/i', function($m) use (&$ctx, $pila) {
                return '(' . sprintf('%.10F', repCostoDeCodigo($m[1], $ctx, $pila)) . ')';
            }, $expr);
            $expr = preg_replace('/\bMO\b/i', '(' . sprintf('%.10F', (float)$ctx['mano_obra']) . ')', $expr);
            $expr = preg_replace('/\bUSD\b/i', '(' . sprintf('%.10F', (float)$ctx['dolar']) . ')', $expr);
            $expr = preg_replace('/\bUTIL\b/i', '(' . sprintf('%.10F', (float)($fila['utilidad'] ?? 1)) . ')', $expr);
            $costo = max(0.0, repCostoEvaluarAritmetica($expr));
        } else {
            $costo = max(0.0, (float)($ctx['bejerman'][$clave] ?? 0));
        }
    } catch (Throwable $e) {
        $ctx['errores'][$clave] = $e->getMessage();
        $costo = 0.0;
    }
    $ctx['cache'][$clave] = $costo;
    return $costo;
}

function repCostoRecalcularTodos(mysqli $conexion, int $bejermanListaId = 0): array
{
    $ctx = repCostoCargarContexto($conexion, $bejermanListaId);
    $actualizados = 0; $sinCosto = 0;
    $st = $conexion->prepare("UPDATE productos_repuestos SET costo_referencia=? WHERE producto_repuesto_id=?");
    foreach ($ctx['repuestos'] as $fila) {
        $id=(int)$fila['producto_repuesto_id'];
        if (!(int)$fila['habilitado']) { $costo=0.0; }
        else { $costo=repCostoDeCodigo((string)$fila['codigo'], $ctx); }
        if ($costo <= 0) $sinCosto++;
        $st->bind_param('di',$costo,$id); $st->execute(); $actualizados++;
    }
    $st->close();
    return array('actualizados'=>$actualizados,'sin_costo'=>$sinCosto,'errores'=>$ctx['errores'],'lista_id'=>$ctx['lista_id']);
}

function repCostoCalcularUno(mysqli $conexion, string $codigo, int $bejermanListaId = 0): array
{
    $ctx = repCostoCargarContexto($conexion, $bejermanListaId);
    $clave = strtoupper(trim($codigo));
    $costo = repCostoDeCodigo($clave, $ctx);
    return array('costo'=>$costo,'error'=>$ctx['errores'][$clave] ?? '','lista_id'=>$ctx['lista_id']);
}
