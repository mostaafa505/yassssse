/* مشترك بين كل الصفحات (حتى الدخول والتثبيت): إظهار/إخفاء كلمة المرور + تسجيل التطبيق (PWA) + زر التثبيت */
(function () {
  'use strict';
  var base = (document.currentScript && document.currentScript.src || '').replace(/assets\/js\/common\.js.*$/, '');

  // ── عين إظهار/إخفاء كلمة المرور على كل خانة باسوورد ──
  document.querySelectorAll('input[type="password"]').forEach(function (inp) {
    if (inp.dataset.pwDone) { return; }
    inp.dataset.pwDone = '1';
    var inGroup = inp.parentNode.classList.contains('input-group'), wrap = inp.parentNode;
    if (!inGroup) {
      wrap = document.createElement('div');
      wrap.className = 'pw-wrap';
      inp.parentNode.insertBefore(wrap, inp);
      wrap.appendChild(inp);
    }
    var b = document.createElement('button');
    b.type = 'button'; b.className = inGroup ? 'btn pw-eye-ig' : 'pw-eye'; b.tabIndex = -1;
    b.setAttribute('aria-label', 'إظهار كلمة المرور');
    b.innerHTML = '<i class="bi bi-eye"></i>';
    b.addEventListener('click', function () {
      var show = inp.type === 'password';
      inp.type = show ? 'text' : 'password';
      b.innerHTML = '<i class="bi ' + (show ? 'bi-eye-slash' : 'bi-eye') + '"></i>';
      b.setAttribute('aria-label', show ? 'إخفاء كلمة المرور' : 'إظهار كلمة المرور');
    });
    wrap.appendChild(b);
  });

  // ── تسجيل الـ Service Worker ──
  if ('serviceWorker' in navigator && (location.protocol === 'https:' || location.hostname === 'localhost' || location.hostname === '127.0.0.1')) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register(base + 'sw.js', { scope: base }).catch(function () {});
    });
  }

  // ── زر تثبيت التطبيق ──
  var standalone = window.matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  var deferred = null;
  function btns() { return document.querySelectorAll('[data-install]'); }
  function show(on) { btns().forEach(function (b) { b.classList.toggle('d-none', !on); }); }
  window.addEventListener('beforeinstallprompt', function (e) { e.preventDefault(); deferred = e; show(true); });
  window.addEventListener('appinstalled', function () { deferred = null; show(false); });
  var isIos = /iphone|ipad|ipod/i.test(navigator.userAgent) && !window.MSStream;
  document.addEventListener('DOMContentLoaded', function () {
    if (standalone) { show(false); return; }
    if (isIos) { show(true); }
    btns().forEach(function (b) {
      b.addEventListener('click', function () {
        if (deferred) { deferred.prompt(); deferred.userChoice.then(function () { deferred = null; show(false); }); return; }
        var m = document.getElementById('installHelp');
        if (m && window.bootstrap) { new bootstrap.Modal(m).show(); } else { alert('من قائمة المتصفح اختار «تثبيت التطبيق» أو «إضافة إلى الشاشة الرئيسية».'); }
      });
    });
  });
})();
