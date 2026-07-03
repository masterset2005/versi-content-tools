(function($) {
	'use strict';

	if ( typeof versiAdmin === 'undefined' ) return;

	// Settings page tab switching
	$('#versi-tabs a').on('click', function(e) {
		e.preventDefault();
		$('#versi-tabs a').removeClass('nav-tab-active');
		$(this).addClass('nav-tab-active');
		$('.versi-tab').hide();
		const $panel = $($(this).attr('href'));
		$panel.show();
		$panel.find('h2, h3').first().attr('tabindex', '-1').focus();
	});

	// Alt processing mode toggle
	function toggleMode() {
		const mode = $('input[name="versi_alt_processing_mode"]:checked').val();
		$('tr[data-mode]').addClass('hidden');
		$('tr[data-mode="' + mode + '"]').removeClass('hidden');
	}
	$('input[name="versi_alt_processing_mode"]').on('change', toggleMode);
	toggleMode();

	// Model selector dropdowns
	$('.versi-model-select').each(function() {
		const $select = $(this);
		const savedValue = $select.val();

		$.ajax({
			url: ajaxurl,
			method: 'POST',
			data: {
				action: 'versi_get_models',
				_ajax_nonce: versiAdmin.modelsNonce,
			},
			success(response) {
				if (!response.success || !response.data) return;
				response.data.forEach(provider => {
					const $group = $('<optgroup>').attr('label', provider.provider);
					provider.models.forEach(model => {
						$group.append($('<option>').val(model.id).text(model.name + ' (' + model.id + ')'));
					});
					$select.append($group);
				});
				if (savedValue) $select.val(savedValue);
			},
			error() {
				$select.replaceWith('<input type="text" id="' + $select.attr('id') + '" name="' + $select.attr('name') + '" value="' + (savedValue || '') + '" class="regular-text code">');
			}
		});
	});

})(jQuery);
