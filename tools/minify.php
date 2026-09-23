<?php
/**
 * KitsuPlay asset minifier — build step without a build system.
 *
 *   php tools/minify.php          # write assets/css/*.min.css, assets/js/*.min.js,
 *                                 # user/js/*.min.js
 *   php tools/minify.php --check  # report what is stale, change nothing
 *   php tools/minify.php --clean  # delete every generated .min.* file
 *
 * header.php never references these files directly: kp_asset() serves the
 * .min.* build only while it exists and is at least as new as its source, so a
 * missing or stale build silently falls back to the original file. Run this
 * after editing anything in assets/css, assets/js or user/js.
 *
 * The minifiers are deliberately conservative — comments, indentation and
 * redundant whitespace only. No identifier renaming, no statement joining, so
 * the output cannot change behaviour (line breaks are preserved for ASI).
 */

declare(strict_types=1);

$root = dirname(__DIR__);

$mode = $argv[1] ?? '';

$targets = [
    $root . '/assets/css' => 'css',
    $root . '/assets/js'  => 'js',
    $root . '/user/js'    => 'js',
];

/** Strip comments and squeeze whitespace out of a stylesheet. */
function kp_min_css(string $css): string
{
    $len = strlen($css);
    $out = '';
    $i = 0;
    $pending = '';        // whitespace waiting to be written
    $prev = '';           // last character actually written

    while ($i < $len) {
        $ch = $css[$i];

        // /* comment */
        if ($ch === '/' && ($css[$i + 1] ?? '') === '*') {
            $end = strpos($css, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            $pending = $pending === '' ? ' ' : $pending;
            continue;
        }

        // Quoted strings are copied verbatim: a font stack or content value may
        // contain ";" or a double space that must survive the squeeze.
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            $chunk = $ch;
            $i++;
            while ($i < $len) {
                $c = $css[$i];
                $chunk .= $c;
                $i++;
                if ($c === '\\' && $i < $len) {
                    $chunk .= $css[$i];
                    $i++;
                    continue;
                }
                if ($c === $quote) break;
            }
            $out .= kp_css_flush($pending, $prev, $chunk[0]);
            $pending = '';
            $out .= $chunk;
            $prev = substr($chunk, -1);
            continue;
        }

        if (ctype_space($ch)) {
            $pending = (strpos($pending, "\n") !== false || $ch === "\n") ? "\n" : ' ';
            $i++;
            continue;
        }

        $out .= kp_css_flush($pending, $prev, $ch);
        $pending = '';
        $out .= $ch;
        $prev = $ch;
        $i++;
    }

    return trim($out);
}

/**
 * Whitespace is kept only when it separates two tokens — never next to the
 * punctuation that delimits them.
 */
function kp_css_flush(string $pending, string $prev, string $next): string
{
    if ($pending === '') return '';
    if ($prev === '') return '';
    if (strpos('{};,', $prev) !== false) return '';
    if (strpos('{};,', $next) !== false) return '';
    return ' ';
}

/**
 * Strip JS comments and indentation, keep every line break.
 *
 * ASI is the reason newlines survive: joining `a` and `(` on one line can turn
 * a call into a syntax error, so this pass never removes a line break.
 */
function kp_min_js(string $js): string
{
    $len = strlen($js);
    $out = '';
    $i = 0;
    $prev = '';          // last significant character written
    $prevWord = '';      // last identifier written (for regex detection)
    $pending = '';

    while ($i < $len) {
        $ch = $js[$i];

        // // line comment
        if ($ch === '/' && ($js[$i + 1] ?? '') === '/') {
            $nl = strpos($js, "\n", $i);
            $i = $nl === false ? $len : $nl;
            continue;
        }

        // /* block comment */
        if ($ch === '/' && ($js[$i + 1] ?? '') === '*') {
            $end = strpos($js, '*/', $i + 2);
            $i = $end === false ? $len : $end + 2;
            if ($pending === '') $pending = ' ';
            continue;
        }

        // Strings and template literals.
        if ($ch === '"' || $ch === "'" || $ch === '`') {
            $chunk = kp_js_scan_string($js, $i, $ch);
            $out .= kp_js_flush($pending, $prev, $chunk[0]);
            $pending = '';
            $out .= $chunk;
            $i += strlen($chunk);
            $prev = substr($chunk, -1);
            $prevWord = '';
            continue;
        }

        // Regex literal (only where a value is expected) — `x /2/ y` stays division.
        if ($ch === '/' && kp_js_regex_here($prev, $prevWord)) {
            $chunk = kp_js_scan_regex($js, $i);
            if ($chunk !== '') {
                $out .= kp_js_flush($pending, $prev, '/');
                $pending = '';
                $out .= $chunk;
                $i += strlen($chunk);
                $prev = substr($chunk, -1);
                $prevWord = '';
                continue;
            }
        }

        if (ctype_space($ch)) {
            $pending = strpos($pending, "\n") !== false || $ch === "\n" ? "\n" : ' ';
            $i++;
            continue;
        }

        $out .= kp_js_flush($pending, $prev, $ch);
        $pending = '';
        $out .= $ch;
        $prev = $ch;
        $prevWord = ctype_alnum($ch) || $ch === '_' || $ch === '$'
            ? $prevWord . $ch
            : '';
        $i++;
    }

    return trim($out);
}

/** Copy a quoted string / template literal, escapes included. */
function kp_js_scan_string(string $js, int $start, string $quote): string
{
    $len = strlen($js);
    $chunk = $quote;
    $i = $start + 1;

    while ($i < $len) {
        $c = $js[$i];
        $chunk .= $c;
        $i++;
        if ($c === '\\' && $i < $len) {
            $chunk .= $js[$i];
            $i++;
            continue;
        }
        if ($c === $quote) break;
        if ($quote === '`' && $c === '$' && ($js[$i] ?? '') === '{') {
            // ${ … } — copy the interpolation verbatim, skipping nested braces
            // and strings so a "}" inside a literal cannot end it early.
            $chunk .= '{';
            $i++;
            $depth = 1;
            while ($i < $len && $depth > 0) {
                $c2 = $js[$i];
                if ($c2 === '"' || $c2 === "'" || $c2 === '`') {
                    $inner = kp_js_scan_string($js, $i, $c2);
                    $chunk .= $inner;
                    $i += strlen($inner);
                    continue;
                }
                if ($c2 === '{') $depth++;
                elseif ($c2 === '}') $depth--;
                $chunk .= $c2;
                $i++;
            }
        }
    }

    return $chunk;
}

/** Copy a regex literal. Returns '' when the '/' was a division operator. */
function kp_js_scan_regex(string $js, int $start): string
{
    $len = strlen($js);
    $i = $start + 1;
    $inClass = false;

    while ($i < $len) {
        $c = $js[$i];
        if ($c === '\\') { $i += 2; continue; }
        if ($c === "\n") return '';            // unterminated: not a regex
        if ($c === '[') $inClass = true;
        elseif ($c === ']') $inClass = false;
        elseif ($c === '/' && !$inClass) {
            $i++;
            while ($i < $len && ctype_alpha($js[$i])) $i++;   // flags
            return substr($js, $start, $i - $start);
        }
        $i++;
    }

    return '';
}

/** A '/' starts a regex only where an expression is expected. */
function kp_js_regex_here(string $prev, string $prevWord): bool
{
    if ($prev === '') return true;
    if (strpos('(,=:[!&|?{};+-*%^~<>', $prev) !== false) return true;
    if (ctype_alnum($prev) || $prev === '_' || $prev === '$') {
        return in_array($prevWord, [
            'return', 'typeof', 'instanceof', 'in', 'of', 'new', 'delete',
            'void', 'case', 'do', 'else', 'throw', 'yield', 'await',
        ], true);
    }
    return false;
}

/** Drop pending whitespace around punctuation (never between two +/-). */
function kp_js_flush(string $pending, string $prev, string $next): string
{
    if ($pending === '') return '';
    if ($next === "\n" && $pending === "\n") return "\n";
    if ($pending === "\n") return "\n";
    if ($prev === '') return '';
    if (strpos('{}();,=:[', $prev) !== false) return '';
    if (strpos('{}();,=:]', $next) !== false) return '';
    if (($prev === '+' && $next === '+') || ($prev === '-' && $next === '-')) return ' ';
    return ' ';
}

// ─── Runner ──────────────────────────────────────────────────────────────────

function kp_fmt(int $bytes): string
{
    return $bytes >= 1024 ? round($bytes / 1024, 1) . ' KB' : $bytes . ' B';
}

$written = 0;
$skipped = 0;
$stale   = 0;

foreach ($targets as $dir => $ext) {

    if (!is_dir($dir)) continue;

    foreach (glob($dir . '/*.' . $ext) ?: [] as $source) {

        // Never treat a generated file as a source.
        if (str_ends_with($source, '.min.' . $ext)) continue;

        $minFile = preg_replace('/\.' . $ext . '$/', '.min.' . $ext, $source);
        $srcSize = (int)filesize($source);

        if ($mode === '--clean') {
            if (is_file($minFile)) {
                unlink($minFile);
                echo "removed  " . basename($minFile) . PHP_EOL;
                $written++;
            }
            continue;
        }

        if ($mode === '--check') {
            $fresh = is_file($minFile) && filemtime($minFile) >= filemtime($source);
            if (!$fresh) {
                echo "STALE    " . basename($source) . PHP_EOL;
                $stale++;
            }
            continue;
        }

        $code = (string)file_get_contents($source);
        if ($code === '') continue;

        $min = $ext === 'css' ? kp_min_css($code) : kp_min_js($code);

        // A build that grows the file means the minifier mis-read something;
        // keep the original in that case rather than shipping something worse.
        if ($min === '' || strlen($min) >= $srcSize) {
            echo "skipped  " . basename($source) . " (no gain)" . PHP_EOL;
            $skipped++;
            continue;
        }

        // Header notes keep it obvious that the file is generated.
        $banner = $ext === 'css'
            ? "/*! " . basename($source) . " (minified build) — edit the source and re-run: php tools/minify.php */\n"
            : "/*! " . basename($source) . " (minified build) — edit the source and re-run: php tools/minify.php */\n";

        file_put_contents($minFile, $banner . $min . PHP_EOL);

        $newSize = (int)filesize($minFile);
        $saved   = $srcSize - $newSize;
        printf(
            "built    %-28s %8s -> %8s  (-%d%%)%s",
            basename($minFile),
            kp_fmt($srcSize),
            kp_fmt($newSize),
            $srcSize > 0 ? (int)round($saved * 100 / $srcSize) : 0,
            PHP_EOL
        );
        $written++;
    }
}

if ($mode === '--check') {
    echo $stale === 0
        ? 'All minified builds are up to date.' . PHP_EOL
        : $stale . " stale build(s) — run: php tools/minify.php" . PHP_EOL;
} elseif ($mode === '--clean') {
    echo "Removed {$written} generated file(s)." . PHP_EOL;
} else {
    echo "Built {$written} file(s)";
    echo $skipped > 0 ? ", skipped {$skipped} (no gain)." : '.';
    echo PHP_EOL;
}
