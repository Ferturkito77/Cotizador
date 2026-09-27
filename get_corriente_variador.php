<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$idCpu=(int)($_POST['id_cpu']??0);
$idTipo=(int)($_POST['id_tipo_control']??0);
$idSubtipo=(int)($_POST['id_subtipo']??0);
$idTension=(int)($_POST['id_tension']??0);
$potencia=(float)($_POST['potencia_hp']??0);
$encoder=!empty($_POST['encoder']) && (string)$_POST['encoder']==='SI'?'SI':'';
if(!$idCpu||!$idTipo||!$idSubtipo||!$idTension||$potencia<=0){echo json_encode(array('ok'=>false,'corriente'=>0));exit;}

/* v118: resolver la CPU de matriz para CLEX/DANGELICA y cualquier CPU heredada. */
$idCpuMatriz=$idCpu;
$stCpu=$conexion->prepare('SELECT COALESCE(NULLIF(cpu_matriz_base_id,0),cpu_id) AS cpu_matriz_base_id FROM cpus WHERE cpu_id=? LIMIT 1');
if($stCpu){
    $stCpu->bind_param('i',$idCpu);
    $stCpu->execute();
    $fc=$stCpu->get_result()->fetch_assoc();
    $stCpu->close();
    if($fc && (int)$fc['cpu_matriz_base_id']>0)$idCpuMatriz=(int)$fc['cpu_matriz_base_id'];
}

$sql="SELECT control_corriente, control_codigo FROM matriz_calculos
      WHERE control_cpu=? AND control_tipo=? AND control_subtipo=? AND control_tension=?
        AND COALESCE(NULLIF(TRIM(control_encoder),''),'')=?
        AND ?>control_potenciadesde AND ?<=control_potenciahasta
      ORDER BY control_potenciadesde DESC LIMIT 1";
$st=$conexion->prepare($sql);
if(!$st){echo json_encode(array('ok'=>false,'corriente'=>0));exit;}
$st->bind_param('iiiisdd',$idCpuMatriz,$idTipo,$idSubtipo,$idTension,$encoder,$potencia,$potencia);
$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
echo json_encode(array('ok'=>(bool)$r,'corriente'=>$r?(float)$r['control_corriente']:0,'codigo'=>$r?(string)$r['control_codigo']:''),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
