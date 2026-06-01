<?php
if (!isset($vpjtqwv)) {
    exit();
}
?>
<link rel="stylesheet" href="chatbot.css">
<div class="mp-chatbot" data-chatbot>
  <button class="mp-chatbot__launcher" type="button" data-chatbot-toggle aria-label="Open Media Pitch chat">
    <span class="mp-chatbot__launcher-icon">?</span>
    <span class="mp-chatbot__launcher-text">Ask Media Pitch</span>
  </button>
  <section class="mp-chatbot__panel" data-chatbot-panel aria-live="polite" aria-label="Media Pitch chatbot">
    <header class="mp-chatbot__header">
      <div>
        <strong>Media Pitch</strong>
        <span>Business assistant</span>
      </div>
      <button type="button" class="mp-chatbot__close" data-chatbot-close aria-label="Close chat">x</button>
    </header>
    <div class="mp-chatbot__messages" data-chatbot-messages>
      <div class="mp-chatbot__message mp-chatbot__message--bot">Hi, I can help you understand Media Pitch services and connect you with the right team. What are you looking for?</div>
    </div>
    <form class="mp-chatbot__form" data-chatbot-form>
      <label class="mp-chatbot__label" for="mp-chatbot-input">Message</label>
      <textarea id="mp-chatbot-input" data-chatbot-input rows="2" maxlength="1500" placeholder="Ask about services, projects, or next steps"></textarea>
      <div class="mp-chatbot__actions">
        <button type="button" data-chatbot-end>End chat</button>
        <button type="submit">Send</button>
      </div>
    </form>
  </section>
</div>
<script src="chatbot.js" defer></script>

