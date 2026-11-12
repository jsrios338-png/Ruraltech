<?php
declare(strict_types=1);
const ROOT = __DIR__;
const PRIVATE_DIR = ROOT.'/private';
const DB_FILE = PRIVATE_DIR.'/data.json';
const MAX_UPLOAD = 15728640;
const EXTENSIONS = ['pdf','doc','docx','odt','jpg','jpeg','png','webp','txt','rtf','ppt','pptx','xls','xlsx'];
function initial_db():array {return ['users'=>[], 'modules'=>[], 'submissions'=>[], 'resources'=>[], 'community'=>[], 'evaluations'=>[], 'audit'=>[]];}
function db_read():array {
 $fp=fopen(DB_FILE,'c+'); if(!$fp)throw new RuntimeException('No se pudo abrir la base de datos');
 try {flock($fp,LOCK_SH);rewind($fp);$s=stream_get_contents($fp);flock($fp,LOCK_UN);}finally{fclose($fp);}
 $v=json_decode($s?:'',true);return is_array($v)?array_replace(initial_db(),$v):initial_db();
}
function db_update(callable $callback):mixed {
 $fp=fopen(DB_FILE,'c+');if(!$fp)throw new RuntimeException('No se pudo abrir la base de datos');
 try{
  if(!flock($fp,LOCK_EX))throw new RuntimeException('Bloqueo de datos falló');
  rewind($fp);$s=stream_get_contents($fp);$v=json_decode($s?:'',true);$db=is_array($v)?array_replace(initial_db(),$v):initial_db();
  $result=$callback($db);
  $new=json_encode($db,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
  rewind($fp);ftruncate($fp,0);fwrite($fp,$new);fflush($fp);flock($fp,LOCK_UN);return $result;
 }finally{fclose($fp);}
}
function id():string {return bin2hex(random_bytes(16));}
function now():string{return gmdate('c');}
function email_norm(string $s):string {return strtolower(trim($s));}
function safe_user(array $u):array{return ['id'=>$u['id'],'name'=>$u['name'],'email'=>$u['email'],'role'=>$u['role'],'site'=>$u['site'],'created'=>$u['created']];}
function json_input():array {
 if(str_contains($_SERVER['CONTENT_TYPE']??'','application/json')){
  $data=json_decode(file_get_contents('php://input'),true);if(!is_array($data))fail('JSON no válido',400);return $data;
 }return $_POST;
}
function fail(string $m,int $status=400):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');echo json_encode(['error'=>$m],JSON_UNESCAPED_UNICODE);exit;}
function send(mixed $v,int $status=200):never {http_response_code($status);header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');echo json_encode($v,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
function current_user():?array {
 $uid=$_SESSION['uid']??null;if(!$uid)return null;$db=db_read();return isset($db['users'][$uid])?safe_user($db['users'][$uid]):null;
}
function require_user():array{$u=current_user();if(!$u)fail('Debe iniciar sesión',401);return $u;}
function require_admin():array{$u=require_user();if($u['role']!=='admin')fail('Acceso reservado al administrador',403);return $u;}
function csrf():void {
 $token=$_SERVER['HTTP_X_CSRF_TOKEN']??'';
 if(!$token||!hash_equals($_SESSION['csrf']??'',(string)$token))fail('Token de protección no válido',403);
 $origin=$_SERVER['HTTP_ORIGIN']??null;
 if($origin){$o=parse_url($origin);$host=$_SERVER['HTTP_HOST']??'';if(!$o||($o['host']??'')!==explode(':',$host)[0])fail('Origen no autorizado',403);}
}
function clean_text(mixed $v,int $max=4000):string {
 if(!is_string($v))fail('Se esperaba texto');$s=trim($v);if(strlen($s)>$max)fail('Texto demasiado largo');return $s;
}
function user_exists_by_email(array $db,string $email):bool {foreach($db['users'] as $u)if($u['email']===$email)return true;return false;}
function record_audit(array &$db,string $action,string $actor,string $detail=''):void {$db['audit'][]=['time'=>now(),'actor'=>$actor,'action'=>$action,'detail'=>substr($detail,0,300)];if(count($db['audit'])>2000)$db['audit']=array_slice($db['audit'],-1500);}
function file_record(array $file,string $uid,string $kind):array {
 if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK)fail('Error al recibir el archivo');
 if(($file['size']??0)<1||$file['size']>MAX_UPLOAD)fail('Archivo vacío o mayor de 15 MB');
 $original=basename(str_replace('\\','/',(string)$file['name']));$original=preg_replace('/[\x00-\x1f\x7f]/u','',$original) ?: 'archivo';
 $ext=strtolower(pathinfo($original,PATHINFO_EXTENSION));if(!in_array($ext,EXTENSIONS,true))fail('Formato de archivo no permitido');
 $mime=(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])?:'application/octet-stream';
 $danger=['text/html','application/x-httpd-php','application/x-php','image/svg+xml'];if(in_array($mime,$danger,true))fail('Contenido no admitido');
 if(in_array($ext,['jpg','jpeg','png','webp'],true)&&!str_starts_with($mime,'image/'))fail('Imagen inválida');
 $id=id();$name=$id.'.bin';$path=PRIVATE_DIR.'/uploads/'.$name;
 if(!is_uploaded_file($file['tmp_name'])||!move_uploaded_file($file['tmp_name'],$path))fail('No se pudo guardar el archivo',500);
 chmod($path,0600);
 return ['id'=>$id,'name'=>substr($original,0,150),'stored'=>$name,'size'=>(int)$file['size'],'mime'=>$mime,'owner'=>$uid,'kind'=>$kind,'created'=>now()];
}
function all_files(array $db):array {
 $files=[];
 foreach($db['submissions'] as $s)foreach($s['files']??[] as $f)$files[$f['id']]=$f;
 foreach($db['resources'] as $r)if(isset($r['file']))$files[$r['file']['id']]=$r['file'];
 return $files;
}
function progress_for(array $db,string $uid):array {
 $mods=[];$points=0;
 for($i=0;$i<6;$i++){
  $d=$db['modules'][$uid][(string)$i]??[];
  $read=count(array_unique(array_filter($d['read']??[],fn($x)=>is_int($x)&&$x>=0&&$x<3)));
  $hasActivity=trim((string)($d['activity']??''))!=='';
  foreach($db['submissions'] as $s)if($s['user_id']===$uid&&$s['module']===$i&&count($s['files']??[])>0)$hasActivity=true;
  $passed=($d['score']??-1)>=2;
  $reflect=(bool)($d['reflection']??false);
  $score=min(3,$read)+(int)$hasActivity+(int)$passed+(int)$reflect;$points+=$score;
  $mods[]=['module'=>$i,'read'=>$read,'activity'=>$hasActivity,'score'=>$d['score']??null,'attempts'=>$d['attempts']??0,'reflection'=>$reflect,'milestones'=>$score,'percent'=>round($score/6*100),'done'=>$score===6];
 }
 return ['percent'=>round($points/36*100),'completed'=>count(array_filter($mods,fn($m)=>$m['done'])),'milestones'=>$points,'modules'=>$mods];
}
const QUIZ_KEYS = [[1,0,0],[1,0,1],[0,0,0],[0,0,0],[0,0,0],[0,0,0]];
