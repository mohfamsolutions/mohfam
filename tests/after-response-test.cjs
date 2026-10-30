// Local HTTP timing check only. No WordPress, Brevo, API key, or email involved.
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const net = require('node:net');
const { spawn } = require('node:child_process');
const assert = require('node:assert/strict');
const { setTimeout: pause } = require('node:timers/promises');

(async () => {
  const fixture = fs.mkdtempSync(path.join(os.tmpdir(), 'mohfam-response-'));
  const reserve = net.createServer();
  await new Promise(resolve => reserve.listen(0, '127.0.0.1', resolve));
  const port = reserve.address().port;
  await new Promise(resolve => reserve.close(resolve));
  const modulePath = path.resolve('twentytwentyfive.child_.theme_.-.jaimemasc.com_/inc/inquiry-mail.php').replaceAll('\\', '/');
  const router = path.join(fixture, 'router.php');
  const marker = path.join(fixture, 'finished.json').replaceAll('\\', '/');
  const quote = value => `'${value.replaceAll("'", "\\'")}'`;
  fs.writeFileSync(router, `<?php
require ${quote(modulePath)};
$body = json_encode(['success' => true]);
\\MohFam\\Mail\\prepareContactResponse(strlen($body));
header('Content-Type: application/json');
register_shutdown_function(static function () {
  if (!\\MohFam\\Mail\\finishContactResponse()) { return; }
  // Simulate a slow contact request after finishing the actual response.
  usleep(2000000);
  file_put_contents(${quote(marker)}, json_encode(['done' => true]));
});
echo $body;
exit;
`);
  const php = process.argv[2] || 'php';
  const child = spawn(php, ['-S', `127.0.0.1:${port}`, router], { stdio: ['ignore', 'ignore', 'pipe'], windowsHide: true });
  const closed = new Promise(resolve => child.once('close', resolve));
  let startup = '';
  try {
    await new Promise((resolve, reject) => {
      const timer = setTimeout(() => reject(new Error('PHP HTTP fixture startup timed out')), 5000);
      child.once('error', error => { clearTimeout(timer); reject(error); });
      child.stderr.on('data', chunk => {
        startup += chunk;
        if (startup.includes('Development Server')) { clearTimeout(timer); resolve(); }
      });
      child.once('exit', code => { clearTimeout(timer); reject(new Error(`PHP exited ${code}: ${startup}`)); });
    });
    const started = performance.now();
    const response = await fetch(`http://127.0.0.1:${port}/`, { signal: AbortSignal.timeout(1500) });
    assert.deepEqual(await response.json(), { success: true });
    const elapsed = performance.now() - started;
    assert.ok(elapsed < 1500, `Response must arrive before the simulated 2-second contact work (${elapsed} ms)`);
    assert.equal(fs.existsSync(marker), false, 'Worker has not finished when visitor receives success');
    for (let attempt = 0; attempt < 30 && !fs.existsSync(marker); attempt++) await pause(100);
    assert.deepEqual(JSON.parse(fs.readFileSync(marker, 'utf8')), { done: true });
    console.log(`Passed local response timing: JSON received in ${Math.round(elapsed)} ms; slow shutdown work completed afterward. No API/email used.`);
  } finally {
    if (child.exitCode === null) {
      child.kill();
    }
    await closed;
    assert.equal(path.dirname(path.resolve(fixture)), path.resolve(os.tmpdir()), 'Cleanup stays in the temporary parent');
    assert.ok(path.basename(fixture).startsWith('mohfam-response-'), 'Cleanup is limited to this test fixture');
    fs.rmSync(fixture, { recursive: true, force: true });
  }
})().catch(error => { console.error(error); process.exitCode = 1; });
