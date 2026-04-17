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
$search_status = GETPOST('search_status', 'alpha');
$search_year = GETPOSTINT('search_year');

if (empty($search_status)) {
	$search_status = 'all';
}
$tolerance = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_VERIFY_TOLERANCE', '0.01');

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

/*
 * Actions
 */

// Recalculate invoice totals (kept for manual troubleshooting if needed)
//if ($action == 'recalculate') {
//	$token = GETPOST('token', 'alpha');
//	if ($token == newToken()) {
//		$nb = $migration->recalculateInvoiceTotals();
//		if ($nb >= 0) {
//			setEventMessages($langs->trans('FactureSituationMigrationRecalcDone', $nb), null, 'mesgs');
//		} else {
//			setEventMessages($migration->error, null, 'errors');
//		}
//		header('Location: '.$_SERVER['PHP_SELF']);
//		exit;
//	}
//}

// CSV Export
if ($action == 'export_csv') {
	if (!$migration->backupTablesExist()) {
		setEventMessages($langs->trans('FactureSituationMigrationNoBackupTables'), null, 'errors');
		header('Location: '.$_SERVER['PHP_SELF']);
		exit;
	}

	$data = $migration->getVerificationCyclesList($search_status, $search_year, $sortfield, $sortorder, 0, 0, $tolerance);

	header('Content-Type: text/csv; charset=utf-8');
	header('Content-Disposition: attachment; filename="verification_migration_'.date('Y-m-d').'.csv"');

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


/*
 * View
 */

$form = new Form($db);

$help_url = '';
$page_name = "FactureSituationMigrationTabVerification";

llxHeader('', $langs->trans($page_name), $help_url);

// Subheader
$linkback = '<a href="'.DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1">'.$langs->trans("BackToModuleList").'</a>';
print load_fiche_titre($langs->trans($page_name), $linkback, 'title_setup');

// Configuration header
$head = facturesituationmigrationAdminPrepareHead();
print dol_get_fiche_head($head, 'verify', $langs->trans($page_name), -1, "facturesituationmigration@facturesituationmigration");

print '<span class="opacitymedium">'.$langs->trans("FactureSituationMigrationVerificationPage").'</span><br>';
print '<span class="opacitymedium">'.$langs->trans('FactureSituationMigrationToleranceNote', $tolerance).'</span><br>';
print '<br>';

// Check backup tables
if (!$migration->backupTablesExist()) {
	print '<div class="warning">'.$langs->trans('FactureSituationMigrationNoBackupTables').'</div>';
	print dol_get_fiche_end();
	llxFooter();
	$db->close();
	return;
}

if ($cycle_ref > 0) {
	// ========================================
	// DETAIL VIEW
	// ========================================

	// Back link
	print '<a href="'.$backtopage.'" class="butAction">'.$langs->trans('FactureSituationMigrationBackToList').'</a>';
	print '<br><br>';

	print load_fiche_titre($langs->trans('FactureSituationMigrationCycleRef', $cycle_ref));

	// Cycle summary from list data
	$cycle_data = $migration->getVerificationCyclesList('all', 0, 'cycle_ref', 'ASC', 0, 0, $tolerance);
	$cycle_summary = null;
	foreach ($cycle_data['cycles'] as $c) {
		if ($c['cycle_ref'] == $cycle_ref) {
			$cycle_summary = $c;
			break;
		}
	}
	$verify = $migration->verifyCycle($cycle_ref, $tolerance);

	if ($verify === false) {
		// SQL error
		print '<div class="error">'.dol_escape_htmltag($migration->error).'</div>';
	} elseif (empty($verify['detail'])) {
		print '<div class="opacitymedium">'.$langs->trans('NoRecordFound').'</div>';
	} else {
		$detail = $verify['detail'];

		// Single table with 12 columns, two headers
		print '<table class="noborder centpercent">';

		// Cycle summary header
		if ($cycle_summary) {
			print '<tr class="liste_titre">';
			print '<td>'.$langs->trans('FactureSituationMigrationCycle').'</td>';
			print '<td>'.$langs->trans('FactureSituationMigrationNbInvoices').'</td>';
			print '<td>'.$langs->trans('FactureSituationMigrationYear').'</td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupHT').'</td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentHT').'</td>';
			print '<td class="right"></td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviationHT').'</td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupTTC').'</td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentTTC').'</td>';
			print '<td class="right"></td>';
			print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviationTTC').'</td>';
			print '<td class="center">'.$langs->trans('FactureSituationMigrationStatus').'</td>';
			print '</tr>';
			$row_class = $cycle_summary['status_ok'] ? '' : ' style="background-color: #fdd;"';
			print '<tr class="oddeven"'.$row_class.'>';
			print '<td><strong>'.$langs->trans('FactureSituationMigrationCycleRef', $cycle_summary['cycle_ref']).'</strong></td>';
			print '<td><strong>'.$cycle_summary['nb_factures'].'</strong></td>';
			print '<td><strong>'.$cycle_summary['year'].'</strong></td>';
			print '<td class="right nowraponall"><strong>'.price($cycle_summary['backup_ht']).'</strong></td>';
			print '<td class="right nowraponall"><strong>'.price($cycle_summary['current_ht']).'</strong></td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall"><strong>'.FactureSituationMigration::badgeStatus($cycle_summary['ecart_ht_ok'], '0', price($cycle_summary['ecart_ht'])).'</strong></td>';
			print '<td class="right nowraponall"><strong>'.price($cycle_summary['backup_ttc']).'</strong></td>';
			print '<td class="right nowraponall"><strong>'.price($cycle_summary['current_ttc']).'</strong></td>';
			print '<td class="right"></td>';
			print '<td class="right nowraponall"><strong>'.FactureSituationMigration::badgeStatus($cycle_summary['ecart_ttc_ok'], '0', price($cycle_summary['ecart_ttc'])).'</strong></td>';
			print '<td class="center">'.FactureSituationMigration::badgeStatus($cycle_summary['status_ok'], 'OK', $langs->trans('Error')).'</td>';
			print '</td>';
			print '</tr>';
		}

		// Invoice detail header
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('FactureSituationMigrationSituationNb', '').'</td>';
		print '<td>'.$langs->trans('Ref').'</td>';
		print '<td>'.$langs->trans('Type').'</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupValue').' HT</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentValue').' HT</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationExpectedDelta').' HT</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviation').' HT</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupValue').' TTC</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentValue').' TTC</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationExpectedDelta').' TTC</td>';
		print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviation').' TTC</td>';
		print '<td class="center">'.$langs->trans('FactureSituationMigrationStatus').'</td>';
		print '</tr>';

		// Invoice detail rows
		$nbInvoices = count($detail);
		$idxInvoice = 0;
		foreach ($detail as $counter => $info) {
			$idxInvoice++;
			$expected = isset($info['expected']) ? $info['expected'] : $info['backup'];
			$row_class = $info['facture_ok'] ? '' : ' style="background-color: #fdd;"';

			$facture_static = new Facture($db);
			$facture_static->id = $info['facture_id'];
			$facture_static->ref = $info['ref'];
			$facture_static->type = $info['type'];

			print '<tr class="oddeven"'.$row_class.'>';
			print '<td>'.$langs->trans('FactureSituationMigrationSituationNb', $counter).'</td>';
			print '<td>'.$facture_static->getNomUrl(1).'</td>';
			print '<td>'.$facture_static->getLibType().'</td>';
			print '<td class="right nowraponall">'.price($info['backup']['total_ht']).'</td>';
			print '<td class="right nowraponall">'.price($info['current']['total_ht']).'</td>';
			print '<td class="right nowraponall">'.price($expected['total_ht']).'</td>';
			print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($info['ecart_ht_ok'], '0', price($info['ecart_ht'])).'</td>';
			print '<td class="right nowraponall">'.price($info['backup']['total_ttc']).'</td>';
			print '<td class="right nowraponall">'.price($info['current']['total_ttc']).'</td>';
			print '<td class="right nowraponall">'.price($expected['total_ttc']).'</td>';
			print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($info['ecart_ttc_ok'], '0', price($info['ecart_ttc'])).'</td>';
			print '<td class="center">'.FactureSituationMigration::badgeStatus($info['facture_ok'], 'OK', $langs->trans('Error')).'</td>';
			print '</tr>';

			// Lines (toggle)
			$nb_lines = count($info['lines']);
			if ($nb_lines > 0) {
				print '<tr class="oddeven">';
				print '<td colspan="12">';
				print '<a class="reposition" href="#" onclick="jQuery(\'.lines-sit-'.$counter.'\').toggle(); return false;">';
				print '<i class="fas fa-chevron-down paddingright"></i>';
				print $langs->trans('FactureSituationMigrationShowLines').' ('.$nb_lines.')';
				print '</a>';
				print '</td>';
				print '</tr>';

				// Lines header
				print '<tr class="liste_titre lines-sit-'.$counter.'" style="display:none">';
				print '<td>'.$langs->trans('FactureSituationMigrationLineId', '').'</td>';
				print '<td colspan="2">Description</td>';
				print '<td class="right">% backup</td>';
				print '<td class="right">% '.$langs->trans('FactureSituationMigrationCurrentValue').'</td>';
				print '<td class="right">% '.$langs->trans('FactureSituationMigrationExpectedDelta').'</td>';
				print '<td class="right">% '.$langs->trans('FactureSituationMigrationDeviation').'</td>';
				print '<td class="right">HT backup</td>';
				print '<td class="right">HT '.$langs->trans('FactureSituationMigrationCurrentValue').'</td>';
				print '<td class="right">HT '.$langs->trans('FactureSituationMigrationExpectedDelta').'</td>';
				print '<td class="right">HT '.$langs->trans('FactureSituationMigrationDeviation').'</td>';
				print '<td class="center">'.$langs->trans('FactureSituationMigrationStatus').'</td>';
				print '</tr>';

				foreach ($info['lines'] as $line_id => $line) {
					$line_expected = isset($line['expected']) ? $line['expected'] : $line['backup'];
					$line_style = $line['line_ok'] ? '' : ' background-color: #fdd;';

					$desc = !empty($line['label']) ? $line['label'] : $line['description'];
					$desc = dol_trunc(dol_string_nohtmltag($desc), 40);

					print '<tr class="oddeven lines-sit-'.$counter.'" style="display:none;'.$line_style.'">';
					print '<td>'.$langs->trans('FactureSituationMigrationLineId', $line_id).'</td>';
					print '<td colspan="2">'.dol_escape_htmltag($desc).'</td>';
					print '<td class="right nowraponall">'.$line['backup']['situation_percent'].'%</td>';
					print '<td class="right nowraponall">'.$line['current']['situation_percent'].'%</td>';
					print '<td class="right nowraponall">'.$line_expected['situation_percent'].'%</td>';
					print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($line['ecart_pct_ok'], '0', $line['ecart_pct']).'</td>';
					print '<td class="right nowraponall">'.price($line['backup']['total_ht']).'</td>';
					print '<td class="right nowraponall">'.price($line['current']['total_ht']).'</td>';
					print '<td class="right nowraponall">'.price($line_expected['total_ht']).'</td>';
					print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($line['ecart_ht_ok'], '0', price($line['ecart_ht'])).'</td>';
					print '<td class="center">'.FactureSituationMigration::badgeStatus($line['line_ok'], 'OK', $langs->trans('Error')).'</td>';
					print '</tr>';
				}

				// Invoice detail header for next invoices
				if ($idxInvoice < $nbInvoices) {
				print '<tr class="liste_titre lines-sit-'.$counter.'" style="display:none">';
				print '<td>'.$langs->trans('FactureSituationMigrationSituationNb', '').'</td>';
				print '<td>'.$langs->trans('Ref').'</td>';
				print '<td>'.$langs->trans('Type').'</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupValue').' HT</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentValue').' HT</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationExpectedDelta').' HT</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviation').' HT</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationBackupValue').' TTC</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationCurrentValue').' TTC</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationExpectedDelta').' TTC</td>';
				print '<td class="right">'.$langs->trans('FactureSituationMigrationDeviation').' TTC</td>';
				print '<td class="center">'.$langs->trans('FactureSituationMigrationStatus').'</td>';
				print '</tr>';
				}
			}
		}
		print '</table>';

		// Section 3: Coherence checks
		print '<br>';
		print load_fiche_titre($langs->trans('FactureSituationMigrationCoherenceChecks'));

		$checks = $verify['checks'];

		print '<table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('FactureSituationMigrationStatus').'</td>';
		print '<td>'.$langs->trans('Description').'</td>';
		print '<td>'.$langs->trans('FactureSituationMigrationDetail').'</td>';
		print '</tr>';

		foreach ($checks as $check) {
			$check_class = $check['ok'] ? '' : ' style="background-color: #fdd;"';
			print '<tr class="oddeven"'.$check_class.'>';
			print '<td>'.FactureSituationMigration::badgeStatus($check['ok'], 'OK', $langs->trans('Error')).'</td>';
			print '<td>'.$langs->trans('FactureSituationMigration'.$check['label']).'</td>';
			print '<td>'.dol_escape_htmltag($check['details']).'</td>';
			print '</tr>';
		}
		print '</table>';
	}
} else {
	// ========================================
	// LIST VIEW
	// ========================================

	$data = $migration->getVerificationCyclesList($search_status, $search_year, $sortfield, $sortorder, $limit, $offset, $tolerance);

	// Summary
	$nb_total = $data['nb_ok'] + $data['nb_error'];
	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';
	print '<tr><td class="titlefield">'.$langs->trans('FactureSituationMigrationCyclesVerified', $nb_total).'</td>';
	print '<td>';
	print '<span class="badge badge-status4 badge-status">'.$langs->trans('FactureSituationMigrationCyclesOk', $data['nb_ok']).'</span> ';
	if ($data['nb_error'] > 0) {
		print '<span class="badge badge-status8 badge-status">'.$langs->trans('FactureSituationMigrationCyclesWithErrors', $data['nb_error']).'</span>';
	}
	print '</td></tr>';
	// Progress bar
	if ($nb_total > 0) {
		$pct_ok = round(($data['nb_ok'] / $nb_total) * 100);
		print '<tr><td></td><td>';
		print '<div style="background-color: #ddd; border-radius: 4px; height: 20px; width: 300px; display: inline-block;">';
		print '<div style="background-color: #4caf50; height: 100%; border-radius: 4px; width: '.$pct_ok.'%;"></div>';
		print '</div> '.$pct_ok.'%';
		print '</td></tr>';
	}
	print '</table>';
	print '</div><br>';

	// Filters
	print '<form method="GET" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="sortfield" value="'.$sortfield.'">';
	print '<input type="hidden" name="sortorder" value="'.$sortorder.'">';
	//print '<input type="hidden" name="page" value="'.$page.'">';
	print '<input type="hidden" name="page_y" value="">';

	print '<div class="liste_titre liste_titre_bydiv centpercent">';
	print '<div class="divsearchfield paddingtop paddingbottom">';

	// Status filter
	print '<label for="search_status">'.$langs->trans('FactureSituationMigrationStatus').':</label> ';
	print '<select name="search_status" id="search_status" class="flat">';
	print '<option value="all"'.($search_status == 'all' ? ' selected' : '').'>'.$langs->trans('FactureSituationMigrationFilterAll').'</option>';
	print '<option value="ok"'.($search_status == 'ok' ? ' selected' : '').'>'.$langs->trans('FactureSituationMigrationFilterOk').'</option>';
	print '<option value="error"'.($search_status == 'error' ? ' selected' : '').'>'.$langs->trans('FactureSituationMigrationFilterErrors').'</option>';
	print '</select>';

	// Year filter
	print ' <label for="search_year">'.$langs->trans('FactureSituationMigrationYear').':</label> ';
	print '<input type="text" name="search_year" id="search_year" value="'.($search_year > 0 ? $search_year : '').'" size="4" class="flat" placeholder="'.$langs->trans('FactureSituationMigrationAllYears').'">';

	print ' <input type="submit" class="button small" value="'.$langs->trans('Search').'">';
	print '</div>';
	print '</div>';

	// Pagination
	$num = count($data['cycles']);
	$nbtotalofrecords = $data['total'];
	if ($num > 0) $num = min($num + 1, $nbtotalofrecords); // for pagination
	$param = '';
	if ($limit > 0 && $limit != $conf->liste_limit) {
		$param .= '&limit='.((int) $limit);
	}
	if ($search_status != 'all') {
		$param .= '&search_status='.urlencode($search_status);
	}
	if ($search_year > 0) {
		$param .= '&search_year='.$search_year;
	}

	// Action buttons
	print '<div class="tabsAction tabsActionNoBottom">';
	// Recalculate totals button (kept for manual troubleshooting if needed)
	//if ($data['nb_error'] > 0) {
	// print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'" style="display:inline">';
	// print '<input type="hidden" name="token" value="'.newToken().'">';
	// print '<input type="hidden" name="action" value="recalculate">';
	// print '<input type="submit" class="butActionDelete" value="'.$langs->trans('FactureSituationMigrationRecalculate').'" onclick="return confirm(\''.$langs->trans('FactureSituationMigrationRecalculateConfirm').'\')">';
	// print '</form> ';
	//}
	// Export CSV button
	print '<a class="butAction" href="'.$_SERVER['PHP_SELF'].'?action=export_csv&token='.newToken().$param.'">'.$langs->trans('FactureSituationMigrationExportCSV').'</a>';
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
		print '<tr class="oddeven"><td colspan="11" class="opacitymedium">'.$langs->trans('NoRecordFound').'</td></tr>';
	}

	foreach ($data['cycles'] as $cycle) {
		$row_class = $cycle['status_ok'] ? '' : ' style="background-color: #fdd;"';
		print '<tr class="oddeven"'.$row_class.'>';
		print '<td>'.$langs->trans('FactureSituationMigrationCycleRef', $cycle['cycle_ref']).'</td>';
		print '<td class="right">'.$cycle['nb_factures'].'</td>';
		print '<td class="right">'.$cycle['year'].'</td>';
		print '<td class="right nowraponall">'.price($cycle['backup_ht']).'</td>';
		print '<td class="right nowraponall">'.price($cycle['current_ht']).'</td>';
		print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($cycle['ecart_ht_ok'], '0', price($cycle['ecart_ht'])).'</td>';
		print '<td class="right nowraponall">'.price($cycle['backup_ttc']).'</td>';
		print '<td class="right nowraponall">'.price($cycle['current_ttc']).'</td>';
		print '<td class="right nowraponall">'.FactureSituationMigration::badgeStatus($cycle['ecart_ttc_ok'], '0', price($cycle['ecart_ttc'])).'</td>';
		print '<td class="center">'.FactureSituationMigration::badgeStatus($cycle['status_ok'], 'OK', $langs->trans('Error')).'</td>';
		print '<td class="center">';
		print '<a href="'.$_SERVER['PHP_SELF'].'?cycle_ref='.$cycle['cycle_ref'].'&backtopage='.urlencode($_SERVER['PHP_SELF']."?page=".$page."&sortfield=".$sortfield."&sortorder=".$sortorder.$param).'">';
		print '<i class="fas fa-search"></i>';
		print '</a>';
		print '</td>';
		print '</tr>';
	}
	print '</table>';
}

// Page end
print dol_get_fiche_end();

llxFooter();
$db->close();
