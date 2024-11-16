/**
 * WordPress-specific JavaScript for handling URL redirection.
 *
 * This script uses jQuery and is intended to be executed when the document is ready.
 * It captures the click event on the 'Save' button, retrieves the old and new URLs,
 * and sends an AJAX request to the server to save the redirection.
 */

jQuery(document).ready(function ($) {
  // Event handler for saving a new URL
  $("#srk_save_new_url").on("click", function () {
    // Retrieve values from input fields
    var srkOldUrl = $("#old_url").val();
    var srkNewUrl = $("#new_url").val();

    // Check if either old or new URL is empty
    if (!srkOldUrl || !srkNewUrl) {
      // Alert user to fill in both fields
      alert(srk_ajax_obj.srkit_redirection_messages.srk_fill_fields);
      return;
    }

    // Perform AJAX request to save the new URL
    $.ajax({
      url: srk_ajax_obj.srkit_redirection_ajax,
      type: "POST",
      data: {
        action: "srk_save_new_url",
        old_url: srkOldUrl,
        new_url: srkNewUrl,
        srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce,
      },
      success: function (response) {
        // Display success message
        alert(response);
        // Reload the page
        location.reload();
      },
      error: function (error) {
        // Alert user in case of save error
        alert(srk_ajax_obj.srkit_redirection_messages.srkit_redirection_save_error);
      },
    });
  });

  // Event handler for deleting a redirection record
  $(".srk-delete-record").on("click", function () {
    // Retrieve the record ID from the data attribute
    var recordId = $(this).data("record-id");

    // Confirm deletion with the user
    if (confirm(srk_ajax_obj.srkit_redirection_messages.srk_confirm_delete)) {
      // Prepare data for AJAX request to delete the record
      var data = {
        action: "srk_delete_redirection_record",
        srkit_redirection_nonce: srk_ajax_obj.srk_save_url_nonce,
        record_id: recordId,
      };

      // Perform AJAX request to delete the record
      $.post(srk_ajax_obj.srkit_redirection_ajax, data, function (response) {
        // Check if deletion was successful
        if (response === "success") {
          // Reload the page after successful deletion
          location.reload();
        } else {
          // Display error message in case of deletion error
          alert(srk_ajax_obj.srkit_redirection_messages.srk_delete_error);
        }
      });
    }
  });
});
