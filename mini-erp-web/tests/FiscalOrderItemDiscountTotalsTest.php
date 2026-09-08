<?php
declare(strict_types=1);
$source=(string)file_get_contents(dirname(__DIR__).'/src/Repositories/FiscalOperationRepository.php');
if(substr_count($source,'$itemsNetTotal=bcadd($itemsNetTotal,$net,2)')!==1||substr_count($source,'$itemsNet=bcadd($itemsNet,$net,2)')!==1)throw new RuntimeException('Item discounts must affect create and update totals.');
if(!str_contains($source,'bcsub($itemsNetTotal,$discount,2)')||!str_contains($source,'bcsub($itemsNet,$discount,2)'))throw new RuntimeException('Header discount must be applied after item discounts.');
echo "FiscalOrderItemDiscountTotalsTest OK\n";
