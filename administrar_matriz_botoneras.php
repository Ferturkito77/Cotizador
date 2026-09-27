<?php
session_start();
require_once 'conexion.php';
require_once 'auth.php';
exigirRoles(array('ADMINISTRADOR'));
header('Location: mantenimiento_senalizacion.php?tab=base');
exit;
