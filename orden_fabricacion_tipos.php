<?php
/* v37 - OF Senalizacion/Accesorios detecta lineas modulares aun si quedaron persistidas como CONTROL. */
/**
 * Clasificacion derivada de ordenes de fabricacion por pedido.
 * No crea una nueva numeracion: un mismo pedido puede exponer distintas OF
 * (CONTROL y SENALIZACION_ACCESORIOS) conservando el mismo pedido_id/numero.
 * v236: REPUESTOS se integra a la OF de Señalización/Accesorios.
 */

function oftNormalizar(string $texto): string
{
    $texto = strtoupper(trim($texto));
    $texto = strtr($texto, array('Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'));
    return preg_replace('/\s+/', ' ', $texto) ?: $texto;
}

function oftModuloDetalle(array $detalle): string
{
    $modulo = oftNormalizar((string)($detalle['modulo'] ?? ''));
    $concepto = oftNormalizar((string)($detalle['concepto'] ?? ''));
    $codigo = oftNormalizar((string)($detalle['codigo'] ?? ''));
    $descripcion = oftNormalizar((string)($detalle['descripcion'] ?? ''));
    $texto = trim($concepto . ' ' . $codigo . ' ' . $descripcion);

    /*
     * Los modulos distintos de CONTROL son confiables y se respetan.
     * Para CONTROL hacemos una segunda verificacion por contenido. Esto corrige
     * pedidos/cotizaciones en los que una linea de Senalizacion o Accesorios fue
     * persistida como CONTROL por una version anterior del flujo modular.
     * La deteccion es deliberadamente especifica para no mover componentes
     * tecnicos reales del control (por ejemplo, "Fuente indicadores MRL").
     */
    if (in_array($modulo, array('SENALIZACION','IEP','ACCESORIOS','REPUESTOS'), true)) return $modulo;

    $patronesSenalConcepto = array(
        'BASE BOTONERA DE CABINA',
        'ADICIONAL POR PARADA EN BOTONERA DE CABINA',
        'INDICADOR DE POSICION',
        'ADICIONAL POR PARADA EN INDICADOR DE CABINA',
        'LLAVE DE SERVICIO INDEPENDIENTE',
        'LLAVE SERVICIO ASCENSORISTA',
        'ADICIONAL LLAVE ASCENSORISTA',
        'BRAILLE LATERAL EN LLAVE ASCENSORISTA',
        'COMUNICACION SERIE',
        'ADICIONAL POR PANO',
        'CALADO P/PESADOR DE CARGA',
        'ADICIONAL POR INTERCOMUNICADOR',
        'ADICIONAL TELEFONO MANOS LIBRES',
        'FUENTE PARA INTERCOMUNICADOR',
        'LUZ DE EMERGENCIA',
        'ACCESIBILIDAD POR VOZ',
        'BOTONERA CABLEADA',
        'MENSAJES ESPECIALES'
    );
    foreach ($patronesSenalConcepto as $patron) {
        if (strpos($concepto, $patron) !== false) return 'SENALIZACION';
    }

    $patronesAccesorios = array(
        'BOTONERA DE FOSO', 'A3XCFOSO',
        'SINTETIZADOR',
        'BOTONERA DE INSPECCION', 'A3XINSIMP',
        'BARRERA',
        'LIMITE C/SOPORTE', 'LIMITE CON SOPORTE',
        'SISTEMA SUPERVISOR',
        'ALARMA DE EMERGENCIA', 'A0710CS',
        'PESADOR DE CARGA',
        'CONTROL DE ACCESOS',
        'CHIP DE CONTACTO', 'A3700LU',
        'TARJETA DE PROXIMIDAD', 'A3701TUHW',
        'GONG TECHO DE CABINA', 'A7250GTC'
    );
    foreach ($patronesAccesorios as $patron) {
        if (strpos($texto, $patron) !== false) return 'ACCESORIOS';
    }

    if ($modulo === 'CONTROL') return 'CONTROL';

    // Compatibilidad con pedidos historicos anteriores a la clasificacion por modulo.
    if (strpos($texto, 'BOTONERA') !== false || strpos($texto, 'INDICADOR') !== false || strpos($texto, 'PULSADOR') !== false || strpos($texto, 'SENALIZ') !== false) return 'SENALIZACION';
    if (strpos($texto, 'IEP') !== false || strpos($texto, 'PREMONT') !== false) return 'IEP';
    foreach (array('ACCESOR','LIMITE','BARRERA','PESADOR','ALARMA','INTERCOM','GONG','CHIP','TARJETA DE PROXIMIDAD','SISTEMA SUPERVISOR') as $patron) {
        if (strpos($texto, $patron) !== false) return 'ACCESORIOS';
    }
    if (strpos($texto, 'REPUEST') !== false) return 'REPUESTOS';
    return 'CONTROL';
}

function oftEsLimite(array $detalle): bool
{
    $texto = oftNormalizar(
        (string)($detalle['concepto'] ?? '') . ' ' .
        (string)($detalle['codigo'] ?? '') . ' ' .
        (string)($detalle['descripcion'] ?? '')
    );
    return strpos($texto, 'LIMITE') !== false;
}

function oftEsPedidoSuministros(array $pedido): bool
{
    return oftNormalizar((string)($pedido['pedido_tipo'] ?? '')) === 'SUMINISTROS'
        || preg_match('/^P[\._-]?\d+/i', trim((string)($pedido['pedido_numero'] ?? ''))) === 1;
}

function oftFiltrarDetalles(array $detalles, string $tipo): array
{
    $tipo = oftNormalizar($tipo);
    $salida = array();
    foreach ($detalles as $detalle) {
        $cantidad = (float)($detalle['cantidad'] ?? 0);
        if ($cantidad <= 0) continue;
        $modulo = oftModuloDetalle($detalle);

        if ($tipo === 'CONTROL') {
            if ($modulo === 'CONTROL') $salida[] = $detalle;
            // Los limites de Accesorios se integran operativamente a Material de Hueco
            // y no se duplican como renglon comercial de la OF de Control.
        } elseif ($tipo === 'SENALIZACION_ACCESORIOS') {
            if ($modulo === 'SENALIZACION') {
                $salida[] = $detalle;
            } elseif ($modulo === 'ACCESORIOS' && !oftEsLimite($detalle)) {
                $salida[] = $detalle;
            } elseif ($modulo === 'REPUESTOS') {
                $salida[] = $detalle;
            }
        } elseif ($tipo === 'SUMINISTROS') {
            $salida[] = $detalle;
        }
    }
    return $salida;
}

function oftTiposDisponibles(array $pedido, array $detalles): array
{
    /* v373: un pedido P. deja de ser automaticamente SUMINISTROS cuando contiene
       una botonera/senalizacion. En ese caso Produccion necesita OF DE SENALIZACION. */
    if (oftEsPedidoSuministros($pedido)) {
        $tieneSenalizacion = false;
        foreach ($detalles as $d) {
            if ((float)($d['cantidad'] ?? 0) <= 0) continue;
            if (oftModuloDetalle($d) === 'SENALIZACION') { $tieneSenalizacion = true; break; }
        }
        if ($tieneSenalizacion) return array('SENALIZACION_ACCESORIOS');
        return array('SUMINISTROS');
    }

    $tipos = array();
    if (oftFiltrarDetalles($detalles, 'CONTROL')) $tipos[] = 'CONTROL';
    if (oftFiltrarDetalles($detalles, 'SENALIZACION_ACCESORIOS')) $tipos[] = 'SENALIZACION_ACCESORIOS';

    // Compatibilidad: pedidos OBRA historicos sin modulo legible conservan OF Control.
    if (!$tipos && $detalles) $tipos[] = 'CONTROL';
    return $tipos;
}

function oftTipoSolicitado(array $pedido, array $detalles, ?string $solicitado): string
{
    $disponibles = oftTiposDisponibles($pedido, $detalles);
    if (!$disponibles) return '';
    $normal = oftNormalizar((string)$solicitado);
    $mapa = array(
        'CONTROL'=>'CONTROL',
        'SENALIZACION'=>'SENALIZACION_ACCESORIOS',
        'SENALIZACION_ACCESORIOS'=>'SENALIZACION_ACCESORIOS',
        'ACCESORIOS'=>'SENALIZACION_ACCESORIOS',
        'REPUESTOS'=>'SENALIZACION_ACCESORIOS',
        'SUMINISTROS'=>'SUMINISTROS'
    );
    $tipo = $mapa[$normal] ?? $disponibles[0];
    return in_array($tipo, $disponibles, true) ? $tipo : $disponibles[0];
}

function oftEtiquetaTipo(string $tipo): string
{
    $tipo = oftNormalizar($tipo);
    if ($tipo === 'SENALIZACION_ACCESORIOS') return 'Señalización, Accesorios y Repuestos';
    if ($tipo === 'SUMINISTROS') return 'Preparación / despacho';
    return 'Fabricación de control';
}

function oftSufijoUrl(string $tipo): string
{
    $tipo = oftNormalizar($tipo);
    if ($tipo === 'SENALIZACION_ACCESORIOS') return 'senalizacion';
    if ($tipo === 'SUMINISTROS') return 'suministros';
    return 'control';
}
