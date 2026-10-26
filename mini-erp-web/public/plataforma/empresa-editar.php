<?php

declare(strict_types=1);

use MiniErp\Repositories\PlatformTenantRepository;
use MiniErp\Services\PlatformTenantData;
use MiniErp\Services\UpdatePlatformTenantService;
use MiniErp\Services\FlashFormState;

require_once __DIR__ . '/_context.php';
[$connection, , $authorizer, $identity] = requireAuthorizedPlatformContext();
require_once __DIR__ . '/../../src/Contracts/PlatformTenantRepositoryContract.php';
require_once __DIR__ . '/../../src/Repositories/PlatformTenantRepository.php';
require_once __DIR__ . '/../../src/Services/PlatformTenantData.php';
require_once __DIR__ . '/../../src/Services/UpdatePlatformTenantService.php';

$tenantId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
$embedded = isset($_GET['embed']) && $_GET['embed'] === '1';
$partial = isset($_GET['view']) && $_GET['view'] === 'partial';
$embedQuery = $embedded ? '&embed=1' : '';
$viewQuery = $partial ? '&view=partial' : '';
$repository = new PlatformTenantRepository($connection);
$tenant = $tenantId > 0 ? $repository->findById($tenantId) : null;
if ($tenant === null) {
    http_response_code(404);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><h1>Empresa não encontrada</h1><a href="/plataforma/">Voltar</a></html>';
    exit;
}

if (empty($_SESSION['platform_tenant_csrf'])) {
    $_SESSION['platform_tenant_csrf'] = bin2hex(random_bytes(32));
}

$values = $tenant;
$logoStorage = new \MiniErp\Services\TenantLoginLogoStorage(dirname(__DIR__));
$error = '';
$state=FlashFormState::consume($_SESSION);if($state){$values=array_merge($values,$state['old_input']);$error=(string)(reset($state['errors'])?:'Revise os campos.');}
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (['razao_social', 'nome_fantasia', 'cnpj', 'slug'] as $field) {
        if (isset($_POST[$field]) && is_scalar($_POST[$field])) {
            $values[$field] = (string) $_POST[$field];
        }
    }
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) $_SESSION['platform_tenant_csrf'], $token)) {
        $error = 'Sessão do formulário expirada. Recarregue a página.';
    } else {
        try {
            $logoBytes = null;
            $logoFile = $_FILES['company_logo'] ?? null;
            if ($logoFile && ($logoFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                if (($logoFile['error'] ?? -1) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($logoFile['tmp_name'] ?? ''))) {
                    throw new InvalidArgumentException('Falha no envio da logo. Envie uma imagem de até 2 MB.');
                }
                $logoBytes = (string) file_get_contents($logoFile['tmp_name']);
                $logoStorage->normalize($logoBytes);
            }
            $service = new UpdatePlatformTenantService($repository, $authorizer);
            $service->update($identity, $tenantId, PlatformTenantData::fromArray($_POST));
            if ($logoBytes !== null) $logoStorage->save($tenantId, $logoBytes);
            $_SESSION['platform_tenant_csrf'] = bin2hex(random_bytes(32));
            header('Location: /plataforma/empresa-editar.php?id=' . $tenantId . $embedQuery . $viewQuery . '&updated=1');
            exit;
        } catch (DomainException|InvalidArgumentException $exception) {
            FlashFormState::store($_SESSION,'platform_tenant_edit',$_POST,['_form'=>$exception->getMessage()]);
            header('Location: /plataforma/empresa-editar.php?id='.$tenantId.$embedQuery.$viewQuery);exit;
        } catch (Throwable) {
            FlashFormState::store($_SESSION,'platform_tenant_edit',$_POST,['_form'=>'Não foi possível atualizar a empresa com segurança.']);
            header('Location: /plataforma/empresa-editar.php?id='.$tenantId.$embedQuery.$viewQuery);exit;
        }
    }
}

$escape = static fn (mixed $value): string => htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
?>
<?php if(!$partial):?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Editar empresa</title><script src="/assets/cnpj-lookup.js" defer></script>
<style>body{font-family:Arial,sans-serif;background:#f1f5f9;margin:0}main{max-width:720px;margin:2rem auto;background:#fff;padding:2rem;border-radius:10px}label,input{display:block;width:100%;box-sizing:border-box}label{font-weight:700;margin-top:1rem}input{padding:.7rem;margin-top:.35rem}.error{color:#b91c1c}.locked{background:#f1f5f9}.actions{margin-top:1.5rem;display:flex;gap:1rem}</style></head><body><main><?php else:?><section class="panel company-general-partial"><?php endif;?>
<?php if(!$embedded):?><p><a href="/plataforma/">← Empresas</a></p><?php endif;?><h1>Editar empresa</h1>
<?php if(isset($_GET['updated'])):?><p class="message success">Alterações salvas com sucesso.</p><?php endif;?>
<?php if(!$partial):?><label>tenant_id<input class="locked" readonly value="<?= $escape($tenantId) ?>"></label><label>Estado atual<input class="locked" readonly value="<?= $escape($tenant['status'] ?? '') ?>"></label><p>O estado, bloqueio e banco dedicado não podem ser alterados nesta task.</p><?php else:?><p class="muted">Identidade comercial e administrativa da empresa.</p><?php endif;?>
<?php if($error!==''):?><p class="error" role="alert"><?= $escape($error) ?></p><?php endif;?>
<form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf_token" value="<?= $escape($_SESSION['platform_tenant_csrf']) ?>">
<fieldset style="margin:16px 0;padding:16px;border:1px solid #dbe5f2;border-radius:12px">
<legend>Logo da empresa</legend>
<?php if ($companyLogo = $logoStorage->url($tenantId)): ?><img src="<?= $escape($companyLogo) ?>" alt="Logo atual da empresa" style="display:block;max-width:180px;max-height:90px;object-fit:contain;margin-bottom:12px"><?php endif; ?>
<label for="companyLogo">Enviar ou substituir logo</label>
<input id="companyLogo" type="file" name="company_logo" accept="image/png,image/jpeg,image/webp" aria-describedby="companyLogoHelp">
<p id="companyLogoHelp" class="muted">Aparece no login desta empresa. PNG, JPG ou WebP, até 2 MB e 2048 × 2048 pixels. Deixe vazio para manter a logo atual.</p>
</fieldset>
<label>Razão social<input id="platform_razao_social" name="razao_social" maxlength="255" required value="<?= $escape($values['razao_social'] ?? '') ?>"></label>
<label>Nome fantasia<input id="platform_nome_fantasia" name="nome_fantasia" maxlength="255" required value="<?= $escape($values['nome_fantasia'] ?? '') ?>"></label>
<label>CNPJ<span class="cnpj-control-group"><input id="platform_cnpj" name="cnpj" maxlength="18" required value="<?= $escape($values['cnpj'] ?? '') ?>"><button type="button" class="btn btn-secondary btn-sm" id="btn-buscar-cnpj-plataforma">Consultar CNPJ</button></span></label>
<label>Slug<input name="slug" maxlength="255" required value="<?= $escape($values['slug'] ?? '') ?>"></label>
<?php if(!$partial):?><div class="actions form-actions"><button class="btn btn-primary" type="submit">Salvar alterações</button><a class="btn btn-secondary" href="/plataforma/">Cancelar</a></div><?php endif;?></form>
<?php if($partial):?></section><?php else:?></main></body></html><?php endif;?>
