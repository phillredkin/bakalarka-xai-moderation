<?php

header('Content-Type: application/json');

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/' || $path === '') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/ui.html');
    exit;
}

if ($path === '/health') {
    echo json_encode([
        "status" => "ok",
        "service" => "gateway"
    ]);
    exit;
}

if ($path === '/api/v1/audio/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: 'redis',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );

    $ttl = (int)(getenv('REDIS_TTL') ?: 300);

    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(["error" => "No file uploaded"]);
        exit;
    }

    $fileTmp = $_FILES['file']['tmp_name'];
    $fileName = $_FILES['file']['name'];

    $fileHash = sha1_file($fileTmp);
    $cacheKey = 'cache:audio:' . $fileHash;

    if ($redis->exists($cacheKey)) {
        echo $redis->get($cacheKey);
        exit;
    }

    $audioUrl = getenv('AUDIO_SERVICE_URL') ?: 'http://audio-service:8000/transcribe';

    $ch = curl_init($audioUrl);

    $cfile = new CURLFile($fileTmp, mime_content_type($fileTmp), $fileName);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_TIMEOUT => 120
    ]);

    $audioResponse = curl_exec($ch);
    $audioCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($audioResponse === false || $audioCode !== 200) {
        http_response_code(502);
        echo json_encode(["error" => "audio-service unavailable"]);
        exit;
    }

    $audioData = json_decode($audioResponse, true);
    $transcribedText = $audioData['text'] ?? '';

    if (!$transcribedText) {
        http_response_code(500);
        echo json_encode(["error" => "No text returned from audio-service"]);
        exit;
    }

    $textUrl = getenv('TEXT_SERVICE_URL') ?: 'http://text-service:8000/analyze';

    $payload = json_encode(["text" => $transcribedText], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($textUrl);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_TIMEOUT => 30
    ]);

    $textResponse = curl_exec($ch);
    $textCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($textResponse === false || $textCode !== 200) {
        http_response_code(502);
        echo json_encode(["error" => "text-service unavailable"]);
        exit;
    }

    $finalData = [
        "status" => "ok",
        "service" => "gateway",
        "audio" => $audioData,
        "moderation" => json_decode($textResponse, true)
    ];

    $redis->setex($cacheKey, $ttl, json_encode($finalData));

    echo json_encode($finalData);

    exit;
}


if ($path === '/api/v1/text/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {

    $rawBody = file_get_contents('php://input');
    if (!$rawBody) {
        http_response_code(400);
        echo json_encode(["error" => "Empty body"]);
        exit;
    }

    $data = json_decode($rawBody, true);
    if (!is_array($data)) {
        http_response_code(400);
        echo json_encode(["error" => "Invalid JSON"]);
        exit;
    }

    if (isset($data['text'])) {
        $payload = ['text' => $data['text']];
    } elseif (isset($data['input'])) {
        $payload = ['text' => $data['input']];
    } elseif (isset($data['message'])) {
        $payload = ['text' => $data['message']];
    } else {
        http_response_code(400);
        echo json_encode(["error" => "Missing text field"]);
        exit;
    }

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);

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
        CURLOPT_TIMEOUT => 15,
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
}


http_response_code(404);
echo json_encode(["error" => "Not Found"]);
exit;
