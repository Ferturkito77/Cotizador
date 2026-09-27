<?php
session_start();
include 'conexion.php';
require_once 'auth.php';
asegurarSistemaUsuarios($conexion);
exigirRoles(array('ADMINISTRADOR','COMERCIAL'));
if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST') {
    automacValidarCsrf(true);
}
$conexion->set_charset('utf8mb4');

function e($valor) {
    return htmlspecialchars((string)$valor, ENT_QUOTES, 'UTF-8');
}

function redirigirClientes($mensaje, $tipo = 'ok', $id = 0) {
    $_SESSION['clientes_mensaje'] = $mensaje;
    $_SESSION['clientes_tipo'] = $tipo;
    $destino = 'administrar_clientes.php';
    if ($id > 0) {
        $destino .= '?editar=' . (int)$id . '#formulario-cliente';
    }
    header('Location: ' . $destino);
    exit;
}

function decimalCliente($nombre) {
    if (!isset($_POST[$nombre]) || trim($_POST[$nombre]) === '') {
        return 0.0;
    }
    $valor = str_replace(',', '.', trim($_POST[$nombre]));
    return is_numeric($valor) ? (float)$valor : false;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $accion = isset($_POST['accion']) ? $_POST['accion'] : '';

    if ($accion === 'guardar' || $accion === 'actualizar') {
        $id = isset($_POST['clientes_id']) ? (int)$_POST['clientes_id'] : 0;
        $codigo = isset($_POST['clientes_codigo']) ? trim($_POST['clientes_codigo']) : '';
        $fantasia = isset($_POST['clientes_nomfantasia']) ? trim($_POST['clientes_nomfantasia']) : '';
        $razon = isset($_POST['clientes_razonsocial']) ? trim($_POST['clientes_razonsocial']) : '';
        $telefono = isset($_POST['clientes_telefono']) ? trim($_POST['clientes_telefono']) : '';
        $email = isset($_POST['clientes_emails']) ? trim($_POST['clientes_emails']) : '';
        $numeroBejerman = isset($_POST['clientes_numero_bejerman']) && trim($_POST['clientes_numero_bejerman']) !== '' ? (int)$_POST['clientes_numero_bejerman'] : null;
        $ranking = isset($_POST['clientes_categoria']) && trim($_POST['clientes_categoria']) !== '' ? (int)$_POST['clientes_categoria'] : null;
        $socioCecaf = isset($_POST['clientes_socio_cecaf_numero']) ? trim($_POST['clientes_socio_cecaf_numero']) : '';
        if ($socioCecaf === '') $socioCecaf = null;
        $d1 = decimalCliente('clientes_d1');
        $d2 = decimalCliente('clientes_d2');
        $d3 = decimalCliente('clientes_d3');
        $habilitado = isset($_POST['clientes_habilitado']) && $_POST['clientes_habilitado'] === 'NO' ? 'NO' : 'SI';

        if ($codigo === '' || $fantasia === '') {
            redirigirClientes('El código y el nombre de fantasía son obligatorios.', 'error', $id);
        }
        if ($d1 === false || $d2 === false || $d3 === false || $d1 < 0 || $d2 < 0 || $d3 < 0 || $d1 > 100 || $d2 > 100 || $d3 > 100) {
            redirigirClientes('Los descuentos deben ser números entre 0 y 100.', 'error', $id);
        }

        if ($accion === 'guardar') {
            $stmt = $conexion->prepare("INSERT INTO clientes (clientes_codigo, clientes_nomfantasia, clientes_razonsocial, clientes_telefono, clientes_emails, clientes_numero_bejerman, clientes_categoria, clientes_socio_cecaf_numero, clientes_cecaf_ultima_actualizacion, clientes_d1, clientes_d2, clientes_d3, clientes_habilitado) VALUES (?, ?, ?, ?, ?, ?, ?, ?, IF(? IS NULL,NULL,NOW()), ?, ?, ?, ?)");
            if (!$stmt) {
                redirigirClientes('No se pudo preparar el alta: ' . $conexion->error, 'error');
            }
            $socioCecaf2 = $socioCecaf;
            $stmt->bind_param('sssssiissddds', $codigo, $fantasia, $razon, $telefono, $email, $numeroBejerman, $ranking, $socioCecaf, $socioCecaf2, $d1, $d2, $d3, $habilitado);
            if (!$stmt->execute()) {
                $error = $stmt->errno === 1062 ? 'Ya existe un cliente con ese código.' : $stmt->error;
                $stmt->close();
                redirigirClientes($error, 'error');
            }
            $stmt->close();
            redirigirClientes('Cliente agregado correctamente.');
        }

        if ($id <= 0) {
            redirigirClientes('No se recibió un cliente válido para modificar.', 'error');
        }
        $stmt = $conexion->prepare("UPDATE clientes SET clientes_codigo=?, clientes_nomfantasia=?, clientes_razonsocial=?, clientes_telefono=?, clientes_emails=?, clientes_numero_bejerman=?, clientes_categoria=?, clientes_socio_cecaf_numero=?, clientes_cecaf_ultima_actualizacion=IF(NOT(clientes_socio_cecaf_numero <=> ?),NOW(),clientes_cecaf_ultima_actualizacion), clientes_d1=?, clientes_d2=?, clientes_d3=?, clientes_habilitado=? WHERE clientes_id=? LIMIT 1");
        if (!$stmt) {
            redirigirClientes('No se pudo preparar la modificación: ' . $conexion->error, 'error', $id);
        }
        $socioCecaf2 = $socioCecaf;
        $stmt->bind_param('sssssiissdddsi', $codigo, $fantasia, $razon, $telefono, $email, $numeroBejerman, $ranking, $socioCecaf, $socioCecaf2, $d1, $d2, $d3, $habilitado, $id);
        if (!$stmt->execute()) {
            $error = $stmt->errno === 1062 ? 'Ya existe otro cliente con ese código.' : $stmt->error;
            $stmt->close();
            redirigirClientes($error, 'error', $id);
        }
        $stmt->close();
        redirigirClientes('Cliente modificado correctamente.');
    }

    if ($accion === 'cambiar_estado') {
        $id = isset($_POST['clientes_id']) ? (int)$_POST['clientes_id'] : 0;
        $estado = isset($_POST['clientes_habilitado']) && $_POST['clientes_habilitado'] === 'SI' ? 'SI' : 'NO';
        if ($id <= 0) {
            redirigirClientes('Cliente inválido.', 'error');
        }
        $stmt = $conexion->prepare("UPDATE clientes SET clientes_habilitado=? WHERE clientes_id=? LIMIT 1");
        if (!$stmt) {
            redirigirClientes('No se pudo preparar el cambio de estado: ' . $conexion->error, 'error');
        }
        $stmt->bind_param('si', $estado, $id);
        if (!$stmt->execute()) {
            $error = $stmt->error;
            $stmt->close();
            redirigirClientes('No se pudo cambiar el estado: ' . $error, 'error');
        }
        $stmt->close();
        redirigirClientes($estado === 'SI' ? 'Cliente habilitado.' : 'Cliente deshabilitado.');
    }
}

$editar = null;
$idEditar = filter_input(INPUT_GET, 'editar', FILTER_VALIDATE_INT);
if ($idEditar) {
    $stmt = $conexion->prepare("SELECT clientes_id, clientes_codigo, clientes_nomfantasia, clientes_razonsocial, clientes_telefono, clientes_emails, clientes_numero_bejerman, clientes_categoria, clientes_socio_cecaf_numero, clientes_d1, clientes_d2, clientes_d3, clientes_habilitado FROM clientes WHERE clientes_id=? LIMIT 1");
    if ($stmt) {
        $stmt->bind_param('i', $idEditar);
        $stmt->execute();
        $editar = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
}

$buscar = isset($_GET['buscar']) ? trim($_GET['buscar']) : '';
$estadoFiltro = isset($_GET['estado']) ? trim($_GET['estado']) : 'TODOS';
$where = array();
$tipos = '';
$valores = array();
if ($buscar !== '') {
    $where[] = "(clientes_codigo LIKE ? OR clientes_nomfantasia LIKE ? OR clientes_razonsocial LIKE ? OR clientes_telefono LIKE ? OR clientes_emails LIKE ? OR CAST(clientes_numero_bejerman AS CHAR) LIKE ? OR clientes_socio_cecaf_numero LIKE ?)";
    $patron = '%' . $buscar . '%';
    $tipos .= 'sssssss';
    for ($i=0;$i<7;$i++) $valores[] = $patron;
}
if ($estadoFiltro === 'SI' || $estadoFiltro === 'NO') {
    $where[] = 'clientes_habilitado = ?';
    $tipos .= 's';
    $valores[] = $estadoFiltro;
}
$sql = "SELECT clientes_id, clientes_codigo, clientes_nomfantasia, clientes_razonsocial, clientes_telefono, clientes_emails, clientes_numero_bejerman, clientes_categoria, clientes_socio_cecaf_numero, clientes_d1, clientes_d2, clientes_d3, clientes_habilitado FROM clientes";
if ($where) { $sql .= ' WHERE ' . implode(' AND ', $where); }
$sql .= ' ORDER BY clientes_nomfantasia, clientes_codigo';
$stmt = $conexion->prepare($sql);
$clientes = null;
if ($stmt) {
    if ($tipos !== '') {
        $refs = array();
        $refs[] = &$tipos;
        foreach ($valores as $k => $v) { $refs[] = &$valores[$k]; }
        call_user_func_array(array($stmt, 'bind_param'), $refs);
    }
    $stmt->execute();
    $clientes = $stmt->get_result();
}

$mensaje = isset($_SESSION['clientes_mensaje']) ? $_SESSION['clientes_mensaje'] : '';
$tipoMensaje = isset($_SESSION['clientes_tipo']) ? $_SESSION['clientes_tipo'] : 'ok';
unset($_SESSION['clientes_mensaje'], $_SESSION['clientes_tipo']);
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Administración de clientes</title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;background:#f4f4f9;color:#202124;margin:18px}.contenedor{max-width:1450px;margin:auto}.menu-principal{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}.menu-principal a{padding:9px 13px;border-radius:5px;text-decoration:none;font-size:13px;font-weight:bold;background:#343a40;color:#fff}.menu-principal a.activo{background:#0d6efd}.menu-principal a:hover{opacity:.88}.caja{background:#fff;border:1px solid #ddd;border-radius:8px;padding:18px;margin-bottom:16px;box-shadow:0 2px 8px rgba(0,0,0,.06)}h1,h2{margin-top:0}.mensaje{padding:11px;border-radius:5px;margin-bottom:14px}.ok{background:#d1e7dd;color:#0f5132}.error{background:#f8d7da;color:#842029}.form-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.campo label{display:block;font-size:12px;font-weight:bold;margin-bottom:4px}.campo input,.campo select{width:100%;height:34px;padding:5px 8px;border:1px solid #9aa0a6;border-radius:4px}.ancho{grid-column:span 2}.acciones-form{grid-column:1/-1;display:flex;gap:8px;flex-wrap:wrap}.boton,button{border:0;border-radius:5px;padding:9px 13px;font-weight:bold;text-decoration:none;cursor:pointer;font-size:12px}.primario{background:#0d6efd;color:#fff}.verde{background:#198754;color:#fff}.rojo{background:#dc3545;color:#fff}.gris{background:#6c757d;color:#fff}.filtros{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.filtros .campo{min-width:180px}.tabla-wrap{overflow:auto}table{width:100%;border-collapse:collapse;font-size:12px}th,td{border-bottom:1px solid #ddd;padding:8px;text-align:left;white-space:nowrap}th{background:#f1f3f5}.acciones{display:flex;gap:6px}.estado-si{color:#157347;font-weight:bold}.estado-no{color:#b02a37;font-weight:bold}.badge-cecaf{display:inline-block;background:#dff3e9;color:#12623d;border:1px solid #a8ddc2;border-radius:999px;padding:3px 7px;font-weight:bold}.acciones-superiores{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px}@media(max-width:800px){.form-grid{grid-template-columns:1fr 1fr}.ancho{grid-column:span 2}}@media(max-width:520px){.form-grid{grid-template-columns:1fr}.ancho{grid-column:span 1}}
</style>
</head>
<body>
<div class="contenedor">
<?php require __DIR__ . '/menu.php'; ?>
<h1>Administración de clientes</h1>
<div class="acciones-superiores"><a class="boton primario" href="importar_clientes.php">Importar / actualizar desde Bejerman</a><a class="boton verde" href="actualizar_socios_cecaf.php">Actualizar socios CECAF</a></div>
<?php if ($mensaje !== ''): ?><div class="mensaje <?= e($tipoMensaje) ?>"><?= e($mensaje) ?></div><?php endif; ?>

<section class="caja" id="formulario-cliente">
<h2><?= $editar ? 'Modificar cliente' : 'Agregar cliente' ?></h2>
<form method="post" class="form-grid"><?= automacCsrfInput() ?>
<input type="hidden" name="accion" value="<?= $editar ? 'actualizar' : 'guardar' ?>">
<input type="hidden" name="clientes_id" value="<?= $editar ? (int)$editar['clientes_id'] : 0 ?>">
<div class="campo"><label>Código *</label><input type="text" name="clientes_codigo" maxlength="50" required value="<?= e($editar ? $editar['clientes_codigo'] : '') ?>"></div>
<div class="campo"><label>Nombre de fantasía *</label><input type="text" name="clientes_nomfantasia" maxlength="150" required value="<?= e($editar ? $editar['clientes_nomfantasia'] : '') ?>"></div>
<div class="campo ancho"><label>Razón social</label><input type="text" name="clientes_razonsocial" maxlength="200" value="<?= e($editar ? $editar['clientes_razonsocial'] : '') ?>"></div>
<div class="campo"><label>Nº cliente Bejerman</label><input type="number" name="clientes_numero_bejerman" min="0" value="<?= e($editar ? ($editar['clientes_numero_bejerman'] ?? '') : '') ?>"></div>
<div class="campo"><label>Ranking</label><input type="number" name="clientes_categoria" min="0" value="<?= e($editar ? ($editar['clientes_categoria'] ?? '') : '') ?>"></div>
<div class="campo"><label>Teléfono</label><input type="text" name="clientes_telefono" maxlength="100" value="<?= e($editar ? ($editar['clientes_telefono'] ?? '') : '') ?>"></div>
<div class="campo"><label>Email</label><input type="email" name="clientes_emails" maxlength="500" value="<?= e($editar ? ($editar['clientes_emails'] ?? '') : '') ?>"></div>
<div class="campo"><label>Nº socio CECAF</label><input type="text" name="clientes_socio_cecaf_numero" maxlength="30" value="<?= e($editar ? ($editar['clientes_socio_cecaf_numero'] ?? '') : '') ?>" placeholder="Vacío = no socio"></div>
<div class="campo"><label>Descuento 1 (%)</label><input type="number" name="clientes_d1" min="0" max="100" step="0.01" value="<?= e($editar ? $editar['clientes_d1'] : '0.00') ?>"></div>
<div class="campo"><label>Descuento 2 (%)</label><input type="number" name="clientes_d2" min="0" max="100" step="0.01" value="<?= e($editar ? $editar['clientes_d2'] : '0.00') ?>"></div>
<div class="campo"><label>Descuento 3 (%)</label><input type="number" name="clientes_d3" min="0" max="100" step="0.01" value="<?= e($editar ? $editar['clientes_d3'] : '0.00') ?>"></div>
<div class="campo"><label>Estado</label><select name="clientes_habilitado"><option value="SI"<?= !$editar || $editar['clientes_habilitado']==='SI' ? ' selected' : '' ?>>Habilitado</option><option value="NO"<?= $editar && $editar['clientes_habilitado']==='NO' ? ' selected' : '' ?>>Deshabilitado</option></select></div>
<div class="acciones-form"><button class="primario" type="submit"><?= $editar ? 'Guardar modificaciones' : 'Agregar cliente' ?></button><?php if ($editar): ?><a class="boton gris" href="administrar_clientes.php#formulario-cliente">Cancelar edición</a><?php endif; ?></div>
</form>
</section>

<section class="caja">
<h2>Clientes registrados</h2>
<form method="get" class="filtros">
<div class="campo"><label>Buscar</label><input type="text" name="buscar" value="<?= e($buscar) ?>" placeholder="Sigla, nombre, Nº Bejerman, email, teléfono o CECAF"></div>
<div class="campo"><label>Estado</label><select name="estado"><option value="TODOS">Todos</option><option value="SI"<?= $estadoFiltro==='SI'?' selected':'' ?>>Habilitados</option><option value="NO"<?= $estadoFiltro==='NO'?' selected':'' ?>>Deshabilitados</option></select></div>
<button class="primario" type="submit">Filtrar</button><a class="boton gris" href="administrar_clientes.php">Limpiar</a>
</form>
<div class="tabla-wrap"><table><thead><tr><th>Sigla</th><th>Nombre de fantasía</th><th>Nº Bejerman</th><th>Teléfono</th><th>Email</th><th>Ranking</th><th>CECAF</th><th>D1</th><th>D2</th><th>D3</th><th>Estado</th><th>Acciones</th></tr></thead><tbody>
<?php if ($clientes && $clientes->num_rows): while ($fila=$clientes->fetch_assoc()): ?>
<tr><td><strong><?= e($fila['clientes_codigo']) ?></strong></td><td><?= e($fila['clientes_nomfantasia']) ?></td><td><?= e($fila['clientes_numero_bejerman'] ?? '') ?></td><td><?= e($fila['clientes_telefono'] ?? '') ?></td><td><?= e($fila['clientes_emails'] ?? '') ?></td><td><?= e($fila['clientes_categoria'] ?? '') ?></td><td><?php if(trim((string)($fila['clientes_socio_cecaf_numero'] ?? ''))!==''): ?><span class="badge-cecaf">Socio Nº <?= e($fila['clientes_socio_cecaf_numero']) ?></span><?php else: ?>—<?php endif; ?></td><td><?= number_format((float)$fila['clientes_d1'],2,',','.') ?>%</td><td><?= number_format((float)$fila['clientes_d2'],2,',','.') ?>%</td><td><?= number_format((float)$fila['clientes_d3'],2,',','.') ?>%</td><td class="<?= $fila['clientes_habilitado']==='SI'?'estado-si':'estado-no' ?>"><?= $fila['clientes_habilitado']==='SI'?'Habilitado':'Deshabilitado' ?></td><td><div class="acciones"><a class="boton primario" href="administrar_clientes.php?editar=<?= (int)$fila['clientes_id'] ?>#formulario-cliente">Editar</a><form method="post"><?= automacCsrfInput() ?><input type="hidden" name="accion" value="cambiar_estado"><input type="hidden" name="clientes_id" value="<?= (int)$fila['clientes_id'] ?>"><input type="hidden" name="clientes_habilitado" value="<?= $fila['clientes_habilitado']==='SI'?'NO':'SI' ?>"><button class="<?= $fila['clientes_habilitado']==='SI'?'rojo':'verde' ?>" type="submit" onclick="return confirm('¿Confirmás el cambio de estado del cliente?');"><?= $fila['clientes_habilitado']==='SI'?'Deshabilitar':'Habilitar' ?></button></form></div></td></tr>
<?php endwhile; else: ?><tr><td colspan="12">No se encontraron clientes.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
</div>
</body>
</html>
