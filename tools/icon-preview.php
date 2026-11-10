<?php
// Local development gallery using the application's actual file classification.
require_once dirname(__DIR__) . '/app/helpers.php';
$examples = ['setup.exe', 'library.dll', 'program.elf', 'libexample.so', 'object.o', 'installer.run', 'backup.zip', 'photos.rar', 'bundle.7z', 'source.tar', 'backup.zst', 'files.cpio', 'app.deb', 'app.flatpak', 'disk.iso', 'app.jar', 'unknown.dat', 'package.rpm', 'app.apk', 'installer.dmg', 'module.wasm', 'main.py', 'app.js', 'app.ts', 'index.php', 'Main.java', 'main.c', 'main.cpp', 'Program.cs', 'main.rs', 'analysis.r', 'main.go', 'script.rb', 'install.sh', 'index.html', 'style.css', 'data.json', 'config.yaml', 'schema.xml', 'query.sql', 'README.md', 'App.vue', 'App.svelte', 'config.toml', 'notes.txt', 'analysis.i64', 'legacy.idb', 'data.db', 'app.sqlite3', 'config.firefly'];
?>
<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>File icon preview</title>
<style>body{margin:0;background:#141416;color:#f5f5f7;font:16px system-ui,sans-serif;padding:48px}main{max-width:1100px;margin:auto}h1{font-size:32px;margin-bottom:8px}p{color:#a1a1aa}.grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px;margin-top:32px}figure{margin:0;padding:24px 12px;background:#1b1b1e;border:1px solid #303034;border-radius:16px;text-align:center}img{width:96px;height:96px}figcaption{margin-top:12px;font-size:14px}small{display:block;color:#a1a1aa;margin-top:6px;font-size:11px}</style>
<main><h1>File icons</h1><p>Binaries, archives, packages &amp; source files · actual application mappings</p><div class="grid">
<?php foreach ($examples as $name): $meta = wb_file_fallback_metadata(pathinfo($name, PATHINFO_EXTENSION)); ?>
<figure><img src="/media/file-fallbacks/<?= $meta['icon'] ?>.svg?v=<?= filemtime(dirname(__DIR__) . '/media/file-fallbacks/' . $meta['icon'] . '.svg') ?>" alt="<?= htmlspecialchars($meta['label']) ?>"><figcaption><?= htmlspecialchars($name) ?><small><?= htmlspecialchars($meta['label']) ?></small></figcaption></figure>
<?php endforeach; ?></div></main></html>
