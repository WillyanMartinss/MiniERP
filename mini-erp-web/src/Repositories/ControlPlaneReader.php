<?php

declare(strict_types=1);

namespace MiniErp\Repositories;

use MiniErp\Context\AuthenticatedPlatformAdmin;
use MiniErp\Contracts\ControlPlaneReaderContract;
use PDO;

final class ControlPlaneReader implements ControlPlaneReaderContract
{
    public function __construct(private PDO $pdo)
    {
    }

    public function findActiveIdentityByUserId(int $userId): ?AuthenticatedPlatformAdmin
    {
        if ($userId < 1) {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, email, name, role FROM platform_admin_users WHERE id = :id AND active = 1 LIMIT 1"
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return new AuthenticatedPlatformAdmin(
            userId: (int) $row['id'],
            email: (string) $row['email'],
            name: (string) $row['name'],
            role: (string) $row['role']
        );
    }

    public function findActiveAuthenticationRecordByEmail(string $email): ?array
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        $stmt = $this->pdo->prepare(
            "SELECT id, email, name, password_hash, active, role, failed_login_attempts, locked_until FROM platform_admin_users WHERE email = :email LIMIT 1"
        );
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return [
            'id' => (int) $row['id'],
            'email' => (string) $row['email'],
            'nome' => (string) $row['name'],
            'senha' => (string) $row['password_hash'],
            'active' => (bool) $row['active'],
            'role' => (string) $row['role'],
            'failed_login_attempts' => (int) $row['failed_login_attempts'],
            'locked_until' => $row['locked_until'],
        ];
    }

    public function listTenants(): array
    {
        $versionColumn = $this->hasSchemaVersionColumn() ? 'schema_version' : 'NULL AS schema_version';
        $stmt = $this->pdo->query(
            "SELECT id AS tenant_id, razao_social, nome_fantasia, cnpj, slug, status, blocked, db_name, {$versionColumn} FROM tenants ORDER BY id DESC"
        );

        return $stmt->fetchAll();
    }

    public function searchTenants(string $query, int $page, int $limit, string $status = '', ?AuthenticatedPlatformAdmin $identity = null): array
    {
        $page=max(1,$page);$limit=max(1,min(100,$limit));$where=[];$params=[];$query=trim($query);
        if($identity!==null)$where[]=\MiniErp\Authorization\PlatformDatabaseAccess::visibilitySql($identity);
        if($query!==''){$where[]='(razao_social LIKE :q OR nome_fantasia LIKE :q OR cnpj LIKE :q OR slug LIKE :q'.(ctype_digit($query)?' OR id=:exact_id':'').')';$params['q']='%'.$query.'%';if(ctype_digit($query))$params['exact_id']=(int)$query;}
        if($status!==''){$where[]=$status==='bloqueada'?'blocked=1':'status=:status';if($status!=='bloqueada')$params['status']=$status;}
        $sql=' FROM tenants'.($where?' WHERE '.implode(' AND ',$where):'');$count=$this->pdo->prepare('SELECT COUNT(*)'.$sql);$count->execute($params);$total=(int)$count->fetchColumn();$version=$this->hasSchemaVersionColumn()?'schema_version':'NULL AS schema_version';$stmt=$this->pdo->prepare("SELECT id AS tenant_id,razao_social,nome_fantasia,cnpj,slug,status,blocked,db_name,{$version}".$sql.' ORDER BY id DESC LIMIT :limit OFFSET :offset');foreach($params as $k=>$v)$stmt->bindValue(':'.$k,$v,is_int($v)?PDO::PARAM_INT:PDO::PARAM_STR);$stmt->bindValue(':limit',$limit,PDO::PARAM_INT);$stmt->bindValue(':offset',($page-1)*$limit,PDO::PARAM_INT);$stmt->execute();return ['items'=>$stmt->fetchAll(),'total'=>$total,'page'=>$page,'limit'=>$limit];
    }

    private function hasSchemaVersionColumn(): bool
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM tenants LIKE 'schema_version'");
        return (bool) $stmt->fetch();
    }
}
