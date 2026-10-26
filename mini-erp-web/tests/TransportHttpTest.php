<?php
declare(strict_types=1);
if(getenv('RUN_TRANSPORT_HTTP_TESTS')!=='1'){echo "TransportHttpTest SKIPPED\n";exit;}
require __DIR__.'/../vendor/autoload.php';
$base=rtrim((string)getenv('TRANSPORT_TEST_BASE'),'/');
$slug=(string)getenv('TRANSPORT_TEST_SLUG');$tenant=(int)getenv('TRANSPORT_TEST_TENANT_ID');
if(!filter_var($base,FILTER_VALIDATE_URL)||!$slug||$tenant<1)throw new RuntimeException('Configure transport test URL, slug and tenant ID.');
$main=(new MiniErp\Infrastructure\ControlPlaneConnectionFactory(__DIR__.'/../config.php'))->create();
$cfg=require __DIR__.'/../config.php';$d=$cfg['db'];
$db=new PDO(sprintf('mysql:host=%s;port=%s;dbname=mini_erp_tenant_%d;charset=utf8mb4',$d['host'],$d['port'],$tenant),$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$email='transport-'.bin2hex(random_bytes(8)).'@example.test';$password=bin2hex(random_bytes(24));
$id=(new MiniErp\Repositories\PlatformAdminRepository($main))->create('TRANSPORT HTTP TEST_ONLY',$email,password_hash($password,PASSWORD_DEFAULT),'GLOBAL_TECH','TEST_ONLY');
$client=curl_init();curl_setopt_array($client,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_HEADER=>true,CURLOPT_FOLLOWLOCATION=>false,CURLOPT_COOKIEFILE=>'',CURLOPT_TIMEOUT=>25]);
$request=static function(string $path,?array $post=null)use($client,$base):array{
    curl_setopt($client,CURLOPT_URL,$base.$path);curl_setopt($client,CURLOPT_POST,$post!==null);
    if($post!==null)curl_setopt($client,CURLOPT_POSTFIELDS,http_build_query($post));else curl_setopt($client,CURLOPT_HTTPGET,true);
    $raw=curl_exec($client);if($raw===false)throw new RuntimeException('HTTP failed: '.curl_error($client));
    $n=curl_getinfo($client,CURLINFO_HEADER_SIZE);return [curl_getinfo($client,CURLINFO_RESPONSE_CODE),substr($raw,0,$n),substr($raw,$n)];
};
$assert=static function(bool $ok,string $label):void{if(!$ok)throw new RuntimeException($label);};
try {
    [$status,,$body]=$request('/login.php?empresa='.rawurlencode($slug));
    $assert($status===200&&(bool)preg_match('/<form id="loginForm".*?<\/form>/s',$body,$form),'Login form');
    $assert((bool)preg_match('/name="csrf_token" value="([^"]+)"/',$form[0],$token),'Login CSRF');
    [$status]=$request('/login.php?empresa='.rawurlencode($slug),['action'=>'tenant_login','csrf_token'=>$token[1],'email'=>$email,'senha'=>$password]);
    $assert($status===302,'Authenticated');
    foreach(['cte','mdfe','veiculos','configuracoes'] as $tab){
        [$status,,$body]=$request('/?page=transporte&tab='.$tab);
        $assert($status===200&&str_contains($body,'class="transport-module"')&&!preg_match('/Fatal error|Warning:|TRANSPORT_DATABASE_ERROR/',$body),'Render '.$tab);
    }
    foreach(['cte'=>'57','mdfe'=>'58'] as $tab=>$model){
        [$status,,$body]=$request('/?page=transporte&tab='.$tab.'&new=1');
        $assert((bool)preg_match('/name="action" value="transport_document_save".*?name="csrf_token" value="([^"]+)"/s',$body,$token),'Document form '.$tab);
        $post=['action'=>'transport_document_save','model'=>$model,'document_id'=>'0','revision'=>'0','csrf_token'=>$token[1],'title'=>'TEST_ONLY HTTP '.$tab,'notes'=>'Preservar edição e acentos','intent'=>'draft'];
        [$status,$headers]=$request('/?page=transporte&tab='.$tab.'&new=1',$post);
        $assert($status===303&&(bool)preg_match('/location:\s*(\?[^\r\n]+)/i',$headers,$location),'Save '.$tab);
        [$status,,$body]=$request('/'.$location[1]);
        $assert($status===200&&str_contains($body,'Preservar edição e acentos'),'Reload '.$tab);
        $assert((bool)preg_match('/name="document_id" value="(\d+)"/',$body,$doc),'Saved ID');
        $post['document_id']=$doc[1];$post['revision']='1';$post['intent']='validate';
        [$status,$headers]=$request('/?page=transporte&tab='.$tab.'&document='.$doc[1],$post);
        $assert($status===303,'Validate '.$tab);
        $row=(new MiniErp\Transport\TransportRepository($db,$tenant))->find((int)$doc[1]);
        $assert($row['status']==='INVALID'&&!$row['validation']['sefaz_authorized'],'Incomplete document stays unauthorized');
        $post['csrf_token']='invalid';
        [$status,,$body]=$request('/?page=transporte&tab='.$tab.'&document='.$doc[1],$post);
        $assert($status===200&&str_contains($body,'Sessão expirada'),'Reject bad CSRF');
    }
    echo "TransportHttpTest OK: four pages, CT-e/MDF-e save/reload/validation, CSRF\n";
} finally {
    $s=$db->prepare('SELECT id FROM transport_documents WHERE tenant_id=? AND created_by=?');$s->execute([$tenant,$id]);
    foreach($s->fetchAll(PDO::FETCH_COLUMN) as $doc){
        $db->prepare('DELETE FROM transport_document_events WHERE tenant_id=? AND document_id=?')->execute([$tenant,$doc]);
        $db->prepare('DELETE FROM transport_documents WHERE tenant_id=? AND id=? AND created_by=?')->execute([$tenant,$doc,$id]);
    }
    try{$request('/erp/logout.php');}catch(Throwable){}
    curl_close($client);
    $main->prepare('DELETE FROM platform_admin_audit_log WHERE admin_id=?')->execute([$id]);
    $main->prepare('DELETE FROM platform_admin_users WHERE id=?')->execute([$id]);
}
