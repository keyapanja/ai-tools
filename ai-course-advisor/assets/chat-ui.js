(function () {
  function createWidget() {
    var root = document.getElementById('aica-chatbot-root');
    if (!root || !window.AICourseAdvisor) {
      return;
    }

    var cfg = window.AICourseAdvisor;
    var shell = document.createElement('div');
    shell.className = 'aica-shell';
    shell.innerHTML =
      '<button class="aica-toggle" aria-expanded="false" aria-label="Toggle chat">💬</button>' +
      '<div class="aica-panel" hidden>' +
      '<div class="aica-header"><span class="aica-title"></span></div>' +
      '<div class="aica-messages"></div>' +
      '<form class="aica-input-wrap"><input type="text" required placeholder="Type your goal..." /><button type="submit">Send</button></form>' +
      '</div>';

    root.appendChild(shell);

    var toggle = shell.querySelector('.aica-toggle');
    var panel = shell.querySelector('.aica-panel');
    var messages = shell.querySelector('.aica-messages');
    var form = shell.querySelector('.aica-input-wrap');
    var input = form.querySelector('input');
    var title = shell.querySelector('.aica-title');

    title.textContent = cfg.title || 'AI Course Advisor';
    shell.style.setProperty('--aica-primary', cfg.primaryColor || '#4f46e5');

    function appendMessage(text, type) {
      var item = document.createElement('div');
      item.className = 'aica-msg aica-' + type;
      item.innerHTML = text;
      messages.appendChild(item);
      messages.scrollTop = messages.scrollHeight;
    }

    appendMessage(cfg.greeting || 'Hi 👋 Tell me what you are looking for today.', 'bot');

    toggle.addEventListener('click', function () {
      var expanded = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', expanded ? 'false' : 'true');
      panel.hidden = expanded;
    });

    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var text = input.value.trim();
      if (!text) return;

      appendMessage(text.replace(/</g, '&lt;'), 'user');
      input.value = '';

      var typing = document.createElement('div');
      typing.className = 'aica-msg aica-bot aica-typing';
      typing.textContent = 'Typing...';
      messages.appendChild(typing);

      fetch(cfg.restUrl, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'X-WP-Nonce': cfg.nonce,
        },
        body: JSON.stringify({ message: text }),
      })
        .then(function (r) {
          return r.json();
        })
        .then(function (data) {
          typing.remove();
          appendMessage(data.answer || 'Sorry, I could not generate a recommendation.', 'bot');
        })
        .catch(function () {
          typing.remove();
          appendMessage('Unable to connect right now. Please try again.', 'bot');
        });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', createWidget);
  } else {
    createWidget();
  }
})();
