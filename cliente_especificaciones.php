<?php
if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }

function asegurarEspecificacionesTecnicasClientes(mysqli $conexion): void
{
    static $hecho=false; if($hecho)return; $hecho=true;
    require_once __DIR__ . '/schema_guard.php';
    verificarTablaColumnas($conexion, 'clientes_especificaciones_tecnicas', array('especificacion_id','cliente_id','tipo','categoria','titulo','detalle','activo','usuario_id','usuario_nombre','fecha_creacion'), 'migracion_mejoras_integrales_v427.sql');
}
function especificacionesTecnicasCliente(mysqli $conexion,int $clienteId,bool $soloActivas=true): array
{
    asegurarEspecificacionesTecnicasClientes($conexion);
    $sql="SELECT * FROM clientes_especificaciones_tecnicas WHERE cliente_id=?".($soloActivas?" AND activo='SI'":"")." ORDER BY FIELD(tipo,'OBLIGATORIA','PREFERENCIA','OBSERVACION'),fecha_creacion DESC,especificacion_id DESC";
    $st=$conexion->prepare($sql); if(!$st)return array();
    $st->bind_param('i',$clienteId);$st->execute();$r=$st->get_result();$out=array();while($x=$r->fetch_assoc())$out[]=$x;$st->close();return $out;
}
?>
