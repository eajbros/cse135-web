// collector-v2.js — Pageview + Technographics
(function() {
  'use strict';

  const endpoint = '/collect';

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

  function sendBeacon() {
    const payload = {
      url: window.location.href,
      title: document.title,
      referrer: document.referrer,
      timestamp: new Date().toISOString(),
      type: 'pageview',
      technographics: getTechnographics()
    };

    const blob = new Blob(
      [JSON.stringify(payload)],
      { type: 'application/json' }
    );

    if (navigator.sendBeacon) {
      navigator.sendBeacon(endpoint, blob);
    } else {
      fetch(endpoint, {
        method: 'POST',
        body: blob,
        keepalive: true
      });
    }
  }

  if (document.readyState === 'complete') {
    sendBeacon();
  } else {
    window.addEventListener('load', sendBeacon);
  }
})();