$ErrorActionPreference = "Stop"
Stop-Service DetectorEndpointAgent -ErrorAction SilentlyContinue
sc.exe delete DetectorEndpointAgent

