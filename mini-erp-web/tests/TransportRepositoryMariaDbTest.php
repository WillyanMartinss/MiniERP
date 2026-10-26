<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use MiniErp\Transport\{CteXmlService,TransportRepository,TransportFields,TransportDocumentValidator};
if (getenv('RUN_TRANSPORT_DB_TESTS') !== '1') {echo "TransportRepositoryMariaDbTest SKIPPED\n";exit;}
$tenant=filter_var(getenv('TRANSPORT_TEST_TENANT_ID'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if (!$tenant) throw new RuntimeException('Set TRANSPORT_TEST_TENANT_ID.');
$cfg=require __DIR__.'/../config.php';$db=$cfg['db'];
$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=mini_erp_tenant_%d;charset=utf8mb4',$db['host'],$db['port'],$tenant),$db['username'],$db['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$repo=new TransportRepository($pdo,$tenant);$ids=[];$vehicle=0;
$check=static function(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);};
function transportRepoKey(string $model,string $cnpj='11222333000181'):string{
    $base=str_pad('352609'.$cnpj.$model,43,'0');$sum=0;$weight=2;
    for($i=42;$i>=0;$i--){$sum+=(int)$base[$i]*$weight;$weight=$weight===9?2:$weight+1;}
    $digit=11-$sum%11;return $base.($digit>=10?'0':$digit);
}
try {
    $check($repo->available(),'Migration installed');
    $repo->options();$repo->settings();
    try {new TransportRepository($pdo,$tenant+1);throw new LogicException('Wrong tenant accepted');} catch(RuntimeException $e) {if($e instanceof LogicException)throw $e;}
    // Random plate, exact IDs tracked for cleanup; existing records are never edited.
    $plate='T'.chr(random_int(65,90)).chr(random_int(65,90)).random_int(0,9).chr(random_int(65,90)).random_int(10,99);
    $v=['plate'=>$plate,'state'=>'SP','renavam'=>'12345678901','tare_kg'=>'1000','capacity_kg'=>'5000','wheel_type'=>'02','body_type'=>'00','ownership'=>'0'];
    $vehicle=$repo->saveVehicle($v);
    $repo->saveVehicle($v+['vehicle_record_id'=>$vehicle]);
    foreach(['57','58'] as $model) {
        $data=TransportFields::normalize($model,['title'=>'TEST_ONLY Transporte '.bin2hex(random_bytes(6)),'notes'=>'Edição com acentos e múltiplas linhas\nTeste','cargo_value'=>'1234,56']);
        if($model==='58')$data['vehicle_id']=(string)$vehicle;
        $snapshot=$repo->snapshot($data);
        $id=$repo->save(0,0,$data,$snapshot,null,0);$ids[]=$id;
        $row=$repo->find($id);
        $check($row['payload']===$data&&$row['snapshot']===$snapshot&&$row['status']==='DRAFT','Draft round trip '.$model);
        $data['notes'].=' alterado';
        $validation=['errors'=>['document_keys'=>'Obrigatório'],'scope'=>'LOCAL_DATA_ONLY','sefaz_authorized'=>false];
        $repo->save($id,1,$data,$snapshot,$validation,0);
        $row=$repo->find($id);
        $check($row['payload']===$data&&$row['validation']===$validation&&$row['status']==='INVALID','Validation round trip');
        try {$repo->save($id,1,$data,$snapshot,null,0);throw new LogicException('Stale revision accepted');} catch(RuntimeException $e) {if($e instanceof LogicException)throw $e;}
        $check(count($repo->history($id))===2,'Failed update must not write event');
        $repo->save($id,2,$data,$snapshot,null,0);
        $check($repo->find($id)['status']==='DRAFT','Editing resets validation');
        $check(in_array($id,array_map('intval',array_column($repo->listing($model),'id')),true),'Listing includes saved document');
    }
    $person=['nome'=>'TEST_ONLY','cpf_cnpj'=>'52998224725','logradouro'=>'Rua de teste','numero'=>'1','bairro'=>'Centro','uf'=>'SP','cep'=>'01001000','codigo_ibge'=>'3550308','municipio'=>'São Paulo'];
    $validCte=TransportFields::normalize('57',['title'=>'TEST_ONLY CT-e XML '.bin2hex(random_bytes(4)),'series'=>'1','issue_at'=>'2026-09-21T10:00','origin_state'=>'SP','origin_city_code'=>'3550308','origin_city_name'=>'São Paulo','destination_state'=>'RJ','destination_city_code'=>'3304557','destination_city_name'=>'Rio de Janeiro','cargo_product'=>'Carga de teste','cargo_value'=>'1000,00','cargo_weight'=>'10,500','cargo_quantity'=>'10','cargo_measure'=>'CAIXAS','document_keys'=>transportRepoKey('55'),'rntrc'=>'12345678','cfop'=>'6353','operation_nature'=>'Transporte','sender_id'=>'1','recipient_id'=>'2','payer_role'=>'0','service_value'=>'100,00','receivable_value'=>'100,00','service_components'=>'Frete;100,00','icms_cst'=>'00','icms_base'=>'100,00','icms_rate'=>'12','notes'=>'TEST_ONLY SEM VALOR FISCAL']);
    $validSnapshot=['sender_id'=>$person,'recipient_id'=>$person,'establishment'=>['tax_id'=>'11222333000181','legal_name'=>'TEST_ONLY','state_registration'=>'123','tax_regime_code'=>'3','street'=>'Rua de teste','number'=>'1','district'=>'Centro','city_ibge_code'=>'3550308','city_name'=>'São Paulo','state'=>'SP','postal_code'=>'01001000']];
    $id=$repo->save(0,0,$validCte,$validSnapshot,(new TransportDocumentValidator())->validate($validCte,$validSnapshot),0);$ids[]=$id;
    $repo->generateCte($id,1,0);
    $row=$repo->find($id);
    $check(isset($row['snapshot']['cte_artifact']['xml'])&&$row['status']==='VALIDATED_LOCAL','Generated CT-e artifact persisted');
    $check(count(array_filter($repo->history($id),static fn($e)=>$e['action']==='GENERATE_CTE_XML'))===1,'Generate event persisted');
    $service=new CteXmlService();
    $xml=$row['snapshot']['cte_artifact']['xml'];
    $establishment=$pdo->query('SELECT tax_id FROM establishments WHERE is_primary=1 ORDER BY id LIMIT 1')->fetchColumn();
    $importTax=preg_replace('/\D/','',(string)$establishment);
    $check(strlen($importTax)===14,'Tenant fiscal CNPJ available for import test');
    $importKey=transportRepoKey('57',$importTax);
    $xml=str_replace($row['snapshot']['cte_artifact']['metadata']['key'],$importKey,$xml);
    $xml=str_replace('11222333000181',$importTax,$xml);
    $imported=$repo->importCte($xml,0);$ids[]=$imported;
    $importedAgain=$repo->importCte($xml,0);
    $row=$repo->find($imported);
    $check($imported===$importedAgain&&$row['status']==='IMPORTED_XML'&&$row['snapshot']['cte_artifact']['metadata']['key']===$importKey,'Imported CT-e XML deduplicated and preserved');
    try{$repo->generateCte($imported,(int)$row['revision'],0);throw new LogicException('Imported XML regenerated');}catch(InvalidArgumentException $e){}
    $check(str_starts_with($service->pdf($row['snapshot']['cte_artifact']['xml']),'%PDF-'),'Imported XML renders DACTE preview');
    echo "TransportRepositoryMariaDbTest OK: CT-e, MDF-e, snapshots, fleet, revision, tenant guard\n";
} finally {
    foreach($ids as $id) {
        $pdo->prepare('DELETE FROM transport_document_events WHERE tenant_id=? AND document_id=?')->execute([$tenant,$id]);
        $pdo->prepare('DELETE FROM transport_documents WHERE tenant_id=? AND id=?')->execute([$tenant,$id]);
    }
    if($vehicle)$pdo->prepare('DELETE FROM transport_vehicles WHERE tenant_id=? AND id=?')->execute([$tenant,$vehicle]);
}
