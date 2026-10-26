<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';

use MiniErp\Authorization\{PersistedPlatformAdminAuthorizer, PlatformDatabaseAccess};
use MiniErp\Context\AuthenticatedPlatformAdmin;
use MiniErp\Infrastructure\ControlPlaneConnectionFactory;
use MiniErp\Repositories\ControlPlaneReader;
use MiniErp\Services\ControlPlaneBootstrapService;

// Somente leitura: valida filtro SQL, totais, busca e paginação no banco configurado.
$pdo = (new ControlPlaneConnectionFactory(dirname(__DIR__) . '/config.php'))->create();
$reader = new ControlPlaneReader($pdo);
$service = new ControlPlaneBootstrapService($reader, new PersistedPlatformAdminAuthorizer());
$all = $reader->listTenants();
foreach (['SUPER_ADMIN', 'GLOBAL_TECH', 'SUPPORT', 'DATABASE_ADMIN', 'AUDITOR'] as $role) {
    $identity = new AuthenticatedPlatformAdmin(1, 'test@example.com', 'Test', $role);
    $expected = array_values(array_filter($all, static fn(array $tenant): bool => PlatformDatabaseAccess::canView($identity, $tenant)));
    if ($service->listTenants($identity) !== $expected) throw new RuntimeException('List mismatch: ' . $role);
    $found = [];
    for ($page = 1; $page <= count($expected) + 1; $page++) {
        $result = $service->searchTenants($identity, '', $page, 1);
        if ($result['total'] !== count($expected)) throw new RuntimeException('Total mismatch: ' . $role);
        foreach ($result['items'] as $item) $found[] = (int) $item['tenant_id'];
    }
    if ($found !== array_map(static fn(array $item): int => (int) $item['tenant_id'], $expected)) throw new RuntimeException('Pagination mismatch: ' . $role);
    if ($role !== 'SUPER_ADMIN') {
        foreach (['5', '990103', '990105', 'infocase', 'frutas-regina'] as $query) {
            $result = $service->searchTenants($identity, $query, 1, 100);
            foreach ($result['items'] as $item) {
                if (!PlatformDatabaseAccess::canView($identity, $item)) throw new RuntimeException('Search leaked protected tenant.');
            }
        }
    }
}
echo "PlatformTenantVisibilityMariaDbTest OK\n";
