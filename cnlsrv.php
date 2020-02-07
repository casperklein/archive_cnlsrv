#!/usr/bin/env php
<?php
require_once dirname(__FILE__).'/sockets.php';
class AES{
  public static function decrypt($data,$key,$iv=null){
    $iv=is_null($iv)?$key:$iv;
    $plain=openssl_decrypt($data,'aes-128-cbc',$key,OPENSSL_RAW_DATA|OPENSSL_NO_PADDING,$iv);
    return $plain;
  }
}
class cnlsrv extends \stdClass{
  const CNL_HOST='127.0.0.1';
  const CNL_PORT=9666;
  protected $socket=null;
  protected $reqMap=null;
  protected function initialize(){
    $this->reqMap=[
      '#^GET /jdcheck.js HTTP/1.1#'       =>function(&$client,$head,$body){
        $this->onJDCheck($client,$head,$body);
      },
      '#^POST /flash/addcrypted2 HTTP/1.1#'=>function(&$client,$head,$body){
        $this->onAddCrypted2($client,$head,$body);
      },
    ];
  }
  protected function createResponse($data=null){
    $response ="HTTP/1.1 200 OK\r\n";
    $response.="Connection: close\r\n";
    $response.="Server: cnlsrv\r\n";
    $response.="Content-Type: text/html\r\n";
    $response.="Content-Length: ".(is_null($data)?0:strlen($data))."\r\n\r\n";
    if(!empty($data)){
      $response.=$data;
    }
    return $response;
  }
  protected function onJDCheck(&$client,$head,$body){
    $response=$this->createResponse("jdownloader=true;\nvar version='9.581';\n");
    $client->write($response);
    return $client->close();
  }
  protected function outputTempFile(array $links,$dir='/tmp',$pre='cnlsrv_'){
    $fn=tempnam($dir,$pre);
    $nb=0;
    if(($fh=fopen($fn,"w"))===false){
      printf("# FAIL: unable to write to <{$fn}> :-/\n");
      return false;
    }
    foreach($links as $n=>$l){
      $nb+=fputs($fh,$l.PHP_EOL);
    }
    fclose($fh);
    printf("# LNK: %s [%d links, %d bytes]\n",$fn,count($links),$nb);
    return true;
  }
  protected function onAddCrypted2(&$client,$head,$body){
    $prepare=function($plain){
      $r=[];
      foreach(preg_split('/\x0D\x0A/',$plain)as$l){
        $l=trim($l);
        if(empty($l)){
          continue;
        }
        $r[]=trim($l);
      }
      return $r;
    };
    $x=(object)[];
    foreach(preg_split('#&#',$body[0])as$kv){
      list($k,$v)=preg_split('#=#',$kv,2);
      $k=trim($k);$v=trim(urldecode($v));
      switch($k){
        case 'jk':
          preg_match("#[0-9]{32}#",$v,$m);
          $x->{$k}=$m[0];
        break;
        default:
          $x->{$k}=$v;
        break;
      }
    }
    $plain=AES::decrypt(base64_decode($x->crypted),hex2bin($x->jk));
    $response=$this->createResponse();
    $client->write($response);
    $client->close();
    $this->outputTempFile($prepare($plain));
    return true;
  }
  protected function onRequest(&$client,$data){
    $tmp=preg_split('/\x0D\x0A\x0D\x0A/',$data);
    $head=preg_split('/\x0D\x0A/',$tmp[0]);
    $body=preg_split('/\x0D\x0A/',$tmp[1]);
    foreach($this->reqMap as $p=>$fn){
      if(((bool)preg_match($p,$head[0]))===true){
        return $fn($client,$head,$body);
      }
    }
    return $client->close();
  }
  public function __construct(){
    $this->initialize();
    $this->socket=new TCPServerSocket(cnlsrv::CNL_HOST,cnlsrv::CNL_PORT);
    $this->socket->setErrorFunction(function($error){
      printf("# TcpError:\n");
      print_r($error);
      printf("%s\n",str_repeat('-',80));
    });
    $this->socket->setup();
    $cnlsrv=&$this;
    $this->socket->setThreadFunction(function(TCPClientSocket $socket)use(&$cnlsrv){
      $alive=true;
      while($alive){
        usleep(100000);
        $in=null;
        $ln=0;
        if(($in=$socket->read(8192))===false){
          $alive=false;
          continue;
        }
        $ln=strlen($in);
        if($ln==0){
          $alive=false;
          continue;
        }
        $cnlsrv->onRequest($socket,$in);
      }
    });
  }
  function __destruct(){
    foreach($this as $k=>$v){
      if(!is_null($this->{$k})){
        $this->{$k}=(unset)$this->{$k};
      }
    }
  }
  function __invoke(){
    $this->socket->startListener();
  }
}
$cnlsrv=new cnlsrv();
$cnlsrv();