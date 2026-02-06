/**
 * SEO Repair Kit - Dashboard "Re-check Status" button
 * 
 * Refreshes the "Site SEO Analysis" table via AJAX without leaving the page.
 */

jQuery(document).ready(function ($) {
  var $dashboard = $("#srk-dashboard");
  if (!$dashboard.length || typeof SRK_DASHBOARD_STATUS === "undefined") {
    return;
  }

  var $header      = $dashboard.find(".srk-seo-analysis-header");
  var $button      = $header.find("button.srk-btn-sm");
  var $issuesBox   = $dashboard.find(".srk-seo-issues-table");
  var $issuesTable = $issuesBox.find(".srk-issues-table");
  var $tbody       = $issuesTable.find("tbody");
  var $emptyState  = $dashboard.find(".srk-empty-state");

  function renderIssues(issues) {
    // If no issues were returned (or the response is malformed), keep the
    // existing table as-is and do not hide current results.
    if (!issues || !issues.length) {
      return;
    }

    $tbody.empty();

    issues.forEach(function (issue) {
      var status = issue.status || "";
      var label = issue.label || "";
      var message = issue.message || "";
      var actionText = issue.action_text || "";
      var actionUrl = issue.action_url || "#";

      var $tr = $("<tr>").addClass("srk-issue-row").addClass("srk-issue-" + status);
      var $tdInfo = $("<td>");
      var $content = $("<div>").addClass("srk-issue-content");
      var $badge = $("<span>").addClass("srk-badge srk-badge-" + status).text(label);
      var $msg = $("<span>").addClass("srk-issue-message").text(message);

      $content.append($badge).append($msg);
      $tdInfo.append($content);

      var $tdActions = $("<td>").addClass("srk-action-column");
      var $actions = $("<div>").addClass("srk-action-buttons");

      if (status !== "success" && actionText) {
        var $primary = $("<a>")
          .addClass("srk-btn-xs")
          .attr("href", actionUrl)
          .text(actionText);
        $actions.append($primary);
      }

      if (actionUrl) {
        var $iconLink = $("<a>")
          .addClass("srk-icon-btn")
          .attr("href", actionUrl)
          .attr("title", "View Details");
        // Match the original PHP-rendered icon so it always remains visible.
        $iconLink.append($("<span>").addClass("dashicons dashicons-arrow-right-alt"));
        $actions.append($iconLink);
      }

      $tdActions.append($actions);

      $tr.append($tdInfo).append($tdActions);
      $tbody.append($tr);
    });
  }

  $button.on("click", function (e) {
    e.preventDefault();

    if ($button.prop("disabled")) {
      return;
    }

    $button.prop("disabled", true).addClass("srk-loading");

    $.post(
      SRK_DASHBOARD_STATUS.ajaxUrl,
      {
        action: "srk_dashboard_refresh_seo_issues",
        nonce: SRK_DASHBOARD_STATUS.nonce,
      }
    )
      .done(function (response) {
        if (response && response.success && response.data && response.data.issues) {
          renderIssues(response.data.issues);
        }
      })
      .always(function () {
        $button.prop("disabled", false).removeClass("srk-loading");
      });
  });
});