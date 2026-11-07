(function () {
  'use strict';
  const card = document.querySelector('.bgm-occupancy');
  if (!card || typeof BossGymMemberOccupancy === 'undefined') return;
  const status = card.querySelector('[data-occupancy-status]');
  const grid = card.querySelector('[data-occupancy-grid]');
  const progressWrap = card.querySelector('[data-occupancy-progress-wrap]');
  const progress = card.querySelector('[data-occupancy-progress]');
  const progressLabel = card.querySelector('[data-occupancy-progress-label]');
  const fields = ['inside', 'male', 'female', 'unspecified', 'capacity', 'percentage'];
  let timer = 0;
  let pending = false;
  const showError = () => {
    grid.hidden = true;
    progressWrap.hidden = true;
    status.classList.add('is-error');
    status.textContent = BossGymMemberOccupancy.error || 'Salon doluluk verisi şu anda alınamıyor.';
  };
  const refresh = async () => {
    if (pending || document.hidden) return;
    pending = true;
    try {
      const body = new URLSearchParams({ action: 'bossgym_member_occupancy', nonce: BossGymMemberOccupancy.nonce });
      const response = await fetch(BossGymMemberOccupancy.ajaxUrl, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' }, body
      });
      const payload = await response.json();
      if (!response.ok || !payload.success || !payload.data) throw new Error('occupancy unavailable');
      const data = payload.data;
      const values = {};
      for (const key of fields) {
        values[key] = Number(data[key]);
        if (!Number.isFinite(values[key]) || values[key] < 0) throw new Error('invalid occupancy data');
      }
      if (values.capacity < 1 || values.inside !== values.male + values.female + values.unspecified) throw new Error('inconsistent occupancy data');
      for (const key of fields) {
        const element = card.querySelector('[data-occupancy-value="' + key + '"]');
        if (element) element.textContent = String(Math.round(values[key]));
      }
      progress.value = Math.min(100, values.percentage);
      progress.setAttribute('aria-valuetext', values.percentage + '% dolu, ' + values.inside + ' / ' + values.capacity + ' kişi');
      progressLabel.textContent = values.percentage + '%';
      grid.hidden = false;
      progressWrap.hidden = false;
      status.classList.remove('is-error');
      const time = new Intl.DateTimeFormat('tr-TR', { hour: '2-digit', minute: '2-digit' }).format(new Date());
      status.textContent = 'Son güncelleme ' + time + '. İçeride ' + values.inside + ' kişi, doluluk yüzde ' + values.percentage + '.';
    } catch (_) {
      showError();
    } finally {
      pending = false;
    }
  };
  refresh();
  timer = window.setInterval(refresh, 60000);
  document.addEventListener('visibilitychange', function () { if (!document.hidden) refresh(); });
  window.addEventListener('pagehide', function () { window.clearInterval(timer); });
}());