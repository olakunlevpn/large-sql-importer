<?php
/**
 * Large Database Importer — single-file, resumable, STANDALONE.
 *
 * Drop this one file anywhere inside ANY PHP website (or its own folder),
 * open it in a browser, type your database details, and import. No framework,
 * no Composer, no dependencies — just PHP + mysqli (bundled with PHP).
 *
 * Features
 *  - Works on any host: you enter host / user / password / database manually.
 *  - Optional auto-detect: prefills creds from wp-config.php or .env if found.
 *  - Chunked upload (bypasses upload_max_filesize) OR pick an already-uploaded file.
 *  - Supports .sql and .sql.gz.
 *  - Streaming (SSE) import: seeks once, runs continuously, persists byte-offset.
 *  - Resume after SQL error, disconnect, or timeout — continues from last boundary.
 *  - Robust SQL splitter: handles quotes, escapes, comments and DELIMITER.
 *
 * !!! SECURITY WARNING !!!
 * This tool has NO login. Anyone who can open this URL can read and overwrite
 * your ENTIRE database. There is no protection. DELETE THIS FILE the moment you
 * finish importing. Never leave it on a live/public server.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
@ini_set('display_errors', '0');
@ini_set('zlib.output_compression', '0');

define('UPLOAD_DIR', __DIR__ . '/uploads');
define('STATE_DIR',  __DIR__ . '/.state');
define('BLOCK_SIZE', 1 << 20);                              // 1MB read block
define('MAX_STMT',   128 << 20);                            // 128MB single-statement cap
define('CHUNK_TXN',  0);                                    // 0 = autocommit (safe resume)
define('BATCH_BYTES', 1 << 20);                             // merge INSERTs up to ~1MB/query (< max_allowed_packet)
define('BATCH_ROWS',  2000);                                // ...or this many rows, whichever first

@mkdir(UPLOAD_DIR, 0755, true);
@mkdir(STATE_DIR, 0755, true);

if (!is_file(STATE_DIR . '/.htaccess')) @file_put_contents(STATE_DIR . '/.htaccess', "Deny from all\n");

/* ----------------------------------------------------------------------------
 * DB defaults — generic, with optional best-effort auto-detect.
 *
 * Standalone: the user always types their own details. Auto-detect only PREFILLS
 * the form when a common config file (wp-config.php / Laravel .env) sits nearby.
 * Nothing here is required for the tool to work on any other site.
 * ------------------------------------------------------------------------- */
function db_defaults()
{
    $def = array('host' => 'localhost', 'user' => 'root', 'pass' => '',
        'name' => '', 'charset' => 'utf8mb4', 'source' => '');

    // Search this dir and a few parents for a config file to prefill from.
    $dir = __DIR__;
    for ($i = 0; $i < 5 && $dir && $dir !== dirname($dir); $i++, $dir = dirname($dir)) {
        // WordPress
        $wp = $dir . '/wp-config.php';
        if (is_file($wp) && ($src = @file_get_contents($wp)) !== false) {
            $g = function ($const) use ($src) {
                if (preg_match('/define\(\s*[\'"]' . $const . '[\'"]\s*,\s*[\'"]([^\'"]*)[\'"]/', $src, $m)) return $m[1];
                return null;
            };
            $name = $g('DB_NAME'); $user = $g('DB_USER'); $pass = $g('DB_PASSWORD'); $host = $g('DB_HOST');
            if ($name !== null) {
                if ($name !== null) $def['name'] = $name;
                if ($user !== null) $def['user'] = $user;
                if ($pass !== null) $def['pass'] = $pass;
                if ($host)          $def['host'] = $host;
                $def['source'] = 'wp-config.php';
                return $def;
            }
        }
        // Laravel / generic .env
        $env = $dir . '/.env';
        if (is_file($env) && ($src = @file_get_contents($env)) !== false) {
            $g = function ($key) use ($src) {
                if (preg_match('/^\s*' . $key . '\s*=\s*"?([^"\r\n]*)"?/m', $src, $m)) return trim($m[1]);
                return null;
            };
            $name = $g('DB_DATABASE'); $user = $g('DB_USERNAME'); $pass = $g('DB_PASSWORD'); $host = $g('DB_HOST');
            if ($name) {
                $def['name'] = $name;
                if ($user !== null) $def['user'] = $user;
                if ($pass !== null) $def['pass'] = $pass;
                if ($host)          $def['host'] = $host;
                $def['source'] = '.env';
                return $def;
            }
        }
    }
    return $def;
}

/* ----------------------------------------------------------------------------
 * Small helpers.
 * ------------------------------------------------------------------------- */
function jout($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

function param($key, $default = '')
{
    if (isset($_POST[$key])) return $_POST[$key];
    if (isset($_GET[$key]))  return $_GET[$key];
    return $default;
}

function safe_name($name)
{
    // Keep the real filename (spaces, parens, etc. are fine) — only strip
    // path traversal and control chars so it stays confined to UPLOAD_DIR.
    $name = str_replace(array("\0", '/', '\\'), '', (string) $name);
    $name = basename($name);
    $name = preg_replace('/[\x00-\x1F]/', '', $name);
    $name = ltrim($name, '.');               // no hidden / ".." traversal
    return $name !== '' ? $name : 'file';
}

function is_allowed_file($name)
{
    return (bool) preg_match('/\.sql(\.gz|\.bz2)?$/i', $name) || (bool) preg_match('/\.zip$/i', $name);
}

function is_gz($name)
{
    return (bool) preg_match('/\.gz$/i', $name);
}

function is_bz2($name)
{
    return (bool) preg_match('/\.bz2$/i', $name);
}

function is_zip($name)
{
    return (bool) preg_match('/\.zip$/i', $name);
}

function file_kind($name)
{
    if (is_gz($name))  return 'gz';
    if (is_bz2($name)) return 'bz2';
    if (is_zip($name)) return 'zip';
    return 'plain';
}

function job_id($path)
{
    return sha1(realpath($path) ?: $path);
}

function state_path($job)
{
    return STATE_DIR . '/' . preg_replace('/[^a-f0-9]/', '', $job) . '.json';
}

function state_load($job)
{
    $p = state_path($job);
    if (!is_file($p)) return null;
    $d = json_decode(@file_get_contents($p), true);
    return is_array($d) ? $d : null;
}

function state_save($job, $state)
{
    @file_put_contents(state_path($job), json_encode($state), LOCK_EX);
}

function fmt_bytes($b)
{
    $b = (float) $b;
    $u = array('B', 'KB', 'MB', 'GB', 'TB');
    $i = 0;
    while ($b >= 1024 && $i < 4) { $b /= 1024; $i++; }
    return round($b, $b >= 100 || $i == 0 ? 0 : 1) . ' ' . $u[$i];
}

/** Uncompressed size from gzip ISIZE trailer (valid for < 4GB, single member). */
function gz_uncompressed_size($path)
{
    $fp = @fopen($path, 'rb');
    if (!$fp) return 0;
    if (fseek($fp, -4, SEEK_END) !== 0) { fclose($fp); return 0; }
    $b = fread($fp, 4);
    fclose($fp);
    if (strlen($b) < 4) return 0;
    $a = unpack('V', $b);
    return isset($a[1]) ? (int) $a[1] : 0;
}

/* ----------------------------------------------------------------------------
 * SQL statement extractor.
 *
 * Returns array($items, $remainder, $consumed) where $items is a list of
 * array('sql' => string, 'end' => int) — 'end' is the byte offset within
 * $buffer immediately after that statement's delimiter. $consumed is the total
 * bytes of $buffer that form complete statements/directives. DELIMITER
 * directives are consumed (mutate &$delimiter) but never emitted as SQL.
 * ------------------------------------------------------------------------- */
function sql_extract($buffer, &$delimiter)
{
    $items    = array();
    $n        = strlen($buffer);
    $pos      = 0;

    while ($pos < $n) {
        // Skip leading whitespace at a fresh statement boundary.
        $j = $pos;
        while ($j < $n) {
            $c = $buffer[$j];
            if ($c === ' ' || $c === "\n" || $c === "\r" || $c === "\t") { $j++; continue; }
            break;
        }
        if ($j >= $n) { $pos = $n; break; } // only whitespace remains — consume it

        // DELIMITER directive (client-side, must own its line).
        $head = substr($buffer, $j, 10);
        if (strcasecmp(substr($head, 0, 9), 'DELIMITER') === 0
            && isset($buffer[$j + 9]) && ($buffer[$j + 9] === ' ' || $buffer[$j + 9] === "\t")) {
            $eol = strpos($buffer, "\n", $j);
            if ($eol === false) break; // need the rest of the line
            $line = substr($buffer, $j, $eol - $j);
            $nd   = trim(substr($line, 9));
            if ($nd !== '') $delimiter = $nd;
            $pos = $eol + 1;
            continue;
        }

        $r = sql_scan($buffer, $j, $delimiter);
        if ($r === null) break; // incomplete statement, wait for more data
        $end  = $r[0];
        $text = $r[1];
        $t    = trim($text);
        if ($t !== '') $items[] = array('sql' => $t, 'end' => $end, 'start' => $j);
        $pos = $end;
    }

    return array($items, substr($buffer, $pos), $pos);
}

/** Scan one statement from $start. Returns array($posAfterDelim, $text) or null. */
function sql_scan($buf, $start, $delim)
{
    $n  = strlen($buf);
    $i  = $start;
    $dl = strlen($delim);
    $d0 = $delim[0];

    while ($i < $n) {
        $c = $buf[$i];

        // Quoted strings / identifiers.
        if ($c === "'" || $c === '"' || $c === '`') {
            $q = $c;
            $i++;
            $closed = false;
            while ($i < $n) {
                $cc = $buf[$i];
                if ($cc === '\\' && $q !== '`') { $i += 2; continue; }   // backslash escape
                if ($cc === $q) {
                    if ($i + 1 < $n && $buf[$i + 1] === $q) { $i += 2; continue; } // doubled quote
                    $i++; $closed = true; break;
                }
                $i++;
            }
            if (!$closed) return null; // unterminated — need more data
            continue;
        }

        // Line comment: -- (followed by space/eol) or #
        if ($c === '#' ||
            ($c === '-' && $i + 1 < $n && $buf[$i + 1] === '-' &&
             ($i + 2 >= $n || $buf[$i + 2] === ' ' || $buf[$i + 2] === "\t" || $buf[$i + 2] === "\n" || $buf[$i + 2] === "\r"))) {
            $eol = strpos($buf, "\n", $i);
            if ($eol === false) return null;
            $i = $eol + 1;
            continue;
        }

        // Block comment.
        if ($c === '/' && $i + 1 < $n && $buf[$i + 1] === '*') {
            $endc = strpos($buf, '*/', $i + 2);
            if ($endc === false) return null;
            $i = $endc + 2;
            continue;
        }

        // Delimiter?
        if ($c === $d0 && ($dl === 1 || substr($buf, $i, $dl) === $delim)) {
            return array($i + $dl, substr($buf, $start, $i - $start));
        }

        $i++;
    }

    return null;
}

/**
 * Is this a database-scope statement (CREATE/DROP/ALTER DATABASE|SCHEMA, USE)?
 * Used to skip them so the dump imports into the connected target DB instead of
 * whatever DB name the file hard-codes. Strips leading comments first.
 */
function stmt_is_db_scope($sql)
{
    $s = ltrim($sql);
    while ($s !== '') {
        if (substr($s, 0, 2) === '/*') {
            $e = strpos($s, '*/');
            if ($e === false) break;
            $s = ltrim(substr($s, $e + 2));
            continue;
        }
        if (substr($s, 0, 2) === '--' || $s[0] === '#') {
            $nl = strpos($s, "\n");
            if ($nl === false) { $s = ''; break; }
            $s = ltrim(substr($s, $nl + 1));
            continue;
        }
        break;
    }
    return (bool) preg_match('/^(USE\s|CREATE\s+(DATABASE|SCHEMA)\b|DROP\s+(DATABASE|SCHEMA)\b|ALTER\s+(DATABASE|SCHEMA)\b)/i', $s);
}

/**
 * If $sql is a plain row-insert (INSERT/INSERT IGNORE/REPLACE ... VALUES <tuples>
 * with nothing after the tuples), set $prefix to the part up to and including
 * VALUES and $vals to the tuple list, and return true. Such statements sharing
 * an identical $prefix can be merged into one multi-row INSERT for speed.
 * INSERT ... SELECT and ON DUPLICATE KEY UPDATE are rejected (not safely mergeable).
 */
function ldi_batch_parts($sql, &$prefix, &$vals)
{
    if (!preg_match('/^(INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+([^\s(]+)\s*(\([^)]*\))?\s*VALUES\b\s*(.+)$/is', $sql, $m)) {
        return false;
    }
    $rest = rtrim($m[4]);
    if ($rest === '' || preg_match('/\bON\s+DUPLICATE\b/i', $rest)) return false;
    $p = $m[1] . ' INTO ' . $m[2] . (isset($m[3]) && $m[3] !== '' ? ' ' . $m[3] : '') . ' VALUES';
    $prefix = preg_replace('/\s+/', ' ', $p);
    $vals = $rest;
    return true;
}

/** Table a statement targets (lowercased, schema/backticks stripped), or null. */
function ldi_stmt_table($sql)
{
    $s = ltrim($sql);
    // strip a single leading comment if present
    if (substr($s, 0, 2) === '/*') { $e = strpos($s, '*/'); if ($e !== false) $s = ltrim(substr($s, $e + 2)); }
    if (preg_match('/^(?:INSERT(?:\s+IGNORE)?|REPLACE)\s+INTO\s+([^\s(;]+)/is', $s, $m)
        || preg_match('/^CREATE\s+(?:TEMPORARY\s+)?TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([^\s(;]+)/is', $s, $m)
        || preg_match('/^DROP\s+TABLE\s+(?:IF\s+EXISTS\s+)?([^\s(;,]+)/is', $s, $m)
        || preg_match('/^ALTER\s+TABLE\s+([^\s(;]+)/is', $s, $m)
        || preg_match('/^TRUNCATE\s+(?:TABLE\s+)?([^\s(;]+)/is', $s, $m)
        || preg_match('/^LOCK\s+TABLES\s+([^\s(;]+)/is', $s, $m)) {
        $t = str_replace('`', '', $m[1]);
        if (strpos($t, '.') !== false) { $parts = explode('.', $t); $t = end($parts); }
        return strtolower($t);
    }
    return null;
}

/** Transient errors worth retrying (deadlock, lock wait, too-many-connections). */
function ldi_is_transient($errno)
{
    return in_array((int) $errno, array(1213, 1205, 1040, 1203), true);
}

/** Run a query, retrying transient errors up to 3 times. $retries counts retries used. */
function ldi_try_query($conn, $sql, &$retries)
{
    $attempt = 0;
    while (true) {
        if (@mysqli_query($conn, $sql)) return true;
        if ($attempt < 3 && ldi_is_transient(mysqli_errno($conn))) {
            $attempt++; $retries++; usleep(200000 * $attempt); continue;
        }
        return false;
    }
}

/** First .sql entry inside a .zip (or first entry); returns array(name,size) or null. */
function ldi_zip_entry($path)
{
    if (!class_exists('ZipArchive')) return null;
    $z = new ZipArchive();
    if ($z->open($path) !== true) return null;
    $pick = null; $first = null;
    for ($i = 0; $i < $z->numFiles; $i++) {
        $st = $z->statIndex($i);
        if (!$st || substr($st['name'], -1) === '/') continue;
        if ($first === null) $first = $st;
        if (preg_match('/\.sql$/i', $st['name'])) { $pick = $st; break; }
    }
    $z->close();
    $e = $pick ?: $first;
    return $e ? array('name' => $e['name'], 'size' => (int) $e['size']) : null;
}

/* ----------------------------------------------------------------------------
 * DB connect.
 * ------------------------------------------------------------------------- */
function db_connect($c, &$err)
{
    @mysqli_report(MYSQLI_REPORT_OFF);

    // Accept host forms: "host", "host:port", "host:/path/to/socket" (WP/.env style).
    $host   = $c['host'];
    $port   = null;
    $socket = null;
    if (strpos($host, ':') !== false) {
        list($h, $rest) = explode(':', $host, 2);
        $host = $h;
        if ($rest !== '' && $rest[0] === '/') $socket = $rest;       // unix socket path
        elseif (ctype_digit($rest))           $port   = (int) $rest; // tcp port
    }
    if ($port === null && !empty($c['port'])) $port = (int) $c['port'];

    $conn = @mysqli_connect($host, $c['user'], $c['pass'], $c['name'],
        $port ? $port : 0, $socket ? $socket : null);
    if (!$conn) { $err = mysqli_connect_error(); return null; }
    $cs = isset($c['charset']) && $c['charset'] !== '' ? $c['charset'] : 'utf8mb4';
    @mysqli_set_charset($conn, $cs);
    return $conn;
}

/* ============================================================================
 * ACTION ROUTER
 * ========================================================================= */
$action = param('action');

/* No authentication by design — delete this file when finished. */

/* ---- test connection ---- */
if ($action === 'test') {
    $err = '';
    $conn = db_connect(array(
        'host' => param('host'), 'user' => param('user'),
        'pass' => param('pass'), 'name' => param('name'),
        'charset' => param('charset', 'utf8mb4'),
    ), $err);
    if (!$conn) jout(array('ok' => false, 'error' => $err));
    $ver  = mysqli_get_server_info($conn);
    $type = (stripos($ver, 'mariadb') !== false) ? 'MariaDB' : 'MySQL';
    mysqli_close($conn);
    jout(array('ok' => true, 'version' => $ver, 'type' => $type));
}

/* ---- list files ---- */
if ($action === 'list') {
    $out = array();
    foreach ((array) glob(UPLOAD_DIR . '/*') as $f) {
        if (!is_file($f) || !is_allowed_file($f)) continue;
        $size = filesize($f);
        $job  = job_id($f);
        $st   = state_load($job);
        $row  = array(
            'name'  => basename($f),
            'size'  => $size,
            'sizeh' => fmt_bytes($size),
            'gz'    => is_gz($f),
            'bz2'   => is_bz2($f),
            'zip'   => is_zip($f),
            'job'   => $job,
            'mtime' => filemtime($f),
        );
        if ($st) {
            $total = isset($st['total']) ? (float) $st['total'] : 0;
            $off   = isset($st['offset']) ? (float) $st['offset'] : 0;
            $row['status']   = isset($st['status']) ? $st['status'] : '';
            $row['percent']  = $total > 0 ? round($off / $total * 100, 1) : null;
            $row['offset']   = $off;
            $row['executed'] = isset($st['executed']) ? $st['executed'] : 0;
            $row['error']    = isset($st['error']) ? $st['error'] : '';
        }
        $out[] = $row;
    }
    usort($out, function ($a, $b) { return $b['mtime'] - $a['mtime']; });
    jout(array('ok' => true, 'files' => $out));
}

/* ---- chunked upload ---- */
if ($action === 'upload') {
    $name = safe_name(param('name'));
    if (!is_allowed_file($name)) jout(array('ok' => false, 'error' => 'Only .sql, .sql.gz or .sql.bz2 allowed'));
    if (!isset($_FILES['chunk']) || $_FILES['chunk']['error'] !== UPLOAD_ERR_OK) {
        jout(array('ok' => false, 'error' => 'No chunk received'));
    }
    $index = (int) param('index');
    $total = (int) param('total');
    $dest  = UPLOAD_DIR . '/' . $name;
    $mode  = ($index === 0) ? 'wb' : 'ab';
    $out   = @fopen($dest, $mode);
    if (!$out) jout(array('ok' => false, 'error' => 'Cannot open destination'));
    $in = @fopen($_FILES['chunk']['tmp_name'], 'rb');
    if (!$in) { fclose($out); jout(array('ok' => false, 'error' => 'Cannot read chunk')); }
    while (!feof($in)) { fwrite($out, fread($in, BLOCK_SIZE)); }
    fclose($in);
    fclose($out);
    if ($index === 0) { @unlink(state_path(job_id($dest))); } // new upload invalidates old state
    jout(array('ok' => true, 'received' => $index + 1, 'total' => $total, 'size' => filesize($dest)));
}

/* ---- delete file ---- */
if ($action === 'delete') {
    $name = safe_name(param('name'));
    $path = UPLOAD_DIR . '/' . $name;
    if (is_file($path)) { @unlink(state_path(job_id($path))); @unlink($path); }
    jout(array('ok' => true));
}

/* ---- prepare import (store params, set offset) ---- */
if ($action === 'prepare') {
    $name = safe_name(param('name'));
    $path = UPLOAD_DIR . '/' . $name;
    if (!is_file($path)) jout(array('ok' => false, 'error' => 'File not found'));

    $db = array(
        'host' => param('host'), 'user' => param('user'),
        'pass' => param('pass'), 'name' => param('name_db'),
        'charset' => param('charset', 'utf8mb4'),
    );
    $err = '';
    $conn = db_connect($db, $err);
    if (!$conn) jout(array('ok' => false, 'error' => 'DB connection failed: ' . $err));
    mysqli_close($conn);

    $kind  = file_kind($path);
    $gz    = ($kind === 'gz');
    $zipEntry = '';
    if ($kind === 'gz')       $total = gz_uncompressed_size($path); // ISIZE (approx > 4GB)
    elseif ($kind === 'bz2')  $total = 0;                            // bzip2 has no size trailer
    elseif ($kind === 'zip') {
        $ze = ldi_zip_entry($path);
        if (!$ze) jout(array('ok' => false, 'error' => 'No readable file found inside the .zip (or ZipArchive missing).'));
        $zipEntry = $ze['name'];
        $total    = $ze['size'];                                     // zip stores uncompressed size
    } else {
        $total = filesize($path);
    }
    if ($kind === 'bz2' && !function_exists('bzopen')) {
        jout(array('ok' => false, 'error' => 'This file is .bz2 but the bzip2 PHP extension is not installed on the server.'));
    }
    if ($kind === 'zip' && !class_exists('ZipArchive')) {
        jout(array('ok' => false, 'error' => 'This file is .zip but the ZipArchive PHP extension is not installed on the server.'));
    }
    $job   = job_id($path);
    $mode  = param('mode', 'fresh');
    $old   = state_load($job);

    if ($mode === 'resume' && $old && isset($old['offset'])) {
        $offset   = (float) $old['offset'];
        $executed = isset($old['executed']) ? (int) $old['executed'] : 0;
        $delim    = isset($old['delimiter']) ? $old['delimiter'] : ';';
    } else {
        $offset = 0; $executed = 0; $delim = ';';
    }

    $state = array(
        'file'        => $name,
        'path'        => $path,
        'gz'          => $gz,
        'kind'        => $kind,
        'zipEntry'    => $zipEntry,
        'total'       => $total,
        'offset'      => $offset,
        'executed'    => $executed,
        'delimiter'   => $delim,
        'db'          => $db,
        'skipErrors'  => (int) param('skipErrors') ? 1 : 0,
        'fkChecks'    => (int) param('fkChecks') ? 1 : 0,
        'forceDb'     => (int) param('forceDb') ? 1 : 0,
        'dryRun'      => (int) param('dryRun') ? 1 : 0,
        'skipExisting'=> (int) param('skipExisting') ? 1 : 0,
        'tables'      => trim((string) param('tables')),
        'preSql'      => (string) param('preSql'),
        'postSql'     => (string) param('postSql'),
        'redirected'  => isset($old['redirected']) && $mode === 'resume' ? (int) $old['redirected'] : 0,
        'status'      => 'ready',
        'error'       => '',
        'heartbeat'   => time(),
    );
    state_save($job, $state);
    jout(array('ok' => true, 'job' => $job, 'total' => $total, 'offset' => $offset,
        'gz' => $gz, 'totalKnown' => $total > 0));
}

/* ---- run import (SSE stream) ---- */
if ($action === 'run') {
    $job = preg_replace('/[^a-f0-9]/', '', param('job'));
    $st  = state_load($job);

    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    while (ob_get_level() > 0) ob_end_flush();

    $sse = function ($event, $data) {
        echo "event: {$event}\n";
        echo 'data: ' . json_encode($data) . "\n\n";
        @flush();
    };

    if (!$st) { $sse('error', array('error' => 'No job state')); exit; }

    // Guard against double-run.
    if (isset($st['status']) && $st['status'] === 'running'
        && isset($st['heartbeat']) && (time() - (int) $st['heartbeat']) < 15) {
        $sse('error', array('error' => 'Job already running'));
        exit;
    }

    @set_time_limit(0);
    @ignore_user_abort(true);

    $path = $st['path'];
    $kind = isset($st['kind']) ? $st['kind'] : (!empty($st['gz']) ? 'gz' : 'plain');
    $total = (float) $st['total'];
    $offset = (float) $st['offset'];
    $executed = (int) $st['executed'];
    $delim = isset($st['delimiter']) ? $st['delimiter'] : ';';
    $skip  = !empty($st['skipErrors']);
    $forceDb = !empty($st['forceDb']);
    $dryRun = !empty($st['dryRun']);
    $skipExisting = !empty($st['skipExisting']);

    if ($kind === 'bz2' && !function_exists('bzopen')) {
        $sse('error', array('error' => 'bzip2 (bz2) PHP extension not available on this server')); exit;
    }
    if ($kind === 'zip' && !class_exists('ZipArchive')) {
        $sse('error', array('error' => 'ZipArchive PHP extension not available on this server')); exit;
    }

    $err = '';
    $conn = db_connect($st['db'], $err);
    if (!$conn) { $sse('error', array('error' => 'DB connection failed: ' . $err)); exit; }

    if (!$dryRun && empty($st['fkChecks'])) {
        @mysqli_query($conn, 'SET FOREIGN_KEY_CHECKS=0');
        @mysqli_query($conn, 'SET UNIQUE_CHECKS=0');
    }

    // Table whitelist ("import only these") + existing-table set ("skip existing").
    $whitelist = null;
    if (trim((string) (isset($st['tables']) ? $st['tables'] : '')) !== '') {
        $whitelist = array();
        foreach (preg_split('/[\s,]+/', strtolower($st['tables'])) as $t) {
            $t = trim($t, '` '); if ($t !== '') $whitelist[$t] = true;
        }
    }
    $existing = array();
    if ($skipExisting) {
        $rt = @mysqli_query($conn, 'SHOW TABLES');
        if ($rt) while ($row = mysqli_fetch_row($rt)) $existing[strtolower($row[0])] = true;
    }

    // Open (no native seek — we read+discard to offset so we can also count lines).
    if ($kind === 'gz') {
        $fh = @gzopen($path, 'rb');
    } elseif ($kind === 'bz2') {
        $fh = @bzopen($path, 'r');
    } elseif ($kind === 'zip') {
        $entry = isset($st['zipEntry']) ? $st['zipEntry'] : '';
        $fh = @fopen('zip://' . $path . '#' . $entry, 'rb');
    } else {
        $fh = @fopen($path, 'rb');
    }
    if (!$fh) { $sse('error', array('error' => 'Cannot open file (' . $kind . ')')); exit; }

    $reader = function ($n) use ($fh, $kind) {
        if ($kind === 'gz')  return gzread($fh, $n);
        if ($kind === 'bz2') return bzread($fh, $n);
        return fread($fh, $n);
    };
    $closer = function () use ($fh, $kind) {
        if ($kind === 'gz')  { @gzclose($fh); return; }
        if ($kind === 'bz2') { @bzclose($fh); return; }
        @fclose($fh);
    };

    // Skip to the resume offset, counting newlines so error reports carry line numbers.
    $lineCount = 0; // newlines strictly before $base
    if ($offset > 0) {
        $left = $offset;
        while ($left > 0) {
            $c = $reader((int) min(BLOCK_SIZE, $left));
            if ($c === '' || $c === false) break;
            $lineCount += substr_count($c, "\n");
            $left -= strlen($c);
        }
    }

    $st['status'] = 'running';
    $st['error']  = '';
    $st['heartbeat'] = time();
    state_save($job, $st);

    $t0        = microtime(true);
    $startOff  = $offset;
    $base      = $offset;   // file offset of buffer start
    $buffer    = '';
    $lastEmit  = 0;
    $lastSave  = microtime(true);

    // Shared mutable run-state (object so closures see writes without by-ref soup).
    // $S->offset is the last COMMITTED statement boundary — a pending batch is not
    // committed until flushed, so resume re-reads from $S->offset and re-batches.
    $S = (object) array(
        'executed'        => $executed,
        'skipped'         => 0,
        'redirected'      => isset($st['redirected']) ? (int) $st['redirected'] : 0,
        'filtered'        => 0,   // skipped: not in selected-tables whitelist
        'filteredExisting'=> 0,   // skipped: table already exists
        'retries'         => 0,   // transient-error retries used
        'offset'          => $offset,
        'warnings'        => array(),
        'tables'          => array(), // distinct tables seen (for dry-run summary)
        'batch'           => null,
        'stop'            => false,
        'error'           => null,
        'failSql'         => '',
        'failLine'        => null,
    );
    $WARN_CAP = 100;

    $addWarn = function ($off, $line, $msg, $sql) use ($S, $WARN_CAP) {
        if (count($S->warnings) < $WARN_CAP) {
            $S->warnings[] = array('offset' => round($off), 'line' => $line, 'error' => $msg, 'sql' => substr($sql, 0, 200));
        }
    };

    // Execute one standalone statement. Returns false only to STOP (stop-on-error).
    $execOne = function ($sql, $soff, $eoff, $line) use ($conn, $S, $skip, $addWarn, $dryRun) {
        if ($dryRun) { $S->executed++; $S->offset = $eoff; return true; }
        if (ldi_try_query($conn, $sql, $S->retries)) { $S->executed++; $S->offset = $eoff; return true; }
        if ($skip) { $S->skipped++; $addWarn($soff, $line, mysqli_error($conn), $sql); $S->offset = $eoff; return true; }
        $S->offset = $soff; $S->error = mysqli_error($conn); $S->failSql = substr($sql, 0, 400); $S->failLine = $line; $S->stop = true;
        return false;
    };

    // Flush the pending INSERT batch as one multi-row query.
    $flush = function () use ($conn, $S, $skip, $addWarn, $dryRun) {
        if (!$S->batch) return true;
        $b = $S->batch;
        if ($dryRun) { $S->executed += $b['count']; $S->offset = $b['end']; $S->batch = null; return true; }
        // Join with "\n," so a trailing line comment (-- or #) inside one tuple is
        // terminated by the newline before the next tuple, instead of swallowing it.
        $merged = $b['prefix'] . ' ' . implode("\n,", $b['vals']);
        if (ldi_try_query($conn, $merged, $S->retries)) {
            $S->executed += $b['count']; $S->offset = $b['end']; $S->batch = null; return true;
        }
        if ($skip) {
            // Fall back to per-row so one bad row doesn't drop the whole batch.
            foreach ($b['vals'] as $v) {
                $one = $b['prefix'] . ' ' . $v;
                if (ldi_try_query($conn, $one, $S->retries)) { $S->executed++; }
                else { $S->skipped++; $addWarn($b['start'], $b['line'], mysqli_error($conn), $one); }
            }
            $S->offset = $b['end']; $S->batch = null; return true;
        }
        $S->offset = $b['start']; $S->error = mysqli_error($conn); $S->failSql = substr($merged, 0, 400);
        $S->failLine = $b['line']; $S->stop = true; $S->batch = null; return false;
    };

    // Process one logical statement: filter / redirect / batch / flush+exec. Returns false to STOP.
    $process = function ($sql, $soff, $eoff, $line) use ($S, $forceDb, $whitelist, $existing, $skipExisting, $dryRun, $flush, $execOne) {
        if ($forceDb && stmt_is_db_scope($sql)) {
            if (!$flush()) return false;
            $S->redirected++; $S->offset = $eoff; return true;
        }
        $table = ldi_stmt_table($sql);
        if ($table !== null) {
            if ($whitelist !== null && !isset($whitelist[$table])) { if (!$flush()) return false; $S->filtered++; $S->offset = $eoff; return true; }
            if ($skipExisting && isset($existing[$table]))         { if (!$flush()) return false; $S->filteredExisting++; $S->offset = $eoff; return true; }
            if ($dryRun && count($S->tables) < 500) $S->tables[$table] = true;
        }
        $prefix = $vals = null;
        if (ldi_batch_parts($sql, $prefix, $vals)) {
            if ($S->batch && ($S->batch['prefix'] !== $prefix
                || $S->batch['bytes'] + strlen($vals) > BATCH_BYTES
                || $S->batch['count'] >= BATCH_ROWS)) {
                if (!$flush()) return false;
            }
            if (!$S->batch) {
                $S->batch = array('prefix' => $prefix, 'vals' => array($vals),
                    'start' => $soff, 'end' => $eoff, 'count' => 1, 'bytes' => strlen($vals), 'line' => $line);
            } else {
                $S->batch['vals'][] = $vals; $S->batch['end'] = $eoff;
                $S->batch['count']++; $S->batch['bytes'] += strlen($vals);
            }
            return true;
        }
        if (!$flush()) return false;          // preserve order before a non-insert
        return $execOne($sql, $soff, $eoff, $line);
    };

    $persist = function ($status) use (&$st, $job, $S, &$delim) {
        $st['offset']     = $S->offset;
        $st['executed']   = $S->executed;
        $st['delimiter']  = $delim;
        $st['skipped']    = $S->skipped;
        $st['redirected'] = $S->redirected;
        $st['status']     = $status;
        $st['heartbeat']  = time();
        state_save($job, $st);
    };

    $emit = function () use ($sse, $S, $total, $t0, $startOff, &$lineCount) {
        $elapsed = microtime(true) - $t0;
        $done    = $S->offset - $startOff;
        $speed   = $elapsed > 0 ? round($done / $elapsed) : 0;
        $remaining = $total > 0 ? max(0, $total - $S->offset) : 0;
        $sse('progress', array(
            'offset'     => $S->offset,
            'total'      => $total,
            'percent'    => $total > 0 ? round($S->offset / $total * 100, 2) : null,
            'executed'   => $S->executed,
            'skipped'    => $S->skipped,
            'redirected' => $S->redirected,
            'filtered'   => $S->filtered + $S->filteredExisting,
            'retries'    => $S->retries,
            'line'       => $lineCount,
            'mem'        => memory_get_usage(true),
            'elapsed'    => round($elapsed, 1),
            'speed'      => $speed,
            'eta'        => ($total > 0 && $speed > 0) ? round($remaining / $speed) : null,
        ));
    };

    $fail = function () use ($persist, &$st, $job, $S, $sse, $closer, $conn) {
        $persist('error'); $st['error'] = $S->error; state_save($job, $st);
        $sse('sqlerror', array('error' => $S->error, 'offset' => $S->offset, 'line' => $S->failLine,
            'sql' => $S->failSql, 'warnings' => $S->warnings));
        $closer(); @mysqli_close($conn); exit;
    };

    // ---- pre-import SQL (fresh, non-dry run only; on resume it has already run) ----
    if (!$dryRun && $startOff == 0 && trim((string) (isset($st['preSql']) ? $st['preSql'] : '')) !== '') {
        $pd = ';';
        list($pitems) = sql_extract($st['preSql'] . "\n", $pd);
        foreach ($pitems as $pit) {
            if (!ldi_try_query($conn, $pit['sql'], $S->retries)) {
                $S->error = 'Pre-SQL failed: ' . mysqli_error($conn);
                $S->failSql = substr($pit['sql'], 0, 400);
                $fail();
            }
        }
    }

    $sse('start', array('offset' => $offset, 'total' => $total, 'executed' => $executed, 'dryRun' => $dryRun ? 1 : 0));

    while (true) {
        if (connection_aborted()) { $persist('paused'); break; }

        $block = $reader(BLOCK_SIZE);
        $eof   = ($block === '' || $block === false);
        if (!$eof) $buffer .= $block;

        list($items, $remainder, $consumed) = sql_extract($buffer, $delim);

        $cursor = 0; $nlSeen = 0; $scanPos = 0; // incremental newline count (avoid O(items*buffer))
        foreach ($items as $it) {
            $nlSeen += substr_count(substr($buffer, $scanPos, $it['start'] - $scanPos), "\n");
            $scanPos = $it['start'];
            $line = $lineCount + $nlSeen + 1;
            if (!$process($it['sql'], $base + $cursor, $base + $it['end'], $line)) break; // stop-on-error
            $cursor = $it['end'];

            $now = microtime(true);
            if ($now - $lastEmit > 0.25) { $emit(); $lastEmit = $now; }
            if ($now - $lastSave > 1.0)  { $persist('running'); $lastSave = $now; }
        }

        if ($S->stop) $fail();

        $lineCount += substr_count(substr($buffer, 0, $consumed), "\n");
        $base  += $consumed;
        $buffer = $remainder;

        if ($eof) {
            $tail = trim($buffer);
            if ($tail !== '') {
                if (!$process($tail, $base, $base + strlen($buffer), $lineCount + 1)) $fail();
            }
            if (!$flush()) $fail();

            // ---- post-import SQL (skipped in dry run) ----
            $postError = null;
            if (!$dryRun && trim((string) (isset($st['postSql']) ? $st['postSql'] : '')) !== '') {
                $qd = ';';
                list($qitems) = sql_extract($st['postSql'] . "\n", $qd);
                foreach ($qitems as $qit) {
                    if (!ldi_try_query($conn, $qit['sql'], $S->retries)) { $postError = mysqli_error($conn); break; }
                }
            }

            $S->offset = $total > 0 ? $total : $base + strlen($buffer);
            $st['total'] = $S->offset; // percent lands on 100 for gz/bz2/zip unknowns
            $persist($dryRun ? 'ready' : 'done');
            $emit();
            $sse('done', array('executed' => $S->executed, 'skipped' => $S->skipped,
                'redirected' => $S->redirected, 'filtered' => $S->filtered, 'filteredExisting' => $S->filteredExisting,
                'retries' => $S->retries, 'dryRun' => $dryRun ? 1 : 0, 'tables' => array_keys($S->tables),
                'elapsed' => round(microtime(true) - $t0, 1), 'warnings' => $S->warnings, 'postError' => $postError));
            break;
        }

        if (strlen($buffer) > MAX_STMT) {
            $S->error = 'Single statement exceeds ' . fmt_bytes(MAX_STMT);
            $S->failSql = '';
            $fail();
        }
    }

    $closer();
    @mysqli_close($conn);
    exit;
}

/* ---- preview dump head (no execution) ---- */
if ($action === 'preview') {
    $name = safe_name(param('name'));
    $path = UPLOAD_DIR . '/' . $name;
    if (!is_file($path)) jout(array('ok' => false, 'error' => 'File not found'));

    $kind = file_kind($path);
    if ($kind === 'bz2' && !function_exists('bzopen')) jout(array('ok' => false, 'error' => 'bzip2 extension missing'));
    if ($kind === 'zip' && !class_exists('ZipArchive')) jout(array('ok' => false, 'error' => 'ZipArchive extension missing'));

    if ($kind === 'gz')       $fh = @gzopen($path, 'rb');
    elseif ($kind === 'bz2')  $fh = @bzopen($path, 'r');
    elseif ($kind === 'zip')  { $ze = ldi_zip_entry($path); $fh = $ze ? @fopen('zip://' . $path . '#' . $ze['name'], 'rb') : false; }
    else                      $fh = @fopen($path, 'rb');
    if (!$fh) jout(array('ok' => false, 'error' => 'Cannot open file'));

    // Read up to ~256KB of the head.
    $buf = ''; $cap = 256 * 1024;
    while (strlen($buf) < $cap) {
        $c = ($kind === 'gz') ? gzread($fh, BLOCK_SIZE) : (($kind === 'bz2') ? bzread($fh, BLOCK_SIZE) : fread($fh, BLOCK_SIZE));
        if ($c === '' || $c === false) break;
        $buf .= $c;
    }
    if ($kind === 'gz') @gzclose($fh); elseif ($kind === 'bz2') @bzclose($fh); else @fclose($fh);

    $d = ';';
    list($items, $rem) = sql_extract($buf, $d);
    $more = ($rem !== '' || strlen($buf) >= $cap);
    $stmts = array(); $tables = array(); $hasDbScope = false; $usesDelimiter = false;
    foreach ($items as $i => $it) {
        if (stmt_is_db_scope($it['sql'])) $hasDbScope = true;
        $t = ldi_stmt_table($it['sql']);
        if ($t !== null && count($tables) < 200) $tables[$t] = true;
        if ($i < 40) $stmts[] = substr(preg_replace('/\s+/', ' ', $it['sql']), 0, 200);
    }
    if ($d !== ';') $usesDelimiter = true;

    jout(array('ok' => true, 'kind' => $kind, 'statements' => $stmts, 'tables' => array_keys($tables),
        'count' => count($items), 'more' => $more, 'hasDbScope' => $hasDbScope, 'usesDelimiter' => $usesDelimiter,
        'sizeh' => fmt_bytes(filesize($path))));
}

/* ---- get single state ---- */
if ($action === 'state') {
    $job = preg_replace('/[^a-f0-9]/', '', param('job'));
    jout(array('ok' => true, 'state' => state_load($job)));
}

/* ============================================================================
 * HTML UI
 * ========================================================================= */
$defaults = db_defaults();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Large SQL Importer</title>
<link rel="stylesheet" href="https://code.jquery.com/ui/1.14.1/themes/base/jquery-ui.min.css">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lato:wght@400;700;900&display=swap">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://code.jquery.com/ui/1.14.1/jquery-ui.min.js"></script>
<style>
  :root{
    --cp-blue:#1ba0e2; --cp-blue-d:#178fcc; --cp-ink:#4a5564; --cp-head:#2a3f4d;
    --cp-border:#d9dde2; --cp-border-in:#c3cbd4; --cp-bg:#f0f2f4; --cp-soft:#fafbfc;
    --cp-strip:#eef5fb;
  }
  *{box-sizing:border-box}
  html,body{margin:0;padding:0}
  body{background:var(--cp-bg);color:var(--cp-ink);font-family:'Lato',Helvetica,Arial,sans-serif;font-size:14px;line-height:1.45}
  a{color:var(--cp-blue);text-decoration:none}
  a:hover{text-decoration:underline}
  .mono{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace}

  /* brand bar */
  .brandbar{background:#fff;border-bottom:1px solid var(--cp-border);box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .brandbar .inner{max-width:1000px;margin:0 auto;padding:10px 16px;display:flex;align-items:center;gap:12px}
  .brandbar .logo{width:34px;height:34px;border-radius:5px;background:linear-gradient(135deg,#1ba0e2,#1577b8);display:flex;align-items:center;justify-content:center;color:#fff;flex:0 0 auto}
  .brandbar h1{font-size:17px;font-weight:900;color:var(--cp-head);margin:0;letter-spacing:-.2px}
  .brandbar .sub{font-size:12px;color:#8a96a3;margin-left:auto}
  .brandbar .ghlink{color:#5b6671;display:flex;align-items:center}
  .brandbar .ghlink:hover{color:#1ba0e2}

  /* breadcrumb */
  .crumbs{max-width:1000px;margin:0 auto;padding:10px 16px 0;font-size:13px;color:#7c8794}
  .crumbs span{color:#a4adb7}

  .wrap{max-width:1000px;margin:0 auto;padding:12px 16px 48px}

  /* panel */
  .panel{background:#fff;border:1px solid var(--cp-border);border-radius:3px;margin-bottom:18px;box-shadow:0 1px 2px rgba(0,0,0,.04)}
  .panel>.phead{display:flex;align-items:center;gap:9px;background:var(--cp-strip);border-bottom:1px solid var(--cp-border);padding:10px 14px;border-radius:3px 3px 0 0}
  .panel>.phead .ico{color:var(--cp-blue);display:flex}
  .panel>.phead h2{margin:0;font-size:14px;font-weight:700;color:#157fc0}
  .panel>.phead .right{margin-left:auto}
  .pbody{padding:16px 14px}

  /* fields */
  .grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
  .field label{display:block;font-size:12px;font-weight:700;color:#6b7682;margin-bottom:4px}
  input.cp{width:100%;padding:7px 9px;border:1px solid var(--cp-border-in);border-radius:3px;font-size:14px;color:var(--cp-ink);background:#fff;font-family:inherit}
  input.cp:focus{outline:none;border-color:var(--cp-blue);box-shadow:0 0 0 2px rgba(27,160,226,.18)}
  textarea.cp{width:100%;padding:7px 9px;border:1px solid var(--cp-border-in);border-radius:3px;font-size:13px;color:var(--cp-ink);background:#fff;font-family:ui-monospace,Menlo,Consolas,monospace;resize:vertical}
  textarea.cp:focus{outline:none;border-color:var(--cp-blue);box-shadow:0 0 0 2px rgba(27,160,226,.18)}
  .hint{font-size:12px;color:#8a96a3;margin:10px 0 0}
  .hint b{color:#3a9c4a}

  /* buttons */
  .btn{display:inline-block;padding:7px 16px;border-radius:3px;border:1px solid transparent;font-size:14px;font-family:inherit;font-weight:700;cursor:pointer;line-height:1.3}
  .btn:focus{outline:none}
  .btn-primary{background:var(--cp-blue);border-color:var(--cp-blue-d);color:#fff}
  .btn-primary:hover{background:var(--cp-blue-d)}
  .btn-default{background:#fff;border-color:var(--cp-border-in);color:var(--cp-ink)}
  .btn-default:hover{background:#f3f6f8}
  .btn-success{background:#54a854;border-color:#4a974a;color:#fff}
  .btn-success:hover{background:#4a974a}
  .btn-warn{background:#f0913d;border-color:#e07e29;color:#fff}
  .btn-warn:hover{background:#e07e29}
  .btn-danger{background:#d9534f;border-color:#c9433f;color:#fff}
  .btn-danger:hover{background:#c9433f}
  .btn[disabled]{opacity:.5;cursor:not-allowed}
  .btnrow{display:flex;flex-wrap:wrap;gap:9px;align-items:center}

  /* callout */
  .callout{padding:11px 14px;border:1px solid;border-left-width:4px;border-radius:3px;margin-bottom:18px}
  .callout-danger{background:#fdeced;border-color:#f0c5c9;border-left-color:#d9534f;color:#9d3b3f}
  .callout-danger b{color:#c0392b}
  .callout .ttl{font-weight:700}

  /* dropzone */
  .dropzone{border:2px dashed var(--cp-border-in);background:var(--cp-soft);border-radius:3px;padding:24px;text-align:center;color:#7c8794;cursor:pointer}
  .dropzone.drag{border-color:var(--cp-blue);background:#eef8fe}
  .dropzone .big{color:#5b6671;font-size:14px}
  .dropzone .sm{font-size:12px;color:#9aa4ae;margin-top:4px}

  /* file list */
  #fileList{margin-top:4px;max-height:300px;overflow:auto}
  .filerow{display:flex;align-items:center;gap:10px;border:1px solid #e4e8ec;border-radius:3px;padding:8px 10px;margin-bottom:6px;background:#fff;cursor:pointer}
  .filerow:hover{background:#f7f9fb}
  .filerow.sel{border-color:var(--cp-blue);background:#eef8fe}
  .filerow .nm{font-size:14px;color:#33404a;font-weight:700;word-break:break-all}
  .filerow .meta{font-size:12px;color:#8a96a3;margin-top:2px}
  .filerow .meta .err{color:#c0392b}
  .filerow .del{margin-left:auto;color:#b7c0c9;background:none;border:none;cursor:pointer;font-size:15px;padding:2px 6px;flex:0 0 auto}
  .filerow .del:hover{color:#d9534f}
  .empty{text-align:center;color:#9aa4ae;font-size:13px;padding:18px}

  /* spinner (cPanel style) */
  .cp-spin{display:inline-block;width:16px;height:16px;border:2px solid #d4dbe1;border-top-color:var(--cp-blue);border-radius:50%;animation:cpspin .7s linear infinite;vertical-align:middle}
  .cp-spin.lg{width:26px;height:26px;border-width:3px}
  @keyframes cpspin{to{transform:rotate(360deg)}}
  .loading{display:flex;align-items:center;justify-content:center;gap:10px;color:#8a96a3;font-size:13px;padding:24px}
  #refreshBtn.spinning{pointer-events:none;opacity:.6}

  /* badge */
  .badge{display:inline-block;font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.3px;padding:2px 7px;border-radius:10px;margin-left:6px;vertical-align:middle}
  .b-done{background:#e3f4e4;color:#2e7d32}
  .b-running{background:#e1f1fb;color:#1577b8}
  .b-error{background:#fbe4e4;color:#c0392b}
  .b-paused{background:#fdf0e0;color:#c97a1f}
  .b-ready{background:#eceff2;color:#6b7682}
  .b-gz{background:#ede7fb;color:#6a4bc0}
  .tag-resumable{font-size:11px;color:#c97a1f;font-weight:700;margin-left:auto;padding-right:6px}

  /* checkbox rows */
  .opt{display:flex;align-items:flex-start;gap:8px;margin-bottom:10px;font-size:14px;color:#3d4753}
  .opt input{margin-top:3px}
  .opt .desc{display:block;font-size:12px;color:#8a96a3;margin-top:2px}
  .optrow{display:flex;flex-wrap:wrap;gap:22px}
  .optrow .opt{margin-bottom:0}

  /* progress */
  .ui-progressbar{height:22px;background:#e4e8ec;border:1px solid var(--cp-border-in);border-radius:3px;overflow:hidden;position:relative}
  .ui-progressbar .ui-progressbar-value{background:linear-gradient(180deg,#39b0ee,#1ba0e2);border:0;margin:0;height:100%}
  .ui-progressbar .ui-progressbar-overlay{display:none}
  .ui-progressbar.ui-progressbar-indeterminate .ui-progressbar-value{width:100%!important;background:repeating-linear-gradient(45deg,#39b0ee,#39b0ee 12px,#1ba0e2 12px,#1ba0e2 24px);animation:cpstripe 1s linear infinite}
  @keyframes cpstripe{0%{background-position:0 0}100%{background-position:48px 0}}
  .upinfo{display:flex;justify-content:space-between;font-size:12px;color:#7c8794;margin:10px 0 5px}

  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:10px;margin-top:14px}
  .stat{border:1px solid #e4e8ec;border-radius:3px;background:var(--cp-soft);padding:10px;text-align:center}
  .stat b{display:block;font-size:18px;font-weight:900;color:#2a3f4d}
  .stat span{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.4px;color:#9aa4ae;margin-top:3px}

  .logbox{margin-top:14px;background:#1e2227;color:#cfd6dd;font-family:ui-monospace,Menlo,Consolas,monospace;font-size:12px;line-height:1.5;padding:11px 13px;height:170px;overflow:auto;border-radius:3px;white-space:pre-wrap;border:1px solid #11151a}

  /* jQuery UI dialog -> cPanel skin */
  .ui-dialog{border:1px solid var(--cp-border-in);border-radius:3px;box-shadow:0 8px 28px rgba(0,0,0,.18);font-family:'Lato',Helvetica,Arial,sans-serif}
  .ui-dialog .ui-dialog-titlebar{background:var(--cp-strip);border:0;border-bottom:1px solid var(--cp-border);border-radius:3px 3px 0 0;color:#157fc0;font-weight:700}
  .ui-dialog .ui-dialog-content{color:var(--cp-ink);font-size:14px}
  .ui-dialog .ui-dialog-buttonpane{border-top:1px solid var(--cp-border)}
  .ui-widget-overlay{background:#1b2733;opacity:.35}
  .ui-tooltip{background:#2a3f4d;color:#fff;border:0;border-radius:3px;font-size:12px;box-shadow:0 4px 12px rgba(0,0,0,.2)}
  .ui-dialog{max-width:94vw!important}

  /* footer */
  .foot{max-width:1000px;margin:8px auto 28px;padding:14px 16px 0;border-top:1px solid var(--cp-border);text-align:center;font-size:13px;color:#8a96a3}
  .foot a{font-weight:700}

  /* responsive */
  @media (max-width:600px){
    .brandbar .inner,.crumbs,.wrap,.foot{padding-left:12px;padding-right:12px}
    .brandbar h1{font-size:16px}
    .brandbar .sub{display:none}
    .panel>.phead{flex-wrap:wrap;row-gap:8px}
    .panel>.phead .right{margin-left:0;width:100%}
    .panel>.phead .right .btn{width:100%}
    .grid{grid-template-columns:1fr}
    .btnrow .btn{flex:1 1 auto}
    .optrow{gap:10px 18px}
  }
</style>
</head>
<body>

<div class="brandbar">
  <div class="inner">
    <div class="logo">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg>
    </div>
    <h1>Large SQL Importer</h1>
    <span class="sub">Resumable &middot; .sql / .sql.gz / .bz2 / .zip</span>
    <a class="ghlink" href="https://github.com/olakunlevpn/large-sql-importer" target="_blank" rel="noopener" title="View on GitHub">
      <svg width="22" height="22" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M8 0C3.58 0 0 3.58 0 8c0 3.54 2.29 6.53 5.47 7.59.4.07.55-.17.55-.38 0-.19-.01-.82-.01-1.49-2.01.37-2.53-.49-2.69-.94-.09-.23-.48-.94-.82-1.13-.28-.15-.68-.52-.01-.53.63-.01 1.08.58 1.23.82.72 1.21 1.87.87 2.33.66.07-.52.28-.87.51-1.07-1.78-.2-3.64-.89-3.64-3.95 0-.87.31-1.59.82-2.15-.08-.2-.36-1.02.08-2.12 0 0 .67-.21 2.2.82.64-.18 1.32-.27 2-.27.68 0 1.36.09 2 .27 1.53-1.04 2.2-.82 2.2-.82.44 1.1.16 1.92.08 2.12.51.56.82 1.27.82 2.15 0 3.07-1.87 3.75-3.65 3.95.29.25.54.73.54 1.48 0 1.07-.01 1.93-.01 2.2 0 .21.15.46.55.38A8.01 8.01 0 0 0 16 8c0-4.42-3.58-8-8-8z"/></svg>
    </a>
  </div>
</div>

<div class="crumbs">Home <span>/</span> Databases <span>/</span> Import Database</div>

<div class="wrap">

  <!-- SECURITY WARNING -->
  <div class="callout callout-danger">
    <div class="ttl">Anyone with this URL can read and overwrite your entire database.</div>
    <div>Use it, then <b>DELETE this file immediately</b>. Never leave it on a live or public server.</div>
  </div>

  <!-- 1 · CONNECTION -->
  <div class="panel">
    <div class="phead">
      <span class="ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><ellipse cx="12" cy="5" rx="8" ry="3"/><path d="M4 5v14c0 1.7 3.6 3 8 3s8-1.3 8-3V5"/><path d="M4 12c0 1.7 3.6 3 8 3s8-1.3 8-3"/></svg></span>
      <h2>1 &middot; Database Connection</h2>
      <span class="right"><button id="testBtn" class="btn btn-default">Test Connection</button></span>
    </div>
    <div class="pbody">
      <div class="grid">
        <div class="field"><label>Host</label><input id="dbHost" class="cp" value="<?php echo htmlspecialchars($defaults['host']); ?>"></div>
        <div class="field"><label>Username</label><input id="dbUser" class="cp" value="<?php echo htmlspecialchars($defaults['user']); ?>"></div>
        <div class="field"><label>Password</label><input id="dbPass" type="password" class="cp" value="<?php echo htmlspecialchars($defaults['pass']); ?>"></div>
        <div class="field"><label>Database</label><input id="dbName" class="cp" value="<?php echo htmlspecialchars($defaults['name']); ?>"></div>
      </div>
      <?php if (!empty($defaults['source'])): ?>
        <p class="hint">Prefilled from <b><?php echo htmlspecialchars($defaults['source']); ?></b> &mdash; edit if needed.</p>
      <?php else: ?>
        <p class="hint">Enter your database details, then click <b>Test Connection</b>.</p>
      <?php endif; ?>
      <p id="testMsg" class="hint" style="margin-top:8px"></p>
    </div>
  </div>

  <!-- 2 · FILE -->
  <div class="panel">
    <div class="phead">
      <span class="ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3v5h5"/><path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg></span>
      <h2>2 &middot; Choose a File</h2>
      <span class="right"><a href="#" id="refreshBtn">&#8635; Refresh</a></span>
    </div>
    <div class="pbody">
      <div id="drop" class="dropzone">
        <input id="fileInput" type="file" accept=".sql,.gz,.sql.gz,.bz2,.sql.bz2,.zip" style="display:none">
        <div class="big">Drop <b>.sql</b>, <b>.sql.gz</b>, <b>.sql.bz2</b> or <b>.zip</b> here, or click to browse</div>
        <div class="sm">Uploaded in chunks &mdash; not limited by PHP upload_max_filesize</div>
      </div>
      <div id="upWrap" style="display:none">
        <div class="upinfo"><span id="upName"></span><span id="upPct">0%</span></div>
        <div id="upBar"></div>
      </div>

      <div style="margin-top:16px;font-weight:700;color:#6b7682;font-size:12px;text-transform:uppercase;letter-spacing:.3px">Files on server</div>
      <div id="fileList"></div>
    </div>
  </div>

  <!-- 3 · IMPORT -->
  <div class="panel">
    <div class="phead">
      <span class="ico"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12"/><path d="M7 10l5 5 5-5"/><path d="M5 21h14"/></svg></span>
      <h2>3 &middot; Import</h2>
    </div>
    <div class="pbody">
      <label class="opt">
        <input id="optForceDb" type="checkbox" checked>
        <span>Import into the connected database
          <span class="desc">Ignores <span class="mono">CREATE DATABASE</span> / <span class="mono">USE</span> in the file &mdash; data lands in the database entered above, not the one hard-coded in the dump.</span>
        </span>
      </label>
      <div class="optrow">
        <label class="opt"><input id="optSkip" type="checkbox"> <span>Skip statements that error</span></label>
        <label class="opt"><input id="optFk" type="checkbox"> <span>Keep FK / unique checks on</span></label>
        <label class="opt"><input id="optDryRun" type="checkbox"> <span>Dry run (validate, no changes)</span></label>
        <label class="opt"><input id="optSkipExisting" type="checkbox"> <span>Skip tables that already exist</span></label>
      </div>
      <div class="field" style="margin-top:10px">
        <label>Import only these tables <span style="font-weight:400;color:#9aa4ae">(comma-separated; blank = all)</span></label>
        <input id="optTables" class="cp" placeholder="e.g. users, orders, wp_posts">
      </div>

      <div style="margin-top:6px"><a href="#" id="advToggle" style="font-size:13px">&#9656; Advanced: pre / post SQL</a></div>
      <div id="advBox" style="display:none;margin-top:10px">
        <div class="grid">
          <div class="field"><label>Run BEFORE import (fresh run only)</label><textarea id="preSql" class="cp" rows="3" placeholder="e.g. SET foreign_key_checks=0;"></textarea></div>
          <div class="field"><label>Run AFTER import (on success)</label><textarea id="postSql" class="cp" rows="3" placeholder="e.g. ANALYZE TABLE users;"></textarea></div>
        </div>
      </div>

      <div class="btnrow" style="margin-top:14px">
        <button id="previewBtn" class="btn btn-default" disabled>Preview</button>
        <button id="startBtn" class="btn btn-success" disabled>Start Import</button>
        <button id="resumeBtn" class="btn btn-warn" disabled style="display:none">Resume</button>
        <button id="stopBtn" class="btn btn-danger" disabled>Stop</button>
        <span id="selName" style="font-size:12px;color:#8a96a3"></span>
      </div>

      <div id="progWrap" style="display:none;margin-top:18px">
        <div id="progBar"></div>
        <div class="stats">
          <div class="stat"><b id="mPct">0%</b><span>Progress</span></div>
          <div class="stat"><b id="mBytes" class="mono">0</b><span>Read</span></div>
          <div class="stat"><b id="mExec" class="mono">0</b><span>Statements</span></div>
          <div class="stat"><b id="mSpeed" class="mono">0</b><span>Speed/s</span></div>
          <div class="stat"><b id="mTime" class="mono">0s</b><span>Elapsed</span></div>
          <div class="stat"><b id="mEta" class="mono">—</b><span>ETA</span></div>
          <div class="stat"><b id="mMem" class="mono">0</b><span>Memory</span></div>
        </div>
        <div id="logBox" class="logbox"></div>
      </div>
    </div>
  </div>

</div>

<footer class="foot">
  Created with love by <a href="https://github.com/olakunlevpn" target="_blank" rel="noopener">Olakunlevpn</a>
  &middot; <a href="https://github.com/olakunlevpn/large-sql-importer" target="_blank" rel="noopener">large-sql-importer</a>
</footer>

<script>
$(function(){
  /* ---------- api ---------- */
  function api(action, data, isForm){
    if(isForm){
      return $.ajax({url:'?action='+action,method:'POST',data:data,processData:false,contentType:false,dataType:'json'});
    }
    return $.ajax({url:location.pathname,method:'POST',data:$.extend({action:action},data||{}),dataType:'json'});
  }
  function esc(s){ return String(s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c])); }
  function fmtBytes(b){ b=+b||0; const u=['B','KB','MB','GB','TB']; let i=0; while(b>=1024&&i<4){b/=1024;i++;} return (b>=100||i==0?Math.round(b):b.toFixed(1))+' '+u[i]; }

  function notify(msg, title){
    $('<div>').html(esc(msg)).dialog({
      title:title||'Notice', modal:true, resizable:false, width:400,
      buttons:{ 'OK':function(){ $(this).dialog('close'); } },
      close:function(){ $(this).dialog('destroy').remove(); }
    });
  }

  $(document).tooltip();

  /* ---------- persist DB details (localStorage) ---------- */
  const DB_FIELDS={dbHost:'host',dbUser:'user',dbPass:'pass',dbName:'name'}, LS='ldi_db';
  function saveDb(){ const d={}; for(const id in DB_FIELDS) d[DB_FIELDS[id]]=$('#'+id).val(); try{localStorage.setItem(LS,JSON.stringify(d));}catch(e){} }
  function restoreDb(){ let d; try{d=JSON.parse(localStorage.getItem(LS)||'null');}catch(e){d=null;} if(!d)return; for(const id in DB_FIELDS){ const v=d[DB_FIELDS[id]]; if(v!==undefined&&v!==null)$('#'+id).val(v); } }
  for(const id in DB_FIELDS) $('#'+id).on('input',saveDb);
  restoreDb();

  /* ---------- test connection ---------- */
  $('#testBtn').on('click',function(){
    const m=$('#testMsg').css('color','#8a96a3').text('Testing…');
    api('test',{host:$('#dbHost').val(),user:$('#dbUser').val(),pass:$('#dbPass').val(),name:$('#dbName').val()})
      .then(function(r){
        if(r.ok) m.css('color','#2e7d32').html('✓ Connected — MySQL '+esc(r.version));
        else m.css('color','#c0392b').html('✗ '+esc(r.error));
      });
  });

  /* ---------- file list ---------- */
  let selected=null;
  function badge(s){ const c={done:'b-done',running:'b-running',error:'b-error',paused:'b-paused',ready:'b-ready'}; return '<span class="badge '+(c[s]||'b-ready')+'">'+s+'</span>'; }
  function loadFiles(){
    $('#fileList').html('<div class="loading"><span class="cp-spin lg"></span> Loading files…</div>');
    $('#refreshBtn').addClass('spinning');
    return api('list').then(function(r){
      $('#refreshBtn').removeClass('spinning');
      const box=$('#fileList').empty();
      if(!r.files||!r.files.length){ box.html('<div class="empty">No files yet — upload one above.</div>'); return; }
      r.files.forEach(function(f){
        const resumable=f.status&&(f.status==='error'||f.status==='paused'||f.status==='running')&&f.percent!=null&&f.percent<100;
        const row=$('<div class="filerow">');
        row.html(
          '<input type="radio" name="file" value="'+esc(f.name)+'">'+
          '<div style="flex:1;min-width:0">'+
            '<div class="nm">'+esc(f.name)+(f.gz?' <span class="badge b-gz">gz</span>':'')+(f.bz2?' <span class="badge b-gz">bz2</span>':'')+(f.zip?' <span class="badge b-gz">zip</span>':'')+(f.status?badge(f.status):'')+'</div>'+
            '<div class="meta">'+esc(f.sizeh)+(f.percent!=null?' &middot; '+f.percent+'% done &middot; '+(f.executed||0)+' stmts':'')+(f.error?' &middot; <span class="err">'+esc(f.error)+'</span>':'')+'</div>'+
          '</div>'+
          (resumable?'<span class="tag-resumable">resumable</span>':'')+
          '<button class="del" title="Delete file and its import state">✕</button>'
        );
        if(selected&&selected.name===f.name){ row.addClass('sel'); row.find('input').prop('checked',true); }
        row.on('click',function(e){ if($(e.target).hasClass('del'))return; $('.filerow').removeClass('sel'); row.addClass('sel'); row.find('input').prop('checked',true); selectFile(f); });
        row.find('.del').on('click',function(e){ e.stopPropagation(); confirmDelete(f.name); });
        box.append(row);
      });
    }, function(){
      $('#refreshBtn').removeClass('spinning');
      $('#fileList').html('<div class="empty">Failed to load files — check the server.</div>');
    });
  }
  function confirmDelete(name){
    $('<div>').text('Delete "'+name+'" and its import state? This cannot be undone.').dialog({
      title:'Confirm Delete', modal:true, resizable:false, width:400,
      buttons:[
        {text:'Delete', class:'', click:function(){ const dlg=$(this); api('delete',{name:name}).then(function(){ if(selected&&selected.name===name){selected=null;$('#selName').text('');$('#startBtn,#previewBtn').prop('disabled',true);} loadFiles(); }); dlg.dialog('close'); }},
        {text:'Cancel', click:function(){ $(this).dialog('close'); }}
      ],
      open:function(){ $(this).closest('.ui-dialog').find('.ui-dialog-buttonpane button:eq(0)').addClass('btn btn-danger'); $(this).closest('.ui-dialog').find('.ui-dialog-buttonpane button:eq(1)').addClass('btn btn-default'); },
      close:function(){ $(this).dialog('destroy').remove(); }
    });
  }
  function selectFile(f){
    selected=f;
    $('#selName').text('Selected: '+f.name);
    $('#startBtn').prop('disabled',false);
    $('#previewBtn').prop('disabled',false);
    const resumable=f.status&&(f.status==='error'||f.status==='paused')&&f.percent!=null&&f.percent<100;
    $('#resumeBtn').toggle(!!resumable).prop('disabled',!resumable);
  }

  /* ---------- preview ---------- */
  $('#previewBtn').on('click',function(){
    if(!selected){ notify('Select a file first.','No File'); return; }
    api('preview',{name:selected.name}).then(function(r){
      if(!r.ok){ notify(esc(r.error),'Preview failed'); return; }
      let h='<div style="font-size:13px;color:#6b7682;margin-bottom:8px">'+esc(selected.name)+' &middot; '+esc(r.sizeh)+' &middot; '+r.count+(r.more?'+':'')+' statements in head'+(r.hasDbScope?' &middot; <b style="color:#c97a1f">contains CREATE/USE DATABASE</b>':'')+(r.usesDelimiter?' &middot; uses DELIMITER':'')+'</div>';
      if(r.tables.length) h+='<div style="margin-bottom:8px"><b>Tables:</b> '+r.tables.map(esc).join(', ')+'</div>';
      h+='<pre style="background:#1e2227;color:#cfd6dd;font-size:12px;padding:10px;border-radius:3px;max-height:300px;overflow:auto;white-space:pre-wrap">'+r.statements.map(esc).join('\n').slice(0,8000)+(r.more?'\n…':'')+'</pre>';
      $('<div>').html(h).dialog({ title:'Dump preview', modal:true, width:640, close:function(){ $(this).dialog('destroy').remove(); } });
    },function(){ notify('Preview request failed.','Error'); });
  });
  $('#refreshBtn').on('click',function(e){ e.preventDefault(); if($(this).hasClass('spinning'))return; loadFiles(); });

  /* ---------- chunked upload ---------- */
  $('#upBar').progressbar({value:0});
  const drop=$('#drop'), fileInput=$('#fileInput');
  drop.on('click',()=>fileInput.trigger('click'));
  drop.on('dragover',e=>{e.preventDefault();drop.addClass('drag');});
  drop.on('dragleave',()=>drop.removeClass('drag'));
  drop.on('drop',e=>{ e.preventDefault();drop.removeClass('drag'); const f=e.originalEvent.dataTransfer.files[0]; if(f)uploadFile(f); });
  fileInput.on('change',function(){ if(this.files[0])uploadFile(this.files[0]); });

  function uploadFile(file){
    if(!/(\.sql(\.gz|\.bz2)?|\.zip)$/i.test(file.name)){ notify('Only .sql, .sql.gz, .sql.bz2 or .zip files are allowed.','Invalid File'); return; }
    const CH=4*1024*1024, total=Math.ceil(file.size/CH);
    $('#upWrap').show(); $('#upName').text(file.name); $('#upBar').progressbar('value',0); $('#upPct').text('0%');
    let i=0;
    function next(){
      if(i>=total){ setTimeout(()=>{ $('#upWrap').hide(); $('#upBar').progressbar('value',0); },800); fileInput.val(''); loadFiles(); return; }
      const fd=new FormData();
      fd.append('name',file.name); fd.append('index',i); fd.append('total',total);
      fd.append('chunk',file.slice(i*CH,Math.min(file.size,(i+1)*CH)));
      api('upload',fd,true).then(function(r){
        if(!r||!r.ok){ notify('Upload failed: '+esc(r&&r.error?r.error:'unknown'),'Upload Error'); $('#upWrap').hide(); return; }
        i++; const pct=Math.round(i/total*100); $('#upBar').progressbar('value',pct); $('#upPct').text(pct+'%'); next();
      },function(){ notify('Upload failed (network).','Upload Error'); $('#upWrap').hide(); });
    }
    next();
  }

  /* ---------- import (SSE) ---------- */
  $('#progBar').progressbar({value:0});
  let es=null;
  function log(msg){ const b=$('#logBox'); b.append(document.createTextNode(msg+'\n')); b.scrollTop(b[0].scrollHeight); }
  function setIndeterminate(on){ $('#progBar').progressbar('option','value',on?false:0); }

  $('#startBtn').on('click',()=>begin('fresh'));
  $('#resumeBtn').on('click',()=>begin('resume'));
  $('#stopBtn').on('click',function(){ if(es){ es.close(); es=null; log('■ Stopped by user — state saved, resumable.'); finishUi(); } });

  function begin(mode){
    if(!selected){ notify('Select a file first.','No File'); return; }
    if(mode==='fresh' && selected.status==='done'){
      $('<div>').text('This file already imported. Re-import from the beginning?').dialog({
        title:'Re-import', modal:true, resizable:false, width:400,
        buttons:[ {text:'Re-import',click:function(){$(this).dialog('close');run('fresh');}}, {text:'Cancel',click:function(){$(this).dialog('close');}} ],
        open:function(){ $(this).closest('.ui-dialog').find('button:eq(0)').addClass('btn btn-success'); $(this).closest('.ui-dialog').find('button:eq(1)').addClass('btn btn-default'); },
        close:function(){ $(this).dialog('destroy').remove(); }
      });
      return;
    }
    run(mode);
  }
  function run(mode){
    $('#progWrap').show(); $('#logBox').empty();
    $('#startBtn').prop('disabled',true); $('#resumeBtn').prop('disabled',true); $('#stopBtn').prop('disabled',false);
    log((mode==='resume'?'↻ Resuming ':'▶ Starting ')+selected.name+' …');
    api('prepare',{
      name:selected.name, mode:mode,
      host:$('#dbHost').val(), user:$('#dbUser').val(), pass:$('#dbPass').val(), name_db:$('#dbName').val(),
      skipErrors:$('#optSkip').is(':checked')?1:0, fkChecks:$('#optFk').is(':checked')?1:0, forceDb:$('#optForceDb').is(':checked')?1:0,
      dryRun:$('#optDryRun').is(':checked')?1:0, skipExisting:$('#optSkipExisting').is(':checked')?1:0, tables:$('#optTables').val(),
      preSql:$('#preSql').val(), postSql:$('#postSql').val()
    }).then(function(r){
      if(!r.ok){ log('✗ '+r.error); finishUi(); return; }
      setIndeterminate(!r.totalKnown);
      if(r.totalKnown && r.offset>0){ const p=r.offset/r.total*100; $('#progBar').progressbar('value',p); $('#mPct').text(p.toFixed(1)+'%'); }
      es=new EventSource('?action=run&job='+encodeURIComponent(r.job));
      es.addEventListener('start',e=>{ const d=JSON.parse(e.data); log('· offset '+d.offset+' / '+(d.total||'?')+' · '+d.executed+' stmts done'); });
      es.addEventListener('progress',e=>updateProgress(JSON.parse(e.data)));
      es.addEventListener('done',e=>updateDone(JSON.parse(e.data)));
      es.addEventListener('sqlerror',e=>{ const d=JSON.parse(e.data); logWarnings(d.warnings); log('✗ SQL error'+(d.line?(' at line '+d.line):'')+': '+d.error); if(d.sql)log('  at: '+d.sql.slice(0,200)); log('  → fix and click Resume.'); es.close(); es=null; finishUi(); });
      es.onerror=()=>{ if(es){ es.close(); es=null; log('⚠ Connection interrupted — state saved. Click Resume.'); finishUi(); } };
    },function(){ log('✗ Request failed.'); finishUi(); });
  }
  function fmtEta(s){ if(s==null)return '—'; s=+s; if(s<60)return s+'s'; if(s<3600)return Math.floor(s/60)+'m '+(s%60)+'s'; return Math.floor(s/3600)+'h '+Math.floor((s%3600)/60)+'m'; }
  function updateProgress(d){
    if(d.percent!=null){ setIndeterminate(false); $('#progBar').progressbar('value',Math.min(100,d.percent)); $('#mPct').text(d.percent.toFixed(1)+'%'); }
    $('#mBytes').text(fmtBytes(d.offset));
    $('#mExec').text(d.executed+(d.skipped?(' / '+d.skipped+' skip'):''));
    $('#mSpeed').text(fmtBytes(d.speed));
    $('#mTime').text(d.elapsed+'s');
    $('#mEta').text(fmtEta(d.eta));
    if(d.mem!=null) $('#mMem').text(fmtBytes(d.mem));
  }
  function logWarnings(w){
    if(!w||!w.length)return;
    log('  ⚠ '+w.length+' statement(s) skipped:');
    w.forEach(x=>log('    • line '+(x.line||'?')+' (offset '+x.offset+'): '+x.error+(x.sql?(' :: '+x.sql.slice(0,80)):'')));
  }
  function updateDone(d){
    setIndeterminate(false); $('#progBar').progressbar('value',100); $('#mPct').text('100%'); $('#mEta').text('0s');
    $('#mExec').text(d.executed+(d.skipped?(' / '+d.skipped+' skip'):'')); $('#mTime').text(d.elapsed+'s');
    log((d.dryRun?'✓ Dry run OK — no changes made. ':'✓ Done — ')+d.executed+' statements in '+d.elapsed+'s'
      +(d.skipped?(' ('+d.skipped+' skipped)'):'')
      +(d.redirected?(' · '+d.redirected+' CREATE/USE DATABASE ignored'):'')
      +(d.filtered?(' · '+d.filtered+' filtered (not in table list)'):'')
      +(d.filteredExisting?(' · '+d.filteredExisting+' skipped (table exists)'):'')
      +(d.retries?(' · '+d.retries+' retries'):''));
    if(d.dryRun && d.tables && d.tables.length) log('  tables: '+d.tables.join(', '));
    logWarnings(d.warnings);
    if(d.postError) log('⚠ Post-SQL error: '+d.postError);
    if(es){es.close();es=null;} finishUi();
    if(!d.dryRun) starDialog();
  }
  function starDialog(){
    const repo='https://github.com/olakunlevpn/large-sql-importer';
    $('<div>').html(
      '<div style="text-align:center;padding:6px 4px">'+
      '<div style="font-size:34px;line-height:1">&#11088;</div>'+
      '<div style="font-size:16px;font-weight:700;color:#2a3f4d;margin-top:8px">Import complete</div>'+
      '<div style="font-size:13px;color:#6b7682;margin-top:6px">If this saved you time, please star the project on GitHub. It helps a lot.</div>'+
      '</div>'
    ).dialog({
      title:'Done', modal:true, resizable:false, width:380,
      buttons:[
        {text:'Star on GitHub', click:function(){ window.open(repo,'_blank','noopener'); $(this).dialog('close'); }},
        {text:'Maybe later', click:function(){ $(this).dialog('close'); }}
      ],
      open:function(){ $(this).closest('.ui-dialog').find('.ui-dialog-buttonpane button:eq(0)').addClass('btn btn-primary'); $(this).closest('.ui-dialog').find('.ui-dialog-buttonpane button:eq(1)').addClass('btn btn-default'); },
      close:function(){ $(this).dialog('destroy').remove(); }
    });
  }
  function finishUi(){ $('#stopBtn').prop('disabled',true); $('#startBtn').prop('disabled',false); loadFiles(); }

  /* ---------- advanced pre/post toggle ---------- */
  $('#advToggle').on('click',function(e){ e.preventDefault(); const b=$('#advBox'); b.slideToggle(120); $(this).html((b.is(':visible')?'&#9662;':'&#9656;')+' Advanced: pre / post SQL'); });

  /* ---------- init ---------- */
  loadFiles();
});
</script>
</body>
</html>
