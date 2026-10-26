<?php

declare(strict_types=1);

$index = file_get_contents(__DIR__ . '/../public/plataforma/index.php');
$placeholder = file_get_contents(__DIR__ . '/../public/plataforma/empresa-acao.php');
$lifecycle = file_get_contents(__DIR__ . '/../src/Services/PlatformTenantLifecycle.php');

if ($index === false || $placeholder === false || $lifecycle === false) {
    fwrite(STDERR, "ASSERTION FAILED: T03 source files must exist\n");
    exit(1);
}

function lifecycleSourceAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ASSERTION FAILED: {$message}\n");
        exit(1);
    }
}

lifecycleSourceAssert(strpos($index, 'PlatformTenantLifecycle') !== false, 'listing uses lifecycle policy');
lifecycleSourceAssert(strpos($index, "actions['provision']") !== false, 'provision visibility follows policy');
lifecycleSourceAssert(strpos($index, "actions['users']") !== false, 'users visibility follows policy');
lifecycleSourceAssert(strpos($index, "actions['erp']") !== false, 'ERP visibility follows policy');
lifecycleSourceAssert(strpos($index, 'aria-disabled="true"') !== false, 'forbidden UI actions are disabled');
lifecycleSourceAssert(strpos($placeholder, 'requireAuthorizedPlatformContext') !== false, 'placeholder requires platform authorization');
lifecycleSourceAssert(str_contains($placeholder, "header('Location: /login.php?empresa=' . rawurlencode((string) \$tenant['slug']), true, 303)"), 'ERP access opens the selected company login');
lifecycleSourceAssert(!str_contains($placeholder, "Location: /?page=dashboard"), 'platform access cannot bypass the company login');
foreach (['erp_global_admin_id', 'erp_user_id', 'erp_tenant_id', 'erp_tenant_slug', 'PLATFORM_SSO'] as $sessionKey) {
    lifecycleSourceAssert(!str_contains($placeholder, $sessionKey), 'platform entry does not establish ERP authentication: ' . $sessionKey);
}

$combined = strtoupper($index . $placeholder . $lifecycle);
foreach (['CREATE DATABASE', 'INSERT INTO', 'UPDATE TENANTS', 'DELETE FROM', 'ALTER TABLE', 'DROP DATABASE'] as $forbidden) {
    lifecycleSourceAssert(strpos($combined, $forbidden) === false, "T03 does not execute {$forbidden}");
}
lifecycleSourceAssert(strpos($combined, 'CREATEUSERFORTENANT') === false, 'T03 does not create users');
lifecycleSourceAssert(strpos($combined, 'TENANTCONNECTIONRESOLVER') === false, 'T03 does not open a tenant database');
lifecycleSourceAssert(strpos($combined, "\$_SESSION['tenant_id'] =") === false, 'T03 keeps ERP tenant session intact');
lifecycleSourceAssert(strpos($combined, "\$_SESSION['current_company_id'] =") === false, 'T03 keeps legacy company session intact');
lifecycleSourceAssert(!preg_match('/UPDATE\s+[^;]*\bBLOCKED\b/i', $combined), 'T03 does not persist blocked');

echo "PlatformTenantLifecycleEntrypoints OK\n";
