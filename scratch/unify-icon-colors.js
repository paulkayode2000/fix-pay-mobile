// Standardize all HeroIcon colors to brand-primary (--brand-primary)
// Only replaces icon className colors, preserving semantic colors (green=success, red=error)
const fs = require('fs');
const path = require('path');

const srcDir = path.resolve(__dirname, '..', 'fixpay-pwa', 'src');

function walkDir(dir, callback) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  for (const entry of entries) {
    const fullPath = path.join(dir, entry.name);
    if (entry.isDirectory()) walkDir(fullPath, callback);
    else if (entry.name.endsWith('.tsx')) callback(fullPath);
  }
}

let totalChanges = 0;
let filesChanged = 0;

walkDir(srcDir, (filePath) => {
  let content = fs.readFileSync(filePath, 'utf8');
  let fileChanged = false;

  // Strategy: Match className strings that contain a size class (w-/h-) AND a gray/blue tint
  // This ensures we only hit icon elements, not body text
  const iconClassNameRegex = /className="([^"]*?w-\d+(?:\.\d+)?\s[^"]*?(?:text-gray-[34567]00|text-blue-500|text-ios-blue)[^"]*?)"/g

  content = content.replace(iconClassNameRegex, (fullMatch) => {
    const orig = fullMatch;
    fullMatch = fullMatch
      .replace(/text-gray-300/g, 'text-brand')
      .replace(/text-gray-400/g, 'text-brand')
      .replace(/text-gray-500/g, 'text-brand')
      .replace(/text-gray-600/g, 'text-brand')
      .replace(/text-gray-700/g, 'text-brand')
      .replace(/text-blue-500/g, 'text-brand')
      .replace(/text-ios-blue/g, 'text-brand');
    if (fullMatch !== orig) { fileChanged = true; totalChanges++; }
    return fullMatch;
  });

  // Handle cn() className patterns: cn('w-5 h-5', variant === 'danger' ? 'text-ios-red' : 'text-gray-600')
  // Only replace the non-danger variant (keep text-ios-red for danger)
  const cnIconRegex = /(cn\([^)]*?['"]\s*,\s*['"][^'"]*?)text-(?:gray-[34567]00|blue-500|ios-blue)([^)]*?\))/g
  content = content.replace(cnIconRegex, (fullMatch, before, after) => {
    fileChanged = true;
    totalChanges++;
    return before + 'text-brand' + after;
  });

  if (fileChanged) {
    fs.writeFileSync(filePath, content);
    const relPath = path.relative(srcDir, filePath);
    console.log('✅ ' + relPath);
    filesChanged++;
  }
});

console.log('\n🎨 Standardized ' + totalChanges + ' icon color(s) across ' + filesChanged + ' files to brand blue');
console.log('Preserved: text-ios-green (success), text-ios-red (error), text-white (on colored bg)');