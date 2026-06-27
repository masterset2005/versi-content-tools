/*global jQuery, versiBulkData */
(function ($) {
	var data = versiBulkData;
	if (!data) return;

	// ----- Look for an active processing area on the dedicated page -----
	var $resultsContainer = $('#versi-results');
	var isProcessingPage = $resultsContainer.length > 0;
	var $statusEl, $notice, $stopLink;

	var mode = data.action || 'missing';
	var workload = data.workload || 'alt';
	var catId = 0;
	var batchSize = data.batchSize || 5;
	var total = 0;
	var done = 0;
	var offset = 0;
	var results = [];
	var running = true;

	// If this is the media library quick-action, no processing area yet.
	if (!isProcessingPage && !data.action) {
		return;
	}

	// ---------- Build UI ----------
	function getActionLabel() {
		if (workload === 'alt') {
			if (mode === 'missing') return 'Fill Missing Alt';
			if (mode === 'review') return 'Review & Improve';
			return 'Regenerate Alt';
		}
		if (mode === 'missing') return 'Generate Missing Excerpts';
		return 'Improve Excerpts';
	}

	if (isProcessingPage) {
		$statusEl = $('#versi-status');
		$stopLink = $('#versi-stop-link');
	} else {
		// Media library inline notice.
		var actionLabel = getActionLabel();
		$stopLink = $('<a href="#" class="versi-stop-link" style="color:#d63638;">stop</a>');
		$notice = $(
			'<div class="notice notice-info is-dismissible">' +
				'<p><strong>Versi:</strong> ' + actionLabel + ' — starting... </p>' +
				'<div class="versi-results" style="margin:8px 0 4px;max-height:320px;overflow-y:auto;font-family:monospace;font-size:12px;line-height:1.5;"></div>' +
			'</div>'
		).insertAfter('.wp-header-end');
		$notice.find('p').append($stopLink);
		$resultsContainer = $notice.find('.versi-results');
	}

	function setStatus(text) {
		if (isProcessingPage) {
			$statusEl.text(text);
			$stopLink.show();
		} else {
			$notice.find('p').html('<strong>Versi:</strong> ' + text + ' ');
			$notice.find('p').append($stopLink);
		}
	}

	function hideStopLink() {
		$stopLink.hide();
	}

	function stop(manual) {
		if (!running) return;
		running = false;
		hideStopLink();
		if (manual) {
			var ok = 0, errs = 0;
			results.forEach(function (r) {
				if (r.status === 'success') ok++;
				else if (r.status === 'error') errs++;
			});
			var summary = 'Stopped. ' + done + ' of ' + total + ' processed (' + ok + ' ok';
			if (errs > 0) summary += ', ' + errs + ' errors';
			summary += ')';
			setStatus(summary);
			if (!isProcessingPage) {
				$notice.removeClass('notice-info').addClass('notice-warning');
			}
		}
		cleanUrl();
	}

	if (!isProcessingPage) {
		$notice.on('click', '.notice-dismiss', function () {
			stop(true);
		});
		$notice.on('click', '.versi-stop-link', function (e) {
			e.preventDefault();
			stop(true);
		});
	}

	$(document).on('click', '.versi-stop-link', function (e) {
		e.preventDefault();
		stop(true);
	});

	// ---------- Entry building ----------
	function buildEntry(r) {
		var $entry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');

		if (workload === 'alt') {
			var thumbUrl = r.thumbnail || '';
			if (thumbUrl) {
				$entry.append('<img src="' + thumbUrl + '" style="width:40px;height:40px;object-fit:cover;border-radius:2px;flex-shrink:0;margin-top:2px;">');
			} else {
				$entry.append('<span style="width:40px;height:40px;flex-shrink:0;background:#f0f0f1;border-radius:2px;display:inline-block;"></span>');
			}
		}

		var $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');

		if (r.status === 'success') {
			var cur = r.previous ? r.previous.substring(0, 200) : '';
			var gen = (r.generated || '').substring(0, 200);

			if (r.changed && cur) {
				$body.text('#' + r.id + ' ' + (r.title || '') + ' → REPLACED\n  was: "' + cur + '"\n  now: "' + gen + '"');
				$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			} else if (r.changed) {
				$body.text('#' + r.id + ' ' + (r.title || '') + ' + ADDED\n  value: "' + gen + '"');
				$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			} else {
				$body.text('#' + r.id + ' ' + (r.title || '') + ' ✓ KEPT\n  value: "' + gen + '"');
				$entry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
			}
		} else if (r.status === 'error') {
			$body.text('#' + r.id + ' ' + (r.title || '') + ' ✗ ' + (r.error || 'Error'));
			$entry.css('background', '#fcf0f1').css('border-left', '3px solid #d63638');
		} else {
			$body.text('#' + r.id + ' ' + (r.title || '') + ' — ' + (r.reason || 'Skipped'));
			$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
		}

		$entry.append($body);

		if (r.status === 'success' && r.previous !== undefined) {
			$entry.append(
				'<button class="versi-redo-btn" data-attachment-id="' + r.id + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">redo</button>' +
				'<button class="versi-undo-btn" data-attachment-id="' + r.id + '" data-previous="' + (r.previous || '').replace(/"/g, '&quot;') + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;">undo</button>'
			);
		}

		$entry.data('attachment-id', r.id);
		return $entry;
	}

	function addEntry(r) {
		var $entry = buildEntry(r);
		$resultsContainer.append($entry);
		$resultsContainer.scrollTop($resultsContainer[0].scrollHeight);
	}

	// ---------- Redo / Undo ----------
	$(document).on('click', '.versi-redo-btn', function () {
		var $btn = $(this);
		var $entry = $btn.closest('.versi-entry');
		var id = $entry.data('attachment-id');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$entry.css('opacity', '0.5');

		var actionName = workload === 'alt' ? 'versi_alt_process_single' : 'versi_excerpt_process_single';
		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: actionName,
				nonce: data.nonce,
				id: id,
				mode: mode,
			},
			success: function (response) {
				var r = response.data;
				var $newEntry = buildEntry(r);
				$entry.replaceWith($newEntry);
			},
			error: function () {
				$btn.text('redo').prop('disabled', false);
				$entry.css('opacity', '1');
			},
		});
	});

	$(document).on('click', '.versi-undo-btn', function () {
		var $btn = $(this);
		var $entry = $btn.closest('.versi-entry');
		var id = $btn.data('attachment-id');
		var prev = $btn.data('previous');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$entry.css('opacity', '0.5');

		var actionName = workload === 'alt' ? 'versi_alt_undo' : 'versi_excerpt_undo';
		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: actionName,
				nonce: data.nonce,
				id: id,
				alt: prev,
			},
			success: function (response) {
				var r = response.data;
				$entry.css('opacity', '1');
				$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
				$entry.find('.versi-redo-btn').remove();
				$entry.find('.versi-undo-btn').remove();
				$entry.find('div:last').text('#' + r.id + ' (Reverted to: "' + r.alt.substring(0, 100) + '")');
			},
			error: function () {
				$btn.text('undo').prop('disabled', false);
				$entry.css('opacity', '1');
			},
		});
	});

	// ---------- Batch processing ----------
	function updateSummary() {
		hideStopLink();
		var ok = 0, errs = 0;
		results.forEach(function (r) {
			if (r.status === 'success') ok++;
			else if (r.status === 'error') errs++;
		});
		var summary = 'Complete. ' + ok + ' ok';
		if (errs > 0) summary += ', ' + errs + ' errors';
		setStatus(summary);
		if (!isProcessingPage) {
			$notice.removeClass('notice-info').addClass(errs > 0 ? 'notice-warning' : 'notice-success');
		}
	}

	function processId(id, cb) {
		setStatus(getActionLabel() + ' — ' + (done + 1) + ' / ' + total + '...');

		var actionName = workload === 'alt' ? 'versi_alt_process_single' : 'versi_excerpt_process_single';

		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: actionName,
				nonce: data.nonce,
				id: id,
				mode: mode,
			},
			success: function (response) {
				if (!running) return;
				var r = response.data;
				results.push(r);
				addEntry(r);
			},
			error: function () {
				if (!running) return;
				results.push({ id: id, status: 'error' });
				addEntry({ id: id, title: '', status: 'error', error: 'Request failed' });
			},
			complete: function () {
				done++;
				cb();
			},
		});
	}

	function processBatch(ids, cb) {
		if (!running || ids.length === 0) {
			cb();
			return;
		}

		var i = 0;
		function nextInBatch() {
			if (!running || i >= ids.length) {
				cb();
				return;
			}
			processId(ids[i], function () {
				i++;
				setTimeout(nextInBatch, 300);
			});
		}
		nextInBatch();
	}

	function fetchBatch() {
		if (!running) return;

		var actionName = workload === 'alt' ? 'versi_alt_get_ids' : 'versi_excerpt_get_ids';

		$.ajax({
			url: data.ajaxUrl,
			method: 'POST',
			data: {
				action: actionName,
				_ajax_nonce: data.nonce,
				mode: mode,
				catId: catId,
				offset: offset,
				batch: batchSize,
			},
			success: function (response) {
				if (!running) return;

				var d = response.data;
				total = d.total;
				var ids = d.ids || [];

				if (ids.length === 0) {
					running = false;
					updateSummary();
					cleanUrl();
					return;
				}

				processBatch(ids, function () {
					if (!running) return;
					offset += ids.length;
					setTimeout(fetchBatch, 100);
				});
			},
			error: function () {
				if (!running) return;
				running = false;
				hideStopLink();
				setStatus('Failed to fetch item list.');
				if (!isProcessingPage) {
					$notice.removeClass('notice-info').addClass('notice-error');
				}
				cleanUrl();
			},
		});
	}

	function cleanUrl() {
		if (!window.history.replaceState) return;
		var url = window.location.pathname + window.location.search;
		url = url.replace(/([?&])versi_action=[^&]*&?/g, '$1');
		url = url.replace(/[?&]$/, '');
		window.history.replaceState({}, document.title, url);
	}

	// ---------- Background job buttons ----------
	$(document).on('click', '.autoalt-bg-btn', function () {
		var btnMode = $(this).data('mode');
		var btnWorkload = $(this).data('workload') || 'alt';
		if (!confirm('Start background processing? You can close the browser and check back later.')) {
			return;
		}
		$.post(data.ajaxUrl, {
			action: 'versi_create_job',
			_ajax_nonce: data.nonce,
			mode: btnMode,
			workload: btnWorkload,
		});
		$(this).prop('disabled', true).text('Started');
	});

	fetchBatch();
})(jQuery);
