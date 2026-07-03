(function($) {
	'use strict';

	if ( typeof versiBackground === 'undefined' ) return;

	// Processing page header: cancel + poll
	$('#versi-bg-cancel').on('click', function() {
		$.post(ajaxurl, {
			action: 'versi_cancel_job',
			_ajax_nonce: versiBackground.cancelNonce,
		}).always(function() {
			location.reload();
		});
	});

	function pollHeader() {
		$.post(ajaxurl, {
			action: 'versi_job_status',
			_ajax_nonce: versiBackground.statusNonce,
		}, function(r) {
			if (r.success && r.data) {
				if (r.data.is_running) {
					$('#versi-bg-progress').text(r.data.processed + ' / ' + r.data.total);
					$('#versi-bg-stall-warn').toggle(r.data.stalled === true);
					setTimeout(pollHeader, 3000);
				} else {
					location.reload();
				}
			}
		});
	}
	if ($('#versi-bg-progress').length) {
		setTimeout(pollHeader, 3000);
	}

	// Background tab: poll + cancel (tab version)
	$('#versi-bg-cancel-tab').on('click', function() {
		$.post(ajaxurl, {
			action: 'versi_cancel_job',
			_ajax_nonce: versiBackground.cancelNonce,
		});
		$(this).prop('disabled', true).text(versiBackground.l10n.cancelling);
	});

	function pollTab() {
		$.post(ajaxurl, {
			action: 'versi_job_status',
			_ajax_nonce: versiBackground.statusNonce,
		}, function(resp) {
			if (resp.success && resp.data) {
				$('#versi-bg-progress-tab').text(resp.data.processed + ' / ' + resp.data.total);
				$('#versi-bg-stall-warn-tab').toggle(resp.data.stalled === true);
				if (resp.data.is_running) {
					setTimeout(pollTab, 3000);
				} else {
					$('#versi-bg-tab .notice-info').removeClass('notice-info').addClass('notice-success')
						.append('<p><em>' + versiBackground.l10n.complete + '</em></p>');
				}
			}
		});
	}
	if ($('#versi-bg-progress-tab').length) {
		pollTab();
	}

	// Background tab: start new job
	$('.versi-bg-start-btn').on('click', function() {
		const $btn = $(this);
		const btnMode = $btn.data('mode');
		const btnWorkload = $btn.data('workload');
		if (!confirm(versiBackground.l10n.startConfirm)) {
			return;
		}
		$.post(ajaxurl, {
			action: 'versi_create_job',
			_ajax_nonce: versiBackground.nonce,
			mode: btnMode,
			workload: btnWorkload,
		});
		$btn.prop('disabled', true).text(versiBackground.l10n.started);
		$('.versi-bg-start-btn').not($btn).prop('disabled', true);
	});

})(jQuery);
