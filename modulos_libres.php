<?php
function obtenerLineasModulosLibres(array $post): array
{
    $modulos = $post['modulo_item_modulo'] ?? array();
    $conceptos = $post['modulo_item_concepto'] ?? array();
    $codigos = $post['modulo_item_codigo'] ?? array();
    $descripciones = $post['modulo_item_descripcion'] ?? array();
    $cantidades = $post['modulo_item_cantidad'] ?? array();
    $precios = $post['modulo_item_precio'] ?? array();
    $bonificados = $post['modulo_item_bonificado'] ?? array();
    $permitidos = array('IEP','ACCESORIOS','REPUESTOS');
    $lineas = array();
    $n = max(count((array)$modulos), count((array)$conceptos), count((array)$codigos), count((array)$descripciones), count((array)$cantidades), count((array)$precios), count((array)$bonificados));
    for ($i=0; $i<$n; $i++) {
        $modulo = strtoupper(trim((string)($modulos[$i] ?? '')));
        $concepto = trim((string)($conceptos[$i] ?? ''));
        $codigo = trim((string)($codigos[$i] ?? ''));
        $descripcion = trim((string)($descripciones[$i] ?? ''));
        $cantidad = (float)str_replace(',', '.', (string)($cantidades[$i] ?? '0'));
        if ($modulo === 'REPUESTOS') {
            $cantidad = (float)max(1, (int)round($cantidad));
        }
        $precioReferencia = ceil((float)str_replace(',', '.', (string)($precios[$i] ?? '0')));
        $bonificado = ($modulo === 'ACCESORIOS') && !empty($bonificados[$i]);
        $precio = $bonificado ? 0.0 : $precioReferencia;
        if ($concepto==='' && $codigo==='' && $descripcion==='' && $cantidad==0.0 && $precio==0.0) continue;
        if (!in_array($modulo,$permitidos,true)) throw new Exception('Módulo inválido en un ítem adicional.');
        if ($concepto==='') $concepto = $modulo;
        if ($cantidad<=0) throw new Exception('La cantidad de los ítems de '.$modulo.' debe ser mayor que cero.');
        if ($precio<0) throw new Exception('El precio de los ítems de '.$modulo.' no puede ser negativo.');
        if ($bonificado && stripos($descripcion, 'BONIFICADO') === false) $descripcion = trim($descripcion . ' · BONIFICADO');
        $formula = $bonificado ? 'BONIFICADO' : ('Ítem cargado en ' . $modulo);
        $lineas[] = array('modulo'=>$modulo,'concepto'=>$concepto,'codigo'=>$codigo,'descripcion'=>$descripcion,'cantidad'=>$cantidad,'unitario'=>$precio,'formula'=>$formula,'total'=>ceil($cantidad*$precio));
    }
    return $lineas;
}
?>
