/**
 * WordPress-specific JavaScript for link scanning functionality.
 *
 * This script uses jQuery and is intended to be executed when the document is ready.
 * It performs asynchronous link scanning, updates progress, and provides options
 * for downloading the scanned links in CSV format.
 */

jQuery(document).ready(function ($) {
  // Selecting relevant DOM elements
  var links = $("#scan-table .scan-http-status");
  var blueBar = $(".blue-bar");
  var progressLabel = $(".progress-label");
  var progressContainer = $(".srk-scan-progress-container");
  var scannedCount = $(".srk-scanned-count");
  var totalCount = $(".srk-total-count");
  var statusText = $(".srk-scan-status-text");
  var spinIcon = $(".srk-scan-progress-info .dashicons");

  // Initializing variables for link processing
  var totalLinks = links.length;
  var processedLinks = 0;
  var summarySubmitted = false;

  // Initialize total count
  totalCount.text(totalLinks);

  if (!totalLinks) {
    progressContainer.hide();
    finalizeScan();
  }

  // Hide CSV download button by default
  $("#download-links-csv").hide();

  /**
   * Function to update the progress bar based on the processed links.
   */
  function updateProgress() {
    var percentage = Math.floor((processedLinks / totalLinks) * 100);
    blueBar.css("width", percentage + "%");
    progressLabel.text(percentage + "%");
    scannedCount.text(processedLinks);

    // Update status text based on progress
    if (percentage < 100) {
      statusText.text("Scanning links...");
    }

    // Show CSV download button when processing is complete and broken links are found
    if (percentage === 100) {
      var brokenCount = updateRowCount();
      spinIcon.removeClass("srk-spin dashicons-update").addClass("dashicons-yes-alt");
      statusText.text("Scan complete!");
      progressContainer.addClass("srk-scan-complete");

      if (brokenCount !== 0) {
        $("#download-links-csv").show();
      } else {
        $("#download-links-csv").hide();
      }
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
      finalizeScan();
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
        var statusCode = parseInt(response, 10);
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
    var totalLinksString =
      typeof srkScanLinksI18n !== "undefined" && srkScanLinksI18n.remainingLinks
        ? srkScanLinksI18n.remainingLinks
        : "Remaining Links: ";

    var congratsMessage =
      typeof srkScanLinksI18n !== "undefined" && srkScanLinksI18n.noBrokenLinks
        ? srkScanLinksI18n.noBrokenLinks
        : "Congrats Broken Links Not Found !";

    $("#scan-row-counter").text(totalLinksString + rowCount);

    // Handle display based on the presence of broken links
    if (rowCount === 0) {
      $("#scan-table").hide();
      $("#scan-row-counter + .srk-no-links-message").remove();
      var noLinksMessage = '<p class="srk-no-links-message">' + congratsMessage + "</p>";
      $("#scan-row-counter").after(noLinksMessage);
      // Clear the row counter text if there are no links
      $("#scan-row-counter").text("");
    } else {
      $("#scan-table").show();
      $("#scan-row-counter + .srk-no-links-message").remove();
    }

    return rowCount;
  }

  function finalizeScan() {
    if (summarySubmitted) {
      return;
    }

    if (typeof ajaxUrlsrkscan === "undefined" || typeof scanSummaryNonce === "undefined") {
      setTimeout(finalizeScan, 200);
      return;
    }

    summarySubmitted = true;

    var brokenRemaining = updateRowCount();
    var workingLinks = totalLinks - brokenRemaining;

    $.ajax({
      url: ajaxUrlsrkscan,
      type: "POST",
      data: {
        action: "srk_store_scan_stats",
        nonce: scanSummaryNonce,
        total_links: totalLinks,
        broken_links: brokenRemaining,
        working_links: workingLinks < 0 ? 0 : workingLinks,
        post_type: typeof srkScanPostType !== "undefined" ? srkScanPostType : "",
      },
    });
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
    var rows = [];

    // Extract headers from table
    var headers = [];
    $("#scan-table thead th").each(function () {
      headers.push(cleanCsvCell($(this).text()));
    });
    rows.push(headers);

    // Extract data from each row in the table
    $("#scan-table tbody tr").each(function () {
      var rowData = [];
      $(this)
        .find("td")
        .each(function () {
          rowData.push(cleanCsvCell(getCellExportText($(this))));
        });
      rows.push(rowData);
    });

    var csvContent = rows
      .map(function (row) {
        return row.map(escapeCsvValue).join(",");
      })
      .join("\r\n");

    // Create a download link and trigger a click event
    var timestamp = new Date().toISOString().replace(/:/g, "-");
    var filename = "links_list_" + timestamp + ".csv";
    var blob = new Blob(["\ufeff" + csvContent], { type: "text/csv;charset=utf-8;" });
    var url = window.URL.createObjectURL(blob);
    var link = document.createElement("a");
    link.setAttribute("href", url);
    link.setAttribute("download", filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    window.URL.revokeObjectURL(url);
  }

  function getCellExportText($cell) {
    var $clone = $cell.clone();
    $clone.find("script, style, .dashicons").remove();
    return $clone.text();
  }

  function cleanCsvCell(value) {
    return String(value || "")
      .replace(/\u00a0/g, " ")
      .replace(/[\r\n\t]+/g, " ")
      .replace(/\s{2,}/g, " ")
      .trim();
  }

  function escapeCsvValue(value) {
    var text = cleanCsvCell(value);
    return '"' + text.replace(/"/g, '""') + '"';
  }
});
