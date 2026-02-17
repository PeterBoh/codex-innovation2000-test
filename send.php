<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

// ====== SMTP-Konfiguration laden (eine Ebene über /innovation2000/) ======
require __DIR__ . '/../config/mail_config.php';

// =================== KONFIG ===================
$SITE_NAME      = 'Innovation 2000';
$SUBJECT_PREFIX = '[Innovation 2000 Kontakt]';

// Empfänger (wo die Anfragen ankommen sollen)
$TO_EMAIL = 'peterboh67@gmail.com';

// Von-Adresse (muss zu Strato-Mailbox passen)
$FROM_EMAIL = 'kontakt@innovation2000.de';

// Rate limit
$RATE_WINDOW_SECONDS = 60;
$RATE_MAX_REQUESTS   = 3;

// =================== PHPMailer laden ===================
require __DIR__ . '/phpmailer/src/Exception.php';
require __DIR__ . '/phpmailer/src/PHPMailer.php';
require __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// =================== Helper ===================
function respond(int $code, array $payload): void {
  http_response_code($code);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE);
  exit;
}

function clean_text(string $s, int $maxLen = 2000): string {
  $s = trim($s);
  $s = str_replace(["\r", "\n"], ' ', $s); // Header Injection verhindern
  $s = preg_replace('/\s+/', ' ', $s) ?? $s;
  if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
  return $s;
}

function clean_message(string $s, int $maxLen = 6000): string {
  $s = trim($s);
  $s = preg_replace("/\r\n|\r/", "\n", $s) ?? $s;
  $s = preg_replace("/^(to:|from:|cc:|bcc:|content-type:).*$/im", "", $s) ?? $s;
  if (mb_strlen($s) > $maxLen) $s = mb_substr($s, 0, $maxLen);
  return $s;
}

// =================== Nur POST ===================
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  respond(405, ['ok' => false, 'message' => 'Method not allowed']);
}

// =================== Honeypot ===================
$hp = (string)($_POST['company'] ?? '');
if ($hp !== '') {
  respond(200, ['ok' => true, 'message' => 'Danke!']);
}

// =================== Rate limit (pro IP) ===================
$ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$tmpDir = sys_get_temp_dir();
$rateFile = $tmpDir . '/i2000_rate_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $ip);

$now = time();
$history = [];
if (is_file($rateFile)) {
  $raw = @file_get_contents($rateFile);
  if ($raw !== false) $history = json_decode($raw, true) ?: [];
}
$history = array_values(array_filter($history, fn($t) => is_int($t) && ($now - $t) < $RATE_WINDOW_SECONDS));

if (count($history) >= $RATE_MAX_REQUESTS) {
  respond(429, ['ok' => false, 'message' => 'Bitte kurz warten und dann erneut senden.']);
}
$history[] = $now;
@file_put_contents($rateFile, json_encode($history));

// =================== Felder ===================
$name  = clean_text((string)($_POST['name'] ?? ''), 120);
$email = trim((string)($_POST['email'] ?? ''));
$phone = clean_text((string)($_POST['phone'] ?? ''), 60);
$city  = clean_text((string)($_POST['city'] ?? ''), 120);
$topic = clean_text((string)($_POST['topic'] ?? ''), 160);
$msg   = clean_message((string)($_POST['message'] ?? ''));

if ($name === '' || $email === '' || $phone === '' || $msg === '') {
  respond(400, ['ok' => false, 'message' => 'Bitte Name, E-Mail, Telefonnummer und Nachricht ausfüllen.']);
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
  respond(400, ['ok' => false, 'message' => 'Bitte eine gültige E-Mail-Adresse eingeben.']);
}

// =================== Mail-Inhalt ===================
$subjectPlain = $SUBJECT_PREFIX . ' ' . ($topic !== '' ? $topic : 'Anfrage');

$bodyLines = [
  "Neue Anfrage über das Kontaktformular ($SITE_NAME)",
  "----------------------------------------------",
  "Name:    $name",
  "E-Mail:  $email",
  "Telefon: $phone",
  "Wohnort: " . ($city !== '' ? $city : '-'),
  "Thema:   " . ($topic !== '' ? $topic : '-'),
  "IP:      $ip",
  "Zeit:    " . date('Y-m-d H:i:s'),
  "",
  "Nachricht:",
  $msg,
  "",
];
$body = implode("\n", $bodyLines);

// =================== SMTP Versand ===================
$mailer = new PHPMailer(true);

try {
  $mailer->CharSet = 'UTF-8';
  $mailer->isSMTP();

  $mailer->Host       = $SMTP_HOST;
  $mailer->SMTPAuth   = true;
  $mailer->Username   = $SMTP_USER;
  $mailer->Password   = $SMTP_PASS;

  // TLS/SSL je nach config
  if (($SMTP_ENCRYPTION ?? 'tls') === 'ssl') {
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
  } else {
    $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
  }
  $mailer->Port = (int)($SMTP_PORT ?? 587);

  // From / To / Reply-To
  $mailer->setFrom($FROM_EMAIL, $SITE_NAME);
  $mailer->addAddress($TO_EMAIL);
  $mailer->addReplyTo($email, $name);

  $mailer->Subject = $subjectPlain;
  $mailer->Body    = $body;
  $mailer->AltBody = $body;

  $mailer->send();

  respond(200, ['ok' => true, 'message' => 'Danke! Deine Nachricht wurde gesendet. Wir melden uns zeitnah.']);

} catch (Exception $e) {
  respond(500, ['ok' => false, 'message' => 'SMTP-Fehler: ' . $mailer->ErrorInfo]);
}
