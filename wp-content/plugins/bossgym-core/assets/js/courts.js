(function () {
  'use strict';
  const form = document.querySelector('[data-bgc-booking]');
  if (!form || typeof BossGymCourts === 'undefined') return;
  if (form.dataset.bgcInitialized === 'true') return;
  form.dataset.bgcInitialized = 'true';

  const court = form.querySelector('[data-bgc-court]');
  const date = form.querySelector('[data-bgc-date]');
  const duration = form.querySelector('[data-bgc-duration]');
  const time = form.querySelector('[data-bgc-time]');
  const summary = form.querySelector('[data-bgc-summary]');
  const submit = form.querySelector('[data-bgc-submit]');
  const grid = form.querySelector('.bgc-slot-grid');
  const slotStatus = form.querySelector('.bgc-slot-status');
  let requestId = 0;
  let verifiedSlots = null;
  let pendingRestoredTime = time.dataset.bgcInitialTime || '';
  let submitting = false;

  async function refreshNonces() {
    const payload = new URLSearchParams({ action: BossGymCourts.refreshAction || 'bossgym_court_refresh_nonces' });
    const response = await fetch(BossGymCourts.ajax, {
      method: 'POST', credentials: 'same-origin', cache: 'no-store',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
      body: payload.toString()
    });
    const result = await response.json();
    if (!response.ok || !result.success || !result.data || !result.data.booking || !result.data.slots) {
      throw new Error(result.data && result.data.message ? result.data.message : 'Güvenlik anahtarı yenilenemedi. Lütfen sayfayı tazeleyip tekrar deneyin.');
    }
    const bookingNonce = form.querySelector('input[name="_bgc_nonce"]');
    if (!bookingNonce) throw new Error('Rezervasyon formu güvenlik alanı bulunamadı.');
    bookingNonce.value = result.data.booking;
    BossGymCourts.nonce = result.data.slots;
  }

  function resetTimes(message) {
    verifiedSlots = null;
    time.innerHTML = '';
    const option = document.createElement('option');
    option.value = '';
    option.textContent = message;
    time.appendChild(option);
    time.disabled = true;
    submit.disabled = true;
    if (slotStatus) slotStatus.textContent = message;
  }

  function setDurations() {
    duration.innerHTML = '';
    const selected = court.options[court.selectedIndex];
    const min = selected && selected.value ? Number(selected.dataset.min) : 0;
    const max = selected && selected.value ? Number(selected.dataset.max) : 0;
    const prompt = document.createElement('option');
    prompt.value = '';
    prompt.textContent = min ? 'Süre seçin' : 'Önce saha seçin';
    duration.appendChild(prompt);
    for (let value = min; value && value <= max; value += 30) {
      const option = document.createElement('option');
      option.value = String(value);
      option.textContent = value >= 60 ? (value % 60 ? Math.floor(value / 60) + ' saat 30 dakika' : value / 60 + ' saat') : value + ' dakika';
      duration.appendChild(option);
    }
    resetTimes('Önce saha, tarih ve süre seçin');
    summary.textContent = 'Saha, tarih ve süre seçtiğinizde temsili tutar burada gösterilir.';
  }

  function renderGrid(items) {
    if (!grid) return; grid.innerHTML = '';
    const labels = {available:'\u004d\u00fc\u0073\u0061\u0069\u0074',full:'\u0044\u006f\u006c\u0075',past:'\u0047\u0065\u00e7\u006d\u0069\u015f',closed:'\u004b\u0061\u0070\u0061\u006c\u0131'};
    (items || []).forEach(item => { const b=document.createElement('button'); b.type='button'; b.className='bgc-slot bgc-slot--'+item.state; b.dataset.time=item.time; b.dataset.stateLabel=labels[item.state]||item.state; b.setAttribute('aria-pressed','false'); b.textContent=item.time+' - '+b.dataset.stateLabel; b.disabled=item.state!=='available'; if(!b.disabled)b.addEventListener('click',()=>{time.value=item.time;time.dispatchEvent(new Event('change',{bubbles:true}));}); grid.appendChild(b); });
    syncSelectedSlot();
  }

  function syncSelectedSlot() {
    if (!grid) return;
    grid.querySelectorAll('.bgc-slot').forEach(button => {
      const selected = !button.disabled && button.dataset.time === time.value;
      button.classList.toggle('is-selected', selected);
      button.setAttribute('aria-pressed', selected ? 'true' : 'false');
      button.textContent = button.dataset.time + ' - ' + (button.dataset.stateLabel || '') + (selected ? ' - Seçili' : '');
      if (selected && slotStatus) slotStatus.textContent = 'Seçilen saat: ' + button.dataset.time + '. Durum: müsait.';
    });
  }

  async function loadTimes() {
    const currentRequest = ++requestId;
    if (!court.value || !date.value || !duration.value) {
      resetTimes('Önce saha, tarih ve süre seçin');
      summary.textContent = 'Saha, tarih ve süre seçtiğinizde temsili tutar burada gösterilir.';
      return false;
    }
    const previouslySelectedTime = time.value || pendingRestoredTime;
    pendingRestoredTime = '';
    verifiedSlots = null;
    submit.disabled = true;
    const selected = court.options[court.selectedIndex];
    const payload = new URLSearchParams({
      action: 'bossgym_court_slots', nonce: BossGymCourts.nonce,
      court_id: court.value, date: date.value, duration: duration.value
    });
    try {
      const response = await fetch(BossGymCourts.ajax, {
        method: 'POST', credentials: 'same-origin',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
        body: payload.toString()
      });
      const result = await response.json();
      if (!response.ok || !result.success) throw new Error(result.data && result.data.message ? result.data.message : 'Saatler yüklenemedi.');
      if (currentRequest !== requestId) return false;
      time.innerHTML = '';
      renderGrid(result.data.availability);
      const counts = {available:0, full:0, past:0, closed:0};
      (result.data.availability || []).forEach(item => { if (Object.prototype.hasOwnProperty.call(counts,item.state)) counts[item.state]++; });
      if (slotStatus) slotStatus.textContent = 'Saat durumu: ' + counts.available + ' müsait, ' + counts.full + ' dolu, ' + counts.closed + ' kapalı, ' + counts.past + ' geçmiş saat.';
      const prompt = document.createElement('option');
      prompt.value = '';
      prompt.textContent = result.data.slots.length ? 'Saat seçin' : (BossGymCourts.empty || 'Uygun saat bulunamadı.');
      time.appendChild(prompt);
      result.data.slots.forEach(function (slot) {
        const option = document.createElement('option');
        option.value = slot;
        option.textContent = slot;
        time.appendChild(option);
      });
      verifiedSlots = result.data.slots;
      const selectionStillAvailable = previouslySelectedTime && verifiedSlots.includes(previouslySelectedTime);
      time.value = selectionStillAvailable ? previouslySelectedTime : '';
      syncSelectedSlot();
      time.disabled = !result.data.slots.length;
      if (previouslySelectedTime && !selectionStillAvailable) {
        summary.textContent = 'Seçili saat bu saha, tarih veya süre için artık uygun değil. Lütfen güncel müsait saatlerden birini seçin.';
      } else {
        updateSummary(selected);
      }
      return true;
    } catch (error) {
      if (currentRequest !== requestId) return false;
      verifiedSlots = null;
      submit.disabled = true;
      summary.textContent = (error.message || 'Müsaitlik bilgisi alınamadı.') + ' Seçiminiz korunuyor; yeniden kontrol ediliyor.';
      return false;
    }
  }

  function updateSummary(selected) {
    if (!selected || !selected.value || !duration.value) return;
    const rate = Number(selected.dataset.rate || 0);
    const minutes = Number(duration.value);
    const total = new Intl.NumberFormat('tr-TR', { maximumFractionDigits: 2 }).format(rate * minutes / 60);
    const chosenTime = time.value ? ' Başlangıç: ' + time.value + '.' : '';
    summary.textContent = selected.textContent + ' · ' + date.value + ' · ' + duration.options[duration.selectedIndex].text + chosenTime + ' · Temsili tutar: ' + total + ' TL. Ödeme alınmaz; rezervasyon yönetici onayında bekler.';
    submit.disabled = !time.value;
  }

  court.addEventListener('change', function () { setDurations(); loadTimes(); });
  date.addEventListener('change', loadTimes);
  duration.addEventListener('change', loadTimes);
  time.addEventListener('change', function () { syncSelectedSlot(); updateSummary(court.options[court.selectedIndex]); });
  form.addEventListener('submit', async function (event) {
    event.preventDefault();
    if (submitting) return;
    if (!court.value || !date.value || !duration.value || !time.value || !Array.isArray(verifiedSlots) || !verifiedSlots.includes(time.value)) {
      summary.textContent = Array.isArray(verifiedSlots)
        ? 'Talep göndermeden önce saha, tarih, geçerli süre ve güncel müsait bir saat seçin.'
        : 'Müsaitlik doğrulanamadı. Lütfen saatlerin yeniden yüklenmesini bekleyin.';
      return;
    }
    const selectedTime = time.value;
    submitting = true;
    submit.disabled = true;
    try {
      await refreshNonces();
      const loaded = await loadTimes();
      if (!loaded || time.value !== selectedTime || !Array.isArray(verifiedSlots) || !verifiedSlots.includes(selectedTime)) {
        summary.textContent = 'Seçtiğiniz saat artık uygun değil. Lütfen güncel müsait saatlerden birini seçin.';
        return;
      }
      HTMLFormElement.prototype.submit.call(form);
    } catch (error) {
      summary.textContent = (error.message || 'Güvenlik anahtarı yenilenemedi.') + ' Seçimleriniz korundu; yeniden deneyin.';
    } finally {
      submitting = false;
      if (document.contains(form)) submit.disabled = !time.value || !Array.isArray(verifiedSlots) || !verifiedSlots.includes(time.value);
    }
  });
  if (court.value) {
    setDurations();
    const initialDuration = duration.dataset.bgcInitialDuration || '';
    if (initialDuration && Array.from(duration.options).some(option => option.value === initialDuration)) duration.value = initialDuration;
  }
  if (BossGymCourts.loggedIn) {
    refreshNonces().then(function () { if (court.value) loadTimes(); }).catch(function (error) {
      summary.textContent = (error.message || 'Güvenlik anahtarı yenilenemedi.') + ' Lütfen sayfayı yenileyip tekrar deneyin.';
    });
  }
  window.setInterval(() => {
    if (document.visibilityState === 'visible' && court.value && date.value && duration.value) loadTimes();
  }, 30000);
}());

