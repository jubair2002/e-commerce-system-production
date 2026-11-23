#!/usr/bin/env node
/**
 * Parity CLI Runner
 * Parses CLI flags / environment variables (ROUTE, UNIT, ALL)
 * executes crawler and generates HTML parity report.
 */

const path = require('path');
const { TEST_ROUTES, crawlRoutes } = require('./crawler');
const { generateHtmlReport } = require('./reporter');

async function main() {
  const args = process.argv.slice(2);

  // Parse flags and environment variables
  let routeFilter = process.env.ROUTE || null;
  let unitFilter = process.env.UNIT || null;
  let allFlag = process.env.ALL || false;
  let phpBaseUrl = process.env.PHP_URL || 'http://localhost:8081';
  let rustBaseUrl = process.env.RUST_URL || 'http://localhost:8082';

  for (const arg of args) {
    if (arg.startsWith('--route=')) routeFilter = arg.split('=')[1];
    if (arg.startsWith('--unit=')) unitFilter = arg.split('=')[1];
    if (arg === '--all') allFlag = true;
    if (arg.startsWith('--php-url=')) phpBaseUrl = arg.split('=')[1];
    if (arg.startsWith('--rust-url=')) rustBaseUrl = arg.split('=')[1];
  }

  let selectedRoutes = TEST_ROUTES;
  if (routeFilter) {
    selectedRoutes = TEST_ROUTES.filter(
      (r) => r.name.toLowerCase() === routeFilter.toLowerCase()
    );
    console.log(`[CLI] Filtering by route: ${routeFilter} (${selectedRoutes.length} matched)`);
  } else if (unitFilter && unitFilter !== 'ALL') {
    selectedRoutes = TEST_ROUTES.filter(
      (r) => r.unit.toLowerCase() === unitFilter.toLowerCase()
    );
    console.log(`[CLI] Filtering by unit: ${unitFilter} (${selectedRoutes.length} matched)`);
  } else {
    console.log(`[CLI] Testing all routes (${selectedRoutes.length} routes)`);
  }

  if (selectedRoutes.length === 0) {
    console.error(`Error: No routes matched the filter criteria!`);
    process.exit(1);
  }

  console.log(`\n======================================================`);
  console.log(` Starting Parity Test Execution`);
  console.log(` PHP Base URL:  ${phpBaseUrl}`);
  console.log(` Rust Base URL: ${rustBaseUrl}`);
  console.log(` Total Routes:  ${selectedRoutes.length}`);
  console.log(`======================================================\n`);

  const results = await crawlRoutes(phpBaseUrl, rustBaseUrl, selectedRoutes);

  const reportPath = path.resolve(__dirname, '../reports/index.html');
  generateHtmlReport(results, [], reportPath);

  const passedCount = results.filter((r) => r.passed).length;
  const failedCount = results.length - passedCount;

  console.log(`\n======================================================`);
  console.log(` Parity Test Results: ${passedCount} Passed, ${failedCount} Failed`);
  console.log(` HTML Report generated at: ${reportPath}`);
  console.log(`======================================================\n`);

  if (failedCount > 0) {
    process.exit(1);
  }
}

main().catch((err) => {
  console.error('Fatal CLI Error:', err);
  process.exit(1);
});
