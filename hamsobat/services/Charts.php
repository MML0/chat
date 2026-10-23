<?php
// Tiny server-side SVG charts for the panel: no JavaScript library, no CDN (works on an
// Iranian host and in an Iranian browser). Colours come from CSS classes, so the charts
// follow the light/dark theme. Every mark has a <title> tooltip.
namespace Hamsobat;

defined('HAMSOBAT') || exit;

final class Charts
{
    /**
     * Vertical bars. $points = [['label' => 'tooltip/axis', 'value' => n, 'value2' => optional line value], …]
     */
    public static function bars(array $points, string $unit = '', string $lineUnit = '', int $height = 160, int $labelEvery = 0): string
    {
        $w = 600;
        $h = $height;
        $pad = 18;
        $n = max(1, count($points));
        $max = max(1, ...array_map(fn ($p) => (float) $p['value'], $points ?: [['value' => 1]]));
        $lineMax = max(1, ...array_map(fn ($p) => (float) ($p['value2'] ?? 0), $points ?: [['value2' => 1]]));
        $slot = ($w - 4) / $n;
        $bw = max(2, $slot * 0.68);
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . ($h + $pad) . '" preserveAspectRatio="none" role="img">';
        foreach ([0.25, 0.5, 0.75] as $g) {
            $y = round($h - $h * $g, 1);
            $svg .= '<line class="grid" x1="0" x2="' . $w . '" y1="' . $y . '" y2="' . $y . '"/>';
        }
        $line = [];
        foreach ($points as $i => $p) {
            $v = (float) $p['value'];
            $bh = $v > 0 ? max(2, ($h - 6) * $v / $max) : 0;
            $x = 2 + $i * $slot + ($slot - $bw) / 2;
            $tip = Html::e($p['label'] . ' — ' . Html::n($v) . ($unit ? " {$unit}" : '') . (isset($p['value2']) ? ' · ' . Html::n($p['value2']) . ($lineUnit ? " {$lineUnit}" : '') : ''));
            $svg .= '<rect class="bar" rx="2" x="' . round($x, 1) . '" y="' . round($h - $bh, 1) . '" width="' . round($bw, 1) . '" height="' . round($bh, 1) . '"><title>' . $tip . '</title></rect>';
            if (isset($p['value2'])) {
                $line[] = round($x + $bw / 2, 1) . ',' . round($h - ($h - 6) * (float) $p['value2'] / $lineMax, 1);
            }
            $every = $labelEvery ?: max(1, (int) ceil($n / 8));
            if ($i % $every === 0 && !empty($p['axis'])) {
                $svg .= '<text class="axis" x="' . round($x + $bw / 2, 1) . '" y="' . ($h + 13) . '" text-anchor="middle">' . Html::e($p['axis']) . '</text>';
            }
        }
        if (count($line) > 1) {
            $svg .= '<polyline class="line2" points="' . implode(' ', $line) . '"/>';
        }
        return $svg . '<text class="axis max" x="' . ($w - 2) . '" y="11" text-anchor="end">' . Html::n($max) . '</text></svg>';
    }

    /**
     * Time chart with real numbers: Y-axis ticks, a dashed average line, the peak labelled,
     * flagged buckets (amber + ⚠), crosshair + tooltip (panel.js) and a table twin. All text
     * is HTML over the plot, so it stays sharp when the plot stretches to the card's width.
     * One unit, one axis: never two scales in one chart.
     *
     * $c = [
     *   'type'    => 'bars' | 'lines',
     *   'series'  => [['name' => …, 'values' => (float|null)[], 'key' => 's1'|'s2'], …] (null = no data, a gap),
     *   'unit'    => 'ms' | '' (ms ticks switch to seconds from 2 s), 'unitLabel' => 'درخواست در هر ساعت',
     *   'axis'    => [index => short x label], 'avg' => ?float, 'avgLabel' => 'میانگین: …',
     *   'threshold' => ?[value, label], 'flags' => int[] buckets to flag, 'peakLabel' => ?string,
     *   'tips'    => [['t' => time label, 'r' => [[name, value text, key], …]], …] one per bucket,
     *   'table'   => ['head' => string[], 'rows' => string[][]] (already formatted, newest first),
     * ]
     */
    public static function time(array $c): string
    {
        $series = $c['series'];
        $n = count($series[0]['values'] ?? []);
        $all = array_filter(array_merge(...array_map(fn ($s) => $s['values'], $series)), fn ($v) => $v !== null);
        if ($n === 0 || !$all || max($all) <= 0) {
            return '<div class="empty">در این بازه درخواستی ثبت نشده.</div>';
        }
        $ms = ($c['unit'] ?? '') === 'ms';
        $top = max(max($all), (float) ($c['avg'] ?? 0));
        if (!empty($c['threshold']) && $c['threshold'][0] <= $top * 1.6) {
            $top = max($top, (float) $c['threshold'][0]); // show the line only when it is near the data
        } else {
            $c['threshold'] = null;
        }
        [$axisMax, $step] = self::niceScale($top, !$ms);
        $sec = $ms && $axisMax >= 2000;
        $pct = fn (float $v) => round(100 * $v / $axisMax, 2);
        $x = fn (int $i) => round(100 * ($i + 0.5) / $n, 3);
        $tick = fn (float $v) => $sec ? Html::n($v / 1000, fmod($v, 1000) ? 1 : 0) : Html::n($v);

        $plot = '';
        for ($v = 0; $v <= $axisMax + 1e-9; $v += $step) {
            $plot .= '<i class="tc-grid' . ($v == 0 ? ' base' : '') . '" style="bottom:' . $pct($v) . '%"></i>';
        }
        $yAxis = '<div class="tc-y"><b>' . Html::e($ms ? ($sec ? 'ثانیه' : 'میلی‌ثانیه') : ($c['unitLabel'] ?? '')) . '</b>';
        for ($v = 0; $v <= $axisMax + 1e-9; $v += $step) {
            $yAxis .= '<span style="bottom:' . $pct($v) . '%">' . $tick($v) . '</span>';
        }
        $yAxis .= '</div>';

        $flags = array_flip($c['flags'] ?? []);
        $dots = [];
        if ($c['type'] === 'bars') {
            $plot .= '<div class="tc-cols">';
            foreach ($series[0]['values'] as $i => $v) {
                $plot .= '<span class="tc-col"><i class="tc-bar ' . $series[0]['key'] . (isset($flags[$i]) ? ' flag' : '') . '" style="height:' . ($v ? max(0.8, $pct((float) $v)) : 0) . '%"></i></span>';
            }
            $plot .= '</div>';
        } else {
            $svg = '<svg class="tc-svg" viewBox="0 0 1000 1000" preserveAspectRatio="none" aria-hidden="true">';
            foreach (array_reverse($series) as $s) { // first series drawn last, on top
                $d = '';
                $open = false;
                foreach ($s['values'] as $i => $v) {
                    if ($v === null) {
                        $open = false;
                        continue;
                    }
                    $d .= ($open ? ' L' : ' M') . round(10 * $x($i), 1) . ',' . round(1000 - 10 * $pct((float) $v), 1);
                    $open = true;
                }
                $svg .= '<path class="tc-line ' . $s['key'] . '" d="' . trim($d) . '"/>';
            }
            $plot .= $svg . '</svg>';
            foreach ($series as $s) {
                $dots[] = ['k' => $s['key'], 'y' => array_map(fn ($v) => $v === null ? null : $pct((float) $v), $s['values'])];
                // a lone point between gaps has no line: show it as a dot
                foreach ($s['values'] as $i => $v) {
                    if ($v !== null && ($s['values'][$i - 1] ?? null) === null && ($s['values'][$i + 1] ?? null) === null) {
                        $plot .= '<i class="tc-pt ' . $s['key'] . '" style="left:' . $x($i) . '%;bottom:' . $pct((float) $v) . '%"></i>';
                    }
                }
            }
        }
        if ($c['type'] === 'lines') { // flagged points of the first line: amber markers (bars turn amber instead)
            foreach ($c['flags'] ?? [] as $i) {
                if (($series[0]['values'][$i] ?? null) !== null) {
                    $plot .= '<i class="tc-pt warn" style="left:' . $x($i) . '%;bottom:' . $pct((float) $series[0]['values'][$i]) . '%"></i>';
                }
            }
        }
        // Reference lines are named in the legend row, so their labels never cover the data.
        if (!empty($c['threshold'])) {
            $plot .= '<i class="tc-ref thr" style="bottom:' . $pct($c['threshold'][0]) . '%"></i>';
        }
        if (!empty($c['avg'])) {
            $plot .= '<i class="tc-ref" style="bottom:' . $pct($c['avg']) . '%"></i>';
        }
        // The peak of the first series, labelled once.
        $vals = $series[0]['values'];
        $known = array_filter($vals, fn ($v) => $v !== null);
        $peak = $known ? array_search(max($known), $vals, true) : false;
        if ($peak !== false && !empty($c['peakLabel'])) {
            $side = $x($peak) > 80 ? ' end' : ($x($peak) < 20 ? ' start' : '');
            if ($c['type'] === 'lines') {
                $plot .= '<i class="tc-pt s1" style="left:' . $x($peak) . '%;bottom:' . $pct((float) $vals[$peak]) . '%"></i>';
            }
            $plot .= '<span class="tc-peak' . $side . '" style="left:' . $x($peak) . '%;bottom:' . $pct((float) $vals[$peak]) . '%">' . Html::e($c['peakLabel']) . '</span>';
        }

        $xAxis = '<div class="tc-x">';
        foreach ($c['axis'] ?? [] as $i => $label) {
            $xAxis .= '<span style="left:' . $x((int) $i) . '%">' . Html::e($label) . '</span>';
        }
        $xAxis .= '</div>';

        $keys = [];
        if (count($series) > 1) {
            foreach ($series as $s) {
                $keys[] = '<span><i class="' . ($c['type'] === 'bars' ? 'sw' : 'ln') . ' ' . $s['key'] . '"></i>' . Html::e($s['name']) . '</span>';
            }
        }
        if (!empty($c['avg'])) {
            $keys[] = '<span><i class="dash"></i>' . Html::e($c['avgLabel'] ?? '') . '</span>';
        }
        if (!empty($c['threshold'])) {
            $keys[] = '<span><i class="dash thr"></i>' . Html::e($c['threshold'][1]) . '</span>';
        }
        $legend = $keys ? '<figcaption class="tc-legend">' . implode('', $keys) . '</figcaption>' : '';

        $table = '';
        if (!empty($c['table']['rows'])) {
            $table = '<details class="tc-table"><summary>📋 جدول اعداد</summary><div class="tc-scroll"><table><thead><tr>';
            foreach ($c['table']['head'] as $h) {
                $table .= '<th>' . Html::e($h) . '</th>';
            }
            $table .= '</tr></thead><tbody>';
            foreach ($c['table']['rows'] as $row) {
                $table .= '<tr>' . implode('', array_map(fn ($cell) => '<td>' . Html::e($cell) . '</td>', $row)) . '</tr>';
            }
            $table .= '</tbody></table></div></details>';
        }

        $data = Html::e(json_encode(['n' => $n, 'tips' => $c['tips'], 'dots' => $dots], JSON_UNESCAPED_UNICODE));
        return '<figure class="tc tc-' . $c['type'] . '" data-tc="' . $data . '"><div class="tc-body">' . $yAxis
            . '<div class="tc-plot" tabindex="0" role="img" aria-label="' . Html::e(($c['peakLabel'] ?? '') . ' · ' . ($c['avgLabel'] ?? '')) . '">' . $plot . '</div></div>'
            . $xAxis . $legend . $table . '</figure>';
    }

    /** A clean axis top and step (1, 2, 2.5 or 5 × 10ⁿ), 2–5 intervals. @return array{0:float,1:float} */
    private static function niceScale(float $max, bool $integer): array
    {
        $raw = max($max, $integer ? 1 : 1e-6) / 4;
        $mag = 10 ** floor(log10($raw));
        $step = 10 * $mag;
        foreach ([1, 2, 2.5, 5, 10] as $m) {
            if ($m * $mag >= $raw && (!$integer || fmod($m * $mag, 1) == 0)) {
                $step = $m * $mag;
                break;
            }
        }
        $step = $integer ? max(1, $step) : $step;
        return [max(1, ceil($max / $step)) * $step, $step];
    }

    /** Smooth-ish line with area fill. $values oldest first. */
    public static function area(array $values, array $labels = [], string $unit = '', int $height = 120): string
    {
        $w = 600;
        $h = $height;
        $n = count($values);
        if ($n < 2) {
            return '<div class="empty">داده‌ای نیست</div>';
        }
        $max = max(1, ...$values);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = [round($i * $w / ($n - 1), 1), round($h - 4 - ($h - 10) * $v / $max, 1)];
        }
        $d = 'M' . implode(' L', array_map(fn ($p) => $p[0] . ',' . $p[1], $pts));
        $svg = '<svg class="chart" viewBox="0 0 ' . $w . ' ' . $h . '" preserveAspectRatio="none" role="img">';
        $svg .= '<path class="area" d="' . $d . ' L' . $w . ',' . $h . ' L0,' . $h . ' Z"/>';
        $svg .= '<path class="stroke" d="' . $d . '"/>';
        foreach ($pts as $i => $p) {
            $svg .= '<circle class="dot" cx="' . $p[0] . '" cy="' . $p[1] . '" r="3"><title>' . Html::e(($labels[$i] ?? '') . ' — ' . Html::n($values[$i], $values[$i] < 10 && $values[$i] != (int) $values[$i] ? 1 : 0) . ($unit ? " {$unit}" : '')) . '</title></circle>';
        }
        return $svg . '<text class="axis max" x="' . ($w - 2) . '" y="11" text-anchor="end">' . Html::n($max) . '</text></svg>';
    }

    /** Ring chart. $parts = [['label' => …, 'value' => n, 'class' => 'c1'], …] */
    public static function donut(array $parts, string $center = ''): string
    {
        $total = array_sum(array_column($parts, 'value'));
        $r = 42;
        $c = 2 * M_PI * $r;
        $svg = '<svg class="donut" viewBox="0 0 120 120" role="img"><circle class="track" cx="60" cy="60" r="' . $r . '"/>';
        $offset = 0;
        foreach ($parts as $p) {
            if ($total <= 0 || $p['value'] <= 0) {
                continue;
            }
            $len = $c * $p['value'] / $total;
            $svg .= '<circle class="seg ' . Html::e($p['class']) . '" cx="60" cy="60" r="' . $r . '" stroke-dasharray="' . round($len, 2) . ' ' . round($c - $len, 2) . '" stroke-dashoffset="' . round(-$offset, 2) . '"><title>'
                . Html::e($p['label'] . ': ' . Html::n($p['value']) . ' (' . Html::n(100 * $p['value'] / $total) . '٪)') . '</title></circle>';
            $offset += $len;
        }
        $svg .= '<text class="center" x="60" y="66" text-anchor="middle">' . Html::e($center) . '</text></svg>';
        $legend = '<ul class="legend">';
        foreach ($parts as $p) {
            $legend .= '<li><i class="' . Html::e($p['class']) . '"></i>' . Html::e($p['label']) . ' <b>' . Html::n($p['value']) . '</b></li>';
        }
        return '<div class="donut-wrap">' . $svg . $legend . '</ul></div>';
    }

    /** Weekday × hour grid. $grid[0..6][0..23] = count, 0 = Saturday (Persian week). */
    public static function heatmap(array $grid): string
    {
        $days = ['شنبه', 'یکشنبه', 'دوشنبه', 'سه‌شنبه', 'چهارشنبه', 'پنجشنبه', 'جمعه'];
        $max = 1;
        foreach ($grid as $row) {
            $max = max($max, ...$row);
        }
        $html = '<div class="heatmap"><div class="hm-row hm-head"><span></span>';
        for ($h = 0; $h < 24; $h++) {
            $html .= '<span>' . ($h % 3 === 0 ? Text::fa($h) : '') . '</span>';
        }
        $html .= '</div>';
        foreach ($days as $d => $name) {
            $html .= '<div class="hm-row"><span class="hm-day">' . $name . '</span>';
            for ($h = 0; $h < 24; $h++) {
                $v = (int) ($grid[$d][$h] ?? 0);
                $level = $v ? 1 + (int) floor(4 * $v / ($max + 0.0001)) : 0;
                $html .= '<span class="hm-cell l' . min(4, $level) . '" title="' . Html::e($name . ' ساعت ' . Text::fa($h) . ': ' . Html::n($v) . ' پیام') . '"></span>';
            }
            $html .= '</div>';
        }
        return $html . '</div>';
    }

    /** Horizontal bars for top lists. $items = [['label' => …, 'value' => n], …] */
    public static function hbars(array $items, string $unit = ''): string
    {
        if (!$items) {
            return '<div class="empty">داده‌ای نیست</div>';
        }
        $max = max(1, ...array_map(fn ($i) => (float) $i['value'], $items));
        $html = '<ul class="hbars">';
        foreach ($items as $i) {
            $html .= '<li><span class="hb-label">' . Html::e($i['label']) . '</span><span class="hb-track"><span class="hb-fill" style="width:'
                . round(100 * $i['value'] / $max, 1) . '%"></span></span><b>' . Html::n($i['value']) . ($unit ? ' ' . Html::e($unit) : '') . '</b></li>';
        }
        return $html . '</ul>';
    }

    /** Mini line for KPI cards. */
    public static function spark(array $values): string
    {
        $n = count($values);
        if ($n < 2) {
            return '';
        }
        $max = max(1, ...$values);
        $pts = [];
        foreach ($values as $i => $v) {
            $pts[] = round($i * 100 / ($n - 1), 1) . ',' . round(28 - 26 * $v / $max, 1);
        }
        return '<svg class="spark" viewBox="0 0 100 30" preserveAspectRatio="none"><polyline points="' . implode(' ', $pts) . '"/></svg>';
    }

    /** Semicircle gauge 0..100. */
    public static function gauge(?float $percent, string $label): string
    {
        if ($percent === null) {
            return '<div class="gauge na"><div class="g-value">؟</div><div class="g-label">' . Html::e($label) . '</div></div>';
        }
        $p = max(0, min(100, $percent));
        $len = round(M_PI * 50 * $p / 100, 1);
        $cls = $p < 60 ? 'ok' : ($p < 85 ? 'warn' : 'bad');
        return '<div class="gauge ' . $cls . '"><svg viewBox="0 0 120 70"><path class="g-track" d="M10,60 A50,50 0 0 1 110,60"/>'
            . '<path class="g-fill" d="M10,60 A50,50 0 0 1 110,60" stroke-dasharray="' . $len . ' 999"/></svg>'
            . '<div class="g-value">' . Html::n($p) . '٪</div><div class="g-label">' . Html::e($label) . '</div></div>';
    }
}
