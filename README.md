# Virevo for Magento 2 (`Virevo_Payment`)

Module de paiement **virement instantané (Virevo)** pour Magento 2 / Adobe
Commerce — sans frais de carte. Méthode **offsite** : le client est redirigé vers
une page de paiement Virevo ; la commande est **facturée** à réception du
virement via un **webhook signé**.

> ℹ️ Module auto-distribuable (composer ou `app/code`), **non testé** ici (pas
> d'environnement Magento local). Code conforme aux conventions Magento 2.4 ;
> à valider sur une instance réelle. La méthode utilise `AbstractMethod` (flux
> offsite simple, encore fonctionnel en 2.4) — migrable vers le Gateway framework.

## Flux
1. Le client choisit « Virement instantané » au checkout et place la commande.
2. `Model\Virevo::getOrderPlaceRedirectUrl()` redirige vers `virevo/standard/redirect`.
3. Le contrôleur **Redirect** crée le paiement Virevo (`POST /v1/payments`,
   `reference` = increment id, `return_url` = page de succès, `cancel_url` = panier)
   et redirige vers `payment_url`.
4. À l'encaissement, Virevo POST `payment.succeeded` sur `virevo/standard/webhook` ;
   la **signature HMAC** est vérifiée, puis la commande est **facturée** et passée
   en *Processing*.

## Installation
**Via composer** (recommandé) :
```bash
composer require virevo/module-payment
bin/magento module:enable Virevo_Payment
bin/magento setup:upgrade && bin/magento cache:flush
```
**Via `app/code`** : copier le dossier dans `app/code/Virevo/Payment/` puis les
mêmes commandes `module:enable` / `setup:upgrade`.

## Configuration
**Magento Admin → Stores → Configuration → Sales → Paiement → Virevo** :
mode `test`/`live`, clés d'API (`vrv_test_…` / `vrv_live_…`, depuis Virevo →
Développeurs), secret de webhook (`whsec_…`). Enregistrer dans Virevo →
Développeurs l'URL de webhook : `https://votre-boutique/virevo/standard/webhook`.

## Distribution & build
```bash
bash bin/build-zip.sh   # → dist/virevo-magento-<version>.zip
```
Release automatisée : un tag `vX.Y.Z` déclenche le workflow de release (ZIP joint).

## Référence API
- Guide : https://virevo.fr/developpeurs
- OpenAPI : https://app.virevo.fr/docs

## Notes / à venir
- **Limite connue (audit du 2026-09-22)** : seul `payment.succeeded` est traité.
  `payment.failed`, `payment.canceled` et `payment.refunded` sont acquittés puis
  ignorés, donc une commande dont le paiement échoue reste en attente
  indéfiniment, et un remboursement fait depuis le tableau de bord Virevo
  n'apparaît jamais dans la boutique. À corriger.
- EUR uniquement.
- Mode test : `POST /v1/payments/{id}/simulate` (ou la page de test hébergée)
  pour déclencher le webhook et valider de bout en bout.

## Licence
GPLv2 or later.
