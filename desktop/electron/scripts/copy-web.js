const fs = require('fs');
const path = require('path');

function ensureDir(dirPath) {
  fs.mkdirSync(dirPath, { recursive: true });
}

function main() {
  const projectRoot = path.resolve(__dirname, '..');
  const sourceDir = path.resolve(projectRoot, '..', '..', 'apps', 'web-pos', 'dist');
  const targetDir = path.resolve(projectRoot, 'dist', 'web');

  if (!fs.existsSync(sourceDir)) {
    throw new Error(
      `web-pos build not found at ${sourceDir}. Run "npm run build:web-pos" from the repo root first.`
    );
  }

  fs.rmSync(targetDir, { recursive: true, force: true });
  ensureDir(targetDir);
  fs.cpSync(sourceDir, targetDir, { recursive: true });

  console.log(`Copied web-pos dist from ${sourceDir} to ${targetDir}`);
}

main();
