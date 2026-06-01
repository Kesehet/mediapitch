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
    node.textContent = text;
    messages.appendChild(node);
    messages.scrollTop = messages.scrollHeight;
    return node;
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

