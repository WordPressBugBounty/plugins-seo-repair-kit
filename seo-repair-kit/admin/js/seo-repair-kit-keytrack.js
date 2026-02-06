// class-seo-repair-kit-keytrack.js
jQuery(document).ready(function($) {
    $("#selected_keywords").chosen({ width: "100%" });
    $("#keytrack-form").on("submit", function() {
        alert("The Threshold Settings form was submitted successfully!");
        return true; // Allow form submission to proceed
    });
});
