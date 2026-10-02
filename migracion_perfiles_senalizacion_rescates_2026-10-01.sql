-- Perfiles administrables de Senalizacion y compatibilidad/articulos de rescates.
-- Ejecutar una vez en la base del cotizador. No crea ni actualiza precios Bejerman.

CREATE TABLE IF NOT EXISTS senal_modelos_perfiles (
  modelo_pulsador_id int(10) unsigned NOT NULL,
  familia_comercial varchar(80) NOT NULL DEFAULT 'GENERAL',
  modo_base varchar(32) NOT NULL DEFAULT 'MATRIZ_COMPLETA',
  tipo_modulo_requerido varchar(30) NOT NULL DEFAULT '',
  pantalla tinyint(1) NOT NULL DEFAULT 0,
  indicador_cabina tinyint(1) NOT NULL DEFAULT 1,
  indicador_pulsador tinyint(1) NOT NULL DEFAULT 1,
  indicador_exterior_independiente tinyint(1) NOT NULL DEFAULT 1,
  regla_adicional_parada varchar(32) NOT NULL DEFAULT 'MATRIZ',
  modelo_adicional_parada varchar(80) NOT NULL DEFAULT '',
  politica_acabado varchar(32) NOT NULL DEFAULT 'COEFICIENTE',
  acabado varchar(30) NOT NULL DEFAULT '',
  modo_pulsador_exterior varchar(32) NOT NULL DEFAULT 'MATRIZ_COMPLETA',
  indicador_incluido_descripcion varchar(200) NOT NULL DEFAULT '',
  separar_indicador_exterior tinyint(1) NOT NULL DEFAULT 0,
  requiere_luz_cortesia tinyint(1) NOT NULL DEFAULT 0,
  activo tinyint(1) NOT NULL DEFAULT 1,
  orden int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (modelo_pulsador_id),
  KEY idx_senal_perfil_orden (orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS senal_indicadores_contextos (
  codigo varchar(80) NOT NULL,
  contexto varchar(40) NOT NULL,
  activo tinyint(1) NOT NULL DEFAULT 1,
  orden int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (codigo, contexto),
  KEY idx_senal_ind_contexto (contexto, activo, orden)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rescates_compatibilidades (
  compatibilidad_id int(10) unsigned NOT NULL AUTO_INCREMENT,
  rescate_id int(10) unsigned NOT NULL,
  tipo_control_id tinyint(3) unsigned NOT NULL,
  subtipo_control_id smallint(5) unsigned DEFAULT NULL,
  corriente_clave smallint(5) unsigned DEFAULT NULL,
  activo enum('SI','NO') NOT NULL DEFAULT 'SI',
  orden int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (compatibilidad_id),
  UNIQUE KEY uk_rescate_compatibilidad (rescate_id, tipo_control_id, subtipo_control_id, corriente_clave),
  KEY idx_rescate_compatibilidad_busqueda (tipo_control_id, subtipo_control_id, corriente_clave, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rescates_codigos_corriente (
  articulo_rescate_id int(10) unsigned NOT NULL AUTO_INCREMENT,
  rescate_id int(10) unsigned NOT NULL,
  subtipo_control_id smallint(5) unsigned NOT NULL,
  corriente_clave smallint(5) unsigned NOT NULL,
  codigo varchar(100) NOT NULL,
  activo enum('SI','NO') NOT NULL DEFAULT 'SI',
  orden int(11) NOT NULL DEFAULT 100,
  PRIMARY KEY (articulo_rescate_id),
  UNIQUE KEY uk_rescate_articulo_corriente (rescate_id, subtipo_control_id, corriente_clave),
  KEY idx_rescate_articulo_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rescates_presentacion (
  rescate_id int(10) unsigned NOT NULL,
  modo_precio varchar(32) NOT NULL DEFAULT 'COMPONENTES',
  codigo_presentacion varchar(100) NOT NULL DEFAULT '',
  PRIMARY KEY (rescate_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Modelos solicitados. La clave primaria de senal_modelos_pulsador no usa AUTO_INCREMENT.
INSERT INTO senal_modelos_pulsador (modelo_pulsador_id, modelo_pulsador_nombre, discontinuado)
SELECT (SELECT COALESCE(MAX(m.modelo_pulsador_id),0)+1 FROM senal_modelos_pulsador m), 'ZERO BLACK', 'NO'
WHERE NOT EXISTS (SELECT 1 FROM senal_modelos_pulsador WHERE UPPER(TRIM(modelo_pulsador_nombre))='ZERO BLACK');

INSERT INTO senal_modelos_pulsador (modelo_pulsador_id, modelo_pulsador_nombre, discontinuado)
SELECT (SELECT COALESCE(MAX(m.modelo_pulsador_id),0)+1 FROM senal_modelos_pulsador m), 'PANTALLA TOUCH 15"', 'NO'
WHERE NOT EXISTS (SELECT 1 FROM senal_modelos_pulsador WHERE UPPER(TRIM(modelo_pulsador_nombre))='PANTALLA TOUCH 15"');

INSERT INTO senal_modelos_pulsador (modelo_pulsador_id, modelo_pulsador_nombre, discontinuado)
SELECT (SELECT COALESCE(MAX(m.modelo_pulsador_id),0)+1 FROM senal_modelos_pulsador m), 'PANTALLA TOUCH 15" (PAÑO NEGRO)', 'NO'
WHERE NOT EXISTS (SELECT 1 FROM senal_modelos_pulsador WHERE UPPER(TRIM(modelo_pulsador_nombre))='PANTALLA TOUCH 15" (PAÑO NEGRO)');

-- Perfiles funcionales iniciales: INSERT-only para no sobrescribir futuros ajustes de Mantenimiento.
INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,regla_adicional_parada,modelo_adicional_parada,modo_pulsador_exterior,orden)
SELECT m.modelo_pulsador_id,'ROND METAL','MODELO_PUERTA','REUTILIZAR_MODELO','METAL','MODELO',20 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='ROND METAL' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,indicador_incluido_descripcion,requiere_luz_cortesia,orden)
SELECT m.modelo_pulsador_id,'ONIX TELEFONICO','ONIX_TELEFONICO',0,0,0,'NINGUNA','Indicador 7 pulg Beaglebond',1,30 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='ONIX TELEFONICO' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,indicador_incluido_descripcion,requiere_luz_cortesia,orden)
SELECT m.modelo_pulsador_id,'ONIX INDIVIDUALES','ONIX_INDIVIDUALES',0,0,0,'A4830 Crystal Color',1,31 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='ONIX INDIVIDUALES' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,pantalla,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,requiere_luz_cortesia,orden)
SELECT m.modelo_pulsador_id,'PANTALLA 21','PANTALLA',1,0,1,0,'NINGUNA',1,10 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre)) LIKE 'PANTALLA 21%' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,tipo_modulo_requerido,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,modelo_adicional_parada,politica_acabado,acabado,modo_pulsador_exterior,separar_indicador_exterior,orden)
SELECT m.modelo_pulsador_id,'ZERO BLACK','MODELO_PUERTA','ELECTRONICO',1,1,0,'REUTILIZAR_MODELO','METAL','INCLUIDO_EN_PRECIO','ACERO NEGRO','MODELO',1,20 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='ZERO BLACK' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,pantalla,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,politica_acabado,acabado,requiere_luz_cortesia,orden)
SELECT m.modelo_pulsador_id,'PANTALLA 15','PANTALLA',1,0,1,0,'NINGUNA','INCLUIDO_EN_PRECIO','ACERO',1,11 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='PANTALLA TOUCH 15"' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

INSERT INTO senal_modelos_perfiles (modelo_pulsador_id,familia_comercial,modo_base,pantalla,indicador_cabina,indicador_pulsador,indicador_exterior_independiente,regla_adicional_parada,politica_acabado,acabado,requiere_luz_cortesia,orden)
SELECT m.modelo_pulsador_id,'PANTALLA 15','PANTALLA',1,0,1,0,'NINGUNA','INCLUIDO_EN_PRECIO','ACERO NEGRO',1,12 FROM senal_modelos_pulsador m
WHERE UPPER(TRIM(m.modelo_pulsador_nombre))='PANTALLA TOUCH 15" (PAÑO NEGRO)' AND NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

-- Perfil neutro para cualquier modelo histórico no tipificado.
INSERT INTO senal_modelos_perfiles (modelo_pulsador_id)
SELECT m.modelo_pulsador_id FROM senal_modelos_pulsador m
WHERE NOT EXISTS (SELECT 1 FROM senal_modelos_perfiles p WHERE p.modelo_pulsador_id=m.modelo_pulsador_id);

-- Los códigos de cabina se guardan en la matriz vigente; se copian solo los criterios
-- técnicos de ROND METAL para no inventar tensión, bornes, registro ni tecla.
INSERT INTO matriz_botoneras_cabina (tipo_puerta,modelo_pulsador_id,tension_modulo_id,borne_id,color_registro_id,tecla_id,codigo,activo)
SELECT src.tipo_puerta,dst.modelo_pulsador_id,src.tension_modulo_id,src.borne_id,src.color_registro_id,src.tecla_id,
       CASE src.tipo_puerta WHEN 'PA' THEN 'RMETA2ZBA0200M' ELSE 'RMET2ZBA0200M' END,'SI'
FROM matriz_botoneras_cabina src
INNER JOIN senal_modelos_pulsador mr ON UPPER(TRIM(mr.modelo_pulsador_nombre))='ROND METAL'
INNER JOIN senal_modelos_pulsador dst ON UPPER(TRIM(dst.modelo_pulsador_nombre))='ZERO BLACK'
WHERE src.modelo_pulsador_id=mr.modelo_pulsador_id AND src.activo='SI'
  AND src.matriz_botonera_id=(SELECT MIN(s2.matriz_botonera_id) FROM matriz_botoneras_cabina s2 WHERE s2.modelo_pulsador_id=mr.modelo_pulsador_id AND s2.tipo_puerta=src.tipo_puerta AND s2.activo='SI')
  AND NOT EXISTS (SELECT 1 FROM matriz_botoneras_cabina x WHERE x.modelo_pulsador_id=dst.modelo_pulsador_id AND x.tipo_puerta=src.tipo_puerta AND x.activo='SI');

-- La Pantalla 15 reutiliza los criterios activos de selección de Pantalla 21.
INSERT INTO matriz_botoneras_cabina (tipo_puerta,modelo_pulsador_id,tension_modulo_id,borne_id,color_registro_id,tecla_id,codigo,activo)
SELECT src.tipo_puerta,dst.modelo_pulsador_id,src.tension_modulo_id,src.borne_id,src.color_registro_id,src.tecla_id,
       CASE UPPER(TRIM(dst.modelo_pulsador_nombre)) WHEN 'PANTALLA TOUCH 15"' THEN 'A3T415C' ELSE 'A3T415CB' END,'SI'
FROM matriz_botoneras_cabina src
INNER JOIN senal_modelos_pulsador p21 ON UPPER(TRIM(p21.modelo_pulsador_nombre)) LIKE 'PANTALLA 21%'
INNER JOIN senal_modelos_pulsador dst ON UPPER(TRIM(dst.modelo_pulsador_nombre)) IN ('PANTALLA TOUCH 15"','PANTALLA TOUCH 15" (PAÑO NEGRO)')
WHERE src.modelo_pulsador_id=p21.modelo_pulsador_id AND src.activo='SI'
  AND NOT EXISTS (SELECT 1 FROM matriz_botoneras_cabina x WHERE x.modelo_pulsador_id=dst.modelo_pulsador_id AND x.tipo_puerta=src.tipo_puerta AND x.activo='SI');

-- Indicadores ZERO BLACK: las restricciones de contexto se registran aparte.
INSERT INTO senal_indicadores_cabina (modelo_indicador,tipo_modulos,codigo,activo,orden)
SELECT 'IND POS 18MM ZERO BLACK','ELECTRONICO','A4418CZ',1,300
WHERE NOT EXISTS (SELECT 1 FROM senal_indicadores_cabina WHERE UPPER(TRIM(codigo))='A4418CZ');
INSERT INTO senal_indicadores_cabina (modelo_indicador,tipo_modulos,codigo,activo,orden)
SELECT 'IND POS A4810 ZERO BLACK CRYSTAL BLACK HORIZONTAL','ELECTRONICO','A4810CZ',1,301
WHERE NOT EXISTS (SELECT 1 FROM senal_indicadores_cabina WHERE UPPER(TRIM(codigo))='A4810CZ');
INSERT INTO senal_indicadores_cabina (modelo_indicador,tipo_modulos,codigo,activo,orden)
SELECT 'IND POS A4830 ZERO BLACK CRYSTAL SLIM HORIZONTAL','ELECTRONICO','A4830CZ',1,302
WHERE NOT EXISTS (SELECT 1 FROM senal_indicadores_cabina WHERE UPPER(TRIM(codigo))='A4830CZ');

INSERT INTO senal_indicadores_contextos (codigo,contexto,activo,orden)
SELECT codigos.codigo,contextos.contexto,1,contextos.orden
FROM (
  SELECT 'A4418CZ' AS codigo UNION ALL SELECT 'A4810CZ' UNION ALL SELECT 'A4830CZ'
) codigos
CROSS JOIN (
  SELECT 'CABINA' AS contexto,10 AS orden UNION ALL SELECT 'PULSADOR_EXTERIOR',20
) contextos
WHERE NOT EXISTS (SELECT 1 FROM senal_indicadores_contextos c WHERE c.codigo=codigos.codigo AND c.contexto=contextos.contexto);

-- Los cuatro casos exteriores usan código de pulsador separado del indicador.
INSERT INTO senal_pulsadores_exteriores_matriz (familia,tipo_modulos,modelo_pulsador,color_registro,tension_modulos,bornes,tecla_modulos,codigo,activo,orden)
SELECT src.familia,src.tipo_modulos,'ZERO BLACK',src.color_registro,src.tension_modulos,src.bornes,src.tecla_modulos,
       CASE WHEN src.familia IN ('SIMPLE','SIMPLE_IP') THEN 'RMET11ZBA000M' ELSE 'RMET12ZBA000M' END,1,src.orden+300
FROM senal_pulsadores_exteriores_matriz src
WHERE UPPER(TRIM(src.modelo_pulsador))='METAL' AND UPPER(TRIM(src.tipo_modulos))='ELECTRONICO'
  AND src.activo=1 AND src.familia IN ('SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP')
  AND src.id=(SELECT MIN(s2.id) FROM senal_pulsadores_exteriores_matriz s2 WHERE s2.familia=src.familia AND UPPER(TRIM(s2.modelo_pulsador))='METAL' AND UPPER(TRIM(s2.tipo_modulos))='ELECTRONICO' AND s2.activo=1)
  AND NOT EXISTS (SELECT 1 FROM senal_pulsadores_exteriores_matriz x WHERE x.familia=src.familia AND UPPER(TRIM(x.tipo_modulos))='ELECTRONICO' AND UPPER(TRIM(x.modelo_pulsador))='ZERO BLACK' AND x.activo=1);

-- Pantalla 15 reutiliza los códigos y criterios exteriores vigentes de Pantalla 21.
INSERT INTO senal_pulsadores_exteriores_matriz (familia,tipo_modulos,modelo_pulsador,color_registro,tension_modulos,bornes,tecla_modulos,codigo,activo,orden)
SELECT src.familia,src.tipo_modulos,dst.modelo_pulsador_nombre,src.color_registro,src.tension_modulos,src.bornes,src.tecla_modulos,src.codigo,src.activo,src.orden+300
FROM senal_pulsadores_exteriores_matriz src
INNER JOIN senal_modelos_pulsador dst ON UPPER(TRIM(dst.modelo_pulsador_nombre)) IN ('PANTALLA TOUCH 15"','PANTALLA TOUCH 15" (PAÑO NEGRO)')
WHERE UPPER(TRIM(src.modelo_pulsador))='PANTALLA 21' AND src.activo=1
  AND src.familia IN ('SIMPLE','SIMPLE_IP','DOBLE','DOBLE_IP')
  AND NOT EXISTS (SELECT 1 FROM senal_pulsadores_exteriores_matriz x WHERE x.familia=src.familia AND UPPER(TRIM(x.tipo_modulos))=UPPER(TRIM(src.tipo_modulos)) AND UPPER(TRIM(x.modelo_pulsador))=(UPPER(TRIM(dst.modelo_pulsador_nombre)) COLLATE utf8mb4_unicode_ci) AND x.activo=1);

-- Rescate integral nuevo. Su precio se resolverá desde la lista Bejerman seleccionada.
INSERT INTO rescates_opciones (rescate_clave,rescate_nombre,rescate_familia,rescate_orden,rescate_activo)
SELECT 'REINT2KVA','RESCATE INTEGRAL P/GD390L C/UPS 2KVA','MRL_IMAN',15,'SI'
WHERE NOT EXISTS (SELECT 1 FROM rescates_opciones WHERE rescate_clave='REINT2KVA');

-- Códigos comerciales históricos: los componentes siguen siendo la fuente de precio.
INSERT INTO rescates_presentacion (rescate_id,modo_precio,codigo_presentacion)
SELECT r.rescate_id,'COMPONENTES',CASE r.rescate_clave
  WHEN 'HID_ECO_MIDI' THEN 'A0750CS'
  WHEN 'HID_220_SIN_UPS' THEN 'A6XREMPAH'
  WHEN 'HID_220_UPS08' THEN 'A6XREMPAH0.8'
  WHEN 'HID_VF_SIN_UPS' THEN 'A6XREMPVFH'
  WHEN 'HID_VF_UPS08' THEN 'A6XREMPVFH0.8'
  WHEN 'HID_COMPLETO_UPS' THEN 'REINTHIDRA'
  WHEN 'MRL_AUTO_SIN_UPS' THEN 'A6XREA'
  WHEN 'MRL_MANUAL_SIN_UPS' THEN 'A6XREM'
  WHEN 'MRL_AUTO_UPS08' THEN 'A6XREA0.8'
  WHEN 'MRL_MANUAL_UPS08' THEN 'A6XREM0.8'
  ELSE '' END
FROM rescates_opciones r
WHERE r.rescate_clave IN ('HID_ECO_MIDI','HID_220_SIN_UPS','HID_220_UPS08','HID_VF_SIN_UPS','HID_VF_UPS08','HID_COMPLETO_UPS','MRL_AUTO_SIN_UPS','MRL_MANUAL_SIN_UPS','MRL_AUTO_UPS08','MRL_MANUAL_UPS08')
  AND NOT EXISTS (SELECT 1 FROM rescates_presentacion p WHERE p.rescate_id=r.rescate_id);

INSERT INTO rescates_presentacion (rescate_id,modo_precio,codigo_presentacion)
SELECT r.rescate_id,'ARTICULO_CORRIENTE',''
FROM rescates_opciones r
WHERE r.rescate_clave IN ('INVT_INTEGRAL_SIN_UPS','REINT2KVA')
  AND NOT EXISTS (SELECT 1 FROM rescates_presentacion p WHERE p.rescate_id=r.rescate_id);

-- Compatibilidades legacy: reproducen la visibilidad anterior por familia.
INSERT INTO rescates_compatibilidades (rescate_id,tipo_control_id,subtipo_control_id,corriente_clave,activo,orden)
SELECT r.rescate_id,3,NULL,NULL,'SI',10 FROM rescates_opciones r
WHERE r.rescate_clave IN ('HID_ECO_MIDI','HID_220_SIN_UPS','HID_220_UPS08','HID_VF_SIN_UPS','HID_VF_UPS08','HID_COMPLETO_UPS')
  AND NOT EXISTS (SELECT 1 FROM rescates_compatibilidades c WHERE c.rescate_id=r.rescate_id AND c.tipo_control_id=3 AND c.subtipo_control_id IS NULL AND c.corriente_clave IS NULL);

INSERT INTO rescates_compatibilidades (rescate_id,tipo_control_id,subtipo_control_id,corriente_clave,activo,orden)
SELECT r.rescate_id,t.ctrltipo_id,NULL,NULL,'SI',10 FROM rescates_opciones r
INNER JOIN tipos_control t ON t.ctrltipo_id IN (4,5,6,7)
WHERE r.rescate_clave IN ('MRL_AUTO_SIN_UPS','MRL_MANUAL_SIN_UPS','MRL_AUTO_UPS08','MRL_MANUAL_UPS08')
  AND NOT EXISTS (SELECT 1 FROM rescates_compatibilidades c WHERE c.rescate_id=r.rescate_id AND c.tipo_control_id=t.ctrltipo_id AND c.subtipo_control_id IS NULL AND c.corriente_clave IS NULL);

INSERT INTO rescates_compatibilidades (rescate_id,tipo_control_id,subtipo_control_id,corriente_clave,activo,orden)
SELECT r.rescate_id,t.ctrltipo_id,s.ctrlsubtipo_id,c.corriente,'SI',20
FROM rescates_opciones r
INNER JOIN tipos_control t ON t.ctrltipo_id IN (4,5,6,7)
INNER JOIN subtipos_control s ON s.ctrlsubtipo_id IN (12,14)
CROSS JOIN (
  SELECT 10 AS corriente UNION ALL SELECT 14 UNION ALL SELECT 18 UNION ALL SELECT 25
  UNION ALL SELECT 32 UNION ALL SELECT 39 UNION ALL SELECT 45 UNION ALL SELECT 60
) c
WHERE r.rescate_clave='INVT_INTEGRAL_SIN_UPS'
  AND NOT EXISTS (SELECT 1 FROM rescates_compatibilidades x WHERE x.rescate_id=r.rescate_id AND x.tipo_control_id=t.ctrltipo_id AND x.subtipo_control_id=s.ctrlsubtipo_id AND x.corriente_clave=c.corriente);

INSERT INTO rescates_codigos_corriente (rescate_id,subtipo_control_id,corriente_clave,codigo,activo,orden)
SELECT r.rescate_id,s.ctrlsubtipo_id,c.corriente,
       CASE c.corriente WHEN 10 THEN 'REINTI10' WHEN 14 THEN 'REINTI14' WHEN 18 THEN 'REINTI18' WHEN 25 THEN 'REINTI25' WHEN 32 THEN 'REINTI32' WHEN 39 THEN 'REINTI39' WHEN 45 THEN 'REINTI45' WHEN 60 THEN 'REINTI60' END,
       'SI',c.corriente
FROM rescates_opciones r
INNER JOIN subtipos_control s ON s.ctrlsubtipo_id IN (12,14)
CROSS JOIN (
  SELECT 10 AS corriente UNION ALL SELECT 14 UNION ALL SELECT 18 UNION ALL SELECT 25
  UNION ALL SELECT 32 UNION ALL SELECT 39 UNION ALL SELECT 45 UNION ALL SELECT 60
) c
WHERE r.rescate_clave='INVT_INTEGRAL_SIN_UPS'
  AND NOT EXISTS (SELECT 1 FROM rescates_codigos_corriente a WHERE a.rescate_id=r.rescate_id AND a.subtipo_control_id=s.ctrlsubtipo_id AND a.corriente_clave=c.corriente);

INSERT INTO rescates_compatibilidades (rescate_id,tipo_control_id,subtipo_control_id,corriente_clave,activo,orden)
SELECT r.rescate_id,5,14,c.corriente,'SI',15
FROM rescates_opciones r
CROSS JOIN (SELECT 14 AS corriente UNION ALL SELECT 18) c
WHERE r.rescate_clave='REINT2KVA'
  AND NOT EXISTS (SELECT 1 FROM rescates_compatibilidades x WHERE x.rescate_id=r.rescate_id AND x.tipo_control_id=5 AND x.subtipo_control_id=14 AND x.corriente_clave=c.corriente);

INSERT INTO rescates_codigos_corriente (rescate_id,subtipo_control_id,corriente_clave,codigo,activo,orden)
SELECT r.rescate_id,14,c.corriente,'REINT2KVA','SI',c.corriente
FROM rescates_opciones r
CROSS JOIN (SELECT 14 AS corriente UNION ALL SELECT 18) c
WHERE r.rescate_clave='REINT2KVA'
  AND NOT EXISTS (SELECT 1 FROM rescates_codigos_corriente a WHERE a.rescate_id=r.rescate_id AND a.subtipo_control_id=14 AND a.corriente_clave=c.corriente);
