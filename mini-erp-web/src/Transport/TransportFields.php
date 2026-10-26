<?php
declare(strict_types=1);
namespace MiniErp\Transport;

final class TransportFields
{
    public const STATES = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
    public static function groups(string $model): array
    {
        $uf = array_combine(self::STATES, self::STATES);
        $groups = [
            'Identificação e percurso' => [
                'title'=>['Identificação do rascunho','text'],
                'source_order_id'=>['Pedido de origem (opcional)','order'],
                'series'=>['Série','number','1'],
                'issue_at'=>['Data e hora de emissão','datetime-local'],
                'origin_state'=>['UF de início','select','',$uf],
                'origin_city_code'=>['Município de carregamento / início — IBGE','text'],
                'origin_city_name'=>['Município de início — nome','text'],
                'destination_state'=>['UF de término','select','',$uf],
                'destination_city_code'=>['Município de descarga / término — IBGE','text'],
                'destination_city_name'=>['Município de término — nome','text'],
            ],
        ];
        if ($model === '57') {
            $groups['Serviço e participantes'] = [
                'cfop'=>['CFOP da prestação','cfop'],
                'operation_nature'=>['Natureza da prestação','text'],
                'sender_id'=>['Remetente','person'],
                'recipient_id'=>['Destinatário','person'],
                'dispatcher_id'=>['Expedidor (quando houver)','person'],
                'receiver_id'=>['Recebedor (quando houver)','person'],
                'payer_role'=>['Tomador do serviço','select','0',['0'=>'Remetente','1'=>'Expedidor','2'=>'Recebedor','3'=>'Destinatário','4'=>'Outro']],
                'payer_id'=>['Outro tomador','person'],
                'service_value'=>['Valor total da prestação (R$)','decimal'],
                'receivable_value'=>['Valor a receber (R$)','decimal'],
                'service_components'=>['Componentes — um por linha: descrição;valor','textarea'],
            ];
            $groups['Tributação da prestação'] = [
                'icms_cst'=>['Situação tributária ICMS','select','',['00'=>'00 — Tributação normal','20'=>'20 — Redução de base','40'=>'40 — Isenta','41'=>'41 — Não tributada','51'=>'51 — Diferida','60'=>'60 — ICMS cobrado anteriormente','90'=>'90 — Outros','SN'=>'Simples Nacional']],
                'icms_base'=>['Base de cálculo ICMS (R$)','decimal'],
                'icms_rate'=>['Alíquota ICMS (%)','decimal'],
                'icms_reduction'=>['Redução da base (%)','decimal'],
                'icms_retained'=>['ICMS retido anteriormente (R$)','decimal'],
                'tax_benefit'=>['Código de benefício fiscal','text'],
                'ibs_cbs_cst'=>['CST IBS/CBS','text'],
                'tax_classification'=>['Classificação tributária IBS/CBS (cClassTrib)','text'],
                'ibs_state_rate'=>['Alíquota IBS estadual (%)','decimal'],
                'ibs_city_rate'=>['Alíquota IBS municipal (%)','decimal'],
                'cbs_rate'=>['Alíquota CBS (%)','decimal'],
                'fiscal_notes'=>['Informações fiscais / fundamento legal','textarea'],
            ];
        } else {
            $groups['Viagem rodoviária'] = [
                'emitter_type'=>['Tipo de emitente','select','1',['1'=>'Prestador de serviço de transporte','2'=>'Transportador de carga própria','3'=>'Prestador que emitirá CT-e globalizado']],
                'departure_at'=>['Previsão de início da viagem','datetime-local'],
                'route_states'=>['UFs de percurso (na ordem, separadas por vírgula)','text'],
                'vehicle_id'=>['Veículo de tração','vehicle'],
                'trailer_id'=>['Reboque (quando houver)','vehicle'],
                'driver_id'=>['Condutor principal','driver'],
                'additional_drivers'=>['Demais condutores — um por linha: CPF;nome','textarea'],
                'contractor_id'=>['Contratante','person'],
                'ciot'=>['CIOT (quando aplicável)','text'],
                'toll_supplier_tax_id'=>['CNPJ do fornecedor do vale-pedágio','text'],
                'toll_purchase_number'=>['Número da compra do vale-pedágio','text'],
                'toll_value'=>['Valor do vale-pedágio (R$)','decimal'],
                'payment_value'=>['Valor do contrato de transporte (R$)','decimal'],
                'payment_type'=>['Forma de pagamento','select','',['0'=>'À vista','1'=>'A prazo']],
                'payment_details'=>['Condições de pagamento / parcelas','textarea'],
            ];
        }
        $groups['Carga e documentos vinculados'] = [
            'cargo_product'=>['Produto predominante','text'],
            'cargo_ncm'=>['NCM do produto predominante','text'],
            'cargo_value'=>['Valor da carga (R$)','decimal'],
            'cargo_weight'=>['Peso bruto da carga (kg)','decimal'],
            'cargo_quantity'=>['Quantidade de volumes','number'],
            'cargo_measure'=>['Espécie / unidade de medida dos volumes','text'],
            'document_keys'=>[$model === '57' ? 'Chaves de NF-e — uma por linha' : 'Chaves de CT-e ou NF-e — uma por linha','textarea'],
        ];
        $groups['Transportador, seguro e observações'] = [
            'carrier_id'=>['Transportadora cadastrada (referência operacional)','carrier'],
            'rntrc'=>['RNTRC do emitente','text'],
            'insurance_responsible'=>['Responsável pelo seguro','select','',['1'=>'Emitente','2'=>'Contratante']],
            'insurer_name'=>['Nome da seguradora','text'],
            'insurer_tax_id'=>['CNPJ da seguradora','text'],
            'insurance_policy'=>['Número da apólice','text'],
            'insurance_endorsements'=>['Averbações — uma por linha','textarea'],
            'notes'=>['Observações da operação','textarea'],
        ];
        return $groups;
    }
    public static function normalize(string $model, array $input): array
    {
        if (!in_array($model,['57','58'],true)) throw new \InvalidArgumentException('Modelo de transporte inválido.');
        $out = ['model'=>$model,'environment'=>2,'modal'=>'01','document_type'=>'0'];
        foreach (self::groups($model) as $fields) foreach ($fields as $key=>$field) {
            if (isset($input[$key]) && !is_scalar($input[$key])) throw new \InvalidArgumentException('Formato inválido: '.$field[0]);
            $value = trim((string)($input[$key] ?? $field[2] ?? ''));
            if (mb_strlen($value) > ($field[1] === 'textarea' ? 12000 : 160)) throw new \InvalidArgumentException('Conteúdo muito longo: '.$field[0]);
            if ($field[1] === 'decimal') $value = str_contains($value, ',') ? str_replace(',', '.', str_replace('.', '', $value)) : $value;
            if ($field[1] === 'select' && $value !== '' && !array_key_exists($value,$field[3])) throw new \InvalidArgumentException('Opção inválida: '.$field[0]);
            $out[$key] = $value;
        }
        if ($out['title'] === '') $out['title'] = ($model === '57' ? 'CT-e' : 'MDF-e').' em elaboração';
        return $out;
    }
}
