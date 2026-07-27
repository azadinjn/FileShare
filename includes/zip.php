<?php
/**
 * zip.php — ZIP archive builder.
 *
 * Uses the native ext-zip when available (XAMPP ships it; InfinityFree has it
 * on most plans). Falls back to a pure-PHP ZIP writer (stored, no compression)
 * when ext-zip is missing, so "download all as ZIP" always works.
 *
 * Public API:
 *   create_zip(array $files, string $outPath): bool
 *   download_zip(string $outPath, string $downloadName): void
 *
 * $files = [['path'=>abs path,'name'=>name inside zip], ...]
 */

/**
 * Build a ZIP at $outPath from $files. Returns true on success.
 */
function create_zip(array $files, string $outPath): bool
{
    if (class_exists('ZipArchive')) {
        return create_zip_native($files, $outPath);
    }
    return create_zip_pure($files, $outPath);
}

function create_zip_native(array $files, string $outPath): bool
{
    $zip = new ZipArchive();
    $res = $zip->open($outPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($res !== true) {
        error_log("ZipArchive open failed: $res");
        return false;
    }
    foreach ($files as $f) {
        if (!is_file($f['path'])) {
            continue;
        }
        $zip->addFile($f['path'], $f['name']);
    }
    return $zip->close();
}

// ----------------------------------------------------------------------
//  Pure-PHP ZIP writer (STORE method, no compression).
//  Implements the minimal PKZIP structure readers require:
//  local file headers + central directory + end-of-central-directory record.
// ----------------------------------------------------------------------
function create_zip_pure(array $files, string $outPath): bool
{
    $fp = @fopen($outPath, 'wb');
    if (!$fp) {
        return false;
    }

    $central = '';
    $offset  = 0;
    $written = 0; // number of entries actually added

    foreach ($files as $f) {
        if (!is_file($f['path'])) {
            continue;
        }
        $data = @file_get_contents($f['path']);
        if ($data === false) {
            continue;
        }
        $name    = $f['name'];
        $nameBin = $name; // ASCII-safe; non-ASCII names left as-is.
        $crc     = crc32($data);
        $size    = strlen($data);
        $time    = time();
        $dosTime = dos_time($time);
        $dosDate = dos_date($time);

        // Local file header (signature 0x04034b50)
        $local = pack('V', 0x04034b50)
            . pack('v', 20)            // version needed
            . pack('v', 0x0000)        // flags
            . pack('v', 0x0000)        // method = stored
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)         // compressed size
            . pack('V', $size)         // uncompressed size
            . pack('v', strlen($nameBin))
            . pack('v', 0)             // extra length
            . $nameBin
            . $data;

        fwrite($fp, $local);

        // Central directory header (signature 0x02014b50)
        $central .= pack('V', 0x02014b50)
            . pack('v', 20)            // version made by
            . pack('v', 20)            // version needed
            . pack('v', 0x0000)        // flags
            . pack('v', 0x0000)        // method
            . pack('v', $dosTime)
            . pack('v', $dosDate)
            . pack('V', $crc)
            . pack('V', $size)
            . pack('V', $size)
            . pack('v', strlen($nameBin))
            . pack('v', 0)             // extra length
            . pack('v', 0)             // comment length
            . pack('v', 0)             // disk number start
            . pack('v', 0)             // internal attrs
            . pack('V', 0)             // external attrs
            . pack('V', $offset)       // offset of local header
            . $nameBin;

        $offset += strlen($local);
        $written++;
    }

    $centralStart = $offset;
    $centralSize  = strlen($central);
    fwrite($fp, $central);

    // End of central directory record (signature 0x06054b50)
    $eocd = pack('V', 0x06054b50)
        . pack('v', 0)            // disk number
        . pack('v', 0)            // disk with central dir
        . pack('v', $written)     // entries on this disk
        . pack('v', $written)     // total entries
        . pack('V', $centralSize)
        . pack('V', $centralStart)
        . pack('v', 0);           // comment length
    fwrite($fp, $eocd);

    fclose($fp);
    return true;
}

/** DOS time field from a unix timestamp. */
function dos_time(int $ts): int
{
    $h = (int) gmdate('H', $ts);
    $m = (int) gmdate('i', $ts);
    $s = (int) gmdate('s', $ts) >> 1;
    return ($h << 11) | ($m << 5) | $s;
}

/** DOS date field from a unix timestamp. */
function dos_date(int $ts): int
{
    $y = ((int) gmdate('Y', $ts)) - 1980;
    $m = (int) gmdate('n', $ts);
    $d = (int) gmdate('j', $ts);
    return ($y << 9) | ($m << 5) | $d;
}

/**
 * Stream a built ZIP to the browser and delete the temp file afterwards.
 */
function download_zip(string $outPath, string $downloadName): void
{
    if (!is_file($outPath)) {
        http_response_code(404);
        echo 'File not found.';
        exit;
    }
    $size = filesize($outPath);
    header('Content-Description: File Transfer');
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="'
        . rawurlencode($downloadName) . '"');
    header('Content-Transfer-Encoding: binary');
    header('Content-Length: ' . $size);
    header('Cache-Control: private, no-store, no-cache, must-revalidate');
    // Stream in chunks to keep memory flat on shared hosts.
    $fp = fopen($outPath, 'rb');
    while (!feof($fp)) {
        echo fread($fp, 1048576);
        flush();
    }
    fclose($fp);
    @unlink($outPath);
    exit;
}
