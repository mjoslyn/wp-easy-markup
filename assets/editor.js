// Easy Markup: the block editor's Comments sidebar (inc/editor.php). Plain script, no build step.
// A comment names its block by its place in the saved content (b1, b2, ... counting every block, inner
// blocks too, in order), the same numbers the page uses. Here that is the block's place in the editor's
// block tree, checked against the comment's words, since unsaved edits can move the numbers.
(() => {
  const { registerPlugin } = wp.plugins;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editor;
  const { createElement: h, Fragment, useState, useEffect } = wp.element;
  const { PanelBody, Button, TextareaControl, TextControl, SelectControl, CheckboxControl, RadioControl, Notice, Spinner, ToolbarGroup, ToolbarButton } = wp.components;
  const { BlockControls } = wp.blockEditor;
  const { addFilter } = wp.hooks;
  const { createHigherOrderComponent } = wp.compose;
  const SIDEBAR = 'easy-markup/easy-markup';
  const COMPOSE_EVENT = 'easy-markup:compose';
  const { useSelect, useDispatch } = wp.data;
  const apiFetch = wp.apiFetch;
  const cfg = window.easyMarkupEditor || { people: [], labels: {} };

  const label = (type) => (type && cfg.labels[type]) || (type ? type.split('/').pop().replace(/-/g, ' ').replace(/^./, (c) => c.toUpperCase()) : 'Whole page');

  /** Every block in the editor, in order (inner blocks after their parent), as the page numbers them. */
  const flatten = (blocks, out = []) => {
    for (const b of blocks) {
      out.push(b);
      if (b.innerBlocks?.length) flatten(b.innerBlocks, out);
    }
    return out;
  };
  const textOf = (b) => {
    const a = b.attributes || {};
    const raw = [a.content, a.value, a.text, a.citation, a.caption, a.alt].map((v) => (v == null ? '' : String(v))).join(' ');
    const div = document.createElement('div');
    // Line breaks and block tags become spaces, so words on either side don't run together.
    div.innerHTML = raw.replace(/<br\s*\/?>|<\/(p|li|h\d|div)>/gi, ' ');
    return (div.textContent || '').replace(/\s+/g, ' ').trim();
  };

  function CommentsSidebar() {
    const postId = useSelect((s) => s('core/editor').getCurrentPostId(), []);
    const status = useSelect((s) => s('core/editor').getEditedPostAttribute('status'), []);
    const dirty = useSelect((s) => s('core/editor').isEditedPostDirty(), []);
    const blocks = useSelect((s) => s('core/block-editor').getBlocks(), []);
    const selectedId = useSelect((s) => s('core/block-editor').getSelectedBlockClientId(), []);
    const { selectBlock } = useDispatch('core/block-editor');

    const [rows, setRows] = useState(null);
    const [links, setLinks] = useState(null);
    const [showResolved, setShowResolved] = useState(false);
    const [text, setText] = useState('');
    // What a new comment goes on: the selected block, or the whole page.
    const [target, setTarget] = useState('block');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [linkLabel, setLinkLabel] = useState('');
    const [linkDays, setLinkDays] = useState('30');
    const [linkSite, setLinkSite] = useState(false);
    const [copied, setCopied] = useState(0);

    const flat = flatten(blocks || []);
    const selIndex = selectedId ? flat.findIndex((b) => b.clientId === selectedId) : -1;
    const picked = selIndex >= 0 ? { id: 'b' + (selIndex + 1), type: flat[selIndex].name, words: textOf(flat[selIndex]).slice(0, 120) } : null;
    // Selecting a different block makes it the target again.
    useEffect(() => {
      if (selectedId) setTarget('block');
    }, [selectedId]);
    const selected = picked && target === 'block' ? picked : null;

    // The block toolbar's Comment button (below) opens this sidebar on its block and asks for the box.
    useEffect(() => {
      const compose = () => {
        setTarget('block');
        setTimeout(() => document.querySelector('.easy-markup-compose textarea')?.focus(), 150);
      };
      document.addEventListener(COMPOSE_EVENT, compose);
      return () => document.removeEventListener(COMPOSE_EVENT, compose);
    }, []);

    const load = () => {
      if (!postId) return;
      apiFetch({ path: `/easy-markup/v1/comments?post=${postId}` }).then(setRows, (e) => setError(e.message || 'Couldn’t load the comments.'));
      apiFetch({ path: `/easy-markup/v1/links?post=${postId}` }).then(setLinks, () => setLinks([]));
    };
    useEffect(load, [postId]);

    const act = async (fn) => {
      setBusy(true);
      setError('');
      try {
        await fn();
        load();
        return true;
      } catch (e) {
        setError(e.message || 'Couldn’t do that. Try again.');
        return false;
      } finally {
        setBusy(false);
      }
    };

    // The editor block a comment is on: by number, checked by its words; else the block whose words match.
    const findBlock = (r) => {
      if (!r.block) return null;
      const start = (r.quote || '').slice(0, 30);
      const byNum = flat[parseInt(r.block.slice(1), 10) - 1];
      if (byNum && byNum.name === r.block_type && (!start || textOf(byNum).startsWith(start) || !textOf(byNum))) return byNum;
      return flat.find((b) => b.name === r.block_type && start && textOf(b).startsWith(start)) || null;
    };
    const show = (r) => {
      const b = findBlock(r);
      if (!b) return setError('That block is no longer in the page.');
      setError('');
      selectBlock(b.clientId);
      document.querySelector(`[data-block="${b.clientId}"]`)?.scrollIntoView({ block: 'center', behavior: 'smooth' });
    };

    const add = (e) => {
      e.preventDefault();
      act(() => apiFetch({ path: '/easy-markup/v1/comments', method: 'POST', data: { post: postId, block: selected?.id || '', quote: selected?.words || '', body: text } })).then((ok) => ok && setText(''));
    };

    const all = rows || [];
    const open = all.filter((r) => r.status === 'open');
    const resolved = all.filter((r) => r.status !== 'open');
    const shown = showResolved ? [...open, ...resolved] : open;

    const insertHandle = (handle) => setText((t) => t + (t && !/\s$/.test(t) ? ' ' : '') + '@' + handle + ' ');

    return h(
      Fragment,
      null,
      h(PluginSidebarMoreMenuItem, { target: 'easy-markup' }, 'Comments'),
      h(
        PluginSidebar,
        { name: 'easy-markup', title: 'Comments', icon: 'admin-comments' },
        h(
          PanelBody,
          { title: 'Add a comment', initialOpen: true },
          h(
            'form',
            { onSubmit: add },
            picked &&
              h(RadioControl, {
                label: 'Comment on',
                selected: target,
                options: [
                  { label: `Selected ${label(picked.type).toLowerCase()}`, value: 'block' },
                  { label: 'Whole page', value: 'page' },
                ],
                onChange: setTarget,
              }),
            h(TextareaControl, {
              className: 'easy-markup-compose',
              label: selected ? `On the selected ${label(selected.type).toLowerCase()}` : 'On the whole page',
              help: picked ? 'Save the page first if you added or moved blocks.' : 'Select a block to comment on it, or use Comment on its toolbar. Save the page first if you added or moved blocks.',
              value: text,
              onChange: setText,
              rows: 4,
              __nextHasNoMarginBottom: true,
            }),
            dirty && selected && h(Notice, { status: 'warning', isDismissible: false }, 'The page has unsaved changes, so this block may not be where the saved page has it. Save first.'),
            cfg.people.length > 0 &&
              h(
                'p',
                { style: { fontSize: '12px', color: '#50575e', margin: '8px 0' } },
                'Mention (they get an email): ',
                cfg.people.map((p) => h(Button, { key: p.handle, variant: 'link', onClick: () => insertHandle(p.handle), label: p.name, style: { marginRight: '6px' } }, '@' + p.handle)),
              ),
            h(Button, { variant: 'primary', type: 'submit', disabled: busy || !text.trim(), isBusy: busy }, 'Add comment'),
          ),
        ),
        h(
          PanelBody,
          { title: rows ? `Comments (${open.length} open)` : 'Comments', initialOpen: true },
          error && h(Notice, { status: 'error', isDismissible: true, onRemove: () => setError('') }, error),
          !rows && h(Spinner),
          resolved.length > 0 && h(CheckboxControl, { label: `Show resolved (${resolved.length})`, checked: showResolved, onChange: setShowResolved, __nextHasNoMarginBottom: true }),
          rows && !shown.length && h('p', null, 'No open comments. Editors can comment here or on the page; guests can comment with a review link (below).'),
          shown.map((r) =>
            h(
              'div',
              { key: r.id, style: { borderTop: '1px solid #e0e0e0', padding: '10px 0', opacity: r.status === 'open' ? 1 : 0.6 } },
              h('div', { style: { fontSize: '12px', color: '#50575e' } }, h('strong', null, label(r.block_type)), r.quote ? ': ' + r.quote.slice(0, 80) : ''),
              h('p', { style: { margin: '4px 0', whiteSpace: 'pre-wrap' } }, r.body),
              r.snapshot && h('a', { href: r.snapshot, target: '_blank', rel: 'noopener' }, h('img', { src: r.snapshot, alt: 'Snapshot of the page', loading: 'lazy', style: { display: 'block', maxWidth: '100%', maxHeight: '140px', objectFit: 'contain', border: '1px solid #dcdcde', borderRadius: '4px', margin: '4px 0' } })),
              h('div', { style: { fontSize: '12px', color: '#50575e' } }, `${r.author}${r.guest ? ' (review link)' : ''}, ${r.created}${r.status === 'resolved' ? `, resolved ${r.resolved}${r.resolved_by ? ' by ' + r.resolved_by : ''}` : ''}`),
              h(
                'div',
                { style: { display: 'flex', gap: '12px', marginTop: '4px' } },
                r.block && h(Button, { variant: 'link', onClick: () => show(r) }, 'Show'),
                h(Button, { variant: 'link', href: r.show_url, target: '_blank' }, 'On page'),
                h(Button, { variant: 'link', disabled: busy, onClick: () => act(() => apiFetch({ path: `/easy-markup/v1/comments/${r.id}`, method: 'POST', data: { action: r.status === 'open' ? 'resolve' : 'reopen' } })) }, r.status === 'open' ? 'Resolve' : 'Reopen'),
                h(Button, { variant: 'link', isDestructive: true, disabled: busy, onClick: () => window.confirm(`Delete ${r.author}’s comment for good? To keep it but mark it done, use Resolve.`) && act(() => apiFetch({ path: `/easy-markup/v1/comments/${r.id}`, method: 'DELETE' })) }, 'Delete'),
              ),
            ),
          ),
          rows && h(Button, { variant: 'link', onClick: load }, 'Refresh'),
        ),
        h(
          PanelBody,
          { title: 'Review links', initialOpen: false },
          h('p', { style: { fontSize: '12px', color: '#50575e' } }, 'Anyone with a review link can comment after giving their name: on this page, or on every page with a whole-site link. Turn a link off to stop it working.'),
          status !== 'publish'
            ? h(Notice, { status: 'info', isDismissible: false }, 'Publish the page to make a review link.')
            : h(
                'form',
                {
                  onSubmit: (e) => {
                    e.preventDefault();
                    act(() => apiFetch({ path: '/easy-markup/v1/links', method: 'POST', data: { post: postId, label: linkLabel, days: parseInt(linkDays, 10) || 0, site: linkSite } })).then((ok) => ok && (setLinkLabel(''), setLinkSite(false)));
                  },
                },
                h(TextControl, { label: 'Who it’s for (optional)', value: linkLabel, onChange: setLinkLabel, __nextHasNoMarginBottom: true }),
                h(SelectControl, {
                  label: 'Works for',
                  value: linkDays,
                  options: [
                    { label: '7 days', value: '7' },
                    { label: '30 days', value: '30' },
                    { label: '90 days', value: '90' },
                    { label: 'Until turned off', value: '0' },
                  ],
                  onChange: setLinkDays,
                  __nextHasNoMarginBottom: true,
                }),
                cfg.canSite && h(CheckboxControl, { label: 'Works on every page of the site', checked: linkSite, onChange: setLinkSite, __nextHasNoMarginBottom: true }),
                h(Button, { variant: 'secondary', type: 'submit', disabled: busy, style: { marginTop: '8px' } }, linkSite ? 'Make a whole-site link' : 'Make a review link'),
              ),
          (links || []).map((l) =>
            h(
              'div',
              { key: l.id, style: { borderTop: '1px solid #e0e0e0', padding: '8px 0', fontSize: '12px', opacity: l.live ? 1 : 0.6 } },
              h('div', null, h('strong', null, l.label || 'Review link'), l.site ? ' · whole site' : '', ` (${l.comments} ${l.comments === 1 ? 'comment' : 'comments'})`),
              h('div', { style: { color: '#50575e' } }, l.live ? `Made ${l.created}${l.expires ? `, works until ${l.expires}` : ''}` : l.revoked ? `Turned off ${l.revoked}` : `Expired ${l.expires}`),
              l.live &&
                h(
                  'div',
                  { style: { display: 'flex', gap: '12px', marginTop: '4px' } },
                  h(Button, { variant: 'link', onClick: () => navigator.clipboard?.writeText(l.url).then(() => (setCopied(l.id), setTimeout(() => setCopied(0), 2000))) }, copied === l.id ? 'Copied' : 'Copy link'),
                  h(Button, { variant: 'link', isDestructive: true, disabled: busy, onClick: () => act(() => apiFetch({ path: `/easy-markup/v1/links/${l.id}`, method: 'DELETE' })) }, 'Turn off'),
                ),
            ),
          ),
        ),
      ),
    );
  }

  registerPlugin('easy-markup', { render: CommentsSidebar, icon: 'admin-comments' });

  // A Comment button on every block's toolbar: selects the block, opens the Comments sidebar and puts
  // the cursor in the comment box.
  const withCommentButton = createHigherOrderComponent(
    (BlockEdit) => (props) => {
      const { enableComplementaryArea } = useDispatch('core/interface');
      const { selectBlock } = useDispatch('core/block-editor');
      if (!props.isSelected) return h(BlockEdit, props);
      return h(
        Fragment,
        null,
        h(BlockEdit, props),
        h(
          BlockControls,
          { group: 'other' },
          h(
            ToolbarGroup,
            null,
            h(ToolbarButton, {
              icon: 'admin-comments',
              label: 'Comment',
              onClick: () => {
                selectBlock(props.clientId);
                enableComplementaryArea('core', SIDEBAR);
                document.dispatchEvent(new CustomEvent(COMPOSE_EVENT));
              },
            }),
          ),
        ),
      );
    },
    'withEasyMarkupCommentButton',
  );
  addFilter('editor.BlockEdit', 'easy-markup/comment-button', withCommentButton);
})();
