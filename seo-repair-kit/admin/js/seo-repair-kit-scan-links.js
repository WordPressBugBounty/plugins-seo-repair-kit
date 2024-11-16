/**
 * WordPress-specific JavaScript for link scanning functionality.
 *
 * This script uses jQuery and is intended to be executed when the document is ready.
 * It performs asynchronous link scanning, updates progress, and provides options
 * for downloading the scanned links in CSV format.
 */

jQuery(document).ready(function ($) {
  // Selecting relevant DOM elements
  var loader = $(".seo-repair-kit-loader");
  var links = $("#scan-table .scan-http-status");
  var progressBar = $(".progress-bar");
  var blueBar = $(".blue-bar");
  var progressLabel = $(".progress-label");

  // Initializing variables for link processing
  var totalLinks = links.length;
  var processedLinks = 0;

  // Hide CSV download button by default
  $("#download-links-csv").hide();

  /**
   * Function to update the progress bar based on the processed links.
   */

  function updateProgress() {
    var percentage = Math.floor((processedLinks / totalLinks) * 100);
    progressBar.width(percentage + "%");
    progressLabel.text(percentage + "%");
    blueBar.width(percentage + "%");

    // Show CSV download button when processing is complete and broken links are found
    if (percentage === 100 && updateRowCount() !== 0) {
      $("#download-links-csv").show();
    } else {
      $("#download-links-csv").hide();
    }
  }

  /**
   * Function to scan a single link asynchronously.
   * @param {number} index - Index of the link in the links array.
   */

  function scanLink(index) {
    if (index >= links.length) {
      loader.hide();
      return;
    }

    var link = $(links[index]).data("link");
    var row = $(links[index]).closest("tr");

    // AJAX request to get the HTTP status of the link
    $.ajax({
      url: ajaxUrlsrkscan,
      type: "POST",
      data: {
        action: "get_scan_http_status",
        link: link,
        srk_scan_nonce: scanHttpStatusNonce,
      },
      success: function (response) {
        row.find(".scan-http-status").text(response);

        // Update the displayed HTTP status for the link
        var statusCode = parseInt(response);
        if (statusCode < 400 || statusCode > 600) {
          row.remove();
          updateRowCount();
        }
        processedLinks++;
        updateProgress();
      },
      error: function (xhr, status, error) {
        row.find(".scan-http-status").text("Error: " + xhr + status + error);
        // Display error message and continue processing
        processedLinks++;
        updateProgress();
      },
      complete: function () {
        // Continue scanning the next link
        scanLink(index + 1);
      },
    });
  }
  scanLink(0);

  /**
   * Function to update the total link count on the page.
   */

  function updateRowCount() {
    var rowCount = $("#scan-table tbody tr").length;
    var totalLinksString = "<?php esc_html_e('Total Links: ', 'seo-repair-kit'); ?>";
    var congratsMessage = "<?php esc_html_e('Congrats Broken Links Not Found !', 'seo-repair-kit'); ?>";
    $("#scan-row-counter").text(totalLinksString + rowCount);

    // Handle display based on the presence of broken links
    if (rowCount === 0) {
      $("#scan-table").hide();
      $("#scan-row-counter + .srk-no-links-message").remove();
      var noLinksMessage = '<p class="srk-no-links-message">' + congratsMessage + '</p>';
        $("#scan-row-counter").after(noLinksMessage);
      // Clear the row counter text if there are no links
      $("#scan-row-counter").text("");
    } else {
      $("#scan-table").show();
      $("#scan-row-counter + .srk-no-links-message").remove();
    }
    return rowCount;
  }

  // Initial update of row count
  updateRowCount();

  $("#download-links-csv").on("click", function (e) {
    e.preventDefault();
    downloadLinksCSV();
  });

  /**
   * Function to download the scanned links in CSV format.
   */

  function downloadLinksCSV() {
    var csvContent = "data:text/csv;charset=utf-8,";
    var headers = [];

    // Extract headers from table
    $("#scan-table thead th").each(function () {
      headers.push($(this).text());
    });
    csvContent += "\n" + headers.join(",") + "\n";

    // Extract data from each row in the table
    $("#scan-table tbody tr").each(function () {
      var rowData = [];
      $(this)
        .find("td")
        .each(function () {
          rowData.push('"' + $(this).text() + '"');
        });
      csvContent += rowData.join(",") + "\n";
    });

    // Create a download link and trigger a click event
    var encodedUri = encodeURI(csvContent);
    var timestamp = new Date().toISOString().replace(/:/g, "-");
    var filename = "links_list_" + timestamp + ".csv";
    var link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
  }
});
