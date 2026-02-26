/**
 * This code was adapted from Perfume.js for use in CSE 135 
 * https://github.com/Zizzamia/perfume.js
 */

/**
 * Initialize global constants
 * Window, Window.console, Window.navigator, Window.performance
 */
const W = window;
const C = W.console;
const D = document;
const WN = W.navigator;
const WP = W.performance;

// ===== CSE135 endpoint + session =====
const ENDPOINT = "https://collector.hknucsd-outreach.org/api/collect.php";

function getCookie(name) {
  const m = document.cookie.match(
    new RegExp('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\/+^])/g, "\\$1") + "=([^;]*)")
  );
  return m ? decodeURIComponent(m[1]) : null;
}

function getSID() {
  return getCookie("sid");
}

// ===== batching =====
let EVENT_QUEUE = [];
let FLUSH_TIMER = null;

function beaconSend(obj, force = false) {
  const sid = getSID();
  if (!sid) return;// session tie-in is required
  const blob = new Blob([JSON.stringify(obj)], { type: "application/json" });

  const ok = navigator.sendBeacon && navigator.sendBeacon(ENDPOINT, blob);
  if (!ok && force) {
    fetch(ENDPOINT, { method: "POST", body: blob, keepalive: true }).catch(() => {});
  }
}

function queueEvent(type, data) {
  const sid = getSID();
  if (!sid) return;
  EVENT_QUEUE.push({
    type,
    ts: Date.now(),
    page: location.href,
    data: data ?? {}
  });

  if (EVENT_QUEUE.length >= 25) flush(true);

  if (!FLUSH_TIMER) {
    FLUSH_TIMER = setTimeout(() => {
      FLUSH_TIMER = null;
      flush(false);
    }, 2000);
  }
}

function flush(force) {
  const sid = getSID();
  if (!sid || !EVENT_QUEUE.length) return;
  beaconSend(
    {
      sid: SID,
      sent_at: Date.now(),
      page: location.href,
      events: EVENT_QUEUE.splice(0, EVENT_QUEUE.length)
    },
    force
  );
}

window.addEventListener("pagehide", () => flush(true));
document.addEventListener("visibilitychange", () => {
  if (document.visibilityState === "hidden") flush(true);
});

const getDM = () => WN.deviceMemory;
const getHC = () => WN.hardwareConcurrency;

const config = {
  // Metrics
  isResourceTiming: false,
  isElementTiming: false,
  // Logging
  maxTime: 15000,
};

/**
 * Initialize data tracking globals with null data
 */
let perfObservers = {};
let metrics = {};

let cls = { value: 0 };
let fcp = { value: 0 };
let lcp = { value: 0 };
let tbt = { value: 0 };
let fcpEntryName = "first-contentful-paint";
let rt = {
  value: {
    beacon: 0,
    css: 0,
    fetch: 0,
    img: 0,
    other: 0,
    script: 0,
    total: 0,
    xmlhttprequest: 0,
  },
};

/**
 * Initialize WebVitals benchmarks
 */

const fcpScore = [1000, 2500];
const lcpScore = [2500, 4000];
const clsScore = [0.1, 0.25];
const tbtScore = [300, 600];

let webVitalsScore = {
  fp: fcpScore,
  fcp: fcpScore,
  lcp: lcpScore,
  lcpFinal: lcpScore,
  fid: [100, 300],
  cls: clsScore,
  clsFinal: clsScore,
  tbt: tbtScore,
  tbt5S: tbtScore,
  tbt10S: tbtScore,
  tbtFinal: tbtScore,
};

/**
 * Takes in a measurement value and compares it to the 
 * Web Vitals benchmarks stored in webVitalsScore
 */
function getVitalsScore(measureName, value) {
  if (!webVitalsScore[measureName]) {
    return null;
  }
  if (value <= webVitalsScore[measureName][0]) {
    return "good";
  }
  return value <= webVitalsScore[measureName][1] ? "needsImprovement" : "poor";
}

/**
 * Gets device memory and hardware concurrency to 
 * determine if a device is low end
 */
function getIsLowEndDevice() {
  // If number of logical processors available to run threads <= 4
  if (getHC() && getHC() <= 4) {
    return true;
  }
  // If the approximate amount of RAM client device has <= 4
  if (getDM() && getDM() <= 4) {
    return true;
  }
  return false;
}

/**
 * If a device is low end or has a poor network connection
 * it is considered a low end experience
 */
function getIsLowEndExperience() {
  if (getIsLowEndDevice()) {
    return true;
  }
  // If the effective type of the connection meaning
  // one of 'slow-2g', '2g', '3g', or '4g' is !== 4g
  if (["slow-2g", "2g", "3g"].includes(getNetworkInformation().effectiveType)) {
    return true;
  }
  return false;
}

/**
 * Gets relevant data from Window.navigator object 
 * and formats into a basic JavaScript object. 
 * Navigator API can be viewed at
 * https://developer.mozilla.org/en-US/docs/Web/API/Navigator
 */
function getNavigatorInfo() {
  if (WN) {
    return {
      deviceMemory: getDM() || 0,
      hardwareConcurrency: getHC() || 0,
      serviceWorkerStatus:
        "serviceWorker" in WN
          ? WN.serviceWorker.controller
            ? "controlled"
            : "supported"
          : "unsupported",
      isLowEndDevice: getIsLowEndDevice(),
      isLowEndExperience: getIsLowEndExperience(),
    };
  }
  return {};
}

/**
 * Navigation Timing API provides performance metrics for HTML documents.
 * w3c.github.io/navigation-timing/
 * developers.google.com/web/fundamentals/performance/navigation-and-resource-timing
 */
function getNavigationTiming() {
  if (!isPerformanceSupported()) {
    return {};
  }
  // There is an open issue to type correctly getEntriesByType
  // github.com/microsoft/TypeScript/issues/33866
  const n = WP.getEntriesByType("navigation")[0];
  // In Safari version 11.2 Navigation Timing isn't supported yet
  if (!n) {
    return {};
  }
  const responseStart = n.responseStart;
  const responseEnd = n.responseEnd;
  // We cache the navigation time for future times
  return {
    // fetchStart marks when the browser starts to fetch a resource
    // responseEnd is when the last byte of the response arrives
    fetchTime: responseEnd - n.fetchStart,
    // Service worker time plus response time
    workerTime: n.workerStart > 0 ? responseEnd - n.workerStart : 0,
    // Request plus response time (network only)
    totalTime: responseEnd - n.requestStart,
    // Response time only (download)
    downloadTime: responseEnd - responseStart,
    // Time to First Byte (TTFB)
    timeToFirstByte: responseStart - n.requestStart,
    // HTTP header size
    headerSize: n.transferSize - n.encodedBodySize || 0,
    // Measuring DNS lookup time
    dnsLookupTime: n.domainLookupEnd - n.domainLookupStart,
  };
}

/**
 * Gets Network information for Window.navigator 
 * and formats into basic JavaScript object
 */
function getNetworkInformation() {
  let et = "4g";
  let sd = false;
  if ("connection" in WN) {
    const dataConnection = WN.connection;
    if (typeof dataConnection !== "object") {
      return {};
    }
    et = dataConnection.effectiveType;
    sd = !!dataConnection.saveData;
    return {
      downlink: dataConnection.downlink,
      effectiveType: dataConnection.effectiveType,
      rtt: dataConnection.rtt,
      saveData: !!dataConnection.saveData,
    };
  }
  return {};
}

/**
 * Gets initial browser screen size and basic
 * browser information (platform, language, cookies)
 */
function initialBrowserData() {
  return {
    userAgent: WN.userAgent,
    innerHeight: W.innerHeight,
    outerHeight: W.outerHeight,
    innerWidth: W.innerWidth,
    outerWidth: W.outerWidth,
    language: WN.language,
    cookieEnabled: WN.cookieEnabled,
  };
}

/**
 * Logs metrics for performanceEntries
 */
function initElementTiming(performanceEntries) {
  performanceEntries.forEach((performanceEntry) => {
    if (performanceEntry.identifier) {
      logMetric(performanceEntry.startTime, performanceEntry.identifier);
    }
  });
}

/**
 * First Paint is essentially the paint after which
 * the biggest above-the-fold layout change has happened.
 */
function initFirstPaint(performanceEntries) {
  performanceEntries.forEach((performanceEntry) => {
    if (performanceEntry.name === "first-paint") {
      logMetric(performanceEntry.startTime, "fp");
    } else if (performanceEntry.name === fcpEntryName) {
      fcp.value = performanceEntry.startTime;
      logMetric(fcp.value, "fcp");
      perfObservers[4] = po("longtask", initTotalBlockingTime);
      poDisconnect(0);
    }
  });
}

/**
 * Grabs FID for use in calculating WebVitals
 */
function initFirstInputDelay(performanceEntries) {
  const lastEntry = performanceEntries.pop();
  if (lastEntry) {
    logMetric(lastEntry.duration, "fid");
  }
  poDisconnect(1);
  logMetric(lcp.value, "lcp");
  if (perfObservers[3]) {
    perfObservers[3].takeRecords();
  }
  logMetric(cls.value, "cls");
  logMetric(tbt.value, "tbt");
  // TBT with 5 second delay after FID
  setTimeout(() => {
    logMetric(tbt.value, `tbt5S`);
  }, 5000);
  // TBT with 10 second delay after FID
  setTimeout(() => {
    logMetric(tbt.value, `tbt10S`);
    logData("dataConsumption", rt.value);
  }, 10000);
}

/**
 * Gets lrgest contentful paint for WebVitals
 */
function initLargestContentfulPaint(performanceEntries) {
  const lastEntry = performanceEntries.pop();
  if (lastEntry) {
    lcp.value = lastEntry.renderTime || lastEntry.loadTime;
  }
}

/**
 * Detects new layout shift occurrences and updates the
 * `cumulativeLayoutShiftScore` variable.
 */
function initLayoutShift(performanceEntries) {
  const lastEntry = performanceEntries.pop();
  // Only count layout shifts without recent user input.
  if (lastEntry && !lastEntry.hadRecentInput && lastEntry.value) {
    cls.value += lastEntry.value;
  }
}

function initPerformanceObserver() {
  perfObservers[0] = po("paint", initFirstPaint);
  // FID needs to be initialized as soon as Perfume is available
  // DataConsumption resolves after FID is triggered
  perfObservers[1] = po("first-input", initFirstInputDelay);
  perfObservers[2] = po("largest-contentful-paint", initLargestContentfulPaint);
  // Collects KB information related to resources on the page
  if (config.isResourceTiming) {
    po("resource", initResourceTiming);
  }
  perfObservers[3] = po("layout-shift", initLayoutShift);
  if (config.isElementTiming) {
    po("element", initElementTiming);
  }
}

function initResourceTiming(performanceEntries) {
  performanceEntries.forEach((performanceEntry) => {
    if (config.isResourceTiming) {
      logData("resourceTiming", performanceEntry);
    }
    if (performanceEntry.decodedBodySize && performanceEntry.initiatorType) {
      const bodySize = performanceEntry.decodedBodySize / 1000;
      rt.value[performanceEntry.initiatorType] += bodySize;
      rt.value.total += bodySize;
    }
  });
}

function initTotalBlockingTime(performanceEntries) {
  performanceEntries.forEach((performanceEntry) => {
    if (
      performanceEntry.name !== "self" ||
      performanceEntry.startTime < fcp.value
    ) {
      return;
    }
    const blockingTime = performanceEntry.duration - 50;
    if (blockingTime > 0) {
      tbt.value += blockingTime;
    }
  });
}

/**
 * True if the browser supports the Navigation Timing API,
 * User Timing API and the PerformanceObserver Interface.
 * In Safari, the User Timing API (performance.mark()) is not available,
 * so the DevTools timeline will not be annotated with marks.
 * Support: developer.mozilla.org/en-US/docs/Web/API/Performance/mark
 * Support: developer.mozilla.org/en-US/docs/Web/API/PerformanceObserver
 * Support: developer.mozilla.org/en-US/docs/Web/API/Performance/getEntriesByType
 */
function isPerformanceSupported() {
  return WP && !!WP.getEntriesByType && !!WP.now && !!WP.mark;
}

/**
 * Format data for reporting
 */
function logData(measureName, metric, customProperties = {}) {
  Object.keys(metric).forEach((key) => {
    if (typeof metric[key] === "number") {
      metric[key] = roundByTwo(metric[key]);
    }
  });
  // Sends the metric to an external tracking service
  reportPerf(measureName, metric, customProperties);
}

/**
 * Dispatches the metric duration into internal logs
 * and the external time tracking service.
 */
function logMetric(duration, measureName) {
  const duration2Decimal = roundByTwo(duration);
  if (duration2Decimal <= config.maxTime && duration2Decimal > 0) {
    // Sends the metric to an external tracking service
    reportPerf(measureName, duration2Decimal);
  }
}

/**
 * Get the duration of the timing metric or -1
 * if there a measurement has not been made by the User Timing API
 */
function performanceMeasure(measureName) {
  WP.measure(
    measureName,
    `mark_${measureName}_start`,
    `mark_${measureName}_end`
  );
  const entry = WP.getEntriesByName(measureName).pop();
  if (entry && entry.entryType === "measure") {
    return entry.duration;
  }
  return -1;
}

/**
 * PerformanceObserver subscribes to performance events as they happen
 * and respond to them asynchronously.
 */
function po(eventType, cb) {
  try {
    const perfObserver = new PerformanceObserver(function () {
      cb(WP.getEntries());
    });
    // Retrieve buffered events and subscribe to newer events for Paint Timing
    perfObserver.observe({ type: eventType, buffered: false });
    start(eventType);
    return perfObserver;
  } catch (e) {
    C.warn("Perfume.js:", e);
  }
  return null;
}

function poDisconnect(observer) {
  if (perfObservers[observer]) {
    perfObservers[observer].disconnect();
  }
  delete perfObservers[observer];
}

/**
 * PushTask to requestIdleCallback
 */
function pushTask(cb) {
  if ("requestIdleCallback" in W) {
    W.requestIdleCallback(cb, { timeout: 3000 });
  } else {
    cb();
  }
}

/**
 * Sends the User timing measure to analyticsTracker
 */
function reportPerf(measureName, data, customProperties = {}) {
  pushTask(() => {
    // Send metric to custom Analytics service
    let payload = ({
      metricName: measureName,
      data,
      eventProperties: customProperties || {},
      navigatorInformation: getNavigatorInfo(),
      vitalsScore: getVitalsScore(measureName, data),
    });
    queueEvent("perf", payload);
  });
}

function reportStorageEstimate(storageInfo) {
  const estimateUsageDetails =
    "usageDetails" in storageInfo ? storageInfo.usageDetails : {};
  logData("storageEstimate", {
    quota: convertToKB(storageInfo.quota),
    usage: convertToKB(storageInfo.usage),
    caches: convertToKB(estimateUsageDetails.caches),
    indexedDB: convertToKB(estimateUsageDetails.indexedDB),
    serviceWorker: convertToKB(estimateUsageDetails.serviceWorkerRegistrations),
  });
}

/**
 * Start performance measurement
 */
function start(markName) {
  if (!isPerformanceSupported() || metrics[markName]) {
    return;
  }
  metrics[markName] = true;
  // Creates a timestamp in the browser's performance entry buffer
  WP.mark(`mark_${markName}_start`);
}

/**
 * End performance measurement
 */
function end(markName, customProperties = {}) {
  if (!isPerformanceSupported() || !metrics[markName]) {
    return;
  }
  // End Performance Mark
  WP.mark(`mark_${markName}_end`);
  delete metrics[markName];
  logData(markName, roundByTwo(performanceMeasure(markName)), customProperties);
}

/**
 * End performance measurement after first paint from the beging of it
 */
function endPaint(markName, customProperties = {}) {
  setTimeout(() => {
    end(markName, customProperties);
  });
}

/**
 * Removes the named mark from the browser's performance entry buffer.
 */
function clear(markName) {
  delete metrics[markName];
  // Mobile Safari v13 and UC Browser v11
  // don't support clearMarks yet
  if (!WP.clearMarks) {
    return;
  }
  WP.clearMarks(`mark_${markName}_start`);
  WP.clearMarks(`mark_${markName}_end`);
}

/** 
 * Collect and report errors
 */ 
function reportError(msg, url, lineNo, columnNo, error) {
  const payload = {
    message: String(msg || (error && error.message) || "unknown"),
    url: String(url || document.location.href),
    line: lineNo ?? null,
    col: columnNo ?? null,
    stack: error && error.stack ? String(error.stack).slice(0, 4000) : null
  };
  queueEvent("error", payload);
  flush(false);
}

// Utility functions for data collection/formatting

function roundByTwo(num) {
  return !Number.isNaN(num) ? Number.parseFloat(num).toFixed(2) : num;
}

function convertToKB(bytes) {
  if (typeof bytes !== "number") {
    return null;
  }
  return roundByTwo(bytes / Math.pow(1024, 2));
}

window.onload = () => {
  let metricTypes = ["navigationTiming", "networkInformation", "storageEstimate", "fp", "fcp",
    "fid", "lcp", "lcpFinal", "cls", "clsFinal", "tbt"];

  if (!isPerformanceSupported()) {
    return;
  }
  // Checks if use Performance or the EmulatedPerformance instance
  if ("PerformanceObserver" in W) {
    initPerformanceObserver();
  }

  metricTypes.forEach(metVal => start(metVal));

  reportPerf("initialBrowserData", initialBrowserData());
  
  // Let's estimate our storage capacity
  if (WN && WN.storage && typeof WN.storage.estimate === "function") {
    WN.storage.estimate().then(reportStorageEstimate);
  }
  
  metricTypes.forEach(metVal => end(metVal));
};

window.onerror = function(msg, url, lineNo, columnNo, error) {
  reportError(msg, url, lineNo, columnNo, error);
};

// ===== CSE135 Required Static + Activity =====

// best-effort manual checks
function detectCssEnabled() {
  try {
    const el = document.createElement("div");
    el.id = "css_test";
    const style = document.createElement("style");
    style.textContent = "#css_test{width:123px}";
    document.head.appendChild(style);
    document.body.appendChild(el);
    const ok = getComputedStyle(el).width === "123px";
    el.remove(); style.remove();
    return ok;
  } catch {
    return null;
  }
}

function detectImagesEnabled() {
  // use your MAIN SITE favicon (must exist)
  const url = "https://www.hknucsd-outreach.org/favicon.ico?img=" + Date.now();
  return new Promise((resolve) => {
    const img = new Image();
    let done = false;
    const finish = (v) => { if (!done) { done = true; resolve(v); } };
    img.onload = () => finish(true);
    img.onerror = () => finish(false);
    img.src = url;
    setTimeout(() => finish(false), 1500);
  });
}

// ENTER
queueEvent("enter", { enteredAt: Date.now() });

// STATIC + required performance timings (simple rubric-friendly)
window.addEventListener("load", async () => {
  const allowsImages = await detectImagesEnabled();
  const allowsCSS = detectCssEnabled();

  const conn = navigator.connection || null;
  queueEvent("static", {
    userAgent: navigator.userAgent,
    language: navigator.language,
    acceptsCookies: !!navigator.cookieEnabled,
    allowsJavaScript: true,
    allowsImages,
    allowsCSS,
    screen: { w: screen.width, h: screen.height },
    window: {
      innerW: window.innerWidth, innerH: window.innerHeight,
      outerW: window.outerWidth, outerH: window.outerHeight
    },
    network: conn ? { effectiveType: conn.effectiveType ?? null } : { effectiveType: null }
  });

  // PERFORMANCE required fields (start/end/total + whole object)
  const nav = performance.getEntriesByType?.("navigation")?.[0];
  if (nav) {
    const start = nav.startTime;
    const end = nav.loadEventEnd;
    queueEvent("performance_required", {
      timing: nav.toJSON ? nav.toJSON() : nav,
      startedLoadingMs: start,
      endedLoadingMs: end,
      totalLoadTimeMs: Math.max(0, end - start)
    });
  } else if (performance.timing) {
    const t = performance.timing;
    const total = (t.loadEventEnd && t.navigationStart)
      ? Math.max(0, t.loadEventEnd - t.navigationStart)
      : null;
    queueEvent("performance_required", {
      timing: t,
      startedLoadingMs: 0,
      endedLoadingMs: total,
      totalLoadTimeMs: total
    });
  }

  flush(false);
});

// ACTIVITY (mouse/click/scroll/keys)
const throttle = (fn, ms) => {
  let last = 0;
  return (...args) => {
    const n = Date.now();
    if (n - last >= ms) { last = n; fn(...args); }
  };
};

window.addEventListener("mousemove", throttle((e) => {
  queueEvent("mousemove", { x: e.clientX, y: e.clientY });
}, 100), { passive: true });

window.addEventListener("click", (e) => {
  queueEvent("click", { x: e.clientX, y: e.clientY, button: e.button });
}, { passive: true });

window.addEventListener("scroll", throttle(() => {
  queueEvent("scroll", { x: window.scrollX, y: window.scrollY });
}, 200), { passive: true });

window.addEventListener("keydown", (e) => {
  queueEvent("keydown", { key: String(e.key).slice(0, 100), code: String(e.code).slice(0, 100) });
}, { passive: true });

window.addEventListener("keyup", (e) => {
  queueEvent("keyup", { key: String(e.key).slice(0, 100), code: String(e.code).slice(0, 100) });
}, { passive: true });

// IDLE detection (>= 2 seconds no activity)
let lastActivity = Date.now();
let idleStart = null;

function markActivity() {
  const n = Date.now();
  if (idleStart !== null) {
    queueEvent("idle_end", { endedAt: n, durationMs: n - idleStart });
    idleStart = null;
    flush(false);
  }
  lastActivity = n;
}

["mousemove","click","scroll","keydown","keyup","touchstart"].forEach((ev) =>
  window.addEventListener(ev, markActivity, { passive: true })
);

setInterval(() => {
  const n = Date.now();
  if (idleStart === null && n - lastActivity >= 2000) {
    idleStart = lastActivity + 2000;
    queueEvent("idle_start", { startedAt: idleStart });
    flush(false);
  }
}, 250);

// LEAVE
window.addEventListener("beforeunload", () => {
  queueEvent("leave", { leftAt: Date.now() });
  flush(true);
});