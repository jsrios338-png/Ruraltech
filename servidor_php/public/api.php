<?php
declare(strict_types=1);
require dirname(__DIR__).'/src.php';
ini_set('session.use_strict_mode','1');ini_set('session.cookie_httponly','1');ini_set('session.cookie_samesite','Lax');
if(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off')ini_set('session.cookie_secure','1');
header('X-Content-Type-Options: nosniff');header('Referrer-Policy: no-referrer');header('X-Frame-Options: DENY');
session_start();$_SESSION['csrf']??=bin2hex(random_bytes(24));
$action=(string)($_GET['action']??'');$method=$_SERVER['REQUEST_METHOD']??'GET';
if($method!=='GET'&&$method!=='HEAD')csrf();
try {
switch($action){
case 'session': send(['user'=>current_user(),'csrf'=>$_SESSION['csrf']]);
case 'login':
 if($method!=='POST')fail('Método inválido',405);
 $body=json_input();$email=email_norm(clean_text($body['email']??'',150));$password=(string)($body['password']??'');
 $key='login_'.hash('sha256',$email);$count=$_SESSION[$key]??['n'=>0,'at'=>0];
 if($count['n']>=6&&time()-$count['at']<600)fail('Demasiados intentos. Inténtelo después.',429);
 $db=db_read();$found=null;foreach($db['users'] as $v)if($v['email']===$email){$found=$v;break;}
 if(!$found||!password_verify($password,$found['password'])){
  $_SESSION[$key]=['n'=>$count['n']+1,'at'=>time()];fail('Credenciales incorrectas',401);
 }
 unset($_SESSION[$key]);session_regenerate_id(true);$_SESSION['uid']=$found['id'];$_SESSION['csrf']=bin2hex(random_bytes(24));send(['user'=>safe_user($found),'csrf'=>$_SESSION['csrf']]);
case 'logout':
 if($method!=='POST')fail('Método inválido',405);
 $_SESSION=[];session_regenerate_id(true);$_SESSION['csrf']=bin2hex(random_bytes(24));send(['ok'=>true,'csrf'=>$_SESSION['csrf']]);
case 'me': send(['user'=>require_user()]);
case 'users':
 require_admin();$db=db_read();$rows=[];foreach($db['users'] as $v)$rows[]=safe_user($v)+['progress'=>progress_for($db,$v['id'])];send($rows);
case 'create_user':
 $admin=require_admin();$b=json_input();$name=clean_text($b['name']??'',80);$email=email_norm(clean_text($b['email']??'',150));$password=(string)($b['password']??'');$role=($b['role']??'teacher')==='admin'?'admin':'teacher';$site=clean_text($b['site']??'',100);
 if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL)||strlen($password)<12||strlen($password)>200)fail('Nombre, correo y contraseña de al menos 12 caracteres son obligatorios');
 $record=db_update(function(&$db)use($name,$email,$password,$role,$site,$admin){
  if(user_exists_by_email($db,$email))fail('Ese correo ya existe',409);
  $uid=id();$u=['id'=>$uid,'name'=>$name,'email'=>$email,'password'=>password_hash($password,PASSWORD_DEFAULT),'role'=>$role,'site'=>$site,'created'=>now()];$db['users'][$uid]=$u;record_audit($db,'user_created',$admin['id'],$uid);return safe_user($u);
 });send($record,201);
case 'reset_password':
 $admin=require_admin();$b=json_input();$uid=clean_text($b['user_id']??'',60);$pwd=(string)($b['password']??'');if(strlen($pwd)<12)fail('Contraseña demasiado corta');
 db_update(function(&$db)use($uid,$pwd,$admin){if(!isset($db['users'][$uid]))fail('Usuario no existe',404);$db['users'][$uid]['password']=password_hash($pwd,PASSWORD_DEFAULT);record_audit($db,'password_reset',$admin['id'],$uid);});send(['ok'=>true]);
case 'change_password':
 $u=require_user();$b=json_input();$old=(string)($b['old']??'');$new=(string)($b['password']??'');if(strlen($new)<12)fail('La nueva contraseña debe tener mínimo 12 caracteres');
 db_update(function(&$db)use($u,$old,$new){if(!password_verify($old,$db['users'][$u['id']]['password']))fail('La contraseña actual es incorrecta',403);$db['users'][$u['id']]['password']=password_hash($new,PASSWORD_DEFAULT);});send(['ok'=>true]);
case 'overview':
 $u=require_user();$db=db_read();$target=$u['role']==='admin'&&isset($_GET['user'])?clean_text($_GET['user'],60):$u['id'];if(!isset($db['users'][$target]))fail('Docente inexistente',404);
 send(['user'=>safe_user($db['users'][$target]),'progress'=>progress_for($db,$target),'modules'=>$db['modules'][$target]??[],'submissions'=>array_values(array_filter($db['submissions'],fn($s)=>$s['user_id']===$target)),'evaluation'=>$db['evaluations'][$target]??null]);
case 'save_module':
 $u=require_user();$b=json_input();$type=(string)($b['type']??'');
 if($type==='diagnosis'){$text=clean_text($b['text']??'',12000);db_update(function(&$db)use($u,$text){$d=&$db['modules'][$u['id']]['diagnosis'];$d??=['activity'=>''];$d['activity']=$text;$d['updated']=now();});send(['ok'=>true]);}
 $i=(int)($b['module']??-1);if($i<0||$i>5)fail('Módulo inválido');
 db_update(function(&$db)use($i,$type,$u,$b){
  $d=&$db['modules'][$u['id']][(string)$i];$d??=['read'=>[],'activity'=>'','reflection'=>false,'attempts'=>0,'score'=>null];
  if($type==='read'){$n=(int)($b['lesson']??-1);if($n<0||$n>2)fail('Lección inválida');$read=$d['read']??[];$read=array_values(array_diff($read,[$n]));if(($b['checked']??false)===true)$read[]=$n;$d['read']=$read;}
  elseif($type==='activity'){$d['activity']=clean_text($b['text']??'',12000);}
  elseif($type==='reflection'){$d['reflection']=($b['checked']??false)===true;}
  elseif($type==='quiz'){
   $answers=$b['answers']??null;if(!is_array($answers)||count($answers)!==3)fail('Responda tres preguntas');
   $keys=QUIZ_KEYS[$i]??[];$score=0;foreach($keys as $k=>$v){if(!is_int($answers[$k])||$answers[$k]<0||$answers[$k]>2)fail('Respuesta no válida');if($answers[$k]===$v)$score++;}
   $d['score']=max((int)($d['score']??0),$score);$d['last_score']=$score;$d['attempts']=($d['attempts']??0)+1;
  }else fail('Operación inválida');
  $d['updated']=now();
 });send(['ok'=>true]);
case 'resource_list':
 require_user();$db=db_read();send(array_values($db['resources']));
case 'resource_upload':
 $u=require_admin();$title=clean_text($_POST['title']??'',150);$desc=clean_text($_POST['description']??'',1500);$mod=clean_text($_POST['module']??'all',8);if($title===''||!($mod==='all'||in_array($mod,['0','1','2','3','4','5'],true)))fail('Datos inválidos');
 $file=file_record($_FILES['file']??[],$u['id'],'resource');
 $r=['id'=>id(),'title'=>$title,'description'=>$desc,'module'=>$mod,'file'=>$file,'created'=>now()];
 db_update(function(&$db)use($r,$u){$db['resources'][$r['id']]=$r;record_audit($db,'resource_upload',$u['id'],$r['id']);});send($r,201);
case 'resource_delete':
 $u=require_admin();$b=json_input();$rid=clean_text($b['id']??'',60);$stored=db_update(function(&$db)use($rid,$u){if(!isset($db['resources'][$rid]))fail('Recurso no encontrado',404);$stored=$db['resources'][$rid]['file']['stored'];unset($db['resources'][$rid]);record_audit($db,'resource_delete',$u['id'],$rid);return $stored;});@unlink(PRIVATE_DIR.'/uploads/'.$stored);send(['ok'=>true]);
case 'submission_upload':
 $u=require_user();$i=(int)($_POST['module']??-1);if($i<0||$i>6)fail('Módulo inválido');
 $file=file_record($_FILES['file']??[],$u['id'],'submission');
 $sub=['id'=>id(),'user_id'=>$u['id'],'module'=>$i,'files'=>[$file],'created'=>now()];
 db_update(function(&$db)use($sub,$u){$db['submissions'][$sub['id']]=$sub;record_audit($db,'submission_upload',$u['id'],$sub['id']);});send($sub,201);
case 'activity_evidence_upload':
 $u=require_user();$aid=clean_text($_POST['activity_id']??'',60);if(!$aid||!isset($db_read()['activities'][$aid]))fail('Actividad no encontrada',404);$file=file_record($_FILES['file']??[],$u['id'],'submission');$sub=['id'=>id(),'activity_id'=>$aid,'user_id'=>$u['id'],'files'=>[$file],'created'=>now()];db_update(function(&$db)use($sub,$u){if(!isset($db['activities'][$sub['activity_id']])||!($db['activities'][$sub['activity_id']]['active']??true))fail('La actividad ya no está disponible',404);$db['activity_submissions'][$sub['id']]=$sub;record_audit($db,'activity_evidence_submitted',$u['id'],$sub['activity_id']);});send($sub,201);
case 'download':
 $u=require_user();$fid=clean_text($_GET['id']??'',60);$db=db_read();$files=all_files($db);$file=$files[$fid]??null;if(!$file)fail('Archivo no encontrado',404);
 if($u['role']!=='admin'&&$file['kind']!=='resource'&&$file['owner']!==$u['id'])fail('Acceso denegado',403);
 $path=PRIVATE_DIR.'/uploads/'.$file['stored'];if(!is_file($path))fail('Archivo extraviado',404);
 header('Content-Type: application/octet-stream');header('Content-Disposition: attachment; filename="'.preg_replace('/[^A-Za-z0-9._-]/','_', $file['name']).'"');header('Content-Length: '.filesize($path));header('X-Content-Type-Options: nosniff');header('Cache-Control: private, no-store');readfile($path);exit;
case 'community':
 require_user();$db=db_read();send(array_values($db['community']));
case 'community_post':
 $u=require_user();$b=json_input();$message=clean_text($b['text']??'',2000);if(!$message)fail('Escriba una publicación');
 db_update(function(&$db)use($u,$message){$db['community'][]=['id'=>id(),'author'=>$u['name'],'user_id'=>$u['id'],'text'=>$message,'created'=>now()];});send(['ok'=>true]);
case 'evaluation':
 $u=require_user();$db=db_read();send($db['evaluations'][$u['id']]??[]);
case 'evaluation_save':
 $u=require_user();$b=json_input();$ratings=$b['ratings']??null;if(!is_array($ratings)||count($ratings)!==10)fail('Se necesitan diez valoraciones');foreach($ratings as $r)if(!is_int($r)||$r<1||$r>5)fail('Valoración inválida');$comments=clean_text($b['comments']??'',2000);
 db_update(function(&$db)use($u,$ratings,$comments){$db['evaluations'][$u['id']]=['ratings'=>$ratings,'comments'=>$comments,'updated'=>now()];});send(['ok'=>true]);
case 'backup':
 require_admin();if($method!=='GET')fail('Método inválido',405);
 $db=db_read();$temp=sys_get_temp_dir().'/ruraltech_'.id().'.tar';$phar=new PharData($temp);
 $phar->addFromString('data.json',json_encode($db,JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT));
 foreach(all_files($db) as $f){$path=PRIVATE_DIR.'/uploads/'.$f['stored'];if(is_file($path))$phar->addFile($path,'uploads/'.$f['stored']);}
 header('Content-Type: application/x-tar');header('Content-Disposition: attachment; filename="RuralTech_respaldo_'.gmdate('Ymd_His').'.tar"');header('Content-Length: '.filesize($temp));header('Cache-Control: no-store');readfile($temp);@unlink($temp);exit;
case 'activity_list':
 $u=require_user();$db=db_read();$activities=[];foreach($db['activities'] as $activity){$item=$activity;$item['submissions']=[];foreach(($db['activity_submissions']??[]) as $submission){if(($submission['activity_id']??'')!==$activity['id'])continue;$owner=$db['users'][$submission['user_id']]??null;if(!$owner||($u['role']!=='admin'&&$owner['id']!==$u['id']))continue;$item['submissions'][]=$u['role']==='admin'?['id'=>$submission['id'],'created'=>$submission['created'],'user'=>safe_user($owner),'files'=>$submission['files']]:['id'=>$submission['id'],'created'=>$submission['created'],'files'=>$submission['files']];}if($u['role']!=='admin'&&!($activity['active']??true)&&!$item['submissions'])continue;$activities[]=$item;}usort($activities,fn($a,$b)=>strcmp($b['created']??'',$a['created']??''));send($activities);
case 'activity_create':
 $admin=require_admin();if($method!=='POST')fail('Método inválido',405);$b=json_input();$title=clean_text($b['title']??'',150);$description=clean_text($b['description']??'',6000);$due=clean_text($b['due']??'',10);if($title===''||$description==='')fail('Título e instrucciones son obligatorios');if($due!==''){$date=DateTimeImmutable::createFromFormat('!Y-m-d',$due);if(!$date||$date->format('Y-m-d')!==$due)fail('Fecha límite no válida');}$activity=['id'=>id(),'title'=>$title,'description'=>$description,'due'=>$due,'active'=>true,'created'=>now(),'created_by'=>$admin['id']];db_update(function(&$db)use($activity,$admin){$db['activities'][$activity['id']]=$activity;record_audit($db,'activity_published',$admin['id'],$activity['id']);});send($activity,201);
case 'activity_archive':
 $admin=require_admin();if($method!=='POST')fail('Método inválido',405);$b=json_input();$activityId=clean_text($b['id']??'',60);db_update(function(&$db)use($activityId,$admin){if(!isset($db['activities'][$activityId]))fail('Actividad no encontrada',404);$db['activities'][$activityId]['active']=false;record_audit($db,'activity_archived',$admin['id'],$activityId);});send(['ok'=>true]);
case 'update_user':
 $admin=require_admin();$b=json_input();$uid=clean_text($b['user_id']??'',60);$name=clean_text($b['name']??'',80);$email=email_norm(clean_text($b['email']??'',150));$role=(string)($b['role']??'teacher');if(!in_array($role,['admin','teacher'],true))fail('Rol no válido');$site=clean_text($b['site']??'',100);$password=(string)($b['password']??'');if(!$name||!filter_var($email,FILTER_VALIDATE_EMAIL))fail('Nombre y correo válidos son obligatorios');if($password!==''&&(strlen($password)<12||strlen($password)>200))fail('La nueva contraseña debe tener al menos 12 caracteres');db_update(function(&$db)use($uid,$name,$email,$role,$site,$password,$admin){if(!isset($db['users'][$uid]))fail('Usuario no existe',404);$user=$db['users'][$uid];if(($user['role']?? '')==='admin'&&$role!=='admin'){$admins=array_filter($db['users'],fn($u)=>($u['role']?? '')==='admin');if(count($admins)<=1)fail('No se puede quitar el rol al último administrador',403);}foreach($db['users'] as $oid=>$other){if($oid!==$uid&&($other['email']?? '')===$email)fail('Ese correo ya pertenece a otro usuario',409);}$user['name']=$name;$user['email']=$email;$user['role']=$role;$user['site']=$site;if($password!== ''){$user['password']=password_hash($password,PASSWORD_DEFAULT);}record_audit($db,'user_updated',$admin['id'],$uid.' rol '.$role);});send(['ok'=>true]);
case 'delete_user':
 $admin=require_admin();$b=json_input();$uid=clean_text($b['user_id']??'',60);if($uid===$admin['id'])fail('No puedes eliminar tu propia cuenta',403);$stored=db_update(function(&$db)use($uid,$admin){if(!isset($db['users'][$uid]))fail('Usuario no existe',404);$user=$db['users'][$uid];if(($user['role']?? '')==='admin'){$admins=array_filter($db['users'],fn($u)=>($u['role']?? '')==='admin');if(count($admins)<=1)fail('No se puede eliminar al último administrador',403);}$stored=[];foreach($db['submissions'] as $sid=>$s){if(($s['user_id']?? '')!==$uid)continue;foreach(($s['files']??[]) as $f)$stored[]=$f['stored'];unset($db['submissions'][$sid]);}foreach(($db['activity_submissions']??[]) as $sid=>$s){if(($s['user_id']?? '')!==$uid)continue;foreach(($s['files']??[]) as $f)$stored[]=$f['stored'];unset($db['activity_submissions'][$sid]);}unset($db['modules'][$uid],$db['evaluations'][$uid]);unset($db['users'][$uid]);record_audit($db,'user_deleted',$admin['id'],substr((string)($user['name']?? ''),0,80));return array_values(array_unique($stored));});foreach($stored as $s){@unlink(PRIVATE_DIR.'/uploads/'.$s);}send(['ok'=>true]);
case 'evaluations':
 require_admin();$db=db_read();$out=[];foreach($db['evaluations'] as $uid=>$e){$usr=$db['users'][$uid]??null;if($usr)$out[]=['name'=>$usr['name'],'email'=>$usr['email'],'role'=>$usr['role'],'site'=>$usr['site']??'']+$e;}send($out);
case 'audit':require_admin();$db=db_read();send(array_slice($db['audit'],-100));
default:fail('Acción no encontrada',404);
}
}catch(Throwable $e){error_log('RuralTech error: '.$e->getMessage());fail('Error interno del servidor',500);}
