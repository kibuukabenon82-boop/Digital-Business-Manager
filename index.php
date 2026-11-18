<?php
declare(strict_types=1);

const DATA_FILE_PREFIX = "<?php http_response_code(404); exit; ?>\n";
const MAX_REQUEST_BYTES = 5 * 1024 * 1024;

$requestHost = parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? ''), PHP_URL_HOST);
$requestHost = strtolower(trim((string)$requestHost, '[]'));
$isLocalHost = in_array($requestHost, ['localhost', '127.0.0.1', '::1'], true);
$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
if (!$isHttps && !$isLocalHost) {
    $hostHeader = (string)($_SERVER['HTTP_HOST'] ?? '');
    if (!preg_match('/^[a-z0-9.-]+(?::[0-9]+)?$/i', $hostHeader)) {
        http_response_code(400);
        exit('Invalid host.');
    }
    header('Location: https://' . $hostHeader . ($_SERVER['REQUEST_URI'] ?? '/'), true, 308);
    exit;
}

ini_set('session.use_strict_mode', '1');
session_set_cookie_params([
    'lifetime' => 0,
    'path' => '/',
    'secure' => $isHttps,
    'httponly' => true,
    'samesite' => 'Strict',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');

function respond(int $status, array $body): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function validRecords($records, array $requiredTextFields, array $requiredNumberFields = []): bool
{
    if (!is_array($records)) {
        return false;
    }

    foreach ($records as $record) {
        if (!is_array($record)) {
            return false;
        }
        foreach ($requiredTextFields as $field => $maxLength) {
            if (!isset($record[$field]) || !is_string($record[$field]) || strlen($record[$field]) > $maxLength) {
                return false;
            }
        }
        foreach ($requiredNumberFields as $field) {
            if (!isset($record[$field]) || !is_numeric($record[$field]) || !is_finite((float)$record[$field]) || (float)$record[$field] < 0) {
                return false;
            }
        }
        if (isset($record['saleType']) && !in_array($record['saleType'], ['Debit', 'Credit', 'Cash'], true)) {
            return false;
        }
    }
    return true;
}

function dataDirectory(): string
{
    return __DIR__ . DIRECTORY_SEPARATOR . 'data';
}

function sendStore(): void
{
    $path = dataDirectory() . DIRECTORY_SEPARATOR . 'store.php';
    if (!is_file($path)) {
        respond(200, ['exists' => false]);
    }

    $file = fopen($path, 'rb');
    if ($file === false) {
        respond(500, ['error' => 'Could not open the server data file.']);
    }
    if (!flock($file, LOCK_SH)) {
        fclose($file);
        respond(500, ['error' => 'Could not lock the server data file.']);
    }
    $contents = stream_get_contents($file);
    flock($file, LOCK_UN);
    fclose($file);

    if (!is_string($contents) || substr($contents, 0, strlen(DATA_FILE_PREFIX)) !== DATA_FILE_PREFIX) {
        respond(500, ['error' => 'The server data file is invalid.']);
    }
    try {
        $store = json_decode(substr($contents, strlen(DATA_FILE_PREFIX)), true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(500, ['error' => 'The server data file contains invalid JSON.']);
    }
    if (!is_array($store) || !isset($store['products'], $store['sales']) || !is_array($store['products']) || !is_array($store['sales'])) {
        respond(500, ['error' => 'The server data file has an invalid format.']);
    }

    respond(200, [
        'exists' => true,
        'products' => $store['products'],
        'sales' => $store['sales'],
        'businessName' => is_string($store['businessName'] ?? null) ? $store['businessName'] : 'Kyobe General Stores',
    ]);
}

function saveStore(): void
{
    $length = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($length > MAX_REQUEST_BYTES) {
        respond(413, ['error' => 'The submitted data is too large.']);
    }
    $raw = file_get_contents('php://input', false, null, 0, MAX_REQUEST_BYTES + 1);
    if (!is_string($raw) || strlen($raw) > MAX_REQUEST_BYTES) {
        respond(413, ['error' => 'The submitted data is too large.']);
    }
    try {
        $store = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        respond(400, ['error' => 'The submitted data is not valid JSON.']);
    }
    if (!is_array($store)
        || !validRecords($store['products'] ?? null, ['id' => 100, 'name' => 200, 'category' => 80, 'unit' => 40], ['stock', 'minimum', 'cost', 'price'])
        || !validRecords($store['sales'] ?? null, ['id' => 100, 'productId' => 100, 'productName' => 200, 'customerName' => 200, 'saleType' => 20, 'unit' => 40, 'date' => 50], ['quantity', 'stockQuantity', 'price', 'total', 'cashPaid'])
        || !is_string($store['businessName'] ?? null)
        || strlen($store['businessName']) > 100) {
        respond(400, ['error' => 'The submitted inventory or sales data has an invalid format.']);
    }

    $directory = dataDirectory();
    if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
        respond(500, ['error' => 'Could not create the server data directory.']);
    }

    $encoded = json_encode($store, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($encoded)) {
        respond(400, ['error' => 'The submitted data could not be encoded as JSON.']);
    }
    $path = $directory . DIRECTORY_SEPARATOR . 'store.php';
    $file = fopen($path, 'c+b');
    if ($file === false) {
        respond(500, ['error' => 'Could not open the server data file for writing.']);
    }
    if (!flock($file, LOCK_EX)) {
        fclose($file);
        respond(500, ['error' => 'Could not lock the server data file for writing.']);
    }
    $contents = DATA_FILE_PREFIX . $encoded;
    $written = ftruncate($file, 0) && rewind($file) && fwrite($file, $contents) === strlen($contents) && fflush($file);
    flock($file, LOCK_UN);
    fclose($file);
    if (!$written) {
        respond(500, ['error' => 'Could not save the server data file.']);
    }

    respond(200, ['success' => true]);
}

function loginPage(string $message, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    $token = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
    $safeMessage = $message === ''
        ? ''
        : '<p class="error" role="alert">' . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</p>';
    $serverPassword = getenv('DBM_MANAGER_PASSWORD');
    $setupMessage = !is_string($serverPassword) || strlen($serverPassword) < 12
        ? '<p class="notice">Set the <code>DBM_MANAGER_PASSWORD</code> environment variable to a password of at least 12 characters before signing in.</p>'
        : '';
    echo <<<HTML
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#f5f4ed">
  <title>Manager sign in · Digital Business Manager</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Fraunces:opsz,wght@9..144,500;9..144,600&display=swap" rel="stylesheet">
  <style>
    * { box-sizing: border-box; }
    body { display: grid; min-height: 100vh; place-items: center; margin: 0; padding: 20px; background: #f5f4ed; color: #222c24; font: 14px/1.5 "DM Sans", sans-serif; }
    main { width: min(100%, 400px); padding: 30px; border: 1px solid #e7e8df; border-radius: 9px; background: #fffefa; box-shadow: 0 14px 40px rgba(36,54,39,.07); }
    .mark { display: grid; width: 42px; height: 42px; place-items: center; margin-bottom: 20px; border-radius: 12px; background: #eaf0e8; font-size: 21px; }
    h1 { margin: 0 0 5px; font: 500 25px/1.2 Fraunces, Georgia, serif; }
    p { margin: 0 0 18px; color: #788078; font-size: 12px; }
    label { display: block; margin-bottom: 6px; color: #596259; font-size: 11px; font-weight: 600; }
    input { width: 100%; min-height: 42px; padding: 0 11px; border: 1px solid #dfe2d8; border-radius: 5px; background: #fffefa; color: #222c24; font: inherit; }
    input:focus { outline: 2px solid #71917a; outline-offset: 1px; }
    button { width: 100%; min-height: 42px; margin-top: 14px; border: 0; border-radius: 5px; background: #315d43; color: white; font: 600 13px "DM Sans", sans-serif; cursor: pointer; }
    button:hover { background: #244631; }
    .error { margin: 0 0 14px; color: #b74937; }
    .notice { margin: 16px 0 0; padding: 10px; border-radius: 5px; background: #f8eae5; color: #743527; }
    code { overflow-wrap: anywhere; }
  </style>
</head>
<body>
  <main>
    <div class="mark" aria-hidden="true">🌿</div>
    <h1>Manager sign in</h1>
    <p>Sign in to access your store inventory and sales.</p>
    {$setupMessage}
    <form method="post" action="index.php">
      <input type="hidden" name="csrf_token" value="{$token}">
      <label for="manager-password">Manager password</label>
      <input id="manager-password" name="password" type="password" maxlength="200" autocomplete="current-password" required>
      <button type="submit">Sign in</button>
    </form>
    {$safeMessage}
  </main>
</body>
</html>
HTML;
    exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isApi = isset($_GET['api']) && $_GET['api'] === 'store';

if ($isApi) {
    if (empty($_SESSION['authenticated'])) {
        respond(401, ['error' => 'Your session has expired. Sign in again.']);
    }
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        respond(403, ['error' => 'The security token is invalid. Reload the page and try again.']);
    }
    if ($method === 'GET') {
        sendStore();
    }
    if ($method === 'POST') {
        saveStore();
    }
    header('Allow: GET, POST');
    respond(405, ['error' => 'This method is not allowed.']);
}

if ($method === 'POST' && ($_POST['action'] ?? '') === 'logout') {
    if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
        loginPage('The security token is invalid. Reload the page and try again.', 403);
    }
    $_SESSION = [];
    session_regenerate_id(true);
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    loginPage('You have signed out.');
}

if (empty($_SESSION['authenticated'])) {
    if ($method === 'POST') {
        if (!hash_equals((string)$_SESSION['csrf_token'], (string)($_POST['csrf_token'] ?? ''))) {
            loginPage('The security token is invalid. Reload the page and try again.', 403);
        }
        $password = getenv('DBM_MANAGER_PASSWORD');
        if (!is_string($password) || strlen($password) < 12) {
            loginPage('The server password is not configured. Ask the site administrator for help.', 503);
        }
        $now = time();
        if (($now < (int)($_SESSION['login_locked_until'] ?? 0))) {
            loginPage('Too many attempts. Wait five minutes before trying again.', 429);
        }
        if (is_string($_POST['password'] ?? null) && strlen($_POST['password']) <= 200 && hash_equals($password, $_POST['password'])) {
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            unset($_SESSION['login_attempts'], $_SESSION['login_locked_until']);
            header('Location: index.php', true, 303);
            exit;
        }
        $_SESSION['login_attempts'] = (int)($_SESSION['login_attempts'] ?? 0) + 1;
        if ($_SESSION['login_attempts'] >= 5) {
            $_SESSION['login_locked_until'] = $now + 300;
            $_SESSION['login_attempts'] = 0;
        }
        loginPage('Incorrect password. Check it and try again.', 401);
    }
    if ($method !== 'GET') {
        header('Allow: GET, POST');
        loginPage('This method is not allowed.', 405);
    }
    loginPage('');
}

if ($method !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit('This method is not allowed.');
}

$html = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . 'index.html');
if (!is_string($html)) {
    http_response_code(500);
    exit('Could not load the application page.');
}
$token = htmlspecialchars((string)$_SESSION['csrf_token'], ENT_QUOTES, 'UTF-8');
echo str_replace(['__CSRF_TOKEN__', '__AUTHENTICATED__'], [$token, 'true'], $html);
