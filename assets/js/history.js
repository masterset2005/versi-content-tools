(function($) {
	'use strict';

	if ( typeof versiHistory === 'undefined' ) return;

	$('#versi-clear-history').on('click', function() {
		if (!confirm(versiHistory.l10n.clearConfirm)) return;
		$.post(ajaxurl, {
			action: 'versi_clear_history',
			_ajax_nonce: versiHistory.nonce,
		}, function() { location.reload(); });
	});

	$(document).on('click', '.versi-history-download', function() {
		const $btn = $(this).prop('disabled', true).text('...');
		const runId = $btn.data('run-id');
		$.post(ajaxurl, {
			action: 'versi_get_history_run',
			_ajax_nonce: versiHistory.nonce,
			run_id: runId,
		}, function(resp) {
			if (!resp.success || !resp.data) {
				$btn.prop('disabled', false).text(versiHistory.l10n.downloadCsv);
				return;
			}
			const run = resp.data;
			const results = run.results || [];
			if (results.length === 0) {
				$btn.prop('disabled', false).text(versiHistory.l10n.downloadCsv);
				return;
			}

			let csv = '';
			if (run.workload === 'auditor') {
				csv = 'Attachment ID,Attachment URL,Path,Post ID,Post Title\n';
				results.forEach(function(r) {
					csv += '"' + (r.attachment_id || '') + '","' + (r.attachment_url || '').replace(/"/g, '""') + '","' + (r.att_path || '').replace(/"/g, '""') + '","' + (r.post_id || '') + '","' + (r.post_title || '').replace(/"/g, '""') + '"\n';
				});
			} else if (run.workload === 'review' || run.mode === 'bulk_review') {
				csv = 'ID,Title,Status,Content,Reason\n';
				results.forEach(function(r) {
					csv += '"' + (r.id || '') + '","' + (r.title || '').replace(/"/g, '""') + '","' + (r.status || '') + '","' + ((r.alt || r.excerpt || '') + '').replace(/"/g, '""') + '","' + (r.reason || '').replace(/"/g, '""') + '"\n';
				});
			} else {
				csv = 'ID,Title,Status,Previous Value,Generated Value,Error/Reason,Changed\n';
				results.forEach(function(r) {
					csv += '"' + (r.id || '') + '","' + (r.title || '').replace(/"/g, '""') + '","' + (r.status || '') + '","' + (r.previous || '').replace(/"/g, '""') + '","' + (r.generated || '').replace(/"/g, '""') + '","' + ((r.error || r.reason || '') + '').replace(/"/g, '""') + '","' + (r.changed ? 'Yes' : 'No') + '"\n';
				});
			}

			const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
			const link = document.createElement('a');
			link.href = URL.createObjectURL(blob);
			link.download = 'versi-' + run.workload + '-' + run.id + '.csv';
			document.body.appendChild(link);
			link.click();
			document.body.removeChild(link);
			URL.revokeObjectURL(link.href);
			$btn.prop('disabled', false).text(versiHistory.l10n.downloadCsv);
		});
	});

})(jQuery);
