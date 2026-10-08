<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
function cpE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
$mensaje='';$tipo='ok';
if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    $accion=(string)($_POST['accion']??'');
    if($accion==='guardar'){
      $id=(int)($_POST['id']??0);$codigo=preg_replace('/\D+/','',(string)($_POST['codigo']??''));$codigo=str_pad($codigo,3,'0',STR_PAD_LEFT);
      $desc=trim((string)($_POST['descripcion']??''));$activo=!empty($_POST['activo'])?1:0;
      if(strlen($codigo)!==3||$desc==='')throw new RuntimeException('Código Bejerman y descripción son obligatorios.');
      if($id>0){$st=$conexion->prepare('UPDATE condiciones_pago SET codigo_bejerman=?,descripcion=?,activo=? WHERE condicion_pago_id=?');$st->bind_param('ssii',$codigo,$desc,$activo,$id);}else{$st=$conexion->prepare('INSERT INTO condiciones_pago(codigo_bejerman,descripcion,activo) VALUES(?,?,?)');$st->bind_param('ssi',$codigo,$desc,$activo);}
      if(!$st->execute())throw new RuntimeException($st->error);$st->close();$mensaje='Condición guardada.';
    }
  }catch(Throwable $e){$mensaje=$e->getMessage();$tipo='error';}
}
$filas=$conexion->query('SELECT * FROM condiciones_pago ORDER BY codigo_bejerman');
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Condiciones de pago</title><link rel="stylesheet" href="automac-ui.css"><style>
.wrap{max-width:1200px;margin:20px auto;padding:0 18px 60px}.hero,.card{background:#fff;border:1px solid #dde5ea;border-radius:14px;padding:18px;margin-bottom:14px}.hero h1{margin:0 0 6px}.hero p{margin:0;color:#64748b}.msg{padding:11px 13px;border-radius:9px;margin:12px 0}.ok{background:#e8f7ef;color:#17603a}.error{background:#fdecec;color:#8f2020}.row{display:grid;grid-template-columns:110px 1fr 90px 110px;gap:9px;align-items:center;margin:8px 0}.row input[type=text]{height:40px;border:1px solid #cad5e1;border-radius:8px;padding:8px}.btn{border:0;border-radius:8px;padding:9px 12px;background:#0d6efd;color:#fff;font-weight:800;cursor:pointer}.new{background:#f8fafc;padding:12px;border-radius:10px;margin-bottom:14px}@media(max-width:760px){.row{grid-template-columns:1fr}.row>*{width:100%}}</style></head><body><?php require 'menu.php';?><main class="wrap"><section class="hero"><h1>Condiciones de pago</h1><p>El código de tres dígitos es el código Bejerman de la forma de pago. Se selecciona al pasar un pedido a Producción.</p></section><?php if($mensaje):?><div class="msg <?=cpE($tipo)?>"><?=cpE($mensaje)?></div><?php endif;?><section class="card"><form method="post" class="row new"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar"><input name="codigo" placeholder="010" maxlength="3" required><input name="descripcion" placeholder="Descripción de la condición" required><label><input type="checkbox" name="activo" checked> Activa</label><button class="btn">AGREGAR</button></form><?php if($filas):while($x=$filas->fetch_assoc()):?><form method="post" class="row"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" value="<?=(int)$x['condicion_pago_id']?>"><input name="codigo" maxlength="3" value="<?=cpE($x['codigo_bejerman'])?>" required><input name="descripcion" value="<?=cpE($x['descripcion'])?>" required><label><input type="checkbox" name="activo"<?=$x['activo']?' checked':''?>> Activa</label><button class="btn">GUARDAR</button></form><?php endwhile;endif;?></section></main></body></html>
