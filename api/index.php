<?php
/**
 * RuralTech — API única para Vercel (runtime vercel-php).
 * Puerto fiel del api.php original + rol estudiante + subidas directas
 * al navegador (client token de Vercel Blob) + restauración desde la interfaz.
 */
declare(strict_types=1);

define('RT_APP', 1);
require __DIR__ . '/_lib.php';

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');

$action = (string) ($_GET['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET' && $method !== 'HEAD') csrf();

    switch ($action) {

    case 'session':
        $u = current_user();
        if ($u) {
            $s = sess_current();
            send(['user' => $u, 'csrf' => $s['csrf'] ?? '']);
        }
        send(['user' => null, 'csrf' => ensure_anon_csrf()]);

    case 'login':
        if ($method !== 'POST') fail('Método inválido', 405);
        $body = json_input();
        $email = email_norm(clean_text($body['email'] ?? '', 150));
        $password = (string) ($body['password'] ?? '');
        $hashKey = 'login_' . hash('sha256', $email);
        $db = db_read();
        throttle_check($db, $hashKey);
        $found = null;
        foreach ($db['users'] as $v) if ($v['email'] === $email) { $found = $v; break; }
        if (!$found || !password_verify($password, $found['password'])) {
            db_update(function (&$db) use ($hashKey) { throttle_fail($db, $hashKey); });
            fail('Credenciales incorrectas', 401);
        }
        // sesión nueva (rotación de identificador) y contador de intentos limpio
        $sess = db_update(function (&$db) use ($found, $hashKey) {
            unset($db['throttle'][$hashKey]);
            return sess_create($found['id'], $db);
        });
        session_cookie($sess['sid']);
        send(['user' => safe_user($found), 'csrf' => $sess['csrf']]);

    case 'logout':
        if ($method !== 'POST') fail('Método inválido', 405);
        $s = sess_current();
        if ($s) {
            db_update(function (&$db) use ($s) { unset($db['sessions'][$s['sid']]); });
        }
        session_cookie('');
        setcookie('rt_csrf', '', ['expires' => 1, 'path' => '/', 'secure' => cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
        send(['ok' => true, 'csrf' => ensure_anon_csrf()]);

    case 'me':
        send(['user' => require_user()]);

    case 'users':
        require_admin();
        $db = db_read();
        $rows = [];
        foreach ($db['users'] as $v) {
            $rows[] = safe_user($v)
                + ['progress' => progress_for($db, $v['id']),
                   'evaluation' => $db['evaluations'][$v['id']] ?? null];
        }
        send($rows);

    case 'students':
        $u = require_user();
        if (!in_array($u['role'], ['admin', 'teacher'], true)) fail('Acceso reservado a docentes', 403);
        $db = db_read();
        $rows = [];
        foreach ($db['users'] as $v) {
            if ($v['role'] !== 'student') continue;
            $rows[] = safe_user($v) + ['progress' => progress_for($db, $v['id'])];
        }
        send($rows);

    case 'create_user':
        $admin = require_admin();
        $b = json_input();
        $name = clean_text($b['name'] ?? '', 80);
        $email = email_norm(clean_text($b['email'] ?? '', 150));
        $password = (string) ($b['password'] ?? '');
        $r = (string) ($b['role'] ?? 'teacher');
        $role = in_array($r, ['admin', 'teacher', 'student'], true) ? $r : 'teacher';
        $site = clean_text($b['site'] ?? '', 100);
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($password) < 12 || strlen($password) > 200) {
            fail('Nombre, correo y contraseña de al menos 12 caracteres son obligatorios');
        }
        $record = db_update(function (&$db) use ($name, $email, $password, $role, $site, $admin) {
            if (user_exists_by_email($db, $email)) fail('Ese correo ya existe', 409);
            $uid = id();
            $u = ['id' => $uid, 'name' => $name, 'email' => $email, 'password' => password_hash($password, PASSWORD_DEFAULT),
                  'role' => $role, 'site' => $site, 'created' => now()];
            $db['users'][$uid] = $u;
            record_audit($db, 'user_created', $admin['id'], $uid . ' rol ' . $role);
            return safe_user($u);
        });
        send($record, 201);

    case 'reset_password':
        $admin = require_admin();
        $b = json_input();
        $uid = clean_text($b['user_id'] ?? '', 60);
        $pwd = (string) ($b['password'] ?? '');
        if (strlen($pwd) < 12) fail('Contraseña demasiado corta');
        db_update(function (&$db) use ($uid, $pwd, $admin) {
            if (!isset($db['users'][$uid])) fail('Usuario no existe', 404);
            $db['users'][$uid]['password'] = password_hash($pwd, PASSWORD_DEFAULT);
            // cierra todas las sesiones del usuario (seguridad)
            foreach ($db['sessions'] as $k => $s) if (($s['uid'] ?? '') === $uid) unset($db['sessions'][$k]);
            record_audit($db, 'password_reset', $admin['id'], $uid);
        });
        send(['ok' => true]);

    case 'change_password':
        $u = require_user();
        $b = json_input();
        $old = (string) ($b['old'] ?? '');
        $new = (string) ($b['password'] ?? '');
        if (strlen($new) < 12) fail('La nueva contraseña debe tener mínimo 12 caracteres');
        $curSid = sess_current()['sid'] ?? '';
        db_update(function (&$db) use ($u, $old, $new, $curSid) {
            if (!password_verify($old, $db['users'][$u['id']]['password'] ?? '')) fail('La contraseña actual es incorrecta', 403);
            $db['users'][$u['id']]['password'] = password_hash($new, PASSWORD_DEFAULT);
            // cierra las demás sesiones del usuario, conservando la actual
            foreach ($db['sessions'] as $k => $s) if (($s['uid'] ?? '') === $u['id'] && $k !== $curSid) unset($db['sessions'][$k]);
            record_audit($db, 'password_changed', $u['id']);
        });
        send(['ok' => true]);

    case 'overview':
        $u = require_user();
        $db = db_read();
        $target = $u['id'];
        if (isset($_GET['user']) && $_GET['user'] !== '') {
            $ask = clean_text((string) $_GET['user'], 60);
            if ($ask !== $u['id']) {
                $tUser = $db['users'][$ask] ?? null;
                if (!$tUser) fail('Usuario inexistente', 404);
                if ($u['role'] === 'admin'
                    || ($u['role'] === 'teacher' && $tUser['role'] === 'student')) {
                    $target = $ask;
                } else {
                    fail('No está autorizado para consultar ese progreso', 403);
                }
            }
        }
        if (!isset($db['users'][$target])) fail('Usuario inexistente', 404);
        send(['user' => safe_user($db['users'][$target]), 'progress' => progress_for($db, $target),
              'modules' => $db['modules'][$target] ?? [],
              'submissions' => array_values(array_filter($db['submissions'], fn($s) => $s['user_id'] === $target)),
              'evaluation' => $db['evaluations'][$target] ?? null]);

    case 'save_module':
        $u = require_user();
        $b = json_input();
        $type = (string) ($b['type'] ?? '');
        if ($type === 'diagnosis') {
            $text = clean_text($b['text'] ?? '', 12000);
            db_update(function (&$db) use ($u, $text) {
                $d = &$db['modules'][$u['id']]['diagnosis'];
                $d ??= ['activity' => ''];
                $d['activity'] = $text;
                $d['updated'] = now();
            });
            send(['ok' => true]);
        }
        $i = (int) ($b['module'] ?? -1);
        if ($i < 0 || $i > 5) fail('Módulo inválido');
        db_update(function (&$db) use ($i, $type, $u, $b) {
            $d = &$db['modules'][$u['id']][(string) $i];
            $d ??= ['read' => [], 'activity' => '', 'reflection' => false, 'attempts' => 0, 'score' => null];
            if ($type === 'read') {
                $n = (int) ($b['lesson'] ?? -1);
                if ($n < 0 || $n > 2) fail('Lección inválida');
                $read = $d['read'] ?? [];
                $read = array_values(array_diff($read, [$n]));
                if (($b['checked'] ?? false) === true) $read[] = $n;
                $d['read'] = $read;
            } elseif ($type === 'activity') {
                $d['activity'] = clean_text($b['text'] ?? '', 12000);
            } elseif ($type === 'reflection') {
                $d['reflection'] = ($b['checked'] ?? false) === true;
            } elseif ($type === 'quiz') {
                $answers = $b['answers'] ?? null;
                if (!is_array($answers) || count($answers) !== 3) fail('Responda tres preguntas');
                $keys = QUIZ_KEYS[$i] ?? [];
                $score = 0;
                foreach ($keys as $k => $v) {
                    if (!is_int($answers[$k]) || $answers[$k] < 0 || $answers[$k] > 2) fail('Respuesta no válida');
                    if ($answers[$k] === $v) $score++;
                }
                $d['score'] = max((int) ($d['score'] ?? 0), $score);
                $d['last_score'] = $score;
                $d['attempts'] = ($d['attempts'] ?? 0) + 1;
            } else {
                fail('Operación inválida');
            }
            $d['updated'] = now();
        });
        send(['ok' => true]);

    case 'resource_list':
        require_user();
        $db = db_read();
        send(array_values($db['resources']));

    /* ---- subidas: preparar (client token) + confirmar ---- */
    case 'upload_prepare': {
        $u = require_user();
        $b = json_input();
        $kind = ($b['kind'] ?? '') === 'resource' ? 'resource' : 'submission';
        if ($kind === 'resource' && $u['role'] !== 'admin') fail('Acceso reservado al administrador', 403);
        $name = clean_text($b['name'] ?? '', 150);
        $size = (int) ($b['size'] ?? 0);
        $module = $kind === 'resource' ? clean_text((string) ($b['module'] ?? 'all'), 8) : (string) (int) ($b['module'] ?? -1);
        $original = basename(str_replace('\\', '/', $name));
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?: 'archivo';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, EXTENSIONS, true)) fail('Formato de archivo no permitido');
        if ($size < 1 || $size > MAX_UPLOAD) fail('Archivo vacío o mayor de 15 MB');
        if ($kind === 'resource') {
            $title = clean_text($b['title'] ?? '', 150);
            if ($title === '' || !($module === 'all' || in_array($module, ['0','1','2','3','4','5'], true))) fail('Datos inválidos');
        } elseif ((int) $module < 0 || (int) $module > 6) {
            fail('Módulo inválido');
        }
        $fid = id();
        $pathname = 'files/' . $fid . '.bin';
        $ctype = MIME_BY_EXT[$ext];
        $token = blob()->clientToken($pathname, $size, [$ctype], time() + 900);
        send(['fid' => $fid, 'pathname' => $pathname, 'kind' => $kind, 'module' => $module,
              'contentType' => $ctype, 'token' => $token, 'maxSize' => MAX_UPLOAD, 'storeId' => blob()->storeId(),
              'uploadUrl' => 'https://vercel.com/api/blob/?pathname=' . $pathname]);
    }

    case 'upload_complete': {
        $u = require_user();
        $b = json_input();
        $fid = clean_text($b['fid'] ?? '', 60);
        if (!preg_match('/^[a-f0-9]{32}$/', $fid)) fail('Identificador de archivo inválido');
        $kind = ($b['kind'] ?? '') === 'resource' ? 'resource' : 'submission';
        if ($kind === 'resource' && $u['role'] !== 'admin') fail('Acceso reservado al administrador', 403);
        $pathname = 'files/' . $fid . '.bin';
        $bc = blob();
        if (!$bc->head($pathname, $realSize, $realCtype)) fail('No se recibió el archivo. Inténtelo de nuevo.', 400);
        if ($realSize < 1 || $realSize > MAX_UPLOAD) { $bc->delete([$pathname]); fail('Archivo vacío o mayor de 15 MB'); }
        // validar contenido real con finfo (equivalente a file_record del original)
        $prefix = $bc->readPrefix($pathname, 1024);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($prefix) ?: 'application/octet-stream';
        if (in_array($mime, DANGER_MIME, true)) { $bc->delete([$pathname]); fail('Contenido no admitido'); }
        $original = basename(str_replace('\\', '/', clean_text($b['name'] ?? 'archivo', 150)));
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?: 'archivo';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, EXTENSIONS, true)) { $bc->delete([$pathname]); fail('Formato de archivo no permitido'); }
        if (in_array($ext, ['jpg','jpeg','png','webp'], true) && !str_starts_with($mime, 'image/')) {
            $bc->delete([$pathname]);
            fail('Imagen inválida');
        }
        $file = ['id' => $fid, 'name' => substr($original, 0, 150), 'stored' => $pathname, 'size' => $realSize,
                 'mime' => $mime, 'owner' => $u['id'], 'kind' => $kind, 'created' => now()];
        if ($kind === 'resource') {
            $title = clean_text($b['title'] ?? '', 150);
            $desc = clean_text($b['description'] ?? '', 1500);
            $module = clean_text((string) ($b['module'] ?? 'all'), 8);
            if ($title === '' || !($module === 'all' || in_array($module, ['0','1','2','3','4','5'], true))) fail('Datos inválidos');
            $r = ['id' => id(), 'title' => $title, 'description' => $desc, 'module' => $module, 'file' => $file, 'created' => now()];
            db_update(function (&$db) use ($r, $u) {
                $db['resources'][$r['id']] = $r;
                record_audit($db, 'resource_upload', $u['id'], $r['id']);
            });
            send($r, 201);
        }
        $i = (int) ($b['module'] ?? -1);
        if ($i < 0 || $i > 6) fail('Módulo inválido');
        $sub = ['id' => id(), 'user_id' => $u['id'], 'module' => $i, 'files' => [$file], 'created' => now()];
        db_update(function (&$db) use ($sub, $u) {
            $db['submissions'][$sub['id']] = $sub;
            record_audit($db, 'submission_upload', $u['id'], $sub['id']);
        });
        send($sub, 201);
    }

    case 'resource_delete':
        $u = require_admin();
        $b = json_input();
        $rid = clean_text($b['id'] ?? '', 60);
        $stored = db_update(function (&$db) use ($rid, $u) {
            if (!isset($db['resources'][$rid])) fail('Recurso no encontrado', 404);
            $stored = $db['resources'][$rid]['file']['stored'];
            unset($db['resources'][$rid]);
            record_audit($db, 'resource_delete', $u['id'], $rid);
            return $stored;
        });
        try { blob()->delete([$stored]); } catch (Throwable) { /* el dato ya salió de la base */ }
        send(['ok' => true]);

    case 'download': {
        $u = require_user();
        $fid = clean_text($_GET['id'] ?? '', 60);
        $db = db_read();
        $files = all_files($db);
        $file = $files[$fid] ?? null;
        if (!$file) fail('Archivo no encontrado', 404);
        $isOwner = ($file['owner'] ?? '') === $u['id'];
        $isResource = ($file['kind'] ?? '') === 'resource';
        // docentes pueden descargar evidencias de estudiantes (seguimiento)
        $ownerRole = $db['users'][$file['owner']]['role'] ?? '';
        $teacherOfStudent = $u['role'] === 'teacher' && $ownerRole === 'student';
        if (!($u['role'] === 'admin' || $isResource || $isOwner || $teacherOfStudent)) {
            fail('Acceso denegado', 403);
        }
        try {
            $bc = blob();
            if (!$bc->head($file['stored'], $size, $ct)) fail('Archivo extraviado', 404);
            $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="' . $fname . '"');
            header('Content-Length: ' . $size);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            $bc->streamTo($file['stored'], $size);
        } catch (Throwable $e) {
            if (!headers_sent()) fail('Archivo extraviado', 404);
            exit;
        }
        exit;
    }

    case 'community':
        require_user();
        $db = db_read();
        send(array_values($db['community']));

    case 'community_post':
        $u = require_user();
        $b = json_input();
        $message = clean_text($b['text'] ?? '', 2000);
        if (!$message) fail('Escriba una publicación');
        db_update(function (&$db) use ($u, $message) {
            $db['community'][] = ['id' => id(), 'author' => $u['name'], 'user_id' => $u['id'], 'text' => $message, 'created' => now()];
            if (count($db['community']) > 2000) $db['community'] = array_slice($db['community'], -1500);
            record_audit($db, 'community_post', $u['id']);
        });
        send(['ok' => true]);

    case 'evaluation':
        $u = require_user();
        $db = db_read();
        send($db['evaluations'][$u['id']] ?? []);

    case 'evaluation_save':
        $u = require_user();
        $b = json_input();
        $ratings = $b['ratings'] ?? null;
        if (!is_array($ratings) || count($ratings) !== 10) fail('Se necesitan diez valoraciones');
        foreach ($ratings as $r) if (!is_int($r) || $r < 1 || $r > 5) fail('Valoración inválida');
        $comments = clean_text($b['comments'] ?? '', 2000);
        db_update(function (&$db) use ($u, $ratings, $comments) {
            $db['evaluations'][$u['id']] = ['ratings' => $ratings, 'comments' => $comments, 'updated' => now()];
            record_audit($db, 'evaluation_save', $u['id']);
        });
        send(['ok' => true]);

    case 'evaluations':
        require_admin();
        $db = db_read();
        $out = [];
        foreach ($db['evaluations'] as $uid => $e) {
            $usr = $db['users'][$uid] ?? null;
            if ($usr) $out[] = ['name' => $usr['name'], 'email' => $usr['email'], 'role' => $usr['role'], 'site' => $usr['site'] ?? ''] + $e;
        }
        send($out);

    case 'backup':
        require_admin();
        if ($method !== 'GET') fail('Método inválido', 405);
        @set_time_limit(55);
        $db = db_read();
        $temp = sys_get_temp_dir() . '/ruraltech_' . id() . '.tar';
        $phar = new PharData($temp);
        $phar->addFromString('data.json', json_encode($db, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        foreach (all_files($db) as $f) {
            try { $content = blob()->readAll($f['stored']); } catch (Throwable) { continue; }
            $phar->addFromString('uploads/' . basename($f['stored']), $content);
            unset($content);
        }
        $size = filesize($temp) ?: 0;
        header('Content-Type: application/x-tar');
        header('Content-Disposition: attachment; filename="RuralTech_respaldo_' . gmdate('Ymd_His') . '.tar"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-store');
        readfile($temp);
        @unlink($temp);
        exit;

    case 'restore': {
        $u = require_admin();
        if ($method !== 'POST') fail('Método inválido', 405);
        @set_time_limit(55);
        $up = $_FILES['tar'] ?? null;
        if (!$up || ($up['error'] ?? 1) !== UPLOAD_ERR_OK) fail('No se recibió el respaldo');
        if (($up['size'] ?? 0) < 10 || ($up['size'] ?? 0) > 4194304) fail('El respaldo debe pesar menos de 4 MB (límite de la plataforma)');
        $tmp = $up['tmp_name'];
        $dbNew = null; $uploads = [];
        try {
            $pharPath = 'phar://' . str_replace('\\', '/', (string) realpath($tmp));
            $dbRaw = @file_get_contents($pharPath . '/data.json');
            if ($dbRaw !== false) $dbNew = json_decode($dbRaw, true);
            if (is_dir($pharPath . '/uploads')) {
                foreach (new DirectoryIterator($pharPath . '/uploads') as $finfo) {
                    if ($finfo->isDot() || !$finfo->isFile()) continue;
                    if (preg_match('/^([a-f0-9]{32})\.bin$/', $finfo->getFilename(), $mm)) {
                        $uploads[$mm[1]] = $pharPath . '/uploads/' . $finfo->getFilename();
                    }
                }
            }
        } catch (Throwable $e) {
            fail('El archivo de respaldo no es un TAR válido');
        }
        if (!is_array($dbNew)) fail('El respaldo no contiene data.json válido');
        $dbNew = db_parse(json_encode($dbNew));
        if (count($uploads) > 20) fail('El respaldo supera 20 archivos adjuntos; divídalo o use la restauración CLI del paquete');
        $totalBytes = 0;
        foreach ($uploads as $p) { $totalBytes += filesize($p) ?: 0; }
        if ($totalBytes > 31457280) fail('El respaldo supera 30 MB de adjuntos');

        // copia previa de seguridad en el almacén
        $preKey = 'backups/pre_restore_' . gmdate('Ymd_His') . '_' . substr(id(), 0, 8) . '.json';
        $current = db_read();
        blob()->put($preKey, json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'application/json', null, 0);

        // subir archivos del respaldo
        $bc = blob();
        foreach ($uploads as $fid32 => $path) {
            $content = file_get_contents($path);
            if ($content === false || strlen($content) < 1 || strlen($content) > MAX_UPLOAD) fail('Archivo adjunto inválido en el respaldo: ' . $fid32);
            $bc->put('files/' . $fid32 . '.bin', $content, 'application/octet-stream', null, 3000);
            unset($content);
        }
        // reemplazar base conservando sesiones actuales
        $me = $u['id'];
        db_update(function (&$db) use ($dbNew, $me) {
            $keep = ['sessions' => $db['sessions'], 'meta' => $db['meta']];
            $db = array_replace(initial_db(), $dbNew);
            $db['sessions'] = $keep['sessions'];
            record_audit($db, 'restore_completed', $me, 'sesiones actuales conservadas');
        });
        send(['ok' => true, 'preRestoreKey' => $preKey]);
    }

    case 'audit':
        require_admin();
        $db = db_read();
        send(array_slice($db['audit'], -100));

    default:
        fail('Acción no encontrada', 404);
    }
} catch (BlobConflict $e) {
    fail('El servidor está muy ocupado. Inténtelo de nuevo.', 503);
} catch (Throwable $e) {
    error_log('RuralTech error: ' . $e->getMessage());
    fail('Error interno del servidor', 500);
}

/** Cookie de sesión (identificador propio, no PHP sessions). */
function session_cookie(string $sid): void
{
    if (headers_sent()) return;
    if ($sid === '') {
        setcookie('rt_sid', '', ['expires' => 1, 'path' => '/', 'secure' => cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
        return;
    }
    setcookie('rt_sid', $sid, ['expires' => 0, 'path' => '/', 'secure' => cookie_secure(), 'httponly' => true, 'samesite' => 'Lax']);
}
