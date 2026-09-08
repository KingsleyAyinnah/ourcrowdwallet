<?php
namespace Ourcr;

/**
 * OURCR ONLINE - GTBank GAPS Account Name Resolver
 *
 * Resolves 10-digit Nigerian bank account numbers to verified account holder names
 * exclusively via GTBank GAPS Web Services (GetAccountInGTB_Enc / GetAccountInOtherBank_Enc).
 */

defined('OURCR_ONLINE') or die('Direct access not permitted.');

class AccountResolver
{
    /**
     * Resolve account holder name exclusively via GTBank GAPS.
     *
     * @param string $accountNumber 10-digit NUBAN account number
     * @param string $bankName      Recipient bank name
     * @return array Standard response [ 'success' => bool, 'account_name' => string, 'message' => string ]
     */
    public static function resolveAccountName(string $accountNumber, string $bankName): array
    {
        $accountNumber = preg_replace('/\D/', '', trim($accountNumber));
        if (strlen($accountNumber) !== 10) {
            return ['success' => false, 'message' => 'Account number must be exactly 10 digits.'];
        }

        $bankCode = getBankCodeByName($bankName);
        if (empty($bankCode)) {
            return ['success' => false, 'message' => 'Unsupported bank selection.'];
        }

        try {
            $gapsRes = \Ourcr\GAPS\GAPS::resolveAccountName($accountNumber, $bankName);
            if (!empty($gapsRes['success']) && !empty($gapsRes['account_name'])) {
                return [
                    'success'      => true,
                    'account_name' => strtoupper(trim($gapsRes['account_name']))
                ];
            }

            return [
                'success' => false,
                'message' => $gapsRes['message'] ?? 'Could not verify account name with GTBank GAPS.'
            ];
        } catch (\Throwable $e) {
            writeLog('wallet', 'error', 'GAPS account resolve exception: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Account verification error: ' . $e->getMessage()
            ];
        }
    }
}
