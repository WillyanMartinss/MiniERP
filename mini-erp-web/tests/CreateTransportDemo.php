<?php
declare(strict_types=1);
// Explicit opt-in: creates a persistent demonstration requested by the operator.
if(getenv('CREATE_TRANSPORT_DEMO')!=='1')exit("Set CREATE_TRANSPORT_DEMO=1 to create the demonstration.\n");
require __DIR__.'/../vendor/autoload.php';
use MiniErp\Transport\{TransportRepository,TransportFields,TransportDocumentValidator};
$cfg=require __DIR__.'/../config.php';$d=$cfg['db'];
$pdo=new PDO(sprintf('mysql:host=%s;port=%s;dbname=mini_erp_tenant_14;charset=utf8mb4',$d['host'],$d['port']),$d['username'],$d['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$repo=new TransportRepository($pdo,14);$options=$repo->options();
$people=$options['person'];
$input=['title'=>'TESTE — CT-e demonstrativo — SEM VALOR FISCAL','series'=>'1','issue_at'=>date('Y-m-d\TH:i'),'origin_state'=>'SP','origin_city_code'=>'3550308','origin_city_name'=>'São Paulo','destination_state'=>'RJ','destination_city_code'=>'3304557','destination_city_name'=>'Rio de Janeiro','cargo_product'=>'Caixas de mercadoria — demonstração','cargo_value'=>'1000,00','cargo_weight'=>'100,00','cargo_quantity'=>'10','cargo_measure'=>'CAIXAS','cfop'=>'6353','operation_nature'=>'Prestação de transporte — TESTE','sender_id'=>(string)($people[0]['id']??''),'recipient_id'=>(string)($people[1]['id']??$people[0]['id']??''),'payer_role'=>'0','service_value'=>'150,00','receivable_value'=>'150,00','service_components'=>'Frete de teste;150,00','icms_cst'=>'00','icms_base'=>'150,00','icms_rate'=>'12','notes'=>'TESTE SEM VALOR FISCAL. Valores e percurso fictícios. Participantes reaproveitados apenas para demonstrar o formulário. Sem XML, assinatura, protocolo ou transmissão SEFAZ. RNTRC e chave NF-e devem ser informados com dados reais de homologação; não foram inventados.'];
$data=TransportFields::normalize('57',$input);$snapshot=$repo->snapshot($data);
$validation=(new TransportDocumentValidator())->validate($data,$snapshot);
$id=0;
foreach($repo->listing('57') as $row)if($row['title']===$data['title']){$id=(int)$row['id'];break;}
if(!$id)$id=$repo->save(0,0,$data,$snapshot,$validation,0);
$stored=$repo->find($id);$data=$stored['payload'];$validation=$stored['validation'];
$e=static fn($s)=>htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');
$rows=['Documento interno'=>'#'.$id,'Empresa'=>'Willyan Info','Percurso'=>$data['origin_city_name'].' / '.$data['origin_state'].' → '.$data['destination_city_name'].' / '.$data['destination_state'],'Carga'=>$data['cargo_product'],'Volumes'=>$data['cargo_quantity'],'Peso'=>'100 kg','Valor da carga'=>'R$ 1.000,00','Frete'=>'R$ 150,00','Prévia de ICMS'=>'R$ 18,00 (12%)'];
$html='<!doctype html><html lang="pt-BR"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>CT-e de teste #'.$id.'</title><style>body{font:16px system-ui;color:#18304d;background:#eef3f8;margin:0;padding:32px}main{max-width:760px;margin:auto;background:white;padding:36px;border-radius:16px}h1{color:#1763c0}aside{padding:18px;background:#fff3ce;border:1px solid #edbd45;border-radius:8px;font-weight:700}table{width:100%;border-collapse:collapse;margin:24px 0}th,td{text-align:left;padding:12px;border-bottom:1px solid #ddd}th{width:36%}li{margin:8px 0}small{color:#53657b}@media print{body{background:white;padding:0}main{padding:12px}}</style><main><h1>CT-e · demonstração</h1><aside>TESTE — SEM VALOR FISCAL<br><small>Prévia interna. Não é DACTE, XML fiscal ou documento autorizado.</small></aside><table>';
foreach($rows as $label=>$value)$html.='<tr><th>'.$e($label).'</th><td>'.$e($value).'</td></tr>';
$html.='</table><h2>Pendências da conferência local</h2><ul>';
foreach($validation['errors'] as $error)$html.='<li>'.$e($error).'</li>';
$html.='</ul><p>Valores e percurso fictícios para teste. Nenhum documento foi transmitido à SEFAZ. A conferência local não substitui validação fiscal.</p><p>Para imprimir, use Ctrl+P ou a opção Imprimir do navegador.</p></main></html>';
$dir=dirname(__DIR__,2).'/outputs';if(!is_dir($dir))mkdir($dir,0775,true);
$path=$dir.'/cte-teste-'.$id.'.html';if(file_put_contents($path,$html)===false)throw new RuntimeException('Preview write failed');
echo json_encode(['document_id'=>$id,'status'=>$stored['status'],'pending_count'=>count($validation['errors']),'preview'=>$path],JSON_UNESCAPED_SLASHES)."\n";
