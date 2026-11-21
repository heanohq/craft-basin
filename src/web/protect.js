/* Basin for Craft: adds the CAPTCHA each Basin form requires. */
(() => {
  const config = window.BasinProtect;
  if (!config || window.BasinProtectStarted) return;
  window.BasinProtectStarted = true;

  const nonce = (document.currentScript && document.currentScript.nonce) || config.nonce || null;
  const FORM_SELECTOR = 'form[action*="usebasin.com/f/"]';
  const EXISTING_CAPTCHA = '.g-recaptcha, .h-captcha, .cf-turnstile, [name="g-recaptcha-response"], [name="h-captcha-response"], [name="cf-turnstile-response"]';

  const RECAPTCHA_SCRIPT = 'https://www.google.com/recaptcha/api.js';
  const keys = config.recaptchaKeys || {};

  const handled = new WeakSet();
  const loaded = {};
  const waiting = { hcaptcha: [], turnstile: [] };
  const ready = { hcaptcha: false, turnstile: false };

  // Google's script is loaded once for every reCAPTCHA form on the page. With
  // a v3 form it's loaded with the v3 key, and v2 widgets are rendered from
  // that same script.
  const recaptcha = { loading: null, ready: false, needV3: false, widgets: [] };

  const uuidOf = (form) => {
    const match = (form.getAttribute('action') || '').match(/usebasin\.com\/f\/([A-Za-z0-9]+)/);
    return match ? match[1] : null;
  };

  const loadScript = (src) => {
    if (loaded[src]) return;
    loaded[src] = true;
    const script = document.createElement('script');
    script.src = src;
    script.async = true;
    script.defer = true;
    if (nonce) script.nonce = nonce;
    document.head.appendChild(script);
  };

  const hidden = (form, container, name, value) => {
    let input = form.querySelector(`input[name="${name}"]`);
    if (!input) {
      input = Object.assign(document.createElement('input'), { type: 'hidden', name });
      container.appendChild(input);
    }
    if (value !== undefined) input.value = value;
    return input;
  };

  // Widgets rendered by the provider's API once it has loaded.
  const renderWidget = (type, el, siteKey) => {
    const api = { recaptcha: window.grecaptcha, hcaptcha: window.hcaptcha, turnstile: window.turnstile }[type];
    api.render(el, { sitekey: siteKey });
  };

  const loadRecaptcha = () => {
    if (!recaptcha.needV3 && !recaptcha.widgets.length) return;
    if (recaptcha.loading) {
      if (recaptcha.needV3 && recaptcha.loading !== 'v3') {
        console.warn('Basin: a reCAPTCHA v3 form was added after reCAPTCHA loaded for v2 only, so it can’t get a token.');
      }
      return;
    }
    recaptcha.loading = recaptcha.needV3 ? 'v3' : 'explicit';
    window.basinOnLoad_recaptcha = () => {
      recaptcha.ready = true;
      recaptcha.widgets.splice(0).forEach(([el, key]) => renderWidget('recaptcha', el, key));
    };
    const render = recaptcha.needV3 ? encodeURIComponent(keys.v3) : 'explicit';
    loadScript(`${RECAPTCHA_SCRIPT}?render=${render}&onload=basinOnLoad_recaptcha`);
  };

  const queueRecaptchaV2 = (el, siteKey) => {
    if (recaptcha.ready) renderWidget('recaptcha', el, siteKey);
    else recaptcha.widgets.push([el, siteKey]);
  };

  const queueWidget = (type, el, siteKey, scriptUrl) => {
    if (ready[type]) {
      renderWidget(type, el, siteKey);
      return;
    }
    waiting[type].push([el, siteKey]);
    const callback = `basinOnLoad_${type}`;
    window[callback] = () => {
      ready[type] = true;
      waiting[type].splice(0).forEach(([e, k]) => renderWidget(type, e, k));
    };
    loadScript(`${scriptUrl}${scriptUrl.includes('?') ? '&' : '?'}render=explicit&onload=${callback}`);
  };

  // reCAPTCHA v3, as in Basin's docs: get a token when the form is submitted,
  // then submit again with it. Re-submitting with requestSubmit() means any
  // other submit handlers (e.g. AJAX) run with the token in place.
  const setUpRecaptchaV3 = (form, container, siteKey) => {
    recaptcha.needV3 = true;
    const input = hidden(form, container, 'g-recaptcha-response');
    hidden(form, container, 'g-recaptcha-version', 'v3');
    let passing = false;

    const getToken = () => new Promise((resolve, reject) => {
      const run = () => window.grecaptcha.ready(() => {
        window.grecaptcha.execute(siteKey, { action: 'submit' }).then((token) => {
          input.value = token;
          resolve();
        }, reject);
      });
      const started = Date.now();
      const wait = () => {
        if (window.grecaptcha && window.grecaptcha.ready) run();
        else if (Date.now() - started > 10000) reject(new Error('reCAPTCHA didn’t load'));
        else setTimeout(wait, 100);
      };
      wait();
    });

    form.addEventListener('submit', (event) => {
      if (passing) {
        passing = false;
        return;
      }
      event.preventDefault();
      event.stopImmediatePropagation();
      const submitter = event.submitter || null;
      getToken().catch((error) => console.warn('Basin:', error)).then(() => {
        passing = true;
        if (form.requestSubmit) {
          form.requestSubmit(submitter);
        } else {
          passing = false;
          form.submit();
        }
      });
    }, true);
  };

  // A template can pick the reCAPTCHA version with data-basin-recaptcha on
  // the placeholder or the <form>; otherwise the form's own setting is used.
  const recaptchaVersion = (form, container, settings) => {
    const chosen = container.getAttribute('data-basin-recaptcha') || form.getAttribute('data-basin-recaptcha');
    return chosen === 'v2' || chosen === 'v3' ? chosen : (settings.recaptchaVersion || 'v3');
  };

  const addCaptcha = (form, container, settings) => {
    const version = settings.captcha === 'recaptcha' ? recaptchaVersion(form, container, settings) : null;
    const siteKey = version ? (keys[version] || settings.siteKey) : settings.siteKey;
    if (version === 'v3') {
      setUpRecaptchaV3(form, container, siteKey);
      return;
    }
    if (!siteKey) {
      console.warn(`Basin: this form requires ${settings.captcha}, but no site key is set for it in Basin, so submissions will fail.`, form);
      return;
    }
    const el = document.createElement('div');
    el.className = 'basin-captcha';
    container.appendChild(el);
    if (version) queueRecaptchaV2(el, siteKey);
    else queueWidget(settings.captcha, el, siteKey, settings.scriptUrl);
  };

  const submitButton = (form) => {
    const buttons = form.querySelectorAll('button[type="submit"], input[type="submit"], button:not([type])');
    return buttons.length ? buttons[buttons.length - 1] : null;
  };

  const protect = (form) => {
    if (handled.has(form)) return;
    const uuid = uuidOf(form);
    const settings = uuid && config.forms[uuid];
    if (!settings) return;

    // A placeholder from form.captcha() says where to put it.
    const placeholder = form.querySelector(`[data-basin-captcha="${uuid}"]`);
    if (placeholder) {
      handled.add(form);
      addCaptcha(form, placeholder, settings);
      return;
    }

    if (!config.auto || form.getAttribute('data-basin-protect') === 'false') return;
    handled.add(form);

    if (!form.querySelector(`[name="${settings.honeypotField}"]`)) {
      const wrapper = document.createElement('div');
      wrapper.setAttribute('data-basin-extra', '');
      wrapper.setAttribute('aria-hidden', 'true');
      wrapper.append(Object.assign(document.createElement('input'), {
        type: 'text', name: settings.honeypotField, tabIndex: -1, autocomplete: 'off',
      }));
      form.prepend(wrapper);
    }
    // The same as honeypot.css, for a page without it (a form added after the
    // page loaded). Styles set from a script aren't blocked by a Content
    // Security Policy.
    form.querySelectorAll('[data-basin-extra]').forEach((wrapper) => {
      Object.entries({
        position: 'absolute', left: '-9999px', top: 'auto', width: '1px', height: '1px', overflow: 'hidden',
      }).forEach(([property, value]) => wrapper.style.setProperty(property, value, 'important'));
    });

    if (form.querySelector(EXISTING_CAPTCHA)) return;

    const container = document.createElement('div');
    container.setAttribute('data-basin-captcha', uuid);
    const button = submitButton(form);
    if (button) {
      button.parentNode.insertBefore(container, button);
    } else {
      form.appendChild(container);
    }
    addCaptcha(form, container, settings);
  };

  const scan = () => {
    document.querySelectorAll(FORM_SELECTOR).forEach(protect);
    loadRecaptcha();
  };

  let queued = false;
  const queueScan = () => {
    if (queued) return;
    queued = true;
    // Not requestAnimationFrame, which doesn't run in background tabs.
    setTimeout(() => {
      queued = false;
      scan();
    }, 50);
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', scan);
  } else {
    scan();
  }
  new MutationObserver(queueScan).observe(document.documentElement, { childList: true, subtree: true });
})();
