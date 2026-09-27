<?php
function documentoEventoRegistrar(mysqli $conexion,string $tipo,int $id,string $numero,string $evento,string $estadoAnterior='',string $estadoNuevo='',string $detalle=''): void
{
    if ($id<=0 || $evento==='') return;
    $tipo=strtoupper(trim($tipo));
    if(!in_array($tipo,array('COTIZACION','PEDIDO','OBRA','OF'),true)) return;
    $uid=(int)($_SESSION['usuario_id']??0);
    $unombre=(string)($_SESSION['usuario_nombre']??'');
    $detalle=mb_substr(trim($detalle),0,500,'UTF-8');
    $st=$conexion->prepare('INSERT INTO documentos_eventos(documento_tipo,documento_id,documento_numero,evento,estado_anterior,estado_nuevo,detalle,usuario_id,usuario_nombre) VALUES(?,?,?,?,?,?,?,?,?)');
    if(!$st)return;
    $st->bind_param('sisssssis',$tipo,$id,$numero,$evento,$estadoAnterior,$estadoNuevo,$detalle,$uid,$unombre);
    @$st->execute();$st->close();
}
