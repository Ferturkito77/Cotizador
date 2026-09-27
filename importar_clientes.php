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

function eic($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function redirigirImportacion($m,$t='ok'){$_SESSION['import_clientes_msg']=$m;$_SESSION['import_clientes_tipo']=$t;header('Location: importar_clientes.php');exit;}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='cargar') {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error']!==UPLOAD_ERR_OK) redirigirImportacion('Seleccione un archivo XLSX o CSV válido.','error');
    try {
        $tab=clientesLeerArchivoTabular($_FILES['archivo']['tmp_name'],$_FILES['archivo']['name']);
        $h=$tab['headers']; $rows=$tab['rows'];
        $map=array(
            'sigla'=>clientesBuscarColumna($h,array('Sigla')),
            'nombre'=>clientesBuscarColumna($h,array('Nombre','Nombre Fantasia','Nombre de fantasia')),
            'ranking'=>clientesBuscarColumna($h,array('Ranking')),
            'telefono'=>clientesBuscarColumna($h,array('Telefono','Teléfono')),
            'email'=>clientesBuscarColumna($h,array('Fax','Mail','Email','Correo')),
            'tipo'=>clientesBuscarColumna($h,array('Tipo Cliente','Tipo de Cliente')),
            'r1'=>clientesBuscarColumna($h,array('R1')),
            'r2'=>clientesBuscarColumna($h,array('R2')),
            'r3'=>clientesBuscarColumna($h,array('R3')),
            'id'=>clientesBuscarColumna($h,array('Id','ID')),
            'numero'=>clientesBuscarColumna($h,array('Numero','Número')),
        );
        if ($map['sigla']<0 || $map['nombre']<0) throw new RuntimeException('No se encontraron las columnas obligatorias Sigla y Nombre.');
        $procesadas=array(); $siglas=array(); $errores=array();
        foreach($rows as $i=>$r){
            $sigla=trim(clientesValorPorIndice($r,$map['sigla']));
            $nombre=trim(clientesValorPorIndice($r,$map['nombre']));
            if($sigla==='' && $nombre==='') continue;
            if($sigla==='' || $nombre===''){ $errores[]='Fila '.($i+2).': falta Sigla o Nombre.'; continue; }
            $key=strtoupper($sigla);
            if(isset($siglas[$key])){$errores[]='Sigla duplicada '.$sigla.' en filas '.($siglas[$key]+2).' y '.($i+2).'.';continue;}
            $siglas[$key]=$i;
            $procesadas[]=array(
                'sigla'=>$sigla,'nombre'=>$nombre,
                'ranking'=>$map['ranking']>=0?clientesNumeroEnteroONull(clientesValorPorIndice($r,$map['ranking'])):null,
                'telefono'=>$map['telefono']>=0?clientesTextoONull(clientesValorPorIndice($r,$map['telefono'])):null,
                'email'=>$map['email']>=0?clientesTextoONull(clientesValorPorIndice($r,$map['email'])):null,
                'socio_cecaf'=>$map['tipo']>=0?clientesTextoONull(clientesValorPorIndice($r,$map['tipo'])):null,
                'd1'=>$map['r1']>=0?clientesDescuentoAPorcentaje(clientesValorPorIndice($r,$map['r1'])):0,
                'd2'=>$map['r2']>=0?clientesDescuentoAPorcentaje(clientesValorPorIndice($r,$map['r2'])):0,
                'd3'=>$map['r3']>=0?clientesDescuentoAPorcentaje(clientesValorPorIndice($r,$map['r3'])):0,
                'origen_id'=>$map['id']>=0?clientesNumeroEnteroONull(clientesValorPorIndice($r,$map['id'])):null,
                'numero_bejerman'=>$map['numero']>=0?clientesNumeroEnteroONull(clientesValorPorIndice($r,$map['numero'])):null,
            );
        }
        if(!$procesadas) throw new RuntimeException('El archivo no contiene clientes utilizables.');
        $_SESSION['import_clientes_preview']=$procesadas;
        $_SESSION['import_clientes_archivo']=basename($_FILES['archivo']['name']);
        $_SESSION['import_clientes_errores']=$errores;
    } catch(Throwable $ex){redirigirImportacion($ex->getMessage(),'error');}
}

if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['accion']??'')==='aplicar') {
    $rows=$_SESSION['import_clientes_preview']??array();
    $archivo=$_SESSION['import_clientes_archivo']??'';
    if(!$rows) redirigirImportacion('No hay una importación preparada.','error');
    $conexion->begin_transaction();
    try{
        $sql="INSERT INTO clientes
            (clientes_codigo,clientes_nomfantasia,clientes_telefono,clientes_emails,clientes_categoria,clientes_d1,clientes_d2,clientes_d3,clientes_origen_id,clientes_numero_bejerman,clientes_socio_cecaf_numero,clientes_cecaf_ultima_actualizacion,clientes_habilitado)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,IF(? IS NULL,NULL,NOW()),'SI')
            ON DUPLICATE KEY UPDATE
              clientes_nomfantasia=VALUES(clientes_nomfantasia),
              clientes_telefono=VALUES(clientes_telefono),
              clientes_emails=VALUES(clientes_emails),
              clientes_categoria=VALUES(clientes_categoria),
              clientes_d1=VALUES(clientes_d1),clientes_d2=VALUES(clientes_d2),clientes_d3=VALUES(clientes_d3),
              clientes_origen_id=VALUES(clientes_origen_id),clientes_numero_bejerman=VALUES(clientes_numero_bejerman),
              clientes_cecaf_ultima_actualizacion=IF(NOT(clientes_socio_cecaf_numero <=> VALUES(clientes_socio_cecaf_numero)),NOW(),clientes_cecaf_ultima_actualizacion),
              clientes_socio_cecaf_numero=VALUES(clientes_socio_cecaf_numero)";
        $st=$conexion->prepare($sql); if(!$st) throw new RuntimeException($conexion->error);
        $altas=0;$actualizados=0;
        foreach($rows as $r){
            $sig=$r['sigla'];$nom=$r['nombre'];$tel=$r['telefono'];$mail=$r['email'];$rank=$r['ranking'];$d1=$r['d1'];$d2=$r['d2'];$d3=$r['d3'];$oid=$r['origen_id'];$num=$r['numero_bejerman'];$soc=$r['socio_cecaf'];$soc2=$soc;
            $st->bind_param('ssssidddiiss',$sig,$nom,$tel,$mail,$rank,$d1,$d2,$d3,$oid,$num,$soc,$soc2);
            if(!$st->execute()) throw new RuntimeException('Error importando '.$sig.': '.$st->error);
            if($st->affected_rows===1)$altas++; else $actualizados++;
        }
        $st->close();
        $usuario=(string)($_SESSION['usuario_nombre']??''); $cantidad=count($rows);
        $hist=$conexion->prepare("INSERT INTO clientes_importaciones(archivo,cantidad_registros,altas,actualizaciones,usuario) VALUES(?,?,?,?,?)");
        if($hist){$hist->bind_param('siiis',$archivo,$cantidad,$altas,$actualizados,$usuario);$hist->execute();$hist->close();}
        $conexion->commit();
        unset($_SESSION['import_clientes_preview'],$_SESSION['import_clientes_archivo'],$_SESSION['import_clientes_errores']);
        redirigirImportacion("Importación aplicada: {$cantidad} registros, {$altas} altas y {$actualizados} actualizaciones.");
    }catch(Throwable $ex){$conexion->rollback();redirigirImportacion('No se pudo aplicar la importación: '.$ex->getMessage(),'error');}
}

if (isset($_GET['cancelar'])) { unset($_SESSION['import_clientes_preview'],$_SESSION['import_clientes_archivo'],$_SESSION['import_clientes_errores']); redirigirImportacion('Importación descartada.'); }

$msg=$_SESSION['import_clientes_msg']??'';$tipo=$_SESSION['import_clientes_tipo']??'ok';unset($_SESSION['import_clientes_msg'],$_SESSION['import_clientes_tipo']);
$preview=$_SESSION['import_clientes_preview']??array();$archivo=$_SESSION['import_clientes_archivo']??'';$errores=$_SESSION['import_clientes_errores']??array();
?>
<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar clientes</title><link rel="stylesheet" href="automac-ui.css"><style>
body{background:#f4f7f9}.wrap{max-width:1320px;margin:18px auto}.card{background:#fff;border:1px solid #d9e3e8;border-radius:14px;padding:20px;margin-bottom:16px}.actions{display:flex;gap:10px;flex-wrap:wrap}.btn{display:inline-block;border:0;border-radius:8px;padding:10px 14px;background:#203746;color:#fff;text-decoration:none;font-weight:700;cursor:pointer}.btn.green{background:#087f4b}.btn.gray{background:#687985}.msg{padding:12px;border-radius:8px;margin-bottom:14px}.ok{background:#dff3e9;color:#145c3b}.error{background:#fde5e5;color:#8b2626}.warn{background:#fff4d6;color:#765000;padding:10px;border-radius:8px;margin:10px 0}.table-wrap{overflow:auto;max-height:520px}table{width:100%;border-collapse:collapse;font-size:12px}th,td{padding:8px;border-bottom:1px solid #e0e8ec;text-align:left;white-space:nowrap}th{background:#203746;color:#fff;position:sticky;top:0}.hint{color:#60727d;font-size:13px;line-height:1.5}
</style></head><body><?php require __DIR__.'/menu.php';?><main class="wrap"><div class="actions" style="margin-bottom:14px"><a class="btn gray" href="administrar_clientes.php">← Volver a clientes</a><a class="btn" href="actualizar_socios_cecaf.php">Actualizar socios CECAF</a></div>
<h1>Importar / actualizar clientes desde Bejerman</h1><?php if($msg):?><div class="msg <?=eic($tipo)?>"><?=eic($msg)?></div><?php endif;?>
<section class="card"><h2>Cargar exportación</h2><p class="hint">La importación usa <strong>Sigla</strong> como clave única. Si existe, actualiza sus datos; si no existe, crea el cliente. <strong>Numero</strong> se conserva como número de cliente Bejerman y puede repetirse. La columna histórica <strong>Fax</strong> se toma como email. <strong>Tipo Cliente</strong> se interpreta como número de socio CECAF. Los clientes que no aparecen en el archivo no se eliminan.</p><form method="post" enctype="multipart/form-data"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="cargar"><input type="file" name="archivo" accept=".xlsx,.csv" required> <button class="btn" type="submit">Preparar importación</button></form></section>
<?php if($preview):?><section class="card"><h2>Vista previa: <?=eic($archivo)?></h2><p><strong><?=count($preview)?></strong> registros listos.</p><?php if($errores):?><div class="warn"><strong>Observaciones:</strong><br><?=implode('<br>',array_map('eic',array_slice($errores,0,20)))?><?=count($errores)>20?'<br>… y '.(count($errores)-20).' más.':''?></div><?php endif;?><div class="table-wrap"><table><thead><tr><th>Sigla</th><th>Nombre</th><th>Nº Bejerman</th><th>Teléfono</th><th>Email</th><th>Ranking</th><th>CECAF</th><th>D1</th><th>D2</th><th>D3</th></tr></thead><tbody><?php foreach(array_slice($preview,0,300) as $r):?><tr><td><?=eic($r['sigla'])?></td><td><?=eic($r['nombre'])?></td><td><?=eic($r['numero_bejerman'])?></td><td><?=eic($r['telefono'])?></td><td><?=eic($r['email'])?></td><td><?=eic($r['ranking'])?></td><td><?=eic($r['socio_cecaf']?:'—')?></td><td><?=number_format((float)$r['d1'],2,',','.')?>%</td><td><?=number_format((float)$r['d2'],2,',','.')?>%</td><td><?=number_format((float)$r['d3'],2,',','.')?>%</td></tr><?php endforeach;?></tbody></table></div><?php if(count($preview)>300):?><p class="hint">Se muestran los primeros 300 registros; se importarán los <?=count($preview)?>.</p><?php endif;?><div class="actions" style="margin-top:14px"><form method="post" onsubmit="return confirm('¿Aplicar esta actualización de clientes?');"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="aplicar"><button class="btn green" type="submit">Aplicar actualización</button></form><a class="btn gray" href="importar_clientes.php?cancelar=1">Descartar</a></div></section><?php endif;?>
</main></body></html>
