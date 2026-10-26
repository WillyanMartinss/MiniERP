<?php
declare(strict_types=1);

// A UI histórica permanece intacta; este controller adiciona o fluxo tenant seguro.
if (session_status() === PHP_SESSION_NONE) session_start();
$tenantSlug = strtolower(trim(is_string($_GET['empresa'] ?? null) ? $_GET['empresa'] : ''));
if ($tenantSlug === '') $tenantSlug = 'willyaninfo';
$tenantLogin = $tenantSlug !== '';
$tenantDisplay = null;
$tenantReader = null;
$tenantUnavailable = false;
$tenantDisplayName = '';
$tenantPolicyMessage = '';

if ($tenantLogin) {
    require_once __DIR__ . '/../src/Contracts/ErpAuthenticationReaderContract.php';
    require_once __DIR__ . '/../src/Context/AuthenticatedTenantUser.php';
    require_once __DIR__ . '/../src/Context/TenantContext.php';
    require_once __DIR__ . '/../src/Adapters/LegacyTenantContextInput.php';
    require_once __DIR__ . '/../src/Context/TenantContextResolver.php';
    require_once __DIR__ . '/../src/Infrastructure/ControlPlaneConnectionFactory.php';
    require_once __DIR__ . '/../src/Infrastructure/TenantConnectionResolver.php';
    require_once __DIR__ . '/../src/Repositories/MainDbErpAuthenticationReader.php';
    require_once __DIR__ . '/../src/Repositories/TenantAccessPolicyRepository.php';
    require_once __DIR__ . '/../src/Services/ErpAuthenticationResult.php';
    require_once __DIR__ . '/../src/Services/ErpAuthenticationService.php';
    try {
        $main = (new \MiniErp\Infrastructure\ControlPlaneConnectionFactory(__DIR__ . '/../config.php'))->create();
        $tenantReader = new \MiniErp\Repositories\MainDbErpAuthenticationReader($main);
        $tenantDisplay = $tenantReader->findTenantBySlug($tenantSlug);
        $tenantUnavailable = $tenantDisplay === null;
        if ($tenantDisplay !== null) {
            require_once __DIR__ . '/../src/Services/TenantLoginLogoStorage.php';
            $tenantDisplay['logo'] = (new \MiniErp\Services\TenantLoginLogoStorage(__DIR__))->url((int) $tenantDisplay['tenant_id']);
            $loginPolicy=(new \MiniErp\Repositories\TenantAccessPolicyRepository($main))->effectiveForTenant((int)$tenantDisplay['tenant_id']);
            if (($loginPolicy['access_mode']??'FULL') === 'BLOCKED') {
                $tenantUnavailable=true;$tenantPolicyMessage='Acesso temporariamente bloqueado.';
                if(trim((string)($loginPolicy['reason']??''))!=='')$tenantPolicyMessage.=' Motivo: '.trim((string)$loginPolicy['reason']).'.';
                if(!empty($loginPolicy['expires_at']))$tenantPolicyMessage.=' Liberação automática em '.date('d/m/Y H:i',strtotime((string)$loginPolicy['expires_at'])).'.';
            }
            $storedName = trim((string) ($tenantDisplay['nome_fantasia'] ?: $tenantDisplay['razao_social']));
            $tenantDisplayName = mb_convert_case($storedName, MB_CASE_TITLE, 'UTF-8');
        }
    } catch (Throwable) {
        $tenantUnavailable = true;
    }
}

// mostra mensagens de erro simples quando redirecionado de `index.php`
$loginError = '';
if (!empty($_GET['error'])) {
    $err = $_GET['error'];
    if ($err === 'invalid') {
        $loginError = 'Credenciais inválidas. Verifique usuário e senha.';
    } elseif ($err === 'inactive') {
        $loginError = 'Usuário inativo. Entre em contato com o administrador.';
    } elseif ($err === 'policy_blocked') {
        $loginError = 'A empresa está temporariamente bloqueada pelo administrador da plataforma.';
    } elseif ($err === 'session') {
        $loginError = 'Não foi possível manter a sessão da empresa. Entre novamente.';
        } elseif ($err === 'unverified') {
            $loginError = 'E-mail não verificado. Verifique sua caixa de entrada.';
    }
}
if (!empty($_GET['registered'])) {
    $loginError = 'Solicitação enviada. O administrador da empresa precisa ativar seu acesso como usuário ou administrador.';
}
if (!empty($_GET['registration_error'])) {
    $loginError = (string)($_SESSION['registration_error'] ?? 'Não foi possível concluir o cadastro. Tente novamente.');
    unset($_SESSION['registration_error']);
}
if (!empty($_GET['oauth_error'])) {
    $loginError = (string)($_SESSION['oauth_error'] ?? 'Não foi possível entrar com a rede social.');
    unset($_SESSION['oauth_error']);
}

if ($tenantUnavailable) {
    $loginError = $tenantPolicyMessage !== '' ? $tenantPolicyMessage : 'Empresa indisponível ou link inválido.';
}

if ($tenantLogin && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string) ($_SESSION['erp_login_csrf'] ?? ''), (string) ($_POST['csrf_token'] ?? ''))) {
        $loginError = 'Sessão expirada. Atualize a página e tente novamente.';
    } elseif ($tenantReader === null || $tenantDisplay === null) {
        $loginError = 'Empresa indisponível ou link inválido.';
    } else {
        try {
            $result = (new \MiniErp\Services\ErpAuthenticationService($tenantReader, new \MiniErp\Context\TenantContextResolver()))
                ->authenticate((string) ($_POST['email'] ?? ''), (string) ($_POST['senha'] ?? ''), $tenantSlug);
            $tenantPdo = (new \MiniErp\Infrastructure\TenantConnectionResolver(__DIR__ . '/../config.php'))->resolve($result->tenantContext);
            $expectedDatabase = 'mini_erp_tenant_' . $result->tenantContext->getEffectiveTenantId();
            if (!hash_equals($expectedDatabase, (string) $tenantPdo->query('SELECT DATABASE()')->fetchColumn())) {
                throw new DomainException('A conexão resolvida não corresponde à empresa autenticada.');
            }
            session_regenerate_id(true);
            $_SESSION['erp_user_id'] = $result->identity->getUserId();
            $_SESSION['erp_tenant_id'] = $result->tenantContext->getEffectiveTenantId();
            $_SESSION['erp_tenant_slug'] = (string) $result->tenant['slug'];
            header('Location: /?page=dashboard');
            exit;
        } catch (DomainException) {
            try {
                require_once __DIR__.'/../src/Repositories/PlatformAdminRepository.php';
                require_once __DIR__.'/../src/Context/AuthenticatedPlatformAdmin.php';
                require_once __DIR__.'/../src/Services/PlatformAuthenticationService.php';
                $platformRepository=new \MiniErp\Repositories\PlatformAdminRepository($main);
                $global=(new \MiniErp\Services\PlatformAuthenticationService($platformRepository))->authenticate((string)($_POST['email']??''),(string)($_POST['senha']??''),$_SERVER['REMOTE_ADDR']??null);
                if(!in_array($global->getRole(),['SUPER_ADMIN','GLOBAL_TECH'],true))throw new DomainException('Papel sem acesso global.');
                $tenantId=(int)$tenantDisplay['tenant_id'];$expectedDb='mini_erp_tenant_'.$tenantId;
                if($tenantId<1||!empty($tenantDisplay['blocked'])||!in_array(strtolower((string)$tenantDisplay['status']),['ativo','ativa','active'],true)||!hash_equals($expectedDb,(string)$tenantDisplay['db_name']))throw new DomainException('Empresa indisponível.');
                $context=new \MiniErp\Context\TenantContext($global->getUserId(),$tenantId,$tenantId);
                (new \MiniErp\Infrastructure\TenantConnectionResolver(__DIR__.'/../config.php'))->resolve($context);
                session_regenerate_id(true);$_SESSION['erp_global_admin_id']=$global->getUserId();$_SESSION['erp_user_id']=$global->getUserId();$_SESSION['erp_tenant_id']=$tenantId;$_SESSION['erp_tenant_slug']=(string)$tenantDisplay['slug'];foreach(['user_id','tenant_id','current_company_id']as$legacySessionKey)unset($_SESSION[$legacySessionKey]);
                $platformRepository->audit($global->getUserId(),'GLOBAL_ERP_LOGIN','tenant',(string)$tenantId,$_SERVER['REMOTE_ADDR']??null,['slug'=>$tenantSlug]);
                header('Location: /?page=dashboard');exit;
            } catch (DomainException) {$loginError = 'Credenciais inválidas ou empresa indisponível.';}
        } catch (Throwable) {
            $loginError = 'Não foi possível abrir o ERP com segurança.';
        }
    }
}

$_SESSION['erp_login_csrf'] ??= bin2hex(random_bytes(32));
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/Favicon-v2/favicon-32x32.png?v=round1">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/Favicon-v2/favicon-16x16.png?v=round1">
    <link rel="apple-touch-icon" href="/assets/images/Favicon-v2/apple-touch-icon.png?v=round1">
    <title>Login - Mini ERP</title>
    <!-- Favicons and manifest -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/Favicon-v2/apple-touch-icon.png?v=round1">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/Favicon-v2/favicon-32x32.png?v=round1">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/Favicon-v2/favicon-16x16.png?v=round1">
    <link rel="manifest" href="/assets/images/site.webmanifest">
    <meta name="theme-color" content="#1e88e5">
    <meta name="description" content="Acesse o Mini ERP - sistema de gestão">
    <link rel="stylesheet" href="/assets/style.css?v=login-mobile3">
    <link rel="stylesheet" href="/assets/login.css?v=login-mobile3">
    <script src="/assets/login.js?v=login-mobile3" defer></script>
</head>
<body class="page-ready">
    <main class="login-shell">
        <div class="container" id="container">
            <header class="login-mobile-brand">
                <img src="/assets/images/logo_login.png" alt="MiniERPWeb" width="2172" height="724">
                <p>Sua gest&atilde;o em um s&oacute; lugar</p>
            </header>
            <div class="form-container sign-up-container">
                <form id="signupForm" method="POST" action="/register.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['erp_login_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="empresa" value="<?= htmlspecialchars($tenantSlug, ENT_QUOTES, 'UTF-8') ?>">
                    <h1>Criar Conta</h1>
                    <div class="social-container">
                        <a href="/oauth.php?provider=facebook&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Cadastrar com Facebook">f</a>
                        <a href="/oauth.php?provider=google&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Cadastrar com Google">G</a>
                        <a href="/oauth.php?provider=linkedin&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Cadastrar com LinkedIn">in</a>
                    </div>
                    <span>ou use seu e-mail para registro</span>
                    <input type="text" name="nome" placeholder="Nome" required />
                    <input type="email" id="signupEmail" name="email" placeholder="Email" autocomplete="section-signup username" inputmode="email" autocapitalize="none" spellcheck="false" required />
                    <input type="tel" name="telefone" placeholder="Telefone (opcional)" autocomplete="tel" />
                    <input type="password" id="signupPassword" name="senha" placeholder="Senha (mínimo 8 caracteres)" autocomplete="section-signup new-password" minlength="8" required />
                    <button type="submit" class="btn primary" id="signupBtnForm" <?= !$tenantLogin || $tenantUnavailable ? 'disabled' : '' ?>>Solicitar acesso</button>
                </form>
            </div>
            <div class="form-container sign-in-container">
                <form id="loginForm" method="POST" action="<?= $tenantLogin ? '/login.php?empresa=' . rawurlencode($tenantSlug) : '/?page=login' ?>">
                    <input type="hidden" name="action" value="<?= $tenantLogin ? 'tenant_login' : 'login' ?>">
                    <?php if ($tenantLogin): ?><input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['erp_login_csrf'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
                    <h1>Entrar</h1>
                    <div class="tenant-login-name" role="group" aria-labelledby="loginCompanyLabel">
                        <span id="loginCompanyLabel" class="tenant-login-label">Empresa que você está acessando</span>
                        <strong id="loginCompanyName"><?= htmlspecialchars($tenantDisplayName !== '' ? $tenantDisplayName : ($tenantLogin ? 'Empresa não identificada' : 'Acesso geral — nenhuma empresa selecionada'), ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                    <?php if($loginError): ?>
                        <div class="login-error" role="alert" style="color:#c62828;margin-bottom:12px;font-weight:700;"><?php echo htmlspecialchars($loginError); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($tenantDisplay)): ?>
                        <?php if (!empty($tenantDisplay['logo'])): ?>
                            <div class="tenant-login-logo"><img src="<?= htmlspecialchars($tenantDisplay['logo']) ?>" alt="Logo"></div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <div class="social-container">
                        <a href="/oauth.php?provider=facebook&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Continuar com Facebook">f</a>
                        <a href="/oauth.php?provider=google&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Continuar com Google">G</a>
                        <a href="/oauth.php?provider=linkedin&amp;empresa=<?= rawurlencode($tenantSlug) ?>" class="social" aria-label="Continuar com LinkedIn">in</a>
                    </div>
                    <span>ou use sua conta</span>
                    <input type="email" id="loginEmail" name="email" placeholder="Email" aria-label="E-mail" autocomplete="section-login username" inputmode="email" autocapitalize="none" spellcheck="false" required />
                    <div class="password-wrap">
                        <input type="password" id="loginPassword" name="senha" placeholder="Senha" autocomplete="section-login current-password" autocapitalize="none" autocorrect="off" spellcheck="false" aria-label="Senha" required>
                        <button type="button" class="toggle-password" id="togglePassword" aria-label="Mostrar senha">👁️</button>
                    </div>
                    <a href="/forgot.php" class="forgot-link">Esqueceu sua senha?</a>
                    <button type="submit" class="btn primary" id="loginBtn" <?= $tenantUnavailable ? 'disabled' : '' ?>>Entrar</button>
                </form>
            </div>
            <div class="overlay-container">
                <div class="overlay">
                    <div class="overlay-panel overlay-left">
                        <h1>Bem de volta!</h1>
                        <p>Para manter-se conectado, faça login com suas informações pessoais</p>
                        <button class="ghost" id="signIn">Entrar</button>
                    </div>
                    <div class="overlay-panel overlay-right">
                        <a href="/" title="MiniERPWeb" class="overlay-logo-link">
                            <img src="/assets/images/logo_login.png" alt="MiniERPWeb" class="overlay-logo" onerror="this.style.display='none'">
                        </a>
                        <p>Insira seus dados pessoais e comece agora</p>
                        <button class="ghost" id="signUp">Registrar</button>
                    </div>
                </div>
            </div>
            <footer class="login-footer" aria-hidden="false">
                <div class="footer-brand">
                    <a href="/" title="MiniERPWeb">
                        <img src="/assets/images/logo_login.png" alt="MiniERPWeb" class="login-footer-logo" onerror="this.style.display='none'">
                    </a>
                </div>
                <div class="footer-dev">Desenvolvido por <a class="dev-link" href="https://willspacce.netlify.app/" target="_blank" rel="noopener noreferrer" aria-label="Portfólio de Willyan Martins">Willyan Martins <span aria-hidden="true">›</span></a></div>
            </footer>
        </div>
    </main>
</body>
</html>
