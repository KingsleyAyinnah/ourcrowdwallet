<?php
namespace Ourcr;

defined('OURCR_ONLINE') or die('Direct access not permitted.');



/**
 * Validation — centralised input validation for OURCR ONLINE.
 *
 * All methods are static and return an empty string on success,
 * or a human-readable error message on failure.
 */
final class Validation
{
    private function __construct() {}

    // =========================================================================
    // Field-level validators (return '' on pass, message on fail)
    // =========================================================================

    /**
     * Assert that a value is non-empty (handles strings, arrays, ints, etc.).
     */
    public static function required(mixed $value, string $field = 'Field'): string
    {
        if ($value === null || $value === '' || $value === [] || (is_string($value) && trim($value) === '')) {
            return "{$field} is required.";
        }
        return '';
    }

    /**
     * Validate an e-mail address format.
     */
    public static function email(string $email): string
    {
        $email = trim($email);
        if ($email === '') {
            return 'Email address is required.';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'Please enter a valid email address.';
        }
        if (strlen($email) > 255) {
            return 'Email address must not exceed 255 characters.';
        }
        return '';
    }

    /**
     * Validate a Nigerian mobile phone number.
     * Accepts: 0801…, 0802…, +2348…, 2348…
     */
    public static function phone(string $phone): string
    {
        $phone = trim($phone);
        if ($phone === '') {
            return 'Phone number is required.';
        }

        // Normalise then validate
        $normalised = self::sanitizeNigerianPhone($phone);
        if ($normalised === '') {
            return 'Please enter a valid Nigerian phone number (e.g. 08012345678).';
        }
        return '';
    }

    /**
     * Validate a password.
     * Rules: min 8 chars, at least one uppercase, one lowercase, one digit.
     */
    public static function password(string $pass): string
    {
        if (strlen($pass) < 8) {
            return 'Password must be at least 8 characters long.';
        }
        if (!preg_match('/[A-Z]/', $pass)) {
            return 'Password must contain at least one uppercase letter.';
        }
        if (!preg_match('/[a-z]/', $pass)) {
            return 'Password must contain at least one lowercase letter.';
        }
        if (!preg_match('/[0-9]/', $pass)) {
            return 'Password must contain at least one number.';
        }
        return '';
    }

    /**
     * Validate a 4-digit numeric PIN.
     */
    public static function pin(string $pin): string
    {
        if (!preg_match('/^\d{4}$/', $pin)) {
            return 'PIN must be exactly 4 digits.';
        }
        return '';
    }

    /**
     * Validate a monetary amount.
     *
     * @param mixed  $amount  The amount to validate (string or numeric)
     * @param float  $min     Minimum allowed value (inclusive)
     * @param float  $max     Maximum allowed value (inclusive)
     * @param string $field   Field label used in error messages
     */
    public static function amount(
        mixed $amount,
        float $min = 0,
        float $max = PHP_FLOAT_MAX,
        string $field = 'Amount'
    ): string {
        if ($amount === null || $amount === '') {
            return "{$field} is required.";
        }
        if (!is_numeric($amount)) {
            return "{$field} must be a valid number.";
        }
        $val = (float) $amount;
        if ($val < $min) {
            return sprintf('%s must be at least ₦%s.', $field, number_format($min, 2));
        }
        if ($val > $max) {
            return sprintf('%s must not exceed ₦%s.', $field, number_format($max, 2));
        }
        return '';
    }

    /**
     * Assert minimum string length.
     */
    public static function minLength(string $val, int $min, string $field = 'Field'): string
    {
        if (strlen(trim($val)) < $min) {
            return "{$field} must be at least {$min} characters long.";
        }
        return '';
    }

    /**
     * Assert maximum string length.
     */
    public static function maxLength(string $val, int $max, string $field = 'Field'): string
    {
        if (strlen(trim($val)) > $max) {
            return "{$field} must not exceed {$max} characters.";
        }
        return '';
    }

    /**
     * Assert that a value contains only alphanumeric characters.
     */
    public static function alphanumeric(string $val, string $field = 'Field'): string
    {
        if (!ctype_alnum($val)) {
            return "{$field} must contain only letters and numbers.";
        }
        return '';
    }

    /**
     * Validate a username.
     * Rules: 3–50 characters, only letters, digits, underscores.
     */
    public static function username(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return 'Username is required.';
        }
        if (strlen($username) < 3) {
            return 'Username must be at least 3 characters long.';
        }
        if (strlen($username) > 50) {
            return 'Username must not exceed 50 characters.';
        }
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
            return 'Username may only contain letters, numbers, and underscores.';
        }
        return '';
    }

    // =========================================================================
    // Sanitizer / Normaliser helpers
    // =========================================================================

    /**
     * Normalise a Nigerian phone number to the 11-digit local format (08XXXXXXXXX).
     * Returns an empty string if normalisation fails.
     */
    public static function sanitizeNigerianPhone(string $phone): string
    {
        // Strip whitespace, dashes, dots, parentheses
        $phone = preg_replace('/[\s\-().]+/', '', $phone);

        // +234XXXXXXXXXX  →  08XXXXXXXXXX
        if (str_starts_with($phone, '+234')) {
            $phone = '0' . substr($phone, 4);
        }

        // 234XXXXXXXXXX  →  08XXXXXXXXXX
        if (str_starts_with($phone, '234') && strlen($phone) === 13) {
            $phone = '0' . substr($phone, 3);
        }

        // Must now be 11 digits starting with 0
        if (!preg_match('/^0[789][01]\d{8}$/', $phone)) {
            return '';
        }

        return $phone;
    }

    // =========================================================================
    // CSRF
    // =========================================================================

    /**
     * Verify the CSRF token for the current request.
     * Calls requireCsrf() which aborts with a 403 if invalid.
     * Returns '' on success (execution never reaches the return on failure).
     */
    public static function csrf(): string
    {
        requireCsrf();
        return '';
    }

    // =========================================================================
    // Batch runner
    // =========================================================================

    /**
     * Run multiple validation rules in one call.
     *
     * Each entry in $rules must be an associative array with:
     *   'field'  => string           — human-readable field name (used as key)
     *   'value'  => mixed            — the value to validate
     *   'rules'  => array<string|array>  — ordered list of rules to apply
     *
     * Supported rule strings (with optional parameters via array form):
     *   'required', 'email', 'phone', 'password', 'pin', 'username',
     *   'alphanumeric', 'csrf',
     *   ['minLength', 5],
     *   ['maxLength', 100],
     *   ['amount', 100, 500000],
     *
     * Returns an associative array of [ fieldName => firstErrorMessage ].
     * Empty array means all passed.
     *
     * Example:
     *   $errors = Validation::all([
     *       ['field' => 'email',    'value' => $email,    'rules' => ['required', 'email']],
     *       ['field' => 'password', 'value' => $password, 'rules' => ['required', 'password']],
     *       ['field' => 'amount',   'value' => $amount,   'rules' => [['amount', 100, 50000]]],
     *   ]);
     */
    public static function all(array $rules): array
    {
        $errors = [];

        foreach ($rules as $entry) {
            $fieldKey  = $entry['field']  ?? 'field';
            $value     = $entry['value']  ?? '';
            $ruleList  = $entry['rules']  ?? [];

            foreach ($ruleList as $rule) {
                $params = [];
                if (is_array($rule)) {
                    $params = array_slice($rule, 1);
                    $rule   = $rule[0];
                }

                $error = match ($rule) {
                    'required'    => self::required($value, $fieldKey),
                    'email'       => self::email((string) $value),
                    'phone'       => self::phone((string) $value),
                    'password'    => self::password((string) $value),
                    'pin'         => self::pin((string) $value),
                    'username'    => self::username((string) $value),
                    'alphanumeric'=> self::alphanumeric((string) $value, $fieldKey),
                    'csrf'        => self::csrf(),
                    'minLength'   => self::minLength((string) $value, (int) ($params[0] ?? 1), $fieldKey),
                    'maxLength'   => self::maxLength((string) $value, (int) ($params[0] ?? 255), $fieldKey),
                    'amount'      => self::amount(
                                         $value,
                                         (float) ($params[0] ?? 0),
                                         (float) ($params[1] ?? PHP_FLOAT_MAX),
                                         $fieldKey
                                     ),
                    default       => '',
                };

                if ($error !== '') {
                    $errors[$fieldKey] = $error;
                    break; // stop at first error for this field
                }
            }
        }

        return $errors;
    }
}
