<?php
/**
 * WalleoPay — passerelle de paiement tierce pour WHMCS.
 *
 * Accepte MTN Mobile Money, Orange Money et la carte bancaire au Cameroun
 * via l'API marchand WalleoPay (https://walleopay.com/api/v1).
 *
 * Le client est redirigé vers la page de paiement hébergée par WalleoPay ;
 * la facture n'est créditée que par le fichier de rappel
 * modules/gateways/callback/walleopay.php, après re-vérification du
 * paiement auprès de l'API.
 *
 * Compatible PHP 7.4+, aucune dépendance Composer.
 *
 * @package    WHMCS
 * @author     WalleoPay
 * @version    1.0.0
 */

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

if (!defined('WALLEOPAY_WHMCS_VERSION')) {
    define('WALLEOPAY_WHMCS_VERSION', '1.0.0');
}

if (!defined('WALLEOPAY_DEFAULT_API')) {
    define('WALLEOPAY_DEFAULT_API', 'https://walleopay.com/api/v1');
}

/**
 * Métadonnées du module.
 *
 * @return array
 */
function walleopay_MetaData()
{
    return array(
        'DisplayName' => 'WalleoPay (MTN MoMo, Orange Money, Carte)',
        'APIVersion' => '1.1',
        'DisableLocalCreditCardInput' => true,
        'TokenisedStorage' => false,
    );
}

/**
 * Champs de configuration affichés dans Setup > Payments > Payment Gateways.
 *
 * @return array
 */
function walleopay_config()
{
    $callbackUrl = walleopay_callbackUrl();

    return array(
        'FriendlyName' => array(
            'Type' => 'System',
            'Value' => 'WalleoPay (MTN MoMo, Orange Money, Carte)',
        ),
        'mode' => array(
            'FriendlyName' => 'Mode',
            'Type' => 'dropdown',
            'Options' => array(
                'test' => 'Test (clé sk_test_…)',
                'live' => 'Production (clé sk_live_…)',
            ),
            'Default' => 'test',
            'Description' => 'Le mode découle de la clé utilisée. En test, aucun argent réel ne circule.',
        ),
        'testSecretKey' => array(
            'FriendlyName' => 'Clé secrète de test',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Clé « sk_test_… » de votre tableau de bord WalleoPay. Ne la publiez jamais côté navigateur.',
        ),
        'liveSecretKey' => array(
            'FriendlyName' => 'Clé secrète de production',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Clé « sk_live_… » de votre tableau de bord WalleoPay.',
        ),
        'webhookSecret' => array(
            'FriendlyName' => 'Secret de webhook',
            'Type' => 'password',
            'Size' => '60',
            'Description' => 'Secret « whsec_… » affiché dans votre tableau de bord WalleoPay. Il sert à vérifier la signature des notifications.',
        ),
        'apiBaseUrl' => array(
            'FriendlyName' => 'URL de base de l\'API',
            'Type' => 'text',
            'Size' => '60',
            'Default' => WALLEOPAY_DEFAULT_API,
            'Description' => 'À ne changer que pour pointer vers un environnement local (ex. http://127.0.0.1:8000/api/v1).',
        ),
        'payButtonText' => array(
            'FriendlyName' => 'Texte du bouton',
            'Type' => 'text',
            'Size' => '40',
            'Default' => 'Payer avec WalleoPay',
            'Description' => 'Libellé affiché au client sur la facture.',
        ),
        'logging' => array(
            'FriendlyName' => 'Journalisation',
            'Type' => 'yesno',
            'Description' => 'Enregistre les appels API dans Utilities > Logs > Module Log (la clé secrète y est masquée).',
        ),
        'callbackHelp' => array(
            'FriendlyName' => 'URL de rappel (webhook)',
            'Type' => 'text',
            'Size' => '80',
            'Default' => $callbackUrl,
            'Description' => 'Copiez cette URL dans votre tableau de bord WalleoPay (champ « URL de notification »). Champ informatif : sa valeur n\'est pas utilisée par le module.',
        ),
    );
}

/**
 * Génère le bouton de paiement affiché sur la facture.
 *
 * @param array $params Paramètres fournis par WHMCS.
 *
 * @return string HTML
 */
function walleopay_link($params)
{
    $invoiceId = (int) $params['invoiceid'];
    $currency = strtoupper(trim((string) $params['currency']));
    $amount = (int) round((float) $params['amount']);
    $buttonText = trim((string) $params['payButtonText']);
    if ($buttonText === '') {
        $buttonText = 'Payer avec WalleoPay';
    }

    if (walleopay_secretKey($params) === '') {
        return walleopay_notice(
            'WalleoPay n\'est pas configuré : la clé secrète du mode « '
            . walleopay_mode($params) . ' » est manquante. Contactez l\'administrateur du site.'
        );
    }

    if ($currency === '') {
        $currency = 'XAF';
    }

    if ($amount < 100) {
        return walleopay_notice('Le montant minimum accepté par WalleoPay est de 100 F CFA.');
    }

    if ($amount > 1000000) {
        return walleopay_notice('Le montant maximum accepté par WalleoPay est de 1 000 000 F CFA.');
    }

    $reference = walleopay_reference($params);
    $payload = walleopay_paymentPayload($params, $reference, $amount, $currency);

    $response = walleopay_apiRequest(
        $params,
        'POST',
        '/payments',
        $payload,
        walleopay_idempotencyKey($params, $reference, $amount, $currency)
    );

    if ($response['ok'] && !empty($response['data']['checkout_url'])) {
        return walleopay_button($response['data']['checkout_url'], $buttonText);
    }

    // Une facture consultée plusieurs fois réutilise la même référence :
    // on récupère le paiement déjà ouvert plutôt que d'en créer un second.
    if ($response['ok'] === false && walleopay_isDuplicateReference($response)) {
        $existing = walleopay_apiRequest($params, 'GET', '/payments/' . rawurlencode($reference));

        if ($existing['ok']) {
            $data = $existing['data'];
            $status = isset($data['status']) ? (string) $data['status'] : '';

            if ($status === 'succeeded') {
                return walleopay_notice('Cette facture a déjà été réglée via WalleoPay. Actualisez la page dans quelques instants.');
            }

            if ($status === 'awaiting_confirmation') {
                return walleopay_notice('Un paiement WalleoPay est en cours de rapprochement pour cette facture. Aucun nouveau paiement n\'est nécessaire.');
            }

            $sameAmount = isset($data['amount']) && (int) $data['amount'] === $amount;
            $sameCurrency = isset($data['currency']) && strtoupper((string) $data['currency']) === $currency;

            if (in_array($status, array('pending', 'processing'), true)
                && $sameAmount && $sameCurrency && !empty($data['checkout_url'])
            ) {
                return walleopay_button($data['checkout_url'], $buttonText);
            }
        }

        // Paiement précédent échoué/expiré, ou montant modifié : nouvelle tentative
        // avec une référence dérivée, toujours rattachée à la facture par les métadonnées.
        $payload['reference'] = substr($reference . '-' . substr(md5(uniqid('walleopay', true)), 0, 8), 0, 120);

        $retry = walleopay_apiRequest(
            $params,
            'POST',
            '/payments',
            $payload,
            walleopay_idempotencyKey($params, $payload['reference'], $amount, $currency)
        );

        if ($retry['ok'] && !empty($retry['data']['checkout_url'])) {
            return walleopay_button($retry['data']['checkout_url'], $buttonText);
        }

        $response = $retry;
    }

    return walleopay_notice(walleopay_errorMessage($response, $invoiceId));
}

/**
 * Corps de la requête de création de paiement.
 *
 * @param array  $params
 * @param string $reference
 * @param int    $amount
 * @param string $currency
 *
 * @return array
 */
function walleopay_paymentPayload($params, $reference, $amount, $currency)
{
    $client = isset($params['clientdetails']) && is_array($params['clientdetails'])
        ? $params['clientdetails']
        : array();

    $name = trim(
        (isset($client['firstname']) ? $client['firstname'] : '')
        . ' '
        . (isset($client['lastname']) ? $client['lastname'] : '')
    );

    if ($name === '' && isset($client['companyname'])) {
        $name = trim((string) $client['companyname']);
    }

    $phone = '';
    foreach (array('phonenumber', 'phonenumberformatted', 'telephoneNumber') as $key) {
        if (!empty($client[$key])) {
            $candidate = walleopay_normalizePhone($client[$key]);
            if ($candidate !== '') {
                $phone = $candidate;
                break;
            }
        }
    }

    $systemUrl = walleopay_systemUrl($params);

    $payload = array(
        'amount' => $amount,
        'currency' => $currency,
        'reference' => $reference,
        'description' => 'Facture #' . (int) $params['invoiceid'],
        'return_url' => isset($params['returnurl']) ? $params['returnurl'] : $systemUrl,
        'cancel_url' => isset($params['returnurl']) ? $params['returnurl'] : $systemUrl,
        'notify_url' => $systemUrl . 'modules/gateways/callback/walleopay.php',
        'metadata' => array(
            'invoice_id' => (string) ((int) $params['invoiceid']),
            'client_id' => (string) (isset($client['userid']) ? (int) $client['userid'] : 0),
            'source' => 'whmcs',
        ),
    );

    if ($name !== '') {
        $payload['customer_name'] = walleopay_truncate($name, 120);
    }

    if (!empty($client['email'])) {
        $payload['customer_email'] = walleopay_truncate($client['email'], 180);
    }

    if ($phone !== '') {
        $payload['customer_phone'] = $phone;
    }

    return $payload;
}

/**
 * Référence marchand stable pour une facture : « invoice-<id> ».
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_reference($params)
{
    return 'invoice-' . (int) $params['invoiceid'];
}

/**
 * Clé d'idempotence déterministe : deux affichages identiques de la même
 * facture ne créent qu'un seul paiement.
 *
 * @param array  $params
 * @param string $reference
 * @param int    $amount
 * @param string $currency
 *
 * @return string
 */
function walleopay_idempotencyKey($params, $reference, $amount, $currency)
{
    return 'whmcs-' . md5(implode('|', array(
        walleopay_systemUrl($params),
        walleopay_mode($params),
        $reference,
        (string) $amount,
        $currency,
    )));
}

/**
 * Requête HTTP vers l'API WalleoPay, en cURL natif.
 *
 * Retourne toujours un tableau :
 *   ok          bool
 *   status      int   code HTTP (0 si erreur réseau)
 *   data        array contenu de « data » en cas de succès
 *   error       array type/message normalisés en cas d'échec API
 *   network     string message d'erreur cURL le cas échéant
 *   retry_after int   secondes à attendre en cas de 429
 *
 * @param array       $params
 * @param string      $method
 * @param string      $path
 * @param array|null  $body
 * @param string|null $idempotencyKey
 *
 * @return array
 */
function walleopay_apiRequest($params, $method, $path, $body = null, $idempotencyKey = null)
{
    $result = array(
        'ok' => false,
        'status' => 0,
        'data' => array(),
        'error' => array('type' => '', 'message' => ''),
        'network' => '',
        'retry_after' => 0,
    );

    if (!function_exists('curl_init')) {
        $result['network'] = 'L\'extension PHP cURL est absente du serveur.';

        return $result;
    }

    $secretKey = walleopay_secretKey($params);
    $url = walleopay_apiBaseUrl($params) . $path;

    $headers = array(
        'Authorization: Bearer ' . $secretKey,
        'Accept: application/json',
        'User-Agent: WalleoPay-WHMCS/' . WALLEOPAY_WHMCS_VERSION,
    );

    $encodedBody = null;
    if ($body !== null) {
        $encodedBody = json_encode($body);
        $headers[] = 'Content-Type: application/json';
    }

    if ($idempotencyKey !== null && $idempotencyKey !== '') {
        $headers[] = 'Idempotency-Key: ' . $idempotencyKey;
    }

    $ch = curl_init();
    curl_setopt_array($ch, array(
        CURLOPT_URL => $url,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_FOLLOWLOCATION => false,
    ));

    if ($encodedBody !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $encodedBody);
    }

    $raw = curl_exec($ch);
    $curlError = curl_error($ch);
    $result['status'] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);

    $rawHeaders = '';
    $rawBody = '';

    if (is_string($raw)) {
        $rawHeaders = substr($raw, 0, $headerSize);
        $rawBody = substr($raw, $headerSize);
    }

    if ($raw === false || $curlError !== '') {
        $result['network'] = $curlError !== '' ? $curlError : 'Connexion à WalleoPay impossible.';
        walleopay_log($params, $method . ' ' . $path, $body, $curlError, $result, $secretKey);

        return $result;
    }

    $decoded = json_decode($rawBody, true);
    if (!is_array($decoded)) {
        $decoded = array();
    }

    if ($result['status'] === 429) {
        $result['retry_after'] = walleopay_headerValue($rawHeaders, 'retry-after');
    }

    if ($result['status'] >= 200 && $result['status'] < 300) {
        $result['ok'] = true;
        $result['data'] = isset($decoded['data']) && is_array($decoded['data']) ? $decoded['data'] : $decoded;
    } else {
        $result['error'] = walleopay_normalizeError($decoded, $result['status']);
    }

    walleopay_log($params, $method . ' ' . $path, $body, $rawBody, $result, $secretKey);

    return $result;
}

/**
 * Ramène toutes les formes d'erreur de l'API à un couple type/message.
 *
 * @param array $decoded
 * @param int   $status
 *
 * @return array
 */
function walleopay_normalizeError($decoded, $status)
{
    if (isset($decoded['error']) && is_array($decoded['error'])) {
        return array(
            'type' => isset($decoded['error']['type']) ? (string) $decoded['error']['type'] : 'api_error',
            'message' => isset($decoded['error']['message']) ? (string) $decoded['error']['message'] : '',
        );
    }

    // Erreurs de validation Laravel : { "message": "...", "errors": { champ: [ ... ] } }
    if (isset($decoded['errors']) && is_array($decoded['errors'])) {
        $messages = array();
        foreach ($decoded['errors'] as $field => $fieldMessages) {
            foreach ((array) $fieldMessages as $fieldMessage) {
                $messages[] = $field . ' : ' . $fieldMessage;
            }
        }

        return array(
            'type' => 'invalid_request',
            'message' => implode(' ', $messages),
        );
    }

    if (isset($decoded['message'])) {
        return array('type' => 'api_error', 'message' => (string) $decoded['message']);
    }

    return array('type' => 'api_error', 'message' => 'Réponse inattendue de WalleoPay (HTTP ' . (int) $status . ').');
}

/**
 * Détecte le conflit de référence marchand / d'idempotence.
 *
 * @param array $response
 *
 * @return bool
 */
function walleopay_isDuplicateReference($response)
{
    $type = isset($response['error']['type']) ? $response['error']['type'] : '';
    $message = isset($response['error']['message']) ? $response['error']['message'] : '';

    if ($type === 'idempotency_conflict') {
        return true;
    }

    if ($type !== 'invalid_request') {
        return false;
    }

    return stripos($message, 'référence') !== false
        || stripos($message, 'reference') !== false;
}

/**
 * Message lisible par le client à partir d'une réponse en échec.
 *
 * @param array $response
 * @param int   $invoiceId
 *
 * @return string
 */
function walleopay_errorMessage($response, $invoiceId)
{
    if ($response['network'] !== '') {
        return 'Impossible de joindre WalleoPay pour le moment. Veuillez réessayer dans quelques minutes.';
    }

    if ($response['status'] === 429) {
        $wait = $response['retry_after'] > 0 ? $response['retry_after'] : 60;

        return 'Trop de tentatives de paiement. Merci de réessayer dans ' . (int) $wait . ' secondes.';
    }

    $type = isset($response['error']['type']) ? $response['error']['type'] : '';
    $detail = isset($response['error']['message']) ? trim($response['error']['message']) : '';

    $known = array(
        'authentication_error' => 'La configuration WalleoPay de ce site est invalide (clé secrète refusée). Contactez l\'administrateur.',
        'merchant_not_active' => 'Le compte marchand WalleoPay n\'est pas actif. Contactez l\'administrateur du site.',
        'kyc_not_approved' => 'Le compte marchand WalleoPay n\'a pas encore été validé. Contactez l\'administrateur du site.',
        'service_not_approved' => 'Ce moyen de paiement n\'est pas encore activé sur le compte marchand.',
        'idempotency_in_progress' => 'Un paiement est déjà en cours de création pour cette facture. Actualisez la page dans quelques secondes.',
        'operator_unknown' => 'Le numéro de téléphone enregistré ne correspond à aucun opérateur pris en charge.',
        'not_found' => 'Paiement introuvable chez WalleoPay.',
    );

    if (isset($known[$type])) {
        return $known[$type];
    }

    $message = 'Le paiement de la facture #' . (int) $invoiceId . ' n\'a pas pu être initialisé.';

    if ($detail !== '') {
        $message .= ' (' . $detail . ')';
    }

    return $message;
}

/**
 * Journalise l'appel dans le Module Log, clé secrète masquée.
 *
 * @param array  $params
 * @param string $action
 * @param mixed  $request
 * @param mixed  $response
 * @param array  $processed
 * @param string $secretKey
 *
 * @return void
 */
function walleopay_log($params, $action, $request, $response, $processed, $secretKey)
{
    if (empty($params['logging']) || !function_exists('logModuleCall')) {
        return;
    }

    $replace = array();
    if ($secretKey !== '') {
        $replace[] = $secretKey;
    }

    logModuleCall(
        'walleopay',
        $action,
        is_array($request) ? $request : (string) $request,
        is_array($response) ? $response : (string) $response,
        $processed,
        $replace
    );
}

/**
 * Lit un en-tête dans une réponse HTTP brute.
 *
 * @param string $rawHeaders
 * @param string $name
 *
 * @return int
 */
function walleopay_headerValue($rawHeaders, $name)
{
    $lines = preg_split('/\r?\n/', (string) $rawHeaders);

    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2 && strtolower(trim($parts[0])) === strtolower($name)) {
            return (int) trim($parts[1]);
        }
    }

    return 0;
}

/**
 * Mode actif : « test » ou « live ».
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_mode($params)
{
    return (isset($params['mode']) && $params['mode'] === 'live') ? 'live' : 'test';
}

/**
 * Clé secrète correspondant au mode actif.
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_secretKey($params)
{
    $key = walleopay_mode($params) === 'live'
        ? (isset($params['liveSecretKey']) ? $params['liveSecretKey'] : '')
        : (isset($params['testSecretKey']) ? $params['testSecretKey'] : '');

    return trim((string) $key);
}

/**
 * URL de base de l'API, sans barre oblique finale.
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_apiBaseUrl($params)
{
    $base = isset($params['apiBaseUrl']) ? trim((string) $params['apiBaseUrl']) : '';

    if ($base === '') {
        $base = WALLEOPAY_DEFAULT_API;
    }

    return rtrim($base, '/');
}

/**
 * URL du système WHMCS, avec barre oblique finale.
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_systemUrl($params)
{
    $url = isset($params['systemurl']) ? trim((string) $params['systemurl']) : '';

    if ($url === '' && class_exists('\\WHMCS\\Config\\Setting')) {
        $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
    }

    return rtrim($url, '/') . '/';
}

/**
 * URL exacte à coller dans le tableau de bord WalleoPay.
 *
 * @return string
 */
function walleopay_callbackUrl()
{
    $url = '';

    if (class_exists('\\WHMCS\\Config\\Setting')) {
        $url = (string) \WHMCS\Config\Setting::getValue('SystemURL');
    }

    if ($url === '') {
        $url = 'https://votre-whmcs.tld';
    }

    return rtrim($url, '/') . '/modules/gateways/callback/walleopay.php';
}

/**
 * Normalise un numéro camerounais en E.164, ou chaîne vide s'il est inutilisable.
 *
 * @param string $raw
 *
 * @return string
 */
function walleopay_normalizePhone($raw)
{
    $digits = preg_replace('/\D+/', '', (string) $raw);
    $digits = ltrim((string) $digits, '0');

    if (strpos($digits, '237') === 0 && strlen($digits) === 12) {
        return '+' . $digits;
    }

    if (strlen($digits) === 9) {
        return '+237' . $digits;
    }

    return '';
}

/**
 * Coupe une chaîne à la longueur acceptée par l'API.
 *
 * @param string $value
 * @param int    $length
 *
 * @return string
 */
function walleopay_truncate($value, $length)
{
    $value = trim((string) $value);

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }

    return substr($value, 0, $length);
}

/**
 * Bouton de redirection vers la page de paiement hébergée.
 *
 * @param string $checkoutUrl
 * @param string $buttonText
 *
 * @return string
 */
function walleopay_button($checkoutUrl, $buttonText)
{
    return '<a href="' . htmlspecialchars($checkoutUrl, ENT_QUOTES, 'UTF-8') . '" '
        . 'class="btn btn-primary" rel="noopener">'
        . htmlspecialchars($buttonText, ENT_QUOTES, 'UTF-8')
        . '</a>'
        . '<p class="small text-muted" style="margin-top:8px">'
        . 'Vous serez redirigé vers la page sécurisée WalleoPay (MTN MoMo, Orange Money ou carte bancaire).'
        . '</p>';
}

/**
 * Encart d'information affiché à la place du bouton.
 *
 * @param string $message
 *
 * @return string
 */
function walleopay_notice($message)
{
    return '<div class="alert alert-danger" role="alert">'
        . htmlspecialchars($message, ENT_QUOTES, 'UTF-8')
        . '</div>';
}
