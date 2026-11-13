<?php
/**
 * Instalador de primer administrador.
 * El código de instalación se define como variable de entorno SETUP_TOKEN
 * del proyecto en Vercel (ningún token se escribe en el sistema de archivos).
 * Sin SETUP_TOKEN, el formulario queda bloqueado; el token temporal también permite recuperar al único administrador.
 */
declare(strict_types=1);
define('RT_APP', 1);
require __DIR__ . '/_lib.php';

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');

$setupToken = (string) getenv('SETUP_TOKEN');
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        if ($setupToken === '') { http_response_code(500); exit('Falta configurar la variable de entorno SETUP_TOKEN en el proyecto.'); }
        $provided = (string) ($_POST['token'] ?? '');
        if (!hash_equals($setupToken, $provided)) { http_response_code(403); exit('Código de instalación incorrecto.'); }
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = email_norm((string) ($_POST['email'] ?? ''));
        $pass = (string) ($_POST['password'] ?? '');
        if (!$name || strlen($name) > 80 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($pass) < 12) {
            http_response_code(400);
            exit('Datos no válidos: nombre, correo y contraseña de 12 caracteres como mínimo.');
        }
        db_update(function (&$db) use ($name, $email, $pass) {
            $admins = array_filter($db['users'], fn($u) => ($u['role'] ?? '') === 'admin');
            if (count($admins) > 1) throw new RuntimeException('Hay más de un administrador; recuperación bloqueada');
            $uid = $admins ? array_key_first($admins) : id();
            foreach ($db['users'] as $otherId => $user) {
                if ($otherId !== $uid && ($user['email'] ?? '') === $email) {
                    throw new RuntimeException('El correo ya pertenece a otro usuario');
                }
            }
            $existing = $db['users'][$uid] ?? null;
            $db['users'][$uid] = [
                'id' => $uid,
                'name' => $name,
                'email' => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT),
                'role' => 'admin',
                'site' => $existing['site'] ?? 'Centro Educativo San Pedro',
                'created' => $existing['created'] ?? now(),
            ];
            foreach ($db['sessions'] as $sid => $session) {
                if (($session['uid'] ?? '') === $uid) unset($db['sessions'][$sid]);
            }
            record_audit($db, $existing ? 'admin_credentials_recovered' : 'admin_created_setup', $uid);
        });
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RuralTech configurado</title><body style="font:16px system-ui;max-width:650px;margin:8vh auto;padding:20px;color:#183d30"><h1>✅ Administrador configurado</h1><p><strong>Elimine ahora la variable SETUP_TOKEN</strong> del proyecto. Abra <a href="/">RuralTech</a> e inicie sesión.</p></body></html>';
    } catch (Throwable $e) {
        error_log('RuralTech setup: ' . $e->getMessage());
        http_response_code(500);
        echo 'No se pudo completar la instalación. Inténtelo de nuevo en unos segundos.';
    }
    exit;
}

if ($setupToken === '') {
    http_response_code(500);
    exit('Falta configurar temporalmente la variable de entorno SETUP_TOKEN en el proyecto.');
}
try {
    $db = db_read();
} catch (Throwable $e) {
    error_log('RuralTech setup read: ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit('No se pudo acceder al almacenamiento. Verifique que BLOB_READ_WRITE_TOKEN esté configurado y tenga acceso al almacén de RuralTech.');
}
$admins = array_filter($db['users'], fn($u) => ($u['role'] ?? '') === 'admin');
if (count($admins) > 1) {
    http_response_code(409);
    exit('Hay más de un administrador. No se puede recuperar automáticamente.');
}
$title = $admins ? 'Recuperar acceso de administrador' : 'Configuración inicial de RuralTech';
$action = $admins ? 'Actualizar correo y contraseña del administrador' : 'Crear administrador';
?><!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></title><style>body{font:16px system-ui;max-width:650px;margin:5vh auto;padding:20px;background:#f5f8f6;color:#183d30}form{background:white;border:1px solid #ddd;padding:25px;border-radius:14px}label{display:block;margin:15px 0 5px}input{width:100%;padding:12px;box-sizing:border-box}button{margin-top:20px;padding:12px 20px;background:#176b52;color:white;border:0;border-radius:8px;cursor:pointer}</style><h1><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></h1><p>Este formulario requiere la variable temporal <code>SETUP_TOKEN</code> configurada en Vercel. Elimínela inmediatamente después de terminar.</p><form method="post"><label>Código privado<input required name="token" autocomplete="off"></label><label>Nombre del administrador<input required name="name" maxlength="80"></label><label>Correo<input required type="email" name="email"></label><label>Contraseña (mínimo 12 caracteres)<input required type="password" minlength="12" name="password" autocomplete="new-password"></label><button><?= htmlspecialchars($action, ENT_QUOTES, 'UTF-8') ?></button></form></html>
