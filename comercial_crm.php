<?php
/** CRM comercial ligero integrado a la tabla clientes existente. */
function asegurarComercialCrm(mysqli $conexion): void
{
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'comercial_seguimientos', array('seguimiento_id','cliente_id','tipo','nota','proximo_contacto','estado','usuario_id','usuario_nombre','responsable_id','responsable_nombre','fecha_creacion'), 'migracion_mejoras_integrales_v427.sql');
    verificarTablaColumnas($conexion, 'comercial_oportunidades', array('oportunidad_id','cliente_id','titulo','estado','importe_estimado','equipos_estimados','fecha_estimada','proximo_contacto','usuario_id','usuario_nombre','responsable_id','responsable_nombre','fecha_creacion'), 'migracion_mejoras_integrales_v427.sql');
    // Los datos previos sin responsable se completan como dato funcional, no como DDL.
    @$conexion->query("UPDATE comercial_seguimientos SET responsable_id=usuario_id,responsable_nombre=usuario_nombre WHERE responsable_id IS NULL");
    @$conexion->query("UPDATE comercial_oportunidades SET responsable_id=usuario_id,responsable_nombre=usuario_nombre WHERE responsable_id IS NULL");
    return;

}
function comercialEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function comercialCliente(mysqli $conexion, int $clienteId): ?array
{
    $st=$conexion->prepare("SELECT * FROM clientes WHERE clientes_id=? LIMIT 1");
    if(!$st)return null; $st->bind_param('i',$clienteId); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    return $r ?: null;
}

function comercialUsuarioActual(): array
{
    return array(
        'id'=>(int)($_SESSION['usuario_id'] ?? 0),
        'nombre'=>(string)($_SESSION['usuario_nombre'] ?? $_SESSION['usuario'] ?? 'Usuario')
    );
}

function comercialResponsables(mysqli $conexion): array
{
    $out=array();
    $r=$conexion->query("SELECT usuario_id,usuario_nombre,usuario_rol FROM usuarios WHERE usuario_activo='SI' AND usuario_rol IN('COMERCIAL','ADMINISTRADOR') ORDER BY usuario_nombre,usuario_login");
    if($r) while($x=$r->fetch_assoc()) $out[]=$x;
    return $out;
}

function comercialResponsable(mysqli $conexion, int $id, array $fallback): array
{
    if($id<=0) return $fallback;
    $st=$conexion->prepare("SELECT usuario_id,usuario_nombre FROM usuarios WHERE usuario_id=? AND usuario_activo='SI' LIMIT 1");
    if(!$st)return $fallback; $st->bind_param('i',$id);$st->execute();$x=$st->get_result()->fetch_assoc();$st->close();
    return $x ? array('id'=>(int)$x['usuario_id'],'nombre'=>(string)$x['usuario_nombre']) : $fallback;
}

function comercialExtraerModeloControl(string $descripcion): string
{
    if (preg_match('/\bControl\s+([A-Z0-9._-]+)/iu',$descripcion,$m)) return strtoupper(trim($m[1]));
    if (preg_match('/\b(A6[0-9A-Z._-]{3,})\b/iu',$descripcion,$m)) return strtoupper(trim($m[1]));
    return 'SIN IDENTIFICAR';
}

function comercialExtraerModeloPulsador(string $descripcion): string
{
    if (preg_match('/\bmodelo\s+([A-Z0-9._-]+)/iu',$descripcion,$m)) return strtoupper(trim($m[1]));
    if (preg_match('/\bpulsador\s+([A-Z0-9._-]+)/iu',$descripcion,$m)) return strtoupper(trim($m[1]));
    return 'SIN IDENTIFICAR';
}

function comercialMoneda($v): string { return '$ '.number_format((float)$v,0,',','.'); }
function comercialFecha($v, bool $hora=false): string {
    if(!$v)return '—'; $ts=strtotime((string)$v); if(!$ts)return (string)$v;
    return date($hora?'d/m/Y H:i':'d/m/Y',$ts);
}
