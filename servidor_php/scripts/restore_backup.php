<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(403);exit('Solo consola');}
require dirname(__DIR__).'/src.php';
if($argc!==3||$argv[2]!=='--confirm'){fwrite(STDERR,"Uso: php scripts/restore_backup.php /ruta/respaldo.tar --confirm\nADVERTENCIA: reemplaza base de datos y adjuntos. Detenga el servidor y conserve una copia previa.\n");exit(1);}
$path=realpath($argv[1]);if(!$path||!is_file($path))exit("Respaldo no encontrado\n");
try{
 $archive=new PharData($path);
 if(!isset($archive['data.json']))throw new RuntimeException('Falta data.json');
 $raw=$archive['data.json']->getContent();$db=json_decode($raw,true,512,JSON_THROW_ON_ERROR);
 foreach(['users','modules','submissions','resources','community','evaluations','audit'] as $key)if(!isset($db[$key])||!is_array($db[$key]))throw new RuntimeException('Datos incompletos: '.$key);
 $files=all_files($db);
 foreach($files as $f){
  $n=$f['stored']??'';
  if(!preg_match('/^[a-f0-9]{32}\.bin$/',$n))throw new RuntimeException('Nombre interno de archivo inválido');
  if(!isset($archive['uploads/'.$n]))throw new RuntimeException('Falta adjunto: '.$n);
 }
 $temp=PRIVATE_DIR.'/restore_'.id();mkdir($temp,0700);
 foreach($files as $f){$name=$f['stored'];$contents=$archive['uploads/'.$name]->getContent();if(strlen($contents)!==(int)$f['size'])throw new RuntimeException('Adjunto truncado: '.$name);file_put_contents($temp.'/'.$name,$contents);}
 $backup=PRIVATE_DIR.'/pre_restore_'.gmdate('Ymd_His').'.json';copy(DB_FILE,$backup);
 // Keep former files for rollback; move restored files into place and then replace DB under exclusive lock.
 foreach($files as $f){$n=$f['stored'];if(!rename($temp.'/'.$n,PRIVATE_DIR.'/uploads/'.$n))throw new RuntimeException('No se pudo restaurar '.$n);}
 @rmdir($temp);
 db_update(function(&$existing)use($db){$existing=$db;});
 echo "Restauración completada. Copia anterior de datos: $backup\n";
}catch(Throwable $e){fwrite(STDERR,"Error: ".$e->getMessage()."\n");exit(1);}
