<?php
/**
 * WalleoPay — fichier de rappel (webhook) pour WHMCS.
 *
 * Règle absolue : une facture n'est JAMAIS créditée sur la seule foi du
 * webhook. L'ordre de vérification est le suivant :
 *   1. signature HMAC vérifiée avec hash_equals(),
 *   2. horodatage « t= » de moins de 300 secondes,
 *   3. re-interrogation de GET /payments/{id} — seul « succeeded » est accepté,
 *   4. contrôle du montant et de la devise face à la facture WHMCS,
 *   5. checkCbInvoiceID() / checkCbTransID() puis addInvoicePayment().
 *
 * Compatible PHP 7.4+, aucune dépendance Composer.
 *
 * @package    WHMCS
 * @author     WalleoPay
 * @version    1.0.0
 */

require_once __DIR__ . '/../../../init.php';
require_once __DIR__ . '/../../../includes/gatewayfunctions.php';
require_once __DIR__ . '/../../../includes/invoicefunctions.php';

/** Tolérance d'horodatage, en secondes. */
define('WALLEOPAY_CB_TOLERANCE', 300);

/** Version du module, pour l'en-tête User-Agent. */
define('WALLEOPAY_CB_VERSION', '1.0.0');

$gatewayModuleName = basename(__FILE__, '.php');
$gatewayParams = getGatewayVariables($gatewayModuleName);

if (!$gatewayParams['type']) {
    http_response_code(503);
    die("Module Not Activated");
}

$rawBody = file_get_contents('php://input');
if (!is_string($rawBody)) {
    $rawBody = '';
}

$signatureHeader = walleopay_cb_signatureHeader();

// ---------------------------------------------------------------------------
// 1. Signature
// ---------------------------------------------------------------------------
$webhookSecret = isset($gatewayParams['webhookSecret']) ? trim((string) $gatewayParams['webhookSecret']) : '';

if ($webhookSecret === '') {
    walleopay_cb_log($gatewayParams, $rawBody, 'Secret de webhook non configuré', 'Unsuccessful');
    walleopay_cb_respond(500, 'webhook_secret_missing', 'Secret de webhook non configuré dans WHMCS.');
}

if ($rawBody === '' || $signatureHeader === '') {
    walleopay_cb_respond(400, 'invalid_request', 'Corps ou signature manquants.');
}

$signature = walleopay_cb_parseSignature($signatureHeader);

if ($signature['timestamp'] <= 0 || $signature['v1'] === '') {
    walleopay_cb_respond(401, 'invalid_signature', 'En-tête de signature illisible.');
}

$expected = hash_hmac('sha256', $signature['timestamp'] . '.' . $rawBody, $webhookSecret);

if (!hash_equals($expected, $signature['v1'])) {
    walleopay_cb_log($gatewayParams, $rawBody, 'Signature invalide', 'Unsuccessful');
    walleopay_cb_respond(401, 'invalid_signature', 'Signature invalide.');
}

// ---------------------------------------------------------------------------
// 2. Fraîcheur de l'horodatage
// ---------------------------------------------------------------------------
if (abs(time() - $signature['timestamp']) > WALLEOPAY_CB_TOLERANCE) {
    walleopay_cb_log($gatewayParams, $rawBody, 'Horodatage de signature hors tolérance', 'Unsuccessful');
    walleopay_cb_respond(401, 'stale_signature', 'Horodatage de signature trop ancien.');
}

$payload = json_decode($rawBody, true);

if (!is_array($payload) || !isset($payload['data']) || !is_array($payload['data'])) {
    walleopay_cb_respond(400, 'invalid_request', 'Corps JSON invalide.');
}

$event = isset($payload['event']) ? (string) $payload['event'] : '';
$notified = $payload['data'];
$paymentId = isset($notified['id']) ? (string) $notified['id'] : '';

// Les événements de versement ne concernent aucune facture.
if (strpos($event, 'payout.') === 0) {
    walleopay_cb_respond(200, 'ignored', 'Événement de versement ignoré.');
}

if ($paymentId === '') {
    walleopay_cb_respond(400, 'invalid_request', 'Identifiant de paiement absent.');
}

// ---------------------------------------------------------------------------
// 3. Re-interrogation de l'API : c'est elle qui fait foi, pas le webhook
// ---------------------------------------------------------------------------
$lookup = walleopay_cb_apiGet($gatewayParams, '/payments/' . rawurlencode($paymentId));

if (!$lookup['ok']) {
    walleopay_cb_log(
        $gatewayParams,
        $notified,
        'Vérification impossible auprès de WalleoPay : ' . $lookup['message'],
        'Unsuccessful'
    );
    walleopay_cb_respond(502, 'verification_failed', 'Vérification auprès de WalleoPay impossible.');
}

$payment = $lookup['data'];
$status = isset($payment['status']) ? (string) $payment['status'] : '';
$transactionId = isset($payment['id']) ? (string) $payment['id'] : $paymentId;
$invoiceId = walleopay_cb_invoiceId($payment);

if ($invoiceId <= 0) {
    walleopay_cb_log($gatewayParams, $payment, 'Facture introuvable dans les métadonnées du paiement', 'Unsuccessful');
    walleopay_cb_respond(400, 'invalid_request', 'Aucune facture rattachée à ce paiement.');
}

// Statuts non créditants : journalisation seulement.
if ($status === 'awaiting_confirmation') {
    walleopay_cb_log(
        $gatewayParams,
        $payment,
        'Paiement en attente de rapprochement manuel (facture #' . $invoiceId . ') — aucune facture créditée',
        'Pending'
    );
    walleopay_cb_respond(200, 'pending', 'Paiement en attente de confirmation.');
}

if (in_array($status, array('failed', 'cancelled', 'expired'), true)) {
    walleopay_cb_log(
        $gatewayParams,
        $payment,
        'Paiement ' . $status . ' pour la facture #' . $invoiceId,
        'Unsuccessful'
    );
    walleopay_cb_respond(200, 'not_paid', 'Paiement non abouti, aucune facture créditée.');
}

if ($status !== 'succeeded') {
    // L'événement annonçait un paiement que l'API ne confirme pas (encore) :
    // on répond en erreur pour que WalleoPay réessaie plus tard.
    walleopay_cb_log(
        $gatewayParams,
        $payment,
        'Statut « ' . $status .' » à la re-vérification : facture #' . $invoiceId . ' non créditée',
        'Unsuccessful'
    );
    walleopay_cb_respond(409, 'not_succeeded', 'Le paiement n\'est pas confirmé comme réussi.');
}

// ---------------------------------------------------------------------------
// 4. Montant et devise attendus par la facture
// ---------------------------------------------------------------------------
$paidAmount = isset($payment['amount']) ? (int) $payment['amount'] : 0;
$paidCurrency = isset($payment['currency']) ? strtoupper((string) $payment['currency']) : '';
$invoice = walleopay_cb_invoiceSnapshot($invoiceId);

if ($invoice === null) {
    walleopay_cb_log($gatewayParams, $payment, 'Facture #' . $invoiceId . ' illisible en base', 'Unsuccessful');
    walleopay_cb_respond(500, 'invoice_unreadable', 'Facture WHMCS illisible.');
}

// WHMCS travaille en décimal, WalleoPay en entier de francs : on arrondit
// le montant WHMCS avant de comparer.
$expectedTotal = (int) round($invoice['total']);
$expectedBalance = (int) round($invoice['balance']);

if ($paidAmount !== $expectedTotal && $paidAmount !== $expectedBalance) {
    walleopay_cb_log(
        $gatewayParams,
        $payment,
        'Montant incohérent : ' . $paidAmount . ' reçu, ' . $expectedTotal
        . ' (total) ou ' . $expectedBalance . ' (solde) attendus pour la facture #' . $invoiceId,
        'Unsuccessful'
    );
    walleopay_cb_respond(400, 'amount_mismatch', 'Montant incohérent avec la facture.');
}

if ($invoice['currency'] !== '' && $paidCurrency !== '' && $invoice['currency'] !== $paidCurrency) {
    walleopay_cb_log(
        $gatewayParams,
        $payment,
        'Devise incohérente : ' . $paidCurrency . ' reçue, ' . $invoice['currency']
        . ' attendue pour la facture #' . $invoiceId,
        'Unsuccessful'
    );
    walleopay_cb_respond(400, 'currency_mismatch', 'Devise incohérente avec la facture.');
}

// ---------------------------------------------------------------------------
// 5. Contrôles WHMCS puis crédit de la facture
// ---------------------------------------------------------------------------
$invoiceId = checkCbInvoiceID($invoiceId, $gatewayParams['name']);
checkCbTransID($transactionId);

$fee = isset($payment['fee']) ? (float) $payment['fee'] : 0.0;

addInvoicePayment($invoiceId, $transactionId, (float) $paidAmount, $fee, $gatewayModuleName);
logTransaction($gatewayParams['name'], $payment, 'Successful');

walleopay_cb_respond(200, 'processed', 'Paiement enregistré.');

// ---------------------------------------------------------------------------
// Fonctions utilitaires
// ---------------------------------------------------------------------------

/**
 * Récupère l'en-tête de signature, avec repli sur getallheaders().
 *
 * @return string
 */
function walleopay_cb_signatureHeader()
{
    if (!empty($_SERVER['HTTP_X_WALLEOPAY_SIGNATURE'])) {
        return (string) $_SERVER['HTTP_X_WALLEOPAY_SIGNATURE'];
    }

    if (function_exists('getallheaders')) {
        $headers = getallheaders();

        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (strtolower($name) === 'x-walleopay-signature') {
                    return (string) $value;
                }
            }
        }
    }

    return '';
}

/**
 * Décompose « t=<timestamp>,v1=<hmac> ».
 *
 * @param string $header
 *
 * @return array
 */
function walleopay_cb_parseSignature($header)
{
    $parsed = array('timestamp' => 0, 'v1' => '');

    foreach (explode(',', $header) as $part) {
        $pair = explode('=', trim($part), 2);

        if (count($pair) !== 2) {
            continue;
        }

        $key = strtolower(trim($pair[0]));
        $value = trim($pair[1]);

        if ($key === 't') {
            $parsed['timestamp'] = (int) $value;
        } elseif ($key === 'v1' && $parsed['v1'] === '') {
            $parsed['v1'] = $value;
        }
    }

    return $parsed;
}

/**
 * Identifiant de facture : métadonnées d'abord, référence « invoice-<id> » ensuite.
 *
 * @param array $payment
 *
 * @return int
 */
function walleopay_cb_invoiceId($payment)
{
    if (isset($payment['metadata']) && is_array($payment['metadata'])
        && isset($payment['metadata']['invoice_id'])
    ) {
        $fromMeta = (int) $payment['metadata']['invoice_id'];

        if ($fromMeta > 0) {
            return $fromMeta;
        }
    }

    $reference = isset($payment['reference']) ? (string) $payment['reference'] : '';

    if (preg_match('/^invoice-(\d+)/', $reference, $matches)) {
        return (int) $matches[1];
    }

    return 0;
}

/**
 * Total, solde restant dû et devise d'une facture WHMCS.
 *
 * @param int $invoiceId
 *
 * @return array|null
 */
function walleopay_cb_invoiceSnapshot($invoiceId)
{
    if (!class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
        return null;
    }

    try {
        $invoice = \Illuminate\Database\Capsule\Manager::table('tblinvoices')
            ->where('id', $invoiceId)
            ->first();

        if (!$invoice) {
            return null;
        }

        $total = (float) $invoice->total;
        $credit = isset($invoice->credit) ? (float) $invoice->credit : 0.0;

        $paid = (float) \Illuminate\Database\Capsule\Manager::table('tblaccounts')
            ->where('invoiceid', $invoiceId)
            ->sum(\Illuminate\Database\Capsule\Manager::raw('amountin - amountout'));

        $currency = '';

        if (isset($invoice->userid)) {
            $currency = (string) \Illuminate\Database\Capsule\Manager::table('tblclients')
                ->join('tblcurrencies', 'tblcurrencies.id', '=', 'tblclients.currency')
                ->where('tblclients.id', (int) $invoice->userid)
                ->value('tblcurrencies.code');
        }

        return array(
            'total' => $total,
            'balance' => $total - $credit - $paid,
            'currency' => strtoupper($currency),
        );
    } catch (\Exception $e) {
        return null;
    }
}

/**
 * GET authentifié sur l'API WalleoPay, en cURL natif.
 *
 * @param array  $gatewayParams
 * @param string $path
 *
 * @return array ok / data / message
 */
function walleopay_cb_apiGet($gatewayParams, $path)
{
    $result = array('ok' => false, 'data' => array(), 'message' => '');

    if (!function_exists('curl_init')) {
        $result['message'] = 'Extension cURL absente.';

        return $result;
    }

    $mode = (isset($gatewayParams['mode']) && $gatewayParams['mode'] === 'live') ? 'live' : 'test';
    $secretKey = trim((string) ($mode === 'live'
        ? (isset($gatewayParams['liveSecretKey']) ? $gatewayParams['liveSecretKey'] : '')
        : (isset($gatewayParams['testSecretKey']) ? $gatewayParams['testSecretKey'] : '')));

    if ($secretKey === '') {
        $result['message'] = 'Clé secrète absente pour le mode ' . $mode . '.';

        return $result;
    }

    $base = isset($gatewayParams['apiBaseUrl']) ? trim((string) $gatewayParams['apiBaseUrl']) : '';

    if ($base === '') {
        $base = 'https://walleopay.com/api/v1';
    }

    $url = rtrim($base, '/') . $path;

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => 'GET',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => array(
            'Authorization: Bearer ' . $secretKey,
            'Accept: application/json',
            'User-Agent: WalleoPay-WHMCS/' . WALLEOPAY_CB_VERSION,
        ),
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ));

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = is_string($raw) ? substr($raw, 0, $headerSize) : '';
    $rawBody = is_string($raw) ? substr($raw, $headerSize) : '';

    if ($raw === false || $curlError !== '') {
        $result['message'] = $curlError !== '' ? $curlError : 'Connexion impossible.';
        walleopay_cb_logModule($gatewayParams, 'GET ' . $path, $curlError, $result, $secretKey);

        return $result;
    }

    $decoded = json_decode($rawBody, true);

    if (!is_array($decoded)) {
        $decoded = array();
    }

    if ($httpCode === 429) {
        $retryAfter = 0;

        foreach (preg_split('/\r?\n/', $rawHeaders) as $line) {
            $pair = explode(':', $line, 2);

            if (count($pair) === 2 && strtolower(trim($pair[0])) === 'retry-after') {
                $retryAfter = (int) trim($pair[1]);
            }
        }

        $result['message'] = 'Limite de débit atteinte, réessayer dans ' . $retryAfter . ' s.';
        walleopay_cb_logModule($gatewayParams, 'GET ' . $path, $rawBody, $result, $secretKey);

        return $result;
    }

    if ($httpCode >= 200 && $httpCode < 300 && isset($decoded['data']) && is_array($decoded['data'])) {
        $result['ok'] = true;
        $result['data'] = $decoded['data'];
    } else {
        if (isset($decoded['error']['message'])) {
            $errorType = isset($decoded['error']['type']) ? (string) $decoded['error']['type'] : 'api_error';
            $result['message'] = $errorType . ' — ' . (string) $decoded['error']['message'];
        } else {
            $result['message'] = 'HTTP ' . $httpCode;
        }
    }

    walleopay_cb_logModule($gatewayParams, 'GET ' . $path, $rawBody, $result, $secretKey);

    return $result;
}

/**
 * Journalise l'appel API dans le Module Log, clé secrète masquée.
 *
 * @param array  $gatewayParams
 * @param string $action
 * @param mixed  $response
 * @param array  $processed
 * @param string $secretKey
 *
 * @return void
 */
function walleopay_cb_logModule($gatewayParams, $action, $response, $processed, $secretKey)
{
    if (empty($gatewayParams['logging']) || !function_exists('logModuleCall')) {
        return;
    }

    $replace = array();

    if ($secretKey !== '') {
        $replace[] = $secretKey;
    }

    logModuleCall('walleopay', $action, 'callback', is_array($response) ? $response : (string) $response, $processed, $replace);
}

/**
 * Trace dans le Gateway Log (Billing > Gateway Log).
 *
 * @param array  $gatewayParams
 * @param mixed  $data
 * @param string $note
 * @param string $status
 *
 * @return void
 */
function walleopay_cb_log($gatewayParams, $data, $note, $status)
{
    if (!function_exists('logTransaction')) {
        return;
    }

    $entry = array('note' => $note);

    if (is_array($data)) {
        $entry = array_merge($entry, $data);
    } elseif (is_string($data) && $data !== '') {
        $entry['payload'] = $data;
    }

    logTransaction($gatewayParams['name'], $entry, $status);
}

/**
 * Répond en JSON avec un code HTTP explicite, puis arrête le script.
 *
 * @param int    $code
 * @param string $status
 * @param string $message
 *
 * @return void
 */
function walleopay_cb_respond($code, $status, $message)
{
    http_response_code($code);

    if (!headers_sent()) {
        header('Content-Type: application/json; charset=utf-8');
    }

    echo json_encode(array(
        'received' => ($code >= 200 && $code < 300),
        'status' => $status,
        'message' => $message,
    ));

    exit;
}
