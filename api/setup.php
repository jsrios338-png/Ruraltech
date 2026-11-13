<?php
/**
 * Instalador de primer administrador.
 * El código de instalación se define como variable de entorno SETUP_TOKEN
 * del proyecto en Vercel (ningún token se escribe en el sistema de archivos).
 * Tras crear el administrador, este instalador se bloquea automáticamente.
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
        $db = db_read();
        if (count($db['users']) > 0) { http_response_code(403); exit('La instalación ya está configurada. Elimine la variable SETUP_TOKEN del proyecto.'); }
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
            if (count($db['users']) > 0) throw new RuntimeException('Ya está instalado');
            $uid = id();
            $db['users'][$uid] = ['id' => $uid, 'name' => $name, 'email' => $email,
                'password' => password_hash($pass, PASSWORD_DEFAULT), 'role' => 'admin',
                'site' => 'Centro Educativo San Pedro', 'created' => now()];
            record_audit($db, 'admin_created_setup', $uid);
        });
        echo '<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>RuralTech instalado</title><body style="font:16px system-ui;max-width:650px;margin:8vh auto;padding:20px;color:#183d30"><h1>✅ Administrador creado</h1><p><strong>Elimine ahora la variable SETUP_TOKEN</strong> del proyecto para desactivar este instalador. Abra <a href="/">RuralTech</a> e inicie sesión.</p></body></html>';
    } catch (Throwable $e) {
        error_log('RuralTech setup: ' . $e->getMessage());
        http_response_code(500);
        echo 'No se pudo completar la instalación. Inténtelo de nuevo en unos segundos.';
    }
    exit;
}

$db = db_read();
if (count($db['users']) > 0) { http_response_code(403); exit('RuralTech ya tiene administrador. Elimine la variable SETUP_TOKEN del proyecto.'); }
?><!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar RuralTech</title><style>body{font:16px system-ui;max-width:650px;margin:5vh auto;padding:20px;background:#f5f8f6;color:#183d30}form{background:white;border:1px solid #ddd;padding:25px;border-radius:14px}label{display:block;margin:15px 0 5px}input{width:100%;padding:12px;box-sizing:border-box}button{margin-top:20px;padding:12px 20px;background:#176b52;color:white;border:0;border-radius:8px;cursor:pointer}</style><h1>Configuración inicial de RuralTech</h1><p>Escriba el <strong>código de instalación</strong> definido en la variable de entorno <code>SETUP_TOKEN</code> del proyecto. Este instalador se desactiva al crear el administrador.</p><form method="post"><label>Código privado de instalación<input required name="token" autocomplete="off"></label><label>Nombre del administrador<input required name="name" maxlength="80"></label><label>Correo<input required type="email" name="email"></label><label>Contraseña (mínimo 12 caracteres)<input required type="password" minlength="12" name="password"></label><button>Crear administrador</button></form></html>
