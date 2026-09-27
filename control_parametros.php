<?php
require_once __DIR__ . '/schema_guard.php';
function ctrlTablaExiste(mysqli $c, string $tabla): bool { return esquemaTablaExiste($c,$tabla); }
function ctrlColumnaExiste(mysqli $c, string $tabla, string $columna): bool { return esquemaColumnaExiste($c,$tabla,$columna); }
function ctrlCpuCapacidad(mysqli $c, int $cpuId): array {
    $def = array('activo'=>'SI','admite_mrl'=>'NO','admite_micronivelacion'=>'NO','admite_maniobra_sabatica'=>'NO','mrl_identidad'=>'AUTOMAC');
    if (!ctrlTablaExiste($c,'control_cpu_capacidades')) return $def;
    $sql = ctrlColumnaExiste($c,'control_cpu_capacidades','activo')
        ? 'SELECT activo,admite_mrl,admite_micronivelacion,admite_maniobra_sabatica,mrl_identidad FROM control_cpu_capacidades WHERE cpu_id=? LIMIT 1'
        : "SELECT 'SI' AS activo,admite_mrl,admite_micronivelacion,admite_maniobra_sabatica,mrl_identidad FROM control_cpu_capacidades WHERE cpu_id=? LIMIT 1";
    $st=$c->prepare($sql);
    if(!$st) return $def; $st->bind_param('i',$cpuId); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    return $r ? array_merge($def,$r) : $def;
}
function ctrlTipoCapacidad(mysqli $c, int $tipoId): array {
    $def=array('requiere_central'=>'NO','permite_tandem'=>'NO','requiere_velocidad'=>'NO','velocidad_fija_mmin'=>null,'familia_rescate'=>'','es_mrl'=>'NO','activo'=>'SI');
    if (!ctrlTablaExiste($c,'control_tipo_capacidades')) return $def;
    $st=$c->prepare('SELECT requiere_central,permite_tandem,requiere_velocidad,velocidad_fija_mmin,familia_rescate,es_mrl,activo FROM control_tipo_capacidades WHERE ctrltipo_id=? LIMIT 1');
    if(!$st) return $def; $st->bind_param('i',$tipoId); $st->execute(); $r=$st->get_result()->fetch_assoc(); $st->close();
    return $r ? array_merge($def,$r) : $def;
}
function ctrlCpuAdmiteTipo(mysqli $c, int $cpuId, int $tipoId): bool {
    if (!ctrlTablaExiste($c,'control_compatibilidades')) {
        if ($tipoId===7) return in_array($cpuId,array(2,3,4,5),true);
        return true;
    }
    $st=$c->prepare("SELECT 1 FROM control_compatibilidades WHERE cpu_id=? AND ctrltipo_id=? AND activo='SI' LIMIT 1");
    if(!$st) return false; $st->bind_param('ii',$cpuId,$tipoId); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}

function ctrlCpuAdmiteCompatibilidad(mysqli $c, int $cpuId, int $tipoId, int $subtipoId): bool {
    if (!ctrlTablaExiste($c,'control_compatibilidades')) return ctrlCpuAdmiteTipo($c,$cpuId,$tipoId);
    $st=$c->prepare("SELECT 1 FROM control_compatibilidades WHERE cpu_id=? AND ctrltipo_id=? AND ctrlsubtipo_id=? AND activo='SI' LIMIT 1");
    if(!$st) return false;
    $st->bind_param('iii',$cpuId,$tipoId,$subtipoId); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
