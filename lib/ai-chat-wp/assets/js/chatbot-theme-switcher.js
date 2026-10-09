/**
 * Chat Theme Switcher
 * Handles dark/light mode toggle in the chat widget.
 *
 * @package AI Chat for WordPress
 */
(function ($) {
  "use strict";

  var STORAGE_KEY = "aicwp_chat_dark_mode";

  /**
   * Apply dark mode state to all chat wrappers and floating widget.
   */
  function applyDarkMode(enabled) {
    var $widget = $(".aicwp-floating-chat-widget");

    $(".aicwp-chat-wrapper").each(function () {
      if (enabled) {
        $(this).addClass("dark-mode");
      } else {
        $(this).removeClass("dark-mode");
      }
    });

    if (enabled) {
      $widget.addClass("dark-mode");
    } else {
      $widget.removeClass("dark-mode");
    }

    // Update animated wave background colors if present
    if (typeof AicwpSilkWave !== "undefined") {
      AicwpSilkWave.setDarkMode(enabled);
    }
  }

  /**
   * Restore saved preference from localStorage on page load.
   */
  function restorePreference() {
    var saved = localStorage.getItem(STORAGE_KEY);
    if (!saved) return;

    applyDarkMode(saved === "dark");
  }

  /**
   * Handle toggle click.
   */
  function onToggleClick() {
    var $wrapper = $(this).closest(".aicwp-chat-wrapper");
    if (!$wrapper.length) {
      $wrapper = $(this)
        .closest(".aicwp-chat-container")
        .closest(".aicwp-chat-wrapper");
    }
    if (!$wrapper.length) return;

    var isDark = $wrapper.hasClass("dark-mode");
    var newMode = !isDark;

    applyDarkMode(newMode);
    localStorage.setItem(STORAGE_KEY, newMode ? "dark" : "light");
  }

  $(function () {
    restorePreference();

    $(document).on("click", ".aicwp-chat-darkmode-toggle", onToggleClick);
  });
})(jQuery);
