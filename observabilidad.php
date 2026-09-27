<?php
/**
 * AUTOMAC v444 - Observabilidad liviana.
 * Registra tiempos de respuesta lentos en JSONL sin cambiar la logica del sistema.
 */
if (!defined('AUTOMAC_OBS_START')) {
    define('AUTOMAC_OBS_START', microtime(true));
}

function automacObsDirectorio(): string
{
    return __DIR__ . '/storage/logs';
}

function automacObsAsegurarDirectorio(): bool
{
    $dir = automacObsDirectorio();
    if (is_dir($dir)) return is_writable($dir);
    return @mkdir($dir, 0775, true) && is_writable($dir);
}

function automacObsRotar(string $archivo, int $maxBytes = 2097152): void
{
    if (!is_file($archivo) || @filesize($archivo) < $maxBytes) return;
    $previo = $archivo . '.1';
    if (is_file($previo)) @unlink($previo);
    @rename($archivo, $previo);
}

function automacObsEscribir(string $nombre, array $dato): void
{
    if (!automacObsAsegurarDirectorio()) return;
    $archivo = automacObsDirectorio() . '/' . $nombre . '.log';
    automacObsRotar($archivo);
    $linea = json_encode($dato, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($linea !== false) @file_put_contents($archivo, $linea . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function automacObsRutaActual(): string
{
    $uri = (string)($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? 'cli');
    $ruta = parse_url($uri, PHP_URL_PATH);
    return is_string($ruta) && $ruta !== '' ? basename($ruta) : 'desconocido';
}

function automacObsUmbralMs(): float
{
    $raw = getenv('AUTOMAC_SLOW_REQUEST_MS');
    if ($raw !== false && is_numeric($raw)) return max(50.0, (float)$raw);
    return 500.0;
}

function automacObsRegistrarRendimiento(): void
{
    if (PHP_SAPI === 'cli') return;
    $ms = (microtime(true) - AUTOMAC_OBS_START) * 1000;
    $registrarTodo = getenv('AUTOMAC_PERF_LOG_ALL') === '1';
    if (!$registrarTodo && $ms < automacObsUmbralMs()) return;

    automacObsEscribir('performance', array(
        'ts' => date('c'),
        'ruta' => automacObsRutaActual(),
        'metodo' => (string)($_SERVER['REQUEST_METHOD'] ?? 'GET'),
        'duracion_ms' => round($ms, 1),
        'memoria_mb' => round(memory_get_peak_usage(true) / 1048576, 2),
        'status' => http_response_code(),
    ));
}

function automacObsLeer(string $nombre, int $limite = 100): array
{
    $archivo = automacObsDirectorio() . '/' . $nombre . '.log';
    if (!is_file($archivo) || !is_readable($archivo)) return array();
    $lineas = @file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (!is_array($lineas)) return array();
    $lineas = array_slice($lineas, -max(1, $limite));
    $salida = array();
    foreach ($lineas as $linea) {
        $dato = json_decode($linea, true);
        if (is_array($dato)) $salida[] = $dato;
    }
    return array_reverse($salida);
}

if (!defined('AUTOMAC_OBS_SHUTDOWN_REGISTRADO')) {
    define('AUTOMAC_OBS_SHUTDOWN_REGISTRADO', true);
    register_shutdown_function('automacObsRegistrarRendimiento');
}

// AUTOMAC v445 - Registro tecnico de errores PHP.
function automacObsNivelError(int $tipo): string
{
    $map=array(
        E_ERROR=>'E_ERROR',E_WARNING=>'E_WARNING',E_PARSE=>'E_PARSE',E_NOTICE=>'E_NOTICE',
        E_CORE_ERROR=>'E_CORE_ERROR',E_CORE_WARNING=>'E_CORE_WARNING',E_COMPILE_ERROR=>'E_COMPILE_ERROR',
        E_COMPILE_WARNING=>'E_COMPILE_WARNING',E_USER_ERROR=>'E_USER_ERROR',E_USER_WARNING=>'E_USER_WARNING',
        E_USER_NOTICE=>'E_USER_NOTICE',E_STRICT=>'E_STRICT',E_RECOVERABLE_ERROR=>'E_RECOVERABLE_ERROR',
        E_DEPRECATED=>'E_DEPRECATED',E_USER_DEPRECATED=>'E_USER_DEPRECATED'
    );
    return $map[$tipo] ?? ('ERROR_'.$tipo);
}

function automacObsRegistrarErrorPhp(int $tipo, string $mensaje, string $archivo, int $linea): bool
{
    if (!(error_reporting() & $tipo)) return false;
    automacObsEscribir('errors', array(
        'ts'=>date('c'),
        'nivel'=>automacObsNivelError($tipo),
        'mensaje'=>$mensaje,
        'archivo'=>basename($archivo),
        'linea'=>$linea,
        'ruta'=>automacObsRutaActual(),
    ));
    return false; // PHP conserva su manejo normal del error.
}

function automacObsRegistrarFatal(): void
{
    $e=error_get_last();
    if(!$e || !in_array((int)$e['type'],array(E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR),true)) return;
    automacObsEscribir('errors', array(
        'ts'=>date('c'),
        'nivel'=>automacObsNivelError((int)$e['type']),
        'mensaje'=>(string)$e['message'],
        'archivo'=>basename((string)$e['file']),
        'linea'=>(int)$e['line'],
        'ruta'=>automacObsRutaActual(),
    ));
}

if (!defined('AUTOMAC_OBS_ERROR_HANDLER_REGISTRADO')) {
    define('AUTOMAC_OBS_ERROR_HANDLER_REGISTRADO', true);
    set_error_handler('automacObsRegistrarErrorPhp');
    register_shutdown_function('automacObsRegistrarFatal');
}
