<?php
declare(strict_types=1);

namespace MiniErp\Services;

use DateTimeImmutable;
use MiniErp\Fiscal\DecimalTaxCalculator;
use MiniErp\Fiscal\FiscalDocumentDTO;
use MiniErp\Fiscal\FiscalNfeXmlBuilder;
use MiniErp\Fiscal\FiscalTaxContext;
use MiniErp\Fiscal\NfeAccessKeyGenerator;
use MiniErp\Fiscal\TaxRuleResolver;
use MiniErp\Repositories\FiscalOperationRepository;
use PDO;

final class FiscalDanfePreviewService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly FiscalOperationRepository $operations,
        private readonly TaxRuleResolver $resolver,
        private readonly string $cacheRoot,
        private readonly string $spedDaVersion='1.1.6',
        private readonly ?FiscalPreviewModelResolver $modelResolver=null,
        private readonly ?FiscalDocumentPreviewRenderer $renderer=null,
        private readonly ?FiscalPreviewPreflightService $previewPreflight=null,
    ) {}

    public function render(int $orderId):array
    {
        if($orderId<1)throw new FiscalDanfeException('PREVIEW_ORDER_NOT_FOUND','Pedido não encontrado.');
        try{$order=$this->operations->orderWithTransport($orderId);}catch(\Throwable){throw new FiscalDanfeException('PREVIEW_ORDER_NOT_FOUND','Pedido não encontrado para esta empresa.');}
        $issuer=$this->one('SELECT * FROM establishments WHERE tenant_id=? AND id=?',[$this->operations->tenantId(),(int)$order['establishment_id']]);
        $recipient=$this->partyRow($order['operation_type']==='ENTRY'?'fornecedores':'clientes',(int)$order['person_id']);
        $preflight=$this->previewPreflight??new FiscalPreviewPreflightService();$preflight->assertCommercial($order,$issuer?:[],$recipient?:[]);
        $settings=$this->one('SELECT primary_model FROM establishment_fiscal_settings WHERE tenant_id=? AND establishment_id=?',[$this->operations->tenantId(),(int)$issuer['id']]);
        $modelResolution=($this->modelResolver??new FiscalPreviewModelResolver())->resolveWithSource($order['fiscal_model']??null,$settings['primary_model']??null);$model=$modelResolution['model'];$modelSource=$modelResolution['source'];

        $items=[];$warnings=[];$calculator=new DecimalTaxCalculator();$previewRecipient=$recipient;$recipientTaxId=preg_replace('/\D/','',(string)($recipient['cpf_cnpj']??$recipient['tax_id']??''));if(!in_array(strlen((string)$recipientTaxId),[11,14],true)&&trim((string)($recipient['foreign_id']??''))===''){$warnings[]=$preflight->warning('CUSTOMER_DOCUMENT_PENDING','CPF/CNPJ do destinatário pendente.',['customer_id'=>(int)($recipient['id']??0)]);$previewRecipient['foreign_id']='PREVIEW-PENDING';}
        $addressFields=['street'=>[['street','logradouro','endereco'],'ENDEREÇO PENDENTE'],'number'=>[['number','numero'],'S/N'],'district'=>[['district','bairro'],'PENDENTE'],'city_ibge_code'=>[['city_ibge_code','codigo_ibge','municipio_ibge'],'9999999'],'city_name'=>[['city_name','municipio','cidade'],'MUNICÍPIO PENDENTE'],'state'=>[['state','uf','estado'],'EX'],'postal_code'=>[['postal_code','cep'],'00000000']];foreach($addressFields as$field=>[$aliases,$technical]){$present=false;foreach($aliases as$alias)if(trim((string)($recipient[$alias]??''))!==''){$present=true;break;}if(!$present){$warnings[]=$preflight->warning('CUSTOMER_ADDRESS_PENDING','Endereço do destinatário incompleto.',['customer_id'=>(int)($recipient['id']??0),'field'=>$field]);$previewRecipient[$field]=$technical;}}
        foreach($order['items']as$index=>$item){
            $destinationState=(string)($recipient['uf']??$recipient['estado']??'');$cfop=trim((string)($item['cfop_padrao']??''));
            if($cfop===''&&preg_match('/^[A-Z]{2}$/i',$destinationState))$cfop=(string)($this->operations->contextualCfop((int)$issuer['id'],(string)$order['operation_type'],(string)$issuer['state'],$destinationState)??'');
            $product=$item;$ncm=trim((string)($item['ncm']??''));if(!preg_match('/^\d{8}$/',$ncm)){$warnings[]=$preflight->warning('NCM_PENDING','NCM pendente no produto.',['product_id'=>(int)$item['id'],'product'=>(string)$item['nome']]);$product['ncm']='00000000';}
            if(!preg_match('/^\d{4}$/',$cfop)){$warnings[]=$preflight->warning('CFOP_PENDING','CFOP pendente no produto.',['product_id'=>(int)$item['id'],'origin_state'=>(string)$issuer['state'],'destination_state'=>$destinationState]);$cfop='0000';}
            $context=new FiscalTaxContext($this->operations->tenantId(),(int)$issuer['id'],(string)$issuer['tax_regime_code'],(string)$issuer['state'],$destinationState,(string)($recipient['person_type']??'PJ'),(string)($recipient['state_registration_indicator']??'9'),(string)($recipient['country_code']??'1058'),$ncm,(string)($item['cest']??''),(string)($item['merchandise_origin']??''),(string)($item['tax_benefit_code']??''),(string)$order['operation_type'],$model,(string)$order['purpose'],(bool)$order['final_consumer'],(($recipient['state_registration_indicator']??'9')==='1'),(string)$order['presence_indicator'],new DateTimeImmutable((string)$order['operation_date']),(string)$item['quantity'],(string)$item['unit_price'],$cfop==='0000'?null:$cfop,(int)$item['id']);
            try{$rule=$this->resolver->resolve($context);$calculation=$calculator->calculate($context,$rule);$tax=get_object_vars($rule);foreach(['icms','ipi','pis','cofins']as$group)$tax[$group]=$calculation['taxes'][$group];$tax['preview_only']=true;$tax['resolved']=true;}catch(\Throwable){$warnings[]=$preflight->warning('FISCAL_RULE_NOT_FOUND','Tributação pendente para o produto.',['product_id'=>(int)$item['id'],'product'=>(string)$item['nome'],'ncm'=>$ncm,'crt'=>(string)$issuer['tax_regime_code'],'operation'=>(string)$order['operation_type'],'origin_state'=>(string)$issuer['state'],'destination_state'=>$destinationState,'cfop_candidate'=>$cfop==='0000'?null:$cfop]);$tax=$preflight->technicalTax($cfop,(string)($item['merchandise_origin']??'0'));}
            $items[]=['product'=>$product,'tax'=>$tax,'values'=>['quantity_commercial'=>$item['quantity'],'quantity_taxable'=>bcmul((string)$item['quantity'],(string)($item['conversion_factor']?:'1'),4),'unit_value_commercial'=>$item['unit_price'],'unit_value_taxable'=>bcdiv((string)$item['unit_price'],(string)($item['conversion_factor']?:'1'),4),'gross_total'=>$item['gross_total'],'discount_amount'=>$item['discount_amount'],'freight_amount'=>$item['item_freight'],'insurance_amount'=>$item['item_insurance'],'other_amount'=>$item['item_other'],'net_total'=>$item['net_total']]];
        }
        if(!$items)throw new FiscalDanfeException('PREVIEW_ITEMS_MISSING','Adicione produtos ao pedido antes de gerar a prévia DANFE.');
        foreach ($items as &$previewItem) {
            if (!preg_match('/^[0-8]$/', (string) ($previewItem['product']['merchandise_origin'] ?? ''))) {
                $warnings[] = $preflight->warning('PRODUCT_ORIGIN_PENDING', 'Origem da mercadoria pendente; valor técnico usado somente na prévia.', ['product_id' => (int) $previewItem['product']['id']]);
                $previewItem['product']['merchandise_origin'] = '0';
            }
            foreach (['unidade', 'taxable_unit'] as $unitField) {
                if (trim((string) ($previewItem['product'][$unitField] ?? '')) === '') {
                    $warnings[] = $preflight->warning('PRODUCT_UNIT_PENDING', 'Unidade do produto pendente.', ['product_id' => (int) $previewItem['product']['id'], 'field' => $unitField]);
                    // Marcador explícito somente na prévia; nunca altera o cadastro ou o XML fiscal.
                    $previewItem['product'][$unitField] = 'PEND';
                }
            }
        }
        unset($previewItem);
        $itemsTotal = '0.00';
        foreach ($items as $previewItem) $itemsTotal = bcadd($itemsTotal, (string) $previewItem['values']['net_total'], 2);
        if (bccomp($itemsTotal, (string) $order['grand_total'], 2) !== 0) {
            throw new FiscalDanfeException('PREVIEW_TOTAL_MISMATCH', 'O total do pedido diverge da soma dos itens. Abra o pedido, confira descontos, frete e valores e salve novamente antes de gerar a prévia.');
        }

        // Série 999 e número derivados são identificadores exclusivamente técnicos,
        // necessários para o layout do XML. Nunca são reservados nem persistidos.
        $number=max(1,$orderId%999999999);$series=999;$numericCode=str_pad((string)((int)hexdec(substr(hash('sha256','preview:'.$this->operations->tenantId().':'.$orderId),0,8))%100000000),8,'0',STR_PAD_LEFT);$ufCode=substr(preg_replace('/\D/','',(string)$issuer['city_ibge_code']),0,2);$issued=new DateTimeImmutable((string)$order['operation_date'].' 12:00:00');
        try{$technicalKey=(new NfeAccessKeyGenerator())->generate($ufCode,$issued->format('ym'),(string)$issuer['tax_id'],$model,$series,$number,1,$numericCode);}catch(\Throwable){throw new FiscalDanfeException('PREVIEW_IDENTITY_INVALID','Os dados do emitente são insuficientes para construir o XML de pré-visualização.');}
        $purpose=$this->purpose((string)$order['purpose']);try{$payment=$this->payment((string)$order['payment_method']);}catch(FiscalDanfeException){$warnings[]=$preflight->warning('PAYMENT_METHOD_PENDING','Forma de pagamento fiscal pendente.');$payment='99';}
        $label=$model==='55'?'DANFE':'DANFC-e';$totals=['model'=>$model,'operation_nature'=>(string)$order['operation_nature'],'operation_type'=>(string)$order['operation_type'],'purpose'=>$purpose,'final_consumer'=>(int)$order['final_consumer'],'presence_indicator'=>(int)$order['presence_indicator'],'products'=>(string)$order['products_total'],'discount'=>(string)$order['discount_amount'],'freight'=>(string)$order['freight_amount'],'insurance'=>(string)$order['insurance_amount'],'other'=>(string)$order['other_amount'],'grand'=>(string)$order['grand_total'],'notes'=>'PRÉVIA '.$label.' — NÃO TRANSMITIDA À SEFAZ — NF-e NÃO PROTOCOLADA — SEM VALOR FISCAL. Pedido administrativo #'.$orderId.'.'.$preflight->warningText($warnings)];
        $carrier=$this->carrierSnapshot((int)($order['carrier_id']??0));
        $driver=$this->partyRow('motoristas',(int)($order['driver_id']??0))??[];
        $transport=['carrier_id'=>(int)($order['carrier_id']??0),'carrier'=>$carrier,'driver_id'=>(int)($order['driver_id']??0),'driver'=>$driver,'freight_mode'=>(string)$order['freight_mode'],'vehicle'=>['plate'=>$order['vehicle_plate']??null,'state'=>$order['vehicle_state']??null,'rntc'=>$order['vehicle_rntc']??null],'volume'=>['quantity'=>$order['volume_quantity']??null,'species'=>$order['volume_species']??null,'brand'=>$order['volume_brand']??null,'numbering'=>$order['volume_numbering']??null,'gross_weight'=>$order['gross_weight']??null,'net_weight'=>$order['net_weight']??null]];
        $dto=new FiscalDocumentDTO(0,$this->operations->tenantId(),$model,$issuer,$previewRecipient,$items,$totals,['method'=>$payment,'amount'=>(string)$order['grand_total']],$transport);
        // tpAmb=1 evita que a biblioteca substitua o nome real do destinatário pela
        // frase de homologação. Isto não transmite nada: o XML é apenas técnico,
        // permanece sem assinatura/protocolo e traz as marcas explícitas de prévia.
        try{$xml=(new FiscalNfeXmlBuilder())->build($dto,['access_key'=>$technicalKey,'uf_code'=>$ufCode,'numeric_code'=>$numericCode,'series'=>$series,'number'=>$number,'issued_at'=>$issued->format(DATE_ATOM),'environment'=>1,'emission_type'=>1,'destination_scope'=>strtoupper((string)$issuer['state'])===strtoupper($destinationState)?1:2,'process_version'=>'MiniERP-PREVIEW']);}catch(FiscalDanfeException$e){throw$e;}catch(\Throwable$e){throw new FiscalDanfeException('PREVIEW_XML_INVALID','Não foi possível gerar a prévia DANFE. Revise emitente, cliente, produtos e tributação.',$e);}
        $previewSnapshot=['order'=>$order,'recipient'=>$previewRecipient,'carrier'=>$carrier,'items'=>$items,'totals'=>$totals,'model'=>$model];
        $snapshotChecksum=hash('sha256',json_encode($previewSnapshot,JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION|JSON_THROW_ON_ERROR));
        $cacheKey=hash('sha256',$this->operations->tenantId().'|'.$orderId.'|'.$model.'|'.$modelSource.'|'.$this->spedDaVersion.'|'.$snapshotChecksum.'|'.hash('sha256',$xml));$dir=rtrim($this->cacheRoot,'/\\').DIRECTORY_SEPARATOR.'tenant-'.$this->operations->tenantId().DIRECTORY_SEPARATOR.'model-'.$model;$path=$dir.DIRECTORY_SEPARATOR.$cacheKey.'.pdf';$hit=is_file($path);$renderMeta=[];
        if($hit){$pdf=(string)file_get_contents($path);$renderMeta=['renderer'=>$model==='55'?'NFePHP\\DA\\NFe\\Danfe':'NFePHP\\DA\\NFe\\Danfce','format'=>$model==='55'?'A4':'80mm','orientation'=>'P'];}else{$renderMeta=($this->renderer??new FiscalDocumentPreviewRenderer())->render($model,$xml);$pdf=(string)$renderMeta['bytes'];if(!str_starts_with($pdf,'%PDF'))throw new FiscalDanfeException('PREVIEW_RENDER_FAILED','O renderizador não retornou um PDF válido.');if(!is_dir($dir)&&!mkdir($dir,0700,true)&&!is_dir($dir))throw new FiscalDanfeException('PREVIEW_CACHE_FAILED','Não foi possível criar o cache privado da prévia.');if(file_put_contents($path,$pdf,LOCK_EX)===false)throw new FiscalDanfeException('PREVIEW_CACHE_FAILED','Não foi possível armazenar o cache privado da prévia.');@chmod($path,0600);}
        $prefix=$model==='55'?'PREVIA-DANFE-55':'PREVIA-DANFCE-65';return['bytes'=>$pdf,'filename'=>$prefix.'-PEDIDO-'.$orderId.'.pdf','xml'=>$xml,'cache'=>$hit?'HIT':'MISS','cache_key'=>$cacheKey,'snapshot'=>$previewSnapshot,'snapshot_checksum'=>$snapshotChecksum,'model'=>$model,'model_source'=>$modelSource,'renderer'=>$renderMeta['renderer'],'page_format'=>$renderMeta['format'],'orientation'=>$renderMeta['orientation'],'preview'=>true,'preview_only'=>true,'warnings'=>$warnings,'tax_status'=>$warnings?'PENDING':'RESOLVED','official_access_key'=>null,'technical_key'=>$technicalKey,'number_reference'=>$number,'series_reference'=>$series,'sha256'=>hash('sha256',$pdf)];
    }

    private function one(string$sql,array$params):?array{$statement=$this->pdo->prepare($sql);$statement->execute($params);return$statement->fetch(PDO::FETCH_ASSOC)?:null;}
    private function partyRow(string$table,int$id):?array
    {
        if(!in_array($table,['clientes','fornecedores','transportadoras','motoristas'],true))return null;
        $tenantColumn=$this->pdo->query("SHOW COLUMNS FROM {$table} LIKE 'tenant_id'")->fetchColumn();
        return $this->one("SELECT * FROM {$table} WHERE id=?".($tenantColumn?' AND tenant_id=?':'').' LIMIT 1',$tenantColumn?[$id,$this->operations->tenantId()]:[$id]);
    }
    private function carrierSnapshot(int $carrierId):array
    {
        if ($carrierId < 1) {
            return [];
        }

        $row=$this->partyRow('transportadoras',$carrierId);
        if(is_array($row)&&$row!==[])return$row;
        $tenantColumn=$this->pdo->query("SHOW COLUMNS FROM clientes LIKE 'tenant_id'")->fetchColumn();
        $sql="SELECT * FROM clientes WHERE id=? AND (role_carrier=1 OR FIND_IN_SET('transportadora',REPLACE(COALESCE(tipo_pessoa,''),' ',''))>0)".($tenantColumn?' AND tenant_id=?':'').' LIMIT 1';
        $row=$this->one($sql,$tenantColumn?[$carrierId,$this->operations->tenantId()]:[$carrierId]);
        if(is_array($row)&&$row!==[])return$row;

        return [];
    }
    private function payment(string$value):string{$value=strtoupper(trim($value));if(preg_match('/^\d{2}$/',$value))return$value;return match($value){'DINHEIRO'=>'01','CHEQUE'=>'02','CARTAO_CREDITO','CARTÃO DE CRÉDITO','CREDITO'=>'03','CARTAO_DEBITO','CARTÃO DE DÉBITO','DEBITO'=>'04','PIX'=>'17','BOLETO'=>'15','SEM_PAGAMENTO','SEM PAGAMENTO'=>'90',default=>throw new FiscalDanfeException('PREVIEW_PAYMENT_INVALID','Informe uma forma de pagamento fiscal válida no pedido.')};}
    private function purpose(string$value):int{$value=strtoupper(trim($value));if(in_array($value,['1','2','3','4','5','6'],true))return(int)$value;return match($value){'NORMAL'=>1,'COMPLEMENTAR','COMPLEMENTARY'=>2,'AJUSTE','ADJUSTMENT'=>3,'DEVOLUCAO','DEVOLUÇÃO','RETURN'=>4,'NOTA_DE_CREDITO','NOTA DE CRÉDITO','CREDIT'=>5,'NOTA_DE_DEBITO','NOTA DE DÉBITO','DEBIT'=>6,default=>throw new FiscalDanfeException('PREVIEW_PURPOSE_INVALID','Informe uma finalidade fiscal válida no pedido.')};}
}
