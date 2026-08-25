(function($) {
	'use strict';

	if ( typeof versiProcessing === 'undefined' ) return;

	const $modeBtns = $('.versi-start-btn');
	const $warning = $('.versi-overwrite-warning');
	const $processingArea = $('#versi-processing-area');
	const $resumeNotice = $('#versi-resume-notice');
	const $stopLink = $('#versi-stop-link');
	const $status = $('#versi-status');
	const $resultsContainer = $('#versi-results');
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
	let currentFilter = 'all';
	let searchQuery = '';
	let reviewResults = [];

	const l10n = versiProcessing.l10n;

	// Build the results table structure on init
	function initResultsTable() {
		if ($resultsContainer.find('.versi-results-header').length) return;

		$resultsContainer.empty().removeClass('versi-results-box').addClass('versi-results-box');

		const header = $('<div class="versi-results-header" id="versi-results-header">');
		header.append('<span class="versi-summary-text" id="versi-summary-text"></span>');
		header.append('<div class="versi-filter-chips" id="versi-filter-chips"></div>');
		$resultsContainer.append(header);

		const scroll = $('<div class="versi-results-scroll">');

		const table = $('<table class="versi-results-table" id="versi-results-table">');
		const thead = $('<thead><tr>' +
			'<th class="versi-col-status">' + l10n.statusSuccess + '</th>' +
			'<th class="versi-col-id">ID</th>' +
			'<th class="versi-col-title">' + l10n.title + '</th>' +
			'<th class="versi-col-prev">' + l10n.previous + '</th>' +
			'<th class="versi-col-new">' + l10n.newValue + '</th>' +
			'<th class="versi-col-actions">' + l10n.actions + '</th>' +
			'</tr></thead>');
		const tbody = $('<tbody id="versi-results-body">');
		table.append(thead).append(tbody);
		scroll.append(table);

		const empty = $('<div class="versi-table-empty" id="versi-table-empty">' +
			'<svg aria-hidden="true" width="40" height="40" fill="none" stroke="#d1d5db" stroke-width="1.5" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>' +
			'<p>' + l10n.noResults + '</p></div>');
		scroll.append(empty);
		$resultsContainer.append(scroll);

		$('#versi-results-header').hide();
		$('#versi-results-table').hide();
	}

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
		initResultsTable();
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
		$resultsContainer.empty().removeClass('versi-results-box');
		$status.text(versiProcessing.l10n.starting);
		$stopLink.show();
		$('#versi-pause-btn').show();
		resultsData = [];
		reviewResults = [];
		running = true;
		stopRequested = false;
		done = 0;
		offset = 0;
		startTime = Date.now();
		batchTimes = [];
		currentFilter = 'all';
		searchQuery = '';
		if (etaTimer) clearInterval(etaTimer);
		etaTimer = setInterval(updateEtaStatus, 5000);

		if ('bulk_review' === mode) {
			initResultsTable();
			$('#versi-results-header').hide();
			$('#versi-results-table').hide();
			fetchReviewBatch();
		} else {
			initResultsTable();
			$('#versi-results-header').hide();
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
		showFilters();
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

	function getStatusLabel(r) {
		if (r.status === 'success' && r.changed && r.previous) return l10n.replaced;
		if (r.status === 'success' && r.changed) return l10n.added;
		if (r.status === 'success' && !r.changed) return l10n.kept;
		if (r.status === 'error' && r.rate_limited) return 'rate-limited';
		if (r.status === 'error') return l10n.statusError;
		if (r.status === 'good') return l10n.statusGood;
		if (r.status === 'bad') return l10n.statusBad;
		if (r.status === 'info') return l10n.statusInfo;
		return l10n.statusSkipped;
	}

	function getStatusClass(r) {
		if (r.status === 'success' && r.changed) return 'success';
		if (r.status === 'success' && !r.changed) return 'kept';
		if (r.status === 'error' && r.rate_limited) return 'rate-limited';
		if (r.status === 'error') return 'error';
		if (r.status === 'good') return 'good';
		if (r.status === 'bad') return 'bad';
		if (r.status === 'info') return 'info';
		return 'skipped';
	}

	function createEntryRow(r) {
		const statusClass = getStatusClass(r);
		const statusLabel = getStatusLabel(r);
		const $row = $('<tr class="versi-result-row status-' + statusClass + '" data-status="' + r.status + '" data-id="' + r.id + '">');

		const $statusCell = $('<td class="versi-col-status">');
		$statusCell.append('<span class="versi-status-badge ' + statusClass + '">' + statusLabel + '</span>');
		$row.append($statusCell);

		$row.append('<td class="versi-col-id">#' + r.id + '</td>');

		const $titleCell = $('<td class="versi-col-title">');
		if (workload === 'alt' && r.thumbnail) {
			$titleCell.append('<img src="' + r.thumbnail + '" style="width:28px;height:28px;border-radius:3px;object-fit:cover;vertical-align:middle;margin-right:6px;">');
		}
		$titleCell.append('<span>' + $('<span>').text(r.title || '').html() + '</span>');
		$row.append($titleCell);

		const prevVal = r.previous || '';
		$row.append('<td class="versi-col-prev"><span class="versi-cell-value prev" title="' + $('<span>').text(prevVal).html() + '">' + $('<span>').text(prevVal).html() + '</span></td>');

		const newVal = r.generated || '';
		$row.append('<td class="versi-col-new"><span class="versi-cell-value new" title="' + $('<span>').text(newVal).html() + '">' + $('<span>').text(newVal).html() + '</span></td>');

		const $actions = $('<td class="versi-col-actions">');
		if (r.status === 'success' && r.previous !== undefined) {
			$actions.append(
				'<button class="versi-redo-btn" data-attachment-id="' + r.id + '" style="font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;margin:0 2px;">' + l10n.resume + '</button>' +
				'<button class="versi-undo-btn" data-attachment-id="' + r.id + '" data-previous="' + (r.previous || '').replace(/"/g, '&quot;') + '" style="font-size:11px;padding:1px 6px;cursor:pointer;background:none;border:1px solid #c3c4c7;border-radius:2px;color:#2271b1;margin:0 2px;">undo</button>'
			);
		}
		$row.append($actions);

		return $row;
	}

	function addEntry(r) {
		resultsData.push(r);
		const $row = createEntryRow(r);
		$('#versi-results-body').append($row);
		$('#versi-results-table').show();
		$('#versi-table-empty').hide();
		const scrollEl = $resultsContainer.find('.versi-results-scroll')[0];
		if (scrollEl) scrollEl.scrollTop = scrollEl.scrollHeight;

		// Update summary
		updateSummaryBar();
	}

	function updateSummaryBar() {
		let ok = 0, errs = 0, kept = 0, skipped = 0;
		resultsData.forEach(r => {
			if (r.status === 'success' && r.changed) ok++;
			else if (r.status === 'success' && !r.changed) kept++;
			else if (r.status === 'error') errs++;
			else skipped++;
		});
		const parts = [];
		if (ok) parts.push('<span style="color:#16a34a;font-weight:600;">' + ok + ' ok</span>');
		if (kept) parts.push('<span style="color:#dba617;font-weight:600;">' + kept + ' ' + l10n.kept + '</span>');
		if (errs) parts.push('<span style="color:#dc2626;font-weight:600;">' + errs + ' ' + l10n.errors.replace(':', '') + '</span>');
		if (skipped) parts.push('<span style="color:#6b7280;">' + skipped + ' ' + l10n.statusSkipped + '</span>');
		const totalStr = done + ' / ' + total;
		$('#versi-summary-text').html('<strong>' + totalStr + '</strong> &mdash; ' + parts.join(' &middot; '));
		$('#versi-results-header').show();
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
				addEntry(r);
			},
			error() {
				if (stopRequested) return;
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

	const reviewBatchSize = Math.max(1, Math.min(batchSize, 50));

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
					reviewResults.push(r);
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
		const content = r.alt || r.excerpt || '';
		const statusClass = r.status === 'good' ? 'good' : (r.status === 'bad' ? 'bad' : 'info');
		const statusLabel = r.status === 'good' ? l10n.statusGood : (r.status === 'bad' ? l10n.statusBad : l10n.statusInfo);
		const safeContent = $('<span>').text(content).html();
		const safeReason = $('<span>').text(r.reason || '').html();
		const safeTitle = $('<span>').text(r.title || '').html();

		const $card = $('<div class="versi-review-card status-' + statusClass + '" data-id="' + r.id + '" data-status="' + r.status + '">');

		const $header = $('<div class="versi-review-header">');
		$header.append('<span class="versi-status-badge ' + statusClass + '">' + statusLabel + '</span>');
		$header.append('<strong>#' + r.id + ' ' + safeTitle + '</strong>');
		$card.append($header);

		if (r.reason && r.status !== 'good') {
			$card.append('<div style="font-size:12px;color:#6b7280;margin:2px 0 8px;">' + safeReason + '</div>');
		}

		if (r.status === 'good') {
			$card.append('<div style="font-size:12px;color:#166534;padding:4px 0;">"' + safeContent + '"</div>');
		} else if (r.status === 'info') {
			$card.append('<div style="font-size:12px;color:#0369a1;padding:4px 0;">"' + (safeContent || $('<span>').text(r.reason || '').html()) + '"</div>');
		} else if (r.status === 'bad') {
			// Inline editing for bad items
			const $editArea = $('<div class="versi-review-edit">');
			$editArea.append('<div style="font-size:11px;color:#6b7280;margin-bottom:4px;">' + l10n.reviewEditPrompt + '</div>');
			$editArea.append('<textarea class="versi-review-textarea" data-original="' + safeContent + '">' + safeContent + '</textarea>');
			const $actions = $('<div class="versi-review-actions">');
			$actions.append('<button class="versi-review-accept button button-primary button-small" data-id="' + r.id + '">' + l10n.accept + '</button>');
			$actions.append('<button class="versi-review-regenerate button button-small" data-id="' + r.id + '">regenerate</button>');
			$actions.append('<button class="versi-review-skip button button-small" data-id="' + r.id + '">' + l10n.skip + '</button>');
			$editArea.append($actions);
			$card.append($editArea);
		}

		$resultsContainer.find('.versi-results-scroll').append($card);
		const scrollEl = $resultsContainer.find('.versi-results-scroll')[0];
		if (scrollEl) scrollEl.scrollTop = scrollEl.scrollHeight;

		$('#versi-table-empty').hide();
	}

	function downloadReviewCSV() {
		if (reviewResults.length === 0) return;
		let csv = 'ID,Title,Status,Content,Reason\n';
		reviewResults.forEach(function(r) {
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
		reviewResults.forEach(r => {
			if (r.status === 'good') good++;
			else if (r.status === 'bad') bad++;
			else info++;
		});
		$status.text(versiProcessing.l10n.reviewComplete + ' ' + good + ' good, ' + bad + ' bad' + (info > 0 ? ', ' + info + ' info' : ''));
		saveResults();
		dismissSavedJob();
		$('#versi-summary-text').html('<strong>' + l10n.reviewComplete + '</strong> ' + good + ' good, ' + bad + ' bad' + (info > 0 ? ', ' + info + ' info' : ''));
		$('#versi-results-header').show();
		const exportBtn = $('<button type="button" class="button" style="margin-left:auto;">' + versiProcessing.l10n.downloadCsv + '</button>');
		exportBtn.on('click', downloadReviewCSV);
		$('#versi-results-header').append(exportBtn);
	}

	function updateSummary() {
		if (etaTimer) clearInterval(etaTimer);
		updateSummaryBar();
		saveResults();
		dismissSavedJob();
		showFilters();
		const exportBtn = $('<button type="button" class="button" style="margin-left:auto;">' + versiProcessing.l10n.downloadCsv + '</button>');
		exportBtn.on('click', downloadResultsCSV);
		$('#versi-results-header').append(exportBtn);
		$status.text($('#versi-summary-text').text());
	}

	function getActionName(prefix) {
		if (workload === 'alt') return 'versi_alt_' + prefix;
		if (workload === 'excerpt') return 'versi_excerpt_' + prefix;
		if (workload === 'content') return 'versi_content_' + prefix;
		return 'versi_seo_' + prefix;
	}

	function getSaveActionName() {
		if (workload === 'alt') return 'versi_alt_save_single';
		if (workload === 'excerpt') return 'versi_excerpt_save_single';
		return '';
	}

	// Filtering
	function showFilters() {
		const $chips = $('#versi-filter-chips').empty();
		const counts = { all: resultsData.length };

		resultsData.forEach(r => {
			const key = r.status === 'success' ? (r.changed ? 'success' : 'kept') : r.status;
			counts[key] = (counts[key] || 0) + 1;
			if (r.status === 'error') counts.error = (counts.error || 0) + 1;
		});

		// Merge counts for display
		const filterMap = [
			{ key: 'all', label: l10n.filterAll, count: counts.all || 0 },
			{ key: 'success', label: l10n.filterSuccess, count: (counts.success || 0) + (counts.kept || 0) },
			{ key: 'error', label: l10n.filterErrors, count: counts.error || 0 },
			{ key: 'skipped', label: l10n.filterSkipped, count: counts.skipped || 0 },
		];

		filterMap.forEach(f => {
			if (f.count === 0 && f.key !== 'all') return;
			const $chip = $('<span class="versi-filter-chip' + (currentFilter === f.key ? ' active' : '') + '" data-filter="' + f.key + '">' + f.label + ' <span class="chip-count">' + f.count + '</span></span>');
			$chip.on('click', function() {
				$('#versi-filter-chips .versi-filter-chip').removeClass('active');
				$(this).addClass('active');
				currentFilter = f.key;
				applyFilters();
			});
			$chips.append($chip);
		});

		// Search chip
		const $searchChip = $('<span class="versi-filter-chip search-chip">' +
			'<svg aria-hidden="true" width="12" height="12" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>' +
			'<input type="text" id="versi-search-input" placeholder="' + l10n.searchResults + '"></span>');
		$chips.append($searchChip);

		$('#versi-search-input').on('input', function() {
			searchQuery = $(this).val().toLowerCase();
			applyFilters();
		});

		$('#versi-results-header').show();
	}

	function applyFilters() {
		$('#versi-results-body tr.versi-result-row').each(function() {
			const $row = $(this);
			const status = $row.data('status');
			const id = String($row.data('id') || '');
			const title = $row.find('.versi-col-title').text().toLowerCase();

			let matchFilter = currentFilter === 'all';
			if (currentFilter === 'success') matchFilter = status === 'success';
			else if (currentFilter === 'error') matchFilter = status === 'error';
			else if (currentFilter === 'skipped') matchFilter = status !== 'success' && status !== 'error';

			let matchSearch = true;
			if (searchQuery) {
				matchSearch = id.indexOf(searchQuery) !== -1 || title.indexOf(searchQuery) !== -1;
			}

			$row.toggleClass('hidden', !(matchFilter && matchSearch));
		});
	}

	// Redo / Undo
	$resultsContainer.on('click', '.versi-redo-btn', function() {
		const $btn = $(this);
		const $row = $btn.closest('.versi-result-row');
		const id = $row.data('id');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$row.css('opacity', '0.5');

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
				$row.replaceWith(createEntryRow(response.data));
			},
			error() {
				$btn.text('redo').prop('disabled', false);
				$row.css('opacity', '1');
			},
		});
	});

	$resultsContainer.on('click', '.versi-undo-btn', function() {
		const $btn = $(this);
		const $row = $btn.closest('.versi-result-row');
		const id = $btn.data('attachment-id');
		const prev = $btn.data('previous');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$row.css('opacity', '0.5');

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
				$row.css('opacity', '1');
				$row.find('.versi-status-badge').removeClass('success kept error rate-limited skipped').addClass('skipped').text(l10n.statusSkipped);
				$row.find('.versi-cell-value.new').html($('<span>').text(r.alt ? r.alt.substring(0, 100) : '').html());
				$row.find('.versi-redo-btn, .versi-undo-btn').remove();
			},
			error() {
				$btn.text('undo').prop('disabled', false);
				$row.css('opacity', '1');
			},
		});
	});

	// Review: Accept edited value
	$resultsContainer.on('click', '.versi-review-accept', function() {
		const $btn = $(this);
		const $card = $btn.closest('.versi-review-card');
		const id = $btn.data('id');
		const $textarea = $card.find('.versi-review-textarea');
		const value = $textarea.val().trim();

		if (!id) return;
		if (!value) return;

		const saveAction = getSaveActionName();
		if (!saveAction) return;

		$btn.text('...').prop('disabled', true);

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: saveAction,
				_ajax_nonce: versiProcessing.nonce,
				id: id,
				value: value,
			},
			success(response) {
				const r = response.data;
				$card.find('.versi-status-badge').removeClass('bad').addClass('success').text(l10n.statusSuccess);
				$card.find('.versi-review-edit').html('<div style="display:flex;align-items:center;gap:8px;padding:8px 0;font-size:12px;color:#166534;">' +
					'<svg aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' +
					'<span>' + l10n.saved + ': "' + $('<span>').text(value).html() + '"</span></div>');
				$card.data('status', 'saved');
				updateReviewEntryStatus(r);
			},
			error() {
				$btn.text(l10n.accept).prop('disabled', false);
			},
		});
	});

	function updateReviewEntryStatus(r) {
		// Update the underlying result data
		const idx = reviewResults.findIndex(item => item.id === r.id);
		if (idx !== -1) {
			reviewResults[idx].status = 'saved';
		}
	}

	// Review: Regenerate
	$resultsContainer.on('click', '.versi-review-regenerate', function() {
		const $btn = $(this);
		const $card = $btn.closest('.versi-review-card');
		const id = $btn.data('id');
		if (!id) return;

		$btn.text('...').prop('disabled', true);
		$card.css('opacity', '0.5');

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
				const gen = r.generated || '';
				$card.css('opacity', '1');
				$card.find('.versi-status-badge').removeClass('bad').addClass('success').text(l10n.statusSuccess);
				$card.find('.versi-review-edit').html('<div style="display:flex;align-items:center;gap:8px;padding:8px 0;font-size:12px;color:#166534;">' +
					'<svg aria-hidden="true" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>' +
					'<span>Regenerated: "' + $('<span>').text(gen.length > 120 ? gen.substring(0, 120) + '...' : gen).html() + '"</span></div>');
				const idx = reviewResults.findIndex(item => item.id === r.id);
				if (idx !== -1) {
					reviewResults[idx].status = 'regenerated';
				}
			},
			error() {
				$btn.text('regenerate').prop('disabled', false);
				$card.css('opacity', '1');
			},
		});
	});

	// Review: Skip
	$resultsContainer.on('click', '.versi-review-skip', function() {
		const $btn = $(this);
		const $card = $btn.closest('.versi-review-card');
		const id = $btn.data('id');
		if (!id) return;

		$card.find('.versi-status-badge').removeClass('bad').addClass('skipped').text(l10n.statusSkipped);
		$card.find('.versi-review-edit').html('<div style="font-size:12px;color:#6b7280;padding:4px 0;">' + l10n.statusSkipped + '</div>');
		$card.data('status', 'skipped');
		const idx = reviewResults.findIndex(item => item.id === id);
		if (idx !== -1) {
			reviewResults[idx].status = 'skipped';
		}
	});

})(jQuery);
