<?php
return [
  'allow_hosts' => ['storage.googleapis.com','edgedl.me.gvt1.com','dl.google.com'],
  'packages' => [
    [
      'platform' => 'linux', 'architecture' => 'x86_64', 'version' => 'chrome-for-testing-126.0.6478.126',
      'url' => 'https://storage.googleapis.com/chrome-for-testing-public/126.0.6478.126/linux64/chrome-linux64.zip',
      'sha256' => '0000000000000000000000000000000000000000000000000000000000000000',
      'executable' => 'chrome-linux64/chrome', 'max_archive_bytes' => 200000000, 'max_extracted_bytes' => 600000000,
    ],
  ],
];
