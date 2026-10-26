<?php
declare(strict_types=1);
namespace MiniErp\Transport;

use PDO;
use RuntimeException;
use MiniErp\Repositories\TenantEstablishmentRepository;

final class TransportRepository
{
    public function __construct(private PDO $pdo, private int $tenantId)
    {
        if ($tenantId < 1 || $pdo->query('SELECT DATABASE()')->fetchColumn() !== 'mini_erp_tenant_'.$tenantId) {
            throw new RuntimeException('O transporte requer a conexão dedicada da empresa autenticada.');
        }
    }
    public function available(): bool
    {
        try { $this->pdo->query('SELECT id FROM transport_documents LIMIT 0'); return true; }
        catch (\PDOException) { return false; }
    }
    public function options(): array
    {
        return [
            'person'=>$this->pdo->query('SELECT id,nome,cpf_cnpj FROM clientes ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC),
            'driver'=>$this->pdo->query('SELECT id,nome,cpf FROM motoristas ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC),
            'carrier'=>$this->pdo->query('SELECT id,nome,cpf_cnpj FROM transportadoras ORDER BY nome')->fetchAll(PDO::FETCH_ASSOC),
            'vehicle'=>$this->vehicles(),
            'cfop'=>$this->pdo->query("SELECT codigo,descricao FROM cfops WHERE codigo LIKE '5%' OR codigo LIKE '6%' OR codigo LIKE '7%' ORDER BY codigo")->fetchAll(PDO::FETCH_ASSOC),
            'order'=>$this->orders(),
        ];
    }
    public function settings(): array
    {
        $s=$this->pdo->prepare('SELECT payload_json FROM transport_settings WHERE tenant_id=?');$s->execute([$this->tenantId]);
        return json_decode((string)($s->fetchColumn() ?: '{}'),true,512,JSON_THROW_ON_ERROR);
    }
    public function saveSettings(array $data, int $actor): void
    {
        $rntrc=trim((string)($data['rntrc']??''));
        if ($rntrc !== '' && !preg_match('/^\d{8}$/',$rntrc)) throw new \InvalidArgumentException('RNTRC deve conter 8 dígitos.');
        $values=['rntrc'=>$rntrc];
        foreach (['cte_series','mdfe_series'] as $field) {
            $value=filter_var($data[$field]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>999]]);
            if ($value===false) throw new \InvalidArgumentException('Série deve estar entre 0 e 999.');
            $values[$field]=(string)$value;
        }
        $s=$this->pdo->prepare('INSERT INTO transport_settings(tenant_id,payload_json,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE payload_json=VALUES(payload_json),updated_by=VALUES(updated_by)');
        $s->execute([$this->tenantId,json_encode($values,JSON_THROW_ON_ERROR),$actor]);
    }
    public function vehicles(): array
    {
        $s=$this->pdo->prepare('SELECT * FROM transport_vehicles WHERE tenant_id=? ORDER BY plate');$s->execute([$this->tenantId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function saveVehicle(array $data): int
    {
        $id=(int)($data['vehicle_record_id']??0);
        $plate=strtoupper(preg_replace('/[^a-z0-9]/i','',(string)($data['plate']??'')));
        if (!preg_match('/^[A-Z]{3}\d[A-Z0-9]\d{2}$/',$plate)) throw new \InvalidArgumentException('Informe uma placa válida.');
        if (!in_array($data['state']??'',TransportFields::STATES,true)) throw new \InvalidArgumentException('Informe a UF do veículo.');
        $values=[$plate,$data['state']];
        foreach (['renavam','tare_kg','capacity_kg','wheel_type','body_type','owner_tax_id','owner_name','owner_rntrc','ownership'] as $field) {
            $value=trim((string)($data[$field]??''));
            if (mb_strlen($value)>($field==='owner_name'?150:14)) throw new \InvalidArgumentException('Campo do veículo muito longo.');
            if (in_array($field,['tare_kg','capacity_kg'],true) && !preg_match('/^\d{1,7}$/',$value)) throw new \InvalidArgumentException('Tara e capacidade devem ser números inteiros positivos.');
            if ($field==='renavam' && $value!=='' && !preg_match('/^\d{9,11}$/',$value)) throw new \InvalidArgumentException('RENAVAM deve conter de 9 a 11 dígitos.');
            if ($field==='wheel_type' && !in_array($value,['01','02','03','04','05','06'],true)) throw new \InvalidArgumentException('Tipo de rodado inválido.');
            if ($field==='body_type' && !in_array($value,['00','01','02','03','04','05'],true)) throw new \InvalidArgumentException('Tipo de carroceria inválido.');
            if ($field==='ownership' && !in_array($value,['0','1','2'],true)) throw new \InvalidArgumentException('Propriedade do veículo inválida.');
            if ($field==='owner_rntrc' && $value!=='' && !preg_match('/^\d{8}$/',$value)) throw new \InvalidArgumentException('RNTRC do proprietário inválido.');
            if ($field==='owner_tax_id' && $value!=='' && !preg_match('/^\d{11}(?:\d{3})?$/',$value)) throw new \InvalidArgumentException('CPF/CNPJ do proprietário deve conter somente dígitos.');
            $values[]=$value;
        }
        if ($id>0) {
            $this->owned('transport_vehicles',$id,true);
            $s=$this->pdo->prepare('UPDATE transport_vehicles SET plate=?,state=?,renavam=?,tare_kg=?,capacity_kg=?,wheel_type=?,body_type=?,owner_tax_id=?,owner_name=?,owner_rntrc=?,ownership=? WHERE id=? AND tenant_id=?');
            $s->execute([...$values,$id,$this->tenantId]);return $id;
        }
        $s=$this->pdo->prepare('INSERT INTO transport_vehicles(plate,state,renavam,tare_kg,capacity_kg,wheel_type,body_type,owner_tax_id,owner_name,owner_rntrc,ownership,tenant_id) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
        $s->execute([...$values,$this->tenantId]);return (int)$this->pdo->lastInsertId();
    }
    private function owned(string $table,int $id,bool $scoped=false): array
    {
        if (!in_array($table,['clientes','motoristas','transportadoras','transport_vehicles'],true)) throw new RuntimeException('Cadastro inválido.');
        $s=$this->pdo->prepare('SELECT * FROM '.$table.' WHERE id=?'.($scoped?' AND tenant_id=?':''));
        $s->execute($scoped?[$id,$this->tenantId]:[$id]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new \InvalidArgumentException('Cadastro referenciado não encontrado nesta empresa.');
        return $row;
    }
    public function snapshot(array $data): array
    {
        $snapshot=['establishment'=>(new TenantEstablishmentRepository($this->pdo))->findPrimaryForTenant($this->tenantId)];
        if (($data['source_order_id']??'')!=='') {
            if (!ctype_digit((string)$data['source_order_id']) || (int)$data['source_order_id']<1) throw new \InvalidArgumentException('Pedido de origem inválido.');
            $snapshot['order']=$this->order((int)$data['source_order_id']);
        }
        foreach (['sender_id'=>'clientes','recipient_id'=>'clientes','dispatcher_id'=>'clientes','receiver_id'=>'clientes','payer_id'=>'clientes','contractor_id'=>'clientes','driver_id'=>'motoristas','carrier_id'=>'transportadoras','vehicle_id'=>'transport_vehicles','trailer_id'=>'transport_vehicles'] as $key=>$table) {
            if (($data[$key]??'')!=='') {
                if (!ctype_digit((string)$data[$key]) || (int)$data[$key]<1) throw new \InvalidArgumentException('Seleção inválida: '.$key);
                $snapshot[$key]=$this->owned($table,(int)$data[$key],$table==='transport_vehicles');
            }
        }
        return $snapshot;
    }
    public function orders(): array
    {
        $s=$this->pdo->prepare('SELECT id,internal_code,operation_type,grand_total FROM fiscal_orders WHERE tenant_id=? ORDER BY id DESC LIMIT 200');$s->execute([$this->tenantId]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function order(int $id): array
    {
        $s=$this->pdo->prepare('SELECT * FROM fiscal_orders WHERE tenant_id=? AND id=?');$s->execute([$this->tenantId,$id]);$order=$s->fetch(PDO::FETCH_ASSOC);
        if(!$order)throw new \InvalidArgumentException('Pedido não encontrado nesta empresa.');
        $s=$this->pdo->prepare('SELECT * FROM fiscal_order_transport_details WHERE tenant_id=? AND order_id=?');$s->execute([$this->tenantId,$id]);
        $order['transport']=$s->fetch(PDO::FETCH_ASSOC)?:[];
        if(!empty($order['person_id']))$order['person']=$this->owned('clientes',(int)$order['person_id']);
        return $order;
    }
    public function find(int $id): ?array
    {
        $s=$this->pdo->prepare('SELECT * FROM transport_documents WHERE id=? AND tenant_id=?');$s->execute([$id,$this->tenantId]);$row=$s->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        foreach (['payload','snapshot','validation'] as $field) $row[$field]=json_decode((string)($row[$field.'_json']?:'{}'),true,512,JSON_THROW_ON_ERROR);
        return $row;
    }
    public function listing(string $model,int $page=1): array
    {
        $s=$this->pdo->prepare('SELECT id,title,status,revision,updated_at FROM transport_documents WHERE tenant_id=? AND model=? ORDER BY updated_at DESC,id DESC LIMIT 25 OFFSET '.((max(1,$page)-1)*25));
        $s->execute([$this->tenantId,$model]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function save(int $id,int $revision,array $data,array $snapshot,?array $validation,int $actor): int
    {
        $status=($snapshot['source']??'')==='CTE_XML_IMPORT'?'IMPORTED_XML':($validation===null?'DRAFT':($validation['errors']?'INVALID':'VALIDATED_LOCAL'));
        $json=static fn($value)=>json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
        $this->pdo->beginTransaction();
        try {
            if ($id>0) {
                $s=$this->pdo->prepare("UPDATE transport_documents SET title=?,status=?,payload_json=?,snapshot_json=?,validation_json=?,updated_by=?,revision=revision+1 WHERE id=? AND tenant_id=? AND model=? AND revision=? AND COALESCE(JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.source')),'') <> 'CTE_XML_IMPORT'");
                $s->execute([$data['title'],$status,$json($data),$json($snapshot),$validation===null?null:$json($validation),$actor,$id,$this->tenantId,$data['model'],$revision]);
                if ($s->rowCount()!==1) throw new RuntimeException('Documento alterado em outra aba ou não encontrado. Reabra antes de salvar.');
                $revision++;
            } else {
                $s=$this->pdo->prepare('INSERT INTO transport_documents(tenant_id,model,title,status,payload_json,snapshot_json,validation_json,created_by,updated_by) VALUES(?,?,?,?,?,?,?,?,?)');
                $s->execute([$this->tenantId,$data['model'],$data['title'],$status,$json($data),$json($snapshot),$validation===null?null:$json($validation),$actor,$actor]);
                $id=(int)$this->pdo->lastInsertId();$revision=1;
            }
            $s=$this->pdo->prepare('INSERT INTO transport_document_events(tenant_id,document_id,revision,action,actor_id,details_json) VALUES(?,?,?,?,?,?)');
            $s->execute([$this->tenantId,$id,$revision,$status==='IMPORTED_XML'?'IMPORT_CTE_XML':(isset($snapshot['cte_artifact'])?'GENERATE_CTE_XML':($validation===null?'SAVE_DRAFT':'VALIDATE_LOCAL')),$actor,$json(['status'=>$status,'validation'=>$validation])]);
            $this->pdo->commit();return $id;
        } catch (\Throwable $e) {$this->pdo->rollBack();throw $e;}
    }
    public function history(int $id): array
    {
        $s=$this->pdo->prepare('SELECT revision,action,created_at FROM transport_document_events WHERE tenant_id=? AND document_id=? ORDER BY id DESC LIMIT 20');$s->execute([$this->tenantId,$id]);return $s->fetchAll(PDO::FETCH_ASSOC);
    }
    public function generateCte(int $id,int $revision,int $actor): int
    {
        $document=$this->find($id);
        if(!$document||$document['model']!=='57'||(int)$document['revision']!==$revision)throw new \InvalidArgumentException('Reabra a versão atual do CT-e antes de gerar.');
        if(($document['snapshot']['source']??'')==='CTE_XML_IMPORT')throw new \InvalidArgumentException('O XML importado é preservado como original e não pode ser regenerado.');
        $artifact=(new CteXmlService())->generate($document);
        $snapshot=$document['snapshot'];$snapshot['cte_artifact']=$artifact;
        $validation=(new TransportDocumentValidator())->validate($document['payload'],$snapshot);
        return $this->save($id,$revision,$document['payload'],$snapshot,$validation,$actor);
    }
    public function importCte(string $xml,int $actor): int
    {
        $metadata=(new CteXmlService())->inspect($xml);
        if(!$metadata['unsigned_xsd_valid'])throw new \InvalidArgumentException('XML fora da estrutura CT-e suportada: '.implode(' ',$metadata['unsigned_xsd_errors']));
        $establishment=(new TenantEstablishmentRepository($this->pdo))->findPrimaryForTenant($this->tenantId);
        $tax=preg_replace('/\D/','',(string)($establishment['tax_id']??''));
        if($tax===''||!in_array($tax,$metadata['party_tax_ids'],true))throw new \InvalidArgumentException('A empresa atual deve constar como emitente ou participante deste CT-e. Confira a empresa selecionada e seu CPF/CNPJ fiscal.');
        $lock='cte-import-'.$this->tenantId.'-'.substr(hash('sha256',$metadata['key']),0,32);
        $s=$this->pdo->prepare('SELECT GET_LOCK(?,5)');$s->execute([$lock]);
        if((int)$s->fetchColumn()!==1)throw new \RuntimeException('Outra importação está em andamento. Tente novamente.');
        try {
            $s=$this->pdo->prepare("SELECT id,snapshot_json FROM transport_documents WHERE tenant_id=? AND model='57' AND JSON_UNQUOTE(JSON_EXTRACT(snapshot_json,'$.cte_artifact.metadata.key'))=?");$s->execute([$this->tenantId,$metadata['key']]);
            if($existing=$s->fetch(PDO::FETCH_ASSOC)){
                $existingSnapshot=json_decode($existing['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
                if(($existingSnapshot['cte_artifact']['metadata']['sha256']??'')!==$metadata['sha256'])throw new \InvalidArgumentException('Já existe um XML diferente com esta chave. O original foi preservado.');
                return (int)$existing['id'];
            }
            $data=TransportFields::normalize('57',['title'=>'XML importado — CT-e '.$metadata['number'].' / '.$metadata['series'],'series'=>$metadata['series'],'service_value'=>$metadata['service_value']]);
            $data['environment']=(int)$metadata['environment'];
            $snapshot=['source'=>'CTE_XML_IMPORT','cte_artifact'=>['origin'=>'IMPORTED_XML','xml'=>$xml,'metadata'=>$metadata,'created_at'=>date(DATE_ATOM)]];
            // No local fiscal validation or authorization is inferred from an imported protocol.
            return $this->save(0,0,$data,$snapshot,null,$actor);
        } finally {$s=$this->pdo->prepare('SELECT RELEASE_LOCK(?)');$s->execute([$lock]);}
    }
}
