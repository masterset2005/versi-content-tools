(function($) {
	'use strict';

	if ( typeof versiProcessing === 'undefined' ) return;

	const $modeBtns = $('.versi-start-btn');
	const $warning = $('.versi-overwrite-warning');
	const $processingArea = $('#versi-processing-area');
	const $resumeNotice = $('#versi-resume-notice');
	const $stopLink = $('#versi-stop-link');
	const $status = $('#versi-status');
	const $results = $('#versi-results');
	const $orText = $('.versi-or-text');
	const $resumeText = $('#versi-resume-text');
	const catId = 0;
	const batchSize = parseInt(versiProcessing.batchSize, 10);
	const fetchSize = Math.min(batchSize * 4, 200);
	const workload = versiProcessing.workload;
	let running = false;
	let mode = '';
	let total = 0;
	let done = 0;
	let offset = 0;
	let resultsData = [];
	let stopRequested = false;
	let startTime = 0;
	let batchTimes = [];
	let etaTimer = null;

	// Check for saved job
	$.ajax({
		url: ajaxurl,
		method: 'POST',
		data: {
			action: 'versi_load_job',
			_ajax_nonce: versiProcessing.nonce,
		},
		success: function(response) {
			if (!response.success || !response.data.exists) return;
			var job = response.data.data;
			if (job.workload !== workload) return;

			mode = job.mode;
			offset = job.offset;
			total = job.total;
			done = job.done;

			$resumeNotice.show();
			var msg = versiProcessing.l10n.pausedJobMsg;
			$resumeText.text(msg.replace('%1$s', mode).replace('%2$s', done).replace('%3$s', total));
		}
	});

	$('#versi-resume-btn').on('click', function() {
		$resumeNotice.hide();
		$processingArea.show();
		$('#versi-processing-area h2').focus();
		$orText.hide();
		$status.text(versiProcessing.l10n.resuming);
		$stopLink.show();
		running = true;
		startTime = Date.now();
		batchTimes = [];
		if (etaTimer) clearInterval(etaTimer);
		etaTimer = setInterval(updateEtaStatus, 5000);
		fetchBatch();
	});

	$('#versi-dismiss-btn').on('click', function() {
		$resumeNotice.hide();
		dismissSavedJob();
	});

	function saveJobState(status) {
		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'versi_save_job',
				_ajax_nonce: versiProcessing.nonce,
				workload: workload,
				mode: mode,
				offset: offset,
				total: total,
				done: done,
				status: status,
			},
		});
	}

	function dismissSavedJob() {
		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'versi_dismiss_job',
				_ajax_nonce: versiProcessing.nonce,
			},
		});
	}

	$modeBtns.on('click', function() {
		const $btn = $(this);
		mode = $btn.data('mode');

		if ('bulk_review' === mode && !confirm(versiProcessing.l10n.reviewConfirm)) {
			return;
		}

		if ($btn.data('destructive') && !confirm(versiProcessing.l10n.overwriteConfirm)) {
			return;
		}

		dismissSavedJob();
		$processingArea.show();
		$('#versi-processing-area h2').focus();
		$resumeNotice.hide();
		$orText.hide();
		$results.empty();
		$status.text(versiProcessing.l10n.starting);
		$stopLink.show();
		$('#versi-pause-btn').show();
		resultsData = [];
		running = true;
		stopRequested = false;
		done = 0;
		offset = 0;
		startTime = Date.now();
		batchTimes = [];
		if (etaTimer) clearInterval(etaTimer);
		etaTimer = setInterval(updateEtaStatus, 5000);

		if ('bulk_review' === mode) {
			fetchReviewBatch();
		} else {
			fetchBatch();
		}
	});

	let isPaused = false;
	$('#versi-pause-btn').on('click', function() {
		isPaused = !isPaused;
		const $btn = $(this);
		if (isPaused) {
			$btn.html('<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M14.752 11.168l-3.197-2.132A1 1 0 0010 9.87v4.263a1 1 0 001.555.832l3.197-2.132a1 1 0 000-1.664z"/></svg> ' + versiProcessing.l10n.resume);
		} else {
			$btn.html('<svg aria-hidden="true" focusable="false" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M10 9v6m4-6v6"/></svg> ' + versiProcessing.l10n.pause);
			if (running && !stopRequested) {
				if ('bulk_review' === mode) fetchReviewBatch();
				else fetchBatch();
			}
		}
	});

	$stopLink.on('click', function(e) {
		e.preventDefault();
		if (!running) return;
		stopRequested = true;
		running = false;
		let ok = 0, errs = 0;
		resultsData.forEach(r => {
			if (r.status === 'success') ok++;
			else if (r.status === 'error') errs++;
		});
		const summary = versiProcessing.l10n.stopped + ' ' + done + ' / ' + total +
			' (ok: ' + ok + (errs > 0 ? ', ' + versiProcessing.l10n.errors + errs : '') + ')';
		$status.text(summary);
		saveJobState('paused');
		if (etaTimer) clearInterval(etaTimer);
	});

	function downloadResultsCSV() {
		if (resultsData.length === 0) return;
		let csv = 'ID,Title,Status,Previous Value,Generated Value,Error/Reason,Changed\n';
		resultsData.forEach(function(r) {
			csv += '"' + (r.id || '') + '","' + (r.title || '').replace(/"/g, '""') + '","' + (r.status || '') + '","' + (r.previous || '').replace(/"/g, '""') + '","' + (r.generated || '').replace(/"/g, '""') + '","' + ((r.error || r.reason || '') + '').replace(/"/g, '""') + '","' + (r.changed ? 'Yes' : 'No') + '"\n';
		});
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'versi-' + workload + '-' + new Date().toISOString().slice(0,19).replace(/[:]/g, '-') + '.csv';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(link.href);
	}

	function saveResults() {
		if (resultsData.length === 0) return;
		$.post(ajaxurl, {
			action: 'versi_save_results',
			_ajax_nonce: versiProcessing.nonce,
			workload: workload,
			mode: mode,
			results: resultsData,
		});
	}

	function updateSummary() {
		if (etaTimer) clearInterval(etaTimer);
		let ok = 0, errs = 0;
		resultsData.forEach(r => {
			if (r.status === 'success') ok++;
			else if (r.status === 'error') errs++;
		});
		$status.text(versiProcessing.l10n.complete + ' ' + ok + ' ok' + (errs > 0 ? ', ' + errs + ' ' + versiProcessing.l10n.errors : ''));
		saveResults();
		dismissSavedJob();
		const exportBtn = $('<button type="button" class="button" style="margin-top:10px;">' + versiProcessing.l10n.downloadCsv + '</button>');
		exportBtn.on('click', downloadResultsCSV);
		$('#versi-processing-area').append(exportBtn);
	}

	function getActionName(prefix) {
		if (workload === 'alt') return 'versi_alt_' + prefix;
		if (workload === 'excerpt') return 'versi_excerpt_' + prefix;
		if (workload === 'content') return 'versi_content_' + prefix;
		return 'versi_seo_' + prefix;
	}

	function truncateText(text, maxLen) {
		if (!text || text.length <= maxLen) return text;
		return text.substring(0, maxLen) + '\u2026';
	}

	function makeBodyText(r, full) {
		const maxLen = full ? Infinity : 150;
		const label = r.title ? r.title + ' ' : '';
		if (r.status === 'success') {
			const cur = r.previous ? truncateText(r.previous, maxLen) : '';
			const gen = truncateText(r.generated || '', maxLen);
			if (r.changed && cur) {
				return '#' + r.id + ' ' + label + '\u2192 REPLACED\n  was: "' + cur + '"\n  now: "' + gen + '"';
			} else if (r.changed) {
				return '#' + r.id + ' ' + label + '+ ADDED\n  value: "' + gen + '"';
			} else {
				return '#' + r.id + ' ' + label + '\u2713 KEPT\n  value: "' + gen + '"';
			}
		} else if (r.status === 'error') {
			return '#' + r.id + ' ' + label + '\u2717 ' + (r.error || 'Error');
		}
		return '#' + r.id + ' ' + label + '\u2014 ' + (r.reason || 'Skipped');
	}

	function createEntryElement(r) {
		const $entry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');

		if (workload === 'alt') {
			const thumbUrl = r.thumbnail || '';
			if (thumbUrl) {
				const $img = $('<span style="width:40px;height:40px;flex-shrink:0;border-radius:2px;display:inline-block;overflow:hidden;">').append(
					$('<img>').css({ width: '40px', height: '40px', objectFit: 'cover' }).prop('src', thumbUrl)
				);
				$entry.append($img);
			} else {
				$entry.append('<span style="width:40px;height:40px;flex-shrink:0;background:#f0f0f1;border-radius:2px;display:inline-block;"></span>');
			}
		}

		const $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');

		if (r.status === 'success') {
			const curFull = r.previous || '';
			const genFull = r.generated || '';
			const needsExpand = curFull.length > 150 || genFull.length > 150;
			const shortText = makeBodyText(r, false);
			$body.text(shortText);

			if (needsExpand) {
				$body.append(' <a href="#" class="versi-expand" data-full="' + encodeURIComponent(makeBodyText(r, true)) + '" data-short="' + encodeURIComponent(shortText) + '" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show more</a>');
			}

			if (r.changed) {
				$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			} else {
				$entry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
			}
		} else if (r.status === 'error' && r.rate_limited) {
			$body.text(makeBodyText(r, false));
			$entry.css('background', '#fef8ee').css('border-left', '3px solid #dba617');
		} else if (r.status === 'error') {
			$body.text(makeBodyText(r, false));
			$entry.css('background', '#fcf0f1').css('border-left', '3px solid #d63638');
		} else {
			$body.text(makeBodyText(r, false));
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
		const $entry = createEntryElement(r);
		$results.append($entry);
		$results.scrollTop($results[0].scrollHeight);
	}

	function formatEta(ms) {
		if (ms <= 0) return '';
		const totalSec = Math.ceil(ms / 1000);
		let min = Math.floor(totalSec / 60);
		const sec = totalSec % 60;
		if (min >= 60) {
			const hr = Math.floor(min / 60);
			min = min % 60;
			return hr + 'h ' + min + 'm ' + versiProcessing.l10n.remaining;
		}
		if (min > 0) return min + 'm ' + sec + 's ' + versiProcessing.l10n.remaining;
		return sec + 's ' + versiProcessing.l10n.remaining;
	}

	function updateEtaStatus() {
		const remaining = total - done;
		let eta = '';
		if (batchTimes.length > 0 && remaining > 0) {
			const avgMs = batchTimes.reduce((a, b) => a + b, 0) / batchTimes.length;
			const itemsPerMs = fetchSize / avgMs;
			const etaMs = Math.round(remaining / itemsPerMs);
			eta = ' \u2014 ' + formatEta(etaMs);
		}
		$status.text(versiProcessing.l10n.processing + ' ' + (done + 1) + ' / ' + total + eta);
	}

	function processId(id, cb, retryCount) {
		if (retryCount === undefined) retryCount = 0;
		const maxRetries = 5;
		let retrying = false;
		updateEtaStatus();

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('process_single'),
				_ajax_nonce: versiProcessing.nonce,
				id: id,
				mode: mode,
			},
			success(response) {
				if (stopRequested) return;
				const r = response.data;
				if (r.rate_limited && retryCount < maxRetries) {
					retrying = true;
					const waitMs = Math.max((parseFloat(r.retry_after) || 5) * 1000, 5000);
					$status.text(versiProcessing.l10n.rateLimited + ' #' + id + ' ' + versiProcessing.l10n.in + ' ' + Math.ceil(waitMs / 1000) + 's...');
					setTimeout(() => processId(id, cb, retryCount + 1), waitMs);
					return;
				}
				if (r.rate_limited) {
					r.error = (r.error || versiProcessing.l10n.aiFailed) + ' ' + versiProcessing.l10n.rateLimitExceeded;
				}
				resultsData.push(r);
				addEntry(r);
			},
			error() {
				if (stopRequested) return;
				resultsData.push({ id: id, status: 'error' });
				addEntry({ id: id, title: '', status: 'error', error: versiProcessing.l10n.requestFailed });
			},
			complete() {
				if (stopRequested) return;
				if (retrying) return;
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

		const batchStart = Date.now();
		const origCb = cb;
		cb = function () {
			const elapsed = Date.now() - batchStart;
			batchTimes.push(elapsed);
			if (batchTimes.length > 30) batchTimes.shift();
			origCb();
		};

		let i = 0;
		let active = 0;
		const maxConcurrent = Math.min(batchSize, ids.length);

		function startNext() {
			while (active < maxConcurrent && i < ids.length && running) {
				const idx = i++;
				active++;
				processId(ids[idx], () => {
					active--;
					if (i < ids.length && running) {
						startNext();
					} else if (active === 0) {
						cb();
					}
				});
			}
			if (active === 0) {
				cb();
			}
		}
		startNext();
	}

	function fetchBatch() {
		if (!running) {
			return;
		}

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('get_ids'),
				_ajax_nonce: versiProcessing.nonce,
				mode: mode,
				catId: catId,
				offset: offset,
				batch: fetchSize,
			},
			success(response) {
				if (stopRequested) return;

				const d = response.data;
				total = d.total;
				const ids = d.ids || [];

				if (ids.length === 0) {
					running = false;
					updateSummary();
					return;
				}

				processBatch(ids, () => {
					if (stopRequested) return;
					if (isPaused) {
						$status.text(versiProcessing.l10n.paused);
						return;
					}
					offset += ids.length;
					saveJobState('paused');
					setTimeout(fetchBatch, 100);
				});
			},
			error() {
				if (stopRequested) return;
				running = false;
				$status.text(versiProcessing.l10n.failedFetch);
			},
		});
	}

	const reviewBatchSize = 30;

	function fetchReviewBatch() {
		if (!running) return;

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('bulk_review'),
				_ajax_nonce: versiProcessing.nonce,
				offset: offset,
				batch: reviewBatchSize,
			},
			success(response) {
				if (stopRequested) return;

				const d = response.data;
				total = d.total;
				const items = d.items || [];

				if (items.length === 0) {
					running = false;
					updateReviewSummary();
					return;
				}

				items.forEach(r => {
					resultsData.push(r);
					addReviewEntry(r);
				});

				done += items.length;
				offset += items.length;
				saveJobState('paused');
				if (isPaused) {
					$status.text(versiProcessing.l10n.paused);
					return;
				}
				setTimeout(fetchReviewBatch, 100);
			},
			error() {
				if (stopRequested) return;
				running = false;
				$status.text(versiProcessing.l10n.failedReview);
			},
		});
	}

	function addReviewEntry(r) {
		const $entry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');
		const label = r.title ? r.title + ' ' : '';
		const excerpt = r.alt || r.excerpt || '';
		const excerptShort = excerpt.length > 100 ? excerpt.substring(0, 100) + '\u2026' : excerpt;

		if (r.status === 'good') {
			$entry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
			const $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');
			$body.text('#' + r.id + ' ' + label + '\u2713 GOOD' + (excerptShort ? '\n  "' + excerptShort + '"' : ''));
			$entry.append($body);
		} else if (r.status === 'bad') {
			$entry.css('background', '#fcf0f1').css('border-left', '3px solid #d63638');
			const $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');
			$body.text('#' + r.id + ' ' + label + '\u2717 BAD\n  reason: ' + (r.reason || 'Unknown') + '\n  "' + excerptShort + '"');
			$entry.append($body);
			$entry.append(
				'<button class="versi-review-redo-btn" data-id="' + r.id + '" style="flex-shrink:0;font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#b32d2e;">regenerate</button>'
			);
		} else {
			$entry.css('background', '#f0f6fc').css('border-left', '3px solid #2271b1');
			const $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');
			$body.text('#' + r.id + ' ' + label + '\u2139 ' + (r.reason || ''));
			$entry.append($body);
		}

		$results.append($entry);
		$results.scrollTop($results[0].scrollHeight);
	}

	function downloadReviewCSV() {
		if (resultsData.length === 0) return;
		let csv = 'ID,Title,Status,Content,Reason\n';
		resultsData.forEach(function(r) {
			csv += '"' + (r.id || '') + '","' + (r.title || '').replace(/"/g, '""') + '","' + (r.status || '') + '","' + ((r.alt || r.excerpt || '') + '').replace(/"/g, '""') + '","' + (r.reason || '').replace(/"/g, '""') + '"\n';
		});
		const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
		const link = document.createElement('a');
		link.href = URL.createObjectURL(blob);
		link.download = 'versi-' + workload + '-review-' + new Date().toISOString().slice(0,19).replace(/[:]/g, '-') + '.csv';
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
		URL.revokeObjectURL(link.href);
	}

	function updateReviewSummary() {
		if (etaTimer) clearInterval(etaTimer);
		let good = 0, bad = 0, info = 0;
		resultsData.forEach(r => {
			if (r.status === 'good') good++;
			else if (r.status === 'bad') bad++;
			else info++;
		});
		$status.text(versiProcessing.l10n.reviewComplete + ' ' + good + ' good, ' + bad + ' bad' + (info > 0 ? ', ' + info + ' info' : ''));
		saveResults();
		dismissSavedJob();
		const exportBtn = $('<button type="button" class="button" style="margin-top:10px;">' + versiProcessing.l10n.downloadCsv + '</button>');
		exportBtn.on('click', downloadReviewCSV);
		$('#versi-processing-area').append(exportBtn);
	}

	// Redo / Undo / Review redo
	$results.on('click', '.versi-redo-btn', function() {
		const $btn = $(this);
		const $entry = $btn.closest('.versi-entry');
		const id = $entry.data('attachment-id');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$entry.css('opacity', '0.5');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('process_single'),
				_ajax_nonce: versiProcessing.nonce,
				id: id,
				mode: mode,
			},
			success(response) {
				$entry.replaceWith(createEntryElement(response.data));
			},
			error() {
				$btn.text('redo').prop('disabled', false);
				$entry.css('opacity', '1');
			},
		});
	});

	$results.on('click', '.versi-undo-btn', function() {
		const $btn = $(this);
		const $entry = $btn.closest('.versi-entry');
		const id = $btn.data('attachment-id');
		const prev = $btn.data('previous');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$entry.css('opacity', '0.5');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('undo'),
				_ajax_nonce: versiProcessing.nonce,
				id: id,
				alt: prev,
			},
			success(response) {
				const r = response.data;
				$entry.css('opacity', '1');
				$entry.css('background', '#f6f7f7').css('border-left', '3px solid #c3c4c7');
				$entry.find('.versi-redo-btn').remove();
				$entry.find('.versi-undo-btn').remove();
				$entry.find('div:last').text('#' + r.id + ' (Reverted to: "' + r.alt.substring(0, 100) + '")');
			},
			error() {
				$btn.text('undo').prop('disabled', false);
				$entry.css('opacity', '1');
			},
		});
	});

	// Review "regenerate" button
	$results.on('click', '.versi-review-redo-btn', function() {
		const $btn = $(this);
		const $entry = $btn.closest('.versi-entry');
		const id = $btn.data('id');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$entry.css('opacity', '0.5');

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: getActionName('process_single'),
				_ajax_nonce: versiProcessing.nonce,
				id: id,
				mode: 'regenerate',
			},
			success(response) {
				const r = response.data;
				const $newEntry = $('<div class="versi-entry" style="display:flex;align-items:flex-start;gap:8px;padding:4px 6px;margin:1px 0;border-radius:2px;">');
				$newEntry.css('background', '#edfaef').css('border-left', '3px solid #00a32a');
				const $body = $('<div style="flex:1;white-space:pre-wrap;word-break:break-word;">');
				const gen = r.generated || '';
				$body.text('#' + r.id + ' ' + (r.title || '') + ' \u2192 REGENERATED\n  new: "' + (gen.length > 100 ? gen.substring(0, 100) + '\u2026' : gen) + '"');
				$newEntry.append($body);
				$entry.replaceWith($newEntry);
			},
			error() {
				$btn.text('regenerate').prop('disabled', false);
				$entry.css('opacity', '1');
			},
		});
	});

	$results.on('click', '.versi-expand', function(e) {
		e.preventDefault();
		const $link = $(this);
		const $body = $link.parent();
		const isExpanded = $link.data('expanded');
		if (!isExpanded) {
			$body.empty();
			$body.text(decodeURIComponent($link.data('full')));
			const $newLink = $('<a href="#" class="versi-expand" data-full="' + $link.data('full') + '" data-expanded="1" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show less</a>');
			$body.append(' ', $newLink);
		} else {
			$body.empty();
			$body.text(decodeURIComponent($link.data('short')));
			const $newLink = $('<a href="#" class="versi-expand" data-full="' + $link.data('full') + '" data-short="' + $link.data('short') + '" style="font-size:11px;color:#2271b1;text-decoration:underline;white-space:nowrap;">show more</a>');
			$body.append(' ', $newLink);
		}
	});

})(jQuery);
