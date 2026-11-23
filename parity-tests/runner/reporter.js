/**
 * Parity Test HTML Report Generator
 * Assembles a clean, interactive HTML dashboard visualizing crawl, DOM, and visual diffs.
 */

const fs = require('fs');
const path = require('path');

function generateHtmlReport(crawlResults, visualResults = [], outputFilePath) {
  const total = crawlResults.length;
  const passed = crawlResults.filter((r) => r.passed).length;
  const failed = total - passed;
  const score = total > 0 ? Math.round((passed / total) * 100) : 0;
  const timestamp = new Date().toISOString();

  const rows = crawlResults
    .map((r, idx) => {
      const badgeClass = r.passed ? 'bg-success' : 'bg-danger';
      const statusText = r.passed ? 'PASS' : 'FAIL';
      const diffContent = r.diff
        ? `<pre class="diff-block"><code>${escapeHtml(r.diff)}</code></pre>`
        : '<span class="text-muted">Identical</span>';

      return `
      <tr>
        <td><strong>${idx + 1}</strong></td>
        <td><code>${escapeHtml(r.route.name)}</code></td>
        <td><code>${escapeHtml(r.route.path)}</code></td>
        <td><span class="badge bg-secondary">${escapeHtml(r.route.unit)}</span></td>
        <td>PHP: ${r.php ? r.php.status : 'N/A'} | Rust: ${r.rust ? r.rust.status : 'N/A'}</td>
        <td><span class="badge ${badgeClass}">${statusText}</span></td>
        <td>
          <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#diff-${idx}">
            Toggle Diff
          </button>
        </td>
      </tr>
      <tr class="collapse" id="diff-${idx}">
        <td colspan="7">
          <div class="card card-body bg-light">
            <h6>DOM / Content Diff:</h6>
            ${diffContent}
          </div>
        </td>
      </tr>
    `;
    })
    .join('\n');

  const html = `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <title>Jinish Potro - Parity Test Verification Report</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <style>
    body { background-color: #f8fafc; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
    .header-box { background: #0f172a; color: white; border-radius: 8px; padding: 2rem; margin-bottom: 2rem; }
    .diff-block { background: #1e293b; color: #f8fafc; padding: 1rem; border-radius: 6px; max-height: 400px; overflow-y: auto; font-size: 0.85rem; }
    .score-badge { font-size: 2.2rem; font-weight: 700; }
  </style>
</head>
<body class="py-4">
<div class="container">
  <div class="header-box shadow-sm">
    <div class="d-flex justify-content-between align-items-center">
      <div>
        <h2 class="mb-1">PHP &harr; Rust Migration Parity Report</h2>
        <p class="text-secondary mb-0">Generated: ${timestamp}</p>
      </div>
      <div class="text-end">
        <div class="score-badge ${score === 100 ? 'text-success' : 'text-warning'}">${score}%</div>
        <div class="text-secondary">Parity Score</div>
      </div>
    </div>
    <hr class="border-secondary my-3">
    <div class="row text-center">
      <div class="col-4">
        <h5>${total}</h5>
        <small class="text-secondary">Total Routes</small>
      </div>
      <div class="col-4">
        <h5 class="text-success">${passed}</h5>
        <small class="text-secondary">Passed</small>
      </div>
      <div class="col-4">
        <h5 class="${failed > 0 ? 'text-danger' : 'text-secondary'}">${failed}</h5>
        <small class="text-secondary">Failed / Differs</small>
      </div>
    </div>
  </div>

  <div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-white py-3">
      <h5 class="mb-0 fw-bold">Route Verification Results</h5>
    </div>
    <div class="table-responsive">
      <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
          <tr>
            <th>#</th>
            <th>Route Name</th>
            <th>Path</th>
            <th>Unit</th>
            <th>Status Codes</th>
            <th>Parity</th>
            <th>Inspection</th>
          </tr>
        </thead>
        <tbody>
          ${rows}
        </tbody>
      </table>
    </div>
  </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>`;

  fs.mkdirSync(path.dirname(outputFilePath), { recursive: true });
  fs.writeFileSync(outputFilePath, html, 'utf8');
}

function escapeHtml(str) {
  if (!str) return '';
  return str
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

module.exports = {
  generateHtmlReport,
};
