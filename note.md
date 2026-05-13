- scripts/final-present.ps1

  Jalankan dari awal (full):

  powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1

  Kalau infra sudah hidup dan tidak mau up/reset ulang:

  powershell -ExecutionPolicy Bypass -File .\scripts\final-present.ps1 -SkipUp -SkipReset

  Script ini otomatis:

  1. up (opsional)
  2. reset (opsional)
  3. run skenario deterministik
  4. verify hard assertions
  5. tampilkan security:alerts-report
  6. tampilkan URL demo
  7. jalankan drift monitor
  8. jalankan retrain policy

  Jika ada step gagal, script langsung stop (fail-fast).