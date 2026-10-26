<?php
declare(strict_types=1);
namespace MiniErp\Transport;

use DOMDocument;
use DOMXPath;
use InvalidArgumentException;
use NFePHP\CTe\MakeCTe;
use NFePHP\DA\CTe\Dacte;

final class CteXmlService
{
    public const NS='http://www.portalfiscal.inf.br/cte';
    public const SCHEMA='PL_CTe_400_NT2025.001 RTC_1.04b';
    public function parse(string $xml): DOMDocument
    {
        if(strlen($xml)>2*1024*1024 || trim($xml)==='' || str_contains($xml,"\0") || preg_match('/<!\s*(DOCTYPE|ENTITY)/i',$xml)) {
            throw new InvalidArgumentException('Envie um XML UTF-8 de CT-e de até 2 MB, sem DTD ou entidades externas.');
        }
        $previous=libxml_use_internal_errors(true);
        try {
            $dom=new DOMDocument();
            if(!$dom->loadXML($xml,LIBXML_NONET|LIBXML_NOBLANKS))throw new InvalidArgumentException('O arquivo não contém XML bem formado.');
            $root=$dom->documentElement;
            if(!$root || $root->namespaceURI!==self::NS || !in_array($root->localName,['CTe','cteProc'],true))throw new InvalidArgumentException('Envie CTe ou cteProc, modelo 57, versão 4.00.');
            $xp=new DOMXPath($dom);$xp->registerNamespace('c',self::NS);
            $base=$root->localName==='CTe'?'/c:CTe':'/c:cteProc/c:CTe';
            if($xp->query($base)->length!==1 || $xp->query('//c:infCte')->length!==1 || $xp->evaluate('string('.$base.'/c:infCte/@versao)')!=='4.00' || $xp->evaluate('string('.$base.'/c:infCte/c:ide/c:mod)')!=='57')throw new InvalidArgumentException('O arquivo deve conter exatamente um CT-e modelo 57, versão 4.00.');
            $key=$xp->evaluate('string('.$base.'/c:infCte/@Id)');
            if(!str_starts_with($key,'CTe')||!TransportDocumentValidator::validAccessKey(substr($key,3))||substr($key,23,2)!=='57')throw new InvalidArgumentException('Chave de acesso do CT-e inválida.');
            return $dom;
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    public function inspect(string $xml): array
    {
        $dom=$this->parse($xml);$xp=new DOMXPath($dom);$xp->registerNamespace('c',self::NS);
        $prefix=$dom->documentElement->localName==='CTe'?'/c:CTe/c:infCte':'/c:cteProc/c:CTe/c:infCte';
        $get=static fn(string $p)=>(string)$xp->evaluate('string('.$prefix.'/'.$p.')');
        $key=substr($get('@Id'),3);
        $protocol=(string)$xp->evaluate('string(/c:cteProc/c:protCTe/c:infProt/c:nProt)');
        $protocolKey=(string)$xp->evaluate('string(/c:cteProc/c:protCTe/c:infProt/c:chCTe)');
        if($protocolKey!==''&&$protocolKey!==$key)throw new InvalidArgumentException('O protocolo pertence a outra chave de CT-e.');
        $schemaRoot=dirname(__DIR__,2).'/vendor/nfephp-org/sped-cte/schemes/'.self::SCHEMA.'/';
        $schema=$schemaRoot.($dom->documentElement->localName==='cteProc'?'procCTe_v4.00.xsd':'cte_v4.00.xsd');
        $previous=libxml_use_internal_errors(true);
        try {
            $valid=$dom->schemaValidate($schema,LIBXML_NONET);
            $errors=array_values(array_unique(array_map(static fn($e)=>trim($e->message),libxml_get_errors())));
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
        $unsigned=$this->validateUnsigned($dom,$schemaRoot);
        $parties=[];
        foreach(['emit','rem','dest','exped','receb','ide/c:toma4'] as $party)foreach(['CNPJ','CPF'] as $taxTag){$value=$get('c:'.$party.'/c:'.$taxTag);if($value!=='')$parties[]=$value;}
        return ['key'=>$key,'number'=>$get('c:ide/c:nCT'),'series'=>$get('c:ide/c:serie'),'environment'=>$get('c:ide/c:tpAmb'),
            'issuer_tax_id'=>$get('c:emit/c:CNPJ')?:$get('c:emit/c:CPF'),'issuer_name'=>$get('c:emit/c:xNome'),
            'recipient_name'=>$get('c:dest/c:xNome'),'service_value'=>$get('c:vPrest/c:vTPrest'),
            'protocol'=>$protocol,'protocol_status'=>(string)$xp->evaluate('string(/c:cteProc/c:protCTe/c:infProt/c:cStat)'),
            'schema'=>self::SCHEMA,'xsd_valid'=>$valid,'xsd_errors'=>array_slice($errors,0,30),
            'unsigned_xsd_valid'=>$unsigned['valid'],'unsigned_xsd_errors'=>$unsigned['errors'],'party_tax_ids'=>array_values(array_unique($parties)),
            'signature_present'=>$dom->getElementsByTagNameNS('http://www.w3.org/2000/09/xmldsig#','Signature')->length>0,
            'signature_validated'=>false,'sefaz_authorized'=>false,'sha256'=>hash('sha256',$xml)];
    }
    private function validateUnsigned(DOMDocument $document,string $schemaRoot): array
    {
        // Validate the pre-signing structure separately. Never report this as full XSD validation.
        $previous=libxml_use_internal_errors(true);
        try {
            $schema=new DOMDocument();$schema->load($schemaRoot.'cteTiposBasico_v4.00.xsd',LIBXML_NONET);
            $xp=new DOMXPath($schema);$xp->registerNamespace('xs','http://www.w3.org/2001/XMLSchema');
            foreach($xp->query('//*[@schemaLocation]') as $node){
                $path=str_replace('\\','/',realpath($schemaRoot.$node->getAttribute('schemaLocation')));
                $node->setAttribute('schemaLocation','file:///'.ltrim(str_replace(' ','%20',$path),'/'));
            }
            foreach($xp->query('/xs:schema/xs:complexType[@name="TCTe"]/xs:sequence/xs:element[@ref="ds:Signature"]') as $node)$node->setAttribute('minOccurs','0');
            $root=$schema->createElementNS('http://www.w3.org/2001/XMLSchema','xs:element');$root->setAttribute('name','CTe');$root->setAttribute('type','TCTe');$schema->documentElement->appendChild($root);
            $cte=new DOMDocument();$cte->appendChild($cte->importNode($document->getElementsByTagNameNS(self::NS,'CTe')->item(0),true));
            $valid=$cte->schemaValidateSource($schema->saveXML(),LIBXML_NONET);
            return ['valid'=>$valid,'errors'=>array_slice(array_values(array_unique(array_map(static fn($e)=>trim($e->message),libxml_get_errors()))),0,30)];
        } finally {libxml_clear_errors();libxml_use_internal_errors($previous);}
    }
    public function generate(array $document): array
    {
        $d=$document['payload'];$s=$document['snapshot'];
        if(($d['model']??'')!=='57')throw new InvalidArgumentException('Geração disponível somente para CT-e.');
        $validation=(new TransportDocumentValidator())->validate($d,$s);
        if($validation['errors'])throw new InvalidArgumentException('Complete os dados antes de gerar: '.implode(' ',array_values($validation['errors'])));
        // Do not silently discard taxation that this pinned library cannot serialize.
        foreach(['ibs_cbs_cst','tax_classification','ibs_state_rate','ibs_city_rate','cbs_rate'] as $field)if(($d[$field]??'')!=='')throw new InvalidArgumentException('XML com IBS/CBS requer a próxima integração RTC. Os valores informados foram preservados; não serão omitidos do XML silenciosamente.');
        if($d['icms_cst']==='60')throw new InvalidArgumentException('CST 60 requer base e alíquota de retenção ainda não disponíveis neste formulário.');
        if(($d['payer_role']??'0')==='4')throw new InvalidArgumentException('Geração com outro tomador ainda não está disponível. Selecione um participante da prestação.');
        $emit=$s['establishment'];$m=new MakeCTe();$tag=static function(string $name,array $values)use($m):void{$m->$name((object)$values);};
        $digits=static fn($v)=>preg_replace('/\D/','',(string)$v);
        $money=static fn($v)=>number_format((float)$v,2,'.','');
        $date=new \DateTimeImmutable($d['issue_at'],new \DateTimeZone('America/Sao_Paulo'));
        $number=max(1,(int)$document['id']);if($number>999999999)throw new InvalidArgumentException('Identificação interna excede o limite de prévia.');
        $tag('taginfCTe',['Id'=>'','versao'=>'4.00']);
        $payerKey=['0'=>'sender_id','1'=>'dispatcher_id','2'=>'receiver_id','3'=>'recipient_id'][$d['payer_role']];
        $payer=$s[$payerKey];
        $tag('tagide',['cUF'=>substr($emit['city_ibge_code'],0,2),'cCT'=>str_pad((string)(hexdec(substr(hash('sha256',$document['id'].':'.$document['revision']),0,6))%99999999),8,'0',STR_PAD_LEFT),'CFOP'=>$d['cfop'],'natOp'=>$d['operation_nature'],'serie'=>$d['series'],'nCT'=>$number,'dhEmi'=>$date->format('Y-m-d\TH:i:sP'),'tpImp'=>'1','tpEmis'=>'1','cDV'=>'0','tpAmb'=>'2','tpCTe'=>'0','procEmi'=>'0','verProc'=>'MiniERP-preview-1','cMunEnv'=>$emit['city_ibge_code'],'xMunEnv'=>$emit['city_name'],'UFEnv'=>$emit['state'],'modal'=>'01','tpServ'=>'0','cMunIni'=>$d['origin_city_code'],'xMunIni'=>$d['origin_city_name'],'UFIni'=>$d['origin_state'],'cMunFim'=>$d['destination_city_code'],'xMunFim'=>$d['destination_city_name'],'UFFim'=>$d['destination_state'],'retira'=>'1','indIEToma'=>empty($payer['inscricao_estadual'])?'9':'1']);
        $tag('tagtoma3',['toma'=>$d['payer_role']]);
        $tag('tagcompl',['xObs'=>'PREVIA DE TESTE - SEM VALOR FISCAL. NUMERACAO INTERNA, SEM RESERVA FISCAL. '.mb_substr($d['notes'],0,1700)]);
        $tax=$digits($emit['tax_id']);
        $tag('tagemit',[(strlen($tax)===14?'CNPJ':'CPF')=>$tax,'IE'=>$digits($emit['state_registration']),'xNome'=>$emit['legal_name'],'CRT'=>$emit['tax_regime_code']]);
        $tag('tagenderEmit',['xLgr'=>$emit['street'],'nro'=>$emit['number'],'xBairro'=>$emit['district'],'cMun'=>$emit['city_ibge_code'],'xMun'=>$emit['city_name'],'CEP'=>$digits($emit['postal_code']),'UF'=>$emit['state']]);
        foreach(['sender_id'=>['tagrem','tagenderReme'],'recipient_id'=>['tagdest','tagenderDest'],'dispatcher_id'=>['tagexped','tagenderExped'],'receiver_id'=>['tagreceb','tagenderReceb']] as $field=>[$personTag,$addressTag]) {
            if(empty($s[$field]))continue;$p=$s[$field];$tax=$digits($p['cpf_cnpj']);
            $tag($personTag,[(strlen($tax)===14?'CNPJ':'CPF')=>$tax,'IE'=>$p['inscricao_estadual']??'','xNome'=>$field==='recipient_id'?'CT-E EMITIDO EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL':$p['nome']]);
            $tag($addressTag,['xLgr'=>$p['logradouro'],'nro'=>$p['numero'],'xBairro'=>$p['bairro'],'cMun'=>$p['codigo_ibge'],'xMun'=>$p['municipio']??$p['cidade']??'','CEP'=>$digits($p['cep']),'UF'=>$p['uf'],'cPais'=>'1058','xPais'=>'BRASIL']);
        }
        $tag('tagvPrest',['vTPrest'=>$money($d['service_value']),'vRec'=>$money($d['receivable_value'])]);
        foreach(TransportDocumentValidator::lines($d['service_components']) as $line){[$name,$value]=array_map('trim',explode(';',$line,2));$tag('tagComp',['xNome'=>$name,'vComp'=>$money(str_replace(',','.',$value))]);}
        $tag('tagicms',['cst'=>$d['icms_cst'],'vBC'=>$money($d['icms_base']?:0),'pICMS'=>$money($d['icms_rate']?:0),'vICMS'=>$validation['calculated_icms']??'0.00','pRedBC'=>$money($d['icms_reduction']?:0),'cBenef'=>$d['tax_benefit'],'infAdFisco'=>$d['fiscal_notes']]);
        $m->taginfCTeNorm();$tag('taginfCarga',['vCarga'=>$money($d['cargo_value']),'proPred'=>$d['cargo_product']]);
        $tag('taginfQ',['cUnid'=>'01','tpMed'=>'PESO BRUTO','qCarga'=>number_format((float)$d['cargo_weight'],4,'.','')]);
        if($d['cargo_quantity']!=='')$tag('taginfQ',['cUnid'=>'03','tpMed'=>$d['cargo_measure']?:'VOLUMES','qCarga'=>number_format((float)$d['cargo_quantity'],4,'.','')]);
        foreach(TransportDocumentValidator::lines($d['document_keys']) as $key)$tag('taginfNFe',['chave'=>$key]);
        $tag('taginfModal',['versaoModal'=>'4.00']);$tag('tagrodo',['RNTRC'=>$d['rntrc']]);
        try {$xml=$m->getXML();}catch(\Throwable $e){throw new InvalidArgumentException('Não foi possível montar o CT-e: '.implode(' ',array_map('strval',$m->getErrors())),0,$e);}
        $metadata=$this->inspect($xml);
        if(!$metadata['unsigned_xsd_valid'])throw new InvalidArgumentException('Revise os campos do CT-e. Estrutura XML: '.implode(' ',$metadata['unsigned_xsd_errors']));
        return ['xml'=>$xml,'metadata'=>$metadata,'origin'=>'GENERATED_PREVIEW','created_at'=>date(DATE_ATOM)];
    }
    public function pdf(string $xml): string
    {
        $this->parse($xml);
        // Imported protocols are unverified: always render as a preview, never certify authorization.
        $dacte=new class($xml) extends Dacte {
            protected function statusCTe(){return ['status'=>false,'message'=>['PRE-VISUALIZACAO','SEM VALIDACAO DE AUTORIZACAO'],'submessage'=>''];}
        };
        $dacte->creditsIntegratorFooter('MiniERP - previa de CT-e',false);
        $pdf=$dacte->render();
        if(!str_starts_with($pdf,'%PDF-'))throw new \RuntimeException('Falha ao renderizar DACTE.');
        return $pdf;
    }
}
