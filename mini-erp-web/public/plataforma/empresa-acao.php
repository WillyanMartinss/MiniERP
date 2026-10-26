<?php

declare(strict_types=1);

use MiniErp\Repositories\PlatformTenantRepository;
use MiniErp\Services\PlatformTenantLifecycle;

require_once __DIR__ . '/_context.php';
[$connection, , , $identity] = requireAuthorizedPlatformContext();
require_once __DIR__ . '/../../src/Contracts/PlatformTenantRepositoryContract.php';
require_once __DIR__ . '/../../src/Repositories/PlatformTenantRepository.php';
require_once __DIR__ . '/../../src/Services/PlatformTenantLifecycle.php';

$tenantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$requestedAction = is_string($_GET['action'] ?? null) ? $_GET['action'] : '';
if ($requestedAction === 'usuarios' && $tenantId > 0) {
    header('Location: /plataforma/empresa-usuarios.php?id=' . $tenantId);
    exit;
}
$actionMap = ['provisionar' => 'provision', 'usuarios' => 'users', 'erp' => 'erp', 'bloquear' => 'block', 'desbloquear' => 'unblock'];
$repository = new PlatformTenantRepository($connection);
$tenant = $tenantId > 0 ? $repository->findById($tenantId) : null;
$lifecycle = new PlatformTenantLifecycle();

if ($tenant === null || !isset($actionMap[$requestedAction])) {
    http_response_code(404);
    $message = 'Empresa ou ação não encontrada.';
} else {
    $actions = $lifecycle->actions((string) ($tenant['status'] ?? ''), $tenant['db_name'] ?? null, !empty($tenant['blocked']));
    if (!$actions[$actionMap[$requestedAction]]) {
        http_response_code(409);
        $message = 'Esta ação não é permitida no estado atual da empresa.';
    } else {
        if ($requestedAction === 'erp') {
            $csrf = (string) ($_SESSION['platform_erp_access_csrf'] ?? '');
            if ($csrf === '' || !hash_equals($csrf, (string) ($_GET['csrf_token'] ?? ''))) {
                http_response_code(403);
                exit('Sessão expirada. Volte ao painel e tente novamente.');
            }
            if (!in_array($identity->getRole(), ['SUPER_ADMIN', 'GLOBAL_TECH'], true)) {
                http_response_code(403);
                exit('Seu perfil não possui acesso técnico ao ERP.');
            }

            // Platform access always leads to the chosen company's login form.
            // ERP authentication is established only after submitting credentials there.
            session_write_close();
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Location: /login.php?empresa=' . rawurlencode((string) $tenant['slug']), true, 303);
            exit;
        }
        $message = 'Ação disponível no lifecycle, mas ainda em implementação. Nenhuma alteração foi executada.';
    }
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Ação futura</title>
<style>body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0}main{max-width:720px;margin:2rem auto;background:#fff;padding:2rem;border-radius:10px}.note{padding:1rem;background:#fef3c7;color:#92400e}</style></head><body><main>
<p><a href="/plataforma/">← Empresas</a></p><h1><?= $escape(ucfirst($requestedAction ?: 'Ação')) ?></h1>
<p class="note"><?= $escape($message) ?></p>
<p>Esta rota é apenas informativa: não cria banco, não cria usuário, não altera status ou bloqueio e não abre o ERP.</p>
</main></body></html>
