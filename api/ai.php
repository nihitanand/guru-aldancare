<?php
header("Access-Control-Allow-Origin: *");
header("Access-Control-Allow-Methods: POST, OPTIONS");
header("Access-Control-Allow-Headers: Content-Type");
header("Content-Type: application/json");

if ($_SERVER["REQUEST_METHOD"] === "OPTIONS") {
    http_response_code(200);
    exit();
}

$body = file_get_contents("php://input");
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(["error" => "Invalid JSON"]);
    exit();
}

$api_key = "sk-ant-api03-L_i9ZwoonrXCAT8r_MMucGnCVThAIxSEmMRPsQK3V-EZbyFLeeVZRaBuxdUvlm3Z-7jCRrxd9Z7bnVmldZ0BuA-" . "TQXzpAAA";

$ch = curl_init("https://api.anthropic.com/v1/messages");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    "x-api-key: " . $api_key,
    "anthropic-version: 2023-06-01",
    "content-type: application/json"
]);
curl_setopt($ch, CURLOPT_TIMEOUT, 60);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

http_response_code($http_code);
echo $response;
