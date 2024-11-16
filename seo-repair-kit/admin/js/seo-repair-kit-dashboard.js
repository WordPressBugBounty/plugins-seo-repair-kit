/**
 * WordPress-specific JavaScript for handling SEO Repair Kit dashboard functionality.
 *
 * This script uses jQuery and is intended to be executed when the document is ready.
 * It captures the click event on the 'Start' button, retrieves the selected post type from the dropdown,
 * and sends an AJAX request to the server to fetch scan links for the selected post type.
 * The received response is then displayed in the designated element on the dashboard.
 */

jQuery(document).ready(function ($) {
  // Event handler for the click on the start button
  $("#start-button").on("click", function (e) {
    e.preventDefault();

    // Get the selected post type from the dropdown
    var srkSelectedPostType = $("#srk-post-type-dropdown").val();

    // Get the nonce for security verification
    var srkitdashboard_nonce = SeoRepairKitDashboardVars.srkitdashboard_nonce;

    // Show the loader while waiting for the AJAX response
    $("#srk-loader-container").show();

    // AJAX request to get scan links and display results
    $.ajax({
      url: SeoRepairKitDashboardVars.ajaxurlsrkdashboard,
      type: "POST",
      data: {
        action: "get_scan_links_dashboard",
        srkSelectedPostType: srkSelectedPostType,
        srkitdashboard_nonce: srkitdashboard_nonce,
      },
      success: function (response) {
        // Hide the loader after receiving the response
        $("#srk-loader-container").hide();

        // Display the scan results in the designated element
        $("#scan-results").html(response);
      },
    });
  });
});
