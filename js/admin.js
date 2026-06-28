/*global jQuery, versiBulkData */
(function ($) {
	var data = versiBulkData;
	if (!data) return;

	// Only activate for media-library inline processing.
	if (!data.action && !data.workload) {
		return;
	}

	var $resultsContainer = $('#versi-results');
	var isProcessingPage = $resultsContainer.length > 0;

	if (!isProcessingPage) {
		return;
	}

	// Legacy support for old processing-page entry point.
	// The live tab on the processing page now has its own inline script,
	// so this file is only loaded when it shouldn't do anything.
	// Gracefully no-op.
})(jQuery);
