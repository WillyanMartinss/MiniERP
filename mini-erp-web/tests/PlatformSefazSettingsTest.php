<?php
declare(strict_types=1);

function sefazSettingsAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);
$page=(string)file_get_contents($root.'/public/plataforma/configuracoes.php');
$repo=(string)file_get_contents($root.'/src/Repositories/PlatformServerSettingsRepository.php');
$factory=(string)file_get_contents($root.'/src/Services/FiscalLocalPipelineFactory.php');
$pipeline=(string)file_get_contents($root.'/src/Services/OfflineFiscalDocumentPipelineService.php');
$builder=(string)file_get_contents($root.'/src/Fiscal/FiscalNfeXmlBuilder.php');
foreach(['Conexões','Configuração SEFAZ','sefaz_technical_cnpj','sefaz_technical_contact','sefaz_technical_email','sefaz_technical_phone','sefaz_csrt_env']as$needle)sefazSettingsAssert(str_contains($page,$needle),'UI '.$needle);
sefazSettingsAssert(str_contains($page,"settings_section\" value=\"connections")&&str_contains($page,"settings_section\" value=\"sefaz"),'formulários isolados');
sefazSettingsAssert(str_contains($repo,'saveSefazTechnical')&&str_contains($repo,"validCnpj(\$values['sefaz_technical_cnpj'])"),'validação técnica');
sefazSettingsAssert(str_contains($factory,'PlatformServerSettingsRepository')&&str_contains($factory,'technicalResponsible:'),'configuração ligada ao pipeline');
sefazSettingsAssert(str_contains($pipeline,"'technical_responsible' => \$this->technicalResponsible"),'dados enviados ao builder');
sefazSettingsAssert(str_contains($builder,'taginfRespTec')&&str_contains($builder,"technical['idCSRT']"),'infRespTec e CSRT');
sefazSettingsAssert(!str_contains($page,'name="CSRT"'),'segredo não exposto no formulário');
echo "PlatformSefazSettingsTest OK\n";
