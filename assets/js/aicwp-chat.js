/**
 * AI Chat Script
 *
 * Handles chat functionality for shortcode
 */

(function ($) {
  "use strict";

  /**
   * Debug logging helper - only logs when debug mode is enabled
   */
  const debugLog = function (...args) {
    if (
      typeof aicwpChatConfig !== "undefined" &&
      aicwpChatConfig.debugMode
    ) {
      console.log("[AI Chat]", ...args);
    }
  };

  const debugError = function (...args) {
    if (
      typeof aicwpChatConfig !== "undefined" &&
      aicwpChatConfig.debugMode
    ) {
      console.error("[AI Chat ERROR]", ...args);
    }
  };

  /**
   * Get headers for REST API requests
   * Only includes X-WP-Nonce for logged-in users to prevent stale nonce errors
   * from cached pages (CDN/Cloudflare). Logged-out users don't need nonce since
   * all chat endpoints have public permission_callback.
   *
   * @return {Object} Headers object for fetch/ajax requests
   */
  const getRequestHeaders = function () {
    var headers = {
      "Content-Type": "application/json",
      "X-Page-URL": window.location.href, // Track which page chat is used on
    };

    // Only send nonce for logged-in users (they won't have cached pages)
    // This prevents "rest_cookie_invalid_nonce" errors from CDN-cached pages
    if (aicwpChatConfig.isLoggedIn && aicwpChatConfig.nonce) {
      headers["X-WP-Nonce"] = aicwpChatConfig.nonce;
    }

    return headers;
  };

  /**
   * Report error to backend for server-side logging
   * Fire-and-forget - doesn't block error handling
   * @param {string} errorType - Error type
   * @param {string} context - Where error occurred
   * @param {Object} details - Error details
   */
  const reportErrorToBackend = function (errorType, context, details) {
    // Don't report if no API base configured
    if (!aicwpChatConfig || !aicwpChatConfig.apiBase) {
      return;
    }

    // Fire-and-forget POST to backend error logging endpoint
    try {
      $.ajax({
        url: aicwpChatConfig.apiBase + "/log-client-error",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({
          error_type: errorType,
          context: context,
          details: {
            status: details.status,
            statusText: details.statusText,
            readyState: details.readyState,
            timestamp: details.timestamp,
            responsePreview: details.responsePreview,
            request_id: details.serverError?.request_id || null,
            v: "1.6.9+",
          },
        }),
        timeout: 5000, // Short timeout - this is fire-and-forget
      });
    } catch (e) {
      // Silently ignore - this is best-effort logging
    }
  };

  /**
   * Detailed error handler - analyzes XHR errors and provides diagnostic info
   * Always logs to console AND reports to backend for server-side debugging
   * @param {Object} xhr - jQuery XHR object
   * @param {string} context - Where the error occurred (e.g., 'chat-proxy', 'tool-call')
   * @return {Object} { userMessage: string, errorType: string, details: object }
   */
  const analyzeError = function (xhr, context) {
    var errorType = "unknown";
    var userMessage = aicwpChatConfig.strings.errorGeneral;
    var details = {
      context: context,
      status: xhr.status,
      statusText: xhr.statusText,
      readyState: xhr.readyState,
      timestamp: new Date().toISOString(),
    };

    // Analyze error type based on XHR state
    if (xhr.status === 0) {
      if (xhr.readyState === 0) {
        // Request never sent - likely blocked or network down
        errorType = "network_blocked";
        userMessage =
          aicwpChatConfig.strings.errorNetwork ||
          "Network error - request could not be sent. Please check your connection.";
      } else if (xhr.readyState === 4) {
        // Request completed but status 0 - connection dropped mid-request
        errorType = "connection_dropped";
        userMessage =
          aicwpChatConfig.strings.errorConnection ||
          "Connection was interrupted. Please try again.";
      } else {
        // Other readyState with status 0 - likely timeout or abort
        errorType = "connection_interrupted";
        userMessage =
          aicwpChatConfig.strings.errorConnection ||
          "Connection was interrupted. Please try again.";
      }
    } else if (xhr.status === 408 || xhr.statusText === "timeout") {
      errorType = "timeout";
      userMessage =
        aicwpChatConfig.strings.errorTimeout ||
        "Request timed out. Please try again.";
    } else if (xhr.status === 429) {
      errorType = "rate_limit";
      userMessage =
        aicwpChatConfig.strings.errorRateLimit ||
        "Too many requests. Please wait a moment and try again.";
    } else if (xhr.status >= 500) {
      errorType = "server_error";
      userMessage =
        aicwpChatConfig.strings.errorServer ||
        "Server error occurred. Please try again.";
      if (xhr.responseJSON && xhr.responseJSON.error) {
        details.serverError = xhr.responseJSON.error;
      }
    } else if (xhr.status >= 400) {
      errorType = "client_error";
      userMessage =
        aicwpChatConfig.strings.errorGeneral ||
        "An error occurred. Please try again.";
      if (xhr.responseJSON && xhr.responseJSON.error) {
        details.serverError = xhr.responseJSON.error;
      }
    } else if (xhr.responseJSON && xhr.responseJSON.error) {
      errorType = "api_error";
      details.serverError = xhr.responseJSON.error;
    }

    // Always try to extract actual error message from response (overrides generic messages)
    // This ensures users see the real error like "Cookie check failed" or "Rate limit exceeded"
    if (xhr.responseJSON) {
      var serverMsg = null;
      // Google/Gemini array format: [{"error": {"message": "..."}}]
      if (Array.isArray(xhr.responseJSON) && xhr.responseJSON[0] && xhr.responseJSON[0].error && xhr.responseJSON[0].error.message) {
        serverMsg = xhr.responseJSON[0].error.message;
      }
      // AI Chat format: {"error": {"message": "..."}}
      else if (xhr.responseJSON.error && xhr.responseJSON.error.message) {
        serverMsg = xhr.responseJSON.error.message;
      }
      // WordPress format: {"message": "..."}
      else if (xhr.responseJSON.message) {
        serverMsg = xhr.responseJSON.message;
      }
      // Simple format: {"error": "string"}
      else if (typeof xhr.responseJSON.error === "string") {
        serverMsg = xhr.responseJSON.error;
      }

      if (serverMsg) {
        userMessage = serverMsg;
      }
    }

    // Try to get response text for additional diagnostics
    try {
      if (xhr.responseText) {
        details.responsePreview = xhr.responseText.substring(0, 500);
      }
    } catch (e) {
      details.responseTextError = "Could not read response text";
    }

    // Always log errors to console for debugging (even without debug mode)
    console.error("[AI Chat] Error in " + context + ":", {
      type: errorType,
      status: xhr.status,
      statusText: xhr.statusText,
      readyState: xhr.readyState,
      details: details,
    });

    // Report error to backend for server-side logging (fire-and-forget)
    reportErrorToBackend(errorType, context, details);

    return {
      userMessage: userMessage,
      errorType: errorType,
      details: details,
    };
  };

  /**
   * Strip the "openai/" namespace prefix used by OpenRouter so that model
   * comparisons below (startsWith "gpt-5", "gpt-", "o1/o3/o4") still work
   * for slugs like "openai/gpt-5-mini" the same way they work for the bare
   * "gpt-5-mini" used when OpenAI is the direct provider.
   * @param {string} model
   * @returns {string}
   */
  const logModelDebug = function (payload, context, chatConfig) {
    debugLog("========== OpenAI API Call (" + context + ") ==========");
    debugLog("Model:", chatConfig.model || "(server-side)");
    debugLog("=".repeat(55));
  };

  /**
   * Compute the reasoning effort the SERVER will apply for this model.
   *
   * The reasoning override runs server-side (class-chat-api.php) right before
   * wp_remote_post, so it's never in the client's payload at log time. This
   * helper mirrors the server logic so the browser console can still show what
   * reasoning setting is in effect per request.
   *
   * @param {string} model  — full model slug (e.g. "openai/gpt-5-mini")
   * @returns {string}      — "minimal" | "none" | "(model default)" | "(native)"
   */
  const computeServerReasoning = function (model) {
    if (!model) return "(native)";
    var isOpenRouter = model.indexOf("/") !== -1;
    if (!isOpenRouter) return "(native — server sets per-model)";

    var cfg = window.aicwpChatConfig && window.aicwpChatConfig.chatConfig;
    var reasoningEnabled = cfg && cfg.openrouter_reasoning_enabled;
    if (reasoningEnabled) return "(model default — toggle ON)";

    // Reasoning disabled — server applies lowest-possible per vendor
    var reasoningMandatory =
      model.indexOf("openai/") === 0 ||
      model.indexOf("google/gemini-3.1-pro") !== -1;
    return reasoningMandatory ? "minimal" : "none";
  };

  /**
   * Log API request summary with model params
   * @param {Object} payload - The API payload
   */
  const logApiRequest = function (payload, model) {
    var params = { server_reasoning: computeServerReasoning(model) };
    debugLog("🚀 API REQUEST | Model:", model || "(server-side)", "| Params:", params);
  };

  /**
   * Generate loading indicator HTML based on configured style
   * @param {string} text - The loading text to display (e.g., "Thinking...", "Searching products...")
   * @return {string} HTML string for the loader
   */
  const generateLoaderHTML = function (text) {
    // Check loading style from config (default: 'spinner')
    var loadingStyle =
      typeof aicwpChatConfig !== "undefined" &&
      aicwpChatConfig.loadingStyle
        ? aicwpChatConfig.loadingStyle
        : "spinner";

    if (loadingStyle === "dots") {
      // Dots only animation (no text)
      return (
        '<div class="aicwp-chat-loader-wrapper">' +
        '<div class="aicwp-chat-typing-dots">' +
        "<span></span>" +
        "<span></span>" +
        "<span></span>" +
        "</div>" +
        "</div>"
      );
    }

    // Default: Spinner + Text with shimmer effect
    return (
      '<span class="aicwp-chat-loading"></span> <span class="aicwp-chat-shimmer-text">' +
      text +
      "</span>"
    );
  };

  /**
   * Log cart event for chat history tracking (fire-and-forget)
   */
  function logCartEvent(conversationId, productId, productName, quantity) {
    if (!conversationId || !productId || !aicwpChatConfig.wooCartEnabled) return;
    $.ajax({
      url: aicwpChatConfig.ajaxUrl,
      method: "POST",
      data: {
        action: "aicwp_log_cart_event",
        nonce: aicwpChatConfig.cartNonce,
        conversation_id: conversationId,
        product_id: productId,
        product_name: productName,
        quantity: quantity || 1,
      },
    });
  }

  /**
   * Escape string for safe HTML insertion
   */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;")
      .replace(/'/g, "&#039;");
  }

  // Flag to ensure global handlers are only bound once
  var globalHandlersBound = false;

  /**
   * Bind global event handlers (contact form) - only once, not per instance
   */
  function bindGlobalHandlers() {
    if (globalHandlersBound) {
      return;
    }
    globalHandlersBound = true;

    debugLog("Binding global contact form handlers (once)");

    // Contact form close button handler
    $(document).on("click", ".aicwp-contact-form-close", function (e) {
      e.preventDefault();
      var $overlay = $(this).closest(".aicwp-contact-form-overlay");
      $overlay.fadeOut(200);
      debugLog("Contact form closed");
    });

    // Contact form submission handler
    $(document).on("submit", ".aicwp-contact-form-body", function (e) {
      e.preventDefault();
      var $form = $(this);
      var $overlay = $form.closest(".aicwp-contact-form-overlay");
      var $submit = $form.find(".aicwp-contact-form-submit");
      var $msgDiv = $form.find(".aicwp-contact-form-message");
      var $buttonText = $submit.find(".button-text");
      var $spinner = $submit.find(".button-spinner");

      // Prevent double submission
      if ($submit.prop("disabled")) {
        debugLog("Contact form already submitting, ignoring");
        return;
      }

      // Get form data
      var name = $form.find('input[name="name"]').val().trim();
      var email = $form.find('input[name="email"]').val().trim();
      var msgContent = $form.find('textarea[name="message"]').val().trim();

      // Basic validation
      if (!name || !email || !msgContent) {
        $msgDiv
          .removeClass("success")
          .addClass("error")
          .text(
            aicwpChatConfig.strings.contactFormFillAll ||
              "Please fill in all fields.",
          )
          .show();
        return;
      }

      // Show loading state
      $submit.prop("disabled", true);
      $buttonText.hide();
      $spinner.show();
      $msgDiv.hide();

      // Submit form via REST API
      $.ajax({
        url: aicwpChatConfig.apiBase + "/contact-form",
        method: "POST",
        headers: getRequestHeaders(),
        contentType: "application/json",
        data: JSON.stringify({
          name: name,
          email: email,
          message: msgContent,
          source: "button",
        }),
        success: function (response) {
          debugLog("Contact form submitted successfully", response);
          $msgDiv
            .removeClass("error")
            .addClass("success")
            .text(
              response.message ||
                aicwpChatConfig.strings.contactFormSent ||
                "Message sent successfully!",
            )
            .show();

          // Reset form
          $form[0].reset();

          // Close overlay after 2 seconds
          setTimeout(function () {
            $overlay.fadeOut(200);
            $msgDiv.hide();
          }, 2000);
        },
        error: function (xhr) {
          debugLog("Contact form error", xhr);
          var errorMsg =
            aicwpChatConfig.strings.contactFormError ||
            "Failed to send message. Please try again.";
          if (xhr.responseJSON && xhr.responseJSON.message) {
            errorMsg = xhr.responseJSON.message;
          }
          $msgDiv
            .removeClass("success")
            .addClass("error")
            .text(errorMsg)
            .show();
        },
        complete: function () {
          $submit.prop("disabled", false);
          $buttonText.show();
          $spinner.hide();
        },
      });
    });

    // === WooCommerce Cart Handlers ===
    if (aicwpChatConfig.wooCartEnabled) {

      // Initialize cart badge count on load
      var initialCount = parseInt(aicwpChatConfig.cartCount) || 0;
      if (initialCount > 0) {
        $(".aicwp-cart-badge").text(initialCount).show();
      }

      // Add to Cart button click (inside product cards)
      $(document).on("click", ".aicwp-add-to-cart-btn:not(.aicwp-select-options-btn)", function (e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        if ($btn.hasClass("loading")) return;

        var productId = $btn.data("product-id");
        var productName = $btn.closest(".aicwp-listing-item").find(".aicwp-listing-title").text().trim();
        var $textSpan = $btn.find(".aicwp-atc-text");
        var originalText = $textSpan.text();

        // Get conversation ID from closest chat wrapper
        var $wrapper = $btn.closest(".aicwp-chat-wrapper");
        var conversationId = $wrapper.length ? $wrapper.data("session-id") || "" : "";

        $btn.addClass("loading");
        $textSpan.text(aicwpChatConfig.strings.addingToCart || "Adding...");

        $.ajax({
          url: aicwpChatConfig.ajaxUrl,
          method: "POST",
          data: {
            action: "aicwp_add_to_cart",
            nonce: aicwpChatConfig.cartNonce,
            product_id: productId,
            quantity: 1,
          },
          success: function (response) {
            if (response.success) {
              $textSpan.text(aicwpChatConfig.strings.addedToCart || "Added!");
              updateCartBadge(response.data.cart_count);
              logCartEvent(conversationId, productId, productName, 1);
              setTimeout(function () {
                $textSpan.text(originalText);
                $btn.removeClass("loading added");
              }, 1500);
              $btn.removeClass("loading").addClass("added");
            } else {
              $textSpan.text(response.data?.message || aicwpChatConfig.strings.cartErrorAdd || "Error");
              setTimeout(function () {
                $textSpan.text(originalText);
                $btn.removeClass("loading");
              }, 2000);
            }
          },
          error: function () {
            $textSpan.text(aicwpChatConfig.strings.cartErrorAdd || "Error");
            setTimeout(function () {
              $textSpan.text(originalText);
              $btn.removeClass("loading");
            }, 2000);
          },
        });
      });

      // Select Options click — navigate to product page
      $(document).on("click", ".aicwp-select-options-btn", function (e) {
        e.preventDefault();
        e.stopPropagation();
        var url = $(this).data("url");
        if (url) {
          window.location.href = url;
        }
      });

      // Cart toggle button click
      $(document).on("click", ".aicwp-chat-cart-toggle", function (e) {
        e.preventDefault();
        var $wrapper = $(this).closest(".aicwp-chat-wrapper, .aicwp-chat-container").find(".aicwp-cart-overlay");
        if (!$wrapper.length) {
          $wrapper = $(this).closest(".aicwp-floating-chat-popup, .aicwp-chat-shortcode-wrapper").find(".aicwp-cart-overlay");
        }
        $wrapper.fadeIn(200);
        loadCartContents($wrapper);
      });

      // Cart popup close
      $(document).on("click", ".aicwp-cart-popup-close", function (e) {
        e.preventDefault();
        $(this).closest(".aicwp-cart-overlay").fadeOut(200);
      });

      // Remove cart item
      $(document).on("click", ".aicwp-cart-item-remove", function (e) {
        e.preventDefault();
        var $item = $(this).closest(".aicwp-cart-item");
        var $overlay = $(this).closest(".aicwp-cart-overlay");
        var cartKey = $(this).data("cart-key");

        $item.css("opacity", "0.5");

        $.ajax({
          url: aicwpChatConfig.ajaxUrl,
          method: "POST",
          data: {
            action: "aicwp_remove_cart_item",
            nonce: aicwpChatConfig.cartNonce,
            cart_item_key: cartKey,
          },
          success: function (response) {
            if (response.success) {
              $item.slideUp(200, function () { $(this).remove(); });
              updateCartBadge(response.data.count);
              $overlay.find(".aicwp-cart-subtotal-amount").html(response.data.subtotal);
              if (response.data.count === 0) {
                $overlay.find(".aicwp-cart-popup-footer").hide();
                $overlay.find(".aicwp-cart-empty").show();
              }
            }
          },
        });
      });

      // Quantity minus
      $(document).on("click", ".aicwp-cart-qty-minus", function (e) {
        e.preventDefault();
        var $item = $(this).closest(".aicwp-cart-item");
        var $overlay = $(this).closest(".aicwp-cart-overlay");
        var cartKey = $item.data("cart-key");
        var $qtyVal = $item.find(".aicwp-cart-qty-value");
        var qty = parseInt($qtyVal.text()) - 1;

        if (qty <= 0) {
          $item.find(".aicwp-cart-item-remove").trigger("click");
          return;
        }

        $qtyVal.text(qty);
        updateCartItemQty($overlay, cartKey, qty);
      });

      // Quantity plus
      $(document).on("click", ".aicwp-cart-qty-plus", function (e) {
        e.preventDefault();
        var $item = $(this).closest(".aicwp-cart-item");
        var $overlay = $(this).closest(".aicwp-cart-overlay");
        var cartKey = $item.data("cart-key");
        var $qtyVal = $item.find(".aicwp-cart-qty-value");
        var qty = Math.min(parseInt($qtyVal.text()) + 1, 100);

        $qtyVal.text(qty);
        updateCartItemQty($overlay, cartKey, qty);
      });
    }

    function updateCartBadge(count) {
      var $badges = $(".aicwp-cart-badge");
      if (count > 0) {
        $badges.text(count).show();
      } else {
        $badges.hide();
      }
    }

    function updateCartItemQty($overlay, cartKey, qty) {
      $.ajax({
        url: aicwpChatConfig.ajaxUrl,
        method: "POST",
        data: {
          action: "aicwp_update_cart_qty",
          nonce: aicwpChatConfig.cartNonce,
          cart_item_key: cartKey,
          quantity: qty,
        },
        success: function (response) {
          if (response.success) {
            updateCartBadge(response.data.count);
            $overlay.find(".aicwp-cart-subtotal-amount").html(response.data.subtotal);
          }
        },
      });
    }

    function loadCartContents($overlay) {
      var $items = $overlay.find(".aicwp-cart-items");
      var $empty = $overlay.find(".aicwp-cart-empty");
      var $footer = $overlay.find(".aicwp-cart-popup-footer");

      $items.html('<div class="aicwp-cart-loading"><svg class="aicwp-cart-spinner" width="18" height="18" viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="2.5" stroke-dasharray="47" stroke-dashoffset="15" stroke-linecap="round"/></svg></div>');
      $empty.hide();
      $footer.hide();

      $.ajax({
        url: aicwpChatConfig.ajaxUrl,
        method: "POST",
        data: {
          action: "aicwp_get_cart",
          nonce: aicwpChatConfig.cartNonce,
        },
        success: function (response) {
          if (response.success && response.data.items.length > 0) {
            var html = "";
            response.data.items.forEach(function (item) {
              html += '<div class="aicwp-cart-item" data-cart-key="' + escHtml(item.key) + '">';
              html += '  <div class="aicwp-cart-item-image">';
              html += '    <img src="' + escHtml(item.thumbnail) + '" alt="' + escHtml(item.title) + '">';
              html += '  </div>';
              html += '  <div class="aicwp-cart-item-details">';
              html += '    <a href="' + escHtml(item.url) + '" class="aicwp-cart-item-title">' + escHtml(item.title) + '</a>';
              // price is HTML from wc_price() — intentionally rendered as HTML
              html += '    <div class="aicwp-cart-item-price">' + item.price + '</div>';
              html += '    <div class="aicwp-cart-item-qty">';
              html += '      <button class="aicwp-cart-qty-minus">-</button>';
              html += '      <span class="aicwp-cart-qty-value">' + parseInt(item.quantity) + '</span>';
              html += '      <button class="aicwp-cart-qty-plus">+</button>';
              html += '    </div>';
              html += '  </div>';
              html += '  <button class="aicwp-cart-item-remove" data-cart-key="' + escHtml(item.key) + '">';
              html += '    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
              html += '  </button>';
              html += '</div>';
            });
            $items.html(html);
            // subtotal is HTML from WC()->cart->get_cart_subtotal() — use .html() intentionally
            $overlay.find(".aicwp-cart-subtotal-amount").html(response.data.subtotal);
            $footer.show();
            $empty.hide();
          } else {
            $items.html("");
            $footer.hide();
            $empty.show();
          }
          updateCartBadge(response.data.count);
        },
        error: function () {
          $items.html("");
          $empty.show();
          $footer.hide();
        },
      });
    }
  }

  /**
   * Initialize custom tooltips for elements with data-chat-tooltip attribute
   */
  function initTooltips() {
    var $tooltip = null;
    var hideTimeout = null;

    // Create tooltip element if it doesn't exist
    function getTooltip() {
      if (!$tooltip) {
        $tooltip = $('<div class="aicwp-tooltip"></div>').appendTo('body');
      }
      return $tooltip;
    }

    // Show tooltip on hover
    $(document).on('mouseenter', '[data-chat-tooltip]', function() {
      var $el = $(this);
      var text = $el.attr('data-chat-tooltip');

      // Don't show tooltip if element has recording or transcribing state
      if (!text || $el.hasClass('recording') || $el.hasClass('transcribing')) return;

      clearTimeout(hideTimeout);

      var tooltip = getTooltip();
      tooltip.text(text);

      // Get element position relative to viewport (for fixed positioning)
      var rect = $el[0].getBoundingClientRect();
      var elWidth = rect.width;

      // Show tooltip to calculate its dimensions
      tooltip.css({
        visibility: 'hidden',
        display: 'block'
      });

      var tooltipWidth = tooltip.outerWidth();
      var tooltipHeight = tooltip.outerHeight();

      // Calculate position (centered above element, using viewport coordinates)
      var left = rect.left + (elWidth / 2);
      var top = rect.top - tooltipHeight - 8; // 8px gap + arrow

      // Ensure tooltip doesn't go off-screen
      if (left - (tooltipWidth / 2) < 10) {
        left = (tooltipWidth / 2) + 10;
      }
      if (left + (tooltipWidth / 2) > $(window).width() - 10) {
        left = $(window).width() - (tooltipWidth / 2) - 10;
      }

      tooltip.css({
        left: left,
        top: top,
        visibility: 'visible',
        display: 'block'
      });

      // Trigger animation
      setTimeout(function() {
        tooltip.addClass('visible');
      }, 10);
    });

    // Hide tooltip on mouse leave
    $(document).on('mouseleave', '[data-chat-tooltip]', function() {
      if ($tooltip) {
        $tooltip.removeClass('visible');
        hideTimeout = setTimeout(function() {
          if ($tooltip) {
            $tooltip.css('display', 'none');
          }
        }, 150);
      }
    });

    // Hide tooltip immediately when element gets recording/transcribing class
    // Using MutationObserver to detect class changes
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        if (mutation.type === 'attributes' && mutation.attributeName === 'class') {
          var $target = $(mutation.target);
          if ($target.hasClass('recording') || $target.hasClass('transcribing')) {
            if ($tooltip) {
              $tooltip.removeClass('visible').css('display', 'none');
            }
          }
        }
      });
    });

    // Observe mic buttons for class changes
    $('[data-chat-tooltip].aicwp-chat-mic-btn').each(function() {
      observer.observe(this, { attributes: true, attributeFilter: ['class'] });
    });

    // Also observe dynamically added mic buttons
    $(document).on('DOMNodeInserted', function(e) {
      var $el = $(e.target);
      if ($el.hasClass('aicwp-chat-mic-btn') && $el.attr('data-chat-tooltip')) {
        observer.observe(e.target, { attributes: true, attributeFilter: ['class'] });
      }
    });
  }

  // Initialize all chat instances
  $(document).ready(function () {
    debugLog("Initializing...");
    debugLog("jQuery loaded:", typeof $ !== "undefined");
    debugLog("Found chat wrappers:", $(".aicwp-chat-wrapper").length);

    // Check if config is loaded
    if (typeof aicwpChatConfig === "undefined") {
      console.error(
        "ERROR: aicwpChatConfig is not defined! Script may not be enqueued properly.",
      );
      return;
    }

    debugLog("Config:", aicwpChatConfig);

    // Initialize custom tooltips
    initTooltips();

    // Bind global handlers once (contact form)
    bindGlobalHandlers();

    $(".aicwp-chat-wrapper").each(function () {
      debugLog("Initializing chat instance:", $(this).attr("id"));
      try {
        new AicwpAIChat($(this));
      } catch (error) {
        console.error("Failed to initialize chat:", error);
      }
    });
  });

  /**
   * Chat class
   */
  function AicwpAIChat($wrapper) {
    this.$wrapper = $wrapper;
    this.chatId = $wrapper.attr("id");
    this.$messages = $wrapper.find(".aicwp-chat-messages");
    this.$input = $wrapper.find(".aicwp-chat-input");
    this.$sendBtn = $wrapper.find(".aicwp-chat-send-btn");

    // Menu dropdown elements
    this.$menuTrigger = $wrapper.find(".aicwp-chat-menu-trigger");
    this.$menuDropdown = $wrapper.find(".aicwp-chat-menu-dropdown");
    this.$clearBtn = $wrapper.find(".aicwp-chat-clear-btn");
    this.$expandBtn = $wrapper.find(".aicwp-chat-expand-btn");

    this.conversationHistory = [];
    this.chatConfig = null;
    this.isProcessing = false;
    this.configLoaded = false;
    this.storageKey = "aicwp_chat_" + this.chatId;
    this.rateLimitStorageKey = "aicwp_chat_rate_limit";

    // Generate unique session ID for conversation tracking
    this.sessionId = this.getOrCreateSessionId();
    // Store on wrapper for global handlers (e.g., cart button click)
    $wrapper.data("session-id", this.sessionId);

    // Read per-instance hideImages setting from data attribute (overrides global config)
    var dataHideImages = $wrapper.data("hide-images");
    this.hideImages =
      dataHideImages !== undefined
        ? parseInt(dataHideImages)
        : aicwpChatConfig.hideImages;

    debugLog("[Chat Init] Hide Images Config:", {
      dataAttribute: dataHideImages,
      globalConfig: aicwpChatConfig.hideImages,
      finalValue: this.hideImages,
    });

    // Rate limiting configuration (from backend or defaults)
    this.rateLimits = {
      tier1: { limit: aicwpChatConfig.rateLimits?.tier1 || 10, window: 60 }, // 10/min
      tier2: { limit: aicwpChatConfig.rateLimits?.tier2 || 30, window: 900 }, // 30/15min
      tier3: {
        limit: aicwpChatConfig.rateLimits?.tier3 || 100,
        window: 86400,
      }, // 100/day
    };

    // Loaded product context (for single product pages - WooCommerce)
    this.loadedProduct = null;
    this.loadedProductStorageKey = "aicwp_chat_loaded_product_" + this.chatId;

    // Track if chat has been expanded (for style 2)
    this.isExpanded = false;

    // Image input handling
    this.attachedImage = null; // { base64: string, mimeType: string, name: string }
    this.$imageBtn = $wrapper.find(".aicwp-chat-image-btn");
    this.$imageInput = $wrapper.find(".aicwp-chat-image-input");

    // Pre-chat fields state
    this.preChatCompleted = false;
    this.preChatData = null; // Array of { label, value } once submitted
    this.preChatDataSent = false; // Whether header was already sent with first message

    this.init();
  }

  AicwpAIChat.prototype = {
    /**
     * Initialize chat
     */
    init: function () {
      var self = this;

      debugLog("Chat init:", {
        chatId: this.chatId,
        hasMessages: this.$messages.length,
        hasInput: this.$input.length,
        hasSendBtn: this.$sendBtn.length,
      });

      // Disable send button until config loads
      this.$sendBtn.prop("disabled", true);

      // Load config (synchronous - uses inline data from wp_localize_script)
      this.loadConfig();

      // Load conversation from localStorage (this clears/restores messages)
      this.loadConversation();

      // Initialize pre-chat form AFTER loadConversation (which clears/restores messages)
      if (aicwpChatConfig.preChatFields && aicwpChatConfig.preChatFields.length > 0) {
        this.initPreChatForm();
      }

      // Load previously loaded product from localStorage (if any)
      this.loadPersistedProductContext();

      // Detect if we're on a single product page and add "Talk about X" button
      this.detectAndAddProductButton();

      // Restore expand/collapse state for floating widget
      this.restoreExpandState();

      // Event listeners
      this.$sendBtn.on("click", function () {
        debugLog("Send button clicked");
        self.sendMessage();
      });

      // Expand Style 2 chat on input focus when pre-chat form is enabled
      // (otherwise the form is hidden in the collapsed state)
      if (aicwpChatConfig.preChatFields && aicwpChatConfig.preChatFields.length > 0) {
        this.$input.on("focus", function () {
          if (!self.isExpanded && self.isStyle2()) {
            self.expandChat();
          }
        });
      }

      this.$input.on("keydown", function (e) {
        // Check isComposing for IME support (Korean, Japanese, Chinese)
        if (e.key === "Enter" && !e.shiftKey && !e.isComposing) {
          e.preventDefault();
          self.sendMessage();
        }
      });

      // Menu dropdown toggle
      this.$menuTrigger.on("click", function (e) {
        e.stopPropagation();
        self.toggleMenu();
      });

      // Close menu when clicking outside
      $(document).on("click", function (e) {
        if (!$(e.target).closest(".aicwp-chat-menu").length) {
          self.closeMenu();
        }
      });

      // Close menu on Escape key
      $(document).on("keydown", function (e) {
        if (e.key === "Escape") {
          self.closeMenu();
        }
      });

      // Clear conversation menu item
      this.$clearBtn.on("click", function () {
        debugLog("Clear button clicked");
        self.closeMenu();
        self.clearConversation();
      });

      // Expand/collapse chat menu item (floating widget only)
      this.$expandBtn.on("click", function () {
        debugLog("Expand/Collapse button clicked");
        self.closeMenu();
        self.toggleExpandChat();
      });

      // Image input button handling
      if (this.$imageBtn.length && this.$imageInput.length) {
        this.$imageBtn.on("click", function () {
          self.$imageInput.trigger("click");
        });

        this.$imageInput.on("change", function (e) {
          var file = e.target.files[0];
          if (file) {
            self.handleImageSelect(file);
          }
        });
      }

      // Show more button (event delegation for dynamically added buttons)
      this.$messages.on("click", ".aicwp-show-more-btn", function () {
        var $btn = $(this);
        var $list = $btn.prev(".aicwp-results-list");

        // Show all hidden listings
        $list
          .find(".aicwp-listing-hidden")
          .removeClass("aicwp-listing-hidden");

        // Hide the button
        $btn.remove();
      });

      // Popular search tag click handler (event delegation)
      $(document).on("click", ".popular-search-tag", function () {
        var $tag = $(this);
        var query = $tag.data("query");
        var $popularSearches = $tag.closest(".aicwp-popular-searches");
        var targetChatId = $popularSearches.data("chat-id");

        debugLog("Popular search tag clicked:", {
          query: query,
          targetChatId: targetChatId,
          currentChatId: self.chatId,
        });

        // Only handle if this tag is for the current chat instance
        if (targetChatId === self.chatId) {
          debugLog("Inserting popular search into input:", query);

          // Insert query into input field
          self.$input.val(query);

          // Focus on input
          self.$input.focus();

          // Optionally auto-send the message
          self.sendMessage();
        }
      });

      // Quick action button click handler (event delegation)
      $(document).on("click", ".aicwp-quick-btn", function (e) {
        e.preventDefault();
        var $btn = $(this);
        var type = $btn.data("type");
        var value = $btn.data("value");
        var buttonText = $btn.text().trim();
        var $chatWrapper = $btn.closest(".aicwp-chat-wrapper");
        var targetChatId = $chatWrapper.attr("id");

        debugLog("Quick button clicked:", {
          type: type,
          value: value,
          buttonText: buttonText,
          targetChatId: targetChatId,
          currentChatId: self.chatId,
        });

        // Only handle if this button is for the current chat instance
        if (targetChatId === self.chatId) {
          if (type === "url" && value) {
            // Open external URL in same tab
            window.location.href = value;
            debugLog("Opening URL:", value);
          } else if (type === "contact") {
            // Show contact form overlay
            var $overlay = $chatWrapper.find(".aicwp-contact-form-overlay");
            $overlay.fadeIn(200);
            $overlay.find('input[name="name"]').focus();
            debugLog("Showing contact form");
          } else if (type === "chat") {
            // Use value if set, otherwise use button text as the message
            var message = value || buttonText;
            if (message) {
              self.$input.val(message);
              self.$input.focus();
              self.sendMessage();
              debugLog("Sending quick message:", message);
            }
          }
        }
      });

      // Note: Contact form handlers are bound globally in bindGlobalHandlers()
      // to prevent duplicate bindings when multiple chat instances exist
    },

    /**
     * Load chat configuration from inline data (no API call needed)
     * Config is now passed via wp_localize_script to eliminate HTTP request
     */
    loadConfig: function () {
      var self = this;

      debugLog("[CONFIG] Loading chat config from inline data (no API call)");

      // Use inline config from wp_localize_script
      // This eliminates the /chat-config API call and avoids duplicate requests
      if (aicwpChatConfig.chatConfig) {
        self.chatConfig = aicwpChatConfig.chatConfig;
        self.configLoaded = true;

        debugLog("[CONFIG] ✅ Loaded from inline data:", {
          enabled: self.chatConfig.enabled,
          woocommerce_available: self.chatConfig.woocommerce_available,
          hasTools: self.chatConfig.hasTools,
          hasComplexTools: self.chatConfig.hasComplexTools,
        });

        // API notification is now shown in init() after loadConversation()
        // to prevent it being cleared by $messages.empty()

        // Enable send button now that config is ready
        self.$sendBtn.prop("disabled", false);

        // Enable product context button if present
        $(".aicwp-load-product-btn").prop("disabled", false);
      } else {
        // Fallback: chatConfig not available in inline data
        debugError("[CONFIG] chatConfig not found in aicwpChatConfig");
        self.addMessage(
          "system",
          "⚠️ Chat configuration not available. Please refresh the page.",
        );
        self.configLoaded = false;
      }
    },

    /**
     * Check if chat is in Style 2 (elementor-chat-style wrapper)
     */
    isStyle2: function () {
      return this.$wrapper.parent().hasClass("elementor-chat-style");
    },

    /**
     * Expand chat to 80vh (Style 2 only)
     * Called on first message or when loading past conversation
     */
    expandChat: function () {
      if (!this.isStyle2() || this.isExpanded) {
        return; // Already expanded or not Style 2
      }

      debugLog("Expanding chat to 80vh (Style 2 animation)");

      this.$wrapper.addClass("expanded");
      this.isExpanded = true;

      // Scroll to bottom after expansion animation completes
      var self = this;
      setTimeout(function () {
        self.$messages.scrollTop(self.$messages[0].scrollHeight);
      }, 600); // Match CSS transition duration
    },

    /**
     * Send message - Dual Mode Architecture
     *
     * Mode 1 (Tools available): Function Calling with chat tools
     *   Flow: User Question → OpenAI with tools → Tool calls → Execute → AI Answer
     *
     * Mode 2 (No tools): RAG-First Architecture
     *   Flow: User Question → Universal Search (top 3) → Get Content → AI Answer
     */
    sendMessage: function () {
      var self = this;
      var message = this.$input.val().trim();
      var hasImage = this.attachedImage !== null;

      // Allow sending if there's text OR an image
      if ((!message && !hasImage) || this.isProcessing) {
        return;
      }

      // Check if config is still loading
      if (!this.configLoaded) {
        this.addMessage("system", aicwpChatConfig.strings.loadingConfig);
        debugLog("[SEND] Config not loaded yet, waiting...");
        return;
      }

      // Check if config loaded
      if (!this.chatConfig) {
        this.addMessage("system", aicwpChatConfig.strings.errorConfig);
        return;
      }

      // Check if enabled
      if (!this.chatConfig.enabled) {
        this.addMessage("system", aicwpChatConfig.strings.chatDisabled);
        return;
      }

      // Check rate limits (client-side)
      var rateLimitCheck = this.checkRateLimit();
      if (!rateLimitCheck.allowed) {
        this.addMessage("system", rateLimitCheck.message);
        debugLog("[Rate Limit] Blocked:", rateLimitCheck);
        return;
      }

      // Record message timestamp for rate limiting
      this.recordMessage();

      // Check if this is the first real message (expand chat for Style 2)
      var isFirstMessage = this.conversationHistory.length === 0;

      // Build message content (with image if attached)
      var messageContent = this.buildMessageContent(message);
      var displayContent = hasImage ? this.getUserMessageDisplay(message) : message;

      // Store image reference before clearing
      var attachedImageData = this.attachedImage;

      // Add user message (with image preview if attached)
      this.addMessage("user", displayContent);
      this.$input.val("");
      this.isProcessing = true;
      this.$sendBtn.prop("disabled", true);

      // Clear attached image after sending
      if (hasImage) {
        this.clearAttachedImage();
      }

      // Expand chat on first message (Style 2 only)
      if (isFirstMessage) {
        this.expandChat();

        // Hide quick buttons after first message if configured
        if (aicwpChatConfig.quickButtonsVisibility === "hide_after_first") {
          var $quickBtns = self.$wrapper.find(".aicwp-chat-quick-buttons");
          $quickBtns.find(".aicwp-quick-btn").fadeOut(150);
          $quickBtns.delay(150).slideUp(200);
          debugLog("[Quick Buttons] Hidden after first message");
        }

        // Remove image header and welcome container after first message (floating widget only)
        if (aicwpChatConfig.hasImageHeader) {
          var $popup = self.$wrapper.closest('.aicwp-floating-chat-popup');
          if ($popup.length) {
            // Destroy animated canvas if present
            if (aicwpChatConfig.hasAnimatedHeader && typeof AicwpSilkWave !== 'undefined') {
              AicwpSilkWave.destroy();
            }
            $popup.removeClass('chat-image-header chat-image-header-overlay chat-animated-header');
            self.$messages.find('.chat-image-bg-welcome-text').remove();
            debugLog("[Image Header] Removed after first message");
          }
        }
      }

      // Add loading indicator
      var loadingId = "loading-" + Date.now();
      this.addMessage(
        "assistant",
        generateLoaderHTML(aicwpChatConfig.strings.searchingDatabase),
        loadingId,
      );

      // ALWAYS use function calling mode - LLM decides whether to search or answer from context
      debugLog("===== FUNCTION CALLING MODE =====");
      debugLog(
        "Tools available:",
        this.chatConfig.hasTools ? 1 : 0,
      );
      debugLog(
        "LLM will decide whether to call tools or answer directly from conversation context",
      );
      debugLog("Has image:", !!attachedImageData);
      this.sendMessageWithFunctionCalling(messageContent, loadingId);
    },

    /**
     * Send message with function calling (tools mode)
     * OpenAI decides which tool to call, we execute it, and send results back
     */
    sendMessageWithFunctionCalling: function (userMessage, loadingId) {
      var self = this;

      // Check if product tools are available (need more history for complex flows)
      var hasComplexTools = self.chatConfig.hasComplexTools;

      // Get valid history slice, respecting admin context length setting
      var contextMultipliers = { short: 1, normal: 2, long: 6 };
      var ctxMul = contextMultipliers[aicwpChatConfig && aicwpChatConfig.contextLength || 'normal'] || 3;
      var baseLimit = hasComplexTools ? 12 : 6;
      var recentHistory = self.getValidHistorySlice(baseLimit * ctxMul);

      // Build messages array (server handles system prompt injection for security)
      var messages = recentHistory.concat([
        { role: "user", content: userMessage },
      ]);

      // Build payload (model and tools are handled server-side)
      var payload = {
        messages: messages,
      };

      // Check if message was from speech-to-text transcription
      if (self.$wrapper.data('speech-pending')) {
        payload.is_transcribed = true;
        self.$wrapper.data('speech-pending', false); // Clear flag after use
        debugLog('[Speech-to-Text] Message was transcribed from voice');
      }

      // If product context is loaded (via "Talk about this product" button),
      // send product ID so server can inject context into system prompt
      if (self.loadedProduct && self.loadedProduct.id) {
        payload.product_context_id = self.loadedProduct.id;
        debugLog(
          "[Product Context] Sending product_context_id:",
          self.loadedProduct.id,
        );
      }

      // Tell server to use auto tool choice when tools are available
      if (self.chatConfig.hasTools) {
        payload.tool_choice = "auto";
      }

      logModelDebug(payload, "Function Calling", self.chatConfig);

      debugLog("===== SENDING TO CHAT-PROXY =====");
      debugLog("Messages:", messages.length);
      debugLog("Tools (server-side):", self.chatConfig.hasTools ? 1 : 0);

      logApiRequest(payload, self.chatConfig.model);

      // Send to OpenAI - no retry to avoid duplicate paid provider calls
        $.ajax({
          url: aicwpChatConfig.apiBase + "/chat-proxy",
          method: "POST",
          headers: $.extend({}, getRequestHeaders(), {
            "X-Session-ID": self.sessionId,
          }, self.getPreChatHeaders()),
          data: JSON.stringify(payload),
          success: function (data) {
            // Check if response indicates an error (success: false)
            if (data.success === false) {
              var errorDetail =
                data.error?.message || data.error?.type || "Unknown error";
              debugError("Chat proxy error:", data.error);
              self.$messages.find("#" + loadingId).remove();
              self.addMessage(
                "system",
                data.error?.message || aicwpChatConfig.strings.errorGeneral,
              );
              self.isProcessing = false;
              self.$sendBtn.prop("disabled", false);
              return;
            }

            var assistantMessage = data.choices[0].message;

            debugLog("===== OPENAI RESPONSE =====");
            debugLog("Has tool_calls:", !!assistantMessage.tool_calls);

            // Check if AI wants to call tools
            if (
              assistantMessage.tool_calls &&
              assistantMessage.tool_calls.length > 0
            ) {
              debugLog(
                "Tool calls requested:",
                assistantMessage.tool_calls.length,
              );

              // Update loading message
              self.$messages
                .find("#" + loadingId + " .aicwp-chat-message-content")
                .html(
                  generateLoaderHTML(
                    aicwpChatConfig.strings.searchingDatabase,
                  ),
                );

              // Execute tool calls
              self.executeToolCalls(userMessage, assistantMessage, loadingId);
            } else {
              // No tools called - AI responded directly (greetings, clarifications, simple questions)
              debugLog("No tool calls, displaying direct response");
              debugLog(
                "This handles: greetings, thanks, clarifications, simple questions",
              );

              // Remove loading
              self.$messages.find("#" + loadingId).remove();

              // Display AI's direct response
              var content = assistantMessage.content || "No response received.";
              self.addMessage("assistant", content);

              // Update conversation history
              self.conversationHistory.push(
                { role: "user", content: userMessage },
                { role: "assistant", content: content },
              );

              // Save conversation
              self.saveConversation();

              self.isProcessing = false;
              self.$sendBtn.prop("disabled", false);
            }
          },
          error: function (xhr) {
            var errorInfo = analyzeError(xhr, "chat-proxy");
            self.$messages.find("#" + loadingId).remove();
            self.addMessage(
              "system",
              errorInfo.userMessage,
            );
            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
          },
        });
    },

    /**
     * Execute tool calls (search_universal_content, WooCommerce tools, contact form, etc.)
     */
    executeToolCalls: function (userMessage, assistantMessage, loadingId) {
      var self = this;
      var toolCall = assistantMessage.tool_calls[0]; // Handle first tool call
      var functionName = toolCall.function.name;
      var functionArgs = JSON.parse(toolCall.function.arguments);

      debugLog("===== EXECUTING TOOL CALL =====");
      debugLog("Function:", functionName);
      debugLog("Arguments:", functionArgs);

      if (functionName === "search_universal_content") {
        // Search universal content (posts/pages/products/docs)
        debugLog("Calling universal content search for:", functionArgs.query);

        self.$messages
          .find("#" + loadingId + " .aicwp-chat-message-content")
          .html(
            generateLoaderHTML(aicwpChatConfig.strings.searchingSiteContent),
          );

        // Call RAG endpoint directly - it handles everything server-side
        // IMPORTANT: Don't pass chat_history here because:
        // 1. RAG endpoint doesn't use function calling
        // 2. Chat history may contain tool messages that will break the request
        // 3. This is being called AS a tool, so history isn't needed
        // No retry to avoid duplicate paid provider calls
        // Check if user message contains an image (for chat history logging)
        var messageHasImage = Array.isArray(userMessage) && userMessage.some(function(part) {
          return part.type === 'image_url';
        });

          // No IIFE wrapper needed - no retry
          $.ajax({
            url: aicwpChatConfig.apiBase + "/rag-chat",
            method: "POST",
            headers: $.extend({}, getRequestHeaders(), {
              "X-Session-ID": self.sessionId,
            }, self.getPreChatHeaders()),
            data: JSON.stringify({
              query: functionArgs.query,
              original_question: self.extractTextFromMessage(userMessage), // Text only, no images
              chat_history: [], // Empty history - this is a tool call, not a conversation
              top_results: functionArgs.top_results || 5, // Server uses DB setting, this is just fallback
              has_image: messageHasImage, // For chat history logging
              post_ids: functionArgs.post_ids || null, // Specific post IDs to search within
            }),
            success: function (response) {
              if (response.success) {
                debugLog(
                  "RAG response received:",
                  response.sources?.length || 0,
                  "sources",
                );

                // Remove loading
                self.$messages.find("#" + loadingId).remove();

                // Handle empty answer from RAG endpoint
                var answer = response.answer;
                if (!answer || answer.trim() === "") {
                  debugError("RAG returned empty answer - check server logs");
                  answer =
                    aicwpChatConfig.strings.noResultsGeneric ||
                    "I couldn't find relevant information. Try different keywords or be more specific about what you're looking for.";
                }

                // Display answer
                self.addMessage("assistant", answer);

                // Display source attribution (if sources exist)
                if (response.sources && response.sources.length > 0) {
                  var sourcesHTML = self.formatSourceAttribution(
                    response.sources,
                  );
                  self.addMessage("assistant", sourcesHTML);
                }

                // Update conversation history - MUST include complete tool calling sequence
                self.conversationHistory.push(
                  { role: "user", content: userMessage },
                  assistantMessage, // Assistant message WITH tool_calls
                  {
                    role: "tool",
                    tool_call_id: toolCall.id,
                    content: JSON.stringify({
                      answer: answer,
                      sources: response.sources || [],
                    }),
                  },
                  { role: "assistant", content: answer }, // Final response
                );

                // Save conversation
                self.saveConversation();

                self.isProcessing = false;
                self.$sendBtn.prop("disabled", false);
              } else {
                debugError("RAG endpoint error:", response.error);
                self.$messages.find("#" + loadingId).remove();
                self.addMessage(
                  "system",
                  response.error?.message || aicwpChatConfig.strings.errorGeneral,
                );
                self.isProcessing = false;
                self.$sendBtn.prop("disabled", false);
              }
            },
            error: function (xhr) {
                // No retry - XHR error may mean the provider already processed (and billed) the request
                var errorInfo = analyzeError(xhr, "rag-universal-search");
                self.$messages.find("#" + loadingId).remove();
                self.addMessage(
                  "system",
                  errorInfo.userMessage,
                );
                self.isProcessing = false;
                self.$sendBtn.prop("disabled", false);
            },
          });
      } else if (functionName === "search_products") {
        // Search WooCommerce products
        self.$messages
          .find("#" + loadingId + " .aicwp-chat-message-content")
          .html(
            generateLoaderHTML(aicwpChatConfig.strings.searchingProducts),
          );

        var productSearchArgs = $.extend({}, functionArgs, { source: "chatbot" });

        $.ajax({
          url: aicwpChatConfig.apiBase + "/woocommerce-product-search",
          method: "POST",
          contentType: "application/json",
          data: JSON.stringify(productSearchArgs),
          success: function (response) {
            if (
              response.success &&
              response.results &&
              response.results.length > 0
            ) {
              debugLog("Product search results:", response.results.length);

              // Show "selecting best matches" while LLM filters
              self.$messages
                .find("#" + loadingId + " .aicwp-chat-message-content")
                .html(
                  generateLoaderHTML(
                    aicwpChatConfig.strings.selectingBestMatches ||
                      "Analyzing results...",
                  ),
                );

              // Send results to LLM for filtering + response
              self.getFinalResponse(
                userMessage,
                assistantMessage,
                toolCall,
                response,
                loadingId,
                "search_products",
              );
            } else {
              // No results - still need to show thinking indicator
              debugLog("No products found");

              // Update loading indicator to show AI is thinking about no results
              self.$messages
                .find("#" + loadingId + " .aicwp-chat-message-content")
                .html(
                  generateLoaderHTML(
                    aicwpChatConfig.strings.thinkingAboutQuery ||
                      "Thinking...",
                  ),
                );

              var noResultsMsg = {
                total: 0,
                results: [],
              };

              // Still send to OpenAI so it can respond naturally
              self.getFinalResponse(
                userMessage,
                assistantMessage,
                toolCall,
                noResultsMsg,
                loadingId,
              );
            }
          },
          error: function (xhr) {
            var errorInfo = analyzeError(xhr, "search_products");
            self.$messages.find("#" + loadingId).remove();
            self.addMessage(
              "system",
              errorInfo.userMessage,
            );
            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
          },
        });
      } else if (functionName === "get_product_details") {
        // Get WooCommerce product details (supports multiple IDs for comparison)
        // Normalize to array for both legacy (product_id) and new (product_ids) formats
        var productIds = functionArgs.product_ids || [functionArgs.product_id];
        if (!Array.isArray(productIds)) {
          productIds = [productIds];
        }

        var loadingText = productIds.length > 1
          ? (aicwpChatConfig.strings.comparingProducts || "Comparing products...")
          : aicwpChatConfig.strings.gettingProductDetails;

        self.$messages
          .find("#" + loadingId + " .aicwp-chat-message-content")
          .html(generateLoaderHTML(loadingText));

        self.getProductDetails(
          productIds,
          userMessage,
          assistantMessage,
          toolCall,
          loadingId,
        );
      } else if (functionName === "check_order_status") {
        // Check WooCommerce order status
        self.$messages
          .find("#" + loadingId + " .aicwp-chat-message-content")
          .html(
            generateLoaderHTML(aicwpChatConfig.strings.checkingOrderStatus),
          );

        self.getOrderStatus(
          functionArgs.order_number,
          functionArgs.billing_email,
          userMessage,
          assistantMessage,
          toolCall,
          loadingId,
        );
      } else if (functionName === "add_to_cart") {
        // Add product to cart via AI tool
        debugLog("Adding to cart via AI tool", functionArgs);

        $.ajax({
          url: aicwpChatConfig.ajaxUrl,
          method: "POST",
          data: {
            action: "aicwp_add_to_cart",
            nonce: aicwpChatConfig.cartNonce,
            product_id: functionArgs.product_id,
            quantity: functionArgs.quantity || 1,
          },
          success: function (response) {
            debugLog("Add to cart response:", response);

            var toolResult;
            if (response.success) {
              toolResult = {
                success: true,
                message: "Product added to cart successfully.",
                cart_count: response.data.cart_count,
                cart_subtotal: response.data.cart_subtotal,
              };
              // Update cart badge
              $(".aicwp-cart-badge").text(response.data.cart_count).show();
              // Log cart event for chat history
              logCartEvent(self.sessionId, functionArgs.product_id, "", functionArgs.quantity || 1);
            } else {
              toolResult = {
                success: false,
                message: response.data?.message || "Could not add to cart.",
              };
            }

            self.$messages.find("#" + loadingId).remove();

            var responseLoadingId = "loading-response-" + Date.now();
            self.addMessage(
              "assistant",
              generateLoaderHTML(aicwpChatConfig.strings.loading),
              responseLoadingId,
            );

            self.getFinalResponse(
              userMessage,
              assistantMessage,
              toolCall,
              toolResult,
              responseLoadingId,
            );
          },
          error: function (xhr) {
            debugError("Add to cart error:", xhr);

            var toolResult = {
              success: false,
              message: xhr.responseJSON?.data?.message || "Could not add to cart.",
            };

            self.$messages.find("#" + loadingId).remove();

            var responseLoadingId = "loading-response-" + Date.now();
            self.addMessage(
              "assistant",
              generateLoaderHTML(aicwpChatConfig.strings.loading),
              responseLoadingId,
            );

            self.getFinalResponse(
              userMessage,
              assistantMessage,
              toolCall,
              toolResult,
              responseLoadingId,
            );
          },
        });
      } else if (functionName === "send_contact_message") {
        // Send contact message via AI
        debugLog("Sending contact message via AI tool");
        self.$messages
          .find("#" + loadingId + " .aicwp-chat-message-content")
          .html(
            generateLoaderHTML(
              aicwpChatConfig.strings.sendingMessage || "Sending message...",
            ),
          );

        $.ajax({
          url: aicwpChatConfig.apiBase + "/contact-form",
          method: "POST",
          headers: getRequestHeaders(),
          contentType: "application/json",
          processData: false,
          data: JSON.stringify({
            name: functionArgs.name || "",
            email: functionArgs.email || "",
            message: functionArgs.message || "",
            source: "llm",
            conversation_id: self.sessionId,
          }),
          success: function (response) {
            debugLog("Contact form response:", response);

            var toolResult = {
              success: response.success || false,
              message:
                response.message ||
                (response.success
                  ? "Message sent successfully!"
                  : "Failed to send message."),
            };

            // Remove loading
            self.$messages.find("#" + loadingId).remove();

            // Add loading for AI response
            var responseLoadingId = "loading-response-" + Date.now();
            self.addMessage(
              "assistant",
              generateLoaderHTML(aicwpChatConfig.strings.loading),
              responseLoadingId,
            );

            // Send result back to OpenAI for natural language response
            self.getFinalResponse(
              userMessage,
              assistantMessage,
              toolCall,
              toolResult,
              responseLoadingId,
            );
          },
          error: function (xhr) {
            debugError("Contact form error:", xhr);

            var errorMessage = "Failed to send message.";
            if (xhr.responseJSON && xhr.responseJSON.message) {
              errorMessage = xhr.responseJSON.message;
            }

            var toolResult = {
              success: false,
              message: errorMessage,
            };

            // Remove loading
            self.$messages.find("#" + loadingId).remove();

            // Add loading for AI response
            var responseLoadingId = "loading-response-" + Date.now();
            self.addMessage(
              "assistant",
              generateLoaderHTML(aicwpChatConfig.strings.loading),
              responseLoadingId,
            );

            // Send error result back to OpenAI
            self.getFinalResponse(
              userMessage,
              assistantMessage,
              toolCall,
              toolResult,
              responseLoadingId,
            );
          },
        });
      } else {
        debugError("Unknown function:", functionName);
        self.$messages.find("#" + loadingId).remove();
        self.addMessage("system", aicwpChatConfig.strings.unknownFunction);
        self.isProcessing = false;
        self.$sendBtn.prop("disabled", false);
      }
    },

    /**
     * Build enriched context from retrieved content
     */
    buildEnrichedContext: function (contentResponses, sourceResults) {
      var context = "RELEVANT CONTENT FROM SITE:\n\n";

      contentResponses.forEach(function (response, index) {
        // Handle both direct response and array response from $.when
        var data = response[0] || response;

        if (data.success && data.structured_content) {
          var source = sourceResults[index];
          context += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
          context += "SOURCE " + (index + 1) + ": " + data.title + "\n";
          context +=
            "Type: " +
            data.post_type.charAt(0).toUpperCase() +
            data.post_type.slice(1) +
            "\n";
          context += "URL: " + data.url + "\n";
          context += "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
          context += data.structured_content + "\n\n\n";
        }
      });

      return context;
    },

    /**
     * Answer with RAG (server-side search + context + OpenAI in ONE call)
     * Simple non-streaming AJAX approach
     */
    answerWithContext: function (
      userMessage,
      enrichedContext,
      sourceResults,
      loadingId,
    ) {
      var self = this;

      // Build conversation history, respecting admin context length setting
      var contextMultipliers = { short: 1, normal: 2, long: 6 };
      var ctxMul = contextMultipliers[aicwpChatConfig && aicwpChatConfig.contextLength || 'normal'] || 3;
      var recentHistory = [];
      var historySlice = self.conversationHistory.slice(-(6 * ctxMul));
      historySlice.forEach(function (msg) {
        recentHistory.push({ role: msg.role, content: msg.content });
      });

      // Check if user message contains an image (for chat history logging)
      var messageHasImage = Array.isArray(userMessage) && userMessage.some(function(part) {
        return part.type === 'image_url';
      });

      var payload = {
        query: userMessage,
        chat_history: recentHistory,
        top_results: 5, // Server uses DB setting, this is just fallback
        has_image: messageHasImage, // For chat history logging
      };

      debugLog("===== CALLING RAG ENDPOINT (SERVER-SIDE RAG) =====");
      debugLog("🔍 Query:", userMessage);
      debugLog("📚 Chat history length:", recentHistory.length, "messages");
      debugLog("🎯 Top results to retrieve:", payload.top_results);
      debugLog("🌐 Endpoint:", aicwpChatConfig.apiBase + "/rag-chat");
      debugLog("📦 Full payload:", payload);
      debugLog("===== SYSTEM PROMPT =====");
      debugLog("(Generated server-side for security)");
      debugLog("===== END SYSTEM PROMPT =====");

      // No retry to avoid duplicate paid provider calls
        $.ajax({
          url: aicwpChatConfig.apiBase + "/rag-chat",
          method: "POST",
          headers: $.extend({}, getRequestHeaders(), {
            "X-Session-ID": self.sessionId,
          }),
          data: JSON.stringify(payload),
          success: function (response) {
            debugLog("===== RAG ENDPOINT RESPONSE =====");
            debugLog("✅ Response received");
            debugLog("📄 Full response:", response);

            if (!response.success) {
              debugError("RAG endpoint returned success:false");
              debugError("Error object:", response.error);
              self.$messages.find("#" + loadingId).remove();
              self.addMessage(
                "system",
                response.error?.message || aicwpChatConfig.strings.errorGeneral,
              );
              self.isProcessing = false;
              self.$sendBtn.prop("disabled", false);
              return;
            }

            var answer = response.answer;
            var sources = response.sources || [];

            debugLog("===== RAG RESPONSE DETAILS =====");
            if (response.query_info) {
              debugLog("📝 Query Transformation:");
              debugLog("  Original query:", response.query_info.original_query);
              debugLog(
                "  Optimized query:",
                response.query_info.optimized_query,
              );
            }
            debugLog("💬 Answer length:", answer ? answer.length : 0, "chars");
            debugLog("📚 Sources found:", sources.length);
            if (sources.length > 0) {
              debugLog("📖 Source details:");
              sources.forEach(function (source, idx) {
                debugLog(
                  "  " +
                    (idx + 1) +
                    ". " +
                    source.title +
                    " (" +
                    source.type +
                    ") - " +
                    source.url,
                );
              });
            } else {
              debugLog("⚠️  No sources returned from search");
            }
            debugLog("⏱️  Performance:", response.performance);
            debugLog("🤖 Model used:", response.model);
            if (response.usage) {
              debugLog("💰 Token usage:", response.usage);
            }

            // Remove loading
            self.$messages.find("#" + loadingId).remove();

            // Display answer
            if (answer) {
              self.addMessage("assistant", answer);
              debugLog("✅ Answer displayed to user");
            } else {
              debugError("⚠️  Empty answer received");
            }

            // Display source attribution (if sources exist)
            if (sources && sources.length > 0) {
              var sourcesHTML = self.formatSourceAttribution(sources);
              self.addMessage("assistant", sourcesHTML);
              debugLog("✅ Source attribution displayed");
            }

            // Update conversation history (simple, no tool calls)
            self.conversationHistory.push(
              { role: "user", content: userMessage },
              { role: "assistant", content: answer },
            );

            // Save conversation
            self.saveConversation();

            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
            debugLog("✅ RAG flow completed successfully");
          },
          error: function (xhr) {
              var errorInfo = analyzeError(xhr, "rag-chat");
              self.$messages.find("#" + loadingId).remove();
              self.addMessage(
                "system",
                errorInfo.userMessage,
              );
              self.isProcessing = false;
              self.$sendBtn.prop("disabled", false);
          },
        });
    },

    /**
     * Format source attribution cards
     */
    formatSourceAttribution: function (sourceResults) {
      // var self = this;
      // var html = '<div class="aicwp-sources">';
      // html += '<div class="aicwp-sources-label">📚 Sources used:</div>';
      // html += '<div class="aicwp-sources-list">';
      // sourceResults.forEach(function(source, index) {
      //     var postTypeLabel = source.post_type ? source.post_type.charAt(0).toUpperCase() + source.post_type.slice(1) : 'Content';
      //     var icon = self.getIconForPostType(source.post_type);
      //     html += '<a href="' + source.url + '" class="aicwp-source-card" target="_blank">';
      //     html += '  <span class="source-icon">' + icon + '</span>';
      //     html += '  <span class="source-info">';
      //     html += '    <span class="source-title">' + source.title + '</span>';
      //     html += '    <span class="source-type">' + postTypeLabel + '</span>';
      //     html += '  </span>';
      //     html += '</a>';
      // });
      // html += '</div></div>';
      // return html;
    },

    /**
     * Get icon for post type
     */
    getIconForPostType: function (postType) {
      var icons = {
        post: '<i class="fa fa-file-text-o"></i>',
        page: '<i class="fa fa-file-o"></i>',
        product: '<i class="fa fa-shopping-cart"></i>',
      };
      return icons[postType] || '<i class="fa fa-file-o"></i>';
    },

    /**
     * Universal content search via API (any post type)
     */
    searchContent: function (params, callback) {
      $.ajax({
        url: aicwpChatConfig.apiBase + "/universal-search",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(params),
        success: function (response) {
          callback(response);
        },
        error: function (xhr) {
          callback({
            success: false,
            error: xhr.responseJSON?.error || "Universal search failed",
          });
        },
      });
    },

    /**
     * Get content details via API (any post type)
     */
    getContentDetails: function (
      postId,
      userMessage,
      assistantMessage,
      toolCall,
      loadingId,
    ) {
      var self = this;

      $.ajax({
        url: aicwpChatConfig.apiBase + "/get-content",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({ post_id: postId }),
        success: function (response) {
          if (response.success) {
            debugLog("===== CONTENT DETAILS =====");
            debugLog("Post ID:", response.post_id);
            debugLog("Post Type:", response.post_type);
            debugLog("Title:", response.title);
            debugLog(
              "Structured content length:",
              response.structured_content.length,
            );

            // Update loading message
            self.$messages
              .find("#" + loadingId + " .aicwp-chat-message-content")
              .html(
                generateLoaderHTML(aicwpChatConfig.strings.analyzingContent),
              );

            // Send structured content to OpenAI for natural response
            self.getDetailsResponse(
              userMessage,
              assistantMessage,
              toolCall,
              response,
              loadingId,
            );
          } else {
            self.$messages.find("#" + loadingId).remove();
            self.addMessage(
              "system",
              aicwpChatConfig.strings.contentNotFound,
            );
            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
          }
        },
        error: function (xhr) {
          self.$messages.find("#" + loadingId).remove();
          self.addMessage(
            "system",
            aicwpChatConfig.strings.errorGettingContent,
          );
          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Format universal content grid (for posts, pages, products, etc.)
     */
    formatContentGrid: function (results) {
      var self = this;
      var html = '<div class="aicwp-results-list">';

      results.forEach(function (item, index) {
        var thumbnail =
          item.featured_image || aicwpChatConfig.placeholderImage || "";
        var title = item.title || "Untitled";
        var excerpt = item.excerpt || "";
        var postType = item.post_type || "post";
        var url = item.url || "#";

        // Best Match badge for first result
        var bestMatchBadge = "";
        if (index === 0) {
          bestMatchBadge =
            '<div class="match-badge best">' +
            (aicwpChatConfig.strings.bestMatch || "Best Match") +
            "</div>";
        }

        // Add hidden class to items after the first 3
        var hiddenClass = index >= 3 ? " aicwp-listing-hidden" : "";

        // Format post type label
        var postTypeLabel =
          postType.charAt(0).toUpperCase() + postType.slice(1);

        html +=
          '<a href="' +
          url +
          '" class="aicwp-listing-item' +
          hiddenClass +
          '">';

        // Only render thumbnail if hideImages is not enabled
        if (!self.hideImages && thumbnail) {
          html += '  <div class="aicwp-listing-thumbnail">';
          html += '    <img src="' + thumbnail + '" alt="' + title + '">';
          html += "  </div>";
        }

        html += '  <div class="aicwp-listing-details">';
        html += '    <div class="aicwp-listing-main">';
        html +=
          '      <h3 class="aicwp-listing-title">' +
          title +
          " " +
          bestMatchBadge +
          "</h3>";
        if (excerpt) {
          html +=
            '      <p class="aicwp-listing-excerpt">' + excerpt + "</p>";
        }
        html += '      <div class="aicwp-listing-meta">';
        html +=
          '        <span class="content-type"><i class="fa fa-file-text-o"></i> ' +
          postTypeLabel +
          "</span>";
        html += "      </div>";
        html += "    </div>";
        html += "  </div>";
        html += "</a>";
      });

      html += "</div>";

      // Add "Show more" button if more than 3 results
      if (results.length > 3) {
        html +=
          '<button class="aicwp-show-more-btn">' +
          aicwpChatConfig.strings.showMore.replace(
            "%d",
            results.length - 3,
          ) +
          "</button>";
      }

      return html;
    },

    /**
     * Safely slice conversation history without breaking tool calling sequences
     * OpenAI requires: assistant[tool_calls] → tool → assistant (complete sequence)
     */
    getValidHistorySlice: function (maxMessages) {
      // FIX: Always validate history, not just when slicing
      // Previously, history <= maxMessages was returned without validation,
      // causing API errors when corrupted tool_calls sequences existed
      var sliced = this.conversationHistory.length <= maxMessages
        ? this.conversationHistory.slice() // Copy to avoid mutating original
        : this.conversationHistory.slice(-maxMessages);

      // Remove any orphaned 'tool' messages at the start (tool without preceding assistant)
      while (sliced.length > 0 && sliced[0].role === "tool") {
        debugLog(
          "[History Validation] Removing orphaned tool message from start",
        );
        sliced.shift();
      }

      // Comprehensive validation: scan entire array and remove incomplete sequences
      var validated = [];
      for (var i = 0; i < sliced.length; i++) {
        var msg = sliced[i];

        // If this is an assistant message with tool_calls
        if (msg.role === "assistant" && msg.tool_calls) {
          // Check if next message is a tool response
          var nextMsg = sliced[i + 1];
          if (!nextMsg || nextMsg.role !== "tool") {
            debugLog(
              "[History Validation] Removing assistant+tool_calls at index " +
                i +
                " - no tool response follows",
            );
            // Skip this message and all remaining (incomplete sequence at end)
            break;
          }

          // Valid sequence start - add assistant message
          validated.push(msg);
        } else if (msg.role === "tool") {
          // Tool message should only appear after assistant+tool_calls (already validated above)
          validated.push(msg);
        } else {
          // Regular user or assistant message (no tool_calls)
          validated.push(msg);
        }
      }

      debugLog(
        "[History Validation] Original: " +
          sliced.length +
          " messages, Validated: " +
          validated.length +
          " messages",
      );
      return validated;
    },

    /**
     * Get product details via API (WooCommerce) - supports multiple IDs for comparison
     * @param {Array} productIds - Array of product IDs to fetch
     */
    getProductDetails: function (
      productIds,
      userMessage,
      assistantMessage,
      toolCall,
      loadingId,
    ) {
      var self = this;

      // Ensure we have an array
      if (!Array.isArray(productIds)) {
        productIds = [productIds];
      }

      // Build params - use product_ids for multiple, product_id for single (backward compat)
      var param = productIds.length > 1
        ? { product_ids: productIds }
        : { product_id: productIds[0] };

      debugLog("[getProductDetails] Fetching products:", productIds);

      $.ajax({
        url: aicwpChatConfig.apiBase + "/woocommerce-product-details",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(param),
        success: function (response) {
          if (response.success) {
            debugLog("===== PRODUCT DETAILS =====");

            // Normalize response structure for getDetailsResponse
            // Backend returns different formats for single vs multiple
            var normalizedResponse;
            if (response.products && Array.isArray(response.products)) {
              // Multiple products - combine structured content
              debugLog("Multiple products:", response.count);
              var combinedContent = response.products.map(function(product, index) {
                return "=== PRODUCT " + (index + 1) + ": " + product.title + " ===\n" +
                       "URL: " + product.url + "\n\n" +
                       product.structured_content;
              }).join("\n\n" + "=".repeat(50) + "\n\n");

              normalizedResponse = {
                success: true,
                product_id: response.products.map(function(p) { return p.product_id; }).join(", "),
                title: response.products.map(function(p) { return p.title; }).join(" vs "),
                structured_content: combinedContent,
                is_comparison: true,
                count: response.count
              };
            } else {
              // Single product - use as-is
              debugLog("Product ID:", response.product_id);
              debugLog("Title:", response.title);
              normalizedResponse = response;
            }

            debugLog("Structured content length:", normalizedResponse.structured_content.length);

            // Update loading message
            var loadingText = normalizedResponse.is_comparison
              ? (aicwpChatConfig.strings.comparingProducts || "Comparing products...")
              : aicwpChatConfig.strings.analyzingProduct;

            self.$messages
              .find("#" + loadingId + " .aicwp-chat-message-content")
              .html(generateLoaderHTML(loadingText));

            // Send structured content to OpenAI for natural response
            self.getDetailsResponse(
              userMessage,
              assistantMessage,
              toolCall,
              normalizedResponse,
              loadingId,
            );
          } else {
            self.$messages.find("#" + loadingId).remove();
            self.addMessage(
              "system",
              aicwpChatConfig.strings.productNotFound,
            );
            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
          }
        },
        error: function (xhr) {
          self.$messages.find("#" + loadingId).remove();
          self.addMessage(
            "system",
            aicwpChatConfig.strings.errorGettingProduct,
          );
          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Get order status via API (WooCommerce)
     */
    getOrderStatus: function (
      orderNumber,
      billingEmail,
      userMessage,
      assistantMessage,
      toolCall,
      loadingId,
    ) {
      var self = this;

      // Build request data
      var requestData = {
        order_number: orderNumber,
      };

      // Add billing email if provided
      if (billingEmail) {
        requestData.billing_email = billingEmail;
      }

      debugLog("===== ORDER STATUS REQUEST =====");
      debugLog("Order Number:", orderNumber);
      debugLog("Billing Email:", billingEmail || "Not provided");

      $.ajax({
        url: aicwpChatConfig.apiBase + "/woocommerce-order-status",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify(requestData),
        success: function (response) {
          if (response.success) {
            debugLog("===== ORDER STATUS RESPONSE =====");
            debugLog("Order Number:", response.order_number);
            debugLog("Status:", response.status);
            debugLog(
              "Structured content length:",
              response.structured_content.length,
            );

            // Update loading message
            self.$messages
              .find("#" + loadingId + " .aicwp-chat-message-content")
              .html(
                generateLoaderHTML(
                  aicwpChatConfig.strings.analyzingOrderDetails,
                ),
              );

            // Send structured content to OpenAI for natural response
            self.getDetailsResponse(
              userMessage,
              assistantMessage,
              toolCall,
              response,
              loadingId,
            );
          } else {
            // Check if email verification is required
            if (response.requires_email) {
              debugLog("Order verification required - asking for email");
              self.$messages.find("#" + loadingId).remove();
              self.addMessage(
                "system",
                response.error ||
                  aicwpChatConfig.strings.orderVerificationRequired,
              );

              // Suggest user provide email in next message
              var suggestionMsg =
                "<p>💡 <strong>Tip:</strong> Please provide the email address you used when placing the order.</p>";
              self.addMessage("assistant", suggestionMsg);
            } else {
              self.$messages.find("#" + loadingId).remove();
              self.addMessage(
                "system",
                response.error || aicwpChatConfig.strings.orderNotFound,
              );
            }

            self.isProcessing = false;
            self.$sendBtn.prop("disabled", false);
          }
        },
        error: function (xhr) {
          debugError("===== ORDER STATUS ERROR =====");
          debugError("Status:", xhr.status);
          debugError("Response:", xhr.responseJSON);

          self.$messages.find("#" + loadingId).remove();

          var errorMsg = aicwpChatConfig.strings.errorGettingOrder;
          if (xhr.responseJSON && xhr.responseJSON.error) {
            errorMsg = xhr.responseJSON.error;
          } else if (xhr.status === 403) {
            errorMsg = aicwpChatConfig.strings.orderVerificationRequired;
          } else if (xhr.status === 404) {
            errorMsg = aicwpChatConfig.strings.orderNotFound;
          }

          self.addMessage("system", errorMsg);
          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Get AI response for content/product details
     */
    getDetailsResponse: function (
      userMessage,
      assistantMessage,
      toolCall,
      detailsResponse,
      loadingId,
    ) {
      var self = this;

      // Get valid history slice, respecting admin context length setting
      var contextMultipliers = { short: 1, normal: 2, long: 6 };
      var ctxMul = contextMultipliers[aicwpChatConfig && aicwpChatConfig.contextLength || 'normal'] || 3;
      var recentHistory = self.getValidHistorySlice(12 * ctxMul);

      // Build API payload (model and tools are handled server-side)
      var payload = {
        messages: recentHistory.concat([
          { role: "user", content: userMessage },
          assistantMessage,
          {
            role: "tool",
            tool_call_id: toolCall.id,
            content: detailsResponse.structured_content, // Semantic embedding content
          },
        ]),
      };

      // If product context is loaded, send product ID for server-side injection
      if (self.loadedProduct && self.loadedProduct.id) {
        payload.product_context_id = self.loadedProduct.id;
      }

      logModelDebug(payload, "Content Details", self.chatConfig);

      debugLog("===== SENDING DETAILS TO OPENAI =====");
      debugLog("Payload messages count:", payload.messages.length);
      debugLog(
        "Tool response content length:",
        detailsResponse.structured_content.length,
        "chars",
      );
      debugLog(
        "Content preview (first 500 chars):",
        detailsResponse.structured_content.substring(0, 500),
      );
      debugLog(
        "Content preview (last 500 chars):",
        detailsResponse.structured_content.substring(
          detailsResponse.structured_content.length - 500,
        ),
      );
      debugLog("Full payload:", payload);
      logApiRequest(payload, self.chatConfig.model);

      $.ajax({
        url: aicwpChatConfig.apiBase + "/chat-proxy",
        method: "POST",
        headers: $.extend({}, getRequestHeaders(), {
          "X-Session-ID": self.sessionId,
        }),
        data: JSON.stringify(payload),
        success: function (data) {
          var finalMessage = data.choices[0].message.content;

          // Check for empty content
          if (!finalMessage || finalMessage.trim() === "") {
            debugError("[AI Chat ERROR] getDetailsResponse - LLM returned empty content");
            debugError("[AI Chat ERROR] Full response:", JSON.stringify(data, null, 2));
            debugError("[AI Chat ERROR] Finish reason:", data.choices?.[0]?.finish_reason);
            if (data.choices?.[0]?.message?.tool_calls) {
              debugError("[AI Chat ERROR] AI returned more tool_calls instead of content");
            }
            finalMessage = aicwpChatConfig.strings.noResultsGeneric ||
              "I couldn't process that request. Please try again.";
          }

          // Remove loading indicator
          self.$messages.find("#" + loadingId).remove();

          // Add AI response
          self.addMessage("assistant", finalMessage);

          // Update history - MUST include complete tool calling sequence
          self.conversationHistory.push(
            { role: "user", content: userMessage },
            assistantMessage, // Assistant message WITH tool_calls
            {
              role: "tool",
              tool_call_id: toolCall.id,
              content: detailsResponse.structured_content,
            },
            { role: "assistant", content: finalMessage }, // Final response
          );

          // Save conversation
          self.saveConversation();

          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
        error: function (xhr) {
          var errorInfo = analyzeError(xhr, "getDetailsResponse");
          self.$messages.find("#" + loadingId).remove();
          self.addMessage("system", errorInfo.userMessage);
          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Get final response from OpenAI
     */
    getFinalResponse: function (
      userMessage,
      assistantMessage,
      toolCall,
      apiResult,
      loadingId,
      filterToolName,
    ) {
      var self = this;

      // Get valid history slice, respecting admin context length setting
      var contextMultipliers = { short: 1, normal: 2, long: 6 };
      var ctxMul = contextMultipliers[aicwpChatConfig && aicwpChatConfig.contextLength || 'normal'] || 3;
      var recentHistory = self.getValidHistorySlice(12 * ctxMul);

      // Detect tool type to format results appropriately
      var toolName = toolCall.function.name;
      var isProductSearch = toolName === "search_products";
      var isNonSearchTool = !isProductSearch;

      // LLM filtering mode: chatbot search results go through LLM for relevance filtering
      var isFilterMode = filterToolName === "search_products";

      // Non-search tools (webhook, contact form, etc.) - pass result directly to AI
      var condensedResults;

      if (isNonSearchTool) {
        condensedResults = apiResult;
      } else if (isProductSearch) {
        // Product search - format with excerpt for LLM relevance filtering
        condensedResults = {
          original_question: self.extractTextFromMessage(userMessage),
          total: apiResult.total,
          products: apiResult.results
            ? apiResult.results.map(function (r) {
                var product = {
                  id: r.id,
                  title: r.title,
                  excerpt: r.excerpt || "",
                  price: r.price?.formatted || "",
                  stock_status: r.stock_status || "",
                  on_sale: r.on_sale || false,
                  url: r.url || "",
                };
                if (r.sku) {
                  product.sku = r.sku;
                }
                if (r.product_type) {
                  product.product_type = r.product_type;
                }
                if (r.variations) {
                  product.variations = r.variations;
                }
                if (r.extra_pricing) {
                  product.extra_pricing = r.extra_pricing;
                }
                return product;
              })
            : [],
        };
      }

      debugLog("===== CONDENSED RESULTS SENT TO AI =====");
      debugLog("Filter mode:", isFilterMode);
      debugLog(JSON.stringify(condensedResults, null, 2));

      // Build API payload for final response (model and tools are handled server-side)
      var payload = {
        messages: recentHistory.concat([
          { role: "user", content: userMessage },
          assistantMessage,
          {
            role: "tool",
            tool_call_id: toolCall.id,
            content: JSON.stringify(condensedResults),
          },
        ]),
      };

      // Enable LLM relevance filtering for chatbot search results
      if (isFilterMode) {
        payload.filter_candidates = true;
      }

      // If product context is loaded, send product ID for server-side injection
      if (self.loadedProduct && self.loadedProduct.id) {
        payload.product_context_id = self.loadedProduct.id;
      }

      logModelDebug(payload, "Tool Response Summary", self.chatConfig);

      debugLog("===== SENDING MESSAGE TO OPENAI (SECOND CALL - SUMMARY) =====");
      debugLog(
        "Conversation history length:",
        recentHistory.length,
        "messages",
      );
      debugLog("Full messages array:", payload.messages);
      debugLog("Condensed results being sent:", condensedResults);
      debugLog("Complete payload:", payload);
      logApiRequest(payload, self.chatConfig.model);

      $.ajax({
        url: aicwpChatConfig.apiBase + "/chat-proxy",
        method: "POST",
        headers: $.extend({}, getRequestHeaders(), {
          "X-Session-ID": self.sessionId,
        }),
        data: JSON.stringify(payload),
        success: function (data) {
          var finalMessage =
            data.choices && data.choices[0] && data.choices[0].message
              ? data.choices[0].message.content
              : null;

          // Handle empty content (shouldn't happen with parallel_tool_calls: false)
          if (!finalMessage || finalMessage.trim() === "") {
            var finishReason = data.choices?.[0]?.finish_reason;
            var responseMessage = data.choices?.[0]?.message;

            debugError("[AI Chat ERROR] LLM returned empty content");
            debugError("[AI Chat ERROR] Finish reason:", finishReason);

            if (finishReason === "tool_calls" && responseMessage?.tool_calls) {
              debugError("[AI Chat ERROR] AI attempted chained tool_calls despite parallel_tool_calls: false");
              debugError("[AI Chat ERROR] Tools:", responseMessage.tool_calls.map(function(tc) { return tc.function.name; }));
            }

            finalMessage =
              aicwpChatConfig.strings.noResultsGeneric ||
              "I couldn't find results matching your search. Try different keywords or be more specific about what you're looking for.";
          }

          // Remove loading indicator
          self.$messages.find("#" + loadingId).remove();

          // LLM filtering mode: use relevant_ids from backend (forced function calling)
          var displayResults = apiResult.results || [];
          var textResponse = finalMessage;

          if (isFilterMode && displayResults.length > 0 && data.relevant_ids) {
            var relevantIds = data.relevant_ids.map(function(id) { return parseInt(id, 10); });
            debugLog("LLM RELEVANCE FILTER: selected IDs [" + relevantIds.join(", ") + "] from candidates [" + displayResults.map(function(r) { return r.id; }).join(", ") + "]");

            if (relevantIds.length > 0) {
              // Filter results to only include LLM-approved IDs
              displayResults = displayResults.filter(function (r) {
                return relevantIds.indexOf(parseInt(r.id, 10)) !== -1;
              });
              // Re-order to match LLM's relevance ranking
              displayResults.sort(function (a, b) {
                return relevantIds.indexOf(parseInt(a.id, 10)) - relevantIds.indexOf(parseInt(b.id, 10));
              });
              debugLog("Filtered to", displayResults.length, "relevant results");
            } else {
              // LLM returned empty relevant_ids - no relevant results
              displayResults = [];
              debugLog("LLM filtered out all results");
            }
          }

          // Add text response first
          if (textResponse) {
            self.addMessage("assistant", textResponse);
          }

          // Render product grid below text (filtered or all results)
          if (isProductSearch && displayResults.length > 0) {
            var gridHTML = self.formatProductsGrid(displayResults);
            self.addMessage("assistant", gridHTML);
          }

          // Update history - MUST include complete tool calling sequence
          self.conversationHistory.push(
            { role: "user", content: userMessage },
            assistantMessage,
            {
              role: "tool",
              tool_call_id: toolCall.id,
              content: JSON.stringify(condensedResults),
            },
            { role: "assistant", content: textResponse },
          );

          // Save conversation to localStorage
          self.saveConversation();

          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
        error: function (xhr) {
          var errorInfo = analyzeError(xhr, "getFinalResponse");
          self.$messages.find("#" + loadingId).remove();
          self.addMessage("system", errorInfo.userMessage);
          self.isProcessing = false;
          self.$sendBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Format products grid (for WooCommerce products)
     */
    formatProductsGrid: function (results) {
      var self = this;
      var html = '<div class="aicwp-results-list">';
      var cartEnabled = aicwpChatConfig.wooCartEnabled;

      results.forEach(function (product, index) {
        // Use theme placeholder (matches frontend)
        var thumbnail =
          product.featured_image || aicwpChatConfig.placeholderImage || "";
        var title = product.title || "Untitled";
        var excerpt = product.excerpt || "";
        var price = product.price?.formatted || "";
        var regularPrice = product.price?.regular || "";
        var salePrice = product.price?.sale || null;
        var onSale = product.on_sale || false;
        var stockStatus = product.stock_status || "";
        var rating = product.rating?.average || 0;
        var ratingCount = product.rating?.count || 0;
        var url = product.url || "#";
        var productId = product.id || 0;
        var productType = product.product_type || "simple";
        var sku = product.sku || "";

        // Best Match badge for first result
        var bestMatchBadge = "";
        if (index === 0) {
          bestMatchBadge =
            '<div class="match-badge best">' +
            (aicwpChatConfig.strings.bestMatch || "Best Match") +
            "</div>";
        }

        // Add hidden class to items after the first 3
        var hiddenClass = index >= 3 ? " aicwp-listing-hidden" : "";
        // Add no-thumbnail class if thumbnail is missing
        var noThumbnailClass = (!self.hideImages && thumbnail) ? "" : " no-thumbnail";

        // When cart is enabled, use <div> wrapper instead of <a> to avoid nested interactive elements
        if (cartEnabled) {
          html +=
            '<div class="aicwp-listing-item aicwp-product-card' +
            hiddenClass +
            noThumbnailClass +
            '">';
        } else {
          html +=
            '<a href="' +
            url +
            '" class="aicwp-listing-item' +
            hiddenClass +
            noThumbnailClass +
            '">';
        }

        // Only render thumbnail if hideImages is not enabled AND we have a valid thumbnail
        if (!self.hideImages && thumbnail) {
          html += '  <div class="aicwp-listing-thumbnail">';
          if (cartEnabled) {
            html += '    <a href="' + url + '"><img src="' + thumbnail + '" alt="' + title + '"></a>';
          } else {
            html += '    <img src="' + thumbnail + '" alt="' + title + '">';
          }
          html += "  </div>";
        }

        html += '  <div class="aicwp-listing-details">';
        html += '    <div class="aicwp-listing-main">';
        if (cartEnabled) {
          html +=
            '      <h3 class="aicwp-listing-title"><a href="' + url + '">' +
            title +
            "</a> " +
            bestMatchBadge +
            "</h3>";
        } else {
          html +=
            '      <h3 class="aicwp-listing-title">' +
            title +
            " " +
            bestMatchBadge +
            "</h3>";
        }
        if (excerpt) {
          html +=
            '      <p class="aicwp-listing-excerpt">' + excerpt + "</p>";
        }
        html += '      <div class="aicwp-listing-meta">';

        // Price display (with sale price handling)
        if (price) {
          if (onSale && salePrice) {
            html +=
              '        <span class="product-price"><span class="regular-price">' +
              regularPrice +
              '</span> <span class="sale-price">' +
              salePrice +
              "</span></span>";
          } else {
            html += '        <span class="product-price">' + price + "</span>";
          }
        }

        // Stock status
        if (stockStatus === "instock") {
          html +=
            '        <span class="stock-status in-stock"><svg class="stock-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm-2 15l-5-5 1.41-1.41L10 14.17l7.59-7.59L19 8l-9 9z"/></svg> ' +
            aicwpChatConfig.strings.inStock +
            "</span>";
        } else if (stockStatus === "outofstock") {
          html +=
            '        <span class="stock-status out-of-stock"><svg class="stock-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 2C6.47 2 2 6.47 2 12s4.47 10 10 10 10-4.47 10-10S17.53 2 12 2zm5 13.59L15.59 17 12 13.41 8.41 17 7 15.59 10.59 12 7 8.41 8.41 7 12 10.59 15.59 7 17 8.41 13.41 12 17 15.59z"/></svg> ' +
            aicwpChatConfig.strings.outOfStock +
            "</span>";
        }

        // Rating
        if (rating > 0) {
          html +=
            '        <span class="aicwp-listing-rating"><svg class="rating-icon" width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><path d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg> ' +
            parseFloat(rating).toFixed(1) +
            "</span>";
        }

        // SKU
        if (sku) {
          html +=
            '        <span class="aicwp-product-sku">' +
            (aicwpChatConfig.strings.sku || "SKU") +
            ": " +
            self.escapeHtml(sku) +
            "</span>";
        }

        html += "      </div>";

        // Add to Cart / Select Options (only when cart is enabled)
        if (cartEnabled && productId) {
          if (productType === "simple" && stockStatus === "instock") {
            html +=
              '      <div class="aicwp-atc-wrapper">' +
              '        <div class="aicwp-add-to-cart-btn" data-product-id="' + productId + '" role="button" tabindex="0">' +
              '          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"></circle><circle cx="20" cy="21" r="1"></circle><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"></path></svg> ' +
              '          <span class="aicwp-atc-text">' + (aicwpChatConfig.strings.addToCart || "Add to Cart") + '</span>' +
              "        </div>" +
              "      </div>";
          } else if (productType !== "simple" && stockStatus === "instock") {
            html +=
              '      <div class="aicwp-atc-wrapper">' +
              '        <div class="aicwp-add-to-cart-btn aicwp-select-options-btn" data-url="' + url + '" role="button" tabindex="0">' +
              '          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="16"></line><line x1="8" y1="12" x2="16" y2="12"></line></svg> ' +
              '          <span>' + (aicwpChatConfig.strings.selectOptions || "Select Options") + '</span>' +
              "        </div>" +
              "      </div>";
          }
        }

        html += "    </div>";
        html += "  </div>";
        html += cartEnabled ? "</div>" : "</a>";
      });

      html += "</div>";

      // Add "Show more" button if more than 3 results
      if (results.length > 3) {
        html +=
          '<button class="aicwp-show-more-btn">' +
          aicwpChatConfig.strings.showMore.replace(
            "%d",
            results.length - 3,
          ) +
          "</button>";
      }

      return html;
    },

    /**
     * Add message to chat
     * @param {string} role - Message role (user, assistant, system)
     * @param {string} content - Message content (text or HTML)
     * @param {string} id - Optional message ID
     * @param {boolean} skipAnimation - Skip typing animation (for restored messages)
     */
    addMessage: function (role, content, id, skipAnimation) {
      // Skip if content is empty/undefined
      if (!content) {
        console.warn("Skipping message with empty content");
        return;
      }

      var $message = $(
        '<div class="aicwp-chat-message aicwp-chat-message-' +
          role +
          '"></div>',
      );
      if (id) {
        $message.attr("id", id);
      }

      // Check if content contains search results grid or other non-streamable content
      var isResultsGrid = content.indexOf("aicwp-results-list") !== -1;
      var isSourceAttribution = content.indexOf("aicwp-sources") !== -1;
      var isLoadingMessage = id && id.indexOf("loading") !== -1;

      if (isResultsGrid) {
        $message.addClass("chat-message-results");
      }

      // Add avatar for assistant messages if avatar is configured
      // Hide avatar visually for result grids (keeps layout spacing)
      if (role === "assistant" && aicwpChatConfig.chatAvatarUrl) {
        $message.addClass("has-avatar");
        var $avatar = $(
          '<img class="aicwp-chat-message-avatar" src="' +
            aicwpChatConfig.chatAvatarUrl +
            '" alt="" />',
        );
        if (isResultsGrid) {
          $avatar.css("opacity", "0");
        }
        $message.append($avatar);
      }

      var $content = $('<div class="aicwp-chat-message-content"></div>');

      // Check if content contains HTML
      if (content.indexOf("<") !== -1) {
        $content.html(content);
      } else {
        $content.text(content);
      }

      $message.append($content);
      this.$messages.append($message);

      // Apply typing animation for NEW assistant text responses only
      // Skip for: restored messages, grids, sources, loading indicators
      var shouldAnimate = aicwpChatConfig.typingAnimation &&
                          role === "assistant" &&
                          !skipAnimation &&
                          !isResultsGrid &&
                          !isSourceAttribution &&
                          !isLoadingMessage;

      // For assistant messages in floating widget, scroll the message itself to top so it's visible
      // (results grid will be above, user question further above)
      // Skip this for shortcodes - they're larger and don't need this behavior
      var isFloatingWidget = this.$wrapper.closest(".aicwp-floating-chat-popup").length > 0;
      if (role === "assistant" && !isResultsGrid && isFloatingWidget) {
        $message[0].scrollIntoView({ block: "start", behavior: "instant" });
      } else {
        // For user/system messages, result grids, and shortcodes - scroll to bottom as normal
        this.$messages.scrollTop(this.$messages[0].scrollHeight);
      }

      if (shouldAnimate) {
        this.animateTyping($content[0]);
      }
    },

    /**
     * Animate typing effect for message content
     * Wraps words in spans and reveals them with staggered timing
     */
    animateTyping: function (contentElement) {
      var self = this;
      var WORDS_PER_BATCH = 3; // Words to reveal per tick
      var TICK_INTERVAL = 50; // ms between batches
      var FADE_TRAIL_LENGTH = 5; // Number of words in fade trail

      // Add streaming-active class to enable hiding of unrevealed words
      contentElement.classList.add("streaming-active");

      // Hide block elements initially - they'll be revealed when their content appears
      var hiddenElements = contentElement.querySelectorAll("li, p, h1, h2, h3, h4, h5, h6");
      hiddenElements.forEach(function (el) {
        el.classList.add("stream-block-hidden");
      });

      // Use TreeWalker to find all text nodes (preserves HTML structure)
      var walker = document.createTreeWalker(
        contentElement,
        NodeFilter.SHOW_TEXT,
        {
          acceptNode: function (node) {
            // Skip text nodes inside code/pre elements - don't animate code
            if (node.parentNode.closest("code, pre")) {
              return NodeFilter.FILTER_REJECT;
            }
            return NodeFilter.FILTER_ACCEPT;
          }
        },
        false
      );

      var textNodes = [];
      while (walker.nextNode()) {
        textNodes.push(walker.currentNode);
      }

      // Wrap each word in a span
      var allWordSpans = [];
      textNodes.forEach(function (textNode) {
        var text = textNode.textContent;
        if (!text.trim()) return; // Skip empty text nodes

        // Split into words while preserving whitespace
        var parts = text.split(/(\s+)/);
        var fragment = document.createDocumentFragment();

        parts.forEach(function (part) {
          if (part.match(/^\s+$/)) {
            // Whitespace - keep as text node
            fragment.appendChild(document.createTextNode(part));
          } else if (part) {
            // Word - wrap in span
            var span = document.createElement("span");
            span.className = "stream-word";
            span.textContent = part;
            fragment.appendChild(span);
            allWordSpans.push(span);
          }
        });

        textNode.parentNode.replaceChild(fragment, textNode);
      });

      // If no words to animate, remove class and exit
      if (allWordSpans.length === 0) {
        contentElement.classList.remove("streaming-active");
        hiddenElements.forEach(function (el) {
          el.classList.remove("stream-block-hidden");
        });
        return;
      }

      // Animate words appearing in batches
      var currentIndex = 0;

      function revealBatch() {
        var endIndex = Math.min(currentIndex + WORDS_PER_BATCH, allWordSpans.length);

        // Reveal current batch
        for (var i = currentIndex; i < endIndex; i++) {
          var span = allWordSpans[i];
          span.classList.add("visible");

          // Check if this word is inside a hidden block element - reveal it
          var parentBlock = span.closest(".stream-block-hidden");
          if (parentBlock) {
            parentBlock.classList.remove("stream-block-hidden");
          }
        }

        // Update fade trail - last N visible words get fading classes
        for (var j = 0; j < allWordSpans.length; j++) {
          var span = allWordSpans[j];
          // Remove all trail classes
          span.classList.remove("trail-1", "trail-2", "trail-3", "trail-4", "trail-5");

          // Add trail classes to last few visible words
          if (span.classList.contains("visible")) {
            var distanceFromEnd = endIndex - 1 - j;
            if (distanceFromEnd >= 0 && distanceFromEnd < FADE_TRAIL_LENGTH) {
              span.classList.add("trail-" + (distanceFromEnd + 1));
            }
          }
        }

        currentIndex = endIndex;

        // For shortcodes, scroll to bottom during animation so user sees latest content
        // For floating widget, no scroll - keeps user's message visible at top
        var isFloatingWidget = self.$wrapper.closest(".aicwp-floating-chat-popup").length > 0;
        if (!isFloatingWidget) {
          self.$messages.scrollTop(self.$messages[0].scrollHeight);
        }

        // Continue or finish
        if (currentIndex < allWordSpans.length) {
          setTimeout(revealBatch, TICK_INTERVAL);
        } else {
          // Animation complete - remove streaming class and unwrap spans
          contentElement.classList.remove("streaming-active");
          hiddenElements.forEach(function (el) {
            el.classList.remove("stream-block-hidden");
          });
          allWordSpans.forEach(function (span) {
            // Move all child nodes out of span (preserves images, emojis, etc.)
            while (span.firstChild) {
              span.parentNode.insertBefore(span.firstChild, span);
            }
            span.parentNode.removeChild(span);
          });
        }
      }

      // Start animation
      revealBatch();
    },

    /**
     * Save conversation to localStorage
     */
    saveConversation: function () {
      try {
        var data = {
          history: this.conversationHistory,
          messages: [],
          timestamp: Date.now(),
        };

        // Save message HTML for display
        this.$messages.find(".aicwp-chat-message").each(function () {
          var $msg = $(this);

          var role = "assistant";
          if ($msg.hasClass("aicwp-chat-message-user")) {
            role = "user";
          } else if ($msg.hasClass("aicwp-chat-message-system")) {
            role = "system";
          }

          data.messages.push({
            role: role,
            content: $msg.find(".aicwp-chat-message-content").html(),
          });
        });

        localStorage.setItem(this.storageKey, JSON.stringify(data));
        debugLog("Conversation saved to localStorage");
      } catch (e) {
        debugError("Failed to save conversation:", e);
      }
    },

    /**
     * Load conversation from localStorage
     */
    loadConversation: function () {
      try {
        var data = localStorage.getItem(this.storageKey);
        if (!data) {
          debugLog(
            "No saved conversation found - showing fresh welcome message",
          );
          // Replace HTML welcome message with current setting from JS
          this.$messages.empty();
          this.showWelcome();
          return;
        }

        data = JSON.parse(data);

        // Check if conversation is less than 24 hours old
        var hoursSinceLastMessage =
          (Date.now() - data.timestamp) / (1000 * 60 * 60);
        if (hoursSinceLastMessage > 24) {
          debugLog("Conversation expired (older than 24 hours)");
          localStorage.removeItem(this.storageKey);
          // Show fresh welcome message
          this.$messages.empty();
          this.showWelcome();
          return;
        }

        // Restore conversation history
        this.conversationHistory = data.history || [];

        // Clear welcome message and restore messages
        this.$messages.empty();

        var self = this;
        if (data.messages && data.messages.length > 0) {
          data.messages.forEach(function (msg) {
            // Skip messages with empty content
            if (msg.content) {
              // Pass true for skipAnimation - don't animate restored messages
              self.addMessage(msg.role, msg.content, null, true);
            }
          });
          debugLog(
            "Loaded " + data.messages.length + " messages from localStorage",
          );

          // Expand chat when loading past conversation (Style 2 only)
          this.expandChat();

          // Hide quick buttons if there are existing messages and setting is "hide_after_first"
          if (aicwpChatConfig.quickButtonsVisibility === "hide_after_first") {
            var $quickBtns = this.$wrapper.find(".aicwp-chat-quick-buttons");
            $quickBtns.find(".aicwp-quick-btn").hide();
            $quickBtns.hide();
            debugLog("[Quick Buttons] Hidden (existing conversation loaded)");
          }

          // Remove image header if there's existing conversation (floating widget only)
          if (aicwpChatConfig.hasImageHeader) {
            var $popup = this.$wrapper.closest('.aicwp-floating-chat-popup');
            if ($popup.length) {
              if (aicwpChatConfig.hasAnimatedHeader && typeof AicwpSilkWave !== 'undefined') {
                AicwpSilkWave.destroy();
              }
              $popup.removeClass('chat-image-header chat-image-header-overlay chat-animated-header');
              debugLog("[Image Header] Removed (existing conversation loaded)");
            }
          }
        } else {
          // Show welcome message if no saved messages
          this.showWelcome();
        }
      } catch (e) {
        debugError("Failed to load conversation:", e);
        // Clear and show fresh welcome message on error
        this.$messages.empty();
        this.showWelcome();
      }
    },

    /**
     * Get or create a unique session ID for conversation tracking
     */
    getOrCreateSessionId: function () {
      var sessionKey = "aicwp_session_" + this.chatId;
      var sessionId = localStorage.getItem(sessionKey);

      if (!sessionId) {
        // Generate unique ID: timestamp + random string
        sessionId =
          Date.now().toString(36) + Math.random().toString(36).substr(2, 9);
        localStorage.setItem(sessionKey, sessionId);
      }

      return sessionId;
    },

    /**
     * Toggle menu dropdown
     */
    toggleMenu: function () {
      var isOpen = this.$menuDropdown.attr("data-state") === "open";
      if (isOpen) {
        this.closeMenu();
      } else {
        this.openMenu();
      }
    },

    /**
     * Open menu dropdown
     */
    openMenu: function () {
      this.$menuDropdown.attr("data-state", "open");
      this.$menuTrigger.attr("aria-expanded", "true");
    },

    /**
     * Close menu dropdown
     */
    closeMenu: function () {
      this.$menuDropdown.attr("data-state", "closed");
      this.$menuTrigger.attr("aria-expanded", "false");
    },

    /**
     * Toggle expand/collapse chat (floating widget only)
     */
    toggleExpandChat: function () {
      var $popup = this.$wrapper.closest(".aicwp-floating-chat-popup");
      if ($popup.length) {
        $popup.toggleClass("is-expanded");
        var isExpanded = $popup.hasClass("is-expanded");
        // Remember preference in localStorage
        localStorage.setItem("aicwp_chat_expanded", isExpanded ? "1" : "0");
        debugLog("Chat expanded:", isExpanded);
      }
    },

    /**
     * Restore expand/collapse state from localStorage (floating widget only)
     */
    restoreExpandState: function () {
      var $popup = this.$wrapper.closest(".aicwp-floating-chat-popup");
      if ($popup.length) {
        var savedState = localStorage.getItem("aicwp_chat_expanded");
        if (savedState === "1") {
          $popup.addClass("is-expanded");
          debugLog("Restored expanded state from localStorage");
        }
      }
    },

    /**
     * Initialize pre-chat required fields form
     * Shows form below welcome message, disables send button with tooltip until fields are filled
     */
    initPreChatForm: function () {
      var self = this;
      var storageKey = "aicwp_pre_chat_data_" + this.chatId;

      // Check if already completed this session
      var savedData = sessionStorage.getItem(storageKey);
      if (savedData) {
        try {
          self.preChatData = JSON.parse(savedData);
          self.preChatCompleted = true;
          debugLog("[Pre-Chat] Already completed, data loaded from session");
          return;
        } catch (e) {
          sessionStorage.removeItem(storageKey);
        }
      }

      // Find the form and move it inside messages area (after welcome message)
      var $form = self.$wrapper.find(".aicwp-pre-chat-form");
      if (!$form.length) {
        debugLog("[Pre-Chat] Form HTML not found in wrapper");
        return;
      }

      $form.detach().appendTo(self.$messages).show();

      // Disable send button with tooltip
      self.$sendBtn.prop("disabled", true);
      self.$sendBtn.attr("data-chat-tooltip", aicwpChatConfig.strings.preChatRequired || "Fill out required fields");

      // Handle form submission
      $form.find(".aicwp-pre-chat-form-body").on("submit", function (e) {
        e.preventDefault();

        var $fields = $(this).find("input[data-field-label]");
        var allFilled = true;
        var data = [];

        $fields.each(function () {
          var val = $(this).val().trim();
          if (!val || val.length < 2 || val.length > 200) {
            allFilled = false;
            $(this).css("border-color", "#dc3545");
          } else {
            $(this).css("border-color", "");
            data.push({
              label: $(this).attr("data-field-label"),
              value: val,
            });
          }
        });

        if (!allFilled) return;

        // Store data
        self.preChatData = data;
        self.preChatCompleted = true;
        self.preChatDataSent = false;
        sessionStorage.setItem(storageKey, JSON.stringify(data));

        // Hide form
        $form.slideUp(200);

        // Enable send button
        self.$sendBtn.prop("disabled", false);
        self.$sendBtn.removeAttr("data-chat-tooltip");
        self.$input.focus();

        debugLog("[Pre-Chat] Form submitted:", data);
      });
    },

    /**
     * Get pre-chat data header for first message
     * Returns object to merge into request headers, or empty object
     */
    getPreChatHeaders: function () {
      if (this.preChatData && !this.preChatDataSent) {
        this.preChatDataSent = true;
        return { "X-Pre-Chat-Data": JSON.stringify(this.preChatData) };
      }
      return {};
    },

    /**
     * Clear conversation
     */
    clearConversation: function () {
      // Clear localStorage
      localStorage.removeItem(this.storageKey);

      // Clear loaded product context
      this.loadedProduct = null;
      this.clearLoadedProductFromStorage();

      // Clear conversation history
      this.conversationHistory = [];

      // Reset pre-chat data send flag so it gets sent with next first message
      if (this.preChatCompleted && this.preChatData) {
        this.preChatDataSent = false;
      }

      // Clear messages and show welcome (keep session ID for analytics continuity)
      this.$messages.empty();
      this.showWelcome();

      // Re-detect and add product button if on product page
      this.detectAndAddProductButton();

      // Show quick buttons again after clearing conversation (if hidden)
      if (aicwpChatConfig.quickButtonsVisibility === "hide_after_first") {
        var $quickBtns = this.$wrapper.find(".aicwp-chat-quick-buttons");
        $quickBtns.find(".aicwp-quick-btn").show();
        $quickBtns.show();
        debugLog("[Quick Buttons] Restored after conversation clear");
      }

      // Restore image header class for floating widget only
      if (aicwpChatConfig.hasImageHeader) {
        var $popup = this.$wrapper.closest('.aicwp-floating-chat-popup');
        if ($popup.length) {
          $popup.addClass('chat-image-header');
          if (aicwpChatConfig.hasAnimatedHeader) {
            $popup.addClass('chat-animated-header chat-image-header-overlay');
            // Re-init animated canvas
            var headerEl = $popup.find('.aicwp-chat-header')[0];
            if (headerEl && typeof AicwpSilkWave !== 'undefined') {
              AicwpSilkWave.init(headerEl, aicwpChatConfig.animatedBgColor || '#1560d0');
            }
          } else if (aicwpChatConfig.hasImageHeaderOverlay) {
            $popup.addClass('chat-image-header-overlay');
          }
          debugLog("[Image Header] Restored after conversation clear");
        }
      }

      debugLog("Conversation cleared (session ID preserved)");
    },

    /**
     * Show welcome message - either image header style or regular bubble
     */
    showWelcome: function () {
      // Image header welcome only for floating widget, not shortcodes
      var isFloatingWidget = this.$wrapper.closest('.aicwp-floating-chat-popup').length > 0;
      if (aicwpChatConfig.hasImageHeader && isFloatingWidget) {
        // Init animated canvas if needed
        if (aicwpChatConfig.hasAnimatedHeader && typeof AicwpSilkWave !== 'undefined') {
          var headerEl = this.$wrapper.find('.aicwp-chat-header')[0];
          if (headerEl) {
            AicwpSilkWave.init(headerEl, aicwpChatConfig.animatedBgColor || '#1560d0');
          }
        }
        // Show centered welcome text for image header style
        var welcomeMessage = aicwpChatConfig.strings.welcomeMessage;
        var welcomeHtml =
          '<div class="chat-image-bg-welcome-text">' +
          '<h3>' + this.escapeHtml(aicwpChatConfig.chatName) + '</h3>';
        if (welcomeMessage.indexOf('<') !== -1) {
          welcomeHtml += welcomeMessage;
        } else {
          welcomeHtml += '<p>' + this.escapeHtml(welcomeMessage) + '</p>';
        }
        welcomeHtml += '</div>';
        this.$messages.append(welcomeHtml);
      } else {
        // Show regular welcome message bubble
        this.addMessage("system", aicwpChatConfig.strings.welcomeMessage, null, true);
      }
    },

    /**
     * Check rate limits (client-side using localStorage)
     * Returns {allowed: boolean, message: string}
     */
    checkRateLimit: function () {
      var now = Date.now();
      var timestamps = this.getMessageTimestamps();

      // Clean up old timestamps (older than 24 hours)
      timestamps = timestamps.filter(function (ts) {
        return now - ts < 86400000; // 24 hours in ms
      });

      // Check Tier 1: X messages per minute
      var tier1Window = this.rateLimits.tier1.window * 1000; // Convert to ms
      var tier1Count = timestamps.filter(function (ts) {
        return now - ts < tier1Window;
      }).length;

      if (tier1Count >= this.rateLimits.tier1.limit) {
        var tier1Wait = Math.ceil(
          (timestamps[timestamps.length - tier1Count] + tier1Window - now) /
            1000,
        );
        return {
          allowed: false,
          tier: "tier1",
          message: this.getRateLimitMessage(
            "minute",
            this.rateLimits.tier1.limit,
            tier1Wait,
          ),
        };
      }

      // Check Tier 2: X messages per 15 minutes
      var tier2Window = this.rateLimits.tier2.window * 1000;
      var tier2Count = timestamps.filter(function (ts) {
        return now - ts < tier2Window;
      }).length;

      if (tier2Count >= this.rateLimits.tier2.limit) {
        var tier2Wait = Math.ceil(
          (timestamps[timestamps.length - tier2Count] + tier2Window - now) /
            1000,
        );
        return {
          allowed: false,
          tier: "tier2",
          message: this.getRateLimitMessage(
            "15 minutes",
            this.rateLimits.tier2.limit,
            tier2Wait,
          ),
        };
      }

      // Check Tier 3: X messages per day
      var tier3Window = this.rateLimits.tier3.window * 1000;
      var tier3Count = timestamps.filter(function (ts) {
        return now - ts < tier3Window;
      }).length;

      if (tier3Count >= this.rateLimits.tier3.limit) {
        var tier3Wait = Math.ceil(
          (timestamps[timestamps.length - tier3Count] + tier3Window - now) /
            1000,
        );
        return {
          allowed: false,
          tier: "tier3",
          message: this.getRateLimitMessage(
            "day",
            this.rateLimits.tier3.limit,
            tier3Wait,
          ),
        };
      }

      // Save cleaned timestamps
      this.saveMessageTimestamps(timestamps);

      return { allowed: true };
    },

    /**
     * Record message timestamp
     */
    recordMessage: function () {
      var timestamps = this.getMessageTimestamps();
      timestamps.push(Date.now());
      this.saveMessageTimestamps(timestamps);
    },

    /**
     * Get message timestamps from localStorage
     */
    getMessageTimestamps: function () {
      try {
        var data = localStorage.getItem(this.rateLimitStorageKey);
        if (!data) return [];

        var parsed = JSON.parse(data);
        return Array.isArray(parsed) ? parsed : [];
      } catch (e) {
        debugError("[Rate Limit] Failed to parse timestamps:", e);
        return [];
      }
    },

    /**
     * Save message timestamps to localStorage
     */
    saveMessageTimestamps: function (timestamps) {
      try {
        localStorage.setItem(
          this.rateLimitStorageKey,
          JSON.stringify(timestamps),
        );
      } catch (e) {
        debugError("[Rate Limit] Failed to save timestamps:", e);
      }
    },

    /**
     * Get human-readable rate limit error message
     */
    getRateLimitMessage: function (period, limit, waitSeconds) {
      var waitText = "";
      if (waitSeconds >= 3600) {
        // Hours for daily limits
        var hours = Math.ceil(waitSeconds / 3600);
        var hourText =
          hours > 1
            ? aicwpChatConfig.strings.hours
            : aicwpChatConfig.strings.hour;
        waitText = hours + " " + hourText;
      } else if (waitSeconds >= 60) {
        // Minutes
        var minutes = Math.ceil(waitSeconds / 60);
        var minuteText =
          minutes > 1
            ? aicwpChatConfig.strings.minutes
            : aicwpChatConfig.strings.minute;
        waitText = minutes + " " + minuteText;
      } else {
        // Seconds
        var secondText =
          waitSeconds > 1
            ? aicwpChatConfig.strings.seconds
            : aicwpChatConfig.strings.second;
        waitText = waitSeconds + " " + secondText;
      }

      return (
        "⏱️ " +
        aicwpChatConfig.strings.rateLimitPrefix +
        " " +
        limit +
        " " +
        aicwpChatConfig.strings.rateLimitSuffix +
        " " +
        period +
        ". " +
        aicwpChatConfig.strings.rateLimitWait +
        " " +
        waitText +
        " " +
        aicwpChatConfig.strings.rateLimitBeforeTrying
      );
    },

    // ========================================
    // PRODUCT CONTEXT FUNCTIONS (WooCommerce)
    // ========================================

    /**
     * Detect single product page and add "Talk about this product" button
     */
    detectAndAddProductButton: function () {
      var self = this;

      // WooCommerce product support requires Pro
      if (!this.chatConfig || !this.chatConfig.woocommerce_available) {
        return;
      }

      // Get product ID from page
      var productId = this.getCurrentProductId();

      if (!productId) {
        debugLog("[Product Context] Not on a product page, skipping button");
        return;
      }

      debugLog("[Product Context] Detected product page, ID:", productId);

      // Check if this product is already loaded
      if (this.loadedProduct && this.loadedProduct.id === productId) {
        debugLog(
          "[Product Context] This product is already loaded, no button needed",
        );
        return;
      }

      // Get product title from page
      var productTitle = this.getCurrentProductTitle();

      // Build button HTML
      var buttonHtml = "";
      if (productTitle) {
        buttonHtml +=
          '<h4 class="aicwp-product-context-title">' +
          productTitle +
          "</h4>";
      }

      // Only disable button if config hasn't loaded yet (config is now loaded synchronously before this)
      var disabledAttr = this.configLoaded ? "" : " disabled";
      buttonHtml +=
        '<button class="aicwp-load-product-btn" data-product-id="' +
        productId +
        '"' + disabledAttr + '>' +
        '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">' +
        '<path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z" fill="currentColor"/>' +
        "</svg>" +
        '<span class="btn-text">' +
        aicwpChatConfig.strings.talkAboutProduct +
        "</span>" +
        "</button>";

      // Add as a special message
      this.addMessage("product-action", buttonHtml, "product-context-btn");

      // Button click handler
      this.$messages.on("click", ".aicwp-load-product-btn", function () {
        var $btn = $(this);
        self.loadProductContext(productId, self.getCurrentProductTitle());
      });
    },

    /**
     * Get current product ID from page (WooCommerce)
     */
    getCurrentProductId: function () {
      var bodyClasses = $("body").attr("class") || "";

      // Check if it's a single product page
      var isSingleProduct = bodyClasses.indexOf("single-product") !== -1;

      // Make sure we're NOT on admin
      var isAdminPage =
        bodyClasses.indexOf("wp-admin") !== -1 ||
        (bodyClasses.indexOf("admin-bar") !== -1 &&
          window.location.href.indexOf("/wp-admin/") !== -1);

      if (!isSingleProduct || isAdminPage) {
        return null;
      }

      // Method 1: Check body class (e.g., postid-1234)
      var postIdMatch = bodyClasses.match(/postid-(\d+)/);
      if (postIdMatch) {
        return parseInt(postIdMatch[1]);
      }

      // Method 2: Check for data attribute on product container
      var $productContainer = $("[data-product_id], .product[data-id]");
      if ($productContainer.length) {
        var dataId =
          $productContainer.data("product_id") || $productContainer.data("id");
        if (dataId) {
          return parseInt(dataId);
        }
      }

      // Method 3: Check for hidden input with product ID
      var $productIdInput = $(
        'input[name="product_id"], input[name="add-to-cart"]',
      );
      if ($productIdInput.length) {
        var inputVal = $productIdInput.val();
        if (inputVal) {
          return parseInt(inputVal);
        }
      }

      return null;
    },

    /**
     * Get current product title from page
     */
    getCurrentProductTitle: function () {
      // WooCommerce product title
      var $productTitle = $(
        ".product_title, .woocommerce-product-title, h1.entry-title",
      );
      if ($productTitle.length) {
        return $productTitle.first().text().trim();
      }

      return "";
    },

    /**
     * Load product context into chat
     */
    loadProductContext: function (productId, productTitle) {
      var self = this;
      var $btn = $(".aicwp-load-product-btn");

      // Show loading state
      $btn.addClass("loading").prop("disabled", true);
      $btn.find(".btn-text").text(aicwpChatConfig.strings.loadingButton);

      debugLog("[Product Context] Fetching product details for ID:", productId);

      // Use get-content endpoint for products
      $.ajax({
        url: aicwpChatConfig.apiBase + "/get-content",
        method: "POST",
        contentType: "application/json",
        data: JSON.stringify({ post_id: productId }),
        success: function (response) {
          if (response.success) {
            // Store product context
            self.loadedProduct = {
              id: productId,
              title: response.title,
              url: response.url,
              content: response.structured_content,
            };

            // Persist to localStorage
            self.saveLoadedProductToStorage();

            debugLog(
              "[Product Context] Loaded product:",
              self.loadedProduct.title,
            );

            // Remove the button
            $btn.closest(".aicwp-chat-message").fadeOut(300, function () {
              $(this).remove();
            });

            // Add confirmation message with SVG icon
            var productIcon =
              '<svg class="context-loaded-icon" width="16" height="16" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M7 18c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zM1 2v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.14 0-.25-.11-.25-.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.58-6.49c.08-.14.12-.31.12-.48 0-.55-.45-1-1-1H5.21l-.94-2H1zm16 16c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2z" fill="currentColor"/></svg>';
            self.addMessage(
              "system",
              productIcon +
                " <strong>" +
                aicwpChatConfig.strings.productContextLoaded +
                '</strong> <a href="' +
                response.url +
                '">' +
                response.title +
                "</a>",
            );
          } else {
            self.handleProductLoadError(
              aicwpChatConfig.strings.failedLoadProductDetails,
            );
            $btn.removeClass("loading").prop("disabled", false);
            $btn
              .find(".btn-text")
              .text(aicwpChatConfig.strings.talkAboutProduct);
          }
        },
        error: function (xhr) {
          debugError("[Product Context] Error loading product:", xhr);
          self.handleProductLoadError(
            aicwpChatConfig.strings.errorLoadingProduct,
          );
          $btn.removeClass("loading").prop("disabled", false);
          $btn
            .find(".btn-text")
            .text(aicwpChatConfig.strings.talkAboutProduct);
        },
      });
    },

    /**
     * Handle product load error
     */
    handleProductLoadError: function (message) {
      this.addMessage("system", "⚠️ " + message);
    },

    /**
     * Save loaded product to localStorage
     */
    saveLoadedProductToStorage: function () {
      try {
        if (this.loadedProduct) {
          localStorage.setItem(
            this.loadedProductStorageKey,
            JSON.stringify(this.loadedProduct),
          );
          debugLog(
            "[Product Context] Saved to localStorage:",
            this.loadedProduct.title,
          );
        }
      } catch (e) {
        debugError("[Product Context] Failed to save to localStorage:", e);
      }
    },

    /**
     * Load persisted product context from localStorage
     */
    loadPersistedProductContext: function () {
      try {
        var data = localStorage.getItem(this.loadedProductStorageKey);
        if (data) {
          this.loadedProduct = JSON.parse(data);
          debugLog(
            "[Product Context] Restored from localStorage:",
            this.loadedProduct.title,
          );
        }
      } catch (e) {
        debugError("[Product Context] Failed to load from localStorage:", e);
      }
    },

    /**
     * Clear loaded product from localStorage
     */
    clearLoadedProductFromStorage: function () {
      try {
        localStorage.removeItem(this.loadedProductStorageKey);
        debugLog("[Product Context] Cleared from localStorage");
      } catch (e) {
        debugError("[Product Context] Failed to clear from localStorage:", e);
      }
    },

    /**
     * Handle image file selection
     */
    handleImageSelect: function (file) {
      var self = this;

      // Allowed MIME types (include image/jpg as fallback - some browsers report it instead of image/jpeg)
      var allowedTypes = ["image/jpeg", "image/jpg", "image/png", "image/gif", "image/webp"];

      // Validate file type
      if (allowedTypes.indexOf(file.type) === -1) {
        debugLog("[Image] Invalid file type:", file.type);
        this.addMessage("system", aicwpChatConfig.strings.imageInvalidFormat || "Invalid image format. Allowed: JPEG, PNG, GIF, WebP.");
        return;
      }

      // Validate file size (max 4MB)
      var maxSize = 4 * 1024 * 1024;
      if (file.size > maxSize) {
        debugLog("[Image] File too large:", file.size);
        this.addMessage("system", aicwpChatConfig.strings.imageTooLarge || "Image is too large. Maximum size is 4MB.");
        return;
      }

      // Read file and check resolution
      var reader = new FileReader();
      reader.onload = function (e) {
        var base64 = e.target.result;

        // Create image to check dimensions
        var img = new Image();
        img.onload = function () {
          var maxDimension = 3000;

          // Check resolution (max 3000x3000)
          if (img.width > maxDimension || img.height > maxDimension) {
            debugLog("[Image] Resolution too large:", img.width + "x" + img.height);
            self.addMessage("system", aicwpChatConfig.strings.imageResolutionTooLarge || "Image resolution is too large. Maximum is 3000x3000 pixels.");
            return;
          }

          // Store the attached image
          self.attachedImage = {
            base64: base64,
            mimeType: file.type,
            name: file.name,
            width: img.width,
            height: img.height,
          };

          // Update button state
          self.$imageBtn.addClass("has-image");

          debugLog("[Image] Image attached:", {
            name: file.name,
            type: file.type,
            size: file.size,
            dimensions: img.width + "x" + img.height,
          });
        };
        img.src = base64;
      };
      reader.readAsDataURL(file);
    },

    /**
     * Clear attached image
     */
    clearAttachedImage: function () {
      this.attachedImage = null;
      this.$imageBtn.removeClass("has-image");
      if (this.$imageInput.length) {
        this.$imageInput.val("");
      }
      debugLog("[Image] Image cleared");
    },

    /**
     * Build message content with optional image
     * Returns array format for multimodal or string for text-only
     */
    buildMessageContent: function (text) {
      if (!this.attachedImage) {
        return text;
      }

      // Build multimodal content array (OpenAI format)
      var content = [];

      // Add image first
      content.push({
        type: "image_url",
        image_url: {
          url: this.attachedImage.base64,
          detail: "auto",
        },
      });

      // Add text if provided (no default - vision models understand images without prompts)
      if (text && text.trim()) {
        content.push({
          type: "text",
          text: text,
        });
      }

      return content;
    },

    /**
     * Get display text for user message (for UI display)
     */
    getUserMessageDisplay: function (text) {
      var html = "";

      if (this.attachedImage) {
        html += '<img src="' + this.attachedImage.base64 + '" alt="Attached image" class="aicwp-chat-user-image" />';
      }

      if (text && text.trim()) {
        html += '<span>' + this.escapeHtml(text) + '</span>';
      }

      return html || aicwpChatConfig.strings.imageAttached || "[Image attached]";
    },

    /**
     * Escape HTML special characters
     */
    escapeHtml: function (text) {
      var div = document.createElement("div");
      div.textContent = text;
      return div.innerHTML;
    },

    /**
     * Extract text from message content
     * Handles both string and multimodal (array) content
     * Used for original_question in search tools (don't send images to search)
     */
    extractTextFromMessage: function (content) {
      if (typeof content === "string") {
        return content;
      }

      if (Array.isArray(content)) {
        var textParts = [];
        for (var i = 0; i < content.length; i++) {
          var part = content[i];
          if (part.type === "text" && part.text) {
            textParts.push(part.text);
          }
        }
        return textParts.join(" ");
      }

      return "";
    },
  };

  // === Theme Switcher (dark/light toggle) ===
  var THEME_STORAGE_KEY = "aicwp_chat_dark_mode";

  function applyDarkMode(enabled) {
    var $widget = $(".aicwp-floating-chat-widget");
    $(".aicwp-chat-wrapper").each(function () {
      $(this).toggleClass("dark-mode", enabled);
    });
    $widget.toggleClass("dark-mode", enabled);
    if (typeof AicwpSilkWave !== "undefined") {
      AicwpSilkWave.setDarkMode(enabled);
    }
  }

  $(function () {
    // Restore saved dark mode preference
    var saved = localStorage.getItem(THEME_STORAGE_KEY);
    if (saved) {
      applyDarkMode(saved === "dark");
    }

    // Handle toggle click
    $(document).on("click", ".aicwp-chat-darkmode-toggle", function () {
      var $wrapper = $(this).closest(".aicwp-chat-wrapper");
      if (!$wrapper.length) {
        $wrapper = $(this)
          .closest(".aicwp-chat-container")
          .closest(".aicwp-chat-wrapper");
      }
      if (!$wrapper.length) return;
      var newMode = !$wrapper.hasClass("dark-mode");
      applyDarkMode(newMode);
      localStorage.setItem(THEME_STORAGE_KEY, newMode ? "dark" : "light");
    });
  });
})(jQuery);
