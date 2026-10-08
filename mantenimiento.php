<?php
require_once 'auth.php';
require_once 'conexion.php';
exigirRoles(array('ADMINISTRADOR'));
function mmE($v){return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function mmTableExists(mysqli $c,string $t): bool { $st=$c->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1"); if(!$st)return false; $st->bind_param('s',$t);$st->execute();$r=$st->get_result();$ok=$r&&$r->num_rows>0;$st->close();return $ok; }
function mmCount(mysqli $c,string $t,string $where='1=1'): int { if(!mmTableExists($c,$t))return 0; $r=$c->query("SELECT COUNT(*) c FROM `".$c->real_escape_string($t)."` WHERE $where"); return $r?(int)$r->fetch_assoc()['c']:0; }
$stats=array(
 'control'=>mmCount($conexion,'matriz_calculos'),
 'senal'=>mmCount($conexion,'matriz_botoneras_cabina'),
 'acc'=>mmCount($conexion,'accesorios_catalogo'),
 'rep'=>mmCount($conexion,'productos_repuestos'),
 'plantillas'=>mmCount($conexion,'plantillas_controles'),
 'plantillas_senal'=>mmCount($conexion,'plantillas_senalizacion'),
 'usuarios'=>mmCount($conexion,'usuarios'),
 'limreg'=>mmCount($conexion,'limites_cantidad_reglas'),
);
?><!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Mantenimiento · Automac</title><link rel="stylesheet" href="automac-ui.css"><style>
.main{padding:24px;max-width:1580px;margin:auto}.hero{background:#fff;border:1px solid #dfe5ec;border-radius:14px;padding:20px;margin-bottom:18px}.hero h1{margin:0 0 5px;font-size:28px}.hero p{margin:0;color:#64748b}.section-title{margin:24px 0 10px;font-size:14px;text-transform:uppercase;letter-spacing:.08em;color:#526474}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(285px,1fr));gap:14px}.card{background:#fff;border:1px solid #dfe5ec;border-radius:12px;padding:16px}.card h2{font-size:18px;margin:0 0 7px}.card p{color:#64748b;min-height:45px;line-height:1.45}.actions{display:flex;gap:8px;flex-wrap:wrap}.btn{display:inline-block;text-decoration:none;border-radius:8px;padding:9px 12px;background:#0f5f9c;color:#fff;font-weight:700}.btn.sec{background:#eef4f8;color:#234}.tag{float:right;background:#edf6f1;color:#17603a;border-radius:999px;padding:4px 8px;font-weight:700;font-size:12px}.tag.warn{background:#fff4d9;color:#805900}.note{margin-top:18px;padding:14px;border:1px solid #eed9a6;background:#fff9e8;border-radius:10px;color:#6d5415}.fixed{border-left:4px solid #0f5f9c}.matrix{border-left:4px solid #138a54}.system{border-left:4px solid #7b4bb7}.audit{border-left:4px solid #b66a00}
</style></head><body><?php require 'menu.php'; ?><main class="main"><section class="hero"><h1>Centro de Mantenimiento</h1><p>Configuración técnica y comercial del cotizador. Alta, edición y control de matrices sin reescribir cotizaciones, pedidos, revisiones ni órdenes históricas.</p></section>
<h3 class="section-title">Datos maestros</h3><div class="grid">
<section class="card fixed"><h2>Clientes</h2><p>Alta, modificación y mantenimiento del padrón de clientes.</p><div class="actions"><a class="btn" href="administrar_clientes.php">Abrir</a></div></section>
<section class="card fixed"><h2>Base Bejerman</h2><p>Listas, productos, costos, actualizaciones y estado de códigos comerciales.</p><div class="actions"><a class="btn" href="actualizar_precios.php">Abrir</a></div></section>
</div>
<h3 class="section-title">Matrices del cotizador</h3><div class="grid">
<section class="card matrix"><span class="tag"><?=$stats['control']?> reglas</span><h2>Matrices de Control</h2><p>Matriz principal, CPUs, tipos/subtipos, potencias, contactores, térmicos, baterías, rescates, encoder, puertas, comunicación, adicionales y dependencias.</p><div class="actions"><a class="btn" href="mantenimiento_control.php">Abrir</a></div></section>
<section class="card matrix"><span class="tag"><?=$stats['senal']?> bases</span><h2>Matrices de Señalización</h2><p>Botoneras, pulsadores exteriores, indicadores, adicionales, accesibilidad, modelos, colores, teclas, bornes, tensiones y códigos.</p><div class="actions"><a class="btn" href="mantenimiento_senalizacion.php">Abrir</a></div></section>
<section class="card matrix"><span class="tag"><?=$stats['acc']?> ítems</span><h2>Matrices de Accesorios</h2><p>Catálogo, límites, barreras, pesadores, supervisor, control de accesos, costos/utilidad y reglas automáticas.</p><div class="actions"><a class="btn" href="mantenimiento_accesorios.php">Abrir</a></div></section>
<section class="card matrix"><span class="tag"><?=$stats['rep']?> productos</span><h2>Matrices de Repuestos</h2><p>Catálogo, categorías, costo/utilidad, equivalencias y reglas de integración con Control, Señalización y Accesorios.</p><div class="actions"><a class="btn" href="mantenimiento_repuestos.php">Abrir</a></div></section>
<section class="card matrix"><span class="tag warn">Pendiente definir</span><h2>Matrices de IEP</h2><p>Espacio reservado hasta definir las matrices técnicas propias de IEP. No se inventan reglas.</p><div class="actions"><a class="btn" href="mantenimiento_iep.php">Abrir</a></div></section>
</div>
<h3 class="section-title">Reglas técnicas específicas</h3><div class="grid">
<section class="card fixed"><h2>Material de hueco</h2><p>Configuraciones, materiales y reglas de orden de fabricación. Independiente del límite máximo de paradas.</p><div class="actions"><a class="btn" href="administrar_material_hueco.php">Abrir</a></div></section>
<section class="card fixed"><h2>Límites de paradas</h2><p>Máximo técnico de paradas permitido por Control. No calcula la cantidad física de límites.</p><div class="actions"><a class="btn" href="limites_paradas.php">Abrir</a></div></section>
<section class="card fixed"><span class="tag"><?=$stats['limreg']?> reglas</span><h2>Cantidad de límites</h2><p>Reglas físicas de límites de Accesorios: subtipo/tipo, contactor de potencial, velocidad y retorno por batería cuando corresponde.</p><div class="actions"><a class="btn" href="mantenimiento_automatizaciones.php#limites">Ver reglas</a></div></section>
<section class="card fixed"><span class="tag"><?=$stats['plantillas']?> plantillas</span><h2>Plantillas de Control</h2><p>Configuraciones reutilizables para acelerar nuevas cotizaciones.</p><div class="actions"><a class="btn" href="administrar_plantillas.php">Abrir</a></div></section>
<section class="card fixed"><span class="tag"><?=$stats['plantillas_senal']?> plantillas</span><h2>Plantillas de Señalización</h2><p>Combinaciones reutilizables de Módulos, Puerta, Pulsador, Color, Tecla, Tensión y Bornes para Botonera de cabina.</p><div class="actions"><a class="btn" href="administrar_plantillas_senalizacion.php">Abrir</a></div></section>
</div>
<h3 class="section-title">Integraciones, diagnóstico y sistema</h3><div class="grid">
<section class="card system"><h2>Relaciones y Automatizaciones</h2><p>Mapa transversal de reglas entre módulos: selección automática, cantidades, equivalencias, sugerencias e integraciones futuras.</p><div class="actions"><a class="btn" href="mantenimiento_automatizaciones.php">Abrir</a></div></section>
<section class="card audit"><h2>Diagnóstico de integridad</h2><p>Chequeo de documentos huérfanos, numeración, clientes, CRM, precios, matrices y estructura de base.</p><div class="actions"><a class="btn" href="diagnostico_integridad.php">Integridad general</a><a class="btn sec" href="diagnostico_rendimiento.php">Rendimiento</a><a class="btn sec" href="diagnostico_errores.php">Errores</a><a class="btn sec" href="diagnostico_catalogos.php">Catálogos</a><a class="btn sec" href="diagnostico_depuracion.php">Depuración</a></div></section>
<section class="card system"><span class="tag"><?=$stats['usuarios']?> usuarios</span><h2>Administración del Sistema</h2><p>Usuarios, roles, numeración, documentos, parámetros generales, condiciones comerciales y estado de versión.</p><div class="actions"><a class="btn" href="mantenimiento_administracion.php">Abrir</a></div></section>
</div><div class="note"><strong>Política de integridad:</strong> primero detectar y documentar dependencias; después desactivar/archivar; borrar únicamente con respaldo y confirmación. Las tablas y archivos legacy no se eliminan automáticamente.</div></main></body></html>
