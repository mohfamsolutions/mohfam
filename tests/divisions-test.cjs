// Keep published divisions consistent across all parent-site entry points.
const fs = require('node:fs');
const path = require('node:path');
const assert = require('node:assert/strict');
const root = 'twentytwentyfive.child_.theme_.-.jaimemasc.com_';
const divisions = [
  ['MohFam Security', 'https://mohfamsecurity.com/', 'security'],
  ['Commercial Cleaning', 'https://mohfamcommercialcleaning.com/', 'cleaning'],
  ['Pool Cleaning', 'https://mohfampoolcleaning.com/', 'pool'],
  ['Landscaping', 'https://mohfamlandscaping.com/', 'landscaping'],
  ['Car Wash', 'https://mohfamcarwash.com/', 'car'],
];
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const hasDestination = (html, url) => (html.match(/<a\b[^>]*>/g) || []).some(tag => {
  const href = tag.match(/\bhref="([^"]+)"/)?.[1];
  return href?.replace(/\/$/, '') === url.replace(/\/$/, '') && tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"');
});
for (const dir of ['parts', 'templates']) {
  for (const name of fs.readdirSync(path.join(root, dir)).filter(name => name.endsWith('.html'))) {
    const html = read(`${dir}/${name}`);
    // Terms contain a general caveat about future services, not an availability label.
    if (name !== 'page-terms-of-service.html') {
      assert.doesNotMatch(html, /coming soon|not yet available|planned next|coming next|register your interest|upcoming divisions|future services|joining our family next/i, `${name}: stale availability copy`);
    }
    assert.doesNotMatch(html, /\(Waitlist\)|mohfamcleaning\.com/, `${name}: retired division link/option`);
    for (const tag of html.match(/<a\b[^>]*>/g) || []) {
      const href = tag.match(/\bhref="([^"]+)"/)?.[1];
      if (href && /^https?:\/\//.test(href) && !['mohfam.co', 'www.mohfam.co'].includes(new URL(href).hostname)) {
        assert.ok(tag.includes('target="_blank"') && tag.includes('rel="noopener noreferrer"'), `${name}: external new-tab rule`);
      }
    }
  }
}
for (const file of ['parts/header.html', 'parts/footer.html', 'parts/service-faq.html']) {
  for (const [, url] of divisions) assert.ok(hasDestination(read(file), url), `${file}: missing ${url}`);
}
const finder = read('parts/service-finder.html');
for (const [, url, id] of divisions) {
  const panel = finder.match(new RegExp(`<div class="mohfam-finder__panel" data-service="${id}"[^>]*>([\\s\\S]*?)<\\/div>`))?.[1];
  assert.ok(panel && hasDestination(panel, url), `Service finder: ${id}`);
}
for (const name of ['front-page.html', 'page-services.html']) {
  const cards = [...read(`templates/${name}`).matchAll(/<article class="mohfam-division-card ([^"]+)"[^>]*>([\s\S]*?)<\/article>/g)];
  assert.equal(cards.length, 5);
  cards.forEach((card, index) => {
    assert.equal(card[1], 'is-live', `${name}: all divisions available to explore`);
    assert.ok(card[2].includes(`>${divisions[index][0]}<`) && hasDestination(card[2], divisions[index][1]), `${name}: division ordering/destination`);
    const photo = card[2].match(/mohfam-division-card__photo--([a-z-]+)/)?.[1];
    const rule = read('assets/css/experience.css').match(new RegExp(`\\.mohfam-division-card__photo--${photo} \\{[^}]*url\\("([^"]+)"\\)`));
    assert.ok(rule && fs.statSync(path.resolve(root, 'assets/css', rule[1])).size > 0, `${name}: photo exists`);
  });
}
for (const file of ['templates/front-page.html', 'templates/page-contact.html']) {
  const html = read(file);
  assert.ok(html.includes('<option value="Landscaping">Landscaping</option>'), `${file}: current inquiry option`);
}
for (const [route, url] of [
  ['/services/mohfam-security', divisions[0][1]],
  ['/services/commercial-cleaning', divisions[1][1]],
  ['/services/pool-cleaning', divisions[2][1]],
  ['/services/landscaping', divisions[3][1]],
  ['/landscaping', divisions[3][1]],
  ['/services/car-wash', divisions[4][1]],
]) {
  assert.match(read('functions.php'), new RegExp(`'${route}'\\s*=>\\s*'${url.replaceAll('.', '\\.')}\'`), `Redirect ${route}`);
}
console.log('Passed all-five-division links, new-tab rules, photos, live statuses, inquiry options, redirects, and stale-copy checks across every template and shared part.');
