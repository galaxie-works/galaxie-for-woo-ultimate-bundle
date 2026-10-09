/**
 * Speech-to-Text for AI Chat (PRO)
 *
 * Handles microphone recording and audio transcription via OpenAI Whisper API.
 * Extends the AicwpAIChat class to add voice input functionality.
 *
 * @package AICWP
 * @since 1.7.0
 */

(function ($) {
  "use strict";

  // Wait for DOM and chat to be ready
  $(document).ready(function () {
    // Initialize speech-to-text on all chat instances
    initSpeechToText();

    // Re-initialize when chat popup opens (for floating widget)
    $(document).on("aicwp-chat-opened", function () {
      initSpeechToText();
    });
  });

  /**
   * Initialize speech-to-text functionality
   */
  function initSpeechToText() {
    $(".aicwp-chat-mic-btn").each(function () {
      var $btn = $(this);

      // Skip if already initialized
      if ($btn.data("speech-initialized")) {
        return;
      }

      // Mark as initialized
      $btn.data("speech-initialized", true);

      // Create handler instance
      var handler = new SpeechToTextHandler($btn);
      $btn.data("speech-handler", handler);
    });
  }

  /**
   * Speech-to-Text Handler Class
   *
   * @param {jQuery} $btn The mic button element
   */
  function SpeechToTextHandler($btn) {
    this.$btn = $btn;
    this.$wrapper = $btn.closest(".aicwp-chat-wrapper");
    this.$input = this.$wrapper.find(".aicwp-chat-input");
    this.$messages = this.$wrapper.find(".aicwp-chat-messages");

    this.mediaRecorder = null;
    this.audioChunks = [];
    this.isRecording = false;
    this.stream = null;
    this.timerInterval = null;
    this.recordingStartTime = null;
    this.$timer = $btn.find(".mic-recording-timer");

    // Check browser support
    // navigator.mediaDevices requires HTTPS (secure context) or localhost
    this.isSupported = !!(
      navigator.mediaDevices && navigator.mediaDevices.getUserMedia
    );

    if (!this.isSupported) {
      var notSupportedMsg =
        aicwpChatConfig.strings.micNotSupported || "Not supported";

      // Check if it's a secure context issue
      if (window.isSecureContext === false) {
        notSupportedMsg =
          aicwpChatConfig.strings.micNoSSL || "Not available without SSL";
        console.warn(
          "[AI Chat] Speech-to-text requires HTTPS. Current origin:",
          window.location.origin,
        );
      }

      $btn.attr("data-chat-tooltip", notSupportedMsg);
      return;
    }

    // Bind events
    this.bindEvents();
  }

  /**
   * Bind click and keyboard events
   */
  SpeechToTextHandler.prototype.bindEvents = function () {
    var self = this;

    this.$btn.on("click", function (e) {
      e.preventDefault();
      e.stopPropagation();

      if (self.$btn.hasClass("disabled")) {
        return;
      }

      if (self.isRecording) {
        self.stopRecording();
      } else {
        self.startRecording();
      }
    });

    // Keyboard accessibility for div (Enter/Space to activate)
    this.$btn.on("keydown", function (e) {
      if (e.key === "Enter" || e.key === " ") {
        e.preventDefault();
        $(this).trigger("click");
      }
    });

    // Stop recording if user presses Escape
    $(document).on("keydown.speechToText", function (e) {
      if (e.key === "Escape" && self.isRecording) {
        self.cancelRecording();
      }
    });
  };

  /**
   * Start recording audio
   */
  SpeechToTextHandler.prototype.startRecording = function () {
    var self = this;

    // Check if already recording
    if (this.isRecording) {
      return;
    }

    // Request microphone access
    navigator.mediaDevices
      .getUserMedia({ audio: true })
      .then(function (stream) {
        self.stream = stream;

        // Determine best MIME type
        var mimeType = "audio/webm";
        if (MediaRecorder.isTypeSupported("audio/webm;codecs=opus")) {
          mimeType = "audio/webm;codecs=opus";
        } else if (MediaRecorder.isTypeSupported("audio/mp4")) {
          mimeType = "audio/mp4";
        } else if (MediaRecorder.isTypeSupported("audio/ogg;codecs=opus")) {
          mimeType = "audio/ogg;codecs=opus";
        }

        self.mediaRecorder = new MediaRecorder(stream, { mimeType: mimeType });
        self.audioChunks = [];

        self.mediaRecorder.ondataavailable = function (e) {
          if (e.data.size > 0) {
            self.audioChunks.push(e.data);
          }
        };

        self.mediaRecorder.onstop = function () {
          // Stop all tracks
          self.stopStream();

          // Only transcribe if we have audio data
          if (self.audioChunks.length > 0) {
            var audioBlob = new Blob(self.audioChunks, { type: mimeType });
            self.transcribeAudio(audioBlob);
          }
        };

        self.mediaRecorder.onerror = function (e) {
          console.error("MediaRecorder error:", e);
          self.stopStream();
          self.setButtonState("idle");
          self.showError(
            aicwpChatConfig.strings.transcriptionFailed ||
              "Recording failed",
          );
        };

        // Start recording
        self.mediaRecorder.start(1000); // Collect data every second
        self.isRecording = true;
        self.setButtonState("recording");

        // Auto-stop after 20 seconds to prevent forgotten recordings
        self.recordingTimeout = setTimeout(function () {
          if (self.isRecording) {
            self.stopRecording();
          }
        }, 20000);
      })
      .catch(function (err) {
        console.error("Microphone access denied:", err);
        self.showError(
          aicwpChatConfig.strings.micAccessDenied ||
            "Microphone access denied",
        );
      });
  };

  /**
   * Stop recording and transcribe
   */
  SpeechToTextHandler.prototype.stopRecording = function () {
    if (!this.isRecording || !this.mediaRecorder) {
      return;
    }

    // Clear timeout
    if (this.recordingTimeout) {
      clearTimeout(this.recordingTimeout);
      this.recordingTimeout = null;
    }

    this.isRecording = false;
    this.setButtonState("transcribing");

    // Stop the recorder (triggers onstop which handles transcription)
    if (this.mediaRecorder.state === "recording") {
      this.mediaRecorder.stop();
    }
  };

  /**
   * Cancel recording without transcribing
   */
  SpeechToTextHandler.prototype.cancelRecording = function () {
    if (!this.isRecording) {
      return;
    }

    // Clear timeout
    if (this.recordingTimeout) {
      clearTimeout(this.recordingTimeout);
      this.recordingTimeout = null;
    }

    this.isRecording = false;
    this.audioChunks = []; // Clear chunks so onstop doesn't transcribe

    // Stop the recorder
    if (this.mediaRecorder && this.mediaRecorder.state === "recording") {
      this.mediaRecorder.stop();
    }

    this.stopStream();
    this.setButtonState("idle");
  };

  /**
   * Stop media stream tracks
   */
  SpeechToTextHandler.prototype.stopStream = function () {
    if (this.stream) {
      this.stream.getTracks().forEach(function (track) {
        track.stop();
      });
      this.stream = null;
    }
  };

  /**
   * Transcribe audio via REST API
   *
   * @param {Blob} audioBlob The recorded audio blob
   */
  SpeechToTextHandler.prototype.transcribeAudio = function (audioBlob) {
    var self = this;

    // Check file size (25MB limit)
    if (audioBlob.size > 25 * 1024 * 1024) {
      this.setButtonState("idle");
      this.showError(
        aicwpChatConfig.strings.audioTooLarge || "Recording too large",
      );
      return;
    }

    // Build form data
    var formData = new FormData();
    formData.append("audio", audioBlob, "recording.webm");

    // Build headers
    var headers = {};
    if (aicwpChatConfig.isLoggedIn && aicwpChatConfig.nonce) {
      headers["X-WP-Nonce"] = aicwpChatConfig.nonce;
    }

    // Send to transcription endpoint
    $.ajax({
      url: aicwpChatConfig.apiBase + "/transcribe",
      method: "POST",
      headers: headers,
      data: formData,
      processData: false,
      contentType: false,
      timeout: 60000, // 60 second timeout
      success: function (response) {
        self.setButtonState("idle");

        if (response.success && response.text) {
          // Insert transcribed text into input
          var currentText = self.$input.val().trim();
          var newText = currentText
            ? currentText + " " + response.text
            : response.text;
          self.$input.val(newText).focus();

          // Mark that next message is from speech-to-text (for mic icon indicator)
          self.$wrapper.data("speech-pending", true);

          // Trigger input event to resize textarea if needed
          self.$input.trigger("input");
        } else {
          var errorMsg =
            response.error && response.error.message
              ? response.error.message
              : aicwpChatConfig.strings.transcriptionFailed ||
                "Transcription failed";
          self.showError(errorMsg);
        }
      },
      error: function (xhr, status, error) {
        self.setButtonState("idle");

        var errorMsg =
          aicwpChatConfig.strings.transcriptionFailed ||
          "Transcription failed";

        // Try to get error message from response
        if (
          xhr.responseJSON &&
          xhr.responseJSON.error &&
          xhr.responseJSON.error.message
        ) {
          errorMsg = xhr.responseJSON.error.message;
        } else if (status === "timeout") {
          errorMsg =
            aicwpChatConfig.strings.errorTimeout || "Request timed out";
        } else if (xhr.status === 429) {
          errorMsg =
            aicwpChatConfig.strings.errorRateLimit || "Too many requests";
        }

        self.showError(errorMsg);
      },
    });
  };

  /**
   * Set button visual state
   *
   * @param {string} state One of: 'idle', 'recording', 'transcribing'
   */
  SpeechToTextHandler.prototype.setButtonState = function (state) {
    var self = this;
    this.$btn.removeClass("recording transcribing");

    // Clear any existing timer
    if (this.timerInterval) {
      clearInterval(this.timerInterval);
      this.timerInterval = null;
    }

    switch (state) {
      case "recording":
        this.$btn.addClass("recording");
        this.$btn.attr(
          "title",
          aicwpChatConfig.strings.micStartRecording || "Recording...",
        );

        // Start timer
        this.recordingStartTime = Date.now();
        this.$timer.text("0:00");
        this.timerInterval = setInterval(function () {
          var elapsed = Math.floor(
            (Date.now() - self.recordingStartTime) / 1000,
          );
          var minutes = Math.floor(elapsed / 60);
          var seconds = elapsed % 60;
          self.$timer.text(minutes + ":" + (seconds < 10 ? "0" : "") + seconds);
        }, 100);
        break;
      case "transcribing":
        this.$btn.addClass("transcribing");
        this.$btn.attr(
          "title",
          aicwpChatConfig.strings.micStopRecording || "Processing...",
        );
        this.$timer.text("0:00");
        break;
      default:
        this.$btn.attr(
          "title",
          this.$btn.data("original-title") || "Voice Input",
        );
        this.$timer.text("0:00");
    }
  };

  /**
   * Show error message in chat
   *
   * @param {string} message Error message to display
   */
  SpeechToTextHandler.prototype.showError = function (message) {
    // Try to use the chat's addMessage method if available
    var chatInstance = this.$wrapper.data("aicwp-chat");
    if (chatInstance && typeof chatInstance.addMessage === "function") {
      chatInstance.addMessage("system", message);
    } else {
      // Fallback: append error directly
      var $error = $(
        '<div class="aicwp-chat-message aicwp-chat-message-system">' +
          '<div class="aicwp-chat-message-content">' +
          escapeHtml(message) +
          "</div>" +
          "</div>",
      );
      this.$messages.append($error);
      this.$messages.scrollTop(this.$messages[0].scrollHeight);
    }
  };

  /**
   * Escape HTML to prevent XSS
   *
   * @param {string} text Text to escape
   * @return {string} Escaped text
   */
  function escapeHtml(text) {
    var div = document.createElement("div");
    div.textContent = text;
    return div.innerHTML;
  }
})(jQuery);
