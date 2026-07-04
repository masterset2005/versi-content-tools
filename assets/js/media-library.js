(function($) {
	'use strict';

	if (typeof wp === 'undefined' || !wp.media || !wp.media.view) return;

	const orig = wp.media.view.Attachment.Library;
	if (!orig) return;

	wp.media.view.Attachment.Library = orig.extend({
		render() {
			const r = orig.prototype.render.apply(this, arguments);
			if (this.model && this.model.get('versi_generated')) {
				const alt = this.model.get('versi_generated');
				this.$el.append(
					'<div style="position:absolute;bottom:0;left:0;right:0;background:rgba(0,0,0,0.7);color:#fff;font-size:10px;padding:2px 4px;line-height:1.3;word-break:break-word;max-height:100%;overflow:hidden;">AI: ' + $('<span>').text(alt).html() + '</div>'
				);
			}
			return r;
		}
	});
})(jQuery);
