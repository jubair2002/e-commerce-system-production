/**
 * Playwright Screenshot Visual Parity Runner
 * Captures pixel-perfect screenshots at 360px, 768px, and 1440px viewports
 * with CSS animations/transitions disabled, and computes pixel diff with pixelmatch.
 */

const fs = require('fs');
const path = require('path');
const { PNG } = require('pngjs');
const pixelmatch = require('pixelmatch');

const VIEWPORTS = [
  { name: 'mobile', width: 360, height: 640 },
  { name: 'tablet', width: 768, height: 1024 },
  { name: 'desktop', width: 1440, height: 900 },
];

const DISABLE_ANIMATIONS_CSS = `
*, *::before, *::after {
  transition: none !important;
  animation: none !important;
  caret-color: transparent !important;
}
`;

/**
 * Compare two PNG buffers using pixelmatch.
 */
function diffPngs(imgBuffer1, imgBuffer2, diffOutputPath, threshold = 0.0) {
  const img1 = PNG.sync.read(imgBuffer1);
  const img2 = PNG.sync.read(imgBuffer2);

  const { width, height } = img1;
  const diffPng = new PNG({ width, height });

  const numDiffPixels = pixelmatch(
    img1.data,
    img2.data,
    diffPng.data,
    width,
    height,
    { threshold }
  );

  if (diffOutputPath) {
    fs.mkdirSync(path.dirname(diffOutputPath), { recursive: true });
    fs.writeFileSync(diffOutputPath, PNG.sync.write(diffPng));
  }

  const totalPixels = width * height;
  const diffRatio = totalPixels > 0 ? numDiffPixels / totalPixels : 0;

  return {
    matches: numDiffPixels === 0,
    numDiffPixels,
    diffRatio,
  };
}

/**
 * Capture screenshots using Playwright across configured viewports.
 */
async function captureVisualParity(browser, routeName, phpUrl, rustUrl, outputDir, threshold = 0.0) {
  const results = [];

  for (const vp of VIEWPORTS) {
    const context = await browser.newContext({
      viewport: { width: vp.width, height: vp.height },
      deviceScaleFactor: 1,
    });

    const pagePhp = await context.newPage();
    const pageRust = await context.newPage();

    try {
      // 1. Load PHP Page
      await pagePhp.goto(phpUrl, { waitUntil: 'networkidle', timeout: 15000 });
      await pagePhp.addStyleTag({ content: DISABLE_ANIMATIONS_CSS });
      const phpScreenshot = await pagePhp.screenshot({ fullPage: true });

      // 2. Load Rust Page
      await pageRust.goto(rustUrl, { waitUntil: 'networkidle', timeout: 15000 });
      await pageRust.addStyleTag({ content: DISABLE_ANIMATIONS_CSS });
      const rustScreenshot = await pageRust.screenshot({ fullPage: true });

      // 3. Diff screenshots
      const diffPath = path.join(outputDir, `${routeName}_${vp.name}_diff.png`);
      const phpPath = path.join(outputDir, `${routeName}_${vp.name}_php.png`);
      const rustPath = path.join(outputDir, `${routeName}_${vp.name}_rust.png`);

      fs.mkdirSync(outputDir, { recursive: true });
      fs.writeFileSync(phpPath, phpScreenshot);
      fs.writeFileSync(rustPath, rustScreenshot);

      const diffRes = diffPngs(phpScreenshot, rustScreenshot, diffPath, threshold);

      results.push({
        viewport: vp.name,
        width: vp.width,
        height: vp.height,
        matches: diffRes.matches,
        diffPixels: diffRes.numDiffPixels,
        diffRatio: diffRes.diffRatio,
        phpScreenshot: phpPath,
        rustScreenshot: rustPath,
        diffScreenshot: diffPath,
      });
    } catch (err) {
      results.push({
        viewport: vp.name,
        error: err.message,
        matches: false,
      });
    } finally {
      await context.close();
    }
  }

  return results;
}

module.exports = {
  VIEWPORTS,
  diffPngs,
  captureVisualParity,
};
