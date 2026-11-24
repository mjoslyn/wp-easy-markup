# Easy Markup

Comment on a WordPress page, or on any block in it, right on the page. Editors and admins comment as themselves; anyone you send a review link to can comment after giving their name. Comments are listed in the block editor's Comments sidebar and on an admin page, where you resolve, reopen or delete them.

## How it works

- **Comment mode on the page.** A Comment button (bottom right) turns comment mode on. The block under the pointer is outlined; click it, or Tab to it and press Enter, to comment on it. "Whole page" comments on the page itself. In comment mode a click comments instead of following a link.
- **Who can comment.** Anyone who can edit the page, and guests who opened a review link for it. Other visitors see nothing.
- **Review links.** A page link (made in the editor's Comments sidebar) works on its page; a whole-site link (a checkbox in the sidebar, or Markup > Review links; editors and admins) works on every published page. Each works for 7, 30 or 90 days or until turned off. Opening one sets a cookie and turns comment mode on; with a whole-site link the Comment button shows on every page the guest visits. A guest gives a name (remembered in their browser). One link can leave at most 30 comments an hour. Turning a link off ends its guests' access at once.
- **@mentions.** Staff can mention each other by handle: the part of their email address before the at sign (jsmith for jsmith@example.org). The person mentioned gets an email with the comment and links to it. Guests can't mention anyone; see guest comment emails below.
- **Comments sidebar** (block editor): the page's comments, open ones first. Show selects the block a comment is on; Resolve, Reopen and Delete; add a comment on the selected block or the whole page (a Selected block / Whole page choice switches between them, and selecting another block switches back); make, copy and turn off review links.
- **Comment button on every block's toolbar** (block editor): opens the Comments sidebar on that block with the cursor in the comment box.
- **Markup** (admin menu, with the number of open comments): every comment, filtered by page and by open or resolved, with View on page (scrolls to the block, outlines it and shows the comment) and Edit page. Its Review links section lists every link (page and whole site) with copy and Turn off, and makes whole-site links.
- **Admin bar** on a page: Markup (open count), Comment on this page, See comments.
- **Guest comment emails** (Settings > Easy Markup): turn on an email for every comment left through a review link and list who gets it (up to 20 addresses). Staff comments don't send these.
- **Colors** (Settings > Easy Markup): the accent (outlines, the active Comment button, quote bars, focus rings), the button color and the button text color, with WordPress's color picker. Clear a color to go back to the default. Applied as CSS custom properties (`--em-accent`, `--em-button`, `--em-button-text`), so a theme can also set them.

### Which block a comment is on

WordPress blocks have no saved IDs, so a block's ID is its place in the page content: b1, b2, ... counting every block, inner blocks too, in order. For people who can comment, the page content's blocks get `data-em-block` and `data-em-type` attributes as they render; the header, footer and template are not numbered. A comment also keeps the block's first words, so it can still find its block after edits move the numbers. The server checks that the block exists in the saved page before taking a comment.

## Requirements

WordPress 6.6 or later (block editor), PHP 8.1 or later.

## Install

Copy the folder to `wp-content/plugins/easy-markup/` (leave out `vendor/` and the Composer and PHPCS files) and activate **Easy Markup**. It creates two tables, `{prefix}easy_markup_comments` and `{prefix}easy_markup_links`.

## Development

The PHP follows the WordPress Coding Standards (WPCS 3):

```
composer install
composer lint       # phpcs
composer lint:fix   # phpcbf
```

The scripts in `assets/` are plain JavaScript using the editor's `wp.*` globals; there is no build step.

## Docs

`docs/` is a static site (page, captioned screencasts) published to GitHub Pages by `.github/workflows/pages.yml`. The screencasts were recorded on a WordPress Playground sample site.

## License

GPL-2.0-or-later.
