<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'sistema_comercial.php';
require_once 'documentos_pdf.php';
require_once 'documentos_storage.php';
require_once 'material_hueco_of.php';
require_once 'orden_fabricacion_tipos.php';
require_once 'orden_fabricacion_layout.php';

asegurarSistemaUsuarios($conexion);
asegurarSistemaComercial($conexion);
exigirRoles(array('ADMINISTRADOR', 'TECNICO', 'COMERCIAL'));

$pedidoId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$pedidoId) { http_response_code(400); die('Orden de fabricación inválida.'); }

try {
    $stmt = $conexion->prepare(
        "SELECT p.pedido_id, p.pedido_numero, p.pedido_tipo, p.revision, p.estado, p.fecha_creacion, p.datos_formulario,
                COALESCE(p.referencia, c.referencia) AS referencia,
                c.cotizacion_numero,
                cl.clientes_codigo, cl.clientes_nomfantasia, cl.clientes_razonsocial, cl.clientes_numero_bejerman, cl.clientes_telefono, cl.clientes_emails,
                COALESCE(u.usuario_nombre, p.usuario, '') AS responsable_comercial
           FROM pedidos p
           LEFT JOIN cotizaciones c ON c.cotizacion_id = p.cotizacion_id
           JOIN clientes cl ON cl.clientes_id = p.cliente_id
           LEFT JOIN usuarios u ON u.usuario_id = p.usuario_id
          WHERE p.pedido_id = ?"
    );
    if (!$stmt) throw new RuntimeException('No se pudo preparar la consulta de la orden.');
    $stmt->bind_param('i', $pedidoId); $stmt->execute();
    $pedido = $stmt->get_result()->fetch_assoc(); $stmt->close();
    if (!$pedido) throw new RuntimeException('El pedido indicado no existe.');

    $stmt = $conexion->prepare(
        "SELECT modulo, concepto, codigo, descripcion, cantidad, formula_aplicada
           FROM pedidos_detalle WHERE pedido_id = ?
          ORDER BY orden_visual, pedido_detalle_id"
    );
    if (!$stmt) throw new RuntimeException('No se pudo preparar el detalle de la orden.');
    $stmt->bind_param('i', $pedidoId); $stmt->execute();
    $resultado = $stmt->get_result(); $todos = array();
    while ($fila = $resultado->fetch_assoc()) $todos[] = $fila;
    $stmt->close();
    if (!$todos) throw new RuntimeException('El pedido no tiene renglones para generar la orden.');

    $tipo = oftTipoSolicitado($pedido, $todos, (string)($_GET['tipo'] ?? ''));
    if ($tipo === '') throw new RuntimeException('El pedido no tiene una orden de fabricación disponible.');
    $detalles = oftFiltrarDetalles($todos, $tipo);
    if (!$detalles && $tipo !== 'CONTROL') throw new RuntimeException('La orden seleccionada no tiene renglones aplicables.');

    $datosFormulario = json_decode((string)($pedido['datos_formulario'] ?? ''), true);
    if (!is_array($datosFormulario)) $datosFormulario = array();

    $pdf = new PdfAutomac();
    if ($tipo === 'SUMINISTROS') {
        ofDibujarOrdenPreparacionSuministros($pdf, $conexion, $pedido, $detalles);
    } elseif ($tipo === 'SENALIZACION_ACCESORIOS') {
        ofDibujarOrdenSenalizacionAccesorios($pdf, $conexion, $pedido, $detalles, $datosFormulario);
    } else {
        $materialHueco = calcularMaterialHuecoOrden($conexion, $datosFormulario);
        $materialHueco = ofAgregarMaterialesRescateMrl($conexion, $materialHueco, $detalles, $datosFormulario);
        ofDibujarPagina1($pdf, $conexion, $pedido, $detalles, $datosFormulario, $materialHueco);
        ofDibujarPagina2($pdf, $conexion, $pedido, $detalles, $datosFormulario);
        ofDibujarPagina3($pdf, $conexion, $pedido, $detalles, $datosFormulario);
    }

    $revision = (int)($pedido['revision'] ?? 0);
    if ($tipo === 'SUMINISTROS') $detalleArchivo = 'SUMINISTROS';
    elseif ($tipo === 'SENALIZACION_ACCESORIOS') $detalleArchivo = 'SENALIZACION ACCESORIOS Y REPUESTOS';
    else $detalleArchivo = 'CONTROL';
    $numeroPedido = pdfNumeroArchivo((string)$pedido['pedido_numero'], 'PEDIDO');
    $numeroOf = preg_replace('/^PED\./', 'OF.', $numeroPedido);
    if ($numeroOf === $numeroPedido && preg_match('/^P\.(\d+)$/', $numeroPedido, $mm)) $numeroOf = 'OF.' . $mm[1];
    $nombre = pdfNombreDocumento('OF',$numeroOf,$pedido,(string)($pedido['referencia']??''),$revision,$detalleArchivo);
    $rutaRelativa = 'pdf/ordenes_fabricacion/' . $nombre;
    $directorio = __DIR__ . '/pdf/ordenes_fabricacion';
    if (!is_dir($directorio) && !mkdir($directorio, 0775, true) && !is_dir($directorio)) throw new RuntimeException('No se pudo crear el directorio de PDFs.');
    $rutaAbs=__DIR__ . '/' . $rutaRelativa;
    $pdf->output($rutaAbs);
    documentosArchivarSinInterrumpir($conexion,$rutaAbs,'Ordenes_Fabricacion',numeroDocumentoVisible((string)$pedido['pedido_numero']));
    header('Location: ' . $rutaRelativa); exit;
} catch (Throwable $e) {
    http_response_code(500);
    die('No se pudo generar el PDF de la orden de fabricación: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}
