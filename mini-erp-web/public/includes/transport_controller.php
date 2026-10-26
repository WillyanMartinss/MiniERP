<?php
declare(strict_types=1);

use MiniErp\Transport\TransportRepository;
use MiniErp\Transport\TransportFields;
use MiniErp\Transport\TransportDocumentValidator;
use MiniErp\Transport\CteXmlService;

if ($page !== 'transporte') return;
require_once __DIR__.'/../../vendor/autoload.php';
$transportError='';$transportRepo=null;
$transportTab=in_array($_GET['tab']??'cte',['cte','mdfe','veiculos','configuracoes'],true)?($_GET['tab']??'cte'):'cte';
$_SESSION['erp_transport_csrf']??=bin2hex(random_bytes(32));
try {
    if ($secureErpRuntime===null) throw new RuntimeException('Entre pelo login da empresa para acessar Transporte.');
    $transportTenant=$secureErpRuntime['result']->tenantContext->getEffectiveTenantId();
    $transportRepo=new TransportRepository($secureErpRuntime['connection'],$transportTenant);
    if (!$transportRepo->available()) throw new RuntimeException('O módulo aguarda a atualização de estrutura 20260913_create_transport_documents.sql no painel da plataforma.');
    if (isset($_GET['cte_file'])) {
        $fileDocument=$transportRepo->find((int)($_GET['document']??0));
        $artifact=$fileDocument['snapshot']['cte_artifact']??null;
        if(!$fileDocument||$fileDocument['model']!=='57'||!$artifact)throw new InvalidArgumentException('Gere ou importe o XML deste CT-e antes de baixar.');
        $format=(string)$_GET['cte_file'];
        if(!in_array($format,['xml','pdf'],true))throw new InvalidArgumentException('Formato inválido.');
        $bytes=$format==='xml'?$artifact['xml']:(new CteXmlService())->pdf($artifact['xml']);
        header('Content-Type: '.($format==='xml'?'application/xml; charset=utf-8':'application/pdf'));
        header('Content-Disposition: '.($format==='xml'?'attachment':'inline').'; filename="cte-'.(int)$fileDocument['id'].($format==='pdf'?'-dacte-previa':'').'.'.$format.'"');
        header('Content-Length: '.strlen($bytes));header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');
        if($format==='xml')header("Content-Security-Policy: sandbox; default-src 'none'");
        session_write_close();echo $bytes;exit;
    }
    if (($_SERVER['REQUEST_METHOD']??'GET')==='POST') {
        if (!hash_equals((string)$_SESSION['erp_transport_csrf'],(string)($_POST['csrf_token']??''))) throw new InvalidArgumentException('Sessão expirada. Atualize a página e tente novamente.');
        if (($erpAccessPolicy['access_mode']??'FULL')==='READ_ONLY'||empty($erpAccessPolicy['can_issue_fiscal'])) throw new InvalidArgumentException('Alterações fiscais não estão liberadas para esta empresa.');
        $actor=(int)$currentUser['id'];$transportAction=(string)($_POST['action']??'');
        if ($transportAction==='transport_cte_generate') {
            $id=$transportRepo->generateCte((int)($_POST['document_id']??0),(int)($_POST['revision']??0),$actor);
            $next='?page=transporte&tab=cte&document='.$id;
        } elseif ($transportAction==='transport_cte_import') {
            $file=$_FILES['cte_xml']??[];
            if(($file['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||!is_uploaded_file($file['tmp_name']??'')||($file['size']??0)>2097152)throw new InvalidArgumentException('Selecione um arquivo XML de CT-e de até 2 MB.');
            $xml=file_get_contents($file['tmp_name']);
            if($xml===false)throw new InvalidArgumentException('Não foi possível ler o arquivo.');
            $id=$transportRepo->importCte($xml,$actor);$next='?page=transporte&tab=cte&document='.$id;
        } elseif ($transportAction==='transport_vehicle_save') {
            $transportRepo->saveVehicle($_POST);$next='?page=transporte&tab=veiculos';
        } elseif ($transportAction==='transport_settings_save') {
            $transportRepo->saveSettings($_POST,$actor);$next='?page=transporte&tab=configuracoes';
        } elseif ($transportAction==='transport_document_save') {
            $model=(string)($_POST['model']??'');
            $data=TransportFields::normalize($model,$_POST);
            $snapshot=$transportRepo->snapshot($data);
            $validation=($_POST['intent']??'draft')==='validate'?(new TransportDocumentValidator())->validate($data,$snapshot):null;
            $id=$transportRepo->save((int)($_POST['document_id']??0),(int)($_POST['revision']??0),$data,$snapshot,$validation,$actor);
            $next='?page=transporte&tab='.($model==='57'?'cte':'mdfe').'&document='.$id;
        } else throw new InvalidArgumentException('Ação de transporte inválida.');
        header('Location: '.$next.'&saved=1',true,303);exit;
    }
} catch (PDOException $e) {
    error_log('TRANSPORT_DATABASE_ERROR '.$e->getCode());
    $transportError=($e->errorInfo[1]??0)===1062 && ($_POST['action']??'')==='transport_vehicle_save'
        ?'Esta placa já está cadastrada nesta empresa. Abra o veículo existente para editá-lo.'
        :'Não foi possível carregar ou salvar os dados de transporte. Tente novamente.';
} catch (InvalidArgumentException|RuntimeException $e) {
    if(isset($_GET['cte_file']))http_response_code(422);
    $transportError=$e->getMessage();
} catch (Throwable $e) {
    error_log('TRANSPORT_ERROR '.get_class($e));
    $transportError='Não foi possível carregar ou salvar o transporte. Os campos permanecem no formulário para revisão.';
}
