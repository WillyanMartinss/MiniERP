<?php
declare(strict_types=1);
namespace MiniErp\Transport;

final class TransportDocumentValidator
{
    private const UF_CODES=['AC'=>'12','AL'=>'27','AP'=>'16','AM'=>'13','BA'=>'29','CE'=>'23','DF'=>'53','ES'=>'32','GO'=>'52','MA'=>'21','MT'=>'51','MS'=>'50','MG'=>'31','PA'=>'15','PB'=>'25','PR'=>'41','PE'=>'26','PI'=>'22','RJ'=>'33','RN'=>'24','RS'=>'43','RO'=>'11','RR'=>'14','SC'=>'42','SP'=>'35','SE'=>'28','TO'=>'17'];
    public function validate(array $data,array $snapshot): array
    {
        $errors=[];$warnings=[];$model=$data['model'];
        $required=['issue_at','origin_state','origin_city_code','origin_city_name','destination_state','destination_city_code','destination_city_name','cargo_product','cargo_value','cargo_weight','document_keys','rntrc'];
        $required=array_merge($required,$model==='57'?['cfop','operation_nature','sender_id','recipient_id','service_value','receivable_value','icms_cst']:['departure_at','vehicle_id','driver_id']);
        $fields=[];foreach(TransportFields::groups($model) as $group) $fields+=$group;
        foreach($required as $key) if(($data[$key]??'')==='') $errors[$key]='Informe '.$fields[$key][0].'.';
        foreach($fields as $key=>$field) {
            $value=$data[$key]??'';
            if($value==='' )continue;
            if($field[1]==='decimal' && !preg_match('/^\d{1,12}(?:\.\d{1,4})?$/',$value)) $errors[$key]='Informe um valor decimal positivo, com até quatro casas decimais.';
            if($field[1]==='number' && !preg_match('/^\d{1,9}$/',$value)) $errors[$key]='Informe um número inteiro não negativo.';
            if($field[1]==='datetime-local') {
                $date=\DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$value);
                if(!$date || $date->format('Y-m-d\TH:i')!==$value) $errors[$key]='Data e hora inválidas.';
            }
        }
        if(!preg_match('/^\d{1,3}$/',(string)$data['series']))$errors['series']='Série deve estar entre 0 e 999.';
        foreach(['origin','destination'] as $side) {
            $code=(string)($data[$side.'_city_code']??'');$uf=$data[$side.'_state']??'';
            if(!preg_match('/^\d{7}$/',$code) || substr($code,0,2)!==(self::UF_CODES[$uf]??''))$errors[$side.'_city_code']='Código IBGE deve ter 7 dígitos e corresponder à UF informada.';
        }
        if(($data['rntrc']??'')!==''&&!preg_match('/^\d{8}$/',$data['rntrc']))$errors['rntrc']='RNTRC deve ter 8 dígitos.';
        foreach(['cargo_value','cargo_weight'] as $key) if(!isset($errors[$key])&&(float)($data[$key]??0)<=0)$errors[$key]='Informe um valor maior que zero.';
        $emitter=$snapshot['establishment']??[];
        foreach(['tax_id','legal_name','state_registration','tax_regime_code','street','number','district','city_ibge_code','city_name','state','postal_code'] as $key) {
            if(trim((string)($emitter[$key]??''))==='')$errors['emit_'.$key]='Complete o cadastro fiscal do emitente: '.$key.'.';
        }
        if($emitter && !self::validTaxId((string)($emitter['tax_id']??'')))$errors['emit_tax_id']='CPF/CNPJ do emitente inválido.';
        if($model==='57') {
            if(!preg_match('/^[567]\d{3}$/',(string)($data['cfop']??'')))$errors['cfop']='Selecione um CFOP de prestação de transporte.';
            elseif(!preg_match('/^[567]35\d$/',$data['cfop']))$errors['cfop']='Este fluxo rodoviário normal requer CFOP do grupo de prestação de transporte (x35x).';
            if(isset($data['cfop'][0])&&$data['destination_state']!==''&&$data['origin_state']!=='') {
                $expected=$data['origin_state']===$data['destination_state']?'5':'6';
                if($data['cfop'][0]!==$expected)$errors['cfop']='CFOP incompatível com prestação interna ou interestadual. Exterior requer fluxo específico.';
            }
            $payer=['0'=>'sender_id','1'=>'dispatcher_id','2'=>'receiver_id','3'=>'recipient_id','4'=>'payer_id'][$data['payer_role']??'0'];
            foreach(array_unique(['sender_id','recipient_id',$payer]) as $key) {
                $person=$snapshot[$key]??null;
                if(!$person){$errors[$key]='Selecione o participante da prestação.';continue;}
                if(!self::validTaxId((string)($person['cpf_cnpj']??'')))$errors[$key]='CPF/CNPJ inválido no cadastro de '.$person['nome'].'.';
                foreach(['logradouro','numero','bairro','uf','cep'] as $address) if(trim((string)($person[$address]??''))==='')$errors[$key]='Complete o endereço de '.$person['nome'].' no cadastro de pessoas.';
                if(!preg_match('/^\d{7}$/',(string)(($person['codigo_ibge']??'')?:($person['codigo_municipal']??''))))$errors[$key]='Complete o código IBGE de '.$person['nome'].'.';
            }
            if(!isset($errors['service_value'])&&(float)($data['service_value']??0)<=0)$errors['service_value']='Valor da prestação deve ser maior que zero.';
            if(!isset($errors['receivable_value'])&&!isset($errors['service_value'])&&(float)($data['receivable_value']??0)>(float)($data['service_value']??0))$errors['receivable_value']='Valor a receber não pode exceder a prestação.';
            if(in_array($data['icms_cst']??'',['00','20','90'],true))foreach(['icms_base','icms_rate'] as $key)if(($data[$key]??'')==='')$errors[$key]='Informe a base e alíquota do ICMS.';
            if(($data['icms_cst']??'')==='20'&&($data['icms_reduction']??'')==='')$errors['icms_reduction']='Informe o percentual de redução.';
            if(($data['icms_cst']??'')==='60'&&($data['icms_retained']??'')==='')$errors['icms_retained']='Informe o ICMS retido anteriormente.';
            foreach(['icms_rate','icms_reduction','ibs_state_rate','ibs_city_rate','cbs_rate'] as $key)if((float)($data[$key]??0)>100)$errors[$key]='Percentual não pode exceder 100%.';
            if(($data['ibs_cbs_cst']??'')!==''&&!preg_match('/^\d{3}$/',$data['ibs_cbs_cst']))$errors['ibs_cbs_cst']='CST IBS/CBS deve ter 3 dígitos.';
            if(($data['tax_classification']??'')!==''&&!preg_match('/^\d{6}$/',$data['tax_classification']))$errors['tax_classification']='cClassTrib deve ter 6 dígitos.';
            $components=0;
            foreach(self::lines($data['service_components']??'') as $line) {
                $parts=explode(';',$line);
                $value=trim(str_replace(',','.',end($parts)));
                if(count($parts)!==2||trim($parts[0])===''||!preg_match('/^\d{1,12}(?:\.\d{1,2})?$/',$value)){$errors['service_components']='Use descrição;valor, com um componente por linha.';break;}
                $components+=self::cents($value);
            }
            if($components>0&&$components!==self::cents($data['service_value']??'0'))$errors['service_components']='A soma dos componentes deve ser igual ao valor da prestação.';
            $warnings[]='Enquadramento ICMS, benefícios, DIFAL e regras IBS/CBS precisam de revisão fiscal. Esta conferência não valida tabelas tributárias da SEFAZ.';
        } else {
            $vehicle=$snapshot['vehicle_id']??[];$driver=$snapshot['driver_id']??[];
            if($vehicle&&((int)$vehicle['tare_kg']<=0||trim($vehicle['renavam'])===''))$errors['vehicle_id']='Complete RENAVAM e tara no cadastro do veículo.';
            if($driver&&(!self::validTaxId((string)$driver['cpf'])||($driver['status']??'ativo')!=='ativo'))$errors['driver_id']='Motorista inativo ou CPF inválido.';
            if($driver&&!empty($driver['vencimento_cnh'])&&$driver['vencimento_cnh']<date('Y-m-d'))$errors['driver_id']='CNH do motorista vencida.';
            if(($data['trailer_id']??'')!==''&&$data['trailer_id']===$data['vehicle_id'])$errors['trailer_id']='Tração e reboque devem ser veículos diferentes.';
            foreach(array_filter(array_map('trim',explode(',',strtoupper($data['route_states']??'')))) as $uf)if(!in_array($uf,TransportFields::STATES,true)||in_array($uf,[$data['origin_state'],$data['destination_state']],true))$errors['route_states']='Informe apenas UFs intermediárias válidas, na ordem do percurso.';
            if(($data['departure_at']??'')!==''&&$data['issue_at']!==''&&$data['departure_at']<$data['issue_at'])$errors['departure_at']='Início da viagem não pode anteceder a emissão.';
            foreach(self::lines($data['additional_drivers']??'') as $line){$parts=explode(';',$line);if(count($parts)!==2||!self::validTaxId($parts[0])||trim($parts[1])==='')$errors['additional_drivers']='Use CPF válido;nome para cada condutor.';}
            if(($data['ciot']??'')!==''&&!preg_match('/^\d{12}$/',$data['ciot']))$errors['ciot']='CIOT deve ter 12 dígitos.';
            if(($data['toll_value']??'')!=='')foreach(['toll_supplier_tax_id','toll_purchase_number'] as $key)if(($data[$key]??'')==='')$errors[$key]='Complete os dados do vale-pedágio.';
            if(($data['toll_supplier_tax_id']??'')!==''&&!self::validTaxId($data['toll_supplier_tax_id']))$errors['toll_supplier_tax_id']='CNPJ do fornecedor inválido.';
            $warnings[]='Conferir exigibilidade de CIOT, vale-pedágio, seguro, contratantes e pagamento. A conferência local não consulta ANTT nem verifica MDF-e não encerrado.';
        }
        $keys=self::lines($data['document_keys']??'');
        if(count($keys)!==count(array_unique($keys)))$errors['document_keys']='Há chaves de acesso duplicadas.';
        foreach($keys as $key) {
            $allowed=$model==='57'?['55']:(($data['emitter_type']??'1')==='1'?['57']:['55']);
            if(!self::validAccessKey($key)||!in_array(substr($key,20,2),$allowed,true)){$errors['document_keys']='Chave inválida ou modelo incompatível. Use '.implode('/',$allowed).' com 44 dígitos e verificador correto.';break;}
        }
        if(($data['cargo_ncm']??'')!==''&&!preg_match('/^\d{8}$/',$data['cargo_ncm']))$errors['cargo_ncm']='NCM deve ter 8 dígitos.';
        if(($data['insurance_policy']??'')!==''||($data['insurer_tax_id']??'')!=='') {
            foreach(['insurance_responsible','insurer_name','insurance_policy'] as $key)if(($data[$key]??'')==='')$errors[$key]='Complete os dados do seguro.';
            if(!self::validTaxId($data['insurer_tax_id']??''))$errors['insurer_tax_id']='CNPJ da seguradora inválido.';
        }
        return ['scope'=>'LOCAL_DATA_ONLY','errors'=>$errors,'warnings'=>$warnings,
            'checked_at'=>date(DATE_ATOM),'document_count'=>count($keys),
            'calculated_icms'=>isset($errors['icms_base'])||isset($errors['icms_rate'])?null:number_format(round((float)($data['icms_base']??0)*(float)($data['icms_rate']??0)/100,2),2,'.',''),
            'xml_validated'=>false,'signature_validated'=>false,'sefaz_authorized'=>false];
    }
    private static function cents(string $value): int { $parts=explode('.',$value);return ((int)$parts[0])*100+(int)str_pad(substr($parts[1]??'',0,2),2,'0'); }
    public static function lines(string $value): array {return array_values(array_filter(array_map('trim',preg_split('/\R/',$value)?:[]),static fn($line)=>$line!==''));}
    public static function validAccessKey(string $key): bool
    {
        if(!preg_match('/^\d{44}$/',$key))return false;
        $sum=0;$weight=2;for($i=42;$i>=0;$i--){$sum+=(int)$key[$i]*$weight;$weight=$weight===9?2:$weight+1;}
        $digit=11-$sum%11;return (int)$key[43]===($digit>=10?0:$digit);
    }
    public static function validTaxId(string $value): bool
    {
        $digits=preg_replace('/\D/','',$value);$len=strlen($digits);
        if(!in_array($len,[11,14],true)||preg_match('/^(\d)\1+$/',$digits))return false;
        for($length=$len-2;$length<$len;$length++) {
            $sum=0;$weight=$len===11?$length+1:($length===12?5:6);
            for($i=0;$i<$length;$i++){$sum+=(int)$digits[$i]*$weight-- ;if($len===14&&$weight===1)$weight=9;}
            $digit=$sum%11<2?0:11-$sum%11;if((int)$digits[$length]!==$digit)return false;
        }
        return true;
    }
}
