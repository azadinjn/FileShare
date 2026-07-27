<?php
/**
 * upload.php — AJAX endpoint that receives one or more files and builds a
 * package. Supports chunked uploads (resumable-style) so large files work
 * within InfinityFree's per-request size limits.
 *
 * Request (multipart/form-data POST):
 *   action            : "create" | "append" | "finish"
 *   chunk_index        : int (for append/finish)
 *   chunks_total       : int (for create/finish)
 *   upload_id          : string (for append/finish)
 *   package_name       : string (for create)
 *   expires_at         : "YYYY-MM-DDTHH:MM" | ""  (for create)
 *   csrf               : token
 *   file               : the chunk or whole file (for append) — JS slices it
 *
 * Response (JSON):
 *   { ok:true, upload_id, chunk_index, received }
 *   { ok:true, code, package_id, files:[{id,name,size,url}] }   (on finish)
 */

require_once __DIR__ . '/includes/helpers.php';
require_once __DIR__ . '/includes/auth.php';

const CHUNK_DIR_PREFIX = 'chunk_';

try {
    if (setting('maintenance') === '1' && !is_admin_logged_in()) {
        json_out(['ok' => false, 'error' => 'Site is under maintenance.'], 503);
    }
    if (setting('allow_uploads') !== '1' && !is_admin_logged_in()) {
        json_out(['ok' => false, 'error' => 'Uploads are currently disabled.'], 403);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['ok' => false, 'error' => 'POST required.'], 405);
    }

    if (!csrf_verify($_POST['csrf'] ?? null)) {
        json_out(['ok' => false, 'error' => 'Security token expired. Please refresh.'], 419);
    }

    $action = $_POST['action'] ?? '';
    switch ($action) {
        case 'create':  handle_create();  break;
        case 'append':  handle_append();  break;
        case 'finish':  handle_finish();  break;
        default:
            json_out(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
} catch (Throwable $e) {
    fail($e, 'Upload failed. Please try again.');
}

// ======================================================================
//  Handlers
// ======================================================================

/**
 * create: client wants to start a new package upload.
 * Returns an upload_id (the temp chunk directory) used by subsequent chunks.
 */
function handle_create(): void
{
    $name       = trim($_POST['package_name'] ?? '');
    $expiresRaw = trim($_POST['expires_at'] ?? '');

    if ($name === '') {
        $name = 'Untitled package';
    }
    if (safe_strlen($name) > 120) {
        $name = safe_substr($name, 0, 120);
    }

    $expiresAt = null;
    if ($expiresRaw !== '') {
        $ts = strtotime($expiresRaw);
        if ($ts === false || $ts < time()) {
            json_out(['ok' => false, 'error' => 'Expiration must be a future date.'], 422);
        }
        $expiresAt = date('Y-m-d H:i:s', $ts);
    }

    $uploadId = bin2hex(random_bytes(16));
    $chunkDir = chunk_dir($uploadId);
    if (!@mkdir($chunkDir, 0755, true)) {
        json_out(['ok' => false, 'error' => 'Could not create temp area.'], 500);
    }

    // Persist package metadata in session so we can finish it even if the
    // DB connection blips between chunks.
    safe_session_start();
    $_SESSION['uploads'][$uploadId] = [
        'name'       => $name,
        'expires_at' => $expiresAt,
        'created'    => time(),
    ];

    json_out(['ok' => true, 'upload_id' => $uploadId]);
}

/**
 * append: receive a chunk for a file, append to its temp file.
 * A new file is started when chunk_index == 0.
 *
 * POST fields: upload_id, file_id (client uuid), chunk_index, file_name,
 *              total_size, last_modified, file (blob)
 */
function handle_append(): void
{
    $uploadId = $_POST['upload_id'] ?? '';
    $fileId   = $_POST['file_id']   ?? '';
    $idx      = (int) ($_POST['chunk_index'] ?? -1);
    $fileName = $_POST['file_name'] ?? '';
    if ($uploadId === '' || $fileId === '' || $idx < 0 || $fileName === '') {
        json_out(['ok' => false, 'error' => 'Missing chunk parameters.'], 400);
    }

    $chunkDir = chunk_dir($uploadId);
    if (!is_dir($chunkDir)) {
        json_out(['ok' => false, 'error' => 'Upload session expired.'], 410);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $msg = upload_error_string($_FILES['file']['error'] ?? -1);
        json_out(['ok' => false, 'error' => $msg], 400);
    }

    // Validate file_id / file_name characters to prevent path traversal.
    if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fileId)) {
        json_out(['ok' => false, 'error' => 'Invalid file id.'], 400);
    }
    $safeName = basename($fileName);
    if ($safeName === '' || $safeName === '.' || $safeName === '..') {
        json_out(['ok' => false, 'error' => 'Invalid file name.'], 400);
    }
    // Reject NUL bytes and control chars.
    if (preg_match('/[\\x00-\\x1f\\x7f]/', $safeName)) {
        json_out(['ok' => false, 'error' => 'Invalid file name.'], 400);
    }

    // The assembled file lives in the chunk dir under a sanitised id.
    $dest = $chunkDir . '/' . $fileId . '.bin';
    $mode = $idx === 0 ? 'wb' : 'ab';
    $out = @fopen($dest, $mode);
    if (!$out) {
        json_out(['ok' => false, 'error' => 'Cannot write temp file.'], 500);
    }
    $in = @fopen($_FILES['file']['tmp_name'], 'rb');
    if (!$in) {
        fclose($out);
        json_out(['ok' => false, 'error' => 'Cannot read uploaded chunk.'], 500);
    }
    while (!feof($in)) {
        $buf = fread($in, 1048576);
        if ($buf === false) break;
        fwrite($out, $buf);
    }
    fclose($in);
    fclose($out);

    json_out(['ok' => true, 'upload_id' => $uploadId, 'received' => $idx]);
}

/**
 * finish: client says all chunks are uploaded.
 * Moves files into uploads/, inserts package + file rows, returns the code.
 *
 * POST fields: upload_id, manifest (JSON array of {file_id, name, size})
 */
function handle_finish(): void
{
    $uploadId = $_POST['upload_id'] ?? '';
    $manifest = $_POST['manifest']  ?? '[]';
    if ($uploadId === '') {
        json_out(['ok' => false, 'error' => 'Missing upload id.'], 400);
    }

    safe_session_start();
    $meta = $_SESSION['uploads'][$uploadId] ?? null;
    if (!$meta) {
        json_out(['ok' => false, 'error' => 'Upload session expired.'], 410);
    }

    $files = json_decode($manifest, true);
    if (!is_array($files) || count($files) === 0) {
        cleanup_chunks($uploadId);
        json_out(['ok' => false, 'error' => 'No files in package.'], 400);
    }

    $chunkDir = chunk_dir($uploadId);
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $code = generate_unique_code();
        $ins = $pdo->prepare(
            'INSERT INTO packages (code, name, expires_at) VALUES (?, ?, ?)'
        );
        $ins->execute([$code, $meta['name'], $meta['expires_at']]);
        $pkgId = (int) $pdo->lastInsertId();

        $fileIns = $pdo->prepare(
            'INSERT INTO files (package_id, original_name, stored_name, mime, size)
             VALUES (?, ?, ?, ?, ?)'
        );

        $outFiles = [];
        foreach ($files as $f) {
            $fid  = $f['file_id'] ?? '';
            $name = $f['name']    ?? '';
            $size = (int) ($f['size'] ?? 0);
            if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $fid)) continue;
            $src = $chunkDir . '/' . $fid . '.bin';
            if (!is_file($src)) continue;

            // Per-file size cap.
            if ($size > MAX_UPLOAD_BYTES) {
                throw new RuntimeException("File too large: $name");
            }

            $storedName = generate_stored_name($name);
            $dest = UPLOAD_DIR . '/' . $storedName;
            if (!@rename($src, $dest)) {
                // rename across devices may fail; fall back to copy+delete.
                if (!@copy($src, $dest)) {
                    throw new RuntimeException("Failed to store $name");
                }
                @unlink($src);
            }

            $mime = mime_type_for($name, $dest);
            $fileIns->execute([$pkgId, $name, $storedName, $mime, $size]);
            $fileId = (int) $pdo->lastInsertId();

            $outFiles[] = [
                'id'   => $fileId,
                'name' => $name,
                'size' => $size,
            ];
        }

        $pdo->commit();
        cleanup_chunks($uploadId);
        unset($_SESSION['uploads'][$uploadId]);

        log_event('info', 'Package created', "code=$code files=" . count($outFiles));

        json_out([
            'ok'          => true,
            'code'        => $code,
            'package_id'  => $pkgId,
            'files'       => $outFiles,
            'download_url'=> base_url() . '/download.php?code=' . $code,
        ]);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        cleanup_chunks($uploadId);
        throw $e;
    }
}

// ======================================================================
//  Utilities
// ======================================================================

function chunk_dir(string $uploadId): string
{
    return TEMP_DIR . '/' . CHUNK_DIR_PREFIX . $uploadId;
}

function cleanup_chunks(string $uploadId): void
{
    $dir = chunk_dir($uploadId);
    if (!is_dir($dir)) return;
    foreach (glob($dir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($dir);
}

function upload_error_string(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE   => 'File exceeds the server size limit.',
        UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form size limit.',
        UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
        UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
        UPLOAD_ERR_NO_TMP_DIR => 'Server temp folder missing.',
        UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
        UPLOAD_ERR_EXTENSION  => 'Upload blocked by a PHP extension.',
        default               => 'Unknown upload error.',
    };
}

/**
 * Best-effort MIME type, preferring finfo when available, else by extension.
 */
function mime_type_for(string $name, string $path): string
{
    if (function_exists('finfo_open')) {
        $fi = finfo_open(FILEINFO_MIME_TYPE);
        if ($fi) {
            $m = finfo_file($fi, $path);
            finfo_close($fi);
            if (is_string($m) && $m !== '') return $m;
        }
    }
    return extension_mime($name);
}

function extension_mime(string $name): string
{
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return [
        'php'  => 'text/plain',
        'js'   => 'application/javascript',
        'html' => 'text/html',
        'css'  => 'text/css',
        'zip'  => 'application/zip',
        'rar'  => 'application/vnd.rar',
        '7z'   => 'application/x-7z-compressed',
        'exe'  => 'application/x-msdownload',
        'apk'  => 'application/vnd.android.package-archive',
        'pdf'  => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'jpg'  => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png'  => 'image/png',
        'gif'  => 'image/gif',
        'mp4'  => 'video/mp4',
        'mp3'  => 'audio/mpeg',
    ][$ext] ?? 'application/octet-stream';
}
