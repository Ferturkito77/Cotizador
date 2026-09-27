<?php
if (!function_exists('estaAutenticado') || !estaAutenticado()) {
    return;
}

$paginaMenuActual = basename((string)($_SERVER['PHP_SELF'] ?? ''));
$usuarioMenu = function_exists('usuarioActual') ? usuarioActual() : null;
$rolMenu = strtoupper(trim((string)($usuarioMenu['rol'] ?? '')));
$nombreUsuarioMenu = (string)($usuarioMenu['nombre'] ?? 'Usuario');

$gruposActivosMenu = array(
    'inicio' => array('inicio.php'),
    'cotizador' => array('index.php'),
    'cotizaciones' => array('cotizaciones.php', 'ver_cotizacion.php'),
    'visor_pdf' => array('visor_pdf.php'),
    'comercial' => array('comercial.php'),
    'pedidos' => array('pedidos.php', 'ver_pedido.php', 'editar_pedido.php', 'pedido_preintegracion.php', 'pasar_produccion.php', 'confirmar_integracion_externa.php'),
    'ordenes' => array('ordenes_fabricacion.php', 'ver_orden_fabricacion.php'),
    'clientes' => array('administrar_clientes.php'),
    'usuarios' => array('administrar_usuarios.php'),
    'plantillas' => array('administrar_plantillas.php', 'plantillas_controles.php'),
    'matriz' => array('administrar_matriz.php', 'administrar_material_hueco.php', 'exportar_matriz_calculos.php', 'limites_paradas.php'),
    'botoneras' => array('administrar_matriz_botoneras.php'),
    'senalizacion_mantenimiento' => array('mantenimiento_senalizacion.php'),
    'accesorios_mantenimiento' => array('mantenimiento_accesorios.php'),
    'mantenimiento' => array('mantenimiento.php','mantenimiento_automatizaciones.php','mantenimiento_administracion.php','diagnostico_depuracion.php'),
    'control_mantenimiento' => array('mantenimiento_control.php','mantenimiento_catalogos_control.php','administrar_matriz.php','exportar_matriz_calculos.php'),
    'catalogos_control' => array('mantenimiento_catalogos_control.php'),
    'diagnostico' => array('diagnostico_catalogos.php','diagnostico_depuracion.php'),
    'automatizaciones' => array('mantenimiento_automatizaciones.php'),
    'administracion_sistema' => array('mantenimiento_administracion.php','mantenimiento_integracion_externa.php','mantenimiento_condiciones_pago.php'),
    'precios' => array('actualizar_precios.php', 'administrar_precios.php'),
    'repuestos_mantenimiento' => array('mantenimiento_repuestos.php', 'administrar_repuestos.php'),
    'iep_mantenimiento' => array('mantenimiento_iep.php'),
    'numeracion' => array('administrar_numeracion.php'),
    'documentos' => array('administrar_documentos.php'),
    'preferencias' => array('mis_preferencias.php'),
    'clave' => array('cambiar_clave.php')
);

if (!function_exists('menuAutomacActivo')) {
    function menuAutomacActivo(string $clave, string $pagina, array $grupos): string
    {
        return in_array($pagina, $grupos[$clave] ?? array(), true) ? ' activo' : '';
    }
}

$titulosPaginaMenu = array(
    'inicio.php' => 'Nueva cotización',
    'index.php' => 'Cotizador de ascensores',
    'cotizaciones.php' => 'Cotizaciones',
    'visor_pdf.php' => 'Visor PDF',
    'comercial.php' => 'Panel comercial',
    'ver_cotizacion.php' => 'Detalle de cotización',
    'pedidos.php' => 'Pedidos',
    'pedido_preintegracion.php' => 'Integración externa · Pedido',
    'ver_pedido.php' => 'Detalle de pedido',
    'editar_pedido.php' => 'Modificar pedido',
    'ordenes_fabricacion.php' => 'Órdenes de fabricación',
    'ver_orden_fabricacion.php' => 'Orden de fabricación',
    'administrar_clientes.php' => 'Clientes',
    'administrar_usuarios.php' => 'Usuarios',
    'administrar_plantillas.php' => 'Plantillas',
    'mantenimiento.php' => 'Mantenimiento',
    'mantenimiento_control.php' => 'Matrices de Control',
    'mantenimiento_iep.php' => 'Matrices de IEP',
    'administrar_matriz.php' => 'Cálculos de Control',
    'administrar_material_hueco.php' => 'Material de hueco',
    'limites_paradas.php' => 'Límites de paradas',
    'administrar_matriz_botoneras.php' => 'Matriz de botoneras',
    'mantenimiento_senalizacion.php' => 'Mantenimiento de señalización',
    'mantenimiento_accesorios.php' => 'Mantenimiento de accesorios',
    'mantenimiento_catalogos_control.php' => 'Configuración de Control',
    'diagnostico_catalogos.php' => 'Diagnóstico de integridad',
    'diagnostico_depuracion.php' => 'Depuración del sistema',
    'mantenimiento_automatizaciones.php' => 'Relaciones y Automatizaciones',
    'mantenimiento_administracion.php' => 'Administración del Sistema',
    'mantenimiento_integracion_externa.php' => 'Integración externa',
    'mantenimiento_condiciones_pago.php' => 'Condiciones de pago',
    'pasar_produccion.php' => 'Paso a Producción',
    'confirmar_integracion_externa.php' => 'Paso a Producción',
    'actualizar_precios.php' => 'Bases de precios Bejerman',
    'mantenimiento_repuestos.php' => 'Repuestos',
    'administrar_numeracion.php' => 'Numeración de documentos',
    'administrar_documentos.php' => 'Archivo de documentos',
    'mis_preferencias.php' => 'Mis preferencias',
    'cambiar_clave.php' => 'Mi contraseña'
);
$tituloPaginaMenu = $titulosPaginaMenu[$paginaMenuActual] ?? 'Cotizador Automac';

$puedeComercial = in_array($rolMenu, array('ADMINISTRADOR', 'COMERCIAL'), true);
$puedeOrdenes = in_array($rolMenu, array('ADMINISTRADOR', 'TECNICO', 'COMERCIAL'), true);
$esAdministradorMenu = $rolMenu === 'ADMINISTRADOR';
$esComercialMenu = $rolMenu === 'COMERCIAL';
$inicialUsuario = function_exists('mb_substr') ? mb_strtoupper(mb_substr($nombreUsuarioMenu, 0, 1, 'UTF-8'), 'UTF-8') : strtoupper(substr($nombreUsuarioMenu, 0, 1));
$destinoMarcaMenu = $puedeComercial ? 'index.php?nueva=1' : ($puedeOrdenes ? 'ordenes_fabricacion.php' : 'cambiar_clave.php');
?>
<link rel="stylesheet" href="automac-ui.css?v=20260920-v528">
<link rel="stylesheet" href="cotizador-v15.css?v=20260921-v150">
<script>document.documentElement.classList.add('automac-shell-loading');document.body.classList.add('automac-app','automac-comfort');<?php if ($paginaMenuActual !== 'index.php'): ?>document.body.classList.add('automac-wide-page');<?php endif; ?></script>

<div class="automac-shell" data-automac-shell>
    <!-- Drawer móvil: conserva todos los accesos y permisos -->
    <aside class="automac-sidebar" aria-label="Navegación principal">
        <a class="automac-sidebar-brand" href="<?= htmlspecialchars($destinoMarcaMenu, ENT_QUOTES, 'UTF-8') ?>" aria-label="Automac">
            <img class="automac-sidebar-logo-img" src="assets/automac-logo-dark.png" alt="AUTOMAC">
            <span><small>Cotizador</small></span>
        </a>
        <nav class="automac-sidebar-nav">
            <?php if ($puedeComercial): ?>
                <a class="automac-side-link" href="index.php?nueva=1"><span class="am-icon">＋</span><span>Nueva cotización</span></a>
            <?php endif; ?>
            <?php if ($puedeComercial || $puedeOrdenes): ?>
                <div class="automac-side-section">Operación</div>
                <?php if ($puedeComercial): ?>
                    <a class="automac-side-link<?= menuAutomacActivo('cotizador', $paginaMenuActual, $gruposActivosMenu) ?>" href="index.php"><span class="am-icon">▣</span><span>Cotizador</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('cotizaciones', $paginaMenuActual, $gruposActivosMenu) ?>" href="cotizaciones.php"><span class="am-icon">▤</span><span>Cotizaciones</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('visor_pdf', $paginaMenuActual, $gruposActivosMenu) ?>" href="visor_pdf.php"><span class="am-icon">▱</span><span>Visor PDF</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('comercial', $paginaMenuActual, $gruposActivosMenu) ?>" href="comercial.php"><span class="am-icon">▥</span><span>Panel comercial</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('pedidos', $paginaMenuActual, $gruposActivosMenu) ?>" href="pedidos.php"><span class="am-icon">▧</span><span>Pedidos</span></a>
                <?php endif; ?>
                <?php if ($puedeOrdenes): ?>
                    <a class="automac-side-link<?= menuAutomacActivo('ordenes', $paginaMenuActual, $gruposActivosMenu) ?>" href="ordenes_fabricacion.php"><span class="am-icon">▦</span><span>Órdenes de fabricación</span></a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($puedeComercial || $esAdministradorMenu): ?>
                <div class="automac-side-section">Mantenimiento</div>
                <?php if ($puedeComercial): ?><a class="automac-side-link<?= menuAutomacActivo('clientes', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_clientes.php"><span class="am-icon">♙</span><span>Clientes</span></a><?php endif; ?>
                <?php if ($esComercialMenu): ?><a class="automac-side-link<?= $paginaMenuActual==='mantenimiento_integracion_externa.php'?' activo':'' ?>" href="mantenimiento_integracion_externa.php"><span class="am-icon">⇄</span><span>Integración externa</span></a><?php endif; ?>
                <?php if ($esAdministradorMenu): ?>
                    <a class="automac-side-link<?= menuAutomacActivo('mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento.php"><span class="am-icon">⚙</span><span>Mantenimiento</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('precios', $paginaMenuActual, $gruposActivosMenu) ?>" href="actualizar_precios.php"><span class="am-icon">▥</span><span>Base Bejerman</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('control_mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_control.php"><span class="am-icon">⌗</span><span>Matrices de Control</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('senalizacion_mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_senalizacion.php"><span class="am-icon">◉</span><span>Matrices de Señalización</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('accesorios_mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_accesorios.php"><span class="am-icon">⊕</span><span>Matrices de Accesorios</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('repuestos_mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_repuestos.php"><span class="am-icon">◇</span><span>Matrices de Repuestos</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('iep_mantenimiento', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_iep.php"><span class="am-icon">▧</span><span>Matrices de IEP</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('matriz', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_material_hueco.php"><span class="am-icon">⌘</span><span>Material de hueco</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('matriz', $paginaMenuActual, $gruposActivosMenu) ?>" href="limites_paradas.php"><span class="am-icon">⇥</span><span>Límites de paradas</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('plantillas', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_plantillas.php"><span class="am-icon">▱</span><span>Plantillas de Control</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('automatizaciones', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_automatizaciones.php"><span class="am-icon">⇄</span><span>Automatizaciones</span></a>
                    <a class="automac-side-link<?= menuAutomacActivo('diagnostico', $paginaMenuActual, $gruposActivosMenu) ?>" href="diagnostico_catalogos.php"><span class="am-icon">✓</span><span>Diagnóstico de integridad</span></a>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($esAdministradorMenu): ?>
                <div class="automac-side-section">Administración</div>
                <a class="automac-side-link<?= menuAutomacActivo('administracion_sistema', $paginaMenuActual, $gruposActivosMenu) ?>" href="mantenimiento_administracion.php"><span class="am-icon">⚙</span><span>Administración del Sistema</span></a>
                <a class="automac-side-link<?= menuAutomacActivo('usuarios', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_usuarios.php"><span class="am-icon">♧</span><span>Usuarios</span></a>
                <a class="automac-side-link<?= menuAutomacActivo('numeracion', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_numeracion.php"><span class="am-icon">#</span><span>Numeración</span></a>
                <a class="automac-side-link<?= menuAutomacActivo('documentos', $paginaMenuActual, $gruposActivosMenu) ?>" href="administrar_documentos.php"><span class="am-icon">▣</span><span>Archivo de documentos</span></a>
                <a class="automac-side-link<?= $paginaMenuActual==='mantenimiento_condiciones_pago.php'?' activo':'' ?>" href="mantenimiento_condiciones_pago.php"><span class="am-icon">$</span><span>Condiciones de pago</span></a>
                <a class="automac-side-link<?= $paginaMenuActual==='mantenimiento_integracion_externa.php'?' activo':'' ?>" href="mantenimiento_integracion_externa.php"><span class="am-icon">⇄</span><span>Integración externa</span></a>
            <?php endif; ?>
        </nav>
        <div class="automac-sidebar-user">
            <div class="automac-user-avatar"><?= htmlspecialchars($inicialUsuario, ENT_QUOTES, 'UTF-8') ?></div>
            <div class="automac-user-copy"><strong><?= htmlspecialchars($nombreUsuarioMenu, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst(strtolower($rolMenu)), ENT_QUOTES, 'UTF-8') ?></small><a href="mis_preferencias.php" style="font-size:11px">Mis preferencias</a></div>
        </div>
    </aside>

    <!-- Barra superior de escritorio -->
    <header class="automac-topbar automac-topnav">
        <button class="automac-mobile-toggle" type="button" aria-label="Abrir menú" data-sidebar-toggle>☰</button>
        <a class="automac-top-brand automac-top-brand-logo" href="<?= htmlspecialchars($destinoMarcaMenu, ENT_QUOTES, 'UTF-8') ?>"><img src="assets/automac-logo-dark.png" alt="AUTOMAC"><span>Cotizador</span></a>
        <div class="automac-topbar-title"><small>Sistema comercial</small><strong><?= htmlspecialchars($tituloPaginaMenu, ENT_QUOTES, 'UTF-8') ?></strong></div>

        <nav class="automac-desktop-nav" aria-label="Navegación principal">
            <?php if ($puedeComercial): ?>
                <a class="automac-top-link nueva" href="index.php?nueva=1">＋ Nueva</a>
                <a class="automac-top-link<?= menuAutomacActivo('cotizaciones', $paginaMenuActual, $gruposActivosMenu) ?>" href="cotizaciones.php">Cotizaciones</a>
                <a class="automac-top-link<?= menuAutomacActivo('pedidos', $paginaMenuActual, $gruposActivosMenu) ?>" href="pedidos.php">Pedidos</a>
            <?php endif; ?>
            <?php if ($puedeOrdenes): ?><a class="automac-top-link<?= menuAutomacActivo('ordenes', $paginaMenuActual, $gruposActivosMenu) ?>" href="ordenes_fabricacion.php">Órdenes</a><?php endif; ?>
            <?php if ($puedeComercial): ?><a class="automac-top-link<?= menuAutomacActivo('comercial', $paginaMenuActual, $gruposActivosMenu) ?>" href="comercial.php">Comercial</a><?php endif; ?>

            <?php if ($puedeComercial || $esAdministradorMenu): ?>
            <details class="automac-nav-menu" name="automac-topmenu">
                <summary>Mantenimiento ▾</summary>
                <div class="automac-nav-dropdown automac-maint-dropdown">
                    <div class="automac-nav-group-label">Datos</div>
                    <?php if ($puedeComercial): ?><a href="administrar_clientes.php">Clientes</a><?php endif; ?>
                    <?php if ($esComercialMenu): ?><a href="mantenimiento_integracion_externa.php">Integración externa</a><?php endif; ?>
                    <?php if ($esAdministradorMenu): ?>
                        <a href="mantenimiento.php"><strong>Centro de Mantenimiento</strong></a>
                        <a href="actualizar_precios.php">Base Bejerman</a>
                        <div class="automac-nav-group-label">Matrices</div>
                        <a href="mantenimiento_control.php">Matrices de Control</a>
                        <a href="mantenimiento_senalizacion.php">Matrices de Señalización</a>
                        <a href="mantenimiento_accesorios.php">Matrices de Accesorios</a>
                        <a href="mantenimiento_repuestos.php">Matrices de Repuestos</a>
                        <a href="mantenimiento_iep.php">Matrices de IEP</a>
                        <div class="automac-nav-group-label">Control / herramientas</div>
                        <a href="administrar_material_hueco.php">Material de hueco</a>
                        <a href="limites_paradas.php">Límites de paradas</a>
                        <a href="administrar_plantillas.php">Plantillas de Control</a>
                        <a href="mantenimiento_automatizaciones.php">Relaciones y Automatizaciones</a>
                        <a href="diagnostico_catalogos.php">Diagnóstico de integridad</a>
                        <a href="diagnostico_depuracion.php">Depuración del sistema</a>
                    <?php endif; ?>
                </div>
            </details>
            <?php endif; ?>
            <?php if ($esAdministradorMenu): ?>
            <details class="automac-nav-menu" name="automac-topmenu">
                <summary>Administración ▾</summary>
                <div class="automac-nav-dropdown right">
                    <a href="mantenimiento_administracion.php"><strong>Administración del Sistema</strong></a>
                    <a href="administrar_usuarios.php">Usuarios</a>
                    <a href="administrar_numeracion.php">Numeración</a>
                    <a href="administrar_documentos.php">Archivo de documentos</a>
                    <a href="mantenimiento_condiciones_pago.php">Condiciones de pago</a>
                    <a href="mantenimiento_integracion_externa.php">Integración externa</a>
                </div>
            </details>
            <?php endif; ?>
        </nav>

        <div class="automac-top-actions">
            <details class="automac-user-topmenu" name="automac-topmenu">
                <summary><span class="automac-status-dot"></span><strong><?= htmlspecialchars($nombreUsuarioMenu, ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars(ucfirst(strtolower($rolMenu)), ENT_QUOTES, 'UTF-8') ?></small></summary>
                <div class="automac-nav-dropdown right user">
                    <a href="mis_preferencias.php">Mis preferencias</a>
                    <a href="cambiar_clave.php">Cambiar contraseña</a>
                    <a class="salir" href="logout.php">Cerrar sesión</a>
                </div>
            </details>
        </div>
    </header>
    <button class="automac-sidebar-overlay" type="button" aria-label="Cerrar menú" data-sidebar-overlay></button>
</div>

<style>
/* v254: defensa visual adicional. Un details cerrado nunca puede dejar visible su desplegable. */
details.automac-nav-menu:not([open]) > .automac-nav-dropdown,
details.automac-user-topmenu:not([open]) > .automac-nav-dropdown{display:none!important;}
details.automac-nav-menu[open] > .automac-nav-dropdown,
details.automac-user-topmenu[open] > .automac-nav-dropdown{display:block!important;}
</style>

<script>
(function(){
    var body=document.body;
    var toggle=document.querySelector('[data-sidebar-toggle]');
    var overlay=document.querySelector('[data-sidebar-overlay]');
    var density=document.querySelector('[data-density-toggle]');
    function closeSidebar(){body.classList.remove('automac-sidebar-open');}
    function setComfort(v){
        body.classList.toggle('automac-comfort',!!v);
        body.classList.toggle('automac-compact',!v);
        if(density){density.textContent=v?'▤':'≡';density.title=v?'Usar modo compacto':'Usar modo cómodo';}
        try{localStorage.setItem('automac_density',v?'comfort':'compact');}catch(e){}
    }
    body.classList.remove('automac-topnav-collapsed','automac-topnav-autohide','automac-topnav-revealed');
    try{localStorage.removeItem('automac_topnav_collapsed');}catch(e){}
    setComfort(true);
    if(toggle)toggle.addEventListener('click',function(){body.classList.toggle('automac-sidebar-open');});
    if(overlay)overlay.addEventListener('click',closeSidebar);
    /* v254: control exclusivo robusto de los menues superiores.
       No dependemos del comportamiento nativo de <details>: el click decide explicitamente
       cual queda abierto y cierra todos los demas. */
    var menusSuperiores=Array.prototype.slice.call(document.querySelectorAll('.automac-nav-menu,.automac-user-topmenu'));
    function cerrarMenusSuperiores(excepto){
        menusSuperiores.forEach(function(m){
            if(m!==excepto){m.open=false;m.removeAttribute('open');}
        });
    }
    menusSuperiores.forEach(function(menu){
        var summary=menu.querySelector('summary');
        if(!summary)return;
        var temporizadorCierre=null;
        function cancelarCierre(){
            if(temporizadorCierre){clearTimeout(temporizadorCierre);temporizadorCierre=null;}
        }
        function programarCierre(){
            cancelarCierre();
            temporizadorCierre=setTimeout(function(){
                menu.open=false;
                menu.removeAttribute('open');
                temporizadorCierre=null;
            },280);
        }
        summary.addEventListener('click',function(e){
            e.preventDefault();
            e.stopPropagation();
            cancelarCierre();
            var abrir=!menu.open;
            cerrarMenusSuperiores(null);
            if(abrir){menu.open=true;menu.setAttribute('open','');}
        });
        /* v255: al retirar el mouse del menu completo, el desplegable se oculta.
           El pequeno retardo permite pasar del boton al desplegable sin que se cierre por el espacio entre ambos. */
        menu.addEventListener('mouseenter',cancelarCierre);
        menu.addEventListener('mouseleave',programarCierre);
        menu.querySelectorAll('.automac-nav-dropdown a').forEach(function(a){
            a.addEventListener('click',function(){cancelarCierre();cerrarMenusSuperiores(null);});
        });
    });
    document.addEventListener('click',function(e){
        if(!e.target.closest('.automac-nav-menu,.automac-user-topmenu')) cerrarMenusSuperiores(null);
    });
    document.addEventListener('keydown',function(e){if(e.key==='Escape'){closeSidebar();document.querySelectorAll('details[open]').forEach(function(d){d.removeAttribute('open');});}});
    /* V55: convierte listados extensos en una grilla densa y agrupa acciones en un menu de fila. */
    function compactarListados(){
        document.querySelectorAll('table').forEach(function(t){
            var h=t.querySelectorAll('thead th').length;
            if(h>=5)t.classList.add('am-data-list');
        });
        document.querySelectorAll('table.am-data-list tbody tr').forEach(function(tr){
            if(tr.classList.contains('detalle')||tr.classList.contains('detalle-pedido'))return;
            var table=tr.closest('table');
            var cells=tr.children;if(!cells||!cells.length)return;
            var actionCols=[];
            if(table){
                table.querySelectorAll('thead th').forEach(function(th,idx){
                    var label=(th.textContent||'').trim().toLowerCase();
                    if(label==='acción'||label==='accion'||label==='acciones'||label.indexOf('acción')>=0||label.indexOf('acciones')>=0)actionCols.push(idx);
                });
            }
            Array.prototype.forEach.call(cells,function(td,idx){
                if(td.querySelector('.am-row-actions'))return;
                var interactivos=td.querySelectorAll('a.btn,button.btn,input.btn[type="submit"],button[data-list-action]');
                if(interactivos.length<2)return;
                /* Compacta la columna Accion/Acciones aunque no tenga clases especiales. */
                var ultimo=td===cells[cells.length-1];
                var claseAccion=td.classList.contains('acciones')||td.querySelector('.acciones-pedido,.acciones,.ordenes-pedido');
                var columnaAccion=actionCols.indexOf(idx)!==-1;
                if(!ultimo&&!claseAccion&&!columnaAccion)return;
                var det=document.createElement('details');det.className='am-row-actions';
                var sum=document.createElement('summary');sum.setAttribute('aria-label','Acciones');sum.title='Acciones';sum.textContent='⋮';
                var menu=document.createElement('div');menu.className='am-row-actions-menu';
                while(td.firstChild)menu.appendChild(td.firstChild);
                det.appendChild(sum);det.appendChild(menu);td.appendChild(det);td.classList.add('am-action-cell');
            });
        });
        document.querySelectorAll('.busqueda,.bus,.filtros,.filters,.filter-bar').forEach(function(x){x.classList.add('am-list-filters');});
    }
    function iniciarCompactacion(){
        compactarListados();
        /* Algunas pantallas agregan o reemplazan filas luego del include del menu. */
        var timer=null;
        var obs=new MutationObserver(function(){
            clearTimeout(timer);
            timer=setTimeout(compactarListados,40);
        });
        obs.observe(document.body,{childList:true,subtree:true});
        setTimeout(compactarListados,120);
        setTimeout(compactarListados,450);
        document.documentElement.classList.remove('automac-shell-loading');
    }
    if(document.readyState==='loading'){
        document.addEventListener('DOMContentLoaded',iniciarCompactacion,{once:true});
    }else{
        iniciarCompactacion();
    }
    document.addEventListener('click',function(e){
        document.querySelectorAll('.am-row-actions[open]').forEach(function(d){if(!d.contains(e.target))d.removeAttribute('open');});
    });
})();
</script>
