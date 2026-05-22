<?php
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(200); exit; }

$body = file_get_contents('php://input');
$ch = curl_init('https://api.anthropic.com/v1/messages');
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST => true,
  CURLOPT_POSTFIELDS => $body,
  CURLOPT_HTTPHEADER => [
    'Content-Type: application/json',
    'x-api-key: ' . implode('', ['sk-ant-api03-_L3MIIDsat-mFvpANdXTxE6N8QALgpw3KDEeIQMa4tS_cHt50hNZzyKb','cofIud4T23AQo2l7GOBvPe7Ra2DB1g-j2veigAA']),
    'anthropic-version: 2023-06-01',
  ],
]);
$response = curl_exec($ch);
$httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);
http_response_code($httpcode);
echo $response;
