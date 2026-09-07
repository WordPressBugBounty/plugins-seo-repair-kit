(function ($) {
	'use strict';

	var scanPaused = false;

	function setStatus(text) {
		$('.srk-ci-scan-status').text(text);
	}

	function setProgress(percent) {
		percent = parseInt(percent, 10) || 0;
		$('.srk-ci-progress-label').text(percent + '% Complete');
		$('.srk-ci-progress-bar').css('width', percent + '%');
	}

	function ajaxPost(data) {
		data.nonce = srkInternalLinking.nonce;
		return $.post(srkInternalLinking.ajaxUrl, data);
	}

	function runBatch(scanId, page) {
		if (scanPaused) {
			setStatus('Paused');
			return;
		}

		ajaxPost({
			action: 'srk_il_run_content_index_batch',
			scan_id: scanId,
			page: page
		}).done(function (response) {
			if (!response || !response.success) {
				setStatus('Failed');
				window.alert(response.data && response.data.message ? response.data.message : 'Indexing failed.');
				return;
			}

			setProgress(response.data.percent);
			setStatus(response.data.complete ? 'Completed' : 'Indexing...');

			if (response.data.complete) {
				window.location.reload();
				return;
			}

			runBatch(scanId, (response.data.next_page || response.data.page || (page + 1)));
		}).fail(function () {
			setStatus('Failed');
			window.alert('Indexing request failed.');
		});
	}

	$(document).on('click', '.srk-ci-start-indexing', function () {
		var $button = $(this);

		scanPaused = false;
		$button.prop('disabled', true).text('Indexing...');
		$('.srk-ci-pause-indexing').prop('disabled', false);
		setStatus('Starting...');
		setProgress(0);

		ajaxPost({ action: 'srk_il_start_content_index' }).done(function (response) {
			if (!response || !response.success) {
				$button.prop('disabled', false).text('Start Indexing');
				setStatus('Failed');
				window.alert(response.data && response.data.message ? response.data.message : 'Unable to start indexing.');
				return;
			}

			runBatch(response.data.scan_id, 1);
		});
	});

	$(document).on('click', '.srk-ci-pause-indexing', function () {
		scanPaused = true;
		$(this).prop('disabled', true);
		$('.srk-ci-start-indexing').prop('disabled', false).text('Resume Indexing');
		setStatus('Paused');
	});

	$(document).on('click', '.srk-il-subtab', function () {
		var viewKey = $(this).data('srk-ci-view');

		$('.srk-il-subtab').removeClass('is-active');
		$(this).addClass('is-active');

		$('.srk-il-ci-panel').removeClass('is-active').hide();
		$('#srk-ci-' + viewKey).addClass('is-active').show();
	});

	// Domain modal
	$(document).on('click','.srk-domain-view-posts,.srk-domain-view-links',function(){
		let domain = $(this).data('domain');
		let action = $(this).hasClass('srk-domain-view-posts') ? 'srk_il_get_domain_posts' : 'srk_il_get_domain_links';

		let $modal = $('#srk-domain-modal');
		let $body  = $modal.find('.srk-domain-modal-body');
		let $title = $modal.find('.srk-domain-modal-title');

		$title.text('Loading '+domain);
		$body.html('<p>Loading...</p>');
		$modal.show();

		$.post(srkInternalLinking.ajaxUrl,{
			action:action,
			nonce:srkInternalLinking.nonce,
			domain:domain
		},function(response){
			if(response.success){
				$body.html(response.data.html);
				$title.text(domain);
			}else{
				$body.html('<p>'+response.data.message+'</p>');
			}
		});
	});

	// Close modal
	$(document).on('click','.srk-domain-close',function(){
		$('#srk-domain-modal').hide();
	});

	// Download CSV
	$(document).on('click','.srk-domain-download',function(){
		let $table = $('#srk-domain-modal .srk-il-data-table')[0];
		if(!$table){alert('No table to download'); return;}

		let csv = [];
		for(let r=0;r<$table.rows.length;r++){
			let row=[];
			let cols = $table.rows[r].cells;
			for(let c=0;c<cols.length;c++){
				row.push('"'+cols[c].innerText.replace(/"/g,'""')+'"');
			}
			csv.push(row.join(','));
		}

		let csvContent = "data:text/csv;charset=utf-8," + csv.join("\n");
		let encodedUri = encodeURI(csvContent);
		let link = document.createElement("a");
		link.setAttribute("href",encodedUri);
		link.setAttribute("download","domain_report.csv");
		document.body.appendChild(link);
		link.click();
		document.body.removeChild(link);
	});


})(jQuery);