<?php

function cfg(): array {
  static $cfg = null;
  if ($cfg === null) $cfg = require __DIR__ . "/config.php";
  return $cfg;
}

function json_out(int $code, array $data): void {
  http_response_code($code);
  header("Content-Type: application/json; charset=utf-8");
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function log_line(string $msg): void {
  $dir = __DIR__ . "/logs";
  if (!is_dir($dir)) @mkdir($dir, 0755, true);
  $file = $dir . "/wa-" . date("Y-m-d") . ".log";
  file_put_contents($file, "[" . date("H:i:s") . "] " . $msg . PHP_EOL, FILE_APPEND);
}

function db(): PDO {
  static $pdo = null;
  if ($pdo) return $pdo;

  $c = cfg()["db"];
  $dsn = "mysql:host={$c['host']};dbname={$c['name']};charset={$c['charset']}";
  $pdo = new PDO($dsn, $c["user"], $c["pass"], [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
  return $pdo;
}

function normalize(string $t): string {
  $t = trim(mb_strtolower($t));
  $t = preg_replace('/\s+/', ' ', $t);
  return $t ?? "";
}

function wa_send_text(string $to, string $text): array {
  $c = cfg();
  $url = "https://graph.facebook.com/{$c['graph_version']}/{$c['phone_number_id']}/messages";

  $payload = [
    "messaging_product" => "whatsapp",
    "to" => $to,
    "type" => "text",
    "text" => ["body" => $text]
  ];

  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      "Authorization: Bearer {$c['access_token']}",
      "Content-Type: application/json"
    ],
    CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_TIMEOUT => 20
  ]);

  $resp = curl_exec($ch);
  $err  = curl_error($ch);
  $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if ($resp === false) {
    log_line("cURL ERROR: " . $err);
    return ["ok" => false, "code" => 0, "error" => $err];
  }

  if ($code < 200 || $code >= 300) {
    log_line("WA API ERROR HTTP $code: $resp");
    return ["ok" => false, "code" => $code, "raw" => $resp];
  }

  return ["ok" => true, "code" => $code, "raw" => $resp];
}

/** Crea tablas si no existen */
function ensure_schema(): void {
  $pdo = db();

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wa_users (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      wa_id VARCHAR(30) NOT NULL UNIQUE,
      state VARCHAR(50) NOT NULL DEFAULT 'MENU',
      last_seen TIMESTAMP NULL DEFAULT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wa_messages (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      wa_msg_id VARCHAR(120) NOT NULL UNIQUE,
      wa_from VARCHAR(30) NOT NULL,
      msg_type VARCHAR(30) NOT NULL,
      body TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS wa_tickets (
      id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      wa_id VARCHAR(30) NOT NULL,
      category VARCHAR(50) NOT NULL,
      details TEXT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'OPEN',
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
  ");
}

function get_user(string $wa_id): array {
  $pdo = db();
  $stmt = $pdo->prepare("SELECT * FROM wa_users WHERE wa_id=? LIMIT 1");
  $stmt->execute([$wa_id]);
  $u = $stmt->fetch();
  if ($u) return $u;

  $stmt = $pdo->prepare("INSERT INTO wa_users(wa_id,state,last_seen) VALUES(?,?,NOW())");
  $stmt->execute([$wa_id, "MENU"]);
  return [
    "wa_id" => $wa_id,
    "state" => "MENU"
  ];
}

function set_state(string $wa_id, string $state): void {
  $pdo = db();
  $stmt = $pdo->prepare("UPDATE wa_users SET state=?, last_seen=NOW() WHERE wa_id=?");
  $stmt->execute([$state, $wa_id]);
}

function open_ticket(string $wa_id, string $category): int {
  $pdo = db();
  $stmt = $pdo->prepare("INSERT INTO wa_tickets(wa_id,category,status) VALUES(?,?, 'OPEN')");
  $stmt->execute([$wa_id, $category]);
  return (int)$pdo->lastInsertId();
}

function add_ticket_details(string $wa_id, string $details): ?int {
  $pdo = db();
  // último ticket abierto
  $stmt = $pdo->prepare("SELECT id FROM wa_tickets WHERE wa_id=? AND status='OPEN' ORDER BY id DESC LIMIT 1");
  $stmt->execute([$wa_id]);
  $t = $stmt->fetch();
  if (!$t) return null;

  $stmt = $pdo->prepare("UPDATE wa_tickets SET details=?, updated_at=NOW() WHERE id=?");
  $stmt->execute([$details, $t["id"]]);
  return (int)$t["id"];
}
