<?php
namespace Ourcr;

use Database;

/**
 * OURCR ONLINE - VTpass API Client
 *
 * Handles all VAS (Value-Added Services) purchases via the VTpass platform:
 * Airtime, Data, Cable TV, Electricity, Betting, Exam PINs.
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');



/**
 * VTpass API Client
 *
 * Authentication: HTTP Basic Auth (username:password → Base64)
 * Additional headers: public-key and secret-key
 *
 * All public methods return a normalized response array:
 * [
 *   'success'   => bool,
 *   'message'   => string,
 *   'code'      => string,  // '000' = success
 *   'reference' => string,
 *   'token'     => string|null,
 *   'data'      => array
 * ]
 */
class VTpass
{
    /** VTpass response codes that indicate a successful transaction */
    private const SUCCESS_CODES = ['000'];

    /** VTpass response codes that indicate a transaction in progress (ambiguous) */
    private const PENDING_CODES = ['099'];

    /** VTpass response codes that indicate a definitive failure (do not retry) */
    private const FAILED_CODES  = ['016', '011', '012', '022', '023', '024', '025', '301'];

    // ─── Authentication ───────────────────────────────────────────────────────

    private static function getEnv(): string
    {
        return setting('vtpass_env', defined('VTPASS_ENV') ? VTPASS_ENV : 'sandbox');
    }

    private static function getApiKey(): string
    {
        $env = self::getEnv();
        $key = ($env === 'live') ? 'vtpass_live_api_key' : 'vtpass_sandbox_api_key';
        return setting($key, setting('vtpass_api_key', defined('VTPASS_API_KEY') ? VTPASS_API_KEY : ''));
    }

    private static function getPublicKey(): string
    {
        $env = self::getEnv();
        $key = ($env === 'live') ? 'vtpass_live_public_key' : 'vtpass_sandbox_public_key';
        return setting($key, setting('vtpass_public_key', defined('VTPASS_PUBLIC_KEY') ? VTPASS_PUBLIC_KEY : ''));
    }

    private static function getSecretKey(): string
    {
        $env = self::getEnv();
        $key = ($env === 'live') ? 'vtpass_live_secret_key' : 'vtpass_sandbox_secret_key';
        return setting($key, setting('vtpass_secret_key', defined('VTPASS_SECRET_KEY') ? VTPASS_SECRET_KEY : ''));
    }

    /**
     * Build the full set of HTTP headers for a VTpass API request.
     *
     * @return string[] Array of "Header-Name: Value" strings for cURL
     */
    private static function buildHeaders(): array
    {
        return [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: '    . self::getApiKey(),
            'public-key: ' . self::getPublicKey(),
            'secret-key: ' . self::getSecretKey(),
        ];
    }

    // ─── Core HTTP Request ────────────────────────────────────────────────────

    /**
     * Make an authenticated HTTP request to VTpass.
     *
     * Supports GET and POST. Implements exponential back-off retry for
     * connection-level failures only (not for 4xx/5xx business errors).
     *
     * @param string $endpoint Full URL of the VTpass endpoint
     * @param array  $payload  Request parameters (POST body or GET query)
     * @param string $method   'POST' or 'GET'
     * @return array Raw decoded VTpass response, or error envelope
     */
    private static function request(
        string $endpoint,
        array  $payload = [],
        string $method  = 'POST'
    ): array {
        // Extend PHP max execution time for VTU API calls to prevent fatal timeouts
        // This is safe because VTpass calls are intentionally long-running
        set_time_limit(120);

        $headers   = self::buildHeaders();
        $attempt   = 0;
        $lastError = '';
        $method    = strtoupper($method);

        while ($attempt < VTPASS_MAX_RETRIES) {
            $attempt++;

            try {
                $ch = curl_init();
                $connectTimeout = defined('VTPASS_CONNECT_TIMEOUT') ? VTPASS_CONNECT_TIMEOUT : 10;
                $curlOpts = [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => VTPASS_TIMEOUT,
                    CURLOPT_CONNECTTIMEOUT => $connectTimeout,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_SSL_VERIFYPEER => true,
                    CURLOPT_SSL_VERIFYHOST => 2,
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS      => 3,
                ];


                if ($method === 'POST') {
                    $curlOpts[CURLOPT_URL]        = $endpoint;
                    $curlOpts[CURLOPT_POST]        = true;
                    $curlOpts[CURLOPT_POSTFIELDS]  = json_encode($payload, JSON_UNESCAPED_UNICODE);
                } else {
                    // GET — append query string
                    $url = empty($payload)
                        ? $endpoint
                        : $endpoint . (str_contains($endpoint, '?') ? '&' : '?') . http_build_query($payload);
                    $curlOpts[CURLOPT_URL]        = $url;
                    $curlOpts[CURLOPT_HTTPGET]     = true;
                }

                curl_setopt_array($ch, $curlOpts);

                $rawResponse = curl_exec($ch);
                $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlError   = curl_error($ch);
                $curlErrno   = curl_errno($ch);
                curl_close($ch);

                // cURL-level network error — log and retry once
                if ($curlErrno !== 0) {
                    $lastError = "cURL error [{$curlErrno}]: {$curlError}";
                    writeLog(LOG_CHAN_VTPASS, 'warning', "VTpass request attempt {$attempt} failed: {$lastError}", [
                        'endpoint' => $endpoint,
                    ]);
                    // No sleep — avoid PHP max_execution_time timeout
                    continue;
                }

                if ($rawResponse === false || $rawResponse === '') {
                    $lastError = "Empty response from VTpass (HTTP {$httpCode})";
                    writeLog(LOG_CHAN_VTPASS, 'warning', $lastError, ['endpoint' => $endpoint]);
                    // No sleep — avoid PHP max_execution_time timeout
                    continue;
                }

                $decoded = json_decode($rawResponse, associative: true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
                    $lastError = 'VTpass returned invalid JSON or non-array: ' . (json_last_error() !== JSON_ERROR_NONE ? json_last_error_msg() : gettype($decoded));
                    writeLog(LOG_CHAN_VTPASS, 'error', $lastError, [
                        'endpoint'     => $endpoint,
                        'raw_response' => substr($rawResponse, 0, 500),
                    ]);
                    // No sleep — avoid PHP max_execution_time timeout
                    continue;
                }

                return $decoded;

            } catch (\Throwable $e) {
                $lastError = $e->getMessage();
                writeLog(LOG_CHAN_VTPASS, 'error', "VTpass request exception (attempt {$attempt}): {$lastError}", [
                    'endpoint' => $endpoint,
                    'trace'    => $e->getTraceAsString(),
                ]);
                // No sleep — avoid PHP max_execution_time timeout
            }
        }

        // All retries exhausted — return a structured failure envelope
        writeLog(LOG_CHAN_VTPASS, 'error', "VTpass all {$attempt} attempts failed: {$lastError}", [
            'endpoint' => $endpoint,
        ]);

        return [
            'code'             => 'NETWORK_ERROR',
            'response_description' => "Connection failed after {$attempt} attempt(s): {$lastError}",
        ];
    }

    // ─── Airtime ──────────────────────────────────────────────────────────────

    /**
     * Purchase airtime for any Nigerian network.
     *
     * @param string $serviceId   Network identifier: 'mtn', 'airtel', 'glo', 'etisalat'
     * @param string $phone       Recipient phone number (11-digit Nigerian format)
     * @param float  $amount      Airtime amount in Naira
     * @param string $requestId   Unique idempotency ID (use generateVtpassRequestId())
     * @return array Normalized response
     */
    public static function buyAirtime(
        string $serviceId,
        string $phone,
        float  $amount,
        string $requestId
    ): array {
        $payload = [
            'request_id'  => $requestId,
            'serviceID'   => $serviceId,
            'amount'      => $amount,
            'phone'       => $phone,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        self::logCall('buyAirtime', $payload, $response);

        return $response;
    }

    // ─── Data ─────────────────────────────────────────────────────────────────

    /**
     * Purchase a mobile data bundle.
     *
     * @param string $serviceId     Data service identifier: 'mtn-data', 'airtel-data', etc.
     * @param string $variationCode Bundle variation code (from getVariations())
     * @param string $phone         Recipient phone number
     * @param float  $amount        Bundle amount in Naira
     * @param string $requestId     Unique idempotency ID
     * @return array Normalized response
     */
    public static function buyData(
        string $serviceId,
        string $variationCode,
        string $phone,
        float  $amount,
        string $requestId
    ): array {
        $payload = [
            'request_id'     => $requestId,
            'serviceID'      => $serviceId,
            'billersCode'    => $phone,
            'variation_code' => $variationCode,
            'amount'         => $amount,
            'phone'          => $phone,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        self::logCall('buyData', $payload, $response);

        return $response;
    }

    // ─── Cable TV ─────────────────────────────────────────────────────────────

    /**
     * Verify a cable TV smart card number before subscription.
     *
     * @param string $serviceId    Cable service: 'dstv', 'gotv', 'startimes'
     * @param string $smartCardNo  Smart card / IUC / decoder number
     * @return array Normalized response; 'data' contains subscriber details
     */
    public static function verifySmartCard(
        string $serviceId,
        string $smartCardNo
    ): array {
        $payload = [
            'serviceID'   => $serviceId,
            'billersCode' => $smartCardNo,
            'type'        => $serviceId,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_VERIFY_CABLE, $payload, 'POST');
        $response = self::normalizeResponse($raw);

        // Merchant-verify returns customer data in 'content' directly (not in transactions)
        if ($response['success'] && empty($response['data']) && !empty($raw['content'])) {
            $response['data'] = $raw['content'];
        }

        self::logCall('verifySmartCard', $payload, $response);

        return $response;
    }

    /**
     * Subscribe / renew a cable TV package.
     *
     * @param string $serviceId     Cable service identifier
     * @param string $variationCode Subscription package code
     * @param string $smartCardNo   Smart card / IUC / decoder number
     * @param string $phone         Subscriber phone number
     * @param float  $amount        Subscription amount in Naira
     * @param string $requestId     Unique idempotency ID
     * @param int    $quantity      Number of months (default 1)
     * @return array Normalized response
     */
    public static function subscribeCable(
        string $serviceId,
        string $variationCode,
        string $smartCardNo,
        string $phone,
        float  $amount,
        string $requestId,
        int    $quantity = 1
    ): array {
        $payload = [
            'request_id'     => $requestId,
            'serviceID'      => $serviceId,
            'billersCode'    => $smartCardNo,
            'variation_code' => $variationCode,
            'amount'         => $amount,
            'phone'          => $phone,
            'subscription_type' => 'change',
            'quantity'       => $quantity,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        self::logCall('subscribeCable', $payload, $response);

        return $response;
    }

    // ─── Electricity ──────────────────────────────────────────────────────────

    /**
     * Verify an electricity meter number before token purchase.
     *
     * @param string $serviceId  Electricity disco: 'ikeja-electric', 'eko-electric', etc.
     * @param string $meterNo    Meter number
     * @param string $meterType  'prepaid' or 'postpaid'
     * @return array Normalized response; 'data' contains meter details and customer name
     */
    public static function verifyMeter(
        string $serviceId,
        string $meterNo,
        string $meterType = 'prepaid'
    ): array {
        $payload = [
            'serviceID'   => $serviceId,
            'billersCode' => $meterNo,
            'type'        => $meterType,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_VERIFY_METER, $payload, 'POST');
        $response = self::normalizeResponse($raw);

        // Merchant-verify returns customer data in 'content' directly (not in transactions)
        // Merge it into data if normalizeResponse didn't capture it
        if ($response['success'] && empty($response['data']) && !empty($raw['content'])) {
            $response['data'] = $raw['content'];
        }

        self::logCall('verifyMeter', $payload, $response);

        return $response;
    }

    /**
     * Purchase an electricity token (prepaid vend token / postpaid payment).
     *
     * @param string $serviceId   Electricity disco identifier
     * @param string $meterNo     Meter number
     * @param string $meterType   'prepaid' or 'postpaid'
     * @param string $phone       Customer phone number
     * @param float  $amount      Amount in Naira (minimum per disco varies)
     * @param string $requestId   Unique idempotency ID
     * @return array Normalized response; 'token' contains the vend token for prepaid
     */
    public static function buyElectricity(
        string $serviceId,
        string $meterNo,
        string $meterType,
        string $phone,
        float  $amount,
        string $requestId
    ): array {
        $payload = [
            'request_id'     => $requestId,
            'serviceID'      => $serviceId,
            'billersCode'    => $meterNo,
            'variation_code' => $meterType,
            'amount'         => $amount,
            'phone'          => $phone,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        self::logCall('buyElectricity', $payload, $response);

        return $response;
    }

    // ─── Betting Wallet ───────────────────────────────────────────────────────

    /**
     * Fund a betting wallet / account.
     *
     * @param string $serviceId   Betting platform: 'bet9ja', 'betking', '1xbet', etc.
     * @param string $customerId  Betting account ID / username
     * @param float  $amount      Fund amount in Naira
     * @param string $requestId   Unique idempotency ID
     * @return array Normalized response
     */
    public static function fundBetting(
        string $serviceId,
        string $customerId,
        float  $amount,
        string $requestId
    ): array {
        $payload = [
            'request_id'  => $requestId,
            'serviceID'   => $serviceId,
            'billersCode' => $customerId,
            'amount'      => $amount,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        self::logCall('fundBetting', $payload, $response);

        return $response;
    }

    // ─── Exam PIN ─────────────────────────────────────────────────────────────

    /**
     * Verify a JAMB Profile ID before purchase.
     *
     * Per VTpass docs, the 'type' field must be the variation_code (e.g. 'utme-mock').
     * Sandbox test profileId: 0123456789
     *
     * @param string $profileId     JAMB Profile ID (from JAMB Official Website)
     * @param string $variationCode JAMB variation code (e.g. 'utme-mock', 'utme-no-mock')
     * @return array Normalized response; 'data' contains Customer_Name
     */
    public static function verifyJambProfile(string $profileId, string $variationCode): array
    {
        $payload = [
            'billersCode' => $profileId,
            'serviceID'   => 'jamb',
            'type'        => $variationCode,
        ];

        $raw      = self::request(VTPASS_ENDPOINT_VERIFY_CABLE, $payload, 'POST');
        $response = self::normalizeResponse($raw);

        // Merchant-verify returns customer data in 'content' directly (not in transactions)
        if (empty($response['data']) && !empty($raw['content'])) {
            $response['data'] = $raw['content'];
        }

        // A code of '000' is success; also check if customer name is present
        if (!$response['success'] && !empty($raw['content']['Customer_Name'])) {
            $response['success'] = true;
            $response['data']    = $raw['content'];
        }

        self::logCall('verifyJambProfile', $payload, $response);

        return $response;
    }

    /**
     * Purchase an exam scratch card / PIN.
     *
     * Handles all four exam services per VTpass API documentation:
     *  - waec             : WAEC Result Checker — no billersCode; returns 'cards' array (root-level)
     *  - waec-registration: WAEC Registration PIN — no billersCode; returns 'tokens' array (root-level)
     *  - jamb             : JAMB UTME — requires billersCode=profileId; returns 'Pin'/'purchased_code' (root)
     *  - neco             : NECO Result Checker — no billersCode; returns 'cards' if available
     *
     * @param string $serviceId     Exam service ID: 'waec', 'waec-registration', 'jamb', 'neco'
     * @param string $variationCode Variation code from getVariations()
     * @param string $phone         Customer/recipient phone number
     * @param float  $amount        Amount in Naira
     * @param string $requestId     Unique idempotency ID
     * @param int    $quantity      Number of PINs (default 1; JAMB is always 1)
     * @param string $billersCode   JAMB Profile ID (required for 'jamb' only)
     * @return array Normalized response with extra keys:
     *               'cards'  => array of ['pin'=>..., 'serial'=>...] (WAEC / NECO)
     *               'tokens' => array of token strings (WAEC-Registration)
     *               'pin'    => single PIN string (JAMB)
     */
    public static function buyExamPin(
        string $serviceId,
        string $variationCode,
        string $phone,
        float  $amount,
        string $requestId,
        int    $quantity = 1,
        string $billersCode = ''
    ): array {
        // Build payload per service documentation
        $payload = [
            'request_id'     => $requestId,
            'serviceID'      => $serviceId,
            'variation_code' => $variationCode,
            'amount'         => $amount,
            'phone'          => $phone,
        ];

        // JAMB requires billersCode = Profile ID; quantity is always 1
        if ($serviceId === 'jamb') {
            $payload['billersCode'] = $billersCode ?: $phone;
        } else {
            // WAEC, WAEC-Registration, NECO: quantity is supported, no billersCode
            $payload['quantity'] = $quantity;
        }

        $raw      = self::request(VTPASS_ENDPOINT_PAY, $payload);
        $response = self::normalizeResponse($raw);

        // ── Extract exam-specific results from root-level response fields ──────
        // VTpass puts cards/tokens/Pin at the root, not inside content.transactions

        // WAEC Result Checker: cards[] with keys 'Serial' and 'Pin' (capital)
        $rawCards = $raw['cards'] ?? [];
        $cards    = [];
        foreach ($rawCards as $c) {
            $cards[] = [
                'pin'    => $c['Pin']    ?? $c['pin']    ?? 'N/A',
                'serial' => $c['Serial'] ?? $c['serial'] ?? 'N/A',
            ];
        }
        $response['data']['cards'] = $cards;

        // WAEC Registration: tokens[] — array of token strings
        $response['data']['tokens'] = $raw['tokens'] ?? [];

        // JAMB: single PIN from 'Pin' field or parsed from 'purchased_code'
        $jambPin = null;
        if (!empty($raw['Pin'])) {
            // Format: "Pin : 3678251321392432" — extract just the number
            $jambPin = trim(preg_replace('/^[Pp]in\s*:\s*/i', '', (string) $raw['Pin']));
        } elseif (!empty($raw['purchased_code'])) {
            $jambPin = trim(preg_replace('/^[Pp]in\s*:\s*/i', '', (string) $raw['purchased_code']));
        }
        $response['data']['pin'] = $jambPin;

        self::logCall('buyExamPin', $payload, $response);

        return $response;
    }

    // ─── Service Catalog ──────────────────────────────────────────────────────

    /**
     * Get all available variation codes (packages) for a VTpass service.
     *
     * @param string $serviceId VTpass service identifier
     * @return array Normalized response; 'data' contains array of variation objects
     */
    public static function getVariations(string $serviceId): array
    {
        $endpoint = VTPASS_BASE_URL . '/service-variations?serviceID=' . urlencode($serviceId);
        $raw      = self::request($endpoint, [], 'GET');

        // VTpass returns response_description = '000' on success (not the word 'success')
        $content    = $raw['content'] ?? $raw['data'] ?? [];
        // Sandbox returns both 'variations' and 'varations' (typo) — check both
        $variations = $content['variations'] ?? $content['varations'] ?? [];

        // Success = code '000' OR response_description '000' OR non-empty variations
        $code    = (string) ($raw['code'] ?? $raw['response_description'] ?? '');
        $success = ($code === '000') || !empty($variations);

        $response = [
            'success'   => $success,
            'message'   => $success ? 'Variations fetched' : (string) ($raw['response_description'] ?? 'Failed to fetch plans'),
            'code'      => $code,
            'reference' => '',
            'token'     => null,
            'data'      => ['variations' => $variations],
        ];

        self::logCall('getVariations', ['serviceID' => $serviceId], $response);

        return $response;
    }

    // ─── Transaction Query / Requery ──────────────────────────────────────────

    /**
     * Query / recheck the status of a previously submitted VTpass transaction.
     *
     * Use this to resolve ambiguous '099' (pending) responses.
     *
     * @param string $requestId The original request_id used in the transaction
     * @return array Normalized response with latest transaction status
     */
    public static function requery(string $requestId): array
    {
        $payload  = ['request_id' => $requestId];
        $raw      = self::request(VTPASS_ENDPOINT_QUERY, $payload, 'POST');
        $response = self::normalizeResponse($raw);

        self::logCall('requery', $payload, $response);

        return $response;
    }

    // ─── VTpass Wallet Balance ────────────────────────────────────────────────

    /**
     * Get the current VTpass API wallet balance.
     *
     * @return array Normalized response; 'data' contains balance info
     */
    public static function getBalance(): array
    {
        $raw = self::request(VTPASS_ENDPOINT_BALANCE, [], 'GET');

        $balance = $raw['Wallet_balance'] ?? $raw['wallet_balance'] ?? $raw['balance'] ?? 
                   $raw['contents']['balance'] ?? $raw['contents']['wallet_balance'] ?? null;

        $success = isset($raw['code'])
            ? in_array((string) $raw['code'], ['000', '1', '200'], true)
            : $balance !== null;

        $response = [
            'success'   => $success,
            'message'   => (string) ($raw['response_description'] ?? ($success ? 'Balance retrieved' : 'Failed')),
            'code'      => (string) ($raw['code'] ?? ''),
            'reference' => '',
            'token'     => null,
            'data'      => [
                'balance' => $balance,
            ],
        ];

        self::logCall('getBalance', [], $response);

        return $response;
    }

    /**
     * Get the live VTpass API wallet balance specifically, bypassing sandbox settings if active.
     *
     * @return array Normalized response
     */
    public static function getLiveBalance(): array
    {
        $liveApiKey    = setting('vtpass_live_api_key', '');
        $livePublicKey = setting('vtpass_live_public_key', '');
        $liveSecretKey = setting('vtpass_live_secret_key', '');

        // If live credentials are empty, fallback to default getBalance
        if (empty($liveApiKey) && empty($livePublicKey) && empty($liveSecretKey)) {
            return self::getBalance();
        }

        // Build headers manually using live credentials
        $headers = [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: '    . $liveApiKey,
            'public-key: ' . $livePublicKey,
            'secret-key: ' . $liveSecretKey,
        ];

        // Core HTTP Request logic simplified for live balance lookup
        set_time_limit(120);
        $endpoint = 'https://vtpass.com/api/balance';
        
        $ch = curl_init();
        $curlOpts = [
            CURLOPT_URL            => $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_HTTPGET        => true,
        ];
        curl_setopt_array($ch, $curlOpts);
        $rawResponse = curl_exec($ch);
        $curlErrno   = curl_errno($ch);
        curl_close($ch);

        if ($curlErrno !== 0 || empty($rawResponse)) {
            return [
                'success' => false,
                'message' => 'Connection failed or empty response from live VTpass API.',
                'data'    => ['balance' => null]
            ];
        }

        $raw = json_decode($rawResponse, true);
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($raw)) {
            return [
                'success' => false,
                'message' => 'Invalid JSON response from live VTpass API.',
                'data'    => ['balance' => null]
            ];
        }

        $balance = $raw['Wallet_balance'] ?? $raw['wallet_balance'] ?? $raw['balance'] ?? 
                   $raw['contents']['balance'] ?? $raw['contents']['wallet_balance'] ?? null;
        $success = isset($raw['code'])
            ? in_array((string)$raw['code'], ['000', '1', '200'], true)
            : $balance !== null;

        return [
            'success'   => $success,
            'message'   => (string)($raw['response_description'] ?? ($success ? 'Live balance retrieved' : 'Failed')),
            'code'      => (string)($raw['code'] ?? ''),
            'reference' => '',
            'token'     => null,
            'data'      => [
                'balance' => $balance,
            ],
        ];
    }

    // ─── Database Operations ──────────────────────────────────────────────────

    /**
     * Persist a VTpass transaction record to the vtpass_transactions table.
     *
     * Should be called BEFORE submitting to VTpass so the record exists
     * even if the API call fails, enabling retry logic.
     *
     * @param int    $userId      User performing the purchase
     * @param string $serviceId   VTpass service identifier
     * @param string $serviceType Transaction type constant (TXN_TYPE_*)
     * @param string $requestId   Unique VTpass request ID
     * @param float  $amount      Transaction amount in Naira
     * @param string $phone       Beneficiary phone number (may be empty for cable/betting)
     * @param array  $payload     Full request payload (for audit)
     * @param array  $response    Initial API response (may be empty on creation)
     * @return int                Database row ID of the saved transaction
     */
    public static function saveTransaction(
        int    $userId,
        string $serviceId,
        string $serviceType,
        string $requestId,
        float  $amount,
        string $phone   = '',
        array  $payload = [],
        array  $response = []
    ): int {
        $uuid = generateUUID();
        $id = Database::insert(
            'INSERT INTO vtpass_transactions
             (uuid, user_id, service_id, service_type, request_id, amount, phone, status,
              vtpass_ref, vtpass_response_code, vtpass_token, request_payload, response_payload, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
            [
                $uuid,
                $userId,
                $serviceId,
                $serviceType,
                $requestId,
                $amount,
                $phone,
                TXN_STATUS_PENDING,
                $response['reference'] ?? null,
                $response['code']      ?? null,
                $response['token']     ?? null,
                json_encode($payload,  JSON_UNESCAPED_UNICODE),
                json_encode($response, JSON_UNESCAPED_UNICODE),
            ]
        );

        return (int) $id;
    }

    /**
     * Update a vtpass_transactions row after receiving the API response.
     *
     * Maps VTpass response codes to internal status constants.
     *
     * @param string      $requestId    The VTpass request_id
     * @param string      $status       TXN_STATUS_SUCCESS | TXN_STATUS_FAILED | TXN_STATUS_PENDING
     * @param string|null $vtpassRef    VTpass transaction reference (transactionId)
     * @param string|null $responseCode VTpass response code
     * @param string|null $token        Electricity token (prepaid only)
     */
    public static function updateTransactionStatus(
        string  $requestId,
        string  $status,
        ?string $vtpassRef    = null,
        ?string $responseCode = null,
        ?string $token        = null
    ): void {
        $existing = Database::fetchOne("SELECT * FROM vtpass_transactions WHERE request_id = ? LIMIT 1", [$requestId]);

        Database::execute(
            'UPDATE vtpass_transactions
             SET status = ?, vtpass_ref = ?, vtpass_response_code = ?, vtpass_token = ?, updated_at = NOW()
             WHERE request_id = ?',
            [
                $status,
                $vtpassRef,
                $responseCode,
                $token,
                $requestId,
            ]
        );

        if ($existing && $existing['status'] !== 'success' && $status === 'success') {
            $updatedTxn = Database::fetchOne("SELECT * FROM vtpass_transactions WHERE request_id = ? LIMIT 1", [$requestId]);
            if ($updatedTxn) {
                triggerDeveloperVtuCommissionShare($updatedTxn);
                triggerVtuCommissionDistribution($updatedTxn);
            }
        }
    }

    // ─── Retry Failed Transactions ────────────────────────────────────────────

    /**
     * Attempt to re-query and resolve all pending/ambiguous VTpass transactions.
     *
     * Intended for use in the cron runner. Queries transactions that are still
     * in 'pending' status and have been pending for at least 2 minutes but less
     * than 24 hours. Calls requery() and updates status based on the result.
     *
     * @return int Number of transactions successfully resolved (either way)
     */
    public static function retryFailed(): int
    {
        $pendingTxns = Database::fetchAll(
            "SELECT * FROM vtpass_transactions
             WHERE status = ?
               AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
               AND created_at <= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
             ORDER BY created_at ASC
             LIMIT 50",
            [TXN_STATUS_PENDING]
        );

        $resolved = 0;

        foreach ($pendingTxns as $txn) {
            try {
                $result = self::requery($txn['request_id']);

                $vtpassRef = $result['reference'] ?? null;
                $resCode   = $result['code']      ?? null;
                $token     = $result['token']     ?? null;

                $newStatus = match (true) {
                    in_array($resCode, self::SUCCESS_CODES, true) => TXN_STATUS_SUCCESS,
                    in_array($resCode, self::FAILED_CODES,  true) => TXN_STATUS_FAILED,
                    default                                        => TXN_STATUS_PENDING,
                };

                if ($newStatus !== TXN_STATUS_PENDING) {
                    self::updateTransactionStatus(
                        requestId:    $txn['request_id'],
                        status:       $newStatus,
                        vtpassRef:    $vtpassRef,
                        responseCode: $resCode,
                        token:        $token
                    );

                    writeLog(LOG_CHAN_VTPASS, 'info', "Retry resolved request_id={$txn['request_id']} → {$newStatus}", [
                        'code'      => $resCode,
                        'vtpassRef' => $vtpassRef,
                    ]);

                    $resolved++;
                }
            } catch (\Throwable $e) {
                writeLog(LOG_CHAN_VTPASS, 'error', "Retry failed for request_id={$txn['request_id']}: " . $e->getMessage());
            }

            // Brief pause to avoid hammering the VTpass API
            usleep(300_000); // 300ms
        }

        return $resolved;
    }

    // ─── Response Normalization ───────────────────────────────────────────────

    /**
     * Normalize a raw VTpass API response into the standard response envelope
     * used by all public methods in this class.
     *
     * VTpass response structure varies across API versions and service types.
     * This method handles all known variations.
     *
     * Standard VTpass success response shape:
     * {
     *   "code": "000",
     *   "response_description": "TRANSACTION SUCCESSFUL",
     *   "requestId": "...",
     *   "content": {
     *     "transactions": {
     *       "transactionId": "...",
     *       "product_name": "...",
     *       "unique_element": "...",  ← phone / meter / smart card
     *       "unit_price": ...,
     *       "quantity": ...,
     *       "service_verification": null,
     *       "channel": "api",
     *       "discount": null,
     *       "tax": null,
     *       "cashback": ...,
     *       "cashback_wallet": ...,
     *       "total_amount": ...,
     *       "balance_after": ...,
     *       "type": "...",
     *       "email": "...",
     *       "phone": "...",
     *       "name": null,
     *       "convinience_fee": ...,
     *       "fixed_price": ...,
     *       "status": "delivered"
     *     }
     *   },
     *   "purchased_code": "12345678901234567890:1234567890"  ← electricity token
     * }
     *
     * @param array $raw Raw response from VTpass API
     * @return array Normalized response
     */
    private static function normalizeResponse(array $raw): array
    {
        if (empty($raw)) {
            return [
                'success'   => false,
                'message'   => 'No response received from VTpass.',
                'code'      => 'EMPTY_RESPONSE',
                'reference' => '',
                'token'     => null,
                'data'      => [],
            ];
        }

        $code    = (string) ($raw['code'] ?? '');
        $desc    = (string) ($raw['response_description'] ?? '');
        
        $success = in_array($code, self::SUCCESS_CODES, strict: true) ||
                   in_array($desc, ['000', '200'], true) ||
                   stripos($desc, 'SUCCESSFUL') !== false ||
                   stripos($desc, 'SUCCESS') !== false;
        
        $message = (string) ($raw['response_description'] ?? ($success ? 'Transaction successful' : 'Transaction failed'));

        // Extract transaction data from 'content.transactions' or root
        $content      = $raw['content'] ?? [];
        $transactions = $content['transactions'] ?? $content['transaction'] ?? $content ?? [];

        // If content is still nested, drill into it
        if (isset($transactions['transactions'])) {
            $transactions = $transactions['transactions'];
        }

        // Reference / transaction ID
        $reference = (string) (
            $transactions['transactionId'] ?? $raw['transactionId'] ??
            $raw['requestId']              ?? $raw['request_id']    ?? ''
        );

        // Electricity token — may be in purchased_code, token, or within transactions
        $rawToken = $raw['purchased_code']         ??
                    $raw['token']                  ??
                    $transactions['token']         ??
                    $transactions['Token']         ??
                    $transactions['purchasedCode'] ??
                    null;

        // Extract just the token portion (format: "TOKEN:UNITS", "Token : TOKEN", or plain token)
        $token = null;
        if (!empty($rawToken)) {
            $tokenStr = (string)$rawToken;
            // Remove "Token : ", "Pin : ", "token : ", etc.
            $tokenStr = preg_replace('/^(token|pin|code)\s*:\s*/i', '', $tokenStr);
            $tokenStr = trim($tokenStr);

            if (str_contains($tokenStr, ':')) {
                $parts = explode(':', $tokenStr);
                $token = trim($parts[0]);
                if (!empty($parts[1]) && !isset($transactions['units'])) {
                    $transactions['units'] = trim($parts[1]);
                }
            } else {
                $token = $tokenStr;
            }
        }

        // Copy root-level fields that could be useful (like units, tariff) into transactions
        $rootFields = ['units', 'tariff', 'tokenAmount', 'exchangeReference', 'resetToken', 'configureToken', 'customerName', 'customerAddress'];
        foreach ($rootFields as $rf) {
            if (isset($raw[$rf]) && !isset($transactions[$rf])) {
                $transactions[$rf] = $raw[$rf];
            }
        }

        return [
            'success'   => $success,
            'message'   => $message,
            'code'      => $code,
            'reference' => $reference,
            'token'     => $token,
            'data'      => $transactions,
        ];
    }

    // ─── Logging ──────────────────────────────────────────────────────────────

    /**
     * Log a VTpass API call to the LOG_CHAN_VTPASS log channel file.
     * Phone numbers and sensitive fields are partially masked in logs.
     *
     * @param string $action   Human-readable action name (e.g., 'buyAirtime')
     * @param array  $request  Request payload
     * @param array  $response Normalized response envelope
     * @param int    $userId   Optional user ID for context
     */
    private static function logCall(
        string $action,
        array  $request,
        array  $response,
        int    $userId = 0
    ): void {
        $level   = $response['success'] ? 'info' : 'error';
        $message = sprintf(
            'VTpass [%s] → %s (code: %s, ref: %s)',
            strtoupper($action),
            $response['message'],
            $response['code'],
            $response['reference'] ?: 'N/A'
        );

        // Mask phone number in logged request
        $safeRequest = $request;
        if (isset($safeRequest['phone']) && strlen($safeRequest['phone']) > 6) {
            $safeRequest['phone'] = maskPhone($safeRequest['phone']);
        }
        if (isset($safeRequest['billersCode']) && strlen($safeRequest['billersCode']) > 6) {
            $safeRequest['billersCode'] = maskPhone($safeRequest['billersCode']);
        }

        writeLog(LOG_CHAN_VTPASS, $level, $message, [
            'action'    => $action,
            'user_id'   => $userId,
            'success'   => $response['success'],
            'code'      => $response['code'],
            'reference' => $response['reference'],
            'service'   => $safeRequest['serviceID'] ?? null,
            'amount'    => $safeRequest['amount']    ?? null,
        ]);
    }
}
