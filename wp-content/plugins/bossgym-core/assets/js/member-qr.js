(function () {
  'use strict';

  function renderMemberCodes() {
    document.querySelectorAll('.bgts-member-qr[data-code]').forEach(function (container) {
      var token = container.getAttribute('data-code') || '';
      if (!/^[a-f0-9]{48}$/.test(token) || typeof window.qrcode !== 'function') {
        container.textContent = 'QR g\u00f6rseli olu\u015fturulamad\u0131. Manuel yedek kodu kullan\u0131n.';
        container.setAttribute('role', 'status');
        return;
      }

      try {
        var qr = window.qrcode(0, 'M');
        qr.addData(token);
        qr.make();
        container.innerHTML = qr.createSvgTag({ cellSize: 8, margin: 4, scalable: true });
        var svg = container.querySelector('svg');
        if (svg) {
          svg.setAttribute('role', 'img');
          svg.setAttribute('aria-label', 'Ki\u015fisel \u00fcyelik QR kodu');
          svg.setAttribute('focusable', 'false');
        }
      } catch (error) {
        container.textContent = 'QR g\u00f6rseli olu\u015fturulamad\u0131. Manuel yedek kodu kullan\u0131n.';
        container.setAttribute('role', 'status');
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderMemberCodes, { once: true });
  } else {
    renderMemberCodes();
  }
}());
