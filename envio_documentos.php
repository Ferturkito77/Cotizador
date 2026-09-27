<?php
require_once __DIR__ . '/documentos_storage.php';

function envioDocEsc($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function envioDocTipoLabel(string $tipo): string
{
    return strtolower($tipo) === 'pedido' ? 'pedido' : 'cotización';
}

function envioDocNormalizarTelefono(string $telefono): string
{
    return preg_replace('/\D+/', '', $telefono) ?: '';
}

function envioDocPlantillas(mysqli $conexion, int $uid, string $tipo): array
{
    $tipo = strtolower($tipo) === 'pedido' ? 'pedido' : 'cotizacion';
    if ($tipo === 'pedido') {
        $asunto = documentosObtenerConfiguracion($conexion, 'mail_asunto_pedido_usuario_' . $uid, 'Pedido Automac {numero}{revision_texto}');
        $cuerpo = documentosObtenerConfiguracion($conexion, 'mail_cuerpo_pedido_usuario_' . $uid, "Hola,\n\nAdjuntamos el pedido {numero}{revision_texto}.\n\nSaludos,\n{usuario}\nAutomac");
        $whatsapp = documentosObtenerConfiguracion($conexion, 'whatsapp_cuerpo_pedido_usuario_' . $uid, 'Hola. Te envío el pedido {numero}{revision_texto} de Automac. A continuación adjunto el PDF.');
    } else {
        // Compatibilidad con las preferencias de Gmail creadas en v84/v85.
        $asuntoAnterior = documentosObtenerConfiguracion($conexion, 'mail_asunto_usuario_' . $uid, 'Cotización Automac {numero}{revision_texto}');
        $cuerpoAnterior = documentosObtenerConfiguracion($conexion, 'mail_cuerpo_usuario_' . $uid, "Hola,\n\nAdjuntamos la cotización {numero}{revision_texto} para su consideración.\n\nSaludos,\n{usuario}\nAutomac");
        $asunto = documentosObtenerConfiguracion($conexion, 'mail_asunto_cotizacion_usuario_' . $uid, $asuntoAnterior);
        $cuerpo = documentosObtenerConfiguracion($conexion, 'mail_cuerpo_cotizacion_usuario_' . $uid, $cuerpoAnterior);
        $whatsapp = documentosObtenerConfiguracion($conexion, 'whatsapp_cuerpo_cotizacion_usuario_' . $uid, 'Hola. Te envío la cotización {numero}{revision_texto} de Automac. A continuación adjunto el PDF.');
    }
    return array($asunto, $cuerpo, $whatsapp);
}

function envioDocAplicarTokens(string $plantilla, array $doc): string
{
    $revision = (int)($doc['revision'] ?? 0);
    $reemplazos = array(
        '{tipo}' => envioDocTipoLabel((string)($doc['tipo'] ?? 'cotizacion')),
        '{numero}' => (string)($doc['numero'] ?? ''),
        '{revision}' => (string)$revision,
        '{revision_texto}' => $revision > 0 ? ' - Revisión ' . $revision : '',
        '{cliente}' => (string)($doc['cliente'] ?? ''),
        '{referencia}' => (string)($doc['referencia'] ?? ''),
        '{usuario}' => (string)($doc['usuario'] ?? '')
    );
    return strtr($plantilla, $reemplazos);
}

function envioDocPreparar(mysqli $conexion, array $doc): array
{
    if (empty($_SESSION['csrf_envio_documento'])) {
        $_SESSION['csrf_envio_documento'] = bin2hex(random_bytes(24));
    }
    $doc['csrf'] = (string)$_SESSION['csrf_envio_documento'];
    $doc['error_email'] = '';
    $doc['aviso_email'] = '';

    $emailTemporal = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['accion_envio_documento'] ?? '') !== '') {
        $token = (string)($_POST['csrf_envio_documento'] ?? '');
        if (!hash_equals($doc['csrf'], $token)) {
            $doc['error_email'] = 'La sesión del formulario venció. Recargá la página e intentá nuevamente.';
        } else {
            $accion = (string)($_POST['accion_envio_documento'] ?? '');
            $clienteId = (int)($doc['cliente_id'] ?? 0);
            if ($accion === 'preparar_email') {
                $emailTemporal = trim((string)($_POST['email_destino'] ?? ''));
                if (!filter_var($emailTemporal, FILTER_VALIDATE_EMAIL)) {
                    $doc['error_email'] = 'Ingresá un email válido.';
                } elseif (!empty($_POST['guardar_email_cliente']) && $clienteId > 0) {
                    $st = $conexion->prepare('UPDATE clientes SET clientes_emails=? WHERE clientes_id=? LIMIT 1');
                    if (!$st) {
                        $doc['error_email'] = 'No se pudo preparar la actualización del email.';
                    } else {
                        $st->bind_param('si', $emailTemporal, $clienteId);
                        if (!$st->execute()) $doc['error_email'] = 'No se pudo guardar el email en la ficha del cliente.';
                        else { $doc['email'] = $emailTemporal; $doc['aviso_email'] = 'El email quedó guardado en la ficha del cliente.'; }
                        $st->close();
                    }
                } else {
                    $doc['aviso_email'] = 'Este email se usará solamente para este envío.';
                }
            }
        }
    }

    $email = $emailTemporal !== '' ? $emailTemporal : trim((string)($doc['email'] ?? ''));
    if ($email !== '') {
        $partes = preg_split('/[;,\s]+/', $email);
        $email = trim((string)($partes[0] ?? ''));
    }
    $u = function_exists('usuarioActual') ? usuarioActual() : null;
    $uid = (int)($u['id'] ?? 0);
    $doc['usuario'] = trim((string)($doc['usuario'] ?? ($u['nombre'] ?? '')));
    list($asuntoTpl, $cuerpoTpl, $whatsappTpl) = envioDocPlantillas($conexion, $uid, (string)($doc['tipo'] ?? 'cotizacion'));
    $asunto = envioDocAplicarTokens($asuntoTpl, $doc);
    $cuerpo = envioDocAplicarTokens($cuerpoTpl, $doc);
    $mensajeWhatsapp = envioDocAplicarTokens($whatsappTpl, $doc);

    $doc['email_destino'] = $email;
    $doc['email_valido'] = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL);
    $doc['gmail_url'] = $doc['email_valido'] ? 'https://mail.google.com/mail/?view=cm&fs=1&to=' . rawurlencode($email) . '&su=' . rawurlencode($asunto) . '&body=' . rawurlencode($cuerpo) : '';
    // WhatsApp se abre sin destinatario: cada usuario elige el contacto manualmente desde WhatsApp Web.
    $doc['whatsapp_url'] = 'https://web.whatsapp.com/send?text=' . rawurlencode($mensajeWhatsapp);
    return $doc;
}

function envioDocRender(array $doc): void
{
    $tipoLabel = ucfirst(envioDocTipoLabel((string)($doc['tipo'] ?? 'cotizacion')));
    $revision = (int)($doc['revision'] ?? 0);
    $esRevision = $revision > 0;
    ?>
    <style>
    .envio-doc{background:#eef4fb;border:1px solid #cbd9ea;border-radius:10px;padding:15px 16px;margin:16px 0 20px}.envio-doc h3{margin:0 0 6px;color:#173a69}.envio-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.envio-canal{background:#fff;border:1px solid #d7e1ed;border-radius:9px;padding:13px}.envio-canal h4{margin:0 0 8px}.envio-acciones{display:flex;gap:8px;flex-wrap:wrap;margin-top:9px}.envio-btn{display:inline-block;padding:9px 12px;border-radius:6px;text-decoration:none;font-weight:700;border:0;cursor:pointer}.envio-mail-btn{background:#1d3f73;color:#fff}.envio-wa-btn{background:#128c7e;color:#fff}.envio-pdf-btn{background:#5b6470;color:#fff}.envio-mini{font-size:12px;color:#607386;line-height:1.45;margin-top:8px}.envio-canal input[type=email],.envio-canal input[type=text]{width:100%;box-sizing:border-box;padding:9px 10px;border:1px solid #b8c7d8;border-radius:6px}.envio-check{display:flex;gap:7px;align-items:flex-start;font-size:12px;margin:8px 0}.envio-error{color:#b42318;font-weight:700;font-size:13px;margin-top:7px}.envio-ok{color:#176336;font-weight:700;font-size:12px;margin-top:7px}@media(max-width:800px){.envio-grid{grid-template-columns:1fr}}
    </style>
    <section class="envio-doc">
      <h3>Enviar <?= envioDocEsc($tipoLabel) ?><?= $esRevision ? ' · Revisión ' . $revision : '' ?> al cliente</h3>
      <div class="envio-mini">El sistema prepara el mensaje. El PDF se adjunta manualmente en Gmail o WhatsApp Web por seguridad del navegador.</div>
      <div class="envio-grid">
        <div class="envio-canal">
          <h4>Email / Gmail</h4>
          <?php if (!empty($doc['email_valido'])): ?>
            <div class="envio-mini">Destinatario: <b><?= envioDocEsc($doc['email_destino']) ?></b></div>
            <div class="envio-acciones">
              <a class="envio-btn envio-mail-btn" href="<?= envioDocEsc($doc['gmail_url']) ?>" target="_blank" rel="noopener">ABRIR GMAIL</a>
              <?php if (!empty($doc['pdf_url'])): ?><a class="envio-btn envio-pdf-btn" href="<?= envioDocEsc($doc['pdf_url']) ?>">DESCARGAR PDF</a><?php endif; ?>
            </div>
            <?php if (!empty($doc['aviso_email'])): ?><div class="envio-ok"><?= envioDocEsc($doc['aviso_email']) ?></div><?php endif; ?>
          <?php else: ?>
            <form method="post">
              <input type="hidden" name="accion_envio_documento" value="preparar_email">
              <input type="hidden" name="csrf_envio_documento" value="<?= envioDocEsc($doc['csrf']) ?>">
              <input type="email" name="email_destino" required placeholder="cliente@empresa.com" value="<?= envioDocEsc($doc['email_destino'] ?? '') ?>">
              <label class="envio-check"><input type="checkbox" name="guardar_email_cliente" value="1"> Guardar este email en la ficha del cliente</label>
              <button class="envio-btn envio-mail-btn" type="submit">PREPARAR EMAIL</button>
            </form>
            <?php if (!empty($doc['error_email'])): ?><div class="envio-error"><?= envioDocEsc($doc['error_email']) ?></div><?php endif; ?>
          <?php endif; ?>
        </div>
        <div class="envio-canal">
          <h4>WhatsApp Web</h4>
          <div class="envio-mini">Abrí WhatsApp Web y elegí manualmente el contacto al que querés enviar el documento. El sistema no usa el teléfono cargado en la ficha del cliente.</div>
          <div class="envio-acciones">
            <a class="envio-btn envio-wa-btn" href="<?= envioDocEsc($doc['whatsapp_url']) ?>" target="_blank" rel="noopener">ABRIR WHATSAPP WEB</a>
            <?php if (!empty($doc['pdf_url'])): ?><a class="envio-btn envio-pdf-btn" href="<?= envioDocEsc($doc['pdf_url']) ?>">DESCARGAR PDF</a><?php endif; ?>
          </div>
          <div class="envio-mini">El mensaje queda preparado; seleccioná el contacto desde WhatsApp Web y adjuntá el PDF descargado.</div>
        </div>
      </div>
      <div class="envio-mini"><a href="mis_preferencias.php">Modificar mis textos predeterminados de email y WhatsApp</a>.</div>
    </section>
    <?php
}
