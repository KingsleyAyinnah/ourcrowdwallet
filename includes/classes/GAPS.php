<?php
namespace Ourcr\GAPS;

/**
 * OURCR ONLINE - GTBank GAPS (Guaranty Trust Bank Automated Payment System) API Client
 *
 * Implements GTBank GAPS WebService integration for:
 * - Account statement retrieval & automated deposit reconciliation
 * - Single transfer outward payments (withdrawals)
 * - Transaction requery & status verification
 *
 * Security: Encrypts <accesscode>, <username>, and <password> using GTBank's RSA Public Key (PKCS1 padding).
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

class GAPS
{
    // ─── Credential Helpers ───────────────────────────────────────────────────

    public static function getAccessCode(): string
    {
        return trim((string) setting('gaps_access_code', setting('gaps_merchant_id', '')));
    }

    public static function getUsername(): string
    {
        return trim((string) setting('gaps_username', ''));
    }

    public static function getPassword(): string
    {
        return (string) setting('gaps_password', '');
    }

    public static function getAccountNumber(): string
    {
        return trim((string) setting('gaps_account_number', ''));
    }

    public static function getChannel(): string
    {
        $channel = trim((string) setting('gaps_channel', ''));
        return $channel !== '' ? $channel : 'CROWDAPP';
    }

    public static function resolveBaseUrl(): string
    {
        $baseUrl = trim((string) setting('gaps_base_url', ''));
        if ($baseUrl !== '') {
            return $baseUrl;
        }

        $env = strtolower(trim((string) setting('gaps_env', 'sandbox')));
        if ($env === 'live') {
            return 'https://gtweb.gtbank.com/GSTPS/GAPS_FileUploader/FileUploader.asmx';
        }

        return 'https://gtweb6.gtbank.com/GSTPS/GAPS_FileUploader/FileUploader.asmx';
    }

    // ─── Cryptographic Helpers (GTBank RSA Public Key Encryption) ────────────

    /**
     * Normalize RSA key text into PEM format.
     */
    private static function normalizePem(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }

        if (str_contains($raw, '-----BEGIN')) {
            return str_replace(["\r\n", "\r"], "\n", $raw);
        }

        $decoded = @base64_decode($raw, true);
        if ($decoded !== false && str_contains($decoded, '-----BEGIN')) {
            return trim(str_replace(["\r\n", "\r"], "\n", $decoded));
        }

        // Clean out any quotes, backslashes, or non-base64 characters
        $body = preg_replace('/[^A-Za-z0-9\+\/\=]/', '', $raw);
        if (empty($body)) {
            return '';
        }

        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split($body, 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /**
     * Get GTBank RSA Public Key resource for encrypting credentials.
     */
    private static function getPublicKey(): \OpenSSLAsymmetricKey
    {
        $raw = trim((string) setting('gaps_public_key', ''));
        if (empty($raw)) {
            throw new \RuntimeException('GAPS: GTBank RSA Public Key is not configured in Admin > API Config.');
        }

        $raw = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $pem = self::normalizePem($raw);
        $key = openssl_pkey_get_public($pem);

        if ($key === false) {
            throw new \RuntimeException('GAPS: Failed to load GTBank RSA Public Key from Admin API Config: ' . openssl_error_string());
        }

        return $key;
    }

    /**
     * Encrypt plaintext string using GTBank RSA Public Key (PKCS1 padding) -> Base64 string.
     */
    public static function encryptField(string $plaintext): string
    {
        if (empty($plaintext)) {
            return '';
        }

        $key = self::getPublicKey();
        $encrypted = '';

        if (!openssl_public_encrypt($plaintext, $encrypted, $key, OPENSSL_PKCS1_PADDING)) {
            throw new \RuntimeException('GAPS: RSA Encryption failed: ' . openssl_error_string());
        }

        return base64_encode($encrypted);
    }

    // ─── Public API Methods ───────────────────────────────────────────────────

    /**
     * Retrieve GTBank Account Statement for deposit reconciliation.
     *
     * @param string $fromDate Y-m-d start date
     * @param string $toDate   Y-m-d end date
     * @param int    $page     Page index
     * @return array Standard statement response
     */
    public static function getStatement(string $fromDate, string $toDate, int $page = 1): array
    {
        $encUser    = self::encryptField(self::getUsername());
        $encPass    = self::encryptField(self::getPassword());
        $encAccess  = self::encryptField(self::getAccessCode());
        $accountNo  = self::getAccountNumber();
        $channel    = self::getChannel();
        $encAccount = self::encryptField($accountNo);

        $xmlPayload = "
  <startDate>{$fromDate}</startDate>
  <endDate>{$toDate}</endDate>
  <pageNumber>{$page}</pageNumber>
  <pageSize>100</pageSize>
  <customerid>{$encAccess}</customerid>
  <username>{$encUser}</username>
  <password>{$encPass}</password>
  <accountNo>{$encAccount}</accountNo>
  <channel>{$channel}</channel>";

        $response = self::sendXmlRequest($xmlPayload, 'AccountStatement_XML_Enc');

        if (!$response['success']) {
            $response['transactions'] = [];
            $response['total']        = 0;
            $response['page']         = $page;
            return $response;
        }

        $transactions = self::parseStatementXml($response['raw']);
        $response['transactions'] = $transactions;
        $response['total']        = count($transactions);
        $response['page']         = $page;

        return $response;
    }

    /**
     * Process a withdrawal (outward transfer) via GTBank GAPS SingleTransfer.
     *
     * @param string $accountNumber Destination account number
     * @param string $accountName   Destination account name
     * @param string $bankCode      CBN bank code
     * @param float  $amount        Amount in Naira
     * @param string $reference     Unique reference
     * @param string $narration     Transfer narration
     * @return array Response envelope
     */
    public static function processWithdrawal(
        string $accountNumber,
        string $accountName,
        string $bankCode,
        float  $amount,
        string $reference,
        string $narration = '',
        string $bankName = ''
    ): array {
        if ($amount <= 0) {
            return self::errorResponse('Withdrawal amount must be greater than zero.', 'INVALID_AMOUNT');
        }

        $encUser        = self::encryptField(self::getUsername());
        $encPass        = self::encryptField(self::getPassword());
        $encAccess      = self::encryptField(self::getAccessCode());
        $encAmount      = self::encryptField(number_format($amount, 2, '.', ''));
        $encVendorAcct  = self::encryptField($accountNumber);
        $encCustomerAcct= self::encryptField(self::getAccountNumber());
        $channel        = self::getChannel();
        $narration      = $narration ?: 'Withdrawal via ' . APP_NAME;
        $paymentDate    = date('Y-m-d');
        $cbnBankCode = (!empty($bankCode) && is_numeric($bankCode)) ? $bankCode : getBankCodeByName($bankName);
        if (empty($cbnBankCode)) {
            $cbnBankCode = getBankCodeByName($bankName);
        }
        if (empty($cbnBankCode)) {
            $cbnBankCode = '058';
        }

        $cleanVendorName = preg_replace('/[^A-Za-z0-9 ]/', '', strtoupper(trim($accountName)));
        if (empty($cleanVendorName)) {
            $cleanVendorName = 'BENEFICIARY';
        }

        $transDetailsInner = "<transaction>" .
            "<amount>{$encAmount}</amount>" .
            "<paymentdate>{$paymentDate}</paymentdate>" .
            "<reference>" . htmlspecialchars($reference, ENT_XML1) . "</reference>" .
            "<remarks>" . htmlspecialchars($narration, ENT_XML1) . "</remarks>" .
            "<vendorcode>12345</vendorcode>" .
            "<vendorname>" . htmlspecialchars($cleanVendorName, ENT_XML1) . "</vendorname>" .
            "<vendoracctnumber>{$encVendorAcct}</vendoracctnumber>" .
            "<vendorbankcode>{$cbnBankCode}</vendorbankcode>" .
            "<customeracctnumber>{$encCustomerAcct}</customeracctnumber>" .
            "</transaction>";

        $cdataInner = "<SingleTransfers><transdetails>" . htmlspecialchars($transDetailsInner, ENT_XML1) . "</transdetails></SingleTransfers>";

        $xmlPayload = "
<xmlRequest><![CDATA[{$cdataInner}]]></xmlRequest>
<username>{$encUser}</username>
<accesscode>{$encAccess}</accesscode>
<password>{$encPass}</password>
<channel>{$channel}</channel>";

        $response = self::sendXmlRequest($xmlPayload, 'SingleTransfers_Enc', 60, 1);

        if ($response['success']) {
            $parsed = self::parseResponseXml($response['raw']);
            $code   = (string)($parsed['responsecode'] ?? $parsed['rescode'] ?? $parsed['code'] ?? $parsed['status'] ?? '');
            $msg    = (string)($parsed['responsemessage'] ?? $parsed['message'] ?? 'Transfer Processed');
            
            $success = in_array($code, ['00', '0', '000', '1000', 'SUCCESS', 'PROCESSED'], true);

            $response['success'] = $success;
            $response['message'] = $msg;
            $response['code']    = $code;
            $response['data']    = $parsed;
        } else {
            writeLog(LOG_CHAN_WALLET, 'error', 'GAPS SingleTransfers_Enc HTTP/SOAP request failed', [
                'reference' => $reference,
                'error'     => $response['message'] ?? 'Unknown transfer error',
            ]);
        }

        return $response;
    }

    /**
     * Requery status of a GAPS transaction by reference using TransactionRequery_Enc.
     */
    public static function requeryTransaction(string $reference): array
    {
        $encUser   = self::encryptField(self::getUsername());
        $encPass   = self::encryptField(self::getPassword());
        $encAccess = self::encryptField(self::getAccessCode());
        $channel   = self::getChannel();

        $innerXml = "<TransactionRequeryRequest><TransRef>" . htmlspecialchars($reference, ENT_XML1) . "</TransRef></TransactionRequeryRequest>";

        $xmlPayload = "
<xmlstring>" . htmlspecialchars($innerXml, ENT_XML1) . "</xmlstring>
<customerid>{$encAccess}</customerid>
<username>{$encUser}</username>
<password>{$encPass}</password>
<channel>{$channel}</channel>";

        writeLog(LOG_CHAN_WALLET, 'info', 'Sending GAPS TransactionRequery_Enc request', [
            'reference' => $reference,
            'endpoint'  => self::resolveBaseUrl(),
        ]);

        $response = self::sendXmlRequest($xmlPayload, 'TransactionRequery_Enc');

        if ($response['success']) {
            $parsed = self::parseResponseXml($response['raw']);
            $code   = (string)($parsed['code'] ?? $parsed['rescode'] ?? $parsed['responsecode'] ?? $parsed['status'] ?? '');
            $msg    = (string)($parsed['message'] ?? $parsed['responsemessage'] ?? 'Transaction Requery Processed');

            writeLog(LOG_CHAN_WALLET, 'info', 'GAPS TransactionRequery_Enc response received', [
                'reference' => $reference,
                'code'      => $code,
                'message'   => $msg,
                'parsed'    => $parsed,
                'raw'       => $response['raw'],
            ]);

            // Success codes: 1000 (Success), 1007 (Duplicate Reference - already processed), 00, 0, SUCCESS
            $success = in_array($code, ['1000', '1007', '00', '0', '000', 'SUCCESS', 'PROCESSED'], true);

            $response['success'] = $success;
            $response['message'] = $msg;
            $response['code']    = $code;
            $response['data']    = $parsed;
        } else {
            writeLog(LOG_CHAN_WALLET, 'error', 'GAPS TransactionRequery_Enc HTTP/SOAP request failed', [
                'reference' => $reference,
                'error'     => $response['message'] ?? 'Unknown requery error',
            ]);
        }

        return $response;
    }

    /**
     * Get current GTBank GAPS available account balance.
     */
    public static function getBalance(): array
    {
        $from   = date('Y-m-d');
        $to     = date('Y-m-d');
        $res    = self::getStatement($from, $to);
        $balance = 0.0;

        if (!empty($res['transactions'])) {
            $latest = end($res['transactions']);
            $balance = (float)($latest['balance_after'] ?? 0.0);
        }

        $res['balance'] = $balance;
        return $res;
    }

    /**
     * Resolve account name using GTBank GAPS validation web services.
     */
    public static function resolveAccountName(string $accountNumber, string $bankName): array
    {
        $accountNumber = preg_replace('/\D/', '', trim($accountNumber));
        if (strlen($accountNumber) !== 10) {
            return ['success' => false, 'message' => 'Account number must be exactly 10 digits.'];
        }

        $bankCode = getBankCodeByName($bankName);
        if (empty($bankCode)) {
            return ['success' => false, 'message' => 'Unsupported bank name.'];
        }

        // Check if bank is GTB
        $isGTB = ($bankCode === '058');

        $username   = self::getUsername();
        $password   = self::getPassword();
        $accessCode = self::getAccessCode();

        if (empty($username) || empty($password) || empty($accessCode)) {
            return [
                'success' => false,
                'message' => 'GTBank GAPS credentials are not configured in Admin > API Config.'
            ];
        }

        $encUser   = self::encryptField($username);
        $encPass   = self::encryptField($password);
        $encAccess = self::encryptField($accessCode);
        $encAccNo  = self::encryptField($accountNumber);
        $channel   = self::getChannel();

        if ($isGTB) {
            $xmlPayload = "
<accountNo>{$encAccNo}</accountNo>
<customerid>{$encAccess}</customerid>
<username>{$encUser}</username>
<password>{$encPass}</password>
<channel>{$channel}</channel>";
            $response = self::sendXmlRequest($xmlPayload, 'GetAccountInGTB_Enc');
        } else {
            $xmlPayload = "
<accountNo>{$encAccNo}</accountNo>
<bankcode>{$bankCode}</bankcode>
<customerid>{$encAccess}</customerid>
<username>{$encUser}</username>
<password>{$encPass}</password>
<channel>{$channel}</channel>";
            $response = self::sendXmlRequest($xmlPayload, 'GetAccountInOtherBank_Enc');
        }

        if (!$response['success']) {
            writeLog(LOG_CHAN_GAPS, 'error', 'GAPS account resolve request failed before parsing.', [
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'action' => $isGTB ? 'GetAccountInGTB_Enc' : 'GetAccountInOtherBank_Enc',
                'gaps_response' => $response,
            ]);
            return $response;
        }

        try {
            $parsed = self::parseResponseXml($response['raw']);
            $code = (string)($parsed['code'] ?? $parsed['rescode'] ?? '');
            
            if ($code === '1000') {
                $accountName = trim((string)($parsed['accountname'] ?? $parsed['account_name'] ?? ''));
                if (!empty($accountName)) {
                    return [
                        'success'      => true,
                        'account_name' => strtoupper($accountName),
                        'currency'     => $parsed['currencycode'] ?? 'NGN',
                        'code'         => $code
                    ];
                }
            }

            $knownErrors = [
                '1001' => 'Invalid NUBAN account number. Account number must be a valid 10-digit number.',
                '1002' => 'Invalid bank code. Recipient bank is unsupported or invalid.',
                '1003' => 'Account not found or inactive at the recipient bank.',
                '1004' => 'Authentication failed with GTBank GAPS. Please verify credentials in Admin.',
                '1005' => 'GAPS channel unauthorized for this inquiry.',
                '1008' => 'Automated name lookup is temporarily unavailable. Please type the account name manually.'
            ];

            $msg = trim((string)($parsed['message'] ?? $parsed['description'] ?? ''));
            $msg = ltrim($msg, ' :');

            if (isset($knownErrors[$code])) {
                $userMsg = $knownErrors[$code];
            } elseif ($code === '1008' || stripos($msg, 'System error') !== false) {
                $userMsg = 'Automated name lookup is temporarily unavailable. Please type the account name manually.';
            } else {
                $userMsg = (!empty($msg) ? $msg : 'Account validation failed') . ($code ? " (Code {$code})" : '');
            }

            writeLog(LOG_CHAN_GAPS, 'error', 'GAPS account resolve returned non-success response.', [
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'action' => $isGTB ? 'GetAccountInGTB_Enc' : 'GetAccountInOtherBank_Enc',
                'response_code' => $code,
                'response_message' => $msg,
                'raw_response' => $response['raw'],
            ]);

            return [
                'success' => false,
                'message' => $userMsg
            ];
        } catch (\Throwable $e) {
            writeLog(LOG_CHAN_GAPS, 'error', 'Failed to parse GAPS account resolve response.', [
                'bank_name' => $bankName,
                'account_number' => $accountNumber,
                'action' => $isGTB ? 'GetAccountInGTB_Enc' : 'GetAccountInOtherBank_Enc',
                'exception' => $e->getMessage(),
                'raw_response' => $response['raw'],
            ]);
            return [
                'success' => false,
                'message' => 'Failed to parse GAPS validation response: ' . $e->getMessage()
            ];
        }
    }

    // ─── HTTP & XML Processing Layer ──────────────────────────────────────────

    /**
     * Send XML POST request to GTBank GAPS WebService endpoint.
     */
    private static function sendXmlRequest(string $xmlPayload, string $actionName, int $timeout = GAPS_TIMEOUT, int $maxRetries = GAPS_MAX_RETRIES): array
    {
        $endpoint = self::resolveBaseUrl();
        $attempt  = 0;
        $lastErr  = '';

        // Wrap in SOAP envelope if targeting .asmx WebService
        if (str_contains($endpoint, '.asmx') || str_contains($endpoint, 'FileUploader')) {
            $soapBody = "<?xml version=\"1.0\" encoding=\"utf-8\"?>
<Envelope xmlns=\"http://schemas.xmlsoap.org/soap/envelope/\">
  <Body>
    <{$actionName} xmlns=\"http://tempuri.org/GAPS_Uploader/FileUploader\">
      {$xmlPayload}
    </{$actionName}>
  </Body>
</Envelope>";
            $headers = [
                'Content-Type: text/xml; charset=utf-8',
                'SOAPAction: "http://tempuri.org/GAPS_Uploader/FileUploader/' . $actionName . '"',
                'Content-Length: ' . strlen($soapBody)
            ];
            $postData = $soapBody;
        } else {
            $headers = [
                'Content-Type: application/xml; charset=utf-8',
                'Content-Length: ' . strlen($xmlPayload)
            ];
            $postData = $xmlPayload;
        }

        $cookieFile = sys_get_temp_dir() . '/gaps_cookie_' . md5(self::getUsername()) . '.txt';

        while ($attempt < $maxRetries) {
            $attempt++;

            try {
                $ch = curl_init();
                curl_setopt_array($ch, [
                    CURLOPT_URL            => $endpoint,
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $postData,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => $timeout,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                    CURLOPT_COOKIEJAR      => $cookieFile,
                    CURLOPT_COOKIEFILE     => $cookieFile,
                    CURLOPT_USERAGENT      => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
                ]);

                $rawResponse = curl_exec($ch);
                $httpCode    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr     = curl_error($ch);
                curl_close($ch);

                if ($curlErr !== '') {
                    $lastErr = "cURL error: {$curlErr}";
                    writeLog(LOG_CHAN_GAPS, 'warning', "GAPS attempt {$attempt} failed: {$lastErr}");
                    if ($attempt < GAPS_MAX_RETRIES) sleep(1);
                    continue;
                }

                if ($rawResponse === false || trim($rawResponse) === '') {
                    $lastErr = "Empty HTTP {$httpCode} response from GAPS endpoint";
                    writeLog(LOG_CHAN_GAPS, 'warning', $lastErr);
                    if ($attempt < GAPS_MAX_RETRIES) sleep(1);
                    continue;
                }

                $success = ($httpCode >= 200 && $httpCode < 300) && !str_contains($rawResponse, 'Fault');

                $result = [
                    'success' => $success,
                    'raw'     => $rawResponse,
                    'message' => $success ? 'Request executed successfully' : "HTTP {$httpCode}",
                    'code'    => (string)$httpCode,
                    'http'    => $httpCode,
                ];

                self::logCall($actionName, ['action' => $actionName], $result);
                return $result;

            } catch (\Throwable $e) {
                $lastErr = $e->getMessage();
                writeLog(LOG_CHAN_GAPS, 'error', "GAPS Exception (attempt {$attempt}): {$lastErr}");
                if ($attempt < GAPS_MAX_RETRIES) sleep(1);
            }
        }

        return self::errorResponse("GAPS request failed after {$attempt} attempt(s): {$lastErr}", 'NETWORK_ERROR');
    }

    /**
     * Parse GTBank GAPS Account Statement XML response into array of transaction records.
     */
    private static function parseStatementXml(string $rawXml): array
    {
        $transactions = [];
        if (empty($rawXml)) {
            return $transactions;
        }

        // Iteratively decode HTML entities if double-encoded
        $decoded = $rawXml;
        $maxDecode = 3;
        while ($maxDecode-- > 0 && (str_contains($decoded, '&lt;') || str_contains($decoded, '&amp;'))) {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        // Extract <Transaction> blocks case-insensitively
        preg_match_all('/<Transaction>(.*?)<\/Transaction>/is', $decoded, $matches);

        if (!empty($matches[1])) {
            foreach ($matches[1] as $block) {
                $mDate = [];
                $mDebit = [];
                $mCredit = [];
                $mBalance = [];
                $mRemarks = [];
                $mRef = [];

                if (!preg_match('/<tra_date>(.*?)<\/tra_date>/is', $block, $mDate)) {
                    preg_match('/<val_date>(.*?)<\/val_date>/is', $block, $mDate);
                }
                preg_match('/<debit>(.*?)<\/debit>/is', $block, $mDebit);
                preg_match('/<credit>(.*?)<\/credit>/is', $block, $mCredit);
                preg_match('/<balance>(.*?)<\/balance>/is', $block, $mBalance);
                preg_match('/<remarks>(.*?)<\/remarks>/is', $block, $mRemarks);
                preg_match('/<reference>(.*?)<\/reference>/is', $block, $mRef);

                $creditStr = trim($mCredit[1] ?? '0');
                $debitStr  = trim($mDebit[1] ?? '0');

                $creditVal = (float)str_replace(',', '', $creditStr);
                $debitVal  = (float)str_replace(',', '', $debitStr);

                $amount    = $creditVal > 0 ? $creditVal : $debitVal;
                $type      = $creditVal > 0 ? TXN_CREDIT : TXN_DEBIT;
                $ref       = trim($mRef[1] ?? '');
                $remarks   = trim($mRemarks[1] ?? '');
                $rawDate   = trim($mDate[1] ?? '');
                $date      = $rawDate ? date('Y-m-d H:i:s', strtotime($rawDate)) : date('Y-m-d H:i:s');
                $balance   = (float)str_replace(',', '', trim($mBalance[1] ?? '0'));

                if ($ref !== '' || $amount > 0) {
                    $transactions[] = [
                        'reference'        => $ref ?: ('GAPS_' . time() . '_' . rand(100, 999)),
                        'type'             => $type,
                        'amount'           => $amount,
                        'sender_name'      => self::extractSenderName($remarks),
                        'narration'        => $remarks,
                        'transaction_date' => $date,
                        'balance_after'    => $balance,
                        'raw'              => [
                            'tra_date' => $rawDate,
                            'debit'    => $debitVal,
                            'credit'   => $creditVal,
                            'balance'  => $balance,
                            'remarks'  => $remarks,
                            'reference'=> $ref,
                        ]
                    ];
                }
            }
        }

        return $transactions;
    }

    private static function parseResponseXml(string $rawXml): array
    {
        $decoded = $rawXml;
        $maxDecode = 3;
        while ($maxDecode-- > 0 && (str_contains($decoded, '&lt;') || str_contains($decoded, '&amp;'))) {
            $decoded = html_entity_decode($decoded, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }

        $parsed = [];
        preg_match_all('/<([a-zA-Z0-9_]+)>([^<]*)<\/\1>/', $decoded, $matches, PREG_SET_ORDER);
        foreach ($matches as $m) {
            $key = strtolower(trim($m[1]));
            $val = trim($m[2]);
            $parsed[$key] = $val;
        }

        return $parsed;
    }

    /**
     * Extract sender name from GTBank GAPS transaction remarks.
     */
    private static function extractSenderName(string $remarks): string
    {
        if (preg_match('/from\s+([A-Za-z0-9\s\/]+)/i', $remarks, $m)) {
            return trim($m[1]);
        }
        return $remarks;
    }

    // ─── Logging & Error Envelope ─────────────────────────────────────────────

    private static function logCall(string $action, array $request, array $response): void
    {
        $level   = ($response['success'] ?? false) ? 'info' : 'error';
        $message = sprintf('GTBank GAPS [%s] → %s (code: %s)', strtoupper($action), $response['message'] ?? 'N/A', $response['code'] ?? 'N/A');

        writeLog(LOG_CHAN_GAPS, $level, $message, [
            'action'  => $action,
            'success' => $response['success'] ?? false,
            'code'    => $response['code'] ?? null,
            'http'    => $response['http'] ?? null,
        ]);
    }

    /**
     * Get CBN bank code by name
     */
    public static function getBankCodeByName(string $bankName): string
    {
        return getBankCodeByName($bankName);
    }

    /**
     * Get 9-digit GAPS Sort Code by name
     */
    public static function getBankSortCodeByName(string $bankName): string
    {
        return getBankSortCodeByName($bankName);
    }

    private static function errorResponse(string $message, string $code = 'ERROR'): array
    {
        return [
            'success'      => false,
            'data'         => [],
            'message'      => $message,
            'code'         => $code,
            'http'         => 0,
            'transactions' => [],
            'balance'      => 0.0,
            'total'        => 0,
            'page'         => 1,
        ];
    }
}

class_alias(GAPS::class, 'Ourcr\GAPS');

