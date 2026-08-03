# CHANGELOG FACTURESITUATIONMIGRATION FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Staging
- MAJ - Seules les lignes réellement facturables sont migrées (product_type 0=produit / 1=service) ; les lignes texte/commentaire/sous-total (autre product_type) sont laissées intactes et exclues de la vérification. Remplace l'ancien filtre basé sur le special_code 104777.
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
