(function () {
    'use strict';
    document.addEventListener('DOMContentLoaded', function () {
        var root = document.querySelector('.bg-fg-gallery');
        if (!root) return;
        var dataNode = root.querySelector('.bg-fg-data');
        var dialog = root.querySelector('.bg-fg-dialog');
        if (!dataNode || !dialog || typeof dialog.showModal !== 'function') return;
        var sets;
        try { sets = JSON.parse(dataNode.textContent || '{}'); } catch (e) { return; }
        var title = dialog.querySelector('#bg-fg-title');
        var description = dialog.querySelector('.bg-fg-dialog__description');
        var image = dialog.querySelector('[data-fg-image]');
        var count = dialog.querySelector('.bg-fg-count');
        var live = dialog.querySelector('[data-fg-live]');
        var active = [];
        var index = 0;
        var trigger = null;
        var touchX = 0;
        function show(indexValue) {
            if (!active.length) return;
            index = (indexValue + active.length) % active.length;
            image.src = active[index][0];
            image.alt = active[index][1];
            count.textContent = (index + 1) + '/' + active.length;
            live.textContent = (index + 1) + ' / ' + active.length;
        }
        function open(key, source) {
            var set = sets[key];
            if (!set || !Array.isArray(set.images) || !set.images.length) return;
            active = set.images;
            trigger = source;
            title.textContent = set.title;
            description.textContent = set.description;
            show(0);
            dialog.showModal();
            dialog.scrollTop = 0;
            dialog.querySelector('[data-fg-close]').focus({ preventScroll: true });
        }
        root.querySelectorAll('[data-fg-open]').forEach(function (button) {
            button.addEventListener('click', function () { open(button.dataset.fgOpen, button); });
        });
        dialog.querySelector('[data-fg-close]').addEventListener('click', function () { dialog.close(); });
        dialog.querySelector('[data-fg-prev]').addEventListener('click', function () { show(index - 1); });
        dialog.querySelector('[data-fg-next]').addEventListener('click', function () { show(index + 1); });
        dialog.addEventListener('click', function (event) { if (event.target === dialog) dialog.close(); });
        dialog.addEventListener('close', function () { if (trigger && document.contains(trigger)) trigger.focus(); });
        dialog.addEventListener('keydown', function (event) {
            if (event.key === 'ArrowLeft') { event.preventDefault(); show(index - 1); }
            if (event.key === 'ArrowRight') { event.preventDefault(); show(index + 1); }
        });
        image.addEventListener('touchstart', function (event) { touchX = event.changedTouches[0].clientX; }, { passive: true });
        image.addEventListener('touchend', function (event) {
            var delta = event.changedTouches[0].clientX - touchX;
            if (Math.abs(delta) > 45) show(index + (delta < 0 ? 1 : -1));
        }, { passive: true });
    });
}());
