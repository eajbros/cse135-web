// collector-v2.js — Pageview + Technographics
(function() {
  'use strict';

  const endpoint = 'https://collector.hknucsd-outreach.org/api/collect.php';

  function getCookie(name) {
    const m = document.cookie.match(new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, '\\$1') + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function getTechnographics() {
    let networkInfo = {};

    if ('connection' in navigator && navigator.connection) {
      const conn = navigator.connection;
      networkInfo = {
        effectiveType: conn.effectiveType,
        downlink: conn.downlink,
        rtt: conn.rtt,
        saveData: conn.saveData
      };
    }

    return {
      userAgent: navigator.userAgent,
      language: navigator.language,
      cookiesEnabled: navigator.cookieEnabled,

      viewportWidth: window.innerWidth,
      viewportHeight: window.innerHeight,

      screenWidth: window.screen.width,
      screenHeight: window.screen.height,
      pixelRatio: window.devicePixelRatio,

      cores: navigator.hardwareConcurrency || 0,
      memory: navigator.deviceMemory || 0,

      network: networkInfo,

      colorScheme: window.matchMedia('(prefers-color-scheme: dark)').matches
        ? 'dark'
        : 'light',

      timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
    };
  }

  function detectCssEnabled() {
    const el = document.createElement('div');
    el.id = 'css_test';
    const style = document.createElement('style');
    style.textContent = '#css_test{width:123px}';
    document.head.appendChild(style);
    document.body.appendChild(el);
    const ok = getComputedStyle(el).width === '123px';
    el.remove(); style.remove();
    return ok;
  }

  function detectImagesEnabled() {
    return new Promise((resolve) => {
      const img = new Image();
      img.onload = () => resolve(true);
      img.onerror = () => resolve(false);
      img.src = 'https://hknucsd-outreach.org/favicon.ico?img=' + Date.now();
      setTimeout(() => resolve(false), 1500);
    });
  }

  async function sendBeacon() {
    const sid = getCookie('sid');
    if (!sid) return;
    const allowsImages = await detectImagesEnabled();
    const allowsCSS = detectCssEnabled();

    const payload = {
      type: 'static',
      sid,
      page: window.location.href,
      ts: Date.now(),

      // required static fields
      userAgent: navigator.userAgent,
      language: navigator.language,
      acceptsCookies: navigator.cookieEnabled,

      allowsJavaScript: true,
      allowsImages,
      allowsCSS,

      screen: { w: screen.width, h: screen.height },
      window: {
        innerW: window.innerWidth, innerH: window.innerHeight,
        outerW: window.outerWidth, outerH: window.outerHeight
      },

      network: ('connection' in navigator && navigator.connection)
        ? { effectiveType: navigator.connection.effectiveType }
        : { effectiveType: null },

      // extra stuff you already collect (fine)
      title: document.title,
      referrer: document.referrer,
      technographics: getTechnographics()
    };

    const blob = new Blob([JSON.stringify(payload)], { type: 'application/json' });

    if (navigator.sendBeacon) navigator.sendBeacon(endpoint, blob);
    else fetch(endpoint, { method: 'POST', body: blob, keepalive: true });
  }

  if (document.readyState === 'complete') {
    const sid = getCookie('sid');
    sendBeacon();
  } else {
    window.addEventListener('load', sendBeacon);
  }
})();