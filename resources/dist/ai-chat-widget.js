/*!
 * AI Chat State Machine - browser widget v1.0.0
 * Zero dependencies, no build step. MIT licensed.
 * https://github.com/faridmasoum/ai-chat-state-machine
 */
(function (global) {
  'use strict';

  var VERSION = '1.0.0';

  // ------------------------------------------------------------------ i18n
  var EN = {
    title: 'Chat', open: 'Open chat', close: 'Close chat', send: 'Send', skip: 'Skip',
    edit: 'Edit', submit: 'Submit', restart: 'Start over',
    placeholder: 'Type your answer…', review: 'Please review your answers',
    done: 'Thank you! Your answers have been submitted.',
    error: 'Something went wrong. Please try again.',
    offline: 'Connection problem. Please try again.',
    typing: 'Typing…', multi: 'Select all that apply', continue: 'Continue',
    language: 'Language', step: 'Step {current} of {total}'
  };

  var LOCALES = {
    en: { name: 'English', dir: 'ltr', m: EN },
    fa: { name: 'فارسی', m: {
      title: 'گفتگو', open: 'باز کردن گفتگو', close: 'بستن',
      send: 'ارسال', skip: 'رد کردن', edit: 'ویرایش',
      submit: 'ثبت نهایی', restart: 'شروع دوباره',
      placeholder: 'پاسخ خود را بنویسید…',
      review: 'لطفاً پاسخ‌های خود را بررسی کنید',
      done: 'ممنون! پاسخ‌های شما ثبت شد.',
      error: 'مشکلی پیش آمد. لطفاً دوباره تلاش کنید.',
      offline: 'مشکل در اتصال. لطفاً دوباره تلاش کنید.',
      typing: 'در حال تایپ…',
      multi: 'همه موارد مناسب را انتخاب کنید',
      continue: 'ادامه', language: 'زبان',
      step: 'مرحله {current} از {total}' } },
    ar: { name: 'العربية', m: {
      title: 'المحادثة', open: 'فتح المحادثة', close: 'إغلاق',
      send: 'إرسال', skip: 'تخطي', edit: 'تعديل',
      submit: 'إرسال نهائي', restart: 'ابدأ من جديد',
      placeholder: 'اكتب إجابتك…',
      review: 'يرجى مراجعة إجاباتك',
      done: 'شكراً لك! تم إرسال إجاباتك.',
      error: 'حدث خطأ ما. حاول مرة أخرى.',
      offline: 'مشكلة في الاتصال. حاول مرة أخرى.',
      typing: 'جارٍ الكتابة…',
      multi: 'اختر كل ما ينطبق',
      continue: 'متابعة', language: 'اللغة',
      step: 'الخطوة {current} من {total}' } },
    he: { name: 'עברית', m: {
      title: 'צ׳אט', open: 'פתח צ׳אט', close: 'סגור',
      send: 'שלח', skip: 'דלג', edit: 'ערוך',
      submit: 'שלח סופית', restart: 'התחל מחדש',
      placeholder: 'הקלד את תשובתך…',
      review: 'אנא בדוק את התשובות שלך',
      done: 'תודה! התשובות שלך נשלחו.',
      error: 'משהו השתבש. נסה שוב.',
      offline: 'בעיית חיבור. נסה שוב.',
      typing: 'מקליד…',
      multi: 'בחר את כל האפשרויות הרלוונטיות',
      continue: 'המשך', language: 'שפה',
      step: 'שלב {current} מתוך {total}' } },
    ur: { name: 'اردو', m: {
      title: 'چیٹ', open: 'چیٹ کھولیں', close: 'بند کریں',
      send: 'بھیجیں', skip: 'چھوڑیں', edit: 'ترمیم',
      submit: 'حتمی جمع', restart: 'دوبارہ شروع کریں',
      placeholder: 'اپنا جواب لکھیں…',
      review: 'براہ کرم اپنے جوابات دیکھ لیں',
      done: 'شکریہ! آپ کے جوابات جمع ہو گئے۔',
      error: 'کچھ غلط ہو گیا۔ دوبارہ کوشش کریں۔',
      offline: 'کنکشن کا مسئلہ۔ دوبارہ کوشش کریں۔',
      typing: 'لکھ رہا ہے…',
      multi: 'تمام متعلقہ منتخب کریں',
      continue: 'جاری رکھیں', language: 'زبان',
      step: 'مرحلہ {current} از {total}' } },
    de: { name: 'Deutsch', m: {
      title: 'Chat', open: 'Chat öffnen', close: 'Chat schließen', send: 'Senden', skip: 'Überspringen',
      edit: 'Bearbeiten', submit: 'Absenden', restart: 'Neu starten', placeholder: 'Antwort eingeben…',
      review: 'Bitte überprüfen Sie Ihre Antworten', done: 'Danke! Ihre Antworten wurden gesendet.',
      error: 'Etwas ist schiefgelaufen. Bitte erneut versuchen.', offline: 'Verbindungsproblem. Bitte erneut versuchen.',
      typing: 'Schreibt…', multi: 'Alles Zutreffende auswählen', continue: 'Weiter', language: 'Sprache',
      step: 'Schritt {current} von {total}' } },
    fr: { name: 'Français', m: {
      title: 'Discussion', open: 'Ouvrir le chat', close: 'Fermer le chat', send: 'Envoyer', skip: 'Passer',
      edit: 'Modifier', submit: 'Valider', restart: 'Recommencer', placeholder: 'Saisissez votre réponse…',
      review: 'Veuillez vérifier vos réponses', done: 'Merci ! Vos réponses ont été envoyées.',
      error: 'Une erreur est survenue. Veuillez réessayer.', offline: 'Problème de connexion. Veuillez réessayer.',
      typing: 'Écriture…', multi: 'Sélectionnez tout ce qui s’applique', continue: 'Continuer', language: 'Langue',
      step: 'Étape {current} sur {total}' } },
    es: { name: 'Español', m: {
      title: 'Chat', open: 'Abrir chat', close: 'Cerrar chat', send: 'Enviar', skip: 'Omitir',
      edit: 'Editar', submit: 'Enviar', restart: 'Empezar de nuevo', placeholder: 'Escribe tu respuesta…',
      review: 'Revisa tus respuestas', done: '¡Gracias! Tus respuestas se han enviado.',
      error: 'Algo salió mal. Inténtalo de nuevo.', offline: 'Problema de conexión. Inténtalo de nuevo.',
      typing: 'Escribiendo…', multi: 'Selecciona todo lo que corresponda', continue: 'Continuar', language: 'Idioma',
      step: 'Paso {current} de {total}' } },
    tr: { name: 'Türkçe', m: {
      title: 'Sohbet', open: 'Sohbeti aç', close: 'Sohbeti kapat', send: 'Gönder', skip: 'Atla',
      edit: 'Düzenle', submit: 'Gönder', restart: 'Yeniden başlat', placeholder: 'Yanıtınızı yazın…',
      review: 'Lütfen yanıtlarınızı kontrol edin', done: 'Teşekkürler! Yanıtlarınız gönderildi.',
      error: 'Bir şeyler ters gitti. Lütfen tekrar deneyin.', offline: 'Bağlantı sorunu. Lütfen tekrar deneyin.',
      typing: 'Yazıyor…', multi: 'Uygun olanların hepsini seçin', continue: 'Devam', language: 'Dil',
      step: 'Adım {current} / {total}' } }
  };

  // Base languages written right-to-left, plus RTL scripts for tags like "pa-Arab" or "az-Arab".
  var RTL_LANGS = ['ar', 'arc', 'ckb', 'dv', 'fa', 'he', 'iw', 'ks', 'ku', 'ps', 'sd', 'ug', 'ur', 'yi', 'azb', 'syr', 'nqo', 'rhg', 'ha-arab'];
  var RTL_SCRIPTS = ['arab', 'hebr', 'thaa', 'syrc', 'nkoo', 'adlm', 'rohg', 'mand', 'samr'];

  function baseLang(tag) { return String(tag || '').toLowerCase().replace(/_/g, '-').split('-')[0]; }

  function isRtl(tag) {
    var t = String(tag || '').toLowerCase().replace(/_/g, '-');
    try {
      if (global.Intl && Intl.Locale) {
        var loc = new Intl.Locale(t);
        var info = loc.getTextInfo ? loc.getTextInfo() : loc.textInfo;
        if (info && info.direction) { return info.direction === 'rtl'; }
      }
    } catch (e) { /* fall through to the static list */ }
    var parts = t.split('-');
    for (var i = 1; i < parts.length; i++) { if (RTL_SCRIPTS.indexOf(parts[i]) !== -1) { return true; } }
    return RTL_LANGS.indexOf(parts[0]) !== -1;
  }

  function findLocale(tag) {
    var t = String(tag || '').toLowerCase().replace(/_/g, '-');
    if (LOCALES[t]) { return t; }
    var b = baseLang(t);
    return LOCALES[b] ? b : null;
  }

  function resolveLang(pref) {
    if (pref && pref !== 'auto') { return findLocale(pref) || String(pref).toLowerCase(); }
    var cands = [document.documentElement.lang].concat(global.navigator && (navigator.languages || [navigator.language]) || []);
    for (var i = 0; i < cands.length; i++) {
      var f = cands[i] && findLocale(cands[i]);
      if (f) { return f; }
    }
    return 'en';
  }

  /**
   * Register or extend a language. Missing keys fall back to English.
   * AiChatWidget.addLocale('sv', {title: 'Chatt', ...}, {dir: 'ltr', name: 'Svenska'})
   */
  function addLocale(code, messages, meta) {
    var key = String(code).toLowerCase().replace(/_/g, '-');
    var existing = LOCALES[key] || { m: {} };
    var merged = {};
    var k;
    for (k in existing.m) { merged[k] = existing.m[k]; }
    for (k in (messages || {})) { merged[k] = messages[k]; }
    LOCALES[key] = { name: (meta && meta.name) || existing.name || key, dir: (meta && meta.dir) || existing.dir, m: merged };
  }

  // ------------------------------------------------------------------- CSS
  var CSS = [
    ':host{all:initial;--acw-primary:#4f46e5;--acw-on-primary:#fff;--acw-bg:#fff;--acw-fg:#18181b;--acw-muted:#71717a;--acw-border:#e4e4e7;',
    '--acw-bot:#f4f4f5;--acw-user:var(--acw-primary);--acw-user-fg:var(--acw-on-primary);--acw-danger:#dc2626;--acw-radius:16px;',
    '--acw-font:system-ui,-apple-system,"Segoe UI",Roboto,"Noto Sans","Noto Sans Arabic","Noto Sans Hebrew",sans-serif;--acw-z:2147483000}',
    '.root{font-family:var(--acw-font);font-size:15px;line-height:1.5;color:var(--acw-fg);box-sizing:border-box}',
    '.root *{box-sizing:border-box;font-family:inherit}',
    '.root.dark{--acw-bg:#18181b;--acw-fg:#f4f4f5;--acw-muted:#a1a1aa;--acw-border:#3f3f46;--acw-bot:#27272a;--acw-danger:#f87171}',
    '.float{position:fixed;inset-block-end:20px;z-index:var(--acw-z)}',
    '.float.end{inset-inline-end:20px}.float.start{inset-inline-start:20px}',
    '.launcher{width:56px;height:56px;border-radius:50%;border:0;background:var(--acw-primary);color:var(--acw-on-primary);cursor:pointer;',
    'display:flex;align-items:center;justify-content:center;box-shadow:0 6px 20px rgba(0,0,0,.25);transition:transform .15s}',
    '.launcher:hover{transform:scale(1.06)}.launcher svg{width:26px;height:26px}',
    '.panel{display:none;flex-direction:column;width:min(380px,calc(100vw - 24px));height:min(640px,calc(100vh - 110px));height:min(640px,calc(100dvh - 110px));',
    'background:var(--acw-bg);border:1px solid var(--acw-border);border-radius:var(--acw-radius);overflow:hidden;box-shadow:0 12px 40px rgba(0,0,0,.25)}',
    '.float .panel{position:absolute;inset-block-end:70px}',
    '.float.end .panel{inset-inline-end:0}.float.start .panel{inset-inline-start:0}',
    '.open .panel{display:flex}',
    '.inline .panel{display:flex;width:100%;height:560px;box-shadow:none}',
    '.head{display:flex;align-items:center;gap:8px;padding:12px 14px;background:var(--acw-primary);color:var(--acw-on-primary)}',
    '.head .title{flex:1;font-weight:600;font-size:16px;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
    '.head button,.head select{background:rgba(255,255,255,.18);color:inherit;border:0;border-radius:8px;padding:4px 8px;cursor:pointer;font-size:13px}',
    '.head select option{color:#18181b}',
    '.bar{height:3px;background:var(--acw-border)}.bar i{display:block;height:100%;width:0;background:var(--acw-primary);transition:width .3s}',
    '.log{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:8px;scroll-behavior:smooth}',
    '.msg{max-width:85%;padding:8px 12px;border-radius:14px;word-wrap:break-word;overflow-wrap:anywhere;white-space:pre-wrap}',
    '.msg.bot{align-self:flex-start;background:var(--acw-bot);border-end-start-radius:4px}',
    '.msg.user{align-self:flex-end;background:var(--acw-user);color:var(--acw-user-fg);border-end-end-radius:4px}',
    '.msg.err{align-self:flex-start;background:transparent;color:var(--acw-danger);border:1px solid var(--acw-danger);font-size:14px}',
    '.typing{align-self:flex-start;display:flex;gap:4px;padding:12px;background:var(--acw-bot);border-radius:14px}',
    '.typing b{width:6px;height:6px;border-radius:50%;background:var(--acw-muted);animation:acw 1s infinite}',
    '.typing b:nth-child(2){animation-delay:.15s}.typing b:nth-child(3){animation-delay:.3s}',
    '@keyframes acw{0%,60%,100%{opacity:.3;transform:none}30%{opacity:1;transform:translateY(-3px)}}',
    '.card{align-self:stretch;border:1px solid var(--acw-border);border-radius:12px;overflow:hidden}',
    '.card .row{display:flex;gap:8px;align-items:center;justify-content:space-between;padding:8px 12px;border-block-end:1px solid var(--acw-border)}',
    '.card .row:last-child{border:0}.card .k{color:var(--acw-muted);font-size:13px}.card .v{font-weight:500;overflow-wrap:anywhere}',
    '.card .row>div{min-width:0}',
    '.link{background:none;border:0;color:var(--acw-primary);cursor:pointer;font-size:13px;padding:2px 4px;text-decoration:underline}',
    '.composer{padding:10px 12px;border-block-start:1px solid var(--acw-border);display:flex;flex-direction:column;gap:8px}',
    '.composer:empty{display:none}',
    '.line{display:flex;gap:8px;align-items:flex-end}',
    '.composer input[type=text],.composer input[type=email],.composer input[type=tel],.composer input[type=number],.composer textarea{',
    'flex:1;min-width:0;padding:9px 12px;border:1px solid var(--acw-border);border-radius:10px;background:var(--acw-bg);color:var(--acw-fg);font-size:15px;resize:none}',
    '.composer input:focus,.composer textarea:focus,.chip:focus-visible,.btn:focus-visible,.launcher:focus-visible,.link:focus-visible,.head button:focus-visible,.head select:focus-visible{outline:2px solid var(--acw-primary);outline-offset:2px}',
    '.btn{background:var(--acw-primary);color:var(--acw-on-primary);border:0;border-radius:10px;padding:9px 16px;cursor:pointer;font-size:15px;font-weight:600}',
    '.btn:disabled,.chip:disabled{opacity:.5;cursor:not-allowed}',
    '.btn.ghost{background:transparent;color:var(--acw-muted);font-weight:500}',
    '.chips{display:flex;flex-wrap:wrap;gap:8px}',
    '.chip{background:var(--acw-bg);color:var(--acw-fg);border:1px solid var(--acw-primary);border-radius:999px;padding:7px 14px;cursor:pointer;font-size:14px}',
    '.chip:hover:not(:disabled),.chip.on{background:var(--acw-primary);color:var(--acw-on-primary)}',
    '.hint{font-size:12px;color:var(--acw-muted)}',
    '.sr{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0 0 0 0);white-space:nowrap}',
    '@media (max-width:480px){.float .panel{position:fixed;inset:0;width:100vw;height:100vh;height:100dvh;border-radius:0;border:0}',
    '.float.open .launcher{display:none}}',
    '@media (prefers-reduced-motion:reduce){.root *{transition:none!important;animation:none!important;scroll-behavior:auto!important}}'
  ].join('');

  var ICON_CHAT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H8l-5 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>';

  // ---------------------------------------------------------------- helpers
  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }   // always textContent: server data is never parsed as HTML
    return n;
  }

  function fmt(str, vars) {
    return String(str).replace(/\{(\w+)\}/g, function (_, k) { return vars && vars[k] != null ? vars[k] : ''; });
  }

  function emit(name, detail, host) {
    try {
      var ev = new CustomEvent(name, { detail: detail, bubbles: true, composed: true });
      host.dispatchEvent(ev);
    } catch (e) { /* old browsers: events are optional */ }
  }

  function store(key, val) {
    try {
      if (val === undefined) { return global.localStorage.getItem(key); }
      if (val === null) { global.localStorage.removeItem(key); } else { global.localStorage.setItem(key, val); }
    } catch (e) { /* private mode / blocked storage: conversation just won't survive a reload */ }
    return null;
  }

  // ----------------------------------------------------------------- Widget
  function Widget(opts) {
    this.o = opts;
    this.endpoint = opts.endpoint;
    this.flow = opts.flow || '';
    this.lang = resolveLang(opts.lang);
    this.dirOpt = opts.dir && opts.dir !== 'auto' ? opts.dir : null;
    this.inline = opts.mode === 'inline';
    this.session = store('acw:' + this.endpoint + ':' + this.flow);
    this.ui = null;
    this.busy = false;
    this.completed = false;
    this.build();
    this.applyLocale();
    if (this.inline || opts.open) { this.setOpen(true); }
    this.boot('start');
  }

  Widget.prototype.t = function (key, vars) {
    var loc = LOCALES[this.lang] || LOCALES[baseLang(this.lang)] || LOCALES.en;
    var o = this.o.messages || {};
    var s = (o[key] != null ? o[key] : null);
    if (s == null) { s = loc.m[key]; }
    if (s == null) { s = EN[key]; }
    return fmt(s, vars);
  };

  Widget.prototype.direction = function () {
    if (this.dirOpt) { return this.dirOpt; }
    var loc = LOCALES[this.lang] || LOCALES[baseLang(this.lang)];
    if (loc && loc.dir) { return loc.dir; }
    return isRtl(this.lang) ? 'rtl' : 'ltr';
  };

  Widget.prototype.build = function () {
    var self = this;
    var host = this.host = el('div');
    host.setAttribute('data-acw', '');
    var target = this.o.target ? document.querySelector(this.o.target) : null;
    (target || document.body).appendChild(host);
    var shadow = host.attachShadow ? host.attachShadow({ mode: 'open' }) : host;
    var style = el('style'); style.textContent = CSS; shadow.appendChild(style);

    var root = this.root = el('div', 'root ' + (this.inline ? 'inline' : 'float ' + (this.o.position === 'start' ? 'start' : 'end')));
    this.applyTheme();
    shadow.appendChild(root);

    if (!this.inline) {
      this.launcher = el('button', 'launcher'); this.launcher.type = 'button'; this.launcher.innerHTML = ICON_CHAT;
      this.launcher.addEventListener('click', function () { self.setOpen(!self.isOpen); });
      root.appendChild(this.launcher);
    }

    var panel = this.panel = el('section', 'panel');
    panel.setAttribute('role', 'dialog');
    var head = el('div', 'head');
    this.titleEl = el('span', 'title');
    head.appendChild(this.titleEl);
    if (this.o.languages) {
      this.select = el('select');
      this.select.addEventListener('change', function () { self.setLanguage(self.select.value); });
      head.appendChild(this.select);
    }
    this.restartBtn = el('button'); this.restartBtn.type = 'button';
    this.restartBtn.textContent = '↻';
    this.restartBtn.addEventListener('click', function () { self.restart(); });
    head.appendChild(this.restartBtn);
    if (!this.inline) {
      this.closeBtn = el('button'); this.closeBtn.type = 'button'; this.closeBtn.textContent = '✕';
      this.closeBtn.addEventListener('click', function () { self.setOpen(false); });
      head.appendChild(this.closeBtn);
    }
    var bar = el('div', 'bar'); this.barFill = el('i'); bar.appendChild(this.barFill);
    bar.setAttribute('role', 'progressbar');
    this.bar = bar;
    this.log = el('div', 'log'); this.log.setAttribute('role', 'log'); this.log.setAttribute('aria-live', 'polite');
    this.composer = el('form', 'composer');
    this.composer.addEventListener('submit', function (e) { e.preventDefault(); });
    panel.appendChild(head); panel.appendChild(bar); panel.appendChild(this.log); panel.appendChild(this.composer);
    root.appendChild(panel);

    panel.addEventListener('keydown', function (e) {
      if (e.key === 'Escape' && !self.inline) { self.setOpen(false); self.launcher.focus(); }
    });

    if (global.matchMedia && (this.o.theme || 'auto') === 'auto') {
      var mq = global.matchMedia('(prefers-color-scheme: dark)');
      var on = function () { self.applyTheme(); };
      if (mq.addEventListener) { mq.addEventListener('change', on); } else if (mq.addListener) { mq.addListener(on); }
    }
  };

  Widget.prototype.applyTheme = function () {
    var th = this.o.theme || 'auto';
    var dark = th === 'dark' || (th === 'auto' && global.matchMedia && global.matchMedia('(prefers-color-scheme: dark)').matches);
    this.root.classList.toggle('dark', !!dark);
  };

  Widget.prototype.applyLocale = function () {
    var dir = this.direction();
    this.root.setAttribute('dir', dir);
    this.root.setAttribute('lang', this.lang);
    this.titleEl.textContent = this.o.title || this.t('title');
    this.panel.setAttribute('aria-label', this.o.title || this.t('title'));
    this.restartBtn.title = this.restartBtn.ariaLabel = this.t('restart');
    if (this.closeBtn) { this.closeBtn.title = this.closeBtn.ariaLabel = this.t('close'); }
    if (this.launcher) { this.launcher.title = this.t('open'); this.launcher.setAttribute('aria-label', this.t('open')); }
    if (this.select) {
      var codes = this.o.languages, cur = this.lang;
      this.select.innerHTML = '';
      this.select.setAttribute('aria-label', this.t('language'));
      for (var i = 0; i < codes.length; i++) {
        var key = findLocale(codes[i]) || codes[i];
        var op = el('option', null, (LOCALES[key] && LOCALES[key].name) || key);
        op.value = codes[i];
        if (codes[i] === cur || key === cur) { op.selected = true; }
        this.select.appendChild(op);
      }
    }
  };

  Widget.prototype.setOpen = function (open) {
    this.isOpen = !!open;
    this.root.classList.toggle('open', this.isOpen);
    if (this.launcher) { this.launcher.setAttribute('aria-expanded', String(this.isOpen)); }
    if (this.isOpen) { this.focusComposer(); }
  };
  Widget.prototype.open = function () { this.setOpen(true); };
  Widget.prototype.close = function () { this.setOpen(false); };

  Widget.prototype.setLanguage = function (lang) {
    this.lang = resolveLang(lang);
    this.applyLocale();
    this.boot('state');   // re-fetch so the server can re-localise the questions
  };

  Widget.prototype.restart = function () {
    store('acw:' + this.endpoint + ':' + this.flow, null);
    this.session = null;
    this.boot('restart');
  };

  Widget.prototype.destroy = function () {
    if (this.host.parentNode) { this.host.parentNode.removeChild(this.host); }
  };

  // -------------------------------------------------------------- transport
  Widget.prototype.post = function (body) {
    var headers = { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' };
    var meta = document.querySelector('meta[name="csrf-token"]');
    var token = (meta && meta.content) || this.o.csrf;
    if (token) { headers['X-CSRF-TOKEN'] = token; }
    body.flow = this.flow; body.locale = this.lang; body.session = this.session;
    return fetch(this.endpoint, { method: 'POST', credentials: 'same-origin', headers: headers, body: JSON.stringify(body) })
      .then(function (r) {
        return r.json().then(function (j) { return { http: r.status, body: j }; }, function () { return { http: r.status, body: {} }; });
      });
  };

  Widget.prototype.setBusy = function (busy) {
    this.busy = busy;
    var ctrls = this.composer.querySelectorAll('input,textarea,button');
    for (var i = 0; i < ctrls.length; i++) { ctrls[i].disabled = busy; }
    if (busy) {
      this.typingEl = el('div', 'typing');
      this.typingEl.setAttribute('role', 'status');
      this.typingEl.setAttribute('aria-label', this.t('typing'));
      this.typingEl.innerHTML = '<b></b><b></b><b></b>';
      this.log.appendChild(this.typingEl);
      this.scroll();
    } else if (this.typingEl) {
      if (this.typingEl.parentNode) { this.typingEl.parentNode.removeChild(this.typingEl); }
      this.typingEl = null;
    }
  };

  Widget.prototype.boot = function (action) {
    var self = this;
    this.setBusy(true);
    this.post({ action: action }).then(function (res) {
      self.setBusy(false);
      if (res.http === 404 && self.session) { self.session = null; return self.boot('start'); }
      self.handle(res, true);
    }, function () { self.setBusy(false); self.say('err', self.t('offline')); });
  };

  Widget.prototype.sendTurn = function (type, value, echo) {
    var self = this;
    if (this.busy || !this.ui) { return; }
    var step = this.ui.step;
    if (echo != null) { this.say('user', echo); }
    this.setBusy(true);
    this.post({ action: 'turn', turn: { step: step, type: type, value: value } }).then(function (res) {
      self.setBusy(false);
      self.handle(res, false);
    }, function () {
      self.setBusy(false);
      self.say('err', self.t('offline'));
      self.renderComposer();
    });
  };

  // --------------------------------------------------------------- response
  Widget.prototype.handle = function (res, rebuild) {
    var b = res.body || {};
    if (!b.session || !b.ui) {
      this.say('err', b.error || this.t('error'));
      this.renderComposer();
      return;
    }
    this.session = b.session;
    store('acw:' + this.endpoint + ':' + this.flow, b.session);
    var wasUi = this.ui;
    this.ui = b.ui;
    this.completed = b.status === 'completed' || b.ui.type === 'done';
    this.summary = b.summary || [];
    this.progress(b.progress);

    if (res.http === 409) { rebuild = true; }
    if (rebuild) {
      this.log.innerHTML = '';
      for (var i = 0; i < this.summary.length && b.ui.type !== 'done'; i++) {
        if (b.ui.type !== 'review' && this.summary[i].step === b.ui.step) { continue; }
        this.say('bot', this.summary[i].label);
        this.say('user', this.summary[i].value === '' ? '\u2014' : this.summary[i].value);
      }
      if (this.completed) { this.log.innerHTML = ''; }
      if (res.http === 409 && b.error) { this.say('err', b.error); }
    }

    if (b.status === 'invalid') {
      this.say('err', b.error || this.t('error'));
    } else if (rebuild || !wasUi || wasUi.step !== b.ui.step || wasUi.type !== b.ui.type || b.status === 'completed') {
      this.present(b.ui);
    }

    this.renderComposer();
    emit('acw:step', { flow: this.flow, step: b.ui.step, ui: b.ui, progress: b.progress, status: b.status }, this.host);
    if (b.status === 'completed' && !this.notifiedDone) {
      this.notifiedDone = true;
      emit('acw:complete', { flow: this.flow, summary: this.summary }, this.host);
      if (typeof this.o.onComplete === 'function') { this.o.onComplete(this.summary); }
    }
    if (b.status !== 'completed') { this.notifiedDone = false; }
  };

  Widget.prototype.progress = function (p) {
    if (!p || !p.total) { this.barFill.style.width = '0'; return; }
    var pct = this.completed ? 100 : Math.max(0, Math.min(100, Math.round(((p.current - 1) / p.total) * 100)));
    this.barFill.style.width = pct + '%';
    this.bar.setAttribute('aria-valuenow', String(pct));
    this.bar.setAttribute('aria-label', this.t('step', { current: p.current, total: p.total }));
  };

  Widget.prototype.present = function (ui) {
    var self = this;
    if (ui.type === 'review') {
      this.say('bot', ui.message || this.t('review'));
      var card = el('div', 'card');
      (ui.items || []).forEach(function (it) {
        var row = el('div', 'row');
        var txt = el('div');
        txt.appendChild(el('div', 'k', it.label));
        txt.appendChild(el('div', 'v', it.value === '' || it.value == null ? '\u2014' : it.value));
        var edit = el('button', 'link', self.t('edit')); edit.type = 'button';
        edit.addEventListener('click', function () { self.sendTurn('edit', it.step, self.t('edit') + ': ' + it.label); });
        row.appendChild(txt); row.appendChild(edit); card.appendChild(row);
      });
      this.log.appendChild(card);
      this.scroll();
    } else if (ui.type === 'done') {
      this.say('bot', ui.message || this.t('done'));
    } else if (ui.message) {
      this.say('bot', ui.message);
    }
  };

  Widget.prototype.say = function (role, text) {
    var m = el('div', 'msg ' + role, text);
    if (this.typingEl) { this.log.insertBefore(m, this.typingEl); } else { this.log.appendChild(m); }
    this.scroll();
  };

  Widget.prototype.scroll = function () { this.log.scrollTop = this.log.scrollHeight; };

  // --------------------------------------------------------------- composer
  Widget.prototype.focusComposer = function () {
    var self = this;
    setTimeout(function () {
      var f = self.composer.querySelector('input,textarea,button');
      if (f && self.isOpen) { f.focus({ preventScroll: true }); }
    }, 50);
  };

  Widget.prototype.optionLabel = function (value) {
    var opts = (this.ui && this.ui.options) || [];
    for (var i = 0; i < opts.length; i++) {
      var v = typeof opts[i] === 'object' ? opts[i].value : opts[i];
      if (String(v) === String(value)) { return typeof opts[i] === 'object' ? (opts[i].label || v) : v; }
    }
    return value;
  };

  Widget.prototype.renderComposer = function () {
    var self = this, ui = this.ui, c = this.composer;
    c.innerHTML = '';
    if (!ui) { return; }
    var type = ui.type;

    if (type === 'done') {
      var again = el('button', 'btn ghost', this.t('restart')); again.type = 'button';
      again.addEventListener('click', function () { self.restart(); });
      c.appendChild(again);
      return;
    }

    if (type === 'review') {
      var sub = el('button', 'btn', this.t('submit')); sub.type = 'button';
      sub.addEventListener('click', function () { self.sendTurn('submit', null, self.t('submit')); });
      c.appendChild(sub);
      this.focusComposer();
      return;
    }

    if (type === 'choice' || type === 'multi') {
      var opts = (ui.options || []).map(function (o) { return typeof o === 'object' ? o : { value: o, label: o }; });
      var chips = el('div', 'chips');
      if (type === 'multi') {
        c.appendChild(el('div', 'hint', this.t('multi')));
        var picked = {};
        var go = el('button', 'btn', this.t('continue')); go.type = 'button'; go.disabled = true;
        var min = ui.min != null ? ui.min : 1, max = ui.max;
        opts.forEach(function (o) {
          var chip = el('button', 'chip', o.label); chip.type = 'button';
          chip.setAttribute('aria-pressed', 'false');
          chip.addEventListener('click', function () {
            var on = !picked[o.value];
            if (on && max != null && Object.keys(picked).length >= max) { return; }
            if (on) { picked[o.value] = true; } else { delete picked[o.value]; }
            chip.classList.toggle('on', on);
            chip.setAttribute('aria-pressed', String(on));
            go.disabled = Object.keys(picked).length < min;
          });
          chips.appendChild(chip);
        });
        go.addEventListener('click', function () {
          var vals = Object.keys(picked);
          self.sendTurn('list', vals, vals.map(function (v) { return self.optionLabel(v); }).join(', '));
        });
        c.appendChild(chips); c.appendChild(go);
      } else {
        opts.forEach(function (o) {
          var chip = el('button', 'chip', o.label); chip.type = 'button';
          chip.addEventListener('click', function () { self.sendTurn('choice', o.value, o.label); });
          chips.appendChild(chip);
        });
        c.appendChild(chips);
      }
      this.addSkip(c);
      this.focusComposer();
      return;
    }

    // text / textarea
    var multiline = type === 'textarea';
    var input = multiline ? el('textarea') : el('input');
    if (multiline) { input.rows = 2; } else { input.type = ui.inputType || 'text'; }
    input.placeholder = ui.placeholder || this.t('placeholder');
    input.setAttribute('aria-label', ui.message || this.t('placeholder'));
    if (ui.maxLength) { input.maxLength = ui.maxLength; }
    if (ui.min != null) { input.min = ui.min; }
    if (ui.max != null) { input.max = ui.max; }
    if (ui.inputType === 'email') { input.autocomplete = 'email'; input.inputMode = 'email'; }
    if (ui.inputType === 'tel') { input.autocomplete = 'tel'; input.inputMode = 'tel'; }
    var line = el('div', 'line');
    var send = el('button', 'btn', this.t('send')); send.type = 'button';
    var submit = function () {
      var v = input.value.trim();
      if (v === '') { input.focus(); return; }
      self.sendTurn('text', v, v);
    };
    send.addEventListener('click', submit);
    input.addEventListener('keydown', function (e) {
      if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); submit(); }
    });
    line.appendChild(input); line.appendChild(send);
    c.appendChild(line);
    this.addSkip(c);
    this.focusComposer();
  };

  Widget.prototype.addSkip = function (c) {
    var self = this;
    if (!this.ui || !this.ui.optional) { return; }
    var skip = el('button', 'btn ghost', this.t('skip')); skip.type = 'button';
    skip.addEventListener('click', function () { self.sendTurn('skip', null, self.t('skip')); });
    c.appendChild(skip);
  };

  // ------------------------------------------------------------- public API
  var instances = [];

  var AiChatWidget = {
    version: VERSION,
    mount: function (opts) {
      if (!opts || !opts.endpoint) { throw new Error('AiChatWidget.mount: "endpoint" is required.'); }
      var w = new Widget(opts);
      instances.push(w);
      return {
        open: function () { w.open(); }, close: function () { w.close(); },
        setLanguage: function (l) { w.setLanguage(l); }, restart: function () { w.restart(); },
        destroy: function () { w.destroy(); }, element: w.host
      };
    },
    addLocale: addLocale,
    isRtl: isRtl,
    locales: function () { return Object.keys(LOCALES); }
  };

  global.AiChatWidget = AiChatWidget;

  // ------------------------------------------------- one-line script-tag mode
  function autoMount() {
    var tag = document.currentScript;
    if (!tag || !tag.getAttribute('data-endpoint')) { return; }
    var d = function (k) { var v = tag.getAttribute('data-' + k); return v === null ? undefined : v; };
    var run = function () {
      AiChatWidget.mount({
        endpoint: d('endpoint'), flow: d('flow'), lang: d('lang') || 'auto', dir: d('dir'),
        theme: d('theme'), title: d('title'), position: d('position'), mode: d('mode'), target: d('target'),
        open: d('open') === 'true', csrf: d('csrf'),
        languages: d('languages') ? d('languages').split(',').map(function (s) { return s.trim(); }).filter(Boolean)
          : (tag.hasAttribute('data-language-switcher') ? ['en', 'fa', 'ar', 'he', 'ur', 'de', 'fr', 'es', 'tr'] : undefined)
      });
      var color = d('color');
      if (color && instances.length) {
        instances[instances.length - 1].root.style.setProperty('--acw-primary', color);
      }
    };
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', run); } else { run(); }
  }
  autoMount();
})(typeof window !== 'undefined' ? window : this);
