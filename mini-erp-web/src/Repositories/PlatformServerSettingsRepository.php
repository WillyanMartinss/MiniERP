<?php
declare(strict_types=1);
namespace MiniErp\Repositories;
use InvalidArgumentException;
use PDO;

final class PlatformServerSettingsRepository
{
    private const SEFAZ_ALLOWED=['sefaz_technical_cnpj','sefaz_technical_contact','sefaz_technical_email','sefaz_technical_phone','sefaz_csrt_id','sefaz_csrt_env'];
    private const ALLOWED=['planned_domain','planned_subdomain','domain_registrar','cloudflare_account_id','cloudflare_zone_id','infrastructure_contact_email','public_base_url','cloudflare_hostname','cloudflare_tunnel_id','cloudflared_path','cloudflare_token_env','backup_root','backup_retention_days','cloudflare_access_enabled','force_https','maintenance_mode','maintenance_message'];
    public function __construct(private PDO $pdo){}
    public function all():array
    {
        $defaults=['planned_domain'=>'','planned_subdomain'=>'app','domain_registrar'=>'','cloudflare_account_id'=>'','cloudflare_zone_id'=>'','infrastructure_contact_email'=>'','public_base_url'=>'','cloudflare_hostname'=>'','cloudflare_tunnel_id'=>'','cloudflared_path'=>'cloudflared','cloudflare_token_env'=>'CLOUDFLARE_TUNNEL_TOKEN','backup_root'=>dirname(__DIR__,3).DIRECTORY_SEPARATOR.'backups','backup_retention_days'=>'30','cloudflare_access_enabled'=>'0','force_https'=>'1','maintenance_mode'=>'0','maintenance_message'=>'Sistema temporariamente em manutenção.'];
        $rows=$this->pdo->query('SELECT setting_key,setting_value FROM platform_server_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_replace($defaults,array_intersect_key($rows?:[],$defaults));
    }
    public function save(array $input,int $actorId):void
    {
        $values=[];foreach(self::ALLOWED as$key)$values[$key]=trim((string)($input[$key]??''));
        if($values['public_base_url']!==''&&!filter_var($values['public_base_url'],FILTER_VALIDATE_URL))throw new InvalidArgumentException('Informe uma URL pública válida.');
        foreach(['planned_domain','cloudflare_hostname']as$key)if($values[$key]!==''&&!preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i',$values[$key]))throw new InvalidArgumentException('Informe um domínio ou hostname válido.');
        if($values['planned_subdomain']!==''&&!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/i',$values['planned_subdomain']))throw new InvalidArgumentException('Informe um subdomínio válido, sem pontos.');
        if($values['infrastructure_contact_email']!==''&&!filter_var($values['infrastructure_contact_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe um e-mail técnico válido.');
        foreach(['cloudflare_account_id','cloudflare_zone_id']as$key)if($values[$key]!==''&&!preg_match('/^[a-zA-Z0-9_-]{16,80}$/',$values[$key]))throw new InvalidArgumentException('O identificador Cloudflare informado é inválido.');
        if($values['cloudflare_token_env']===''||!preg_match('/^[A-Z][A-Z0-9_]{2,79}$/',$values['cloudflare_token_env']))throw new InvalidArgumentException('Informe somente o nome da variável de ambiente do token.');
        $retention=filter_var($values['backup_retention_days'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3650]]);if($retention===false)throw new InvalidArgumentException('A retenção deve ficar entre 1 e 3650 dias.');$values['backup_retention_days']=(string)$retention;
        foreach(['maintenance_mode','cloudflare_access_enabled','force_https']as$key)$values[$key]=!empty($input[$key])?'1':'0';
        if(mb_strlen($values['maintenance_message'])>500)throw new InvalidArgumentException('A mensagem de manutenção deve ter até 500 caracteres.');
        $stmt=$this->pdo->prepare('INSERT INTO platform_server_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');$this->pdo->beginTransaction();try{foreach($values as$key=>$value)$stmt->execute([$key,$value,$actorId]);$this->pdo->commit();}catch(\Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    public function sefazTechnical():array
    {
        $defaults=array_fill_keys(self::SEFAZ_ALLOWED,'');
        $rows=$this->pdo->query("SELECT setting_key,setting_value FROM platform_server_settings WHERE setting_key LIKE 'sefaz_%'")->fetchAll(PDO::FETCH_KEY_PAIR);
        return array_replace($defaults,array_intersect_key($rows?:[],$defaults));
    }

    public function clearSefazTechnical():void
    {
        $marks=implode(',',array_fill(0,count(self::SEFAZ_ALLOWED),'?'));
        $this->pdo->prepare("DELETE FROM platform_server_settings WHERE setting_key IN ($marks)")->execute(self::SEFAZ_ALLOWED);
    }

    public function saveSefazTechnical(array $input,int $actorId):void
    {
        $values=[];foreach(self::SEFAZ_ALLOWED as$key)$values[$key]=trim((string)($input[$key]??''));
        $values['sefaz_technical_cnpj']=preg_replace('/\D/','',$values['sefaz_technical_cnpj'])??'';
        $values['sefaz_technical_phone']=preg_replace('/\D/','',$values['sefaz_technical_phone'])??'';
        if(!$this->validCnpj($values['sefaz_technical_cnpj']))throw new InvalidArgumentException('Informe um CNPJ válido do responsável técnico.');
        if($values['sefaz_technical_contact']===''||mb_strlen($values['sefaz_technical_contact'])>60)throw new InvalidArgumentException('Informe o contato técnico com até 60 caracteres.');
        if(!filter_var($values['sefaz_technical_email'],FILTER_VALIDATE_EMAIL))throw new InvalidArgumentException('Informe um e-mail válido do responsável técnico.');
        if(!in_array(strlen($values['sefaz_technical_phone']),[10,11],true))throw new InvalidArgumentException('Informe o telefone técnico com DDD.');
        if($values['sefaz_csrt_id']!==''&&!preg_match('/^\d{2}$/',$values['sefaz_csrt_id']))throw new InvalidArgumentException('O ID do CSRT deve conter 2 dígitos.');
        if($values['sefaz_csrt_env']===''||!preg_match('/^[A-Z][A-Z0-9_]{2,79}$/',$values['sefaz_csrt_env']))throw new InvalidArgumentException('Informe somente o nome da variável de ambiente do CSRT.');
        $stmt=$this->pdo->prepare('INSERT INTO platform_server_settings(setting_key,setting_value,updated_by) VALUES(?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by=VALUES(updated_by)');
        $this->pdo->beginTransaction();try{foreach($values as$key=>$value)$stmt->execute([$key,$value,$actorId]);$this->pdo->commit();}catch(\Throwable$e){if($this->pdo->inTransaction())$this->pdo->rollBack();throw$e;}
    }

    private function validCnpj(string $cnpj):bool
    {
        if(!preg_match('/^\d{14}$/',$cnpj)||preg_match('/^(\d)\1{13}$/',$cnpj))return false;
        for($position=12;$position<=13;$position++){$sum=0;$weight=$position===12?5:6;for($index=0;$index<$position;$index++){$sum+=(int)$cnpj[$index]*$weight;$weight=$weight===2?9:$weight-1;}$digit=$sum%11<2?0:11-($sum%11);if((int)$cnpj[$position]!==$digit)return false;}
        return true;
    }
}
