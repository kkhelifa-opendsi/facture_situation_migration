<?php
/* Copyright (C) 2023 SuperAdmin
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
 * \file    facturesituationmigration/lib/facturesituationmigration.lib.php
 * \ingroup facturesituationmigration
 * \brief   Library files with common functions for FactureSituationMigration
 */

/**
 * Prepare admin pages header
 *
 * @return array
 */
function facturesituationmigrationAdminPrepareHead()
{
	global $db, $langs, $conf;

	$langs->load("facturesituationmigration@facturesituationmigration");

	$h = 0;
	$head = array();

	$head[$h][0] = dol_buildpath("/facturesituationmigration/admin/setup.php", 1);
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;
	$head[$h][0] = dol_buildpath("/facturesituationmigration/admin/migration.php", 1);
	$head[$h][1] = $langs->trans("FactureSituationMigrationTabMigration");
	$head[$h][2] = 'migration';
	$h++;

	/*
	$head[$h][0] = dol_buildpath("/facturesituationmigration/admin/myobject_extrafields.php", 1);
	$head[$h][1] = $langs->trans("ExtraFields");
	$nbExtrafields = is_countable($extrafields->attributes['myobject']['label']) ? count($extrafields->attributes['myobject']['label']) : 0;
	if ($nbExtrafields > 0) {
		$head[$h][1] .= ' <span class="badge">' . $nbExtrafields . '</span>';
	}
	$head[$h][2] = 'myobject_extrafields';
	$h++;
	*/

	if (getDolGlobalInt('MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP') >= 3) {
		dol_include_once('/facturesituationmigration/class/facturesituationmigration.class.php');
		$migration_tmp = new FactureSituationMigration($db);
		if ($migration_tmp->backupTablesExist()) {
			$head[$h][0] = dol_buildpath("/facturesituationmigration/admin/verify.php", 1);
			$head[$h][1] = $langs->trans("FactureSituationMigrationTabVerification");
			$head[$h][2] = 'verify';
			$h++;
		}
	}

	$head[$h][0] = dol_buildpath("/facturesituationmigration/admin/about.php", 1);
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	// Show more tabs from modules
	// Entries must be declared in modules descriptor with line
	//$this->tabs = array(
	//	'entity:+tabname:Title:@facturesituationmigration:/facturesituationmigration/mypage.php?id=__ID__'
	//); // to add new tab
	//$this->tabs = array(
	//	'entity:-tabname:Title:@facturesituationmigration:/facturesituationmigration/mypage.php?id=__ID__'
	//); // to remove a tab
	complete_head_from_modules($conf, $langs, null, $head, $h, 'facturesituationmigration@facturesituationmigration');

	complete_head_from_modules($conf, $langs, null, $head, $h, 'facturesituationmigration@facturesituationmigration', 'remove');

	return $head;
}
