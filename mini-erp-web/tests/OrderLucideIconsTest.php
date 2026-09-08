<?php
declare(strict_types=1);
function lucideAssert(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$root=dirname(__DIR__);$page=(string)file_get_contents($root.'/public/index.php');$css=(string)file_get_contents($root.'/public/assets/order-editor.css');
foreach(['data-order-action="save"','data-fiscal-action="finalize"','id="fiscal-preview-submit"','data-order-test-fill','data-order-new','disabled title="Financeiro']as$contract)lucideAssert(str_contains($page,$contract),'contrato preservado: '.$contract);
lucideAssert(substr_count($page,'class="erp-icon order-routine__icon"')===7,'sete ícones Lucide na barra, incluindo Importar XML');
foreach(['M19 21H5','M14.5 2H6','M6 9V2h12','M13 2 3 14h9','M8 12h8M12 8v8','M16 8h-6']as$path)lucideAssert(str_contains($page,$path),'ícone Lucide '.$path);
foreach(['Gravar','Preparar Nota','Prévia DANF','Preencher teste','Novo','Financeiro','Importar XML']as$text)lucideAssert(str_contains($page,$text),'texto preservado '.$text);
lucideAssert(str_contains($css,'.order-routine .erp-icon')&&str_contains($css,'stroke-width:2')&&str_contains($css,'color:currentColor'),'padrão visual Lucide');
echo "OrderLucideIconsTest OK\n";
