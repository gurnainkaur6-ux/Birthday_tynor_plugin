<?php
/**
 * poster_generator.php — High-resolution birthday poster generator (PHP GD).
 *
 *  • Canvas ....... 1500 x 1000 px landscape (print-ready).
 *  • Bleed ........ 35 mm outer bleed boundary + trim guide.
 *  • Portrait ..... circular crop of the employee photo (Excel photo path),
 *                   with a coloured initials fallback when no photo exists.
 *  • Text ......... dynamic name / designation / department via TrueType,
 *                   graceful fallback to built-in GD fonts.
 *  • Lookup ....... by the unified emp_id key.
 *
 *  Returns a GD image resource (caller does imagepng / imagedestroy) or null.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

/* ── Poster geometry ────────────────────────────────────────────────── */
if (!defined('POSTER_WIDTH'))   define('POSTER_WIDTH', 1500);   // px (landscape)
if (!defined('POSTER_HEIGHT'))  define('POSTER_HEIGHT', 1000);  // px
if (!defined('POSTER_BLEED_MM'))define('POSTER_BLEED_MM', 35);  // mm outer bleed
if (!defined('POSTER_DPI'))     define('POSTER_DPI', 150);      // px per inch

/**
 * Resolve a stored photo path/URL to a readable local file, if possible.
 */
function resolvePosterPhotoFile(?string $photoPath, string $empId): ?string
{
    $appRoot = defined('APP_ROOT') ? APP_ROOT : dirname(__DIR__);
    $candidates = [];

    if ($photoPath !== null && trim($photoPath) !== '') {
        $p = trim($photoPath);

        // Full URL → take the path portion and map it under the app root.
        if (preg_match('#^https?://#i', $p)) {
            $urlPath = parse_url($p, PHP_URL_PATH) ?: '';
            $urlPath = ltrim($urlPath, '/');
            // Drop a leading project segment (e.g. "tynor/") so it maps to APP_ROOT.
            $base = basename($appRoot);
            if ($base !== '' && str_starts_with($urlPath, $base . '/')) {
                $urlPath = substr($urlPath, strlen($base) + 1);
            }
            $candidates[] = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $urlPath);
        } else {
            // Relative or absolute filesystem-ish path.
            $rel = ltrim(str_replace('\\', '/', $p), '/');
            $candidates[] = $appRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
            if (is_file($p)) $candidates[] = $p;
        }
    }

    // Legacy / convention fallbacks keyed by emp_id.
    $clean = preg_replace('/[^A-Za-z0-9]/', '', $empId);
    foreach (['jpg', 'jpeg', 'png'] as $ext) {
        $candidates[] = $appRoot . "/uploads/portraits/{$empId}.{$ext}";
        $candidates[] = $appRoot . "/uploads/portraits/{$clean}.{$ext}";
    }

    foreach ($candidates as $c) {
        if ($c && is_file($c)) return $c;
    }
    return null;
}

/**
 * Load an image file into a GD resource regardless of jpg/png/gif/webp.
 */
function loadPosterImage(string $file)
{
    $info = @getimagesize($file);
    if (!$info) return null;
    switch ($info[2]) {
        case IMAGETYPE_JPEG: return @imagecreatefromjpeg($file);
        case IMAGETYPE_PNG:  return @imagecreatefrompng($file);
        case IMAGETYPE_GIF:  return @imagecreatefromgif($file);
        case IMAGETYPE_WEBP: return function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : null;
        default:             return null;
    }
}

function generateBirthdayPosterGD(PDO $conn, string $empCode)
{
    // ── 1. Load employee via the unified key (emp_id) ────────────────
    $stmt = $conn->prepare("SELECT * FROM v_employee_master_complete WHERE emp_id = ? LIMIT 1");
    $stmt->execute([$empCode]);
    $emp = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$emp) {
        return null;
    }

    $fullName    = $emp['full_name']        ?: 'Team Member';
    $department  = $emp['department_name']  ?: 'General Operations';
    $designation = $emp['designation_name'] ?: 'Team Member';
    $plant       = $emp['plant_name']       ?: ($emp['plant_location'] ?: 'Headquarters');
    $dob         = $emp['dob_formatted']    ?: '';

    $parts    = preg_split('/\s+/u', trim($fullName));
    $initials = strtoupper(mb_substr($parts[0] ?? '', 0, 1) . (isset($parts[1]) ? mb_substr($parts[1], 0, 1) : ''));

    // ── 2. Canvas + geometry ─────────────────────────────────────────
    $W = POSTER_WIDTH;
    $H = POSTER_HEIGHT;
    $bleed = (int)round(POSTER_BLEED_MM / 25.4 * POSTER_DPI); // mm → px

    $im = imagecreatetruecolor($W, $H);
    imagealphablending($im, true);
    imagesavealpha($im, true);

    // Palette
    $darkBlue    = [10, 34, 64];
    $vibrantBlue = [29, 99, 199];
    $white       = imagecolorallocate($im, 255, 255, 255);
    $gold        = imagecolorallocate($im, 245, 158, 11);
    $lightGray   = imagecolorallocate($im, 203, 213, 225);
    $softWhite   = imagecolorallocate($im, 226, 232, 240);

    // ── 3. Vertical gradient background ──────────────────────────────
    for ($y = 0; $y < $H; $y++) {
        $t = $y / $H;
        $r = (int)($darkBlue[0] + ($vibrantBlue[0] - $darkBlue[0]) * $t);
        $g = (int)($darkBlue[1] + ($vibrantBlue[1] - $darkBlue[1]) * $t);
        $b = (int)($darkBlue[2] + ($vibrantBlue[2] - $darkBlue[2]) * $t);
        imageline($im, 0, $y, $W, $y, imagecolorallocate($im, $r, $g, $b));
    }

    // ── 4. Decorative confetti ───────────────────────────────────────
    for ($i = 0; $i < 45; $i++) {
        $cx = rand($bleed, $W - $bleed);
        $cy = rand($bleed, $H - $bleed);
        $sz = rand(14, 60);
        $deco = imagecolorallocatealpha($im, rand(200, 255), rand(180, 255), rand(150, 255), rand(85, 112));
        imagefilledellipse($im, $cx, $cy, $sz, $sz, $deco);
    }

    // ── 5. Bleed / trim guide (35 mm outer boundary) ─────────────────
    $guide = imagecolorallocatealpha($im, 255, 255, 255, 95);
    imagesetthickness($im, 2);
    imagerectangle($im, $bleed, $bleed, $W - $bleed, $H - $bleed, $guide);
    // Corner crop marks
    $mark = 40;
    imagesetthickness($im, 3);
    foreach ([[$bleed, $bleed, 1, 1], [$W - $bleed, $bleed, -1, 1], [$bleed, $H - $bleed, 1, -1], [$W - $bleed, $H - $bleed, -1, -1]] as $c) {
        [$x, $y, $dx, $dy] = $c;
        imageline($im, $x, $y, $x + $dx * $mark, $y, $guide);
        imageline($im, $x, $y, $x, $y + $dy * $mark, $guide);
    }
    imagesetthickness($im, 1);

    // ── 6. Fonts ─────────────────────────────────────────────────────
    $fontCandidates = [
        'C:/Windows/Fonts/arialbd.ttf',
        'C:/Windows/Fonts/arial.ttf',
        'C:/Windows/Fonts/segoeuib.ttf',
        'C:/Windows/Fonts/segoeui.ttf',
        '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
        '/usr/share/fonts/truetype/freefont/FreeSansBold.ttf',
    ];
    $fontFile = null;
    foreach ($fontCandidates as $f) {
        if (is_file($f)) { $fontFile = $f; break; }
    }

    $drawText = function ($size, $x, $y, $color, $text) use ($im, $fontFile) {
        if ($fontFile) {
            imagettftext($im, $size, 0, $x, $y, $color, $fontFile, $text);
        } else {
            $gdFont = $size > 26 ? 5 : ($size > 16 ? 4 : 3);
            imagestring($im, $gdFont, $x, $y - $size, $text, $color);
        }
    };

    // ── 7. Circular portrait (left column, inside safe area) ─────────
    $avatarR = 200;
    $avatarX = $bleed + 45 + $avatarR;
    $avatarY = (int)($H / 2);

    // White ring
    imagefilledellipse($im, $avatarX, $avatarY, $avatarR * 2 + 24, $avatarR * 2 + 24, $white);
    imagefilledellipse($im, $avatarX, $avatarY, $avatarR * 2 + 12, $avatarR * 2 + 12, $gold);

    $photoFile   = resolvePosterPhotoFile($emp['photo_path'] ?? null, $empCode);
    $portrait    = $photoFile ? loadPosterImage($photoFile) : null;

    if ($portrait) {
        $w = imagesx($portrait);
        $h = imagesy($portrait);
        $min = min($w, $h);

        // Square, centre-cropped source
        $square = imagecreatetruecolor($min, $min);
        imagecopy($square, $portrait, 0, 0, (int)(($w - $min) / 2), (int)(($h - $min) / 2), $min, $min);

        // Circular mask target
        $dia    = $avatarR * 2;
        $circle = imagecreatetruecolor($dia, $dia);
        imagealphablending($circle, false);
        imagesavealpha($circle, true);
        $trans = imagecolorallocatealpha($circle, 0, 0, 0, 127);
        imagefill($circle, 0, 0, $trans);
        imagecopyresampled($circle, $square, 0, 0, 0, 0, $dia, $dia, $min, $min);

        // Punch a circular alpha mask
        for ($yy = 0; $yy < $dia; $yy++) {
            for ($xx = 0; $xx < $dia; $xx++) {
                $dxp = $xx - $dia / 2;
                $dyp = $yy - $dia / 2;
                if (($dxp * $dxp + $dyp * $dyp) > ($avatarR * $avatarR)) {
                    imagesetpixel($circle, $xx, $yy, $trans);
                }
            }
        }
        imagecopy($im, $circle, $avatarX - $avatarR, $avatarY - $avatarR, 0, 0, $dia, $dia);
        imagedestroy($portrait);
        imagedestroy($square);
        imagedestroy($circle);
    } else {
        // Initials fallback
        $bg = imagecolorallocate($im, 29, 78, 216);
        imagefilledellipse($im, $avatarX, $avatarY, $avatarR * 2, $avatarR * 2, $bg);
        if ($fontFile) {
            $fs   = 120;
            $bbox = imagettfbbox($fs, 0, $fontFile, $initials);
            $tw   = $bbox[2] - $bbox[0];
            $th   = $bbox[1] - $bbox[7];
            imagettftext($im, $fs, 0, (int)($avatarX - $tw / 2), (int)($avatarY + $th / 2), $white, $fontFile, $initials);
        } else {
            imagestring($im, 5, $avatarX - 20, $avatarY - 10, $initials, $white);
        }
    }

    // ── 8. Text column (right of portrait), kept inside the safe area ─
    $tx      = $avatarX + $avatarR + 70;
    $right   = $W - $bleed - 40;
    $maxW    = max(200, $right - $tx);

    // Measure text width (TrueType if available, else approximate GD width).
    $measure = function (int $size, string $text) use ($fontFile) {
        if ($fontFile) {
            $bb = imagettfbbox($size, 0, $fontFile, $text);
            return abs($bb[2] - $bb[0]);
        }
        return strlen($text) * imagefontwidth(5);
    };
    // Shrink a font size until the text fits within $maxW (down to $min).
    $fit = function (int $size, int $min, string $text) use ($measure, $maxW) {
        while ($size > $min && $measure($size, $text) > $maxW) $size--;
        return $size;
    };
    // Word-wrap into lines that each fit within $maxW.
    $wrap = function (int $size, string $text) use ($measure, $maxW) {
        $words = preg_split('/\s+/', trim($text));
        $lines = [];
        $cur   = '';
        foreach ($words as $w) {
            $try = $cur === '' ? $w : "$cur $w";
            if ($measure($size, $try) > $maxW && $cur !== '') {
                $lines[] = $cur;
                $cur = $w;
            } else {
                $cur = $try;
            }
        }
        if ($cur !== '') $lines[] = $cur;
        return $lines;
    };

    $y = $avatarY - 210;

    $drawText($fit(26, 16, 'WISHING A VERY HAPPY BIRTHDAY TO'), $tx, $y, $gold, 'WISHING A VERY HAPPY BIRTHDAY TO');
    $y += 70;

    $nameSize = $fit(64, 34, $fullName);
    $drawText($nameSize, $tx, $y, $white, $fullName);
    $y += 66;

    $drawText($fit(30, 20, $designation), $tx, $y, $softWhite, $designation);
    $y += 44;

    foreach ($wrap(24, 'Department of ' . $department) as $ln) {
        $drawText(24, $tx, $y, $lightGray, $ln);
        $y += 34;
    }

    $y += 16;
    imagesetthickness($im, 2);
    imageline($im, $tx, $y, $right, $y, $softWhite);
    imagesetthickness($im, 1);
    $y += 46;

    // (Birth year intentionally not printed on the poster — it can be shared
    //  company-wide, so exposing DOB/age would be inappropriate.)

    $message = 'Thank you for your valued contributions to our team. '
             . 'May your day be filled with joy, peace and prosperity!';
    foreach ($wrap(22, $message) as $ln) {
        $drawText(22, $tx, $y, $white, $ln);
        $y += 32;
    }

    $company = defined('COMPANY_NAME') ? COMPANY_NAME : 'Tynor Orthotics';
    $y += 24;
    foreach ($wrap(20, $company . ' HR Team  |  ' . $plant) as $ln) {
        $drawText(20, $tx, $y, $gold, $ln);
        $y += 28;
    }

    return $im;
}