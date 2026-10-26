<?php
declare(strict_types=1);use MiniErp\Platform\MultiTenantRuntimeFactory;require_once __DIR__.'/_context.php';require_once __DIR__.'/_layout.php';[$main,,,$identity]=requireAuthorizedPlatformContext();require_once __DIR__.'/../../vendor/autoload.php';[$service,$catalog,$operations]=MultiTenantRuntimeFactory::create($main,dirname(__DIR__,2));if(empty($_SESSION['platform_operations_csrf']))$_SESSION['platform_operations_csrf']=bin2hex(random_bytes(32));$result=null;$error='';if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){try{if(!hash_equals($_SESSION['platform_operations_csrf'],(string)($_POST['csrf_token']??'')))throw new RuntimeException('CSRF inválido.');$action=(string)($_POST['action']??'');if($action==='simulate')$result=$service->simulate($identity,(string)($_POST['migration_id']??''),(array)($_POST['tenant_ids']??[]));elseif($action==='execute')$result=$service->execute($identity,(string)($_POST['plan_id']??''),(string)($_POST['confirmation']??''),(string)($_POST['reason']??''));else throw new RuntimeException('Ação inválida.');}catch(Throwable $e){$error=$e->getMessage();}}$q=trim((string)($_GET['q']??''));$filter=(string)($_GET['filter']??'all');$sql='SELECT id AS tenant_id,nome_fantasia,razao_social,slug,cnpj,db_name,schema_version,status,blocked FROM tenants';$params=[];if($q!==''){$sql.=' WHERE CONCAT_WS(\' \',id,nome_fantasia,razao_social,slug,cnpj,db_name) LIKE ?';$params[]='%'.$q.'%';}$sql.=' ORDER BY id';$s=$main->prepare($sql);$s->execute($params);$tenants=array_values(array_filter($s->fetchAll(PDO::FETCH_ASSOC),static fn(array $tenant):bool=>\MiniErp\Authorization\PlatformDatabaseAccess::canView($identity,$tenant)));$migrations=array_filter($catalog->all(),fn($m)=>$m['target']==='TENANT');$history=$operations->history();renderPlatformStart($identity, 'Operações Multi-tenant', 'Operações Multi-tenant');
?>
<div class="page-title">
    <div>
        <p class="eyebrow">MIGRATIONS OFICIAIS</p>
        <h1>Operações Multi-tenant</h1>
        <p>Dry-run → confirmação → backup → lock → execução sequencial → validação.</p>
    </div>
</div>
<?php if ($error): ?>
    <p class="message warning"><?= platformEscape($error) ?></p>
<?php endif; ?>
<section class="panel" id="catalogos-fiscais"><div class="panel-header"><div><h2>Bibliotecas fiscais</h2><span class="muted">NCM oficial compartilhado; CFOP isolado por empresa.</span></div></div><?php if(!empty($_SESSION['platform_ncm_sync_message'])):?><p class="message success"><?=platformEscape($_SESSION['platform_ncm_sync_message'])?></p><?php unset($_SESSION['platform_ncm_sync_message']);endif?><?php if(!empty($_SESSION['platform_ncm_sync_error'])):?><p class="message warning"><?=platformEscape($_SESSION['platform_ncm_sync_error'])?></p><?php unset($_SESSION['platform_ncm_sync_error']);endif?><div class="operations-grid"><div><h3>NCM oficial</h3><p>Atualiza códigos vigentes e descrições diretamente do Portal Único Siscomex.</p><form method="post" action="/plataforma/ncm-sincronizar.php"><input type="hidden" name="csrf_token" value="<?=platformEscape($_SESSION['platform_operations_csrf'])?>"><button class="btn" type="submit">Atualizar biblioteca NCM</button></form></div><div><h3>CFOP por empresa</h3><p>O seletor pesquisa somente os CFOPs ativos do tenant.</p></div></div></section>
<form method="get" class="panel form-grid">
    <label>Buscar empresa/banco
        <input name="q" value="<?= platformEscape($q) ?>">
    </label>
    <button class="btn">Buscar</button>
</form>
<form method="post">
    <input type="hidden" name="csrf_token" value="<?= platformEscape($_SESSION['platform_operations_csrf']) ?>">
    <input type="hidden" name="action" value="simulate">
    <div class="operations-grid">
        <section class="panel">
            <h2>Empresas</h2>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr><th></th><th>Tenant</th><th>Empresa</th><th>Banco</th><th>Schema</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($tenants as $t): ?>
                            <tr>
                                <td><input type="checkbox" name="tenant_ids[]" value="<?= (int) $t['tenant_id'] ?>"></td>
                                <td>#<?= (int) $t['tenant_id'] ?></td>
                                <td><?= platformEscape($t['nome_fantasia']) ?></td>
                                <td><?= platformEscape($t['db_name']) ?></td>
                                <td><?= platformEscape($t['schema_version']) ?></td>
                                <td><?= platformEscape($t['status']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
        <aside class="panel">
            <h2>Migration</h2>
            <select name="migration_id" required>
                <option value="">Selecione</option>
                <?php foreach ($migrations as $m): ?>
                    <option value="<?= platformEscape($m['migration_id']) ?>"><?= platformEscape($m['migration_id'] . ' · ' . $m['risk']) ?></option>
                <?php endforeach; ?>
            </select>
            <p>Nenhuma empresa é selecionada implicitamente.</p>
            <button class="btn">SIMULAR</button>
        </aside>
    </div>
</form>
<section class="panel accounting-catalog-tools">
 <div class="panel-header"><div><h2>Produtos e contabilidade</h2><span class="muted">Exportações CSV compatíveis com Excel, compostas a partir dos produtos e regras tributárias do banco selecionado.</span></div></div>
 <div class="operations-grid">
  <form method="get" action="/plataforma/catalogo-contabil-download.php" class="form-grid"><input type="hidden" name="csrf_token" value="<?=platformEscape($_SESSION['platform_operations_csrf'])?>"><input type="hidden" name="kind" value="report"><label>Empresa<select name="tenant_id" required><option value="">Selecione uma empresa</option><?php foreach($tenants as$t):if(empty($t['db_name'])||!empty($t['blocked']))continue;?><option value="<?=(int)$t['tenant_id']?>">#<?=(int)$t['tenant_id']?> · <?=platformEscape($t['nome_fantasia'])?></option><?php endforeach?></select></label><label>Relatório<select name="report" required><option value="normal">Contabilidade · Regime Normal</option><option value="simples">Contabilidade · Simples Nacional</option><option value="simples_simplificado">Simples Nacional · Simplificado</option></select></label><button class="btn" type="submit">Gerar CSV para Excel</button></form>
  <div><h3>Importar cadastros</h3><p class="muted">Selecione a empresa e envie o Excel. O sistema identifica os cabeçalhos antes de gravar.</p><form method="post" action="/plataforma/catalogo-importar.php" enctype="multipart/form-data" class="form-grid"><input type="hidden" name="csrf_token" value="<?=platformEscape($_SESSION['platform_operations_csrf'])?>"><input type="hidden" name="action" value="simulate"><label>Empresa / banco<select name="tenant_id" required><option value="">Selecione</option><?php foreach($tenants as$t):if(empty($t['db_name'])||!empty($t['blocked']))continue;?><option value="<?=(int)$t['tenant_id']?>">#<?=(int)$t['tenant_id']?> · <?=platformEscape($t['nome_fantasia']?:$t['razao_social'])?></option><?php endforeach?></select></label><label>Dados da planilha<select name="entity" required><option value="produtos">Produtos</option><option value="clientes">Clientes</option><option value="fornecedores">Fornecedores</option><option value="cfops">CFOPs</option><option value="impostos">Impostos dos produtos</option><option value="icms_uf">ICMS por UF de destino</option></select></label><label>Vigência inicial (ICMS por UF)<input type="date" name="valid_from"></label><label>Vigência final opcional<input type="date" name="valid_to"></label><label>Arquivo Excel ou CSV<input type="file" name="import_file" accept=".xlsx,.csv,.txt" required></label><button class="btn" type="submit">Analisar planilha</button></form><div class="actions"><?php foreach(['produtos'=>'Produtos','clientes'=>'Clientes','fornecedores'=>'Fornecedores','cfops'=>'CFOPs','impostos'=>'Impostos','icms_uf'=>'ICMS por UF']as$key=>$label):?><a class="btn small secondary" href="/plataforma/catalogo-contabil-download.php?kind=template&amp;entity=<?=$key?>&amp;csrf_token=<?=urlencode($_SESSION['platform_operations_csrf'])?>">Modelo <?=$label?></a><?php endforeach?></div><p class="message warning">Primeiro será exibida uma simulação com inclusões, atualizações e erros. Nada é gravado sem a confirmação final.</p></div>
 </div>
</section>

<?php if($result&&isset($result['plan_id'])):$count=count($result['results']);?><section class="panel"><h2>Plano <?=platformEscape($result['plan_id'])?></h2><p>Expira em <?=$result['expires_in']?> segundos · checksum <?=platformEscape($result['migration']['checksum'])?> · risco <?=platformEscape($result['migration']['risk'])?> · backup obrigatório</p><table><?php foreach($result['results']as$r):?><tr><td>#<?=$r['tenant_id']?></td><td><?=platformEscape($r['db_name'])?></td><td><strong><?=platformEscape($r['status'])?></strong></td><td>write_performed=false</td></tr><?php endforeach?></table><?php if(in_array($identity->getRole(),['SUPER_ADMIN','GLOBAL_TECH'],true)):?><form method="post" class="form-grid"><input type="hidden" name="csrf_token" value="<?=platformEscape($_SESSION['platform_operations_csrf'])?>"><input type="hidden" name="action" value="execute"><input type="hidden" name="plan_id" value="<?=platformEscape($result['plan_id'])?>"><label>Confirmação<input name="confirmation" placeholder="EXECUTAR EM <?=$count?> EMPRESAS" required></label><label>Motivo<input name="reason" required></label><button class="btn">EXECUTAR OPERAÇÃO</button></form><?php endif?></section><?php endif?><?php if($result&&isset($result['operation_id'])):?><section class="panel"><h2>Operação <?=platformEscape($result['operation_id'])?></h2><p>Status geral: <strong><?=platformEscape($result['status'])?></strong></p><p>Progresso: <?=count($result['results'])?>/<?=count($result['results'])?></p><table><?php foreach($result['results']as$r):?><tr><td>#<?=$r['tenant_id']?></td><td><?=platformEscape($r['status'])?></td></tr><?php endforeach?></table><a href="/plataforma/operacao-relatorio.php?id=<?=rawurlencode($result['operation_id'])?>">Baixar relatório JSON</a></section><?php endif?><section class="panel"><h2>Histórico de Operações</h2><table><thead><tr><th>Data</th><th>Migration</th><th>Administrador</th><th>Tenants</th><th>Sucessos</th><th>Status</th></tr></thead><tbody><?php foreach($history as$h):?><tr><td><?=platformEscape($h['created_at'])?></td><td><?=platformEscape($h['migration_id'])?></td><td><?=platformEscape($h['admin_name'])?></td><td><?=$h['tenant_count']?></td><td><?=$h['successes']?></td><td><?=platformEscape($h['status'])?></td></tr><?php endforeach?></tbody></table></section><?php renderPlatformEnd();
