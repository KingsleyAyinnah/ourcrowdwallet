<?php
/**
 * OURCR ONLINE - Global Helper Functions
 * Pure utility functions with no side effects
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

// ─── String Helpers ──────────────────────────────────────────────────────────

/**
 * Generate a cryptographically secure UUID v4
 */
function generateUUID(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Generate a secure random token
 */
function generateToken(int $length = 64): string
{
    return bin2hex(random_bytes($length / 2));
}

/**
 * Generate a random alphanumeric code
 */
function generateCode(int $length = 8): string
{
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code  = '';
    $max   = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/**
 * Generate a numeric OTP
 */
function generateOTP(int $length = 6): string
{
    $min = (int) str_pad('1', $length, '0');
    $max = (int) str_pad('9', $length, '9');
    return str_pad((string) random_int($min, $max), $length, '0', STR_PAD_LEFT);
}

/**
 * Generate a unique transaction reference
 */
function generateTxnRef(string $prefix = 'OURCR'): string
{
    return strtoupper($prefix) . date('ymdHis') . strtoupper(generateCode(6));
}

/**
 * Generate a unique VTpass request ID
 */
function generateVtpassRequestId(): string
{
    return date('YmdHis') . random_int(1000, 9999);
}

/**
 * Slugify a string
 */
function slugify(string $text): string
{
    $text = preg_replace('~[^\pL\d]+~u', '-', $text);
    $text = iconv('utf-8', 'us-ascii//TRANSLIT', $text);
    $text = preg_replace('~[^-\w]+~', '', $text);
    $text = trim($text, '-');
    $text = preg_replace('~-+~', '-', $text);
    return strtolower($text ?: 'n-a');
}

/**
 * Truncate string with ellipsis
 */
function truncate(string $text, int $length = 100, string $suffix = '...'): string
{
    if (mb_strlen($text) <= $length) {
        return $text;
    }
    return mb_substr($text, 0, $length - mb_strlen($suffix)) . $suffix;
}

/**
 * Mask sensitive strings (e.g. account numbers, emails)
 */
function maskString(string $value, int $visibleStart = 3, int $visibleEnd = 3): string
{
    $len = strlen($value);
    if ($len <= $visibleStart + $visibleEnd) {
        return str_repeat('*', $len);
    }
    $masked = substr($value, 0, $visibleStart)
        . str_repeat('*', $len - $visibleStart - $visibleEnd)
        . substr($value, -$visibleEnd);
    return $masked;
}

/**
 * Mask email address
 */
function maskEmail(string $email): string
{
    [$user, $domain] = explode('@', $email, 2);
    return maskString($user, 2, 1) . '@' . $domain;
}

/**
 * Mask phone number
 */
function maskPhone(string $phone): string
{
    return maskString($phone, 4, 2);
}

// ─── Money Helpers ────────────────────────────────────────────────────────────

/**
 * Format a byte count into a human-readable size string (KB, MB, GB…)
 */
function formatBytes(int|float $bytes, int $precision = 2): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max(0, (float) $bytes);
    $pow   = $bytes > 0 ? floor(log($bytes, 1024)) : 0;
    $pow   = min($pow, count($units) - 1);
    return round($bytes / pow(1024, $pow), $precision) . ' ' . $units[$pow];
}

/**
 * Format a monetary amount with the currency symbol
 */
function formatMoney(float|string $amount, bool $symbol = true): string
{
    $formatted = number_format((float) $amount, 2, '.', ',');
    return $symbol ? (APP_CURRENCY_SYMBOL . $formatted) : $formatted;
}

/**
 * Parse a formatted money string back to float
 */
function parseMoney(string $amount): float
{
    return (float) str_replace([APP_CURRENCY_SYMBOL, ','], '', $amount);
}

// ─── Date Helpers ────────────────────────────────────────────────────────────

/**
 * Format a datetime string or timestamp
 */
function formatDate(string|int|null $date, string $format = DATE_FORMAT): string
{
    if (empty($date)) {
        return 'N/A';
    }
    $ts = is_numeric($date) ? (int) $date : strtotime($date);
    if ($ts === false) {
        return 'N/A';
    }
    return date($format, $ts);
}

/**
 * Format datetime
 */
function formatDateTime(string|int|null $date): string
{
    return formatDate($date, DATETIME_FORMAT);
}

/**
 * Get human-readable time difference (time ago)
 */
function timeAgo(string|int $date): string
{
    $ts   = is_numeric($date) ? (int) $date : strtotime($date);
    $diff = time() - $ts;

    if ($diff < 60) {
        return 'just now';
    } elseif ($diff < 3600) {
        $m = floor($diff / 60);
        return $m . ' minute' . ($m > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 86400) {
        $h = floor($diff / 3600);
        return $h . ' hour' . ($h > 1 ? 's' : '') . ' ago';
    } elseif ($diff < 604800) {
        $d = floor($diff / 86400);
        return $d . ' day' . ($d > 1 ? 's' : '') . ' ago';
    } else {
        return date(DATE_FORMAT, $ts);
    }
}

// ─── Array / Input Helpers ───────────────────────────────────────────────────

/**
 * Safely get value from $_POST
 */
function post(string $key, mixed $default = ''): mixed
{
    return $_POST[$key] ?? $default;
}

/**
 * Safely get value from $_GET
 */
function get(string $key, mixed $default = ''): mixed
{
    return $_GET[$key] ?? $default;
}

/**
 * Safely get value from $_SERVER
 */
function server(string $key, mixed $default = ''): mixed
{
    return $_SERVER[$key] ?? $default;
}

/**
 * Get the client IP address
 */
function getClientIP(): string
{
    $keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare real visitor IP
        'HTTP_CLIENT_IP',
        'HTTP_X_FORWARDED_FOR',
        'HTTP_X_REAL_IP',
        'REMOTE_ADDR',
    ];
    foreach ($keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = trim(explode(',', $_SERVER[$key])[0]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get the current page URL
 */
function currentUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '') . ($_SERVER['REQUEST_URI'] ?? '');
}

// ─── Output / Sanitization Helpers ───────────────────────────────────────────

/**
 * Escape HTML output (always use for user data in HTML context)
 */
function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Output escaped string directly
 */
function out(mixed $value): void
{
    echo e($value);
}

/**
 * Check if request is AJAX
 */
function isAjax(): bool
{
    return isset($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

/**
 * Check if request is POST
 */
function isPost(): bool
{
    return $_SERVER['REQUEST_METHOD'] === 'POST';
}

/**
 * Return JSON response and exit
 */
function jsonResponse(array $data, int $statusCode = 200): never
{
    http_response_code($statusCode);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/**
 * Redirect to a URL
 */
function redirect(string $url, bool $permanent = false): never
{
    if ($permanent) {
        header('HTTP/1.1 301 Moved Permanently');
    }
    header('Location: ' . $url);
    exit;
}

/**
 * Redirect to a named route
 */
function redirectTo(string $route): never
{
    redirect(APP_URL . '/' . ltrim($route, '/'));
}

/**
 * Check if value is a valid Nigerian phone number
 */
function isValidPhone(string $phone): bool
{
    return (bool) preg_match('/^(\+?234|0)[789][01]\d{8}$/', preg_replace('/\s+/', '', $phone));
}

/**
 * Normalize phone number to 11-digit local format
 */
function normalizePhone(string $phone): string
{
    $phone = preg_replace('/\s+/', '', $phone);
    if (str_starts_with($phone, '+234')) {
        return '0' . substr($phone, 4);
    }
    if (str_starts_with($phone, '234') && strlen($phone) === 13) {
        return '0' . substr($phone, 3);
    }
    return $phone;
}

/**
 * Get network name from phone number
 */
function getNetwork(string $phone): string
{
    $phone = normalizePhone($phone);
    $prefix = substr($phone, 0, 4);

    $networks = [
        'mtn'      => ['0703','0706','0803','0806','0813','0814','0816','0903','0906','0913','0916'],
        'airtel'   => ['0701','0708','0802','0808','0812','0902','0907','0901','0904'],
        'glo'      => ['0705','0805','0807','0811','0815','0905','0915'],
        'etisalat' => ['0809','0817','0818','0908','0909'],
    ];

    foreach ($networks as $network => $prefixes) {
        if (in_array($prefix, $prefixes, true)) {
            return $network;
        }
    }
    return 'unknown';
}

/**
 * Convert hex color to RGB components (for CSS rgba usage)
 */
function hexToRgb(string $hex): string
{
    $hex = ltrim($hex, '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
    }
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return "$r, $g, $b";
}

/**
 * Get CBN bank code by name
 */
function getBankCodeByName(string $bankName): string
{
    $map = [
        'access bank'                               => '044',
        'citibank'                                  => '023',
        'ecobank'                                   => '050',
        'fidelity bank'                             => '070',
        'first bank'                                => '011',
        'first bank of nigeria'                     => '011',
        'first city monument bank'                  => '214',
        'first city monument bank (fcmb)'           => '214',
        'fcmb'                                      => '214',
        'globus bank'                               => '103',
        'guaranty trust bank'                       => '058',
        'guaranty trust bank (gtbank)'              => '058',
        'gtbank'                                    => '058',
        'heritage bank'                             => '030',
        'keystone bank'                             => '082',
        'lotus bank'                                => '303',
        'moniepoint'                                => '090405',
        'moniepoint mfb'                            => '090405',
        'moniepoint microfinance bank'              => '090405',
        'opay'                                      => '100004',
        'opay (digital wallet)'                     => '100004',
        'opay digital services limited'             => '100004',
        'paycom'                                    => '100004',
        'optimus bank'                              => '107',
        'palmpay'                                   => '100033',
        'palmpay limited'                           => '100033',
        'paragon mfb'                               => '51237',
        'polaris bank'                              => '076',
        'premiumtrust bank'                         => '105',
        'providus bank'                             => '101',
        'signature bank'                            => '106',
        'stanbic ibtc'                              => '221',
        'stanbic ibtc bank'                         => '221',
        'standard chartered bank'                   => '068',
        'sterling bank'                             => '232',
        'suntrust bank'                             => '100',
        'taj bank'                                  => '302',
        'titan trust bank'                          => '102',
        'union bank'                                => '032',
        'union bank of nigeria'                     => '032',
        'united bank for africa'                    => '033',
        'united bank for africa (uba)'              => '033',
        'uba'                                       => '033',
        'unity bank'                                => '215',
        'wema bank'                                 => '035',
        'zenith bank'                               => '057',
        'kuda bank'                                 => '090267',
        'kuda microfinance bank'                    => '090267',
        'rubies mfb'                                => '090175',
        'vfd microfinance bank'                     => '090110',
        'carbon'                                    => '100026',
        'fairmoney mfb'                             => '090551',
        'fairmoney microfinance bank'               => '090551'
    ];

    $key = trim(strtolower($bankName));
    return $map[$key] ?? '';
}

/**
 * Get GTBank GAPS 9-digit Head Office sort code by bank name
 */
function getBankSortCodeByName(string $bankName): string
{
    $map = [
        'access bank'                      => '044150291',
        'citibank'                         => '023150005',
        'ecobank'                          => '050150010',
        'fidelity bank'                    => '070150003',
        'first bank'                       => '011151003',
        'first bank of nigeria'            => '011151003',
        'first city monument bank'         => '214150018',
        'first city monument bank (fcmb)'  => '214150018',
        'fcmb'                             => '214150018',
        'globus bank'                      => '103150103',
        'guaranty trust bank'              => '058152052',
        'guaranty trust bank (gtbank)'     => '058152052',
        'gtbank'                           => '058152052',
        'heritage bank'                    => '030150014',
        'keystone bank'                    => '082150017',
        'kuda bank'                        => '090267001',
        'kuda microfinance bank'           => '090267001',
        '090267'                           => '090267001',
        'lotus bank'                       => '303150303',
        '303'                              => '303150303',
        'moniepoint'                       => '090405001',
        'moniepoint mfb'                   => '090405001',
        'moniepoint microfinance bank'     => '090405001',
        '090405'                           => '090405001',
        'opay'                             => '305150305',
        'opay (digital wallet)'            => '305150305',
        'opay digital services limited'    => '305150305',
        'paycom'                           => '305150305',
        '100004'                           => '305150305',
        '305'                              => '305150305',
        'optimus bank'                     => '028150028',
        'palmpay'                          => '100033001',
        'palmpay limited'                  => '100033001',
        '100033'                           => '100033001',
        'paragon mfb'                      => '090393001',
        'polaris bank'                     => '076151365',
        'premiumtrust bank'                => '105150105',
        'providus bank'                    => '101152019',
        'signature bank'                   => '106150106',
        'stanbic ibtc'                     => '221159522',
        'stanbic ibtc bank'                => '221159522',
        'standard chartered bank'          => '068150015',
        'sterling bank'                    => '232150016',
        'suntrust bank'                    => '100152049',
        'taj bank'                         => '302150302',
        'titan trust bank'                 => '102150102',
        'union bank'                       => '032154568',
        'union bank of nigeria'            => '032154568',
        'united bank for africa'           => '033152048',
        'united bank for africa (uba)'     => '033152048',
        'uba'                              => '033152048',
        'unity bank'                       => '215082334',
        'vfd microfinance bank'            => '090110001',
        '090110'                           => '090110001',
        'wema bank'                        => '035150103',
        'zenith bank'                      => '057150013',
        'carbon'                           => '100026001',
        '100026'                           => '100026001',
        'fairmoney mfb'                    => '090551001',
        'fairmoney microfinance bank'      => '090551001',
        '090551'                           => '090551001',
        'rubies mfb'                       => '090175001',
        '090175'                           => '090175001',
    ];

    $key = trim(strtolower($bankName));
    if (isset($map[$key])) {
        return $map[$key];
    }

    $clean = preg_replace('/[^a-z0-9]/', '', $key);
    foreach ($map as $k => $sort) {
        if (preg_replace('/[^a-z0-9]/', '', $k) === $clean) {
            return $sort;
        }
    }

    if (strlen($bankName) === 9 && ctype_digit($bankName)) {
        return $bankName;
    }

    $cbn = getBankCodeByName($bankName);
    if (empty($cbn) && ctype_digit($bankName)) {
        $cbn = $bankName;
    }

    if (!empty($cbn) && isset($map[$cbn])) {
        return $map[$cbn];
    }

    if (strlen($cbn) === 9 && ctype_digit($cbn)) {
        return $cbn;
    }

    if (strlen($cbn) === 6 && ctype_digit($cbn)) {
        return $cbn . '001';
    }

    if (strlen($cbn) === 3 && ctype_digit($cbn)) {
        return $cbn . '150' . $cbn;
    }

    return '058152052';
}

/**
 * Check if the request is served over HTTPS
 */
function isHttps(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
}

/**
 * Get unified visual icons, labels, colors, and badge background for transaction categories
 */
function getCategoryVisuals(string $cat): array
{
    return match (strtolower(trim($cat))) {
        'deposit' => [
            'icon'  => 'fas fa-arrow-down-left',
            'label' => 'Deposit',
            'bg'    => 'rgba(16, 185, 129, 0.12)',
            'color' => '#10b981',
        ],
        'withdrawal' => [
            'icon'  => 'fas fa-arrow-up-right',
            'label' => 'Withdrawal',
            'bg'    => 'rgba(239, 68, 68, 0.12)',
            'color' => '#ef4444',
        ],
        'transfer' => [
            'icon'  => 'fas fa-paper-plane',
            'label' => 'Transfer',
            'bg'    => 'rgba(99, 102, 241, 0.12)',
            'color' => '#6366f1',
        ],
        'airtime' => [
            'icon'  => 'fas fa-phone-alt',
            'label' => 'Airtime',
            'bg'    => 'rgba(245, 158, 11, 0.12)',
            'color' => '#f59e0b',
        ],
        'data' => [
            'icon'  => 'fas fa-wifi',
            'label' => 'Data Bundle',
            'bg'    => 'rgba(14, 165, 233, 0.12)',
            'color' => '#0ea5e9',
        ],
        'cable_tv', 'cable', 'tv' => [
            'icon'  => 'fas fa-tv',
            'label' => 'Cable TV',
            'bg'    => 'rgba(168, 85, 247, 0.12)',
            'color' => '#a855f7',
        ],
        'electricity', 'electric', 'power' => [
            'icon'  => 'fas fa-bolt',
            'label' => 'Electricity',
            'bg'    => 'rgba(234, 179, 8, 0.15)',
            'color' => '#ca8a04',
        ],
        'betting', 'bet' => [
            'icon'  => 'fas fa-futbol',
            'label' => 'Betting Funding',
            'bg'    => 'rgba(20, 184, 166, 0.12)',
            'color' => '#14b8a6',
        ],
        'exam_pin', 'exam', 'waec', 'jamb', 'neco' => [
            'icon'  => 'fas fa-graduation-cap',
            'label' => 'Exam Pin',
            'bg'    => 'rgba(236, 72, 153, 0.12)',
            'color' => '#ec4899',
        ],
        'referral_bonus', 'bonus' => [
            'icon'  => 'fas fa-gift',
            'label' => 'Referral Bonus',
            'bg'    => 'rgba(16, 185, 129, 0.12)',
            'color' => '#10b981',
        ],
        default => [
            'icon'  => 'fas fa-receipt',
            'label' => ucfirst(str_replace('_', ' ', $cat)),
            'bg'    => 'rgba(107, 114, 128, 0.12)',
            'color' => '#6b7280',
        ],
    };
}

