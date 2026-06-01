(function () {
  var root = document.querySelector('[data-chatbot]');
  if (!root) {
    return;
  }

  var toggle = root.querySelector('[data-chatbot-toggle]');
  var close = root.querySelector('[data-chatbot-close]');
  var form = root.querySelector('[data-chatbot-form]');
  var input = root.querySelector('[data-chatbot-input]');
  var messages = root.querySelector('[data-chatbot-messages]');
  var endButton = root.querySelector('[data-chatbot-end]');
  var sessionKey = 'mediapitch_chatbot_session_id';
  var finalizedKey = 'mediapitch_chatbot_finalized';
  var pending = false;

  function sessionId() {
    var existing = sessionStorage.getItem(sessionKey);
    if (existing) {
      return existing;
    }

    var value = 'mp_' + Date.now().toString(36) + '_' + Math.random().toString(36).slice(2, 12);
    sessionStorage.setItem(sessionKey, value);
    sessionStorage.removeItem(finalizedKey);
    return value;
  }

  function addMessage(text, type) {
    var node = document.createElement('div');
    node.className = 'mp-chatbot__message mp-chatbot__message--' + type;
    node.innerHTML = renderMarkdown(text);
    messages.appendChild(node);
    messages.scrollTop = messages.scrollHeight;
    return node;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function sanitizeUrl(value) {
    var url = String(value || '').trim();
    if (/^(https?:\/\/|mailto:|tel:)/i.test(url) || /^[a-z0-9._/-]+\.php(?:[?#][^\s]*)?$/i.test(url)) {
      return escapeHtml(url);
    }
    return '#';
  }

  function renderInlineMarkdown(text) {
    var html = escapeHtml(text);
    html = html.replace(/`([^`]+)`/g, '<code>$1</code>');
    html = html.replace(/\[([^\]]+)\]\(([^)]+)\)/g, function (_, label, url) {
      return '<a href="' + sanitizeUrl(url) + '" target="_blank" rel="noopener noreferrer">' + label + '</a>';
    });
    html = html.replace(/\*\*([^*]+)\*\*/g, '<strong>$1</strong>');
    html = html.replace(/__([^_]+)__/g, '<strong>$1</strong>');
    html = html.replace(/(^|[^*])\*([^*\n]+)\*/g, '$1<em>$2</em>');
    html = html.replace(/(^|[^_])_([^_\n]+)_/g, '$1<em>$2</em>');
    return html;
  }

  function renderMarkdown(text) {
    var lines = String(text || '').replace(/\r\n/g, '\n').split('\n');
    var output = [];
    var listOpen = false;
    var orderedListOpen = false;

    function closeList() {
      if (listOpen) {
        output.push('</ul>');
        listOpen = false;
      }
      if (orderedListOpen) {
        output.push('</ol>');
        orderedListOpen = false;
      }
    }

    lines.forEach(function (line) {
      var trimmed = line.trim();
      var bullet = trimmed.match(/^[-*]\s+(.+)$/);
      var ordered = trimmed.match(/^\d+\.\s+(.+)$/);
      var heading = trimmed.match(/^(#{1,3})\s+(.+)$/);

      if (bullet) {
        if (orderedListOpen) {
          output.push('</ol>');
          orderedListOpen = false;
        }
        if (!listOpen) {
          output.push('<ul>');
          listOpen = true;
        }
        output.push('<li>' + renderInlineMarkdown(bullet[1]) + '</li>');
        return;
      }

      if (ordered) {
        if (listOpen) {
          output.push('</ul>');
          listOpen = false;
        }
        if (!orderedListOpen) {
          output.push('<ol>');
          orderedListOpen = true;
        }
        output.push('<li>' + renderInlineMarkdown(ordered[1]) + '</li>');
        return;
      }

      closeList();

      if (trimmed === '') {
        output.push('<br>');
      } else if (heading) {
        output.push('<strong class="mp-chatbot__heading">' + renderInlineMarkdown(heading[2]) + '</strong>');
      } else {
        output.push('<p>' + renderInlineMarkdown(trimmed) + '</p>');
      }
    });

    closeList();
    return output.join('');
  }

  function request(payload, keepalive) {
    payload.session_id = sessionId();
    payload.page = window.location.href;

    return fetch('chatbot-api.php', {
      method: 'POST',
      headers: {'Content-Type': 'application/json'},
      body: JSON.stringify(payload),
      keepalive: !!keepalive
    }).then(function (response) {
      return response.json().then(function (data) {
        if (!response.ok || data.ok === false) {
          throw new Error(data.error || 'Chatbot request failed.');
        }
        if (data.session_id) {
          sessionStorage.setItem(sessionKey, data.session_id);
        }
        return data;
      });
    });
  }

  toggle.addEventListener('click', function () {
    root.classList.toggle('is-open');
    if (root.classList.contains('is-open')) {
      input.focus();
    }
  });

  close.addEventListener('click', function () {
    root.classList.remove('is-open');
  });

  form.addEventListener('submit', function (event) {
    event.preventDefault();
    if (pending) {
      return;
    }

    var text = input.value.trim();
    if (!text) {
      return;
    }

    pending = true;
    input.value = '';
    sessionStorage.removeItem(finalizedKey);
    addMessage(text, 'user');
    var status = addMessage('Media Pitch assistant is typing...', 'status');

    request({action: 'message', message: text})
      .then(function (data) {
        status.remove();
        addMessage(data.reply, 'bot');
      })
      .catch(function (error) {
        status.remove();
        addMessage(error.message || 'The assistant is unavailable right now. Please contact info@mediapitch.in.', 'bot');
      })
      .finally(function () {
        pending = false;
        input.focus();
      });
  });

  input.addEventListener('keydown', function (event) {
    if (event.key === 'Enter' && !event.shiftKey) {
      event.preventDefault();
      if (form.requestSubmit) {
        form.requestSubmit();
      } else {
        form.dispatchEvent(new Event('submit', {cancelable: true}));
      }
    }
  });

  endButton.addEventListener('click', function () {
    if (sessionStorage.getItem(finalizedKey) === '1') {
      addMessage('This chat has already been sent to the Media Pitch team.', 'status');
      return;
    }

    addMessage('Sending this chat to the Media Pitch team...', 'status');
    request({action: 'finalize'})
      .then(function () {
        sessionStorage.setItem(finalizedKey, '1');
        addMessage('Thanks. This chat has been sent to the Media Pitch team.', 'bot');
      })
      .catch(function (error) {
        addMessage(error.message || 'Unable to send the chat summary right now.', 'bot');
      });
  });

  window.addEventListener('pagehide', function () {
    if (sessionStorage.getItem(finalizedKey) === '1') {
      return;
    }

    request({action: 'finalize'}, true)
      .then(function () {
        sessionStorage.setItem(finalizedKey, '1');
      })
      .catch(function () {});
  });
})();
