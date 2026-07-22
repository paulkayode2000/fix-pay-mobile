// Fix remaining non-brand-blue icons in payment screens
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

  // Fix: green checkmarks → brand blue (consistency)
  // Only in payment/verification contexts where there's no semantic success meaning needed
  if (content.includes('text-green-600') && content.includes('CheckCircleIcon')) {
    content = content.replace(/text-green-600/g, 'text-brand');
    fileChanged = true;
    totalChanges++;
  }

  // Fix: red heart on receipts → brand blue
  if (content.includes('text-red-500') && (content.includes('HeartSolid') || content.includes('HeartIcon'))) {
    content = content.replace(/text-red-500/g, 'text-brand');
    fileChanged = true;
    totalChanges++;
  }

  if (fileChanged) {
    fs.writeFileSync(filePath, content);
    const relPath = path.relative(srcDir, filePath);
    console.log('✅ ' + relPath);
    filesChanged++;
  }
});

console.log('\n🎨 Fixed ' + totalChanges + ' icon(s) across ' + filesChanged + ' files');