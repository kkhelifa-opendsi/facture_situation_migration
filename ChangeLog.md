# CHANGELOG FACTURESITUATIONMIGRATION FOR [DOLIBARR ERP CRM](https://www.dolibarr.org)

## Staging
- NEW - Migration étape 3 : traitement automatique par lots via AJAX avec barre de progression (plus besoin de cliquer pour chaque lot). Nouvelle méthode `migrationStep3Batch()`, endpoint `ajax/ajax_step3.php` ; arrêt automatique si un lot ne progresse plus (évite une boucle infinie sur des cycles en erreur). L'écran passe automatiquement à l'étape 4 dès qu'il ne reste plus rien à migrer (auto-complétion au chargement de la page, indépendante du JS). Ancien handler POST `doStep3` supprimé.
- NEW - Écran de vérification : boutons « Correction » (menu déroulant, via `dolGetButtonAction`) aux niveaux cycle, facture et ligne, affichés uniquement en cas d'écart. Au niveau cycle : « Considérer le cycle comme OK » (sans modifier les données) et « Appliquer les valeurs attendues ».
- NEW - Application des valeurs attendues : ne corrige que les lignes réellement en écart (les lignes/factures correctes ne sont jamais réécrites). Pour chaque facture : forçage en dur des totaux d'en-tête au montant réglé si la facture est intégralement payée (`paye = 1` ou reste à payer nul), sinon recalcul des totaux depuis les lignes. Revérification du cycle après correction avec affichage du résultat.
- NEW - Vérification : `getVerificationCycleDetail` expose le statut de paiement par facture ; une facture intégralement payée dont l'écart au backup est corrigé est considérée OK même si son en-tête diffère de la somme des lignes d'un centime (arrondi mode 1↔mode 2).
- MAJ - Écran de détail d'un cycle : les lignes non facturables (product_type ≠ 0/1 : texte/commentaire/sous-total) affichent un badge « Non migré » (gris) au lieu d'« OK », car elles ne sont pas migrées (même libellé que les avoirs).
- NEW - Écran de détail d'un cycle : les avoirs du cycle sont affichés dans un bloc dédié, à leur position dans le cycle (juste après la facture de situation qu'ils créditent), avec un badge « Non migré ». Le clic sur le badge (facture et ligne) déplie les montants secondaires (TVA, taxes locales, devise) en valeurs actuelles, comme pour les lignes normales. Nouvelle méthode `getCycleCreditNotes()`.
- FIX - Vérification : un cycle contenant un avoir n'est plus signalé en erreur à tort. L'avoir partageait le `situation_counter` de la facture qu'il crédite et entrait en collision dans le détail (indexé par counter), polluant les contrôles de cohérence. Les avoirs (non migrés, sans backup) sont désormais exclus de `getVerificationCycleDetail` et des contrôles.
- FIX - Liste des cycles : la colonne « nombre de factures » inclut désormais les avoirs (comptage sur toutes les factures du cycle, la requête étant auparavant pilotée par la table de backup qui n'en contient pas).
- MAJ - Pages migration et vérification : les boutons d'action (`<a class="butAction">`) sont rendus via `dolGetButtonAction` pour le balisage Dolibarr standard (navigation précédent/suivant/retour, revérifier, rollback, revérifier tout, export CSV, étape 3, modifier). Les soumissions POST des étapes 1/2/4 restent des formulaires.
- NEW - Liste des cycles : bouton déroulant « Correction » pour corriger en masse (traitement AJAX par lots avec barre de progression) les cycles en erreur dont l'écart est dans le seuil autorisé. Nouvelle méthode `correctBatch()` et endpoint `ajax/ajax_correct.php`. Un cycle n'est corrigé que si l'écart absolu de chaque facture ET de chaque ligne est dans le seuil ; si une seule ligne dépasse le seuil, tout le cycle est ignoré (compté séparément) et reste en erreur.
- NEW - Configuration : nouveau paramètre `FACTURESITUATIONMIGRATION_MAX_ECART_AUTOCORRECT` (défaut 0.1) — écart absolu maximal (HT, TVA, TTC, taxes locales, devise) de toute facture/ligne autorisant la correction automatique d'un cycle lors de la correction en masse.
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
