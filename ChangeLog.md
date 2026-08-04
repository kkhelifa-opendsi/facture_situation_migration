# CHANGELOG FACTURESITUATIONMIGRATION FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Staging
- NEW - Migration étape 3 : traitement automatique par lots via AJAX avec barre de progression (plus besoin de cliquer pour chaque lot). Nouvelle méthode `migrationStep3Batch()`, endpoint `ajax/ajax_step3.php` ; arrêt automatique si un lot ne progresse plus (évite une boucle infinie sur des cycles en erreur). L'écran passe automatiquement à l'étape 4 dès qu'il ne reste plus rien à migrer (auto-complétion au chargement de la page, indépendante du JS). Ancien handler POST `doStep3` supprimé.
- NEW - Écran de vérification : boutons « Correction » (menu déroulant, via `dolGetButtonAction`) aux niveaux cycle, facture et ligne, affichés uniquement en cas d'écart. Au niveau cycle : « Considérer le cycle comme OK » (sans modifier les données) et « Appliquer les valeurs attendues ».
- NEW - Application des valeurs attendues : ne corrige que les lignes réellement en écart (les lignes/factures correctes ne sont jamais réécrites). Pour chaque facture : forçage en dur des totaux d'en-tête au montant réglé si la facture est intégralement payée (`paye = 1` ou reste à payer nul), sinon recalcul des totaux depuis les lignes. Revérification du cycle après correction avec affichage du résultat.
- NEW - Vérification : `getVerificationCycleDetail` expose le statut de paiement par facture ; une facture intégralement payée dont l'écart au backup est corrigé est considérée OK même si son en-tête diffère de la somme des lignes d'un centime (arrondi mode 1↔mode 2).
- MAJ - Seules les lignes réellement facturables sont migrées (product_type 0=produit / 1=service) ; les lignes texte/commentaire/sous-total (autre product_type) sont laissées intactes et exclues de la vérification. Remplace l'ancien filtre basé sur le special_code 104777.
- MAJ - Migration : les montants de ligne (HT, TVA, TTC, taxes locales, devise) sont recalculés via `calcul_price_total` sur le pourcentage delta — comme le fait Dolibarr nativement (représentation mode 2 canonique) — au lieu de soustraire les montants cumulés stockés. Supprime la dépendance à la correction conditionnelle d'`update_price`.
- MAJ - Vérification rendue indépendante du calcul de migration : pourcentage / HT / taxes locales / HT devise comparés au delta du backup ; TVA vérifiée au niveau facture ; TTC dérivé des composantes attendues pour rester cohérent (ttc = ht + tva + taxes locales).
- MAJ - Page de vérification : le clic sur le badge OK/Erreur d'une ligne ou d'une facture affiche désormais tous les champs (et plus seulement ceux en écart) ; le badge est toujours dépliable.
- FIX - Correction des faux positifs de vérification : écarts négatifs aberrants sur la TVA/TTC de ligne (montants sources incohérents en mode 1) et attendu TTC incohérent avec l'attendu HT.
- FIX - Navigation : après correction (ou passage OK) d'un cycle alors que le filtre « en erreur » est actif, redirection automatique vers le cycle en erreur suivant (le cycle corrigé quitte le filtre) au lieu de rester dessus avec un précédent/suivant cassé.
- FIX - Correction de la détection de la version installée à l'installation/mise à jour du module.
- FIX - Correction des alias SQL (AS) dans les requêtes de migration de la table de suivi.

## 0.4
- NEW - Refonte de l'interface admin en pages séparées : migration, configuration et vérification.
- NEW - Outillage de vérification post-migration avec statut par cycle (colonne "checked" dans la table de migration) et bouton pour revérifier tous les cycles.
- NEW - Page de détail d'un cycle : affichage des lignes, navigation cycle précédent/suivant, boutons de revérification et de rollback par cycle, mise en évidence des écarts et erreurs sur les montants.
- NEW - Support de PostgreSQL dans le moteur de migration.
- NEW - Rollback par cycle (en plus du rollback global).
- MAJ - Renforcement du moteur : recalcul des totaux de la facture, gestion des localtax (localtax1/localtax2) et backup des factures.
- MAJ - Refonte du traitement des cycles et du statut de migration d'un cycle ; déplacement des styles dans un fichier CSS dédié.
- MAJ - Corrections et mises en conformité avec les standards Dolibarr (phpcs).
- FIX - Correction des calculs d'écart lors des vérifications.
- FIX - Correction de la mise à jour des constantes globales au bon endroit.
- FIX - Correction des tests de vérification.

## 0.3
- NEW - Ajout entrées logs (dolibarr_situationmigration.log) - Plus de details avec FactureSituationMigration::log_detail = 1

## 0.2
- FIX - La migration est faite sur l'entité en cours. chaque entité doit effectuer la migration.
- FIX - Correction de la boucle du script de migration.
- MAJ - Backup des lignes de factures de situation uniquement, les autres lignes ne sont pas impactées.
- NEW - Paramètre cycle_limit dans la classe FactureSituationMigration pour limiter le nombre de cycles de factures à traiter, 50 par défaut.
- NEW - Rollback fonctionnel.

## 0.1 - Initial version
