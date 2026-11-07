(function () {
  'use strict';
  var root = document.documentElement;
  var items;
  if (!('IntersectionObserver' in window)) return;
  items = document.querySelectorAll('.bossgym-home .bg-section, .bossgym-home .bg-card, .bossgym-home .bg-demo-panel');
  if (!items.length) return;
  root.classList.add('bg-motion-ready');
  var observer = new IntersectionObserver(function (entries) {
    entries.forEach(function (entry) {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('is-visible');
      observer.unobserve(entry.target);
    });
  }, { threshold: 0.12, rootMargin: '0px 0px -35px 0px' });
  items.forEach(function (item) {
    item.setAttribute('data-reveal', '');
    observer.observe(item);
  });
})();
