(function () {
  'use strict';

  function initCourtsCarousels() {
    document.querySelectorAll('[data-court-slider]').forEach(function (slider) {
      if (slider.dataset.carouselReady === 'true') return;
      var image = slider.querySelector('[data-court-slide-image]');
      var count = slider.querySelector('[data-court-count]');
      var previous = slider.querySelector('[data-court-prev]');
      var next = slider.querySelector('[data-court-next]');
      var errorNotice = slider.querySelector('[data-court-image-error]');
      var slides;
      try { slides = JSON.parse(slider.dataset.courtSlides || '[]'); } catch (error) { slides = []; }
      if (!image || !count || !previous || !next || !slides.length) return;

      slider.dataset.carouselReady = 'true';
      var index = 0;
      var transitionTimer;
      var startX = null;
      var startY = null;

      function showSlide(nextIndex, announce) {
        index = (nextIndex + slides.length) % slides.length;
        var slide = slides[index];
        slider.classList.add('is-transitioning');
        window.clearTimeout(transitionTimer);
        image.onload = function () {
          image.hidden = false;
          if (errorNotice) errorNotice.hidden = true;
          slider.classList.remove('is-transitioning');
        };
        image.onerror = function () {
          image.hidden = true;
          if (errorNotice) errorNotice.hidden = false;
          slider.classList.remove('is-transitioning');
        };
        image.alt = slide.alt || '';
        image.src = slide.src;
        count.textContent = (index + 1) + ' / ' + slides.length;
        if (announce) count.setAttribute('aria-label', 'Fotoğraf ' + (index + 1) + ' / ' + slides.length);
        transitionTimer = window.setTimeout(function () { slider.classList.remove('is-transitioning'); }, 260);
      }

      previous.addEventListener('click', function () { showSlide(index - 1, true); });
      next.addEventListener('click', function () { showSlide(index + 1, true); });
      slider.addEventListener('keydown', function (event) {
        if (event.altKey || event.ctrlKey || event.metaKey) return;
        if (event.key === 'ArrowLeft') { event.preventDefault(); showSlide(index - 1, true); }
        if (event.key === 'ArrowRight') { event.preventDefault(); showSlide(index + 1, true); }
      });
      slider.addEventListener('touchstart', function (event) {
        if (!event.changedTouches.length) return;
        startX = event.changedTouches[0].clientX;
        startY = event.changedTouches[0].clientY;
      }, { passive: true });
      slider.addEventListener('touchend', function (event) {
        if (startX === null || !event.changedTouches.length) return;
        var deltaX = event.changedTouches[0].clientX - startX;
        var deltaY = event.changedTouches[0].clientY - startY;
        startX = null;
        startY = null;
        if (Math.abs(deltaX) < 42 || Math.abs(deltaX) <= Math.abs(deltaY)) return;
        event.preventDefault();
        showSlide(index + (deltaX < 0 ? 1 : -1), true);
      }, { passive: false });
      showSlide(0, false);
      if (image.complete && image.naturalWidth === 0) {
        image.hidden = true;
        if (errorNotice) errorNotice.hidden = false;
      }
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCourtsCarousels, { once: true });
  } else {
    initCourtsCarousels();
  }
}());
