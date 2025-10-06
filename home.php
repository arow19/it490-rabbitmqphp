<?php
require_once __DIR__ . '/vendor/autoload.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $session = $payload['session'] ?? '';

    if (!$session) {
        http_response_code(400);
        echo json_encode(['status' => 'error', 'message' => 'No session provided.']);
        exit;
    }

    try {
        $connection = new \PhpAmqpLib\Connection\AMQPStreamConnection('10.147.17.197', 5672, 'rey', 'rey', 'projectVhost');
        $channel = $connection->channel();

        $channel->queue_declare('auth_request', false, true, false, false);
        $channel->queue_declare('auth_response', false, true, false, false);

        $data = ["action" => "validate", "session" => $session];
        $corrId = bin2hex(random_bytes(12));

        $msg = new \PhpAmqpLib\Message\AMQPMessage(json_encode($data), ['correlation_id' => $corrId, 'reply_to' => 'auth_response', 'content_type' => 'application/json']);
        $channel->basic_publish($msg, '', 'auth_request');

        $response = null;
        $callback = function ($msg) use (&$response, $corrId) {
            if ($msg->get('correlation_id') == $corrId) {
                $response = $msg->body;
            }
        };

        $channel->basic_consume('auth_response', '', false, true, false, false, $callback);

        $start = time();
        while ($response === null && (time() - $start) < 5) {
            $channel->wait(null, false, 1);
        }

        $channel->close();
        $connection->close();

        if ($response === null) {
            http_response_code(504);
            echo json_encode(['status' => 'error', 'message' => 'No response from authentication server.']);
            exit;
        }

        $result = json_decode($response, true);
        if (!$result || ($result['status'] ?? '') !== 'success') {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Invalid or expired session.']);
            exit;
        }

        $user = $result['user'] ?? null;
        echo json_encode(['status' => 'success', 'user' => $user]);
        exit;

    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['status' => 'error', 'message' => 'Server error', 'detail' => $e->getMessage()]);
        exit;
    }
}
?>
<html>
<head>
  <title>Home</title>
  <style>
    body {
      font-family: Arial, sans-serif;
      background: #eef2f7;
      display: flex;
      justify-content: center;
      align-items: center;
      height: 100vh;
    }

    .home-container {
      background: #fff;
      padding: 2rem;
      border-radius: 10px;
      box-shadow: 0 4px 10px rgba(0,0,0,0.1);
      text-align: center;
    }

    .hidden {
      display: none;
    }

    button {
      padding: 10px 16px;
      border: none;
      border-radius: 6px;
      background: #007BFF;
      color: #fff;
      cursor: pointer;
      font-size: 14px;
    }

    button:hover {
      background: #0056b3;
    }
  </style>
</head>
<body>
  <div class="home-container">
    <h1>Welcome To Pivot Point!</h1>
    <p id="statusText">Validating your session…</p>

    <div id="authedArea" class="hidden">
      <p>You are successfully logged in.</p>
      <button id="logoutBtn">Logout</button>
    </div>
  </div>

  <script>
    (function scrubQueryOnce() {
      const params = new URLSearchParams(window.location.search);
      const urlSession = params.get('session');
      if (urlSession) {
        try {
          sessionStorage.setItem('sessionKey', urlSession);
        } catch (e) {}
        const clean = window.location.pathname + window.location.hash;
        history.replaceState({}, document.title, clean);
      }
    })();

    async function validate() {
      const key = sessionStorage.getItem('sessionKey');
      if (!key) {
        document.getElementById('statusText').textContent = 'No session found. Please login again.';
        setTimeout(() => location.href = 'index.html', 700);
        return;
      }

      try {
        const res = await fetch(window.location.pathname, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ session: key })
        });

        const data = await res.json().catch(() => ({}));
        if (!res.ok || data.status !== 'success') throw new Error(data.message || 'Invalid session');

        document.getElementById('statusText').classList.add('hidden');
        document.getElementById('authedArea').classList.remove('hidden');

      } catch (e) {
        sessionStorage.removeItem('sessionKey');
        document.getElementById('statusText').textContent = 'Session expired. Please login again.';
        setTimeout(() => location.href = 'index.html', 900);
      }
    }

    document.getElementById('logoutBtn').addEventListener('click', () => {
      sessionStorage.removeItem('sessionKey');
      location.href = 'index.html';
    });

    validate();
  </script>
</body>
</html>

