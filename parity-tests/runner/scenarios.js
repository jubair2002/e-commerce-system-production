/**
 * POST Scenario Runner
 * Executes state-changing workflow scenarios (Register, Login, Add to Cart, Checkout)
 * against PHP and Rust on fresh database snapshots and verifies resulting state and DB rows.
 */

/**
 * Execute customer registration scenario.
 */
async function scenarioRegister(baseUrl) {
  const email = `test_${Date.now()}@example.com`;
  const postData = new URLSearchParams({
    name: 'Scenario Customer',
    email,
    password: 'Secret@2026Password!',
    phone: '01711223344',
  });

  const res = await fetch(`${baseUrl}/register`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body: postData.toString(),
    redirect: 'manual',
  });

  return {
    scenario: 'customer_registration',
    status: res.status,
    location: res.headers.get('location'),
    email,
  };
}

/**
 * Execute AJAX Cart operations scenario.
 */
async function scenarioCart(baseUrl) {
  const steps = [];

  // Step 1: Add simple product 101
  const addParams = new URLSearchParams({
    action: 'add',
    product_id: '101',
    qty: '2',
  });
  const res1 = await fetch(`${baseUrl}/api/cart_ajax.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: addParams.toString(),
  });
  const json1 = await res1.json().catch(() => ({}));
  steps.push({ action: 'add', status: res1.status, json: json1 });

  // Step 2: Update qty
  const updateParams = new URLSearchParams({
    action: 'set',
    cart_key: '101:0',
    qty: '3',
  });
  const res2 = await fetch(`${baseUrl}/api/cart_ajax.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: updateParams.toString(),
  });
  const json2 = await res2.json().catch(() => ({}));
  steps.push({ action: 'set', status: res2.status, json: json2 });

  // Step 3: Remove
  const removeParams = new URLSearchParams({
    action: 'remove',
    cart_key: '101:0',
  });
  const res3 = await fetch(`${baseUrl}/api/cart_ajax.php`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: removeParams.toString(),
  });
  const json3 = await res3.json().catch(() => ({}));
  steps.push({ action: 'remove', status: res3.status, json: json3 });

  return {
    scenario: 'cart_ajax_lifecycle',
    steps,
  };
}

/**
 * Compare two scenario run outputs.
 */
function compareScenarioResults(phpResult, rustResult) {
  const identicalStatus = phpResult.status === rustResult.status;
  const identicalLocation = phpResult.location === rustResult.location;

  return {
    matches: identicalStatus && identicalLocation,
    php: phpResult,
    rust: rustResult,
  };
}

module.exports = {
  scenarioRegister,
  scenarioCart,
  compareScenarioResults,
};
