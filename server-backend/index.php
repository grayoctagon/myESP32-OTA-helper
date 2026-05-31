<?php
declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
error_reporting(E_ALL);
ob_start();

const DATA_FILE = __DIR__ . '/data.json';
const DATA_LOCK_FILE = __DIR__ . '/data.json.lock';
const BINS_DIR = __DIR__ . '/bins';
const BACKUP_DIR = __DIR__ . '/dataBackups';
const MAX_UPLOAD_BYTES = 8388608; // 8 MB

class ApiError extends Exception
{
    public int $status;

    public function __construct(string $message = 'request failed', int $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

if (isset($_GET['action'])) {
    try {
        handle_action((string)$_GET['action']);
    } catch (ApiError $e) {
        json_error($e->getMessage(), $e->status);
    } catch (Throwable $e) {
        error_log((string)$e);
        json_error('server error', 500);
    }
    exit;
}

function handle_action(string $action): void
{
    ensure_storage();

    switch ($action) {
        case 'esp_download':
            action_esp_download();
            return;
        case 'frontend_data':
            action_frontend_data();
            return;
        case 'upload_bin':
            action_upload_bin();
            return;
        case 'set_esp_firmware':
            action_set_esp_firmware();
            return;
        case 'set_label':
            action_set_label();
            return;
        case 'change_token':
            action_change_token();
            return;
        case 'delete_esp32':
            action_delete_esp32();
            return;
        case 'delete_token':
            action_delete_token();
            return;
        case 'create_token':
            action_create_token();
            return;
        case 'mkBackup':
            action_mk_backup();
            return;
        case 'change_logLimit':
            action_change_log_limit();
            return;
        case 'change_maxKnownESP':
            action_change_max_known_esp();
            return;
        case 'download_file': // Frontend-Download einer gespeicherten Firmware-Version.
            action_download_file();
            return;
        default:
            throw new ApiError('unknown action', 404);
    }
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        throw new ApiError('method not allowed', 405);
    }
}

function default_data(): array
{
    return [
        'logLimit' => 300,
        'maxKnownESP' => 4,
        'esps' => [],
        'allowedTokens' => [],
        'files' => [],
    ];
}

function default_esp(string $mac, array $now): array
{
    return [
        'label' => 'new ESP at ' . $now['iso'],
        'lastSeenT' => false,
        'lastSeenISO8601' => false,
        'lastFirmware' => false,
        'lastFirmwareDownloadT' => false,
        'lastFirmwareDownloadISO8601' => false,
        'nextFirmwareFile' => false,
        'recentVisits' => [],
    ];
}

function ensure_storage(): void
{
    ensure_dir(BINS_DIR);
    ensure_dir(BACKUP_DIR);
    ensure_deny_htaccess(BINS_DIR);
    ensure_deny_htaccess(BACKUP_DIR);

    $lock = fopen(DATA_LOCK_FILE, 'c');
    if (!$lock) {
        throw new RuntimeException('cannot open lock file');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('cannot lock data');
        }
        if (!is_file(DATA_FILE)) {
            $data = default_data();
            write_data_unlocked($data);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function ensure_dir(string $dir): void
{
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('cannot create directory');
    }
}

function ensure_deny_htaccess(string $dir): void
{
    $file = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '.htaccess';
    if (!is_file($file)) {
        $content = "<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\nDeny from all\n</IfModule>\n";
        file_put_contents($file, $content, LOCK_EX);
    }
}

function with_data_write(callable $callback)
{
    ensure_storage();
    $lock = fopen(DATA_LOCK_FILE, 'c');
    if (!$lock) {
        throw new RuntimeException('cannot open lock file');
    }
    try {
        if (!flock($lock, LOCK_EX)) {
            throw new RuntimeException('cannot lock data');
        }
        $data = read_data_unlocked();
        $result = $callback($data);
        $data = normalize_data($data);
        trim_all_logs($data);
        write_data_unlocked($data);
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function with_data_read(callable $callback)
{
    ensure_storage();
    $lock = fopen(DATA_LOCK_FILE, 'c');
    if (!$lock) {
        throw new RuntimeException('cannot open lock file');
    }
    try {
        if (!flock($lock, LOCK_SH)) {
            throw new RuntimeException('cannot lock data');
        }
        $data = read_data_unlocked();
        return $callback($data);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function read_data_unlocked(): array
{
    if (!is_file(DATA_FILE)) {
        return default_data();
    }
    $json = file_get_contents(DATA_FILE);
    if ($json === false || trim($json) === '') {
        return default_data();
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('invalid data.json');
    }
    return normalize_data($data);
}

function write_data_unlocked(array $data): void
{
    $data = normalize_data($data);
    trim_all_logs($data);
    $json = json_encode(json_ready_data($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false) {
        throw new RuntimeException('cannot encode json');
    }
    $tmp = DATA_FILE . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    if (file_put_contents($tmp, $json . "\n", LOCK_EX) === false) {
        @unlink($tmp);
        throw new RuntimeException('cannot write data temp file');
    }
    @chmod($tmp, 0644);
    if (!rename($tmp, DATA_FILE)) {
        @unlink($tmp);
        throw new RuntimeException('cannot replace data file');
    }
}

function json_ready_data(array $data): array
{
    if (empty($data['esps'])) {
        $data['esps'] = new stdClass();
    }
    return $data;
}

function normalize_data(array $data): array
{
    $out = default_data();
    $out['logLimit'] = max(1, (int)($data['logLimit'] ?? 300));
    $out['maxKnownESP'] = max(0, (int)($data['maxKnownESP'] ?? 4));

    $rawEsps = $data['esps'] ?? [];
    if (is_array($rawEsps)) {
        foreach ($rawEsps as $mac => $esp) {
            if (!is_array($esp)) {
                $esp = [];
            }
            $normalizedMac = normalize_mac((string)$mac);
            if ($normalizedMac === null) {
                $normalizedMac = safe_id((string)$mac);
            }
            if ($normalizedMac === '') {
                continue;
            }
            $out['esps'][$normalizedMac] = [
                'label' => string_or_default($esp['label'] ?? null, 'ESP ' . $normalizedMac),
                'lastSeenT' => scalar_or_false($esp['lastSeenT'] ?? false),
                'lastSeenISO8601' => scalar_or_false($esp['lastSeenISO8601'] ?? false),
                'lastFirmware' => scalar_or_false($esp['lastFirmware'] ?? false),
                'lastFirmwareDownloadT' => scalar_or_false($esp['lastFirmwareDownloadT'] ?? false),
                'lastFirmwareDownloadISO8601' => scalar_or_false($esp['lastFirmwareDownloadISO8601'] ?? false),
                'nextFirmwareFile' => normalize_next_value($esp['nextFirmwareFile'] ?? false),
                'recentVisits' => normalize_visits($esp['recentVisits'] ?? []),
            ];
        }
    }

    $rawTokens = $data['allowedTokens'] ?? [];
    if (is_array($rawTokens)) {
        foreach ($rawTokens as $token) {
            if (!is_array($token)) {
                continue;
            }
            $hash = (string)($token['hash'] ?? '');
            if ($hash === '') {
                continue;
            }
            $out['allowedTokens'][] = [
                'label' => string_or_default($token['label'] ?? null, 'Token'),
                'hash' => $hash,
                'allowedUpload' => bool_value($token['allowedUpload'] ?? false),
                'recentVisits' => normalize_visits($token['recentVisits'] ?? []),
            ];
        }
    }

    $rawFiles = $data['files'] ?? [];
    if (is_array($rawFiles)) {
        foreach ($rawFiles as $file) {
            if (!is_array($file)) {
                continue;
            }
            $name = normalize_filename((string)($file['name'] ?? ''));
            if ($name === '' || !is_safe_name($name) || !ends_with_bin($name)) {
                continue;
            }
            $versions = [];
            $rawVersions = $file['versions'] ?? [];
            if (is_array($rawVersions)) {
                foreach ($rawVersions as $version) {
                    if (!is_array($version)) {
                        continue;
                    }
                    $versionFile = normalize_filename((string)($version['file'] ?? ''));
                    if ($versionFile === '' || !is_safe_name($versionFile) || !ends_with_bin($versionFile)) {
                        continue;
                    }
                    $versions[] = [
                        'file' => $versionFile,
                        'label' => string_or_default($version['label'] ?? null, ''),
                    ];
                }
            }
            $out['files'][] = [
                'name' => $name,
                'label' => string_or_default($file['label'] ?? null, ''),
                'versions' => $versions,
            ];
        }
    }

    trim_all_logs($out);
    return $out;
}

function normalize_visits($visits): array
{
    if (!is_array($visits)) {
        return [];
    }
    $out = [];
    foreach ($visits as $visit) {
        if (is_array($visit)) {
            $out[] = $visit;
        }
    }
    return $out;
}

function trim_all_logs(array &$data): void
{
    $limit = max(1, (int)($data['logLimit'] ?? 300));
    foreach ($data['esps'] as &$esp) {
        $esp['recentVisits'] = limit_recent($esp['recentVisits'] ?? [], $limit);
    }
    unset($esp);
    foreach ($data['allowedTokens'] as &$token) {
        $token['recentVisits'] = limit_recent($token['recentVisits'] ?? [], $limit);
    }
    unset($token);
}

function limit_recent(array $items, int $limit): array
{
    if (count($items) <= $limit) {
        return array_values($items);
    }
    return array_values(array_slice($items, -$limit));
}

function string_or_default($value, string $default): string
{
    if (is_scalar($value)) {
        return cut_string((string)$value, 2000);
    }
    return $default;
}

function scalar_or_false($value)
{
    if ($value === false || $value === null || $value === '') {
        return false;
    }
    if (is_scalar($value)) {
        return is_numeric($value) ? (0 + $value) : cut_string((string)$value, 2000);
    }
    return false;
}

function normalize_next_value($value)
{
    if ($value === false || $value === null || $value === '' || $value === 'false') {
        return false;
    }
    if (!is_scalar($value)) {
        return false;
    }
    return cut_string((string)$value, 500);
}

function bool_value($value): bool
{
    if (is_bool($value)) {
        return $value;
    }
    if (is_numeric($value)) {
        return ((int)$value) !== 0;
    }
    $value = strtolower(trim((string)$value));
    return in_array($value, ['1', 'true', 'yes', 'on', 'ja'], true);
}

function cut_string(string $s, int $max): string
{
    return strlen($s) > $max ? substr($s, 0, $max) : $s;
}

function now_info(): array
{
    $micro = microtime(true);
    $dt = DateTimeImmutable::createFromFormat('U.u', sprintf('%.6F', $micro));
    if (!$dt) {
        $dt = new DateTimeImmutable('now');
    }
    $tz = new DateTimeZone(date_default_timezone_get() ?: 'UTC');
    $dt = $dt->setTimezone($tz);
    $iso = $dt->format('Y-m-d\TH:i:s.vP');
    return [
        'ms' => (string)((int)floor($micro * 1000)),
        'iso' => $iso,
        'fileIso' => str_replace(':', '_', $iso),
    ];
}

function normalize_mac(string $mac): ?string
{
    $mac = trim($mac);
    if ($mac === '') {
        return null;
    }
    $plain = preg_replace('/[^0-9a-fA-F]/', '', $mac);
    if (!is_string($plain) || strlen($plain) !== 12 || !ctype_xdigit($plain)) {
        return null;
    }
    return strtoupper(implode('-', str_split($plain, 2)));
}

function safe_id(string $s): string
{
    $s = preg_replace('/[^A-Za-z0-9._+-]/', '-', trim($s));
    return cut_string((string)$s, 100);
}

function normalize_filename(string $name): string
{
    $name = str_replace('\\', '/', $name);
    $name = basename($name);
    $name = preg_replace('/[^A-Za-z0-9._+-]/', '_', $name);
    $name = trim((string)$name, " \t\n\r\0\x0B");
    return cut_string($name, 240);
}

function is_safe_name(string $name): bool
{
    return $name !== '' && preg_match('/\A[A-Za-z0-9._+-]+\z/', $name) === 1 && strpos($name, '/') === false && strpos($name, '\\') === false;
}

function ends_with_bin(string $name): bool
{
    return str_ends_with(strtolower($name), '.bin');
}

function request_token(): string
{
    $token = $_POST['token'] ?? $_GET['token'] ?? '';
    if (is_array($token)) {
        $token = '';
    }
    if ($token === '' && isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $auth = (string)$_SERVER['HTTP_AUTHORIZATION'];
        if (stripos($auth, 'Bearer ') === 0) {
            $token = substr($auth, 7);
        }
    }
    return trim((string)$token);
}

function authenticate(array &$data, bool $needUpload): array
{
    $secret = request_token();
    if ($secret === '') {
        throw new ApiError('invalid authentication', 403);
    }
    foreach ($data['allowedTokens'] as $idx => $token) {
        $hash = (string)($token['hash'] ?? '');
        if ($hash !== '' && password_verify($secret, $hash)) {
            $auth = [
                'idx' => (int)$idx,
                'hash' => $hash,
                'label' => (string)($token['label'] ?? ''),
                'allowedUpload' => !empty($token['allowedUpload']),
            ];
            if ($needUpload && !$auth['allowedUpload']) {
                throw new ApiError('invalid authentication', 403);
            }
            return $auth;
        }
    }
    throw new ApiError('invalid authentication', 403);
}

function post_string(string $name, string $default = ''): string
{
    $value = $_POST[$name] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return cut_string((string)$value, 4000);
}

function get_string(string $name, string $default = ''): string
{
    $value = $_GET[$name] ?? $default;
    if (is_array($value)) {
        return $default;
    }
    return cut_string((string)$value, 4000);
}

function client_info(): array
{
    $ip = (string)($_SERVER['REMOTE_ADDR'] ?? '');
    $host = '';
    if ($ip !== '') {
        $resolved = @gethostbyaddr($ip);
        $host = is_string($resolved) ? $resolved : '';
    }
    return [$ip, $host];
}

function add_token_visit(array &$data, int $tokenIdx, string $action, ?string $note = null): void
{
    if (!isset($data['allowedTokens'][$tokenIdx])) {
        return;
    }
    $now = now_info();
    [$ip, $host] = client_info();
    $visit = [
        't' => $now['ms'],
        'tISO8601' => $now['iso'],
        'action' => $action,
    ];
    if ($note !== null && $note !== '') {
        $visit['action_note'] = cut_string($note, 2000);
    }
    if ($ip !== '') {
        $visit['IP'] = $ip;
        $visit['gethostbyaddr'] = $host;
    }
    $data['allowedTokens'][$tokenIdx]['recentVisits'][] = $visit;
    $data['allowedTokens'][$tokenIdx]['recentVisits'] = limit_recent($data['allowedTokens'][$tokenIdx]['recentVisits'], (int)$data['logLimit']);
}

function add_esp_visit(array &$data, string $mac, $firmware, $didDownload, string $note): void
{
    if (!isset($data['esps'][$mac])) {
        return;
    }
    $now = now_info();
    [$ip, $host] = client_info();
    $visit = [
        't' => $now['ms'],
        'tISO8601' => $now['iso'],
        'firmware' => $firmware === null || $firmware === '' ? false : cut_string((string)$firmware, 500),
        'didDownload' => $didDownload === false ? false : cut_string((string)$didDownload, 500),
        'IP' => $ip,
        'gethostbyaddr' => $host,
        'action_note' => cut_string($note, 2000),
    ];
    $data['esps'][$mac]['recentVisits'][] = $visit;
    $data['esps'][$mac]['recentVisits'] = limit_recent($data['esps'][$mac]['recentVisits'], (int)$data['logLimit']);
}

function json_success(array $payload = []): void
{
    clear_output_buffers();
    http_response_code(200);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['success' => true], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    clear_output_buffers();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function clear_output_buffers(): void
{
    while (ob_get_level() > 0) {
        @ob_end_clean();
    }
}

function send_no_firmware(): void
{
    clear_output_buffers();
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 200 OK');
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'no new firmware available';
    exit;
}

function send_binary_file(string $path, string $downloadName, bool $attachment): void
{
    if (!is_file($path)) {
        throw new ApiError('file not found', 404);
    }
    $size = filesize($path);
    if ($size === false) {
        throw new ApiError('file not readable', 404);
    }
    clear_output_buffers();
    header(($_SERVER['SERVER_PROTOCOL'] ?? 'HTTP/1.1') . ' 200 OK');
    header('Content-Type: application/octet-stream');
    header('Content-Length: ' . (string)$size);
    header('Cache-Control: no-store');
    if ($attachment) {
        $safeDownload = str_replace(['"', "\r", "\n"], '', $downloadName);
        header('Content-Disposition: attachment; filename="' . $safeDownload . '"');
    }
    $fp = fopen($path, 'rb');
    if ($fp !== false) {
        fpassthru($fp);
        fclose($fp);
    }
    exit;
}

function action_esp_download(): void
{
    require_method('GET');
    $mac = normalize_mac(get_string('macadress'));
    if ($mac === null) {
        send_no_firmware();
    }
    $currentFirmware = get_string('currentFirmware', get_string('firmware', ''));

    $result = with_data_write(function (array &$data) use ($mac, $currentFirmware): array {
        $now = now_info();
        $known = isset($data['esps'][$mac]);
        if (!$known) {
            if (count($data['esps']) >= (int)$data['maxKnownESP']) {
                return ['type' => 'none'];
            }
            $data['esps'][$mac] = default_esp($mac, $now);
        }

        $data['esps'][$mac]['lastSeenT'] = $now['ms'];
        $data['esps'][$mac]['lastSeenISO8601'] = $now['iso'];
        if ($currentFirmware !== '') {
            $data['esps'][$mac]['lastFirmware'] = cut_string($currentFirmware, 500);
        }

        $esp = $data['esps'][$mac];
        $firmwareInfo = resolve_firmware_for_esp($data, $esp['nextFirmwareFile'] ?? false);
        $lastDownload = $esp['lastFirmwareDownloadT'] ?? false;
        $currentOrStoredFirmware = $currentFirmware !== '' ? $currentFirmware : ($esp['lastFirmware'] ?? false);

        if ($firmwareInfo === null || !version_is_newer_than_last_download((int)$firmwareInfo['mtimeMs'], $lastDownload)) {
            add_esp_visit($data, $mac, $currentOrStoredFirmware, false, 'no new firmware available');
            return ['type' => 'none'];
        }

        $data['esps'][$mac]['lastFirmwareDownloadT'] = (int)$now['ms'];
        $data['esps'][$mac]['lastFirmwareDownloadISO8601'] = $now['iso'];
        add_esp_visit(
            $data,
            $mac,
            $currentOrStoredFirmware,
            $firmwareInfo['versionFile'],
            'downloaded ' . $firmwareInfo['versionFile'] . ' size: ' . $firmwareInfo['size'] . ' Bytes'
        );

        return [
            'type' => 'file',
            'path' => $firmwareInfo['path'],
            'downloadName' => $firmwareInfo['baseName'],
        ];
    });

    if (($result['type'] ?? 'none') === 'file') {
        send_binary_file((string)$result['path'], (string)$result['downloadName'], false);
    }
    send_no_firmware();
}

function resolve_firmware_for_esp(array $data, $nextFirmwareFile): ?array
{
    $nextFirmwareFile = normalize_next_value($nextFirmwareFile);
    if ($nextFirmwareFile === false) {
        return null;
    }
    $next = (string)$nextFirmwareFile;
    if (str_starts_with($next, '*')) {
        $baseName = normalize_filename(substr($next, 1));
        if ($baseName === '' || !is_safe_name($baseName) || !ends_with_bin($baseName)) {
            return null;
        }
        $file = find_file_entry($data, $baseName);
        if ($file === null) {
            return null;
        }
        $best = null;
        foreach ($file['versions'] as $version) {
            $info = firmware_version_info($baseName, (string)$version['file']);
            if ($info === null) {
                continue;
            }
            if ($best === null || $info['mtimeMs'] > $best['mtimeMs']) {
                $best = $info;
            }
        }
        return $best;
    }

    $versionName = normalize_filename($next);
    if ($versionName === '' || !is_safe_name($versionName) || !ends_with_bin($versionName)) {
        return null;
    }
    foreach ($data['files'] as $file) {
        $baseName = (string)($file['name'] ?? '');
        if (!is_safe_name($baseName)) {
            continue;
        }
        foreach (($file['versions'] ?? []) as $version) {
            if (($version['file'] ?? '') === $versionName) {
                return firmware_version_info($baseName, $versionName);
            }
        }
    }
    return null;
}

function version_is_newer_than_last_download(int $versionMs, $lastDownload): bool
{
    if ($lastDownload === false || $lastDownload === null || $lastDownload === '' || $lastDownload === 'false') {
        return true;
    }
    return $versionMs > (int)$lastDownload;
}

function find_file_entry(array $data, string $name): ?array
{
    foreach ($data['files'] as $file) {
        if (($file['name'] ?? '') === $name) {
            return $file;
        }
    }
    return null;
}

function firmware_version_info(string $baseName, string $versionFile): ?array
{
    if (!is_safe_name($baseName) || !is_safe_name($versionFile)) {
        return null;
    }
    $path = BINS_DIR . DIRECTORY_SEPARATOR . $baseName . DIRECTORY_SEPARATOR . $versionFile;
    if (!is_file($path)) {
        return null;
    }
    $mtime = filemtime($path);
    $size = filesize($path);
    if ($mtime === false || $size === false) {
        return null;
    }
    return [
        'baseName' => $baseName,
        'versionFile' => $versionFile,
        'path' => $path,
        'mtimeMs' => $mtime * 1000,
        'size' => $size,
    ];
}

function action_frontend_data(): void
{
    require_method('GET');
    $payload = with_data_write(function (array &$data): array {
        $auth = authenticate($data, false);
        add_token_visit($data, $auth['idx'], 'frontend_data');
        return frontend_payload($data, $auth);
    });
    json_success($payload);
}

function frontend_payload(array $data, array $auth): array
{
    $isAdmin = !empty($auth['allowedUpload']);
    $tokens = [];
    foreach ($data['allowedTokens'] as $idx => $token) {
        $isCurrent = ((int)$idx === (int)$auth['idx']);
        $row = [
            'label' => (string)($token['label'] ?? ''),
            'allowedUpload' => !empty($token['allowedUpload']),
            'recentVisits' => $token['recentVisits'] ?? [],
            'isCurrent' => $isCurrent,
        ];
        if ($isAdmin || $isCurrent) {
            $row['hash'] = (string)($token['hash'] ?? '');
        } else {
            $row['hash'] = null;
        }
        $tokens[] = $row;
    }
    return [
        'auth' => [
            'label' => (string)$auth['label'],
            'allowedUpload' => $isAdmin,
            'currentTokenHash' => (string)$auth['hash'],
        ],
        'serverISO8601' => now_info()['iso'],
        'logLimit' => (int)$data['logLimit'],
        'maxKnownESP' => (int)$data['maxKnownESP'],
        'esps' => $data['esps'],
        'files' => $data['files'],
        'allowedTokens' => $tokens,
        'backups' => list_backups(),
    ];
}

function action_upload_bin(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);

        if (!isset($_FILES['file']) || !is_array($_FILES['file'])) {
            throw new ApiError('missing file', 400);
        }
        $upload = $_FILES['file'];
        if (is_array($upload['name'] ?? null)) {
            throw new ApiError('only one file allowed', 400);
        }
        if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new ApiError('upload failed', 400);
        }
        $size = (int)($upload['size'] ?? 0);
        if ($size <= 0 || $size > MAX_UPLOAD_BYTES) {
            throw new ApiError('invalid file size', 400);
        }
        $baseName = normalize_filename((string)($upload['name'] ?? ''));
        if ($baseName === '' || !is_safe_name($baseName) || !ends_with_bin($baseName)) {
            throw new ApiError('only safe .bin filenames are allowed', 400);
        }

        $dir = BINS_DIR . DIRECTORY_SEPARATOR . $baseName;
        ensure_dir($dir);
        $now = now_info();
        $storedName = $now['fileIso'] . '_' . $baseName;
        if (!is_safe_name($storedName) || !ends_with_bin($storedName)) {
            throw new ApiError('invalid stored filename', 400);
        }
        $target = $dir . DIRECTORY_SEPARATOR . $storedName;
        if (is_file($target)) {
            throw new ApiError('file already exists', 409);
        }
        if (!is_uploaded_file((string)$upload['tmp_name']) || !move_uploaded_file((string)$upload['tmp_name'], $target)) {
            throw new ApiError('could not store upload', 500);
        }
        @chmod($target, 0644);

        $label = cut_string(post_string('label', ''), 2000);
        upsert_file_version($data, $baseName, $storedName, $label);
        $note = 'uploaded ' . $storedName . ' size: ' . $size . ' Bytes';
        add_token_visit($data, $auth['idx'], 'upload_bin', $note);

        return ['file' => $baseName, 'version' => $storedName, 'message' => $note];
    });
    json_success($result);
}

function upsert_file_version(array &$data, string $baseName, string $storedName, string $label): void
{
    foreach ($data['files'] as &$file) {
        if (($file['name'] ?? '') === $baseName) {
            if (($file['label'] ?? '') === '' && $label !== '') {
                $file['label'] = $label;
            }
            foreach ($file['versions'] as $version) {
                if (($version['file'] ?? '') === $storedName) {
                    return;
                }
            }
            $file['versions'][] = ['file' => $storedName, 'label' => $label];
            return;
        }
    }
    unset($file);
    $data['files'][] = [
        'name' => $baseName,
        'label' => $label,
        'versions' => [
            ['file' => $storedName, 'label' => $label],
        ],
    ];
}

function action_set_esp_firmware(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $mac = normalize_mac(post_string('macadress'));
        if ($mac === null || !isset($data['esps'][$mac])) {
            throw new ApiError('esp not found', 404);
        }
        $next = validate_next_firmware_selection($data, post_string('nextFirmwareFile', 'false'));
        $old = $data['esps'][$mac]['nextFirmwareFile'] ?? false;
        $data['esps'][$mac]['nextFirmwareFile'] = $next;
        $data['esps'][$mac]['lastFirmwareDownloadT'] = false;
        $data['esps'][$mac]['lastFirmwareDownloadISO8601'] = false;
        add_token_visit($data, $auth['idx'], 'set_esp_firmware', 'macadress=' . $mac . ' old=' . value_to_log($old) . ' new=' . value_to_log($next));
        return ['macadress' => $mac, 'nextFirmwareFile' => $next];
    });
    json_success($result);
}

function validate_next_firmware_selection(array $data, string $raw)
{
    $raw = trim($raw);
    if ($raw === '' || $raw === 'false') {
        return false;
    }
    if (str_starts_with($raw, '*')) {
        $baseName = normalize_filename(substr($raw, 1));
        if ($baseName === '' || !is_safe_name($baseName) || find_file_entry($data, $baseName) === null) {
            throw new ApiError('invalid firmware selection', 400);
        }
        return '*' . $baseName;
    }
    $versionName = normalize_filename($raw);
    if ($versionName === '' || !is_safe_name($versionName)) {
        throw new ApiError('invalid firmware selection', 400);
    }
    foreach ($data['files'] as $file) {
        foreach (($file['versions'] ?? []) as $version) {
            if (($version['file'] ?? '') === $versionName) {
                return $versionName;
            }
        }
    }
    throw new ApiError('invalid firmware selection', 400);
}

function value_to_log($value): string
{
    if ($value === false || $value === null || $value === '') {
        return 'false';
    }
    return cut_string((string)$value, 500);
}

function action_set_label(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $type = strtolower(trim(post_string('type')));
        $id = post_string('id');
        $label = cut_string(post_string('label', ''), 2000);

        switch ($type) {
            case 'esp':
                $mac = normalize_mac($id);
                if ($mac === null || !isset($data['esps'][$mac])) {
                    throw new ApiError('esp not found', 404);
                }
                $data['esps'][$mac]['label'] = $label;
                $id = $mac;
                break;
            case 'token':
                $idx = find_token_index_by_hash($data, $id);
                if ($idx === null) {
                    throw new ApiError('token not found', 404);
                }
                $data['allowedTokens'][$idx]['label'] = $label;
                break;
            case 'file':
                $baseName = normalize_filename($id);
                $found = false;
                foreach ($data['files'] as &$file) {
                    if (($file['name'] ?? '') === $baseName) {
                        $file['label'] = $label;
                        $found = true;
                        break;
                    }
                }
                unset($file);
                if (!$found) {
                    throw new ApiError('file not found', 404);
                }
                $id = $baseName;
                break;
            case 'fileversion':
                [$baseName, $versionName] = parse_fileversion_id($id);
                $found = false;
                foreach ($data['files'] as &$file) {
                    if ($baseName !== '' && ($file['name'] ?? '') !== $baseName) {
                        continue;
                    }
                    foreach ($file['versions'] as &$version) {
                        if (($version['file'] ?? '') === $versionName) {
                            $version['label'] = $label;
                            $found = true;
                            break 2;
                        }
                    }
                    unset($version);
                }
                unset($file, $version);
                if (!$found) {
                    throw new ApiError('file version not found', 404);
                }
                $id = ($baseName !== '' ? $baseName . '||' : '') . $versionName;
                break;
            default:
                throw new ApiError('invalid label type', 400);
        }

        add_token_visit($data, $auth['idx'], 'set_label', 'type=' . $type . ' id=' . cut_string($id, 500));
        return ['type' => $type, 'id' => $id, 'label' => $label];
    });
    json_success($result);
}

function parse_fileversion_id(string $id): array
{
    if (strpos($id, '||') !== false) {
        [$baseName, $versionName] = explode('||', $id, 2);
        $baseName = normalize_filename($baseName);
    } else {
        $baseName = '';
        $versionName = $id;
    }
    $versionName = normalize_filename($versionName);
    if (($baseName !== '' && !is_safe_name($baseName)) || $versionName === '' || !is_safe_name($versionName)) {
        throw new ApiError('invalid file version id', 400);
    }
    return [$baseName, $versionName];
}

function action_change_token(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, false);
        $targetHash = post_string('targetTokenHash');
        $newToken = post_string('newToken');
        if ($targetHash === '' || $newToken === '') {
            throw new ApiError('missing token data', 400);
        }
        $idx = find_token_index_by_hash($data, $targetHash);
        if ($idx === null) {
            throw new ApiError('token not found', 404);
        }
        if (empty($auth['allowedUpload']) && !hash_equals($auth['hash'], $targetHash)) {
            throw new ApiError('invalid authentication', 403);
        }
        $isCurrent = hash_equals($auth['hash'], $targetHash);
        $newHash = password_hash($newToken, PASSWORD_DEFAULT);
        if (!is_string($newHash) || $newHash === '') {
            throw new ApiError('could not hash token', 500);
        }
        $data['allowedTokens'][$idx]['hash'] = $newHash;
        add_token_visit($data, $auth['idx'], 'change_token', 'target=' . ($isCurrent ? 'self' : 'other'));
        return ['changedCurrentToken' => $isCurrent];
    });
    json_success($result);
}

function find_token_index_by_hash(array $data, string $hash): ?int
{
    foreach ($data['allowedTokens'] as $idx => $token) {
        if (hash_equals((string)($token['hash'] ?? ''), $hash)) {
            return (int)$idx;
        }
    }
    return null;
}

function action_delete_esp32(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $mac = normalize_mac(post_string('macadress'));
        if ($mac === null || !isset($data['esps'][$mac])) {
            throw new ApiError('esp not found', 404);
        }
        unset($data['esps'][$mac]);
        add_token_visit($data, $auth['idx'], 'delete_esp32', 'macadress=' . $mac);
        return ['deleted' => $mac];
    });
    json_success($result);
}

function action_delete_token(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $targetHash = post_string('targetTokenHash');
        $idx = find_token_index_by_hash($data, $targetHash);
        if ($idx === null) {
            throw new ApiError('token not found', 404);
        }
        $isCurrent = hash_equals($auth['hash'], $targetHash);
        add_token_visit($data, $auth['idx'], 'delete_token', 'target=' . ($isCurrent ? 'self' : 'other'));
        unset($data['allowedTokens'][$idx]);
        $data['allowedTokens'] = array_values($data['allowedTokens']);
        return ['deletedCurrentToken' => $isCurrent];
    });
    json_success($result);
}

function action_create_token(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $secret = post_string('targetTokenSecret');
        $label = post_string('Label', post_string('label', 'new Token'));
        $allowedUpload = bool_value(post_string('is_allowedUpload', '0'));
        if ($secret === '') {
            throw new ApiError('missing new token secret', 400);
        }
        foreach ($data['allowedTokens'] as $token) {
            if (password_verify($secret, (string)($token['hash'] ?? ''))) {
                throw new ApiError('token already exists', 409);
            }
        }
        $hash = password_hash($secret, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new ApiError('could not hash token', 500);
        }
        $data['allowedTokens'][] = [
            'label' => cut_string($label, 2000),
            'hash' => $hash,
            'allowedUpload' => $allowedUpload,
            'recentVisits' => [],
        ];
        add_token_visit($data, $auth['idx'], 'create_token', 'label=' . cut_string($label, 500) . ' allowedUpload=' . ($allowedUpload ? 'true' : 'false'));
        return ['created' => true];
    });
    json_success($result);
}

function action_mk_backup(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        add_token_visit($data, $auth['idx'], 'mkBackup');
        $backup = write_backup_from_data($data);
        return ['backup' => $backup, 'backups' => list_backups()];
    });
    json_success($result);
}

function write_backup_from_data(array $data): string
{
    ensure_dir(BACKUP_DIR);
    ensure_deny_htaccess(BACKUP_DIR);
    $base = now_info()['fileIso'] . '_data.json';
    $filename = $base;
    $n = 1;
    while (is_file(BACKUP_DIR . DIRECTORY_SEPARATOR . $filename)) {
        $filename = preg_replace('/\.json\z/', '', $base) . '_' . $n . '.json';
        $n++;
    }
    $path = BACKUP_DIR . DIRECTORY_SEPARATOR . $filename;
    $tmp = $path . '.tmp.' . getmypid();
    $json = json_encode(json_ready_data(normalize_data($data)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($json === false || file_put_contents($tmp, $json . "\n", LOCK_EX) === false || !rename($tmp, $path)) {
        @unlink($tmp);
        throw new ApiError('could not create backup', 500);
    }
    @chmod($path, 0644);
    return $filename;
}

function list_backups(): array
{
    ensure_dir(BACKUP_DIR);
    ensure_deny_htaccess(BACKUP_DIR);
    $items = [];
    $files = glob(BACKUP_DIR . DIRECTORY_SEPARATOR . '*.json');
    if (is_array($files)) {
        foreach ($files as $file) {
            $name = basename($file);
            if (is_safe_name($name)) {
                $items[] = [
                    'name' => $name,
                    'size' => (int)(filesize($file) ?: 0),
                    'mtimeISO8601' => date('c', (int)(filemtime($file) ?: time())),
                ];
            }
        }
    }
    usort($items, static fn($a, $b) => strcmp((string)$b['name'], (string)$a['name']));
    return $items;
}

function action_change_log_limit(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $old = (int)$data['logLimit'];
        $new = (int)post_string('new_logLimit', '0');
        if ($new < 1 || $new > 10000) {
            throw new ApiError('invalid logLimit', 400);
        }
        $data['logLimit'] = $new;
        trim_all_logs($data);
        add_token_visit($data, $auth['idx'], 'change_logLimit', 'old=' . $old . ' new=' . $new);
        return ['old' => $old, 'new' => $new];
    });
    json_success($result);
}

function action_change_max_known_esp(): void
{
    require_method('POST');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, true);
        $old = (int)$data['maxKnownESP'];
        $new = (int)post_string('new_maxKnownESP', '0');
        if ($new < 0 || $new > 10000) {
            throw new ApiError('invalid maxKnownESP', 400);
        }
        $data['maxKnownESP'] = $new;
        add_token_visit($data, $auth['idx'], 'change_maxKnownESP', 'old=' . $old . ' new=' . $new);
        return ['old' => $old, 'new' => $new];
    });
    json_success($result);
}

function action_download_file(): void
{
    require_method('GET');
    $result = with_data_write(function (array &$data): array {
        $auth = authenticate($data, false);
        $baseName = normalize_filename(get_string('file'));
        $versionName = normalize_filename(get_string('version'));
        if ($baseName === '' || $versionName === '' || !is_safe_name($baseName) || !is_safe_name($versionName)) {
            throw new ApiError('invalid file', 400);
        }
        $found = false;
        foreach ($data['files'] as $file) {
            if (($file['name'] ?? '') !== $baseName) {
                continue;
            }
            foreach (($file['versions'] ?? []) as $version) {
                if (($version['file'] ?? '') === $versionName) {
                    $found = true;
                    break 2;
                }
            }
        }
        if (!$found) {
            throw new ApiError('file not found', 404);
        }
        $info = firmware_version_info($baseName, $versionName);
        if ($info === null) {
            throw new ApiError('file not found', 404);
        }
        add_token_visit($data, $auth['idx'], 'download_file', 'file=' . $baseName . ' version=' . $versionName);
        return ['path' => $info['path'], 'downloadName' => $versionName];
    });
    send_binary_file((string)$result['path'], (string)$result['downloadName'], true);
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>ESP32-C3 OTA Backend</title>
    <style>
        body { font-family: system-ui, sans-serif; margin: 1rem; line-height: 1.35; }
        h1, h2, h3 { margin: 1rem 0 .5rem; }
        input, textarea, select, button { font: inherit; margin: .15rem; max-width: 100%; }
        textarea { width: min(46rem, 95vw); height: 5rem; }
        button { cursor: pointer; }
        .card { border: 1px solid #bbb; border-radius: .5rem; padding: .75rem; margin: .7rem 0; }
        .muted { color: #666; font-size: .9em; }
        .row { display: flex; flex-wrap: wrap; gap: .5rem; align-items: center; }
        .field { margin: .2rem 0; }
        .id { font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; word-break: break-all; }
        .visitsBox { max-height: 200px; overflow: auto; padding: .25rem; }
        .visit { border: 2px solid #ddd; border-radius: .35rem; padding: .35rem; margin: .35rem 0; font-size: .9em; }
        .fresh { border-color: #21a35b; }
        .ok { color: #166534; }
        .err { color: #9f1239; }
        .editor { border-left: 3px solid #999; padding-left: .5rem; margin: .4rem 0; }
        .hidden { display: none; }
        .danger { color: #9f1239; }
        .small { font-size: .9em; }
    </style>
</head>
<body>
<h1>ESP32-C3 OTA Backend</h1>

<div id="tokenBox" class="card">
    <form id="tokenForm">
        <label>Token <input id="tokenInput" type="password" autocomplete="current-password"></label>
        <button type="submit">Token verwenden</button>
    </form>
    <div class="muted">Der Token wird als GET-Parameter an diese Seite angehängt.</div>
</div>

<div class="card">
    <div class="row">
        <button id="reloadBtn" type="button">Neu laden</button>
        <label><input id="autoReload" type="checkbox"> Auto Reload</label>
        <label>Intervall <input id="autoReloadSeconds" type="number" min="5" value="300" style="width:7rem"> Sekunden</label>
        <span id="status" class="muted"></span>
    </div>
</div>

<main id="app"></main>

<script>
(function () {
    'use strict';

    const qs = new URLSearchParams(window.location.search);
    let token = qs.get('token') || '';
    let state = null;
    let reloadTimer = null;

    const tokenBox = document.getElementById('tokenBox');
    const tokenForm = document.getElementById('tokenForm');
    const tokenInput = document.getElementById('tokenInput');
    const app = document.getElementById('app');
    const status = document.getElementById('status');
    const reloadBtn = document.getElementById('reloadBtn');
    const autoReload = document.getElementById('autoReload');
    const autoReloadSeconds = document.getElementById('autoReloadSeconds');

    function setStatus(text, cls) {
        status.textContent = text || '';
        status.className = cls || 'muted';
    }

    function el(tag, text, cls) {
        const node = document.createElement(tag);
        if (text !== undefined && text !== null) node.textContent = String(text);
        if (cls) node.className = cls;
        return node;
    }

    function clear(node) {
        while (node.firstChild) node.removeChild(node.firstChild);
    }

    function valueText(value) {
        if (value === false || value === null || value === undefined || value === '') return 'false';
        return String(value);
    }

    function urlWithToken(action) {
        return '?action=' + encodeURIComponent(action) + '&token=' + encodeURIComponent(token);
    }

    async function parseJsonResponse(response) {
        const text = await response.text();
        let json;
        try { json = JSON.parse(text); } catch (e) { throw new Error(text || 'Keine JSON-Antwort'); }
        if (!response.ok || !json.success) throw new Error(json.error || 'Aktion fehlgeschlagen');
        return json;
    }

    async function api(action, params) {
        const body = new URLSearchParams();
        Object.keys(params || {}).forEach(key => body.append(key, params[key] === false ? 'false' : String(params[key])));
        const response = await fetch(urlWithToken(action), { method: 'POST', body, cache: 'no-store' });
        return parseJsonResponse(response);
    }

    async function apiForm(action, formData) {
        const response = await fetch(urlWithToken(action), { method: 'POST', body: formData, cache: 'no-store' });
        return parseJsonResponse(response);
    }

    async function loadData() {
        if (!token) {
            tokenBox.classList.remove('hidden');
            app.textContent = '';
            setStatus('Bitte Token eingeben.', 'muted');
            return;
        }
        tokenBox.classList.add('hidden');
        setStatus('Lade ...', 'muted');
        try {
            const response = await fetch(urlWithToken('frontend_data'), { cache: 'no-store' });
            const json = await parseJsonResponse(response);
            state = json;
            render(json);
            setStatus('Geladen: ' + (json.serverISO8601 || new Date().toISOString()), 'ok');
        } catch (e) {
            state = null;
            clear(app);
            tokenBox.classList.remove('hidden');
            setStatus(e.message, 'err');
        }
    }

    function updateUrlToken(newToken) {
        token = newToken;
        const u = new URL(window.location.href);
        u.searchParams.set('token', newToken);
        u.searchParams.delete('action');
        history.replaceState(null, '', u.pathname + '?' + u.searchParams.toString());
    }

    tokenForm.addEventListener('submit', function (ev) {
        ev.preventDefault();
        const newToken = tokenInput.value.trim();
        if (!newToken) return;
        updateUrlToken(newToken);
        loadData();
    });

    reloadBtn.addEventListener('click', loadData);

    function loadAutoReloadSettings() {
        autoReload.checked = localStorage.getItem('otaAutoReloadEnabled') === '1';
        autoReloadSeconds.value = localStorage.getItem('otaAutoReloadSeconds') || '300';
        scheduleAutoReload();
    }

    function saveAutoReloadSettings() {
        localStorage.setItem('otaAutoReloadEnabled', autoReload.checked ? '1' : '0');
        localStorage.setItem('otaAutoReloadSeconds', String(Math.max(5, Number(autoReloadSeconds.value) || 300)));
        scheduleAutoReload();
    }

    function scheduleAutoReload() {
        if (reloadTimer) clearInterval(reloadTimer);
        reloadTimer = null;
        if (autoReload.checked) {
            const seconds = Math.max(5, Number(autoReloadSeconds.value) || 300);
            reloadTimer = setInterval(loadData, seconds * 1000);
        }
    }

    autoReload.addEventListener('change', saveAutoReloadSettings);
    autoReloadSeconds.addEventListener('change', saveAutoReloadSettings);

    function render(data) {
        clear(app);
        app.appendChild(renderSummary(data));
        if (data.auth && data.auth.allowedUpload) {
            app.appendChild(renderAdminTools(data));
        }
        app.appendChild(renderEsps(data));
        app.appendChild(renderFiles(data));
        app.appendChild(renderTokens(data));
    }

    function renderSummary(data) {
        const card = el('section', null, 'card');
        card.appendChild(el('h2', 'Status'));
        card.appendChild(field('Token', (data.auth.allowedUpload ? 'Admin/Upload' : 'Read-only') + ' (' + (data.auth.label || '') + ')'));
        card.appendChild(field('logLimit', data.logLimit));
        card.appendChild(field('maxKnownESP', data.maxKnownESP));
        return card;
    }

    function field(name, value) {
        const div = el('div', null, 'field');
        div.appendChild(el('strong', name + ': '));
        div.appendChild(document.createTextNode(valueText(value)));
        return div;
    }

    function renderAdminTools(data) {
        const section = el('section', null, 'card');
        section.appendChild(el('h2', 'Admin'));

        const settings = el('div', null, 'card');
        settings.appendChild(el('h3', 'Limits'));
        const logInput = el('input');
        logInput.type = 'number';
        logInput.min = '1';
        logInput.value = data.logLimit;
        const logBtn = el('button', 'logLimit speichern');
        logBtn.type = 'button';
        logBtn.addEventListener('click', async function () {
            try { await api('change_logLimit', { new_logLimit: logInput.value }); await loadData(); }
            catch (e) { setStatus(e.message, 'err'); }
        });
        settings.appendChild(el('label', 'logLimit '));
        settings.appendChild(logInput);
        settings.appendChild(logBtn);
        settings.appendChild(document.createElement('br'));

        const espInput = el('input');
        espInput.type = 'number';
        espInput.min = '0';
        espInput.value = data.maxKnownESP;
        const espBtn = el('button', 'maxKnownESP speichern');
        espBtn.type = 'button';
        espBtn.addEventListener('click', async function () {
            try { await api('change_maxKnownESP', { new_maxKnownESP: espInput.value }); await loadData(); }
            catch (e) { setStatus(e.message, 'err'); }
        });
        settings.appendChild(el('label', 'maxKnownESP '));
        settings.appendChild(espInput);
        settings.appendChild(espBtn);
        section.appendChild(settings);

        const backup = el('div', null, 'card');
        backup.appendChild(el('h3', 'Backups'));
        const backupBtn = el('button', 'Backup erstellen');
        backupBtn.type = 'button';
        backupBtn.addEventListener('click', async function () {
            try { await api('mkBackup', {}); await loadData(); }
            catch (e) { setStatus(e.message, 'err'); }
        });
        backup.appendChild(backupBtn);
        const list = el('div');
        (data.backups || []).forEach(b => list.appendChild(field(b.name, b.size + ' Bytes, ' + b.mtimeISO8601)));
        if (!(data.backups || []).length) list.appendChild(el('div', 'Keine Backups vorhanden.', 'muted'));
        backup.appendChild(list);
        section.appendChild(backup);

        const upload = el('div', null, 'card');
        upload.appendChild(el('h3', 'Firmware hochladen'));
        const form = el('form');
        const file = el('input');
        file.type = 'file';
        file.name = 'file';
        file.accept = '.bin';
        const label = el('input');
        label.name = 'label';
        label.placeholder = 'Label optional';
        const btn = el('button', 'Upload');
        btn.type = 'submit';
        form.appendChild(file);
        form.appendChild(label);
        form.appendChild(btn);
        form.addEventListener('submit', async function (ev) {
            ev.preventDefault();
            if (!file.files[0]) return;
            const fd = new FormData();
            fd.append('file', file.files[0]);
            fd.append('label', label.value);
            try { await apiForm('upload_bin', fd); form.reset(); await loadData(); }
            catch (e) { setStatus(e.message, 'err'); }
        });
        upload.appendChild(form);
        section.appendChild(upload);

        const createToken = el('div', null, 'card');
        createToken.appendChild(el('h3', 'Token erstellen'));
        const tokenSecret = el('input');
        tokenSecret.type = 'password';
        tokenSecret.placeholder = 'neuer Token';
        const tokenLabel = el('input');
        tokenLabel.placeholder = 'Label';
        const tokenAdmin = el('label');
        const tokenAdminCb = el('input');
        tokenAdminCb.type = 'checkbox';
        tokenAdmin.appendChild(tokenAdminCb);
        tokenAdmin.appendChild(document.createTextNode(' allowedUpload'));
        const createBtn = el('button', 'Token erstellen');
        createBtn.type = 'button';
        createBtn.addEventListener('click', async function () {
            try {
                await api('create_token', {
                    targetTokenSecret: tokenSecret.value,
                    Label: tokenLabel.value,
                    is_allowedUpload: tokenAdminCb.checked ? '1' : '0'
                });
                tokenSecret.value = '';
                tokenLabel.value = '';
                tokenAdminCb.checked = false;
                await loadData();
            } catch (e) { setStatus(e.message, 'err'); }
        });
        createToken.appendChild(tokenSecret);
        createToken.appendChild(tokenLabel);
        createToken.appendChild(tokenAdmin);
        createToken.appendChild(createBtn);
        section.appendChild(createToken);

        return section;
    }

    function labelHeader(type, id, ident, label, canEdit) {
        const wrap = el('div', null, 'row');
        const title = el('strong', null, 'id');
        title.textContent = ident + ' (' + valueText(label) + ')';
        wrap.appendChild(title);
        if (canEdit) {
            const edit = el('button', '✏️');
            edit.type = 'button';
            edit.title = 'Label bearbeiten';
            edit.addEventListener('click', function () { openLabelEditor(wrap, type, id, label); });
            wrap.appendChild(edit);
        }
        return wrap;
    }

    function openLabelEditor(parent, type, id, oldLabel) {
        const old = parent.querySelector('.editor');
        if (old) { old.remove(); return; }
        const editor = el('div', null, 'editor');
        const ta = el('textarea');
        ta.value = oldLabel || '';
        const save = el('button', 'Speichern');
        save.type = 'button';
        save.addEventListener('click', async function () {
            try { await api('set_label', { type, id, label: ta.value }); await loadData(); }
            catch (e) { setStatus(e.message, 'err'); }
        });
        editor.appendChild(ta);
        editor.appendChild(document.createElement('br'));
        editor.appendChild(save);
        parent.appendChild(editor);
    }

    function firmwareOptions(files, current) {
        const options = [{ value: 'false', label: 'false' }];
        (files || []).forEach(file => {
            options.push({ value: '*' + file.name, label: '*' + file.name + ' (neueste Version)' });
            (file.versions || []).forEach(v => options.push({ value: v.file, label: v.file }));
        });
        if (current !== false && current !== null && current !== undefined && current !== '' && !options.some(o => o.value === String(current))) {
            options.push({ value: String(current), label: String(current) + ' (nicht gefunden)' });
        }
        return options;
    }

    function renderEsps(data) {
        const section = el('section');
        section.appendChild(el('h2', 'ESPs'));
        const entries = Object.entries(data.esps || {}).sort((a, b) => a[0].localeCompare(b[0]));
        if (!entries.length) section.appendChild(el('div', 'Keine ESPs bekannt.', 'muted'));
        entries.forEach(([mac, esp]) => {
            const card = el('div', null, 'card');
            const head = labelHeader('esp', mac, mac, esp.label, data.auth.allowedUpload);
            if (data.auth.allowedUpload) {
                const del = el('button', '🗑️');
                del.type = 'button';
                del.title = 'ESP löschen';
                del.addEventListener('click', async function () {
                    if (!window.confirm('Sind Sie sicher? ESP ' + mac + ' löschen?')) return;
                    try { await api('delete_esp32', { macadress: mac }); await loadData(); }
                    catch (e) { setStatus(e.message, 'err'); }
                });
                head.appendChild(del);
            }
            card.appendChild(head);
            card.appendChild(field('lastSeen', esp.lastSeenISO8601 || esp.lastSeenT));
            card.appendChild(field('lastFirmware', esp.lastFirmware));
            card.appendChild(field('lastFirmwareDownload', esp.lastFirmwareDownloadISO8601 || esp.lastFirmwareDownloadT));

            if (data.auth.allowedUpload) {
                const row = el('div', null, 'field');
                row.appendChild(el('strong', 'nextFirmwareFile: '));
                const select = el('select');
                firmwareOptions(data.files, esp.nextFirmwareFile).forEach(opt => {
                    const option = el('option', opt.label);
                    option.value = opt.value;
                    if (opt.value === valueText(esp.nextFirmwareFile)) option.selected = true;
                    select.appendChild(option);
                });
                select.addEventListener('change', async function () {
                    try { await api('set_esp_firmware', { macadress: mac, nextFirmwareFile: select.value }); await loadData(); }
                    catch (e) { setStatus(e.message, 'err'); }
                });
                row.appendChild(select);
                card.appendChild(row);
            } else {
                card.appendChild(field('nextFirmwareFile', esp.nextFirmwareFile));
            }
            card.appendChild(renderVisits(esp.recentVisits || []));
            section.appendChild(card);
        });
        return section;
    }

    function renderFiles(data) {
        const section = el('section');
        section.appendChild(el('h2', 'Firmware-Dateien'));
        if (!(data.files || []).length) section.appendChild(el('div', 'Keine Firmware-Dateien vorhanden.', 'muted'));
        (data.files || []).forEach(file => {
            const card = el('div', null, 'card');
            card.appendChild(labelHeader('file', file.name, file.name, file.label, data.auth.allowedUpload));
            (file.versions || []).forEach(v => {
                const version = el('div', null, 'card small');
                version.appendChild(labelHeader('fileversion', file.name + '||' + v.file, v.file, v.label, data.auth.allowedUpload));
                const dl = el('button', 'Download');
                dl.type = 'button';
                dl.addEventListener('click', function () { downloadVersion(file.name, v.file); });
                version.appendChild(dl);
                card.appendChild(version);
            });
            section.appendChild(card);
        });
        return section;
    }

    async function downloadVersion(file, version) {
        try {
            const response = await fetch(urlWithToken('download_file') + '&file=' + encodeURIComponent(file) + '&version=' + encodeURIComponent(version), { cache: 'no-store' });
            const ct = response.headers.get('Content-Type') || '';
            if (!response.ok || ct.indexOf('application/json') !== -1) {
                const json = await response.json().catch(() => ({ error: 'Download fehlgeschlagen' }));
                throw new Error(json.error || 'Download fehlgeschlagen');
            }
            const blob = await response.blob();
            const url = URL.createObjectURL(blob);
            const a = el('a');
            a.href = url;
            a.download = version;
            document.body.appendChild(a);
            a.click();
            setTimeout(function () { URL.revokeObjectURL(url); a.remove(); }, 1000);
        } catch (e) { setStatus(e.message, 'err'); }
    }

    function renderTokens(data) {
        const section = el('section');
        section.appendChild(el('h2', 'Tokens'));
        (data.allowedTokens || []).forEach((tok, idx) => {
            const id = tok.hash || ('Token #' + (idx + 1));
            const shownId = data.auth.allowedUpload ? id : (tok.isCurrent ? 'eigener Token' : 'Token');
            const card = el('div', null, 'card');
            card.appendChild(labelHeader('token', id, shownId, tok.label, data.auth.allowedUpload && !!tok.hash));
            card.appendChild(field('allowedUpload', tok.allowedUpload ? 'true' : 'false'));

            if ((data.auth.allowedUpload || tok.isCurrent) && tok.hash) {
                const change = el('div', null, 'field');
                const inp = el('input');
                inp.type = 'password';
                inp.placeholder = 'neuer Token';
                const btn = el('button', 'Token ändern');
                btn.type = 'button';
                btn.addEventListener('click', async function () {
                    if (!inp.value) return;
                    try {
                        const res = await api('change_token', { targetTokenHash: tok.hash, newToken: inp.value });
                        if (res.changedCurrentToken) updateUrlToken(inp.value);
                        inp.value = '';
                        await loadData();
                    } catch (e) { setStatus(e.message, 'err'); }
                });
                change.appendChild(inp);
                change.appendChild(btn);
                card.appendChild(change);
            }

            if (data.auth.allowedUpload && tok.hash) {
                const del = el('button', 'Token löschen');
                del.type = 'button';
                del.className = 'danger';
                del.addEventListener('click', async function () {
                    if (!window.confirm('Sind Sie sicher? Token löschen?')) return;
                    try {
                        const res = await api('delete_token', { targetTokenHash: tok.hash });
                        if (res.deletedCurrentToken) {
                            token = '';
                            const u = new URL(window.location.href);
                            u.searchParams.delete('token');
                            history.replaceState(null, '', u.pathname + (u.search ? '?' + u.searchParams.toString() : ''));
                        }
                        await loadData();
                    } catch (e) { setStatus(e.message, 'err'); }
                });
                card.appendChild(del);
            }
            card.appendChild(renderVisits(tok.recentVisits || []));
            section.appendChild(card);
        });
        return section;
    }

    function renderVisits(visits) {
        const details = el('details');
        const summary = el('summary', 'recentVisits (' + visits.length + ')');
        details.appendChild(summary);
        const box = el('div', null, 'visitsBox');
        visits.slice().reverse().forEach(v => {
            const item = el('div', null, 'visit');
            markVisitAge(item, v);
            Object.keys(v).forEach(key => {
                const line = el('div');
                line.appendChild(el('strong', key + ': '));
                line.appendChild(document.createTextNode(valueText(v[key])));
                item.appendChild(line);
            });
            box.appendChild(item);
        });
        details.appendChild(box);
        return details;
    }

    function markVisitAge(node, visit) {
        let t = Number(visit.t || 0);
        if (!t && visit.tISO8601) t = Date.parse(visit.tISO8601);
        if (!t) return;
        let age = Date.now() - t;
        if (age < 0) age = 0;
        const five = 5 * 60 * 1000;
        const day = 24 * 60 * 60 * 1000;
        if (age < five) {
            node.classList.add('fresh');
        } else if (age < day) {
            const r = Math.max(0, Math.min(1, (age - five) / (day - five)));
            const g = [33, 163, 91];
            const b = [37, 99, 235];
            const c = g.map((x, i) => Math.round(x + (b[i] - x) * r));
            node.style.borderColor = 'rgb(' + c.join(',') + ')';
        }
    }

    loadAutoReloadSettings();
    loadData();
})();
</script>
</body>
</html>