/**
 * SEO Repair Kit - Copy JavaScript
 *
 * @since 2.1.0
 */
(function(){
	'use strict';

	// Utility: copy text with modern API, fallback to execCommand for legacy.
	async function copyText(text) {
		if (!text) throw new Error('Nothing to copy');
		// Prefer async clipboard when available & in secure context.
		if (navigator.clipboard && window.isSecureContext) {
			await navigator.clipboard.writeText(text);
			return true;
		}
		// Fallback: hidden textarea + execCommand.
		const ta = document.createElement('textarea');
		ta.value = text;
		ta.setAttribute('readonly', '');
		ta.style.position = 'absolute';
		ta.style.left = '-9999px';
		document.body.appendChild(ta);
		ta.select();
		try {
			const ok = document.execCommand('copy');
			document.body.removeChild(ta);
			if (!ok) throw new Error('execCommand failed');
			return true;
		} catch (e) {
			document.body.removeChild(ta);
			throw e;
		}
	}

	function setStatus(msg, isError) {
		var statusEl = document.getElementById('srk-copy-json-status');
		if (!statusEl) return;
		statusEl.textContent = msg || '';
		statusEl.style.color = isError ? '#d63638' : '#F28500'; // WP notice colors
		if (msg) {
			// Clear after 2 seconds.
			setTimeout(function(){ statusEl.textContent = ''; }, 2000);
		}
	}

	function getJsonPreviewText() {
		var pre = document.getElementById('srk-json-preview');
		if (!pre) return '';
		// Use textContent so we copy the plain JSON (not markup).
		return (pre.textContent || '').trim();
	}

	function maybeToggleCopyButton() {
		var btn = document.getElementById('srk-copy-json');
		var container = document.getElementById('srk-json-preview-container');
		if (!btn || !container) return;

		// Enable when container is visible and JSON not empty.
		var visible = container.style.display !== 'none';
		var hasJson = getJsonPreviewText().length > 0;
		btn.disabled = !(visible && hasJson);
	}

	function init() {
		var btn = document.getElementById('srk-copy-json');
		if (btn) {
			btn.addEventListener('click', async function() {
				try {
					var text = getJsonPreviewText();
					await copyText(text);
					setStatus('Copied!', false);
				} catch (e) {
					setStatus('Copy failed', true);
				}
			});
		}

		// Observe changes to preview visibility/content to toggle the button state.
		var container = document.getElementById('srk-json-preview-container');
		var pre = document.getElementById('srk-json-preview');

		if (container) {
			var obsContainer = new MutationObserver(maybeToggleCopyButton);
			obsContainer.observe(container, { attributes: true, attributeFilter: ['style', 'class'] });
		}
		if (pre) {
			var obsPre = new MutationObserver(maybeToggleCopyButton);
			obsPre.observe(pre, { characterData: true, childList: true, subtree: true });
		}

		// Also run on load.
		maybeToggleCopyButton();

		// If your manager script dispatches a custom event after preview update, listen to it too:
		// window.dispatchEvent(new CustomEvent('srk-json-preview-updated'))
		window.addEventListener('srk-json-preview-updated', maybeToggleCopyButton);
	}

	// DOM ready
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', init);
	} else {
		init();
	}
})();