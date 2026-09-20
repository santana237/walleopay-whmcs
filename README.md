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
| **Mode** | `Test` utilise la clé `sk_test_…`, `Production` la clé `sk_live_…`. Le mode réel découle toujours de la clé envoyée. |
| **Clé secrète de test** | Clé `sk_test_…` du tableau de bord WalleoPay. |
| **Clé secrète de production** | Clé `sk_live_…` du tableau de bord WalleoPay. |
| **Secret de webhook** | Secret `whsec_…` du tableau de bord WalleoPay. Sans lui, aucune notification n'est acceptée. |
| **URL de base de l'API** | `https://walleopay.com/api/v1` par défaut. À ne changer que pour un environnement local (`http://127.0.0.1:8000/api/v1`). |
| **Texte du bouton** | Libellé affiché au client, « Payer avec WalleoPay » par défaut. |
| **Journalisation** | Active la trace des appels API dans **Utilities → Logs → Module Log**. La clé secrète y est masquée. |
| **URL de rappel (webhook)** | Champ informatif : il affiche l'URL à coller côté WalleoPay. |

Les clés secrètes ne doivent **jamais** être exposées côté navigateur ni
communiquées par courriel : elles autorisent tous les appels API de votre compte
marchand.

## 4. URL de rappel (webhook)

Dans votre tableau de bord WalleoPay, renseignez comme URL de notification :

```
https://votre-whmcs.tld/modules/gateways/callback/walleopay.php
```

Remplacez `https://votre-whmcs.tld/` par l'URL système exacte de votre WHMCS
(**Setup → General Settings → General → WHMCS System URL**), barre oblique
finale comprise avant `modules/`. Le module transmet également cette URL dans
chaque paiement (`notify_url`), mais la valeur configurée côté WalleoPay reste
la référence.

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
  correspondent pas à la facture.

## 6. Comment la facture est créditée

Le fichier de rappel ne fait jamais confiance au seul webhook. Dans l'ordre :

1. Vérification de la signature `X-WalleoPay-Signature` (HMAC SHA-256, comparée
   avec `hash_equals`).
2. Contrôle de l'horodatage `t=` : au-delà de **300 secondes**, la requête est
   rejetée en `401`.
3. **Re-interrogation de `GET /payments/{id}`** auprès de l'API : seul un statut
   `succeeded` renvoyé par l'API autorise la suite.
4. Contrôle du montant (total ou solde de la facture, arrondi à l'entier) et de
   la devise.
5. `checkCbInvoiceID()`, `checkCbTransID()` (anti-doublon) puis
   `addInvoicePayment()` et `logTransaction()`.

Traitement des autres statuts :

| Statut WalleoPay | Comportement |
|---|---|
| `succeeded` | Facture créditée, transaction journalisée « Successful ». |
| `awaiting_confirmation` | Journalisation « Pending » uniquement. **Aucune facture créditée** : le rapprochement manuel est en cours côté WalleoPay. |
| `failed`, `cancelled`, `expired` | Journalisation « Unsuccessful », aucun paiement ajouté. |
| `pending`, `processing` | Réponse `409` pour que WalleoPay renvoie la notification plus tard. |
| `payout.*` | Ignoré (ne concerne aucune facture). |

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
2. WHMCS appelle `POST /payments` avec la référence `invoice-<id>`, une clé
   `Idempotency-Key` déterministe et les métadonnées
   `{invoice_id, client_id, source: "whmcs"}`.
3. Le client est redirigé vers la page de paiement WalleoPay, choisit son moyen
   de paiement et valide (code PIN Mobile Money ou carte).
4. Il revient sur la facture WHMCS (`return_url`), pendant que le webhook
   crédite la facture en arrière-plan.

Si la facture est rouverte alors qu'un paiement est déjà en cours, le module
réutilise le paiement existant au lieu d'en créer un second. Si le paiement
précédent a échoué ou expiré, une nouvelle tentative est créée avec une
référence dérivée (`invoice-12-a1b2c3d4`), toujours rattachée à la facture par
les métadonnées.

## 8. Test avant mise en production

1. Mode **Test** + clé `sk_test_…`, secret de webhook de test.
2. Créez une facture de 1 000 XAF sur un client de test.
3. Payez-la depuis l'espace client ; vérifiez dans **Billing → Gateway Log**
   l'entrée « Successful » et le paiement rattaché à la facture.
4. Basculez ensuite en **Production** et remplacez les clés.

Les deux modes utilisent des clés et des secrets de webhook distincts : pensez à
changer les deux lors de la bascule.

## 9. Dépannage

| Symptôme | Piste |
|---|---|
| « WalleoPay n'est pas configuré… » sur la facture | La clé secrète du mode sélectionné est vide. |
| « Impossible de joindre WalleoPay… » | Sortie HTTPS bloquée sur le serveur, ou extension cURL absente. Vérifiez le Module Log. |
| « Trop de tentatives de paiement » | Limite de 120 requêtes/minute par clé atteinte (HTTP 429). Le délai d'attente est indiqué dans le message. |
| « La configuration WalleoPay de ce site est invalide » | Clé refusée (`authentication_error`) : clé de test utilisée en mode production, ou clé révoquée. |
| Compte non validé / service non approuvé | `kyc_not_approved` ou `service_not_approved` : finalisez la validation dans le tableau de bord WalleoPay. |
| La facture reste impayée malgré un paiement réussi | Vérifiez **Billing → Gateway Log** : `401` = mauvais secret de webhook, `400 amount_mismatch` = montant ou devise divergents, aucune entrée = URL de rappel non renseignée ou inaccessible. |
| Paiement « en attente de confirmation » | Statut `awaiting_confirmation` : le client a payé au code marchand, le rapprochement est manuel. La facture sera créditée à la notification suivante. |

Journaux utiles :

- **Billing → Gateway Log** : ce que le fichier de rappel a décidé pour chaque
  notification.
- **Utilities → Logs → Module Log** : requêtes et réponses de l'API WalleoPay
  (activez l'option « Journalisation » ; la clé secrète y est masquée).

## 10. Remboursements

Le module ne déclare pas de fonction `walleopay_refund()` : WalleoPay propose
l'annulation d'un paiement non finalisé, pas le remboursement d'un paiement
abouti. WHMCS n'affiche donc pas de bouton *Refund* pour cette passerelle. Les
remboursements se traitent depuis le tableau de bord WalleoPay, puis se
constatent manuellement dans WHMCS.

---

Support : [walleopay.com](https://walleopay.com) — documentation API
`https://walleopay.com/documentation`.
