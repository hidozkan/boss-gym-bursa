(function () {
  'use strict';

  function initAccessibilityPanel() {
  var root = document.documentElement;
  var panel = document.getElementById('bossgym-accessibility-panel');
  if (!panel) return;

  var triggers = Array.prototype.slice.call(document.querySelectorAll('.bossgym-accessibility-trigger'));
  var themeButtons = panel.querySelectorAll('[data-accessibility-theme]');
  var contrastButton = panel.querySelector('[data-accessibility-contrast]');
  var scaleOutput = panel.querySelector('[data-accessibility-scale]');
  var speechStatus = panel.querySelector('[data-speech-status]');
  var speechStart = panel.querySelector('[data-speech-start]');
  var speechPause = panel.querySelector('[data-speech-pause]');
  var speechResume = panel.querySelector('[data-speech-resume]');
  var speechStop = panel.querySelector('[data-speech-stop]');
  var closeButton = panel.querySelector('[data-accessibility-close]');
  var storageKey = 'bossgymAccessibilityPreferences';
  var activeTrigger = null;
  var speechQueue = [];
  var speechIndex = 0;
  var selectedVoice = null;
  var speechActive = false;

  function readPreferences() {
    try {
      var value = JSON.parse(window.localStorage.getItem(storageKey) || '{}');
      var scale = Number(value.scale);
      return {
        theme: value.theme === 'light' ? 'light' : 'dark',
        contrast: value.contrast === true,
        scale: Number.isFinite(scale) && scale >= 80 && scale <= 140 && scale % 10 === 0 ? scale : 100
      };
    } catch (error) {
      return { theme: 'dark', contrast: false, scale: 100 };
    }
  }

  var preferences = readPreferences();

  function savePreferences() {
    try { window.localStorage.setItem(storageKey, JSON.stringify(preferences)); } catch (error) { /* Storage may be blocked. */ }
  }

  function applyPreferences() {
    root.classList.toggle('bg-a11y-light', preferences.theme === 'light');
    root.classList.toggle('bg-a11y-high-contrast', preferences.contrast);
    root.style.fontSize = preferences.scale === 100 ? '' : preferences.scale + '%';
    Array.prototype.forEach.call(themeButtons, function (button) {
      button.setAttribute('aria-pressed', button.dataset.accessibilityTheme === preferences.theme ? 'true' : 'false');
    });
    contrastButton.setAttribute('aria-pressed', preferences.contrast ? 'true' : 'false');
    contrastButton.textContent = preferences.contrast ? 'Yüksek kontrastı kapat' : 'Yüksek kontrastı aç';
    scaleOutput.textContent = preferences.scale + '%';
  }

  function placePanel(trigger) {
    var rect = trigger.getBoundingClientRect();
    panel.style.left = 'auto';
    panel.style.right = Math.max(8, window.innerWidth - rect.right) + 'px';
    panel.style.top = 'auto';
    panel.style.bottom = Math.max(8, window.innerHeight - rect.top + 10) + 'px';
    panel.style.maxHeight = Math.max(160, Math.min(640, window.innerHeight * 0.78, rect.top - 24)) + 'px';
  }

  function openPanel(trigger) {
    activeTrigger = trigger;
    panel.hidden = false;
    triggers.forEach(function (item) { item.setAttribute('aria-expanded', item === trigger ? 'true' : 'false'); });
    placePanel(trigger);
    closeButton.focus({ preventScroll: true });
  }

  function closePanel(restoreFocus) {
    if (panel.hidden) return;
    panel.hidden = true;
    triggers.forEach(function (item) { item.setAttribute('aria-expanded', 'false'); });
    if (restoreFocus && activeTrigger && document.contains(activeTrigger) && activeTrigger.getClientRects().length) activeTrigger.focus({ preventScroll: true });
    activeTrigger = null;
  }

  triggers.forEach(function (trigger) {
    trigger.addEventListener('click', function () {
      if (panel.hidden) openPanel(trigger);
      else if (activeTrigger === trigger) closePanel(true);
      else openPanel(trigger);
    });
  });

  closeButton.addEventListener('click', function () { closePanel(true); });
  document.addEventListener('keydown', function (event) {
    if (!panel.hidden && event.key === 'Escape') {
      event.preventDefault();
      closePanel(true);
    }
  });
  document.addEventListener('click', function (event) {
    if (!panel.hidden && !panel.contains(event.target) && !event.target.closest('.bossgym-accessibility-trigger')) closePanel(false);
  });
  window.addEventListener('resize', function () {
    if (panel.hidden || !activeTrigger) return;
    if (!activeTrigger.getClientRects().length) {
      var visibleTrigger = triggers.find(function (item) { return item.getClientRects().length > 0; });
      if (visibleTrigger) { activeTrigger = visibleTrigger; triggers.forEach(function (item) { item.setAttribute('aria-expanded', item === activeTrigger ? 'true' : 'false'); }); }
    }
    placePanel(activeTrigger);
  });

  Array.prototype.forEach.call(themeButtons, function (button) {
    button.addEventListener('click', function () {
      preferences.theme = button.dataset.accessibilityTheme === 'light' ? 'light' : 'dark';
      applyPreferences();
      savePreferences();
    });
  });

  contrastButton.addEventListener('click', function () {
    preferences.contrast = !preferences.contrast;
    applyPreferences();
    savePreferences();
  });

  panel.querySelectorAll('[data-accessibility-size]').forEach(function (button) {
    button.addEventListener('click', function () {
      if (button.dataset.accessibilitySize === 'up') preferences.scale = Math.min(140, preferences.scale + 10);
      else if (button.dataset.accessibilitySize === 'down') preferences.scale = Math.max(80, preferences.scale - 10);
      else preferences.scale = 100;
      applyPreferences();
      savePreferences();
    });
  });

  function updateSpeechControls() {
    var speaking = !!(window.speechSynthesis && window.speechSynthesis.speaking);
    var paused = !!(window.speechSynthesis && window.speechSynthesis.paused);
    speechPause.disabled = !speaking || paused;
    speechResume.disabled = !paused;
    speechStop.disabled = !speaking && !speechActive;
    speechStart.disabled = speechActive && speaking;
  }

  function stopSpeech(message) {
    if (window.speechSynthesis) window.speechSynthesis.cancel();
    speechQueue = [];
    speechIndex = 0;
    speechActive = false;
    if (message) speechStatus.textContent = message;
    updateSpeechControls();
  }

  function getReadableText() {
    var source = document.querySelector('#main') || document.querySelector('main');
    if (!source) return '';
    var copy = source.cloneNode(true);
    copy.querySelectorAll('header,footer,nav,aside,form,input,textarea,select,button,script,style,iframe,svg,[aria-hidden="true"],[contenteditable="true"],[role="dialog"],.bgm-dashboard,.bg-contact-form-section,.bgpt-weekly,.bgpt-slot-grid,.bgc-slot-grid,.bossgym-accessibility-panel').forEach(function (node) { node.remove(); });
    return (copy.innerText || copy.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 30000);
  }

  function makeSpeechChunks(text) {
    var chunks = [];
    var maxLength = 2200;
    while (text.length) {
      if (text.length <= maxLength) { chunks.push(text); break; }
      var splitAt = text.lastIndexOf(' ', maxLength);
      if (splitAt < 1000) splitAt = maxLength;
      chunks.push(text.slice(0, splitAt).trim());
      text = text.slice(splitAt).trim();
    }
    return chunks;
  }

  function speakNext() {
    if (!speechActive || speechIndex >= speechQueue.length) {
      stopSpeech('Sesli okuma tamamlandı.');
      return;
    }
    var utterance = new SpeechSynthesisUtterance(speechQueue[speechIndex]);
    utterance.lang = 'tr-TR';
    utterance.voice = selectedVoice;
    utterance.onend = function () { speechIndex += 1; speakNext(); };
    utterance.onerror = function () { stopSpeech('Sesli okuma durduruldu veya tarayıcı tarafından desteklenmedi.'); };
    window.speechSynthesis.speak(utterance);
    updateSpeechControls();
  }

  speechStart.addEventListener('click', function () {
    if (!('speechSynthesis' in window) || typeof window.SpeechSynthesisUtterance !== 'function') {
      speechStatus.textContent = 'Bu tarayıcı sesli okumayı desteklemiyor.';
      return;
    }
    var voices = window.speechSynthesis.getVoices();
    selectedVoice = voices.find(function (voice) { return /^tr(?:-|_)/i.test(voice.lang || ''); }) || null;
    if (!selectedVoice) {
      speechStatus.textContent = 'Tarayıcıda Türkçe ses bulunamadı. Farklı dilde ses kullanılmadı.';
      return;
    }
    var text = getReadableText();
    if (!text) {
      speechStatus.textContent = 'Okunabilecek ana içerik bulunamadı.';
      return;
    }
    window.speechSynthesis.cancel();
    speechQueue = makeSpeechChunks(text);
    speechIndex = 0;
    speechActive = true;
    speechStatus.textContent = 'Ana içerik Türkçe okunuyor. Menü, formlar ve kişisel panel bilgileri okunmaz.';
    speakNext();
  });

  speechPause.addEventListener('click', function () {
    if (window.speechSynthesis && window.speechSynthesis.speaking) {
      window.speechSynthesis.pause();
      speechStatus.textContent = 'Sesli okuma duraklatıldı.';
      updateSpeechControls();
    }
  });
  speechResume.addEventListener('click', function () {
    if (window.speechSynthesis && window.speechSynthesis.paused) {
      window.speechSynthesis.resume();
      speechStatus.textContent = 'Sesli okuma devam ediyor.';
      updateSpeechControls();
    }
  });
  speechStop.addEventListener('click', function () { stopSpeech('Sesli okuma durduruldu.'); });
  document.addEventListener('click', function (event) {
    if (event.target.closest('a[href]')) stopSpeech('Sayfa bağlantısı açılırken sesli okuma durduruldu.');
  });
  window.addEventListener('pagehide', function () { stopSpeech(''); });

  panel.querySelector('[data-accessibility-reset]').addEventListener('click', function () {
    stopSpeech('Erişilebilirlik ayarları sıfırlandı.');
    preferences = { theme: 'dark', contrast: false, scale: 100 };
    try { window.localStorage.removeItem(storageKey); } catch (error) { /* Storage may be blocked. */ }
    applyPreferences();
  });

  applyPreferences();
  updateSpeechControls();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAccessibilityPanel, { once: true });
  } else {
    initAccessibilityPanel();
  }
}());
