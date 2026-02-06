/**
 * Handles the Link Scanner AJAX flow inside the SEO Repair Kit admin.
 *
 * Captures clicks on the Start button, sends the selected post type to the server,
 * and renders the scan results without a full page reload.
 */

jQuery(document).ready(function ($) {
  // Event handler for the click on the start button
  $("#start-button").on("click", function (e) {
    e.preventDefault();
    
    // Disable button during scan
    var $button = $(this);
    $button.prop("disabled", true).addClass("srk-loading");

    // Get the selected post type from the dropdown
    var srkSelectedPostType = $("#srk-post-type-dropdown").val();

    // Get the nonce for security verification
    var srkitdashboard_nonce = SeoRepairKitDashboardVars.srkitdashboard_nonce;

    // Show the loader while waiting for the AJAX response
    $("#srk-loader-container").addClass("show").fadeIn(200);
    $("#scan-results").fadeOut(200);

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
        $("#srk-loader-container").removeClass("show").fadeOut(200);

        // Display the scan results in the designated element with fade in animation
        $("#scan-results").html(response).hide().fadeIn(400);
        
        // Re-enable button
        $button.prop("disabled", false).removeClass("srk-loading");
      },
      error: function() {
        // Hide loader on error
        $("#srk-loader-container").removeClass("show").fadeOut(200);
        $("#scan-results").fadeIn(200);
        
        // Re-enable button
        $button.prop("disabled", false).removeClass("srk-loading");
        
        // Show error message
        $("#scan-results").html(
          '<div class="srk-card srk-empty">' +
          '<h3>Scan Failed</h3>' +
          '<p>An error occurred while scanning. Please try again.</p>' +
          '</div>'
        );
      }
    });
  });
});