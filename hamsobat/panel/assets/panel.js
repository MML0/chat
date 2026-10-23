/* Hamsobat admin panel. No libraries. User text is only ever set with textContent. */
(function () {
  'use strict';

  var store = {
    get: function (k) { try { return window.localStorage.getItem(k); } catch (e) { return null; } },
    set: function (k, v) { try { window.localStorage.setItem(k, v); } catch (e) { /* private mode */ } }
  };
  var fa = function (n) { return String(n).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); };
  var num = function (n) { return fa(Number(n || 0).toLocaleString('en-US').replace(/,/g, '٬')); };
  var el = function (tag, cls, text) {
    var e = document.createElement(tag);
    if (cls) { e.className = cls; }
    if (text !== undefined && text !== null) { e.textContent = text; }
    return e;
  };

  // ---------------------------------------------------------------- theme
  var root = document.documentElement;
  var saved = store.get('hs-theme');
  if (saved === 'light' || saved === 'dark') {
    root.setAttribute('data-theme', saved);
  } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: light)').matches) {
    root.setAttribute('data-theme', 'light');
  }

  document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-theme-toggle]').forEach(function (b) {
      b.addEventListener('click', function () {
        var next = root.getAttribute('data-theme') === 'light' ? 'dark' : 'light';
        root.setAttribute('data-theme', next);
        store.set('hs-theme', next);
      });
    });

    // Time charts (server page): crosshair + one tooltip with every number of that time.
    document.querySelectorAll('.tc').forEach(function (fig) {
      var data;
      try { data = JSON.parse(fig.getAttribute('data-tc')); } catch (e) { return; }
      var plot = fig.querySelector('.tc-plot');
      if (!plot || !data || !data.n) { return; }
      var cols = fig.querySelectorAll('.tc-col');
      var cross = el('div', 'tc-cross');
      var tip = el('div', 'tc-tip');
      cross.hidden = tip.hidden = true;
      plot.appendChild(cross);
      plot.appendChild(tip);
      var dots = (data.dots || []).map(function (s) {
        var d = el('i', 'tc-dot ' + s.k);
        d.hidden = true;
        plot.appendChild(d);
        return d;
      });
      var current = -1;
      var show = function (i) {
        i = Math.max(0, Math.min(data.n - 1, i));
        current = i;
        var x = (i + 0.5) * 100 / data.n;
        cross.style.left = x + '%';
        cross.hidden = false;
        cols.forEach(function (c, j) { c.classList.toggle('hover', j === i); });
        (data.dots || []).forEach(function (s, k) {
          var y = s.y[i];
          dots[k].hidden = y === null;
          if (y !== null) { dots[k].style.left = x + '%'; dots[k].style.bottom = y + '%'; }
        });
        var t = data.tips[i] || { t: '', r: [] };
        tip.textContent = '';
        tip.appendChild(el('span', 't', t.t));
        t.r.forEach(function (r) {
          var row = el('div', 'row');
          var label = el('span', null, r[0]);
          if (r[2]) { label.insertBefore(el('i', 'k ' + r[2]), label.firstChild); }
          row.appendChild(label);
          row.appendChild(el('b', null, r[1]));
          tip.appendChild(row);
        });
        tip.style.left = x + '%';
        tip.classList.toggle('flip', x > 55);
        tip.hidden = false;
      };
      var hide = function () {
        current = -1;
        cross.hidden = tip.hidden = true;
        dots.forEach(function (d) { d.hidden = true; });
        cols.forEach(function (c) { c.classList.remove('hover'); });
      };
      var at = function (e) {
        var r = plot.getBoundingClientRect();
        return Math.floor((e.clientX - r.left) / r.width * data.n);
      };
      plot.addEventListener('pointermove', function (e) { show(at(e)); });
      plot.addEventListener('pointerdown', function (e) { show(at(e)); });
      plot.addEventListener('pointerleave', function (e) { if (e.pointerType === 'mouse') { hide(); } });
      plot.addEventListener('focus', function () { show(current >= 0 ? current : data.n - 1); });
      plot.addEventListener('blur', hide);
      plot.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
          e.preventDefault();
          show((current < 0 ? data.n - 1 : current) + (e.key === 'ArrowRight' ? 1 : -1));
        } else if (e.key === 'Escape') {
          hide();
        }
      });
    });

    // Mobile menu
    document.querySelectorAll('[data-toggle-nav]').forEach(function (b) {
      b.addEventListener('click', function () { document.body.classList.toggle('nav-open'); });
    });
    document.addEventListener('click', function (e) {
      if (document.body.classList.contains('nav-open') && !e.target.closest('#side') && !e.target.closest('[data-toggle-nav]')) {
        document.body.classList.remove('nav-open');
      }
    });

    // "Are you sure?" on dangerous buttons
    document.querySelectorAll('form[data-confirm]').forEach(function (f) {
      f.addEventListener('submit', function (e) {
        if (!window.confirm(f.getAttribute('data-confirm'))) { e.preventDefault(); }
      });
    });

    // Filters submit by themselves
    document.querySelectorAll('[data-autosubmit]').forEach(function (s) {
      s.addEventListener('change', function () { s.form.submit(); });
    });

    // Copy buttons
    document.querySelectorAll('[data-copy]').forEach(function (b) {
      b.addEventListener('click', function () {
        var t = b.getAttribute('data-copy');
        if (navigator.clipboard) { navigator.clipboard.writeText(t); }
        b.textContent = '✔ کپی شد';
      });
    });

    // Broken avatars fall back to the coloured initial
    document.addEventListener('error', function (e) {
      if (e.target.tagName === 'IMG' && e.target.parentNode && e.target.parentNode.classList.contains('avatar')) {
        e.target.remove();
      }
    }, true);

    // Flash fades out
    var flash = document.querySelector('[data-flash]');
    if (flash) { setTimeout(function () { flash.style.transition = 'opacity .6s'; flash.style.opacity = '0'; setTimeout(function () { flash.remove(); }, 700); }, 6000); }

    // A confirm on one submit button (forms with two actions)
    document.querySelectorAll('[data-confirm-click]').forEach(function (b) {
      b.addEventListener('click', function (e) {
        if (!window.confirm(b.getAttribute('data-confirm-click'))) { e.preventDefault(); }
      });
    });

    composePreview();
    broadcastProgress();
    settingsBar();
    liveCounters();
    liveFeed();
    liveTranscript();
  });

  // ---------------------------------------------------------------- broadcast: preview + progress
  function composePreview() {
    var form = document.querySelector('[data-compose]');
    var out = document.querySelector('[data-preview]');
    var btn = document.querySelector('[data-preview-button]');
    if (!form || !out) { return; }
    function update() {
      var t = form.querySelector('[data-preview-src]').value;
      out.textContent = t || 'متن پیام این‌جا دیده می‌شود…';
      var label = form.querySelector('[name=button_text]').value;
      btn.hidden = !label;
      btn.textContent = label;
    }
    form.addEventListener('input', update);
    update();
  }

  function broadcastProgress() {
    var list = document.querySelector('[data-broadcasts]');
    if (!list || !list.querySelector('[data-status="running"]')) { return; }
    var timer = setInterval(function () {
      getJSON('api.php?a=broadcast', function (d) {
        var running = false;
        (d.items || []).forEach(function (r) {
          var li = list.querySelector('[data-bc="' + r.id + '"]');
          if (!li) { return; }
          if (li.getAttribute('data-status') !== r.status) { window.location.reload(); return; }
          var done = r.sent + r.failed;
          li.querySelector('.meter span').style.width = (r.total ? Math.min(100, Math.round(100 * done / r.total)) : 100) + '%';
          li.querySelector('[data-bc-count]').textContent = num(r.sent) + ' رسید · ' + num(r.failed) + ' نرسید (ربات را بسته‌اند) · از ' + num(r.total);
          if (r.status === 'running') { running = true; }
        });
        if (!running) { clearInterval(timer); }
      });
    }, 2000);
  }

  // ---------------------------------------------------------------- settings: show "save" when something changed
  function settingsBar() {
    var form = document.querySelector('[data-settings]');
    if (!form) { return; }
    var bar = form.querySelector('[data-save-bar]');
    var dirty = false;
    form.addEventListener('input', function () { dirty = true; bar.classList.add('on'); });
    form.addEventListener('change', function () { dirty = true; bar.classList.add('on'); });
    form.addEventListener('reset', function () { dirty = false; bar.classList.remove('on'); });
    form.addEventListener('submit', function () { dirty = false; });
    window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
  }

  // ---------------------------------------------------------------- counters in the top bar
  function applyLive(live) {
    if (!live) { return; }
    Object.keys(live).forEach(function (k) {
      document.querySelectorAll('[data-live="' + k + '"]').forEach(function (e) {
        var v = live[k];
        e.textContent = typeof v === 'number' ? num(v) : (v || '—');
      });
    });
    if (live.perf) {
      document.querySelectorAll('[data-live="avg_ms"]').forEach(function (e) {
        e.textContent = live.perf.requests ? num(Math.round(live.perf.avg_ms)) + ' میلی‌ثانیه' : '—';
      });
    }
  }

  function getJSON(url, cb) {
    fetch(url, { credentials: 'same-origin', cache: 'no-store' })
      .then(function (r) { if (r.status === 401) { window.location.reload(); } return r.json(); })
      .then(cb)
      .catch(function () { /* try again next tick */ });
  }

  function liveCounters() {
    if (document.querySelector('[data-feed]')) { return; } // the live page polls more often anyway
    if (!document.querySelector('[data-live]')) { return; }
    setInterval(function () {
      if (document.hidden) { return; }
      getJSON('api.php?a=live', function (d) { applyLive(d.live); });
    }, 15000);
  }

  // ---------------------------------------------------------------- live message stream
  function liveFeed() {
    var feed = document.querySelector('[data-feed]');
    if (!feed) { return; }
    var after = parseInt(feed.getAttribute('data-after'), 10) || 0;
    var paused = false;
    var first = true;
    var showMedia = document.querySelector('[data-feed-media]');
    var pauseBtn = document.querySelector('[data-feed-pause]');
    if (pauseBtn) {
      pauseBtn.addEventListener('click', function () {
        paused = !paused;
        pauseBtn.textContent = paused ? '▶️ ادامه' : '⏸ توقف';
      });
    }
    function person(p) {
      var a = el('a', 'g-' + (p.g || ''), p.nick || ('#' + p.id));
      a.href = '?p=user&id=' + p.id;
      return a;
    }
    function row(m) {
      var li = el('li', (first ? '' : 'fresh ') + (m.status !== 'sent' ? 'filtered' : ''));
      li.appendChild(el('span', 'f-icon', m.icon));
      var body = el('div');
      var who = el('div', 'f-who');
      who.appendChild(person(m.from));
      who.appendChild(document.createTextNode(' ➜ '));
      who.appendChild(person(m.to));
      if (m.status === 'filtered') { who.appendChild(el('span', 'badge bad', ' 🚫 فیلتر شد')); }
      if (m.status === 'failed') { who.appendChild(el('span', 'badge bad', ' ❌ نرسید')); }
      body.appendChild(who);
      var text = el('a', 'f-text', m.text || (m.type === 'text' ? '' : '‏(' + m.type + ')'));
      text.href = '?p=chat&id=' + m.chat;
      body.appendChild(text);
      if (m.file && showMedia && showMedia.checked) {
        if (m.type === 'photo' || m.type === 'sticker') {
          var img = el('img', 'f-media');
          img.loading = 'lazy';
          img.src = 'media.php?m=' + m.id;
          body.appendChild(img);
        } else if (m.type === 'voice' || m.type === 'audio') {
          var au = el('audio');
          au.controls = true;
          au.preload = 'none';
          au.src = 'media.php?m=' + m.id;
          body.appendChild(au);
        }
      }
      li.appendChild(body);
      li.appendChild(el('span', 'f-time', m.time));
      return li;
    }
    function tick() {
      if (paused || document.hidden) { return; }
      getJSON('api.php?a=feed&after=' + after, function (d) {
        applyLive(d.live);
        var items = d.items || [];
        if (items.length) {
          var empty = feed.querySelector('[data-feed-empty]');
          if (empty) { empty.remove(); }
        }
        items.forEach(function (m) {
          feed.insertBefore(row(m), feed.firstChild);
          after = Math.max(after, m.id);
        });
        while (feed.children.length > 200) { feed.removeChild(feed.lastChild); }
        first = false;
      });
    }
    tick();
    setInterval(tick, 2500);
  }

  // ---------------------------------------------------------------- an open, active chat updates itself
  function liveTranscript() {
    var box = document.querySelector('[data-transcript]');
    if (!box) { return; }
    box.scrollTop = box.scrollHeight;
    if (box.getAttribute('data-active') !== '1') { return; }
    var after = parseInt(box.getAttribute('data-after'), 10) || 0;
    var id = box.getAttribute('data-chat');
    var timer = setInterval(function () {
      if (document.hidden) { return; }
      getJSON('api.php?a=chat&id=' + id + '&after=' + after, function (d) {
        (d.items || []).forEach(function (m) {
          var empty = box.querySelector('[data-transcript-empty]');
          if (empty) { empty.remove(); }
          var b = el('div', 'bubble ' + m.side + (m.status !== 'sent' ? ' blocked' : ''));
          if (m.file) {
            var src = 'media.php?m=' + m.id;
            if (m.type === 'photo' || m.type === 'sticker') {
              var img = el('img', 'media ' + m.type);
              img.src = src;
              b.appendChild(img);
            } else if (m.type === 'voice' || m.type === 'audio') {
              var au = el('audio');
              au.controls = true;
              au.src = src;
              b.appendChild(au);
            } else {
              var v = el('video');
              v.controls = true;
              v.src = src;
              b.appendChild(v);
            }
          }
          if (m.text) { b.appendChild(el('p', '', m.text)); }
          b.appendChild(el('span', 'when', m.time + (m.status === 'filtered' ? ' · 🚫 فیلتر شد' : '')));
          box.appendChild(b);
          after = Math.max(after, m.id);
          box.scrollTop = box.scrollHeight;
        });
        if (d.status === 'ended') {
          clearInterval(timer);
          box.appendChild(el('div', 'day-sep')).appendChild(el('span', '', '🏁 گفت‌وگو تمام شد'));
        }
      });
    }, 3000);
  }
})();
