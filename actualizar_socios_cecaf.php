<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
require_once 'clientes_excel.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function ec($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function redirCEC($m,$t='ok'){$_SESSION['cecaf_msg']=$m;$_SESSION['cecaf_tipo']=$t;header('Location: actualizar_socios_cecaf.php');exit;}

function cargarClientesCecaf(mysqli $conexion): array {
    $out=array();
    $rs=$conexion->query("SELECT clientes_id,clientes_codigo,clientes_nomfantasia,clientes_razonsocial,clientes_numero_bejerman,clientes_socio_cecaf_numero FROM clientes ORDER BY clientes_nomfantasia,clientes_codigo");
    if($rs) while($r=$rs->fetch_assoc()) $out[]=$r;
    return $out;
}
function construirIndicesCecaf(array $clientes): array {
    $sig=array();$nom=array();
    foreach($clientes as $c){
        $sig[strtoupper(trim((string)$c['clientes_codigo']))]=(int)$c['clientes_id'];
        foreach(array($c['clientes_nomfantasia'],$c['clientes_razonsocial']) as $n){
            $k=clientesNormalizarTexto($n);
            if($k==='') continue;
            if(!isset($nom[$k])) $nom[$k]=array();
            $nom[$k][]=(int)$c['clientes_id'];
        }
    }
    return array($sig,$nom);
}
function cargarAliasCecaf(mysqli $conexion): array {
    $out=array();
    $rs=$conexion->query("SELECT nombre_normalizado,cliente_id FROM clientes_cecaf_aliases");
    if($rs) while($r=$rs->fetch_assoc()) $out[(string)$r['nombre_normalizado']]=(int)$r['cliente_id'];
    return $out;
}

$clientes=cargarClientesCecaf($conexion);
list($indiceSigla,$indiceNombre)=construirIndicesCecaf($clientes);
$clientesPorId=array(); foreach($clientes as $c)$clientesPorId[(int)$c['clientes_id']]=$c;
$alias=cargarAliasCecaf($conexion);

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='cargar') {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK) redirCEC('Seleccione un archivo XLSX o CSV válido.','error');
    try{
        $tab=clientesLeerArchivoTabular($_FILES['archivo']['tmp_name'],$_FILES['archivo']['name']);
        if(!$tab['headers'] || !$tab['rows']) throw new RuntimeException('El archivo no contiene datos.');
        $_SESSION['cecaf_raw']=$tab;
        $_SESSION['cecaf_archivo']=basename($_FILES['archivo']['name']);
        $_SESSION['cecaf_step']='mapear';
    }catch(Throwable $ex){redirCEC($ex->getMessage(),'error');}
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='comparar') {
    $tab=$_SESSION['cecaf_raw']??null;
    if(!$tab) redirCEC('Primero cargue el listado CECAF.','error');
    $colSocio=(int)($_POST['col_socio']??-1);$colNombre=(int)($_POST['col_nombre']??-1);$colSigla=(int)($_POST['col_sigla']??-1);
    if($colSocio<0 || $colNombre<0) redirCEC('Seleccione las columnas Número de socio y Nombre.','error');
    $actuales=array();$vistos=array();
    foreach($tab['rows'] as $i=>$r){
        $num=trim(clientesValorPorIndice($r,$colSocio));$nom=trim(clientesValorPorIndice($r,$colNombre));$sig=$colSigla>=0?trim(clientesValorPorIndice($r,$colSigla)):'';
        if($num==='' && $nom==='' && $sig==='') continue;
        if($num==='' || $nom==='') continue;
        $cid=0;$metodo='';
        if($sig!=='' && isset($indiceSigla[strtoupper($sig)])){ $cid=$indiceSigla[strtoupper($sig)];$metodo='Sigla'; }
        if(!$cid){$nk=clientesNormalizarTexto($nom); if($nk!=='' && isset($alias[$nk])){$cid=$alias[$nk];$metodo='Vínculo guardado';}}
        if(!$cid){$nk=clientesNormalizarTexto($nom); if($nk!=='' && isset($indiceNombre[$nk]) && count(array_unique($indiceNombre[$nk]))===1){$cid=(int)$indiceNombre[$nk][0];$metodo='Nombre exacto';}}
        $actuales[]=array('fila'=>$i+2,'numero_socio'=>$num,'nombre_cecaf'=>$nom,'sigla_cecaf'=>$sig,'cliente_id'=>$cid,'metodo'=>$metodo);
        if($cid)$vistos[$cid]=true;
    }
    if(!$actuales) redirCEC('No se encontraron socios utilizables con esas columnas.','error');
    $_SESSION['cecaf_preview']=$actuales;$_SESSION['cecaf_step']='preview';
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='aplicar') {
    $preview=$_SESSION['cecaf_preview']??array(); if(!$preview) redirCEC('No hay una comparación preparada.','error');
    $manual=$_POST['manual_sigla']??array();$mapeados=array();$ignorados=0;$sinResolver=array();
    foreach($preview as $idx=>$r){
        $cid=(int)$r['cliente_id'];
        if(!$cid){
            $entrada=trim((string)($manual[$idx]??''));
            if(strtoupper($entrada)==='IGNORAR'){ $ignorados++; continue; }
            if($entrada!==''){
                $sigla=trim(explode(' — ',$entrada,2)[0]);
                $cid=$indiceSigla[strtoupper($sigla)]??0;
            }
        }
        if(!$cid){$sinResolver[]=$r['nombre_cecaf'];continue;}
        $mapeados[$cid]=array('numero_socio'=>$r['numero_socio'],'nombre_cecaf'=>$r['nombre_cecaf']);
    }
    if($sinResolver){redirCEC('Quedan socios sin vincular: '.implode(', ',array_slice($sinResolver,0,8)).(count($sinResolver)>8?'…':'').'. Vincúlelos o marque IGNORAR.','error');}
    $conexion->begin_transaction();
    try{
        $anteriores=array();$rs=$conexion->query("SELECT clientes_id,clientes_socio_cecaf_numero FROM clientes WHERE clientes_socio_cecaf_numero IS NOT NULL AND TRIM(clientes_socio_cecaf_numero)<>''");
        if($rs)while($r=$rs->fetch_assoc())$anteriores[(int)$r['clientes_id']]=(string)$r['clientes_socio_cecaf_numero'];
        $continuan=0;$nuevos=0;$cambiados=0;
        $st=$conexion->prepare("UPDATE clientes SET clientes_socio_cecaf_numero=?,clientes_cecaf_ultima_actualizacion=NOW() WHERE clientes_id=?");
        $aliasSt=$conexion->prepare("INSERT INTO clientes_cecaf_aliases(nombre_normalizado,nombre_cecaf,cliente_id,fecha_actualizacion) VALUES(?,?,?,NOW()) ON DUPLICATE KEY UPDATE nombre_cecaf=VALUES(nombre_cecaf),cliente_id=VALUES(cliente_id),fecha_actualizacion=NOW()");
        foreach($mapeados as $cid=>$info){
            $num=(string)$info['numero_socio'];$id=(int)$cid;$st->bind_param('si',$num,$id);if(!$st->execute())throw new RuntimeException($st->error);
            if(isset($anteriores[$id])){if((string)$anteriores[$id]===$num)$continuan++;else $cambiados++;}else $nuevos++;
            $norm=clientesNormalizarTexto($info['nombre_cecaf']);$nom=(string)$info['nombre_cecaf'];
            if($norm!=='' && $aliasSt){$aliasSt->bind_param('ssi',$norm,$nom,$id);$aliasSt->execute();}
        }
        if($st)$st->close();if($aliasSt)$aliasSt->close();
        $retirados=0;
        foreach($anteriores as $cid=>$num){
            if(isset($mapeados[$cid]))continue;
            $id=(int)$cid;$cl=$conexion->prepare("UPDATE clientes SET clientes_socio_cecaf_numero=NULL,clientes_cecaf_ultima_actualizacion=NOW() WHERE clientes_id=?");$cl->bind_param('i',$id);$cl->execute();$cl->close();$retirados++;
        }
        $archivo=(string)($_SESSION['cecaf_archivo']??'');$usuario=(string)($_SESSION['usuario_nombre']??'');$total=count($mapeados);
        $hist=$conexion->prepare("INSERT INTO clientes_cecaf_importaciones(archivo,socios_archivo,continuan,nuevos,numero_cambiado,dejaron_ser_socios,ignorados,usuario) VALUES(?,?,?,?,?,?,?,?)");
        if($hist){$hist->bind_param('siiiiiis',$archivo,$total,$continuan,$nuevos,$cambiados,$retirados,$ignorados,$usuario);$hist->execute();$hist->close();}
        $conexion->commit();
        unset($_SESSION['cecaf_raw'],$_SESSION['cecaf_preview'],$_SESSION['cecaf_archivo'],$_SESSION['cecaf_step']);
        redirCEC("CECAF actualizado: {$continuan} continúan, {$nuevos} nuevos, {$cambiados} cambiaron número y {$retirados} dejaron de figurar como socios.".($ignorados?" {$ignorados} filas fueron ignoradas.":''));
    }catch(Throwable $ex){$conexion->rollback();redirCEC('No se pudo aplicar la actualización CECAF: '.$ex->getMessage(),'error');}
}

if(isset($_GET['cancelar'])){unset($_SESSION['cecaf_raw'],$_SESSION['cecaf_preview'],$_SESSION['cecaf_archivo'],$_SESSION['cecaf_step']);redirCEC('Actualización CECAF descartada.');}
$msg=$_SESSION['cecaf_msg']??'';$tipo=$_SESSION['cecaf_tipo']??'ok';unset($_SESSION['cecaf_msg'],$_SESSION['cecaf_tipo']);$step=$_SESSION['cecaf_step']??'';$raw=$_SESSION['cecaf_raw']??null;$preview=$_SESSION['cecaf_preview']??array();$archivo=$_SESSION['cecaf_archivo']??'';
$autoSocio=$raw?clientesBuscarColumna($raw['headers'],array('Numero Socio','Nro Socio','Socio','Numero de socio','N° Socio')):-1;$autoNombre=$raw?clientesBuscarColumna($raw['headers'],array('Nombre','Razon Social','Razón Social','Empresa')):-1;$autoSigla=$raw?clientesBuscarColumna($raw['headers'],array('Sigla')):-1;
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Actualizar socios CECAF</title><link rel="stylesheet" href="automac-ui.css"><style>
body{background:#f4f7f9}.wrap{max-width:1360px;margin:18px auto}.card{background:#fff;border:1px solid #d9e3e8;border-radius:14px;padding:20px;margin-bottom:16px}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;background:#203746;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.green{background:#087f4b}.gray{background:#687985}.msg{padding:12px;border-radius:8px;margin-bottom:14px}.ok{background:#dff3e9;color:#145c3b}.error{background:#fde5e5;color:#8b2626}.hint{font-size:13px;color:#60727d;line-height:1.55}.grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.campo label{display:block;font-size:12px;font-weight:700;margin-bottom:4px}.campo select,.campo input{width:100%;padding:9px;border:1px solid #b8c7cf;border-radius:7px}.table-wrap{overflow:auto;max-height:560px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px;border-bottom:1px solid #e0e8ec;text-align:left;vertical-align:top}th{background:#203746;color:#fff;position:sticky;top:0}.badge{display:inline-block;padding:4px 7px;border-radius:999px;font-size:11px;font-weight:700}.b-ok{background:#dff3e9;color:#12623d}.b-new{background:#e6f0ff;color:#205b9f}.b-review{background:#fff0cc;color:#7a5100}@media(max-width:800px){.grid{grid-template-columns:1fr}}
</style></head><body><?php require __DIR__.'/menu.php';?><main class="wrap"><div class="actions" style="margin-bottom:14px"><a class="btn gray" href="administrar_clientes.php">← Volver a clientes</a><a class="btn" href="importar_clientes.php">Importar clientes Bejerman</a></div><h1>Actualizar socios CECAF</h1><?php if($msg):?><div class="msg <?=ec($tipo)?>"><?=ec($msg)?></div><?php endif;?>
<section class="card"><h2>1. Cargar listado actual</h2><p class="hint">Use el Excel convertido desde el PDF de CECAF. El archivo debe representar <strong>solo los socios actuales</strong>. El sistema compara el listado con la base de clientes: agrega el número de socio a quienes aparecen y quita la condición CECAF a quienes figuraban antes pero ya no aparecen. No se elimina ningún cliente.</p><form method="post" enctype="multipart/form-data"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="cargar"><input type="file" name="archivo" accept=".xlsx,.csv" required> <button class="btn" type="submit">Leer listado CECAF</button></form></section>
<?php if($step==='mapear' && $raw):?><section class="card"><h2>2. Indicar columnas de <?=ec($archivo)?></h2><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="comparar"><div class="grid"><div class="campo"><label>Número de socio *</label><select name="col_socio" required><option value="-1">Seleccione...</option><?php foreach($raw['headers'] as $i=>$h):?><option value="<?=$i?>"<?=$i===$autoSocio?' selected':''?>><?=ec($h?:'Columna '.($i+1))?></option><?php endforeach;?></select></div><div class="campo"><label>Nombre / empresa *</label><select name="col_nombre" required><option value="-1">Seleccione...</option><?php foreach($raw['headers'] as $i=>$h):?><option value="<?=$i?>"<?=$i===$autoNombre?' selected':''?>><?=ec($h?:'Columna '.($i+1))?></option><?php endforeach;?></select></div><div class="campo"><label>Sigla (opcional)</label><select name="col_sigla"><option value="-1">El archivo no tiene Sigla</option><?php foreach($raw['headers'] as $i=>$h):?><option value="<?=$i?>"<?=$i===$autoSigla?' selected':''?>><?=ec($h?:'Columna '.($i+1))?></option><?php endforeach;?></select></div></div><div class="actions" style="margin-top:14px"><button class="btn green" type="submit">Comparar con clientes</button><a class="btn gray" href="actualizar_socios_cecaf.php?cancelar=1">Cancelar</a></div></form></section><?php endif;?>
<?php if($step==='preview' && $preview):$anteriores=array_filter($clientes, function($c){ return trim((string)$c['clientes_socio_cecaf_numero'])!==''; });$autoIds=array();foreach($preview as $r)if($r['cliente_id'])$autoIds[(int)$r['cliente_id']]=true;$dejan=0;foreach($anteriores as $c)if(!isset($autoIds[(int)$c['clientes_id']]))$dejan++;?><section class="card"><h2>3. Comparación</h2><p class="hint">Listado: <strong><?=count($preview)?></strong> socios. Vínculos automáticos: <strong><?=count($autoIds)?></strong>. Socios anteriores que dejarían de figurar si se aplica esta comparación: <strong><?=$dejan?></strong>. Las filas sin coincidencia deben vincularse manualmente o marcarse como IGNORAR antes de aplicar.</p><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="aplicar"><datalist id="lista_clientes_cecaf"><option value="IGNORAR"><?php foreach($clientes as $c):?><option value="<?=ec($c['clientes_codigo'].' — '.$c['clientes_nomfantasia'])?>"><?php endforeach;?></datalist><div class="table-wrap"><table><thead><tr><th>Nº socio</th><th>Nombre CECAF</th><th>Cliente Automac</th><th>Estado</th><th>Vincular si hace falta</th></tr></thead><tbody><?php foreach($preview as $i=>$r):$cid=(int)$r['cliente_id'];$cli=$cid?($clientesPorId[$cid]??null):null;?><tr><td><strong><?=ec($r['numero_socio'])?></strong></td><td><?=ec($r['nombre_cecaf'])?></td><td><?=$cli?ec($cli['clientes_codigo'].' — '.$cli['clientes_nomfantasia']):'—'?></td><td><?php if($cli):$antes=trim((string)$cli['clientes_socio_cecaf_numero']);?><span class="badge <?=$antes!==''?'b-ok':'b-new'?>"><?=$antes!==''?'Continúa / actualizar':'Nuevo socio'?> · <?=ec($r['metodo'])?></span><?php else:?><span class="badge b-review">Revisar</span><?php endif;?></td><td><?php if(!$cli):?><input list="lista_clientes_cecaf" name="manual_sigla[<?=$i?>]" placeholder="Escriba sigla/nombre o IGNORAR" required style="min-width:280px"><?php else:?><input type="hidden" name="manual_sigla[<?=$i?>]" value=""><?php endif;?></td></tr><?php endforeach;?></tbody></table></div><div class="actions" style="margin-top:14px"><button class="btn green" type="submit" onclick="return confirm('¿Aplicar esta actualización CECAF? Los clientes que eran socios y ya no aparecen dejarán de figurar como socios.');">Aplicar actualización CECAF</button><a class="btn gray" href="actualizar_socios_cecaf.php?cancelar=1">Descartar</a></div></form></section><?php endif;?>
</main></body></html>
