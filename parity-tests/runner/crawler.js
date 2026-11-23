/**
 * Parity Test Crawler & Route Recorder
 * Requests each route in the verified route map against both PHP and Rust backends,
 * records HTTP status, headers, and bodies, and invokes normalizers to verify parity.
 */

const { compareHtml, compareJson, compareText } = require('./normalizer');

// Authoritative Route Map for Parity Testing
const TEST_ROUTES = [
  { name: 'home', path: '/', unit: 'storefront', type: 'html' },
  { name: 'shop', path: '/shop', unit: 'storefront', type: 'html' },
  { name: 'category', path: '/category/men', unit: 'storefront', type: 'html' },
  { name: 'product_simple', path: '/product/classic-crewneck-t-shirt', unit: 'storefront', type: 'html' },
  { name: 'product_sale', path: '/product/slim-fit-stretch-denim-jeans', unit: 'storefront', type: 'html' },
  { name: 'product_oos', path: '/product/handcrafted-leather-bifold-wallet', unit: 'storefront', type: 'html' },
  { name: 'product_variable', path: '/product/premium-oxford-button-down-shirt', unit: 'storefront', type: 'html' },
  { name: 'cart_empty', path: '/cart', unit: 'storefront', type: 'html' },
  { name: 'checkout_empty', path: '/checkout', unit: 'storefront', type: 'html' },
  { name: 'track', path: '/track', unit: 'storefront', type: 'html' },
  { name: 'login', path: '/login', unit: 'storefront', type: 'html' },
  { name: 'register', path: '/register', unit: 'storefront', type: 'html' },
  { name: 'forgot_password', path: '/forgot-password', unit: 'storefront', type: 'html' },
  { name: 'contact', path: '/contact', unit: 'storefront', type: 'html' },
  { name: 'page_about', path: '/page/about-us', unit: 'storefront', type: 'html' },
  { name: 'sitemap_xml', path: '/sitemap.xml', unit: 'storefront', type: 'xml' },
  { name: 'metrics', path: '/metrics', unit: 'storefront', type: 'text' },
  { name: 'api_suggest', path: '/api/search/suggest?q=shirt', unit: 'api', type: 'json' },
  { name: 'api_cart_ajax_get', path: '/api/cart_ajax.php', unit: 'api', type: 'json' },
  { name: '404_fallback', path: '/non-existent-page-test-404', unit: 'storefront', type: 'html' },
];

/**
 * Fetch a URL with headers.
 */
async function fetchEndpoint(baseUrl, routePath, extraHeaders = {}) {
  const url = `${baseUrl}${routePath}`;
  try {
    const res = await fetch(url, {
      method: 'GET',
      headers: {
        'User-Agent': 'JinishPotroParityCrawler/1.0',
        ...extraHeaders,
      },
      redirect: 'manual', // do not auto-follow to check status code & Location header
    });

    const status = res.status;
    const location = res.headers.get('location') || null;
    const contentType = res.headers.get('content-type') || '';
    const body = await res.text();

    return {
      ok: true,
      status,
      location,
      contentType,
      body,
    };
  } catch (err) {
    return {
      ok: false,
      error: err.message,
    };
  }
}

/**
 * Run parity crawl across specified routes.
 */
async function crawlRoutes(phpBaseUrl, rustBaseUrl, routesToTest = null) {
  const routes = routesToTest || TEST_ROUTES;
  const results = [];

  for (const route of routes) {
    console.log(`[Crawler] Testing route: ${route.name} (${route.path})...`);

    const phpRes = await fetchEndpoint(phpBaseUrl, route.path);
    const rustRes = await fetchEndpoint(rustBaseUrl, route.path);

    if (!phpRes.ok || !rustRes.ok) {
      results.push({
        route,
        passed: false,
        error: `Fetch error - PHP: ${phpRes.error || 'OK'}, Rust: ${rustRes.error || 'OK'}`,
      });
      continue;
    }

    // 1. Check HTTP Status
    const statusMatches = phpRes.status === rustRes.status;

    // 2. Check Redirect Target (if 301/302)
    let redirectMatches = true;
    if (phpRes.status === 301 || phpRes.status === 302) {
      redirectMatches = phpRes.location === rustRes.location;
    }

    // 3. Compare Body
    let bodyResult = { matches: false, diffString: '' };
    if (route.type === 'html') {
      bodyResult = compareHtml(phpRes.body, rustRes.body);
    } else if (route.type === 'json') {
      bodyResult = compareJson(phpRes.body, rustRes.body);
    } else {
      bodyResult = compareText(phpRes.body, rustRes.body);
    }

    const passed = statusMatches && redirectMatches && bodyResult.matches;

    results.push({
      route,
      passed,
      php: {
        status: phpRes.status,
        location: phpRes.location,
        contentType: phpRes.contentType,
      },
      rust: {
        status: rustRes.status,
        location: rustRes.location,
        contentType: rustRes.contentType,
      },
      diff: bodyResult.diffString || null,
    });
  }

  return results;
}

module.exports = {
  TEST_ROUTES,
  fetchEndpoint,
  crawlRoutes,
};
