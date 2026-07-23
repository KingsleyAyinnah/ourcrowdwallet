<?php
/**
 * OURCR ONLINE - VTpass Lookups API Endpoint
 * Returns JSON only. Never exposes raw PHP errors to the client.
 */

// Buffer ALL output so no PHP warnings/notices can corrupt the JSON response
ob_start();

define('OURCR_ONLINE', true);
require_once dirname(__DIR__) . '/config/config.php';

// Discard any buffered output (warnings, notices from includes)
ob_clean();

header('Content-Type: application/json; charset=UTF-8');

// Check authentication
if (!isLoggedIn()) {
    jsonResponse(['success' => false, 'message' => 'Unauthorized access.'], 401);
}

$action = get('action');

try {
    switch ($action) {
        case 'calculate_discount':
            $serviceId   = sanitizeString(get('serviceId'));
            $serviceType = sanitizeString(get('serviceType'));
            $amount      = (float)get('amount');
            $quantity    = max(1, (int)get('quantity', 1));
            $variation   = sanitizeString(get('variation'));

            $calc = calculateUserVtuDiscount($serviceId, $amount, $quantity, $variation, $serviceType);
            jsonResponse([
                'success'         => true,
                'amount'          => $amount,
                'user_commission' => $calc['user_commission'],
                'amount_to_pay'   => $calc['amount_to_pay'],
                'formatted_comm'  => formatMoney($calc['user_commission']),
                'formatted_pay'   => formatMoney($calc['amount_to_pay']),
            ]);
            break;

        case 'variations':
            $serviceId = sanitizeString(get('serviceId'));
            if (empty($serviceId)) {
                jsonResponse(['success' => false, 'message' => 'Service ID is required.'], 400);
            }

            $result = Ourcr\VTpass::getVariations($serviceId);
            if ($result['success']) {
                jsonResponse([
                    'success'    => true,
                    'variations' => $result['data']['variations'] ?? []
                ]);
            } else {
                writeLog(LOG_CHAN_VTPASS, 'warning', 'getVariations failed', [
                    'serviceId' => $serviceId,
                    'message'   => $result['message'] ?? 'N/A',
                ]);
                jsonResponse(['success' => false, 'message' => 'Could not load plans. Please refresh and try again.'], 400);
            }
            break;

        case 'verify_meter':
            $serviceId = sanitizeString(get('serviceId'));
            $meterNo   = sanitizeString(get('meterNo'));
            $type      = sanitizeString(get('meterType', 'prepaid'));

            if (empty($serviceId) || empty($meterNo)) {
                jsonResponse(['success' => false, 'message' => 'Electricity provider and meter number are required.'], 400);
            }

            $result = Ourcr\VTpass::verifyMeter($serviceId, $meterNo, $type);
            if ($result['success']) {
                jsonResponse([
                    'success'       => true,
                    'customer_name' => $result['data']['Customer_Name'] ?? 'N/A',
                    'address'       => $result['data']['Address'] ?? 'N/A',
                ]);
            } else {
                writeLog(LOG_CHAN_VTPASS, 'warning', 'verifyMeter failed', [
                    'serviceId' => $serviceId,
                    'meterNo'   => $meterNo,
                    'code'      => $result['code'] ?? 'N/A',
                    'message'   => $result['message'] ?? 'N/A',
                ]);
                jsonResponse(['success' => false, 'message' => 'Meter verification failed. Please check the meter number and try again.'], 400);
            }
            break;

        case 'verify_card':
            $serviceId   = sanitizeString(get('serviceId'));
            $smartCardNo = sanitizeString(get('smartCardNo'));

            if (empty($serviceId) || empty($smartCardNo)) {
                jsonResponse(['success' => false, 'message' => 'Cable provider and smart card number are required.'], 400);
            }

            $result = Ourcr\VTpass::verifySmartCard($serviceId, $smartCardNo);
            if ($result['success']) {
                jsonResponse([
                    'success'       => true,
                    'customer_name' => $result['data']['Customer_Name'] ?? 'N/A',
                    'status'        => $result['data']['Status'] ?? 'N/A',
                ]);
            } else {
                writeLog(LOG_CHAN_VTPASS, 'warning', 'verifySmartCard failed', [
                    'serviceId'   => $serviceId,
                    'smartCardNo' => $smartCardNo,
                    'code'        => $result['code'] ?? 'N/A',
                    'message'     => $result['message'] ?? 'N/A',
                ]);
                jsonResponse(['success' => false, 'message' => 'Smart card verification failed. Please check the card number and try again.'], 400);
            }
            break;

        case 'verify_jamb':
            $profileId     = sanitizeString(get('profileId'));
            $variationCode = sanitizeString(get('variationCode'));

            if (empty($profileId) || empty($variationCode)) {
                jsonResponse(['success' => false, 'message' => 'Profile ID and variation code are required.'], 400);
            }

            $result = Ourcr\VTpass::verifyJambProfile($profileId, $variationCode);
            if ($result['success']) {
                jsonResponse([
                    'success'       => true,
                    'customer_name' => $result['data']['Customer_Name'] ?? 'N/A',
                ]);
            } else {
                writeLog(LOG_CHAN_VTPASS, 'warning', 'verifyJambProfile failed', [
                    'profileId'     => $profileId,
                    'variationCode' => $variationCode,
                    'code'          => $result['code'] ?? 'N/A',
                    'message'       => $result['message'] ?? 'N/A',
                ]);
                jsonResponse(['success' => false, 'message' => 'JAMB Profile ID verification failed. Please check the ID and try again.'], 400);
            }
            break;

        default:
            jsonResponse(['success' => false, 'message' => 'Invalid action requested.'], 404);
            break;
    }

} catch (Throwable $e) {
    // Log full error silently, return generic JSON error to client
    writeLog(LOG_CHAN_ERROR, 'error', 'VTpass API endpoint exception: ' . $e->getMessage(), [
        'action' => $action,
        'file'   => $e->getFile(),
        'line'   => $e->getLine(),
        'trace'  => substr($e->getTraceAsString(), 0, 1000),
    ]);
    // Clean any buffered output (error messages etc.) before returning JSON
    ob_clean();
    jsonResponse(['success' => false, 'message' => 'Service temporarily unavailable. Please try again.'], 500);
}
