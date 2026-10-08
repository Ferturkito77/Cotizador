<?php
/* v38 - Pedido en una linea + Botonera comercial agrupada (base + paradas + indicador). */
require_once __DIR__ . '/documentos_storage.php';
require_once __DIR__ . '/services/control_motor.php';
/**
 * Generación de documentos comerciales PDF sin dependencias externas.
 * Los PDF quedan congelados en /pdf/cotizaciones y /pdf/pedidos.
 */

class PdfAutomac
{
    private $pages = array();
    private $content = array();
    private $x = 42;
    private $y = 800;
    private $pageWidth = 595.28;
    private $pageHeight = 841.89;
    private $margin = 42;
    private $fontSize = 9;
    private $images = array();

    public function __construct()
    {
        $this->newPage();
    }

    private function enc(string $text): string
    {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        return $converted === false ? $text : $converted;
    }

    private function esc(string $text): string
    {
        return str_replace(array('\\', '(', ')', "\r", "\n"), array('\\\\', '\\(', '\\)', '', ' '), $this->enc($text));
    }

    private function cmd(string $command): void
    {
        $this->content[] = $command;
    }

    public function newPage(): void
    {
        if ($this->content) {
            $this->pages[] = implode("\n", $this->content);
        }
        $this->content = array();
        $this->x = $this->margin;
        $this->y = $this->pageHeight - $this->margin;
    }

    public function setY(float $y): void { $this->y = $y; }
    public function getY(): float { return $this->y; }

    public function text(float $x, float $y, string $text, int $size = 9, bool $bold = false): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->cmd("BT /{$font} {$size} Tf 1 0 0 1 " . $this->n($x) . ' ' . $this->n($y) . ' Tm (' . $this->esc($text) . ') Tj ET');
    }

    public function line(float $x1, float $y1, float $x2, float $y2, float $width = 0.5): void
    {
        $this->cmd($this->n($width) . ' w ' . $this->n($x1) . ' ' . $this->n($y1) . ' m ' . $this->n($x2) . ' ' . $this->n($y2) . ' l S');
    }

    private function roundedPath(float $x, float $y, float $w, float $h, float $radius): string
    {
        $r = max(0.0, min($radius, $w / 2, $h / 2));
        if ($r <= 0.01) {
            return $this->n($x) . ' ' . $this->n($y) . ' ' . $this->n($w) . ' ' . $this->n($h) . ' re';
        }
        // Aproximacion Bezier de un cuarto de circunferencia.
        $k = 0.5522847498;
        $c = $r * $k;
        $x2 = $x + $w;
        $y2 = $y + $h;
        return implode(' ', array(
            $this->n($x + $r), $this->n($y), 'm',
            $this->n($x2 - $r), $this->n($y), 'l',
            $this->n($x2 - $r + $c), $this->n($y), $this->n($x2), $this->n($y + $r - $c), $this->n($x2), $this->n($y + $r), 'c',
            $this->n($x2), $this->n($y2 - $r), 'l',
            $this->n($x2), $this->n($y2 - $r + $c), $this->n($x2 - $r + $c), $this->n($y2), $this->n($x2 - $r), $this->n($y2), 'c',
            $this->n($x + $r), $this->n($y2), 'l',
            $this->n($x + $r - $c), $this->n($y2), $this->n($x), $this->n($y2 - $r + $c), $this->n($x), $this->n($y2 - $r), 'c',
            $this->n($x), $this->n($y + $r), 'l',
            $this->n($x), $this->n($y + $r - $c), $this->n($x + $r - $c), $this->n($y), $this->n($x + $r), $this->n($y), 'c',
            'h'
        ));
    }

    public function fillColorRect(float $x, float $y, float $w, float $h, int $r, int $g, int $b): void
    {
        // v47: suavizado visual general. Las barras finas de acento quedan rectas.
        $radius = ($w > 12 && $h > 12) ? min(5.0, $h / 4) : 0.0;
        $path = $this->roundedPath($x, $y, $w, $h, $radius);
        $this->cmd($this->n($r/255) . ' ' . $this->n($g/255) . ' ' . $this->n($b/255) . ' rg ' . $path . ' f 0 g');
    }

    public function roundedRect(float $x, float $y, float $w, float $h, float $radius = 5.0, bool $fill = false, float $gray = 0.92): void
    {
        $path = $this->roundedPath($x, $y, $w, $h, $radius);
        if ($fill) {
            $this->cmd($this->n($gray) . ' g ' . $path . ' f 0 g');
        } else {
            $this->cmd('0.6 G 0.5 w ' . $path . ' S 0 G');
        }
    }

    public function colorText(float $x, float $y, string $text, int $size, bool $bold, int $r, int $g, int $b): void
    {
        $font = $bold ? 'F2' : 'F1';
        $this->cmd($this->n($r/255) . ' ' . $this->n($g/255) . ' ' . $this->n($b/255) . " rg BT /{$font} {$size} Tf 1 0 0 1 " . $this->n($x) . ' ' . $this->n($y) . ' Tm (' . $this->esc($text) . ') Tj ET 0 g');
    }

    /** v404: iconos vectoriales simples, pensados para PDF y blanco/negro. */
    public function commercialIcon(float $x, float $y, string $tipo): void
    {
        $t = strtoupper(trim($tipo));
        $this->roundedRect($x, $y, 18, 18, 4, false);
        if ($t === 'ENTREGA') {
            // Camion: caja + cabina + ruedas.
            $this->rect($x+3, $y+7, 8, 6, false);
            $this->line($x+11, $y+7, $x+15, $y+7, .7);
            $this->line($x+15, $y+7, $x+15, $y+11, .7);
            $this->line($x+15, $y+11, $x+12.5, $y+13, .7);
            $this->line($x+12.5, $y+13, $x+11, $y+13, .7);
            $this->text($x+4, $y+2.6, 'o', 5, true);
            $this->text($x+12, $y+2.6, 'o', 5, true);
        } elseif ($t === 'PAGO') {
            // Tarjeta / comprobante.
            $this->rect($x+3, $y+4, 12, 10, false);
            $this->line($x+4, $y+11, $x+14, $y+11, 1.1);
            $this->text($x+7, $y+5.8, '$', 6, true);
        } else {
            // Comprobante IVA / porcentaje.
            $this->rect($x+4, $y+3, 10, 12, false);
            $this->text($x+6, $y+6.4, '%', 6, true);
            $this->line($x+6, $y+12, $x+12, $y+12, .5);
        }
    }



    /** v415: iconos oficiales de modulos. Mismo criterio visual que el cotizador. */
    public function moduleIcon(float $x, float $y, string $modulo, float $size = 14.0, int $r = 0, int $g = 0, int $b = 0): void
    {
        $m = strtoupper(trim($modulo));
        $sx = $size / 24.0;
        $X = static function(float $v) use ($x, $sx): float { return $x + ($v * $sx); };
        $Y = static function(float $v) use ($y, $sx): float { return $y + ($v * $sx); };
        $n = static function(float $v): string { return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.'); };
        $stroke = $n($r/255).' '.$n($g/255).' '.$n($b/255).' RG ';
        $w = max(.55, .95*$sx);
        $this->cmd($stroke.$n($w).' w 1 J 1 j');
        $line = function(float $x1,float $y1,float $x2,float $y2) use($X,$Y,$n){
            $this->cmd($n($X($x1)).' '.$n($Y($y1)).' m '.$n($X($x2)).' '.$n($Y($y2)).' l S');
        };
        $rect = function(float $rx,float $ry,float $rw,float $rh) use($X,$Y,$sx,$n){
            $this->cmd($n($X($rx)).' '.$n($Y($ry)).' '.$n($rw*$sx).' '.$n($rh*$sx).' re S');
        };
        $circle = function(float $cx,float $cy,float $rad) use($X,$Y,$sx,$n){
            $k=.5522847498; $rr=$rad*$sx; $cc=$rr*$k; $xx=$X($cx); $yy=$Y($cy);
            $this->cmd(implode(' ',array(
                $n($xx+$rr),$n($yy),'m',
                $n($xx+$rr),$n($yy+$cc),$n($xx+$cc),$n($yy+$rr),$n($xx),$n($yy+$rr),'c',
                $n($xx-$cc),$n($yy+$rr),$n($xx-$rr),$n($yy+$cc),$n($xx-$rr),$n($yy),'c',
                $n($xx-$rr),$n($yy-$cc),$n($xx-$cc),$n($yy-$rr),$n($xx),$n($yy-$rr),'c',
                $n($xx+$cc),$n($yy-$rr),$n($xx+$rr),$n($yy-$cc),$n($xx+$rr),$n($yy),'c S'
            )));
        };
        if ($m === 'CONTROL') {
            // Engranaje: aro central y 8 radios cortos.
            $circle(12,12,3.2); $circle(12,12,7.1);
            foreach(array(array(12,4.9,12,2.5),array(12,19.1,12,21.5),array(4.9,12,2.5,12),array(19.1,12,21.5,12),array(7,7,5.3,5.3),array(17,17,18.7,18.7),array(17,7,18.7,5.3),array(7,17,5.3,18.7)) as $a)$line(...$a);
        } elseif ($m === 'SENALIZACION') {
            // Panel / botonera.
            $rect(5,3,14,18); $line(9,8,15,8); $line(9,12,15,12); $circle(12,17,1.1);
        } elseif ($m === 'ACCESORIOS') {
            // Llave + herramienta cruzada.
            $circle(15.7,7.3,3.0); $line(13.6,9.4,5.0,18.0); $line(5.0,18.0,7.0,20.0); $line(7.0,20.0,15.6,11.4);
            $line(5.0,5.0,11.0,11.0); $line(4.0,4.0,6.2,4.7); $line(4.0,4.0,4.7,6.2);
        } elseif ($m === 'IEP') {
            // Cubo.
            $line(4,7,12,3); $line(12,3,20,7); $line(20,7,12,11); $line(12,11,4,7);
            $line(4,7,4,17); $line(4,17,12,21); $line(12,21,20,17); $line(20,17,20,7); $line(12,11,12,21);
        } elseif ($m === 'REPUESTOS') {
            // Llave simple, igual al modulo Repuestos del cotizador.
            $circle(15.7,7.3,3.0); $line(13.6,9.4,4.5,18.5); $line(4.5,18.5,6.5,20.5); $line(6.5,20.5,15.6,11.4);
        } else {
            $rect(5,5,14,14);
        }
        $this->cmd('0 G');
    }
    public function rect(float $x, float $y, float $w, float $h, bool $fill = false, float $gray = 0.92): void
    {
        if ($fill) {
            $this->cmd($this->n($gray) . ' g ' . $this->n($x) . ' ' . $this->n($y) . ' ' . $this->n($w) . ' ' . $this->n($h) . ' re f 0 g');
        } else {
            $this->cmd('0.6 G 0.5 w ' . $this->n($x) . ' ' . $this->n($y) . ' ' . $this->n($w) . ' ' . $this->n($h) . ' re S 0 G');
        }
    }

    private function n(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }


    private function uLen(string $text): int
    {
        if (function_exists('mb_strlen')) return mb_strlen($text, 'UTF-8');
        if (function_exists('iconv_strlen')) { $n=@iconv_strlen($text, 'UTF-8'); if ($n!==false) return (int)$n; }
        return strlen($text);
    }

    private function uSubstr(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) return (string)mb_substr($text, $start, $length, 'UTF-8');
        if (function_exists('iconv_substr')) { $v=@iconv_substr($text, $start, $length, 'UTF-8'); if ($v!==false) return (string)$v; }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    public function wrap(string $text, float $width, float $size = 9): array
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if ($text === '') return array('');
        $average = max(4.2, $size * 0.52);
        $maxChars = max(8, (int)floor($width / $average));
        $words = preg_split('/\s+/u', $text) ?: array($text);
        $lines = array(); $line = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($this->uLen($candidate) <= $maxChars) {
                $line = $candidate;
            } else {
                if ($line !== '') $lines[] = $line;
                while ($this->uLen($word) > $maxChars) {
                    $lines[] = $this->uSubstr($word, 0, $maxChars);
                    $word = $this->uSubstr($word, $maxChars);
                }
                $line = $word;
            }
        }
        if ($line !== '') $lines[] = $line;
        return $lines;
    }

    public function tableHeaderCompact(float &$y, array $columns, int $r=18, int $g=50, int $b=91): void
    {
        $x = $this->margin; $h = 16;
        $totalW = array_sum(array_column($columns, 'w'));
        // v459: cabecera suave, legible en pantalla y en impresion B/N.
        $this->fillColorRect($x, $y - $h + 3, $totalW, $h, 235, 242, 247);
        $this->fillColorRect($x, $y - $h + 3, 3.5, $h, 18, 50, 91);
        foreach ($columns as $col) {
            $this->colorText($x + 5, $y - 6.4, $col['label'], 7.8, true, 18, 50, 91);
            $x += $col['w'];
        }
        $y -= $h;
    }

    public function tableRowCompact(float &$y, array $columns, array $values, int $size = 7, int $maxLinesAllowed = 2): void
    {
        $lineHeight = 8;
        $lineSets = array(); $maxLines = 1;
        foreach ($columns as $i => $col) {
            $all = $this->wrap((string)($values[$i] ?? ''), $col['w'] - 7, $size);
            if (count($all) > $maxLinesAllowed) {
                $all = array_slice($all, 0, $maxLinesAllowed);
                $last = count($all)-1;
                $all[$last] = rtrim($all[$last], ' .') . '...';
            }
            $lineSets[$i] = $all;
            $maxLines = max($maxLines, count($all));
        }
        $h = max(18, $maxLines * $lineHeight + 7);
        if ($y - $h < 115) {
            $this->newPage();
            $y = 795;
            $this->tableHeaderCompact($y, $columns);
        }
        $x = $this->margin;
        $this->rect($x, $y - $h + 3, array_sum(array_column($columns, 'w')), $h);
        foreach ($columns as $i => $col) {
            $align = $col['align'] ?? 'left';
            foreach ($lineSets[$i] as $j => $line) {
                $tx = $x + 4;
                if ($align === 'right') {
                    $estimated = $this->uLen($line) * $size * 0.48;
                    $tx = $x + $col['w'] - 4 - $estimated;
                } elseif ($align === 'center') {
                    $estimated = $this->uLen($line) * $size * 0.48;
                    $tx = $x + max(4, ($col['w'] - $estimated) / 2);
                }
                $this->text($tx, $y - 8 - ($j * $lineHeight), $line, $size, false);
            }
            $x += $col['w'];
        }
        $y -= $h;
    }

    /** v403: fila de una sola hoja. Descripcion puede envolver; codigos e importes no se parten. */
    public function tableRowOnePage(float &$y, array $columns, array $values, float $size = 5.7): void
    {
        $lineHeight = max(7.0, $size * 1.00);
        $lineSets = array(); $fontSizes = array(); $maxLines = 1;
        foreach ($columns as $i => $col) {
            $valor=(string)($values[$i] ?? '');
            if (!empty($col['nowrap'])) {
                $all=array($valor);
                $fs=$size;
                $anchoDisponible=max(10.0,$col['w']-6);
                $estimado=$this->uLen($valor)*$fs*0.48;
                if($estimado>$anchoDisponible && $estimado>0){
                    $fs=max((float)($col['min_font'] ?? 5.8),$fs*($anchoDisponible/$estimado));
                }
                $fontSizes[$i]=$fs;
            } else {
                $all = $this->wrap($valor, $col['w'] - 6, max(6.0, $size));
                $fontSizes[$i]=$size;
            }
            $lineSets[$i] = $all;
            $maxLines = max($maxLines, count($all));
        }
        $h = max(14.5, $maxLines * $lineHeight + 4.0);
        $x = $this->margin;
        $this->rect($x, $y - $h + 2, array_sum(array_column($columns, 'w')), $h);
        foreach ($columns as $i => $col) {
            $align = $col['align'] ?? 'left';
            $fs=$fontSizes[$i]??$size;
            foreach ($lineSets[$i] as $j => $line) {
                $tx = $x + 3;
                if ($align === 'right') {
                    $estimated = $this->uLen($line) * $fs * 0.48;
                    $tx = $x + $col['w'] - 3 - $estimated;
                } elseif ($align === 'center') {
                    $estimated = $this->uLen($line) * $fs * 0.48;
                    $tx = $x + max(3, ($col['w'] - $estimated) / 2);
                }
                $this->text($tx, $y - 7.4 - ($j * $lineHeight), $line, $fs, false);
            }
            $x += $col['w'];
        }
        $y -= $h;
    }

    public function paragraph(float $x, float &$y, string $text, float $width, int $size = 9, float $leading = 12, bool $bold = false): void
    {
        foreach ($this->wrap($text, $width, $size) as $line) {
            $this->text($x, $y, $line, $size, $bold);
            $y -= $leading;
        }
    }

    public function tableHeader(float &$y, array $columns): void
    {
        $x = $this->margin; $h = 24;
        $this->fillColorRect($x, $y - $h + 6, array_sum(array_column($columns, 'w')), $h, 16, 44, 61);
        foreach ($columns as $col) {
            $this->colorText($x + 4, $y - 9, $col['label'], 8, true, 255, 255, 255);
            $x += $col['w'];
        }
        $y -= $h;
    }

    public function tableRow(float &$y, array $columns, array $values, int $size = 8): void
    {
        $lineHeight = 10;
        $lineSets = array(); $maxLines = 1;
        foreach ($columns as $i => $col) {
            $lineSets[$i] = $this->wrap((string)($values[$i] ?? ''), $col['w'] - 8, $size);
            $maxLines = max($maxLines, count($lineSets[$i]));
        }
        $h = max(25, $maxLines * $lineHeight + 10);
        if ($y - $h < 90) {
            $this->newPage();
            $y = $this->pageHeight - $this->margin;
            $this->tableHeader($y, $columns);
        }
        $x = $this->margin;
        $this->rect($x, $y - $h + 4, array_sum(array_column($columns, 'w')), $h);
        foreach ($columns as $i => $col) {
            $align = $col['align'] ?? 'left';
            foreach ($lineSets[$i] as $j => $line) {
                $tx = $x + 4;
                if ($align === 'right') {
                    $estimated = $this->uLen($line) * $size * 0.48;
                    $tx = $x + $col['w'] - 4 - $estimated;
                } elseif ($align === 'center') {
                    $estimated = $this->uLen($line) * $size * 0.48;
                    $tx = $x + max(4, ($col['w'] - $estimated) / 2);
                }
                $this->text($tx, $y - 10 - ($j * $lineHeight), $line, $size, false);
            }
            $x += $col['w'];
        }
        $y -= $h;
    }

    public function imageJpeg(string $path, float $x, float $y, float $w, float $h): void
    {
        if (!is_file($path)) return;
        $info = @getimagesize($path);
        if (!$info || (($info[2] ?? 0) !== IMAGETYPE_JPEG)) return;
        $key = md5($path);
        if (!isset($this->images[$key])) {
            $data = @file_get_contents($path);
            if ($data === false) return;
            $this->images[$key] = array(
                'name' => 'Im' . (count($this->images) + 1),
                'w' => (int)$info[0],
                'h' => (int)$info[1],
                'data' => $data,
            );
        }
        $name = $this->images[$key]['name'];
        $this->cmd('q ' . $this->n($w) . ' 0 0 ' . $this->n($h) . ' ' . $this->n($x) . ' ' . $this->n($y) . ' cm /' . $name . ' Do Q');
    }

    public function automacLogo(float $x, float $y, float $w = 58): void
    {
        $path = __DIR__ . '/assets/automac-logo-pdf-clean.jpg';
        $w = min($w, 54.0);
        $h = $w * (150 / 286);
        $this->imageJpeg($path, $x, $y, $w, $h);
    }

    public function output(string $path): void
    {
        if ($this->content) $this->pages[] = implode("\n", $this->content);
        $objects = array();
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $kids = array();
        $font1 = 3; $font2 = 4;
        $objects[$font1] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[$font2] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';
        $next = 5;
        $imageRefs = array();
        foreach ($this->images as $img) {
            $imgObj = $next++;
            $imageRefs[$img['name']] = $imgObj;
            $data = $img['data'];
            $objects[$imgObj] = "<< /Type /XObject /Subtype /Image /Width {$img['w']} /Height {$img['h']} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($data) . " >>\nstream\n" . $data . "\nendstream";
        }
        $xObjectResources = '';
        if ($imageRefs) {
            $parts = array();
            foreach ($imageRefs as $name => $obj) $parts[] = '/' . $name . ' ' . $obj . ' 0 R';
            $xObjectResources = ' /XObject << ' . implode(' ', $parts) . ' >>';
        }
        foreach ($this->pages as $pageContent) {
            $pageObj = $next++; $contentObj = $next++;
            $kids[] = $pageObj . ' 0 R';
            $stream = $pageContent;
            $objects[$contentObj] = "<< /Length " . strlen($stream) . " >>\nstream\n{$stream}\nendstream";
            $objects[$pageObj] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595.28 841.89] /Resources << /Font << /F1 3 0 R /F2 4 0 R >>' . $xObjectResources . ' >> /Contents ' . $contentObj . ' 0 R >>';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = array(0);
        foreach ($objects as $num => $body) {
            $offsets[$num] = strlen($pdf);
            $pdf .= $num . " 0 obj\n" . $body . "\nendobj\n";
        }
        $xref = strlen($pdf);
        $maxObj = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($maxObj + 1) . "\n0000000000 65535 f \n";
        for ($i = 1; $i <= $maxObj; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i] ?? 0) . "\n";
        }
        $pdf .= "trailer\n<< /Size " . ($maxObj + 1) . " /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF";
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException('No se pudo crear la carpeta de documentos PDF.');
        }
        if (file_put_contents($path, $pdf) === false) {
            throw new RuntimeException('No se pudo guardar el documento PDF.');
        }
    }
}


function pdfProtegerTokensTecnicos(string $texto, array &$tokensProtegidos, int &$indice): string
{
    $marcasCanonicas = array(
        '/(?<![\pL\pN_])HONEYWELL(?![\pL\pN_])/iu' => 'Honeywell',
        '/(?<![\pL\pN_])ROND\s+METAL(?![\pL\pN_])/iu' => 'Rond Metal',
    );
    foreach ($marcasCanonicas as $patronMarca => $grafiaCanonica) {
        $texto = preg_replace_callback(
            $patronMarca,
            static function () use (&$tokensProtegidos, &$indice, $grafiaCanonica): string {
                $marca = '__PDFTOK' . $indice++ . '__';
                $tokensProtegidos[$marca] = $grafiaCanonica;
                return $marca;
            },
            $texto
        ) ?? $texto;
    }

    $texto = preg_replace_callback(
        '/(?<![\pL\pN_])(?:\p{Lu}\.){2,}(?![\pL\pN_])/u',
        static function (array $coincidencia) use (&$tokensProtegidos, &$indice): string {
            $marca = '__PDFTOK' . $indice++ . '__';
            $tokensProtegidos[$marca] = $coincidencia[0];
            return $marca;
        },
        $texto
    ) ?? $texto;

    $siglas = array(
        'SD','INVT','HP','PA','PM','VF','AC','DC','AR/AC','V5R','V5Z','V5B',
        'IVA','MRL','IEP','LED','UPS','CPU','PLC','VVVF','CAN','RS485','RS-485',
        'PTC','IP','LCD','TFT','USB','GSM','SIM','NFC'
    );

    return preg_replace_callback(
        '/(?<![\pL\pN_])[^\s,;:.()]+(?![\pL\pN_])/u',
        static function (array $coincidencia) use (&$tokensProtegidos, &$indice, $siglas): string {
            $token = $coincidencia[0];
            $limpio = trim($token, " \t\n\r\0\x0B,;:.()[]{}");
            if ($limpio === '' || preg_match('/^__PDFTOK\d+__$/', $limpio)) return $token;

            $mayus = function_exists('mb_strtoupper') ? mb_strtoupper($limpio, 'UTF-8') : strtoupper($limpio);
            $tieneLetra = (bool)preg_match('/\pL/u', $limpio);
            $tieneNumero = (bool)preg_match('/\d/u', $limpio);
            if (!$tieneLetra || (!$tieneNumero && !in_array($mayus, $siglas, true))) return $token;

            $marca = '__PDFTOK' . $indice++ . '__';
            $tokensProtegidos[$marca] = $limpio;
            return str_replace($limpio, $marca, $token);
        },
        $texto
    ) ?? $texto;
}

function pdfRestaurarTokensProtegidos(string $texto, array $tokensProtegidos): string
{
    foreach ($tokensProtegidos as $marca => $original) {
        $texto = str_replace(strtolower($marca), $original, $texto);
        $texto = str_replace($marca, $original, $texto);
    }
    return $texto;
}

function pdfTextoMinusculas(string $texto): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    if ($texto === '') return '';

    $mayusculas = function_exists('mb_strtoupper') ? mb_strtoupper($texto, 'UTF-8') : strtoupper($texto);
    $esTodoMayuscula = $texto === $mayusculas;
    $tokensProtegidos = array();
    $indice = 0;
    $textoProtegido = pdfProtegerTokensTecnicos($texto, $tokensProtegidos, $indice);

    if ($esTodoMayuscula) {
        $resultado = function_exists('mb_strtolower') ? mb_strtolower($textoProtegido, 'UTF-8') : strtolower($textoProtegido);
        if (function_exists('mb_substr') && function_exists('mb_strtoupper')) {
            $primera = mb_substr($resultado, 0, 1, 'UTF-8');
            $resultado = mb_strtoupper($primera, 'UTF-8') . mb_substr($resultado, 1, null, 'UTF-8');
        } else {
            $resultado = ucfirst($resultado);
        }
    } else {
        $palabrasMenores = array('A','AL','CON','DE','DEL','EL','EN','LA','LAS','LOS','O','PARA','POR','U','Y');
        $resultado = preg_replace_callback(
            '/(?<![\pL\pN_])[\pL][\pL\pM]*(?![\pL\pN_])/u',
            static function (array $coincidencia) use ($palabrasMenores): string {
                $palabra = $coincidencia[0];
                $mayuscula = function_exists('mb_strtoupper') ? mb_strtoupper($palabra, 'UTF-8') : strtoupper($palabra);
                if ($palabra !== $mayuscula || in_array($mayuscula, $palabrasMenores, true)) return $palabra;
                if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strtolower')) {
                    return mb_strtoupper(mb_substr($palabra, 0, 1, 'UTF-8'), 'UTF-8')
                        . mb_strtolower(mb_substr($palabra, 1, null, 'UTF-8'), 'UTF-8');
                }
                return ucfirst(strtolower($palabra));
            },
            $textoProtegido
        );
        if ($resultado === null) $resultado = $textoProtegido;
    }

    return pdfRestaurarTokensProtegidos($resultado, $tokensProtegidos);
}

function pdfTextoNominal(string $texto): string
{
    $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? $texto);
    if ($texto === '') return '';

    $tokensProtegidos = array();
    $indice = 0;
    $textoProtegido = pdfProtegerTokensTecnicos($texto, $tokensProtegidos, $indice);
    $resultado = function_exists('mb_strtolower') ? mb_strtolower($textoProtegido, 'UTF-8') : strtolower($textoProtegido);
    $capitalizado = preg_replace_callback(
        '/(?<![\pL\pN_])[\pL][\pL\pM]*(?![\pL\pN_])/u',
        static function (array $coincidencia): string {
            if (function_exists('mb_substr') && function_exists('mb_strtoupper') && function_exists('mb_strtolower')) {
                return mb_strtoupper(mb_substr($coincidencia[0], 0, 1, 'UTF-8'), 'UTF-8')
                    . mb_strtolower(mb_substr($coincidencia[0], 1, null, 'UTF-8'), 'UTF-8');
            }
            return ucfirst(strtolower($coincidencia[0]));
        },
        $resultado
    );
    if ($capitalizado !== null) $resultado = $capitalizado;

    return pdfRestaurarTokensProtegidos($resultado, $tokensProtegidos);
}

function pdfNombreCliente(array $fila): string
{
    $nombre = trim((string)($fila['clientes_nomfantasia'] ?? ''));
    if ($nombre === '') $nombre = trim((string)($fila['clientes_razonsocial'] ?? ''));
    if ($nombre === '') return '-';
    // Criterio documental Automac: el nombre de fantasia se presenta en MAYUSCULAS.
    return function_exists('mb_strtoupper') ? mb_strtoupper($nombre, 'UTF-8') : strtoupper($nombre);
}

function pdfNotaTecnicaMrl(array $fila): array
{
    $json = (string)($fila['cotizacion_datos'] ?? $fila['datos_formulario'] ?? '');
    if ($json === '') return array('', '');
    $datos = json_decode($json, true);
    if (!is_array($datos) || (int)($datos['id_tipo_control'] ?? 0) !== 7) return array('', '');

    /* v133: los documentos nuevos guardan un snapshot preciso de la variante MRL.
     * Si el documento es histórico y no posee el snapshot, se conserva la leyenda legacy. */
    $tituloSnapshot = trim((string)($datos['mrl_configuracion_documento'] ?? ''));
    $gabineteSnapshot = trim((string)($datos['mrl_gabinete_documento'] ?? ''));
    if ($tituloSnapshot !== '' && $gabineteSnapshot !== '') {
        return array($tituloSnapshot, $gabineteSnapshot);
    }

    $gabinete = strtoupper(trim((string)($datos['tipo_gabinete_mrl'] ?? '')));
    if ($gabinete === 'WITTUR') {
        return array(
            'CONFIGURACION MRL WITTUR',
            'Gabinete aproximado 400x2200x240 mm en pintura epoxy gris. Visor y espacio para palanca de freno manual / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /'
        );
    }

    return array(
        'CONFIGURACION MRL AUTOMAC / DANGELICA / CLEX',
        'Gabinete aproximado 460x2000x200 mm en pintura epoxy gris / Llaves termomagneticas trifasicas y monofasicas, para iluminacion de cabina y hueco / Bornera para IEP / Llave de rearme para limitador de velocidad / Espacio para UCM / Resistencias con proteccion y cable blindado, para el hueco /'
    );
}

function pdfClienteInterno(array $fila): string
{
    $nombre = pdfNombreCliente($fila);
    $sigla = strtoupper(trim((string)($fila['clientes_codigo'] ?? '')));
    if ($sigla === '') return $nombre;
    return $nombre . ' (' . $sigla . ')';
}

function pdfSolicitanteCliente(array $fila): string
{
    foreach (array((string)($fila['datos_formulario'] ?? ''), (string)($fila['cotizacion_datos'] ?? '')) as $json) {
        if (trim($json) === '') continue;
        $datos = json_decode($json, true);
        if (!is_array($datos)) continue;
        $valor = trim((string)($datos['solicitante_cliente'] ?? ''));
        if ($valor !== '') return $valor;
    }
    return '';
}

function pdfNumeroCliente(array $fila): string
{
    $numero = trim((string)($fila['clientes_numero_bejerman'] ?? ''));
    return $numero !== '' ? $numero : '-';
}

function pdfTelefonoCliente(array $fila): string
{
    $telefono = trim((string)($fila['clientes_telefono'] ?? ''));
    return $telefono !== '' ? $telefono : '-';
}

function pdfEmailCliente(array $fila): string
{
    $email = trim((string)($fila['clientes_emails'] ?? ''));
    return $email !== '' ? $email : '-';
}

function pdfDinero(float $valor): string
{
    return '$' . number_format(ceil($valor), 0, ',', '.');
}

function pdfNombreSeguro(string $numero): string
{
    $valor = trim($numero);
    $valor = preg_replace('~[\\/:*?"<>|]+~u', ' - ', $valor) ?? $valor;
    $valor = preg_replace('/\s+/u', ' ', $valor) ?? $valor;
    $valor = trim($valor, " .-_\t\n\r\0\x0B");
    return $valor !== '' ? $valor : 'DOCUMENTO';
}

function pdfTextoArchivo(string $texto, int $maximo=70): string
{
    $texto = trim($texto);
    if ($texto === '') return '';
    $texto = function_exists('mb_strtoupper') ? mb_strtoupper($texto, 'UTF-8') : strtoupper($texto);
    $texto = pdfNombreSeguro($texto);
    if (function_exists('mb_substr')) return rtrim(mb_substr($texto, 0, $maximo, 'UTF-8'));
    return rtrim(substr($texto, 0, $maximo));
}

function pdfNumeroArchivo(string $numero, string $tipo=''): string
{
    $visible = numeroDocumentoVisible($numero);
    $tipo = strtoupper(trim($tipo));
    if ($tipo === 'PEDIDO' && preg_match('/^#(\d+)$/', $visible, $m)) return 'PED.' . $m[1];
    return $visible !== '' ? $visible : $tipo;
}

function pdfNombreDocumento(string $tipo, string $numero, array $cliente, string $referencia='', ?int $revision=null, string $detalle=''): string
{
    $partes = array();
    $base = pdfNumeroArchivo($numero, $tipo);
    if ($base !== '') $partes[] = $base;
    if ($detalle !== '') $partes[] = pdfTextoArchivo($detalle, 42);
    if ($revision !== null) $partes[] = 'REV.' . $revision;
    $partes[] = pdfTextoArchivo(pdfNombreCliente($cliente), 64);
    $ref = pdfTextoArchivo($referencia, 55);
    if ($ref !== '' && $ref !== '-') $partes[] = $ref;
    return pdfNombreSeguro(implode(' - ', array_filter($partes, function($v){ return trim((string)$v)!==''; }))) . '.pdf';
}

function pdfConsultaNombre(mysqli $conexion, string $tabla, string $campoId, string $campoNombre, int $id): string
{
    if ($id <= 0 || !preg_match('/^[a-zA-Z0-9_]+$/', $tabla . $campoId . $campoNombre)) return '';
    $sql = "SELECT {$campoNombre} AS nombre FROM {$tabla} WHERE {$campoId}=? LIMIT 1";
    $st = $conexion->prepare($sql);
    if (!$st) return '';
    $st->bind_param('i', $id); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    return trim((string)($r['nombre'] ?? ''));
}

function pdfDatosTecnicos(mysqli $conexion, array $cotizacion): array
{
    $f = json_decode((string)($cotizacion['datos_formulario'] ?? ''), true);
    if (!is_array($f)) $f = array();

    $paradas = array_values(array_filter(
        array_map('intval', (array)($f['paradas_equipo'] ?? array())),
        function($x){ return $x > 0; }
    ));

    $datos = array(
        'cantidad' => max(1, (int)($f['cantidad_equipos'] ?? 1)),
        'paradas' => $paradas,
        'nomenclaturas' => array_values(array_map(static function($v){ return trim((string)$v); }, (array)($f['nomenclatura_equipo'] ?? array()))),
        'maniobra' => pdfConsultaNombre($conexion, 'maniobras', 'maniobra_id', 'maniobra_name', (int)($f['id_maniobra'] ?? 0)),
        'cpu' => pdfConsultaNombre($conexion, 'cpus', 'cpu_id', 'cpu_name', (int)($f['id_cpu'] ?? 0)),
        'tipo' => pdfConsultaNombre($conexion, 'tipos_control', 'ctrltipo_id', 'ctrltipo_name', (int)($f['id_tipo_control'] ?? 0)),
        'subtipo' => pdfConsultaNombre($conexion, 'subtipos_control', 'ctrlsubtipo_id', 'ctrlsubtipo_name', (int)($f['id_subtipo'] ?? 0)),
        'tension' => pdfConsultaNombre($conexion, 'tensiones', 'tension_id', 'tension_name', (int)($f['id_tension'] ?? 0)),
        'material' => pdfConsultaNombre($conexion, 'materiales_hueco', 'mathueco_id', 'mathueco_name', (int)($f['id_material_hueco'] ?? 0)),
        'potencia' => array_key_exists('dato_motor_tipo', $f) ? trim((string)($f['dato_motor_hp_equivalente'] ?? '')) : trim((string)($f['potencia_hp'] ?? '')),
        'dato_motor_tipo' => strtoupper(trim((string)($f['dato_motor_tipo'] ?? ''))),
        'dato_motor_valor' => trim((string)($f['dato_motor_valor'] ?? '')),
        'corriente_requerida' => trim((string)($f['dato_motor_corriente'] ?? '')),
        'velocidad' => trim((string)($f['velocidad_vf'] ?? '')),
        'corriente' => '',
        'contactor' => '',
        'comunicacion' => 'comunicación paralelo',
        'configuracion_especial' => 'Normal',
        'programa_tip' => '',
    );

    $cfgEspecial = strtoupper(trim((string)($f['configuracion_especial_control'] ?? '')));
    if ($cfgEspecial === '' && strtoupper(trim((string)($f['doble_acceso'] ?? ''))) === 'SI') $cfgEspecial = 'DOBLE_ACCESO_SELECTIVO';
    if ($cfgEspecial === 'DOBLE_ACCESO_SELECTIVO') $datos['configuracion_especial'] = 'Doble acceso selectivo';
    elseif ($cfgEspecial === 'TIP') {
        $datos['configuracion_especial'] = 'Maniobra TIP';
        $prog = strtoupper(trim((string)($f['programa_tip'] ?? '')));
        $datos['programa_tip'] = $prog === 'ESPECIAL_ESTANDAR' ? 'Especial + Estándar' : ($prog === 'ESPECIAL_ESPECIAL' ? 'Especial + Especial' : '');
    }

    /* Para 1V y 2V la velocidad es fija en el cálculo, aunque no llegue en el formulario. */
    if ($datos['velocidad'] === '') {
        if ((int)($f['id_tipo_control'] ?? 0) === 1) $datos['velocidad'] = '45';
        if ((int)($f['id_tipo_control'] ?? 0) === 2) $datos['velocidad'] = '60';
    }

    $datos['puerta_cabina'] = pdfConsultaNombre($conexion, 'ptacabina', 'ptacabina_id', 'ptacabina_name', (int)($f['id_ptacabina'] ?? 0));
    if ($datos['puerta_cabina'] === '') {
        $datos['puerta_cabina'] = pdfConsultaNombre($conexion, 'ptacabina_mc', 'ptacabinamc_id', 'ptacabinamc_name', (int)($f['id_ptacabina_mc'] ?? 0));
    }
    $datos['puerta_pisos'] = pdfConsultaNombre($conexion, 'ptapisos', 'ptapisos_id', 'ptapisos_name', (int)($f['id_ptapisos'] ?? 0));
    if ($datos['puerta_pisos'] === '') {
        $datos['puerta_pisos'] = pdfConsultaNombre($conexion, 'ptapisos_mc', 'ptapisosmc_id', 'ptapisosmc_name', (int)($f['id_ptapisos_mc'] ?? 0));
    }

    $datos['apertura_operadores'] = '';
    $cantidadOperadores = max(0, (int)($f['cantidad_operadores'] ?? 0));
    if ($cantidadOperadores >= 2) {
        $aperturas = is_array($f['operador_aperturas'] ?? null) ? $f['operador_aperturas'] : array();
        $partesApertura = array();
        for ($iOperador = 0; $iOperador < $cantidadOperadores; $iOperador++) {
            $detalle = trim((string)($aperturas[$iOperador] ?? ''));
            if ($detalle === '') $detalle = 'A CONFIRMAR';
            $partesApertura[] = 'Operador ' . ($iOperador + 1) . ': ' . $detalle;
        }
        $datos['apertura_operadores'] = implode(' / ', $partesApertura);
    }

    $idComSerie = (int)($f['id_comunicacion_serie'] ?? 0);
    if ($idComSerie > 0) {
        $nombreComSerie = pdfConsultaNombre($conexion, 'comunicaciones_serie', 'comserie_id', 'comserie_name', $idComSerie);
        if ($nombreComSerie !== '') {
            $normalizado = strtoupper(trim($nombreComSerie));
            if (strpos($normalizado, 'TOTAL') !== false) {
                $datos['comunicacion'] = 'comunicación serie total';
            } elseif (strpos($normalizado, 'CABINA') !== false) {
                $datos['comunicacion'] = 'comunicación serie en cabina';
            } elseif (strpos($normalizado, 'PISO') !== false) {
                $datos['comunicacion'] = 'comunicación serie en pisos';
            } else {
                $datos['comunicacion'] = 'comunicación serie ' . strtolower($nombreComSerie);
            }
        }
    }

    /* Recuperar corriente y contactor de la misma fila de matriz usada por el cálculo. */
    $idCpu = (int)($f['id_cpu'] ?? 0);
    $idCpuMatriz = $idCpu;
    if ($idCpu > 0) {
        $stCpuBase = $conexion->prepare('SELECT COALESCE(NULLIF(cpu_matriz_base_id,0),cpu_id) AS cpu_matriz_base_id FROM cpus WHERE cpu_id=? LIMIT 1');
        if ($stCpuBase) {
            $stCpuBase->bind_param('i', $idCpu);
            $stCpuBase->execute();
            $fcBase = $stCpuBase->get_result()->fetch_assoc();
            $stCpuBase->close();
            if ($fcBase && (int)$fcBase['cpu_matriz_base_id'] > 0) $idCpuMatriz = (int)$fcBase['cpu_matriz_base_id'];
        }
    }
    $idTipo = (int)($f['id_tipo_control'] ?? 0);
    $idSubtipo = (int)($f['id_subtipo'] ?? 0);
    $idTension = (int)($f['id_tension'] ?? 0);
    $potencia = is_numeric($datos['potencia']) ? (float)$datos['potencia'] : 0.0;
    $encoder = trim((string)($f['encoder'] ?? ''));

    if ($idTipo === 4 && array_key_exists('dato_motor_tipo', $f)) {
        try {
            $normalizado = normalizarDatoMotorACorriente($f['dato_motor_tipo'], $f['dato_motor_valor'] ?? '', $idTension);
            $datos['dato_motor_tipo'] = $normalizado['dato_original_tipo'];
            $datos['dato_motor_valor'] = (string)$normalizado['dato_original_valor'];
            $datos['corriente_requerida'] = (string)$normalizado['corriente_normalizada'];
            $datos['potencia'] = $normalizado['hp_equivalente'] === null ? '' : (string)$normalizado['hp_equivalente'];
            $idCpuMatriz = controlMotorCpuMatriz($conexion, $idCpu);
            $filaVF = obtenerVariadorSeleccionadoVF($conexion, (int)($f['id_matriz_variador'] ?? 0), $idCpuMatriz, $idSubtipo, $idTension, $encoder, $normalizado['corriente_normalizada']);
            if ($filaVF) {
                $datos['corriente'] = trim((string)$filaVF['control_corriente']);
                $idContactorVF = (int)($filaVF['control_contactor'] ?? 0);
                if ($idContactorVF > 0) $datos['contactor'] = pdfConsultaNombre($conexion, 'contactores', 'contactor_id', 'contactor_name', $idContactorVF);
                $datos['subtipo'] = trim((string)$filaVF['ctrlsubtipo_name']);
            }
        } catch (Throwable $e) {
            // El documento conserva las líneas comerciales congeladas aunque falte una fila técnica actual.
        }
    } elseif ($idCpu > 0 && $idTipo > 0 && $idSubtipo > 0 && $idTension > 0 && $potencia >= 0) {
        $sqlMatriz = "SELECT m.control_corriente, c.contactor_name
                      FROM matriz_calculos m
                      LEFT JOIN contactores c ON c.contactor_id = m.control_contactor
                      WHERE m.control_cpu = ?
                        AND m.control_tipo = ?
                        AND m.control_subtipo = ?
                        AND m.control_tension = ?
                        AND COALESCE(NULLIF(TRIM(m.control_encoder), ''), '') = ?
                        AND ? > m.control_potenciadesde
                        AND ? <= m.control_potenciahasta
                      ORDER BY m.control_potenciadesde DESC
                      LIMIT 1";
        $st = $conexion->prepare($sqlMatriz);
        if ($st) {
            $st->bind_param('iiiisdd', $idCpuMatriz, $idTipo, $idSubtipo, $idTension, $encoder, $potencia, $potencia);
            $st->execute();
            $fila = $st->get_result()->fetch_assoc();
            $st->close();
            if ($fila) {
                $datos['corriente'] = trim((string)($fila['control_corriente'] ?? ''));
                $datos['contactor'] = trim((string)($fila['contactor_name'] ?? ''));
            }
        }
    }

    return $datos;
}

function pdfTextoParadas(array $paradas): string
{
    if (!$paradas) return '';
    $unicas = array_values(array_unique(array_map('intval', $paradas)));
    if (count($unicas) === 1) return (string)$unicas[0];
    return implode(' / ', $paradas);
}

function pdfCompletarDesdeDescripcionBase(array $base, array $t): array
{
    $d = strtoupper(trim((string)($base['descripcion'] ?? '')));
    if ($t['cpu'] === '' && preg_match('/\b(A6(?:220|300|700)V?\d*)\b/i', $d, $m)) $t['cpu'] = $m[1];
    if ($t['subtipo'] === '' && preg_match('/\b((?:GD|L)\s*\d+[A-Z0-9.-]*)\b/i', $d, $m)) $t['subtipo'] = preg_replace('/\s+/', '', $m[1]);
    if ($t['corriente'] === '' && preg_match('/\b(\d+(?:[.,]\d+)?)\s*A\b/i', $d, $m)) $t['corriente'] = str_replace(',', '.', $m[1]);
    if ($t['contactor'] === '' && preg_match('/\bC\s*(\d+(?:[.,]\d+)?)\s*A\b/i', $d, $m)) $t['contactor'] = str_replace(',', '.', $m[1]);
    if ($t['tension'] === '' && preg_match('/\b(3\s*[Xx]\s*380(?:\s*V)?)\b/', $d, $m)) $t['tension'] = preg_replace('/\s+/', '', $m[1]);
    return $t;
}

function pdfSinAcentos(string $texto): string
{
    $mapa = array(
        'á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n',
        'Á'=>'A','É'=>'E','Í'=>'I','Ó'=>'O','Ú'=>'U','Ü'=>'U','Ñ'=>'N'
    );
    $texto = strtr($texto, $mapa);
    $texto = preg_replace('/\s+/u', ' ', $texto);
    return trim((string)$texto);
}

function pdfDescripcionControl(array $base, array $t): string
{
    $t = pdfCompletarDesdeDescripcionBase($base, $t);
    $partes = array();
    $partes[] = 'Control' . ($t['cpu'] !== '' ? ' ' . $t['cpu'] : '');

    if ($t['maniobra'] !== '') $partes[] = 'maniobra ' . $t['maniobra'];
    if (($t['configuracion_especial'] ?? 'Normal') !== 'Normal') {
        $cfg = (string)$t['configuracion_especial'];
        if ($cfg === 'Maniobra TIP' && ($t['programa_tip'] ?? '') !== '') $cfg .= ' ' . (string)$t['programa_tip'];
        $partes[] = $cfg;
    }

    if ($t['subtipo'] !== '') {
        $regulador = 'regulador ' . $t['subtipo'];
        if ($t['corriente'] !== '') $regulador .= ' de ' . $t['corriente'] . ' A';
        $partes[] = $regulador;
    } elseif ($t['corriente'] !== '') {
        $partes[] = 'corriente ' . $t['corriente'] . ' A';
    }

    if ($t['contactor'] !== '') $partes[] = 'contactor de ' . $t['contactor'] . ' A';
    if ($t['dato_motor_tipo'] !== '' && $t['dato_motor_valor'] !== '') {
        $unidad = $t['dato_motor_tipo'] === 'AMP' ? 'A' : ($t['dato_motor_tipo'] === 'KW' ? 'kW' : ($t['dato_motor_tipo'] === 'CV' ? 'CV' : 'HP'));
        $partes[] = 'motor informado ' . $t['dato_motor_valor'] . ' ' . $unidad;
        if ($t['corriente_requerida'] !== '') $partes[] = 'corriente requerida ' . $t['corriente_requerida'] . ' A';
    }
    if ($t['potencia'] !== '') $partes[] = 'potencia ' . $t['potencia'] . ' HP';
    if ($t['tension'] !== '') $partes[] = 'alimentación ' . $t['tension'];
    if ($t['velocidad'] !== '') $partes[] = 'velocidad ' . $t['velocidad'] . ' m/min';

    $textoParadas = pdfTextoParadas((array)$t['paradas']);
    if ($textoParadas !== '') $partes[] = 'para ' . $textoParadas . ' paradas';
    $partesNomenclatura = array();
    foreach ((array)($t['paradas'] ?? array()) as $iNom=>$pNom) {
        $nom = trim((string)(($t['nomenclaturas'] ?? array())[$iNom] ?? ''));
        if ($nom === '') $nom = 'A CONFIRMAR';
        $partesNomenclatura[] = 'Coche '.($iNom+1).': '.(int)$pNom.' paradas ['.$nom.']';
    }
    if ($partesNomenclatura) $partes[] = 'nomenclatura ' . implode(' / ', $partesNomenclatura);

    if ($t['puerta_cabina'] !== '') $partes[] = 'con puerta de cabina ' . strtolower($t['puerta_cabina']);
    if ($t['puerta_pisos'] !== '') $partes[] = 'puertas de piso ' . strtolower($t['puerta_pisos']);
    if ($t['comunicacion'] !== '') $partes[] = $t['comunicacion'];

    /* Si la cotización histórica no guardó la configuración completa, no reemplazar
       la descripción congelada por un texto vacío o incompleto. */
    $datosReales = 0;
    foreach (array('cpu','maniobra','subtipo','corriente','contactor','potencia','tension','velocidad','puerta_cabina','puerta_pisos') as $k) {
        if (trim((string)($t[$k] ?? '')) !== '') $datosReales++;
    }
    if ($datosReales < 3 && trim((string)($base['descripcion'] ?? '')) !== '') {
        $descripcionBase = rtrim(trim((string)$base['descripcion']), '.');
        if (stripos($descripcionBase, 'comunicaci') === false && $t['comunicacion'] !== '') {
            $descripcionBase .= ', ' . $t['comunicacion'];
        }
        return $descripcionBase . '.';
    }

    return implode(', ', array_values(array_filter($partes))) . '.';
}

function pdfDescripcionMaterialHueco(string $material): string
{
    $normalizado = strtoupper(trim($material));
    if (strpos($normalizado, 'IMAN') !== false) {
        return 'Material de hueco compuesto por imanes, cabezales y soportes magneticos. Incluido en el precio del control.';
    }
    if (strpos($normalizado, 'PLACA') !== false || strpos($normalizado, 'INFRARROJ') !== false) {
        return 'Material de hueco compuesto por placas, cabezales infrarrojos y soportes. Incluido en el precio del control.';
    }
    return 'Material de hueco. Incluido en el precio del control.';
}

function pdfResumenSenalizacion(mysqli $conexion, array $doc): array
{
    $f = json_decode((string)($doc['datos_formulario'] ?? ''), true);
    if (!is_array($f) || empty($f['senal_incluir'])) return array();
    $modelo = pdfConsultaNombre($conexion, 'senal_modelos_pulsador', 'modelo_pulsador_id', 'modelo_pulsador_nombre', (int)($f['senal_modelo'] ?? 0));
    $tipo = pdfConsultaNombre($conexion, 'senal_tipos_modulo', 'tipo_modulo_id', 'tipo_modulo_nombre', (int)($f['senal_tipo_modulo'] ?? 0));
    $tension = pdfConsultaNombre($conexion, 'senal_tensiones_modulo', 'tension_modulo_id', 'tension_modulo_nombre', (int)($f['senal_tension'] ?? 0));
    $color = pdfConsultaNombre($conexion, 'senal_colores_registro', 'color_registro_id', 'color_registro_nombre', (int)($f['senal_color'] ?? 0));
    $tecla = pdfConsultaNombre($conexion, 'senal_teclas', 'tecla_id', 'tecla_nombre', (int)($f['senal_tecla'] ?? 0));
    $modeloSenalId=(int)($f['senal_modelo'] ?? 0);
    $tensionSenalId=(int)($f['senal_tension'] ?? 0);
    if($modeloSenalId===5) $borne='4B';
    elseif($modeloSenalId===6) $borne=($tensionSenalId===2?'3B':'4B');
    else {
        $usaDatosControl=!empty($f['senal_tiene_control']) && !empty($f['senal_usar_control']);
        $borneManual=(int)($f['senal_borne_manual'] ?? 0);
        if(!$usaDatosControl && in_array($borneManual,array(1,2),true)) $borne=($borneManual===2?'4B':'3B');
        elseif((int)($f['senal_tipo_modulo'] ?? 0)===2 && $borneManual===2) $borne='4B';
        else $borne='3B';
    }
    $puerta = strtoupper((string)($f['senal_tipo_puerta'] ?? '')) === 'PM' ? 'Puerta manual' : 'Puerta automatica';
    $r = array();
    $incluyeCabina=!array_key_exists('senal_incluir_botonera_cabina',$f) || !empty($f['senal_incluir_botonera_cabina']);
    if($incluyeCabina){
      $r[] = 'Botonera de cabina: '.max(1,(int)($f['senal_cantidad']??1)).' unidad(es) - '.$modelo.' - '.$tipo.' - '.$puerta;
    } else {
      $r[] = 'Sin botonera de cabina - señalización exterior independiente.';
    }
    if($incluyeCabina){
      $ppb=$f['senal_paradas_equipo']??array(); if(!is_array($ppb))$ppb=array($ppb); $ppb=array_values(array_filter(array_map('intval',$ppb),static function($v){return $v>0;}));
      $nnb=$f['senal_nomenclatura_equipo']??($f['nomenclatura_equipo']??array()); if(!is_array($nnb))$nnb=array($nnb); $nnb=array_values(array_map(static function($v){return trim((string)$v);},$nnb));
      $mmb=$f['senal_medidas_equipo']??array(); if(!is_array($mmb))$mmb=array($mmb); $mmb=array_values(array_map(static function($v){return trim((string)$v);},$mmb));
      $medLegacy=trim((string)($f['senal_medidas']??'')); if(!$mmb && $medLegacy!=='')$mmb=array_fill(0,max(1,count($ppb)),$medLegacy);
      $partesParadas=array(); foreach($ppb as $i=>$p){$nom=trim((string)($nnb[$i]??''));if($nom==='')$nom='A CONFIRMAR';$med=trim((string)($mmb[$i]??''));$partesParadas[]='Coche '.($i+1).': '.(int)$p.' paradas · '.$nom.($med!==''?' · medida '.$med:'');} $textoParadas=$partesParadas?implode(' / ',$partesParadas):((string)max(0,(int)($f['senal_paradas']??0)).' paradas · A CONFIRMAR'.($medLegacy!==''?' · medida '.$medLegacy:''));
      if(strpos(strtoupper($modelo),'ROND METAL')!==false || strtoupper($modelo)==='METAL') $r[]='Paradas, nomenclatura y medida por coche: '.$textoParadas.' - Configuracion ROND METAL por modelo + tipo de puerta.'; else $r[] = 'Paradas, nomenclatura y medida por coche: '.$textoParadas.' - Tension: '.$tension.' - Bornes: '.$borne.' - Color: '.$color.' - Tecla: '.$tecla;
      $indicador=trim((string)($f['senal_indicador_modelo']??''));
      if($indicador!=='') $r[]='Indicador: '.max(0,(int)($f['senal_indicador_cantidad']??0)).' x '.$indicador;
      $calado=trim((string)($f['senal_medidas_calado']??'')); if($calado!=='')$r[]='Medidas del calado: '.$calado;
      if(strtoupper($modelo)==='A3900') $r[]='Braille lateral incluido por defecto.'; else $r[]='Braille interior incluido por defecto.';
      if(!empty($f['senal_logo_grabado'])) $r[]='Logo en cabina: sin cargo'.(trim((string)($f['senal_tipo_logo']??''))!==''?' - '.trim((string)$f['senal_tipo_logo']):'');
    }
    $com=trim((string)($f['senal_comunicacion_serie_tipo']??'')); if($com!=='')$r[]='Comunicacion serie: '.$com;
    foreach(array('senal_caracteristicas_especiales'=>'Caracteristicas especiales','senal_mensajes_especiales'=>'Mensajes especiales','senal_medidas_especiales'=>'Medidas especiales','senal_acabado'=>'Acabado') as $campo=>$titulo){$v=trim((string)($f[$campo]??''));if($v!=='')$r[]=$titulo.': '.$v;}
    $familiasExtPdf=array('SIMPLE'=>'Pulsador exterior simple','SIMPLE_IP'=>'Pulsador exterior simple + IP','DOBLE'=>'Pulsador exterior doble','DOBLE_IP'=>'Pulsador exterior doble + IP');
    $itemsExtPdf=null;
    if(array_key_exists('senal_pulsadores_items_json',$f)){
      $rawExt=trim((string)($f['senal_pulsadores_items_json']??''));$decExt=$rawExt!==''?json_decode($rawExt,true):array();$itemsExtPdf=is_array($decExt)?$decExt:array();
    }
    if(is_array($itemsExtPdf)){
      foreach($itemsExtPdf as $itPdf){
        if(!is_array($itPdf))continue;$famPdf=strtoupper(trim((string)($itPdf['familia']??'')));if(!isset($familiasExtPdf[$famPdf]))continue;
        $cantPdf=max(0,(int)($itPdf['cantidad']??0));if($cantPdf<=0)continue;$modeloPdf=trim((string)($itPdf['modelo']??''));
        $esMetalPdf=strtoupper($modeloPdf)==='METAL' || strpos(strtoupper($modeloPdf),'ROND METAL')!==false;
        $partPdf=array($cantPdf.' unidad(es)',$modeloPdf);
        if(!$esMetalPdf){foreach(array('tipo','color','tension','bornes','tecla') as $kPdf){$vPdf=trim((string)($itPdf[$kPdf]??''));if($vPdf!=='')$partPdf[]=$vPdf;}}
        elseif(substr($famPdf,-3)==='_IP' && trim((string)($itPdf['tipo']??''))!=='')$partPdf[]=trim((string)$itPdf['tipo']);
        $txtPdf=$familiasExtPdf[$famPdf].': '.implode(' - ',array_filter($partPdf));
        $codPdf=trim((string)($itPdf['codigo']??''));if($codPdf!=='')$txtPdf.=' - Codigo '.$codPdf;
        if(substr($famPdf,-3)==='_IP'){$indCod=trim((string)($itPdf['indicador_codigo']??''));$indMod=trim((string)($itPdf['indicador_modelo']??''));if($indCod!=='')$txtPdf.=' - Indicador '.($indMod!==''?$indMod.' ':'').'('.$indCod.')';}
        $medPdf=trim((string)($itPdf['medida']??''));$acabPdf=strtoupper(trim((string)($itPdf['acabado']??'ACERO')));$espPdf=!empty($itPdf['medida_especial']);$txtPdf.=' - Medida '.($medPdf!==''?$medPdf:'A CONFIRMAR').' - Tapa '.$acabPdf.($espPdf?' - MEDIDA ESPECIAL':'');
        $r[]=$txtPdf;
      }
    }else{
      $familiasLegacy=array('simple'=>'Pulsador exterior simple','simple_ip'=>'Pulsador exterior simple + IP','doble'=>'Pulsador exterior doble','doble_ip'=>'Pulsador exterior doble + IP');
      $cantExtPdf=array('simple'=>'senal_pulsadores_simples_cantidad','simple_ip'=>'senal_pulsadores_simples_indicador_cantidad','doble'=>'senal_pulsadores_dobles_cantidad','doble_ip'=>'senal_pulsadores_dobles_indicador_cantidad');
      foreach($familiasLegacy as $famPdf=>$nomPdf){
        $prefPdf='senal_ext_'.$famPdf.'_';$cantPdf=max(0,(int)($f[$cantExtPdf[$famPdf]]??0));if($cantPdf<=0 || empty($f[$prefPdf.'agregado']))continue;
        $modeloPdf=trim((string)($f[$prefPdf.'modelo']??''));$partPdf=array_filter(array($cantPdf.' unidad(es)',$modeloPdf,trim((string)($f[$prefPdf.'tipo']??'')),trim((string)($f[$prefPdf.'color']??'')),trim((string)($f[$prefPdf.'tension']??'')),trim((string)($f[$prefPdf.'bornes']??'')),trim((string)($f[$prefPdf.'tecla']??''))));
        $txtPdf=$nomPdf.': '.implode(' - ',$partPdf);if(substr($famPdf,-3)==='_ip' && trim((string)($f[$prefPdf.'indicador_codigo']??''))!=='')$txtPdf.=' - Indicador exterior '.$f[$prefPdf.'indicador_codigo'];$r[]=$txtPdf;
      }
    }
    // v190: indicadores exteriores individuales, cada uno con cantidad y medida propia.
    if(array_key_exists('senal_indicadores_exteriores_items_json',$f)){
      $rawInd=trim((string)($f['senal_indicadores_exteriores_items_json']??''));$decInd=$rawInd!==''?json_decode($rawInd,true):array();
      if(is_array($decInd))foreach($decInd as $itInd){if(!is_array($itInd))continue;$cantInd=max(0,(int)($itInd['cantidad']??0));if($cantInd<=0)continue;$modeloInd=trim((string)($itInd['modelo']??''));$tipoInd=trim((string)($itInd['tipo']??''));$codigoInd=trim((string)($itInd['codigo']??''));$medInd=trim((string)($itInd['medida']??''));$acabInd=strtoupper(trim((string)($itInd['acabado']??'ACERO')));$espInd=!empty($itInd['medida_especial']);$txt='Indicador de posicion exterior: '.$cantInd.' unidad(es) - '.$modeloInd.($tipoInd!==''?' - '.$tipoInd:'').($codigoInd!==''?' - Codigo '.$codigoInd:'').' - Medida '.($medInd!==''?$medInd:'A CONFIRMAR').' - Tapa '.$acabInd.($espInd?' - MEDIDA ESPECIAL':'');$r[]=$txt;}
    }
    return $r;
}

function pdfDibujarResumenSenalizacion(PdfAutomac $pdf, mysqli $conexion, array $doc, float &$y): void
{
    $lineas=pdfResumenSenalizacion($conexion,$doc); if(!$lineas)return;
    $alto=30;
    foreach($lineas as $l)$alto += max(1,count($pdf->wrap($l,485,8)))*11;
    if($y-$alto<95){$pdf->newPage();$y=795;}
    $pdf->fillColorRect(42,$y-18,511,22,232,246,238);
    $ff=json_decode((string)($doc['datos_formulario']??''),true);$tituloSenal=(!is_array($ff)||!array_key_exists('senal_incluir_botonera_cabina',$ff)||!empty($ff['senal_incluir_botonera_cabina']))?'CONFIGURACION - BOTONERA DE CABINA':'CONFIGURACION - SENALIZACION EXTERIOR';
    $pdf->colorText(50,$y-10,$tituloSenal,9,true,13,99,61);
    $y-=30;
    foreach($lineas as $l){$pdf->paragraph(50,$y,'- '.$l,495,8,11,false);$y-=2;}
    $y-=8;
}

function pdfItemsComerciales(mysqli $conexion, array $cotizacion, array $detalles): array
{
    $datosAgrupacion=json_decode((string)($cotizacion['datos_formulario']??''),true); if(!is_array($datosAgrupacion))$datosAgrupacion=array();
    $detalles = agruparBotoneraCabinaPresentacion($detalles,$datosAgrupacion);
    /*
     * El documento puede contener cualquier combinacion de modulos. Control no
     * debe suponerse: solo se resume cuando realmente existen lineas CONTROL.
     */
    $tieneControl = false;
    $subtotalControl = 0.0;
    $base = array('codigo' => '', 'descripcion' => 'Control electronico para ascensor');

    foreach ($detalles as $d) {
        $moduloDetalle = strtoupper(trim((string)($d['modulo'] ?? '')));
        $conceptoDetalle = strtoupper(trim((string)($d['concepto'] ?? '')));

        if ($moduloDetalle === 'CONTROL' || $conceptoDetalle === 'BASE') {
            $tieneControl = true;
            $subtotalControl += (float)($d['importe_total'] ?? 0);
        }

        if ($conceptoDetalle === 'BASE') {
            $base = array(
                'codigo' => (string)($d['codigo'] ?? ''),
                'descripcion' => (string)($d['descripcion'] ?? '')
            );
        }
    }

    $items = array();

    if ($tieneControl) {
        $t = pdfDatosTecnicos($conexion, $cotizacion);
        $cantidad = max(1, (int)$t['cantidad']);
        $descripcionBaseCongelada = trim((string)$base['descripcion']);
        $descripcionControlPdf = preg_match('/^Control\b/ui', $descripcionBaseCongelada)
            ? pdfSinAcentos($descripcionBaseCongelada)
            : pdfSinAcentos(pdfDescripcionControl($base, $t));
        /* La leyenda extensa del gabinete MRL se presenta debajo del Control
         * en un bloque propio. Se la quita de la descripcion principal para
         * evitar renglones excesivamente altos o texto truncado. */
        $descripcionControlPdf = preg_replace('/[,; ]*Gabinete aproximado\b.*$/iu', '', $descripcionControlPdf) ?? $descripcionControlPdf;
        $descripcionControlPdf = rtrim(trim($descripcionControlPdf), " ,;/.") . '.';
        $descripcionControlFormateada = pdfTextoMinusculas($descripcionControlPdf);
        $descripcionCentralOriginal = preg_match('/^Control\b/ui', $descripcionBaseCongelada)
            ? $descripcionBaseCongelada : $descripcionControlPdf;
        if (preg_match('/\bcentral\s+([^,;]+?)(?=,|;|\.$|$)/iu', $descripcionCentralOriginal, $centralEncontrada)) {
            $nombreCentral = trim($centralEncontrada[1]);
            $nombresCentrales = array('MORIS' => 'Moris', 'ROJAS' => 'Rojas', 'OMAR' => 'Omar', 'GMV' => 'GMV');
            if (preg_match('/^otra:\s*(.*)$/iu', $nombreCentral, $otraEncontrada)) {
                $nombreCentral = 'Otra: ' . $otraEncontrada[1];
            } else {
                $nombreCentral = $nombresCentrales[strtoupper($nombreCentral)] ?? $nombreCentral;
            }
            $descripcionControlFormateada = preg_replace_callback(
                '/\bcentral\s+[^,;]+?(?=,|;|\.$|$)/iu',
                static function () use ($nombreCentral) { return 'central ' . $nombreCentral; },
                $descripcionControlFormateada,
                1
            ) ?? $descripcionControlFormateada;
        }

        $items[] = array(
            'modulo' => 'CONTROL',
            'cantidad' => $cantidad,
            'codigo' => $base['codigo'],
            'descripcion' => $descripcionControlFormateada,
            'unitario' => $subtotalControl / $cantidad,
            'total' => $subtotalControl,
        );

        /* El rescate es comercialmente independiente del Control, pero recibe
         * el mismo factor de descuentos. Debe verse inmediatamente debajo del
         * Control y antes del material de hueco. */
        foreach ($detalles as $dRescate) {
            $moduloRescate = strtoupper(trim((string)($dRescate['modulo'] ?? '')));
            $conceptoRescate = strtoupper(trim((string)($dRescate['concepto'] ?? '')));
            if ($moduloRescate !== 'RESCATE' && $conceptoRescate !== 'RESCATE') continue;
            $items[] = array(
                'modulo' => 'RESCATE',
                'cantidad' => (float)($dRescate['cantidad'] ?? 1),
                'codigo' => (string)($dRescate['codigo'] ?? ''),
                'descripcion' => pdfTextoMinusculas(pdfSinAcentos(trim((string)($dRescate['descripcion'] ?? 'Rescate')))),
                'unitario' => (float)($dRescate['precio_unitario'] ?? 0),
                'total' => (float)($dRescate['importe_total'] ?? 0),
            );
        }

        /* El material de hueco solo pertenece al PDF cuando se cotizo Control. */
        $material = $t['material'];
        if ($material === '') {
            foreach ($detalles as $d) {
                $moduloDetalle = strtoupper(trim((string)($d['modulo'] ?? '')));
                if ($moduloDetalle !== '' && $moduloDetalle !== 'CONTROL') continue;

                $textoMaterial = strtoupper((string)($d['concepto'] ?? '') . ' ' . (string)($d['descripcion'] ?? ''));
                if (strpos($textoMaterial, 'IMAN') !== false || strpos($textoMaterial, 'MAGNET') !== false) {
                    $material = 'IMANES Y CABEZALES MAGNETICOS';
                    break;
                }
                if (strpos($textoMaterial, 'PLACA') !== false || strpos($textoMaterial, 'INFRARROJ') !== false) {
                    $material = 'PLACAS Y CABEZALES INFRARROJOS';
                    break;
                }
            }
        }
        if ($material === '') $material = 'Material de hueco';

        $items[] = array(
            'modulo' => 'CONTROL',
            'cantidad' => $cantidad,
            'codigo' => 'MH',
            'descripcion' => pdfTextoMinusculas(pdfDescripcionMaterialHueco($material)),
            'unitario' => 0.0,
            'total' => 0.0,
        );
    }

    foreach ($detalles as $d) {
        $concepto = strtoupper(trim((string)($d['concepto'] ?? '')));
        $modulo = strtoupper(trim((string)($d['modulo'] ?? '')));
        $codigoDetalle = strtoupper(trim((string)($d['codigo'] ?? '')));

        /* RESCATE ya se insertó entre Control y Material de Hueco. */
        if ($modulo === 'RESCATE' || $concepto === 'RESCATE') continue;

        /* Compatibilidad: algunos pedidos convertidos anteriormente clasificaron
         * los limites como CONTROL. Se reconocen por concepto o codigo para que
         * vuelvan a mostrarse como Accesorios en el PDF. */
        if ($modulo === 'CONTROL' && (
            strpos($concepto, 'LIMITE') !== false || strpos($concepto, 'LÍMITE') !== false ||
            strpos($codigoDetalle, 'HLGLLA') === 0 || strpos($codigoDetalle, 'HXCK') === 0
        )) {
            $modulo = 'ACCESORIOS';
        }

        /*
         * CONTROL se resume arriba. Los demas modulos se muestran renglon por
         * renglon. La compatibilidad por concepto cubre documentos historicos.
         */
        $esModuloAdicional = in_array($modulo, array('SENALIZACION', 'IEP', 'ACCESORIOS', 'REPUESTOS'), true);
        $esConceptoHistorico = preg_match('/^(BOTONERA|INDICADOR|ACCESORIO|LIMITE|LÍMITE|INSTALACION|INSTALACIÓN|REPUESTO)/u', $concepto) === 1;

        if ($esModuloAdicional || ($modulo === '' && $esConceptoHistorico)) {
            $items[] = array(
                'modulo' => $modulo !== '' ? $modulo : 'CONTROL',
                'concepto' => (string)($d['concepto'] ?? ''),
                'cantidad' => (float)$d['cantidad'],
                'codigo' => (string)$d['codigo'],
                'descripcion' => descripcionPresentacionRondMetal(pdfTextoMinusculas(pdfSinAcentos(trim((string)$d['descripcion'])))),
                'unitario' => (float)$d['precio_unitario'],
                'total' => (float)$d['importe_total'],
            );
        }
    }

    return $items;
}


function pdfTemaModulo(string $modulo): array
{
    $m = strtoupper(trim($modulo));
    // v459: paleta unificada con la interfaz del cotizador.
    // Se evita depender de colores intensos para conservar lectura en B/N.
    $temas = array(
        'CONTROL' => array('CONTROL', 18, 50, 91, ''),
        'SENALIZACION' => array('SENALIZACION', 74, 132, 164, ''),
        'ACCESORIOS' => array('ACCESORIOS', 19, 135, 83, ''),
        'IEP' => array('IEP', 91, 116, 139, ''),
        'REPUESTOS' => array('REPUESTOS', 58, 126, 145, ''),
    );
    return $temas[$m] ?? array($m !== '' ? $m : 'OTROS', 78, 95, 108, 'O');
}

function pdfTemaModuloClaro(string $modulo): array
{
    $m = strtoupper(trim($modulo));
    $fondos = array(
        'CONTROL' => array(237, 243, 249),
        'SENALIZACION' => array(240, 247, 250),
        'ACCESORIOS' => array(238, 248, 243),
        'IEP' => array(243, 246, 248),
        'REPUESTOS' => array(239, 247, 249),
    );
    return $fondos[$m] ?? array(245, 247, 248);
}

function pdfModuloOrden(string $modulo): int
{
    $orden = array('CONTROL'=>1,'SENALIZACION'=>2,'ACCESORIOS'=>3,'IEP'=>4,'REPUESTOS'=>5);
    return $orden[strtoupper(trim($modulo))] ?? 9;
}

function pdfCrearDocumento(mysqli $conexion, string $tipo, int $id, string $destino): void
{
    $esPedido = $tipo === 'PEDIDO';
    if ($esPedido) {
        $sql = "SELECT p.*, c.cotizacion_numero,COALESCE(p.referencia,c.referencia) AS referencia,c.datos_formulario AS cotizacion_datos,c.subtotal,c.descuento_1,c.descuento_2,c.descuento_3,
                cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,
                COALESCE(u.usuario_nombre,p.usuario,'') AS ejecutado_por
                FROM pedidos p
                LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id
                JOIN clientes cl ON cl.clientes_id=p.cliente_id
                LEFT JOIN usuarios u ON u.usuario_id=p.usuario_id
                WHERE p.pedido_id=? LIMIT 1";
    } else {
        $sql = "SELECT c.*, cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,
                COALESCE(u.usuario_nombre,c.usuario,'') AS ejecutado_por,
                (SELECT cr.motivo_modificacion FROM cotizaciones_revisiones cr WHERE cr.cotizacion_id=c.cotizacion_id ORDER BY cr.revision_id DESC LIMIT 1) AS motivo_revision_actual,
                COALESCE((SELECT MAX(cr2.fecha_revision) FROM cotizaciones_revisiones cr2 WHERE cr2.cotizacion_id=c.cotizacion_id), c.fecha_creacion) AS fecha_version_actual
                FROM cotizaciones c
                JOIN clientes cl ON cl.clientes_id=c.cliente_id
                LEFT JOIN usuarios u ON u.usuario_id=c.usuario_id
                WHERE c.cotizacion_id=? LIMIT 1";
    }
    $st = $conexion->prepare($sql); if (!$st) throw new RuntimeException($conexion->error);
    $st->bind_param('i', $id); $st->execute(); $doc = $st->get_result()->fetch_assoc(); $st->close();
    if (!$doc) throw new RuntimeException('Documento inexistente.');
    if ($esPedido) {
        if (empty($doc['datos_formulario'])) $doc['datos_formulario'] = $doc['cotizacion_datos'] ?? null;
        $st = $conexion->prepare('SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,importe_total FROM pedidos_detalle WHERE pedido_id=? ORDER BY orden_visual,pedido_detalle_id');
        $cotParaItems = $doc; $cotParaItems['total'] = $doc['total'];
    } else {
        $st = $conexion->prepare('SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,importe_total FROM cotizaciones_detalle WHERE cotizacion_id=? ORDER BY orden_visual,detalle_id');
        $cotParaItems = $doc;
    }
    $st->bind_param('i', $id); $st->execute(); $r = $st->get_result(); $detalles = array(); while ($x = $r->fetch_assoc()) $detalles[] = $x; $st->close();
    $items = pdfItemsComerciales($conexion, $cotParaItems, $detalles);

    /*
     * Los descuentos generales se aplican únicamente a Control. Los módulos
     * REPUESTOS, ACCESORIOS e IEP conservan el precio final almacenado en cada
     * renglón. En documentos mixtos distribuimos la diferencia sólo entre las
     * líneas sujetas a descuento, sin alterar los repuestos.
     */
    $sumaProtegida = 0.0;
    $sumaDescontable = 0.0;
    $indicesDescontables = array();
    foreach ($items as $indiceItem => $itemPdf) {
        $importeItem = (float)($itemPdf['total'] ?? 0);
        if ($importeItem <= 0) continue;
        $moduloItem = strtoupper(trim((string)($itemPdf['modulo'] ?? 'CONTROL')));
        if (in_array($moduloItem, array('REPUESTOS', 'ACCESORIOS', 'IEP', 'SENALIZACION'), true)) {
            $sumaProtegida += $importeItem;
        } else {
            $sumaDescontable += $importeItem;
            $indicesDescontables[] = $indiceItem;
        }
    }
    $totalDocumento = ceil((float)$doc['total']);
    $totalDescontableFinal = ceil(max(0.0, $totalDocumento - $sumaProtegida));
    if ($sumaDescontable > 0 && count($indicesDescontables) > 0) {
        $factorDocumento = $totalDescontableFinal / $sumaDescontable;
        $acumuladoDocumento = 0.0;
        $ultimoIndice = end($indicesDescontables);
        reset($indicesDescontables);
        foreach ($indicesDescontables as $indiceItem) {
            $cantidadItem = max(0.000001, (float)($items[$indiceItem]['cantidad'] ?? 1));
            if ($indiceItem === $ultimoIndice) {
                $totalItemFinal = round($totalDescontableFinal - $acumuladoDocumento, 2);
            } else {
                $totalItemFinal = round((float)$items[$indiceItem]['total'] * $factorDocumento, 2);
                $acumuladoDocumento += $totalItemFinal;
            }
            $items[$indiceItem]['total'] = $totalItemFinal;
            $items[$indiceItem]['unitario'] = round($totalItemFinal / $cantidadItem, 2);
        }
    }


    $pdf = new PdfAutomac();
    $numero = numeroDocumentoVisible($esPedido ? (string)$doc['pedido_numero'] : (string)$doc['cotizacion_numero']);
    $fechaDocumento = $esPedido ? fechaVersionActualPedido($doc) : fechaVersionActualCotizacion($conexion, $id, (string)($doc['fecha_creacion'] ?? ''));
    $fecha = date('d/m/Y', strtotime($fechaDocumento));
    $cliente = pdfNombreCliente($doc);
    $revisionDoc = (int)($doc['revision'] ?? 0);
    $motivoRevision = (!$esPedido && $revisionDoc > 0) ? trim((string)($doc['motivo_revision_actual'] ?? '')) : '';

    /*
     * v454 - Documento comercial profesional de una sola hoja.
     * Tipografia adaptativa: usa el mayor cuerpo posible segun la densidad.
     *
     * v394 - Documento formal y compacto.
     * Hoja 1: documento comercial completo.
     * Hoja 2: descripción/configuración técnica completa.
     * No se modifican importes, cantidades ni reglas de negocio.
     */
    $pdf->automacLogo(38, 797, 54);
    $pdf->colorText(214, 806, $esPedido ? 'PEDIDO' : 'COTIZACION', 19.5, true, 18, 50, 91);
    /* v402: numero de documento protagonista. */
    $pdf->colorText(414, 812, $esPedido ? 'PEDIDO Nro.' : 'COTIZACION Nro.', 7.4, true, 63, 79, 94);
    $pdf->colorText(414, 794, $numero . ($revisionDoc > 0 ? '  R.' . $revisionDoc : ''), 15.2, true, 18, 50, 91);
    $pdf->colorText(414, 780, 'EMISION  ' . $fecha, 7.2, true, 63, 79, 94);
    $pdf->fillColorRect(38, 771, 519, 2.4, 18, 50, 91);

    /* v400: cabecera compacta en dos grupos: Cliente / Obra y condiciones. */
    $top = 764; $hDatos = 48;
    /* v455: eje visual unico. Todos los bloques comerciales comparten
     * exactamente los mismos bordes laterales para conservar simetria. */
    $contenidoX = 42; $contenidoW = 511;
    $gapDatos = 6; $grupoW = ($contenidoW-$gapDatos)/2;
    $xCliente=$contenidoX; $xObra=$contenidoX+$grupoW+$gapDatos;
    $pdf->fillColorRect($xCliente,$top-$hDatos,$grupoW,$hDatos,244,248,251);
    $pdf->fillColorRect($xObra,$top-$hDatos,$grupoW,$hDatos,244,248,251);
    $pdf->roundedRect($xCliente,$top-$hDatos,$grupoW,$hDatos,5);
    $pdf->roundedRect($xObra,$top-$hDatos,$grupoW,$hDatos,5);

    /* Cliente + contacto */
    $pdf->colorText($xCliente+8,$top-10,'CLIENTE / CONTACTO',8.1,true,18,50,91);
    $pdf->text($xCliente+8,$top-24,pdfTextoNominal($cliente !== '' ? $cliente : '-'),11.2,true);
    $clienteLinea='Bj: '.pdfNumeroCliente($doc);
    $telCliente=pdfTextoMinusculas(pdfTelefonoCliente($doc));
    if($telCliente!=='')$clienteLinea.=' | '.$telCliente;
    $pdf->text($xCliente+8,$top-37,$clienteLinea,7.1);
    $contacto=pdfSolicitanteCliente($doc) ?: '-';
    $email=pdfEmailCliente($doc);
    $contactoLinea='Contacto: '.pdfTextoNominal($contacto);
    if($email!=='')$contactoLinea.=' | '.$email;
    $cwrap=$pdf->wrap($contactoLinea,$grupoW-16,5);
    $pdf->text($xCliente+8,$top-46,$cwrap[0]??'',6.9,true);

    /* Obra / referencia + revisión o entrega/pago */
    $pdf->colorText($xObra+8,$top-10,$esPedido?'OBRA / ENTREGA / PAGO':'OBRA / REFERENCIA',8.1,true,18,50,91);
    $refCompleta=pdfTextoNominal(((string)$doc['referencia'] ?: '-'));
    $refWrap=$pdf->wrap($refCompleta,$grupoW-16,6);
    $pdf->text($xObra+8,$top-24,$refWrap[0]??'-',10.8,true);
    if($esPedido){
        $fechaEntregaTxt='A confirmar';
        if(!empty($doc['fecha_entrega']) && (string)$doc['fecha_entrega']!=='0000-00-00'){
            $tsE=strtotime((string)$doc['fecha_entrega']); if($tsE!==false)$fechaEntregaTxt=date('d/m/Y',$tsE);
        }
        $codigoPago=trim((string)($doc['condicion_pago_codigo']??''));
        $descripcionPago=trim((string)($doc['condicion_pago_descripcion']??''));
        $formaPagoTexto=trim(($codigoPago!==''?$codigoPago.' - ':'').$descripcionPago);
        if($formaPagoTexto==='')$formaPagoTexto='A confirmar';
        $lineaObra='Entrega: '.$fechaEntregaTxt.' | Pago: '.$formaPagoTexto;
        $ow=$pdf->wrap($lineaObra,$grupoW-16,5);
        $pdf->text($xObra+8,$top-37,$ow[0]??'',7.1,true);
        if(isset($ow[1]))$pdf->text($xObra+8,$top-46,$ow[1],6.6);
    }else{
        $pdf->text($xObra+8,$top-37,'Rev. '.$revisionDoc.' | Resp.: '.pdfTextoNominal(((string)$doc['ejecutado_por'] ?: '-')),7.1,true);
    }

    $y=$top-$hDatos-7;

    /* Agrupar items sin tocar cálculos. */
    $grupos=array('CONTROL'=>array(),'SENALIZACION'=>array(),'ACCESORIOS'=>array(),'IEP'=>array(),'REPUESTOS'=>array());
    foreach($items as $it){$m=strtoupper(trim((string)($it['modulo']??'CONTROL')));if(!isset($grupos[$m]))$m='CONTROL';$grupos[$m][]=$it;}
    $sumas=array(); foreach($grupos as $m=>$lista){$sumas[$m]=0.0;foreach($lista as $it)$sumas[$m]+=(float)($it['total']??0);}
    $modsResumen=array('CONTROL','SENALIZACION','ACCESORIOS','IEP','REPUESTOS');

    /* Resumen compacto en una fila. Ajustado para evitar solapes entre icono y precio. */
    /* v455: cinco modulos de ancho identico, centrados sobre el mismo eje
     * que las tablas y las bandas de seccion. */
    $gap=3; $miniW=($contenidoW-($gap*4))/5; $miniH=28; $x=$contenidoX;
    foreach($modsResumen as $m){
        [$lab,$rr,$gg,$bb,$ico]=pdfTemaModulo($m);
        [$fr,$fg,$fb]=pdfTemaModuloClaro($m);
        $pdf->fillColorRect($x,$y-$miniH,$miniW,$miniH,$fr,$fg,$fb);
        $pdf->roundedRect($x,$y-$miniH,$miniW,$miniH,4);
        $pdf->fillColorRect($x,$y-$miniH,3.2,$miniH,$rr,$gg,$bb);
        $pdf->moduleIcon($x+6,$y-9,$m,6.8,$rr,$gg,$bb);
        $pdf->colorText($x+15,$y-7,$lab,6.0,true,$rr,$gg,$bb);
        if(count($grupos[$m])>0){
            $pdf->text($x+4,$y-20,pdfDinero($sumas[$m]),7.1,true);
            $pdf->text($x+$miniW-20,$y-20,count($grupos[$m]).' it.',5.5);
        }else{
            $pdf->text($x+4,$y-20,'No incluido',6.1,true);
        }
        $x+=$miniW+$gap;
    }
    $y-=$miniH+5;

    /* v400: precios con ancho suficiente y descripción prioritaria. */
    /* v401: priorizamos Descripcion. Unitario y Total se llevan al extremo derecho. */
    /* v454: columnas pensadas para lectura real en papel/A4. El codigo gana ancho
     * para evitar microtipografia; la descripcion conserva la mayor superficie. */
    $columns=array(
        /* v459: el ancho total de las columnas debe coincidir exactamente con
         * $contenidoW (511 pt). Así bandas, tablas, resumen y total comparten
         * el mismo eje y los mismos márgenes izquierdo/derecho. */
        array('label'=>'Cant.','w'=>22,'align'=>'center','nowrap'=>true),
        array('label'=>'Codigo','w'=>104),
        array('label'=>'Descripcion','w'=>267),
        array('label'=>'Unit.','w'=>58,'align'=>'right','nowrap'=>true),
        array('label'=>'Total','w'=>60,'align'=>'right','nowrap'=>true),
    );

    /*
     * v401 - Tipografia adaptativa, pero imprimible.
     * Se elige la MAYOR fuente que entra en una sola hoja.
     * Antes de reducir fuente se aprovecha todo el ancho de Descripcion.
     */
    $cantidadItemsPdf=count($items);
    $caracteresPdf=0;
    foreach($items as $itPdf){$caracteresPdf+=strlen((string)($itPdf['descripcion']??''));}

    $estimarAlturaTablas=function(float $size) use ($pdf,$grupos,$modsResumen,$columns): float {
        $altura=0.0;
        foreach($modsResumen as $moduloEst){
            if(empty($grupos[$moduloEst])) continue;
            $altura += 32.0; /* banda + cabecera + separacion */
            foreach($grupos[$moduloEst] as $itemEst){
                $vals=array(
                    (string)($itemEst['cantidad']??''),
                    (string)($itemEst['codigo']??''),
                    trim((string)(($itemEst['descripcion']??'')!==''?$itemEst['descripcion']:($itemEst['concepto']??''))),
                    pdfDinero((float)($itemEst['unitario']??0)),
                    pdfDinero((float)($itemEst['total']??0)),
                );
                $maxLines=1;
                foreach($columns as $iCol=>$colEst){
                    $lineas=$pdf->wrap($vals[$iCol]??'', $colEst['w']-6, max(6.0,$size));
                    $maxLines=max($maxLines,count($lineas));
                }
                $lineHeight=max(7.0,$size*1.00);
                $altura += max(14.5,$maxLines*$lineHeight+4.0);
            }
        }
        return $altura;
    };

    /* v454: tipografia profesional adaptativa. Se usa la fuente MAS GRANDE
     * que entra en una sola hoja; solo documentos excepcionalmente densos
     * bajan de 8 pt. La prioridad es legibilidad, no llenar la pagina. */
    $candidatosFuente=array(9.2,9.0,8.8,8.6,8.4,8.2,8.0,7.8,7.6,7.4,7.2,7.0,6.8);
    $tamDescripcionPdf=6.8;
    $alturaDisponible=max(330.0,$y-112.0);
    foreach($candidatosFuente as $cand){
        if($estimarAlturaTablas($cand) <= $alturaDisponible){
            $tamDescripcionPdf=$cand;
            break;
        }
    }

    /* v399: toda la descripcion comercial se imprime completa en la misma hoja.
     * Se prioriza informacion sobre rotulos y espacios. Nunca se agrega "...". */
    $descripcionCompleta=function(array $item): string{
        $desc=trim((string)($item['descripcion']??''));
        $concepto=trim((string)($item['concepto']??''));
        return $desc!=='' ? $desc : $concepto;
    };

    foreach($modsResumen as $moduloActual){
        if(!$grupos[$moduloActual])continue;
        [$lab,$rr,$gg,$bb,$ico]=pdfTemaModulo($moduloActual);
        [$fr,$fg,$fb]=pdfTemaModuloClaro($moduloActual);
        /* v455: banda unica de extremo a extremo. El subtotal forma parte
         * de la misma fila y queda alineado al margen derecho. */
        $pdf->fillColorRect($contenidoX,$y-14,$contenidoW,15,$fr,$fg,$fb);
        $pdf->fillColorRect($contenidoX,$y-14,4,15,$rr,$gg,$bb);
        $pdf->moduleIcon($contenidoX+7,$y-12,$moduloActual,9.5,$rr,$gg,$bb);
        $pdf->colorText($contenidoX+21,$y-8,$lab,8.5,true,18,50,91);
        $subTexto='Subt. '.pdfDinero($sumas[$moduloActual]);
        $tamSub=7.2;
        $anchoSub=strlen($subTexto)*$tamSub*0.58;
        $xSub=max($contenidoX+170,$contenidoX+$contenidoW-8-$anchoSub);
        $pdf->colorText($xSub,$y-8,$subTexto,$tamSub,true,18,50,91);
        $y-=16;
        $pdf->tableHeaderCompact($y,$columns);
        foreach($grupos[$moduloActual] as $item){
            $cant=abs((float)$item['cantidad']-round((float)$item['cantidad']))<0.0001?number_format((float)$item['cantidad'],0,',','.'):number_format((float)$item['cantidad'],2,',','.');
            $pdf->tableRowOnePage($y,$columns,array($cant,$item['codigo'],$descripcionCompleta($item),pdfDinero((float)$item['unitario']),pdfDinero((float)$item['total'])),$tamDescripcionPdf);
        }
        $y-=4;
    }

    /* Si el contenido es denso, usamos el espacio inferior completo antes del pie. */
    if($y<105){$y=105;}

    /* v403: condiciones a la izquierda y total independiente a la derecha. Sin superposiciones. */
    /* v455: condiciones y total comparten la misma grilla horizontal. */
    $totalBoxW=174; $gapInferior=12; $condX=$contenidoX;
    $condW=$contenidoW-$totalBoxW-$gapInferior;
    $totalX=$condX+$condW+$gapInferior;

    if($esPedido){
        $fechaEntregaTexto='A confirmar';
        if(!empty($doc['fecha_entrega'])&&(string)$doc['fecha_entrega']!=='0000-00-00'){$tsEntrega=strtotime((string)$doc['fecha_entrega']);if($tsEntrega!==false)$fechaEntregaTexto=date('d/m/Y',$tsEntrega);}
        $codigoPago=trim((string)($doc['condicion_pago_codigo']??''));$descripcionPago=trim((string)($doc['condicion_pago_descripcion']??''));
        $formaPagoTexto=trim(($codigoPago!==''?$codigoPago.' - ':'').$descripcionPago);if($formaPagoTexto==='')$formaPagoTexto='A confirmar';
        $condiciones=array(array('E','ENTREGA',$fechaEntregaTexto),array('$','FORMA DE PAGO',$formaPagoTexto),array('%','IVA','Precios NO incluyen IVA'));
    }else{
        $condiciones=array(array('E','ENTREGA','Sujeta a disponibilidad de insumos'),array('$','FORMA DE PAGO','A convenir'),array('%','IVA','Precios NO incluyen IVA'));
    }

    $pdf->fillColorRect($condX,$y-12,$condW,16,244,248,251);
    $pdf->fillColorRect($condX,$y-12,4,16,19,135,83);
    $pdf->colorText($condX+9,$y-7,'CONDICIONES COMERCIALES',8.2,true,18,50,91);
    $cy=$y-20;
    foreach($condiciones as $c){
        $tipoIcono = ($c[1]==='ENTREGA') ? 'ENTREGA' : (($c[1]==='FORMA DE PAGO') ? 'PAGO' : 'IVA');
        $pdf->commercialIcon($condX,$cy-11,$tipoIcono);
        $pdf->colorText($condX+25,$cy+1,$c[1],7.8,true,18,50,91);
        $lineasCond=$pdf->wrap($c[2],$condW-55,7.6);
        $pdf->text($condX+25,$cy-8,$lineasCond[0]??'',7.6);
        if(isset($lineasCond[1]))$pdf->text($condX+25,$cy-15,$lineasCond[1],7.0);
        $cy-=24;
    }

    $pdf->fillColorRect($totalX,$y-58,$totalBoxW,58,241,249,245);
    $pdf->roundedRect($totalX,$y-58,$totalBoxW,58,5);
    $pdf->fillColorRect($totalX,$y-58,4,58,19,135,83);
    /* v457: total comercial alineado a la derecha y sin leyenda de moneda redundante. */
    $tituloTotal=$esPedido?'TOTAL PEDIDO':'TOTAL GENERAL';
    $tamTituloTotal=9.0;
    /* v458: margen derecho conservador para que el texto quede siempre dentro del recuadro. */
    $anchoTituloTotal=strlen($tituloTotal)*$tamTituloTotal*0.48;
    $xTituloTotal=max($totalX+12,$totalX+$totalBoxW-16-$anchoTituloTotal);
    $pdf->colorText($xTituloTotal,$y-14,$tituloTotal,$tamTituloTotal,true,18,50,91);
    /* v456: el total general debe destacarse sin dominar el documento.
     * Se mantiene entre 1 y 2 puntos por encima del cuerpo de importes. */
    $tamTotalGeneral=max(9.0,min(10.2,$tamDescripcionPdf+1.5));
    $importeTotalTexto=pdfDinero((float)$doc['total']);
    $anchoImporteTotal=strlen($importeTotalTexto)*$tamTotalGeneral*0.48;
    $xImporteTotal=max($totalX+12,$totalX+$totalBoxW-16-$anchoImporteTotal);
    $pdf->colorText($xImporteTotal,$y-37,$importeTotalTexto,$tamTotalGeneral,true,18,50,91);
    $y-=72;

    /* Pie de hoja comercial. */
    $pdf->line($contenidoX,48,$contenidoX+$contenidoW,48,.55);
    /* v458: una sola firma de marca en el pie, sin repetir AUTOMAC. */
    $pdf->text($contenidoX,36,'AUTOMAC, Electrónica para Ascensores Confiables',5.7);
    $pdf->text(430,36,'Resp.: '.((string)$doc['ejecutado_por'] ?: '-'),5.7);

    /* v398: sin hoja tecnica adicional. La configuracion comercial relevante
     * queda contenida en las descripciones de la hoja principal. Se elimina
     * deliberadamente el bloque duplicado "CONFIGURACION - BOTONERA DE CABINA". */
    $pdf->output($destino);
}

function generarPdfCotizacion(mysqli $conexion, int $id): string
{
    $st=$conexion->prepare('SELECT c.cotizacion_numero,c.referencia,cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial FROM cotizaciones c JOIN clientes cl ON cl.clientes_id=c.cliente_id WHERE c.cotizacion_id=?');
    $st->bind_param('i',$id);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
    if(!$r) throw new RuntimeException('Cotización inexistente.');
    $nombre=pdfNombreDocumento('COTIZACION',(string)$r['cotizacion_numero'],$r,(string)($r['referencia']??''));
    $rel='pdf/cotizaciones/'.$nombre;
    $abs=__DIR__.'/'.$rel;
    pdfCrearDocumento($conexion,'COTIZACION',$id,$abs);
    documentosArchivarSinInterrumpir($conexion,$abs,'Cotizaciones',numeroDocumentoVisible((string)$r['cotizacion_numero']));
    $st=$conexion->prepare('UPDATE cotizaciones SET pdf_archivo=?,pdf_fecha=NOW() WHERE cotizacion_id=?');$st->bind_param('si',$rel,$id);$st->execute();$st->close();
    return $rel;
}

function generarPdfPedido(mysqli $conexion, int $id): string
{
    $st=$conexion->prepare('SELECT p.pedido_numero,p.revision,COALESCE(p.referencia,c.referencia) AS referencia,cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id JOIN clientes cl ON cl.clientes_id=p.cliente_id WHERE p.pedido_id=?');
    $st->bind_param('i',$id);$st->execute();$r=$st->get_result()->fetch_assoc();$st->close();
    if(!$r) throw new RuntimeException('Pedido inexistente.');
    // El PDF comercial conserva el numero del pedido; la revision se agrega solo cuando corresponde.
    $rev=(int)($r['revision']??0);
    $nombre=pdfNombreDocumento('PEDIDO',(string)$r['pedido_numero'],$r,(string)($r['referencia']??''),$rev>0?$rev:null);
    $rel='pdf/pedidos/'.$nombre;
    $abs=__DIR__.'/'.$rel;
    pdfCrearDocumento($conexion,'PEDIDO',$id,$abs);
    documentosArchivarSinInterrumpir($conexion,$abs,'Pedidos',numeroDocumentoVisible((string)$r['pedido_numero']));
    $st=$conexion->prepare('UPDATE pedidos SET pdf_archivo=?,pdf_fecha=NOW() WHERE pedido_id=?');$st->bind_param('si',$rel,$id);$st->execute();$st->close();
    return $rel;
}


function generarHojasModificacionPedido(mysqli $conexion, int $pedidoId, int $revision, string $motivo): array
{
    $st=$conexion->prepare("SELECT p.pedido_numero,p.revision,p.fecha_ultima_modificacion,p.datos_formulario,c.datos_formulario AS cotizacion_datos,COALESCE(p.referencia,c.referencia) AS referencia,cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,cl.clientes_telefono,cl.clientes_emails,COALESCE(u.usuario_nombre,p.usuario_modificacion,'') usuario_modificacion FROM pedidos p LEFT JOIN cotizaciones c ON c.cotizacion_id=p.cotizacion_id JOIN clientes cl ON cl.clientes_id=p.cliente_id LEFT JOIN usuarios u ON u.usuario_id=p.usuario_modificacion_id WHERE p.pedido_id=?");
    $st->bind_param('i',$pedidoId);$st->execute();$d=$st->get_result()->fetch_assoc();$st->close();
    if(!$d) throw new RuntimeException('Pedido inexistente.');
    $dir=__DIR__.'/pdf/modificaciones'; if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de modificaciones.');
    $salidas=array();
    foreach(array('PRODUCCION'=>'Producción','ADMINISTRACION'=>'Administración') as $clave=>$copia){
        $pdf=new PdfAutomac(); $y=800;
        $pdf->automacLogo(42, $y-18, 82); $pdf->text(300,$y,'HOJA DE MODIFICACIÓN DE PEDIDO',13,true); $y-=22; $pdf->line(42,$y,553,$y,1.2); $y-=26;
        $pdf->text(42,$y,'COPIA: '.$copia,13,true); $y-=24;
        $pdf->text(42,$y,'Pedido: '.numeroDocumentoVisible((string)$d['pedido_numero']),11,true); $pdf->text(350,$y,'Revisión: '.(int)$revision,11,true); $y-=18;
        $pdf->text(42,$y,'Fecha de modificación: '.date('d/m/Y H:i',strtotime((string)$d['fecha_ultima_modificacion'])),9,false); $y-=16;
        $pdf->text(42,$y,'Cliente: '.pdfTextoNominal(pdfClienteInterno($d)),9,false); $y-=16;
        if($clave==='ADMINISTRACION'){ $pdf->text(42,$y,'Solicitante: '.pdfTextoNominal((pdfSolicitanteCliente($d)?:'-')),9,false); $y-=16; }
        $pdf->text(42,$y,'Obra / referencia: '.pdfTextoNominal((string)($d['referencia']?:'-')),9,false); $y-=16;
        $pdf->text(42,$y,'Modificado por: '.pdfTextoNominal((string)($d['usuario_modificacion']?:'-')),9,false); $y-=24;
        $pdf->text(42,$y,'DETALLE DE LA MODIFICACIÓN',11,true); $y-=18;
        foreach($pdf->wrap($motivo,90) as $linea){$pdf->text(48,$y,$linea,10,false);$y-=15;}
        $y-=20; $pdf->line(42,$y,260,$y,.5); $pdf->line(335,$y,553,$y,.5); $y-=14;
        $pdf->text(80,$y,'Firma Producción',8,false); $pdf->text(390,$y,'Firma Administración',8,false);
        $rel='pdf/modificaciones/'.pdfNombreDocumento('PEDIDO',(string)$d['pedido_numero'],$d,(string)($d['referencia']??''),$revision,'MODIFICACION '.$clave);
        $abs=__DIR__.'/'.$rel; $pdf->output($abs); documentosArchivarSinInterrumpir($conexion,$abs,'Modificaciones',numeroDocumentoVisible((string)$d['pedido_numero'])); $salidas[$clave]=$rel;
    }
    $st=$conexion->prepare('UPDATE pedidos SET hoja_modificacion_produccion=?,hoja_modificacion_administracion=? WHERE pedido_id=?');
    $st->bind_param('ssi',$salidas['PRODUCCION'],$salidas['ADMINISTRACION'],$pedidoId);$st->execute();$st->close();
    return $salidas;
}


/* v176 - Carátulas de modificación de cotización para Producción y Administración. */
function cotizacionModulosModificados(mysqli $conexion, int $cotizacionId, int $revisionActual): array
{
    if ($revisionActual <= 0) return array();
    $revisionAnterior = $revisionActual - 1;
    $st = $conexion->prepare('SELECT revision_id FROM cotizaciones_revisiones WHERE cotizacion_id=? AND revision=? LIMIT 1');
    $st->bind_param('ii', $cotizacionId, $revisionAnterior); $st->execute();
    $r = $st->get_result()->fetch_assoc(); $st->close();
    if (!$r) return array();
    $revisionId = (int)$r['revision_id'];

    $normalizar = static function(array $filas): array {
        $porModulo = array();
        foreach ($filas as $x) {
            $m = strtoupper(trim((string)($x['modulo'] ?? '')));
            if ($m === '') $m = 'CONTROL';
            $fila = array(
                trim((string)($x['concepto'] ?? '')),
                trim((string)($x['codigo'] ?? '')),
                trim((string)($x['descripcion'] ?? '')),
                round((float)($x['cantidad'] ?? 0), 4),
                round((float)($x['precio_unitario'] ?? 0), 4),
                trim((string)($x['formula_aplicada'] ?? '')),
                round((float)($x['importe_total'] ?? 0), 4),
            );
            $porModulo[$m][] = $fila;
        }
        foreach ($porModulo as &$filasModulo) {
            usort($filasModulo, static function($a,$b){ return strcmp(json_encode($a, JSON_UNESCAPED_UNICODE), json_encode($b, JSON_UNESCAPED_UNICODE)); });
        }
        unset($filasModulo);
        return $porModulo;
    };

    $anteriores = array();
    $st = $conexion->prepare('SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM cotizaciones_revisiones_detalle WHERE revision_id=?');
    $st->bind_param('i',$revisionId); $st->execute(); $rs=$st->get_result(); while($x=$rs->fetch_assoc()) $anteriores[]=$x; $st->close();
    $actuales = array();
    $st = $conexion->prepare('SELECT modulo,concepto,codigo,descripcion,cantidad,precio_unitario,formula_aplicada,importe_total FROM cotizaciones_detalle WHERE cotizacion_id=?');
    $st->bind_param('i',$cotizacionId); $st->execute(); $rs=$st->get_result(); while($x=$rs->fetch_assoc()) $actuales[]=$x; $st->close();

    $a = $normalizar($anteriores); $b = $normalizar($actuales);
    $todos = array_unique(array_merge(array_keys($a), array_keys($b)));
    $cambiados = array();
    foreach ($todos as $m) {
        if (json_encode($a[$m] ?? array(), JSON_UNESCAPED_UNICODE) !== json_encode($b[$m] ?? array(), JSON_UNESCAPED_UNICODE)) $cambiados[]=$m;
    }
    $orden = array('CONTROL'=>10,'SENALIZACION'=>20,'ACCESORIOS'=>30,'IEP'=>40,'REPUESTOS'=>50);
    usort($cambiados, static function($x,$y) use($orden){ return ($orden[$x]??999) <=> ($orden[$y]??999); });
    return $cambiados;
}

function generarHojasModificacionCotizacion(mysqli $conexion, int $cotizacionId, ?int $revisionForzada=null, ?string $motivoForzado=null): array
{
    $st=$conexion->prepare("SELECT c.cotizacion_numero,c.revision,c.fecha_creacion,c.datos_formulario,c.referencia,c.lista_nombre,c.lista_fecha,c.lista_valor_dolar,cl.clientes_codigo,cl.clientes_nomfantasia,cl.clientes_razonsocial,cl.clientes_numero_bejerman,COALESCE(u.usuario_nombre,c.usuario,'') usuario_actual FROM cotizaciones c JOIN clientes cl ON cl.clientes_id=c.cliente_id LEFT JOIN usuarios u ON u.usuario_id=c.usuario_id WHERE c.cotizacion_id=?");
    $st->bind_param('i',$cotizacionId); $st->execute(); $d=$st->get_result()->fetch_assoc(); $st->close();
    if(!$d) throw new RuntimeException('Cotización inexistente.');
    $revisionActual = $revisionForzada !== null ? (int)$revisionForzada : (int)($d['revision'] ?? 0);
    if ($revisionActual <= 0) throw new RuntimeException('La cotización todavía no tiene modificaciones.');
    $revisionAnterior = $revisionActual - 1;

    $st=$conexion->prepare('SELECT motivo_modificacion,fecha_revision,usuario FROM cotizaciones_revisiones WHERE cotizacion_id=? AND revision=? LIMIT 1');
    $st->bind_param('ii',$cotizacionId,$revisionAnterior); $st->execute(); $rev=$st->get_result()->fetch_assoc(); $st->close();
    $motivo = trim((string)($motivoForzado ?? motivoVersionCotizacion($conexion,(int)$cotizacionId,(int)$revisionActual)));
    if ($motivo === '') $motivo = 'Modificación de cotización.';
    $fecha = (string)($rev['fecha_revision'] ?? date('Y-m-d H:i:s'));
    $usuario = trim((string)($d['usuario_actual'] ?? ''));
    if ($usuario === '') $usuario = trim((string)($rev['usuario'] ?? ''));

    $mods = cotizacionModulosModificados($conexion,$cotizacionId,$revisionActual);
    $etiquetas = array('CONTROL'=>'Control','SENALIZACION'=>'Señalización','ACCESORIOS'=>'Accesorios','IEP'=>'IEP','REPUESTOS'=>'Repuestos');
    $modsVisibles=array(); foreach($mods as $m) $modsVisibles[]=$etiquetas[$m]??$m;
    $textoModulos = $modsVisibles ? implode(' / ',$modsVisibles) : 'Datos generales / sin cambio valorizado detectable';

    $dir=__DIR__.'/pdf/modificaciones'; if(!is_dir($dir) && !mkdir($dir,0775,true) && !is_dir($dir)) throw new RuntimeException('No se pudo crear la carpeta de modificaciones.');
    $salidas=array();
    foreach(array('PRODUCCION'=>'Producción','ADMINISTRACION'=>'Administración') as $clave=>$copia){
        $pdf=new PdfAutomac(); $y=800;
        $pdf->automacLogo(42,$y-18,82); $pdf->text(290,$y,'CARÁTULA DE MODIFICACIÓN DE COTIZACIÓN',12,true); $y-=22; $pdf->line(42,$y,553,$y,1.2); $y-=26;
        $pdf->text(42,$y,'COPIA: '.$copia,13,true); $y-=24;
        $pdf->text(42,$y,'Cotización: '.numeroDocumentoVisible((string)$d['cotizacion_numero']),11,true); $pdf->text(350,$y,etiquetaVersionCotizacion((int)$revisionActual),11,true); $y-=18;
        $pdf->text(42,$y,'Fecha de modificación: '.date('d/m/Y H:i',strtotime($fecha)),9,false); $y-=16;
        $pdf->text(42,$y,'Cliente: '.pdfTextoNominal(pdfClienteInterno($d)),9,false); $y-=16;
        if($clave==='ADMINISTRACION'){ $pdf->text(42,$y,'Solicitante: '.pdfTextoNominal((pdfSolicitanteCliente($d)?:'-')),9,false); $y-=16; }
        $pdf->text(42,$y,'Obra / referencia: '.pdfTextoNominal((string)($d['referencia']?:'-')),9,false); $y-=16;
        $pdf->text(42,$y,'Modificado por: '.pdfTextoNominal($usuario?:'-'),9,false); $y-=20;
        $pdf->fillColorRect(42,$y-25,511,31,244,248,246); $pdf->colorText(50,$y-8,'MÓDULOS MODIFICADOS: '.$textoModulos,9,true,13,99,61); $y-=42;
        $pdf->text(42,$y,'DETALLE DE LA MODIFICACIÓN',11,true); $y-=18;
        foreach($pdf->wrap($motivo,90) as $linea){$pdf->text(48,$y,$linea,10,false);$y-=15;}
        $y-=28; $pdf->line(42,$y,260,$y,.5); $pdf->line(335,$y,553,$y,.5); $y-=14;
        $pdf->text(80,$y,'Firma Producción',8,false); $pdf->text(390,$y,'Firma Administración',8,false);
        $rel='pdf/modificaciones/'.pdfNombreDocumento('COTIZACION',(string)$d['cotizacion_numero'],$d,(string)($d['referencia']??''),$revisionActual,'MODIFICACION '.$clave);
        $abs=__DIR__.'/'.$rel; $pdf->output($abs); documentosArchivarSinInterrumpir($conexion,$abs,'Modificaciones',numeroDocumentoVisible((string)$d['cotizacion_numero'])); $salidas[$clave]=$rel;
    }
    return $salidas;
}
