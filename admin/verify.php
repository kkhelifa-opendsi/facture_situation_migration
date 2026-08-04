<?php
/* Copyright (C) 2023 SuperAdmin
 * Copyright (C) 2026 Kamel Khelifa
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    facturesituationmigration/admin/verify.php
 * \ingroup facturesituationmigration
 * \brief   Verification page - compare backup vs current data after migration.
 */

// Load Dolibarr environment
$res = 0;
if (!$res && !empty($_SERVER["CONTEXT_DOCUMENT_ROOT"])) {
	$res = @include $_SERVER["CONTEXT_DOCUMENT_ROOT"]."/main.inc.php";
}
$tmp = empty($_SERVER['SCRIPT_FILENAME']) ? '' : $_SERVER['SCRIPT_FILENAME']; $tmp2 = realpath(__FILE__); $i = strlen($tmp) - 1; $j = strlen($tmp2) - 1;
while ($i > 0 && $j > 0 && isset($tmp[$i]) && isset($tmp2[$j]) && $tmp[$i] == $tmp2[$j]) {
	$i--; $j--;
}
if (!$res && $i > 0 && file_exists(substr($tmp, 0, ($i + 1))."/main.inc.php")) {
	$res = @include substr($tmp, 0, ($i + 1))."/main.inc.php";
}
if (!$res && $i > 0 && file_exists(dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php")) {
	$res = @include dirname(substr($tmp, 0, ($i + 1)))."/main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $langs, $user, $conf, $db;

// Libraries
require_once DOL_DOCUMENT_ROOT."/core/lib/admin.lib.php";
require_once '../lib/facturesituationmigration.lib.php';

// Classes
dol_include_once('custom/facturesituationmigration/class/facturesituationmigration.class.php');

// Translations
$langs->loadLangs(array("admin", "bills", "facturesituationmigration@facturesituationmigration"));

// Access control
if (!$user->admin) {
	accessforbidden();
}

// Parameters
$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$cycle_ref = GETPOSTINT('cycle_ref');
$scope = GETPOST('scope', 'aZ09');
$target_id = GETPOSTINT('target_id');
$search_status = GETPOST('search_status', 'alpha');
$search_year = GETPOSTINT('search_year');

if (empty($search_status)) {
	$search_status = 'all';
}
$tolerance = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_VERIFY_TOLERANCE', '0');

// Load variable for pagination
$limit = GETPOSTINT('limit') ? GETPOSTINT('limit') : $conf->liste_limit;
$sortfield = GETPOST('sortfield', 'aZ09comma');
$sortorder = GETPOST('sortorder', 'aZ09comma');
$page = GETPOSTISSET('pageplusone') ? (GETPOSTINT('pageplusone') - 1) : GETPOSTINT("page");
if (!$sortorder) {
	$sortorder = "ASC";
}
if (!$sortfield) {
	$sortfield = "cycle_ref";
}
if (empty($page) || $page < 0 || GETPOST('button_search', 'alpha') || GETPOST('button_removefilter', 'alpha')) {
	// If $page is not defined, or '' or -1 or if we click on clear filters
	$page = 0;
}
$offset = $limit * $page;
$pageprev = $page - 1;
$pagenext = $page + 1;

$migration = new FactureSituationMigration($db);

// Build base URL for prev/next (preserve filters)
$params = '';
if ($search_status != 'all') {
	$params .= '&search_status='.urlencode($search_status);
}
if ($search_year > 0) {
	$params .= '&search_year='.$search_year;
}
if ($sortfield != 'cycle_ref') {
	$params .= '&sortfield='.urlencode($sortfield);
}
if ($sortorder != 'ASC') {
	$params .= '&sortorder='.urlencode($sortorder);
}
if (!empty($backtopage)) {
	$params .= '&backtopage='.urlencode($backtopage);
}


/*
 * Actions
 */

// Rollback a single cycle
if ($action == 'rollback_cycle' && $cycle_ref > 0) {
	$result_rb = $migration->rollbackMigration($cycle_ref);
	if ($result_rb < 0) {
		setEventMessages($migration->error, null, 'errors');
	} else {
		setEventMessages($langs->trans('FactureSituationMigrationRollbackCycleDone', $cycle_ref), null, 'mesgs');

		// Get adjacent cycles to redirect (cycle just left the filtered list)
		$adjacent = $migration->getAdjacentCycles($cycle_ref, $search_status, $search_year, $sortfield, $sortorder);
		$redirect_cycle = 0;
		if ($adjacent !== false) {
			if ($adjacent['next'] !== null) {
				$redirect_cycle = $adjacent['next'];
			} elseif ($adjacent['prev'] !== null) {
				$redirect_cycle = $adjacent['prev'];
			}
		} else {
			setEventMessages($migration->error, null, 'errors');
		}

		if ($redirect_cycle > 0) {
			$redirect_url = $_SERVER['PHP_SELF'].'?cycle_ref='.$redirect_cycle . $params;
		} else {
			$redirect_url = !empty($backtopage) ? $backtopage : $_SERVER['PHP_SELF'];
		}
		header('Location: '.$redirect_url);
		exit;
	}
}

// Re-verify a single cycle
if ($action == 'reverify_cycle' && $cycle_ref > 0) {
	$verify_result = $migration->verifyCycle($cycle_ref, $tolerance);
	if ($verify_result === false) {
		setEventMessages($migration->error, null, 'errors');
	} else {
		if ($verify_result['ok']) {
			$res_status = $migration->setCycleSuccessful($cycle_ref);
			if ($res_status < 0) {
				setEventMessages($migration->error, null, 'errors');
			} else {
				setEventMessages($langs->trans('FactureSituationMigrationReverifyCycleOk'), null, 'mesgs');
			}
		} else {
			$res_status = $migration->setCycleError($cycle_ref);
			if ($res_status < 0) {
				setEventMessages($migration->error, null, 'errors');
			} else {
				setEventMessages($langs->trans('FactureSituationMigrationReverifyCycleError'), null, 'warnings');
			}
		}
	}

	header('Location: '.$_SERVER['PHP_SELF'].'?cycle_ref='.$cycle_ref.$params);
	exit;
}

// Correction: mark a cycle as OK without changing data, or apply expected values
if (($action == 'force_cycle_ok' || $action == 'apply_expected') && $cycle_ref > 0) {
	// Capture the neighbouring cycle BEFORE the change, while this cycle is still in the
	// filtered list. If the correction makes it leave the current filter (e.g. it is no
	// longer in error), we redirect to that neighbour instead of staying on a cycle that
	// dropped out of the list, which would break prev/next navigation.
	$adjacent_before = $migration->getAdjacentCycles($cycle_ref, $search_status, $search_year, $sortfield, $sortorder);
	$new_status_val = null; // 1 = ok, -1 = error, null = unchanged

	if ($action == 'force_cycle_ok') {
		$res_corr = $migration->setCycleSuccessful($cycle_ref);
		if ($res_corr < 0) {
			setEventMessages($migration->error, null, 'errors');
		} else {
			$new_status_val = 1;
			setEventMessages($langs->trans('FactureSituationMigrationForceCycleOkDone', $cycle_ref), null, 'mesgs');
		}
	} else {
		$nb_corr = $migration->applyExpectedValues($cycle_ref, $scope, $target_id);
		if ($nb_corr < 0) {
			setEventMessages($migration->error, null, 'errors');
		} else {
			setEventMessages($langs->trans('FactureSituationMigrationApplyExpectedDone', $nb_corr), null, 'mesgs');
			// Always re-verify the whole cycle after the correction and refresh its status,
			// reporting the outcome so the user sees whether the cycle is now clean.
			$verify_corr = $migration->verifyCycle($cycle_ref, $tolerance);
			if ($verify_corr === false) {
				setEventMessages($migration->error, null, 'errors');
			} elseif ($verify_corr['ok']) {
				$migration->setCycleSuccessful($cycle_ref);
				$new_status_val = 1;
				setEventMessages($langs->trans('FactureSituationMigrationReverifyCycleOk'), null, 'mesgs');
			} else {
				$migration->setCycleError($cycle_ref);
				$new_status_val = -1;
				setEventMessages($langs->trans('FactureSituationMigrationReverifyCycleError'), null, 'warnings');
			}
		}
	}

	// Does the cycle still match the active status filter after the change?
	$still_listed = true;
	if ($new_status_val !== null) {
		if ($search_status == 'ok') {
			$still_listed = ($new_status_val == 1);
		} elseif ($search_status == 'error') {
			$still_listed = ($new_status_val == -1);
		} elseif ($search_status == 'not_migrated') {
			$still_listed = false; // status is now +/-1, no longer "not migrated"
		}
		// 'all' and 'migrated' still contain the cycle
	}

	// Stay on the same cycle if it is still listed; otherwise jump to the neighbour
	// captured before the change (next in the filtered error list, else previous).
	$redirect_cycle = $cycle_ref;
	if (!$still_listed) {
		$redirect_cycle = 0;
		if ($adjacent_before !== false) {
			if ($adjacent_before['next'] !== null) {
				$redirect_cycle = $adjacent_before['next'];
			} elseif ($adjacent_before['prev'] !== null) {
				$redirect_cycle = $adjacent_before['prev'];
			}
		}
	}

	if ($redirect_cycle > 0) {
		header('Location: ' . $_SERVER['PHP_SELF'] . '?cycle_ref=' . $redirect_cycle . $params);
	} else {
		header('Location: ' . (!empty($backtopage) ? $backtopage : $_SERVER['PHP_SELF']));
	}
	exit;
}

// CSV Export
if ($action == 'export_csv') {
	if (!$migration->backupTablesExist()) {
		setEventMessages($langs->trans('FactureSituationMigrationNoBackupTables'), null, 'errors');
		header('Location: ' . $_SERVER['PHP_SELF']);
		exit;
	}

	$data = $migration->getVerificationCyclesList($search_status, $search_year, $sortfield, $sortorder, 0, 0, $tolerance);
	if ($data === false) {
		setEventMessages($migration->error, null, 'errors');
	} else {
		header('Content-Type: text/csv; charset=utf-8');
		header('Content-Disposition: attachment; filename="verification_migration_' . date('Y-m-d') . '.csv"');

		$output = fopen('php://output', 'w');
		// BOM UTF-8
		fwrite($output, "\xEF\xBB\xBF");

		fputcsv($output, array(
			$langs->trans('FactureSituationMigrationCycle'),
			$langs->trans('FactureSituationMigrationNbInvoices'),
			$langs->trans('FactureSituationMigrationBackupHT'),
			$langs->trans('FactureSituationMigrationCurrentHT'),
			$langs->trans('FactureSituationMigrationDeviationHT'),
			$langs->trans('FactureSituationMigrationBackupTTC'),
			$langs->trans('FactureSituationMigrationCurrentTTC'),
			$langs->trans('FactureSituationMigrationDeviationTTC'),
			$langs->trans('FactureSituationMigrationStatus'),
		), ';');

		foreach ($data['cycles'] as $cycle) {
			fputcsv($output, array(
				$cycle['cycle_ref'],
				$cycle['nb_factures'],
				price2num($cycle['backup_ht'], 'MT'),
				price2num($cycle['current_ht'], 'MT'),
				price2num($cycle['ecart_ht'], 'MT'),
				price2num($cycle['backup_ttc'], 'MT'),
				price2num($cycle['current_ttc'], 'MT'),
				price2num($cycle['ecart_ttc'], 'MT'),
				$cycle['status_ok'] ? 'OK' : 'ERROR',
			), ';');
		}

		fclose($output);
		exit;
	}
}


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "FactureSituationMigrationTabVerification";

$arrayofjs = array('/facturesituationmigration/js/facturesituationmigration.js.php');
$arrayofcss = array('/facturesituationmigration/css/facturesituationmigration.css');
llxHeader('', $langs->trans($page_name), $help_url, '', 0, 0, $arrayofjs, $arrayofcss);

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = facturesituationmigrationAdminPrepareHead();
print dol_get_fiche_head($head, 'verify', $langs->trans($page_name), -1, "");

print '<span class="opacitymedium">'.$langs->trans("FactureSituationMigrationVerificationPage").'</span><br>';
print '<span class="opacitymedium">'.$langs->trans('FactureSituationMigrationToleranceNote', $tolerance).'</span><br>';
print '<br>';

// Check backup tables
if (!$migration->backupTablesExist()) {
	print '<div class="warning">' . $langs->trans('FactureSituationMigrationNoBackupTables') . '</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	return;
}

if ($cycle_ref > 0) {
	// ========================================
	// DETAIL VIEW
	// ========================================

	// Navigation: back + prev/next
	$adjacent = $migration->getAdjacentCycles($cycle_ref, $search_status, $search_year, $sortfield, $sortorder);
	if ($adjacent === false) {
		setEventMessages($migration->error, null, 'errors');
	}

	// Pagination: prev / back / next (right-aligned via load_fiche_titre)
	$nav_links = '';
	$prev_url = ($adjacent !== false && $adjacent['prev'] !== null) ? $_SERVER['PHP_SELF'].'?cycle_ref='.$adjacent['prev'].$params : '';
	$nav_links .= dolGetButtonAction($langs->trans('Previous'), '<i class="fas fa-chevron-left paddingright"></i>'.$langs->trans('Previous'), 'default', $prev_url, '', ($prev_url ? 1 : -1)).' ';
	$nav_links .= dolGetButtonAction($langs->trans('FactureSituationMigrationBackToList'), '', 'default', $backtopage, '', 1).' ';
	$next_url = ($adjacent !== false && $adjacent['next'] !== null) ? $_SERVER['PHP_SELF'].'?cycle_ref='.$adjacent['next'].$params : '';
	$nav_links .= dolGetButtonAction($langs->trans('Next'), $langs->trans('Next').'<i class="fas fa-chevron-right paddingleft"></i>', 'default', $next_url, '', ($next_url ? 1 : -1));
	print load_fiche_titre('', $nav_links, '');

	// Live verification (also reused below for the detail table). Computed here so the
	// correction button can be shown only when the cycle actually has errors.
	$verify = $migration->verifyCycle($cycle_ref, $tolerance);

	// Title + action buttons (right-aligned)
	$action_links = '';
	$action_links .= dolGetButtonAction($langs->trans('FactureSituationMigrationReverifyCycle'), '', 'default', $_SERVER['PHP_SELF'].'?action=reverify_cycle&cycle_ref='.$cycle_ref.$params, '', 1).' ';
	// Correction dropdown: only shown when the cycle has errors
	if ($verify !== false && empty($verify['ok'])) {
		$cycle_corr_url = array(
			array(
				'label' => 'FactureSituationMigrationForceCycleOk',
				'urlraw' => $_SERVER['PHP_SELF'].'?action=force_cycle_ok&token='.newToken().'&cycle_ref='.$cycle_ref.$params,
				'perm' => 1,
				'enabled' => true,
				'attr' => array('onclick' => "return confirm('".dol_escape_js($langs->trans('FactureSituationMigrationForceCycleOkConfirm'))."')"),
			),
			array(
				'label' => 'FactureSituationMigrationApplyExpectedCycle',
				'urlraw' => $_SERVER['PHP_SELF'].'?action=apply_expected&scope=cycle&token='.newToken().'&cycle_ref='.$cycle_ref.$params,
				'perm' => 1,
				'enabled' => true,
				'attr' => array('onclick' => "return confirm('".dol_escape_js($langs->trans('FactureSituationMigrationApplyExpectedCycleConfirm'))."')"),
			),
		);
		// Native Dolibarr dropdown: passing an array of buttons as $url builds the dropdown
		// (see contrat/card.php). The toggle label is the $text argument.
		$action_links .= dolGetButtonAction('', $langs->trans('FactureSituationMigrationCorrection'), 'default', $cycle_corr_url, '', 1).' ';
	}
	$action_links .= dolGetButtonAction($langs->trans('FactureSituationMigrationRollbackCycle'), '', 'delete', $_SERVER['PHP_SELF'].'?action=rollback_cycle&token='.newToken().'&cycle_ref='.$cycle_ref.$params, '', 1, array('attr' => array('onclick' => "return confirm('".dol_escape_js($langs->trans('FactureSituationMigrationRollbackCycleConfirm'))."')")));
	print load_fiche_titre($langs->trans('FactureSituationMigrationCycleRef', $cycle_ref), $action_links);

	// Cycle summary from list data
	$cycle_data = $migration->getVerificationCyclesList('all', 0, 'cycle_ref', 'ASC', 0, 0, $tolerance);
	$cycle_summary = null;
	if ($cycle_data === false) {
		setEventMessages($migration->error, null, 'errors');
	} else {
		foreach ($cycle_data['cycles'] as $c) {
			if ($c['cycle_ref'] == $cycle_ref) {
				$cycle_summary = $c;
				break;
			}
		}
	}

	if ($verify === false) {
		// SQL error
		print '<div class="error">' . dol_escape_htmltag($migration->error) . '</div>';
	} elseif (empty($verify['detail'])) {
		print '<div class="opacitymedium">' . $langs->trans('NoRecordFound') . '</div>';
	} else {
		$detail = $verify['detail'];

		// Credit notes (avoirs) of the cycle: shown for information only. They are not
		// migrated (no backup, expected or ecart) and are excluded from the checks. They are
		// displayed as their own block, in cycle order: right after the situation invoice they
		// credit (same situation_counter). Grouped by counter so each block can be interleaved.
		$credit_notes = $migration->getCycleCreditNotes($cycle_ref);
		$cn_by_counter = array();
		foreach ($credit_notes as $cn) {
			$cn_by_counter[(int) $cn['situation_counter']][] = $cn;
		}
		$cn_rendered = array();

		// Single table with 12 columns, two headers
		print '<table class="noborder centpercent">';

		// Cycle summary header
		if ($cycle_summary) {
			print '<tr class="liste_titre">';
			print '<td>' . $langs->trans('FactureSituationMigrationCycle') . '</td>';
			print '<td>' . $langs->trans('FactureSituationMigrationNbInvoices') . '</td>';
			print '<td>' . $langs->trans('FactureSituationMigrationYear') . '</td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationBackupHT') . '</td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationCurrentHT') . '</td>';
			print '<td class="right"></td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationDeviationHT') . '</td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationBackupTTC') . '</td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationCurrentTTC') . '</td>';
			print '<td class="right"></td>';
			print '<td class="right">' . $langs->trans('FactureSituationMigrationDeviationTTC') . '</td>';
			print '<td class="center">' . $langs->trans('FactureSituationMigrationStatus') . '</td>';
			print '<td class="center"></td>';
			print '</tr>';
			$row_class = $cycle_summary['status_ok'] ? '' : ' fsm-row-error';
			print '<tr class="oddeven' . $row_class . '">';
			print '<td><strong>' . $langs->trans('FactureSituationMigrationCycleRef', $cycle_summary['cycle_ref']) . '</strong></td>';
			print '<td><strong>' . $cycle_summary['nb_factures'] . '</strong></td>';
			print '<td><strong>' . $cycle_summary['year'] . '</strong></td>';
			print '<td class="right nowraponall"><strong>' . price($cycle_summary['backup_ht']) . '</strong></td>';
			print '<td class="right nowraponall"><strong>' . price($cycle_summary['current_ht']) . '</strong></td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall"><strong>' . FactureSituationMigration::badgeStatus($cycle_summary['ecart_ht_ok'], '0', price($cycle_summary['ecart_ht'])) . '</strong></td>';
			print '<td class="right nowraponall"><strong>' . price($cycle_summary['backup_ttc']) . '</strong></td>';
			print '<td class="right nowraponall"><strong>' . price($cycle_summary['current_ttc']) . '</strong></td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall"><strong>' . FactureSituationMigration::badgeStatus($cycle_summary['ecart_ttc_ok'], '0', price($cycle_summary['ecart_ttc'])) . '</strong></td>';
			print '<td class="center">' . FactureSituationMigration::badgeStatus($cycle_summary['status_ok'], 'OK', $langs->trans('Error')) . '</td>';
			print '<td class="center"></td>';
			print '</tr>';
		}

		// Invoice detail header
		print printInvoiceHeaders();

		// Invoice detail rows
		$nbInvoices = count($detail);
		$idxInvoice = 0;
		foreach ($detail as $counter => $info) {
			$idxInvoice++;
			$expected = isset($info['expected']) ? $info['expected'] : $info['backup'];
			$row_class = $info['facture_ok'] ? '' : ' fsm-row-error';
			$nb_lines = count($info['lines']);

			$facture_static = new Facture($db);
			$facture_static->id = $info['facture_id'];
			$facture_static->ref = $info['ref'];
			$facture_static->type = $info['type'];

			// Secondary amounts expandable row (only if errors on non-displayed fields)
			$fac_secondary_fields = array(
				'total_tva' => $langs->trans('VAT'),
				'localtax1' => $langs->trans('LT1'),
				'localtax2' => $langs->trans('LT2'),
				'multicurrency_total_ht' => $langs->trans('MulticurrencyAmountHT'),
				'multicurrency_total_tva' => $langs->trans('MulticurrencyAmountVAT'),
				'multicurrency_total_ttc' => $langs->trans('MulticurrencyAmountTTC'),
			);
			$secondary_errors = printSecondaryAmountsDetails($fac_secondary_fields, $info, $expected, $counter);

			print '<tr class="oddeven' . $row_class . '">';
			print '<td' . ($nb_lines > 0 ? ' onclick="jQuery(\'.lines-sit-' . $counter . '\').toggle(); jQuery(\'.fsm-sec-line-sit-' . $counter . '\').hide(); return false;" title="' . dol_escape_js($langs->trans('FactureSituationMigrationShowLines')) . '"' : '') .'>';
			print $langs->trans('FactureSituationMigrationSituationNb', $counter);
			if ($nb_lines > 0) {
				print '<i class="fas fa-chevron-down paddingleft paddingright"></i>(' . $nb_lines . ')';
			}
			print '</td>';
			print '<td>' . $facture_static->getNomUrl(1) . '</td>';
			print '<td>' . $facture_static->getLibType() . '</td>';
			print '<td class="right nowraponall">' . price($info['backup']['total_ht']) . '</td>';
			print '<td class="right nowraponall">' . price($info['current']['total_ht']) . '</td>';
			print '<td class="right nowraponall">' . price($expected['total_ht']) . '</td>';
			print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($info['ecart_total_ht_ok'], '0', price($info['ecart_total_ht'])) . '</td>';
			print '<td class="right nowraponall">' . price($info['backup']['total_ttc']) . '</td>';
			print '<td class="right nowraponall">' . price($info['current']['total_ttc']) . '</td>';
			print '<td class="right nowraponall">' . price($expected['total_ttc']) . '</td>';
			print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($info['ecart_total_ttc_ok'], '0', price($info['ecart_total_ttc'])) . '</td>';
			print '<td class="center"' . ($secondary_errors['nb'] > 0 ? ' onclick="jQuery(\'.fsm-sec-fac-' . $counter . '\').toggle(); return false;" title="' . dol_escape_js($langs->trans('FactureSituationMigrationSecondaryAmounts')) . '"' : '') .'>';
			print FactureSituationMigration::badgeStatus($info['facture_ok'], 'OK', $langs->trans('Error'));
			if ($secondary_errors['nb'] > 0) {
				print '<i class="fas fa-chevron-down paddingleft paddingleft"></i>';
				if ($secondary_errors['nb_err'] > 0) {
					print '(' . $secondary_errors['nb_err'] . ')';
				}
			}
			print '</td>';
			print '<td class="center">';
			if ((int) $info['type'] == (int) Facture::TYPE_SITUATION && empty($info['facture_ok'])) {
				print fsmCorrectionDropdown(array(array(
					'label' => 'FactureSituationMigrationApplyExpectedFacture',
					'urlraw' => $_SERVER['PHP_SELF'].'?action=apply_expected&scope=facture&target_id='.((int) $info['facture_id']).'&token='.newToken().'&cycle_ref='.$cycle_ref.$params,
					'attr' => array('onclick' => "return confirm('".dol_escape_js($langs->trans('FactureSituationMigrationApplyExpectedFactureConfirm'))."')"),
				)));
			}
			print '</td>';
			print '</tr>';

			print $secondary_errors['html'];

			// Lines (toggle)
			if ($nb_lines > 0) {
				// Lines header
				print printInvoiceLineHeaders($counter);

				$nbLines = count($info['lines']);
				$idxLine = 0;
				foreach ($info['lines'] as $line_id => $line) {
					$idxLine++;
					$line_expected = isset($line['expected']) ? $line['expected'] : $line['backup'];
					$line_class = $line['line_ok'] ? '' : ' fsm-row-error';

					$desc = !empty($line['label']) ? $line['label'] : $line['description'];
					$desc = dol_trunc(dol_string_nohtmltag($desc), 40);

					// Secondary amounts for this line (only if errors on non-displayed fields)
					$line_secondary_fields = array(
						'total_tva' => $langs->trans('VAT'),
						'total_ttc' => 'TTC',
						'total_localtax1' => $langs->trans('LT1'),
						'total_localtax2' => $langs->trans('LT2'),
						'multicurrency_total_ht' => $langs->trans('MulticurrencyAmountHT'),
						'multicurrency_total_tva' => $langs->trans('MulticurrencyAmountVAT'),
						'multicurrency_total_ttc' => $langs->trans('MulticurrencyAmountTTC'),
					);
					$secondary_errors = printSecondaryAmountsDetails($line_secondary_fields, $line, $line_expected, $counter, $line_id, $idxLine < $nbLines);

					print '<tr class="oddeven lines-sit-' . $counter . $line_class . ' fsm-lines-hidden">';
					print '<td>' . $langs->trans('FactureSituationMigrationLineId', $line_id) . '</td>';
					print '<td colspan="2">' . dol_escape_htmltag($desc) . '</td>';
					print '<td class="right nowraponall">' . $line['backup']['situation_percent'] . '%</td>';
					print '<td class="right nowraponall">' . $line['current']['situation_percent'] . '%</td>';
					print '<td class="right nowraponall">' . $line_expected['situation_percent'] . '%</td>';
					print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($line['ecart_situation_percent_ok'], '0', $line['ecart_situation_percent']) . '</td>';
					print '<td class="right nowraponall">' . price($line['backup']['total_ht']) . '</td>';
					print '<td class="right nowraponall">' . price($line['current']['total_ht']) . '</td>';
					print '<td class="right nowraponall">' . price($line_expected['total_ht']) . '</td>';
					print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($line['ecart_total_ht_ok'], '0', price($line['ecart_total_ht'])) . '</td>';
					print '<td class="center"' . ($secondary_errors['nb'] > 0 ? ' onclick="jQuery(\'.fsm-sec-line-' . $line_id . '\').toggle(); return false;" title="' . dol_escape_js($langs->trans('FactureSituationMigrationSecondaryAmounts')) . '"' : '') .'>';
					if ((int) $line['product_type'] !== 0 && (int) $line['product_type'] !== 1) {
						// Non-billable line (text/comment/subtotal): not migrated.
						print dolGetBadge($langs->trans('FactureSituationMigrationCycleNotMigrated'), '', 'status0', 'status');
					} else {
						print FactureSituationMigration::badgeStatus($line['line_ok'], 'OK', $langs->trans('Error'));
					}
					if ($secondary_errors['nb'] > 0) {
						print '<i class="fas fa-chevron-down paddingleft paddingleft"></i>';
						if ($secondary_errors['nb_err'] > 0) {
							print '(' . $secondary_errors['nb_err'] . ')';
						}
					}
					print '</td>';
					print '<td class="center">';
					if (((int) $line['product_type'] === 0 || (int) $line['product_type'] === 1) && empty($line['line_ok'])) {
						print fsmCorrectionDropdown(array(array(
							'label' => 'FactureSituationMigrationApplyExpectedLine',
							'urlraw' => $_SERVER['PHP_SELF'].'?action=apply_expected&scope=line&target_id='.((int) $line_id).'&token='.newToken().'&cycle_ref='.$cycle_ref.$params,
							'attr' => array('onclick' => "return confirm('".dol_escape_js($langs->trans('FactureSituationMigrationApplyExpectedLineConfirm'))."')"),
						)));
					}
					print '</td>';
					print '</tr>';

					print $secondary_errors['html'];
				}

				// Invoice detail header for next invoices
				if ($idxInvoice < $nbInvoices) {
					print printInvoiceHeaders($counter);
				}
			}

			// Credit note block(s) crediting this situation: rendered right after it,
			// keeping the cycle's facture/avoir display order (as in Dolibarr).
			if (!empty($cn_by_counter[$counter])) {
				foreach ($cn_by_counter[$counter] as $cn) {
					printCreditNoteBlock($db, $langs, $cn);
					$cn_rendered[(int) $cn['facture_id']] = true;
				}
			}
		}

		// Orphan credit notes: their situation_counter matches no situation invoice in the
		// cycle detail (edge case). Render them at the end so none are silently dropped.
		foreach ($credit_notes as $cn) {
			if (empty($cn_rendered[(int) $cn['facture_id']])) {
				printCreditNoteBlock($db, $langs, $cn);
			}
		}
		print '</table>';

		// Section 3: Coherence checks
		print '<br>';
		print load_fiche_titre($langs->trans('FactureSituationMigrationCoherenceChecks'));

		$checks = $verify['checks'];

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>' . $langs->trans('FactureSituationMigrationStatus') . '</td>';
		print '<td>' . $langs->trans('Description') . '</td>';
		print '<td>' . $langs->trans('FactureSituationMigrationDetail') . '</td>';
		print '</tr>';

		foreach ($checks as $check) {
			$check_class = $check['ok'] ? '' : ' fsm-row-error';
			print '<tr class="oddeven' . $check_class . '">';
			print '<td>' . FactureSituationMigration::badgeStatus($check['ok'], 'OK', $langs->trans('Error')) . '</td>';
			print '<td>' . $langs->trans('FactureSituationMigration' . $check['label']) . '</td>';
			print '<td>' . dol_escape_htmltag($check['details']) . '</td>';
			print '</tr>';
		}
		print '</table>';
	}
} else {
	// ========================================
	// LIST VIEW
	// ========================================

	$data = $migration->getVerificationCyclesList($search_status, $search_year, $sortfield, $sortorder, $limit, $offset, $tolerance);
	if ($data === false) {
		setEventMessages($migration->error, null, 'errors');
	} else {
		// Summary
		$nb_total = $data['nb_ok'] + $data['nb_error'] + $data['nb_not_migrated'];
		print '<div class="fichecenter">';
		print '<div class="underbanner clearboth"></div>';
		print '<table class="border centpercent tableforfield">';
		print '<tr><td class="titlefield">' . $langs->trans('FactureSituationMigrationNbCycles', $nb_total) . '</td>';
		print '<td>';
		print dolGetBadge($langs->trans('FactureSituationMigrationCyclesOk', $data['nb_ok']), '', 'status4', 'status').' ';
		if ($data['nb_error'] > 0) {
			print dolGetBadge($langs->trans('FactureSituationMigrationCyclesWithErrors', $data['nb_error']), '', 'status8', 'status');
		}
		if ($data['nb_not_migrated'] > 0) {
			print dolGetBadge($langs->trans('FactureSituationMigrationCyclesNotMigrated', $data['nb_not_migrated']), '', 'status0', 'status');
		}
		print '</td></tr>';
		// Progress bar
		if ($nb_total > 0) {
			$pct_ok = round(($data['nb_ok'] / $nb_total) * 100);
			print '<tr><td></td><td>';
			print '<div class="progress progress-striped" title="' . $pct_ok . '%">';
			print '<div class="progress-bar progress-bar-success" role="progressbar" style="width: ' . $pct_ok . '%" aria-valuenow="' . $pct_ok . '" aria-valuemin="0" aria-valuemax="100"></div>';
			print '</div>';
			print '</td></tr>';
		}
		print '</table>';
		print '</div><br>';

		// Filters
		print '<form method="GET" action="' . $_SERVER['PHP_SELF'] . '">';
		print '<input type="hidden" name="token" value="' . newToken() . '">';
		print '<input type="hidden" name="sortfield" value="' . $sortfield . '">';
		print '<input type="hidden" name="sortorder" value="' . $sortorder . '">';
		//print '<input type="hidden" name="page" value="'.$page.'">';
		print '<input type="hidden" name="page_y" value="">';

		print '<div class="liste_titre liste_titre_bydiv centpercent">';
		print '<div class="divsearchfield paddingtop paddingbottom">';

		// Status filter
		print '<label for="search_status">' . $langs->trans('FactureSituationMigrationStatus') . ':</label> ';
		$status_options = array(
			'all' => $langs->trans('FactureSituationMigrationFilterAll'),
			'migrated' => $langs->trans('FactureSituationMigrationFilterMigrated'),
			'not_migrated' => $langs->trans('FactureSituationMigrationFilterNotMigrated'),
			'ok' => $langs->trans('FactureSituationMigrationFilterOk'),
			'error' => $langs->trans('FactureSituationMigrationFilterErrors'),
		);
		print $form->selectarray('search_status', $status_options, $search_status, 0, 0, 0, '', 0, 0, 0, '', 'flat minwidth100');

		// Year filter
		print ' <label for="search_year">' . $langs->trans('FactureSituationMigrationYear') . ':</label> ';
		print '<input type="text" name="search_year" id="search_year" value="' . ($search_year > 0 ? $search_year : '') . '" size="4" class="flat" placeholder="' . $langs->trans('FactureSituationMigrationAllYears') . '">';
		print ' <input type="submit" class="button small" value="' . $langs->trans('Search') . '">';
		print '</div>';
		print '</div>';

		// Pagination
		$num = count($data['cycles']);
		$nbtotalofrecords = $data['total'];
		if ($num > 0) $num = min($num + 1, $nbtotalofrecords); // for pagination
		$param = '';
		if ($limit > 0 && $limit != $conf->liste_limit) {
			$param .= '&limit=' . ((int)$limit);
		}
		if ($search_status != 'all') {
			$param .= '&search_status=' . urlencode($search_status);
		}
		if ($search_year > 0) {
			$param .= '&search_year=' . $search_year;
		}

		// Action buttons
		print '<div class="tabsAction tabsActionNoBottom">';
		// Re-verify all cycles button
		print dolGetButtonAction($langs->trans('FactureSituationMigrationReverifyAll'), '', 'default', '#', 'btn-reverify', 1);
		// Bulk correction dropdown: auto-correct cycles in error whose deviation is within the
		// configured threshold. The item triggers the AJAX batch process (bound by its id).
		print fsmCorrectionDropdown(array(array(
			'label' => 'FactureSituationMigrationApplyExpectedAll',
			'id' => 'btn-correct-all',
			'urlraw' => '#',
		)));
		// Export CSV button
		print dolGetButtonAction($langs->trans('FactureSituationMigrationExportCSV'), '', 'default', $_SERVER['PHP_SELF'].'?action=export_csv&token='.newToken().$param, '', 1);
		print '</div>';

		// Re-verify progress (hidden by default)
		print '<div id="reverify-progress" class="fsm-progress-container">';
		print '<div class="fsm-progress-status"><span id="reverify-status"></span></div>';
		print '<div class="progress progress-striped" id="reverify-progress-bar" title="0%">';
		print '<div id="reverify-bar" class="progress-bar progress-bar-success" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>';
		print '</div>';
		print '</div>';

		// Bulk correction progress (hidden by default)
		print '<div id="correct-progress" class="fsm-progress-container">';
		print '<div class="fsm-progress-status"><span id="correct-status"></span></div>';
		print '<div class="progress progress-striped" id="correct-progress-bar" title="0%">';
		print '<div id="correct-bar" class="progress-bar progress-bar-success" role="progressbar" style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>';
		print '</div>';
		print '</div>';

		print_barre_liste('', $page, $_SERVER['PHP_SELF'], $param, $sortfield, $sortorder, '', $num, $nbtotalofrecords, '', 0, '', '', $limit);
		print '</form>';

		// Table
		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print_liste_field_titre($langs->trans('FactureSituationMigrationCycle'), $_SERVER['PHP_SELF'], 'cycle_ref', '', $param, '', $sortfield, $sortorder);
		print_liste_field_titre($langs->trans('FactureSituationMigrationNbInvoices'), $_SERVER['PHP_SELF'], 'nb_factures', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationYear'), $_SERVER['PHP_SELF'], 'year', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationBackupHT'), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationCurrentHT'), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationDeviationHT'), $_SERVER['PHP_SELF'], 'ecart_ht', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationBackupTTC'), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationCurrentTTC'), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationDeviationTTC'), $_SERVER['PHP_SELF'], 'ecart_ttc', '', $param, '', $sortfield, $sortorder, 'right ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationStatus'), $_SERVER['PHP_SELF'], 'status_ok', '', $param, '', $sortfield, $sortorder, 'center ');
		print_liste_field_titre($langs->trans('FactureSituationMigrationDetail'), $_SERVER['PHP_SELF'], '', '', $param, '', $sortfield, $sortorder, 'center ');
		print '</tr>';

		if ($num == 0) {
			print '<tr class="oddeven"><td colspan="11" class="opacitymedium">' . $langs->trans('NoRecordFound') . '</td></tr>';
		}

		foreach ($data['cycles'] as $cycle) {
			$row_class = $cycle['status_ok'] ? '' : ' fsm-row-error';
			print '<tr class="oddeven' . $row_class . '">';
			print '<td>' . $langs->trans('FactureSituationMigrationCycleRef', $cycle['cycle_ref']) . '</td>';
			print '<td class="right">' . $cycle['nb_factures'] . '</td>';
			print '<td class="right">' . $cycle['year'] . '</td>';
			if (!$cycle['not_migrated']) {
				print '<td class="right nowraponall">' . price($cycle['backup_ht']) . '</td>';
				print '<td class="right nowraponall">' . price($cycle['current_ht']) . '</td>';
				print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($cycle['ecart_ht_ok'], '0', price($cycle['ecart_ht'])) . '</td>';
				print '<td class="right nowraponall">' . price($cycle['backup_ttc']) . '</td>';
				print '<td class="right nowraponall">' . price($cycle['current_ttc']) . '</td>';
				print '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($cycle['ecart_ttc_ok'], '0', price($cycle['ecart_ttc'])) . '</td>';
				print '<td class="center">' . FactureSituationMigration::badgeStatus($cycle['status_ok'], 'OK', $langs->trans('Error')) . '</td>';
				print '<td class="center">';
				print '<a href="' . $_SERVER['PHP_SELF'] . '?cycle_ref=' . $cycle['cycle_ref'] . $param . '&sortfield=' . urlencode($sortfield) . '&sortorder=' . urlencode($sortorder) . '&backtopage=' . urlencode($_SERVER['PHP_SELF'] . "?page=" . urlencode($page) . "&sortfield=" . urlencode($sortfield) . '&sortorder=' . urlencode($sortorder) . $param) . '">';
				print '<i class="fas fa-search"></i>';
				print '</a>';
				print '</td>';
			} else {
				print '<td class="right nowraponall" colspan="6"></td>';
				print '<td class="center">'.dolGetBadge($langs->trans('FactureSituationMigrationCycleNotMigrated'), '', 'status0', 'status').'</td>';
				print '<td class="center"></td>';
			}
			print '</tr>';
		}
		print '</table>';
	}
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();

/**
 * Print a credit note (avoir) block: main invoice row + collapsible lines.
 *
 * Credit notes are not migrated (no backup, expected or ecart), so only the current
 * stored values are shown, with a neutral "not migrated" badge. Rendered as its own
 * block in the cycle's facture/avoir order, right after the situation invoice it credits.
 *
 * @param	DoliDB		$db		Database handler
 * @param	Translate	$langs	Language object
 * @param	array		$cn		Credit note data from getCycleCreditNotes()
 * @return	void
 */
function printCreditNoteBlock($db, $langs, $cn)
{
	$cn_id = (int) $cn['facture_id'];
	$cn_nb_lines = count($cn['lines']);

	$cn_static = new Facture($db);
	$cn_static->id = $cn['facture_id'];
	$cn_static->ref = $cn['ref'];
	$cn_static->type = $cn['type'];

	print '<tr class="oddeven">';
	print '<td' . ($cn_nb_lines > 0 ? ' onclick="jQuery(\'.lines-av-' . $cn_id . '\').toggle(); return false;" title="' . dol_escape_js($langs->trans('FactureSituationMigrationShowLines')) . '"' : '') . '>';
	print $langs->trans('CreditNote');
	if ($cn_nb_lines > 0) {
		print '<i class="fas fa-chevron-down paddingleft paddingright"></i>(' . $cn_nb_lines . ')';
	}
	print '</td>';
	print '<td>' . $cn_static->getNomUrl(1) . '</td>';
	print '<td>' . $cn_static->getLibType() . '</td>';
	print '<td class="right"></td>';
	print '<td class="right nowraponall">' . price($cn['total_ht']) . '</td>';
	print '<td class="right"></td>';
	print '<td class="right"></td>';
	print '<td class="right"></td>';
	print '<td class="right nowraponall">' . price($cn['total_ttc']) . '</td>';
	print '<td class="right"></td>';
	print '<td class="right"></td>';
	print '<td class="center">' . dolGetBadge($langs->trans('FactureSituationMigrationCycleNotMigrated'), '', 'status0', 'status') . '</td>';
	print '<td class="center"></td>';
	print '</tr>';

	if ($cn_nb_lines > 0) {
		print '<tr class="liste_titre lines-av-' . $cn_id . ' fsm-lines-hidden">';
		print '<td>' . $langs->trans('FactureSituationMigrationLineId', '') . '</td>';
		print '<td colspan="2">Description</td>';
		print '<td class="right"></td>';
		print '<td class="right">%</td>';
		print '<td class="right"></td>';
		print '<td class="right"></td>';
		print '<td class="right"></td>';
		print '<td class="right">HT</td>';
		print '<td class="right"></td>';
		print '<td class="right nowraponall">TTC</td>';
		print '<td class="center"></td>';
		print '<td class="center"></td>';
		print '</tr>';
		foreach ($cn['lines'] as $cn_line_id => $cn_line) {
			$cn_desc = !empty($cn_line['label']) ? $cn_line['label'] : $cn_line['description'];
			$cn_desc = dol_trunc(dol_string_nohtmltag($cn_desc), 40);
			print '<tr class="oddeven lines-av-' . $cn_id . ' fsm-lines-hidden">';
			print '<td>' . $langs->trans('FactureSituationMigrationLineId', $cn_line_id) . '</td>';
			print '<td colspan="2">' . dol_escape_htmltag($cn_desc) . '</td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall">' . $cn_line['situation_percent'] . '%</td>';
			print '<td class="right"></td>';
			print '<td class="right"></td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall">' . price($cn_line['total_ht']) . '</td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall">' . price($cn_line['total_ttc']) . '</td>';
			print '<td class="center"></td>';
			print '<td class="center"></td>';
			print '</tr>';
		}
	}
}

/**
 * Get invoice headers HTML to show
 * @param	int		$counter	Line counter (used show/hide line bloc)
 * @return	string				Html string
 */
function printInvoiceHeaders($counter = -1)
{
	global $langs;

	$out = '<tr class="liste_titre' . ($counter == -1 ? '' : ' lines-sit-' . $counter . ' fsm-lines-hidden') . '">';
	$out .= '<td>' . $langs->trans('FactureSituationMigrationSituationNb', '') . '</td>';
	$out .= '<td>' . $langs->trans('Ref') . '</td>';
	$out .= '<td>' . $langs->trans('Type') . '</td>';
	foreach ([$langs->trans('HT'), $langs->trans('TTC')] as $label) {
		$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationBackupValue') . ' ' . $label . '</td>';
		$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationCurrentValue') . ' ' . $label . '</td>';
		$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationExpectedDelta') . ' ' . $label . '</td>';
		$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationDeviation') . ' ' . $label . '</td>';
	}
	$out .= '<td class="center">' . $langs->trans('FactureSituationMigrationStatus') . '</td>';
	$out .= '<td class="center">' . $langs->trans('FactureSituationMigrationCorrection') . '</td>';
	$out .= '</tr>';

	return $out;
}

/**
 * Get invoice line headers HTML to show
 * @param	int		$counter	Line counter (used show/hide line bloc)
 * @param	int		$line_id	Line ID (used show/hide sub errors line bloc)
 * @return	string				Html string
 */
function printInvoiceLineHeaders($counter, $line_id = -1)
{
	global $langs;

	$out = '<tr class="liste_titre' . ($line_id == -1 ? ' lines-sit-' . $counter : ' fsm-sec-line-' . $line_id . ' fsm-sec-line-sit-' . $counter) . ' fsm-lines-hidden">';
	$out .= '<td>' . $langs->trans('FactureSituationMigrationLineId', '') . '</td>';
	$out .= '<td colspan="2">Description</td>';
	$out .= '<td class="right">% backup</td>';
	$out .= '<td class="right">% ' . $langs->trans('FactureSituationMigrationCurrentValue') . '</td>';
	$out .= '<td class="right">% ' . $langs->trans('FactureSituationMigrationExpectedDelta') . '</td>';
	$out .= '<td class="right">% ' . $langs->trans('FactureSituationMigrationDeviation') . '</td>';
	$out .= '<td class="right">HT backup</td>';
	$out .= '<td class="right">HT ' . $langs->trans('FactureSituationMigrationCurrentValue') . '</td>';
	$out .= '<td class="right">HT ' . $langs->trans('FactureSituationMigrationExpectedDelta') . '</td>';
	$out .= '<td class="right">HT ' . $langs->trans('FactureSituationMigrationDeviation') . '</td>';
	$out .= '<td class="center">' . $langs->trans('FactureSituationMigrationStatus') . '</td>';
	$out .= '<td class="center">' . $langs->trans('FactureSituationMigrationCorrection') . '</td>';
	$out .= '</tr>';

	return $out;
}

/**
 * Get secondary amounts detail HTML to show (ALL fields, not only those in error)
 *
 * The block lists every secondary amount so the user can verify the full set of
 * values when clicking the status badge, not just the fields flagged in error.
 * Rows in error keep the red highlight (fsm-row-error).
 *
 * @param	array	$secondary_fields	List of secondary amounts to show
 * @param	array	$info				Infos
 * @param	array	$expected			Expected infos
 * @param	int		$counter			Line counter (used show/hide line bloc)
 * @param	int		$line_id			Line ID (used show/hide sub errors line bloc)
 * @param	bool	$show_line_header	Show line header after the bloc
 * @return	array						array('html' => xxx, 'nb' => yyy, 'nb_err' => zzz)
 */
function printSecondaryAmountsDetails($secondary_fields, $info, $expected, $counter, $line_id = -1, $show_line_header = false)
{
	global $langs;

	$result = array('html' => '', 'nb' => 0, 'nb_err' => 0);
	if (empty($secondary_fields)) {
		return $result;
	}

	// Count errors (for the badge indicator) but always render every field.
	$nb_err = 0;
	foreach ($secondary_fields as $field => $label) {
		if (empty($info['ecart_' . $field . '_ok'])) {
			$nb_err++;
		}
	}

	$class = ($line_id == -1 ? 'fsm-sec-fac-' . $counter : 'fsm-sec-line-' . $line_id . ' fsm-sec-line-sit-' . $counter) . ' fsm-lines-hidden';
	$out = '<tr class="liste_titre ' . $class . '">';
	$out .= '<td colspan="3">' . $langs->trans('FactureSituationMigrationDetail') . '</td>';
	$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationBackupValue') . '</td>';
	$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationCurrentValue') . '</td>';
	$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationExpectedDelta') . '</td>';
	$out .= '<td class="right">' . $langs->trans('FactureSituationMigrationDeviation') . '</td>';
	$out .= '<td colspan="6"></td>';
	$out .= '</tr>';

	foreach ($secondary_fields as $field => $label) {
		$field_ok = !empty($info['ecart_' . $field . '_ok']);
		$out .= '<tr class="oddeven ' . $class . ($field_ok ? '' : ' fsm-row-error') . '">';
		$out .= '<td colspan="3">' . dol_escape_htmltag($label) . '</td>';
		$out .= '<td class="right nowraponall">' . price($info['backup'][$field]) . '</td>';
		$out .= '<td class="right nowraponall">' . price($info['current'][$field]) . '</td>';
		$out .= '<td class="right nowraponall">' . price($expected[$field]) . '</td>';
		$ecart = price($info['ecart_' . $field]);
		$out .= '<td class="right nowraponall">' . FactureSituationMigration::badgeStatus($info['ecart_' . $field . '_ok'], $ecart, $ecart) . '</td>';
		$out .= '<td colspan="6"></td>';
		$out .= '</tr>';
	}

	// Lines header for next line
	if ($show_line_header) {
		$out .= printInvoiceLineHeaders($counter, $line_id);
	}

	$result = array('html' => $out, 'nb' => count($secondary_fields), 'nb_err' => $nb_err);

	return $result;
}

/**
 * Build a "Correction" dropdown button, forcing the native Dolibarr dropdown markup even
 * for a single action.
 *
 * dolGetButtonAction() only builds a dropdown when its $url argument is an array of 2+
 * buttons; a single action renders a plain button. There is no native option to force a
 * one-item dropdown, so this thin wrapper reproduces core's dropdown-holder markup (same
 * classes as dolGetButtonAction) and still renders each item link through dolGetButtonAction
 * to keep the standard button markup and the confirm handlers. Used at facture and line
 * level (one correction action); the cycle level has 2 actions and uses dolGetButtonAction
 * with an array directly.
 *
 * @param	array	$items	List of actions: each array('label'=>langkey, 'urlraw'=>url, 'attr'=>array)
 * @return	string			HTML dropdown
 */
function fsmCorrectionDropdown($items)
{
	global $langs;

	if (empty($items)) {
		return '';
	}

	$label = $langs->trans('FactureSituationMigrationCorrection');
	$out = '<div class="dropdown inline-block dropdown-holder">';
	$out .= '<a style="margin-right: auto;" class="dropdown-toggle butAction" data-toggle="dropdown">'.$label.'</a>';
	$out .= '<div class="dropdown-content">';
	foreach ($items as $it) {
		$it_url = !empty($it['urlraw']) ? $it['urlraw'] : '#';
		$it_id = !empty($it['id']) ? $it['id'] : '';
		$out .= dolGetButtonAction($langs->trans($it['label']), '', 'default', $it_url, $it_id, 1, array('attr' => (empty($it['attr']) ? array() : $it['attr'])));
	}
	$out .= '</div>';
	$out .= '</div>';

	return $out;
}
