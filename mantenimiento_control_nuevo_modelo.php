<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
require_once 'control_parametros.php';
require_once 'schema_guard.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function nmcE($v){ return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nmcTablaColumna(mysqli $c, string $tabla, string $columna): bool {
    $st=$c->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    if(!$st) return false;
    $st->bind_param('ss',$tabla,$columna); $st->execute(); $ok=(bool)$st->get_result()->fetch_row(); $st->close(); return $ok;
}
function nmcFlash($msg,$tipo='ok'){ $_SESSION['nmc_msg']=$msg; $_SESSION['nmc_tipo']=$tipo; }
function nmcRedirect($url){ header('Location: '.$url); exit; }
function nmcContarFilasMatriz(mysqli $c, int $cpu, array $compat): int {
    if(!$cpu || !$compat) return 0;
    $total=0;
    $st=$c->prepare('SELECT COUNT(*) c FROM matriz_calculos WHERE control_cpu=? AND control_tipo=? AND control_subtipo=?');
    if(!$st) return 0;
    foreach($compat as $par){ $tipo=(int)$par[0]; $sub=(int)$par[1]; $st->bind_param('iii',$cpu,$tipo,$sub); $st->execute(); $r=$st->get_result()->fetch_assoc(); $total+=(int)($r['c']??0); }
    $st->close();
    return $total;
}

$requisitos = array(
    'cpus.v117' => nmcTablaColumna($conexion,'cpus','velocidad_max_mmin') && nmcTablaColumna($conexion,'cpus','admite_encoder') && nmcTablaColumna($conexion,'cpus','cpu_matriz_base_id'),
    'v213' => ctrlTablaExiste($conexion,'control_cpu_capacidades') && ctrlTablaExiste($conexion,'control_tipo_capacidades') && ctrlTablaExiste($conexion,'control_compatibilidades'),
    'v214' => nmcTablaColumna($conexion,'control_cpu_capacidades','activo')
);
$instalado = !in_array(false,$requisitos,true);

$msg=$_SESSION['nmc_msg']??''; $msgTipo=$_SESSION['nmc_tipo']??'ok'; unset($_SESSION['nmc_msg'],$_SESSION['nmc_tipo']);

$cpusBase=$conexion->query("SELECT c.cpu_id,c.cpu_name,c.velocidad_max_mmin,c.admite_encoder,
    COALESCE(NULLIF(c.cpu_matriz_base_id,0),c.cpu_id) cpu_matriz_base_id,
    COUNT(DISTINCT m.control_id) filas_matriz
    FROM cpus c
    LEFT JOIN matriz_calculos m ON m.control_cpu=COALESCE(NULLIF(c.cpu_matriz_base_id,0),c.cpu_id)
    GROUP BY c.cpu_id,c.cpu_name,c.velocidad_max_mmin,c.admite_encoder,c.cpu_matriz_base_id
    ORDER BY c.cpu_name");
$tipos=$conexion->query("SELECT t.ctrltipo_id,t.ctrltipo_name,
    COALESCE(cap.activo,'SI') tipo_activo,COALESCE(cap.es_mrl,'NO') es_mrl
    FROM tipos_control t LEFT JOIN control_tipo_capacidades cap ON cap.ctrltipo_id=t.ctrltipo_id
    ORDER BY t.ctrltipo_id");
$subs=$conexion->query('SELECT ctrlsubtipo_id,ctrlsubtipo_name FROM subtipos_control ORDER BY ctrlsubtipo_name');
$tiposArr=array(); if($tipos) while($r=$tipos->fetch_assoc()) $tiposArr[(int)$r['ctrltipo_id']]=$r;
$subsArr=array(); if($subs) while($r=$subs->fetch_assoc()) $subsArr[(int)$r['ctrlsubtipo_id']]=$r;

$baseElegida=(int)($_GET['base']??($_POST['cpu_matriz_base_id']??0));
$baseNombre='';
if($baseElegida){$stbn=$conexion->prepare('SELECT cpu_name FROM cpus WHERE cpu_id=? LIMIT 1');if($stbn){$stbn->bind_param('i',$baseElegida);$stbn->execute();$rbn=$stbn->get_result()->fetch_assoc();$baseNombre=$rbn['cpu_name']??'';$stbn->close();}}
$compatBase=array();
if($baseElegida && ctrlTablaExiste($conexion,'control_compatibilidades')){
    $st=$conexion->prepare("SELECT cc.ctrltipo_id,cc.ctrlsubtipo_id,t.ctrltipo_name,s.ctrlsubtipo_name,cc.activo
        FROM control_compatibilidades cc
        JOIN tipos_control t ON t.ctrltipo_id=cc.ctrltipo_id
        JOIN subtipos_control s ON s.ctrlsubtipo_id=cc.ctrlsubtipo_id
        WHERE cc.cpu_id=? AND cc.activo='SI'
        ORDER BY t.ctrltipo_id,s.ctrlsubtipo_name");
    if($st){$st->bind_param('i',$baseElegida);$st->execute();$rs=$st->get_result();while($r=$rs->fetch_assoc())$compatBase[]=$r;$st->close();}
}

if($instalado && $_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='crear_modelo'){
    $nombre=trim((string)($_POST['cpu_name']??''));
    $velRaw=trim((string)($_POST['velocidad_max_mmin']??''));
    $vel=$velRaw===''?null:(float)str_replace(',','.',$velRaw);
    $encoder=in_array(($_POST['admite_encoder']??''),array('SI','NO'),true)?$_POST['admite_encoder']:null;
    $base=(int)($_POST['cpu_matriz_base_id']??0);
    $activo=($_POST['activo']??'SI')==='NO'?'NO':'SI';
    $mrl=($_POST['admite_mrl']??'NO')==='SI'?'SI':'NO';
    $micro=($_POST['admite_micronivelacion']??'NO')==='SI'?'SI':'NO';
    $sab=($_POST['admite_maniobra_sabatica']??'NO')==='SI'?'SI':'NO';
    $ident=trim((string)($_POST['mrl_identidad']??'AUTOMAC')); if($ident==='')$ident='AUTOMAC';
    $obs=trim((string)($_POST['observaciones']??''));
    $compatRaw=isset($_POST['compat'])&&is_array($_POST['compat'])?$_POST['compat']:array();
    $crearMatrizPropia=($_POST['crear_matriz_propia']??'NO')==='SI'?'SI':'NO';

    $errores=array();
    if($nombre==='') $errores[]='Ingrese el nombre del nuevo modelo/CPU.';
    if($vel!==null && $vel<=0) $errores[]='La velocidad máxima debe quedar vacía o ser mayor que cero.';
    if(!$encoder) $errores[]='Defina si el modelo admite encoder.';
    if(!$base) $errores[]='Seleccione una matriz base técnica/económica.';
    if(!$compatRaw) $errores[]='Seleccione al menos una combinación Tipo + Subtipo.';

    if(!$errores){
        $st=$conexion->prepare('SELECT cpu_id FROM cpus WHERE UPPER(TRIM(cpu_name))=UPPER(TRIM(?)) LIMIT 1');
        $st->bind_param('s',$nombre);$st->execute();if($st->get_result()->fetch_row())$errores[]='Ya existe un modelo con ese nombre.';$st->close();
        $st=$conexion->prepare('SELECT cpu_id FROM cpus WHERE cpu_id=? LIMIT 1');$st->bind_param('i',$base);$st->execute();if(!$st->get_result()->fetch_row())$errores[]='La CPU elegida como matriz base no existe.';$st->close();
    }

    $compatValidas=array(); $incluyeMrl=false;
    foreach($compatRaw as $clave){
        if(!preg_match('/^(\d+):(\d+)$/',(string)$clave,$m)) continue;
        $tipo=(int)$m[1];$sub=(int)$m[2];
        if(!isset($tiposArr[$tipo],$subsArr[$sub])) continue;
        if(($tiposArr[$tipo]['tipo_activo']??'SI')!=='SI') continue;
        if(($tiposArr[$tipo]['es_mrl']??'NO')==='SI')$incluyeMrl=true;
        $compatValidas[$tipo.':'.$sub]=array($tipo,$sub);
    }
    if(!$compatValidas)$errores[]='Las compatibilidades seleccionadas no son válidas.';
    if($incluyeMrl && $mrl!=='SI')$errores[]='Seleccionó una configuración MRL pero el modelo está marcado como NO compatible con MRL.';

    if($errores){
        nmcFlash(implode(' ',array_unique($errores)),'error');
    } else {
        $conexion->begin_transaction();
        try{
            $st=$conexion->prepare('INSERT INTO cpus(cpu_name,velocidad_max_mmin,admite_encoder,cpu_matriz_base_id) VALUES(?,?,?,?)');
            if(!$st) throw new Exception($conexion->error);
            $st->bind_param('sdsi',$nombre,$vel,$encoder,$base);
            if(!$st->execute())throw new Exception($st->error);
            $nuevoCpu=(int)$st->insert_id;$st->close();

            $st=$conexion->prepare("INSERT INTO control_cpu_capacidades(cpu_id,activo,admite_mrl,admite_micronivelacion,admite_maniobra_sabatica,mrl_identidad,observaciones)
                VALUES(?,?,?,?,?,?,?)");
            if(!$st)throw new Exception($conexion->error);
            $st->bind_param('issssss',$nuevoCpu,$activo,$mrl,$micro,$sab,$ident,$obs);
            if(!$st->execute())throw new Exception($st->error);$st->close();

            $st=$conexion->prepare("INSERT INTO control_compatibilidades(cpu_id,ctrltipo_id,ctrlsubtipo_id,activo,observaciones) VALUES(?,?,?,'SI',?)");
            if(!$st)throw new Exception($conexion->error);
            foreach($compatValidas as $par){
                $nota='Alta asistida v214';$tipo=$par[0];$sub=$par[1];
                $st->bind_param('iiis',$nuevoCpu,$tipo,$sub,$nota);
                if(!$st->execute())throw new Exception($st->error);
            }
            $st->close();

            $filasClonadas=0;
            if($crearMatrizPropia==='SI') {
                $sqlClonar="INSERT INTO matriz_calculos (control_cpu,control_tipo,control_subtipo,control_tension,control_encoder,control_potenciadesde,control_potenciahasta,control_corriente,control_contactorpot,control_contactor,control_termicos,control_codigo,control_precio)
                    SELECT ?,control_tipo,control_subtipo,control_tension,control_encoder,control_potenciadesde,control_potenciahasta,control_corriente,control_contactorpot,control_contactor,control_termicos,control_codigo,control_precio
                    FROM matriz_calculos WHERE control_cpu=? AND control_tipo=? AND control_subtipo=?";
                $stc=$conexion->prepare($sqlClonar);
                if(!$stc) throw new Exception($conexion->error);
                foreach($compatValidas as $par){
                    $tipo=$par[0]; $sub=$par[1];
                    $stc->bind_param('iiii',$nuevoCpu,$base,$tipo,$sub);
                    if(!$stc->execute()) throw new Exception($stc->error);
                    $filasClonadas += $stc->affected_rows;
                }
                $stc->close();
            }

            $conexion->commit();
            nmcFlash('Modelo '.$nombre.' creado correctamente. Se habilitaron '.count($compatValidas).' combinaciones Tipo + Subtipo.'.($crearMatrizPropia==='SI'?' Se crearon '.$filasClonadas.' filas de matriz propias.':' Reutiliza la matriz base hasta que se creen filas propias.'));
            nmcRedirect('mantenimiento_control_nuevo_modelo.php?creado='.$nuevoCpu);
        } catch(Throwable $e){
            $conexion->rollback();
            nmcFlash('No se pudo crear el modelo. No se guardó ningún cambio. Detalle: '.$e->getMessage(),'error');
        }
    }
}

$creado=(int)($_GET['creado']??0); $modeloCreado=null;$conteosCreado=null;$filasPropiasCreado=0;
if($creado){
    $st=$conexion->prepare("SELECT c.*,cap.activo,cap.admite_mrl,cap.admite_micronivelacion,cap.admite_maniobra_sabatica,cap.mrl_identidad,b.cpu_name base_nombre
        FROM cpus c LEFT JOIN control_cpu_capacidades cap ON cap.cpu_id=c.cpu_id LEFT JOIN cpus b ON b.cpu_id=c.cpu_matriz_base_id WHERE c.cpu_id=? LIMIT 1");
    if($st){$st->bind_param('i',$creado);$st->execute();$modeloCreado=$st->get_result()->fetch_assoc();$st->close();}
    $st=$conexion->prepare("SELECT COUNT(*) compat FROM control_compatibilidades WHERE cpu_id=? AND activo='SI'"); if($st){$st->bind_param('i',$creado);$st->execute();$conteosCreado=$st->get_result()->fetch_assoc();$st->close();}
    $st=$conexion->prepare('SELECT COUNT(*) c FROM matriz_calculos WHERE control_cpu=?'); if($st){$st->bind_param('i',$creado);$st->execute();$rr=$st->get_result()->fetch_assoc();$filasPropiasCreado=(int)($rr['c']??0);$st->close();}
}
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><title>Nuevo modelo de Control · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
main{max-width:1450px;margin:auto;padding:24px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:12px;padding:18px;margin-bottom:14px}.head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.btn{display:inline-block;padding:9px 12px;border:0;border-radius:8px;background:#0f5f9c;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.sec{background:#edf3f7;color:#234}.msg{padding:11px;border-radius:8px;margin:10px 0}.ok{background:#e8f7ef}.error{background:#fdecec;color:#8a1c1c}.warn{background:#fff7df;border:1px solid #eed69a;padding:12px;border-radius:9px}.steps{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin:12px 0}.step{padding:9px;border-radius:8px;background:#eef4f8;font-size:12px;font-weight:700}.grid{display:grid;grid-template-columns:repeat(3,minmax(210px,1fr));gap:12px}.grid2{display:grid;grid-template-columns:repeat(2,minmax(240px,1fr));gap:10px}label{display:block;font-size:12px;font-weight:700;color:#52606d}input,select,textarea{width:100%;box-sizing:border-box;padding:8px;border:1px solid #cbd5df;border-radius:7px;background:#fff}input[type=checkbox]{width:auto}.compat{display:grid;grid-template-columns:repeat(3,minmax(260px,1fr));gap:8px;max-height:420px;overflow:auto;border:1px solid #dde5ec;border-radius:9px;padding:10px}.compat label{display:flex;gap:8px;align-items:flex-start;padding:8px;border:1px solid #e4eaf0;border-radius:8px;background:#fbfcfd;font-weight:600}.mini{font-size:12px;color:#667085}.badge{display:inline-block;padding:3px 7px;border-radius:999px;background:#eef3f7;font-size:11px}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:8px}.summary div{padding:10px;background:#f6f9fb;border-radius:8px}.req{color:#b42318}@media(max-width:900px){.grid,.grid2,.compat,.steps,.summary{grid-template-columns:1fr}}
</style></head><body><?php require 'menu.php';?><main>
<section class="card"><div class="head"><div><h1 style="margin:0">Nuevo modelo de Control</h1><p class="mini">Alta guiada de una CPU/modelo con matriz base, capacidades y compatibilidades. No modifica modelos ni históricos existentes.</p></div><div><a class="btn sec" href="mantenimiento_control.php?tab=catalogos">Volver a Matrices de Control</a></div></div><?php if($msg):?><div class="msg <?=nmcE($msgTipo)?>"><?=nmcE($msg)?></div><?php endif;?>
<?php if(!$instalado):?><div class="warn"><strong>Faltan requisitos de base.</strong><br><?php foreach($requisitos as $k=>$ok):?><?=nmcE($k)?>: <strong><?=$ok?'OK':'FALTA'?></strong><br><?php endforeach;?><br>Importe primero <code>migracion_control_parametrizacion_v213.sql</code> y luego <code>migracion_control_asistente_v214.sql</code>.</div><?php endif;?></section>

<?php if($modeloCreado):?><section class="card"><h2 style="margin-top:0">Modelo creado</h2><div class="summary"><div><span class="mini">Modelo</span><br><strong><?=nmcE($modeloCreado['cpu_name'])?></strong></div><div><span class="mini">Matriz base</span><br><strong><?=nmcE($modeloCreado['base_nombre'])?></strong></div><div><span class="mini">Compatibilidades</span><br><strong><?=(int)($conteosCreado['compat']??0)?></strong></div><div><span class="mini">Filas propias</span><br><strong><?=$filasPropiasCreado?></strong></div><div><span class="mini">Estado</span><br><strong><?=nmcE($modeloCreado['activo']??'SI')?></strong></div></div><p class="mini"><?=$filasPropiasCreado>0?'El modelo ya posee una matriz económica/técnica propia. El motor la prioriza sobre la matriz base.':'El modelo reutiliza la matriz base mientras no tenga filas propias.'?></p><a class="btn" href="mantenimiento_control.php?tab=principal&cpu=<?=(int)$modeloCreado['cpu_id']?>">Ver modelo en Matrices de Control</a><a class="btn sec" href="mantenimiento_control_editar_modelo.php?cpu=<?=(int)$modeloCreado['cpu_id']?>">Editar modelo completo</a><a class="btn sec" href="administrar_matriz.php?f_cpu=<?=(int)$modeloCreado['cpu_id']?>">Editar matriz propia</a></section><?php endif;?>

<?php if($instalado):?><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="crear_modelo">
<section class="card"><div class="steps"><div class="step">1 · Modelo y matriz base</div><div class="step">2 · Capacidades</div><div class="step">3 · Tipos y subtipos</div><div class="step">4 · Matriz propia</div><div class="step">5 · Validar y crear</div></div><h2>1. Modelo y matriz base</h2><div class="grid"><label>Nombre del modelo <span class="req">*</span><input name="cpu_name" required maxlength="50" value="<?=nmcE($_POST['cpu_name']??'')?>" placeholder="Ej.: NUEVO MODELO"></label><label>Matriz base <span class="req">*</span><select name="cpu_matriz_base_id" id="baseCpu" required onchange="location.href='mantenimiento_control_nuevo_modelo.php?base='+this.value"><option value="">Seleccione</option><?php if($cpusBase){$cpusBase->data_seek(0);while($r=$cpusBase->fetch_assoc()):?><option value="<?=(int)$r['cpu_id']?>"<?=$baseElegida===(int)$r['cpu_id']?' selected':''?>><?=nmcE($r['cpu_name'])?> · <?=(int)$r['filas_matriz']?> filas</option><?php endwhile;}?></select><span class="mini">Define qué matriz existente puede reutilizar el nuevo modelo.</span></label><label>Velocidad máxima m/min<input type="number" min="1" step="0.01" name="velocidad_max_mmin" value="<?=nmcE($_POST['velocidad_max_mmin']??'')?>" placeholder="Vacío = sin tope propio"></label><label>Admite encoder <span class="req">*</span><select name="admite_encoder" required><option value="">Seleccione</option><option value="NO" <?=($_POST['admite_encoder']??'')==='NO'?'selected':''?>>NO</option><option value="SI" <?=($_POST['admite_encoder']??'')==='SI'?'selected':''?>>SI</option></select></label><label>Estado inicial<select name="activo"><option value="SI">ACTIVO</option><option value="NO" <?=($_POST['activo']??'')==='NO'?'selected':''?>>INACTIVO</option></select><span class="mini">Inactivo no se ofrece en nuevas cotizaciones.</span></label></div></section>
<section class="card"><h2>2. Capacidades del modelo</h2><div class="grid"><label>Admite MRL<select name="admite_mrl"><option value="NO">NO</option><option value="SI" <?=($_POST['admite_mrl']??'')==='SI'?'selected':''?>>SI</option></select></label><label>Admite micronivelación<select name="admite_micronivelacion"><option value="NO">NO</option><option value="SI" <?=($_POST['admite_micronivelacion']??'')==='SI'?'selected':''?>>SI</option></select></label><label>Admite maniobra sabática<select name="admite_maniobra_sabatica"><option value="NO">NO</option><option value="SI" <?=($_POST['admite_maniobra_sabatica']??'')==='SI'?'selected':''?>>SI</option></select></label><label>Identidad gabinete MRL<input name="mrl_identidad" maxlength="40" value="<?=nmcE($_POST['mrl_identidad']??'AUTOMAC')?>" placeholder="AUTOMAC / CLEX / DANGELICA"></label><label style="grid-column:span 2">Observaciones<textarea name="observaciones" rows="2"><?=nmcE($_POST['observaciones']??'')?></textarea></label></div></section>
<section class="card"><h2>3. Tipos y subtipos permitidos</h2><p class="mini">Seleccioná exactamente qué combinaciones podrá usar este modelo. Si elegís una CPU base arriba, se muestran primero sus compatibilidades vigentes.</p><?php if(!$baseElegida):?><div class="warn">Elegí primero una matriz base para cargar sus combinaciones recomendadas.</div><?php else:?><div class="compat"><?php
$ya=array();$seleccionPost=isset($_POST['compat'])&&is_array($_POST['compat'])?array_flip($_POST['compat']):array();
foreach($compatBase as $r):$k=(int)$r['ctrltipo_id'].':'.(int)$r['ctrlsubtipo_id'];$ya[$k]=true;$check=$_SERVER['REQUEST_METHOD']==='POST'?isset($seleccionPost[$k]):true;?><label><input type="checkbox" name="compat[]" value="<?=nmcE($k)?>" <?=$check?'checked':''?>><span><strong><?=nmcE($r['ctrltipo_name'])?></strong><br><?=nmcE($r['ctrlsubtipo_name'])?><br><span class="badge">recomendada por <?=nmcE($baseNombre)?></span></span></label><?php endforeach;
foreach($tiposArr as $tid=>$tr): if(($tr['tipo_activo']??'SI')!=='SI')continue; foreach($subsArr as $sid=>$sr):$k=$tid.':'.$sid;if(isset($ya[$k]))continue;$check=isset($seleccionPost[$k]);?><label><input type="checkbox" name="compat[]" value="<?=nmcE($k)?>" <?=$check?'checked':''?>><span><strong><?=nmcE($tr['ctrltipo_name'])?></strong><br><?=nmcE($sr['ctrlsubtipo_name'])?><?php if(($tr['es_mrl']??'NO')==='SI'):?><br><span class="badge">MRL</span><?php endif;?></span></label><?php endforeach; endforeach;?></div><?php endif;?></section>
<section class="card"><h2>4. Matriz económica/técnica</h2><div class="grid2"><label style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #dde5ec;border-radius:9px"><input type="radio" name="crear_matriz_propia" value="NO" <?=($_POST['crear_matriz_propia']??'NO')!=='SI'?'checked':''?>><span><strong>Reutilizar matriz base</strong><br><span class="mini">El modelo usa la matriz seleccionada arriba. Ideal si técnicamente y económicamente es equivalente.</span></span></label><label style="display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #dde5ec;border-radius:9px"><input type="radio" name="crear_matriz_propia" value="SI" <?=($_POST['crear_matriz_propia']??'')==='SI'?'checked':''?>><span><strong>Crear matriz propia copiando la base</strong><br><span class="mini">Copia únicamente las filas de Tipo + Subtipo seleccionadas. Luego se pueden editar códigos, tensiones, potencias, contactores y térmicos desde Matriz principal.</span></span></label></div><?php if($baseElegida):?><p class="mini">La matriz base <?=nmcE($baseNombre)?> contiene configuraciones que se copiarán solo para las compatibilidades marcadas. Los códigos y precios no se recalculan: se copian exactamente como punto de partida.</p><?php endif;?></section>
<section class="card"><h2>5. Validación y creación</h2><div class="warn"><strong>Qué crea el asistente:</strong> el modelo en <code>cpus</code>, capacidades, compatibilidades y, si elegís “matriz propia”, una copia técnica/económica de las filas compatibles de la CPU base. No modifica históricos, Límites de paradas, cantidad física de límites ni Material de Hueco.</div><p><button class="btn" type="submit" <?=$baseElegida?'':'disabled'?>>Crear modelo de Control</button> <a class="btn sec" href="mantenimiento_control.php?tab=catalogos">Cancelar</a></p></section>
</form><?php endif;?></main></body></html>
