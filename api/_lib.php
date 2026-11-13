<?php
/**
 * RuralTech - nucleo de la aplicacion (cliente Blob + capa de datos + sesiones).
 * Este archivo forma parte del paquete de funciones; su acceso directo devuelve 404.
 */
declare(strict_types=1);

if (!defined('RT_APP')) { http_response_code(404); exit('404'); }
final class BlobClient
{
    private string $token;
    private string $sid;          // identificador del almacén sin el prefijo store_
    private string $host;         // https://xxxx.private.blob.vercel-storage.com
    private const API = 'https://vercel.com/api/blob';
    private const API_VERSION = '13';

    public function __construct(?string $token = null)
    {
        $t = $token ?? (string) getenv('BLOB_READ_WRITE_TOKEN');
        if ($t === '' || !preg_match('/^vercel_blob_rw_([^_]+)_/', $t, $m)) {
            throw new RuntimeException('Credencial de almacenamiento (BLOB_READ_WRITE_TOKEN) ausente o inválida.');
        }
        $this->token = $t;
        $this->sid = $m[1];
        $this->host = 'https://' . strtolower($this->sid) . '.private.blob.vercel-storage.com';
    }

    public function storeId(): string { return $this->sid; }

    /** Headers comunes de la API de control. */
    private function controlHeaders(?string $bearer = null): array
    {
        return [
            'authorization: Bearer ' . ($bearer ?? $this->token),
            'x-vercel-blob-store-id: ' . $this->sid,
            'x-api-version: ' . self::API_VERSION,
            'x-api-blob-request-id: ' . $this->sid . ':' . time() . ':' . bin2hex(random_bytes(4)),
            'x-api-blob-request-attempt: 0',
        ];
    }

    /** Solicitud HTTP con cURL; devuelve [status, cuerpo, cabeceras]. */
    private function request(string $method, string $url, array $headers, ?string $body = null): array
    {
        $respHeaders = [];
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 55,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$respHeaders) {
                if (preg_match('/^([^:]+):\s*(.*)$/', trim($h), $mm)) {
                    $respHeaders[strtolower(trim($mm[1]))] = trim($mm[2]);
                }
                return strlen($h);
            },
        ]);
        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($method === 'HEAD') curl_setopt($ch, CURLOPT_NOBODY, true); // no esperar cuerpo
        $out = curl_exec($ch);
        if ($out === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('Fallo de red con el almacenamiento: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, (string) $out, $respHeaders];
    }

    /** PUT de contenido (ruta de servidor). Devuelve el nuevo ETag. */
    public function put(string $pathname, string $content, string $contentType, ?string $ifMatch = null, int $cacheAge = 3000): string
    {
        $headers = array_merge($this->controlHeaders(), [
            'x-vercel-blob-access: private',
            'x-content-type: ' . $contentType,
            'x-cache-control-max-age: ' . $cacheAge,
            'x-allow-overwrite: 1',
        ]);
        if ($ifMatch !== null) $headers[] = 'x-if-match: ' . $this->normEtag($ifMatch);
        [$status, $body] = $this->request('PUT', self::API . '/?pathname=' . rawurlencode($pathname), $headers, $content);
        if ($status === 412) throw new BlobConflict();
        if ($status !== 200) throw new RuntimeException('El almacenamiento rechazó la escritura (' . $status . '): ' . substr($body, 0, 200));
        $j = json_decode($body, true);
        $etag = is_array($j) ? ($j['etag'] ?? null) : null;
        return (string) $etag;
    }

    /** GET + ETag del objeto completo (siempre fresco, sin caché CDN). */
    public function readFresh(string $pathname, ?string &$etag = null): ?string
    {
        [$status, $body, $h] = $this->request('GET', $this->host . '/' . $pathname . '?cache=0', $this->controlHeaders());
        if ($status === 404) { $etag = null; return null; }
        if ($status !== 200) throw new RuntimeException('El almacenamiento rechazó la lectura (' . $status . ')');
        $etag = $this->normEtag($h['etag'] ?? '');
        return $body;
    }

    /** Lectura normalizada (con ETag de referencia). */
    public function etag(): string { return ''; }

    private function normEtag(string $e): string
    {
        $e = trim($e);
        if ($e !== '' && $e[0] !== '"') $e = '"' . $e . '"';
        return $e;
    }

    /** HEAD: tamaño y tipo del objeto. */
    public function head(string $pathname, ?int &$size = null, ?string &$contentType = null): bool
    {
        [$status, , $h] = $this->request('HEAD', $this->host . '/' . $pathname, $this->controlHeaders());
        if ($status !== 200) return false;
        $size = (int) ($h['content-length'] ?? 0);
        $contentType = $h['content-type'] ?? 'application/octet-stream';
        return true;
    }

    /** Rango inicial del objeto (para detectar el MIME real con finfo). */
    public function readPrefix(string $pathname, int $bytes = 1024): string
    {
        [$status, $body] = $this->request('GET', $this->host . '/' . $pathname . '?cache=0',
            array_merge($this->controlHeaders(), ['Range: bytes=0-' . ($bytes - 1)]));
        if ($status !== 200 && $status !== 206) throw new RuntimeException('No se pudo inspeccionar el archivo (' . $status . ')');
        return strlen($body) > $bytes ? substr($body, 0, $bytes) : $body;
    }

    /** Descarga completa (memoria). */
    public function readAll(string $pathname): string
    {
        [$status, $body] = $this->request('GET', $this->host . '/' . $pathname . '?cache=0', $this->controlHeaders());
        if ($status !== 200) throw new RuntimeException('No se pudo leer el archivo (' . $status . ')');
        return $body;
    }

    /** Transmite el objeto al cliente HTTP (descarga autorizada). */
    public function streamTo(string $pathname, int $size): void
    {
        $ch = curl_init($this->host . '/' . $pathname . '?cache=0');
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => $this->controlHeaders(),
            CURLOPT_WRITEFUNCTION => function ($ch, $chunk) {
                echo $chunk;
                if (function_exists('flush')) @flush();
                return strlen($chunk);
            },
            CURLOPT_TIMEOUT => 55,
        ]);
        curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($status !== 200) throw new RuntimeException('No se pudo transmitir el archivo (' . $status . ')');
        unset($size);
    }

    /** Lista objetos por prefijo. */
    public function list(string $prefix, int $limit = 1000): array
    {
        $out = [];
        $cursor = '';
        do {
            $url = self::API . '/?limit=' . min($limit, 1000) . '&prefix=' . rawurlencode($prefix) . ($cursor !== '' ? '&cursor=' . rawurlencode($cursor) : '');
            [$status, $body] = $this->request('GET', $url, $this->controlHeaders());
            if ($status !== 200) throw new RuntimeException('El almacenamiento rechazó el listado (' . $status . ')');
            $j = json_decode($body, true);
            if (!is_array($j)) throw new RuntimeException('Respuesta de listado inválida');
            foreach (($j['blobs'] ?? []) as $b) $out[] = $b;
            $cursor = ($j['hasMore'] ?? false) ? (string) ($j['cursor'] ?? '') : '';
        } while ($cursor !== '');
        return $out;
    }

    /** Elimina objetos por nombre de ruta. */
    public function delete(array $pathnames): void
    {
        if (!$pathnames) return;
        [$status] = $this->request('POST', self::API . '/delete',
            array_merge($this->controlHeaders(), ['content-type: application/json']),
            json_encode(['urls' => array_values($pathnames)]));
        if ($status !== 200) throw new RuntimeException('El almacenamiento rechazó el borrado (' . $status . ')');
    }

    /** Token de cliente (HMAC-SHA256, formato oficial del SDK) para subidas directas del navegador. */
    public function clientToken(string $pathname, int $maxBytes, array $allowedTypes, int $validUntilTs): string
    {
        $payloadB64 = base64_encode(json_encode([
            'pathname' => $pathname,
            'validUntil' => $validUntilTs * 1000,
            'maximumSizeInBytes' => $maxBytes,
            'allowedContentTypes' => $allowedTypes,
            'addRandomSuffix' => false,
            'allowOverwrite' => false,
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $hmac = hash_hmac('sha256', $payloadB64, $this->token);
        return 'vercel_blob_client_' . $this->sid . '_' . base64_encode($hmac . '.' . $payloadB64);
    }
}

/** Excepción de escritura concurrente (reintentar). */
final class BlobConflict extends RuntimeException {}


const DB_KEY = 'data/data.json';
const MAX_UPLOAD = 15728640;
const EXTENSIONS = ['pdf','doc','docx','odt','jpg','jpeg','png','webp','txt','rtf','ppt','pptx','xls','xlsx'];
const MIME_BY_EXT = [
    'pdf' => 'application/pdf',
    'doc' => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'odt' => 'application/vnd.oasis.opendocument.text',
    'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp',
    'txt' => 'text/plain', 'rtf' => 'application/rtf',
    'ppt' => 'application/vnd.ms-powerpoint',
    'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    'xls' => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const DANGER_MIME = ['text/html','application/x-httpd-php','application/x-php','image/svg+xml'];
const QUIZ_KEYS = [[1,0,0],[1,0,1],[0,0,0],[0,0,0],[0,0,0],[0,0,0]];
const SESSION_TTL = 43200;      // 12 horas deslizantes
const SESSION_REFRESH = 21600;  // renovar cuando quede menos de la mitad

function initial_db(): array
{
    return ['users'=>[], 'modules'=>[], 'submissions'=>[], 'resources'=>[], 'community'=>[],
            'evaluations'=>[], 'audit'=>[], 'sessions'=>[], 'throttle'=>[], 'meta'=>['created'=>now()]];
}

/* ---------- cliente singleton ---------- */
function blob(): BlobClient
{
    static $c = null;
    return $c ??= new BlobClient();
}

/* ---------- lectura/escritura de la base de datos ---------- */
function db_parse(?string $raw): array
{
    $v = json_decode((string) $raw, true);
    return is_array($v) ? array_replace(initial_db(), $v) : initial_db();
}

/** Lectura memoizada dentro de la misma petición. */
function db_read(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    $etag = null;
    $raw = blob()->readFresh(DB_KEY, $etag);
    $cache = db_parse($raw);
    return $cache;
}

/**
 * Actualización atómica: lee fresco, muta y escribe con ETag condicional.
 * Reintenta ante escrituras concurrentes (equivalente a flock exclusivo).
 */
function db_update(callable $callback): mixed
{
    for ($i = 0; $i < 6; $i++) {
        $etag = null;
        $raw = blob()->readFresh(DB_KEY, $etag);
        $db = db_parse($raw);
        $result = $callback($db); // puede lanzar excepciones/fail()
        $json = json_encode($db, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        try {
            blob()->put(DB_KEY, $json, 'application/json', $etag, 0);
            return $result;
        } catch (BlobConflict) {
            usleep(random_int(30000, 120000));
        }
    }
    throw new RuntimeException('El servidor está muy ocupado. Inténtelo de nuevo.');
}

/* ---------- utilidades de dominio (puerto fiel del original) ---------- */
function id(): string { return bin2hex(random_bytes(16)); }
function now(): string { return gmdate('c'); }
function email_norm(string $s): string { return strtolower(trim($s)); }
function safe_user(array $u): array
{
    return ['id'=>$u['id'], 'name'=>$u['name'], 'email'=>$u['email'], 'role'=>$u['role'],
            'site'=>$u['site'] ?? '', 'created'=>$u['created']];
}
function json_input(): array
{
    if (str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json')) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) fail('JSON no válido', 400);
        return $data;
    }
    return $_POST;
}
function fail(string $m, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $m], JSON_UNESCAPED_UNICODE);
    exit;
}
function send(mixed $v, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function clean_text(mixed $v, int $max = 4000): string
{
    if (!is_string($v)) fail('Se esperaba texto');
    $s = trim($v);
    if (strlen($s) > $max) fail('Texto demasiado largo');
    return $s;
}
function user_exists_by_email(array $db, string $email): bool
{
    foreach ($db['users'] as $u) if ($u['email'] === $email) return true;
    return false;
}
function record_audit(array &$db, string $action, string $actor, string $detail = ''): void
{
    $db['audit'][] = ['time'=>now(), 'actor'=>$actor, 'action'=>$action, 'detail'=>substr($detail, 0, 300)];
    if (count($db['audit']) > 2000) $db['audit'] = array_slice($db['audit'], -1500);
}
function all_files(array $db): array
{
    $files = [];
    foreach ($db['submissions'] as $s) foreach (($s['files'] ?? []) as $f) $files[$f['id']] = $f;
    foreach ($db['resources'] as $r) if (isset($r['file'])) $files[$r['file']['id']] = $r['file'];
    return $files;
}
function progress_for(array $db, string $uid): array
{
    $mods = []; $points = 0;
    for ($i = 0; $i < 6; $i++) {
        $d = $db['modules'][$uid][(string) $i] ?? [];
        $read = count(array_unique(array_filter($d['read'] ?? [], fn($x) => is_int($x) && $x >= 0 && $x < 3)));
        $hasActivity = trim((string) ($d['activity'] ?? '')) !== '';
        foreach ($db['submissions'] as $s) if ($s['user_id'] === $uid && $s['module'] === $i && count($s['files'] ?? []) > 0) $hasActivity = true;
        $passed = ($d['score'] ?? -1) >= 2;
        $reflect = (bool) ($d['reflection'] ?? false);
        $score = min(3, $read) + (int) $hasActivity + (int) $passed + (int) $reflect;
        $points += $score;
        $mods[] = ['module'=>$i, 'read'=>$read, 'activity'=>$hasActivity, 'score'=>$d['score'] ?? null,
                   'attempts'=>$d['attempts'] ?? 0, 'reflection'=>$reflect, 'milestones'=>$score,
                   'percent'=>round($score / 6 * 100), 'done'=>$score === 6];
    }
    return ['percent'=>round($points / 36 * 100), 'completed'=>count(array_filter($mods, fn($m) => $m['done'])),
            'milestones'=>$points, 'modules'=>$mods];
}

/* ---------- sesiones propias (dentro de data.json) ---------- */
function cookie_secure(): bool
{
    return getenv('VERCEL') === '1' || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
}
function set_cookie_expires(): void
{
    if (!headers_sent()) setcookie('rt_csrf', '', ['expires'=>1, 'path'=>'/', 'secure'=>cookie_secure(), 'httponly'=>true, 'samesite'=>'Lax']);
}
function sess_create(string $uid, array &$db): array
{
    // poda de sesiones vencidas y tope de tamaño
    $t = time();
    foreach ($db['sessions'] as $k => $s) if (($s['exp'] ?? 0) < $t) unset($db['sessions'][$k]);
    if (count($db['sessions']) > 600) {
        uasort($db['sessions'], fn($a, $b) => ($a['exp'] ?? 0) <=> ($b['exp'] ?? 0));
        $db['sessions'] = array_slice($db['sessions'], -400, null, true);
    }
    $sid = bin2hex(random_bytes(24));
    $csrf = bin2hex(random_bytes(24));
    $db['sessions'][$sid] = ['uid'=>$uid, 'csrf'=>$csrf, 'exp'=>$t + SESSION_TTL, 'last'=>$t];
    return ['sid'=>$sid, 'csrf'=>$csrf];
}
function sess_current(): ?array
{
    static $sess = false;
    if ($sess !== false) return $sess;
    $sid = $_COOKIE['rt_sid'] ?? '';
    $sess = null;
    if (preg_match('/^[a-f0-9]{48}$/', $sid)) {
        $db = db_read();
        $s = $db['sessions'][$sid] ?? null;
        if (is_array($s) && ($s['exp'] ?? 0) > time()) {
            $sess = $s + ['sid' => $sid];
            // renovación deslizante: al menos cada hora y media activa
            if (time() - ($s['last'] ?? 0) > 900 || ($s['exp'] ?? 0) - time() < SESSION_REFRESH) {
                try {
                    db_update(function (&$db2) use ($sid) {
                        $cur = $db2['sessions'][$sid] ?? null;
                        if (is_array($cur)) {
                            $cur['last'] = time();
                            $cur['exp'] = time() + SESSION_TTL;
                            $db2['sessions'][$sid] = $cur;
                        }
                    });
                } catch (Throwable) { /* la sesión sigue válida aunque no se renovó */ }
            }
        }
    }
    return $sess;
}
function current_user(): ?array
{
    $s = sess_current();
    if (!$s || ($s['uid'] ?? '') === '') return null;
    $db = db_read();
    return isset($db['users'][$s['uid']]) ? safe_user($db['users'][$s['uid']]) : null;
}
function require_user(): array { $u = current_user(); if (!$u) fail('Debe iniciar sesión', 401); return $u; }
function require_admin(): array { $u = require_user(); if ($u['role'] !== 'admin') fail('Acceso reservado al administrador', 403); return $u; }

/** CSRF: token de sesión; para pre-sesión se usa cookie de doble envío. */
function csrf(): void
{
    $token = (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    $sess = sess_current();
    $expected = $sess['csrf'] ?? ($_COOKIE['rt_csrf'] ?? '');
    if (!$token || $expected === '' || !hash_equals($expected, $token)) fail('Token de protección no válido', 403);
    $origin = $_SERVER['HTTP_ORIGIN'] ?? null;
    if ($origin) {
        $o = parse_url($origin);
        $host = explode(':', $_SERVER['HTTP_HOST'] ?? '')[0];
        if (!$o || ($o['host'] ?? '') !== $host) fail('Origen no autorizado', 403);
    }
}

/** Garantiza un token CSRF para visitantes anónimos (cookie de doble envío). */
function ensure_anon_csrf(): string
{
    $c = $_COOKIE['rt_csrf'] ?? '';
    if (!preg_match('/^[a-f0-9]{48}$/', $c)) {
        $c = bin2hex(random_bytes(24));
        if (!headers_sent()) setcookie('rt_csrf', $c, ['expires'=>0, 'path'=>'/', 'secure'=>cookie_secure(), 'httponly'=>true, 'samesite'=>'Lax']);
    }
    return $c;
}

/** Bloqueo de intentos por cuenta (persistente, corrige el hallazgo S-1). */
function throttle_check(array $db, string $emailHash): void
{
    $c = $db['throttle'][$emailHash] ?? null;
    if (is_array($c) && ($c['n'] ?? 0) >= 6 && time() - ($c['at'] ?? 0) < 600) {
        fail('Demasiados intentos. Inténtelo después.', 429);
    }
}
function throttle_fail(array &$db, string $emailHash): void
{
    $c = $db['throttle'][$emailHash] ?? ['n'=>0, 'at'=>0];
    $c['n'] = ($c['n'] ?? 0) + 1;
    $c['at'] = time();
    $db['throttle'][$emailHash] = $c;
    // poda de entradas viejas
    if (count($db['throttle']) > 500) {
        foreach ($db['throttle'] as $k => $v) if (time() - ($v['at'] ?? 0) > 3600) unset($db['throttle'][$k]);
        $db['throttle'] = array_slice($db['throttle'], -300, null, true);
    }
}
