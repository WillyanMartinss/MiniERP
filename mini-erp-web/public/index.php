<?php

// Carrega as classes essenciais da aplicação.
require_once __DIR__ . '/../app/Database.php';
require_once __DIR__ . '/../app/Repository.php';
require_once __DIR__ . '/../src/Contracts/TaxRuleRepositoryContract.php';
require_once __DIR__ . '/../src/Fiscal/FiscalTaxContext.php';
require_once __DIR__ . '/../src/Fiscal/FiscalTaxRule.php';
require_once __DIR__ . '/../src/Fiscal/FiscalTaxResolution.php';
require_once __DIR__ . '/../src/Fiscal/TaxRuleResolver.php';
require_once __DIR__ . '/../src/Repositories/MariaDbTaxRuleRepository.php';
require_once __DIR__ . '/../src/Repositories/FiscalOperationRepository.php';
require_once __DIR__ . '/../src/Repositories/IssuedOrdersRepository.php';
require_once __DIR__ . '/../src/Repositories/DashboardRepository.php';
require_once __DIR__ . '/../src/Repositories/StockRepository.php';
require_once __DIR__ . '/../src/Repositories/FinancialRepository.php';
require_once __DIR__ . '/../src/Repositories/MasterDataDirectoryRepository.php';
require_once __DIR__ . '/../src/Repositories/FiscalDocumentEventRepository.php';
require_once __DIR__ . '/../src/Services/CreateInternalFiscalDocumentService.php';
require_once __DIR__ . '/../src/Services/FlashFormState.php';
require_once __DIR__ . '/includes/fiscal_preview.php';

// Inicia sessão para autenticação cedo, antes de decidir qual DB usar
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ponte localizada entre a sessão ERP segura e o runtime legado.
$secureErpRuntime = null;
$globalTechnicalId=(int)($_SESSION['erp_global_admin_id']??0);
$hasErpSession = !empty($_SESSION['erp_user_id']) || !empty($_SESSION['erp_tenant_id']) || $globalTechnicalId>0;
if ($globalTechnicalId>0 && !empty($_SESSION['erp_tenant_id'])) {
    require_once __DIR__ . '/../vendor/autoload.php';
    try {
        $main=(new \MiniErp\Infrastructure\ControlPlaneConnectionFactory(__DIR__.'/../config.php'))->create();
        $platformRecord=(new \MiniErp\Repositories\PlatformAdminRepository($main))->findActiveIdentity($globalTechnicalId);
        if(!$platformRecord||!in_array((string)$platformRecord['role'],['SUPER_ADMIN','GLOBAL_TECH'],true))throw new DomainException('Acesso técnico global inválido.');
        $erpReader=new \MiniErp\Repositories\MainDbErpAuthenticationReader($main);$tenantId=(int)$_SESSION['erp_tenant_id'];$tenant=$erpReader->findTenantById($tenantId);
        if(!$tenant||!in_array(strtolower((string)$tenant['status']),['ativo','ativa','active'],true)||!empty($tenant['blocked']))throw new DomainException('Empresa indisponível.');
        $context=new \MiniErp\Context\TenantContext($globalTechnicalId,$tenantId,$tenantId);
        $connection=(new \MiniErp\Infrastructure\TenantConnectionResolver(__DIR__.'/../config.php'))->resolve($context);
        $platformEmail = trim((string) $platformRecord['email']);
        $technicalIdentityEmail = filter_var($platformEmail, FILTER_VALIDATE_EMAIL)
            ? $platformEmail
            : 'platform-admin-' . $globalTechnicalId . '@local.invalid';
        $identity=new \MiniErp\Context\AuthenticatedTenantUser($globalTechnicalId,$tenantId,(string)$platformRecord['name'],$technicalIdentityEmail);
        $secureErpRuntime=['connection'=>$connection,'result'=>new \MiniErp\Services\ErpAuthenticationResult($identity,$context,$tenant),'user'=>['id'=>$globalTechnicalId,'nome'=>$platformRecord['name'],'email'=>$platformRecord['email'],'role'=>'admin','status'=>'ativo','tenant_id'=>$tenantId]];
        Database::useResolvedTenantConnection($connection);
    } catch (Throwable $globalAccessError) {
        error_log('GLOBAL_ERP_SESSION_RESTORE_FAILED type='.get_class($globalAccessError).' message='.substr($globalAccessError->getMessage(),0,300));
        $failedTenantSlug = strtolower(trim((string) ($_SESSION['erp_tenant_slug'] ?? '')));
        unset($_SESSION['erp_global_admin_id'],$_SESSION['erp_user_id'],$_SESSION['erp_tenant_id'],$_SESSION['erp_tenant_slug']);
        session_write_close();
        header('Location: /login.php'.($failedTenantSlug!==''?'?empresa='.rawurlencode($failedTenantSlug).'&error=session':'?error=session'));
        exit;
    }
} elseif ($hasErpSession) {
    require_once __DIR__ . '/../src/Contracts/ErpAuthenticationReaderContract.php';
    require_once __DIR__ . '/../src/Context/AuthenticatedTenantUser.php';
    require_once __DIR__ . '/../src/Context/TenantContext.php';
    require_once __DIR__ . '/../src/Adapters/LegacyTenantContextInput.php';
    require_once __DIR__ . '/../src/Adapters/ErpLegacyBootstrap.php';
    require_once __DIR__ . '/../src/Context/TenantContextResolver.php';
    require_once __DIR__ . '/../src/Infrastructure/ControlPlaneConnectionFactory.php';
    require_once __DIR__ . '/../src/Infrastructure/TenantConnectionResolver.php';
    require_once __DIR__ . '/../src/Repositories/MainDbErpAuthenticationReader.php';
    require_once __DIR__ . '/../src/Services/ErpAuthenticationResult.php';
    require_once __DIR__ . '/../src/Services/ErpAuthenticationService.php';
    try {
        $main = (new \MiniErp\Infrastructure\ControlPlaneConnectionFactory(__DIR__ . '/../config.php'))->create();
        $erpReader = new \MiniErp\Repositories\MainDbErpAuthenticationReader($main);
        $erpAuthentication = new \MiniErp\Services\ErpAuthenticationService($erpReader, new \MiniErp\Context\TenantContextResolver());
        $secureErpRuntime = (new \MiniErp\Adapters\ErpLegacyBootstrap($erpReader, $erpAuthentication, new \MiniErp\Infrastructure\TenantConnectionResolver(__DIR__ . '/../config.php')))->bootstrap($_SESSION);
        Database::useResolvedTenantConnection($secureErpRuntime['connection']);
    } catch (Throwable) {
        unset($_SESSION['erp_user_id'], $_SESSION['erp_tenant_id'], $_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['current_company_id']);
        header('Location: /erp/login.php?error=session');
        exit;
    }
}

// Fluxo legado preservado apenas quando não existe uma sessão ERP segura.
$config = require __DIR__ . '/../config.php';
$dbConf = $config['db'];
if ($secureErpRuntime === null && !empty($_SESSION['tenant_id'])) {
    try {
        $serverDsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbConf['host'], $dbConf['port'], $dbConf['database']);
        $adminPdo = new PDO($serverDsn, $dbConf['username'], $dbConf['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
        $stmt = $adminPdo->prepare('SELECT db_name FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => (int)$_SESSION['tenant_id']]);
        $row = $stmt->fetch();
        if ($row && !empty($row['db_name'])) {
            Database::setTenantDbName($row['db_name']);
        }
    } catch (Throwable $e) {
        // ignore, fallback para DB principal
    }
}

// Cria o repositório que interage com o banco (agora que tenant DB já foi decidido)
$repo = new Repository($secureErpRuntime['connection'] ?? null, $secureErpRuntime === null);

// Resolve tenant from URL slug (first segment) when present.
// Example: /mercado-silva/login  -> tenant slug = mercado-silva
$currentTenant = null;
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
$pathSegments = array_values(array_filter(explode('/', $requestPath)));
if ($secureErpRuntime === null && !empty($pathSegments)) {
    $first = $pathSegments[0];
    // avoid matching assets and known files
    $ignore = ['assets', 'favicon.ico', 'login.php', 'index.php', 'forgot.php', 'reset.php'];
    if (!in_array($first, $ignore, true)) {
        $t = $repo->findTenantBySlug($first);
        if ($t) {
            $_SESSION['tenant_id'] = (int)$t['id'];
            // backward compat
            $_SESSION['current_company_id'] = (int)$t['id'];
                // se o tenant tiver um db_name configurado, força a conexão para este DB
                if (!empty($t['db_name'])) {
                    Database::setTenantDbName($t['db_name']);
                }
            $currentTenant = $t;
        }
    }
}

// Define qual página de menu está ativa.
$page = $_GET['page'] ?? 'dashboard';
$erpPolicyRouteNotice = '';
if (($erpAccessPolicy['access_mode'] ?? 'FULL') === 'READ_ONLY' && in_array($page, ['pedidos','fiscal_notes'], true)) {
    $page = 'dashboard';
    $erpPolicyRouteNotice = 'Modo consulta: Entrada, Saída, Pedidos Emitidos e Central de Notas estão temporariamente indisponíveis.';
}
$legacyMasterPages=['clientes'=>'cliente','fornecedores'=>'fornecedor','motoristas'=>'motorista','transportadoras'=>'transportadora'];
if(isset($legacyMasterPages[$page])){$_GET['people_type']=$legacyMasterPages[$page];if(!empty($_GET['edit'])){$_GET['person']=(int)$_GET['edit'];$_GET['source_type']=$legacyMasterPages[$page];}$page='cadastro';$_GET['tab']='pessoas';}

// Armazena mensagens visuais de sucesso ou erro para o usuário.
$flash = [
    'success' => '',
    'error' => '',
];
if ($erpPolicyRouteNotice !== '') $flash['error'] = $erpPolicyRouteNotice;
$failedClienteData = null;
$failedFormData = null;
$failedFormAction = '';
$erpAccessPolicy = ['access_mode'=>'FULL','can_issue_fiscal'=>1,'can_manage_users'=>1,'can_use_financial'=>1,'expires_at'=>null,'reason'=>''];
if ($secureErpRuntime !== null && $globalTechnicalId === 0) {
    require_once __DIR__ . '/../src/Repositories/TenantAccessPolicyRepository.php';
    try {
        $erpAccessPolicy = (new \MiniErp\Repositories\TenantAccessPolicyRepository($main))->effectiveForTenant((int)$_SESSION['erp_tenant_id']);
        if (($erpAccessPolicy['access_mode'] ?? 'FULL') === 'BLOCKED') {
            unset($_SESSION['erp_user_id'],$_SESSION['erp_tenant_id'],$_SESSION['erp_tenant_slug'],$_SESSION['user_id'],$_SESSION['tenant_id'],$_SESSION['current_company_id']);
            header('Location: /login.php?error=policy_blocked'); exit;
        }
    } catch (Throwable $policyError) { error_log('TENANT_POLICY_READ_FAILED type='.get_class($policyError)); }
}
$restoredFormState = \MiniErp\Services\FlashFormState::consume($_SESSION);
if ($restoredFormState !== null) {
    $failedFormAction = $restoredFormState['action'];
    $failedFormData = $restoredFormState['old_input'];
    $flash['error'] = reset($restoredFormState['errors']) ?: 'Revise os campos informados.';
    if ($failedFormAction === 'save_cliente') $failedClienteData = $failedFormData;
}
$_SESSION['erp_client_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['erp_establishment_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['erp_fiscal_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['erp_stock_csrf'] ??= bin2hex(random_bytes(32));
$_SESSION['erp_financial_csrf'] ??= bin2hex(random_bytes(32));
setcookie('erp_fiscal_csrf', (string)$_SESSION['erp_fiscal_csrf'], ['expires'=>0,'path'=>'/','secure'=>!empty($_SERVER['HTTPS']),'httponly'=>true,'samesite'=>'Strict']);
$erpEstablishmentService = null; $erpEstablishment = null; $erpEstablishmentSchemaAvailable = false;
if ($secureErpRuntime !== null) {
    require_once __DIR__ . '/../src/Contracts/EstablishmentRepositoryContract.php'; require_once __DIR__ . '/../src/Repositories/TenantEstablishmentRepository.php';
    require_once __DIR__ . '/../src/Services/EstablishmentData.php'; require_once __DIR__ . '/../src/Services/EstablishmentService.php'; require_once __DIR__ . '/../src/Services/FiscalReadiness.php'; require_once __DIR__ . '/includes/establishment_form.php';
    $erpEstablishmentRepository = new \MiniErp\Repositories\TenantEstablishmentRepository($secureErpRuntime['connection']); $erpEstablishmentService = new \MiniErp\Services\EstablishmentService($erpEstablishmentRepository);
    $erpEstablishmentSchemaAvailable = $erpEstablishmentRepository->schemaAvailable(); $erpTenantId = $secureErpRuntime['result']->tenantContext->getEffectiveTenantId(); $erpEstablishment = $erpEstablishmentService->find($erpTenantId);
}
if (!empty($_GET['client_saved'])) $flash['success'] = 'Cliente salvo com sucesso.';
if (!empty($_GET['client_deleted'])) $flash['success'] = 'Cliente removido com sucesso.';

// Trata ações de formulário enviados via POST.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        if (($erpAccessPolicy['access_mode'] ?? 'FULL') === 'READ_ONLY' && $action !== 'logout') throw new RuntimeException('Empresa em modo somente consulta. Alterações estão temporariamente bloqueadas.');
        if (in_array($action,['save_usuario','delete_usuario','approve_usuario','assign_user_company','create_admin_account'],true) && empty($erpAccessPolicy['can_manage_users'])) throw new RuntimeException('Gerenciamento de usuários bloqueado pelo Painel da Plataforma.');
        if (in_array($action,['save_fiscal_order','save_fiscal_mirror','save_internal_fiscal_document'],true) && empty($erpAccessPolicy['can_issue_fiscal'])) throw new RuntimeException('Emissão fiscal bloqueada pelo Painel da Plataforma.');
        switch ($action) {
            case 'save_establishment':
                if ($erpEstablishmentService === null || !hash_equals((string) $_SESSION['erp_establishment_csrf'], (string) ($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Sessão fiscal inválida.');
                $erpTenantId = $secureErpRuntime['result']->tenantContext->getEffectiveTenantId(); $erpEstablishment = $erpEstablishmentService->save($erpTenantId, new \MiniErp\Services\EstablishmentData($_POST));
                header('Location: ?page=configuracao&tab=empresa&fiscal_saved=1'); exit;

            case 'save_cliente':
                if (!hash_equals((string) $_SESSION['erp_client_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
                    throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
                }
                // Se fornecido CPF/CNPJ, tentar buscar dados na BrasilAPI para autopreencher
                $cpfcnpjRaw = trim((string)($_POST['cpf_cnpj'] ?? ''));
                if ($cpfcnpjRaw !== '') {
                    $cnpjData = $repo->fetchCnpjData($cpfcnpjRaw);
                    if (is_array($cnpjData)) {
                        $_POST['nome'] = $_POST['nome'] ?? ($cnpjData['legal_name'] ?? '');
                        $_POST['nome_fantasia'] = $_POST['nome_fantasia'] ?? ($cnpjData['trade_name'] ?? '');
                        $_POST['cep'] = $_POST['cep'] ?? ($cnpjData['postal_code'] ?? '');
                        $_POST['uf'] = $_POST['uf'] ?? ($cnpjData['state'] ?? '');
                        $_POST['municipio'] = $_POST['municipio'] ?? ($cnpjData['city'] ?? '');
                        $logradouro = trim((string)($cnpjData['street'] ?? ''));
                        if ($logradouro !== '') $_POST['logradouro'] = $_POST['logradouro'] ?? $logradouro;
                        $_POST['numero'] = $_POST['numero'] ?? ($cnpjData['number'] ?? '');
                        $_POST['complemento'] = $_POST['complemento'] ?? ($cnpjData['complement'] ?? '');
                        $_POST['bairro'] = $_POST['bairro'] ?? ($cnpjData['district'] ?? '');
                        $_POST['telefone'] = $_POST['telefone'] ?? ($cnpjData['phone_1'] ?? '');
                        $_POST['codigo_ibge'] = $_POST['codigo_ibge'] ?? ($cnpjData['city_ibge_code'] ?? '');
                        $_POST['data'] = json_encode($cnpjData, JSON_UNESCAPED_UNICODE);
                    }
                }
                // Salva ou atualiza um cliente.
                $repo->saveCliente($_POST);
                header('Location: ?page=cadastro&tab=pessoas&client_saved=1');
                exit;

            case 'delete_cliente':
                if (!hash_equals((string) $_SESSION['erp_client_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
                    throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
                }
                // Remove um cliente do banco.
                $repo->deleteCliente((int) ($_POST['id'] ?? 0));
                header('Location: ?page=cadastro&tab=pessoas&client_deleted=1');
                exit;

            case 'save_produto':
                // Salva ou atualiza um produto.
                $repo->saveProduto($_POST);
                $flash['success'] = 'Produto salvo com sucesso.';
                break;

            case 'delete_produto':
                // Remove um produto do banco.
                $repo->deleteProduto((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'Produto removido com sucesso.';
                break;

            case 'save_venda':
                // Registra a venda e atualiza estoque. Retorna o ID criado.
                // Passa o CNPJ da empresa (se uma empresa estiver selecionada na sessão)
                $empresaCnpj = null;
                $dataDir = __DIR__ . '/../data';
                if (is_dir($dataDir)) {
                    $empresasFile = $dataDir . '/empresas.json';
                    if (file_exists($empresasFile)) {
                        $empresas = json_decode(file_get_contents($empresasFile), true) ?: [];
                        $currentId = $_SESSION['current_company_id'] ?? null;
                        if ($currentId) {
                            foreach ($empresas as $e) {
                                if ((int)($e['id'] ?? 0) === (int)$currentId) {
                                    $empresaCnpj = preg_replace('/\D/', '', $e['cnpj'] ?? '');
                                    break;
                                }
                            }
                        } elseif (!empty($empresas[0])) {
                            $empresaCnpj = preg_replace('/\D/', '', $empresas[0]['cnpj'] ?? '');
                        }
                    } else {
                        // fallback antigo para compatibilidade
                        $empresaFile = $dataDir . '/empresa.json';
                        if (file_exists($empresaFile)) {
                            $empresa = json_decode(file_get_contents($empresaFile), true) ?: [];
                            $empresaCnpj = preg_replace('/\D/', '', $empresa['cnpj'] ?? '');
                        }
                    }
                }
                if ($empresaCnpj) $_POST['empresa_cnpj'] = $empresaCnpj;

                $vendaId = $repo->createSale($_POST, $_POST['itens'] ?? []);
                $flash['success'] = 'Venda registrada com sucesso.';
                break;

            case 'save_fiscal_order':
            case 'save_fiscal_mirror':
            case 'save_internal_fiscal_document':
                $tenantId = (int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id'] ?? 0);
                $userId = (int)($_SESSION['erp_user_id'] ?? $_SESSION['user_id'] ?? 0);
                if ($tenantId < 1 || $userId < 1) throw new RuntimeException('Contexto autenticado obrigatório.');
                if (!in_array((string)($_POST['fiscal_model'] ?? ''), ['55','65'], true)) throw new RuntimeException('FISCAL_DOCUMENT_MODEL_UNSUPPORTED');
                if (!hash_equals((string)($_SESSION['erp_fiscal_csrf'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('ORDER_CSRF_INVALID');
                if (($_POST['tipo'] ?? 'saida') === 'entrada') $_POST['cliente_id'] = $_POST['fornecedor_id'] ?? 0;
                $operationRepo = new \MiniErp\Repositories\FiscalOperationRepository(Database::getConnection(), $tenantId);
                $operationRepo->assertOrderParties($_POST);
                $_POST['operation_nature']=$operationRepo->validatedOperationNature($_POST);
                $idempotencyKey = (string)($_POST['idempotency_key'] ?? '');
                if ($_POST['action'] === 'save_internal_fiscal_document' && ($existingDocument = $operationRepo->findDocumentByKey($idempotencyKey))) {
                    $flash['success'] = "Documento fiscal interno #{$existingDocument['id']} já havia sido gravado; nenhuma duplicação ocorreu.";
                    break;
                }
                $orderId = (int)($_POST['order_id'] ?? 0);
                $orderId = $operationRepo->saveOrderWithTransport($orderId, $_POST, $_POST['itens'] ?? [], $userId);
                // O repositório retorna apenas depois do COMMIT da transação externa.
                // Releia do banco antes de confirmar ao navegador: o redirect nunca pode
                // anunciar um pedido parcial, de outro tenant ou sem os dados essenciais.
                $savedOrder = $operationRepo->orderWithTransport($orderId);
                $expectedPersonId = (int)(($_POST['tipo'] ?? 'saida') === 'entrada'
                    ? ($_POST['fornecedor_id'] ?? 0)
                    : ($_POST['cliente_id'] ?? 0));
                if (
                    (int)($savedOrder['id'] ?? 0) !== $orderId
                    || (int)($savedOrder['tenant_id'] ?? 0) !== $tenantId
                    || (int)($savedOrder['person_id'] ?? 0) !== $expectedPersonId
                    || empty($savedOrder['items'])
                    || !isset($savedOrder['grand_total'])
                    || !in_array((string)($savedOrder['fiscal_model'] ?? ''), ['55', '65'], true)
                    || empty($savedOrder['operation_date'])
                ) {
                    throw new RuntimeException('ORDER_SAVE_READBACK_FAILED');
                }
                if (($savedOrder['operation_type'] ?? '') === 'ENTRY') {
                    (new \MiniErp\Repositories\StockRepository(Database::getConnection(), $tenantId))->receiveEntryOrder($orderId, $savedOrder['items'], $userId);
                    $savedOrder = $operationRepo->orderWithTransport($orderId);
                }
                (new \MiniErp\Repositories\FinancialRepository(Database::getConnection(), $tenantId))->syncOrder($savedOrder, $userId);
                if ($_POST['action'] === 'save_fiscal_mirror') {
                    $mirrorId = $operationRepo->createMirror($orderId, $userId);
                    $flash['success'] = "Pedido #{$orderId} e Espelho #{$mirrorId} gravados sem emissão fiscal.";
                } elseif ($_POST['action'] === 'save_internal_fiscal_document') {
                    $taxRepo = new \MiniErp\Repositories\MariaDbTaxRuleRepository(Database::getConnection(), $tenantId);
                    $service = new \MiniErp\Services\CreateInternalFiscalDocumentService($operationRepo, new \MiniErp\Fiscal\TaxRuleResolver($taxRepo));
                    $document = $service->create($orderId, $idempotencyKey, $userId);
                    $events = new \MiniErp\Repositories\FiscalDocumentEventRepository(Database::getConnection(), $tenantId);
                    $events->append((int)$document['id'],'DOCUMENT_CREATED','PRECHECK',(string)$document['status'],(string)$document['status'],$document['pending']?'Documento fiscal criado com pendências: '.implode('; ',$document['pending']):'Documento fiscal pronto para preparação local.',[], $userId);
                    $flash['success'] = "Documento fiscal interno #{$document['id']} gravado como {$document['status']}; nenhuma NF-e foi emitida.";
                } else {
                    $flash['success'] = "Pedido #{$orderId} salvo sem baixa de estoque e sem emissão.";
                }
                if ($_POST['action'] === 'save_fiscal_order') {
                    // GRAVAR: salva apenas o pedido comercial, sem criar/alterar documento fiscal,
                    // e redireciona sempre para a lista operacional de Pedidos Emitidos com highlight.
                    header('Location: ?page=pedidos&tab=emitidos&highlight_order='.$orderId);
                    exit;
                }
                break;

            case 'save_empresa':
                // Se fornecido CNPJ, tentar buscar dados na BrasilAPI para autopreencher
                $cnpjRaw = trim((string)($_POST['cnpj'] ?? ''));
                if ($cnpjRaw !== '') {
                    $cnpjData = $repo->fetchCnpjData($cnpjRaw);
                    if (is_array($cnpjData)) {
                        $_POST['razao_social'] = $_POST['razao_social'] ?? ($cnpjData['legal_name'] ?? '');
                        $_POST['nome_fantasia'] = $_POST['nome_fantasia'] ?? ($cnpjData['trade_name'] ?? '');
                        $_POST['cep'] = $_POST['cep'] ?? ($cnpjData['postal_code'] ?? '');
                        $_POST['uf'] = $_POST['uf'] ?? ($cnpjData['state'] ?? '');
                        $_POST['municipio'] = $_POST['municipio'] ?? ($cnpjData['city'] ?? '');
                        $logradouro = trim((string)($cnpjData['street'] ?? ''));
                        if ($logradouro !== '') $_POST['logradouro'] = $_POST['logradouro'] ?? $logradouro;
                        $_POST['numero'] = $_POST['numero'] ?? ($cnpjData['number'] ?? '');
                        $_POST['complemento'] = $_POST['complemento'] ?? ($cnpjData['complement'] ?? '');
                        $_POST['bairro'] = $_POST['bairro'] ?? ($cnpjData['district'] ?? '');
                        $_POST['telefone'] = $_POST['telefone'] ?? ($cnpjData['phone_1'] ?? '');
                        // opcional: armazenar payload bruto para auditoria
                        $_POST['data'] = json_encode($cnpjData, JSON_UNESCAPED_UNICODE);
                    }
                }

                // Save tenant into DB
                $repo->saveCompany($_POST);
                $flash['success'] = 'Empresa salva.';
                break;

            case 'assign_user_company':
                $uid = (int)($_POST['id'] ?? 0);
                $cid = isset($_POST['company_id']) && $_POST['company_id'] !== '' ? (int)$_POST['company_id'] : null;
                if ($uid) {
                    $repo->assignUserToCompany($uid, $cid);
                    $flash['success'] = 'Usuário atualizado com empresa.';
                }
                break;

            case 'select_empresa':
                $id = (int)($_POST['company_id'] ?? 0);
                if ($id) {
                    // set as current tenant in session
                    $_SESSION['tenant_id'] = $id;
                    // backward compatibility
                    $_SESSION['current_company_id'] = $id;
                    // força conexão para o DB do tenant selecionado, se disponível
                    $t = $repo->findTenantById($id);
                    if ($t && !empty($t['db_name'])) {
                        Database::setTenantDbName($t['db_name']);
                    }
                    $flash['success'] = 'Empresa selecionada.';
                    // redireciona diretamente para a página da empresa selecionada
                    header('Location: ?page=company&id=' . $id);
                    exit;
                }
                break;
            case 'create_tenant_user':
                $id = (int)($_POST['id'] ?? 0);
                $email = trim((string)($_POST['email'] ?? ''));
                $senha = (string)($_POST['senha'] ?? '');
                if (!$id || $email === '' || $senha === '') {
                    $flash['error'] = 'ID da empresa, e-mail e senha são obrigatórios.';
                    break;
                }
                try {
                    // garante que o tenant exista no DB principal; se não, tenta persistir a partir do JSON legacy
                    $t = $repo->findTenantById($id);
                    if (!$t) {
                        $dataDir = __DIR__ . '/../data';
                        $empresasFile = $dataDir . '/empresas.json';
                        if (file_exists($empresasFile)) {
                            $empresas = json_decode(file_get_contents($empresasFile), true) ?: [];
                            $found = null;
                            foreach ($empresas as $ee) {
                                if ((int)($ee['id'] ?? 0) === $id) { $found = $ee; break; }
                            }
                            if ($found) {
                                // salva empresa no DB (isso provisiona tenant e cria admin padrão)
                                $repo->saveCompany($found);
                                // tenta localizar pelo CNPJ (o ID pode mudar quando migrado do JSON)
                                if (!empty($found['cnpj'])) {
                                    $t = $repo->findTenantByCnpj($found['cnpj']);
                                }
                                // se ainda não encontrou, tenta localizar por slug (se houver) ou por nome
                                if (!$t) {
                                    if (!empty($found['slug'])) {
                                        try { $t = $repo->findTenantBySlug((string)$found['slug']); } catch (Throwable $e) { /* ignore */ }
                                    }
                                }
                                if (!$t) {
                                    // tentativa final: busca direta no DB principal por nome_fantasia
                                    try {
                                        $config = require __DIR__ . '/../config.php';
                                        $dbConf = $config['db'];
                                        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', $dbConf['host'], $dbConf['port'], $dbConf['database']);
                                        $pdoMain = new PDO($dsn, $dbConf['username'], $dbConf['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
                                        $name = trim((string)($found['nome_fantasia'] ?? $found['apelido'] ?? ''));
                                        if ($name !== '') {
                                            $row = $pdoMain->prepare('SELECT * FROM tenants WHERE nome_fantasia = :nome LIMIT 1');
                                            $row->execute(['nome' => $name]);
                                            $t = $row->fetch() ?: null;
                                        }
                                    } catch (Throwable $e) {
                                        // ignore
                                    }
                                }
                                // se ainda não encontrou, tenta por id antigo como último recurso
                                if (!$t) {
                                    $t = $repo->findTenantById($id);
                                }
                            }
                        }
                    }

                    if (!$t) throw new Exception('Empresa/tenant não encontrada no banco. Primeiro cadastre a empresa.');

                    // cria/atualiza usuário central
                    $repo->createOrUpdateAdmin($email, $senha, 'Administrador');

                    // obtém usuário e associa à empresa/tenant
                    $user = $repo->findUsuarioByEmail($email);
                    if ($user) {
                        // Assign user to company/tenant explicitly without mutating session
                        require_once __DIR__ . '/../src/Repositories/MainDbUserRepository.php';
                        $mainRepo = new \MiniErp\Repositories\MainDbUserRepository();
                        try {
                            $mainRepo->assignUserToCompanyExplicit((int)$user['id'], $id, $id);
                        } catch (Throwable $e) {
                            throw $e;
                        }
                    }

                    // cria link direto para o login da empresa (usa slug quando disponível)
                    $loginTenant = $t['slug'] ?? $t['id'];
                    $flash['success'] = 'Usuário criado e associado à empresa.';
                    $flash['company_login'] = '/login.php?tenant=' . urlencode($loginTenant);
                } catch (Throwable $e) {
                    $flash['error'] = 'Erro ao criar usuário: ' . $e->getMessage();
                }
                break;
            case 'create_tenant_admin':
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    try {
                        $t = $repo->findTenantById($id);
                        if (!$t) {
                            // tenta migrar a partir do JSON legacy
                            $dataDir = __DIR__ . '/../data';
                            $empresasFile = $dataDir . '/empresas.json';
                            if (file_exists($empresasFile)) {
                                $empresas = json_decode(file_get_contents($empresasFile), true) ?: [];
                                foreach ($empresas as $ee) {
                                    if ((int)($ee['id'] ?? 0) === $id) {
                                        $repo->saveCompany($ee);
                                        if (!empty($ee['cnpj'])) {
                                            $t = $repo->findTenantByCnpj($ee['cnpj']);
                                        }
                                        if (!$t) $t = $repo->findTenantById($id);
                                        break;
                                    }
                                }
                            }
                        }

                        if (!$t) throw new Exception('Tenant não encontrado após tentativa de migração (id=' . $id . ')');

                        $repo->createTenantAdmin((int)$t['id']);
                        $flash['success'] = 'Conta administrativa criada/garantida para a empresa (id=' . $t['id'] . ').';
                        $loginTenant = $t['slug'] ?? $t['id'];
                        $flash['company_login'] = '/login.php?tenant=' . urlencode($loginTenant);
                    } catch (Throwable $e) {
                        $flash['error'] = 'Erro ao criar conta administrativa para id=' . $id . ': ' . $e->getMessage();
                    }
                }
                break;
            case 'delete_empresa':
                $id = (int)($_POST['id'] ?? 0);
                if ($id) {
                    $repo->deleteCompany($id);
                    if (isset($_SESSION['tenant_id']) && (int)$_SESSION['tenant_id'] === $id) {
                        unset($_SESSION['tenant_id']);
                        unset($_SESSION['current_company_id']);
                    }
                    $flash['success'] = 'Empresa removida.';
                }
                break;
                case 'toggle_block_empresa':
                    $id = (int)($_POST['id'] ?? 0);
                    $blocked = isset($_POST['blocked']) && $_POST['blocked'] === '1' ? true : false;
                    if ($id) {
                        $repo->setCompanyBlocked($id, $blocked);
                        $flash['success'] = $blocked ? 'Empresa bloqueada; logins desabilitados.' : 'Empresa desbloqueada; logins habilitados.';
                    }
                    break;

            case 'save_product_taxes':
                // Salva impostos no banco via Repository
                $prodId = (int)($_POST['product_id'] ?? 0);
                $taxes = [
                    'ipi' => $_POST['ipi'] ?? '',
                    'icms' => $_POST['icms'] ?? '',
                    'pis' => $_POST['pis'] ?? '',
                    'cofins' => $_POST['cofins'] ?? '',
                ];
                $repo->saveProductTaxes($prodId, $taxes);
                $flash['success'] = 'Impostos salvos para o produto (banco).';
                break;

            case 'save_cfop':
                $repo->saveCfop($_POST);
                $flash['success'] = 'CFOP salvo com sucesso.';
                break;

            case 'delete_cfop':
                $repo->deleteCfop((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'CFOP removido com sucesso.';
                break;

            case 'save_fornecedor':
                $cpfcnpjRaw = trim((string)($_POST['cpf_cnpj'] ?? ''));
                if ($cpfcnpjRaw !== '') {
                    $cnpjData = $repo->fetchCnpjData($cpfcnpjRaw);
                    if (is_array($cnpjData)) {
                        $_POST['nome'] = $_POST['nome'] ?? ($cnpjData['legal_name'] ?? '');
                        $_POST['nome_fantasia'] = $_POST['nome_fantasia'] ?? ($cnpjData['trade_name'] ?? '');
                        $_POST['cep'] = $_POST['cep'] ?? ($cnpjData['postal_code'] ?? '');
                        $_POST['uf'] = $_POST['uf'] ?? ($cnpjData['state'] ?? '');
                        $_POST['municipio'] = $_POST['municipio'] ?? ($cnpjData['city'] ?? '');
                        $logradouro = trim((string)($cnpjData['street'] ?? ''));
                        if ($logradouro !== '') $_POST['logradouro'] = $_POST['logradouro'] ?? $logradouro;
                        $_POST['numero'] = $_POST['numero'] ?? ($cnpjData['number'] ?? '');
                        $_POST['complemento'] = $_POST['complemento'] ?? ($cnpjData['complement'] ?? '');
                        $_POST['bairro'] = $_POST['bairro'] ?? ($cnpjData['district'] ?? '');
                        $_POST['telefone'] = $_POST['telefone'] ?? ($cnpjData['phone_1'] ?? '');
                        $_POST['codigo_ibge'] = $_POST['codigo_ibge'] ?? ($cnpjData['city_ibge_code'] ?? '');
                        $_POST['data'] = json_encode($cnpjData, JSON_UNESCAPED_UNICODE);
                    }
                }
                $repo->saveFornecedor($_POST);
                $flash['success'] = 'Fornecedor salvo com sucesso.';
                break;

            case 'delete_fornecedor':
                $repo->deleteFornecedor((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'Fornecedor removido com sucesso.';
                break;

            case 'save_motorista':
                $repo->saveMotorista($_POST);
                $flash['success'] = 'Motorista salvo com sucesso.';
                break;

            case 'delete_motorista':
                $repo->deleteMotorista((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'Motorista removido com sucesso.';
                break;

            case 'save_transportadora':
                $repo->saveTransportadora($_POST);
                $flash['success'] = 'Transportadora salva com sucesso.';
                break;

            case 'delete_transportadora':
                $repo->deleteTransportadora((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'Transportadora removida com sucesso.';
                break;

            case 'save_usuario':
                $isNew = empty($_POST['id']);
                $repo->saveUsuario($_POST);
                if ($isNew && ($page === 'save_usuario' || $page === 'login')) {
                    // se veio do formulário público (signup), redireciona para login mostrando instrução
                    header('Location: /login.php?registered=1');
                    exit;
                }
                $flash['success'] = 'Funcionário salvo com sucesso.';
                break;

            case 'create_admin_account':
                $email = trim((string)($_POST['email'] ?? ''));
                $senha = (string)($_POST['senha'] ?? '');
                $nome = trim((string)($_POST['nome'] ?? 'Administrador'));
                if ($email === '' || $senha === '') {
                    throw new InvalidArgumentException('E-mail e senha são obrigatórios para criar a conta administrativa.');
                }
                $repo->createOrUpdateAdmin($email, $senha, $nome);
                $flash['success'] = 'Conta administrativa criada/atualizada com sucesso.';
                break;

            case 'delete_usuario':
                $repo->deleteUsuario((int) ($_POST['id'] ?? 0));
                $flash['success'] = 'Funcionário removido com sucesso.';
                break;

            case 'approve_usuario':
                $repo->approveUsuario((int) ($_POST['id'] ?? 0), (string)($_POST['approval_role']??'user'));
                $flash['success'] = 'Usuário aprovado e ativado.';
                break;

            case 'login':
                $email = trim((string)($_POST['email'] ?? ''));
                $senha = (string)($_POST['senha'] ?? '');
                $user = $repo->findUsuarioByEmail($email);
                $isDefaultAdmin = strtolower((string)($email ?? '')) === 'admin@localhost';
                $isValidDefaultAdmin = $isDefaultAdmin && $senha === 'admin';
                // Somente autentica se o usuário existir e a senha bater.
                if (!$user || (!password_verify($senha, $user['senha']) && !$isValidDefaultAdmin)) {
                    // Redireciona de volta para a página de login com erro.
                    header('Location: /login.php?error=invalid');
                    exit;
                }

                // Verifica se usuário está ativo
                if (isset($user['status']) && $user['status'] !== 'ativo') {
                    header('Location: /login.php?error=inactive');
                    exit;
                }

                // Verifica se e-mail foi confirmado (admin@localhost e administradores são isentos)
                if (isset($user['email_verified']) && ((int)$user['email_verified']) === 0) {
                    $emailLower = strtolower((string)($user['email'] ?? ''));
                    $role = (string)($user['role'] ?? '');
                    if ($emailLower !== 'admin@localhost' && strtolower($role) !== 'admin') {
                        header('Location: /login.php?error=unverified');
                        exit;
                    }
                }

                // Sucesso: cria sessão, define tenant e empresa corrente e redireciona para dashboard
                $_SESSION['user_id'] = (int)$user['id'];
                // Prioriza tenant_id direto do usuário
                if (!empty($user['tenant_id'])) {
                    $_SESSION['tenant_id'] = (int) $user['tenant_id'];
                    $t = $repo->findTenantById((int)$user['tenant_id']);
                    if ($t && !empty($t['db_name'])) {
                        Database::setTenantDbName($t['db_name']);
                    }
                } elseif (!empty($user['company_id'])) {
                    // Compatibilidade: tenta resolver tenant a partir do company_id
                    $company = $repo->findCompany((int)$user['company_id']);
                    if ($company) {
                        $_SESSION['tenant_id'] = (int) $company['id'];
                        $t = $repo->findTenantById((int)$company['id']);
                        if ($t && !empty($t['db_name'])) {
                            Database::setTenantDbName($t['db_name']);
                        }
                    }
                    $_SESSION['current_company_id'] = (int) $user['company_id'];
                }
                // Garante um tenant de sessão mínimo para ambientes de desenvolvimento.
                if (empty($_SESSION['tenant_id'])) {
                    $_SESSION['tenant_id'] = 1;
                }
                header('Location: ?page=dashboard');
                exit;
                break;

            case 'logout':
                if ($secureErpRuntime !== null) {
                    $logoutTenantSlug = strtolower(trim((string) ($_SESSION['erp_tenant_slug'] ?? '')));
                    unset($_SESSION['erp_user_id'], $_SESSION['erp_global_admin_id'], $_SESSION['erp_tenant_id'], $_SESSION['erp_tenant_slug'], $_SESSION['user_id'], $_SESSION['tenant_id'], $_SESSION['current_company_id'], $_SESSION['erp_login_csrf'], $_SESSION['erp_logout_csrf']);
                    session_regenerate_id(true);
                    header('Location: /login.php' . ($logoutTenantSlug !== '' ? '?empresa=' . rawurlencode($logoutTenantSlug) : ''));
                    exit;
                }
                session_unset();
                session_destroy();
                header('Location: /login.php');
                exit;
                break;
        }
    } catch (Throwable $e) {
        // Captura qualquer erro e mostra a mensagem para o usuário.
        $flash['error'] = $e->getMessage();
        if (str_starts_with((string) $action, 'save_')) {
            $failedFormData = array_diff_key($_POST, array_flip(['senha', 'password', 'certificate_password', 'csrf_token']));
            $failedFormAction = (string) $action;
        }
        if ($action === 'save_cliente') $failedClienteData = $failedFormData;
        \MiniErp\Services\FlashFormState::store($_SESSION, (string)$action, $_POST, ['_form' => $e->getMessage()]);
        $redirect = (string)($_SERVER['REQUEST_URI'] ?? '/');
        if (str_starts_with($redirect, '/') && !str_contains($redirect, "\r") && !str_contains($redirect, "\n")) {
            header('Location: ' . $redirect); exit;
        }
    }
}

if ($failedFormAction === 'save_establishment' && is_array($failedFormData)) {
    $erpEstablishment = array_merge($erpEstablishment ?? [], $failedFormData);
}

// Coleta os dados principais para uso nas telas.
$currentUser = null;
if ($secureErpRuntime !== null) {
    $currentUser = $secureErpRuntime['user'];
} elseif (!empty($_SESSION['user_id'])) {
    $currentUser = $repo->findUsuarioById((int) $_SESSION['user_id']);
}
$canUseOrderTestFill = strtolower((string)($currentUser['role'] ?? '')) === 'admin'
    && ($globalTechnicalId > 0 || ((int)($currentUser['id'] ?? 0) === 9
        && (int)($_SESSION['erp_user_id'] ?? $_SESSION['user_id'] ?? 0) === 9));

// Se não estiver na página de login e não existe usuário autenticado, redireciona para login
if ($page !== 'login' && !$currentUser) {
    header('Location: /login.php');
    exit;
}

if ($page === 'transporte') require __DIR__.'/includes/transport_controller.php';

$dashboardToday=new DateTimeImmutable('today',new DateTimeZone('America/Sao_Paulo'));
$validDashboardDate=static fn(string$value):bool=>preg_match('/^\d{4}-\d{2}-\d{2}$/',$value)===1;
$dashboardFrom=(string)($_GET['from']??$dashboardToday->modify('-29 days')->format('Y-m-d'));
$dashboardTo=(string)($_GET['to']??$dashboardToday->format('Y-m-d'));
if(!$validDashboardDate($dashboardFrom)||!$validDashboardDate($dashboardTo)||$dashboardFrom>$dashboardTo){$dashboardFrom=$dashboardToday->modify('-29 days')->format('Y-m-d');$dashboardTo=$dashboardToday->format('Y-m-d');}
$dashboardFilters=['from'=>$dashboardFrom,'to'=>$dashboardTo,'customer_id'=>max(0,(int)($_GET['customer_id']??0)),'model'=>in_array((string)($_GET['model']??''),['55','65'],true)?(string)$_GET['model']:'','status'=>in_array((string)($_GET['status']??''),['pending','rejected','authorized','preparing'],true)?(string)$_GET['status']:''];
$dashboardRepository = new \MiniErp\Repositories\DashboardRepository(
    Database::getConnection(),
    (int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id'] ?? 0),
    new DateTimeZone('America/Sao_Paulo')
);
$dashboard = $dashboardRepository->analytics($dashboardFilters);
$dashboard['financial']=(new \MiniErp\Repositories\FinancialRepository(Database::getConnection(),(int)($_SESSION['erp_tenant_id']??$_SESSION['tenant_id']??0)))->summary();
$dashboardCustomers=$dashboardRepository->customerOptions();
$clientes = $repo->listClientes((string) ($_GET['q'] ?? ''));
$produtos = $repo->listProdutos();
$stockSelectableLots=[];if($page==='pedidos'){try{$stockSelectableLots=(new \MiniErp\Repositories\StockRepository(Database::getConnection(),(int)($_SESSION['erp_tenant_id']??$_SESSION['tenant_id']??0)))->selectableLots();}catch(Throwable$stockLotsError){error_log('ORDER_STOCK_LOTS_LOAD_FAILED type='.get_class($stockLotsError));}}
$vendas = $repo->listVendas();
$cfops = $repo->listCfops();
$fornecedores = $repo->listFornecedores();
$motoristas = $repo->listMotoristas();
$transportadoras = $repo->listTransportadoras();

// Busca o cliente que será editado na tela de clientes.
$editCliente = null;
if ($page === 'clientes' && isset($_GET['edit'])) {
    $editCliente = $repo->findCliente((int) $_GET['edit']);
}
if ($failedFormAction === 'save_cliente') $editCliente = $failedFormData;

$editPessoa = null;
if ($page === 'cadastro' && isset($_GET['tab']) && $_GET['tab'] === 'pessoas' && isset($_GET['edit'])) {
    $editPessoa = $repo->findCliente((int) $_GET['edit']);
}
if ($failedClienteData !== null) $editPessoa = $failedClienteData;

// Busca o produto que será editado na tela de produtos.
$editProduto = null;
if ($page === 'produtos' && isset($_GET['edit'])) {
    $editProduto = $repo->findProduto((int) $_GET['edit']);
}
if ($failedFormAction === 'save_produto') $editProduto = $failedFormData;

$editCfop = null;
if ($page === 'cadastro' && isset($_GET['tab']) && $_GET['tab'] === 'cfops' && isset($_GET['edit'])) {
    $editCfop = $repo->findCfop((int) $_GET['edit']);
}
if ($failedFormAction === 'save_cfop') $editCfop = $failedFormData;

$editFornecedor = null;
if ($page === 'cadastro' && isset($_GET['tab']) && $_GET['tab'] === 'fornecedores' && isset($_GET['edit'])) {
    $editFornecedor = $repo->findFornecedor((int) $_GET['edit']);
}
if ($failedFormAction === 'save_fornecedor') $editFornecedor = $failedFormData;

$editMotorista = null;
if ($page === 'cadastro' && isset($_GET['tab']) && $_GET['tab'] === 'motoristas' && isset($_GET['edit'])) {
    $editMotorista = $repo->findMotorista((int) $_GET['edit']);
}
if ($failedFormAction === 'save_motorista') $editMotorista = $failedFormData;

$editTransportadora = null;
if ($page === 'cadastro' && isset($_GET['tab']) && $_GET['tab'] === 'transportadoras' && isset($_GET['edit'])) {
    $editTransportadora = $repo->findTransportadora((int) $_GET['edit']);
}
if ($failedFormAction === 'save_transportadora') $editTransportadora = $failedFormData;

// Edit usuário (funcionário) via parametro
$editUsuario = null;
if ($page === 'configuracao' && isset($_GET['edit_user'])) {
    $editUsuario = $repo->findUsuarioById((int) $_GET['edit_user']);
}
if ($failedFormAction === 'save_usuario') $editUsuario = $failedFormData;

// Formata dinheiro em reais para exibir no HTML.
function formatCurrency(float $value): string
{
    return 'R$ ' . number_format($value, 2, ',', '.');
}

// Define se o item do menu está ativo.
function navClass(string $pageName, string $currentPage): string
{
    return $pageName === $currentPage || ($pageName === 'pedidos' && $currentPage === 'fiscal_notes') ? 'active' : '';
}

// Detecta as imagens de logo disponíveis no diretório de assets (fallbacks).
$imagesDir = __DIR__ . '/assets/images';
$assetUrl = static function (string $path): string {
    $relative = 'assets/' . ltrim($path, '/');
    $file = __DIR__ . '/' . $relative;
    return $relative . (is_file($file) ? '?v=' . filemtime($file) : '');
};
$logoUrl = $assetUrl('images/LOGO.png');
$loaderGifUrl = null;
if (is_dir($imagesDir)) {
    // Preferir o arquivo 'mini-erp-logo.png' (o logo principal enviado),
    // depois o 'logo_login.png' (ícone usado no login), e em seguida outros fallbacks.
    if (file_exists($imagesDir . '/mini-erp-logo.png')) {
        $logoUrl = $assetUrl('images/mini-erp-logo.png');
    } elseif (file_exists($imagesDir . '/logo_login.png')) {
        $logoUrl = $assetUrl('images/logo_login.png');
    } elseif (file_exists($imagesDir . '/LOGO.png')) {
        $logoUrl = $assetUrl('images/LOGO.png');
    } elseif (file_exists($imagesDir . '/logo.png')) {
        $logoUrl = $assetUrl('images/logo.png');
    }

    if (file_exists($imagesDir . '/gif_logo.gif')) {
        $loaderGifUrl = $assetUrl('images/gif_logo.gif');
    } elseif (file_exists($imagesDir . '/loader.gif')) {
        $loaderGifUrl = $assetUrl('images/loader.gif');
    }
}

// A página de login é servida por /login.php
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/Favicon-v2/favicon-32x32.png?v=round1">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/Favicon-v2/favicon-16x16.png?v=round1">
    <link rel="apple-touch-icon" href="/assets/images/Favicon-v2/apple-touch-icon.png?v=round1">
    <title>Mini ERP</title>
    <!-- Favicons -->
    <link rel="apple-touch-icon" sizes="180x180" href="/assets/images/Favicon-v2/apple-touch-icon.png?v=round1">
    <link rel="icon" type="image/png" sizes="32x32" href="/assets/images/Favicon-v2/favicon-32x32.png?v=round1">
    <link rel="icon" type="image/png" sizes="16x16" href="/assets/images/Favicon-v2/favicon-16x16.png?v=round1">
    <meta name="theme-color" content="#1e88e5">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('style.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('issued-orders.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('erp-companies.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('erp-companies-modern.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('app-ui.css')) ?>">
    <?php if ($page === 'transporte'): ?><link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('transport.css')) ?>"><?php endif; ?>
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('app-feedback.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('ui-forms.css')) ?>">
    <link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('fiscal-notes.css')) ?>">
    <?php if($page === 'pedidos'): ?><link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('order-editor.css')) ?>"><?php endif; ?>
    <?php if($page === 'cadastro' && ($_GET['tab']??'') === 'xml'): ?><link rel="stylesheet" href="<?= htmlspecialchars($assetUrl('xml-catalog.css')) ?>"><?php endif; ?>
    <script src="<?= htmlspecialchars($assetUrl('vendor/feather.min.js')) ?>"></script>
    <script src="<?= htmlspecialchars($assetUrl('cnpj-lookup.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars($assetUrl('app-ui.js')) ?>" defer></script>
    <script src="<?= htmlspecialchars($assetUrl('app-feedback.js')) ?>" defer></script>
</head>
<body class="<?= ($erpAccessPolicy['access_mode'] ?? 'FULL') === 'READ_ONLY' ? 'erp-read-only' : '' ?>">
    <div id="page-loader" class="page-loader" aria-hidden="false">
        <div class="loader-inner">
            <?php if ($loaderGifUrl): ?>
                <img src="<?= htmlspecialchars($loaderGifUrl) ?>" alt="Carregando" class="loader-logo" onerror="this.style.display='none'">
            <?php else: ?>
                <img src="<?= htmlspecialchars($logoUrl) ?>" alt="Mini ERP Web" class="loader-logo" onerror="this.style.display='none'">
            <?php endif; ?>
        </div>
    </div>
    <div class="app-shell">
        <!-- Menu principal no topo (antes era sidebar) -->
        
        <button id="hamburger" class="hamburger" aria-label="Abrir menu" aria-controls="sidebar-drawer" aria-expanded="false">☰</button>

        <div id="drawer-backdrop" class="drawer-backdrop" aria-hidden="true"></div>

        <aside id="sidebar-drawer" class="sidebar-drawer" aria-hidden="true">
            <div class="drawer-inner">
                <div class="drawer-mobile-head">
                    <div><strong>Menu principal</strong><span>Navegue pelo Mini ERP</span></div>
                    <button type="button" class="drawer-close" data-drawer-close aria-label="Fechar menu">×</button>
                </div>
                <button type="button" class="drawer-expand-control" data-drawer-expand aria-expanded="false" title="Abrir menu completo" aria-label="Abrir menu completo"><i data-feather="menu"></i><span>Menu completo</span></button>
                <ul class="drawer-cats">
                    <li class="drawer-cat">
                        <button class="cat-toggle <?= $page === 'financeiro' ? 'active' : '' ?>" aria-expanded="<?= $page === 'financeiro' ? 'true' : 'false' ?>"><i data-feather="dollar-sign"></i><span>Financeiro</span></button>
                        <ul class="drawer-submenu <?= $page === 'financeiro' ? 'open' : '' ?>">
                            <li><a class="<?= $page === 'financeiro' && ($_GET['financial_tab'] ?? 'receivable') === 'receivable' ? 'active' : '' ?>" href="?page=financeiro&amp;financial_tab=receivable">Contas a receber</a></li>
                            <li><a class="<?= $page === 'financeiro' && ($_GET['financial_tab'] ?? '') === 'payable' ? 'active' : '' ?>" href="?page=financeiro&amp;financial_tab=payable">Contas a pagar</a></li>
                        </ul>
                    </li>
                    <li class="drawer-cat">
                        <button class="cat-toggle <?= $page === 'dashboard' ? 'active' : '' ?>" aria-expanded="<?= $page === 'dashboard' ? 'true' : 'false' ?>"><i data-feather="home"></i><span>Dashboard</span></button>
                        <ul class="drawer-submenu <?= $page === 'dashboard' ? 'open' : '' ?>">
                            <li><a class="<?= $page === 'dashboard' && ($_GET['tab'] ?? 'overview') === 'overview' ? 'active' : '' ?>" href="?page=dashboard&amp;tab=overview">Visão geral</a></li>
                            <li><a class="<?= $page === 'dashboard' && ($_GET['tab'] ?? '') === 'sales' ? 'active' : '' ?>" href="?page=dashboard&amp;tab=sales">Vendas</a></li>
                            <li><a class="<?= $page === 'dashboard' && ($_GET['tab'] ?? '') === 'financial' ? 'active' : '' ?>" href="?page=dashboard&amp;tab=financial">Financeiro</a></li>
                        </ul>
                    </li>

                    <li class="drawer-cat">
                        <button class="cat-toggle" aria-expanded="false"><i data-feather="file-text"></i><span>Pedidos</span></button>
                        <ul class="drawer-submenu">
                            <li><a href="?page=pedidos&tab=entrada">Entrada</a></li>
                            <li><a href="?page=pedidos&tab=saida">Saída</a></li>
                            <li><a href="?page=pedidos&tab=emitidos">Pedidos Emitidos</a></li><li><a class="<?= $page === 'fiscal_notes' ? 'active' : '' ?>" href="?page=fiscal_notes">Central de Notas</a></li>
                        </ul>
                    </li>

                    <li class="drawer-cat">
                        <button class="cat-toggle" aria-expanded="false"><i data-feather="users"></i><span>Cadastro</span></button>
                        <ul class="drawer-submenu">
                            <li><a href="?page=cadastro&tab=pessoas">Pessoas</a></li>
                            <li><a href="?page=cadastro&tab=produtos">Produtos</a></li>
                            <li><a href="?page=cadastro&tab=cfops">CFOPs</a></li>
                            <li><a href="?page=cadastro&tab=xml">Cadastro por XML</a></li>
                        </ul>
                    </li>

                    <li class="drawer-section-label" aria-hidden="true">Operação de estoque</li>
                    <li class="drawer-cat">
                        <button class="cat-toggle <?= $page === 'estoque' ? 'active' : '' ?>" aria-expanded="<?= $page === 'estoque' ? 'true' : 'false' ?>"><i data-feather="package"></i><span>Estoque</span></button>
                        <ul class="drawer-submenu <?= $page === 'estoque' ? 'open' : '' ?>">
                            <li><a class="<?= $page === 'estoque' && ($_GET['stock_tab'] ?? 'products') === 'products' ? 'active' : '' ?>" href="?page=estoque&amp;stock_tab=products">Produtos e saldos</a></li>
                            <li><a class="<?= $page === 'estoque' && ($_GET['stock_tab'] ?? '') === 'lots' ? 'active' : '' ?>" href="?page=estoque&amp;stock_tab=lots">Lotes</a></li>
                            <li><a class="<?= $page === 'estoque' && ($_GET['stock_tab'] ?? '') === 'movements' ? 'active' : '' ?>" href="?page=estoque&amp;stock_tab=movements">Movimentações</a></li>
                            <li><a class="<?= $page === 'estoque' && ($_GET['stock_tab'] ?? '') === 'locations' ? 'active' : '' ?>" href="?page=estoque&amp;stock_tab=locations">Locais</a></li>
                        </ul>
                    </li>

                    <li class="drawer-cat">
                        <button class="cat-toggle <?= $page === 'transporte' ? 'active' : '' ?>" aria-expanded="false"><i data-feather="truck"></i><span>Transporte</span></button>
                        <ul class="drawer-submenu">
                            <?php foreach (['cte'=>'CT-e','mdfe'=>'MDF-e','veiculos'=>'Veículos','configuracoes'=>'Configurações de transporte'] as $transportKey=>$transportLabel): ?>
                            <li><a class="<?= $page === 'transporte' && ($_GET['tab'] ?? 'cte') === $transportKey ? 'active' : '' ?>" href="?page=transporte&amp;tab=<?= $transportKey ?>"><?= htmlspecialchars($transportLabel) ?></a></li>
                            <?php endforeach; ?>
                        </ul>
                    </li>
                    <li class="drawer-cat">
                        <button class="cat-toggle" aria-expanded="false"><i data-feather="settings"></i><span>Configuração</span></button>
                        <ul class="drawer-submenu">
                            <li><a href="?page=configuracao&tab=empresa">Empresa</a></li>
                            <li><a href="?page=configuracao&tab=usuarios">Usuários</a></li>
                            <li><a href="?page=configuracao#fiscal">Fiscal</a></li>
                            <li><a href="?page=configuracao#nfce">NFC-e</a></li>
                            <li><a href="?page=configuracao#contador">Contador</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </aside>
        <script src="<?= htmlspecialchars($assetUrl('sidebar-menu.js')) ?>"></script>

        <main class="main-content">
            <!-- Cabeçalho da aplicação -->
            <header class="topbar">
                <div class="brand">
                    <a class="brand-home-link" href="?page=dashboard" aria-label="Voltar ao Dashboard" title="Voltar ao Dashboard"><img src="<?= htmlspecialchars($logoUrl) ?>" alt="Mini ERP Web" class="site-logo" onerror="this.style.display='none'"></a>
                </div>

                <nav class="topnav">
                    <div class="menu">
                        <div class="menu-item-wrapper"><a class="menu-item <?= navClass('dashboard', $page) ?>" href="?page=dashboard">Dashboard</a></div>
                        <div class="menu-item-wrapper">
                            <a class="menu-item <?= navClass('pedidos', $page) ?>" href="?page=pedidos">PEDIDOS</a>
                            <div class="submenu">
                                <a href="?page=pedidos&tab=entrada">Entrada</a>
                                <a href="?page=pedidos&tab=saida">Saída</a>
                                <a href="?page=pedidos&tab=emitidos">Pedidos Emitidos</a>
                                <a class="<?= $page === 'fiscal_notes' ? 'active' : '' ?>" href="?page=fiscal_notes">Central de Notas</a>
                            </div>
                        </div>
                        <div class="menu-item-wrapper">
                            <a class="menu-item <?= navClass('cadastro', $page) ?>" href="?page=cadastro">CADASTRO</a>
                            <div class="submenu">
                                <a href="?page=cadastro&tab=pessoas">Pessoas</a>
                                <a href="?page=cadastro&tab=produtos">Produtos</a>
                                <a href="?page=cadastro&tab=cfops">CFOPs</a>
                                <a href="?page=cadastro&tab=xml">Cadastro por XML</a>
                            </div>
                        </div>
                        <div class="menu-item-wrapper"><a class="menu-item <?= navClass('estoque', $page) ?>" href="?page=estoque">ESTOQUE</a></div>
                        <div class="menu-item-wrapper">
                            <a class="menu-item <?= navClass('transporte', $page) ?>" href="?page=transporte">TRANSPORTE</a>
                            <div class="submenu"><a href="?page=transporte&amp;tab=cte">CT-e</a><a href="?page=transporte&amp;tab=mdfe">MDF-e</a><a href="?page=transporte&amp;tab=veiculos">Ve&#237;culos</a><a href="?page=transporte&amp;tab=configuracoes">Configura&#231;&#245;es de transporte</a></div>
                        </div>
                        <div class="menu-item-wrapper">
                            <a class="menu-item <?= navClass('configuracao', $page) ?>" href="?page=configuracao">CONFIGURAÇÃO</a>
                            <div class="submenu">
                                <a href="?page=configuracao&tab=empresa">Empresa</a>
                                <a href="?page=configuracao&tab=usuarios">Usuários</a>
                                <a href="?page=configuracao#fiscal">Fiscal</a>
                                <a href="?page=configuracao#nfce">NFC-e</a>
                                <a href="?page=configuracao#contador">Contador</a>
                            </div>
                        </div>
                    </div>
                </nav>

                <div class="user-pill"> 
                    <?php if (!empty($currentUser)): ?>
                        <div class="avatar-circle">
                            <?php if (!empty($currentUser['avatar'])): ?>
                                <img src="<?= htmlspecialchars($currentUser['avatar']) ?>" alt="<?= htmlspecialchars($currentUser['nome']) ?>">
                            <?php else: ?>
                                <span><?= htmlspecialchars(mb_substr($currentUser['nome'], 0, 1)) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="user-name"><?= htmlspecialchars($currentUser['nome']) ?></div>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="logout">
                            <button class="btn ghost" type="submit">Sair</button>
                        </form>
                    <?php else: ?>
                        <a class="btn primary" href="/login.php">Entrar</a>
                    <?php endif; ?>
                </div>
            </header>

            <?php if (($erpAccessPolicy['access_mode'] ?? 'FULL') === 'READ_ONLY'): ?>
                <div class="alert warning"><strong>Modo somente consulta.</strong> Alterações estão bloqueadas<?= !empty($erpAccessPolicy['expires_at']) ? ' até '.htmlspecialchars(date('d/m/Y H:i', strtotime((string)$erpAccessPolicy['expires_at']))) : '' ?>.</div>
            <?php elseif (empty($erpAccessPolicy['can_issue_fiscal'])): ?>
                <div class="alert warning"><strong>Emissão fiscal bloqueada.</strong> A consulta dos dados continua disponível.</div>
            <?php endif; ?>

            <!-- Mensagens de feedback ao usuário -->
            <?php if (!empty($flash['success'])): ?>
                <div class="alert success">
                    <?= htmlspecialchars($flash['success']) ?>
                    <?php if (!empty($flash['company_login'])): ?>
                        <div style="margin-top:8px;"><a class="btn primary" href="<?= htmlspecialchars($flash['company_login']) ?>">Ir para login desta empresa</a></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($flash['error'])): ?>
                <div class="alert error"><?= htmlspecialchars($flash['error']) ?></div>
            <?php endif; ?>

            <?php
            // Renderiza a seção correspondente à página ativa.
            switch ($page) {
                case 'fiscal_notes':
                    define('FISCAL_NOTES_EMBEDDED', true);
                    ob_start();
                    include __DIR__ . '/fiscal_notes.php';
                    $fiscalNotesDocument = (string) ob_get_clean();
                    if (preg_match('/(<section class="fiscal-notes-shell"[^>]*>.*?<\/section><\/section>).*?(<dialog id="fiscal-detail-modal".*?<\/dialog>).*?(<script type="application\/json" id="fiscal-center-action-state">.*?<\/script>)/s', $fiscalNotesDocument, $fiscalNotesParts)) {
                        echo $fiscalNotesParts[1], $fiscalNotesParts[2], $fiscalNotesParts[3];
                    } else {
                        echo '<section class="panel"><h2>Central de Notas</h2><p>Não foi possível montar a página. Tente novamente.</p></section>';
                    }
                    ?>
                    <script src="<?= htmlspecialchars($assetUrl('fiscal-notes.js')) ?>"></script>
                    <script src="<?= htmlspecialchars($assetUrl('fiscal-notes-context.js')) ?>"></script>
                    <?php
                    break;
                case 'company':
                    $companyId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
                    $company = $companyId ? $repo->findCompany($companyId) : null;
                    $companyData = [];
                    if ($company) {
                        $companyData = json_decode($company['data'] ?? '{}', true) ?: [];
                    }
                    ?>
                    <section class="page-header">
                        <div>
                            <p class="eyebrow">Empresa</p>
                            <h2><?= htmlspecialchars($company['nome_fantasia'] ?? ($companyData['nome_fantasia'] ?? 'Empresa')) ?></h2>
                        </div>
                        <a class="btn secondary" href="?page=configuracao&tab=empresa">Voltar</a>
                    </section>

                    <div class="panel">
                        <?php if (!$company): ?>
                            <p>Empresa não encontrada.</p>
                        <?php else: ?>
                            <h3>Dados principais</h3>
                            <table>
                                <tr><th>Apelido</th><td><?= htmlspecialchars($company['nome_fantasia'] ?? ($companyData['nome_fantasia'] ?? '')) ?></td></tr>
                                <tr><th>Razão Social</th><td><?= htmlspecialchars($company['razao_social'] ?? ($companyData['razao_social'] ?? '')) ?></td></tr>
                                <tr><th>CNPJ</th><td><?= htmlspecialchars($company['cnpj'] ?? ($companyData['cnpj'] ?? '')) ?></td></tr>
                                <tr><th>CEP</th><td><?= htmlspecialchars($companyData['cep'] ?? '') ?></td></tr>
                                <tr><th>Logradouro</th><td><?= htmlspecialchars((($companyData['descricao_tipo_de_logradouro'] ?? '') . ' ' . ($companyData['logradouro'] ?? '')) ?? '') ?></td></tr>
                                <tr><th>Número</th><td><?= htmlspecialchars($companyData['numero'] ?? '') ?></td></tr>
                                <tr><th>Complemento</th><td><?= htmlspecialchars($companyData['complemento'] ?? '') ?></td></tr>
                                <tr><th>Bairro</th><td><?= htmlspecialchars($companyData['bairro'] ?? '') ?></td></tr>
                                <tr><th>Cidade / UF</th><td><?= htmlspecialchars(($companyData['municipio'] ?? '') . ' / ' . ($companyData['uf'] ?? '')) ?></td></tr>
                                <tr><th>Telefone</th><td><?= htmlspecialchars($companyData['ddd_telefone_1'] ?? $companyData['telefone'] ?? '') ?></td></tr>
                                <tr><th>Código IBGE</th><td><?= htmlspecialchars($companyData['codigo_ibge'] ?? $companyData['codigo_municipal'] ?? '') ?></td></tr>
                            </table>

                            <h3>Payload BrasilAPI (raw)</h3>
                            <pre style="white-space:pre-wrap;max-height:360px;overflow:auto;background:#f6f6f6;padding:12px;border-radius:6px;"><?= htmlspecialchars(json_encode($companyData, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre>
                        <?php endif; ?>
                    </div>
                    <?php
                    break;
                case 'pedidos':
                    $tab = $_GET['tab'] ?? 'entrada';
                    $fiscalOperationRepo = new \MiniErp\Repositories\FiscalOperationRepository(Database::getConnection(), (int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id']));
                    $issuedFilters=['q'=>mb_substr(trim((string)($_GET['io_q']??'')),0,120),'from'=>(string)($_GET['io_from']??''),'to'=>(string)($_GET['io_to']??''),'model'=>(string)($_GET['io_model']??''),'status'=>(string)($_GET['io_status']??'')];
                    $issuedData=['rows'=>[],'total'=>0,'page'=>1,'pages'=>1,'per_page'=>20];
                    if($tab==='emitidos'){
                        try{$issuedData=(new \MiniErp\Repositories\IssuedOrdersRepository(Database::getConnection(),(int)($_SESSION['erp_tenant_id']??$_SESSION['tenant_id'])))->paginate($issuedFilters,(int)($_GET['io_page']??1),(int)($_GET['io_per_page']??20));}
                        catch(\Throwable $issuedLoadError){error_log('ISSUED_ORDERS_LOAD_FAILED type='.get_class($issuedLoadError));$issuedLoadMessage='NÃ£o foi possÃ­vel carregar os pedidos emitidos.';}
                        $fiscalOrders=$issuedData['rows'];
                    }else{$fiscalOrders = $fiscalOperationRepo->listOrders($tab === 'entrada' ? 'ENTRY' : 'EXIT');}
                    $editingOrder = isset($_GET['order_id']) ? $fiscalOperationRepo->orderWithTransport((int)$_GET['order_id']) : null;
                    $previewCompanyModel = '55';
                    if(!empty($erpEstablishment['id'])){
                        try{
                            $previewModelQuery = Database::getConnection()->prepare('SELECT primary_model FROM establishment_fiscal_settings WHERE tenant_id=? AND establishment_id=? LIMIT 1');
                            $previewModelQuery->execute([(int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id']),(int)$erpEstablishment['id']]);
                            $configuredPreviewModel = (string)($previewModelQuery->fetchColumn() ?: '');
                            if(in_array($configuredPreviewModel,['55','65'],true))$previewCompanyModel=$configuredPreviewModel;
                        }catch(\Throwable $previewModelError){
                            error_log('ORDER_PRIMARY_MODEL_READ_FAILED tenant='.(int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id']).' type='.get_class($previewModelError));
                        }
                    }
                    $previewSelectedModel = (string)($editingOrder['fiscal_model'] ?? $previewCompanyModel);
                    $viewMirror = isset($_GET['mirror_id']) ? $fiscalOperationRepo->mirror((int)$_GET['mirror_id']) : null;
                    $viewDocument = isset($_GET['document_id']) ? $fiscalOperationRepo->document((int)$_GET['document_id']) : null;
                    if ($viewDocument) {
                        $documentOrder = $fiscalOperationRepo->order((int)$viewDocument['source_order_id']);
                        $viewDocument['totals']['model'] = $documentOrder['fiscal_model'];
                    }
                    ?>
                    <div class="order-page-shell<?= $tab !== 'emitidos' ? ' order-editor-page' : '' ?>">
                        <div class="order-page-header">
                            <div class="order-page-header__crumbs">
                                <a href="?page=pedidos">Pedidos</a>
                                <span>›</span>
                                <span>Novo pedido</span>
                            </div>
                            <div class="order-page-header__content">
                                <div class="order-page-title-wrap">
                                    <span class="order-page-title-icon" aria-hidden="true">◫</span>
                                    <div>
                                        <h1>Novo pedido de venda</h1>
                                    </div>
                                </div>
                                <div class="order-page-actions">
                                    <?php if($tab!=='emitidos'): ?><button type="button" class="btn btn-secondary" data-order-cancel title="Voltar para Pedidos Emitidos">Cancelar</button><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="pedido-shell">
                        <?php if ($tab === 'emitidos'): ?>
                            <form class="issued-filters" method="get">
                                <input type="hidden" name="page" value="pedidos"><input type="hidden" name="tab" value="emitidos">
                                <label>Pesquisar<input name="io_q" value="<?= htmlspecialchars($issuedFilters['q']) ?>" placeholder="Pedido, cliente, CPF/CNPJ ou chave"></label>
                                <label>Data inicial<input type="date" name="io_from" value="<?= htmlspecialchars($issuedFilters['from']) ?>"></label>
                                <label>Data final<input type="date" name="io_to" value="<?= htmlspecialchars($issuedFilters['to']) ?>"></label>
                                <label>Modelo<select name="io_model"><option value="">Todos</option><option value="55"<?= $issuedFilters['model']==='55'?' selected':'' ?>>55 — NF-e</option><option value="65"<?= $issuedFilters['model']==='65'?' selected':'' ?>>65 — NFC-e</option></select></label>
                                <label>Status<select name="io_status"><option value="">Todos</option><?php foreach(['NOT_CREATED'=>'Não iniciado','FISCAL_PENDING'=>'Pendente fiscal','FISCAL_READY'=>'Pronto','DOCUMENT_OUTDATED'=>'Documento desatualizado'] as $value=>$label): ?><option value="<?= $value ?>"<?= $issuedFilters['status']===$value?' selected':'' ?>><?= $label ?></option><?php endforeach; ?></select></label>
                                <label>Por página<select name="io_per_page"><?php foreach([10,20,50,100] as $n): ?><option value="<?= $n ?>"<?= $issuedData['per_page']===$n?' selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></label>
                                <div class="issued-filter-actions"><button class="btn btn-small" type="submit">Filtrar</button><a class="btn btn-small secondary" href="?page=pedidos&amp;tab=emitidos">Limpar</a></div>
                            </form>

                            <?php if ($viewMirror): $snap=$viewMirror['snapshot']; ?>
                                <div class="message warning" style="text-align:center;font-size:18px"><strong>PRÉVIA DANFE / ESPELHO — SEM VALOR FISCAL — NÃO TRANSMITIDO À SEFAZ</strong></div>
                                <div class="panel"><h2>ESPELHO DO PEDIDO #<?= (int)$viewMirror['source_order_id'] ?> — versão <?= (int)$viewMirror['snapshot_version'] ?></h2><p class="message warning">Este é um Espelho interno e não possui valor fiscal.</p><p>Criado em <?= htmlspecialchars($viewMirror['created_at']) ?> por usuário #<?= (int)$viewMirror['created_by'] ?></p><p>Total: <?= formatCurrency((float)($snap['grand_total']??0)) ?></p><table><thead><tr><th>Produto</th><th>Quantidade</th><th>Preço</th><th>Total</th></tr></thead><tbody><?php foreach(($snap['items']??[]) as $i): ?><tr><td><?= htmlspecialchars($i['nome']??'') ?></td><td><?= htmlspecialchars((string)$i['quantity']) ?></td><td><?= formatCurrency((float)$i['unit_price']) ?></td><td><?= formatCurrency((float)$i['net_total']) ?></td></tr><?php endforeach; ?></tbody></table><button class="btn secondary" onclick="window.print()">Imprimir Espelho</button></div>
                            <?php elseif ($viewDocument): renderFiscalPreview($viewDocument); ?>
                                <div class="panel"><h2>Documento Fiscal Interno v<?= (int)$viewDocument['document_version'] ?></h2><p class="message warning">Documento fiscal interno — ainda não transmitido à SEFAZ.</p><span class="status-badge"><?= htmlspecialchars($viewDocument['status']) ?></span><?php if($viewDocument['status']==='FISCAL_READY'): ?><p>Pronto para futura geração do XML.</p><?php endif; ?><h3>Pendências</h3><ul><?php foreach($viewDocument['pending'] as $p): ?><li><?= htmlspecialchars($p) ?></li><?php endforeach; ?></ul><h3>Emitente snapshot</h3><pre><?= htmlspecialchars(json_encode($viewDocument['issuer_snapshot'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><h3>Destinatário snapshot</h3><pre><?= htmlspecialchars(json_encode($viewDocument['recipient_snapshot'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><h3>Itens e tributação</h3><?php foreach($viewDocument['items'] as $i): ?><pre><?= htmlspecialchars(json_encode(['produto'=>json_decode($i['product_snapshot_json'],true),'tributacao'=>json_decode($i['tax_resolution_json']?:'null',true)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre><?php endforeach; ?><h3>Totais / Pagamento / Transporte</h3><pre><?= htmlspecialchars(json_encode(['totais'=>$viewDocument['totals'],'pagamento'=>$viewDocument['payment_snapshot'],'transporte'=>$viewDocument['transport_snapshot']],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)) ?></pre></div>
                            <?php else: ?><?php $savedOrderId=max(0,(int)($_GET['highlight_order']??$_GET['saved_order']??0)); ?>
                            <?php if($savedOrderId>0): ?><div class="message success">Pedido #<?= $savedOrderId ?> salvo. Ele já está disponível na lista operacional abaixo.</div><?php endif; ?>
                            <div class="emitted-table-wrap" data-issued-orders data-csrf="<?= htmlspecialchars((string)($_SESSION['erp_fiscal_csrf']??'')) ?>">
                                <?php if(!empty($issuedLoadMessage)): ?><div class="message error"><?= htmlspecialchars($issuedLoadMessage) ?></div><?php endif; ?>
                                <table class="emitted-table">
                                    <thead>
                                        <tr>
                                            <th>Pedido</th>
                                            <th>Data</th>
                                            <th>Cliente / fornecedor</th>
                                            <th>Total</th><th>Modelo</th><th>Status</th><th>Fiscal</th>
                                            <th>Ações</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($fiscalOrders as $v): ?>
                                            <?php $editTab=$v['operation_type']==='ENTRY'?'entrada':'saida';$locked=!empty($v['reservation_id']); ?>
                                            <tr class="issued-row<?= $savedOrderId===(int)$v['id']?' is-highlighted':'' ?>" tabindex="0" data-edit-url="?page=pedidos&amp;tab=<?= $editTab ?>&amp;order_id=<?= (int)$v['id'] ?>">
                                                <td data-label="Pedido"><strong>#<?= (int)$v['id'] ?></strong><small><?= htmlspecialchars((string)($v['internal_code']??'')) ?></small></td>
                                                <td data-label="Data"><?= htmlspecialchars(date('d/m/Y', strtotime($v['operation_date']))) ?></td>
                                                <td data-label="Cliente / fornecedor"><?= htmlspecialchars($v['person_name']) ?></td>
                                                <td data-label="Total"><strong><?= htmlspecialchars(formatCurrency((float)$v['grand_total'])) ?></strong></td>
                                                <td data-label="Modelo"><?= htmlspecialchars((string)$v['fiscal_model']) ?></td>
                                                <td data-label="Status"><span class="status-badge"><?= htmlspecialchars((string)$v['fiscal_status']) ?></span></td>
                                                <td data-label="Fiscal"><?php if($locked): ?><span class="fiscal-lock" title="Numeração fiscal reservada">🔒 Nº <?= htmlspecialchars((string)$v['fiscal_number']) ?></span><?php elseif($v['document_id']): ?>Documento #<?= (int)$v['document_id'] ?><?php else: ?>Não iniciado<?php endif; ?></td>
                                                <td class="table-actions" data-label="Ações">
                                                    <a class="issued-action" href="?page=pedidos&amp;tab=<?= $editTab ?>&amp;order_id=<?= (int)$v['id'] ?>" title="Editar pedido" aria-label="Editar pedido">✎ <span>Editar</span></a>
                                                    <button class="issued-action" type="button" data-issued-action="preview" data-order-id="<?= (int)$v['id'] ?>" title="Gerar <?= $v['fiscal_model']==='65'?'Prévia DANFC-e':'Prévia DANFE' ?>" aria-label="Gerar <?= $v['fiscal_model']==='65'?'Prévia DANFC-e':'Prévia DANFE' ?>">▤ <span><?= $v['fiscal_model']==='65'?'Prévia DANFC-e':'Prévia DANFE' ?></span></button>
                                                    <button class="issued-action primary" type="button" data-issued-action="issue" data-order-id="<?= (int)$v['id'] ?>" data-idempotency="<?= hash('sha256',session_id().'|issue|'.(int)$v['id'].'|'.bin2hex(random_bytes(8))) ?>" title="Gerar, assinar e baixar XML local" aria-label="Assinar e baixar XML local">✍ <span>Assinar XML</span></button>
                                                    <details class="issued-more"><summary title="Mais ações" aria-label="Mais ações">⋮</summary><div><button type="button" data-issued-action="clone" data-order-id="<?= (int)$v['id'] ?>" title="Clonar pedido">⧉ Clonar</button><button type="button" class="danger" data-issued-action="delete" data-order-id="<?= (int)$v['id'] ?>" data-order-label="#<?= (int)$v['id'] ?> — <?= htmlspecialchars($v['person_name']) ?> — <?= htmlspecialchars(date('d/m/Y',strtotime($v['operation_date']))) ?> — <?= htmlspecialchars(formatCurrency((float)$v['grand_total'])) ?>" title="Excluir pedido"<?= $locked?' disabled':'' ?>>🗑 Excluir</button></div></details>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                                <?php $ioQuery=$_GET; ?><footer class="issued-pagination"><span><?= (int)$issuedData['total'] ?> registro(s)</span><nav><?php for($p=max(1,$issuedData['page']-2);$p<=min($issuedData['pages'],$issuedData['page']+2);$p++):$ioQuery['io_page']=$p; ?><a class="<?= $p===$issuedData['page']?'active':'' ?>" href="?<?= htmlspecialchars(http_build_query($ioQuery)) ?>"><?= $p ?></a><?php endfor; ?></nav></footer>
                            </div>
                            <dialog id="issued-delete-modal" class="app-modal"><div class="app-modal__surface"><header class="app-modal__header"><h2>Excluir pedido?</h2><button type="button" data-app-modal-close aria-label="Fechar">×</button></header><div class="app-modal__body"><p>Esta ação excluirá somente um pedido sem vínculo fiscal.</p><strong data-issued-delete-label></strong></div><footer class="app-modal__footer"><button class="btn secondary" type="button" data-app-modal-close>Cancelar</button><button class="btn danger" type="button" data-confirm-issued-delete>Excluir pedido</button></footer></div></dialog>
                            <!-- Contrato legado preservado: data-danfe-preview / Prévia DANFE. A lista usa o fluxo POST seguro acima. -->
                            <script src="<?= htmlspecialchars($assetUrl('issued-orders.js')) ?>"></script><?php endif; ?>
                        <?php else: ?>
                            <?php
                            $orderDirection=$tab==='entrada'?'ENTRY':'EXIT';
                            $applicableCfops=array_values(array_filter($cfops,static function(array$cf)use($orderDirection):bool{if(($cf['status']??'ativo')!=='ativo')return false;$code=preg_replace('/\D/','',(string)($cf['codigo']??''));return$orderDirection==='ENTRY'?in_array($code[0]??'',['1','2','3'],true):in_array($code[0]??'',['5','6','7'],true);}));
                            $currentNature=trim((string)($editingOrder['operation_nature']??''));$selectedCfopId=0;foreach($applicableCfops as$cf)if(trim((string)($cf['descricao']??''))===$currentNature){$selectedCfopId=(int)$cf['id'];break;}
                            ?>
                            <form method="POST" id="pedido-form" class="pedido-form">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars((string)($_SESSION['erp_fiscal_csrf'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="tipo" value="<?= $tab ?>">
                                <input type="hidden" name="order_id" value="<?= (int)($editingOrder['id'] ?? 0) ?>">
                                <input type="hidden" name="establishment_id" value="<?= (int)($erpEstablishment['id'] ?? 0) ?>">
                                <input type="hidden" name="idempotency_key" value="<?= hash('sha256', session_id() . '|' . bin2hex(random_bytes(16))) ?>">

                                <div class="pedido-section order-card">
                                    <div class="section-head">
                                        <div>
                                            <p class="eyebrow">Operação fiscal</p>
                                            <h3>Defina a natureza da operação e as informações fiscais do documento.</h3>
                                        </div>
                                    </div>
                                    <div class="order-grid order-grid--fiscal">
                                        <div class="order-field order-field--nature">
                                            <label>Natureza da operação</label>
                                            <select name="operation_nature" data-order-nature required>
                                                <option value="">Selecione o CFOP</option>
                                                <?php foreach($applicableCfops as$cf):$nature=trim((string)($cf['descricao']??'')); ?><option value="<?= htmlspecialchars($nature) ?>" data-cfop-id="<?= (int)$cf['id'] ?>"<?= $selectedCfopId===(int)$cf['id']?' selected':'' ?>><?= htmlspecialchars($nature) ?></option><?php endforeach; ?>
                                                <?php if($currentNature!==''&&$selectedCfopId===0): ?><option value="<?= htmlspecialchars($currentNature) ?>" selected disabled>Legado — <?= htmlspecialchars($currentNature) ?></option><?php endif; ?>
                                            </select>
                                        </div>
                                        <div class="order-field order-field--model">
                                            <label>Modelo fiscal pretendido</label>
                                            <select name="fiscal_model" id="fiscal-model-select"><option value="55"<?= $previewSelectedModel==='55'?' selected':'' ?>>55 — NF-e / DANFE A4</option><option value="65"<?= $previewSelectedModel==='65'?' selected':'' ?>>65 — NFC-e / DANFC-e cupom</option></select>
                                        </div>
                                        <div class="order-field order-field--purpose">
                                            <label>Finalidade</label>
                                            <select name="purpose"><option value="NORMAL">Normal</option><option value="RETURN">Devolução</option></select>
                                        </div>
                                        <div class="order-field order-field--presence">
                                            <label>Presença</label>
                                            <select name="presence_indicator"><option value="1">1 — Presencial</option><option value="2">2 — Internet</option><option value="9">9 — Outros</option></select>
                                        </div>
                                    </div>
                                    <div class="order-grid order-grid--fiscal-footer">
                                        <div class="order-field order-field--cfop">
                                            <label>CFOP</label>
                                            <select name="cfop_id" data-order-cfop required>
                                                <option value="">Selecione CFOP</option>
                                                <?php foreach ($applicableCfops as $cf): ?>
                                                    <option value="<?= (int)$cf['id'] ?>" data-nature="<?= htmlspecialchars(trim((string)($cf['descricao']??''))) ?>"<?= $selectedCfopId===(int)$cf['id']?' selected':'' ?>><?= htmlspecialchars(($cf['codigo'] ?? '') . ' - ' . ($cf['descricao'] ?? '')) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="order-field order-field--toggle">
                                            <label class="switch-field">
                                                <span>Consumidor final</span>
                                                <span class="switch-control" role="switch" aria-checked="false" tabindex="0">
                                                    <input type="checkbox" name="final_consumer" value="1" class="switch-input" aria-label="Consumidor final">
                                                    <span class="switch-track"><span class="switch-thumb"></span></span>
                                                    <span class="switch-state">Não</span>
                                                </span>
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <div class="pedido-section order-card">
                                    <div class="section-head">
                                        <div>
                                            <p class="eyebrow">Dados do pedido</p>
                                            <h3>Informações do documento e do cliente.</h3>
                                        </div>
                                    </div>
                                    <div class="order-grid order-grid--customer">
                                        <div class="order-field order-field--client">
                                            <label>Cliente / Pessoa</label>
                                            <div class="search-select-wrap">
                                                <span class="search-select-icon" aria-hidden="true">⌕</span>
                                                <select name="cliente_id" class="customer-select" <?= $tab === 'saida' ? 'required' : '' ?>>
                                                    <option value="">Pesquisar cliente por nome, CPF ou CNPJ...</option>
                                                    <?php foreach ($clientes as $c): ?>
                                                        <option value="<?= (int)$c['id'] ?>" <?= (int)($editingOrder['person_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <?php if (!empty($editingOrder['person_id'])): ?>
                                                <?php $selectedClient = null; foreach ($clientes as $c) { if ((int)$c['id'] === (int)($editingOrder['person_id'] ?? 0)) { $selectedClient = $c; break; } } ?>
                                                <?php if ($selectedClient): ?>
                                                    <div class="selected-customer-summary">
                                                        <strong><?= htmlspecialchars($selectedClient['nome']) ?></strong>
                                                        <span>CPF/CNPJ: <?= htmlspecialchars($selectedClient['cpf_cnpj'] ?? '-') ?></span>
                                                    </div>
                                                <?php endif; ?>
                                            <?php endif; ?>
                                        </div>
                                        <div class="order-field order-field--date">
                                            <label>Data do pedido</label>
                                            <div class="date-wrap">
                                                <input type="date" name="data_venda" value="<?= htmlspecialchars($editingOrder['operation_date'] ?? date('Y-m-d')) ?>">
                                            </div>
                                        </div>
                                        <div class="order-field order-field--code">
                                            <label>Código interno</label>
                                            <input type="text" name="codigo_interno" placeholder="Ex.: 000123">
                                        </div>
                                        <div class="order-field order-field--seller">
                                            <label>Vendedor</label>
                                            <select name="vendedor_id">
                                                <option value="">Sem vendedor</option>
                                                <?php foreach ($clientes as $c): ?>
                                                    <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['nome']) ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div class="pedido-section order-card logistics-card" id="logistics-card">
                                    <div class="section-head">
                                        <div>
                                            <p class="eyebrow">Logística e transporte</p>
                                            <h3>Informações de fornecedor, transporte e responsáveis pela entrega.</h3>
                                        </div>
                                        <button type="button" class="order-collapse-toggle" data-collapse-target="logistics-body" aria-expanded="true">
                                            <span>Recolher</span>
                                            <span class="collapse-icon" aria-hidden="true">↑</span>
                                        </button>
                                    </div>
                                    <div class="logistics-body" id="logistics-body">
                                        <div class="order-grid order-grid--logistics">
                                            <div class="order-field">
                                                <label>Modalidade do frete</label>
                                                <?php $selectedFreightMode=(string)($editingOrder['freight_mode']??'9'); ?>
                                                <select name="freight_mode">
                                                    <?php foreach(['0'=>'0 — Emitente','1'=>'1 — Destinatário','2'=>'2 — Terceiros','3'=>'3 — Próprio emitente','4'=>'4 — Próprio destinatário','9'=>'9 — Sem transporte']as$code=>$label): ?>
                                                        <option value="<?= $code ?>"<?= $selectedFreightMode===$code?' selected':'' ?>><?= htmlspecialchars($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="order-field">
                                                <label>Fornecedor</label>
                                                <select name="fornecedor_id" <?= $tab === 'entrada' ? 'required' : '' ?>>
                                                    <option value="">Selecione um fornecedor</option>
                                                    <?php foreach ($fornecedores as $f): ?>
                                                        <option value="<?= (int)$f['id'] ?>"><?= htmlspecialchars($f['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="order-field">
                                                <label>Transportadora</label>
                                                <select name="transportadora_id">
                                                    <option value="">Selecione uma transportadora</option>
                                                    <?php foreach ($transportadoras as $t): ?>
                                                    <option value="<?= (int)$t['id'] ?>" <?= (int)($editingOrder['carrier_id'] ?? 0) === (int)$t['id'] ? 'selected' : '' ?>><?= htmlspecialchars($t['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                            <div class="order-field">
                                                <label>Motorista</label>
                                                <select name="motorista_id">
                                                    <option value="">Selecione um motorista</option>
                                                    <?php foreach ($motoristas as $m): ?>
                                                    <option value="<?= (int)$m['id'] ?>" <?= (int)($editingOrder['driver_id'] ?? 0) === (int)$m['id'] ? 'selected' : '' ?>><?= htmlspecialchars($m['nome']) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            </div>
                                        </div>
                                        <h4>Veículo</h4>
                                        <div class="order-grid order-grid--logistics">
                                            <div class="order-field"><label>Placa</label><input name="vehicle_plate" maxlength="10" value="<?= htmlspecialchars((string)($editingOrder['vehicle_plate']??'')) ?>"></div>
                                            <div class="order-field"><label>UF</label><input name="vehicle_state" maxlength="2" value="<?= htmlspecialchars((string)($editingOrder['vehicle_state']??'')) ?>"></div>
                                            <div class="order-field"><label>RNTC / ANTT</label><input name="vehicle_rntc" maxlength="20" value="<?= htmlspecialchars((string)($editingOrder['vehicle_rntc']??'')) ?>"></div>
                                        </div>
                                        <h4>Volumes</h4>
                                        <div class="order-grid order-grid--logistics">
                                            <div class="order-field"><label>Quantidade</label><input type="number" name="volume_quantity" min="0" step="1" value="<?= htmlspecialchars((string)($editingOrder['volume_quantity']??'')) ?>"></div>
                                            <div class="order-field"><label>Espécie</label><input name="volume_species" maxlength="60" value="<?= htmlspecialchars((string)($editingOrder['volume_species']??'')) ?>"></div>
                                            <div class="order-field"><label>Marca</label><input name="volume_brand" maxlength="60" value="<?= htmlspecialchars((string)($editingOrder['volume_brand']??'')) ?>"></div>
                                            <div class="order-field"><label>Numeração</label><input name="volume_numbering" maxlength="60" value="<?= htmlspecialchars((string)($editingOrder['volume_numbering']??'')) ?>"></div>
                                            <div class="order-field"><label>Peso bruto</label><input name="gross_weight" inputmode="decimal" placeholder="0,000" value="<?= htmlspecialchars((string)($editingOrder['gross_weight']??'')) ?>"></div>
                                            <div class="order-field"><label>Peso líquido</label><input name="net_weight" inputmode="decimal" placeholder="0,000" value="<?= htmlspecialchars((string)($editingOrder['net_weight']??'')) ?>"></div>
                                        </div>
                                    </div>
                                </div>

                                <div class="pedido-section search-section">
                                    <div class="search-header">
                                        <span>Selecione os Produtos</span>
                                    </div>
                                    <div class="search-row">
                                        <div class="field-wrap search-field-wrap">
                                            <label>Buscar produtos</label>
                                            <input id="product-search" type="text" placeholder="Digite código ou nome do produto" autocomplete="off">
                                        </div>
                                        <button type="button" id="btn-clear" class="btn secondary">Limpar Pedido (F3)</button>
                                        <button type="button" id="btn-add-sample" class="btn primary">Adicionar</button>
                                    </div>
                                </div>

                                <div class="pedido-section table-section">
                                    <div class="table-head">
                                        <span>Produtos do Pedido</span>
                                        <button type="button" class="btn small warning">Limpar Pedido</button>
                                    </div>
                                    <table id="items-table">
                                        <thead>
                                            <tr><th>Item</th><th>Cod.Prod.</th><th>Descrição</th><th>UN</th><th>Qtd.</th><th>Vlr Uni.</th><th>Desconto</th><th>Vlr Total</th><th>Status fiscal</th><th>Ações</th></tr>
                                        </thead>
                                        <tbody>
                                            <tr class="no-items"><td colspan="10">Nenhum registro encontrado</td></tr>
                                        </tbody>
                                    </table>
                                </div>

                                <div class="pedido-section">
                                    <label>Observações</label>
                                    <textarea name="observacoes" rows="3" placeholder="Observações da venda..."></textarea>
                                </div>

                                <div class="pedido-section payment-grid">
                                    <div class="payment-block">
                                        <div class="field-wrap">
                                            <label>Pagamento</label>
                                            <select name="condicao_pagamento">
                                                <option value="avista">À vista</option>
                                                <option value="30dias">30 dias</option>
                                                <option value="prazo">Prazo indeterminado</option>
                                            </select>
                                        </div>
                                        <div class="field-wrap">
                                            <label>1º Vencimento</label>
                                            <input type="date" name="vencimento">
                                        </div>
                                        <div class="field-wrap">
                                            <label>Documento</label>
                                            <select name="documento">
                                                <option>DINHEIRO</option>
                                                <option>Cartão de débito</option>
                                                <option>PIX</option>
                                                <option>Crédito</option>
                                                <option>Boleto</option>
                                            </select>
                                        </div>
                                    </div>

                                    <div class="payment-block">
                                        <div class="field-wrap">
                                            <label>Frete</label>
                                            <input type="text" name="frete" value="0">
                                        </div>
                                        <div class="field-wrap">
                                            <label>Seguro</label>
                                            <input type="number" step="0.01" min="0" name="seguro" value="0">
                                        </div>
                                        <div class="field-wrap">
                                            <label>Outras despesas</label>
                                            <input type="number" step="0.01" min="0" name="outras_despesas" value="0">
                                        </div>
                                        <div class="field-wrap">
                                            <label>Desconto %</label>
                                            <input type="number" step="0.01" name="desconto_percent" value="0">
                                        </div>
                                        <div class="field-wrap">
                                            <label>Desconto Valor</label>
                                            <input type="number" step="0.01" name="desconto_valor" value="0">
                                        </div>
                                    </div>
                                </div>

                                <div class="totals-panel">
                                    <div class="total-box">
                                        <span>Total Produtos</span>
                                        <strong id="total-produtos">R$ 0,00</strong>
                                    </div>
                                    <div class="total-box total-box-primary">
                                        <span>Valor Total</span>
                                        <strong id="valor-total">R$ 0,00</strong>
                                    </div>
                                </div>

                                <div class="order-action-bar" role="toolbar" aria-label="Ações do pedido" data-order-action-bar>
                                    <div class="order-action-bar__intro"><strong>Ações</strong><span>Pedido</span></div>
                                    <div class="order-action-bar__buttons">
                                        <button class="order-routine order-routine--primary" type="submit" name="action" value="save_fiscal_order" data-order-action="save" title="Gravar e mostrar na lista de Pedidos" aria-label="Gravar pedido"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M19 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11l5 5v11a2 2 0 0 1-2 2Z"/><path d="M17 21v-8H7v8M7 3v5h8"/></svg><span data-order-label>Gravar</span></button>
                                        <button class="order-routine order-routine--fiscal" type="submit" data-fiscal-action="finalize" data-order-action="note" title="Salvar e preparar documento interno na Central de Notas" aria-label="Preparar nota"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><path d="M14 2v6h6M16 13H8M16 17H8M10 9H8"/></svg><span data-order-label>Preparar Nota</span></button>
                                        <button id="fiscal-preview-submit" class="order-routine order-routine--preview" type="submit" data-fiscal-action="preview" data-order-action="print" title="Abrir prévia DANFE ou DANFC-e em uma nova guia" aria-label="Gerar prévia fiscal"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M6 9V2h12v7M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect width="12" height="8" x="6" y="14" rx="1"/></svg><span data-order-label><?= $previewSelectedModel==='65'?'Prévia DANFC-e':'Prévia DANFE' ?></span></button>
                                        <?php if($canUseOrderTestFill): ?><button class="order-routine order-routine--test" type="button" data-order-test-fill title="Preencher o formulário com dados existentes para teste; não salva automaticamente" aria-label="Preencher dados de teste"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M13 2 3 14h9l-1 8 10-12h-9Z"/></svg><span>Preencher teste</span></button><?php endif; ?>
                                        <span class="order-action-bar__divider" aria-hidden="true"></span>
                                        <button class="order-routine order-routine--secondary" type="button" data-order-new data-new-url="?page=pedidos&amp;tab=<?= urlencode($tab) ?>" title="Iniciar novo pedido" aria-label="Novo pedido"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M8 12h8M12 8v8"/></svg><span data-order-label>Novo</span></button>
                                        <button class="order-routine order-routine--disabled" type="button" disabled title="Financeiro será integrado ao módulo de contas e estoque." aria-label="Financeiro — em breve"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><circle cx="12" cy="12" r="10"/><path d="M16 8h-6a2 2 0 1 0 0 4h4a2 2 0 1 1 0 4H8M12 18V6"/></svg><span>Financeiro</span></button>
                                        <?php if($tab==='entrada'): ?><button class="order-routine order-routine--secondary" type="button" data-order-import-xml title="Selecionar XML de NF-e de entrada" aria-label="Importar XML de entrada"><svg class="erp-icon order-routine__icon" aria-hidden="true" viewBox="0 0 24 24"><path d="M14.5 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V7.5Z"/><path d="M14 2v6h6M12 18v-6M9 15l3-3 3 3"/></svg><span>Importar XML</span></button><input type="file" accept=".xml,text/xml,application/xml" data-order-import-xml-file hidden><?php endif; ?>
                                    </div>
                                </div>
                                <div class="order-action-feedback" role="status" aria-live="polite" hidden></div>

                            </form>

                            <script>
                            (()=>{
                                const form=document.querySelector('#pedido-form');if(!form)return;
                                const feedback=document.querySelector('.order-action-feedback');
                                const cfop=form.querySelector('[data-order-cfop]'),nature=form.querySelector('[data-order-nature]');
                                const syncNature=()=>{const option=cfop?.selectedOptions[0];if(nature&&option){nature.value=option.dataset.nature||'';nature.dispatchEvent(new Event('change',{bubbles:true}));}};
                                cfop?.addEventListener('change',syncNature);nature?.addEventListener('change',()=>{const selected=nature.selectedOptions[0];if(selected?.dataset.cfopId&&cfop)cfop.value=selected.dataset.cfopId;});
                                const initial=new URLSearchParams(new FormData(form)).toString();
                                const isDirty=()=>new URLSearchParams(new FormData(form)).toString()!==initial;
                                const confirmLeave=()=>!isDirty()||window.confirm('Existem alterações não salvas. Deseja iniciar um novo pedido?');
                                const showError=message=>{if(!feedback)return;feedback.hidden=false;feedback.className='order-action-feedback message error';feedback.textContent=message;feedback.scrollIntoView({block:'nearest'});};
                                const showInfo=message=>{if(!feedback)return;feedback.hidden=false;feedback.className='order-action-feedback message success';feedback.textContent=message;};
                                const xmlImportButton=form.querySelector('[data-order-import-xml]');
                                const xmlImportFile=form.querySelector('[data-order-import-xml-file]');
                                xmlImportButton?.addEventListener('click',()=>xmlImportFile?.click());
                                xmlImportFile?.addEventListener('change',async()=>{
                                    const file=xmlImportFile.files?.[0];if(!file)return;
                                    if(!file.name.toLowerCase().endsWith('.xml')){xmlImportFile.value='';showError('Selecione um arquivo XML de NF-e.');return;}
                                    const escape=value=>String(value??'').replace(/[&<>"']/g,char=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[char]));
                                    const request=async action=>{const body=new FormData();body.set('xml',file);body.set('xml_action',action);body.set('csrf_token',form.querySelector('[name="csrf_token"]')?.value||'');if(action!=='analyze')body.set('confirm_supplier','1');const response=await fetch('entry_xml_import.php',{method:'POST',body,credentials:'same-origin',headers:{Accept:'application/json'}});const payload=await response.json().catch(()=>({success:false,message:'Resposta inválida do servidor.'}));if(!response.ok||!payload.success)throw new Error(payload.message||'Não foi possível importar o XML.');return payload;};
                                    try{
                                        xmlImportButton.disabled=true;xmlImportButton.setAttribute('aria-busy','true');showInfo(`Analisando ${file.name}...`);
                                        const payload=await request('analyze'),analysis=payload.analysis,supplier=analysis.supplier,invoice=analysis.invoice;
                                        const mismatch=!invoice.recipient_match;
                                        const dialog=document.createElement('dialog');dialog.className='xml-import-dialog';dialog.innerHTML=`<form method="dialog" class="xml-import-card"><header><div><small>Leitura de XML</small><h2>Escolha o tipo de importação</h2></div><button value="cancel" aria-label="Fechar">×</button></header><div class="xml-import-local-error" data-xml-error hidden></div>${mismatch?`<section class="xml-import-recipient-warning"><strong>Este XML não pertence à empresa ativa</strong><p>Ele pode cadastrar o fornecedor, produtos e impostos, mas não pode gerar uma entrada nem movimentar estoque.</p><p>XML: ${escape(invoice.recipient_document)} · Empresa: ${escape(invoice.establishment_document||'não configurada')}</p></section>`:''}<section class="xml-import-supplier"><span>${supplier.existing_id?'Fornecedor já cadastrado':'Novo fornecedor será cadastrado'}</span><strong>${escape(supplier.name)}</strong><p>CNPJ/CPF: ${escape(supplier.document)} · IE: ${escape(supplier.state_registration||'não informada')}</p><p>${escape(supplier.street)}, ${escape(supplier.number)} · ${escape(supplier.city)}/${escape(supplier.state)}</p></section><div class="xml-import-summary"><div><span>NF-e</span><strong>${escape(invoice.number)}</strong></div><div><span>Produtos</span><strong>${analysis.summary.items}</strong></div><div><span>Novos</span><strong>${analysis.summary.new_products}</strong></div><div><span>Total</span><strong>R$ ${Number(invoice.total).toFixed(2).replace('.',',')}</strong></div></div><p class="xml-import-cfop ${invoice.cfop_id?'':'warning'}">CFOP de saída ${escape(invoice.source_cfop)} → entrada ${escape(invoice.entry_cfop)}${invoice.cfop_id?'':' — este CFOP ainda não está cadastrado; selecione-o manualmente depois.'}</p><div class="xml-import-products">${analysis.items.slice(0,12).map(item=>`<p><strong>${escape(item.code)}</strong> ${escape(item.name)} <span>${item.existing_product_id?'vincular':'cadastrar'}</span></p>`).join('')}${analysis.items.length>12?`<p>... e mais ${analysis.items.length-12} produto(s).</p>`:''}</div><footer><button value="cancel" class="btn secondary">Cancelar</button><button type="button" class="btn secondary" data-catalog-xml>Somente cadastrar</button><button type="button" class="btn primary" data-confirm-xml ${mismatch?'disabled title="Disponível somente quando o XML for destinado à empresa"':''}>Importar como entrada</button></footer></form>`;
                                        document.body.appendChild(dialog);dialog.addEventListener('close',()=>{dialog.remove();if(dialog.returnValue==='cancel')xmlImportFile.value='';});dialog.showModal();
                                        const localError=dialog.querySelector('[data-xml-error]');
                                        const runCatalog=async button=>{button.disabled=true;button.textContent='Cadastrando...';try{const imported=await request('catalog');dialog.close('catalog');showInfo(imported.message);xmlImportFile.value='';}catch(error){button.disabled=false;button.textContent='Tentar cadastrar';localError.hidden=false;localError.textContent=error.message;}};
                                        dialog.querySelector('[data-catalog-xml]')?.addEventListener('click',event=>runCatalog(event.currentTarget));
                                        dialog.querySelector('[data-confirm-xml]')?.addEventListener('click',async event=>{const button=event.currentTarget;button.disabled=true;button.textContent='Importando...';try{const imported=await request('import'),result=imported.result;const supplierSelect=form.elements.namedItem('fornecedor_id');if(supplierSelect){let option=[...supplierSelect.options].find(item=>item.value===String(result.supplier_id));if(!option){option=new Option(result.supplier_name,String(result.supplier_id));supplierSelect.add(option)}supplierSelect.value=String(result.supplier_id);supplierSelect.dispatchEvent(new Event('change',{bubbles:true}));}const setValue=(name,value)=>{const field=form.elements.namedItem(name);if(field&&value!==null&&value!==undefined){field.value=String(value);field.dispatchEvent(new Event('change',{bubbles:true}));field.dispatchEvent(new Event('input',{bubbles:true}));}};setValue('data_venda',result.invoice.date);setValue('codigo_interno',analysis.access_key||`NFE-${result.invoice.number}`);setValue('fiscal_model','55');setValue('freight_mode',result.invoice.freight_mode);setValue('frete',result.invoice.freight);setValue('seguro',result.invoice.insurance);setValue('outras_despesas',result.invoice.other);setValue('desconto_valor',result.invoice.discount);setValue('observacoes',result.invoice.notes);if(result.invoice.cfop_id)setValue('cfop_id',result.invoice.cfop_id);window.dispatchEvent(new CustomEvent('erp:entry-xml-products',{detail:result.products}));dialog.close('imported');showInfo(imported.message);xmlImportFile.value='';}catch(error){button.disabled=false;button.textContent='Tentar novamente';localError.hidden=false;localError.textContent=error.message;}});
                                    }catch(error){xmlImportFile.value='';showError(error.message||'Não foi possível ler o XML selecionado.');}
                                    finally{xmlImportButton.disabled=false;xmlImportButton.removeAttribute('aria-busy');}
                                });
                                document.querySelector('[data-order-new]')?.addEventListener('click',event=>{if(confirmLeave())location.href=event.currentTarget.dataset.newUrl;});
                                document.querySelector('[data-order-cancel]')?.addEventListener('click',()=>{if(confirmLeave())location.href='?page=pedidos&tab=emitidos';});
                                const fiscalModelSelect=document.getElementById('fiscal-model-select');const fiscalPreviewSubmit=document.getElementById('fiscal-preview-submit');
                                function syncFiscalPreviewLabel(){const label=fiscalPreviewSubmit?.querySelector('[data-order-label]');if(label&&fiscalModelSelect)label.textContent=fiscalModelSelect.value==='65'?'Prévia DANFC-e':'Prévia DANFE';}
                                fiscalModelSelect?.addEventListener('change',syncFiscalPreviewLabel);syncFiscalPreviewLabel();
                                form.addEventListener('submit',async event=>{
                                    const submitter=event.submitter;if(!(submitter instanceof HTMLButtonElement))return;
                                    if(submitter.dataset.orderAction==='save'){
                                        if(submitter.dataset.busy==='1'){event.preventDefault();return;}
                                        submitter.dataset.busy='1';const label=submitter.querySelector('[data-order-label]');if(label)label.textContent='Salvando...';submitter.setAttribute('aria-busy','true');setTimeout(()=>submitter.disabled=true,0);return;
                                    }
                                    const fiscalAction=submitter.dataset.fiscalAction;if(!fiscalAction)return;
                                    event.preventDefault();if(submitter.dataset.busy==='1')return;submitter.dataset.busy='1';submitter.disabled=true;
                                    const label=submitter.querySelector('[data-order-label]');const oldLabel=label?.textContent||'';if(label)label.textContent=fiscalAction==='preview'?'Gerando prévia...':'Preparando nota...';submitter.setAttribute('aria-busy','true');
                                    const mobilePreview=fiscalAction==='preview'&&window.matchMedia('(max-width: 899px), (pointer: coarse)').matches;
                                    let previewWindow=null;if(fiscalAction==='preview'&&!mobilePreview){previewWindow=window.open('','_blank');if(previewWindow)previewWindow.document.write('<!doctype html><meta charset="utf-8"><title>Gerando prévia</title><p style="font:16px sans-serif;padding:30px">Gerando prévia do pedido...</p>');}
                                    const data=new FormData(form);data.set('fiscal_action',fiscalAction);data.set('idempotency_key',form.querySelector('[name="idempotency_key"]')?.value||'');
                                    try{
                                        const response=await fetch('fiscal_action.php',{method:'POST',body:data,credentials:'same-origin',headers:{Accept:'application/json'}});const result=await response.json().catch(()=>({success:false,error_message:'O servidor retornou uma resposta inválida. Tente novamente.'}));
                                        if(!response.ok||!result.success)throw Object.assign(new Error(result.error_message||'Não foi possível concluir a rotina.'),{notesUrl:result.notes_url});
                                        const orderField=form.querySelector('[name="order_id"]');if(orderField&&result.order_id)orderField.value=String(result.order_id);
                                        if(fiscalAction==='preview'){if(result.danfe_url){if(previewWindow)previewWindow.location.href=result.danfe_url;else location.href=result.danfe_url;}else if(previewWindow)previewWindow.close();return;}
                                        if(previewWindow)previewWindow.close();location.href=result.notes_url||'?page=fiscal_notes';
                                    }catch(error){if(previewWindow){previewWindow.document.body.innerHTML='<p style="font:16px sans-serif;padding:30px">Não foi possível gerar a prévia. Volte ao ERP, revise os dados e tente novamente.</p>';}showError(error.message||'Não foi possível concluir. Os dados foram preservados.');if(error.notesUrl&&fiscalAction!=='preview')location.href=error.notesUrl;}
                                    finally{submitter.dataset.busy='0';submitter.disabled=false;submitter.removeAttribute('aria-busy');if(label)label.textContent=oldLabel;}
                                });
                            })();
                            </script>

                            <script>
                                window.PRODUCTS = <?= json_encode(array_map(function($p){ return ['product_id'=>(int)$p['id'],'id'=>(int)$p['id'],'nome'=>$p['nome'],'codigo'=>$p['codigo'],'preco'=>(float)$p['preco'],'un'=>$p['unidade'] ?? 'UN','estoque'=>(float)($p['estoque_atual'] ?? 0),'status'=>$p['status'] ?? 'ativo','stock_control_by_lot'=>!empty($p['stock_control_by_lot'])]; }, $produtos)) ?>;
                                window.STOCK_LOTS = <?=json_encode($stockSelectableLots,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
                                window.CLIENTS = <?= json_encode($clientes) ?>;
                                window.ORDER_ITEMS = <?= json_encode($editingOrder['items'] ?? []) ?>;
                                // carregar impostos por produto do banco
                                window.PRODUCT_TAXES = {};
                                <?php
                                try {
                                    $pt = $repo->listProductTaxes();
                                    echo 'window.PRODUCT_TAXES = ' . json_encode($pt) . ';';
                                } catch (Throwable $e) {
                                    echo 'window.PRODUCT_TAXES = {}';
                                }
                                ?>
                            </script>
                        <?php endif; ?>
                        <!-- Modal para mostrar impostos do produto -->
                        <div id="product-taxes-modal" class="modal" role="dialog" aria-hidden="true">
                            <div class="modal-header">
                                <strong>Impostos do Produto</strong>
                                <button class="close" type="button" id="close-modal">×</button>
                            </div>
                            <div class="modal-body" id="modal-body-content">
                                <!-- preenchido por JS -->
                            </div>
                        </div>
                    </div>
                    </div>
                    <?php
                    break;

                case 'estoque':
                    include __DIR__.'/includes/stock_page.php';
                    break;

                case 'transporte':
                    include __DIR__.'/includes/transport_page.php';
                    break;

                case 'financeiro':
                    if(empty($erpAccessPolicy['can_use_financial'])){echo '<section class="panel"><h2>Financeiro bloqueado</h2><p>O acesso a este módulo foi desabilitado pelo Painel da Plataforma.</p></section>';break;}
                    include __DIR__.'/includes/financial_page.php';
                    break;

                case 'cadastro':
                    $tab = $_GET['tab'] ?? 'pessoas';
                    $legacyPeopleTabs = ['fornecedores' => 'fornecedor', 'motoristas' => 'motorista', 'transportadoras' => 'transportadora'];
                    if (isset($legacyPeopleTabs[$tab])) {
                        $_GET['people_type'] = $legacyPeopleTabs[$tab];
                        $tab = 'pessoas';
                    }
                    if (!in_array($tab, ['pessoas', 'produtos', 'cfops', 'xml'], true)) $tab = 'pessoas';
                    $_SESSION['erp_master_data_csrf'] ??= bin2hex(random_bytes(24));
                    if($tab==='xml')require __DIR__.'/includes/xml_catalog_registration.php';else require __DIR__ . '/includes/master_data_configuration.php';
                    if (false):
                    ?>
                    <section class="page-header">
                        <div>
                            <h2>Cadastro</h2>
                        </div>
                        <a class="btn primary" href="?page=cadastro&tab=pessoas">+ Adicionar</a>
                    </section>

                    <div class="tabs">
                        <a class="tab <?= $tab === 'pessoas' ? 'active' : '' ?>" href="?page=cadastro&tab=pessoas">Pessoas</a>
                        <a class="tab <?= $tab === 'produtos' ? 'active' : '' ?>" href="?page=cadastro&tab=produtos">Produtos</a>
                        <a class="tab <?= $tab === 'cfops' ? 'active' : '' ?>" href="?page=cadastro&tab=cfops">CFOPs</a>
                        <a class="tab <?= $tab === 'fornecedores' ? 'active' : '' ?>" href="?page=cadastro&tab=fornecedores">Fornecedores</a>
                        <a class="tab <?= $tab === 'motoristas' ? 'active' : '' ?>" href="?page=cadastro&tab=motoristas">Motoristas</a>
                        <a class="tab <?= $tab === 'transportadoras' ? 'active' : '' ?>" href="?page=cadastro&tab=transportadoras">Transportadoras</a>
                    </div>

                    <div class="panel">
                        <?php if ($tab === 'pessoas'): ?>
                            <?php
                            $tiposPessoa = [];
                            if (!empty($editPessoa['tipo_pessoa'])) {
                                $tiposPessoa = array_values(array_filter(array_map('trim', preg_split('/\s*,\s*/', (string) $editPessoa['tipo_pessoa']))));
                            }
                            if ($tiposPessoa === []) {
                                $tiposPessoa = ['cliente'];
                            }
                            $pessoaFisicaValor = strtolower(trim((string) ($editPessoa['pessoa_fisica'] ?? 'sim')));
                            if (!in_array($pessoaFisicaValor, ['sim', 'nao', 'não'], true)) {
                                $pessoaFisicaValor = 'sim';
                            }
                            ?>
                            <div class="register-shell">
                                <div class="register-header">
                                    <div class="register-title"><?= $editPessoa ? 'Editar Pessoa' : 'Nova Pessoa' ?></div>
                                    <div class="register-breadcrumb">Início / Pessoas / <?= $editPessoa ? 'Editar Pessoa' : 'Nova Pessoa' ?></div>
                                </div>

                                <form method="POST" class="register-form">
                                    <input type="hidden" name="action" value="save_cliente">
                                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['erp_client_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                    <?php if ($editPessoa): ?>
                                        <input type="hidden" name="id" value="<?= (int) $editPessoa['id'] ?>">
                                    <?php endif; ?>

                                    <div class="register-tabs" role="tablist" aria-label="Tipos de cadastro da pessoa">
                                        <button type="button" class="register-tab active" data-register-tab="basicas">Informações Básicas</button>
                                        <button type="button" class="register-tab" data-register-tab="entrega">Endereço de Entrega</button>
                                        <button type="button" class="register-tab" data-register-tab="fiscal">Fiscal e Financeiro</button>
                                        <button type="button" class="register-tab" data-register-tab="transportadora">Transportadora</button>
                                    </div>

                                    <div class="register-panels">
                                        <div class="register-panel active" data-register-panel="basicas">
                                            <div class="register-grid two-columns-form">
                                                <div class="field-block">
                                                    <label>CPF/CNPJ: <span class="required">*</span></label>
                                                    <div style="display:flex;gap:8px;align-items:center">
                                                        <input type="text" id="cliente_cpf_cnpj" name="cpf_cnpj" value="<?= htmlspecialchars($editPessoa['cpf_cnpj'] ?? '') ?>" placeholder="000.000.000-00" required>
                                                        <button type="button" id="btn-buscar-cnpj-cliente" class="btn small">Buscar CNPJ</button>
                                                    </div>
                                                </div>
                                                <div class="field-block">
                                                    <label>Nome/Razão Social: <span class="required">*</span></label>
                                                    <input type="text" id="cliente_nome" name="nome" value="<?= htmlspecialchars($editPessoa['nome'] ?? '') ?>" placeholder="Digite o nome ou razão social" required>
                                                </div>
                                            </div>

                                            <div class="register-grid two-columns-form">
                                                <div class="field-block">
                                                    <label>Nome / Nome Fantasia: <span class="required">*</span></label>
                                                    <input type="text" id="cliente_nome_fantasia" name="nome_fantasia" value="<?= htmlspecialchars($editPessoa['nome_fantasia'] ?? '') ?>" placeholder="Digite o nome fantasia">
                                                </div>
                                                <div class="field-block">
                                                    <label>Tipo de Pessoa: <span class="required">*</span></label>
                                                    <div class="check-row">
                                                        <label class="checkbox-inline"><input type="checkbox" name="tipo_pessoa[]" value="cliente" <?= in_array('cliente', $tiposPessoa, true) ? 'checked' : '' ?>> Cliente</label>
                                                        <label class="checkbox-inline"><input type="checkbox" name="tipo_pessoa[]" value="fornecedor" <?= in_array('fornecedor', $tiposPessoa, true) ? 'checked' : '' ?>> Fornecedor</label>
                                                        <label class="checkbox-inline"><input type="checkbox" name="tipo_pessoa[]" value="vendedor" <?= in_array('vendedor', $tiposPessoa, true) ? 'checked' : '' ?>> Vendedor</label>
                                                        <label class="checkbox-inline"><input type="checkbox" name="tipo_pessoa[]" value="transportadora" <?= in_array('transportadora', $tiposPessoa, true) ? 'checked' : '' ?>> Transportadora</label>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="register-grid four-columns-form">
                                                <div class="field-block">
                                                    <label>Pessoa Física:</label>
                                                    <select name="pessoa_fisica">
                                                        <option value="sim" <?= $pessoaFisicaValor === 'sim' ? 'selected' : '' ?>>Sim</option>
                                                        <option value="nao" <?= in_array($pessoaFisicaValor, ['nao', 'não'], true) ? 'selected' : '' ?>>Não</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Aniversário:</label>
                                                    <input type="date" name="aniversario" value="<?= htmlspecialchars($editPessoa['aniversario'] ?? '') ?>">
                                                </div>
                                                <div class="field-block"><label>Tipo fiscal:</label><select name="person_type"><option value="PF" <?= (($editPessoa['person_type'] ?? 'PF') === 'PF') ? 'selected' : '' ?>>Pessoa Física</option><option value="PJ" <?= (($editPessoa['person_type'] ?? '') === 'PJ') ? 'selected' : '' ?>>Pessoa Jurídica</option><option value="FOREIGN" <?= (($editPessoa['person_type'] ?? '') === 'FOREIGN') ? 'selected' : '' ?>>Estrangeiro</option></select></div>
                                                <div class="field-block"><label>RG:</label><input type="text" name="rg" value="<?= htmlspecialchars($editPessoa['rg'] ?? '') ?>"></div>
                                                <div class="field-block">
                                                    <label>Gênero:</label>
                                                    <select name="genero">
                                                        <option value="" <?= (($editPessoa['genero'] ?? '') === '') ? 'selected' : '' ?>>Não selecionado</option>
                                                        <option value="Masculino" <?= (($editPessoa['genero'] ?? '') === 'Masculino') ? 'selected' : '' ?>>Masculino</option>
                                                        <option value="Feminino" <?= (($editPessoa['genero'] ?? '') === 'Feminino') ? 'selected' : '' ?>>Feminino</option>
                                                        <option value="Outro" <?= (($editPessoa['genero'] ?? '') === 'Outro') ? 'selected' : '' ?>>Outro</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Status:</label>
                                                    <select name="status">
                                                        <option value="ativo" <?= (($editPessoa['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                                                        <option value="inativo" <?= (($editPessoa['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="register-grid five-columns-form">
                                                <div class="field-block">
                                                    <label>Nome do Contato: <span class="required">*</span></label>
                                                    <input type="text" name="nome_contato" value="<?= htmlspecialchars($editPessoa['nome_contato'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>E-mail: <span class="required">*</span></label>
                                                    <input type="email" name="email" value="<?= htmlspecialchars($editPessoa['email'] ?? '') ?>" placeholder="nome@empresa.com">
                                                </div>
                                                <div class="field-block">
                                                    <label>Fone Principal:</label>
                                                    <input type="text" name="fone_principal" value="<?= htmlspecialchars($editPessoa['fone_principal'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Fone 2:</label>
                                                    <input type="text" name="fone_2" value="<?= htmlspecialchars($editPessoa['fone_2'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Fone 3:</label>
                                                    <input type="text" name="fone_3" value="<?= htmlspecialchars($editPessoa['fone_3'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="register-panel" data-register-panel="entrega">
                                            <div class="register-grid five-columns-form">
                                                <div class="field-block">
                                                    <label>CEP <span class="required">*</span></label>
                                                    <input type="text" id="cliente_cep" name="cep" value="<?= htmlspecialchars($editPessoa['cep'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Logradouro <span class="required">*</span></label>
                                                    <input type="text" id="cliente_logradouro" name="logradouro" value="<?= htmlspecialchars($editPessoa['logradouro'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Número <span class="required">*</span></label>
                                                    <input type="text" id="cliente_numero" name="numero" value="<?= htmlspecialchars($editPessoa['numero'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Bairro <span class="required">*</span></label>
                                                    <input type="text" id="cliente_bairro" name="bairro" value="<?= htmlspecialchars($editPessoa['bairro'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Cidade <span class="required">*</span></label>
                                                    <input type="text" id="cliente_cidade" name="municipio" value="<?= htmlspecialchars($editPessoa['municipio'] ?? $editPessoa['cidade'] ?? '') ?>">
                                                </div>
                                            </div>

                                            <div class="register-grid four-columns-form">
                                                <div class="field-block">
                                                    <label>Estado:</label>
                                                    <select id="cliente_uf" name="estado">
                                                        <option value="" <?= (($editPessoa['estado'] ?? '') === '') ? 'selected' : '' ?>>Selecione</option>
                                                        <option value="SP" <?= (($editPessoa['estado'] ?? '') === 'SP') ? 'selected' : '' ?>>SP</option>
                                                        <option value="RJ" <?= (($editPessoa['estado'] ?? '') === 'RJ') ? 'selected' : '' ?>>RJ</option>
                                                        <option value="MG" <?= (($editPessoa['estado'] ?? '') === 'MG') ? 'selected' : '' ?>>MG</option>
                                                        <option value="PR" <?= (($editPessoa['estado'] ?? '') === 'PR') ? 'selected' : '' ?>>PR</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Complemento:</label>
                                                    <input type="text" id="cliente_complemento" name="complemento" value="<?= htmlspecialchars($editPessoa['complemento'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Ponto de Referência:</label>
                                                    <input type="text" id="cliente_ponto_referencia" name="ponto_referencia" value="<?= htmlspecialchars($editPessoa['ponto_referencia'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Cód. IBGE:</label>
                                                    <input type="text" id="cliente_codigo_ibge" name="codigo_ibge" value="<?= htmlspecialchars($editPessoa['codigo_ibge'] ?? '') ?>">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="register-panel" data-register-panel="fiscal">
                                            <div class="register-grid four-columns-form">
                                                <div class="field-block"><label>Indicador de IE:</label><select name="state_registration_indicator"><option value="1" <?= (($editPessoa['state_registration_indicator'] ?? '9') === '1') ? 'selected' : '' ?>>1 — Contribuinte ICMS</option><option value="2" <?= (($editPessoa['state_registration_indicator'] ?? '') === '2') ? 'selected' : '' ?>>2 — Contribuinte isento</option><option value="9" <?= (($editPessoa['state_registration_indicator'] ?? '9') === '9') ? 'selected' : '' ?>>9 — Não contribuinte</option></select></div>
                                                <div class="field-block"><label>Inscrição Estadual:</label><input type="text" name="inscricao_estadual" value="<?= htmlspecialchars($editPessoa['inscricao_estadual'] ?? '') ?>"></div>
                                                <div class="field-block"><label>Identificação estrangeira:</label><input type="text" name="foreign_id" value="<?= htmlspecialchars($editPessoa['foreign_id'] ?? '') ?>"></div>
                                                <div class="field-block"><label>País:</label><input type="text" name="country_name" value="<?= htmlspecialchars($editPessoa['country_name'] ?? 'BRASIL') ?>"></div>
                                                <div class="field-block"><label>Código do país:</label><input type="text" name="country_code" value="<?= htmlspecialchars($editPessoa['country_code'] ?? '1058') ?>"></div>
                                            </div>
                                            <div class="register-grid four-columns-form">
                                                <div class="field-block">
                                                    <label>Suprama:</label>
                                                    <input type="text" name="suprama" value="<?= htmlspecialchars($editPessoa['suprama'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>IM:</label>
                                                    <input type="text" name="im" value="<?= htmlspecialchars($editPessoa['im'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Vendedor:</label>
                                                    <select name="vendedor">
                                                        <option value="" <?= (($editPessoa['vendedor'] ?? '') === '') ? 'selected' : '' ?>>Sem Vendedor</option>
                                                        <option value="Vendedor A" <?= (($editPessoa['vendedor'] ?? '') === 'Vendedor A') ? 'selected' : '' ?>>Vendedor A</option>
                                                        <option value="Vendedor B" <?= (($editPessoa['vendedor'] ?? '') === 'Vendedor B') ? 'selected' : '' ?>>Vendedor B</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Status Pagamento:</label>
                                                    <select name="status_pagamento">
                                                        <option value="" <?= (($editPessoa['status_pagamento'] ?? '') === '') ? 'selected' : '' ?>>Selecione</option>
                                                        <option value="Ótimo" <?= (($editPessoa['status_pagamento'] ?? '') === 'Ótimo') ? 'selected' : '' ?>>Ótimo</option>
                                                        <option value="Bom" <?= (($editPessoa['status_pagamento'] ?? '') === 'Bom') ? 'selected' : '' ?>>Bom</option>
                                                        <option value="Regular" <?= (($editPessoa['status_pagamento'] ?? '') === 'Regular') ? 'selected' : '' ?>>Regular</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="register-grid four-columns-form">
                                                <div class="field-block">
                                                    <label>Pagamento:</label>
                                                    <select name="pagamento">
                                                        <option value="" <?= (($editPessoa['pagamento'] ?? '') === '') ? 'selected' : '' ?>>Nenhum</option>
                                                        <option value="À vista" <?= (($editPessoa['pagamento'] ?? '') === 'À vista') ? 'selected' : '' ?>>À vista</option>
                                                        <option value="Parcelado" <?= (($editPessoa['pagamento'] ?? '') === 'Parcelado') ? 'selected' : '' ?>>Parcelado</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Anvisa Data Venc.:</label>
                                                    <input type="date" name="anvisa_data_venc" value="<?= htmlspecialchars($editPessoa['anvisa_data_venc'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Anvisa Código:</label>
                                                    <input type="text" name="anvisa_codigo" value="<?= htmlspecialchars($editPessoa['anvisa_codigo'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>% Comissão:</label>
                                                    <input type="text" name="comissao_percentual" value="<?= htmlspecialchars($editPessoa['comissao_percentual'] ?? '') ?>" placeholder="%">
                                                </div>
                                            </div>

                                            <div class="register-grid four-columns-form">
                                                <div class="field-block">
                                                    <label>Comissão Volume:</label>
                                                    <input type="text" name="comissao_volume" value="<?= htmlspecialchars($editPessoa['comissao_volume'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Forma de Pagamento:</label>
                                                    <select name="forma_pagamento">
                                                        <option value="" <?= (($editPessoa['forma_pagamento'] ?? '') === '') ? 'selected' : '' ?>>Nenhum</option>
                                                        <option value="Dinheiro" <?= (($editPessoa['forma_pagamento'] ?? '') === 'Dinheiro') ? 'selected' : '' ?>>Dinheiro</option>
                                                        <option value="Cartão" <?= (($editPessoa['forma_pagamento'] ?? '') === 'Cartão') ? 'selected' : '' ?>>Cartão</option>
                                                        <option value="Boleto" <?= (($editPessoa['forma_pagamento'] ?? '') === 'Boleto') ? 'selected' : '' ?>>Boleto</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Limite Crédito:</label>
                                                    <input type="text" name="limite_credito" value="<?= htmlspecialchars($editPessoa['limite_credito'] ?? '') ?>" placeholder="R$">
                                                </div>
                                                <div class="field-block">
                                                    <label>Desconto:</label>
                                                    <input type="text" name="desconto" value="<?= htmlspecialchars($editPessoa['desconto'] ?? '') ?>" placeholder="R$">
                                                </div>
                                            </div>

                                            <div class="register-grid one-column-form">
                                                <div class="field-block">
                                                    <label>Funeral:</label>
                                                    <input type="text" name="funeral" value="<?= htmlspecialchars($editPessoa['funeral'] ?? '') ?>" placeholder="%">
                                                </div>
                                            </div>
                                        </div>

                                        <div class="register-panel" data-register-panel="transportadora">
                                            <div class="register-grid three-columns-form">
                                                <div class="field-block">
                                                    <label>Transportadora:</label>
                                                    <select name="transportadora">
                                                        <option value="" <?= (($editPessoa['transportadora'] ?? '') === '') ? 'selected' : '' ?>>Selecione</option>
                                                        <option value="Transportadora A" <?= (($editPessoa['transportadora'] ?? '') === 'Transportadora A') ? 'selected' : '' ?>>Transportadora A</option>
                                                        <option value="Transportadora B" <?= (($editPessoa['transportadora'] ?? '') === 'Transportadora B') ? 'selected' : '' ?>>Transportadora B</option>
                                                    </select>
                                                </div>
                                                <div class="field-block">
                                                    <label>Placa:</label>
                                                    <input type="text" name="placa" value="<?= htmlspecialchars($editPessoa['placa'] ?? '') ?>" placeholder="Informe a placa">
                                                </div>
                                                <div class="field-block">
                                                    <label>Placa UF:</label>
                                                    <select name="placa_uf">
                                                        <option value="" <?= (($editPessoa['placa_uf'] ?? '') === '') ? 'selected' : '' ?>>Nenhuma</option>
                                                        <option value="SP" <?= (($editPessoa['placa_uf'] ?? '') === 'SP') ? 'selected' : '' ?>>SP</option>
                                                        <option value="RJ" <?= (($editPessoa['placa_uf'] ?? '') === 'RJ') ? 'selected' : '' ?>>RJ</option>
                                                        <option value="MG" <?= (($editPessoa['placa_uf'] ?? '') === 'MG') ? 'selected' : '' ?>>MG</option>
                                                    </select>
                                                </div>
                                            </div>

                                            <div class="register-grid three-columns-form">
                                                <div class="field-block">
                                                    <label>ANTT:</label>
                                                    <input type="text" name="antt" value="<?= htmlspecialchars($editPessoa['antt'] ?? '') ?>">
                                                </div>
                                                <div class="field-block">
                                                    <label>Frete:</label>
                                                    <input type="text" name="frete" value="<?= htmlspecialchars($editPessoa['frete'] ?? '') ?>" placeholder="R$">
                                                </div>
                                                <div class="field-block">
                                                    <label>Valor do Frete:</label>
                                                    <input type="text" name="valor_frete" value="<?= htmlspecialchars($editPessoa['valor_frete'] ?? '') ?>" placeholder="R$">
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="register-action-row">
                                        <label style="width:100%">Observações:<textarea name="observations" rows="3" style="width:100%"><?= htmlspecialchars($editPessoa['observations'] ?? '') ?></textarea></label>
                                        <button class="btn primary" type="submit">Salvar</button>
                                        <a class="btn secondary" href="?page=cadastro&tab=pessoas">Voltar</a>
                                        <a class="btn secondary" href="?page=cadastro&tab=pessoas">Adicionar Nova Pessoa</a>
                                    </div>
                                </form>

                                <div class="register-list-header">
                                    <h3>Pessoas</h3>
                                    <a class="btn primary" href="?page=cadastro&tab=pessoas">+ Adicionar</a>
                                </div>

                                <div class="register-list-wrap">
                                    <div class="list-toolbar">
                                        <div class="inline-filters">
                                            <label>Status:
                                                <select>
                                                    <option>Ativos</option>
                                                </select>
                                            </label>
                                            <label>Tipo:
                                                <select>
                                                    <option>Todos</option>
                                                </select>
                                            </label>
                                        </div>
                                        <form method="GET" class="inline-actions-right">
                                            <input type="hidden" name="page" value="cadastro"><input type="hidden" name="tab" value="pessoas">
                                            <input type="search" name="q" value="<?= htmlspecialchars((string) ($_GET['q'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Nome, documento ou e-mail">
                                            <button class="btn btn-small btn-outline" type="submit">Pesquisar</button>
                                            <button class="btn btn-small btn-success" type="button">PDF</button>
                                            <button class="btn btn-small btn-warning" type="button">Excel</button>
                                            <button class="btn btn-small btn-muted" type="button">E-mail</button>
                                        </form>
                                    </div>

                                    <table class="register-table">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Nome</th>
                                                <th>Nome Fantasia</th>
                                                <th>CPF/CNPJ</th>
                                                <th>Ações</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach ($clientes as $c): ?>
                                                <tr>
                                                    <td><?= (int) $c['id'] ?></td>
                                                    <td><?= htmlspecialchars($c['nome']) ?></td>
                                                    <td><?= htmlspecialchars($c['nome_fantasia'] ?? $c['cidade'] ?? '') ?></td>
                                                    <td><?= htmlspecialchars($c['cpf_cnpj']) ?></td>
                                                    <td class="table-actions">
                                                        <a class="icon-action view" href="?page=cadastro&tab=pessoas&edit=<?= (int)$c['id'] ?>" title="Editar">✎</a>
                                                        <form method="POST" class="inline-form" onsubmit="return confirm('Deseja remover esta pessoa?');">
                                                            <input type="hidden" name="action" value="delete_cliente">
                                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['erp_client_csrf'], ENT_QUOTES, 'UTF-8') ?>">
                                                            <input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                                                            <button class="icon-action delete" type="submit" title="Excluir">✕</button>
                                                        </form>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        <?php elseif ($tab === 'produtos'): ?>
                            <div class="page-header">
                                <div>
                                    <p class="eyebrow">Produtos</p>
                                    <h2>Cadastro de Produtos</h2>
                                </div>
                                <button type="button" class="btn primary" data-register-tab-switch="product-cadastro">+ Novo produto</button>
                            </div>

                            <div class="register-shell">
                                <div class="register-tabs">
                                    <button type="button" class="register-tab active" data-register-tab="product-cadastro">Cadastro</button>
                                    <button type="button" class="register-tab" data-register-tab="product-lista">Lista</button>
                                </div>

                                <div class="register-panels">
                                    <div class="register-panel active" data-register-panel="product-cadastro">
                                        <div class="panel">
                                            <h3><?= $editProduto ? 'Editar produto' : 'Cadastrar produto' ?></h3>
                                            <form method="POST" class="form-grid">
                                                <input type="hidden" name="action" value="save_produto">
                                                <?php if ($editProduto): ?>
                                                    <input type="hidden" name="id" value="<?= (int) $editProduto['id'] ?>">
                                                <?php endif; ?>

                                                <div class="inline-row">
                                                    <label>
                                                        Nome do produto
                                                        <input type="text" name="nome" value="<?= htmlspecialchars($editProduto['nome'] ?? '') ?>" required>
                                                    </label>
                                                    <label>
                                                        Código
                                                        <input type="text" name="codigo" value="<?= htmlspecialchars($editProduto['codigo'] ?? '') ?>" required>
                                                    </label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>
                                                        NCM
                                                        <input type="text" name="ncm" value="<?= htmlspecialchars($editProduto['ncm'] ?? '') ?>">
                                                    </label>
                                                    <label>
                                                        CEST
                                                        <input type="text" name="cest" value="<?= htmlspecialchars($editProduto['cest'] ?? '') ?>">
                                                    </label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>Origem da mercadoria
                                                        <select name="merchandise_origin">
                                                            <option value="">Selecione</option>
                                                            <?php foreach (['0'=>'0 - Nacional','1'=>'1 - Estrangeira, importação direta','2'=>'2 - Estrangeira, mercado interno','3'=>'3 - Nacional, conteúdo importado > 40%','4'=>'4 - Nacional, processo básico','5'=>'5 - Nacional, conteúdo importado ≤ 40%','6'=>'6 - Estrangeira sem similar','7'=>'7 - Estrangeira mercado interno sem similar','8'=>'8 - Nacional, conteúdo importado > 70%'] as $code=>$label): ?>
                                                                <option value="<?= $code ?>" <?= (($editProduto['merchandise_origin'] ?? '') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </label>
                                                    <label>EX TIPI<input type="text" name="extipi" value="<?= htmlspecialchars($editProduto['extipi'] ?? '') ?>"></label>
                                                </div>
                                                <div class="inline-row">
                                                    <label>Código de benefício fiscal (cBenef)<input type="text" name="tax_benefit_code" value="<?= htmlspecialchars($editProduto['tax_benefit_code'] ?? '') ?>"></label>
                                                    <label>Número FCI<input type="text" name="fci_number" value="<?= htmlspecialchars($editProduto['fci_number'] ?? '') ?>"></label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>
                                                        Unidade
                                                        <input type="text" name="unidade" value="<?= htmlspecialchars($editProduto['unidade'] ?? 'UN') ?>">
                                                    </label>
                                                    <label>
                                                        GTIN / Código de barras
                                                        <input type="text" name="gtin" value="<?= htmlspecialchars($editProduto['gtin'] ?? '') ?>">
                                                    </label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>Unidade tributável<input type="text" name="taxable_unit" value="<?= htmlspecialchars($editProduto['taxable_unit'] ?? ($editProduto['unidade'] ?? 'UN')) ?>"></label>
                                                    <label>GTIN tributável<input type="text" name="gtin_tributable" value="<?= htmlspecialchars($editProduto['gtin_tributable'] ?? ($editProduto['gtin'] ?? 'SEM GTIN')) ?>"></label>
                                                    <label>Fator comercial → tributável<input type="number" step="0.000001" min="0.000001" name="conversion_factor" value="<?= htmlspecialchars((string)($editProduto['conversion_factor'] ?? 1)) ?>"></label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>
                                                        CFOP padrão
                                                        <input type="text" name="cfop_padrao" value="<?= htmlspecialchars($editProduto['cfop_padrao'] ?? '') ?>">
                                                    </label>
                                                    <label>
                                                        Categoria
                                                        <input type="text" name="categoria" value="<?= htmlspecialchars($editProduto['categoria'] ?? '') ?>">
                                                    </label>
                                                </div>

                                                <div class="inline-row">
                                                    <label>
                                                        Preço de custo
                                                        <input type="number" step="0.0001" min="0" name="cost_price" value="<?= htmlspecialchars((string) ($editProduto['cost_price'] ?? 0)) ?>">
                                                    </label>
                                                    <label>
                                                        Preço de venda
                                                        <input type="number" step="0.01" min="0" name="preco" value="<?= htmlspecialchars((string) ($editProduto['preco'] ?? 0)) ?>" required>
                                                    </label>
                                                    <label>
                                                        Estoque atual
                                                        <input type="number" min="0" name="estoque_atual" value="<?= htmlspecialchars((string) ($editProduto['estoque_atual'] ?? 0)) ?>" required>
                                                    </label>
                                                    <label>Estoque mínimo<input type="number" step="0.0001" min="0" name="minimum_stock" value="<?= htmlspecialchars((string)($editProduto['minimum_stock'] ?? 0)) ?>"></label>
                                                </div>

                                                <label>
                                                    Status
                                                    <select name="status">
                                                        <option value="ativo" <?= (($editProduto['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                                                        <option value="inativo" <?= (($editProduto['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                                                    </select>
                                                </label>

                                                <div class="form-actions">
                                                    <button class="btn primary" type="submit">Salvar produto</button>
                                                    <?php if ($editProduto): ?>
                                                        <a class="btn secondary" href="?page=cadastro&tab=produtos">Cancelar</a>
                                                    <?php endif; ?>
                                                    <button type="button" class="btn secondary" data-register-tab-switch="product-lista">Lista de produtos</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>

                                    <div class="register-panel" data-register-panel="product-lista">
                                        <div class="panel">
                                            <div class="list-toolbar" style="padding: 0 0 16px; border-top: none; background: transparent; justify-content: space-between;">
                                                <div class="inline-filters">
                                                    <label>
                                                        Buscar
                                                        <input id="product-list-search" type="search" placeholder="Nome, código ou categoria" aria-label="Buscar produtos" style="min-width: 220px;">
                                                    </label>
                                                    <label>
                                                        Status
                                                        <select id="product-status-filter">
                                                            <option value="all">Todos</option>
                                                            <option value="ativo">Ativos</option>
                                                            <option value="inativo">Inativos</option>
                                                        </select>
                                                    </label>
                                                </div>
                                                <button type="button" class="btn primary" data-register-tab-switch="product-cadastro">+ Novo produto</button>
                                            </div>

                                            <table class="register-table">
                                                <thead>
                                                    <tr>
                                                        <th>ID</th>
                                                        <th>Produto</th>
                                                        <th>Código</th>
                                                        <th>NCM</th>
                                                        <th>Unidade</th>
                                                        <th>Preço</th>
                                                        <th>Estoque</th>
                                                        <th>Ações</th>
                                                    </tr>
                                                </thead>
                                                <tbody id="product-list-body">
                                                    <?php foreach ($produtos as $p): ?>
                                                        <tr data-product-row data-product-text="<?= htmlspecialchars(strtolower((string)($p['nome'] ?? '') . ' ' . ($p['codigo'] ?? '') . ' ' . ($p['categoria'] ?? '')), ENT_QUOTES, 'UTF-8') ?>" data-product-status="<?= htmlspecialchars(strtolower((string)($p['status'] ?? 'ativo')), ENT_QUOTES, 'UTF-8') ?>">
                                                            <td><?= (int) $p['id'] ?></td>
                                                            <td><?= htmlspecialchars($p['nome']) ?></td>
                                                            <td><?= htmlspecialchars($p['codigo']) ?></td>
                                                            <td><?= htmlspecialchars($p['ncm'] ?? '') ?></td>
                                                            <td><?= htmlspecialchars($p['unidade'] ?? 'UN') ?></td>
                                                            <td><?= formatCurrency((float) ($p['preco'] ?? 0)) ?></td>
                                                            <td><?= (int) ($p['estoque_atual'] ?? 0) ?></td>
                                                            <td class="actions">
                                                                <a class="link-button" href="?page=cadastro&tab=produtos&edit=<?= (int) $p['id'] ?>">Editar</a>
                                                                <a class="link-button" href="?page=cadastro&tab=produtos&edit_taxes=<?= (int) $p['id'] ?>">Impostos</a>
                                                                <form method="POST" class="inline-form" onsubmit="return confirm('Deseja remover este produto?');">
                                                                    <input type="hidden" name="action" value="delete_produto">
                                                                    <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                                                    <button class="link-button danger" type="submit">Excluir</button>
                                                                </form>
                                                            </td>
                                                        </tr>
                                                        <?php if (isset($_GET['edit_taxes']) && (int)$_GET['edit_taxes'] === (int)$p['id']):
                                                            $tax = $repo->getProductTaxes((int)$p['id']) ?? ['ipi' => '', 'icms' => '', 'pis' => '', 'cofins' => ''];
                                                        ?>
                                                            <tr>
                                                                <td colspan="8">
                                                                    <form method="POST" class="panel" style="padding: 16px; margin-top: 8px;">
                                                                        <input type="hidden" name="action" value="save_product_taxes">
                                                                        <input type="hidden" name="product_id" value="<?= (int) $p['id'] ?>">
                                                                        <div class="inline-row">
                                                                            <label>
                                                                                IPI
                                                                                <input type="text" name="ipi" value="<?= htmlspecialchars($tax['ipi']) ?>">
                                                                            </label>
                                                                            <label>
                                                                                ICMS
                                                                                <input type="text" name="icms" value="<?= htmlspecialchars($tax['icms']) ?>">
                                                                            </label>
                                                                        </div>
                                                                        <div class="inline-row">
                                                                            <label>
                                                                                PIS
                                                                                <input type="text" name="pis" value="<?= htmlspecialchars($tax['pis']) ?>">
                                                                            </label>
                                                                            <label>
                                                                                COFINS
                                                                                <input type="text" name="cofins" value="<?= htmlspecialchars($tax['cofins']) ?>">
                                                                            </label>
                                                                        </div>
                                                                        <div class="form-actions">
                                                                            <button class="btn primary" type="submit">Salvar impostos</button>
                                                                            <a class="btn secondary" href="?page=cadastro&tab=produtos">Fechar</a>
                                                                        </div>
                                                                    </form>
                                                                </td>
                                                            </tr>
                                                        <?php endif; ?>
                                                    <?php endforeach; ?>
                                                </tbody>
                                            </table>
                                            <div id="product-list-empty" style="display:none; padding: 16px; color: var(--muted);">Nenhum produto encontrado.</div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php elseif ($tab === 'cfops'): ?>
                            <div class="page-header">
                                <div><p class="eyebrow">CFOP</p><h2>CFOPs</h2></div>
                                <a class="btn primary" href="?page=cadastro&tab=cfops">+ Novo CFOP</a>
                            </div>
                            <div class="grid two-columns">
                                <div class="panel">
                                    <h3><?= $editCfop ? 'Editar CFOP' : 'Cadastrar CFOP' ?></h3>
                                    <form method="POST" class="form-grid">
                                        <input type="hidden" name="action" value="save_cfop">
                                        <?php if ($editCfop): ?><input type="hidden" name="id" value="<?= (int) $editCfop['id'] ?>"><?php endif; ?>
                                        <label>Código<input type="text" name="codigo" value="<?= htmlspecialchars($editCfop['codigo'] ?? '') ?>" required></label>
                                        <label>Descrição<input type="text" name="descricao" value="<?= htmlspecialchars($editCfop['descricao'] ?? '') ?>" required></label>
                                        <label>Natureza<input type="text" name="natureza" value="<?= htmlspecialchars($editCfop['natureza'] ?? '') ?>"></label>
                                        <label>Aplicação<input type="text" name="aplicacao" value="<?= htmlspecialchars($editCfop['aplicacao'] ?? '') ?>"></label>
                                        <label>Status<select name="status"><option value="ativo" <?= (($editCfop['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option><option value="inativo" <?= (($editCfop['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option></select></label>
                                        <div class="form-actions"><button class="btn primary" type="submit">Salvar</button><?php if ($editCfop): ?><a class="btn secondary" href="?page=cadastro&tab=cfops">Cancelar</a><?php endif; ?></div>
                                    </form>
                                </div>
                                <div class="panel">
                                    <h3>Lista de CFOPs</h3>
                                    <table><thead><tr><th>Código</th><th>Descrição</th><th>Natureza</th><th>Ações</th></tr></thead><tbody>
                                        <?php foreach ($cfops as $cf): ?>
                                            <tr><td><?= htmlspecialchars($cf['codigo']) ?></td><td><?= htmlspecialchars($cf['descricao']) ?></td><td><?= htmlspecialchars($cf['natureza']) ?></td><td class="actions"><a class="link-button" href="?page=cadastro&tab=cfops&edit=<?= (int)$cf['id'] ?>">Editar</a><form method="POST" class="inline-form" onsubmit="return confirm('Excluir CFOP?');"><input type="hidden" name="action" value="delete_cfop"><input type="hidden" name="id" value="<?= (int)$cf['id'] ?>"><button class="link-button danger" type="submit">Excluir</button></form></td></tr>
                                        <?php endforeach; ?>
                                    </tbody></table>
                                </div>
                            </div>
                        <?php elseif ($tab === 'fornecedores'): ?>
                            <div class="page-header"><div><p class="eyebrow">Fornecedores</p><h2>Fornecedores</h2></div><a class="btn primary" href="?page=cadastro&tab=fornecedores">+ Novo fornecedor</a></div>
                            <div class="grid two-columns">
                                <div class="panel">
                                    <h3><?= $editFornecedor ? 'Editar fornecedor' : 'Cadastrar fornecedor' ?></h3>
                                        <form method="POST" class="form-grid">
                                        <input type="hidden" name="action" value="save_fornecedor">
                                        <?php if ($editFornecedor): ?><input type="hidden" name="id" value="<?= (int) $editFornecedor['id'] ?>"><?php endif; ?>
                                        <label>Nome<input type="text" id="fornecedor_nome" name="nome" value="<?= htmlspecialchars($editFornecedor['nome'] ?? '') ?>" required></label>
                                        <label>Nome fantasia<input type="text" id="fornecedor_nome_fantasia" name="nome_fantasia" value="<?= htmlspecialchars($editFornecedor['nome_fantasia'] ?? '') ?>"></label>
                                        <label>CPF/CNPJ
                                            <div style="display:flex;gap:8px;align-items:center">
                                                <input type="text" id="fornecedor_cpf_cnpj" name="cpf_cnpj" value="<?= htmlspecialchars($editFornecedor['cpf_cnpj'] ?? '') ?>" required>
                                                <button type="button" id="btn-buscar-cnpj-fornecedor" class="btn small">Buscar CNPJ</button>
                                            </div>
                                        </label>
                                        <label>Inscrição Estadual<input type="text" name="inscricao_estadual" value="<?= htmlspecialchars($editFornecedor['inscricao_estadual'] ?? '') ?>"></label>
                                        <label>E-mail<input type="email" name="email" value="<?= htmlspecialchars($editFornecedor['email'] ?? '') ?>"></label>
                                        <label>Telefone<input type="text" id="fornecedor_telefone" name="telefone" value="<?= htmlspecialchars($editFornecedor['telefone'] ?? '') ?>"></label>
                                        <label>CEP<input type="text" id="fornecedor_cep" name="cep" value="<?= htmlspecialchars($editFornecedor['cep'] ?? '') ?>"></label>
                                        <label>Logradouro<input type="text" id="fornecedor_logradouro" name="logradouro" value="<?= htmlspecialchars($editFornecedor['logradouro'] ?? '') ?>"></label>
                                        <label>Número<input type="text" id="fornecedor_numero" name="numero" value="<?= htmlspecialchars($editFornecedor['numero'] ?? '') ?>"></label>
                                        <label>Complemento<input type="text" id="fornecedor_complemento" name="complemento" value="<?= htmlspecialchars($editFornecedor['complemento'] ?? '') ?>"></label>
                                        <label>Bairro<input type="text" id="fornecedor_bairro" name="bairro" value="<?= htmlspecialchars($editFornecedor['bairro'] ?? '') ?>"></label>
                                        <label>Município<input type="text" id="fornecedor_municipio" name="municipio" value="<?= htmlspecialchars($editFornecedor['municipio'] ?? '') ?>"></label>
                                        <label>UF<input type="text" id="fornecedor_uf" name="uf" value="<?= htmlspecialchars($editFornecedor['uf'] ?? '') ?>"></label>
                                        <label>Cidade<input type="text" id="fornecedor_cidade" name="cidade" value="<?= htmlspecialchars($editFornecedor['cidade'] ?? '') ?>"></label>
                                        <label>Status<select name="status"><option value="ativo" <?= (($editFornecedor['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option><option value="inativo" <?= (($editFornecedor['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option></select></label>
                                        <div class="form-actions"><button class="btn primary" type="submit">Salvar</button><?php if ($editFornecedor): ?><a class="btn secondary" href="?page=cadastro&tab=fornecedores">Cancelar</a><?php endif; ?></div>
                                    </form>
                                </div>
                                <div class="panel"><h3>Lista de fornecedores</h3><table><thead><tr><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>Ações</th></tr></thead><tbody><?php foreach ($fornecedores as $f): ?><tr><td><?= htmlspecialchars($f['nome']) ?></td><td><?= htmlspecialchars($f['cpf_cnpj']) ?></td><td><?= htmlspecialchars($f['telefone']) ?></td><td class="actions"><a class="link-button" href="?page=cadastro&tab=fornecedores&edit=<?= (int)$f['id'] ?>">Editar</a><form method="POST" class="inline-form" onsubmit="return confirm('Excluir fornecedor?');"><input type="hidden" name="action" value="delete_fornecedor"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>"><button class="link-button danger" type="submit">Excluir</button></form></td></tr><?php endforeach; ?></tbody></table></div>
                            </div>
                        <?php elseif ($tab === 'motoristas'): ?>
                            <div class="page-header"><div><p class="eyebrow">Motoristas</p><h2>Motoristas</h2></div><a class="btn primary" href="?page=cadastro&tab=motoristas">+ Novo motorista</a></div>
                            <div class="grid two-columns">
                                <div class="panel">
                                    <h3><?= $editMotorista ? 'Editar motorista' : 'Cadastrar motorista' ?></h3>
                                    <form method="POST" class="form-grid">
                                        <input type="hidden" name="action" value="save_motorista">
                                        <?php if ($editMotorista): ?><input type="hidden" name="id" value="<?= (int) $editMotorista['id'] ?>"><?php endif; ?>
                                        <label>Nome<input type="text" name="nome" value="<?= htmlspecialchars($editMotorista['nome'] ?? '') ?>" required></label>
                                        <label>CPF<input type="text" name="cpf" value="<?= htmlspecialchars($editMotorista['cpf'] ?? '') ?>" required></label>
                                        <label>CNH<input type="text" name="cnh" value="<?= htmlspecialchars($editMotorista['cnh'] ?? '') ?>"></label>
                                        <label>Categoria CNH<input type="text" name="categoria_cnh" value="<?= htmlspecialchars($editMotorista['categoria_cnh'] ?? '') ?>"></label>
                                        <label>Vencimento CNH<input type="date" name="vencimento_cnh" value="<?= htmlspecialchars($editMotorista['vencimento_cnh'] ?? '') ?>"></label>
                                        <label>Telefone<input type="text" name="telefone" value="<?= htmlspecialchars($editMotorista['telefone'] ?? '') ?>"></label>
                                        <label>Status<select name="status"><option value="ativo" <?= (($editMotorista['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option><option value="inativo" <?= (($editMotorista['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option></select></label>
                                        <div class="form-actions"><button class="btn primary" type="submit">Salvar</button><?php if ($editMotorista): ?><a class="btn secondary" href="?page=cadastro&tab=motoristas">Cancelar</a><?php endif; ?></div>
                                    </form>
                                </div>
                                <div class="panel"><h3>Lista de motoristas</h3><table><thead><tr><th>Nome</th><th>CPF</th><th>CNH</th><th>Ações</th></tr></thead><tbody><?php foreach ($motoristas as $m): ?><tr><td><?= htmlspecialchars($m['nome']) ?></td><td><?= htmlspecialchars($m['cpf']) ?></td><td><?= htmlspecialchars($m['cnh']) ?></td><td class="actions"><a class="link-button" href="?page=cadastro&tab=motoristas&edit=<?= (int)$m['id'] ?>">Editar</a><form method="POST" class="inline-form" onsubmit="return confirm('Excluir motorista?');"><input type="hidden" name="action" value="delete_motorista"><input type="hidden" name="id" value="<?= (int)$m['id'] ?>"><button class="link-button danger" type="submit">Excluir</button></form></td></tr><?php endforeach; ?></tbody></table></div>
                            </div>
                        <?php elseif ($tab === 'transportadoras'): ?>
                            <div class="page-header"><div><p class="eyebrow">Transportadoras</p><h2>Transportadoras</h2></div><a class="btn primary" href="?page=cadastro&tab=transportadoras">+ Nova transportadora</a></div>
                            <div class="grid two-columns">
                                <div class="panel">
                                    <h3><?= $editTransportadora ? 'Editar transportadora' : 'Cadastrar transportadora' ?></h3>
                                    <form method="POST" class="form-grid">
                                        <input type="hidden" name="action" value="save_transportadora">
                                        <?php if ($editTransportadora): ?><input type="hidden" name="id" value="<?= (int) $editTransportadora['id'] ?>"><?php endif; ?>
                                        <label>Nome<input type="text" id="transportadora_nome" name="nome" value="<?= htmlspecialchars($editTransportadora['nome'] ?? '') ?>" required></label>
                                        <label>Nome fantasia<input type="text" id="transportadora_nome_fantasia" name="nome_fantasia" value="<?= htmlspecialchars($editTransportadora['nome_fantasia'] ?? '') ?>"></label>
                                        <label>CPF/CNPJ<div style="display:flex;gap:8px;align-items:center"><input type="text" id="transportadora_cpf_cnpj" name="cpf_cnpj" value="<?= htmlspecialchars($editTransportadora['cpf_cnpj'] ?? '') ?>" required><button type="button" id="btn-buscar-cnpj-transportadora" class="btn small">Buscar CNPJ</button></div></label>
                                        <label>Inscrição Estadual<input type="text" name="inscricao_estadual" value="<?= htmlspecialchars($editTransportadora['inscricao_estadual'] ?? '') ?>"></label>
                                        <label>E-mail<input type="email" name="email" value="<?= htmlspecialchars($editTransportadora['email'] ?? '') ?>"></label>
                                        <label>Telefone<input type="text" id="transportadora_telefone" name="telefone" value="<?= htmlspecialchars($editTransportadora['telefone'] ?? '') ?>"></label>
                                        <label>CEP<input type="text" id="transportadora_cep" name="cep" value="<?= htmlspecialchars($editTransportadora['cep'] ?? '') ?>"></label>
                                        <label>Logradouro<input type="text" id="transportadora_logradouro" name="logradouro" value="<?= htmlspecialchars($editTransportadora['logradouro'] ?? '') ?>"></label>
                                        <label>Número<input type="text" id="transportadora_numero" name="numero" value="<?= htmlspecialchars($editTransportadora['numero'] ?? '') ?>"></label>
                                        <label>Complemento<input type="text" id="transportadora_complemento" name="complemento" value="<?= htmlspecialchars($editTransportadora['complemento'] ?? '') ?>"></label>
                                        <label>Bairro<input type="text" id="transportadora_bairro" name="bairro" value="<?= htmlspecialchars($editTransportadora['bairro'] ?? '') ?>"></label>
                                        <label>Município<input type="text" id="transportadora_municipio" name="municipio" value="<?= htmlspecialchars($editTransportadora['municipio'] ?? '') ?>"></label>
                                        <label>UF<input type="text" id="transportadora_uf" name="uf" value="<?= htmlspecialchars($editTransportadora['uf'] ?? '') ?>"></label>
                                        <label>Cidade<input type="text" id="transportadora_cidade" name="cidade" value="<?= htmlspecialchars($editTransportadora['cidade'] ?? '') ?>"></label>
                                        <label>Status<select name="status"><option value="ativo" <?= (($editTransportadora['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option><option value="inativo" <?= (($editTransportadora['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option></select></label>
                                        <div class="form-actions"><button class="btn primary" type="submit">Salvar</button><?php if ($editTransportadora): ?><a class="btn secondary" href="?page=cadastro&tab=transportadoras">Cancelar</a><?php endif; ?></div>
                                    </form>
                                </div>
                                <div class="panel"><h3>Lista de transportadoras</h3><table><thead><tr><th>Nome</th><th>CPF/CNPJ</th><th>Telefone</th><th>Ações</th></tr></thead><tbody><?php foreach ($transportadoras as $t): ?><tr><td><?= htmlspecialchars($t['nome']) ?></td><td><?= htmlspecialchars($t['cpf_cnpj']) ?></td><td><?= htmlspecialchars($t['telefone']) ?></td><td class="actions"><a class="link-button" href="?page=cadastro&tab=transportadoras&edit=<?= (int)$t['id'] ?>">Editar</a><form method="POST" class="inline-form" onsubmit="return confirm('Excluir transportadora?');"><input type="hidden" name="action" value="delete_transportadora"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="link-button danger" type="submit">Excluir</button></form></td></tr><?php endforeach; ?></tbody></table></div>
                            </div>
                        <?php else: ?>
                            <h3><?=ucfirst($tab)?></h3>
                            <p>Área de cadastro de <?=htmlspecialchars($tab)?>.</p>
                        <?php endif; ?>
                    </div>
                    <?php endif;
                    break;

                case 'configuracao':
                    // CARREGAR DADOS EXISTENTES
                    $tab = $_GET['tab'] ?? 'empresa';
                    $dataFile = __DIR__ . '/../data/empresa.json';
                    $empresa = [];
                    if (file_exists($dataFile)) {
                        $empresa = json_decode(file_get_contents($dataFile), true) ?: [];
                    }
                    ?>
                    <?php if (!isset($tab)) { $tab = 'empresa'; }
                    ?>
                    <nav class="config-tabs" style="margin-bottom:16px;">
                        <a class="tab <?= ($tab === 'empresa') ? 'active' : '' ?>" href="?page=configuracao&tab=empresa" style="margin-right:8px;padding:8px 12px;border:1px solid #ccc;border-bottom:none;text-decoration:none;<?= ($tab === 'empresa') ? 'background:#fff;font-weight:600;' : 'background:#f5f5f5;' ?>">Empresa</a>
                        <a class="tab <?= ($tab === 'usuarios') ? 'active' : '' ?>" href="?page=configuracao&tab=usuarios" style="margin-right:8px;padding:8px 12px;border:1px solid #ccc;border-bottom:none;text-decoration:none;<?= ($tab === 'usuarios') ? 'background:#fff;font-weight:600;' : 'background:#f5f5f5;' ?>">Usuários</a>
                        <a class="tab <?= ($tab === 'fiscal') ? 'active' : '' ?>" href="?page=configuracao&tab=fiscal" style="padding:8px 12px;border:1px solid #ccc;border-bottom:none;text-decoration:none;<?= ($tab === 'fiscal') ? 'background:#fff;font-weight:600;' : 'background:#f5f5f5;' ?>">Fiscal</a>
                    </nav>

                    <?php if($tab!=='empresa'): ?><section class="page-header">
                        <div>
                            <p class="eyebrow">Configuração</p>
                            <h2>Configuração da Empresa</h2>
                        </div>
                        <a class="btn primary" href="?page=configuracao">Cadastrar Empresa</a>
                    </section><?php endif; ?>

                    <?php if ($tab === 'fiscal'): ?>
                        <div class="panel"><h3>Central de Configuração Fiscal</h3><p>CFOP, CSC, ICMS, PIS/COFINS, IPI, IBS/CBS e Imposto Seletivo usam a mesma fonte no banco deste tenant.</p><a class="btn primary" href="/plataforma/empresa-fiscal-central.php?id=<?= (int)($_SESSION['erp_tenant_id'] ?? $_SESSION['tenant_id'] ?? 0) ?>">Abrir Central Fiscal NF-e / NFC-e</a></div>
                    <?php endif; ?>

                    <?php if ($tab === 'empresa'): include __DIR__.'/includes/company_configuration.php'; endif; ?>
                    <?php if (false && $tab === 'empresa'): ?>
                    <?php if ($secureErpRuntime !== null): $erpReadiness = (new \MiniErp\Services\FiscalReadiness())->evaluate($erpEstablishment); ?>
                    <div class="panel"><h3>Empresa / Estabelecimento fiscal</h3><p>Fonte canônica deste tenant · Fiscal Readiness: <strong><?= htmlspecialchars($erpReadiness['status']) ?></strong> (<?= $erpReadiness['complete_count'] ?>/<?= $erpReadiness['total_count'] ?>).</p><?php if (isset($_GET['fiscal_saved'])): ?><p class="message success">Cadastro fiscal salvo.</p><?php endif; ?><?php if (!$erpEstablishmentSchemaAvailable): ?><p class="message error">Migration FISCAL-01 ainda não aplicada neste banco. Faça backup e aplique-a manualmente.</p><?php else: ?><?php renderEstablishmentForm($erpEstablishment ?? [], (string) $_SESSION['erp_establishment_csrf']); ?><?php endif; ?></div>
                    <?php endif; ?>
                    <?php
                        // carregar lista de empresas do banco (fallback para antigo JSON se vazio)
                        $empresas = $repo->listCompanies();
                        if (empty($empresas)) {
                            $dataDir = __DIR__ . '/../data';
                            $empresasFile = $dataDir . '/empresas.json';
                            if (file_exists($empresasFile)) {
                                $empresas = json_decode(file_get_contents($empresasFile), true) ?: [];
                            }
                        }
                        $currentCompanyId = $_SESSION['current_company_id'] ?? null;
                        $isNew = isset($_GET['new_company']) || (isset($_GET['edit_company']) && $_GET['edit_company'] === 'new');
                        $editCompany = null;
                        if (!empty($_GET['edit_company'])) {
                            $editId = (int)$_GET['edit_company'];
                            // tenta buscar do banco
                            $editCompany = $repo->findCompany($editId) ?: null;
                            // se não achou no DB, tenta no JSON fallback
                            if (!$editCompany) {
                                foreach ($empresas as $e) {
                                    if ((int)($e['id'] ?? 0) === $editId) { $editCompany = $e; break; }
                                }
                            }
                        }
                        if ($failedFormAction === 'save_empresa') {
                            $editCompany = $failedFormData;
                            $isNew = empty($failedFormData['id']);
                        }
                    ?>

                    <div class="panel">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
                            <h3>Empresas</h3>
                            <div>
                                <a class="btn primary" href="?page=configuracao&tab=empresa&new_company=1">+ Adicionar Empresa</a>
                            </div>
                        </div>

                        <?php if (!empty($_GET['new_company']) || $editCompany || $failedFormAction === 'save_empresa'): ?>
                            <form method="POST" class="form-grid">
                                <input type="hidden" name="action" value="save_empresa">
                                <?php if ($editCompany): ?><input type="hidden" name="id" value="<?= (int)$editCompany['id'] ?>"><?php endif; ?>
                                <label>Apelido <input type="text" id="company_apelido" name="apelido" value="<?= htmlspecialchars($editCompany['apelido'] ?? '') ?>" required></label>
                                <label>Razão Social <input type="text" id="company_razao" name="razao_social" value="<?= htmlspecialchars($editCompany['razao_social'] ?? '') ?>" required></label>
                                <label>CNPJ <div style="display:flex;gap:8px"><input type="text" id="company_cnpj" name="cnpj" value="<?= htmlspecialchars($editCompany['cnpj'] ?? '') ?>" required><button type="button" id="btn-buscar-cnpj" class="btn small">Buscar CNPJ</button></div></label>
                                <label>Cidade <input type="text" id="company_municipio" name="municipio" value="<?= htmlspecialchars($editCompany['municipio'] ?? '') ?>"></label>
                                <label>Regime <input type="text" id="company_regime" name="regime" value="<?= htmlspecialchars($editCompany['regime'] ?? '') ?>"></label>
                                <label>CEP <input type="text" id="company_cep" name="cep" value="<?= htmlspecialchars($editCompany['cep'] ?? '') ?>"></label>
                                <label>UF <input type="text" id="company_uf" name="uf" value="<?= htmlspecialchars($editCompany['uf'] ?? '') ?>"></label>
                                <label>Logradouro <input type="text" id="company_logradouro" name="logradouro" value="<?= htmlspecialchars($editCompany['logradouro'] ?? '') ?>"></label>
                                <label>Número <input type="text" id="company_numero" name="numero" value="<?= htmlspecialchars($editCompany['numero'] ?? '') ?>"></label>
                                <label>Complemento <input type="text" id="company_complemento" name="complemento" value="<?= htmlspecialchars($editCompany['complemento'] ?? '') ?>"></label>
                                <label>Bairro <input type="text" id="company_bairro" name="bairro" value="<?= htmlspecialchars($editCompany['bairro'] ?? '') ?>"></label>
                                <label>Telefone <input type="text" id="company_telefone" name="telefone" value="<?= htmlspecialchars($editCompany['telefone'] ?? '') ?>"></label>
                                <label>Cód. IBGE <input type="text" id="company_codigo_ibge" name="codigo_ibge" value="<?= htmlspecialchars($editCompany['codigo_ibge'] ?? '') ?>"></label>
                                <div id="company-cnpj-feedback" class="message" style="display:none" role="status" aria-live="polite"></div>
                                <div class="form-actions"><button class="btn primary" type="submit">Salvar Empresa</button> <a class="btn secondary" href="?page=configuracao&tab=empresa">Cancelar</a></div>
                            </form>
                                <script>
                                    (function(){
                                        const btn = document.getElementById('btn-buscar-cnpj-legacy-disabled');
                                        const feedback = document.getElementById('company-cnpj-feedback');
                                        if (!btn) return;
                                        const setConsultedValue = function(fieldId, consultedValue) {
                                            const field = document.getElementById(fieldId);
                                            const value = String(consultedValue || '').trim();
                                            if (!field || !value) return;

                                            const oldAction = field.parentElement.querySelector('.cnpj-use-consulted');
                                            if (oldAction) oldAction.remove();
                                            if (!field.value.trim()) {
                                                field.value = value;
                                                return;
                                            }
                                            if (field.value.trim().toLocaleLowerCase('pt-BR') === value.toLocaleLowerCase('pt-BR')) return;

                                            const action = document.createElement('button');
                                            action.type = 'button';
                                            action.className = 'link-button cnpj-use-consulted';
                                            action.textContent = 'Usar dado consultado: ' + value;
                                            action.style.marginTop = '4px';
                                            action.addEventListener('click', function() {
                                                field.value = value;
                                                action.remove();
                                            });
                                            field.parentElement.appendChild(action);
                                        };
                                        btn.addEventListener('click', async function(){
                                            const cnpj = document.getElementById('company_cnpj').value || '';
                                            if (!cnpj) return alert('Informe o CNPJ antes de buscar.');
                                            btn.disabled = true;
                                            btn.textContent = 'Buscando...';
                                            feedback.style.display = 'none';
                                            try {
                                                const res = await fetch('/ajax_cnpj.php?cnpj=' + encodeURIComponent(cnpj));
                                                if (!res.ok) {
                                                    const err = await res.json().catch(()=>({}));
                                                    const messages = {
                                                        invalid_cnpj: 'O CNPJ informado é inválido.',
                                                        not_found: 'CNPJ não encontrado.',
                                                        provider_unavailable: 'A consulta está indisponível no momento.'
                                                    };
                                                    alert(messages[err.error] || ('Erro ao consultar CNPJ (' + res.status + ').'));
                                                    return;
                                                }
                                                const payload = await res.json();
                                                const data = payload.data || {};
                                                const regime = Array.isArray(data.regime) ? ((data.regime[0] || {}).forma_de_tributacao || '') : (data.regime || '');
                                                const log = (data.descricao_tipo_de_logradouro||'') + ' ' + (data.logradouro||'');
                                                setConsultedValue('company_razao', data.razao_social);
                                                setConsultedValue('company_apelido', data.nome_fantasia);
                                                setConsultedValue('company_municipio', data.municipio);
                                                setConsultedValue('company_regime', regime);
                                                setConsultedValue('company_cep', data.cep);
                                                setConsultedValue('company_uf', data.uf);
                                                setConsultedValue('company_logradouro', log);
                                                setConsultedValue('company_numero', data.numero);
                                                setConsultedValue('company_complemento', data.complemento);
                                                setConsultedValue('company_bairro', data.bairro);
                                                setConsultedValue('company_telefone', data.ddd_telefone_1);
                                                setConsultedValue('company_codigo_ibge', data.codigo_ibge || data.codigo_municipal);
                                                feedback.textContent = 'Dados consultados automaticamente. Revise antes de salvar.';
                                                feedback.style.display = 'block';
                                            } catch (e) {
                                                alert('Não foi possível consultar o CNPJ. Verifique sua conexão e tente novamente.');
                                            } finally {
                                                btn.disabled = false;
                                                btn.textContent = 'Buscar CNPJ';
                                            }
                                        });
                                    })();
                                </script>
                        <?php elseif (!empty($_GET['create_user_for'])): ?>
                            <?php $createFor = (int)$_GET['create_user_for']; ?>
                            <form method="POST" class="form-grid">
                                <input type="hidden" name="action" value="create_tenant_user">
                                <input type="hidden" name="id" value="<?= $createFor ?>">
                                <label>E-mail (login) <input type="email" name="email" required></label>
                                <label>Senha <input type="password" name="senha" required></label>
                                <div class="form-actions">
                                    <button class="btn primary" type="submit">Criar usuário</button>
                                    <a class="btn secondary" href="?page=configuracao&tab=empresa">Cancelar</a>
                                </div>
                            </form>
                        <?php else: ?>
                            <table style="width:100%;">
                                <thead><tr><th>ID</th><th>Apelido</th><th>Razão Social</th><th>CNPJ</th><th>Cidade</th><th>Regime</th><th>Ações</th></tr></thead>
                                <tbody>
                                <?php foreach ($empresas as $e): ?>
                                    <tr>
                                        <td><?= (int)($e['id'] ?? $e['id'] ?? 0) ?></td>
                                        <td><?= htmlspecialchars($e['nome_fantasia'] ?? ($e['apelido'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($e['razao_social'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($e['cnpj'] ?? '') ?></td>
                                        <td><?= htmlspecialchars($e['municipio'] ?? ($e['cidade'] ?? '')) ?></td>
                                        <td><?= htmlspecialchars($e['regime'] ?? '') ?></td>
                                        <td>
                                            <form method="POST" style="display:inline;margin-right:6px;">
                                                <input type="hidden" name="action" value="select_empresa">
                                                <input type="hidden" name="company_id" value="<?= (int)($e['id'] ?? 0) ?>">
                                                <button class="btn small" type="submit">Selecionar</button>
                                            </form>
                                            <a class="btn small" href="?page=configuracao&tab=empresa&create_user_for=<?= (int)($e['id'] ?? 0) ?>">Criar usuário</a>
                                            <a class="btn small" href="?page=company&id=<?= (int)($e['id'] ?? 0) ?>">Ver</a>
                                            <a class="btn small" href="?page=configuracao&tab=empresa&edit_company=<?= (int)($e['id'] ?? 0) ?>">Editar</a>
                                            <?php $isBlocked = !empty($e['blocked']) ? true : false; ?>
                                            <form method="POST" style="display:inline;margin-left:6px;">
                                                <input type="hidden" name="action" value="toggle_block_empresa">
                                                <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
                                                <input type="hidden" name="blocked" value="<?= $isBlocked ? '0' : '1' ?>">
                                                <button class="btn <?= $isBlocked ? 'secondary' : 'danger' ?> small" type="submit"><?= $isBlocked ? 'Desbloquear' : 'Bloquear' ?></button>
                                            </form>
                                            <form method="POST" style="display:inline;margin-left:6px;" onsubmit="return confirm('Remover empresa?');">
                                                <input type="hidden" name="action" value="delete_empresa">
                                                <input type="hidden" name="id" value="<?= (int)($e['id'] ?? 0) ?>">
                                                <button class="btn danger small" type="submit">Excluir</button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        <?php endif; ?>

                    </div>
                    <?php endif; ?>
                    <!-- Conta administrativa movida para a guia Usuários -->

                    <?php if (($tab ?? 'empresa') === 'usuarios'): ?>
                        <!-- Cadastro de funcionários / usuários (guia Usuários) -->
                        <?php $usuarios = $repo->listUsuarios(); ?>
                        <section class="page-header">
                            <div>
                                <p class="eyebrow">Segurança</p>
                                <h2>Funcionários / Usuários</h2>
                            </div>
                            <button class="btn primary" type="button" data-user-modal-open>+ Novo usuário</button>
                        </section>

                        <div class="panel user-management-panel">
                            <div class="user-management-summary">
                                <div><strong><?= count($usuarios) ?></strong><span>usuários da empresa</span></div>
                                <p>Crie acessos, vincule ao cadastro de pessoas e defina as permissões de cada usuário.</p>
                            </div>
                            <div class="user-list-wrap">
                                <table class="user-management-table">
                                    <thead><tr><th>Usuário</th><th>Pessoa vinculada</th><th>Perfil</th><th>Status</th><th>Ações</th></tr></thead>
                                    <tbody>
                                    <?php foreach ($usuarios as $u): ?>
                                        <tr>
                                            <td data-label="Usuário"><strong><?= htmlspecialchars($u['nome']) ?></strong><small><?= htmlspecialchars($u['email']) ?></small></td>
                                            <td data-label="Pessoa"><?= !empty($u['pessoa_nome']) ? htmlspecialchars($u['pessoa_nome']) : '<span class="user-unlinked">Sem vínculo</span>' ?></td>
                                            <td data-label="Perfil"><?= htmlspecialchars($u['cargo'] ?: $u['role']) ?></td>
                                            <td data-label="Status"><span class="status-badge <?= ($u['status'] ?? '') === 'ativo' ? 'success' : '' ?>"><?= htmlspecialchars($u['status'] ?? 'inativo') ?></span></td>
                                            <td data-label="Ações" class="user-row-actions">
                                                <?php if (($u['status']??'') === 'pendente'): ?><form method="POST" class="user-approval-form"><input type="hidden" name="action" value="approve_usuario"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><select name="approval_role" aria-label="Perfil a liberar"><option value="user">Usuário</option><option value="admin">Administrador</option></select><button class="btn small" type="submit">Ativar acesso</button></form><?php endif; ?>
                                                <button class="btn small" type="button" data-user-edit="<?= (int)$u['id'] ?>">Editar</button>
                                                <form method="POST"><input type="hidden" name="action" value="delete_usuario"><input type="hidden" name="id" value="<?= (int)$u['id'] ?>"><button class="btn ghost small" type="submit" onclick="return confirm('Remover usuário?')">Remover</button></form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (!$usuarios): ?><tr><td colspan="5" class="empty-state">Nenhum usuário cadastrado.</td></tr><?php endif; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>

                        <div class="user-modal" data-user-modal hidden aria-hidden="true">
                            <div class="user-modal__backdrop" data-user-modal-close></div>
                            <section class="user-modal__surface" role="dialog" aria-modal="true" aria-labelledby="user-modal-title">
                                <header class="user-modal__header"><div><small>Usuários da empresa</small><h3 id="user-modal-title">Novo usuário</h3></div><button type="button" data-user-modal-close aria-label="Fechar">×</button></header>
                                <form method="POST" data-user-form>
                                    <input type="hidden" name="action" value="save_usuario"><input type="hidden" name="id" value="">
                                    <nav class="user-form-tabs" role="tablist">
                                        <button type="button" class="active" data-user-tab="access" role="tab">Dados de acesso</button>
                                        <button type="button" data-user-tab="person" role="tab">Vínculo com pessoa</button>
                                        <button type="button" data-user-tab="permissions" role="tab">Permissões</button>
                                    </nav>
                                    <div class="user-modal__body">
                                        <div class="user-tab-panel active" data-user-panel="access">
                                            <div class="form-grid two-columns"><label>Nome completo <input name="nome" required autocomplete="name"></label><label>E-mail de acesso <input type="email" name="email" required autocomplete="email"></label><label>Senha <input type="password" name="senha" autocomplete="new-password" placeholder="Obrigatória para novo usuário"></label><label>Cargo <select name="cargo"><option value="funcionario">Funcionário</option><option value="vendedor">Vendedor</option><option value="motorista">Motorista</option><option value="transportadora">Transportadora</option><option value="admin">Administrador</option></select></label><label>Perfil de acesso <select name="role"><option value="user">Usuário</option><option value="admin">Administrador</option></select></label><label>Status <select name="status"><option value="ativo">Ativo</option><option value="inativo">Inativo</option></select></label></div>
                                        </div>
                                        <div class="user-tab-panel" data-user-panel="person">
                                            <div class="user-person-help"><strong>Vincular a uma pessoa cadastrada</strong><p>O vínculo é opcional e permite identificar qual pessoa utiliza este acesso.</p></div>
                                            <label>Pessoa <select name="pessoa_id"><option value="">Sem vínculo</option><?php foreach ($clientes as $person): ?><option value="<?= (int)$person['id'] ?>"><?= htmlspecialchars($person['nome']) ?><?= !empty($person['cpf_cnpj']) ? ' — '.htmlspecialchars($person['cpf_cnpj']) : '' ?></option><?php endforeach; ?></select></label>
                                            <a class="btn secondary" href="?page=cadastro&tab=pessoas">Abrir cadastro de pessoas</a>
                                        </div>
                                        <div class="user-tab-panel" data-user-panel="permissions">
                                            <div class="user-permission-grid"><?php foreach (['vendas:view'=>'Ver vendas','vendas:edit'=>'Editar vendas','produtos:view'=>'Ver produtos','produtos:edit'=>'Editar produtos','relatorios:view'=>'Ver relatórios','config:manage'=>'Gerenciar configurações','clientes:manage'=>'Gerenciar pessoas'] as $key=>$label): ?><label><input type="checkbox" name="permissions[]" value="<?= htmlspecialchars($key) ?>"><span><?= htmlspecialchars($label) ?></span></label><?php endforeach; ?></div>
                                        </div>
                                    </div>
                                    <footer class="user-modal__footer"><button class="btn secondary" type="button" data-user-modal-close>Cancelar</button><button class="btn primary" type="submit">Salvar usuário</button></footer>
                                </form>
                            </section>
                        </div>
                        <script>window.ERP_COMPANY_USERS=<?= json_encode($usuarios, JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;</script>

                        <!-- Painel rápido para criar/atualizar conta administrativa (agora dentro de Usuários) -->
                        <?php if (false): ?>
                        <div class="panel">
                            <p>Use este formulário para criar ou atualizar rapidamente a conta administrativa (role = admin). O e-mail será marcado como verificado automaticamente.</p>
                            <form method="POST" class="form-grid" style="max-width:420px;">
                                <input type="hidden" name="action" value="create_admin_account">
                                <label>Nome <input type="text" name="nome" value="Administrador"></label>
                                <label>E-mail <input type="email" name="email" placeholder="admin@localhost" value="admin@localhost" required></label>
                                <label>Senha <input type="password" name="senha" placeholder="Digite a senha" required></label>
                                <div class="form-actions"><button class="btn primary" type="submit">Criar/Atualizar Conta Admin</button></div>
                            </form>
                        </div>

                        <div class="grid two-columns">
                            <div class="panel">
                                <h3><?= $editUsuario ? 'Editar funcionário' : 'Cadastrar funcionário' ?></h3>
                                <form method="POST" class="form-grid">
                                    <input type="hidden" name="action" value="save_usuario">
                                    <?php if ($editUsuario): ?>
                                        <input type="hidden" name="id" value="<?= (int)$editUsuario['id'] ?>">
                                    <?php endif; ?>

                                    <label>Nome <input type="text" name="nome" value="<?= htmlspecialchars($editUsuario['nome'] ?? '') ?>" required></label>
                                    <label>E-mail <input type="email" name="email" value="<?= htmlspecialchars($editUsuario['email'] ?? '') ?>" required></label>
                                    <label>Senha <input type="password" name="senha" placeholder="Deixe em branco para manter"></label>
                                    <input type="hidden" name="company_id" value="<?= htmlspecialchars($_SESSION['current_company_id'] ?? '') ?>">
                                    <label>Role <input type="text" name="role" value="<?= htmlspecialchars($editUsuario['role'] ?? 'user') ?>"></label>
                                    <label>Cargo
                                        <select name="cargo">
                                            <option value="funcionario" <?= (($editUsuario['cargo'] ?? '') === 'funcionario') ? 'selected' : '' ?>>Funcionário</option>
                                            <option value="vendedor" <?= (($editUsuario['cargo'] ?? '') === 'vendedor') ? 'selected' : '' ?>>Vendedor</option>
                                            <option value="motorista" <?= (($editUsuario['cargo'] ?? '') === 'motorista') ? 'selected' : '' ?>>Motorista</option>
                                            <option value="transportadora" <?= (($editUsuario['cargo'] ?? '') === 'transportadora') ? 'selected' : '' ?>>Transportadora</option>
                                            <option value="admin" <?= (($editUsuario['cargo'] ?? '') === 'admin') ? 'selected' : '' ?>>Administrador</option>
                                        </select>
                                    </label>
                                    <label>Status
                                        <select name="status">
                                            <option value="ativo" <?= (($editUsuario['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                                            <option value="inativo" <?= (($editUsuario['status'] ?? '') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                                        </select>
                                    </label>
                                    <fieldset>
                                        <legend>Permissões</legend>
                                        <?php
                                        $availablePermissions = [
                                            'vendas:view' => 'Ver Vendas',
                                            'vendas:edit' => 'Editar Vendas',
                                            'produtos:view' => 'Ver Produtos',
                                            'produtos:edit' => 'Editar Produtos',
                                            'relatorios:view' => 'Ver Relatórios',
                                            'config:manage' => 'Gerenciar Configurações',
                                            'clientes:manage' => 'Gerenciar Clientes',
                                        ];
                                        $userPerms = array_filter(array_map('trim', explode(',', (string)($editUsuario['permissions'] ?? ''))));
                                        foreach ($availablePermissions as $permKey => $permLabel):
                                        ?>
                                            <label class="checkbox-inline"><input type="checkbox" name="permissions[]" value="<?= $permKey ?>" <?= in_array($permKey, $userPerms, true) ? 'checked' : '' ?>> <?= $permLabel ?></label>
                                        <?php endforeach; ?>
                                    </fieldset>

                                    <fieldset>
                                        <legend>Criar registros associados (opcional)</legend>
                                        <label class="checkbox-inline"><input type="checkbox" name="create_cliente" value="1" <?= !empty($_POST['create_cliente']) ? 'checked' : '' ?>> Criar como cliente</label>
                                        <label>CPF/CNPJ cliente <input type="text" name="cpf_cnpj_cliente" value="<?= htmlspecialchars($editUsuario['cpf_cnpj_cliente'] ?? '') ?>" placeholder="Somente números" maxlength="14" pattern="[0-9]{11}|[0-9]{14}"></label>
                                        <label class="checkbox-inline"><input type="checkbox" name="create_motorista" value="1" <?= !empty($_POST['create_motorista']) ? 'checked' : '' ?>> Criar como motorista</label>
                                        <label>CPF motorista <input type="text" name="cpf_motorista" value="<?= htmlspecialchars($editUsuario['cpf_motorista'] ?? '') ?>" placeholder="Somente números" maxlength="11" pattern="[0-9]{11}"></label>
                                    </fieldset>
                                    <label>Avatar (URL relativa) <input type="text" name="avatar" value="<?= htmlspecialchars($editUsuario['avatar'] ?? '') ?>"></label>
                                    <div class="form-actions"><button class="btn primary" type="submit">Salvar</button></div>
                                </form>
                            </div>

                            <div class="panel">
                                <h3>Lista de funcionários</h3>
                                <table>
                                    <thead><tr><th>Nome</th><th>E-mail</th><th>Role</th><th>Cargo</th><th>Status</th><th></th></tr></thead>
                                    <tbody>
                                    <?php foreach ($usuarios as $u): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($u['nome']) ?></td>
                                            <td><?= htmlspecialchars($u['email']) ?></td>
                                            <td><?= htmlspecialchars($u['role']) ?></td>
                                            <td><?= htmlspecialchars($u['cargo'] ?? '') ?></td>
                                            <td><?= htmlspecialchars($u['status'] ?? '') ?></td>
                                            <td>
                                                <a class="btn small" href="?page=configuracao&tab=usuarios&edit_user=<?= (int)$u['id'] ?>">Editar</a>
                                                <!-- assign company form -->
                                                <form method="POST" style="display:inline;margin-left:6px;">
                                                    <input type="hidden" name="action" value="assign_user_company">
                                                    <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                    <select name="company_id" onchange="this.form.submit()" style="margin-left:6px;">
                                                        <option value="">-- Empresa --</option>
                                                        <?php foreach ($repo->listCompanies() as $c): ?>
                                                            <option value="<?= (int)$c['id'] ?>" <?= ((int)($u['company_id'] ?? 0) === (int)$c['id']) ? 'selected' : '' ?>><?= htmlspecialchars($c['apelido'] ?: $c['razao_social']) ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                </form>
                                                    <?php if (!empty($currentUser) && strtolower((string)($currentUser['role'] ?? '')) === 'admin' && ($u['status'] ?? '') !== 'ativo'): ?>
                                                        <form method="POST" style="display:inline">
                                                            <input type="hidden" name="action" value="approve_usuario">
                                                            <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                            <button class="btn success small" type="submit">Aprovar Acesso</button>
                                                        </form>
                                                    <?php endif; ?>
                                                    <form method="POST" style="display:inline">
                                                        <input type="hidden" name="action" value="delete_usuario">
                                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                                        <button class="btn ghost small" type="submit" onclick="return confirm('Remover usuário?')">Remover</button>
                                                    </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endif; ?>
                    <?php
                    break;

                case 'clientes':
                    ?>
                    <!-- Tela de cadastro e listagem de clientes -->
                    <section class="page-header">
                        <div>
                            <p class="eyebrow">Cadastros</p>
                            <h2>Clientes</h2>
                        </div>
                        <a class="btn primary" href="?page=clientes">+ Novo cliente</a>
                    </section>

                    <div class="grid two-columns">
                        <div class="panel">
                            <h3><?= $editCliente ? 'Editar cliente' : 'Cadastrar cliente' ?></h3>
                            <form method="POST" class="form-grid">
                                <input type="hidden" name="action" value="save_cliente">
                                <?php if ($editCliente): ?>
                                    <input type="hidden" name="id" value="<?= (int) $editCliente['id'] ?>">
                                <?php endif; ?>

                                <label>
                                    Nome
                                    <input type="text" id="cliente_nome" name="nome" value="<?= htmlspecialchars($editCliente['nome'] ?? '') ?>" required>
                                </label>

                                <label>
                                    CPF / CNPJ
                                    <span style="display:flex;gap:8px;align-items:center"><input type="text" id="cliente_cpf_cnpj" name="cpf_cnpj" value="<?= htmlspecialchars($editCliente['cpf_cnpj'] ?? '') ?>" required><button type="button" id="btn-buscar-cnpj-cliente" class="btn small">Buscar CNPJ</button></span>
                                </label>

                                <label>
                                    Inscrição Estadual
                                    <input type="text" name="inscricao_estadual" value="<?= htmlspecialchars($editCliente['inscricao_estadual'] ?? '') ?>">
                                </label>

                                <label>
                                    E-mail
                                    <input type="email" name="email" value="<?= htmlspecialchars($editCliente['email'] ?? '') ?>">
                                </label>

                                <label>
                                    Telefone
                                    <input type="text" id="cliente_telefone" name="telefone" value="<?= htmlspecialchars($editCliente['telefone'] ?? '') ?>">
                                </label>

                                <label>
                                    CEP
                                    <input type="text" id="cliente_cep" name="cep" value="<?= htmlspecialchars($editCliente['cep'] ?? '') ?>">
                                </label>

                                <label>
                                    Logradouro
                                    <input type="text" id="cliente_logradouro" name="logradouro" value="<?= htmlspecialchars($editCliente['logradouro'] ?? '') ?>">
                                </label>

                                <label>
                                    Número
                                    <input type="text" id="cliente_numero" name="numero" value="<?= htmlspecialchars($editCliente['numero'] ?? '') ?>">
                                </label>

                                <label>
                                    Complemento
                                    <input type="text" id="cliente_complemento" name="complemento" value="<?= htmlspecialchars($editCliente['complemento'] ?? '') ?>">
                                </label>

                                <label>
                                    Bairro
                                    <input type="text" id="cliente_bairro" name="bairro" value="<?= htmlspecialchars($editCliente['bairro'] ?? '') ?>">
                                </label>

                                <label>
                                    Município
                                    <input type="text" id="cliente_cidade" name="municipio" value="<?= htmlspecialchars($editCliente['municipio'] ?? '') ?>">
                                </label>

                                <label>
                                    Código Municipal (IBGE)
                                    <input type="text" id="cliente_codigo_ibge" name="codigo_municipal" value="<?= htmlspecialchars($editCliente['codigo_municipal'] ?? '') ?>">
                                </label>

                                <label>
                                    UF
                                    <input type="text" id="cliente_uf" name="uf" value="<?= htmlspecialchars($editCliente['uf'] ?? '') ?>">
                                </label>

                                <label>
                                    Cidade
                                    <input type="text" name="cidade" value="<?= htmlspecialchars($editCliente['cidade'] ?? '') ?>">
                                </label>

                                <label>
                                    Status
                                    <select name="status">
                                        <option value="ativo" <?= (($editCliente['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                                        <option value="inativo" <?= (($editCliente['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </label>

                                <div class="form-actions">
                                    <button class="btn primary" type="submit">Salvar</button>
                                    <?php if ($editCliente): ?>
                                        <a class="btn secondary" href="?page=clientes">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <div class="panel">
                            <h3>Lista de clientes</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>E-mail</th>
                                        <th>Cidade</th>
                                        <th>Status</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($clientes as $cliente): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($cliente['nome']) ?></td>
                                            <td><?= htmlspecialchars($cliente['email']) ?></td>
                                            <td><?= htmlspecialchars($cliente['cidade']) ?></td>
                                            <td><span class="badge success"><?= htmlspecialchars($cliente['status']) ?></span></td>
                                            <td class="actions">
                                                <a class="link-button" href="?page=clientes&edit=<?= (int) $cliente['id'] ?>">Editar</a>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Deseja remover este cliente?');">
                                                    <input type="hidden" name="action" value="delete_cliente">
                                                    <input type="hidden" name="id" value="<?= (int) $cliente['id'] ?>">
                                                    <button class="link-button danger" type="submit">Excluir</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php
                    break;

                case 'produtos':
                    ?>
                    <!-- Tela de cadastro e listagem de produtos -->
                    <section class="page-header">
                        <div>
                            <p class="eyebrow">Estoque</p>
                            <h2>Produtos</h2>
                        </div>
                        <a class="btn primary" href="?page=produtos">+ Novo produto</a>
                    </section>

                    <div class="grid two-columns">
                        <div class="panel">
                            <h3><?= $editProduto ? 'Editar produto' : 'Cadastrar produto' ?></h3>
                            <form method="POST" class="form-grid">
                                <input type="hidden" name="action" value="save_produto">
                                <?php if ($editProduto): ?>
                                    <input type="hidden" name="id" value="<?= (int) $editProduto['id'] ?>">
                                <?php endif; ?>
                                <input type="hidden" name="company_id" value="<?= htmlspecialchars($_SESSION['current_company_id'] ?? '') ?>">

                                <label>
                                    Nome
                                    <input type="text" name="nome" value="<?= htmlspecialchars($editProduto['nome'] ?? '') ?>" required>
                                </label>

                                <label>
                                    Código
                                    <input type="text" name="codigo" value="<?= htmlspecialchars($editProduto['codigo'] ?? '') ?>" required>
                                </label>

                                <label>
                                    NCM
                                    <input type="text" name="ncm" value="<?= htmlspecialchars($editProduto['ncm'] ?? '') ?>">
                                </label>

                                <label>
                                    CEST
                                    <input type="text" name="cest" value="<?= htmlspecialchars($editProduto['cest'] ?? '') ?>">
                                </label>

                                <label>Origem da mercadoria<select name="merchandise_origin"><option value="">Selecione</option><?php foreach (['0'=>'0 - Nacional','1'=>'1 - Estrangeira direta','2'=>'2 - Estrangeira mercado interno','3'=>'3 - Nacional > 40% importado','4'=>'4 - Nacional PPB','5'=>'5 - Nacional ≤ 40% importado','6'=>'6 - Estrangeira sem similar','7'=>'7 - Estrangeira interna sem similar','8'=>'8 - Nacional > 70% importado'] as $code=>$label): ?><option value="<?= $code ?>" <?= (($editProduto['merchandise_origin'] ?? '') === $code) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option><?php endforeach; ?></select></label>
                                <label>EX TIPI<input type="text" name="extipi" value="<?= htmlspecialchars($editProduto['extipi'] ?? '') ?>"></label>
                                <label>cBenef<input type="text" name="tax_benefit_code" value="<?= htmlspecialchars($editProduto['tax_benefit_code'] ?? '') ?>"></label>
                                <label>FCI<input type="text" name="fci_number" value="<?= htmlspecialchars($editProduto['fci_number'] ?? '') ?>"></label>

                                <label>
                                    Unidade
                                    <input type="text" name="unidade" value="<?= htmlspecialchars($editProduto['unidade'] ?? 'UN') ?>">
                                </label>

                                <label>
                                    GTIN / Código de Barras
                                    <input type="text" name="gtin" value="<?= htmlspecialchars($editProduto['gtin'] ?? '') ?>">
                                </label>
                                <label>Unidade tributável<input type="text" name="taxable_unit" value="<?= htmlspecialchars($editProduto['taxable_unit'] ?? ($editProduto['unidade'] ?? 'UN')) ?>"></label>
                                <label>GTIN tributável<input type="text" name="gtin_tributable" value="<?= htmlspecialchars($editProduto['gtin_tributable'] ?? ($editProduto['gtin'] ?? 'SEM GTIN')) ?>"></label>
                                <label>Fator de conversão<input type="number" step="0.000001" min="0.000001" name="conversion_factor" value="<?= htmlspecialchars((string)($editProduto['conversion_factor'] ?? 1)) ?>"></label>

                                <label>
                                    CFOP Padrão
                                    <input type="text" name="cfop_padrao" value="<?= htmlspecialchars($editProduto['cfop_padrao'] ?? '') ?>">
                                </label>

                                <label>
                                    Categoria
                                    <input type="text" name="categoria" value="<?= htmlspecialchars($editProduto['categoria'] ?? '') ?>">
                                </label>

                                <label>
                                    Preço
                                    <input type="number" step="0.01" min="0" name="preco" value="<?= htmlspecialchars((string) ($editProduto['preco'] ?? 0)) ?>" required>
                                </label>
                                <label>Preço de custo<input type="number" step="0.0001" min="0" name="cost_price" value="<?= htmlspecialchars((string)($editProduto['cost_price'] ?? 0)) ?>"></label>

                                <label>
                                    Estoque atual
                                    <input type="number" min="0" name="estoque_atual" value="<?= htmlspecialchars((string) ($editProduto['estoque_atual'] ?? 0)) ?>" required>
                                </label>
                                <label>Estoque mínimo<input type="number" step="0.0001" min="0" name="minimum_stock" value="<?= htmlspecialchars((string)($editProduto['minimum_stock'] ?? 0)) ?>"></label>

                                <label>
                                    Status
                                    <select name="status">
                                        <option value="ativo" <?= (($editProduto['status'] ?? 'ativo') === 'ativo') ? 'selected' : '' ?>>Ativo</option>
                                        <option value="inativo" <?= (($editProduto['status'] ?? 'ativo') === 'inativo') ? 'selected' : '' ?>>Inativo</option>
                                    </select>
                                </label>

                                <div class="form-actions">
                                    <button class="btn primary" type="submit">Salvar</button>
                                    <?php if ($editProduto): ?>
                                        <a class="btn secondary" href="?page=produtos">Cancelar</a>
                                    <?php endif; ?>
                                </div>
                            </form>
                        </div>

                        <div class="panel">
                            <h3>Lista de produtos</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>Produto</th>
                                        <th>Código</th>
                                        <th>Preço</th>
                                        <th>Estoque</th>
                                        <th>Ações</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($produtos as $produto): ?>
                                        <tr>
                                            <td><?= htmlspecialchars($produto['nome']) ?></td>
                                            <td><?= htmlspecialchars($produto['codigo']) ?></td>
                                            <td><?= formatCurrency((float) $produto['preco']) ?></td>
                                            <td><?= (int) $produto['estoque_atual'] ?></td>
                                            <td class="actions">
                                                <a class="link-button" href="?page=produtos&edit=<?= (int) $produto['id'] ?>">Editar</a>
                                                <form method="POST" class="inline-form" onsubmit="return confirm('Deseja remover este produto?');">
                                                    <input type="hidden" name="action" value="delete_produto">
                                                    <input type="hidden" name="id" value="<?= (int) $produto['id'] ?>">
                                                    <button class="link-button danger" type="submit">Excluir</button>
                                                </form>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php
                    break;

                case 'vendas':
                    ?>
                    <!-- Tela de registro e histórico de vendas -->
                    <section class="page-header">
                        <div>
                            <p class="eyebrow">Operações</p>
                            <h2>Vendas</h2>
                        </div>
                    </section>

                    <div class="grid two-columns">
                        <div class="panel">
                            <h3>Registrar venda</h3>
                            <form method="POST" class="form-grid">
                                <input type="hidden" name="action" value="save_venda">

                                <label>
                                    Cliente
                                    <select name="cliente_id" required>
                                        <option value="">Selecione</option>
                                        <?php foreach ($clientes as $cliente): ?>
                                            <option value="<?= (int) $cliente['id'] ?>"><?= htmlspecialchars($cliente['nome']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>

                                <?php for ($i = 0; $i < 3; $i++): ?>
                                    <div class="inline-row">
                                        <label>
                                            Produto <?= $i + 1 ?>
                                            <select name="itens[<?= $i ?>][produto_id]">
                                                <option value="">Selecione</option>
                                                <?php foreach ($produtos as $produto): ?>
                                                    <option value="<?= (int) $produto['id'] ?>"><?= htmlspecialchars($produto['nome']) ?> (<?= (int) $produto['estoque_atual'] ?> em estoque)</option>
                                                <?php endforeach; ?>
                                            </select>
                                        </label>

                                        <label>
                                            Quantidade
                                            <input type="number" min="1" name="itens[<?= $i ?>][quantidade]" value="0">
                                        </label>
                                    </div>
                                <?php endfor; ?>

                                <div class="form-actions">
                                    <button class="btn primary" type="submit">Finalizar venda</button>
                                </div>
                            </form>
                        </div>

                        <div class="panel">
                            <h3>Histórico de vendas</h3>
                            <table>
                                <thead>
                                    <tr>
                                        <th>ID</th>
                                        <th>Cliente</th>
                                        <th>Data</th>
                                        <th>Total</th>
                                        <th>Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($vendas as $venda): ?>
                                        <tr>
                                            <td>#<?= (int) $venda['id'] ?></td>
                                            <td><?= htmlspecialchars($venda['cliente_nome']) ?></td>
                                            <td><?= htmlspecialchars($venda['data_venda']) ?></td>
                                            <td><?= formatCurrency((float) $venda['total']) ?></td>
                                            <td><span class="badge success"><?= htmlspecialchars($venda['status']) ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php
                    break;

                default:
                    $dashboardTab=in_array((string)($_GET['tab']??'overview'),['overview','sales','notes','customers','stock','financial'],true)?(string)($_GET['tab']??'overview'):'overview';
                    $dashboardQuery=static function(array$replace=[])use($dashboardFilters):string{$query=['page'=>'dashboard','tab'=>'overview',...$dashboardFilters,...$replace];foreach($query as$key=>$value)if($value===''||$value===0)unset($query[$key]);return'?'.http_build_query($query);};
                    $renderMoneyChart=static function(array$days,string$label):string{$payload=['type'=>'money','label'=>$label,'labels'=>array_column($days,'label'),'values'=>array_map(static fn(array$day):float=>(float)$day['revenue'],$days),'counts'=>array_map(static fn(array$day):int=>(int)$day['count'],$days)];return'<div class="dashboard-chart-frame"><canvas class="dashboard-chart" data-chart="'.htmlspecialchars(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8').'" role="img" aria-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'"></canvas></div>';};
                    $renderCountChart=static function(array$days,string$key,string$label):string{$payload=['type'=>'count','label'=>$label,'labels'=>array_column($days,'label'),'values'=>array_map(static fn(array$day):int=>(int)$day[$key],$days)];return'<div class="dashboard-chart-frame"><canvas class="dashboard-chart" data-chart="'.htmlspecialchars(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8').'" role="img" aria-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'"></canvas></div>';};
                    $renderDonutChart=static function(array$rows,string$label):string{$rows=array_slice($rows,0,6);$payload=['type'=>'donut','label'=>$label,'labels'=>array_map(static fn(array$row):string=>(string)$row['nome'],$rows),'values'=>array_map(static fn(array$row):float=>(float)$row['quantity'],$rows)];return'<div class="dashboard-chart-frame dashboard-chart-frame--donut"><canvas class="dashboard-chart" data-chart="'.htmlspecialchars(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),ENT_QUOTES,'UTF-8').'" role="img" aria-label="'.htmlspecialchars($label,ENT_QUOTES,'UTF-8').'"></canvas></div>';};
                    $preset=static fn(int$days):string=>$dashboardQuery(['from'=>$dashboardToday->modify('-'.($days-1).' days')->format('Y-m-d'),'to'=>$dashboardToday->format('Y-m-d'),'tab'=>$dashboardTab]);
                    ?>
                    <section class="page-header dashboard-heading"><div><p class="eyebrow">Inteligência operacional</p><h2>Dashboard analítico</h2><p>Vendas, notas, clientes e estoque com fontes separadas e filtros por período.</p></div></section>
                    <form class="dashboard-filters panel" method="get"><input type="hidden" name="page" value="dashboard"><input type="hidden" name="tab" value="<?=htmlspecialchars($dashboardTab)?>"><label>Data inicial<input type="date" name="from" value="<?=htmlspecialchars($dashboardFilters['from'])?>"></label><label>Data final<input type="date" name="to" value="<?=htmlspecialchars($dashboardFilters['to'])?>"></label><label>Cliente<select name="customer_id"><option value="0">Todos os clientes</option><?php foreach($dashboardCustomers as$customer):?><option value="<?=(int)$customer['id']?>"<?=$dashboardFilters['customer_id']===(int)$customer['id']?' selected':''?>><?=htmlspecialchars($customer['nome'])?></option><?php endforeach?></select></label><label>Modelo<select name="model"><option value="">Todos</option><option value="55"<?=$dashboardFilters['model']==='55'?' selected':''?>>55 — NF-e</option><option value="65"<?=$dashboardFilters['model']==='65'?' selected':''?>>65 — NFC-e</option></select></label><?php if($dashboardTab==='notes'):?><label>Status<select name="status"><option value="">Todos</option><?php foreach(['pending'=>'Pendentes','rejected'=>'Rejeitadas','authorized'=>'Autorizadas','preparing'=>'Em preparação']as$value=>$label):?><option value="<?=$value?>"<?=$dashboardFilters['status']===$value?' selected':''?>><?=$label?></option><?php endforeach?></select></label><?php endif?><div class="dashboard-filter-actions"><button class="btn" type="submit">Aplicar filtros</button><a class="btn secondary" href="?page=dashboard&amp;tab=<?=urlencode($dashboardTab)?>">Limpar filtros</a></div><div class="dashboard-presets"><span>Atalhos:</span><a href="<?=$preset(1)?>">Hoje</a><a href="<?=$preset(7)?>">7 dias</a><a href="<?=$preset(30)?>">30 dias</a><a href="<?=$dashboardQuery(['from'=>$dashboardToday->modify('first day of this month')->format('Y-m-d'),'to'=>$dashboardToday->format('Y-m-d'),'tab'=>$dashboardTab])?>">Este mês</a></div></form>
                    <nav class="dashboard-tabs" aria-label="Seções do Dashboard"><?php foreach(['overview'=>'Visão geral','sales'=>'Vendas','notes'=>'Notas','customers'=>'Clientes','stock'=>'Estoque','financial'=>'Financeiro']as$value=>$label):?><a class="<?=$dashboardTab===$value?'active':''?>" href="<?=$dashboardQuery(['tab'=>$value])?>" aria-current="<?=$dashboardTab===$value?'page':'false'?>"><?=$label?></a><?php endforeach?></nav>

                    <?php if($dashboardTab==='overview'):$overviewDays=array_slice($dashboard['sales_by_day'],-7);?>
                        <div class="stats-grid analytics-stats dashboard-kpis"><a class="stat-card dashboard-kpi dashboard-kpi--green" href="?page=cadastro&amp;tab=pessoas"><span>Clientes cadastrados</span><strong><?=(int)$dashboard['clientes']?></strong><small>Acessar cadastro ›</small></a><a class="stat-card dashboard-kpi dashboard-kpi--cyan" href="?page=pedidos&amp;tab=emitidos"><span>Pedidos emitidos</span><strong><?=(int)$dashboard['issued_orders']?></strong><small>Acessar pedidos ›</small></a><a class="stat-card dashboard-kpi dashboard-kpi--blue" href="?page=fiscal_notes"><span>Notas na Central</span><strong><?=(int)$dashboard['notes']['total']?></strong><small>Acessar Central de Notas ›</small></a><a class="stat-card dashboard-kpi dashboard-kpi--red" href="?page=fiscal_notes&amp;status=ATTENTION"><span>Pendências fiscais</span><strong><?=(int)$dashboard['notes']['attention']?></strong><small>Ver pendentes e rejeitadas ›</small></a><a class="stat-card dashboard-kpi dashboard-kpi--amber" href="?page=estoque"><span>Saldo em estoque</span><strong><?=htmlspecialchars(number_format((float)$dashboard['stock_balance'],2,',','.'))?></strong><small><?=(int)$dashboard['estoque_baixo']?> produto(s) em nível baixo ›</small></a><a class="stat-card dashboard-kpi dashboard-kpi--blue" href="?page=financeiro"><span>Saldo financeiro projetado</span><strong><?=formatCurrency($dashboard['financial']['receivable']-$dashboard['financial']['payable'])?></strong><small>A receber <?=formatCurrency($dashboard['financial']['receivable'])?> ›</small></a></div>
                        <div class="analytics-grid dashboard-overview-charts"><div class="panel sales-panel"><div class="sales-header"><div><p class="eyebrow">Pedidos</p><h3>Faturamento dos últimos 7 dias</h3></div><span class="sales-total-label">Total: <?=formatCurrency((float)array_sum(array_column($overviewDays,'revenue')))?></span></div><?=$renderMoneyChart($overviewDays,'Faturamento dos últimos sete dias')?></div><div class="panel sales-panel"><div class="sales-header"><div><p class="eyebrow">Produtos</p><h3>Mais vendidos no período</h3></div></div><?=$dashboard['top_products']?$renderDonutChart($dashboard['top_products'],'Produtos mais vendidos'):'<div class="dashboard-empty-chart">Ainda não existem vendas no período.</div>'?></div></div>
                        <div class="panel"><h3>Alertas de estoque</h3><p>Produtos ativos abaixo do estoque mínimo: <?=(int)$dashboard['estoque_baixo']?></p><?php foreach($dashboard['low_stock_products']as$product):?><div class="stock-row"><span><?=htmlspecialchars($product['nome'])?></span><strong><?=htmlspecialchars((string)$product['estoque_atual'])?> <?=htmlspecialchars((string)($product['unidade']?:'UN'))?> · mínimo <?=htmlspecialchars((string)$product['minimum_stock'])?></strong></div><?php endforeach?></div>
                    <?php elseif($dashboardTab==='sales'):?>
                        <div class="stats-grid"><div class="stat-card warning"><span>Total vendido</span><strong><?=formatCurrency((float)$dashboard['faturamento'])?></strong></div><div class="stat-card"><span>Quantidade de pedidos</span><strong><?=(int)$dashboard['vendas']?></strong></div><div class="stat-card"><span>Ticket médio</span><strong><?=formatCurrency((float)$dashboard['ticket_average'])?></strong></div><div class="stat-card"><span>Maior venda</span><strong><?=formatCurrency((float)$dashboard['largest_sale'])?></strong></div></div>
                        <div class="analytics-grid"><div class="panel sales-panel"><div class="sales-header"><h3>Faturamento por dia</h3></div><?=$renderMoneyChart($dashboard['sales_by_day'],'Faturamento por dia')?></div><div class="panel sales-panel"><div class="sales-header"><h3>Pedidos por dia</h3></div><?=$renderCountChart($dashboard['sales_by_day'],'count','Pedidos por dia')?></div></div>
                        <div class="analytics-grid"><div class="panel analytics-table"><h3>Produtos mais vendidos</h3><table><thead><tr><th>Produto</th><th>Quantidade</th><th>Faturamento</th></tr></thead><tbody><?php foreach($dashboard['top_products']as$row):?><tr><td><?=htmlspecialchars($row['nome'])?></td><td><?=htmlspecialchars((string)$row['quantity'])?> <?=htmlspecialchars((string)$row['unidade'])?></td><td><?=formatCurrency((float)$row['revenue'])?></td></tr><?php endforeach?></tbody></table></div><div class="panel analytics-table"><h3>Últimos itens vendidos</h3><table><thead><tr><th>Data</th><th>Pedido</th><th>Cliente</th><th>Produto</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr></thead><tbody><?php foreach($dashboard['last_sold_items']as$row):?><tr><td><?=htmlspecialchars(date('d/m/Y',strtotime($row['operation_date'])))?></td><td>#<?=(int)$row['order_id']?></td><td><?=htmlspecialchars($row['customer_name'])?></td><td><?=htmlspecialchars($row['product_name'])?></td><td><?=htmlspecialchars((string)$row['quantity'])?></td><td><?=formatCurrency((float)$row['unit_price'])?></td><td><?=formatCurrency((float)$row['net_total'])?></td></tr><?php endforeach?></tbody></table></div></div>
                    <?php elseif($dashboardTab==='notes'):$notes=$dashboard['notes'];?>
                        <div class="stats-grid"><div class="stat-card"><span>Notas geradas</span><strong><?=(int)$notes['total']?></strong></div><div class="stat-card"><span>Pendentes</span><strong><?=(int)$notes['pending']?></strong></div><div class="stat-card"><span>Rejeitadas</span><strong><?=(int)$notes['rejected']?></strong></div><div class="stat-card"><span>Autorizadas</span><strong><?=(int)$notes['authorized']?></strong></div><div class="stat-card warning"><span>Valor fiscal autorizado</span><strong><?=formatCurrency((float)$notes['fiscal_total'])?></strong></div></div>
                        <div class="analytics-grid"><div class="panel analytics-status"><h3>Notas por status</h3><?php $statusMax=max(1,...array_values($notes['by_status']));foreach($notes['by_status']as$label=>$value):?><div class="analytics-progress" title="<?=htmlspecialchars($label)?>: <?=(int)$value?>"><span><?=htmlspecialchars($label)?></span><div><i data-width="<?=number_format($value/$statusMax*100,2,'.','')?>"></i></div><strong><?=(int)$value?></strong></div><?php endforeach?></div><div class="panel sales-panel"><h3>Notas por dia</h3><?=$renderCountChart($notes['by_day'],'count','Notas por dia')?></div></div><div class="panel"><h3>Divisão por modelo</h3><div class="model-split"><span>NF-e 55 <strong><?=(int)$notes['by_model']['55']?></strong></span><span>NFC-e 65 <strong><?=(int)$notes['by_model']['65']?></strong></span></div></div>
                    <?php elseif($dashboardTab==='customers'):$best=$dashboard['best_customer'];?>
                        <div class="stats-grid"><div class="stat-card stat-card--wide"><span>Maior cliente do período</span><strong><?=htmlspecialchars((string)($best['customer_name']??'Sem vendas'))?></strong><small><?=formatCurrency((float)($best['revenue']??0))?> em <?=(int)($best['orders_count']??0)?> pedido(s)</small></div></div><div class="panel analytics-table"><h3>Top 10 clientes por faturamento</h3><table><thead><tr><th>Cliente</th><th>Pedidos</th><th>Itens</th><th>Faturamento</th><th>Ticket médio</th></tr></thead><tbody><?php foreach($dashboard['top_customers']as$row):?><tr><td><?=htmlspecialchars($row['customer_name'])?></td><td><?=(int)$row['orders_count']?></td><td><?=htmlspecialchars((string)$row['items_count'])?></td><td><?=formatCurrency((float)$row['revenue'])?></td><td><?=formatCurrency((float)$row['average_ticket'])?></td></tr><?php endforeach?></tbody></table></div>
                    <?php elseif($dashboardTab==='financial'):$finance=$dashboard['financial'];?>
                        <div class="stats-grid"><div class="stat-card"><span>Contas a receber</span><strong><?=formatCurrency($finance['receivable'])?></strong></div><div class="stat-card"><span>Contas a pagar</span><strong><?=formatCurrency($finance['payable'])?></strong></div><div class="stat-card warning"><span>Em atraso</span><strong><?=formatCurrency($finance['overdue'])?></strong></div><div class="stat-card"><span>Saldo projetado</span><strong><?=formatCurrency($finance['receivable']-$finance['payable'])?></strong></div></div><div class="panel"><h3>Resumo financeiro</h3><p>Os valores consideram títulos em aberto e parcialmente pagos. Vendas a prazo geram contas a receber; entradas a prazo e despesas geram contas a pagar.</p><a class="btn" href="?page=financeiro">Abrir módulo Financeiro</a></div>
                    <?php else:$movement=$dashboard['stock']['movement'];?>
                        <div class="stats-grid"><div class="stat-card"><span>Estoque baixo</span><strong><?=(int)$dashboard['estoque_baixo']?></strong></div><div class="stat-card"><span>Itens vendidos no período</span><strong><?=htmlspecialchars((string)$dashboard['stock']['sold_quantity'])?></strong></div><div class="stat-card"><span>Movimentações de saída registradas</span><strong><?=$movement['available']?'Disponível':'Sem ledger'?></strong></div></div><div class="message warning"><?=htmlspecialchars($movement['note'])?> As quantidades abaixo representam itens vendidos, não baixa comprovada de estoque.</div><div class="analytics-grid"><div class="panel analytics-table"><h3>Mais vendidos</h3><table><thead><tr><th>Produto</th><th>Quantidade vendida</th><th>Faturamento</th></tr></thead><tbody><?php foreach($dashboard['top_products']as$row):?><tr><td><?=htmlspecialchars($row['nome'])?></td><td><?=htmlspecialchars((string)$row['quantity'])?> <?=htmlspecialchars((string)$row['unidade'])?></td><td><?=formatCurrency((float)$row['revenue'])?></td></tr><?php endforeach?></tbody></table></div><div class="panel analytics-table"><h3>Estoque baixo</h3><table><thead><tr><th>Produto</th><th>Atual</th><th>Mínimo</th><th>Diferença</th></tr></thead><tbody><?php foreach($dashboard['low_stock_products']as$row):?><tr><td><?=htmlspecialchars($row['nome'])?></td><td><?=htmlspecialchars((string)$row['estoque_atual'])?> <?=htmlspecialchars((string)$row['unidade'])?></td><td><?=htmlspecialchars((string)$row['minimum_stock'])?></td><td><?=htmlspecialchars((string)((float)$row['estoque_atual']-(float)$row['minimum_stock']))?></td></tr><?php endforeach?></tbody></table></div></div><div class="panel analytics-table"><h3>Últimos itens vendidos</h3><table><thead><tr><th>Data</th><th>Produto</th><th>Pedido</th><th>Cliente</th><th>Quantidade</th><th>Estoque atual</th></tr></thead><tbody><?php foreach($dashboard['last_sold_items']as$row):?><tr><td><?=htmlspecialchars(date('d/m/Y',strtotime($row['operation_date'])))?></td><td><?=htmlspecialchars($row['product_name'])?></td><td>#<?=(int)$row['order_id']?></td><td><?=htmlspecialchars($row['customer_name'])?></td><td><?=htmlspecialchars((string)$row['quantity'])?></td><td><?=htmlspecialchars((string)$row['estoque_atual'])?> <?=htmlspecialchars((string)$row['unidade'])?></td></tr><?php endforeach?></tbody></table></div>
                    <?php
                    endif;
                    break;
            }
            ?>
            <footer class="site-credit erp-credit">Desenvolvido por <a href="https://willspacce.netlify.app/" target="_blank" rel="noopener noreferrer" aria-label="Portfólio de Willyan Martins">Willyan Martins <span aria-hidden="true">›</span></a></footer>
        </main>
    </div>
    <?php if ($page === 'dashboard'): ?><script src="<?= htmlspecialchars($assetUrl('vendor/chart.umd.min.js')) ?>"></script><?php endif; ?>
    <script src="<?= htmlspecialchars($assetUrl('app.js')) ?>"></script>
</body>
</html>
