<?php
/**
 * Archivo externo de documentos del Cotizador Automac.
 *
 * Los PDF siguen guardandose dentro de /pdf para que la aplicacion pueda
 * servirlos desde el navegador. Adicionalmente se copia cada documento
 * emitido a una raiz configurable, pensada como archivo oficial/backup.
 */

function documentosRutaPredeterminada(): string
{
    if (defined('PHP_OS_FAMILY') && PHP_OS_FAMILY === 'Windows') {
        return 'C:\\Cotizador\\Documentos';
    }
    return __DIR__ . DIRECTORY_SEPARATOR . 'archivo_documentos';
}

function documentosTablaConfiguracionExiste(mysqli $conexion): bool
{
    $r = $conexion->query("SHOW TABLES LIKE 'configuracion_sistema'");
    return $r && $r->num_rows > 0;
}

function documentosObtenerConfiguracion(mysqli $conexion, string $clave, string $predeterminado=''): string
{
    if (!documentosTablaConfiguracionExiste($conexion)) return $predeterminado;
    $st = $conexion->prepare('SELECT config_valor FROM configuracion_sistema WHERE config_clave=? LIMIT 1');
    if (!$st) return $predeterminado;
    $st->bind_param('s', $clave);
    $st->execute();
    $fila = $st->get_result()->fetch_assoc();
    $st->close();
    $valor = trim((string)($fila['config_valor'] ?? ''));
    return $valor !== '' ? $valor : $predeterminado;
}

function documentosGuardarConfiguracion(mysqli $conexion, string $clave, string $valor, string $descripcion=''): void
{
    if (!documentosTablaConfiguracionExiste($conexion)) {
        throw new RuntimeException('Falta la tabla configuracion_sistema. Ejecute migracion_v83_archivo_documentos.sql.');
    }
    $st = $conexion->prepare("INSERT INTO configuracion_sistema(config_clave,config_valor,config_descripcion,config_actualizado) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE config_valor=VALUES(config_valor),config_descripcion=VALUES(config_descripcion),config_actualizado=NOW()");
    if (!$st) throw new RuntimeException('No se pudo preparar la configuracion de documentos.');
    $st->bind_param('sss', $clave, $valor, $descripcion);
    if (!$st->execute()) { $err=$st->error; $st->close(); throw new RuntimeException($err); }
    $st->close();
}

function documentosRaiz(mysqli $conexion): string
{
    return rtrim(documentosObtenerConfiguracion($conexion, 'documentos_raiz', documentosRutaPredeterminada()), "\\/");
}

function documentosNombreCarpeta(string $texto): string
{
    $texto = trim($texto);
    if ($texto === '') return 'SIN_NUMERO';
    $texto = preg_replace('/[\\\\\/:*?"<>|]+/u', '_', $texto);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    $texto = trim((string)$texto, ". \t\n\r\0\x0B");
    return $texto !== '' ? $texto : 'SIN_NUMERO';
}

function documentosCrearDirectorio(string $ruta): void
{
    if (is_dir($ruta)) return;
    if (!@mkdir($ruta, 0775, true) && !is_dir($ruta)) {
        throw new RuntimeException('No se pudo crear la carpeta de archivo: ' . $ruta);
    }
}

function documentosRutaArchivo(mysqli $conexion, string $categoria, string $numero='', ?int $anio=null): string
{
    $anio = $anio ?: (int)date('Y');
    $raiz = documentosRaiz($conexion);
    $partes = array($raiz, documentosNombreCarpeta($categoria), (string)$anio);
    if (trim($numero) !== '') $partes[] = documentosNombreCarpeta($numero);
    return implode(DIRECTORY_SEPARATOR, $partes);
}

/**
 * Copia un PDF ya generado al archivo externo. Si el archivo externo no esta
 * disponible, no se elimina ni altera la copia web; el llamador decide si
 * quiere tratar el error como advertencia.
 */
function documentosArchivarPdf(mysqli $conexion, string $origenAbsoluto, string $categoria, string $numero=''): string
{
    if (!is_file($origenAbsoluto)) throw new RuntimeException('No existe el PDF a archivar: ' . $origenAbsoluto);
    $destinoDir = documentosRutaArchivo($conexion, $categoria, $numero);
    documentosCrearDirectorio($destinoDir);
    $destino = $destinoDir . DIRECTORY_SEPARATOR . basename($origenAbsoluto);
    if (!@copy($origenAbsoluto, $destino)) {
        throw new RuntimeException('No se pudo copiar el PDF al archivo general: ' . $destino);
    }
    return $destino;
}

function documentosArchivarSinInterrumpir(mysqli $conexion, string $origenAbsoluto, string $categoria, string $numero=''): ?string
{
    try {
        return documentosArchivarPdf($conexion, $origenAbsoluto, $categoria, $numero);
    } catch (Throwable $e) {
        error_log('Archivo externo de PDF: ' . $e->getMessage());
        return null;
    }
}

function documentosProbarRuta(mysqli $conexion, ?string $ruta=null): array
{
    $ruta = trim((string)($ruta ?? documentosRaiz($conexion)));
    if ($ruta === '') return array(false, 'La ruta no puede quedar vacia.');
    try {
        documentosCrearDirectorio($ruta);
        $archivo = rtrim($ruta, "\\/") . DIRECTORY_SEPARATOR . '.automac_prueba_' . uniqid('', true) . '.tmp';
        $ok = @file_put_contents($archivo, 'AUTOMAC ' . date('c'));
        if ($ok === false) return array(false, 'La carpeta existe, pero PHP no tiene permiso de escritura.');
        @unlink($archivo);
        return array(true, 'Ruta disponible y con permiso de escritura.');
    } catch (Throwable $e) {
        return array(false, $e->getMessage());
    }
}
