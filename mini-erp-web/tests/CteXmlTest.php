<?php
declare(strict_types=1);
require __DIR__.'/TransportDocumentTest.php';
use MiniErp\Transport\CteXmlService;
$service=new CteXmlService();
$snapshot['sender_id']['municipio']='São Paulo';$snapshot['recipient_id']['municipio']='São Paulo';
$artifact=$service->generate(['id'=>123,'revision'=>1,'payload'=>$data,'snapshot'=>$snapshot]);
transportAssert(str_contains($artifact['xml'],'<CTe'),'CT-e generated');
transportAssert($artifact['metadata']['environment']==='2','Homologation only');
transportAssert(!$artifact['metadata']['sefaz_authorized'],'No authorization claim');
transportAssert($artifact['metadata']['unsigned_xsd_valid']===true,'Unsigned CT-e structure valid');
$signedErrors=implode(' ', $artifact['metadata']['xsd_errors']);
transportAssert(str_contains($signedErrors,'Signature')||str_contains($signedErrors,'infCTeSupl'),'Full XSD remains pending before signing');
$pdf=$service->pdf($artifact['xml']);
transportAssert(str_starts_with($pdf,'%PDF-')&&strlen($pdf)>3000,'DACTE PDF');
foreach(['<!DOCTYPE CTe [<!ENTITY x SYSTEM "file:///etc/passwd">]><CTe/>','<notCTe/>',str_repeat('x',2097153)] as $bad){try{$service->parse($bad);throw new RuntimeException('Unsafe XML accepted');}catch(InvalidArgumentException){}}
if(getenv('WRITE_CTE_TEST_ARTIFACTS')==='1'){
    $out=dirname(__DIR__,2).'/outputs';if(!is_dir($out))mkdir($out,0775,true);
    file_put_contents($out.'/cte-homologacao-teste.xml',$artifact['xml']);
    file_put_contents($out.'/dacte-homologacao-teste.pdf',$pdf);
}
echo "CteXmlTest OK\n";
