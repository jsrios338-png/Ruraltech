<?php
/** Instalador de primer administrador; requiere clave generada fuera del directorio público. */
declare(strict_types=1);
require __DIR__.'/api_bootstrap.php';
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 header('Content-Type: text/html; charset=utf-8');
 $db=db_read(); if(count($db['users'])>0){http_response_code(403);exit('La instalación ya está configurada.');}
 $token_file=PRIVATE_DIR.'/install-token.txt';
 if(!is_file($token_file)){http_response_code(500);exit('Falta el token de instalación.');}
 $provided=(string)($_POST['token']??'');
 if(!hash_equals(trim((string)file_get_contents($token_file)),$provided)){http_response_code(403);exit('Código de instalación incorrecto.');}
 $name=trim((string)($_POST['name']??''));$email=email_norm((string)($_POST['email']??''));$pass=(string)($_POST['password']??'');
 if(!$name||strlen($name)>80||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($pass)<12){http_response_code(400);exit('Datos no válidos: nombre, correo y contraseña de 12 caracteres como mínimo.');}
 try {
  db_update(function(&$db)use($name,$email,$pass){
   if(count($db['users'])>0)throw new RuntimeException('Ya está instalado');
   $uid=id();$u=['id'=>$uid,'name'=>$name,'email'=>$email,'password'=>password_hash($pass,PASSWORD_DEFAULT),'role'=>'admin','site'=>'Centro Educativo San Pedro','created'=>now()];$db['users'][$uid]=$u;record_audit($db,'admin_created_setup',$uid);
  });
  @unlink($token_file);
  echo '<!doctype html><meta charset="utf-8"><title>RuralTech instalado</title><h1>Administrador creado</h1><p>Elimine <code>setup.php</code> del directorio público. Abra <a href="index.html">RuralTech</a> e inicie sesión.</p>';
 }catch(Throwable $e){http_response_code(500);echo 'No se pudo completar la instalación. Revise permisos y registros del servidor.';}
 exit;
}
$db=db_read();if(count($db['users'])>0){http_response_code(403);exit('RuralTech ya tiene administrador. Elimine setup.php.');}
$token_file=PRIVATE_DIR.'/install-token.txt';
if(!is_file($token_file)){ $token=bin2hex(random_bytes(20));if(file_put_contents($token_file,$token,LOCK_EX)===false) {http_response_code(500);exit('No se pudo crear el código de instalación. Revise permisos.');} @chmod($token_file,0600); }
?><!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Instalar RuralTech</title><style>body{font:16px system-ui;max-width:650px;margin:5vh auto;padding:20px;background:#f5f8f6;color:#183d30}form{background:white;border:1px solid #ddd;padding:25px;border-radius:14px}label{display:block;margin:15px 0 5px}input{width:100%;padding:12px;box-sizing:border-box}button{margin-top:20px;padding:12px 20px;background:#176b52;color:white;border:0;border-radius:8px}</style><h1>Configuración inicial de RuralTech</h1><p>Abra con el administrador de archivos del alojamiento <code>ruraltech_core/private/install-token.txt</code> (en servidor PHP, <code>private/install-token.txt</code>) y copie su código aquí. Este archivo no debe exponerse públicamente.</p><form method="post"><label>Código privado de instalación<input required name="token" autocomplete="off"></label><label>Nombre del administrador<input required name="name" maxlength="80"></label><label>Correo<input required type="email" name="email"></label><label>Contraseña (mínimo 12 caracteres)<input required type="password" minlength="12" name="password"></label><button>Crear administrador</button></form></html>
