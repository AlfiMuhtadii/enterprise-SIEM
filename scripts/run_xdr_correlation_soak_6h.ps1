Set-Location 'D:\project\Detector'
C:\Python314\python.exe scripts\xdr_correlation_soak.py --duration-minutes 360 --batch-size 5000 --sleep-ms 100 --request-timeout-sec 180 --correlate-retries 2 --correlate-retry-sleep-ms 250 --status-retries 2 --status-retry-sleep-ms 250 --status-timeout-sec 90 --status-check-interval-sec 60 --output reports\xdr_correlation_soak_6h.json
