<?php
$id=filter_input(INPUT_GET,'id',FILTER_VALIDATE_INT);if(!$id)$id=filter_input(INPUT_POST,'pedido_id',FILTER_VALIDATE_INT);
header('Location: pasar_produccion.php?id='.(int)$id);exit;
