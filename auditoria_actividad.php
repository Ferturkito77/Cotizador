<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function asegurarAuditoriaActividad(mysqli $conexion): void
{
    static $hecho=false; if($hecho)return; $hecho=true;
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'auditoria_actividad', array('actividad_id','fecha','usuario_id','usuario_login','usuario_nombre','usuario_rol','modulo','accion','detalle','referencia','pagina','metodo','ip'), 'migracion_mejoras_integrales_v427.sql');
    // Retencion controlada: limpieza liviana una vez por sesion/dia.
    $hoy=date('Y-m-d');
    if(($_SESSION['auditoria_ultima_limpieza'] ?? '')!==$hoy){
        $_SESSION['auditoria_ultima_limpieza']=$hoy;
        @$conexion->query("DELETE FROM auditoria_actividad WHERE fecha < DATE_SUB(NOW(), INTERVAL 3 YEAR) LIMIT 5000");
    }
}
function auditoriaCantidad(mysqli $conexion): int
{
    asegurarAuditoriaActividad($conexion);$r=$conexion->query("SELECT COUNT(*) c FROM auditoria_actividad");return $r?(int)($r->fetch_assoc()['c']??0):0;
}

function auditoriaEliminarAnterioresA3Anios(mysqli $conexion,int $limite=50000): int
{
    asegurarAuditoriaActividad($conexion);$limite=max(1,min(200000,$limite));
    $conexion->query("DELETE FROM auditoria_actividad WHERE fecha < DATE_SUB(NOW(), INTERVAL 3 YEAR) LIMIT ".$limite);
    return max(0,(int)$conexion->affected_rows);
}


function auditoriaModuloPagina(string $pagina): string
{
    $p=strtolower($pagina);
    if(strpos($p,'comercial')!==false) return 'COMERCIAL';
    if(strpos($p,'cotizacion')!==false || $p==='index.php') return 'COTIZADOR';
    if(strpos($p,'pedido')!==false) return 'PEDIDOS';
    if(strpos($p,'orden')!==false || strpos($p,'produccion')!==false) return 'PRODUCCION';
    if(strpos($p,'cliente')!==false) return 'CLIENTES';
    if(strpos($p,'mantenimiento')!==false || strpos($p,'matriz')!==false || strpos($p,'plantilla')!==false || strpos($p,'precio')!==false) return 'MANTENIMIENTO';
    if(strpos($p,'usuario')!==false || strpos($p,'numeracion')!==false || strpos($p,'documento')!==false) return 'ADMINISTRACION';
    if(strpos($p,'pdf')!==false || strpos($p,'descargar')!==false || strpos($p,'exportar')!==false) return 'DOCUMENTOS';
    return 'SISTEMA';
}

function auditoriaRegistrar(mysqli $conexion,string $accion,string $modulo='',string $detalle='',string $referencia=''): void
{
    $uid=(int)($_SESSION['usuario_id']??0); if($uid<=0)return;
    asegurarAuditoriaActividad($conexion);
    $login=(string)($_SESSION['usuario_login']??'');
    $nombre=(string)($_SESSION['usuario_nombre']??'Usuario');
    $rol=(string)($_SESSION['usuario_rol']??'');
    $pagina=basename((string)($_SERVER['PHP_SELF']??''));
    if($modulo==='')$modulo=auditoriaModuloPagina($pagina);
    $metodo=(string)($_SERVER['REQUEST_METHOD']??'GET');
    $ip=(string)($_SERVER['REMOTE_ADDR']??'');
    $detalle=mb_substr(trim($detalle),0,4000,'UTF-8');
    $referencia=mb_substr(trim($referencia),0,160,'UTF-8');
    $st=$conexion->prepare("INSERT INTO auditoria_actividad(usuario_id,usuario_login,usuario_nombre,usuario_rol,modulo,accion,detalle,referencia,pagina,metodo,ip) VALUES(?,?,?,?,?,?,?,?,?,?,?)");
    if(!$st)return;
    $st->bind_param('issssssssss',$uid,$login,$nombre,$rol,$modulo,$accion,$detalle,$referencia,$pagina,$metodo,$ip);
    @$st->execute(); $st->close();
}

function auditoriaReferenciaSolicitud(): string
{
    $candidatos=array('cotizacion_numero','pedido_numero','obra_numero','numero','cliente_id','cotizacion_id','pedido_id','orden_id','obra_id');
    $partes=array();
    foreach($candidatos as $k){
        $v=$_POST[$k]??$_GET[$k]??null;
        if($v!==null && $v!=='' && !is_array($v))$partes[]=$k.'='.(string)$v;
        if(count($partes)>=3)break;
    }
    return implode(' · ',$partes);
}

function auditoriaRegistrarSolicitudAutomatica(mysqli $conexion): void
{
    static $registrada=false; if($registrada)return; $registrada=true;
    if(empty($_SESSION['usuario_id']))return;
    $pagina=basename((string)($_SERVER['PHP_SELF']??''));
    // Endpoints técnicos/automáticos: no ensuciar el historial humano.
    $ignorar=array('calcular.php','calcular_senalizacion.php','calcular_limites_accesorios.php','get_cliente.php','get_corriente_variador.php','get_subtipos.php','precio_pulsador_exterior.php','buscar_repuestos.php');
    if(in_array($pagina,$ignorar,true))return;
    $metodo=strtoupper((string)($_SERVER['REQUEST_METHOD']??'GET'));
    $modulo=auditoriaModuloPagina($pagina);
    $ref=auditoriaReferenciaSolicitud();
    if($metodo==='POST'){
        $accion=trim((string)($_POST['accion']??$_POST['modo']??''));
        if($accion==='')$accion='GUARDAR / MODIFICAR';
        $accion='Acción: '.str_replace(array('_','-'),' ',$accion);
        $detalle='Operación realizada en '.preg_replace('/\.php$/i','',$pagina).'.';
        // Agrega datos útiles no sensibles, evitando claves/passwords y cuerpos gigantes.
        $campos=array();
        foreach($_POST as $k=>$v){
            if(is_array($v))continue;
            if(preg_match('/clave|password|token|csrf/i',$k))continue;
            if(!preg_match('/^(cliente|solicitante|referencia|estado|tipo|titulo|responsable|motivo|numero|cotizacion|pedido|obra)/i',$k))continue;
            $sv=trim((string)$v); if($sv==='')continue;
            $campos[]=$k.'='.mb_substr($sv,0,120,'UTF-8');
            if(count($campos)>=6)break;
        }
        if($campos)$detalle.=' '.implode(' · ',$campos);
        auditoriaRegistrar($conexion,$accion,$modulo,$detalle,$ref);
    }else{
        $accion='Abrió '.preg_replace('/\.php$/i','',$pagina);
        auditoriaRegistrar($conexion,$accion,$modulo,'Acceso a pantalla o consulta.',$ref);
    }
}
?>
