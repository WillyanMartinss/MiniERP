<?php // Legacy regression marker: ASSOCIAÇÃO HISTÓRICA EM REVISÃO
declare(strict_types=1);

use MiniErp\Platform\{SchemaCompatibilityClassifier, TemplateSchemaInspector, TenantSchemaComparator, TenantSchemaInspector};
use MiniErp\Repositories\PlatformTenantRepository;

require_once __DIR__ . '/_context.php';
require_once __DIR__ . '/_layout.php';

[$main, , , $identity] = requireAuthorizedPlatformContext();
require_once __DIR__ . '/../../vendor/autoload.php';

$tenantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$tenant = (new PlatformTenantRepository($main))->findById($tenantId);
if (!$tenant) {
    http_response_code(404);
    exit('Empresa não encontrada.');
}

if (!\MiniErp\Authorization\PlatformDatabaseAccess::canView($identity, $tenant)) {
    http_response_code(403);
    exit('Acesso ao banco restrito ao SUPER_ADMIN.');
}

$db = (string) ($tenant['db_name'] ?? '');
$cfg = (require __DIR__ . '/../../config.php')['db'];
$server = new PDO(
    sprintf('mysql:host=%s;port=%s;charset=utf8mb4', $cfg['host'], $cfg['port']),
    $cfg['username'],
    $cfg['password'],
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]
);
$inspector = new TenantSchemaInspector($server);
$actual = $inspector->inspect($db);
$expected = (new TemplateSchemaInspector())->inspect(__DIR__ . '/../../database/tenant-template/v1/schema.sql');
$raw = (new TenantSchemaComparator())->compare($expected, $actual);
$diff = (new SchemaCompatibilityClassifier())->classify($raw);
$table = (string) ($_GET['table'] ?? '');
$rows = $table === '' ? null : $inspector->tableRows($db, $table, max(1, (int) ($_GET['page'] ?? 1)), min(100, max(1, (int) ($_GET['limit'] ?? 50))));

renderPlatformStart($identity, 'Banco de Dados', 'Empresas → ' . ($tenant['nome_fantasia'] ?? '') . ' → Banco de Dados');
?>
<div class="page-title">
    <div>
        <h1>Database Manager</h1>
        <p><?= platformEscape($db) ?> · <strong>READ-ONLY</strong></p>
    </div>
</div>
<section class="panel">
    <h2>Compatibilidade observada</h2>
    <p>Versão declarada: <strong><?= platformEscape($tenant['schema_version'] ?? 'desconhecida') ?></strong> · Estado observado: <strong><?= platformEscape($diff['status']) ?></strong> · <?= $diff['blocking_count'] ?> bloqueios · <?= $diff['legacy_count'] ?> extras legados</p>
    <details>
        <summary>Diferenças classificadas</summary>
        <pre><?= platformEscape(json_encode($diff['differences'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) ?></pre>
    </details>
</section>
<section class="panel">
    <h2>Tabelas</h2>
    <div class="table-wrap">
        <table>
            <thead>
                <tr><th>Tabela</th><th>Engine</th><th>Collation</th><th>Ação</th></tr>
            </thead>
            <tbody>
                <?php foreach ($actual['tables'] as $name => $meta): ?>
                    <tr>
                        <td><?= platformEscape($name) ?></td>
                        <td><?= platformEscape($meta['engine']) ?></td>
                        <td><?= platformEscape($meta['collation']) ?></td>
                        <td><a href="?id=<?= $tenantId ?>&amp;table=<?= rawurlencode($name) ?>">Colunas, índices e dados</a></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</section>
<?php if($table!==''&&isset($actual['tables'][$table])):$meta=$actual['tables'][$table];?><section class="panel"><h2><?=platformEscape($table)?></h2><h3>Colunas</h3><pre><?=platformEscape(json_encode(array_values($meta['columns']),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre><h3>Índices</h3><pre><?=platformEscape(json_encode($meta['indexes'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre><h3>Foreign keys</h3><pre><?=platformEscape(json_encode($meta['foreign_keys'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre><h3>Dados paginados</h3><p><?=($rows['total']??0)?> registros · página <?=($rows['page']??1)?> · máximo 100</p><pre><?=platformEscape(json_encode($rows['rows']??[],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE))?></pre></section><?php endif?><p><a href="/plataforma/empresa.php?id=<?=$tenantId?>">← Voltar</a></p><?php renderPlatformEnd();
