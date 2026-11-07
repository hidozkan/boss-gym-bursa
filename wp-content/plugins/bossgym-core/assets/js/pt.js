(function ($) {
    'use strict';
    $(function () {
        var $form = $('.bgpt-form').first();
        if (!$form.length || !window.BossGymPT) return;
        var $mode = $form.find('.bgpt-mode');
        var $package = $form.find('.bgpt-package');
        var $trainer = $form.find('.bgpt-trainer');
        var $date = $form.find('.bgpt-date');
        var $time = $form.find('.bgpt-time');
        var $grid = $form.find('.bgpt-slot-grid');
        var $slotStatus = $form.find('.bgpt-slot-status');
        var $cards = $('.bgpt-package-card');
        var $weeklyRows = $('.bgpt-weekly tbody tr');
        var $weeklyEmpty = $('.bgpt-weekly-empty');
        var requestedTrainer = String($trainer.data('requested-trainer') || BossGymPT.requestedTrainer || '');

        function syncSelectedSlot() {
            var selectedTime = String($time.val() || '');
            $grid.find('.bgpt-slot').each(function () {
                var selected = !this.disabled && String(this.dataset.time || '') === selectedTime;
                $(this).toggleClass('is-selected', selected).attr('aria-pressed', selected ? 'true' : 'false').text(this.dataset.time + ' - ' + (this.dataset.stateLabel || '') + (selected ? ' - Seçili' : ''));
                if (selected) $slotStatus.text('Seçilen saat: ' + this.dataset.time + '. Durum: müsait.');
            });
        }

        function clearSlots(message) {
            $time.prop('disabled', true).empty().append($('<option>').val('').text(message));
            $grid.empty();
            $slotStatus.text(message || 'Saat seçimi temizlendi.');
        }
        function renderGrid(items) {
            $grid.empty();
            (items || []).forEach(function (item) {
                var labels = { available: 'Müsait', full: 'Dolu', past: 'Geçmiş', closed: 'Kapalı' };
                var stateLabel = labels[item.state] || item.state;
                var $button = $('<button type="button">').addClass('bgpt-slot bgpt-slot--' + item.state).attr({'data-time': item.time, 'data-state-label': stateLabel, 'aria-pressed': 'false'}).text(item.time + ' - ' + stateLabel);
                if (item.state !== 'available') $button.prop('disabled', true);
                else $button.on('click', function () { $time.val(item.time).trigger('change'); });
                $grid.append($button);
            });
            var counts = {available:0, full:0, past:0, closed:0};
            (items || []).forEach(function (item) { if (Object.prototype.hasOwnProperty.call(counts, item.state)) counts[item.state]++; });
            $slotStatus.text('Saat durumu: ' + counts.available + ' müsait, ' + counts.full + ' dolu, ' + counts.closed + ' kapalı, ' + counts.past + ' geçmiş saat.');
            syncSelectedSlot();
        }
        function filterPackages(resetInvalid) {
            var mode = $mode.val();
            $package.find('option').each(function () {
                var optionMode = $(this).attr('data-mode');
                $(this).prop('hidden', !!optionMode && !!mode && optionMode !== mode);
            });
            $cards.each(function () { $(this).prop('hidden', !!mode && $(this).data('mode') !== mode); });
            var selected = $package.find(':selected');
            if (resetInvalid && selected.attr('data-mode') && mode && selected.attr('data-mode') !== mode) $package.val('');
            filterTrainers(true);
        }
        function filterTrainers(reset) {
            var packageId = String($package.val() || '');
            var mode = String($mode.val() || '');
            $trainer.find('option').each(function () {
                var pack = $(this).attr('data-pack');
                if (!pack) return;
                var optionMode = String($package.find('option[value="' + pack + '"]').attr('data-mode') || '');
                var modeMatches = !!mode && (!optionMode || optionMode === mode);
                var packageMatches = !packageId || pack === packageId;
                $(this).prop('hidden', !(modeMatches && packageMatches));
            });
            var selected = $trainer.find(':selected');
            var selectedPack = String(selected.attr('data-pack') || '');
            var selectedMode = selectedPack ? String($package.find('option[value="' + selectedPack + '"]').attr('data-mode') || '') : '';
            if (reset || (selectedPack && ((packageId && selectedPack !== packageId) || (mode && selectedMode && selectedMode !== mode)))) $trainer.val('');
            if (!packageId) clearSlots('Önce antrenman türünü ve paketi seçin');
            else if (!$trainer.val()) clearSlots('Antrenör seçin');
        }
        function filterWeeklySchedule() {
            if (!$weeklyRows.length) return;
            var mode = String($mode.val() || '');
            var packageId = String($package.val() || '');
            var trainerId = String($trainer.val() || '');
            var visibleRows = 0;
            $weeklyRows.each(function () {
                var $row = $(this);
                var trainerMatches = !trainerId || String($row.data('trainer-id')) === trainerId;
                var visiblePrograms = 0;
                $row.find('.bgpt-weekly-program').each(function () {
                    var $program = $(this);
                    var packageMatches = !packageId || String($program.data('package-id')) === packageId;
                    var modeMatches = !mode || String($program.data('mode')) === mode;
                    var visible = packageMatches && modeMatches;
                    $program.prop('hidden', !visible);
                    if (visible) visiblePrograms += 1;
                });
                var visible = trainerMatches && visiblePrograms > 0;
                $row.prop('hidden', !visible);
                if (visible) visibleRows += 1;
            });
            $weeklyEmpty.prop('hidden', visibleRows > 0);
        }
        function loadSlots() {
            var packageId = $package.val(), trainerId = $trainer.val(), date = $date.val();
            if (!packageId || !trainerId || !date) {
                clearSlots(!packageId ? 'Önce antrenman türünü ve paketi seçin' : (!trainerId ? 'Antrenör seçin' : 'Tarih seçin'));
                return;
            }
            clearSlots(BossGymPT.loading || 'Uygun saatler yükleniyor…');
            $.post(BossGymPT.ajax, { action: 'bossgym_pt_slots', nonce: BossGymPT.nonce, package_id: packageId, trainer_id: trainerId, date: date })
                .done(function (response) {
                    $time.empty();
                    renderGrid(response.success ? response.data.availability : []);
                    if (response.success && response.data.slots.length) {
                        $time.append($('<option>').val('').text('Saat seçin'));
                        response.data.slots.forEach(function (slot) { $time.append($('<option>').val(slot).text(slot)); });
                        $time.prop('disabled', false);
                    } else {
                        clearSlots(response.data && response.data.message ? response.data.message : (BossGymPT.empty || 'Bu tarih için uygun saat yok.'));
                    }
                }).fail(function (xhr) {
                    clearSlots(xhr.responseJSON && xhr.responseJSON.data ? xhr.responseJSON.data.message : (BossGymPT.empty || 'Uygun saat bilgisi alınamadı.'));
                });
        }

        $mode.on('change', function () {
            $package.val('');
            filterPackages(false);
            filterWeeklySchedule();
            clearSlots('Önce antrenman türünü ve paketi seçin');
        });
        $package.on('change', function () { filterPackages(false); filterTrainers(true); filterWeeklySchedule(); loadSlots(); });
        $trainer.on('change', function () { filterWeeklySchedule(); loadSlots(); });
        $date.on('change', loadSlots);
        $time.on('change', syncSelectedSlot);

        if (requestedTrainer && !$package.val()) {
            var $firstMatch = $trainer.find('option[data-pack]').filter(function () { return $(this).val() === requestedTrainer; }).first();
            if ($firstMatch.length) {
                var trainerPackage = String($firstMatch.attr('data-pack'));
                var packageMode = $package.find('option[value="' + trainerPackage + '"]').attr('data-mode');
                if (packageMode) $mode.val(packageMode);
                $package.val(trainerPackage);
            }
        }
        filterPackages(false);
        filterWeeklySchedule();
        if ($package.val()) {
            filterTrainers(true);
            if (requestedTrainer) {
                var $candidate = $trainer.find('option[value="' + requestedTrainer + '"][data-pack="' + $package.val() + '"]');
                if ($candidate.length) $trainer.val(requestedTrainer);
            }
            loadSlots();
        }
        window.setInterval(function () { if ($package.val() && $trainer.val() && $date.val()) loadSlots(); }, 30000);
    });
}(jQuery));
