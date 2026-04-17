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

require_once '../../main.inc.php';

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
					$('#reverify-bar').addClass('fsm-progress-error');
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
			$('#reverify-bar').css('width', pct + '%');
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
				$('#reverify-bar').addClass('fsm-progress-warning');
				$('#btn-reverify').addClass('butAction').removeClass('butActionRefused');
			} else {
				// All OK: reload page to refresh the list
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
	});

})(jQuery);