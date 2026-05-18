<?php

return [
    // stub  — synthetic evidence only (always works, no real services required)
    // real  — publishes to ingestion-gateway and polls for real pipeline artifacts
    'pipeline_mode' => env('SCENARIO_PIPELINE_MODE', 'stub'),

    // Go ingestion-gateway endpoint. Must match XDR_INGEST_ADDR in the Go service.
    'ingestion_gateway_url' => env('SCENARIO_INGESTION_GATEWAY_URL', 'http://127.0.0.1:8091'),

    // HMAC secret for signing telemetry POSTs. Must match XDR_INGEST_SECRET in Go.
    'ingestion_gateway_secret' => env('SCENARIO_INGESTION_GATEWAY_SECRET',
        env('XDR_INGEST_SECRET', 'dev-secret-change-me')),

    // How long to wait for pipeline artifacts (alerts) before marking PARTIAL.
    'pipeline_timeout_seconds' => (int) env('SCENARIO_PIPELINE_TIMEOUT_SECONDS', 30),

    // Polling interval in ms while waiting for pipeline artifacts.
    'pipeline_poll_ms' => (int) env('SCENARIO_PIPELINE_POLL_MS', 1000),

    // Milliseconds to sleep between pipeline stages during job execution.
    // Set to 0 in production. Non-zero gives a visible progressive timeline
    // in local/demo environments. Override via SCENARIO_STAGE_DELAY_MS env var.
    'stage_delay_ms' => (int) env('SCENARIO_STAGE_DELAY_MS', 350),

    'scenarios' => [
        [
            'id'               => 'sql_injection_emulation',
            'title'            => 'SQL Injection Emulation',
            'mitre_id'         => 'T1190',
            'mitre_name'       => 'Exploit Public-Facing Application',
            'severity'         => 'high',
            'expected_rule'    => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
            'telemetry_type'   => 'cloud',
            'description'      => 'Emulates SQL injection-like access patterns against cloud API endpoints. Sends structured telemetry matching high-risk API access patterns to validate cloud detection rules. Does not interact with any live database.',
            'expected_telemetry' => [
                'telemetry_type' => 'cloud',
                'event_type'     => 'GetObject',
                'risk_score_min' => 0.70,
                'count'          => 5,
            ],
            'expected_detection' => [
                'rule'       => 'CLOUD_SUSPICIOUS_OBJECT_ACCESS',
                'severity'   => 'high',
                'confidence' => 0.71,
                'alert_count' => 1,
            ],
            'pipeline_stages' => ['ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized', 'correlation', 'xdr.alerts', 'alerts.created', 'incidents.updated'],
        ],
        [
            'id'               => 'failed_login_burst',
            'title'            => 'Failed Login Burst',
            'mitre_id'         => 'T1110.001',
            'mitre_name'       => 'Password Guessing',
            'severity'         => 'high',
            'expected_rule'    => 'IDENTITY_MFA_FAILURE_BURST',
            'telemetry_type'   => 'identity',
            'description'      => 'Sends a burst of synthetic failed authentication events for a test actor to validate the IDENTITY_MFA_FAILURE_BURST detection rule. Uses isolated test credentials that do not exist in production systems.',
            'expected_telemetry' => [
                'telemetry_type' => 'identity',
                'event_type'     => 'login_failed',
                'count'          => 6,
            ],
            'expected_detection' => [
                'rule'       => 'IDENTITY_MFA_FAILURE_BURST',
                'severity'   => 'high',
                'confidence' => 0.71,
                'alert_count' => 1,
            ],
            'pipeline_stages' => ['ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized', 'correlation', 'xdr.alerts', 'alerts.created', 'incidents.updated'],
        ],
        [
            'id'               => 'suspicious_dns_query',
            'title'            => 'Suspicious DNS Query',
            'mitre_id'         => 'T1071.004',
            'mitre_name'       => 'Application Layer Protocol: DNS',
            'severity'         => 'medium',
            'expected_rule'    => 'suspicious_dns_query',
            'telemetry_type'   => 'endpoint',
            'description'      => 'Sends synthetic endpoint DNS query telemetry with DGA-like domain characteristics (high length, numeric density) to validate the suspicious_dns_query shadow detection rule.',
            'expected_telemetry' => [
                'telemetry_type' => 'endpoint',
                'event_type'     => 'dns_query',
                'count'          => 1,
            ],
            'expected_detection' => [
                'rule'       => 'suspicious_dns_query',
                'severity'   => 'medium',
                'confidence' => 0.68,
                'alert_count' => 1,
            ],
            'pipeline_stages' => ['ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized', 'correlation', 'xdr.alerts.shadow.endpoint'],
        ],
        [
            'id'               => 'ioc_match',
            'title'            => 'IOC Match',
            'mitre_id'         => 'T1071',
            'mitre_name'       => 'Application Layer Protocol',
            'severity'         => 'high',
            'expected_rule'    => 'ioc_ip_match',
            'telemetry_type'   => 'endpoint',
            'description'      => 'Sends a synthetic network connection event to a known-malicious IP from the threat intelligence fixture set. Validates the IOC IP match enrichment rule and trace_id propagation.',
            'expected_telemetry' => [
                'telemetry_type'   => 'endpoint',
                'event_type'       => 'network_connection',
                'destination_ip'   => '185.220.101.200',
                'destination_port' => 4444,
                'count'            => 1,
            ],
            'expected_detection' => [
                'rule'       => 'ioc_ip_match',
                'severity'   => 'high',
                'confidence' => 0.90,
                'alert_count' => 1,
            ],
            'pipeline_stages' => ['ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized', 'correlation', 'xdr.alerts.shadow.endpoint'],
        ],
        [
            'id'               => 'suspicious_powershell_event',
            'title'            => 'Suspicious PowerShell Event',
            'mitre_id'         => 'T1059.001',
            'mitre_name'       => 'Command and Scripting Interpreter: PowerShell',
            'severity'         => 'high',
            'expected_rule'    => 'powershell_encoded_command',
            'telemetry_type'   => 'endpoint',
            'description'      => 'Emits a synthetic process_start event for powershell.exe with a base64-encoded command argument to validate the powershell_encoded_command shadow detection rule.',
            'expected_telemetry' => [
                'telemetry_type' => 'endpoint',
                'event_type'     => 'process_start',
                'process_name'   => 'powershell.exe',
                'count'          => 1,
            ],
            'expected_detection' => [
                'rule'       => 'powershell_encoded_command',
                'severity'   => 'high',
                'confidence' => 0.85,
                'alert_count' => 1,
            ],
            'pipeline_stages' => ['ingestion', 'telemetry.raw', 'normalizer', 'telemetry.normalized', 'correlation', 'xdr.alerts.shadow.endpoint'],
        ],
    ],
];
