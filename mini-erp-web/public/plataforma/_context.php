<?php
declare(strict_types=1);

use MiniErp\Authorization\PersistedPlatformAdminAuthorizer;
use MiniErp\Infrastructure\ControlPlaneConnectionFactory;
use MiniErp\Repositories\{ControlPlaneReader, PlatformAdminRepository};
use MiniErp\Services\ControlPlaneBootstrapService;

require_once __DIR__ . '/_session.php';

function requireAuthorizedPlatformContext(): array
{
    startPlatformSession();
    $adminId = (int) ($_SESSION['platform_admin']['admin_id'] ?? 0);
    if ($adminId < 1) {
        header('Location: /plataforma/login.php');
        exit;
    }

    require_once __DIR__ . '/../../vendor/autoload.php';

    try {
        $connection = (new ControlPlaneConnectionFactory(__DIR__ . '/../../config.php'))->create();
        $reader = new ControlPlaneReader($connection);
        $authorizer = new PersistedPlatformAdminAuthorizer();
        $service = new ControlPlaneBootstrapService($reader, $authorizer);
        $identity = $service->resolveIdentity($adminId);

        // Todas as telas e ações de empresa passam por esta autorização antes de ler dados.
        $route = basename((string) ($_SERVER['SCRIPT_NAME'] ?? ''));
        $tenantIds = array_merge((array) ($_GET['tenant_ids'] ?? []), (array) ($_POST['tenant_ids'] ?? []));
        foreach ([$_GET, $_POST] as $input) {
            if (isset($input['tenant_id'])) $tenantIds[] = $input['tenant_id'];
            if (str_starts_with($route, 'empresa') || in_array($route, ['ajax-certificado.php', 'ajax-sefaz-status.php', 'certificado-download.php'], true)) {
                if (isset($input['id'])) $tenantIds[] = $input['id'];
            }
        }
        foreach ($tenantIds as $requestedTenantId) {
            $tenant = (new \MiniErp\Repositories\PlatformTenantRepository($connection))->findById((int) $requestedTenantId);
            if ($tenant && !\MiniErp\Authorization\PlatformDatabaseAccess::canView($identity, $tenant)) {
                http_response_code(403);
                exit('Acesso a esta empresa restrito ao SUPER_ADMIN.');
            }
        }

        $isDryRun = basename((string) ($_SERVER['SCRIPT_NAME'] ?? '')) === 'operacoes-multitenant.php'
            && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

        $csrf = (string) ($_SESSION['platform_operations_csrf'] ?? '');
        if ($isDryRun && $csrf !== '' && hash_equals($csrf, (string) ($_POST['csrf_token'] ?? ''))) {
            $ids = array_values(array_filter(array_map('intval', (array) ($_POST['tenant_ids'] ?? [])), fn($id) => $id > 0));
            (new PlatformAdminRepository($connection))->audit(
                $adminId,
                'MULTITENANT_DRY_RUN',
                'migration',
                (string) ($_POST['migration'] ?? ''),
                $_SERVER['REMOTE_ADDR'] ?? null,
                ['tenant_ids' => $ids]
            );
        }

        return [$connection, $reader, $authorizer, $identity, $service];
    } catch (DomainException) {
        unset($_SESSION['platform_admin']);
        http_response_code(403);
        exit('Acesso administrativo negado.');
    } catch (Throwable) {
        http_response_code(503);
        exit('Painel indisponível.');
    }
}
