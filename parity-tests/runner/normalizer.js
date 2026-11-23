/**
 * Parity Test Normalizer
 * Normalizes HTML DOM, JSON, and Text/XML/CSV responses across PHP and Rust backends.
 * Strips dynamic non-functional tokens (CSRF tokens, checkout tokens, nonces, timestamps)
 * and verifies structural, semantic, and textual equivalence.
 */

const cheerio = require('cheerio');
const diff = require('diff');

/**
 * Normalizes HTML string to a canonical representation.
 * @param {string} rawHtml
 * @returns {string}
 */
function normalizeHtml(rawHtml) {
  if (!rawHtml || typeof rawHtml !== 'string') return '';

  const $ = cheerio.load(rawHtml, {
    xmlMode: false,
    decodeEntities: true,
  });

  // 1. Strip dynamic anti-CSRF and anti-replay session tokens
  $('input[name="_csrf"]').attr('value', '[CSRF_TOKEN]');
  $('input[name="csrf_token"]').attr('value', '[CSRF_TOKEN]');
  $('input[name="checkout_token"]').attr('value', '[CHECKOUT_TOKEN]');
  $('meta[name="csrf-token"]').attr('content', '[CSRF_TOKEN]');

  // 2. Strip script nonces and dynamic timestamps
  $('script[nonce]').removeAttr('nonce');
  $('link[rel="stylesheet"]').each((_, el) => {
    const href = $(el).attr('href');
    if (href) {
      $(el).attr('href', href.replace(/[?&]v=\d+/, ''));
    }
  });

  // 3. Remove runtime execution time comments (e.g. <!-- rendered in 0.012s -->)
  $('*')
    .contents()
    .filter(function () {
      return this.type === 'comment';
    })
    .remove();

  // 4. Canonicalize elements: sort attributes, trim whitespace between tags
  function walk(node) {
    if (node.type === 'tag') {
      // Sort element attributes alphabetically
      if (node.attribs) {
        const sorted = {};
        Object.keys(node.attribs)
          .sort()
          .forEach((key) => {
            sorted[key] = node.attribs[key].trim();
          });
        node.attribs = sorted;
      }

      // Recurse children
      if (node.children) {
        node.children.forEach(walk);
      }
    } else if (node.type === 'text') {
      // Collapse whitespace in text nodes
      node.data = node.data.replace(/\s+/g, ' ');
    }
  }

  $.root().children().each((_, el) => walk(el));

  let canonical = $.html();
  // Strip insignificant whitespace between closing and opening tags: >  < -> ><
  canonical = canonical.replace(/>\s+</g, '><').trim();

  return canonical;
}

/**
 * Compare two HTML payloads.
 */
function compareHtml(phpHtml, rustHtml) {
  const normPhp = normalizeHtml(phpHtml);
  const normRust = normalizeHtml(rustHtml);

  if (normPhp === normRust) {
    return { matches: true, diffs: [] };
  }

  const patch = diff.structuredPatch('php', 'rust', normPhp, normRust);
  return {
    matches: false,
    patch: patch,
    diffString: diff.createPatch('response.html', normPhp, normRust),
  };
}

/**
 * Deep normalize JSON object with sorted keys.
 */
function normalizeJson(obj) {
  if (obj === null || typeof obj !== 'object') {
    return obj;
  }
  if (Array.isArray(obj)) {
    return obj.map(normalizeJson);
  }
  const sorted = {};
  Object.keys(obj)
    .sort()
    .forEach((key) => {
      // Strip dynamic token fields
      if (key === '_csrf' || key === 'csrf_token' || key === 'checkout_token') {
        sorted[key] = '[TOKEN]';
      } else {
        sorted[key] = normalizeJson(obj[key]);
      }
    });
  return sorted;
}

/**
 * Compare two JSON responses.
 */
function compareJson(phpJson, rustJson) {
  const normPhp = normalizeJson(typeof phpJson === 'string' ? JSON.parse(phpJson) : phpJson);
  const normRust = normalizeJson(typeof rustJson === 'string' ? JSON.parse(rustJson) : rustJson);

  const phpStr = JSON.stringify(normPhp, null, 2);
  const rustStr = JSON.stringify(normRust, null, 2);

  if (phpStr === rustStr) {
    return { matches: true, diffs: [] };
  }

  return {
    matches: false,
    diffString: diff.createPatch('response.json', phpStr, rustStr),
  };
}

/**
 * Compare Text/CSV/XML payloads line by line.
 */
function compareText(phpText, rustText) {
  const cleanPhp = (phpText || '').replace(/\r\n/g, '\n').trim();
  const cleanRust = (rustText || '').replace(/\r\n/g, '\n').trim();

  if (cleanPhp === cleanRust) {
    return { matches: true, diffs: [] };
  }

  return {
    matches: false,
    diffString: diff.createPatch('content.txt', cleanPhp, cleanRust),
  };
}

module.exports = {
  normalizeHtml,
  compareHtml,
  normalizeJson,
  compareJson,
  compareText,
};
