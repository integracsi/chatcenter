<?php
require __DIR__ . "/functions.php";
date_default_timezone_set(cfg()["timezone"]);

// ---------- 1) Verificación (GET) ----------
if ($_SERVER["REQUEST_METHOD"] === "GET") {
  $mode = $_GET["hub_mode"] ?? $_GET["hub.mode"] ?? "";
  $token = $_GET["hub_verify_token"] ?? $_GET["hub.verify_token"] ?? "";
  $challenge = $_GET["hub_challenge"] ?? $_GET["hub.challenge"] ?? "";

  if ($mode === "subscribe" && $token === cfg()["verify_token"]) {
    header("Content-Type: text/plain; charset=utf-8");
    echo $challenge;
    exit;
  }
  http_response_code(403);
  echo "Forbidden";
  exit;
}

// ---------- 2) Recepción (POST) ----------
$raw = file_get_contents("php://input");
if (!$raw) json_out(400, ["ok" => false, "error" => "empty body"]);

log_line("IN: " . $raw);
$payload = json_decode($raw, true);
if (!is_array($payload)) json_out(400, ["ok" => false, "error" => "invalid json"]);

ensure_schema();

// Extraer mensajes
$messages = $payload["entry"][0]["changes"][0]["value"]["messages"] ?? [];
if (!$messages) json_out(200, ["ok" => true, "info" => "no messages"]);

foreach ($messages as $m) {
  $from = $m["from"] ?? "";
  $msgId = $m["id"] ?? "";
  $type = $m["type"] ?? "";

  if (!$from || !$msgId) continue;

  $text = "";
  if ($type === "text") {
    $text = $m["text"]["body"] ?? "";
  } else {
    // Por ahora solo informamos
    $text = "[tipo: {$type}]";
  }

  // Guardar mensaje (anti-duplicado por UNIQUE wa_msg_id)
  try {
    $pdo = db();
    $stmt = $pdo->prepare("INSERT IGNORE INTO wa_messages(wa_msg_id, wa_from, msg_type, body) VALUES(?,?,?,?)");
    $stmt->execute([$msgId, $from, $type, $text]);
  } catch (Throwable $e) {
    log_line("DB ERROR (messages): " . $e->getMessage());
  }

  $u = get_user($from);
  $state = $u["state"] ?? "MENU";
  $t = normalize($text);

  // Comandos globales
  if ($t === "menu" || $t === "inicio" || $t === "0") {
    set_state($from, "MENU");
    $state = "MENU";
  }

  // --------- Lógica por estados ---------
  if ($state === "MENU") {
    wa_send_text($from,
      "👨‍💻 *INFORMÁTICA Y SEGURIDAD*\n" .
      "Responde con un número:\n" .
      "1) Soporte técnico (PC/Redes)\n" .
      "2) Ciberseguridad (hackeo, cuentas, phishing)\n" .
      "3) CCTV / Alarmas / Control de acceso\n" .
      "4) Cotización / Paquetes\n" .
      "5) Hablar con un asesor\n\n" .
      "Escribe *menu* en cualquier momento."
    );
    set_state($from, "AWAIT_OPTION");
    continue;
  }

  if ($state === "AWAIT_OPTION") {
    if ($t === "1") {
      open_ticket($from, "SOPORTE");
      set_state($from, "AWAIT_DETAILS");
      wa_send_text($from,
        "🛠️ *Soporte técnico*\n" .
        "Describe el problema (equipo, falla, si es red/WiFi, desde cuándo)."
      );
      continue;
    }
    if ($t === "2") {
      open_ticket($from, "CIBERSEGURIDAD");
      set_state($from, "AWAIT_DETAILS");
      wa_send_text($from,
        "🛡️ *Ciberseguridad*\n" .
        "¿Qué pasó? (cuenta comprometida, phishing, robo de WhatsApp, etc.).\n" .
        "Incluye: plataforma y hora aproximada."
      );
      continue;
    }
    if ($t === "3") {
      open_ticket($from, "CCTV_ALARMAS");
      set_state($from, "AWAIT_DETAILS");
      wa_send_text($from,
        "📷 *CCTV/Alarmas/Acceso*\n" .
        "¿Qué necesitas? (instalación, mantenimiento, número de cámaras, ubicación)."
      );
      continue;
    }
    if ($t === "4") {
      set_state($from, "MENU");
      wa_send_text($from,
        "💳 *Cotizaciones / Paquetes*\n" .
        "Dime qué te interesa:\n" .
        "- Soporte (domicilio/remoto)\n" .
        "- Redes (cableado/WiFi)\n" .
        "- Seguridad (auditoría, hardening, respaldo)\n" .
        "- CCTV\n\n" .
        "Escribe *menu* para volver."
      );
      continue;
    }
    if ($t === "5") {
      set_state($from, "MENU");
      wa_send_text($from,
        "👤 Para hablar con un asesor, por favor envía:\n" .
        "1) Nombre\n2) Colonia/Ciudad\n3) Mejor horario\n\n" .
        "En breve te contactan."
      );
      continue;
    }

    wa_send_text($from, "Opción no válida. Responde 1-5, o escribe *menu*.");
    continue;
  }

  if ($state === "AWAIT_DETAILS") {
    $ticketId = add_ticket_details($from, $text);

    set_state($from, "MENU");

    $folio = $ticketId ? ("Folio: #" . $ticketId) : "Folio: (no disponible)";
    wa_send_text($from,
      "✅ Listo, registré tu solicitud.\n{$folio}\n" .
      "En breve te respondemos.\n\n" .
      "Escribe *menu* para ver opciones."
    );
    continue;
  }

  // Fallback
  set_state($from, "MENU");
  wa_send_text($from, "Escribe *menu* para iniciar.");
}

json_out(200, ["ok" => true]);
