<?php
// Alkawave contact form -> Brevo transactional email relay.
// API key is loaded from brevo-config.php (gitignored) or the BREVO_API_KEY env var.
// Never hard-code the key in this file.

header('Content-Type: application/json');

// --- Method guard ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

// --- Config ---
$TO         = 'waveteam@alkawave.com';
$FROM_EMAIL = 'waveteam@alkawave.com';   // must be a verified sender (or auth domain) in Brevo
$FROM_NAME  = 'Alkawave Website';
$API_KEY    = getenv('BREVO_API_KEY') ?: '';
$cfg = __DIR__ . '/brevo-config.php';
if (!$API_KEY && is_file($cfg)) {
    $loaded = require $cfg;
    if (is_string($loaded)) $API_KEY = $loaded;
}

if (!$API_KEY) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'Email is not configured. Please email waveteam@alkawave.com.']);
    exit;
}

// --- Read input (JSON or form-encoded) ---
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) $data = $_POST;

$name    = trim($data['name']    ?? '');
$email   = trim($data['email']   ?? '');
$company = trim($data['company'] ?? '');
$phone   = trim($data['phone']   ?? '');
$message = trim($data['message'] ?? '');
$hp      = trim($data['website'] ?? ''); // honeypot

// --- Spam honeypot: silently accept, send nothing ---
if ($hp !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

// --- Validate ---
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'Please provide your name, a valid email, and a message.']);
    exit;
}

$esc = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
$rows = [
    'Name'    => $name,
    'Email'   => $email,
    'Company' => $company !== '' ? $company : '—',
    'Phone'   => $phone   !== '' ? $phone   : '—',
];
$html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;color:#111;line-height:1.55">'
      . '<h2 style="margin:0 0 16px">New project inquiry — alkawave.com</h2>';
foreach ($rows as $k => $v) {
    $html .= '<p style="margin:0 0 6px"><strong>' . $esc($k) . ':</strong> ' . $esc($v) . '</p>';
}
$html .= '<p style="margin:16px 0 6px"><strong>Project details:</strong></p>'
       . '<p style="margin:0;white-space:pre-wrap">' . $esc($message) . '</p></div>';

$text = "Name: $name\nEmail: $email\nCompany: " . ($company ?: '—')
      . "\nPhone: " . ($phone ?: '—') . "\n\nProject details:\n$message\n";

$payload = [
    'sender'      => ['name' => $FROM_NAME, 'email' => $FROM_EMAIL],
    'to'          => [['email' => $TO]],
    'replyTo'     => ['email' => $email, 'name' => $name],
    'subject'     => 'New inquiry from ' . $name . ($company ? " ($company)" : ''),
    'htmlContent' => $html,
    'textContent' => $text,
];

// --- Send via Brevo ---
$ch = curl_init('https://api.brevo.com/v3/smtp/email');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_HTTPHEADER     => [
        'api-key: ' . $API_KEY,
        'Content-Type: application/json',
        'accept: application/json',
    ],
    CURLOPT_POSTFIELDS     => json_encode($payload),
    CURLOPT_TIMEOUT        => 20,
]);
$response = curl_exec($ch);
$status   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr || $status < 200 || $status >= 300) {
    http_response_code(502);
    error_log('Brevo send failed: ' . $status . ' ' . $curlErr . ' ' . $response);
    echo json_encode(['ok' => false, 'error' => 'We could not send your message. Please email waveteam@alkawave.com.']);
    exit;
}

echo json_encode(['ok' => true]);
