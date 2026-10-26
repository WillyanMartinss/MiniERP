<?php
declare(strict_types=1);

namespace MiniErp\Authorization;

use MiniErp\Context\AuthenticatedPlatformAdmin;
use DomainException;

final class PlatformDatabaseAccess
{
    // Identificadores persistentes: INFOCASE, INFOCASE filial e FRUTAS REGINA.
    private const OWNER_ONLY_TENANTS = [5, 990103, 990105];

    public static function visibilitySql(AuthenticatedPlatformAdmin $identity): string
    {
        if ($identity->getRole() === 'SUPER_ADMIN') return '1=1';
        $ids = implode(',', self::OWNER_ONLY_TENANTS);
        $databases = implode(',', array_map(static fn(int $id): string => "'mini_erp_tenant_$id'", self::OWNER_ONLY_TENANTS));
        return "(id NOT IN ($ids) AND COALESCE(db_name,'') NOT IN ($databases))";
    }

    public static function canView(AuthenticatedPlatformAdmin $identity, array $tenant): bool
    {
        $id = (int) ($tenant['tenant_id'] ?? $tenant['id'] ?? 0);
        $database = (string) ($tenant['db_name'] ?? '');
        $protected = in_array($id, self::OWNER_ONLY_TENANTS, true)
            || in_array($database, array_map(static fn(int $id): string => 'mini_erp_tenant_' . $id, self::OWNER_ONLY_TENANTS), true);
        return $identity->getRole() === 'SUPER_ADMIN' || !$protected;
    }

    public static function assertCanView(AuthenticatedPlatformAdmin $identity, array $tenant): void
    {
        if (!self::canView($identity, $tenant)) {
            throw new DomainException('Acesso ao banco restrito ao SUPER_ADMIN.');
        }
    }
}
