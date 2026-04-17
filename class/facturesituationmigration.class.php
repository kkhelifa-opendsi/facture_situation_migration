<?php
/* Copyright (C) 2023       Progiseize        <contact@progiseize.fr>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
include_once DOL_DOCUMENT_ROOT.'/core/lib/price.lib.php';

/**
 * Class FactureSituationMigration
 *
 * Handles migration of situation invoices from mode 1 (cumulative) to mode 2 (delta/incremental).
 */
class FactureSituationMigration
{
	/**
	 * @var string Table facture name.
	 */
	public $table_facture = 'facture';

	/**
	 * @var string Table facturedet name.
	 */
	public $table_facturedet = 'facturedet';

	/**
	 * @var string Table migration name.
	 */
	public $table_migration = 'facture_situation_migration';

	/**
	 * @var string Table backup facture name.
	 */
	public $table_backupfac = 'facture_situation_migration_backup_facture';

	/**
	 * @var string Table backup facturedet name.
	 */
	public $table_backupdet = 'facture_situation_migration_backup_facturedet';

	/**
	 * @var DoliDB Database handler.
	 */
	public $db;

	/**
	 * @var string Warning message.
	 */
	public $warning = '';

	/**
	 * @var string Error message.
	 */
	public $error = '';

	/**
	 * @var string[] Error messages.
	 */
	public $errors = array();

	/**
	 * @var int Maximum number of cycles to process per step 3 execution.
	 */
	public $cycle_limit = 50;

	/**
	 * @var int Enable detailed logging (0=off, 1=on).
	 */
	private $log_detail = 0;


	/**
	 * Constructor
	 *
	 * @param  DoliDB  $_db  Database handler
	 */
	public function __construct($_db)
	{
		global $db;
		$this->db = is_object($_db) ? $_db : $db;
	}

	/**
	 * Return an HTML badge span: green (status4) if test is true, red (status8) otherwise.
	 *
	 * @param  bool   $test      Condition to evaluate
	 * @param  string $value_ok  Label displayed when test is true
	 * @param  string $value_nok Label displayed when test is false
	 * @return string            HTML span with badge class
	 */
	public static function badgeStatus($test, $value_ok, $value_nok)
	{
		if ($test) {
			return '<span class="badge badge-status4">'.$value_ok.'</span>';
		}
		return '<span class="badge badge-status8">'.$value_nok.'</span>';
	}

	/**
	 * Set done field to -1 to flag migration errors on a specific invoice.
	 *
	 * @param  int  $facture_id  Invoice rowid (used as PK in migration table)
	 * @return int               1 on success, -1 on SQL error
	 */
	public function setFactureError($facture_id)
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_migration;
		$sql .= " SET done = -1";
		$sql .= " WHERE rowid = ".((int) $facture_id);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorSetFactureStatus', $facture_id, $this->db->lasterror());
			dol_syslog('setFactureError SQL error for facture_id='.$facture_id.': '.$this->db->lasterror().' sql='.$sql, LOG_ERR, 0, '_situationmigration');
			return -1;
		}
		return 1;
	}

	/**
	 * Set done field to 1 to mark an invoice as successfully migrated.
	 *
	 * @param  int  $facture_id  Invoice rowid (used as PK in migration table)
	 * @return int               1 on success, -1 on SQL error
	 */
	public function setFactureDone($facture_id)
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');

		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_migration;
		$sql .= " SET done = 1";
		$sql .= " WHERE rowid = ".((int) $facture_id);
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorSetFactureStatus', $facture_id, $this->db->lasterror());
			dol_syslog('setFactureDone SQL error for facture_id='.$facture_id.': '.$this->db->lasterror().' sql='.$sql, LOG_ERR, 0, '_situationmigration');
			return -1;
		}
		return 1;
	}

	/**
	 * Count distinct cycles still pending migration (done=0).
	 *
	 * @return int  Number of cycles to do, or -1 on SQL error
	 */
	public function countMigrationToDo()
	{
		$sql = "SELECT COUNT(DISTINCT situation_cycle_ref) as nbcycle";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_migration;
		$sql .= " WHERE done = 0 AND entity IN (" . getEntity('facture') . ")";
		$res = $this->db->query($sql);

		if (!$res) {
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		return $obj ? intval($obj->nbcycle) : 0;
	}

	/**
	 * Count distinct cycles still in errors (done=-1).
	 *
	 * @return int  Number of cycles in errors, or -1 on SQL error
	 */
	public function countMigrationErrors()
	{
		$sql = "SELECT COUNT(DISTINCT situation_cycle_ref) as nbcycle";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_migration;
		$sql .= " WHERE done = -1 AND entity IN (" . getEntity('facture') . ")";
		$res = $this->db->query($sql);

		if (!$res) {
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		return $obj ? intval($obj->nbcycle) : 0;
	}

	/**
	 * Count all distinct cycles in the migration table.
	 *
	 * @return int  Total number of cycles, or -1 on SQL error
	 */
	public function countMigrationAll()
	{
		$sql = "SELECT COUNT(DISTINCT situation_cycle_ref) as nbcycle";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_migration;
		$sql .= " WHERE entity IN (" . getEntity('facture') . ")";
		$res = $this->db->query($sql);
		if (!$res) {
			return -1;
		}
		$obj = $this->db->fetch_object($res);
		return $obj ? intval($obj->nbcycle) : 0;
	}

	/**
	 * Step 1: Create a backup of facturedet for all situation invoices.
	 *
	 * Creates backup tables for llx_facturedet and llx_facture (situation invoices only),
	 * then copies data into them.
	 *
	 * On error, $this->error contains the translated error message.
	 * When already done, $this->warning contains the translated warning message.
	 *
	 * @return int  1 on success, 0 if already done, -1/-2 if facturedet backup fails, -4/-5 if facture backup fails
	 */
	public function migration_step_1()
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');
		$this->error = '';
		$this->warning = '';

		// IF ALREADY DONE
		if (getDolGlobalInt('MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP') > 0) {
			$this->warning = $langs->trans('FactureSituationMigrationWarningStep1AlreadyDone');
			return 0;
		}

		dol_syslog('START MIGRATION STEP 1', LOG_DEBUG, 0, '_situationmigration');

		// CREATE
		dol_syslog('Create a backup table (' . MAIN_DB_PREFIX . $this->table_backupdet . ')', LOG_DEBUG, 0, '_situationmigration');
		if ($this->db->type == 'pgsql') {
			$sql_create = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . $this->table_backupdet . " (LIKE " . MAIN_DB_PREFIX . $this->table_facturedet . " INCLUDING ALL)";
		} else {
			$sql_create = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . $this->table_backupdet . " LIKE " . MAIN_DB_PREFIX . $this->table_facturedet;
		}
		dol_syslog('sql=' . $sql_create, LOG_DEBUG, 0, '_situationmigration');
		$result_create = $this->db->query($sql_create);
		if (!$result_create) {
			$this->error = $langs->trans('FactureSituationMigrationErrorStep1CreateBackupDet', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_create, LOG_ERR, 0, '_situationmigration');
			return -1;
		}

		// BACKUP FACTUREDET
		dol_syslog('Store ' . MAIN_DB_PREFIX . $this->table_facturedet . ' into backup table (' . MAIN_DB_PREFIX . $this->table_backupdet . ')', LOG_DEBUG, 0, '_situationmigration');
		if ($this->db->type == 'pgsql') {
			$sql_copy = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_backupdet;
			$sql_copy .= " SELECT fd.* FROM " . MAIN_DB_PREFIX . $this->table_facturedet . " as fd";
			$sql_copy .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facture . " as f ON f.rowid = fd.fk_facture";
			$sql_copy .= " WHERE f.entity IN (" . getEntity('facture') . ")";
			$sql_copy .= " AND f.type = " . ((int) Facture::TYPE_SITUATION);
			$sql_copy .= " ON CONFLICT DO NOTHING";
		} else {
			$sql_copy = "INSERT IGNORE INTO " . MAIN_DB_PREFIX . $this->table_backupdet;
			$sql_copy .= " SELECT fd.* FROM " . MAIN_DB_PREFIX . $this->table_facturedet . " as fd";
			$sql_copy .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facture . " as f ON f.rowid = fd.fk_facture";
			$sql_copy .= " WHERE f.entity IN (" . getEntity('facture') . ")";
			$sql_copy .= " AND f.type = " . ((int) Facture::TYPE_SITUATION);
		}
		dol_syslog('sql=' . $sql_copy, LOG_DEBUG, 0, '_situationmigration');
		$result_copy = $this->db->query($sql_copy);
		if (!$result_copy) {
			$this->error = $langs->trans('FactureSituationMigrationErrorStep1CopyDet', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_copy, LOG_ERR, 0, '_situationmigration');
			return -2;
		}

		// CREATE BACKUP TABLE FACTURE
		dol_syslog('Create a backup table (' . MAIN_DB_PREFIX . $this->table_backupfac . ')', LOG_DEBUG, 0, '_situationmigration');
		if ($this->db->type == 'pgsql') {
			$sql_create_fac = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . $this->table_backupfac . " (LIKE " . MAIN_DB_PREFIX . $this->table_facture . " INCLUDING ALL)";
		} else {
			$sql_create_fac = "CREATE TABLE IF NOT EXISTS " . MAIN_DB_PREFIX . $this->table_backupfac . " LIKE " . MAIN_DB_PREFIX . $this->table_facture;
		}
		dol_syslog('sql=' . $sql_create_fac, LOG_DEBUG, 0, '_situationmigration');
		$result_create_fac = $this->db->query($sql_create_fac);
		if (!$result_create_fac) {
			$this->error = $langs->trans('FactureSituationMigrationErrorStep1CreateBackupFac', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_create_fac, LOG_ERR, 0, '_situationmigration');
			return -4;
		}

		// BACKUP FACTURE
		dol_syslog('Store ' . MAIN_DB_PREFIX . $this->table_facture . ' into backup table (' . MAIN_DB_PREFIX . $this->table_backupfac . ')', LOG_DEBUG, 0, '_situationmigration');
		if ($this->db->type == 'pgsql') {
			$sql_copy_fac = "INSERT INTO " . MAIN_DB_PREFIX . $this->table_backupfac;
			$sql_copy_fac .= " SELECT f.* FROM " . MAIN_DB_PREFIX . $this->table_facture . " as f";
			$sql_copy_fac .= " WHERE f.entity IN (" . getEntity('facture') . ")";
			$sql_copy_fac .= " AND f.type = " . ((int) Facture::TYPE_SITUATION);
			$sql_copy_fac .= " ON CONFLICT DO NOTHING";
		} else {
			$sql_copy_fac = "INSERT IGNORE INTO " . MAIN_DB_PREFIX . $this->table_backupfac;
			$sql_copy_fac .= " SELECT f.* FROM " . MAIN_DB_PREFIX . $this->table_facture . " as f";
			$sql_copy_fac .= " WHERE f.entity IN (" . getEntity('facture') . ")";
			$sql_copy_fac .= " AND f.type = " . ((int) Facture::TYPE_SITUATION);
		}
		dol_syslog('sql=' . $sql_copy_fac, LOG_DEBUG, 0, '_situationmigration');
		$result_copy_fac = $this->db->query($sql_copy_fac);
		if (!$result_copy_fac) {
			$this->error = $langs->trans('FactureSituationMigrationErrorStep1CopyFac', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_copy_fac, LOG_ERR, 0, '_situationmigration');
			return -5;
		}

		dol_syslog('END MIGRATION STEP 1', LOG_DEBUG, 0, '_situationmigration');
		return 1;
	}

	/**
	 * Get the list of situation cycle refs to migrate when filtered by current year.
	 *
	 * Only active when FACTURESITUATIONMIGRATION_CURRENT_YEAR is set. Returns cycles
	 * whose last invoice was issued in the current year.
	 *
	 * On SQL error, $this->error is populated and the method returns false.
	 *
	 * @return string|null|false  Comma-separated cycle refs (possibly empty string if no match),
	 *                            null if option is disabled, false on SQL error
	 */
	public function get_sequences_to_migrate()
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');

		if (getDolGlobalString('FACTURESITUATIONMIGRATION_CURRENT_YEAR', '') == '') {
			return null;
		}

		$ret = array();
		$current_year = (int) date('Y');
		$entityList = getEntity('facture');

		$sql = "SELECT DISTINCT situation_cycle_ref FROM " . MAIN_DB_PREFIX . $this->table_facture;
		$sql .= " WHERE type = " . ((int) Facture::TYPE_SITUATION);
		$sql .= " AND entity IN (" . $entityList . ")";
		$sql .= " AND COALESCE(situation_cycle_ref, 0) > 0";

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorGetSequencesSelect', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql, LOG_ERR, 0, '_situationmigration');
			return false;
		}

		while ($obj = $this->db->fetch_object($res)) {
			$sql2 = "SELECT situation_cycle_ref, EXTRACT(YEAR FROM datef) as year";
			$sql2 .= " FROM " . MAIN_DB_PREFIX . $this->table_facture;
			$sql2 .= " WHERE situation_cycle_ref = " . ((int) $obj->situation_cycle_ref);
			$sql2 .= " AND type = " . ((int) Facture::TYPE_SITUATION);
			$sql2 .= " AND entity IN (" . $entityList . ")";
			$sql2 .= " ORDER BY situation_counter DESC LIMIT 1";
			$res2 = $this->db->query($sql2);
			if (!$res2) {
				$this->error = $langs->trans('FactureSituationMigrationErrorGetSequencesLastInvoice', $this->db->lasterror());
				dol_syslog($this->error . ' sql=' . $sql2, LOG_ERR, 0, '_situationmigration');
				$this->db->free($res);
				return false;
			}
			$obj2 = $this->db->fetch_object($res2);
			// if ($obj2->situation_final == 0 && $obj2->year > (date('Y') - 1)) {
			//     dol_syslog('  Add that cycle number '.$obj2->situation_cycle_ref.' to migration due to cycle not ended and last invoice edited in '.$obj2->year,LOG_DEBUG,0,'_situationmigration');
			//     $ret[] = $obj2->situation_cycle_ref;
			// }
			// Les cycles en cours ou terminés cette année
			if ($obj2 && (int) $obj2->year == $current_year) {
				dol_syslog('  Add that cycle number ' . $obj2->situation_cycle_ref . ' to migration due to cycle not ended and last invoice edited in ' . $obj2->year, LOG_DEBUG, 0, '_situationmigration');
				$ret[] = (int) $obj2->situation_cycle_ref;
			}
			$this->db->free($res2);
		}
		$this->db->free($res);

		return implode(',', $ret);
	}

	/**
	 * Step 2: Index all situation invoice IDs into the migration tracking table.
	 *
	 * Populates llx_facture_situation_migration with one row per situation invoice,
	 * allowing step 3 to track which cycles have been processed.
	 *
	 * On error, $this->error contains the translated error message.
	 * On no-op (no invoices / all already indexed), $this->warning contains the translated message.
	 *
	 * @return int  1=all inserted, 2=inserted in several runs, 3=all already existed,
	 *              0=no invoices found, -1=SQL error, -2=insertion error
	 */
	public function migration_step_2()
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');
		$this->error = '';
		$this->warning = '';

		dol_syslog('START MIGRATION STEP 2', LOG_DEBUG, 0, '_situationmigration');

		// On récupère tous les identifiants des factures de situations
		dol_syslog('Select all situation invoices with cycle ref', LOG_DEBUG, 0, '_situationmigration');
		$sql = "SELECT rowid, entity, situation_cycle_ref FROM ".MAIN_DB_PREFIX.$this->table_facture;
		$sql.= " WHERE type = ".((int) Facture::TYPE_SITUATION)." AND entity IN (".getEntity('facture').")";
		//uniquement une certaine selection de factures
		$liste_seq_operate = $this->get_sequences_to_migrate();
		if ($liste_seq_operate === false) {
			// SQL error already set $this->error
			return -1;
		}
		if (null !== $liste_seq_operate) {
			if ($liste_seq_operate === '') {
				$this->warning = $langs->trans('FactureSituationMigrationWarningStep2NoCyclesCurrentYear');
				dol_syslog('No cycles found for current year', LOG_DEBUG, 0, '_situationmigration');
				return 0;
			}
			$sql.= " AND situation_cycle_ref IN(".$liste_seq_operate.")";
		}
		$res = $this->db->query($sql);
		dol_syslog('sql='.$sql, LOG_DEBUG, 0, '_situationmigration');

		if (!$res) {
			// ERREUR SQL SELECT
			$this->error = $langs->trans('FactureSituationMigrationErrorStep2Select', $this->db->lasterror());
			dol_syslog($this->error.' sql='.$sql, LOG_ERR, 0, '_situationmigration');
			return -1;
		}

		$num_rows = $this->db->num_rows($res);
		if ($num_rows == 0) {
			// AUCUNE FACTURE DE SITUATION
			$this->warning = $langs->trans('FactureSituationMigrationWarningStep2NoInvoices');
			dol_syslog('No cycles found, no migration needed', LOG_DEBUG, 0, '_situationmigration');
			return 0;
		}

		$insert_exist = 0;

		dol_syslog('Result : '.$num_rows.' invoices founded', LOG_DEBUG, 0, '_situationmigration');
		dol_syslog('We store results in migration table ('.MAIN_DB_PREFIX.$this->table_migration.')', LOG_DEBUG, 0, '_situationmigration');

		while ($obj = $this->db->fetch_object($res)) {
			$sql_insert = "INSERT INTO ".MAIN_DB_PREFIX.$this->table_migration;
			$sql_insert.= " (rowid,situation_cycle_ref,entity,done) VALUES (";
			$sql_insert.= ((int) $obj->rowid).",";
			$sql_insert.= ((int) $obj->situation_cycle_ref).",";
			$sql_insert.= ((int) $obj->entity).",";
			$sql_insert.= "0";
			$sql_insert.= ")";
			dol_syslog('sql='.$sql_insert, LOG_DEBUG, 0, '_situationmigration');

			$res_insert = $this->db->query($sql_insert);
			if (!$res_insert) {
				if ($this->db->lasterrno() == 'DB_ERROR_RECORD_ALREADY_EXISTS') {
					dol_syslog('ID(' . $obj->rowid . ') already exist, we continue', LOG_DEBUG, 0, '_situationmigration');
					$insert_exist++;
				} else {
					// ERREUR INSERTION
					$this->error = $langs->trans('FactureSituationMigrationErrorStep2Insert', $this->db->lasterror());
					dol_syslog($this->error, LOG_ERR, 0, '_situationmigration');
					return -2;
				}
			}
		}

		// At this point, any SQL error would have returned -2 already, so
		// $insert_success + $insert_exist == $num_rows.
		if ($insert_exist == $num_rows) {
			// AUCUNE INSERTION NECESSAIRE
			$this->warning = $langs->trans('FactureSituationMigrationWarningStep2AlreadyIndexed');
			dol_syslog('Step2 result = 3 (Reload step 2 with all results already stored, we can go to step 3)', LOG_DEBUG, 0, '_situationmigration');
			dol_syslog('END MIGRATION STEP 2', LOG_DEBUG, 0, '_situationmigration');
			return 3;
		} elseif ($insert_exist > 0) {
			// SUCCES EN CAS D'ERREURS PRECEDENTES
			dol_syslog('Step2 result = 2 (All insert ok, in several times)', LOG_DEBUG, 0, '_situationmigration');
			dol_syslog('END MIGRATION STEP 2', LOG_DEBUG, 0, '_situationmigration');
			return 2;
		}

		// SUCCES
		dol_syslog('Step2 result = 1 (All insert at the same time)', LOG_DEBUG, 0, '_situationmigration');
		dol_syslog('END MIGRATION STEP 2', LOG_DEBUG, 0, '_situationmigration');
		return 1;
	}

	/**
	 * Step 3: Convert situation invoice lines from cumulative to delta values.
	 *
	 * For each pending cycle, loads all TYPE_SITUATION invoices ordered by situation_counter
	 * descending, then computes deltas for: situation_percent, total_ht, total_tva, total_ttc,
	 * total_localtax1, total_localtax2, multicurrency_total_ht, multicurrency_total_tva,
	 * multicurrency_total_ttc. After each cycle commit, recalculates invoice totals via update_price().
	 *
	 * Credit notes (TYPE_CREDIT_NOTE) are excluded as they already store delta/negative values in mode 1.
	 *
	 * On error, $this->error is populated with a translated message.
	 * On partial success (some cycles failed), $this->warning is populated.
	 *
	 * @param  string[]  $listOfErrors  Array filled with invoice refs that encountered errors (by reference)
	 * @return int                      Number of cycles migrated on full success,
	 *                                  1 if all already done, -1 if partial errors, -2 if all errors
	 */
	public function migration_step_3(&$listOfErrors)
	{
		global $conf, $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');
		$this->error = '';
		$this->warning = '';

		dol_syslog('START MIGRATION STEP 3', LOG_DEBUG, 0, '_situationmigration');

		// Force mode 2 in memory for this request: update_price must not subtract
		// previous invoices since lines are being converted to deltas.
		// The DB constant is only persisted in migration.php when all cycles are done.
		$conf->global->INVOICE_USE_SITUATION = '2';
		dol_syslog('Set INVOICE_USE_SITUATION=2 in memory for step 3', LOG_DEBUG, 0, '_situationmigration');

		// ON RECUPERE ET REGROUPE LES REFERENCES DE CYCLE
		$sql = "SELECT";
		$sql .= " DISTINCT situation_cycle_ref";
		$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_migration . " AS m";
		$sql .= " WHERE m.done = 0";
		$sql .= " AND m.entity IN (" . getEntity('facture') . ")";
		// Exclude cycles that already contain a failed invoice (done=-1) to avoid
		// re-processing already-migrated lines (which would compute delta-of-delta
		// and corrupt the data). Those cycles must be unblocked manually after
		// fixing the underlying issue (reset done=-1 to 0).
		$sql .= " AND NOT EXISTS (";
		$sql .= "SELECT 1 FROM " . MAIN_DB_PREFIX . $this->table_migration . " AS m2";
		$sql .= " WHERE m2.situation_cycle_ref = m.situation_cycle_ref";
		$sql .= " AND m2.done = -1 AND m2.entity IN (" . getEntity('facture') . ")";
		$sql .= ")";
		$sql .= " LIMIT " . ((int) $this->cycle_limit);
		dol_syslog('We Select and group ref cycle not done', LOG_DEBUG, 0, '_situationmigration');
		dol_syslog('sql=' . $sql, LOG_DEBUG, 0, '_situationmigration');

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorStep3Select', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql, LOG_ERR, 0, '_situationmigration');
			return -2;
		}

		// RETURN 1 IF ALL ALREADY DONE
		if ($this->db->num_rows($res) == 0) {
			$this->warning = $langs->trans('FactureSituationMigrationWarningStep3NothingToDo');
			dol_syslog('All cycles are already done', LOG_DEBUG, 0, '_situationmigration');
			return 1;
		}

		$nb_update = 0;
		$nb_update_success = 0;
		$nb_update_error = 0;

		// POUR CHAQUE CYCLE
		dol_syslog('For each cycle not done', LOG_DEBUG, 0, '_situationmigration');
		while ($obj = $this->db->fetch_object($res)) {
			$nb_update++;

			$sql_bis = "SELECT";
			$sql_bis .= " f.rowid as facture_id, f.ref as facture_ref, f.situation_cycle_ref as facture_cycle_ref, f.situation_counter as facture_situation_counter, f.situation_final as facture_situation_final, EXTRACT(YEAR FROM f.datef) as facture_year";
			$sql_bis .= " , fd.rowid as ligne_id, fd.situation_percent as ligne_percent, fd.fk_prev_id as ligne_prev_id";
			$sql_bis .= " , fd.subprice as ligne_subprice, fd.total_ht as ligne_total_ht, fd.total_tva as ligne_total_tva, fd.total_ttc as ligne_total_ttc, fd.total_localtax1 as ligne_total_localtax1, fd.total_localtax2 as ligne_total_localtax2, fd.special_code as special_code";
			$sql_bis .= " , fd.multicurrency_subprice as ligne_multicurrency_subprice, fd.multicurrency_total_ht as ligne_multicurrency_total_ht, fd.multicurrency_total_tva as ligne_multicurrency_total_tva, fd.multicurrency_total_ttc as ligne_multicurrency_total_ttc";
			$sql_bis .= " FROM " . MAIN_DB_PREFIX . $this->table_facture . " AS f";
			$sql_bis .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facturedet . " AS fd ON f.rowid = fd.fk_facture";
			$sql_bis .= " WHERE f.situation_cycle_ref = " . ((int) $obj->situation_cycle_ref) . " AND f.type = " . ((int) Facture::TYPE_SITUATION) . " AND f.entity IN (" . getEntity('facture') . ")";
			$sql_bis .= " ORDER BY f.situation_cycle_ref DESC, f.situation_counter DESC";
			dol_syslog('START CYCLE_REF:: ' . $obj->situation_cycle_ref, LOG_DEBUG, 0, '_situationmigration');
			dol_syslog('sql=' . $sql_bis, LOG_DEBUG, 0, '_situationmigration');

			$res_bis = $this->db->query($sql_bis);
			if ($res_bis) {
				$this->db->begin();

				/* -------------------------------------------------------- */
				/* CONSTRUCTION TABLEAU ----------------------------------- */
				/* -------------------------------------------------------- */

				$cycle_array = array();

				// POUR CHAQUE LIGNE DU CYCLE
				while ($obj_bis = $this->db->fetch_object($res_bis)) {
					if (!isset($cycle_array[$obj_bis->facture_situation_counter])) {
						$cycle_array[$obj_bis->facture_situation_counter] = array(
							'facture_year' => $obj_bis->facture_year,
							'situation_final' => $obj_bis->facture_situation_final,
							'facture_id' => $obj_bis->facture_id,
							'facture_ref' => $obj_bis->facture_ref,
							'lines' => array(),
						);
					}

					$cycle_array[$obj_bis->facture_situation_counter]['lines'][$obj_bis->ligne_id] = array(
						'line_percent' => $obj_bis->ligne_percent,
						'fk_prev_id' => $obj_bis->ligne_prev_id,
						'subprice' => $obj_bis->ligne_subprice,
						'ligne_total_ht' => $obj_bis->ligne_total_ht,
						'ligne_total_tva' => $obj_bis->ligne_total_tva,
						'ligne_total_ttc' => $obj_bis->ligne_total_ttc,
						'ligne_total_localtax1' => $obj_bis->ligne_total_localtax1,
						'ligne_total_localtax2' => $obj_bis->ligne_total_localtax2,
						'multicurrency_subprice' => $obj_bis->ligne_multicurrency_subprice,
						'multicurrency_ligne_total_ht' => $obj_bis->ligne_multicurrency_total_ht,
						'multicurrency_ligne_total_tva' => $obj_bis->ligne_multicurrency_total_tva,
						'multicurrency_ligne_total_ttc' => $obj_bis->ligne_multicurrency_total_ttc,
						'special_code' => $obj_bis->special_code,
					);
				}

				/* -------------------------------------------------------- */
				/* PARCOURS TABLEAU ----------------------------------- */
				/* -------------------------------------------------------- */
				//var_dump('-- CYCLE N°'.$obj->situation_cycle_ref);
				//var_dump($cycle_array);

				$facture_update = 0;
				$facture_update_success = 0;
				$facture_update_error = 0;

				// TRI DECROISSANT
				krsort($cycle_array);

				// print json_encode($cycle_array);exit;
				// POUR CHAQUE SITUATION DU CYCLE
				foreach ($cycle_array as $cycle_counter => $cycle_infos) {
					$facture_update++;

					// print json_encode($cycle_infos);exit;
					//var_dump('---- SITU '.$cycle_counter.' :: '.count($cycle_infos['lines']).' lignes :: '.$cycle_infos['facture_id'].' :: '.$cycle_infos['facture_ref']);

					$facture_ref = $cycle_infos['facture_ref'];
					$factureline_update = 0;
					$factureline_update_success = 0;
					$factureline_update_error = 0;

					dol_syslog('SituationCounter::' . $cycle_counter . ' (' . $cycle_infos['facture_ref'] . ')', LOG_DEBUG, 0, '_situationmigration');

					// Si on est sur une situation > 1 dans le cycle, on recalcule, la situation 1 est toujours correcte
					if (intval($cycle_counter) > 1) {
						$cycle_counter_before = intval($cycle_counter) - 1;

						// Vérifier que la situation précédente existe dans le cycle
						if (!isset($cycle_array[$cycle_counter_before])) {
							dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos['facture_ref'] . ' (id=' . $cycle_infos['facture_id'] . ', situation_counter=' . $cycle_counter . ') ERROR: previous situation_counter=' . $cycle_counter_before . ' is missing from this cycle (gap in sequence). Cycle rolled back and marked in error.', LOG_ERR, 0, '_situationmigration');
							// Rollback to discard any UPDATE made on previous situations of this cycle
							// (otherwise a partial migration would be persisted and the cycle would be
							// re-processed with delta-of-delta corruption on the next batch).
							$this->db->rollback();
							$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3PrevCounterMissing', $obj->situation_cycle_ref, $cycle_infos['facture_ref'], $cycle_counter, $cycle_counter_before);
							// setFactureError() runs outside the rolled-back transaction so the
							// failure flag is persisted (used by the NOT EXISTS guard in the next batch).
							if ($this->setFactureError($cycle_infos['facture_id']) < 0) {
								$listOfErrors[] = $this->error;
							}
							$nb_update_error++;
							continue 2;
						}

						// Pour chaque ligne de la facture
						foreach ($cycle_infos['lines'] as $line_id => $line_infos) {
							// Check if special code or subtotal
							if ($line_infos['special_code'] == '104777') {
								continue;
							}

							$fk_prev_id = $line_infos['fk_prev_id'];
							// Check if is a previous id. A line in situation N>1 with no
							// fk_prev_id is a brand new line added during this situation
							// (allowed by native Dolibarr). In mode 1 its stored value already
							// equals the expected mode 2 delta (since there is no previous
							// situation to subtract), so we skip the UPDATE on purpose.
							if (empty($fk_prev_id)) {
								dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos['facture_ref'] . ' (situation_counter=' . $cycle_counter . ') Line::' . $line_id . ' has no fk_prev_id (new line added in this situation), no migration needed', LOG_DEBUG, 0, '_situationmigration');
								continue;
							}

							// Vérifier que la ligne précédente existe
							if (!isset($cycle_array[$cycle_counter_before]['lines'][$fk_prev_id])) {
								dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos['facture_ref'] . ' (id=' . $cycle_infos['facture_id'] . ', situation_counter=' . $cycle_counter . ') ERROR on line=' . $line_id . ': previous line id=' . $fk_prev_id . ' not found in situation_counter=' . $cycle_counter_before . '. Cycle rolled back and marked in error.', LOG_ERR, 0, '_situationmigration');
								$this->db->rollback();
								$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3PrevLineMissing', $obj->situation_cycle_ref, $cycle_infos['facture_ref'], $cycle_counter, $line_id, $fk_prev_id, $cycle_counter_before);
								if ($this->setFactureError($cycle_infos['facture_id']) < 0) {
									$listOfErrors[] = $this->error;
								}
								$nb_update_error++;
								continue 3;
							}

							$prev_line_infos = $cycle_array[$cycle_counter_before]['lines'][$fk_prev_id];
							$prev_facture_ref = $cycle_array[$cycle_counter_before]['facture_ref'];

							$factureline_update++;
							if (!isset($line_infos['line_percent'])) {
								dol_syslog('Invoice::' . $facture_ref . ' - Line::' . $line_id . ' - PreviousInvoice::' . $prev_facture_ref . ' - PreviousLine::' . $fk_prev_id . " ERROR: line_percent is not set (a)", LOG_ERR, 0, '_situationmigration');
								$factureline_update_error++;
							} else {
								$percent = $line_infos['line_percent'];
								$this->_handle_line_percent_value($percent, $line_id, $fk_prev_id, $line_infos, $factureline_update_error);
							}
							if (!isset($prev_line_infos['line_percent'])) {
								dol_syslog('Invoice::' . $cycle_infos['facture_ref'] . ' - Line::' . $line_id . ' - PreviousInvoice::' . $prev_facture_ref . ' - PreviousLine::' . $fk_prev_id . " ERROR: prev cycle counter line_percent is not set (b)", LOG_ERR, 0, '_situationmigration');
								$factureline_update_error++;
							} else {
								$percent = $prev_line_infos['line_percent'];
								$this->_handle_line_percent_value($percent, $line_id, $fk_prev_id, $prev_line_infos, $factureline_update_error);
							}

							dol_syslog('Invoice::' . $cycle_infos['facture_ref'] . ' - Line::' . $line_id . ' - PreviousInvoice::' . $prev_facture_ref . ' - factureline_update_error=' . $factureline_update_error, LOG_DEBUG, 0, '_situationmigration');
							if ($factureline_update_error > 0) {
								dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos['facture_ref'] . ' (id=' . $cycle_infos['facture_id'] . ', situation_counter=' . $cycle_counter . ') ERROR on line=' . $line_id . ' (prev_line=' . $fk_prev_id . ', prev_invoice=' . $prev_facture_ref . '): line_percent invalid or out of range. Cycle rolled back and marked in error.', LOG_ERR, 0, '_situationmigration');
								// Rollback to discard any UPDATE made on previous situations of this cycle
								// (otherwise a partial migration would be persisted and the cycle would be
								// re-processed with delta-of-delta corruption on the next batch).
								$this->db->rollback();
								$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3LinePercent', $obj->situation_cycle_ref, $cycle_infos['facture_ref'], $cycle_counter, $line_id);
								// setFactureError() runs outside the rolled-back transaction so the
								// failure flag is persisted (used by the NOT EXISTS guard in the next batch).
								if ($this->setFactureError($cycle_infos['facture_id']) < 0) {
									$listOfErrors[] = $this->error;
								}
								$nb_update_error++;
								continue 3;
							}

							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['line_percent'] = number_format(floatval($line_infos['line_percent']) - floatval($prev_line_infos['line_percent']), 2, '.', '');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ht'] = price2num(floatval($line_infos['ligne_total_ht']) - floatval($prev_line_infos['ligne_total_ht']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_tva'] = price2num(floatval($line_infos['ligne_total_tva']) - floatval($prev_line_infos['ligne_total_tva']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ttc'] = price2num(floatval($line_infos['ligne_total_ttc']) - floatval($prev_line_infos['ligne_total_ttc']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_localtax1'] = price2num(floatval($line_infos['ligne_total_localtax1']) - floatval($prev_line_infos['ligne_total_localtax1']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_localtax2'] = price2num(floatval($line_infos['ligne_total_localtax2']) - floatval($prev_line_infos['ligne_total_localtax2']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ht'] = price2num(floatval($line_infos['multicurrency_ligne_total_ht']) - floatval($prev_line_infos['multicurrency_ligne_total_ht']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_tva'] = price2num(floatval($line_infos['multicurrency_ligne_total_tva']) - floatval($prev_line_infos['multicurrency_ligne_total_tva']), 'MT');
							$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ttc'] = price2num(floatval($line_infos['multicurrency_ligne_total_ttc']) - floatval($prev_line_infos['multicurrency_ligne_total_ttc']), 'MT');

							// LOGS
							if ($this->log_detail > 0) {
								$log_percent = 'New Percent = Actual(' . $line_infos['line_percent'] . ') - PreviousLine(' . $prev_line_infos['line_percent'] . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['line_percent'] . '%';
								$log_ht = 'New TotalHT = Actual(' . floatval($line_infos['ligne_total_ht']) . ') - PreviousLine(' . floatval($prev_line_infos['ligne_total_ht']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ht'] . '€';
								$log_tva = 'New TotalTVA = Actual(' . floatval($line_infos['ligne_total_tva']) . ') - PreviousLine(' . floatval($prev_line_infos['ligne_total_tva']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_tva'] . '€';
								$log_ttc = 'New TotalTTC = Actual(' . floatval($line_infos['ligne_total_ttc']) . ') - PreviousLine(' . floatval($prev_line_infos['ligne_total_ttc']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ttc'] . '€';
								$log_multiht = 'New MulticurrencyTotalHT = Actual(' . floatval($line_infos['multicurrency_ligne_total_ht']) . ') - PreviousLine(' . floatval($prev_line_infos['multicurrency_ligne_total_ht']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ht'] . '€';
								$log_multitva = 'New MulticurrencyTotalTVA = Actual(' . floatval($line_infos['multicurrency_ligne_total_tva']) . ') - PreviousLine(' . floatval($prev_line_infos['multicurrency_ligne_total_tva']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_tva'] . '€';
								$log_multittc = 'New MulticurrencyTotalTTC = Actual(' . floatval($line_infos['multicurrency_ligne_total_ttc']) . ') - PreviousLine(' . floatval($prev_line_infos['multicurrency_ligne_total_ttc']) . ') = ' . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ttc'] . '€';
								dol_syslog('Invoice::' . $cycle_infos['facture_ref'] . ' - Line::' . $line_id . ' - PreviousLine::' . $fk_prev_id, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_percent, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_ht, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_tva, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_ttc, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_multiht, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_multitva, LOG_DEBUG, 0, '_situationmigration');
								dol_syslog($log_multittc, LOG_DEBUG, 0, '_situationmigration');
							}
							//var_dump('------------- NEW HT:'.$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ht'].'€ || '.$cycle_array[$cycle_counter]['lines'][$fk_prev_id]['line_percent'].'%');

							$sql_update = "UPDATE " . MAIN_DB_PREFIX . $this->table_facturedet . " SET";
							$sql_update .= " situation_percent = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['line_percent'] . "',";
							$sql_update .= " total_ht = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ht'] . "',";
							$sql_update .= " total_tva = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_tva'] . "',";
							$sql_update .= " total_ttc = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_ttc'] . "',";
							$sql_update .= " total_localtax1 = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_localtax1'] . "',";
							$sql_update .= " total_localtax2 = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['ligne_total_localtax2'] . "',";
							$sql_update .= " multicurrency_total_ht = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ht'] . "',";
							$sql_update .= " multicurrency_total_tva = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_tva'] . "',";
							$sql_update .= " multicurrency_total_ttc = '" . $cycle_array[$cycle_counter]['lines'][$fk_prev_id]['multicurrency_ligne_total_ttc'] . "'";
							$sql_update .= " WHERE rowid = " . ((int) $line_id);
							dol_syslog('sql=' . $sql_update, LOG_DEBUG, 0, '_situationmigration');

							$res_update = $this->db->query($sql_update);
							if ($res_update) {
								$factureline_update_success++;
							} else {
								dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos['facture_ref'] . ' (id=' . $cycle_infos['facture_id'] . ', situation_counter=' . $cycle_counter . ') SQL UPDATE failed for line=' . $line_id . ': ' . $this->db->lasterror() . ' sql=' . $sql_update . '. Cycle rolled back and marked in error.', LOG_ERR, 0, '_situationmigration');
								$this->db->rollback();
								$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3UpdateLine', $obj->situation_cycle_ref, $cycle_infos['facture_ref'], $cycle_counter, $line_id, $this->db->lasterror());
								if ($this->setFactureError($cycle_infos['facture_id']) < 0) {
									$listOfErrors[] = $this->error;
								}
								$nb_update_error++;
								continue 3;
							}
						}

						if ($factureline_update == $factureline_update_success) {
							if ($this->setFactureDone($cycle_infos['facture_id']) < 0) {
								$listOfErrors[] = $this->error;
								$facture_update_error++;
							} else {
								$facture_update_success++;
							}
						} else {
							$facture_update_error++;
						}
					} else {
						dol_syslog('We do nothing, first situation', LOG_DEBUG, 0, '_situationmigration');
						if ($this->setFactureDone($cycle_infos['facture_id']) < 0) {
							$listOfErrors[] = $this->error;
							$facture_update_error++;
						} else {
							$facture_update_success++;
						}
						// foreach($cycle_infos['lines'] as $lid => $l) { var_dump('-------- LIGNE ID:'.$lid.' ||  HT:'.$l['ligne_total_ht'].'€ || '.$l['line_percent'].'%'); }
					}
				}
				//var_dump('NB fact cycle: '.$facture_update.' :: Success: '.$facture_update_success.' | Err: '.$facture_update_error);

				if ($facture_update == $facture_update_success) {
					// All still inside the transaction (begin was called before the cycle loop).
					// Dolibarr handles nested begin/commit via a counter, so internal
					// begin/commit calls in update_price() are no-ops at this nesting level.
					$cycle_error = false;

					// Recalcul des totaux facture après migration des lignes en delta
					// INVOICE_USE_SITUATION=2 is already set at the start of step 3
					foreach ($cycle_array as $cycle_infos_upd) {
						$facture_tmp = new Facture($this->db);
						$res_fetch = $facture_tmp->fetch($cycle_infos_upd['facture_id']);
						if ($res_fetch <= 0) {
							dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos_upd['facture_ref'] . ' (id=' . $cycle_infos_upd['facture_id'] . ') fetch failed (result=' . $res_fetch . ')', LOG_ERR, 0, '_situationmigration');
							$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3FetchInvoice', $obj->situation_cycle_ref, $cycle_infos_upd['facture_ref']);
							$cycle_error = true;
							break;
						}
						$res_price = $facture_tmp->update_price(1);
						if ($res_price < 0) {
							dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' Invoice::' . $cycle_infos_upd['facture_ref'] . ' update_price failed (result=' . $res_price . '): ' . $facture_tmp->error, LOG_ERR, 0, '_situationmigration');
							$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3UpdatePrice', $obj->situation_cycle_ref, $cycle_infos_upd['facture_ref'], $facture_tmp->error);
							$cycle_error = true;
							break;
						}
						dol_syslog('update_price done for invoice ' . $cycle_infos_upd['facture_ref'], LOG_DEBUG, 0, '_situationmigration');
					}

					// Post-migration verification + store result (still in transaction)
					$verify = $this->verifyCycle((int) $obj->situation_cycle_ref);
					if ($verify === false) {
						// SQL error during verification
						dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' post-migration verification SQL error: ' . $this->error, LOG_ERR, 0, '_situationmigration');
						$listOfErrors[] = $this->error;
						$cycle_error = true;
					} else {
						$check_val = $verify['ok'] ? 1 : -1;
						$res_check = $this->setCycleChecked((int) $obj->situation_cycle_ref, $check_val);
						if ($res_check < 0) {
							dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' setCycleChecked SQL error: ' . $this->db->lasterror(), LOG_ERR, 0, '_situationmigration');
							$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3SetChecked', $obj->situation_cycle_ref, $this->db->lasterror());
							$cycle_error = true;
						}
						dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' post-migration verification: ' . ($verify['ok'] ? 'OK' : 'FAILED'), ($verify['ok'] ? LOG_DEBUG : LOG_WARNING), 0, '_situationmigration');
					}

					if ($cycle_error) {
						$this->db->rollback();
						$nb_update_error++;
					} else {
						$this->db->commit();
						$nb_update_success++;
					}
				} else {
					// Defensive fallback: with the immediate-rollback handling above this branch
					// should normally never be reached. Kept as a safety net.
					dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' UNEXPECTED post-loop inconsistency (facture_update=' . $facture_update . ', success=' . $facture_update_success . '). Cycle rolled back.', LOG_ERR, 0, '_situationmigration');
					$this->db->rollback();
					$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3UnexpectedInconsistency', $obj->situation_cycle_ref);
					$nb_update_error++;
				}
			} else {
				dol_syslog('Cycle::' . $obj->situation_cycle_ref . ' SQL error fetching cycle details: ' . $this->db->lasterror() . ' sql=' . $sql_bis, LOG_ERR, 0, '_situationmigration');
				$listOfErrors[] = $langs->trans('FactureSituationMigrationErrorStep3FetchCycle', $obj->situation_cycle_ref, $this->db->lasterror());
				$nb_update_error++;
			}

			dol_syslog('END CYCLEREF', LOG_DEBUG, 0, '_situationmigration');
		}

		if ($nb_update == $nb_update_success) {
			// SI TOUT EST FAIT
			dol_syslog('Step3 result = All success', LOG_DEBUG, 0, '_situationmigration');
			dol_syslog('END MIGRATION STEP 3', LOG_DEBUG, 0, '_situationmigration');
			return $nb_update_success;
		} elseif ($nb_update == $nb_update_error) {
			// TOUT EN ERREUR
			$this->error = $langs->trans('FactureSituationMigrationErrorStep3AllFailed');
			dol_syslog('Step3 result = All update errors', LOG_ERR, 0, '_situationmigration');
			dol_syslog('END MIGRATION STEP 3', LOG_DEBUG, 0, '_situationmigration');
			return -2;
		}

		// SI RESULTATS POSITIFS ET NEGATIFS (partial)
		$this->warning = $langs->trans('FactureSituationMigrationWarningStep3PartialErrors', $nb_update_error, $nb_update);
		dol_syslog('Step3 result = Success and errors', LOG_ERR, 0, '_situationmigration');
		dol_syslog('END MIGRATION STEP 3', LOG_DEBUG, 0, '_situationmigration');
		return -1;
	}

	/**
	 * Rollback migration: restore backup data and reset constants to mode 1.
	 *
	 * Restores facturedet lines and facture totals from backup tables via UPDATE,
	 * resets migration step/done/mode constants, then cleans up migration and backup tables.
	 *
	 * On error, $this->error contains the translated error message.
	 *
	 * @return int  1 on success, -1=facturedet restore error, -2=facture restore error,
	 *              -3=const error, -4=migration cleanup error, -5=backupdet cleanup error,
	 *              -6=backupfac cleanup error
	 */
	public function rollbackMigration()
	{
		global $conf, $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');
		$this->error = '';
		$this->warning = '';

		dol_syslog('START MIGRATION ROLLBACK', LOG_DEBUG, 0, '_situationmigration');

		$this->db->begin();

		$entityList = getEntity('facture');

		// Restauration des valeurs depuis la table de backup via UPDATE (pas de DELETE/INSERT
		// pour éviter les problèmes de contraintes FK sur facturedet)
		if ($this->db->type == 'pgsql') {
			$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_facturedet . " AS fd SET";
			$sql .= " situation_percent = bk.situation_percent,";
			$sql .= " total_ht = bk.total_ht,";
			$sql .= " total_tva = bk.total_tva,";
			$sql .= " total_ttc = bk.total_ttc,";
			$sql .= " total_localtax1 = bk.total_localtax1,";
			$sql .= " total_localtax2 = bk.total_localtax2,";
			$sql .= " multicurrency_total_ht = bk.multicurrency_total_ht,";
			$sql .= " multicurrency_total_tva = bk.multicurrency_total_tva,";
			$sql .= " multicurrency_total_ttc = bk.multicurrency_total_ttc";
			$sql .= " FROM " . MAIN_DB_PREFIX . $this->table_backupdet . " AS bk,";
			$sql .= " " . MAIN_DB_PREFIX . $this->table_facture . " AS f";
			$sql .= " WHERE bk.rowid = fd.rowid AND f.rowid = fd.fk_facture";
			$sql .= " AND f.type = " . ((int) Facture::TYPE_SITUATION) . " AND f.entity IN (" . $entityList . ")";
		} else {
			$sql = "UPDATE " . MAIN_DB_PREFIX . $this->table_facturedet . " AS fd";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_backupdet . " AS bk ON bk.rowid = fd.rowid";
			$sql .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facture . " AS f ON f.rowid = fd.fk_facture";
			$sql .= " AND f.type = " . ((int) Facture::TYPE_SITUATION) . " AND f.entity IN (" . $entityList . ")";
			$sql .= " SET fd.situation_percent = bk.situation_percent,";
			$sql .= " fd.total_ht = bk.total_ht,";
			$sql .= " fd.total_tva = bk.total_tva,";
			$sql .= " fd.total_ttc = bk.total_ttc,";
			$sql .= " fd.total_localtax1 = bk.total_localtax1,";
			$sql .= " fd.total_localtax2 = bk.total_localtax2,";
			$sql .= " fd.multicurrency_total_ht = bk.multicurrency_total_ht,";
			$sql .= " fd.multicurrency_total_tva = bk.multicurrency_total_tva,";
			$sql .= " fd.multicurrency_total_ttc = bk.multicurrency_total_ttc";
		}
		dol_syslog('sql=' . $sql, LOG_DEBUG, 0, '_situationmigration');

		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackRestoreDet', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -1;
		}

		// Restauration des totaux facture depuis la table de backup
		if ($this->db->type == 'pgsql') {
			$sql_fac = "UPDATE " . MAIN_DB_PREFIX . $this->table_facture . " AS f SET";
			$sql_fac .= " total_ht = bk.total_ht,";
			$sql_fac .= " total_tva = bk.total_tva,";
			$sql_fac .= " total_ttc = bk.total_ttc,";
			$sql_fac .= " localtax1 = bk.localtax1,";
			$sql_fac .= " localtax2 = bk.localtax2,";
			$sql_fac .= " revenuestamp = bk.revenuestamp,";
			$sql_fac .= " multicurrency_total_ht = bk.multicurrency_total_ht,";
			$sql_fac .= " multicurrency_total_tva = bk.multicurrency_total_tva,";
			$sql_fac .= " multicurrency_total_ttc = bk.multicurrency_total_ttc";
			$sql_fac .= " FROM " . MAIN_DB_PREFIX . $this->table_backupfac . " AS bk";
			$sql_fac .= " WHERE bk.rowid = f.rowid";
			$sql_fac .= " AND f.type = " . ((int) Facture::TYPE_SITUATION) . " AND f.entity IN (" . $entityList . ")";
		} else {
			$sql_fac = "UPDATE " . MAIN_DB_PREFIX . $this->table_facture . " AS f";
			$sql_fac .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_backupfac . " AS bk ON bk.rowid = f.rowid";
			$sql_fac .= " SET f.total_ht = bk.total_ht,";
			$sql_fac .= " f.total_tva = bk.total_tva,";
			$sql_fac .= " f.total_ttc = bk.total_ttc,";
			$sql_fac .= " f.localtax1 = bk.localtax1,";
			$sql_fac .= " f.localtax2 = bk.localtax2,";
			$sql_fac .= " f.revenuestamp = bk.revenuestamp,";
			$sql_fac .= " f.multicurrency_total_ht = bk.multicurrency_total_ht,";
			$sql_fac .= " f.multicurrency_total_tva = bk.multicurrency_total_tva,";
			$sql_fac .= " f.multicurrency_total_ttc = bk.multicurrency_total_ttc";
			$sql_fac .= " WHERE f.type = " . ((int) Facture::TYPE_SITUATION) . " AND f.entity IN (" . $entityList . ")";
		}
		dol_syslog('sql=' . $sql_fac, LOG_DEBUG, 0, '_situationmigration');

		$res_fac = $this->db->query($sql_fac);
		if (!$res_fac) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackRestoreFac', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_fac, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -2;
		}

		if (!dolibarr_set_const($this->db, 'MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP', '0', 'chaine', 0, '', $conf->entity)) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackSetConst', 'MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP');
			dol_syslog($this->error, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -3;
		}
		if (!dolibarr_set_const($this->db, 'FACTURESITUATIONMIGRATION_ISDONE', '0', 'chaine', 0, '', $conf->entity)) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackSetConst', 'FACTURESITUATIONMIGRATION_ISDONE');
			dol_syslog($this->error, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -3;
		}
		if (!dolibarr_set_const($this->db, 'INVOICE_USE_SITUATION', '1', 'chaine', 0, '', $conf->entity)) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackSetConst', 'INVOICE_USE_SITUATION');
			dol_syslog($this->error, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -3;
		}
		dol_syslog('Reset MAIN_MODULE_FACTURESITUATIONMIGRATION_STEP=0 || FACTURESITUATIONMIGRATION_ISDONE=0 || INVOICE_USE_SITUATION=1', LOG_DEBUG, 0, '_situationmigration');

		// VIDER LES TABLES MIGRATION ET BACKUP (scoped to current entity sharing)
		$sql_migration = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_migration;
		$sql_migration .= " WHERE entity IN (" . $entityList . ")";
		dol_syslog('sql=' . $sql_migration, LOG_DEBUG, 0, '_situationmigration');

		$res_migration = $this->db->query($sql_migration);
		if (!$res_migration) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackCleanMigration', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_migration, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -4;
		}

		$sql_backup = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_backupdet;
		$sql_backup .= " WHERE rowid IN (";
		$sql_backup .= "SELECT * FROM (SELECT bk2.rowid FROM " . MAIN_DB_PREFIX . $this->table_backupdet . " as bk2";
		$sql_backup .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facture . " as f2 ON f2.rowid = bk2.fk_facture";
		$sql_backup .= " AND f2.entity IN (" . $entityList . ")) as tmp2";
		$sql_backup .= ")";
		dol_syslog('sql=' . $sql_backup, LOG_DEBUG, 0, '_situationmigration');

		$res_backup = $this->db->query($sql_backup);
		if (!$res_backup) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackCleanBackupDet', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_backup, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -5;
		}

		$sql_backup_fac = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_backupfac;
		$sql_backup_fac .= " WHERE rowid IN (";
		$sql_backup_fac .= "SELECT * FROM (SELECT bk3.rowid FROM " . MAIN_DB_PREFIX . $this->table_backupfac . " as bk3";
		$sql_backup_fac .= " WHERE bk3.entity IN (" . $entityList . ")) as tmp3";
		$sql_backup_fac .= ")";
		dol_syslog('sql=' . $sql_backup_fac, LOG_DEBUG, 0, '_situationmigration');

		$res_backup_fac = $this->db->query($sql_backup_fac);
		if (!$res_backup_fac) {
			$this->error = $langs->trans('FactureSituationMigrationErrorRollbackCleanBackupFac', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_backup_fac, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -6;
		}

		$this->db->commit();

		// Supprimer les tables de backup si elles sont vides
		$res_count_det = $this->db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . $this->table_backupdet);
		if ($res_count_det) {
			$obj_count_det = $this->db->fetch_object($res_count_det);
			if ($obj_count_det && intval($obj_count_det->nb) == 0) {
				$this->db->query("DROP TABLE " . MAIN_DB_PREFIX . $this->table_backupdet);
				dol_syslog('Dropped empty backup table ' . MAIN_DB_PREFIX . $this->table_backupdet, LOG_DEBUG, 0, '_situationmigration');
			}
		}

		$res_count_fac = $this->db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . $this->table_backupfac);
		if ($res_count_fac) {
			$obj_count_fac = $this->db->fetch_object($res_count_fac);
			if ($obj_count_fac && intval($obj_count_fac->nb) == 0) {
				$this->db->query("DROP TABLE " . MAIN_DB_PREFIX . $this->table_backupfac);
				dol_syslog('Dropped empty backup table ' . MAIN_DB_PREFIX . $this->table_backupfac, LOG_DEBUG, 0, '_situationmigration');
			}
		}

		dol_syslog('END MIGRATION ROLLBACK', LOG_DEBUG, 0, '_situationmigration');
		return 1;
	}

	/**
	 * Confirm migration: clean up backup tables to finalize.
	 *
	 * Deletes data from backup tables for the current entity and drops them
	 * if empty. The migration tracking table is cleaned but never dropped
	 * (it is created by the module installer and may be used by other entities).
	 *
	 * The 3 DELETE operations run in a transaction so a partial failure
	 * leaves the database in a coherent state.
	 *
	 * On error, $this->error contains the translated error message.
	 *
	 * @return int  1 on success, -1 on error
	 */
	public function confirmMigration()
	{
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');
		$this->error = '';
		$this->warning = '';

		dol_syslog('START CONFIRM MIGRATION', LOG_DEBUG, 0, '_situationmigration');

		$entityList = getEntity('facture');

		$this->db->begin();

		// Delete migration tracking data for current entity
		$sql = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_migration;
		$sql .= " WHERE entity IN (" . $entityList . ")";
		$res = $this->db->query($sql);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorConfirmCleanMigration', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -1;
		}

		// Delete backup facturedet for current entity
		$sql_del_det = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_backupdet;
		$sql_del_det .= " WHERE rowid IN (";
		$sql_del_det .= "SELECT * FROM (SELECT bk.rowid FROM " . MAIN_DB_PREFIX . $this->table_backupdet . " as bk";
		$sql_del_det .= " INNER JOIN " . MAIN_DB_PREFIX . $this->table_facture . " as f ON f.rowid = bk.fk_facture";
		$sql_del_det .= " AND f.entity IN (" . $entityList . ")) as tmp";
		$sql_del_det .= ")";
		$res = $this->db->query($sql_del_det);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorConfirmCleanBackupDet', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_del_det, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -1;
		}

		// Delete backup facture for current entity
		$sql_del_fac = "DELETE FROM " . MAIN_DB_PREFIX . $this->table_backupfac;
		$sql_del_fac .= " WHERE rowid IN (";
		$sql_del_fac .= "SELECT * FROM (SELECT bk.rowid FROM " . MAIN_DB_PREFIX . $this->table_backupfac . " as bk";
		$sql_del_fac .= " WHERE bk.entity IN (" . $entityList . ")) as tmp";
		$sql_del_fac .= ")";
		$res = $this->db->query($sql_del_fac);
		if (!$res) {
			$this->error = $langs->trans('FactureSituationMigrationErrorConfirmCleanBackupFac', $this->db->lasterror());
			dol_syslog($this->error . ' sql=' . $sql_del_fac, LOG_ERR, 0, '_situationmigration');
			$this->db->rollback();
			return -1;
		}

		$this->db->commit();

		// Drop backup tables only if they are now completely empty (multi-entity safe).
		// Failures here are non-blocking: data has been cleaned up, table drop is a tidy-up step.
		$backup_tables = array($this->table_backupdet, $this->table_backupfac);
		foreach ($backup_tables as $table) {
			$res_count = $this->db->query("SELECT COUNT(*) as nb FROM " . MAIN_DB_PREFIX . $table);
			if (!$res_count) {
				dol_syslog('Cannot count rows in ' . MAIN_DB_PREFIX . $table . ': ' . $this->db->lasterror(), LOG_WARNING, 0, '_situationmigration');
				continue;
			}
			$obj = $this->db->fetch_object($res_count);
			if ($obj && ((int) $obj->nb) == 0) {
				$res_drop = $this->db->query("DROP TABLE " . MAIN_DB_PREFIX . $table);
				if ($res_drop) {
					dol_syslog('Dropped empty backup table ' . MAIN_DB_PREFIX . $table, LOG_DEBUG, 0, '_situationmigration');
				} else {
					dol_syslog('Cannot drop empty backup table ' . MAIN_DB_PREFIX . $table . ': ' . $this->db->lasterror(), LOG_WARNING, 0, '_situationmigration');
				}
			}
		}

		dol_syslog('END CONFIRM MIGRATION', LOG_DEBUG, 0, '_situationmigration');
		return 1;
	}

	/**
	 * Handle invalid line percent values (>100 or <0) based on module configuration.
	 *
	 * If FACTURESITUATIONMIGRATION_PERCENT_MORE_100_ERROR is set, increments error counter.
	 * If FACTURESITUATIONMIGRATION_PERCENT_MORE_100_FIX is set, forces percent to 100.
	 * Same logic for <0 with PERCENT_LESS_0_ERROR/_FIX constants.
	 *
	 * @param  float   $percent       The percent value to check
	 * @param  int     $lineID        Current line rowid (for logging)
	 * @param  int     $linePrevID    Previous line rowid (for logging)
	 * @param  array   $lineInfos     Line data array, modified by reference if fix is applied
	 * @param  int     $update_error  Error counter, incremented by reference if error is flagged
	 * @return void
	 */
	protected function _handle_line_percent_value($percent, $lineID, $linePrevID, &$lineInfos, &$update_error)
	{
		if ($percent > 100) {
			if (getDolGlobalString('FACTURESITUATIONMIGRATION_PERCENT_MORE_100_ERROR', '') != '') {
				dol_syslog('Line::'.$lineID.' - PreviousLine::'.$linePrevID." ERROR: line_percent is > 100", LOG_ERR, 0, '_situationmigration');
				$update_error++;
			}
			if (getDolGlobalString('FACTURESITUATIONMIGRATION_PERCENT_MORE_100_FIX', '') != '') {
				dol_syslog('Line::'.$lineID.' - PreviousLine::'.$linePrevID." FIX: line_percent is > 100, force to 100", LOG_ERR, 0, '_situationmigration');
				$lineInfos['line_percent'] = 100;
			}
		} elseif ($percent < 0) {
			if (getDolGlobalString('FACTURESITUATIONMIGRATION_PERCENT_LESS_0_ERROR', '') != '') {
				dol_syslog('Line::'.$lineID.' - PreviousLine::'.$linePrevID." ERROR: line_percent is < 0", LOG_ERR, 0, '_situationmigration');
				$update_error++;
			}
			if (getDolGlobalString('FACTURESITUATIONMIGRATION_PERCENT_LESS_0_FIX', '') != '') {
				dol_syslog('Line::'.$lineID.' - PreviousLine::'.$linePrevID." FIX: line_percent is < 0, force to 0", LOG_ERR, 0, '_situationmigration');
				$lineInfos['line_percent'] = 0;
			}
		}
	}

	/**
	 * Recalculate all situation invoice totals using mode 2 (delta).
	 *
	 * Fixes facture.total_ht/tva/ttc that were corrupted by update_price()
	 * running in mode 1 after lines were already converted to deltas.
	 * Forces INVOICE_USE_SITUATION=2 in memory during recalculation.
	 *
	 * @return int  Number of invoices recalculated, or -1 on error
	 */
	public function recalculateInvoiceTotals()
	{
		global $conf;

		$entityList = getEntity('facture');

		$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX.$this->table_facture;
		$sql .= " WHERE COALESCE(situation_cycle_ref, 0) > 0";
		$sql .= " AND entity IN (".$entityList.")";
		$sql .= " ORDER BY situation_cycle_ref ASC, situation_counter ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return -1;
		}

		// Force mode 2: all lines are deltas, invoice totals = simple sum of lines
		$save_use_situation = getDolGlobalString('INVOICE_USE_SITUATION');
		$conf->global->INVOICE_USE_SITUATION = '2';

		$count = 0;
		while ($obj = $this->db->fetch_object($resql)) {
			$facture_tmp = new Facture($this->db);
			if ($facture_tmp->fetch($obj->rowid) > 0) {
				$facture_tmp->update_price(1);
				$count++;
			}
		}
		$this->db->free($resql);

		// Restore original value
		$conf->global->INVOICE_USE_SITUATION = $save_use_situation;

		dol_syslog('recalculateInvoiceTotals: '.$count.' invoices recalculated', LOG_DEBUG, 0, '_situationmigration');
		return $count;
	}

	/**
	 * Check if both backup tables exist.
	 *
	 * @return bool  true if both backup tables exist
	 */
	public function backupTablesExist()
	{
		$found_det = false;
		$found_fac = false;
		$target_det = MAIN_DB_PREFIX.$this->table_backupdet;
		$target_fac = MAIN_DB_PREFIX.$this->table_backupfac;

		if ($this->db->type == 'pgsql') {
			$sql = "SELECT tablename FROM pg_tables WHERE schemaname = 'public'";
			$sql .= " AND (tablename = '".$this->db->escape($target_det)."'";
			$sql .= " OR tablename = '".$this->db->escape($target_fac)."')";
		} else {
			$sql = "SHOW TABLES LIKE '".$this->db->escape(MAIN_DB_PREFIX)."facture_situation_migration_backup_%'";
		}

		$resql = $this->db->query($sql);
		if ($resql) {
			while ($obj = $this->db->fetch_array($resql)) {
				$tablename = $obj[0];
				if ($tablename == $target_det) {
					$found_det = true;
				}
				if ($tablename == $target_fac) {
					$found_fac = true;
				}
			}
			$this->db->free($resql);
		}

		return ($found_det && $found_fac);
	}

	/**
	 * Get list of cycles with backup vs current comparison for verification.
	 *
	 * @param  string  $search_status  Filter: 'all', 'ok', 'error'
	 * @param  int     $search_year    Filter by year (0 = all)
	 * @param  string  $sortfield      Sort field
	 * @param  string  $sortorder      Sort order (ASC/DESC)
	 * @param  int     $limit          Max results
	 * @param  int     $offset         Offset for pagination
	 * @param  float   $tolerance      Rounding tolerance for deviation comparison (default 0.01)
	 * @return array                   Array with keys: cycles, total, nb_ok, nb_error
	 */
	public function getVerificationCyclesList($search_status = 'all', $search_year = 0, $sortfield = 'cycle_ref', $sortorder = 'ASC', $limit = 0, $offset = 0, $tolerance = 0.01)
	{
		$result = array('cycles' => array(), 'total' => 0, 'nb_ok' => 0, 'nb_error' => 0);

		$entityList = getEntity('facture');
		$tolerance = (float) $tolerance;
		$search_year = (int) $search_year;
		$offset = max(0, (int) $offset);
		$limit = max(0, (int) $limit);

		// Whitelist sortfield/sortorder to prevent SQL injection
		$sort_columns = array('cycle_ref', 'nb_factures', 'year', 'ecart_ht', 'ecart_ttc', 'status_ok');
		if (!in_array($sortfield, $sort_columns)) {
			$sortfield = 'cycle_ref';
		}
		$sortorder = (strtoupper($sortorder) == 'DESC') ? 'DESC' : 'ASC';

		// Cycle status comes from the pre-computed `checked` column in the
		// migration table (set by verifyCycle() after step 3). MIN(m.checked)
		// gives the cycle-level status: 1=OK, -1=error, 0=not checked.
		// We still compute ecart_ht/ecart_ttc for informational display.

		$ecart_ht_sql = '(SUM(f.total_ht) - SUM(bk.total_ht))';
		$ecart_ttc_sql = '(SUM(f.total_ttc) - SUM(bk.total_ttc))';

		$from_where = " FROM ".MAIN_DB_PREFIX.$this->table_backupfac." as bk";
		$from_where .= " INNER JOIN ".MAIN_DB_PREFIX.$this->table_facture." as f ON f.rowid = bk.rowid";
		$from_where .= " LEFT JOIN ".MAIN_DB_PREFIX.$this->table_migration." as m ON m.rowid = bk.rowid";
		$from_where .= " WHERE COALESCE(bk.situation_cycle_ref, 0) > 0";
		$from_where .= " AND bk.entity IN (".$entityList.")";

		// HAVING clause for filters applied after aggregation
		$having = '';
		if ($search_year > 0) {
			$having .= " AND MAX(EXTRACT(YEAR FROM bk.datef)) = ".$search_year;
		}
		if ($search_status == 'ok') {
			$having .= " AND MIN(COALESCE(m.checked, 0)) = 1";
		} elseif ($search_status == 'error') {
			$having .= " AND MIN(COALESCE(m.checked, 0)) != 1";
		}
		if ($having != '') {
			$having = ' HAVING 1=1'.$having;
		}

		// --------------------------------------------------------------------
		// Query 1: global stats (nb_ok, nb_error) - NO filter applied
		// Uses MIN(checked) per cycle: 1 = OK, anything else = error/not checked
		// --------------------------------------------------------------------
		$sql_stats = "SELECT";
		$sql_stats .= " SUM(".$this->db->ifsql('cycle_check = 1', '1', '0').") as nb_ok,";
		$sql_stats .= " SUM(".$this->db->ifsql('cycle_check != 1', '1', '0').") as nb_error";
		$sql_stats .= " FROM (";
		$sql_stats .= " SELECT MIN(COALESCE(m.checked, 0)) as cycle_check";
		$sql_stats .= $from_where;
		$sql_stats .= " GROUP BY bk.situation_cycle_ref";
		$sql_stats .= " ) as sub_stats";

		$resql = $this->db->query($sql_stats);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$result['nb_ok'] = (int) $obj->nb_ok;
				$result['nb_error'] = (int) $obj->nb_error;
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			dol_syslog('getVerificationCyclesList SQL stats error: '.$this->error.' sql='.$sql_stats, LOG_ERR, 0, '_situationmigration');
			return $result;
		}

		// --------------------------------------------------------------------
		// Query 2: total count of filtered cycles (for pagination)
		// --------------------------------------------------------------------
		$sql_count = "SELECT COUNT(*) as nb FROM (";
		$sql_count .= " SELECT bk.situation_cycle_ref";
		$sql_count .= $from_where;
		$sql_count .= " GROUP BY bk.situation_cycle_ref";
		$sql_count .= $having;
		$sql_count .= " ) as sub_count";

		$resql = $this->db->query($sql_count);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			if ($obj) {
				$result['total'] = (int) $obj->nb;
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			dol_syslog('getVerificationCyclesList SQL count error: '.$this->error.' sql='.$sql_count, LOG_ERR, 0, '_situationmigration');
			return $result;
		}

		// Skip the page query if we know the filtered set is empty
		if ($result['total'] == 0) {
			return $result;
		}

		// --------------------------------------------------------------------
		// Query 3: paginated page of cycles (filters + sort + limit/offset)
		// --------------------------------------------------------------------
		$sql_page = "SELECT bk.situation_cycle_ref as cycle_ref,";
		$sql_page .= " SUM(bk.total_ht) as backup_ht,";
		$sql_page .= " SUM(bk.total_ttc) as backup_ttc,";
		$sql_page .= " SUM(f.total_ht) as current_ht,";
		$sql_page .= " SUM(f.total_ttc) as current_ttc,";
		$sql_page .= " ROUND(".$ecart_ht_sql.", 2) as ecart_ht,";
		$sql_page .= " ROUND(".$ecart_ttc_sql.", 2) as ecart_ttc,";
		$sql_page .= " COUNT(*) as nb_factures,";
		$sql_page .= " MAX(EXTRACT(YEAR FROM bk.datef)) as year,";
		$sql_page .= " MIN(COALESCE(m.checked, 0)) as status_ok";
		$sql_page .= $from_where;
		$sql_page .= " GROUP BY bk.situation_cycle_ref";
		$sql_page .= $having;
		$sql_page .= " ORDER BY ".$sortfield." ".$sortorder;
		if ($limit > 0) {
			$sql_page .= " LIMIT ".$limit." OFFSET ".$offset;
		}

		$resql = $this->db->query($sql_page);
		if ($resql) {
			while ($obj = $this->db->fetch_object($resql)) {
				$ecart_ht = (float) $obj->ecart_ht;
				$ecart_ttc = (float) $obj->ecart_ttc;
				$result['cycles'][] = array(
					'cycle_ref' => (int) $obj->cycle_ref,
					'nb_factures' => (int) $obj->nb_factures,
					'backup_ht' => (float) $obj->backup_ht,
					'current_ht' => (float) $obj->current_ht,
					'ecart_ht' => $ecart_ht,
					'ecart_ht_ok' => (abs($ecart_ht) <= $tolerance),
					'backup_ttc' => (float) $obj->backup_ttc,
					'current_ttc' => (float) $obj->current_ttc,
					'ecart_ttc' => $ecart_ttc,
					'ecart_ttc_ok' => (abs($ecart_ttc) <= $tolerance),
					'year' => (int) $obj->year,
					'status_ok' => ((int) $obj->status_ok == 1),
				);
			}
			$this->db->free($resql);
		} else {
			$this->error = $this->db->lasterror();
			dol_syslog('getVerificationCyclesList SQL page error: '.$this->error.' sql='.$sql_page, LOG_ERR, 0, '_situationmigration');
		}

		return $result;
	}

	/**
	 * Get detailed comparison for a specific cycle (invoices and lines).
	 *
	 * On SQL error, $this->error is populated and the method returns false.
	 *
	 * @param  int         $cycle_ref  Situation cycle reference
	 * @param  float       $tolerance  Rounding tolerance for ecart flags (default from constant or 0.01)
	 * @return array|false             Array keyed by situation_counter, or false on SQL error
	 */
	public function getVerificationCycleDetail($cycle_ref, $tolerance = -1)
	{
		if ($tolerance < 0) {
			$tolerance = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_VERIFY_TOLERANCE', '0.01');
		}
		global $langs;
		$langs->load('facturesituationmigration@facturesituationmigration');

		$entityList = getEntity('facture');
		$detail = array();

		// Invoices: current vs backup
		$sql = "SELECT f.rowid as facture_id, f.ref, f.type, f.situation_counter, f.situation_final,";
		$sql .= " f.total_ht, f.total_tva, f.total_ttc, f.localtax1, f.localtax2,";
		$sql .= " f.multicurrency_total_ht, f.multicurrency_total_tva, f.multicurrency_total_ttc,";
		$sql .= " bk.total_ht as bk_total_ht, bk.total_tva as bk_total_tva, bk.total_ttc as bk_total_ttc,";
		$sql .= " bk.localtax1 as bk_localtax1, bk.localtax2 as bk_localtax2,";
		$sql .= " bk.multicurrency_total_ht as bk_multi_ht, bk.multicurrency_total_tva as bk_multi_tva,";
		$sql .= " bk.multicurrency_total_ttc as bk_multi_ttc";
		$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_facture." as f";
		$sql .= " LEFT JOIN ".MAIN_DB_PREFIX.$this->table_backupfac." as bk ON bk.rowid = f.rowid";
		$sql .= " WHERE f.situation_cycle_ref = ".((int) $cycle_ref);
		$sql .= " AND f.entity IN (".$entityList.")";
		$sql .= " ORDER BY f.situation_counter ASC";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('getVerificationCycleDetail: SQL error on invoices query: '.$this->db->lasterror().' sql='.$sql, LOG_ERR, 0, '_situationmigration');
			$this->error = $langs->trans('FactureSituationMigrationErrorDetailInvoicesQuery', $cycle_ref, $this->db->lasterror());
			return false;
		}
		while ($obj = $this->db->fetch_object($resql)) {
				$counter = (int) $obj->situation_counter;
				$detail[$counter] = array(
					'facture_id' => (int) $obj->facture_id,
					'ref' => $obj->ref,
					'type' => (int) $obj->type,
					'situation_counter' => $counter,
					'situation_final' => (int) $obj->situation_final,
					'current' => array(
						'total_ht' => (float) $obj->total_ht,
						'total_tva' => (float) $obj->total_tva,
						'total_ttc' => (float) $obj->total_ttc,
						'localtax1' => (float) $obj->localtax1,
						'localtax2' => (float) $obj->localtax2,
						'multicurrency_total_ht' => (float) $obj->multicurrency_total_ht,
						'multicurrency_total_tva' => (float) $obj->multicurrency_total_tva,
						'multicurrency_total_ttc' => (float) $obj->multicurrency_total_ttc,
					),
					'backup' => array(
						'total_ht' => (float) $obj->bk_total_ht,
						'total_tva' => (float) $obj->bk_total_tva,
						'total_ttc' => (float) $obj->bk_total_ttc,
						'localtax1' => (float) $obj->bk_localtax1,
						'localtax2' => (float) $obj->bk_localtax2,
						'multicurrency_total_ht' => (float) $obj->bk_multi_ht,
						'multicurrency_total_tva' => (float) $obj->bk_multi_tva,
						'multicurrency_total_ttc' => (float) $obj->bk_multi_ttc,
					),
					'lines' => array(),
				);
		}
		$this->db->free($resql);

		// Lines: current vs backup
		$sql_lines = "SELECT fd.rowid as line_id, fd.label, fd.description, fd.fk_prev_id,";
		$sql_lines .= " fd.situation_percent, fd.total_ht, fd.total_tva, fd.total_ttc,";
		$sql_lines .= " fd.total_localtax1, fd.total_localtax2,";
		$sql_lines .= " fd.multicurrency_total_ht, fd.multicurrency_total_tva, fd.multicurrency_total_ttc,";
		$sql_lines .= " bk_fd.situation_percent as bk_percent, bk_fd.total_ht as bk_ht,";
		$sql_lines .= " bk_fd.total_tva as bk_tva, bk_fd.total_ttc as bk_ttc,";
		$sql_lines .= " bk_fd.total_localtax1 as bk_localtax1, bk_fd.total_localtax2 as bk_localtax2,";
		$sql_lines .= " bk_fd.multicurrency_total_ht as bk_multi_ht,";
		$sql_lines .= " bk_fd.multicurrency_total_tva as bk_multi_tva,";
		$sql_lines .= " bk_fd.multicurrency_total_ttc as bk_multi_ttc,";
		$sql_lines .= " fd.fk_facture, f.situation_counter";
		$sql_lines .= " FROM ".MAIN_DB_PREFIX.$this->table_facturedet." as fd";
		$sql_lines .= " INNER JOIN ".MAIN_DB_PREFIX.$this->table_facture." as f ON f.rowid = fd.fk_facture";
		$sql_lines .= " LEFT JOIN ".MAIN_DB_PREFIX.$this->table_backupdet." as bk_fd ON bk_fd.rowid = fd.rowid";
		$sql_lines .= " WHERE f.situation_cycle_ref = ".((int) $cycle_ref);
		$sql_lines .= " AND f.entity IN (".$entityList.")";
		$sql_lines .= " ORDER BY f.situation_counter ASC, fd.rowid ASC";

		$resql = $this->db->query($sql_lines);
		if (!$resql) {
			dol_syslog('getVerificationCycleDetail: SQL error on lines query: '.$this->db->lasterror().' sql='.$sql_lines, LOG_ERR, 0, '_situationmigration');
			$this->error = $langs->trans('FactureSituationMigrationErrorDetailLinesQuery', $cycle_ref, $this->db->lasterror());
			return false;
		}
		while ($obj = $this->db->fetch_object($resql)) {
			$counter = (int) $obj->situation_counter;
			if (!isset($detail[$counter])) {
				continue;
			}
			$detail[$counter]['lines'][$obj->line_id] = array(
				'line_id' => (int) $obj->line_id,
				'label' => $obj->label,
				'description' => $obj->description,
				'fk_prev_id' => (int) $obj->fk_prev_id,
				'current' => array(
					'situation_percent' => (float) $obj->situation_percent,
					'total_ht' => (float) $obj->total_ht,
					'total_tva' => (float) $obj->total_tva,
					'total_ttc' => (float) $obj->total_ttc,
					'total_localtax1' => (float) $obj->total_localtax1,
					'total_localtax2' => (float) $obj->total_localtax2,
					'multicurrency_total_ht' => (float) $obj->multicurrency_total_ht,
					'multicurrency_total_tva' => (float) $obj->multicurrency_total_tva,
					'multicurrency_total_ttc' => (float) $obj->multicurrency_total_ttc,
				),
				'backup' => array(
					'situation_percent' => (float) $obj->bk_percent,
					'total_ht' => (float) $obj->bk_ht,
					'total_tva' => (float) $obj->bk_tva,
					'total_ttc' => (float) $obj->bk_ttc,
					'total_localtax1' => (float) $obj->bk_localtax1,
					'total_localtax2' => (float) $obj->bk_localtax2,
					'multicurrency_total_ht' => (float) $obj->bk_multi_ht,
					'multicurrency_total_tva' => (float) $obj->bk_multi_tva,
					'multicurrency_total_ttc' => (float) $obj->bk_multi_ttc,
				),
			);
		}
		$this->db->free($resql);

		// Compute expected deltas for each situation > 1
		$counters = array_keys($detail);
		sort($counters);
		// Build line mapping: for each counter > 1, find previous line via fk_prev_id
		foreach ($counters as $idx => $counter) {
			if ($counter <= 1 || $idx == 0) {
				// Situation 1: expected = backup (no transformation)
				foreach ($detail[$counter]['lines'] as $line_id => &$line) {
					$line['expected'] = $line['backup'];
				}
				unset($line);
				continue;
			}
			$prev_counter = $counters[$idx - 1];
			foreach ($detail[$counter]['lines'] as $line_id => &$line) {
				$fk_prev_id = $line['fk_prev_id'];
				if ($fk_prev_id > 0 && isset($detail[$prev_counter]['lines'][$fk_prev_id])) {
					$prev_bk = $detail[$prev_counter]['lines'][$fk_prev_id]['backup'];
					$cur_bk = $line['backup'];
					$line['expected'] = array(
						'situation_percent' => round($cur_bk['situation_percent'] - $prev_bk['situation_percent'], 2),
						'total_ht' => round($cur_bk['total_ht'] - $prev_bk['total_ht'], 2),
						'total_tva' => round($cur_bk['total_tva'] - $prev_bk['total_tva'], 2),
						'total_ttc' => round($cur_bk['total_ttc'] - $prev_bk['total_ttc'], 2),
						'total_localtax1' => round($cur_bk['total_localtax1'] - $prev_bk['total_localtax1'], 2),
						'total_localtax2' => round($cur_bk['total_localtax2'] - $prev_bk['total_localtax2'], 2),
						'multicurrency_total_ht' => round($cur_bk['multicurrency_total_ht'] - $prev_bk['multicurrency_total_ht'], 2),
						'multicurrency_total_tva' => round($cur_bk['multicurrency_total_tva'] - $prev_bk['multicurrency_total_tva'], 2),
						'multicurrency_total_ttc' => round($cur_bk['multicurrency_total_ttc'] - $prev_bk['multicurrency_total_ttc'], 2),
					);
				} else {
					$line['expected'] = $line['backup'];
				}
			}
			unset($line);
		}

		// Expected invoice totals: backup facture totals were already deltas in
		// mode 1 (update_price subtracted previous invoices). So after migration,
		// the current total should simply equal the backup total for all situations.
		// Also compute ecart values and flags for the view layer.
		foreach ($counters as $idx => $counter) {
			$detail[$counter]['expected'] = $detail[$counter]['backup'];

			$expected = $detail[$counter]['expected'];
			$ecart_ht = round($detail[$counter]['current']['total_ht'] - $expected['total_ht'], 2);
			$ecart_ttc = round($detail[$counter]['current']['total_ttc'] - $expected['total_ttc'], 2);
			$ecart_ht_ok = (abs($ecart_ht) <= $tolerance);
			$ecart_ttc_ok = (abs($ecart_ttc) <= $tolerance);
			$facture_ok = ($ecart_ht_ok && $ecart_ttc_ok);

			$detail[$counter]['ecart_ht'] = $ecart_ht;
			$detail[$counter]['ecart_ttc'] = $ecart_ttc;
			$detail[$counter]['ecart_ht_ok'] = $ecart_ht_ok;
			$detail[$counter]['ecart_ttc_ok'] = $ecart_ttc_ok;

			// Per-line ecart flags
			foreach ($detail[$counter]['lines'] as $line_id => &$line) {
				$line_expected = isset($line['expected']) ? $line['expected'] : $line['backup'];
				$ecart_pct = round($line['current']['situation_percent'] - $line_expected['situation_percent'], 2);
				$ecart_line_ht = round($line['current']['total_ht'] - $line_expected['total_ht'], 2);

				$line['ecart_pct'] = $ecart_pct;
				$line['ecart_ht'] = $ecart_line_ht;
				$line['ecart_pct_ok'] = (abs($ecart_pct) <= $tolerance);
				$line['ecart_ht_ok'] = (abs($ecart_line_ht) <= $tolerance);
				$line['line_ok'] = ($line['ecart_pct_ok'] && $line['ecart_ht_ok']);

				if (!$line['line_ok']) {
					$facture_ok = false;
				}
			}
			unset($line);

			$detail[$counter]['facture_ok'] = $facture_ok;
		}

		return $detail;
	}

	/**
	 * Run coherence checks on a specific cycle.
	 *
	 * 4 checks:
	 * 1. Sum of current deltas percents = backup percent of last situation
	 * 2. Invoice total_ht = SUM(facturedet.total_ht) for each invoice
	 * 3. fk_prev_id chain integrity
	 * 4. Situation 1 lines unchanged (backup == current)
	 *
	 * @param  int    $cycle_ref  Situation cycle reference
	 * @param  float  $tolerance  Rounding tolerance (default from constant or 0.01)
	 * @param  array  $detail     Pre-loaded detail from getVerificationCycleDetail() (null = auto-load)
	 * @return array              Array of check results
	 */
	public function getCoherenceChecks($cycle_ref, $tolerance = -1, $detail = null)
	{
		if ($tolerance < 0) {
			$tolerance = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_VERIFY_TOLERANCE', '0.01');
		}
		if ($detail === null) {
			$detail = $this->getVerificationCycleDetail($cycle_ref, $tolerance);
		}
		if ($detail === false) {
			return array(
				array('label' => 'LoadDetail', 'ok' => false, 'details' => $this->error),
			);
		}
		$checks = array();
		$counters = array_keys($detail);
		sort($counters);

		// Check 0: Per-facture ecart (uses flags already computed by getVerificationCycleDetail)
		$check0_ok = true;
		$check0_details = '';
		foreach ($detail as $counter => $info) {
			if (!$info['ecart_ht_ok'] || !$info['ecart_ttc_ok']) {
				$check0_ok = false;
				$check0_details .= $info['ref'].' (sit '.$counter.'): ecart_ht='.$info['ecart_ht'].', ecart_ttc='.$info['ecart_ttc'].'. ';
			}
		}
		$checks[] = array(
			'label' => 'InvoiceTotalsMatchBackup',
			'ok' => $check0_ok,
			'details' => $check0_ok ? '' : $check0_details,
		);

		// Check 1: Sum of current percent deltas = backup percent of last situation
		$check1_ok = true;
		$check1_details = '';
		if (count($counters) > 0) {
			$last_counter = end($counters);
			// Collect all line IDs from last situation and their backup percents
			$last_lines = $detail[$last_counter]['lines'];
			// For each line chain, sum current percents across all situations
			foreach ($last_lines as $last_line_id => $last_line) {
				$target_percent = $last_line['backup']['situation_percent'];
				// Walk the chain backwards to sum current percents
				$sum_percent = 0;
				$line_id = $last_line_id;
				for ($i = count($counters) - 1; $i >= 0; $i--) {
					$c = $counters[$i];
					if (isset($detail[$c]['lines'][$line_id])) {
						$sum_percent += $detail[$c]['lines'][$line_id]['current']['situation_percent'];
						$line_id = $detail[$c]['lines'][$line_id]['fk_prev_id'];
					}
				}
				if (abs($sum_percent - $target_percent) > $tolerance) {
					$check1_ok = false;
					$check1_details .= 'Line #'.$last_line_id.': sum='.$sum_percent.' vs backup='.$target_percent.'. ';
				}
			}
		}
		$checks[] = array(
			'label' => 'SumDeltasEqualsTotal',
			'ok' => $check1_ok,
			'details' => $check1_ok ? '' : $check1_details,
		);

		// Check 2: Invoice total_ht = SUM(facturedet.total_ht)
		$check2_ok = true;
		$check2_details = '';
		$entityList = getEntity('facture');
		foreach ($detail as $counter => $info) {
			$sql = "SELECT SUM(fd.total_ht) as sum_ht";
			$sql .= " FROM ".MAIN_DB_PREFIX.$this->table_facturedet." as fd";
			$sql .= " WHERE fd.fk_facture = ".((int) $info['facture_id']);
			$resql = $this->db->query($sql);
			if ($resql) {
				$obj = $this->db->fetch_object($resql);
				$sum_lines_ht = (float) $obj->sum_ht;
				if (abs($sum_lines_ht - $info['current']['total_ht']) > $tolerance) {
					$check2_ok = false;
					$check2_details .= $info['ref'].': lines='.$sum_lines_ht.' vs invoice='.$info['current']['total_ht'].'. ';
				}
				$this->db->free($resql);
			}
		}
		$checks[] = array(
			'label' => 'InvoiceTotalMatchesLines',
			'ok' => $check2_ok,
			'details' => $check2_ok ? '' : $check2_details,
		);

		// Check 3: fk_prev_id chain integrity
		$check3_ok = true;
		$check3_details = '';
		foreach ($counters as $idx => $counter) {
			if ($idx == 0) {
				continue;
			}
			$prev_counter = $counters[$idx - 1];
			foreach ($detail[$counter]['lines'] as $line_id => $line) {
				$fk_prev_id = $line['fk_prev_id'];
				if ($fk_prev_id > 0 && !isset($detail[$prev_counter]['lines'][$fk_prev_id])) {
					$check3_ok = false;
					$check3_details .= 'Line #'.$line_id.': fk_prev_id='.$fk_prev_id.' not found in situation '.$prev_counter.'. ';
				}
			}
		}
		$checks[] = array(
			'label' => 'PrevIdChainIntact',
			'ok' => $check3_ok,
			'details' => $check3_ok ? '' : $check3_details,
		);

		// Check 4: Situation 1 lines unchanged (backup == current)
		$check4_ok = true;
		$check4_details = '';
		if (count($counters) > 0 && $counters[0] == 1 && isset($detail[1])) {
			foreach ($detail[1]['lines'] as $line_id => $line) {
				$fields = array('situation_percent', 'total_ht', 'total_tva', 'total_ttc');
				foreach ($fields as $f) {
					if (abs($line['current'][$f] - $line['backup'][$f]) > $tolerance) {
						$check4_ok = false;
						$check4_details .= 'Line #'.$line_id.': '.$f.' current='.$line['current'][$f].' vs backup='.$line['backup'][$f].'. ';
					}
				}
			}
		}
		$checks[] = array(
			'label' => 'FirstSituationUnchanged',
			'ok' => $check4_ok,
			'details' => $check4_ok ? '' : $check4_details,
		);

		return $checks;
	}

	/**
	 * Full verification of a cycle: load detail, check per-facture ecarts,
	 * and run the 4 coherence checks.
	 *
	 * This is the shared entry point used both by migration step 3 (post-migration
	 * validation) and by the verification detail page. Factorizes all test logic
	 * so both code paths run the exact same checks.
	 *
	 * @param  int          $cycle_ref  Situation cycle reference
	 * @param  float        $tolerance  Rounding tolerance (default from constant or 0.01)
	 * @return array|false              array('ok' => bool, 'detail' => array, 'checks' => array), or false on SQL error ($this->error is set)
	 */
	public function verifyCycle($cycle_ref, $tolerance = -1)
	{
		if ($tolerance < 0) {
			$tolerance = (float) getDolGlobalString('FACTURESITUATIONMIGRATION_VERIFY_TOLERANCE', '0.01');
		}

		$all_ok = true;

		// Load all detail data once (invoices + lines, backup vs current)
		$detail = $this->getVerificationCycleDetail($cycle_ref, $tolerance);
		if ($detail === false) {
			// SQL error — $this->error is already set by getVerificationCycleDetail
			return false;
		}

		// Ecart values and flags are already computed by getVerificationCycleDetail().
		// All checks (including InvoiceTotalsMatchBackup) via getCoherenceChecks
		$checks = $this->getCoherenceChecks($cycle_ref, $tolerance, $detail);
		foreach ($checks as $check) {
			if (!$check['ok']) {
				$all_ok = false;
			}
		}

		return array('ok' => $all_ok, 'detail' => $detail, 'checks' => $checks);
	}

	/**
	 * Set the checked flag on all invoices of a cycle in the migration table.
	 *
	 * @param  int  $cycle_ref   Situation cycle reference
	 * @param  int  $check_value 1=OK, -1=error, 0=not checked
	 * @return int               Number of rows updated, or -1 on SQL error
	 */
	public function setCycleChecked($cycle_ref, $check_value)
	{
		$sql = "UPDATE ".MAIN_DB_PREFIX.$this->table_migration;
		$sql .= " SET checked = ".((int) $check_value);
		$sql .= " WHERE situation_cycle_ref = ".((int) $cycle_ref);
		$sql .= " AND entity IN (".getEntity('facture').")";

		$resql = $this->db->query($sql);
		if (!$resql) {
			dol_syslog('setCycleChecked error: '.$this->db->lasterror().' sql='.$sql, LOG_ERR, 0, '_situationmigration');
			return -1;
		}
		return $this->db->affected_rows($resql);
	}
}
