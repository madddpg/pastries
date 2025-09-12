
<?php
echo "<pre>";
echo "__FILE__      = " . __FILE__ . "\n";
echo "__DIR__       = " . __DIR__ . "\n";
echo "parent root   = " . dirname(__DIR__) . "\n";
$candidates = [
  '/home/u778762049/domains/cupsandcuddles.online/secure/service_account.json',
  '/home/u778762049/secure/service_account.json',
  dirname(__DIR__).'/../secure/service_account.json',
  dirname(__DIR__).'/secure/service_account.json'
];
foreach ($candidates as $p) {
    echo ($p) . " => " . (is_file($p) ? "FOUND" : "MISSING") . "\n";
}
echo "Directory listing of secure (if exists):\n";
$secureDir = '/home/u778762049/domains/cupsandcuddles.online/secure';
if (is_dir($secureDir)) {
    foreach (scandir($secureDir) as $f) {
        if ($f === '.' || $f === '..') continue;
        echo "  $f\n";
    }
} else {
    echo "  secure dir NOT FOUND at $secureDir\n";
}
echo "</pre>";