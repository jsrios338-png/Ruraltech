<?php
/**
 * RuralTech — API única para Vercel (runtime vercel-php).
 * Puerto fiel del api.php original + subidas directas
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
        if (!$found || !in_array($found['role'] ?? '', ['admin', 'teacher'], true)
            || !password_verify($password, $found['password'])) {
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

    case 'create_user':
        $admin = require_admin();
        $b = json_input();
        $name = clean_text($b['name'] ?? '', 80);
        $email = email_norm(clean_text($b['email'] ?? '', 150));
        $password = (string) ($b['password'] ?? '');
        $r = (string) ($b['role'] ?? 'teacher');
        if (!in_array($r, ['admin', 'teacher'], true)) fail('Rol no válido');
        $role = $r;
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

    case 'update_user':
        $admin = require_admin();
        $b = json_input();
        $uid = clean_text($b['user_id'] ?? '', 60);
        $name = clean_text($b['name'] ?? '', 80);
        $email = email_norm(clean_text($b['email'] ?? '', 150));
        $role = (string) ($b['role'] ?? 'teacher');
        if (!in_array($role, ['admin', 'teacher'], true)) fail('Rol no válido');
        $site = clean_text($b['site'] ?? '', 100);
        $password = (string) ($b['password'] ?? '');
        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            fail('Nombre y correo válidos son obligatorios');
        }
        if ($password !== '' && (strlen($password) < 12 || strlen($password) > 200)) {
            fail('La nueva contraseña debe tener al menos 12 caracteres');
        }
        db_update(function (&$db) use ($uid, $name, $email, $role, $site, $password, $admin) {
            if (!isset($db['users'][$uid])) fail('Usuario no existe', 404);
            $user = $db['users'][$uid];
            // no dejar la plataforma sin administradores
            if (($user['role'] ?? '') === 'admin' && $role !== 'admin') {
                $admins = array_filter($db['users'], fn($u) => ($u['role'] ?? '') === 'admin');
                if (count($admins) <= 1) fail('No se puede quitar el rol al último administrador', 403);
            }
            foreach ($db['users'] as $oid => $other) {
                if ($oid !== $uid && ($other['email'] ?? '') === $email) fail('Ese correo ya pertenece a otro usuario', 409);
            }
            $user['name'] = $name;
            $user['email'] = $email;
            $user['role'] = $role;
            $user['site'] = $site;
            if ($password !== '') {
                $user['password'] = password_hash($password, PASSWORD_DEFAULT);
                // cierra las sesiones del usuario editado por seguridad
                foreach ($db['sessions'] as $k => $s) if (($s['uid'] ?? '') === $uid) unset($db['sessions'][$k]);
            }
            $db['users'][$uid] = $user;
            record_audit($db, 'user_updated', $admin['id'], $uid . ' rol ' . $role);
        });
        send(['ok' => true]);

    case 'delete_user': {
        $admin = require_admin();
        $b = json_input();
        $uid = clean_text($b['user_id'] ?? '', 60);
        if ($uid === $admin['id']) fail('No puedes eliminar tu propia cuenta', 403);
        $stored = db_update(function (&$db) use ($uid, $admin) {
            if (!isset($db['users'][$uid])) fail('Usuario no existe', 404);
            $user = $db['users'][$uid];
            if (($user['role'] ?? '') === 'admin') {
                $admins = array_filter($db['users'], fn($u) => ($u['role'] ?? '') === 'admin');
                if (count($admins) <= 1) fail('No se puede eliminar al último administrador', 403);
            }
            // entregas de módulos y evidencias de actividades (con sus archivos);
            // los recursos institucionales se conservan
            $stored = [];
            foreach ($db['submissions'] as $sid => $s) {
                if (($s['user_id'] ?? '') !== $uid) continue;
                foreach (($s['files'] ?? []) as $f) $stored[] = $f['stored'];
                unset($db['submissions'][$sid]);
            }
            foreach (($db['activity_submissions'] ?? []) as $sid => $s) {
                if (($s['user_id'] ?? '') !== $uid) continue;
                foreach (($s['files'] ?? []) as $f) $stored[] = $f['stored'];
                unset($db['activity_submissions'][$sid]);
            }
            unset($db['modules'][$uid], $db['evaluations'][$uid]);
            foreach ($db['sessions'] as $k => $s) if (($s['uid'] ?? '') === $uid) unset($db['sessions'][$k]);
            unset($db['users'][$uid]);
            record_audit($db, 'user_deleted', $admin['id'], substr((string) ($user['name'] ?? ''), 0, 80));
            return array_values(array_unique($stored));
        });
        try { blob()->delete($stored); } catch (Throwable) { /* los registros ya salieron de la base */ }
        send(['ok' => true]);
    }

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
                if ($u['role'] === 'admin') {
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
        $resources = array_values($db['resources']);
        usort($resources, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
        send($resources);

    case 'activity_list':
        $u = require_user();
        $db = db_read();
        $activities = [];
        foreach ($db['activities'] as $activity) {
            $item = $activity;
            $item['submissions'] = [];
            foreach (($db['activity_submissions'] ?? []) as $submission) {
                if (($submission['activity_id'] ?? '') !== $activity['id']) continue;
                $owner = $db['users'][$submission['user_id']] ?? null;
                if (!$owner || ($u['role'] !== 'admin' && $owner['id'] !== $u['id'])) continue;
                $item['submissions'][] = $u['role'] === 'admin'
                    ? ['id' => $submission['id'], 'created' => $submission['created'],
                       'user' => safe_user($owner), 'files' => $submission['files']]
                    : ['id' => $submission['id'], 'created' => $submission['created'],
                       'files' => $submission['files']];
            }
            if ($u['role'] !== 'admin' && !($activity['active'] ?? true) && !$item['submissions']) continue;
            $activities[] = $item;
        }
        usort($activities, fn($a, $b) => strcmp($b['created'] ?? '', $a['created'] ?? ''));
        send($activities);

    case 'activity_create':
        $admin = require_admin();
        if ($method !== 'POST') fail('Método inválido', 405);
        $b = json_input();
        $title = clean_text($b['title'] ?? '', 150);
        $description = clean_text($b['description'] ?? '', 6000);
        $due = clean_text($b['due'] ?? '', 10);
        if ($title === '' || $description === '') fail('Título e instrucciones son obligatorios');
        if ($due !== '') {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d', $due);
            if (!$date || $date->format('Y-m-d') !== $due) fail('Fecha límite no válida');
        }
        $activity = [
            'id' => id(), 'title' => $title, 'description' => $description,
            'due' => $due, 'active' => true, 'created' => now(), 'created_by' => $admin['id'],
        ];
        db_update(function (&$db) use ($activity, $admin) {
            $db['activities'][$activity['id']] = $activity;
            record_audit($db, 'activity_published', $admin['id'], $activity['id']);
        });
        send($activity, 201);

    case 'activity_archive':
        $admin = require_admin();
        if ($method !== 'POST') fail('Método inválido', 405);
        $b = json_input();
        $activityId = clean_text($b['id'] ?? '', 60);
        db_update(function (&$db) use ($activityId, $admin) {
            if (!isset($db['activities'][$activityId])) fail('Actividad no encontrada', 404);
            $db['activities'][$activityId]['active'] = false;
            record_audit($db, 'activity_archived', $admin['id'], $activityId);
        });
        send(['ok' => true]);

    /* ---- subidas: preparar (client token) + confirmar ---- */
    case 'upload_prepare': {
        $u = require_user();
        $b = json_input();
        $kind = (string) ($b['kind'] ?? 'submission');
        if (!in_array($kind, ['resource', 'submission', 'activity_evidence'], true)) fail('Tipo de archivo no válido');
        if ($kind === 'resource' && $u['role'] !== 'admin') fail('Acceso reservado al administrador', 403);
        if ($kind === 'activity_evidence' && $u['role'] !== 'teacher') fail('Solo los docentes pueden entregar evidencias', 403);
        $name = clean_text($b['name'] ?? '', 150);
        $size = (int) ($b['size'] ?? 0);
        $module = $kind === 'resource' ? clean_text((string) ($b['module'] ?? 'all'), 8) : (string) (int) ($b['module'] ?? -1);
        $original = basename(str_replace('\\', '/', $name));
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?: 'archivo';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, EXTENSIONS, true)) fail('Formato de archivo no permitido');
        $sizeLimit = $kind === 'resource' && in_array($ext, ['mp4', 'webm'], true)
            ? MAX_VIDEO_UPLOAD : MAX_UPLOAD;
        if ($size < 1 || $size > $sizeLimit) fail($sizeLimit === MAX_VIDEO_UPLOAD
            ? 'El video está vacío o supera los 100 MB' : 'Archivo vacío o mayor de 15 MB');
        if ($kind === 'resource') {
            $title = clean_text($b['title'] ?? '', 150);
            if ($title === '' || !($module === 'all' || in_array($module, ['0','1','2','3','4','5'], true))) fail('Datos inválidos');
        } elseif ($kind === 'activity_evidence') {
            $activityId = clean_text($b['activity_id'] ?? '', 60);
            $activity = db_read()['activities'][$activityId] ?? null;
            if (!$activity || !($activity['active'] ?? true)) fail('La actividad ya no está disponible', 404);
        } elseif ((int) $module < 0 || (int) $module > 6) {
            fail('Módulo inválido');
        }
        $fid = id();
        $pathname = 'files/' . $fid . '.bin';
        $ctype = MIME_BY_EXT[$ext];
        $token = blob()->clientToken($pathname, $size, [$ctype], time() + 900);
        send(['fid' => $fid, 'pathname' => $pathname, 'kind' => $kind, 'module' => $module,
              'contentType' => $ctype, 'token' => $token, 'maxSize' => $sizeLimit, 'storeId' => blob()->storeId(),
              'uploadUrl' => 'https://vercel.com/api/blob/?pathname=' . $pathname]);
    }

    case 'upload_complete': {
        $u = require_user();
        $b = json_input();
        $fid = clean_text($b['fid'] ?? '', 60);
        if (!preg_match('/^[a-f0-9]{32}$/', $fid)) fail('Identificador de archivo inválido');
        $kind = (string) ($b['kind'] ?? 'submission');
        if (!in_array($kind, ['resource', 'submission', 'activity_evidence'], true)) fail('Tipo de archivo no válido');
        if ($kind === 'resource' && $u['role'] !== 'admin') fail('Acceso reservado al administrador', 403);
        if ($kind === 'activity_evidence' && $u['role'] !== 'teacher') fail('Solo los docentes pueden entregar evidencias', 403);
        $pathname = 'files/' . $fid . '.bin';
        $bc = blob();
        if (!$bc->head($pathname, $realSize, $realCtype)) fail('No se recibió el archivo. Inténtelo de nuevo.', 400);
        $original = basename(str_replace('\\', '/', clean_text($b['name'] ?? 'archivo', 150)));
        $original = preg_replace('/[\x00-\x1f\x7f]/u', '', $original) ?: 'archivo';
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!in_array($ext, EXTENSIONS, true)) { $bc->delete([$pathname]); fail('Formato de archivo no permitido'); }
        $sizeLimit = $kind === 'resource' && in_array($ext, ['mp4', 'webm'], true)
            ? MAX_VIDEO_UPLOAD : MAX_UPLOAD;
        if ($realSize < 1 || $realSize > $sizeLimit) {
            $bc->delete([$pathname]);
            fail($sizeLimit === MAX_VIDEO_UPLOAD ? 'El video supera los 100 MB' : 'Archivo mayor de 15 MB');
        }
        // validar contenido real con finfo (equivalente a file_record del original)
        $prefix = $bc->readPrefix($pathname, 1024);
        $mime = (new finfo(FILEINFO_MIME_TYPE))->buffer($prefix) ?: 'application/octet-stream';
        if (in_array($mime, DANGER_MIME, true)) { $bc->delete([$pathname]); fail('Contenido no admitido'); }
        if (in_array($ext, ['mp4', 'webm'], true)) {
            if ($kind !== 'resource' || !in_array($mime, [MIME_BY_EXT[$ext], 'application/octet-stream'], true)) {
                $bc->delete([$pathname]);
                fail('Video no válido; use MP4 o WebM');
            }
            $mime = MIME_BY_EXT[$ext];
        }
        if (in_array($ext, ['jpg','jpeg','png','webp'], true) && !str_starts_with($mime, 'image/')) {
            $bc->delete([$pathname]);
            fail('Imagen inválida');
        }
        $file = ['id' => $fid, 'name' => substr($original, 0, 150), 'stored' => $pathname, 'size' => $realSize,
                 'mime' => $mime, 'owner' => $u['id'], 'kind' => $kind === 'resource' ? 'resource' : 'submission', 'created' => now()];
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
        if ($kind === 'activity_evidence') {
            $activityId = clean_text($b['activity_id'] ?? '', 60);
            $submission = ['id' => id(), 'activity_id' => $activityId, 'user_id' => $u['id'],
                           'files' => [$file], 'created' => now()];
            db_update(function (&$db) use ($submission, $u) {
                if (!isset($db['activities'][$submission['activity_id']])
                    || !($db['activities'][$submission['activity_id']]['active'] ?? true)) {
                    fail('La actividad ya no está disponible', 404);
                }
                $db['activity_submissions'][$submission['id']] = $submission;
                record_audit($db, 'activity_evidence_submitted', $u['id'], $submission['activity_id']);
            });
            send($submission, 201);
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
        if (!($u['role'] === 'admin' || $isResource || $isOwner)) {
            fail('Acceso denegado', 403);
        }
        $inline = ($_GET['inline'] ?? '') === '1';
        if ($inline && !in_array(strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)), ['mp4', 'webm'], true)) {
            fail('Vista previa no disponible para este archivo', 400);
        }
        try {
            $bc = blob();
            if (!$bc->head($file['stored'], $size, $ct)) fail('Archivo extraviado', 404);
            $fname = preg_replace('/[^A-Za-z0-9._-]/', '_', $file['name']);
            header('Content-Type: ' . ($inline ? $file['mime'] : 'application/octet-stream'));
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $fname . '"');
            $range = null;
            if ($inline) {
                header('Accept-Ranges: bytes');
                $requestedRange = (string) ($_SERVER['HTTP_RANGE'] ?? '');
                if ($requestedRange !== '') {
                    if (!preg_match('/^bytes=(\d*)-(\d*)$/D', $requestedRange, $matches)
                        || ($matches[1] === '' && $matches[2] === '')) {
                        http_response_code(416);
                        header('Content-Range: bytes */' . $size);
                        exit;
                    }
                    if ($matches[1] === '') {
                        $suffixLength = (int) $matches[2];
                        if ($suffixLength < 1) {
                            http_response_code(416);
                            header('Content-Range: bytes */' . $size);
                            exit;
                        }
                        $start = max(0, $size - $suffixLength);
                        $end = $size - 1;
                    } else {
                        $start = (int) $matches[1];
                        $end = $matches[2] === '' ? $size - 1 : min((int) $matches[2], $size - 1);
                    }
                    if ($start >= $size || $end < $start) {
                        http_response_code(416);
                        header('Content-Range: bytes */' . $size);
                        exit;
                    }
                    $range = $start . '-' . $end;
                }
            }
            if ($range === null) header('Content-Length: ' . $size);
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, no-store');
            $bc->streamTo($file['stored'], $size, $range);
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
    fail('Error interno del servidor: ' . $e->getMessage(), 500); // DIAGNOSTICO TEMPORAL — revertir
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
