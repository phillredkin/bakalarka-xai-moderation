<?php

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '') {
    header('Content-Type: text/html, charset=utf-8');
    readfile(__DIR__ . 'ui.html');
    exit;
}

if ($path === '/health') {
    echo json_encode(["status" => "ok", "service" => "gateway"]);
    exit;
}

if ($path !== '/api/v1/text/analyze' || $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(404);
    echo json_encode(["error" => "Not Found"]);
    exit;
}

$body = file_get_contents('php://input');
if (!$body) {
    http_response_code(400);
    echo json_encode(["error" => "Empty body"]);
    exit;
}

$redis = new Redis();
$redis->connect(
    getenv('REDIS_HOST') ?: 'redis',
    (int)(getenv('REDIS_PORT') ?: 6379)
);

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rateKey = 'rate:' . $clientIp;
$limit = (int)(getenv('RATE_LIMIT_PER_MIN') ?: 60);

$requests = $redis->incr($rateKey);
if ($requests === 1) {
    $redis->expire($rateKey, 60);
}

if ($requests > $limit) {
    http_response_code(429);
    echo json_encode(["error" => "Rate limit exceeded"]);
    exit;
}

$cacheKey = 'cache:text:' . sha1($body);
$ttl = (int)(getenv('REDIS_TTL') ?: 300);

if ($redis->exists($cacheKey)) {
    echo $redis->get($cacheKey);
    exit;
}

$url = getenv('TEXT_SERVICE_URL') ?: 'http://text-service:8000/analyze';

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_TIMEOUT => 10,
]);

$response = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($response === false || $code !== 200) {
    http_response_code(502);
    echo json_encode(["error" => "text-service unavailable"]);
    exit;
}

$redis->setex($cacheKey, $ttl, $response);

echo $response;
exit;
