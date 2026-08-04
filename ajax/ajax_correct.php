<?php
/* Copyright (C) 2026 Kamel Khelifa
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
 * \file    facturesituationmigration/ajax/ajax_correct.php
 * \ingroup facturesituationmigration
 * \brief   AJAX endpoint to auto-correct cycles in error (deviation within threshold) in batches.
 */

if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', 1);
}

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
if (!$res && file_exists("../../../main.inc.php")) {
	$res = @include "../../../main.inc.php";
}
if (!$res && file_exists("../../../../main.inc.php")) {
	$res = @include "../../../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

global $user, $db;

// Access control
if (!$user->admin) {
	http_response_code(403);
	echo json_encode(array('error' => 'Access denied'));
	exit;
}

dol_include_once('custom/facturesituationmigration/class/facturesituationmigration.class.php');

$last_cycle_ref = GETPOSTINT('last_cycle_ref');
$batch_size = GETPOSTINT('batch_size');
if ($batch_size <= 0) {
	$batch_size = 10;
}

// Max deviation threshold comes from the module config (never trusted from the client).
$max_ecart = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_MAX_ECART_AUTOCORRECT', '0.1');

$migration = new FactureSituationMigration($db);

header('Content-Type: application/json; charset=utf-8');
echo json_encode($migration->correctBatch($batch_size, $last_cycle_ref, $max_ecart));

$db->close();
