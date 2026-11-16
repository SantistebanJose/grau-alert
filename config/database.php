<?php
declare(strict_types=1);

// Define las credenciales en variables de entorno o en config.local.php (ignorado por Git).
const APP_NAME = 'Grau-Alert';
date_default_timezone_set('America/Lima');

function conectar_oll_BD(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $local = [];
    $localPath = dirname(__DIR__) . '/config.local.php';
    if (is_file($localPath)) {
        $values = require $localPath;
        if (is_array($values)) $local = $values;
    }
    $setting = static fn(string $key, string $fallback = ''): string => (string)(getenv($key) ?: ($local[$key] ?? $fallback));
    $host = $setting('DB_HOST');
    $port = $setting('DB_PORT', '5432');
    $name = $setting('DB_NAME');
    $user = $setting('DB_USER');
    $pass = $setting('DB_PASSWORD');
    if ($host === '' || $name === '' || $user === '') {
        throw new RuntimeException('Falta configurar DB_HOST, DB_NAME y DB_USER en el servidor.');
    }
    $sslMode = $setting('DB_SSLMODE', in_array(strtolower($host), ['localhost', '127.0.0.1', '::1'], true) ? 'disable' : 'require');
    $dsn = "pgsql:host={$host};port={$port};dbname={$name};sslmode={$sslMode}";
    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec("SET TIME ZONE 'America/Lima'");
    return $pdo;
}

function app_setting(string $key, string $fallback = ''): string
{
    if (getenv($key) !== false) return (string)getenv($key);
    $localPath = dirname(__DIR__) . '/config.local.php';
    if (is_file($localPath)) {
        $values = require $localPath;
        if (is_array($values) && isset($values[$key])) return (string)$values[$key];
    }
    return $fallback;
}

function db(): PDO
{
    return conectar_oll_BD();
}

function start_app_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        // Evita que otros proyectos PHP alojados en localhost sobrescriban PHPSESSID.
        session_name('GRAUALERTSID');
        // Apache suele definir HTTPS como la cadena "off" incluso en HTTP local;
        // !empty('off') es true y provocaba cookies Secure que no se enviaban en localhost.
        $httpsValue = strtolower(trim((string)($_SERVER['HTTPS'] ?? '')));
        if ($httpsValue === '' || in_array($httpsValue, ['off', '0', 'false'], true)) {
            // En hosting detrás de proxy TLS, Apache puede recibir HTTP interno.
            $httpsValue = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        }
        $isHttps = $httpsValue !== '' && !in_array($httpsValue, ['off', '0', 'false'], true);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'secure' => $isHttps,
            'samesite' => 'Lax',
        ]);
        ini_set('session.use_strict_mode', '1');
        session_start();
    }
}

function current_user(): ?array
{
    start_app_session();
    return $_SESSION['user'] ?? null;
}

function require_user(array $roles = []): array
{
    $user = current_user();
    if (!$user || ($roles && !in_array($user['rol'], $roles, true))) {
        http_response_code(403);
        throw new RuntimeException('No tienes permiso para realizar esta acción.');
    }
    return $user;
}
