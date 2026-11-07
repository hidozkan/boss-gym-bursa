(function () {
    'use strict';
    function format(amount) {
        try { return '₺' + new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 0 }).format(amount); }
        catch (e) { return '₺' + amount; }
    }
    function refresh(form) {
        var pack = form.querySelector('.bgm-package-choice');
        var trainer = form.querySelector('.bgm-trainer-choice');
        var summary = form.querySelector('.bgm-price-breakdown');
        if (!summary) return;
        var option = pack && pack.selectedOptions ? pack.selectedOptions[0] : null;
        var base = option ? Number(option.dataset.base || 0) : 0;
        var addon = option ? Number(option.dataset.addon || 0) : 0;
        if (trainer) {
            Array.prototype.forEach.call(trainer.options, function (item) {
                if (item.value === '0') return;
                item.disabled = addon <= 0;
            });
            if (addon <= 0 && trainer.value !== '0') trainer.value = '0';
        }
        var extra = trainer && trainer.value !== '0' ? addon : 0;
        summary.dataset.basePrice = String(base);
        summary.dataset.addonPrice = String(addon);
        summary.querySelector('[data-price-base]').textContent = format(base);
        summary.querySelector('[data-price-addon]').textContent = format(extra);
        summary.querySelector('[data-price-total]').textContent = format(base + extra);
        var note = form.querySelector('.bgm-trainer-fee-note');
        if (note) note.textContent = addon > 0 ? 'Antrenör/PT ek ücreti, antrenör seçilirse temsili toplam fiyata eklenir.' : 'Antrenör/PT ek ücreti bu paket için yönetici tarafından tanımlanmamış; yalnızca “Antrenör istemiyorum” seçeneği kullanılabilir. İndirim uygulanmaz.';
    }
    document.addEventListener('change', function (event) {
        if (!event.target.matches('.bgm-package-choice, .bgm-trainer-choice')) return;
        var form = event.target.closest('.bgm-form');
        if (form) refresh(form);
    });
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.bgm-form').forEach(refresh);
    });
}());
