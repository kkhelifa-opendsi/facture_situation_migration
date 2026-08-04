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
 * \file    facturesituationmigration/js/facturesituationmigration.js.php
 * \ingroup facturesituationmigration
 * \brief   JavaScript for FactureSituationMigration module (verification page).
 */

if (!defined('NOREQUIRESOC')) {
	define('NOREQUIRESOC', '1');
}
if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', 1);
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', 1);
}
if (!defined('NOLOGIN')) {
	define('NOLOGIN', 1);
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', 1);
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', 1);
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}

session_cache_limiter('public');

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

$langs->loadLangs(array('facturesituationmigration@facturesituationmigration'));

top_httphead('text/javascript; charset=UTF-8');

if (empty($dolibarr_nocache)) {
	header('Cache-Control: max-age=10800, public, must-revalidate');
} else {
	header('Cache-Control: no-cache');
}

?>
/* ==========================================================================
   FactureSituationMigration - Reverify AJAX batch processing
   ========================================================================== */

(function($) {
	'use strict';

	window.FactureSituationMigrationVerify = {

		// Configuration
		ajaxUrl: '<?php echo dol_buildpath('/facturesituationmigration/ajax/ajax_reverify.php', 1); ?>',
		batchSize: 10,

		// Translations
		trans: {
			confirmReverify: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationReverifyConfirm')); ?>',
			reverifyDone: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationReverifyDone')); ?>',
			cycle: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCycle')); ?>',
			error: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('Error')); ?>'
		},

		// State
		lastCycleRef: 0,
		totalProcessed: 0,
		totalToProcess: 0,
		totalErrors: 0,

		/**
		 * Initialize the reverify button
		 */
		init: function() {
			var self = this;
			$('#btn-reverify').on('click', function(e) {
				e.preventDefault();
				self.start();
			});
		},

		/**
		 * Start the reverification process
		 */
		start: function() {
			if (!confirm(this.trans.confirmReverify)) {
				return;
			}

			$('#btn-reverify').addClass('butActionRefused').removeClass('butAction');
			$('#reverify-progress').show();

			this.lastCycleRef = 0;
			this.totalProcessed = 0;
			this.totalToProcess = 0;
			this.totalErrors = 0;

			this.processBatch();
		},

		/**
		 * Process one batch of cycles via AJAX
		 */
		processBatch: function() {
			var self = this;

			$.ajax({
				url: this.ajaxUrl,
				type: 'GET',
				data: {
					last_cycle_ref: this.lastCycleRef,
					batch_size: this.batchSize
				},
				dataType: 'json',
				success: function(data) {
					self.handleBatchResult(data);
				},
				error: function(xhr) {
					$.jnotify(self.trans.error + ': ' + xhr.statusText, 'error', true);
					$('#reverify-bar').removeClass('progress-bar-success').addClass('progress-bar-danger');
					$('#btn-reverify').addClass('butAction').removeClass('butActionRefused');
				}
			});
		},

		/**
		 * Handle the result of a batch
		 * @param {object} data - JSON response from the AJAX endpoint
		 */
		handleBatchResult: function(data) {
			this.totalProcessed += data.processed;
			if (this.totalToProcess === 0) {
				this.totalToProcess = this.totalProcessed + data.remaining;
			}
			this.lastCycleRef = data.last_cycle_ref;

			// Display errors via jnotify (sticky)
			if (data.errors && data.errors.length > 0) {
				this.totalErrors += data.errors.length;
				var error_msg = '';
				for (var i = 0; i < data.errors.length; i++) {
					error_msg += (error_msg ? '<br>' : '') + data.errors[i];
				}
				$.jnotify(error_msg, 'error', true);
			}

			// Update progress bar
			var pct = this.totalToProcess > 0 ? Math.round((this.totalProcessed / this.totalToProcess) * 100) : 0;
			$('#reverify-bar').css('width', pct + '%').attr('aria-valuenow', pct);
			$('#reverify-progress-bar').attr('title', pct + '%');
			$('#reverify-status').text(this.totalProcessed + ' / ' + this.totalToProcess + ' (' + pct + '%)');

			if (data.done) {
				this.onComplete();
			} else {
				this.processBatch();
			}
		},

		/**
		 * Called when all batches are done
		 */
		onComplete: function() {
			var msg = this.trans.reverifyDone + ' (' + this.totalProcessed + ' ' + this.trans.cycle + 's';
			if (this.totalErrors > 0) {
				msg += ', ' + this.totalErrors + ' ' + this.trans.error + 's';
			}
			msg += ')';

			$.jnotify(msg, this.totalErrors > 0 ? 'error' : 'ok', this.totalErrors > 0);
			$('#reverify-status').html('<strong>' + msg + '</strong>');

			if (this.totalErrors > 0) {
				// Errors occurred: don't reload (errors would be lost), re-enable button
				$('#reverify-bar').removeClass('progress-bar-success').addClass('progress-bar-warning');
				$('#btn-reverify').addClass('butAction').removeClass('butActionRefused');
			} else {
				// All OK: reload page to refresh the list
				setTimeout(function() {
					location.reload();
				}, 2000);
			}
		}
	};

	window.FactureSituationMigrationStep3 = {

		// Configuration
		ajaxUrl: '<?php echo dol_buildpath('/facturesituationmigration/ajax/ajax_step3.php', 1); ?>',
		batchSize: 10,

		// Translations
		trans: {
			confirmStep3: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationStep3Confirm')); ?>',
			step3Done: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationStep3Done')); ?>',
			cycle: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCycle')); ?>',
			error: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('Error')); ?>'
		},

		// State
		totalProcessed: 0,
		totalToProcess: 0,
		totalErrors: 0,

		/**
		 * Initialize the step 3 button
		 */
		init: function() {
			var self = this;
			$('#btn-step3').on('click', function(e) {
				e.preventDefault();
				self.start();
			});
		},

		/**
		 * Start the batch migration process
		 */
		start: function() {
			if (!confirm(this.trans.confirmStep3)) {
				return;
			}

			$('#btn-step3').addClass('butActionRefused').removeClass('butAction');
			$('#step3-progress').show();

			this.totalProcessed = 0;
			this.totalToProcess = 0;
			this.totalErrors = 0;

			this.processBatch();
		},

		/**
		 * Process one batch of cycles via AJAX
		 */
		processBatch: function() {
			var self = this;

			$.ajax({
				url: this.ajaxUrl,
				type: 'GET',
				data: {
					batch_size: this.batchSize
				},
				dataType: 'json',
				success: function(data) {
					self.handleBatchResult(data);
				},
				error: function(xhr) {
					$.jnotify(self.trans.error + ': ' + xhr.statusText, 'error', true);
					$('#step3-bar').removeClass('progress-bar-success').addClass('progress-bar-danger');
					$('#btn-step3').addClass('butAction').removeClass('butActionRefused');
				}
			});
		},

		/**
		 * Handle the result of a batch
		 * @param {object} data - JSON response from the AJAX endpoint
		 */
		handleBatchResult: function(data) {
			this.totalProcessed += data.processed;
			if (this.totalToProcess === 0) {
				this.totalToProcess = this.totalProcessed + data.remaining;
			}

			// Display errors via jnotify (sticky)
			if (data.errors && data.errors.length > 0) {
				this.totalErrors += data.errors.length;
				var error_msg = '';
				for (var i = 0; i < data.errors.length; i++) {
					error_msg += (error_msg ? '<br>' : '') + data.errors[i];
				}
				$.jnotify(error_msg, 'error', true);
			}

			// Update progress bar
			var pct = this.totalToProcess > 0 ? Math.round((this.totalProcessed / this.totalToProcess) * 100) : 0;
			$('#step3-bar').css('width', pct + '%').attr('aria-valuenow', pct);
			$('#step3-progress-bar').attr('title', pct + '%');
			$('#step3-status').text(this.totalProcessed + ' / ' + this.totalToProcess + ' (' + pct + '%)');

			if (data.done) {
				this.onComplete(data.remaining);
			} else {
				this.processBatch();
			}
		},

		/**
		 * Called when the batch loop stops.
		 * @param {number} remaining - cycles still pending (0 = migration fully complete)
		 */
		onComplete: function(remaining) {
			var msg = this.trans.step3Done + ' (' + this.totalProcessed + ' ' + this.trans.cycle + 's';
			if (this.totalErrors > 0) {
				msg += ', ' + this.totalErrors + ' ' + this.trans.error + 's';
			}
			msg += ')';

			$.jnotify(msg, this.totalErrors > 0 ? 'error' : 'ok', this.totalErrors > 0);
			$('#step3-status').html('<strong>' + msg + '</strong>');

			// Reload as soon as the migration is complete (nothing left to process), so the
			// page advances to step 4 - even if some batches reported errors along the way.
			// Only stay (to keep errors visible and allow a retry) if cycles remain pending.
			if (remaining > 0) {
				$('#step3-bar').removeClass('progress-bar-success').addClass('progress-bar-warning');
				$('#btn-step3').addClass('butAction').removeClass('butActionRefused');
			} else {
				setTimeout(function() {
					location.reload();
				}, 2000);
			}
		}
	};

	window.FactureSituationMigrationCorrect = {

		// Configuration
		ajaxUrl: '<?php echo dol_buildpath('/facturesituationmigration/ajax/ajax_correct.php', 1); ?>',
		batchSize: 10,
		maxEcart: '<?php echo dol_escape_js((string) getDolGlobalString('FACTURESITUATIONMIGRATION_MAX_ECART_AUTOCORRECT', '0.1')); ?>',

		// Translations
		trans: {
			confirmCorrect: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCorrectAllConfirm', getDolGlobalString('FACTURESITUATIONMIGRATION_MAX_ECART_AUTOCORRECT', '0.1'))); ?>',
			correctDone: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCorrectAllDone')); ?>',
			corrected: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCorrected')); ?>',
			skipped: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationSkipped')); ?>',
			cycle: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('FactureSituationMigrationCycle')); ?>',
			error: '<?php echo dol_escape_js($langs->transnoentitiesnoconv('Error')); ?>'
		},

		// State
		lastCycleRef: 0,
		totalProcessed: 0,
		totalToProcess: 0,
		totalCorrected: 0,
		totalSkipped: 0,
		totalErrors: 0,

		/**
		 * Initialize the correct-all button
		 */
		init: function() {
			var self = this;
			$('#btn-correct-all').on('click', function(e) {
				e.preventDefault();
				self.start();
			});
		},

		/**
		 * Start the bulk correction process
		 */
		start: function() {
			if (!confirm(this.trans.confirmCorrect)) {
				return;
			}

			$('#btn-correct-all').addClass('butActionRefused').removeClass('butAction');
			$('#correct-progress').show();

			this.lastCycleRef = 0;
			this.totalProcessed = 0;
			this.totalToProcess = 0;
			this.totalCorrected = 0;
			this.totalSkipped = 0;
			this.totalErrors = 0;

			this.processBatch();
		},

		/**
		 * Process one batch of cycles via AJAX
		 */
		processBatch: function() {
			var self = this;

			$.ajax({
				url: this.ajaxUrl,
				type: 'GET',
				data: {
					last_cycle_ref: this.lastCycleRef,
					batch_size: this.batchSize
				},
				dataType: 'json',
				success: function(data) {
					self.handleBatchResult(data);
				},
				error: function(xhr) {
					$.jnotify(self.trans.error + ': ' + xhr.statusText, 'error', true);
					$('#correct-bar').removeClass('progress-bar-success').addClass('progress-bar-danger');
					$('#btn-correct-all').addClass('butAction').removeClass('butActionRefused');
				}
			});
		},

		/**
		 * Handle the result of a batch
		 * @param {object} data - JSON response from the AJAX endpoint
		 */
		handleBatchResult: function(data) {
			this.totalProcessed += data.processed;
			this.totalCorrected += data.corrected;
			this.totalSkipped += data.skipped;
			if (this.totalToProcess === 0) {
				this.totalToProcess = this.totalProcessed + data.remaining;
			}
			this.lastCycleRef = data.last_cycle_ref;

			// Display errors via jnotify (sticky)
			if (data.errors && data.errors.length > 0) {
				this.totalErrors += data.errors.length;
				var error_msg = '';
				for (var i = 0; i < data.errors.length; i++) {
					error_msg += (error_msg ? '<br>' : '') + data.errors[i];
				}
				$.jnotify(error_msg, 'error', true);
			}

			// Update progress bar
			var pct = this.totalToProcess > 0 ? Math.round((this.totalProcessed / this.totalToProcess) * 100) : 0;
			$('#correct-bar').css('width', pct + '%').attr('aria-valuenow', pct);
			$('#correct-progress-bar').attr('title', pct + '%');
			$('#correct-status').text(this.totalProcessed + ' / ' + this.totalToProcess + ' (' + pct + '%) - '
				+ this.totalCorrected + ' ' + this.trans.corrected + ', ' + this.totalSkipped + ' ' + this.trans.skipped);

			if (data.done) {
				this.onComplete();
			} else {
				this.processBatch();
			}
		},

		/**
		 * Called when all batches are done
		 */
		onComplete: function() {
			var msg = this.trans.correctDone + ' (' + this.totalCorrected + ' ' + this.trans.corrected
				+ ', ' + this.totalSkipped + ' ' + this.trans.skipped;
			if (this.totalErrors > 0) {
				msg += ', ' + this.totalErrors + ' ' + this.trans.error + 's';
			}
			msg += ')';

			$.jnotify(msg, this.totalErrors > 0 ? 'error' : 'ok', this.totalErrors > 0);
			$('#correct-status').html('<strong>' + msg + '</strong>');

			if (this.totalErrors > 0) {
				// Errors occurred: don't reload (errors would be lost), re-enable button
				$('#correct-bar').removeClass('progress-bar-success').addClass('progress-bar-warning');
				$('#btn-correct-all').addClass('butAction').removeClass('butActionRefused');
			} else {
				// Reload page to refresh the list with the new statuses
				setTimeout(function() {
					location.reload();
				}, 2000);
			}
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		if ($('#btn-reverify').length) {
			FactureSituationMigrationVerify.init();
		}
		if ($('#btn-step3').length) {
			FactureSituationMigrationStep3.init();
		}
		if ($('#btn-correct-all').length) {
			FactureSituationMigrationCorrect.init();
		}
	});

})(jQuery);