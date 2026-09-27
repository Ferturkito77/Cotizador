<?php
/**
 * Helpers del cotizador extraídos de index.php en v430.
 * Sin lógica de arranque ni estado global: funciones reutilizables de presentación y catálogo.
 */

function nombrePropioCliente($valor)
{
    $texto = trim((string)$valor);
    if ($texto === '') return '';
    // Presentacion uniforme del cliente en el cotizador: nombre de fantasia en MAYUSCULAS.
    return function_exists('mb_strtoupper') ? mb_strtoupper($texto, 'UTF-8') : strtoupper($texto);
}

function escapar($valor)
{
    return htmlspecialchars($valor, ENT_QUOTES, 'UTF-8');
}

function cantidadVisualEntera($valor)
{
    if ($valor === null || $valor === '') return '0';
    if (!is_numeric($valor)) return trim((string)$valor);
    $numero = (float)$valor;
    if (abs($numero - round($numero)) < 0.0000001) return (string)(int)round($numero);
    return rtrim(rtrim(number_format($numero, 4, '.', ''), '0'), '.');
}

function renderizarItemsModuloGuardados(array $items): void
{
    if (count($items) === 0) $items = array(array('concepto'=>'','codigo'=>'','descripcion'=>'','cantidad'=>'1','precio'=>''));
    foreach ($items as $item) {
        echo '<div class="item-modular iep-item-operativo">';
        echo '<label><span>Concepto</span><input data-campo="concepto" placeholder="Ej.: IEP / suministro" value="'.escapar($item['concepto'] ?? '').'"></label>';
        echo '<label><span>Código</span><input data-campo="codigo" placeholder="Código" value="'.escapar($item['codigo'] ?? '').'"></label>';
        echo '<label class="iep-descripcion"><span>Descripción</span><input data-campo="descripcion" placeholder="Descripción técnica" value="'.escapar($item['descripcion'] ?? '').'"></label>';
        echo '<label><span>Cantidad</span><input data-campo="cantidad" type="number" min="0.0001" step="0.0001" value="'.escapar($item['cantidad'] ?? '1').'" placeholder="Cantidad"></label>';
        echo '<label><span>Precio unitario</span><input data-campo="precio" type="number" min="0" step="0.01" value="'.escapar($item['precio'] ?? '').'" placeholder="Precio unitario"></label>';
        echo '</div>';
    }
}


function factorDescuentoAccesoriosServidor($d1, $d2, $d3): float
{
    $d1 = max(0, min(100, (float)$d1));
    $d2 = max(0, min(100, (float)$d2));
    $d3 = max(0, min(100, (float)$d3));
    return (1 - $d1 / 100) * (1 - $d2 / 100) * (1 - $d3 / 100);
}

function renderizarAccesoriosGuardados(array $items, float $factor): void
{
    if (count($items) === 0) return;
    foreach ($items as $item) {
        $concepto = trim((string)($item['concepto'] ?? ''));
        $codigo = trim((string)($item['codigo'] ?? ''));
        $descripcion = trim((string)($item['descripcion'] ?? ''));
        $precioFinal = (float)($item['precio'] ?? 0);
        if ($concepto === '' && $codigo === '' && $descripcion === '' && $precioFinal <= 0) {
            continue;
        }
        $precioBaseGuardado = $item['precio_base'] ?? '';
        $precioBase = is_numeric($precioBaseGuardado) ? (float)$precioBaseGuardado : (($precioFinal > 0 && $factor > 0) ? $precioFinal / $factor : $precioFinal);
        echo '<div class="item-modular accesorio-item" data-precio-base="'.escapar(number_format($precioBase,4,'.','')).'" style="display:grid;gap:8px;margin:10px 0;padding:10px;border:1px solid #ddd;border-radius:6px;">';
        echo '<input data-campo="concepto" placeholder="Concepto" value="'.escapar($concepto).'">';
        echo '<input data-campo="codigo" placeholder="Código" value="'.escapar($codigo).'">';
        echo '<input data-campo="descripcion" placeholder="Descripción" value="'.escapar($descripcion).'">';
        echo '<input data-campo="cantidad" type="number" min="0.0001" step="0.0001" value="'.escapar($item['cantidad'] ?? '1').'" placeholder="Cantidad" oninput="actualizarResumenDocumento()">';
        echo '<input class="accesorio-precio-final" type="number" min="0" step="1" value="'.escapar((string)ceil($precioFinal)).'" placeholder="Precio final con descuento" oninput="actualizarPrecioFinalAccesorioManual(this)">';
        echo '<input class="accesorio-precio-base" type="hidden" value="'.escapar(number_format($precioBase,4,'.','')).'">';
        echo '<input type="hidden" data-campo="precio" value="'.escapar((string)ceil($precioFinal)).'">';
        echo '</div>';
    }
}

function esItemLimiteAccesorio(array $item): bool
{
    $concepto = strtoupper(trim((string)($item['concepto'] ?? '')));
    $codigo = strtoupper(trim((string)($item['codigo'] ?? '')));
    return $concepto === 'LÍMITES' || $concepto === 'LIMITES' || strpos($codigo, 'HLGLLA') === 0 || strpos($codigo, 'HXCK') === 0;
}

function esItemManualRepuesto(array $item): bool
{
    $concepto = strtoupper(trim((string)($item['concepto'] ?? '')));
    $codigo = trim((string)($item['codigo'] ?? ''));
    return $codigo === '' && ($concepto === '' || strpos($concepto, 'MANUAL') !== false || strpos($concepto, 'ADICIONAL') !== false);
}

function renderizarRepuestosGuardados(array $items): void
{
    foreach ($items as $item) {
        if (esItemManualRepuesto($item)) continue;
        $codigo=escapar($item['codigo'] ?? '');
        $descripcion=escapar($item['descripcion'] ?? '');
        $cantidad=(string)max(1, (int)round((float)($item['cantidad'] ?? 1)));
        $precio=escapar((string)ceil((float)($item['precio'] ?? 0)));
        $concepto=escapar($item['concepto'] ?? 'Repuesto');
        $total=(string)ceil(((float)($item['precio'] ?? 0))*((int)$cantidad));
        echo '<div class="item-modular repuesto-agregado repuesto-producto" data-precio-base="'.$precio.'" data-precio-15="'.$precio.'" data-precio-30="'.$precio.'">';
        echo '<div class="repuesto-info"><strong>'.$codigo.'</strong><div class="repuesto-desc">'.$descripcion.'</div><textarea class="repuesto-desc-editor" rows="4" style="display:none">'.$descripcion.'</textarea><button type="button" class="repuesto-editar-desc" onclick="editarDescripcionRepuesto(this)">Editar descripción</button><small>Ítem guardado en el documento</small></div>';
        echo '<div><label>Descuento</label><select class="repuesto-descuento" disabled><option>Precio guardado</option></select></div>';
        echo '<div><label>Cantidad</label><input data-campo="cantidad" type="number" min="1" step="1" inputmode="numeric" value="'.$cantidad.'" oninput="normalizarCantidadRepuesto(this)" onchange="normalizarCantidadRepuesto(this)"></div>';
        echo '<div class="ui-price-detail"><label>Precio unitario</label><input class="repuesto-precio-visible" value="'.$precio.'" readonly></div>';
        echo '<div class="ui-price-detail"><label>Total</label><input class="repuesto-total-visible" value="'.$total.'" readonly></div>';
        echo '<button type="button" class="repuesto-quitar" onclick="quitarRepuesto(this)">Quitar</button>';
        echo '<input type="hidden" data-campo="concepto" value="'.$concepto.'"><input type="hidden" data-campo="codigo" value="'.$codigo.'"><input type="hidden" data-campo="descripcion" value="'.$descripcion.'"><input type="hidden" data-campo="precio" value="'.$precio.'">';
        echo '</div>';
    }
}

function renderizarRepuestosManualesGuardados(array $items): void
{
    $manuales = array();
    foreach ($items as $item) if (esItemManualRepuesto($item) && (trim((string)($item['descripcion'] ?? '')) !== '' || (float)($item['precio'] ?? 0) > 0)) $manuales[] = $item;
    for ($i=0; $i<3; $i++) {
        $item=$manuales[$i] ?? array();
        $concepto=escapar($item['concepto'] ?? '');
        $descripcion=escapar($item['descripcion'] ?? '');
        $cantidad=escapar($item['cantidad'] ?? '1');
        $precio=escapar((string)ceil((float)($item['precio'] ?? 0)));
        echo '<div class="item-modular repuesto-manual">';
        echo '<input data-campo="concepto" placeholder="Concepto adicional '.($i+1).'" value="'.$concepto.'" oninput="actualizarEstadoRepuestoManual(this.closest(\'.repuesto-manual\'));actualizarResumenDocumento()">';
        echo '<input data-campo="codigo" type="hidden" value="">';
        echo '<input data-campo="descripcion" placeholder="Descripción del ítem adicional" value="'.$descripcion.'" oninput="actualizarEstadoRepuestoManual(this.closest(\'.repuesto-manual\'));actualizarResumenDocumento()">';
        echo '<input data-campo="cantidad" type="number" min="1" step="1" value="'.$cantidad.'" placeholder="Cantidad" oninput="actualizarTotalManualRepuesto(this)" onchange="actualizarTotalManualRepuesto(this)">';
        echo '<input data-campo="precio" type="number" min="0" step="1" value="'.$precio.'" placeholder="Precio unitario" oninput="actualizarTotalManualRepuesto(this)" onchange="actualizarTotalManualRepuesto(this)">';
        echo '<input class="repuesto-total-manual" value="'.ceil(((float)($item['precio'] ?? 0))*((float)($item['cantidad'] ?? 1))).'" readonly aria-label="Total">';
        echo '</div>';
    }
}

function cargarTablaAccesorioEspecial($conexion,$tabla,$orden=''){
    static $cache=array();
    $salida=array();
    if(!esquemaTablaExiste($conexion,(string)$tabla)) return $salida;
    $clave=(string)$tabla.'|'.(string)$orden;
    if(array_key_exists($clave,$cache)) return $cache[$clave];
    $sql='SELECT * FROM `'.$tabla.'`'.($orden!==''?' ORDER BY '.$orden:'');
    $r=$conexion->query($sql); if($r) while($x=$r->fetch_assoc()) $salida[]=$x;
    return $cache[$clave]=$salida;
}

function valorSeleccionado($datos, $campo, $valor)
{
    return isset($datos[$campo]) && (string)$datos[$campo] === (string)$valor ? ' selected' : '';
}

function valorMarcado($datos, $campo, $valor = 'SI')
{
    return isset($datos[$campo]) && (string)$datos[$campo] === $valor ? ' checked' : '';
}

