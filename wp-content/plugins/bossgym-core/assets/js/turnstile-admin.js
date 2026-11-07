(function () {
  'use strict';
  if (typeof BossGymTurnstile === 'undefined') return;

  const result = document.getElementById('bgts-result');
  const manual = document.getElementById('bgts-manual');
  const direction = document.getElementById('bgts-direction');
  const video = document.getElementById('bgts-video');
  const startButton = document.getElementById('bgts-start');
  const stopButton = document.getElementById('bgts-stop');
  let stream = null;
  let detector = null;
  let scanTimer = null;
  let submitting = false;
  let lastSubmittedScan = null;
  let scanCanvas = null;
  let scanContext = null;

  const setStatus = (message, state) => {
    if (!result) return;
    result.textContent = message;
    result.dataset.state = state || '';
  };

  const post = async (action, data) => {
    const body = new URLSearchParams(Object.assign({ action, nonce: BossGymTurnstile.nonce }, data));
    const response = await fetch(BossGymTurnstile.ajax, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8' },
      body
    });
    const payload = await response.json();
    if (!response.ok && !payload.data?.message) throw new Error('Sunucu isteği tamamlayamadı.');
    return payload;
  };

  const refreshOccupancy = async () => {
    const panel = document.getElementById('bgts-occupancy');
    if (!panel) return;
    try {
      const response = await post('bossgym_turnstile_stats', {});
      if (response.success && typeof response.data?.html === 'string') panel.innerHTML = response.data.html;
      else if (!response.success) panel.textContent = response.data?.message || 'Doluluk bilgisi alınamadı.';
    } catch (_) {
      panel.textContent = 'Doluluk bilgisi sunucudan alınamadı.';
    }
  };

  const submit = async code => {
    if (submitting) return false;
    const cleanCode = String(code || '').trim();
    if (!cleanCode || !direction) {
      setStatus('Üye kodu ve Giriş/Çıkış seçimi gereklidir.', 'error');
      return false;
    }
    const now = Date.now();
    if (lastSubmittedScan && lastSubmittedScan.code === cleanCode && lastSubmittedScan.direction === direction.value && now - lastSubmittedScan.time < 3000) {
      setStatus('Aynı kod kısa aralıkta tekrar algılandı; yinelenen işlem gönderilmedi.', 'error');
      return false;
    }
    submitting = true;
    lastSubmittedScan = { code: cleanCode, direction: direction.value, time: now };
    setStatus('QR kod doğrulanıyor…', 'pending');
    try {
      const response = await post('bossgym_turnstile_scan', { code: cleanCode, direction: direction.value });
      setStatus(response.data?.message || (response.success ? 'İşlem tamamlandı.' : 'İşlem gerçekleştirilemedi.'), response.success ? 'success' : 'error');
      if (response.success) await refreshOccupancy();
      return Boolean(response.success);
    } catch (error) {
      setStatus(error.message || 'Turnike doğrulama servisine ulaşılamadı. Bağlantıyı kontrol edip tekrar deneyin.', 'error');
      return false;
    } finally {
      submitting = false;
    }
  };

  if (manual) {
    manual.addEventListener('submit', event => {
      event.preventDefault();
      submit(new FormData(manual).get('code'));
    });
  }

  const stop = () => {
    clearTimeout(scanTimer);
    stream?.getTracks().forEach(track => track.stop());
    stream = null;
    detector = null;
    scanCanvas = null;
    scanContext = null;
    if (video) {
      video.pause();
      video.srcObject = null;
    }
    if (startButton) startButton.disabled = false;
    if (stopButton) stopButton.disabled = true;
  };

  const cameraErrorMessage = error => {
    if (!window.isSecureContext) return 'Tarayıcı kamerayı yalnızca HTTPS veya güvenli localhost bağlantısında açar. Siteyi http://localhost üzerinden ya da HTTPS ile açın; manuel kod girişi kullanılabilir.';
    const name = error?.name || '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError' || name === 'SecurityError') return 'Kamera izni verilmedi. Adres çubuğundaki site izinlerinden kameraya izin verip yeniden deneyin; manuel kod girişi kullanılabilir.';
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') return 'Bu cihazda kullanılabilir kamera bulunamadı. Manuel kod girişi kullanılabilir.';
    if (name === 'NotReadableError' || name === 'TrackStartError') return 'Kamera başka bir uygulama tarafından kullanılıyor veya açılamıyor. Diğer uygulamaları kapatıp yeniden deneyin.';
    if (name === 'OverconstrainedError' || name === 'ConstraintNot satisfied') return 'İstenen arka kamera kullanılamıyor. Kamera seçimini değiştirip yeniden deneyin.';
    if (name === 'AbortError') return 'Kamera başlatma işlemi kesildi. Yeniden deneyin veya manuel kod girişi kullanın.';
    return 'Kamera açılamadı' + (error?.message ? ': ' + error.message : '. Manuel kod girişi kullanılabilir.');
  };

  const runScanner = async () => {
    if (stream) return;
    if (!window.isSecureContext) {
      setStatus(cameraErrorMessage({ name: 'SecurityError' }), 'error');
      return;
    }
    if (!navigator.mediaDevices?.getUserMedia) {
      setStatus('Bu tarayıcı kameraya erişimi desteklemiyor. HTTPS/localhost kullanın veya manuel kod girişi yapın.', 'error');
      return;
    }
    if (!('BarcodeDetector' in window) && typeof window.jsQR !== 'function') {
      setStatus('Yerel QR okuyucu yüklenemedi. Sayfayı yenileyin veya manuel kod girişi kullanın.', 'error');
      return;
    }

    if (startButton) startButton.disabled = true;
    try {
      detector = null;
      if ('BarcodeDetector' in window) {
        try {
          const formats = typeof BarcodeDetector.getSupportedFormats === 'function' ? await BarcodeDetector.getSupportedFormats() : ['qr_code'];
          if (formats.includes('qr_code')) detector = new BarcodeDetector({ formats: ['qr_code'] });
        } catch (_) {
          detector = null;
        }
      }
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
      } catch (error) {
        if (error?.name !== 'OverconstrainedError') throw error;
        stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
      }
      video.srcObject = stream;
      await video.play();
      if (!detector) {
        scanCanvas = document.createElement('canvas');
        scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
        if (!scanContext || typeof window.jsQR !== 'function') {
          stop();
          setStatus('Yerel QR okuyucu başlatılamadı. Manuel kod girişi kullanılabilir.', 'error');
          return;
        }
      }
      if (stopButton) stopButton.disabled = false;
      setStatus(detector ? 'Kamera açık. Seçili işlem uygulanır; QR kodu kamera önünde tutun.' : 'Kamera açık. Yerel QR okuyucu hazır; seçili işlem uygulanır.', 'pending');

      const tick = async () => {
        if (!stream || submitting) return;
        try {
          let code = '';
          if (detector) {
            try {
              const codes = await detector.detect(video);
              if (codes.length) code = codes[0].rawValue || '';
            } catch (_) {
              detector = null;
              scanCanvas = document.createElement('canvas');
              scanContext = scanCanvas.getContext('2d', { willReadFrequently: true });
            }
          }
          if (!code && !detector && scanContext && video.videoWidth && video.videoHeight) {
            scanCanvas.width = video.videoWidth;
            scanCanvas.height = video.videoHeight;
            scanContext.drawImage(video, 0, 0, scanCanvas.width, scanCanvas.height);
            const frame = scanContext.getImageData(0, 0, scanCanvas.width, scanCanvas.height);
            code = window.jsQR(frame.data, frame.width, frame.height, { inversionAttempts: 'attemptBoth' })?.data || '';
          }
          if (code) {
            stop();
            await submit(code);
            return;
          }
        } catch (_) {
          stop();
          setStatus('Kameradan görüntü okunamadı. Kamera iznini ve görüntüyü kontrol edin veya manuel kod girişi kullanın.', 'error');
          return;
        }
        scanTimer = setTimeout(tick, 350);
      };
      tick();
    } catch (error) {
      stop();
      setStatus(cameraErrorMessage(error), 'error');
    }
  };

  startButton?.addEventListener('click', runScanner);
  stopButton?.addEventListener('click', () => {
    stop();
    setStatus('Kamera durduruldu. Manuel kod girişi kullanılabilir.', '');
  });
  window.addEventListener('pagehide', stop);

  const occupancyPanel = document.getElementById('bgts-occupancy');
  if (occupancyPanel) {
    refreshOccupancy();
    window.setInterval(refreshOccupancy, 15000);
  }
}());
