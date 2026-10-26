<?php
declare(strict_types=1);
require __DIR__.'/../vendor/autoload.php';
use MiniErp\Transport\TransportFields;
use MiniErp\Transport\TransportDocumentValidator;
function transportAssert(bool $ok,string $message): void {if(!$ok)throw new RuntimeException($message);}
function transportKey(string $model):string{
 $base=str_pad('35260911222333000181'.$model,43,'0');$sum=0;$weight=2;
 for($i=42;$i>=0;$i--){$sum+=(int)$base[$i]*$weight;$weight=$weight===9?2:$weight+1;}
 $digit=11-$sum%11;return $base.($digit>=10?'0':$digit);
}
$validator=new TransportDocumentValidator();
$input=['series'=>'1','issue_at'=>'2026-09-13T10:00','origin_state'=>'SP','origin_city_code'=>'3550308','origin_city_name'=>'São Paulo','destination_state'=>'RJ','destination_city_code'=>'3304557','destination_city_name'=>'Rio de Janeiro','cargo_product'=>'Carga de teste','cargo_value'=>'1000,00','cargo_weight'=>'10,500','document_keys'=>transportKey('55'),'rntrc'=>'12345678','cfop'=>'6353','operation_nature'=>'Transporte','sender_id'=>'1','recipient_id'=>'2','payer_role'=>'0','service_value'=>'100,00','receivable_value'=>'100,00','icms_cst'=>'00','icms_base'=>'100,00','icms_rate'=>'12','service_components'=>'Frete;100,00'];
$person=['nome'=>'TEST_ONLY','cpf_cnpj'=>'52998224725','logradouro'=>'Rua de teste','numero'=>'1','bairro'=>'Centro','uf'=>'SP','cep'=>'01001000','codigo_ibge'=>'3550308'];
$snapshot=['sender_id'=>$person,'recipient_id'=>$person,'establishment'=>['tax_id'=>'11222333000181','legal_name'=>'TEST_ONLY','state_registration'=>'123','tax_regime_code'=>'3','street'=>'Rua de teste','number'=>'1','district'=>'Centro','city_ibge_code'=>'3550308','city_name'=>'São Paulo','state'=>'SP','postal_code'=>'01001000']];
$data=TransportFields::normalize('57',$input);
$result=$validator->validate($data,$snapshot);
transportAssert($result['errors']===[],json_encode($result['errors']));
transportAssert($result['scope']==='LOCAL_DATA_ONLY'&&!$result['sefaz_authorized']&&!$result['xml_validated'],'Local validation cannot promote fiscal authorization');
transportAssert($data['cargo_value']==='1000.00'&&$data['cargo_weight']==='10.500','Brazilian decimals round trip');
transportAssert($result['calculated_icms']==='12.00','ICMS preview calculation');
$bad=$data;$bad['document_keys'].="\n".$bad['document_keys'];$bad['origin_city_code']='3304557';$bad['receivable_value']='200';
$errors=$validator->validate($bad,$snapshot)['errors'];
foreach(['document_keys','origin_city_code','receivable_value'] as $field)transportAssert(isset($errors[$field]),'Validation missing: '.$field);
$mdfe=TransportFields::normalize('58',$input+['vehicle_id'=>'3','driver_id'=>'4','departure_at'=>'2026-09-13T11:00']);$mdfe['document_keys']=transportKey('57');
$snapshot['vehicle_id']=['tare_kg'=>3000,'renavam'=>'12345678901'];$snapshot['driver_id']=['cpf'=>'52998224725','status'=>'ativo','vencimento_cnh'=>'2030-01-01'];
transportAssert($validator->validate($mdfe,$snapshot)['errors']===[], 'Valid base MDF-e data');
$mdfe['document_keys']=transportKey('55');transportAssert(isset($validator->validate($mdfe,$snapshot)['errors']['document_keys']),'MDF-e document model follows emitter type');
transportAssert(!TransportDocumentValidator::validTaxId('11111111111'),'Repeated tax IDs rejected');
transportAssert(!TransportDocumentValidator::validAccessKey(substr(transportKey('55'),0,43).'9'),'Invalid access-key check digit rejected');
try{TransportFields::normalize('55',[]);throw new RuntimeException('NF-e must not be accepted as transport');}catch(InvalidArgumentException){}
try{TransportFields::normalize('57',['sender_id'=>['1']]);throw new RuntimeException('Array injection');}catch(InvalidArgumentException){}
echo "TransportDocumentTest OK\n";
