<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';

use MiniErp\Authorization\PlatformDatabaseAccess;
use MiniErp\Context\AuthenticatedPlatformAdmin;
use MiniErp\Repositories\PlatformServerSettingsRepository;

foreach (['SUPER_ADMIN', 'GLOBAL_TECH', 'SUPPORT', 'DATABASE_ADMIN', 'AUDITOR'] as $role) {
    $identity = new AuthenticatedPlatformAdmin(1, 'test@example.com', 'Test', $role);
    foreach ([5, 990103, 990105] as $id) {
        foreach ([['tenant_id' => $id], ['db_name' => 'mini_erp_tenant_' . $id]] as $tenant) {
            $expected = $role === 'SUPER_ADMIN';
            if (PlatformDatabaseAccess::canView($identity, $tenant) !== $expected) {
                throw new RuntimeException("Unexpected access: $role / $id");
            }
            try {
                PlatformDatabaseAccess::assertCanView($identity, $tenant);
                if (!$expected) throw new RuntimeException('Protected database was accessible.');
            } catch (DomainException $error) {
                if ($expected) throw $error;
            }
        }
    }
    if (!PlatformDatabaseAccess::canView($identity, ['tenant_id' => 14])) {
        throw new RuntimeException('Unrelated tenant access changed.');
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE platform_server_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
$pdo->exec("INSERT INTO platform_server_settings VALUES ('sefaz_technical_cnpj','old'),('sefaz_csrt_env','OLD_SECRET'),('public_base_url','https://example.com')");
$repo = new PlatformServerSettingsRepository($pdo);
$repo->clearSefazTechnical();
if (count($repo->sefazTechnical()) !== 6 || array_filter($repo->sefazTechnical()) !== []) {
    throw new RuntimeException('SEFAZ settings were not cleared.');
}
if ($pdo->query("SELECT setting_value FROM platform_server_settings WHERE setting_key='public_base_url'")->fetchColumn() !== 'https://example.com') {
    throw new RuntimeException('Unrelated settings changed.');
}
echo "PlatformDatabaseAccessTest OK\n";
