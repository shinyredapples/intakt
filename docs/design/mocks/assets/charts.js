/* Intakt mocks: small, quiet SVG charts. Single-hue marks (--primary), recessive grid, direct labels,
   hover/focus tooltip on every mark, and a table under each chart. No amber. */
(function () {
  "use strict";
  const NS = "http://www.w3.org/2000/svg";
  const el = (tag, attrs, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  };
  let tip;
  function showTip(evt, title, value) {
    if (!tip) { tip = document.createElement("div"); tip.className = "chart-tip"; tip.setAttribute("role", "status"); document.body.appendChild(tip); }
    tip.textContent = "";
    const v = document.createElement("strong"); v.textContent = value;
    const t = document.createElement("span"); t.textContent = title;
    tip.append(v, t);
    const r = (evt.target.getBoundingClientRect ? evt.target : evt.currentTarget).getBoundingClientRect();
    tip.style.display = "block";
    const x = Math.min(innerWidth - tip.offsetWidth - 8, Math.max(8, r.left + r.width / 2 - tip.offsetWidth / 2));
    tip.style.left = x + scrollX + "px";
    tip.style.top = r.top + scrollY - tip.offsetHeight - 8 + "px";
  }
  const hideTip = () => tip && (tip.style.display = "none");

  function niceMax(m) { const p = Math.pow(10, Math.floor(Math.log10(m || 1))); return Math.ceil(m / p * 2) / 2 * p; }

  /* opts: { type: "bar"|"line", labels, values, unit, fmt, max, height, endLabel } */
  function chart(host, o) {
    const W = Math.max(240, Math.round(host.clientWidth || 560)), H = o.height || 180, P = { l: 36, r: o.type === "line" ? 64 : 8, t: 12, b: 26 };
    const vals = o.values.filter((v) => v != null);
    const max = o.max || niceMax(vals.length ? Math.max(0, ...vals) : 0) || 1; // all zero or no data: a scale of 1, never 0 or -Infinity
    const fmt = o.fmt || ((v) => String(v));
    const svg = el("svg", { viewBox: `0 0 ${W} ${H}`, width: W, height: H, class: "chart-svg", role: "img", "aria-label": o.aria || "" });
    const iw = W - P.l - P.r, ih = H - P.t - P.b, n = o.values.length;
    const y = (v) => P.t + ih - (v / max) * ih;
    [0, 0.5, 1].forEach((f) => {
      const v = max * f, yy = y(v);
      el("line", { x1: P.l, x2: W - P.r, y1: yy, y2: yy, class: f === 0 ? "c-base" : "c-grid" }, svg);
      const t = el("text", { x: P.l - 6, y: yy + 4, class: "c-axis", "text-anchor": "end" }, svg); t.textContent = fmt(+v.toFixed(1));
    });
    const step = iw / n;
    const cx = (i) => P.l + step * i + step / 2;
    [0, Math.floor((n - 1) / 2), n - 1].forEach((i) => {
      const t = el("text", { x: cx(i), y: H - 6, class: "c-axis", "text-anchor": "middle" }, svg); t.textContent = o.labels[i];
    });
    if (o.type === "bar") {
      const bw = Math.max(4, Math.min(28, step - 6));
      o.values.forEach((v, i) => {
        if (v == null) return;
        const h = Math.max(1, (v / max) * ih), x = cx(i) - bw / 2;
        const r = Math.min(4, bw / 2, h);
        el("path", { d: `M${x},${y(0)} V${y(v) + r} Q${x},${y(v)} ${x + r},${y(v)} H${x + bw - r} Q${x + bw},${y(v)} ${x + bw},${y(v) + r} V${y(0)} Z`, class: "c-bar" }, svg);
        const hit = el("rect", { x: P.l + step * i, y: P.t, width: step, height: ih, class: "c-hit", tabindex: 0, "aria-label": `${o.labels[i]}: ${fmt(v)}${o.unit || ""}` }, svg);
        const on = (e) => showTip(e, o.labels[i], fmt(v) + (o.unit || ""));
        hit.addEventListener("pointerenter", on); hit.addEventListener("focus", on);
        hit.addEventListener("pointerleave", hideTip); hit.addEventListener("blur", hideTip);
      });
    } else {
      let d = "", started = false;
      o.values.forEach((v, i) => { if (v == null) { started = false; return; } d += (started ? "L" : "M") + cx(i) + "," + y(v); started = true; });
      el("path", { d, class: "c-line" }, svg);
      o.values.forEach((v, i) => {
        if (v == null) return;
        const hit = el("rect", { x: P.l + step * i, y: P.t, width: step, height: ih, class: "c-hit", tabindex: 0, "aria-label": `${o.labels[i]}: ${fmt(v)}${o.unit || ""}` }, svg);
        const dot = el("circle", { cx: cx(i), cy: y(v), r: 4, class: "c-dot" }, svg);
        const on = (e) => { dot.classList.add("on"); showTip(e, o.labels[i], fmt(v) + (o.unit || "")); };
        const off = () => { dot.classList.remove("on"); hideTip(); };
        hit.addEventListener("pointerenter", on); hit.addEventListener("focus", on);
        hit.addEventListener("pointerleave", off); hit.addEventListener("blur", off);
      });
      let li = o.values.length - 1; while (li >= 0 && o.values[li] == null) li--;
      if (li >= 0) { // no endpoint when the series has no observation
        el("circle", { cx: cx(li), cy: y(o.values[li]), r: 4, class: "c-end" }, svg);
        const t = el("text", { x: cx(li) + 8, y: y(o.values[li]) + 4, class: "c-label" }, svg);
        t.textContent = (o.endLabel ? o.endLabel + " " : "") + fmt(o.values[li]) + (o.unit || "");
      }
    }
    host.appendChild(svg);
    if (o.table !== false) {
      const det = document.createElement("details"); det.className = "c-table";
      const s = document.createElement("summary"); s.textContent = "Show as table"; det.appendChild(s);
      const tb = document.createElement("table"); const hr = tb.insertRow();
      [o.rowName || "Day", o.colName || "Value"].forEach((h) => { const th = document.createElement("th"); th.textContent = h; hr.appendChild(th); });
      o.values.forEach((v, i) => { const r = tb.insertRow(); r.insertCell().textContent = o.labels[i]; r.insertCell().textContent = v == null ? "No data" : fmt(v) + (o.unit || ""); });
      det.appendChild(tb); host.appendChild(det);
    }
  }
  window.IntaktCharts = { chart };
})();
