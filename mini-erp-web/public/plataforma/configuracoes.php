<?php
declare(strict_types=1);

use MiniErp\Repositories\{PlatformAdminRepository, PlatformServerSettingsRepository};

require_once __DIR__.'/_context.php';
[$pdo,,, $identity] = requireAuthorizedPlatformContext();
require_once __DIR__.'/_layout.php';

$role = $identity->getRole();
$canEdit = in_array($role, ['SUPER_ADMIN','DATABASE_ADMIN'], true);
$repo = new PlatformServerSettingsRepository($pdo);
$_SESSION['platform_server_settings_csrf'] ??= bin2hex(random_bytes(32));
$success=''; $error='';
$activeTab=in_array((string)($_GET['tab']??$_POST['settings_section']??'connections'),['connections','sefaz'],true)?(string)($_GET['tab']??$_POST['settings_section']??'connections'):'connections';
if (($_SERVER['REQUEST_METHOD']??'GET') === 'POST') {
    try {
        if (!$canEdit) throw new DomainException('Seu perfil possui acesso somente para consulta.');
        if (!hash_equals((string)$_SESSION['platform_server_settings_csrf'], (string)($_POST['csrf_token']??''))) throw new DomainException('Sessão expirada. Atualize a página.');
        if($activeTab==='sefaz'){$repo->saveSefazTechnical($_POST,$identity->getUserId());$auditAction='SEFAZ_TECHNICAL_SETTINGS_UPDATED';$success='Configuração do responsável técnico SEFAZ salva.';}
        else{$repo->save($_POST,$identity->getUserId());$auditAction='SERVER_SETTINGS_UPDATED';$success='Configurações de conexões salvas.';}
        (new PlatformAdminRepository($pdo))->audit($identity->getUserId(),$auditAction,'platform',$activeTab,$_SERVER['REMOTE_ADDR']??null,['keys'=>array_keys($_POST)]);
    } catch (Throwable $e) { $error=$e->getMessage(); }
}
$settings=$repo->all();
$sefazSettings=$repo->sefazTechnical();$sefazCsrtConfigured=$sefazSettings['sefaz_csrt_env']!==''&&trim((string)getenv($sefazSettings['sefaz_csrt_env']))!=='';
$cfg=require __DIR__.'/../../config.php'; $db=$cfg['db']??[];
$dbOk=false; try{$pdo->query('SELECT 1');$dbOk=true;}catch(Throwable){}
$cloudflared=$settings['cloudflared_path'];
$cloudflaredFromPath=$cloudflared==='cloudflared' ? trim((string)@shell_exec(PHP_OS_FAMILY==='Windows'?'where.exe cloudflared 2>NUL':'command -v cloudflared 2>/dev/null')) : '';
$cloudflaredFound=$cloudflared!=='' && (is_file($cloudflared) || $cloudflaredFromPath!=='');
$tokenConfigured=getenv($settings['cloudflare_token_env'])!==false && trim((string)getenv($settings['cloudflare_token_env']))!=='';
$backupWritable=is_dir($settings['backup_root']) && is_writable($settings['backup_root']);
$futureHostname=trim($settings['planned_subdomain'].'.'.$settings['planned_domain'],'.');
$domainPrepared=$settings['planned_domain']!=='';
$cloudflarePrepared=$settings['cloudflare_account_id']!==''&&$settings['cloudflare_zone_id']!=='';
$tunnelPrepared=$settings['cloudflare_tunnel_id']!==''&&$tokenConfigured;
$oauthProviders=[
 'Google'=>['MINI_ERP_GOOGLE_CLIENT_ID','MINI_ERP_GOOGLE_CLIENT_SECRET'],
 'Facebook'=>['MINI_ERP_FACEBOOK_CLIENT_ID','MINI_ERP_FACEBOOK_CLIENT_SECRET'],
 'LinkedIn'=>['MINI_ERP_LINKEDIN_CLIENT_ID','MINI_ERP_LINKEDIN_CLIENT_SECRET'],
];
$e=static fn($v)=>platformEscape($v);
renderPlatformStart($identity,'Configurações','Infraestrutura e servidores');
?>
<div class="page-title"><div><p class="eyebrow">Control-Plane</p><h1>Configurações</h1><p class="muted">Infraestrutura, publicação externa, backups e manutenção.</p></div></div>
<?php if($success):?><p class="message success"><?=$e($success)?></p><?php endif?><?php if($error):?><p class="message error"><?=$e($error)?></p><?php endif?>
<nav class="platform-settings-tabs" aria-label="Guias de configurações"><a class="<?=$activeTab==='connections'?'active':''?>" href="?tab=connections">Conexões</a><a class="<?=$activeTab==='sefaz'?'active':''?>" href="?tab=sefaz">Configuração SEFAZ</a></nav>
<?php if($activeTab==='connections'):?>
<section class="server-health-grid" aria-label="Estado dos serviços">
 <article class="server-health-card"><span class="server-dot <?=$dbOk?'ok':'bad'?>"></span><div><small>Banco principal</small><strong><?=$dbOk?'Conectado':'Indisponível'?></strong><span><?=$e(($db['host']??'—').':'.($db['port']??'—'))?></span></div></article>
 <article class="server-health-card"><span class="server-dot <?=$cloudflaredFound?'ok':'warn'?>"></span><div><small>Cloudflare Tunnel</small><strong><?=$cloudflaredFound?'Executável configurado':'Revisar caminho'?></strong><span><?=$tokenConfigured?'Token disponível no ambiente':'Token não exposto/configurado'?></span></div></article>
 <article class="server-health-card"><span class="server-dot <?=$backupWritable?'ok':'warn'?>"></span><div><small>Backups</small><strong><?=$backupWritable?'Diretório gravável':'Diretório pendente'?></strong><span><?=$e($settings['backup_root'])?></span></div></article>
 <article class="server-health-card"><span class="server-dot ok"></span><div><small>Runtime</small><strong>PHP <?=PHP_VERSION?></strong><span><?=$e(PHP_OS_FAMILY)?> · <?=date_default_timezone_get()?></span></div></article>
</section>
<form method="post" class="server-settings-form" action="?tab=connections">
 <input type="hidden" name="csrf_token" value="<?=$e($_SESSION['platform_server_settings_csrf'])?>">
 <input type="hidden" name="settings_section" value="connections">
 <section class="panel settings-section domain-readiness"><div class="panel-header"><div><h2>Preparação do domínio futuro</h2><span class="muted">Preencha aos poucos. Nada será publicado enquanto o domínio e o túnel permanente não existirem.</span></div></div>
  <div class="domain-preview"><span>Endereço planejado</span><strong>https://<?=$e($futureHostname?:'app.seudominio.com.br')?></strong></div>
  <ol class="readiness-list">
   <li class="<?=$domainPrepared?'done':''?>"><span><?=$domainPrepared?'✓':'1'?></span><div><strong>Comprar e informar o domínio</strong><small><?=$domainPrepared?'Domínio planejado: '.$e($settings['planned_domain']):'Exemplo: minierpweb.com.br'?></small></div></li>
   <li class="<?=$cloudflarePrepared?'done':''?>"><span><?=$cloudflarePrepared?'✓':'2'?></span><div><strong>Adicionar o domínio à Cloudflare</strong><small>Depois copie o Account ID e o Zone ID.</small></div></li>
   <li class="<?=$tunnelPrepared?'done':''?>"><span><?=$tunnelPrepared?'✓':'3'?></span><div><strong>Criar um túnel permanente</strong><small>O token fica somente no servidor, em variável de ambiente.</small></div></li>
   <li><span>4</span><div><strong>Publicar e testar HTTPS</strong><small>Validar login, cookies, uploads, DANFE e acesso mobile antes da troca.</small></div></li>
  </ol>
  <div class="settings-grid">
   <label>Domínio que pretende comprar<input name="planned_domain" value="<?=$e($settings['planned_domain'])?>" placeholder="minierpweb.com.br"><small>Não inclua https:// nem caminhos.</small></label>
   <label>Subdomínio do sistema<input name="planned_subdomain" value="<?=$e($settings['planned_subdomain'])?>" placeholder="app"><small>Exemplos: app, painel ou erp.</small></label>
   <label>Empresa onde comprou o domínio<input name="domain_registrar" value="<?=$e($settings['domain_registrar'])?>" placeholder="Registro.br, Cloudflare Registrar..."></label>
   <label>E-mail técnico responsável<input type="email" name="infrastructure_contact_email" value="<?=$e($settings['infrastructure_contact_email'])?>" placeholder="tecnico@seudominio.com.br"></label>
  </div>
 </section>
 <section class="panel settings-section"><div class="panel-header"><div><h2>Publicação e Cloudflare</h2><span class="muted">O token permanece em variável de ambiente e nunca é salvo no banco.</span></div></div><div class="settings-grid">
  <label>URL pública<input type="url" name="public_base_url" value="<?=$e($settings['public_base_url'])?>" placeholder="https://erp.exemplo.com"></label>
  <label>Hostname Cloudflare<input name="cloudflare_hostname" value="<?=$e($settings['cloudflare_hostname'])?>" placeholder="erp.exemplo.com"></label>
  <label>ID ou nome do túnel<input name="cloudflare_tunnel_id" value="<?=$e($settings['cloudflare_tunnel_id'])?>" placeholder="mini-erp-production"></label>
  <label>Cloudflare Account ID<input name="cloudflare_account_id" value="<?=$e($settings['cloudflare_account_id'])?>" placeholder="Disponível no painel da Cloudflare"></label>
  <label>Cloudflare Zone ID<input name="cloudflare_zone_id" value="<?=$e($settings['cloudflare_zone_id'])?>" placeholder="Disponível na página do domínio"></label>
  <label>Caminho do cloudflared<input name="cloudflared_path" value="<?=$e($settings['cloudflared_path'])?>" placeholder="C:\\cloudflared\\cloudflared.exe"></label>
  <label>Variável do token<input name="cloudflare_token_env" value="<?=$e($settings['cloudflare_token_env'])?>" autocomplete="off"><small>Salve apenas o nome, por exemplo CLOUDFLARE_TUNNEL_TOKEN.</small></label>
  <label class="settings-toggle"><input type="checkbox" name="force_https" value="1" <?=$settings['force_https']==='1'?'checked':''?>><span><strong>Forçar HTTPS</strong><small>Recomendado quando o domínio permanente estiver ativo.</small></span></label>
  <label class="settings-toggle"><input type="checkbox" name="cloudflare_access_enabled" value="1" <?=$settings['cloudflare_access_enabled']==='1'?'checked':''?>><span><strong>Cloudflare Access</strong><small>Proteção adicional para o painel administrativo.</small></span></label>
 </div></section>
 <section class="panel settings-section"><div class="panel-header"><div><h2>Backups e continuidade</h2><span class="muted">Políticas administrativas do servidor.</span></div></div><div class="settings-grid">
  <label>Diretório de backups<input name="backup_root" value="<?=$e($settings['backup_root'])?>"></label>
  <label>Retenção em dias<input type="number" min="1" max="3650" name="backup_retention_days" value="<?=$e($settings['backup_retention_days'])?>"></label>
  <label class="settings-toggle"><input type="checkbox" name="maintenance_mode" value="1" <?=$settings['maintenance_mode']==='1'?'checked':''?>><span><strong>Modo de manutenção</strong><small>Deixa preparada a política de indisponibilidade controlada.</small></span></label>
  <label class="settings-wide">Mensagem de manutenção<textarea name="maintenance_message" rows="3" maxlength="500"><?=$e($settings['maintenance_message'])?></textarea></label>
 </div></section>
 <section class="panel settings-section readonly-settings"><div class="panel-header"><div><h2>Ambiente detectado</h2><span class="muted">Informações sensíveis permanecem ocultas e não podem ser alteradas nesta tela.</span></div></div><dl><div><dt>Banco</dt><dd><?=$e($db['database']??'—')?></dd></div><div><dt>Host</dt><dd><?=$e($db['host']??'—')?></dd></div><div><dt>Porta</dt><dd><?=$e($db['port']??'—')?></dd></div><div><dt>Servidor web</dt><dd><?=$e($_SERVER['SERVER_SOFTWARE']??'PHP local')?></dd></div></dl></section>
 <div class="settings-actions"><span class="muted"><?=$canEdit?'Alterações são registradas na auditoria.':'Seu perfil está em modo consulta.'?></span><button class="btn" type="submit" <?=$canEdit?'':'disabled'?>>Salvar configurações</button></div>
</form>
<section class="panel settings-section readonly-settings"><div class="panel-header"><div><h2>Login social das empresas</h2><span class="muted">As chaves ficam somente nas variáveis de ambiente do servidor. Depois de configuradas, os botões usam o link de login da empresa e criam uma solicitação pendente.</span></div></div><dl><?php foreach($oauthProviders as $provider=>$environmentNames):$ready=true;foreach($environmentNames as $environmentName)$ready=$ready&&trim((string)getenv($environmentName))!=='';?><div><dt><?=$e($provider)?></dt><dd><strong class="<?=$ready?'status-ok':'status-pending'?>"><?=$ready?'Configurado':'Pendente'?></strong><small><?= $e(implode(' + ', $environmentNames)) ?></small></dd></div><?php endforeach;?></dl><p class="muted">URL de retorno para cadastrar em cada provedor: <code><?= $e(($settings['public_base_url']?:'https://seu-dominio').'/oauth.php') ?></code></p></section>
<?php else:?>
<section class="panel settings-section"><div class="panel-header"><div><h2>Responsável técnico do MiniERP</h2><span class="muted">Estes dados formam o grupo <code>infRespTec</code> enviado no XML da NF-e em homologação.</span></div><strong class="<?=$sefazSettings['sefaz_technical_cnpj']!==''?'status-ok':'status-pending'?>"><?=$sefazSettings['sefaz_technical_cnpj']!==''?'Dados preenchidos':'Configuração pendente'?></strong></div><p class="message warning">Use exatamente o CNPJ cadastrado como responsável técnico na SEFAZ/PR. Um CNPJ diferente provoca a rejeição 974.</p></section>
<p class="muted">Preencha e salve quando tiver os dados oficiais do responsável técnico e da SEFAZ.</p>
<form method="post" class="server-settings-form" action="?tab=sefaz" autocomplete="off"><input type="hidden" name="csrf_token" value="<?=$e($_SESSION['platform_server_settings_csrf'])?>"><input type="hidden" name="settings_section" value="sefaz">
 <section class="panel settings-section"><div class="panel-header"><div><h2>Identificação técnica</h2><span class="muted">Dados da empresa responsável pelo desenvolvimento e suporte do MiniERP.</span></div></div><div class="settings-grid">
  <label>CNPJ do responsável técnico<input name="sefaz_technical_cnpj" value="<?=$e($sefazSettings['sefaz_technical_cnpj'])?>" inputmode="numeric" maxlength="18" placeholder="00.000.000/0000-00" required><small>Deve coincidir com o cadastro da software house na SEFAZ.</small></label>
  <label>Nome do contato<input name="sefaz_technical_contact" value="<?=$e($sefazSettings['sefaz_technical_contact'])?>" maxlength="60" required></label>
  <label>E-mail técnico<input type="email" name="sefaz_technical_email" value="<?=$e($sefazSettings['sefaz_technical_email'])?>" required></label>
  <label>Telefone com DDD<input name="sefaz_technical_phone" value="<?=$e($sefazSettings['sefaz_technical_phone'])?>" inputmode="tel" maxlength="16" required></label>
 </div></section>
 <section class="panel settings-section"><div class="panel-header"><div><h2>CSRT</h2><span class="muted">Quando exigido pela UF, o segredo permanece somente em variável de ambiente.</span></div><strong class="<?=$sefazCsrtConfigured?'status-ok':'status-pending'?>"><?=$sefazCsrtConfigured?'Segredo disponível':'Segredo não configurado'?></strong></div><div class="settings-grid">
  <label>ID do CSRT<input name="sefaz_csrt_id" value="<?=$e($sefazSettings['sefaz_csrt_id'])?>" inputmode="numeric" maxlength="2" placeholder="01"><small>Identificador fornecido pela SEFAZ.</small></label>
  <label>Variável de ambiente do CSRT<input name="sefaz_csrt_env" value="<?=$e($sefazSettings['sefaz_csrt_env'])?>" spellcheck="false" placeholder="MINI_ERP_SEFAZ_CSRT" required><small>O segredo não é exibido nem salvo no banco.</small></label>
 </div></section>
 <div class="settings-actions"><span class="muted">Alterações auditadas e aplicadas às próximas NF-e geradas.</span><button class="btn" type="submit" <?=$canEdit?'':'disabled'?>>Salvar configuração SEFAZ</button></div>
</form>
<?php endif?>
<?php renderPlatformEnd();
