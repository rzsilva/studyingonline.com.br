(function () {
  'use strict';

  var header = document.querySelector('.site-header');
  var toggle = document.querySelector('.nav-toggle');
  var menu = document.getElementById('menu');

  function setMenu(open) {
    menu.classList.toggle('open', open);
    toggle.setAttribute('aria-expanded', String(open));
    toggle.querySelector('.sr-only').textContent = open ? 'Fechar menu' : 'Abrir menu';
  }

  toggle.addEventListener('click', function () {
    setMenu(!menu.classList.contains('open'));
  });
  menu.addEventListener('click', function (e) {
    if (e.target.closest('a')) setMenu(false);
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape' && menu.classList.contains('open')) { setMenu(false); toggle.focus(); }
  });

  function onScroll() { header.classList.toggle('scrolled', window.scrollY > 8); }
  window.addEventListener('scroll', onScroll, { passive: true });
  onScroll();

  document.getElementById('ano').textContent = new Date().getFullYear();

  // Formulário de contato
  var form = document.getElementById('form-contato');
  var status = form.querySelector('.form-status');
  var msg = form.querySelector('#mensagem');

  function showStatus(text, ok) {
    status.textContent = text;
    status.className = 'form-status ' + (ok ? 'ok' : 'err');
  }

  // Botão "Contratar" pré-preenche a mensagem com o plano escolhido
  document.querySelectorAll('[data-plano]').forEach(function (a) {
    a.addEventListener('click', function () {
      if (!msg.value.trim()) msg.value = 'Tenho interesse no Plano ' + a.dataset.plano + '.';
    });
  });

  if (/[?&]enviado=1/.test(location.search)) showStatus('Mensagem enviada! Em breve entraremos em contato.', true);

  form.addEventListener('submit', function (e) {
    e.preventDefault();
    var invalid = null;
    form.querySelectorAll('input, textarea').forEach(function (el) {
      var bad = !el.checkValidity();
      el.setAttribute('aria-invalid', String(bad));
      if (bad && !invalid) invalid = el;
    });
    if (invalid) {
      showStatus('Verifique os campos destacados.', false);
      invalid.focus();
      return;
    }

    var btn = form.querySelector('button[type=submit]');
    btn.disabled = true;
    btn.textContent = 'Enviando…';
    status.textContent = '';

    fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        showStatus(data.message, data.ok);
        if (data.ok) form.reset();
      })
      .catch(function () {
        showStatus('Não foi possível enviar agora. Tente novamente ou escreva para comercial@adaline.com.br.', false);
      })
      .finally(function () {
        btn.disabled = false;
        btn.textContent = 'Enviar mensagem';
      });
  });
})();
