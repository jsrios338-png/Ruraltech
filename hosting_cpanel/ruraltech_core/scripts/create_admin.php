<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola');}
require dirname(__DIR__).'/src.php';
if($argc<4){fwrite(STDERR,"Uso: php scripts/create_admin.php 'Nombre' correo@dominio.com 'ContrasenaSeguraDe12+' [Sede]\n");exit(1);}
$name=trim($argv[1]);$email=email_norm($argv[2]);$password=$argv[3];$site=$argv[4]??'Centro Educativo San Pedro';
if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12){fwrite(STDERR,"Nombre, correo o contraseña inválidos (mínimo 12 caracteres).\n");exit(1);}
try {
 $u=db_update(function(&$db)use($name,$email,$password,$site){if(user_exists_by_email($db,$email))throw new RuntimeException('Correo ya registrado');$id=id();$u=['id'=>$id,'name'=>$name,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>'admin','site'=>$site,'created'=>now()];$db['users'][$id]=$u;record_audit($db,'admin_created_cli',$id);return safe_user($u);});
 echo "Administrador creado: {$u['email']}\n";
}catch(Throwable $e){fwrite(STDERR,$e->getMessage()."\n");exit(1);}
