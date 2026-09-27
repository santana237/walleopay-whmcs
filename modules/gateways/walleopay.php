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

if (!defined('WALLEOPAY_MAX_ATTEMPT')) {
    /**
     * Numéro de tentative le plus élevé qu'une facture puisse atteindre.
     *
     * Une tentative ne s'ouvre qu'après la clôture de la précédente : il
     * faudrait des centaines de pages laissées expirer pour s'en approcher.
     * Au-delà, le module s'arrête plutôt que de sonder l'API sans fin.
     */
    define('WALLEOPAY_MAX_ATTEMPT', 999);
}

if (!defined('WALLEOPAY_MAX_STEPS')) {
    /**
     * Pas en avant autorisés pendant un seul affichage de la facture.
     *
     * Le cas courant en demande un ou deux. Les autres ne servent qu'à
     * enjamber un numéro déjà pris ailleurs, et l'on préfère un message
     * clair à une rafale d'appels qui buterait sur la limite de débit.
     */
    define('WALLEOPAY_MAX_STEPS', 10);
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
            'Description' => 'Le mode découle de la clé utilisée. Le test n\'est pas une simulation : il débite réellement le client et crédite votre solde WalleoPay, commission comprise. Faites vos essais avec de petits montants.',
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
            'Description' => 'Secret « whsec_… » affiché dans votre tableau de bord WalleoPay, rubrique Notifications. Il sert à vérifier la signature des notifications. Un seul secret par compte : le même en test et en production.',
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
            'Description' => 'Adresse que le module transmet avec chaque paiement : WalleoPay y envoie les notifications de paiement, quelle que soit l\'URL de notification par défaut de votre tableau de bord. Rien à copier. Champ informatif : sa valeur n\'est pas utilisée par le module.',
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

    $attempt = walleopay_openAttempt($params, $amount, $currency);

    switch ($attempt['state']) {
        case 'open':
            return walleopay_button($attempt['checkout_url'], $buttonText);

        case 'paid':
            return walleopay_notice('Cette facture a déjà été réglée via WalleoPay. Actualisez la page dans quelques instants.');

        case 'awaiting':
            return walleopay_notice('Un paiement WalleoPay est en cours de rapprochement pour cette facture. Aucun nouveau paiement n\'est nécessaire.');

        case 'busy':
            return walleopay_notice('Un paiement WalleoPay est en cours de validation pour cette facture. Actualisez la page dans quelques minutes.');

        case 'exhausted':
            return walleopay_notice('Trop de tentatives de paiement pour cette facture. Contactez-nous pour la régler.');
    }

    return walleopay_notice(walleopay_errorMessage($attempt['response'], $invoiceId));
}

/**
 * Trouve — ou ouvre — la tentative de paiement à présenter pour la facture.
 *
 * Une facture connaît parfois plusieurs paiements : la page de paiement
 * expire au bout de 30 minutes, un solde est insuffisant, le montant dû
 * change. Chaque paiement porte donc un numéro de tentative dans sa
 * référence (« invoice-42 », puis « invoice-42-2 », « invoice-42-3 »…), et sa
 * clé d'idempotence en découle.
 *
 * Autrefois, la clé ne dépendait que de la facture et du montant : rouverte
 * après expiration, la facture recevait de l'API la réponse d'origine,
 * rejouée telle quelle, et le bouton menait à une page morte. Deux règles
 * tiennent désormais l'ensemble :
 *
 *   - un numéro ne s'ouvre qu'une fois le précédent clos (échoué, annulé,
 *     expiré, ou réussi ET déjà enregistré dans WHMCS) : il n'existe jamais
 *     deux paiements payables à la fois pour une facture, et un paiement
 *     réussi que WHMCS n'a pas encore crédité bloque toute nouvelle
 *     demande ;
 *   - une tentative est toujours relue par GET avant d'être présentée : une
 *     réponse rejouée décrit le paiement à sa création, pas tel qu'il est.
 *
 * @param array         $params
 * @param int           $amount   Montant dû, en francs entiers.
 * @param string        $currency
 * @param callable|null $request  Appel à l'API, remplaçable pour les tests.
 * @param callable|null $recorded Paiement déjà enregistré dans WHMCS ? Idem.
 *
 * @return array state : open (avec checkout_url), paid, awaiting, busy,
 *               exhausted ou error (avec response)
 */
function walleopay_openAttempt($params, $amount, $currency, $request = null, $recorded = null)
{
    $request = $request !== null ? $request : 'walleopay_apiRequest';
    $recorded = $recorded !== null ? $recorded : 'walleopay_transactionRecorded';
    $base = walleopay_reference($params);

    $last = walleopay_lastAttempt($params, $base, $request);

    if (isset($last['error'])) {
        return array('state' => 'error', 'response' => $last['error']);
    }

    $number = $last['number'];
    $payment = $last['payment'];

    for ($step = 0; $step < WALLEOPAY_MAX_STEPS; $step++) {
        if ($payment !== null) {
            $outcome = walleopay_evaluateAttempt($params, $payment, $amount, $currency, $request, $recorded);

            if ($outcome['state'] !== 'closed') {
                return $outcome;
            }
        }

        $number++;

        if ($number > WALLEOPAY_MAX_ATTEMPT) {
            return array('state' => 'exhausted');
        }

        $reference = walleopay_attemptReference($base, $number);
        $created = call_user_func(
            $request,
            $params,
            'POST',
            '/payments',
            walleopay_paymentPayload($params, $reference, $amount, $currency),
            walleopay_idempotencyKey($params, $reference, $amount, $currency)
        );

        if ($created['ok'] && empty($created['replayed'])) {
            // Réponse fraîche : elle dit l'état réel du paiement qui vient
            // de naître, on l'examine au tour suivant sans la relire.
            $payment = $created['data'];
            continue;
        }

        if (!$created['ok'] && !walleopay_isDuplicateReference($created)) {
            return array('state' => 'error', 'response' => $created);
        }

        // Réponse rejouée, ou numéro déjà pris : seul un GET dit où en est
        // réellement ce paiement.
        $lookup = walleopay_lookupAttempt($params, $base, $number, $request);

        if ($lookup['ok']) {
            $payment = $lookup['data'];
            continue;
        }

        if ((int) $lookup['status'] !== 404) {
            return array('state' => 'error', 'response' => $lookup);
        }

        // Numéro pris hors de ce mode — une référence est unique pour tout le
        // compte, test et production confondus — ou jamais créé : on
        // l'enjambe.
        $payment = null;
    }

    return array('state' => 'exhausted');
}

/**
 * Dernière tentative existante de la facture : numéro (0 si aucune) et
 * paiement correspondant.
 *
 * Les numéros s'ouvrent l'un après l'autre et forment une suite continue :
 * une recherche exponentielle puis dichotomique trouve le dernier en
 * quelques appels, même après des dizaines de pages laissées expirer. Les
 * sonder un à un finirait par buter sur la limite de 120 requêtes par
 * minute et par clé.
 *
 * @param array    $params
 * @param string   $base
 * @param callable $request
 *
 * @return array number + payment, ou error (réponse API en échec)
 */
function walleopay_lastAttempt($params, $base, $request)
{
    $found = 0;
    $payment = null;
    $missing = 0;
    $probe = 1;

    while ($missing === 0) {
        $lookup = walleopay_lookupAttempt($params, $base, $probe, $request);

        if ($lookup['ok']) {
            $found = $probe;
            $payment = $lookup['data'];

            if ($probe >= WALLEOPAY_MAX_ATTEMPT) {
                break;
            }

            $probe = min($probe * 2, WALLEOPAY_MAX_ATTEMPT);
            continue;
        }

        if ((int) $lookup['status'] !== 404) {
            return array('error' => $lookup);
        }

        // La première référence est la seule qu'une ancienne version du
        // module ait pu prendre dans l'autre mode : absente ici, elle ne
        // prouve pas que la suite est vide.
        if ($probe === 1) {
            $next = walleopay_lookupAttempt($params, $base, 2, $request);

            if ($next['ok']) {
                $found = 2;
                $payment = $next['data'];
                $probe = 4;
                continue;
            }

            if ((int) $next['status'] !== 404) {
                return array('error' => $next);
            }
        }

        $missing = $probe;
    }

    while ($missing - $found > 1) {
        $middle = intdiv($found + $missing, 2);
        $lookup = walleopay_lookupAttempt($params, $base, $middle, $request);

        if ($lookup['ok']) {
            $found = $middle;
            $payment = $lookup['data'];
        } elseif ((int) $lookup['status'] === 404) {
            $missing = $middle;
        } else {
            return array('error' => $lookup);
        }
    }

    return array('number' => $found, 'payment' => $payment);
}

/**
 * GET d'une tentative par sa référence.
 *
 * @param array    $params
 * @param string   $base
 * @param int      $number
 * @param callable $request
 *
 * @return array Réponse normalisée de walleopay_apiRequest()
 */
function walleopay_lookupAttempt($params, $base, $number, $request)
{
    return call_user_func(
        $request,
        $params,
        'GET',
        '/payments/' . rawurlencode(walleopay_attemptReference($base, $number))
    );
}

/**
 * Ce que l'on peut faire d'une tentative existante.
 *
 * @param array    $params
 * @param array    $payment  Paiement tel que relu auprès de l'API.
 * @param int      $amount
 * @param string   $currency
 * @param callable $request
 * @param callable $recorded
 *
 * @return array state : closed (on peut en ouvrir une autre), open, paid,
 *               awaiting, busy ou error
 */
function walleopay_evaluateAttempt($params, $payment, $amount, $currency, $request, $recorded)
{
    $status = isset($payment['status']) ? (string) $payment['status'] : '';
    $paymentId = isset($payment['id']) ? (string) $payment['id'] : '';

    if ($status === 'succeeded') {
        // Déjà crédité : ce qui reste dû est une nouvelle dette. Pas encore
        // crédité (notification en route, ou refusée) : un second paiement
        // ferait payer le client deux fois.
        return call_user_func($recorded, $paymentId)
            ? array('state' => 'closed')
            : array('state' => 'paid');
    }

    if ($status === 'awaiting_confirmation') {
        return array('state' => 'awaiting');
    }

    if (in_array($status, array('failed', 'cancelled', 'expired'), true)) {
        return array('state' => 'closed');
    }

    // Statut inconnu : on n'ouvre rien à côté d'un paiement qu'on ne sait
    // pas lire.
    if (!in_array($status, array('pending', 'processing'), true)) {
        return array('state' => 'busy');
    }

    $sameAmount = walleopay_settledAmount($payment, array($amount)) !== null;
    $sameCurrency = isset($payment['currency']) && strtoupper((string) $payment['currency']) === $currency;

    if ($sameAmount && $sameCurrency) {
        // Même demande : on la reprend. Sans page à montrer, on attend
        // plutôt que d'annuler un paiement qui n'a rien de périmé.
        return !empty($payment['checkout_url'])
            ? array('state' => 'open', 'checkout_url' => (string) $payment['checkout_url'])
            : array('state' => 'busy');
    }

    // Le montant dû a changé pendant que la page restait ouverte. Une
    // demande déjà partie vers le téléphone du client ne s'annule pas sans
    // risque : l'opérateur pourrait débiter un paiement que l'on croit mort.
    if ($status === 'processing' || $paymentId === '') {
        return array('state' => 'busy');
    }

    $cancel = call_user_func(
        $request,
        $params,
        'POST',
        '/payments/' . rawurlencode($paymentId) . '/cancel',
        array()
    );

    if (!$cancel['ok']) {
        return array('state' => 'error', 'response' => $cancel);
    }

    $after = isset($cancel['data']['status']) ? (string) $cancel['data']['status'] : '';

    // Annulation sans effet : on ne superpose pas une seconde page à la
    // première.
    if ($after === '' || $after === 'pending' || $after === 'processing') {
        return array('state' => 'busy');
    }

    // Le paiement a pu aboutir juste avant l'annulation : on le relit comme
    // les autres, sans jamais rappeler l'annulation.
    return walleopay_evaluateAttempt($params, $cancel['data'], $amount, $currency, $request, $recorded);
}

/**
 * Part d'un paiement qui règle le montant attendu, ou null.
 *
 * Quand le client paie la commission, WalleoPay l'ajoute par-dessus le
 * montant demandé : `amount` vaut alors la facture PLUS `fee`, et `net` la
 * facture seule. Comparer `amount` à la facture échouait donc à tous les
 * coups.
 *
 * L'API ne dit pas qui porte la commission. Pour ne pas deviner, le module
 * glisse le montant qu'il demande dans les métadonnées (`requested_amount`) :
 * il doit être l'un des montants attendus, et le paiement doit en être l'une
 * des deux formes — le montant seul, ou le montant plus la commission. Sans
 * cette donnée (paiement créé par une version antérieure), les deux formes
 * sont essayées sur chaque montant attendu. Dans tous les cas, un montant
 * vraiment différent ne correspond jamais.
 *
 * Même règle dans le fichier de rappel (walleopay_cb_settledAmount()).
 *
 * @param array $payment
 * @param array $expected Montants acceptables, en francs entiers.
 *
 * @return array|null amount (réglé), customer_fee (commission payée par le
 *                    client, 0 sinon)
 */
function walleopay_settledAmount($payment, $expected)
{
    $amount = isset($payment['amount']) ? (int) $payment['amount'] : -1;
    $fee = isset($payment['fee']) ? (int) $payment['fee'] : 0;
    $candidates = array();

    foreach ($expected as $candidate) {
        $candidates[] = (int) $candidate;
    }

    if (isset($payment['metadata']['requested_amount'])) {
        $requested = (int) $payment['metadata']['requested_amount'];

        if (!in_array($requested, $candidates, true)) {
            return null;
        }

        $candidates = array($requested);
    }

    foreach ($candidates as $candidate) {
        if ($amount === $candidate) {
            return array('amount' => $candidate, 'customer_fee' => 0);
        }
    }

    if ($fee <= 0) {
        return null;
    }

    foreach ($candidates as $candidate) {
        // `net` est ce que le marchand touche : quand il est fourni, il doit
        // tomber lui aussi sur le montant attendu.
        if ($amount - $fee === $candidate
            && (!isset($payment['net']) || (int) $payment['net'] === $candidate)
        ) {
            return array('amount' => $candidate, 'customer_fee' => $fee);
        }
    }

    return null;
}

/**
 * Ce paiement WalleoPay figure-t-il déjà parmi les transactions WHMCS ?
 *
 * En cas de doute (base illisible), la réponse est non : on préfère
 * demander au client d'actualiser la page que risquer de le faire payer deux
 * fois.
 *
 * @param string $paymentId
 *
 * @return bool
 */
function walleopay_transactionRecorded($paymentId)
{
    if ($paymentId === '' || !class_exists('\\Illuminate\\Database\\Capsule\\Manager')) {
        return false;
    }

    try {
        return \Illuminate\Database\Capsule\Manager::table('tblaccounts')
            ->where('transid', $paymentId)
            ->exists();
    } catch (\Throwable $e) {
        return false;
    }
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
            // Le montant demandé, tel quel : quand le client paie la
            // commission, `amount` la contient, et seul ce chiffre dit sans
            // ambiguïté ce que la facture réclamait (walleopay_settledAmount()).
            'requested_amount' => (int) $amount,
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
 * Racine des références d'une facture : « invoice-<id> » en production,
 * « invoice-<id>-test » en test.
 *
 * Une référence est unique pour tout le compte WalleoPay, test et
 * production confondus, alors qu'une clé ne voit que les paiements de son
 * mode. Sans cette distinction, une facture ouverte pendant les essais
 * aurait laissé en production des numéros déjà pris et invisibles.
 *
 * @param array $params
 *
 * @return string
 */
function walleopay_reference($params)
{
    $base = 'invoice-' . (int) $params['invoiceid'];

    return walleopay_mode($params) === 'live' ? $base : $base . '-test';
}

/**
 * Référence de la tentative n° $number : la racine seule pour la première,
 * suivie de « -<n> » ensuite (« invoice-42 », « invoice-42-2 »…).
 *
 * La première garde la forme historique : les paiements créés par les
 * versions précédentes du module restent reconnus.
 *
 * @param string $base
 * @param int    $number
 *
 * @return string
 */
function walleopay_attemptReference($base, $number)
{
    return (int) $number <= 1 ? $base : $base . '-' . (int) $number;
}

/**
 * Clé d'idempotence déterministe d'une tentative.
 *
 * Elle porte la référence, donc le numéro de tentative : deux affichages
 * simultanés de la facture ne créent qu'un paiement, mais une tentative
 * close n'est jamais rejouée — la suivante a sa propre clé.
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
 *   replayed    bool  réponse d'idempotence rejouée (en-tête Idempotent-Replay) :
 *                     elle décrit le paiement à sa création, pas son état actuel
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
        'replayed' => false,
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

    $result['replayed'] = strtolower(walleopay_headerText($rawHeaders, 'idempotent-replay')) === 'true';

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
 * Lit un en-tête numérique dans une réponse HTTP brute.
 *
 * @param string $rawHeaders
 * @param string $name
 *
 * @return int
 */
function walleopay_headerValue($rawHeaders, $name)
{
    return (int) walleopay_headerText($rawHeaders, $name);
}

/**
 * Lit un en-tête dans une réponse HTTP brute, chaîne vide s'il est absent.
 *
 * @param string $rawHeaders
 * @param string $name
 *
 * @return string
 */
function walleopay_headerText($rawHeaders, $name)
{
    $lines = preg_split('/\r?\n/', (string) $rawHeaders);

    foreach ($lines as $line) {
        $parts = explode(':', $line, 2);
        if (count($parts) === 2 && strtolower(trim($parts[0])) === strtolower($name)) {
            return trim($parts[1]);
        }
    }

    return '';
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
 * Adresse du fichier de rappel, affichée dans les réglages pour vérification.
 *
 * Le module transmet la même avec chaque paiement (notify_url), et c'est elle
 * que WalleoPay appelle : rien n'est à recopier dans le tableau de bord.
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
