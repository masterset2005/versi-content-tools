(function($) {
	'use strict';

	if ( typeof versiAudit === 'undefined' ) return;

	$('#versi-audit-btn').on('click', function() {
		const $btn = $(this).prop('disabled', true);
		const $results = $('#versi-audit-results');
		$results.html('<div class="versi-scan-status"><span class="versi-scan-spinner"></span>' + versiAudit.l10n.initializing + '</div>');

		$.post(ajaxurl, {
			action: 'versi_run_audit',
			_ajax_nonce: versiAudit.nonce,
		}, function(resp) {
			if (!resp.success) {
				$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">' + (resp.data?.message || 'Unknown error') + '</div>');
				$btn.prop('disabled', false);
				return;
			}

			if (resp.data.complete) {
				$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">' + versiAudit.l10n.noneFound + '</div>');
				$btn.prop('disabled', false);
				return;
			}

			processAuditBatch(0, resp.data.total, []);
		}).fail(function(jqXHR) {
			var msg = versiAudit.l10n.failed;
			if (jqXHR.responseJSON && jqXHR.responseJSON.data && jqXHR.responseJSON.data.message) {
				msg = jqXHR.responseJSON.data.message;
			} else if (jqXHR.status === 500) {
				msg = versiAudit.l10n.serverError;
			} else if (jqXHR.status === 504 || jqXHR.status === 502) {
				msg = versiAudit.l10n.timeoutError;
			} else if (jqXHR.status) {
				msg = versiAudit.l10n.statusError + jqXHR.status;
			}
			$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">' + msg + '</div>');
			$btn.prop('disabled', false);
		});
	});

	let auditResults = [];

	function processAuditBatch(offset, total, accumulatedResults) {
		const $results = $('#versi-audit-results');
		const $btn = $('#versi-audit-btn');

		$results.html('<div class="versi-scan-status"><span class="versi-scan-spinner"></span>' + versiAudit.l10n.scanning + ' ' + offset + ' / ' + total + '...</div>');

		$.post(ajaxurl, {
			action: 'versi_audit_progress',
			_ajax_nonce: versiAudit.nonce,
			offset: offset,
			limit: versiAudit.batchSize,
		}, function(resp) {
			if (!resp.success) {
				$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">' + (resp.data?.message || 'Scan failed') + '</div>');
				$btn.prop('disabled', false);
				return;
			}

			const newResults = resp.data.results;
			const combined = accumulatedResults.concat(newResults);

			if (resp.data.complete) {
				auditResults = combined;
				if (combined.length > 0) {
					const totalPosts = new Set(combined.map(function(i) { return i.post_id; })).size;
					var html = '<div class="versi-audit-summary">' + versiAudit.l10n.found + ' <strong>' + combined.length + '</strong> ' + versiAudit.l10n.unlinkedImages + ' <strong>' + totalPosts + '</strong> ' + versiAudit.l10n.acrossPosts + '.</div>';
					html += '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;gap:10px;">';
					html += '	<div style="display:flex;gap:8px;align-items:center;">';
					html += '		<button class="button" id="versi-bulk-link-btn">' + versiAudit.l10n.linkSelected + '</button>';
					html += '		<button class="button" id="versi-audit-export-csv">' + versiAudit.l10n.exportCsv + '</button>';
					html += '	</div>';
					html += '	<div>';
					html += '		<select id="versi-audit-filter" style="font-size:12px;padding:2px 5px;border-radius:4px;">';
					html += '			<option value="all">' + versiAudit.l10n.allResults + '</option>';
					html += '			<option value="verified">' + versiAudit.l10n.verifiedOnly + '</option>';
					html += '		</select>';
					html += '	</div>';
					html += '</div>';
					html += '<table class="wp-list-table widefat fixed striped"><thead><tr><th style="width:40px;"><input type="checkbox" id="versi-select-all"></th><th>' + versiAudit.l10n.image + '</th><th>' + versiAudit.l10n.foundIn + '</th><th>' + versiAudit.l10n.action + '</th></tr></thead><tbody>';
					combined.forEach(function(item) {
						html += '<tr><td><input type="checkbox" class="versi-link-check" data-att="' + item.attachment_id + '" data-post="' + item.post_id + '"></td><td><a href="' + item.att_edit_link + '" target="_blank">#' + item.attachment_id + '</a><br><small style="color:#666;">' + item.att_path + '</small></td><td><a href="' + item.post_edit_link + '" target="_blank">' + item.post_title + '</a></td><td><button class="button button-small versi-link-btn" data-att="' + item.attachment_id + '" data-post="' + item.post_id + '">' + versiAudit.l10n.link + '</button></td></tr>';
					});
					html += '</tbody></table>';
					$results.html(html);
				} else {
					$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">' + versiAudit.l10n.noneFound + '</div>');
				}
				$btn.prop('disabled', false);
				if (auditResults.length > 0) {
					$.post(ajaxurl, {
						action: 'versi_save_results',
						_ajax_nonce: versiAudit.processNonce,
						workload: 'auditor',
						mode: 'audit',
						results: auditResults,
					});
				}
			} else {
				processAuditBatch(resp.data.scanned, total, combined);
			}
		}).fail(function(jqXHR) {
			$results.html('<div class="versi-audit-summary" style="background:#fef2f2;border-color:#fecaca;color:#991b1b;">Request failed: ' + jqXHR.status + '</div>');
			$btn.prop('disabled', false);
		});
	}

	$(document).on('click', '#versi-audit-export-csv', function() {
		if (auditResults.length === 0) return;
		var csv = 'Attachment ID,Attachment URL,Path,Post ID,Post Title\n';
		auditResults.forEach(function(item) {
			csv += '"' + (item.attachment_id || '') + '","' + (item.attachment_url || '').replace(/"/g, '""') + '","' + (item.att_path || '').replace(/"/g, '""') + '","' + (item.post_id || '') + '","' + (item.post_title || '').replace(/"/g, '""') + '"\n';
		});
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'versi-auditor-' + new Date().toISOString().slice(0,19).replace(/[:]/g, '-') + '.csv';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(link.href);
	});

	$(document).on('click', '#versi-select-all', function() {
		$('.versi-link-check').prop('checked', $(this).prop('checked'));
	});

	$(document).on('click', '#versi-bulk-link-btn', function() {
		const $checked = $('.versi-link-check:checked');
		if ($checked.length === 0) return;

		const $btn = $(this).prop('disabled', true);
		var toLink = [];
		$checked.each(function() {
			toLink.push({ att: $(this).data('att'), post: $(this).data('post') });
		});

		var processed = 0;
		function processBatch() {
			if (processed >= toLink.length) {
				location.reload();
				return;
			}
			const item = toLink[processed];
			$.post(ajaxurl, {
				action: 'versi_link_attachment',
				_ajax_nonce: versiAudit.linkNonce,
				attachment_id: item.att,
				post_id: item.post
			}, function() {
				processed++;
				processBatch();
			});
		}
		processBatch();
	});

	$(document).on('click', '.versi-link-btn', function() {
		const $btn = $(this);
		$.post(ajaxurl, {
			action: 'versi_link_attachment',
			_ajax_nonce: versiAudit.linkNonce,
			attachment_id: $btn.data('att'),
			post_id: $btn.data('post')
		}, function(resp) {
			if (resp.success) {
				$btn.text(versiAudit.l10n.linked).prop('disabled', true);
			}
		});
	});

})(jQuery);
