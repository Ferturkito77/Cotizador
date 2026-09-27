<?php
/**
 * Utilidades locales para importar clientes/CECAF desde XLSX o CSV.
 * No requiere Composer. Para XLSX utiliza ZipArchive, disponible normalmente
 * en XAMPP con la extensión php_zip habilitada.
 */

function clientesNormalizarEncabezado($valor): string
{
    $valor = trim((string)$valor);
    if ($valor === '') return '';
    $mapa = array(
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n'
    );
    $valor = strtr($valor, $mapa);
    $valor = strtoupper($valor);
    $valor = preg_replace('/[^A-Z0-9]+/', ' ', $valor);
    return trim((string)$valor);
}

function clientesNormalizarTexto($valor): string
{
    $valor = trim((string)$valor);
    if ($valor === '') return '';
    $mapa = array(
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N',
        'á'=>'A','é'=>'E','í'=>'I','ó'=>'O','ú'=>'U','ü'=>'U','ñ'=>'N'
    );
    $valor = strtr($valor, $mapa);
    $valor = strtoupper($valor);
    $valor = preg_replace('/[^A-Z0-9]+/', ' ', $valor);
    $valor = preg_replace('/\s+/', ' ', (string)$valor);
    return trim((string)$valor);
}

function clientesValorPorIndice(array $fila, int $indice): string
{
    if ($indice < 0 || !array_key_exists($indice, $fila)) return '';
    $v = $fila[$indice];
    if ($v === null) return '';
    if (is_bool($v)) return $v ? '1' : '0';
    if (is_float($v) && floor($v) == $v) return (string)(int)$v;
    return trim((string)$v);
}

function clientesLeerCsv(string $ruta): array
{
    $fh = fopen($ruta, 'rb');
    if (!$fh) throw new RuntimeException('No se pudo abrir el archivo CSV.');
    $primera = fgets($fh);
    if ($primera === false) { fclose($fh); return array('headers'=>array(),'rows'=>array()); }
    $separador = substr_count($primera, ';') >= substr_count($primera, ',') ? ';' : ',';
    rewind($fh);
    $datos = array();
    while (($fila = fgetcsv($fh, 0, $separador)) !== false) {
        if (count($fila) === 1 && trim((string)$fila[0]) === '') continue;
        $datos[] = $fila;
    }
    fclose($fh);
    if (!$datos) return array('headers'=>array(),'rows'=>array());
    $headers = array_map(static function($v){ return trim((string)$v); }, array_shift($datos));
    return array('headers'=>$headers,'rows'=>$datos);
}

function clientesXlsxSharedStrings(ZipArchive $zip): array
{
    $xml = $zip->getFromName('xl/sharedStrings.xml');
    if ($xml === false) return array();
    $sx = simplexml_load_string($xml);
    if ($sx === false) return array();
    $out = array();
    foreach ($sx->si as $si) {
        if (isset($si->t)) {
            $out[] = (string)$si->t;
            continue;
        }
        $partes = array();
        if (isset($si->r)) {
            foreach ($si->r as $r) $partes[] = (string)$r->t;
        }
        $out[] = implode('', $partes);
    }
    return $out;
}

function clientesColumnaAIndice(string $ref): int
{
    if (!preg_match('/^([A-Z]+)/i', $ref, $m)) return 0;
    $letras = strtoupper($m[1]);
    $n = 0;
    for ($i=0, $l=strlen($letras); $i<$l; $i++) $n = $n * 26 + (ord($letras[$i]) - 64);
    return max(0, $n - 1);
}

function clientesLeerXlsx(string $ruta): array
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('Para importar XLSX debe habilitarse la extensión PHP zip (ZipArchive) en XAMPP. Como alternativa puede guardar el archivo como CSV.');
    }
    if (!function_exists('simplexml_load_string')) {
        throw new RuntimeException('Para importar XLSX debe habilitarse SimpleXML en PHP. Como alternativa puede guardar el archivo como CSV.');
    }
    $zip = new ZipArchive();
    if ($zip->open($ruta) !== true) throw new RuntimeException('No se pudo abrir el archivo XLSX.');
    try {
        $shared = clientesXlsxSharedStrings($zip);
        $workbookXml = $zip->getFromName('xl/workbook.xml');
        $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbookXml === false || $relsXml === false) throw new RuntimeException('El XLSX no contiene una estructura de libro válida.');
        $wb = simplexml_load_string($workbookXml);
        $rels = simplexml_load_string($relsXml);
        if ($wb === false || $rels === false) throw new RuntimeException('No se pudo interpretar el libro XLSX.');
        $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $relMap = array();
        foreach ($rels->Relationship as $r) $relMap[(string)$r['Id']] = (string)$r['Target'];
        $sheet = $wb->sheets->sheet[0] ?? null;
        if (!$sheet) throw new RuntimeException('El XLSX no tiene hojas.');
        $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
        $rid = (string)$attrs['id'];
        $target = $relMap[$rid] ?? 'worksheets/sheet1.xml';
        $sheetPath = (substr($target,0,1)==='/') ? ltrim($target, '/') : 'xl/' . ltrim($target, '/');
        $sheetXml = $zip->getFromName($sheetPath);
        if ($sheetXml === false) throw new RuntimeException('No se pudo leer la primera hoja del XLSX.');
        $sx = simplexml_load_string($sheetXml);
        if ($sx === false) throw new RuntimeException('No se pudo interpretar la hoja XLSX.');
        $filas = array();
        foreach ($sx->sheetData->row as $row) {
            $fila = array();
            $max = -1;
            foreach ($row->c as $c) {
                $idx = clientesColumnaAIndice((string)$c['r']);
                $tipo = (string)$c['t'];
                $valor = '';
                if ($tipo === 'inlineStr') {
                    $valor = isset($c->is->t) ? (string)$c->is->t : '';
                } else {
                    $raw = isset($c->v) ? (string)$c->v : '';
                    if ($tipo === 's') $valor = $shared[(int)$raw] ?? '';
                    elseif ($tipo === 'b') $valor = $raw === '1' ? '1' : '0';
                    else $valor = $raw;
                }
                $fila[$idx] = $valor;
                if ($idx > $max) $max = $idx;
            }
            if ($max < 0) continue;
            $normal = array();
            for ($i=0; $i<=$max; $i++) $normal[] = $fila[$i] ?? '';
            $filas[] = $normal;
        }
        if (!$filas) return array('headers'=>array(),'rows'=>array());
        $headers = array_map(static function($v){ return trim((string)$v); }, array_shift($filas));
        return array('headers'=>$headers,'rows'=>$filas);
    } finally {
        $zip->close();
    }
}

function clientesLeerArchivoTabular(string $ruta, string $nombreOriginal=''): array
{
    $ext = strtolower(pathinfo($nombreOriginal ?: $ruta, PATHINFO_EXTENSION));
    if ($ext === 'xlsx') return clientesLeerXlsx($ruta);
    if ($ext === 'csv' || $ext === 'txt') return clientesLeerCsv($ruta);
    throw new RuntimeException('Formato no admitido. Use XLSX o CSV.');
}

function clientesBuscarColumna(array $headers, array $variantes): int
{
    $normalizados = array_map('clientesNormalizarEncabezado', $headers);
    foreach ($variantes as $v) {
        $nv = clientesNormalizarEncabezado($v);
        foreach ($normalizados as $i => $h) if ($h === $nv) return (int)$i;
    }
    foreach ($variantes as $v) {
        $nv = clientesNormalizarEncabezado($v);
        foreach ($normalizados as $i => $h) if ($nv !== '' && strpos($h, $nv) !== false) return (int)$i;
    }
    return -1;
}

function clientesDescuentoAPorcentaje($valor): float
{
    if ($valor === null || trim((string)$valor) === '') return 0.0;
    $s = str_replace(array('%', ' '), '', trim((string)$valor));
    $s = str_replace(',', '.', $s);
    if (!is_numeric($s)) return 0.0;
    $n = (float)$s;
    if ($n >= 0 && $n <= 1.000001) $n *= 100;
    return round(max(0, min(100, $n)), 2);
}

function clientesNumeroEnteroONull($valor): ?int
{
    $s = trim((string)$valor);
    if ($s === '') return null;
    $s = preg_replace('/[^0-9-]/', '', $s);
    return ($s === '' || $s === '-') ? null : (int)$s;
}

function clientesTextoONull($valor): ?string
{
    $s = trim((string)$valor);
    return $s === '' ? null : $s;
}
