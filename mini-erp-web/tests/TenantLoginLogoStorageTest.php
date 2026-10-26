<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/Services/TenantLoginLogoStorage.php';

function logoAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
$root = sys_get_temp_dir() . '/tenant-logo-test-' . bin2hex(random_bytes(8));
$storage = new MiniErp\Services\TenantLoginLogoStorage($root);
try {
    $image = imagecreatetruecolor(8, 4);
    ob_start(); imagepng($image); $png = (string) ob_get_clean();
    imagedestroy($image);
    logoAssert($storage->url(14) === null, 'No logo initially');
    $storage->save(14, $png);
    logoAssert(str_starts_with((string) $storage->url(14), '/uploads/tenant-logos/14.png?v='), 'Tenant-scoped URL');
    logoAssert($storage->url(15) === null, 'Other companies remain unchanged');
    $saved = file_get_contents($root . '/uploads/tenant-logos/14.png');
    logoAssert(getimagesizefromstring($saved)[2] === IMAGETYPE_PNG, 'Stored image is re-encoded PNG');
    $storage->save(14, $png);
    logoAssert($storage->url(14) !== null, 'Existing logo can be replaced');
    foreach (['<svg onload="alert(1)"></svg>', '<?php echo "invalid";', str_repeat('x', 2097153)] as $invalid) {
        try { $storage->save(14, $invalid); throw new RuntimeException('Invalid file accepted'); }
        catch (InvalidArgumentException) {}
        logoAssert(file_get_contents($root . '/uploads/tenant-logos/14.png') === $saved, 'Failed upload preserves old logo');
    }
    try { $storage->save(0, $png); throw new RuntimeException('Invalid tenant accepted'); }
    catch (InvalidArgumentException) {}
    echo "TenantLoginLogoStorage OK\n";
} finally {
    if (is_file($root . '/uploads/tenant-logos/14.png')) unlink($root . '/uploads/tenant-logos/14.png');
    if (is_dir($root . '/uploads/tenant-logos')) rmdir($root . '/uploads/tenant-logos');
    if (is_dir($root . '/uploads')) rmdir($root . '/uploads');
    if (is_dir($root)) rmdir($root);
}
