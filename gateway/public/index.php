<?php

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/health') {
    header('Content-Type: application/json');
    echo json_encode(["status" => "ok", "service" => "gateway"]);
    exit;
}

if ($path === '/api/v1/text/analyze' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');

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
    $error = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    header('Content-Type: application/json');

    if ($response === false) {
        http_response_code(502);
        echo json_encode(["status" => "error", "error" => $error]);
        exit;
    }

    http_response_code($code ?: 200);
    echo $response;
    exit;
}

http_response_code(404);
echo "Not Found";
