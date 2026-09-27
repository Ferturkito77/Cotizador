<?php
/**
 * Seguridad transversal del cotizador.
 * - cookies de sesion endurecidas cuando la sesion aun no inicio
 * - defensa CSRF por token para operaciones sensibles
 * - defensa Same-Origin/Fetch-Metadata para todo POST autenticado
 */
function automacConfigurarSesionSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || ((string)($_SERVER['SERVER_PORT'] ?? '') === '443');
    @ini_set('session.use_strict_mode', '1');
    @ini_set('session.use_only_cookies', '1');
    @ini_set('session.cookie_httponly', '1');
    @ini_set('session.cookie_samesite', 'Lax');
    if ($https) @ini_set('session.cookie_secure', '1');
}

function automacCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['automac_csrf']) || !is_string($_SESSION['automac_csrf'])) {
        $_SESSION['automac_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['automac_csrf'];
}

function automacCsrfInput(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(automacCsrfToken(), ENT_QUOTES, 'UTF-8') . '">';
}

function automacValidarCsrf(bool $obligatorio = true): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') return true;
    $recibido = (string)($_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $esperado = automacCsrfToken();
    $ok = ($recibido !== '' && hash_equals($esperado, $recibido));
    if (!$ok && $obligatorio) {
        http_response_code(419);
        die('La sesión del formulario venció o la solicitud no es válida. Volvé a cargar la página e intentá nuevamente.');
    }
    return $ok;
}

function automacMismoOrigen(): bool
{
    $metodo = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($metodo, array('POST','PUT','PATCH','DELETE'), true)) return true;

    $fetchSite = strtolower(trim((string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '')));
    if ($fetchSite !== '' && in_array($fetchSite, array('cross-site'), true)) return false;

    $host = strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')));
    foreach (array('HTTP_ORIGIN','HTTP_REFERER') as $header) {
        $valor = trim((string)($_SERVER[$header] ?? ''));
        if ($valor === '') continue;
        $h = strtolower((string)(parse_url($valor, PHP_URL_HOST) ?? ''));
        if ($host !== '' && $h !== '' && !hash_equals($host, $h)) return false;
    }
    return true;
}

function automacProtegerSolicitudMutante(): void
{
    if (!automacMismoOrigen()) {
        http_response_code(403);
        die('Solicitud rechazada por seguridad. Recargá el cotizador e intentá nuevamente.');
    }
}

/** v428: usar en endpoints que realizan cambios persistentes. */
function automacExigirPostConCsrf(): void
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        die('Método no permitido.');
    }
    automacValidarCsrf(true);
}
