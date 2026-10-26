<?php
declare(strict_types=1);

if (getenv('RUN_MOBILE_LOGIN_HTTP_TESTS') !== '1') {
    echo "MobileLoginHttp SKIPPED\n";
    exit;
}
require __DIR__ . '/../vendor/autoload.php';

$base = rtrim((string) getenv('MOBILE_LOGIN_TEST_BASE'), '/');
$slug = (string) getenv('MOBILE_LOGIN_TEST_TENANT');
if (!filter_var($base, FILTER_VALIDATE_URL) || $slug === '') {
    throw new RuntimeException('Set MOBILE_LOGIN_TEST_BASE and MOBILE_LOGIN_TEST_TENANT.');
}
function mobileHttpAssert(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException($label);
    echo $label . " PASS\n";
}
function mobileHttpRequest(CurlHandle $client, string $url, ?array $post = null): array {
    curl_setopt($client, CURLOPT_URL, $url);
    curl_setopt($client, CURLOPT_POST, $post !== null);
    if ($post !== null) curl_setopt($client, CURLOPT_POSTFIELDS, http_build_query($post));
    else curl_setopt($client, CURLOPT_HTTPGET, true);
    $raw = curl_exec($client);
    if ($raw === false) throw new RuntimeException('HTTP request failed: ' . curl_error($client));
    $headerSize = (int) curl_getinfo($client, CURLINFO_HEADER_SIZE);
    return [(int) curl_getinfo($client, CURLINFO_RESPONSE_CODE), substr($raw, 0, $headerSize), substr($raw, $headerSize)];
}

$pdo = (new MiniErp\Infrastructure\ControlPlaneConnectionFactory(__DIR__ . '/../config.php'))->create();
$repository = new MiniErp\Repositories\PlatformAdminRepository($pdo);
$email = 'mobile-http-' . bin2hex(random_bytes(8)) . '@example.test';
$password = bin2hex(random_bytes(24));
$id = $repository->create('MOBILE HTTP TEST_ONLY', $email, password_hash($password, PASSWORD_DEFAULT), 'GLOBAL_TECH', 'TEST_ONLY');
$agents = [
    'iPhone' => 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Version/17.0 Mobile/15E148 Safari/604.1',
    'Android' => 'Mozilla/5.0 (Linux; Android 14) AppleWebKit/537.36 Chrome/128.0.0.0 Mobile Safari/537.36',
];
try {
    foreach ($agents as $device => $agent) {
        $client = curl_init();
        curl_setopt_array($client, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_COOKIEFILE => '', CURLOPT_TIMEOUT => 25, CURLOPT_USERAGENT => $agent]);
        try {
            $url = $base . '/login.php?empresa=' . rawurlencode($slug);
            [$status, , $body] = mobileHttpRequest($client, $url);
            mobileHttpAssert($status === 200 && (bool) preg_match('/<form id="loginForm".*?<\/form>/s', $body, $form), $device . 'LoginForm');
            mobileHttpAssert((bool) preg_match('/name="csrf_token" value="([^"]+)"/', $form[0], $token), $device . 'CsrfToken');
            [$status, $headers, $body] = mobileHttpRequest($client, $url, ['action' => 'tenant_login',
                'csrf_token' => $token[1], 'email' => $email, 'senha' => $password]);
            if ($status !== 302 && preg_match('/<div class="login-error"[^>]*>(.*?)<\/div>/s', $body, $error)) {
                echo $device . ' response: ' . strip_tags($error[1]) . "\n";
            }
            mobileHttpAssert($status === 302 && stripos($headers, 'location: /?page=dashboard') !== false, $device . 'AuthenticatedRedirect status=' . $status);
            [$status, , $body] = mobileHttpRequest($client, $base . '/?page=dashboard');
            mobileHttpAssert($status === 200 && str_contains($body, 'Dashboard analítico'), $device . 'SessionRestoredAtDashboard');
            mobileHttpRequest($client, $base . '/erp/logout.php');
        } finally {
            curl_close($client);
        }
    }
} finally {
    $pdo->prepare('DELETE FROM platform_admin_audit_log WHERE admin_id=?')->execute([$id]);
    $pdo->prepare('DELETE FROM platform_admin_users WHERE id=?')->execute([$id]);
}
