// Render tools/share-card.html to docs/share-card.png (1200 x 630).
// Usage: node tools/share-card.mjs   (needs Playwright: npx playwright install chromium)
import { chromium } from 'playwright';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { dirname, resolve } from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1200, height: 630 } });
await page.goto(pathToFileURL(resolve(here, 'share-card.html')).href);
await page.evaluate(() => document.fonts.ready);
await page.screenshot({ path: resolve(here, '../docs/share-card.png') });
await browser.close();
console.log('docs/share-card.png');
