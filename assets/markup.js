// Easy Markup: comments on a page's blocks, on the page itself (inc/frontend.php adds this script for
// people who may comment, and inc/blocks.php marks the page content's blocks with data-em-block and
// data-em-type). A Comment button turns comment mode on: the block under the pointer is outlined, and a
// click on it (or Enter on a focused one) opens a small form. "Whole page" comments on the page itself.
// A guest gives a name (kept in this browser); staff comment as themselves and can @mention each other.
// ?markup-show=<id> (staff) scrolls to a comment's block and shows the comment.
(() => {
  const cfg = window.easyMarkup;
  if (!cfg) return;
  const NAME_KEY = 'easy-markup-name';
  const label = (type) => (type && cfg.labels[type]) || (type ? type.split('/').pop().replace(/-/g, ' ').replace(/^./, (c) => c.toUpperCase()) : 'Whole page');
  const words = (el) => (el.textContent || '').replace(/\s+/g, ' ').trim().slice(0, 120);
  const readName = () => {
    try {
      return localStorage.getItem(NAME_KEY) || '';
    } catch {
      return '';
    }
  };
  const keepName = (v) => {
    try {
      localStorage.setItem(NAME_KEY, v);
    } catch {}
  };
  const el = (tag, props = {}, ...kids) => {
    const n = Object.assign(document.createElement(tag), props);
    n.append(...kids);
    return n;
  };

  // Page snapshots (Markup > Settings): html2canvas loads the first time a comment box opens.
  let h2cLoading = null;
  const loadH2C = () => {
    if (window.html2canvas) return Promise.resolve(window.html2canvas);
    h2cLoading ??= new Promise((done) => {
      const s = document.createElement('script');
      s.src = cfg.h2c;
      s.onload = () => done(window.html2canvas || null);
      s.onerror = () => done(null);
      document.head.append(s);
    });
    return h2cLoading;
  };
  // A JPEG data: URL of the whole page as it looks now, with the commented block still outlined and
  // without the comment bar or box; scaled to fit 1600 x 8000. Null if it can't be made (for example
  // an image from another site the browser won't let a canvas read).
  const capture = async () => {
    try {
      const h2c = await loadH2C();
      if (!h2c) return null;
      // Where the commented block is on the page (document coordinates), for the frame.
      const picked = document.querySelector('.em-picked');
      const box = picked?.getBoundingClientRect();
      const frame = box && {
        x: box.left + window.scrollX - 6,
        y: box.top + window.scrollY - 6,
        w: box.width + 12,
        h: box.height + 12,
        accent: getComputedStyle(document.documentElement).getPropertyValue('--em-accent').trim() || '#2271b1',
      };
      const canvas = await h2c(document.body, {
        backgroundColor: getComputedStyle(document.body).backgroundColor || '#ffffff',
        scale: 1,
        useCORS: true,
        logging: false,
        // The admin bar is pinned to the screen and isn't page content; it would land mid-picture.
        ignoreElements: (n) => n.id === 'wpadminbar' || n.classList?.contains('em-bar') || n.classList?.contains('em-panel') || n.classList?.contains('em-card'),
        // html2canvas doesn't draw outlines, so the captured copy gets a frame drawn over the commented
        // block where it sits on the page; the page's own layout is left alone.
        onclone: (doc) => {
          doc.querySelectorAll('.em-hover').forEach((n) => n.classList.remove('em-hover'));
          if (!frame) return;
          const f = doc.createElement('div');
          f.style.cssText = `position:absolute;left:${frame.x}px;top:${frame.y}px;width:${frame.w}px;height:${frame.h}px;border:4px solid ${frame.accent};border-radius:6px;box-sizing:border-box;z-index:2147483647;pointer-events:none`;
          doc.body.appendChild(f);
        },
      });
      const k = Math.min(1, 1600 / canvas.width, 8000 / canvas.height);
      let out = canvas;
      if (k < 1) {
        out = document.createElement('canvas');
        out.width = Math.round(canvas.width * k);
        out.height = Math.round(canvas.height * k);
        out.getContext('2d').drawImage(canvas, 0, 0, out.width, out.height);
      }
      return out.toDataURL('image/jpeg', 0.72);
    } catch {
      return null;
    }
  };
  const within = (promise, ms) => Promise.race([promise, new Promise((done) => setTimeout(() => done(null), ms))]);

  const bar = el('div', { className: 'em-bar' });
  const button = el('button', { type: 'button', className: 'em-button', textContent: 'Comment' });
  button.setAttribute('aria-pressed', 'false');
  const whole = el('button', { type: 'button', className: 'em-button em-button--quiet', textContent: 'Whole page', hidden: true });
  const status = el('div', { className: 'em-sr' });
  status.setAttribute('role', 'status');
  bar.append(whole, button);
  document.body.append(bar, status);

  let on = false;
  let hovered = null;
  let panel = null;
  const blocks = () => [...document.querySelectorAll('[data-em-block]')];

  const closePanel = () => {
    document.querySelector('.em-picked')?.classList.remove('em-picked');
    panel?.remove();
    panel = null;
  };

  const setMode = (v) => {
    on = v;
    button.setAttribute('aria-pressed', String(v));
    button.textContent = v ? 'Done commenting' : 'Comment';
    whole.hidden = !v;
    document.documentElement.classList.toggle('em-on', v);
    for (const b of blocks()) {
      if (v && !b.hasAttribute('tabindex')) {
        b.setAttribute('tabindex', '0');
        b.dataset.emTab = '1';
      } else if (!v && b.dataset.emTab) {
        b.removeAttribute('tabindex');
        delete b.dataset.emTab;
      }
    }
    if (!v) {
      hovered?.classList.remove('em-hover');
      hovered = null;
      closePanel();
    }
    status.textContent = v ? 'Comment mode on: choose a block to comment on it.' : 'Comment mode off.';
  };

  // @handle chips under the box: a click puts the handle in. The server emails the people mentioned.
  const mentionChips = (box) => {
    if (!cfg.people?.length) return null;
    const wrap = el('p', { className: 'em-hint' }, 'Mention someone (they get an email): ');
    for (const p of cfg.people) {
      const chip = el('button', { type: 'button', className: 'em-chip', textContent: '@' + p.handle, title: p.name });
      chip.addEventListener('click', () => {
        const at = box.selectionStart ?? box.value.length;
        const before = box.value.slice(0, at);
        const pad = before && !/\s$/.test(before) ? ' ' : '';
        box.value = before + pad + '@' + p.handle + ' ' + box.value.slice(at);
        box.focus();
      });
      wrap.append(chip, ' ');
    }
    return wrap;
  };

  const open = (block) => {
    closePanel();
    block?.classList.add('em-picked');
    const type = block?.dataset.emType || '';
    const quote = block ? words(block) : '';
    // Captured after the box opens and the block is outlined, so the picture shows which block it is.
    const snapshot = cfg.snapshots ? new Promise((done) => requestAnimationFrame(() => done(capture()))) : Promise.resolve(null);
    panel = el('form', { className: 'em-panel' });
    panel.setAttribute('aria-label', block ? 'Comment on this block' : 'Comment on the whole page');
    panel.append(el('p', { className: 'em-quote', textContent: label(type) + (quote ? ': ' + quote : '') }));
    let name = null;
    if (cfg.guest) {
      name = el('input', { required: true, maxLength: 80, autocomplete: 'name', value: readName() });
      panel.append(el('label', {}, 'Your name', name));
    }
    const body = el('textarea', { required: true, maxLength: 2000 });
    panel.append(el('label', {}, 'Comment', body));
    const chips = cfg.guest ? null : mentionChips(body);
    if (chips) panel.append(chips);
    if (cfg.snapshots) panel.append(el('p', { className: 'em-hint', textContent: 'A picture of the page is saved with your comment.' }));
    const error = el('p', { className: 'em-error' });
    error.setAttribute('role', 'alert');
    const cancel = el('button', { type: 'button', textContent: 'Cancel' });
    const send = el('button', { type: 'submit', className: 'em-send', textContent: 'Send' });
    panel.append(error, el('div', { className: 'em-row' }, cancel, send));
    document.body.append(panel);
    (name && !name.value ? name : body).focus();
    const back = () => (closePanel(), (block || whole).focus());
    cancel.addEventListener('click', back);
    panel.addEventListener('keydown', (e) => e.key === 'Escape' && back());
    panel.addEventListener('submit', async (e) => {
      e.preventDefault();
      send.disabled = true;
      error.textContent = '';
      if (name) keepName(name.value.trim());
      const shot = await within(snapshot, 8000);
      const headers = { 'Content-Type': 'application/json' };
      if (cfg.nonce) headers['X-WP-Nonce'] = cfg.nonce;
      const res = await fetch(cfg.api + 'comments', {
        method: 'POST',
        credentials: 'same-origin',
        headers,
        body: JSON.stringify({ post: cfg.post, block: block?.dataset.emBlock || '', quote, body: body.value, name: name ? name.value : undefined, snapshot: shot || undefined }),
      }).catch(() => null);
      const out = res ? await res.json().catch(() => null) : null;
      send.disabled = false;
      if (!res?.ok || !out?.ok) {
        error.textContent = out?.message || 'Your comment couldn’t be sent. Try again.';
        return;
      }
      closePanel();
      status.textContent = 'Comment sent. Thank you.';
      button.textContent = 'Comment sent';
      setTimeout(() => on && (button.textContent = 'Done commenting'), 2000);
      (block || whole).focus();
    });
  };

  button.addEventListener('click', () => setMode(!on));
  whole.addEventListener('click', () => open(null));

  // The innermost block under the pointer: a comment goes on the block, not the section around it,
  // unless the section itself is pointed at.
  const blockAt = (t) => (t instanceof Element ? t.closest('[data-em-block]') : null);
  document.addEventListener('mouseover', (e) => {
    if (!on) return;
    const b = blockAt(e.target);
    if (b === hovered) return;
    hovered?.classList.remove('em-hover');
    hovered = b;
    b?.classList.add('em-hover');
  });
  // In comment mode a click comments instead of following a link or pressing a button on the page.
  document.addEventListener(
    'click',
    (e) => {
      if (!on || bar.contains(e.target) || panel?.contains(e.target)) return;
      const b = blockAt(e.target);
      if (!b) return;
      e.preventDefault();
      e.stopPropagation();
      open(b);
    },
    true,
  );
  document.addEventListener('keydown', (e) => {
    if (!on || e.key !== 'Enter' || panel?.contains(e.target)) return;
    const b = e.target instanceof Element && e.target.matches('[data-em-block]') ? e.target : null;
    if (b) {
      e.preventDefault();
      open(b);
    }
  });

  // Showing one comment: its block by number, checked by its words (edits can move the numbers);
  // if the words don't match, the block whose words do.
  const findBlock = (c) => {
    if (!c.block) return null;
    const start = (c.quote || '').slice(0, 40);
    const byId = document.querySelector(`[data-em-block="${CSS.escape(c.block)}"]`);
    if (!start || (byId && words(byId).startsWith(start))) return byId;
    return blocks().find((b) => words(b).startsWith(start)) || byId;
  };
  const showComment = (c) => {
    const b = findBlock(c);
    const card = el('div', { className: 'em-panel em-card' });
    card.setAttribute('role', 'dialog');
    card.setAttribute('aria-label', 'Comment');
    card.append(
      el('p', { className: 'em-quote', textContent: c.where }),
      el('p', { className: 'em-body', textContent: c.body }),
      el('p', { className: 'em-meta', textContent: `${c.author}${c.guest ? ' (review link)' : ''}, ${c.created}${c.status === 'resolved' ? `, resolved ${c.resolved}` : ''}` }),
    );
    if (c.snapshot) {
      const a = el('a', { href: c.snapshot, target: '_blank', rel: 'noopener', className: 'em-shot' }, el('img', { src: c.snapshot, alt: 'The page when the comment was written' }));
      card.append(el('p', { className: 'em-hint', textContent: 'As it looked then:' }), a);
    }
    if (c.block && !b) card.append(el('p', { className: 'em-error', textContent: 'That block is no longer on the page.' }));
    const close = el('button', { type: 'button', textContent: 'Close' });
    close.addEventListener('click', () => (card.remove(), b?.classList.remove('em-picked')));
    card.append(el('div', { className: 'em-row' }, close));
    document.body.append(card);
    if (b) {
      b.classList.add('em-picked');
      b.scrollIntoView({ block: 'center', behavior: 'smooth' });
    }
  };

  if (cfg.show) showComment(cfg.show);
  else if (cfg.start) setMode(true);
})();
