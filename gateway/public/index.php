<?php

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: https://clarimod.vercel.app');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

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


if ($path === '/api/v1/image/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: 'redis',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );

    $ttl = (int)(getenv('REDIS_TTL') ?: 300);

    $imageServiceUrl = getenv('IMAGE_SERVICE_URL') ?: 'http://image-service:8000/analyze';

    $fileHash = null;

    $ch = curl_init($imageServiceUrl);

    if (!empty($_FILES['file'])) {

        $fileTmp = $_FILES['file']['tmp_name'];
        $fileName = $_FILES['file']['name'];

        $fileHash = sha1_file($fileTmp);
        $cacheKey = 'cache:image:' . $fileHash;

        if ($redis->exists($cacheKey)) {
            echo $redis->get($cacheKey);
            exit;
        }

        $cfile = new CURLFile($fileTmp, mime_content_type($fileTmp), $fileName);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => $cfile],
            CURLOPT_TIMEOUT => 60
        ]);
    }

    else {

        $rawBody = file_get_contents('php://input');
        if (!$rawBody) {
            http_response_code(400);
            echo json_encode(["error" => "Empty body"]);
            exit;
        }

        $fileHash = sha1($rawBody);
        $cacheKey = 'cache:image:' . $fileHash;

        if ($redis->exists($cacheKey)) {
            echo $redis->get($cacheKey);
            exit;
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $rawBody,
            CURLOPT_TIMEOUT => 60
        ]);
    }

    $imageResponse = curl_exec($ch);
    $imageCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($imageResponse === false || $imageCode !== 200) {
        http_response_code(502);
        echo json_encode(["error" => "image-service unavailable"]);
        exit;
    }

    $imageData = json_decode($imageResponse, true);
    $extractedText = trim($imageData['text'] ?? '');

    $sightResponse = null;

    $sightUser = getenv('SIGHTENGINE_API_USER');
    $sightSecret = getenv('SIGHTENGINE_API_KEY');

    if (!empty($_FILES['file'])) {

        $sightParams = [
            'media' => new CURLFile($fileTmp),
            'models' => 'weapon,offensive-2.0,text-content,gore-2.0,violence,self-harm,recreational_drug,medical',
            'api_user' => $sightUser,
            'api_secret' => $sightSecret
        ];

        $chSight = curl_init('https://api.sightengine.com/1.0/check.json');
        curl_setopt($chSight, CURLOPT_POST, true);
        curl_setopt($chSight, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($chSight, CURLOPT_POSTFIELDS, $sightParams);

    } else {

        $body = json_decode($rawBody, true);
        $imageUrl = $body['url'] ?? null;

        $params = [
            'url' => $imageUrl,
            'models' => 'weapon,offensive-2.0,text-content,gore-2.0,violence,self-harm',
            'api_user' => $sightUser,
            'api_secret' => $sightSecret
        ];

        $chSight = curl_init('https://api.sightengine.com/1.0/check.json?' . http_build_query($params));
        curl_setopt($chSight, CURLOPT_RETURNTRANSFER, true);
    }

    $sightRaw = curl_exec($chSight);
    curl_close($chSight);

    $sightResponse = json_decode($sightRaw, true);

    $textModeration = null;

    if ($extractedText !== '') {

        $textUrl = getenv('TEXT_SERVICE_URL') ?: 'http://text-service:8000/analyze';
        $payload = json_encode(["text" => $extractedText], JSON_UNESCAPED_UNICODE);

        $ch = curl_init($textUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_TIMEOUT => 30
        ]);

        $textResponse = curl_exec($ch);
        curl_close($ch);

        $textModeration = json_decode($textResponse, true);
    }

    $finalData = json_encode([
        "status" => "ok",
        "ocr" => [
            "status" => "ok",
            "text" => $extractedText !== ''
                ? $extractedText
                : "Text was not found on this image"
        ],
        "moderation" => $textModeration,
        "sightengine" => $sightResponse
    ]);

    $redis->setex($cacheKey, $ttl, $finalData);

    echo $finalData;
    exit;
}

if ($path === '/api/v1/video/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!isset($_FILES['file'])) {
        http_response_code(400);
        echo json_encode(["error" => "No video uploaded"]);
        exit;
    }

    $uploadError = $_FILES['file']['error'] ?? UPLOAD_ERR_NO_FILE;

    if ($uploadError !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            "error" => "Video upload failed",
            "upload_error_code" => $uploadError
        ]);
        exit;
    }

    $fileTmp = $_FILES['file']['tmp_name'] ?? '';
    $fileName = $_FILES['file']['name'] ?? 'video.mp4';

    if ($fileTmp === '' || !file_exists($fileTmp)) {
        http_response_code(400);
        echo json_encode([
            "error" => "Uploaded temp file is missing"
        ]);
        exit;
    }

    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: 'redis',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );

    $ttl = (int)(getenv('REDIS_TTL_VIDEO') ?: 3600);

    $videoUrl = getenv('VIDEO_SERVICE_URL') ?: 'http://video-service:8000/analyze';
    $mimeType = mime_content_type($fileTmp) ?: 'application/octet-stream';
    $fileHash = sha1_file($fileTmp);
    $cacheKey = 'cache:video:' . $fileHash;

    if ($redis->exists($cacheKey)) {
        echo $redis->get($cacheKey);
        exit;
    }

    $ch = curl_init($videoUrl);
    $cfile = new CURLFile($fileTmp, $mimeType, $fileName);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['file' => $cfile],
        CURLOPT_TIMEOUT => 300
    ]);

    $videoResponse = curl_exec($ch);
    $videoCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($videoResponse === false || $videoCode !== 200) {
        http_response_code(502);
        echo json_encode([
            "error" => "video-service unavailable",
            "details" => $curlError ?: $videoResponse
        ]);
        exit;
    }

    $redis->setex($cacheKey, $ttl, $videoResponse);

    echo $videoResponse;
    exit;
}

if ($path === '/api/v1/video/status' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    $mediaId = $_GET['id'] ?? '';

    if ($mediaId === '') {
        http_response_code(400);
        echo json_encode(["error" => "Missing media id"]);
        exit;
    }

    $sightUser = getenv('SIGHTENGINE_API_USER');
    $sightSecret = getenv('SIGHTENGINE_API_KEY');

    if (!$sightUser || !$sightSecret) {
        http_response_code(500);
        echo json_encode(["error" => "Sightengine credentials are missing"]);
        exit;
    }

    $redis = new Redis();
    $redis->connect(
        getenv('REDIS_HOST') ?: 'redis',
        (int)(getenv('REDIS_PORT') ?: 6379)
    );

    $ttlPending = (int)(getenv('REDIS_TTL_VIDEO_STATUS_PENDING') ?: 10);
    $ttlFinal = (int)(getenv('REDIS_TTL_VIDEO_STATUS_FINAL') ?: 3600);

    $cacheKey = 'cache:video:status:' . $mediaId;

    if ($redis->exists($cacheKey)) {
        echo $redis->get($cacheKey);
        exit;
    }

    $params = [
        'id' => $mediaId,
        'api_user' => $sightUser,
        'api_secret' => $sightSecret
    ];

    $url = 'https://api.sightengine.com/1.0/video/byid.json?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 60
    ]);

    $response = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $code !== 200) {
        http_response_code(502);
        echo json_encode([
            "error" => "sightengine status unavailable",
            "details" => $curlError ?: $response
        ]);
        exit;
    }

    $decoded = json_decode($response, true);
    $jobStatus = $decoded['output']['data']['status'] ?? '';

    if (in_array($jobStatus, ['finished', 'failure', 'stopped'], true)) {
        $redis->setex($cacheKey, $ttlFinal, $response);
    } else {
        $redis->setex($cacheKey, $ttlPending, $response);
    }

    echo $response;
    exit;
}

http_response_code(404);
echo json_encode(["error" => "Not Found"]);
exit;
