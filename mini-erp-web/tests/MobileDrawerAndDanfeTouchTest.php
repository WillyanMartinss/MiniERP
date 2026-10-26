<?php
declare(strict_types=1);

function mobileUiAssert(bool $condition, string $label): void
{
    if (!$condition) throw new RuntimeException($label);
    echo $label . " PASS\n";
}

$page = (string) file_get_contents(__DIR__ . '/../public/index.php');
$js = (string) file_get_contents(__DIR__ . '/../public/assets/sidebar-menu.js');
$css = (string) file_get_contents(__DIR__ . '/../public/assets/style.css');

mobileUiAssert(str_contains($page, 'aria-controls="sidebar-drawer"') && str_contains($page, 'data-drawer-close'), 'MobileDrawerAccessibleControlsTest');
mobileUiAssert(str_contains($js, "document.body.classList.add('mobile-menu-open')") && str_contains($js, "closeButton?.addEventListener('click', closeDrawer)"), 'MobileDrawerInteractionTest');
mobileUiAssert(str_contains($css, 'height:auto') && str_contains($css, 'width:min(78vw,310px)') && str_contains($css, 'overscroll-behavior:contain'), 'MobileDrawerCompactGlassLayoutTest');
mobileUiAssert(str_contains($page, "window.matchMedia('(max-width: 899px), (pointer: coarse)').matches") && str_contains($page, 'else location.href=result.danfe_url'), 'MobileDanfeSameTabNavigationTest');
