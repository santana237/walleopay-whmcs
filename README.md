# WalleoPay pour WHMCS

Passerelle de paiement tierce (« third party gateway ») permettant d'encaisser
vos factures WHMCS par **MTN Mobile Money**, **Orange Money** et **carte
bancaire** via [WalleoPay](https://walleopay.com).

- Compatible WHMCS 8.x (API de module version 1.1), PHP 7.4 et supérieur.
- Aucune dépendance Composer, aucune bibliothèque à installer : cURL natif.
- Le client est redirigé vers la page de paiement hébergée par WalleoPay : aucune
  donnée de carte ne transite par votre WHMCS.

---

## 1. Installation

Copiez les deux fichiers dans votre installation WHMCS, en respectant
exactement l'arborescence :

```
modules/gateways/walleopay.php
modules/gateways/callback/walleopay.php
```

Autrement dit, depuis le dossier de cette intégration :

```sh
cp modules/gateways/walleopay.php            /chemin/vers/whmcs/modules/gateways/
cp modules/gateways/callback/walleopay.php   /chemin/vers/whmcs/modules/gateways/callback/
```

Droits recommandés : `644` pour les fichiers, propriétaire identique au reste de
l'installation WHMCS.

> Ne renommez jamais les fichiers : WHMCS déduit le nom du module du nom de
> fichier, et toutes les fonctions sont préfixées `walleopay_`.

## 2. Activation

1. Dans l'administration WHMCS : **Setup → Payments → Payment Gateways**.
2. Onglet **All Payment Gateways**, cliquez sur **WalleoPay (MTN MoMo, Orange
   Money, Carte)**.
3. Le module s'active et bascule dans l'onglet **Manage Existing Gateways**.

## 3. Réglages

| Champ | Description |
|---|---|
| **Mode** | `Test` utilise la clé `sk_test_…`, `Production` la clé `sk_live_…`. Le mode effectivement appliqué découle toujours de la clé envoyée. Le test n'est pas une simulation : voir la section 8. |
| **Clé secrète de test** | Clé `sk_test_…` du tableau de bord WalleoPay (**Applications → Clés API**). |
| **Clé secrète de production** | Clé `sk_live_…` du tableau de bord WalleoPay. |
| **Secret de webhook** | Secret `whsec_…` du tableau de bord WalleoPay (**Applications → Notifications**). Un seul par compte, le même en test et en production. Sans lui, aucune notification n'est acceptée. |
| **URL de base de l'API** | `https://walleopay.com/api/v1` par défaut. À ne changer que pour un environnement local (`http://127.0.0.1:8000/api/v1`). |
| **Texte du bouton** | Libellé affiché au client, « Payer avec WalleoPay » par défaut. |
| **Journalisation** | Active la trace des appels API dans **Utilities → Logs → Module Log**. La clé secrète y est masquée. |
| **URL de rappel (webhook)** | Champ informatif : il affiche l'adresse que le module transmet avec chaque paiement. Rien à copier côté WalleoPay. |

Les clés secrètes ne doivent **jamais** être exposées côté navigateur ni
communiquées par courriel : elles autorisent tous les appels API de votre compte
marchand.

## 4. URL de rappel (webhook)

Le module transmet lui-même l'adresse du fichier de rappel avec chaque paiement
(champ `notify_url`) :

```
https://votre-whmcs.tld/modules/gateways/callback/walleopay.php
```

Elle est construite à partir de l'URL système de votre WHMCS (**Setup → General
Settings → General → WHMCS System URL**) : vérifiez que celle-ci est exacte, en
HTTPS. **Cette adresse l'emporte** sur l'URL de notification par défaut réglée
dans le tableau de bord WalleoPay (**Mon compte → Paramètres → URLs par
défaut**) : il n'y a rien à y déclarer pour WHMCS. L'URL par défaut ne sert
qu'aux paiements créés sans `notify_url` et aux notifications de remboursement
et de reversement ; si elle pointe vers WHMCS, le fichier de rappel répond `200`
et ignore ces dernières.

L'URL doit être accessible publiquement en HTTPS, sans authentification HTTP,
sans pare-feu applicatif bloquant les requêtes `POST` de WalleoPay.

## 5. Devise XAF

WHMCS ne propose pas le franc CFA par défaut. Avant le premier paiement :

1. **Setup → Payments → Currencies**.
2. **Add New Currency**, code `XAF`, préfixe vide, suffixe ` FCFA`, séparateur de
   milliers ` ` (espace), **2 décimales → mettre `0`**.
3. Affectez cette devise aux clients concernés (fiche client → onglet
   *Summary* → *Currency*).

Points d'attention :

- WalleoPay travaille en **entiers de francs CFA** ; le module arrondit le
  montant WHMCS à l'entier le plus proche avant de créer le paiement.
- Le montant doit être compris entre **100** et **1 000 000 XAF** ; en dehors de
  cet intervalle, le module affiche un message au lieu du bouton.
- Le fichier de rappel refuse tout paiement dont le montant ou la devise ne
  correspondent pas à la facture. Quand le client paie la commission (réglage
  « Qui paie la commission » du compte ou du service), WalleoPay l'ajoute
  par-dessus : le paiement porte sur la facture **plus** la commission, et c'est
  `amount − fee` qui est comparé à la facture. Le module joint à chaque paiement
  le montant qu'il demande (métadonnée `requested_amount`), qui doit valoir le
  total ou le solde de la facture : aucun écart n'est deviné.

## 6. Comment la facture est créditée

Le fichier de rappel ne fait jamais confiance au seul webhook. Dans l'ordre :

1. Vérification de la signature `X-WalleoPay-Signature` (HMAC SHA-256, comparée
   avec `hash_equals`).
2. Contrôle de l'horodatage `t=` : au-delà de **300 secondes**, la requête est
   rejetée en `401`.
3. **Re-interrogation de `GET /payments/{id}`** auprès de l'API : seul un statut
   `succeeded` renvoyé par l'API autorise la suite.
4. Contrôle du montant (total ou solde de la facture, arrondi à l'entier, commission
   payée par le client mise à part) et de la devise.
5. `checkCbInvoiceID()`, `checkCbTransID()` (anti-doublon) puis
   `addInvoicePayment()` et `logTransaction()`.

Traitement des autres statuts :

| Statut WalleoPay | Comportement |
|---|---|
| `succeeded` | Facture créditée du montant qu'elle réclamait, transaction journalisée « Successful ». La commission est inscrite en frais quand elle est retenue sur la somme ; quand le client l'a payée par-dessus, elle ne l'est pas, et la facture n'est pas créditée d'un trop-perçu. |
| `awaiting_confirmation` | Journalisation « Pending » uniquement. **Aucune facture créditée** : le rapprochement manuel est en cours côté WalleoPay. |
| `failed`, `cancelled`, `expired` | Journalisation « Unsuccessful », aucun paiement ajouté. |
| `pending`, `processing` | Réponse `409` pour que WalleoPay renvoie la notification plus tard. |
| `payout.*`, `refund.*` | Ignorés, réponse `200` (ne concernent aucune facture). |

Codes de réponse HTTP renvoyés à WalleoPay :

| Code | Signification |
|---|---|
| `200` | Notification traitée (créditée, ignorée ou journalisée). |
| `400` | Corps invalide, facture absente, montant ou devise incohérents. |
| `401` | Signature invalide ou horodatage hors tolérance. |
| `409` | Paiement pas encore confirmé : WalleoPay réessaiera. |
| `500` / `502` | Secret manquant, facture illisible, ou API injoignable. |
| `503` | Module non activé dans WHMCS. |

## 7. Parcours client

1. Le client ouvre sa facture et clique sur **Payer avec WalleoPay**.
2. WHMCS appelle `POST /payments` avec la référence `invoice-<id>`
   (`invoice-<id>-test` en mode test), une clé `Idempotency-Key` propre à cette
   tentative et les métadonnées
   `{invoice_id, client_id, source: "whmcs", requested_amount}`.
3. Le client est redirigé vers la page de paiement WalleoPay, choisit son moyen
   de paiement et valide (code PIN Mobile Money ou carte).
4. Il revient sur la facture WHMCS (`return_url`), pendant que le webhook
   crédite la facture en arrière-plan.

Le bouton est préparé à chaque affichage de la facture, et le module relit
toujours l'état réel de la dernière tentative (`GET /payments/{référence}`)
avant de le présenter :

| Dernière tentative | Ce que voit le client |
|---|---|
| En cours (`pending`, `processing`), même montant | Le même bouton, vers la même page de paiement. |
| `pending` mais le montant dû a changé | Elle est annulée, puis une nouvelle tentative est ouverte. |
| `processing` mais le montant dû a changé | Un message d'attente : la demande est déjà sur le téléphone du client, on ne l'annule pas. |
| `failed`, `cancelled`, `expired` | Une nouvelle tentative : `invoice-12-2`, puis `invoice-12-3`… |
| `succeeded`, pas encore enregistrée dans WHMCS | « Cette facture a déjà été réglée » : aucun nouveau paiement. |
| `succeeded` et déjà enregistrée (solde rouvert) | Une nouvelle tentative pour le solde. |
| `awaiting_confirmation` | Un message : le rapprochement est en cours, aucun nouveau paiement. |

Chaque tentative a sa propre clé d'idempotence : une page expirée (au bout de
30 minutes) n'est jamais resservie, et deux affichages simultanés ne créent qu'un
seul paiement. Une nouvelle tentative ne s'ouvre qu'une fois la précédente
close : il n'y a jamais deux paiements payables à la fois pour une même facture.
En production, les paiements créés par la version précédente sous
`invoice-<id>` restent reconnus comme première tentative. En test, les
références prennent désormais le suffixe `-test` : une référence étant unique
pour tout le compte, test et production ne se disputent plus les mêmes numéros.

## 8. Test avant mise en production

Le mode test **n'est pas une simulation** : il passe par un vrai canal de
paiement, débite réellement le payeur et crédite votre solde WalleoPay,
commission comprise. Seuls les clés, l'historique et les statistiques restent
séparés de la production. Testez avec de petits montants.

1. Mode **Test** + clé `sk_test_…`, et le secret de webhook du compte.
2. Créez une petite facture (par exemple 100 XAF) sur un client de test.
3. Payez-la depuis l'espace client ; vérifiez dans **Billing → Gateway Log**
   l'entrée « Successful » et le paiement rattaché à la facture.
4. Basculez ensuite en **Production** et renseignez la clé `sk_live_…`.

Les deux modes ont des clés distinctes mais **un seul secret de webhook**,
celui du compte : lors de la bascule, seule la clé change.

## 9. Dépannage

| Symptôme | Piste |
|---|---|
| « WalleoPay n'est pas configuré… » sur la facture | La clé secrète du mode sélectionné est vide. |
| « Impossible de joindre WalleoPay… » | Sortie HTTPS bloquée sur le serveur, ou extension cURL absente. Vérifiez le Module Log. |
| « Trop de tentatives de paiement » | Limite de 120 requêtes/minute par clé atteinte (HTTP 429). Le délai d'attente est indiqué dans le message. |
| « La configuration WalleoPay de ce site est invalide » | Clé refusée (`authentication_error`) : clé de test utilisée en mode production, ou clé révoquée. |
| Compte non validé / service non approuvé | `kyc_not_approved` ou `service_not_approved` : finalisez la validation dans le tableau de bord WalleoPay. |
| La facture reste impayée malgré un paiement réussi | Vérifiez **Billing → Gateway Log** : `401` = mauvais secret de webhook, `400 amount_mismatch` = montant ou devise divergents, aucune entrée = URL de rappel inaccessible (URL système WHMCS erronée, pare-feu, authentification HTTP). Une fois la cause corrigée, rejouez la notification depuis **Applications → Notifications** du tableau de bord WalleoPay. |
| « Cette facture a déjà été réglée via WalleoPay » alors qu'elle reste impayée | Un paiement a réussi mais WHMCS ne l'a pas encore crédité : le module refuse d'en ouvrir un second. Réglez la notification (ligne précédente) plutôt que de faire repayer le client. |
| Paiement « en attente de confirmation » | Statut `awaiting_confirmation` : le client a payé au code marchand, le rapprochement est manuel. La facture sera créditée à la notification suivante. |

Journaux utiles :

- **Billing → Gateway Log** : ce que le fichier de rappel a décidé pour chaque
  notification.
- **Utilities → Logs → Module Log** : requêtes et réponses de l'API WalleoPay
  (activez l'option « Journalisation » ; la clé secrète y est masquée).

## 10. Remboursements

WalleoPay sait rembourser un paiement abouti, en tout ou en partie : depuis la
fiche du paiement dans le tableau de bord (**Mes ventes → Paiements**, réservé au
propriétaire du compte, suivi dans **Mes ventes → Remboursements**) ou par l'API
(`POST /payments/{id}/refunds`). Ce module ne déclare
cependant pas de fonction `walleopay_refund()` : WHMCS n'affiche donc pas de
bouton *Refund* pour cette passerelle. Remboursez depuis le tableau de bord
WalleoPay, puis constatez le remboursement à la main dans WHMCS.

---

Support : [walleopay.com](https://walleopay.com) — documentation API
`https://walleopay.com/documentation`.
