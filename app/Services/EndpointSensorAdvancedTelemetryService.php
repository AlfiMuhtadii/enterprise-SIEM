<?php

namespace App\Services;

use App\Models\EndpointFileHashLineage;
use App\Models\EndpointModuleLoad;
use App\Models\EndpointRegistryTimeline;
use App\Models\EndpointSocketLifecycle;
use App\Models\EndpointProcessAncestryValidation;
use App\Models\EndpointAntiEvasionIndicator;
use App\Models\EndpointRuntimeVisibility;
use App\Models\EndpointLineageConfidence;
use App\Models\EndpointSocketAnomaly;
use Illuminate\Support\Str;

class EndpointSensorAdvancedTelemetryService
{
    public const ADVISORY_ONLY = true;

    // Lineage thresholds
    public const MAX_REUSE_COUNT_SUSPICIOUS        = 10;
    public const MIN_LINEAGE_CONFIDENCE            = 0.70;
    public const DEGRADATION_THRESHOLD             = 0.10;
    public const MAX_LINEAGE_TRAVERSAL_DEPTH       = 20;

    // Socket thresholds
    public const MAX_RECONNECT_COUNT_SUSPICIOUS    = 5;
    public const BEACONING_THRESHOLD_RECONNECTS    = 10;
    public const SUSPICIOUS_PORTS                  = [4444, 6666, 1337, 31337, 8080, 9001];

    // Anti-evasion confidence thresholds
    public const MIN_EVASION_CONFIDENCE_HIGH       = 0.75;
    public const MIN_EVASION_CONFIDENCE_MEDIUM     = 0.50;

    // =========================================================================
    // File hash lineage
    // =========================================================================

    public function recordFileHashLineage(
        string $endpointId,
        string $tenantId,
        string $fileHashSha256,
        string $filePath,
        string $processName,
        array  $params = []
    ): EndpointFileHashLineage {
        $reuseCount = $params['reuse_count'] ?? 1;
        $suspiciousPropagation = $reuseCount >= self::MAX_REUSE_COUNT_SUSPICIOUS
            || ($params['suspicious_propagation'] ?? false);

        return EndpointFileHashLineage::create([
            'lineage_id'           => 'efhl-' . Str::uuid(),
            'endpoint_id'          => $endpointId,
            'tenant_id'            => $tenantId,
            'file_hash_sha256'     => $fileHashSha256,
            'file_path'            => $filePath,
            'process_name'         => $processName,
            'parent_hash_sha256'   => $params['parent_hash_sha256']  ?? null,
            'renamed_executable'   => $params['renamed_executable']  ?? false,
            'reuse_count'          => $reuseCount,
            'suspicious_propagation'=> $suspiciousPropagation,
            'is_advisory'          => true,
            'lineage_context'      => $params['context']             ?? null,
        ]);
    }

    // =========================================================================
    // Module/DLL loads
    // =========================================================================

    public function recordModuleLoad(
        string $endpointId,
        string $tenantId,
        string $moduleName,
        string $modulePath,
        string $processName,
        array  $params = []
    ): EndpointModuleLoad {
        $isSigned          = $params['is_signed']          ?? true;
        $networkCorrelated = $params['network_correlated'] ?? false;
        $suspiciousLineage = !$isSigned || ($params['suspicious_lineage'] ?? false);

        return EndpointModuleLoad::create([
            'load_id'            => 'eml-' . Str::uuid(),
            'endpoint_id'        => $endpointId,
            'tenant_id'          => $tenantId,
            'module_name'        => $moduleName,
            'module_path'        => $modulePath,
            'process_name'       => $processName,
            'module_hash_sha256' => $params['module_hash_sha256'] ?? null,
            'is_signed'          => $isSigned,
            'suspicious_lineage' => $suspiciousLineage,
            'load_frequency'     => $params['load_frequency']     ?? 1,
            'network_correlated' => $networkCorrelated,
            'is_advisory'        => true,
            'load_context'       => $params['context']            ?? null,
        ]);
    }

    // =========================================================================
    // Registry timelines
    // =========================================================================

    public function recordRegistryTimeline(
        string $endpointId,
        string $tenantId,
        string $registryKey,
        string $modificationType,
        string $keyCategory,
        string $processName,
        array  $params = []
    ): EndpointRegistryTimeline {
        if (!in_array($modificationType, EndpointRegistryTimeline::MODIFICATION_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid modification type: {$modificationType}");
        }

        if (!in_array($keyCategory, EndpointRegistryTimeline::KEY_CATEGORIES, true)) {
            throw new \InvalidArgumentException("Invalid key category: {$keyCategory}");
        }

        $suspiciousLineage = in_array($keyCategory, ['persistence', 'startup', 'autorun'], true)
            && ($params['suspicious_lineage'] ?? false);

        return EndpointRegistryTimeline::create([
            'timeline_id'         => 'ert-' . Str::uuid(),
            'endpoint_id'         => $endpointId,
            'tenant_id'           => $tenantId,
            'registry_key'        => $registryKey,
            'registry_value_name' => $params['registry_value_name'] ?? null,
            'modification_type'   => $modificationType,
            'key_category'        => $keyCategory,
            'process_name'        => $processName,
            'suspicious_lineage'  => $suspiciousLineage,
            'is_advisory'         => true,
            'registry_context'    => $params['context']            ?? null,
        ]);
    }

    // =========================================================================
    // Socket lifecycle
    // =========================================================================

    public function recordSocketLifecycle(
        string $endpointId,
        string $tenantId,
        string $processName,
        string $localAddress,
        int    $localPort,
        string $protocol,
        string $state,
        array  $params = []
    ): EndpointSocketLifecycle {
        if (!in_array($protocol, EndpointSocketLifecycle::PROTOCOLS, true)) {
            throw new \InvalidArgumentException("Invalid protocol: {$protocol}");
        }

        if (!in_array($state, EndpointSocketLifecycle::STATES, true)) {
            throw new \InvalidArgumentException("Invalid socket state: {$state}");
        }

        $suspiciousPort = in_array($localPort, self::SUSPICIOUS_PORTS, true)
                       || in_array($params['remote_port'] ?? 0, self::SUSPICIOUS_PORTS, true);

        return EndpointSocketLifecycle::create([
            'socket_id'                   => 'esl-' . Str::uuid(),
            'endpoint_id'                 => $endpointId,
            'tenant_id'                   => $tenantId,
            'process_name'                => $processName,
            'local_address'               => $localAddress,
            'local_port'                  => $localPort,
            'remote_address'              => $params['remote_address']            ?? null,
            'remote_port'                 => $params['remote_port']               ?? null,
            'protocol'                    => $protocol,
            'state'                       => $state,
            'connection_duration_seconds' => $params['connection_duration_seconds'] ?? 0.0,
            'reconnect_count'             => $params['reconnect_count']            ?? 0,
            'suspicious_port'             => $suspiciousPort,
            'is_advisory'                 => true,
            'socket_context'              => $params['context']                   ?? null,
        ]);
    }

    // =========================================================================
    // Process ancestry validation
    // =========================================================================

    public function validateProcessAncestry(
        string $endpointId,
        string $tenantId,
        string $processName,
        array  $params = []
    ): EndpointProcessAncestryValidation {
        $parentFound     = $params['parent_found']               ?? true;
        $lineageConsist  = $params['lineage_consistent']         ?? true;
        $confidence      = min(1.0, max(0.0, $params['lineage_confidence'] ?? 1.0));
        $orphaned        = !$parentFound;
        $divergence      = !$lineageConsist || $confidence < self::MIN_LINEAGE_CONFIDENCE;

        return EndpointProcessAncestryValidation::create([
            'validation_id'               => 'epav-' . Str::uuid(),
            'endpoint_id'                 => $endpointId,
            'tenant_id'                   => $tenantId,
            'process_name'                => $processName,
            'parent_process_name'         => $params['parent_process_name']        ?? null,
            'parent_found'                => $parentFound,
            'orphaned_chain'              => $orphaned,
            'lineage_consistent'          => $lineageConsist,
            'lineage_confidence'          => $confidence,
            'ancestry_divergence_detected'=> $divergence,
            'replay_ordered'              => true,
            'is_advisory'                 => true,
            'ancestry_evidence'           => $params['evidence']                   ?? null,
        ]);
    }

    // =========================================================================
    // Anti-evasion indicators
    // =========================================================================

    public function recordAntiEvasionIndicator(
        string $endpointId,
        string $tenantId,
        string $indicatorType,
        string $processName,
        float  $confidenceScore,
        array  $params = []
    ): EndpointAntiEvasionIndicator {
        if (!in_array($indicatorType, EndpointAntiEvasionIndicator::INDICATOR_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid indicator type: {$indicatorType}");
        }

        $clamped  = min(1.0, max(0.0, $confidenceScore));
        $severity = match(true) {
            $clamped >= self::MIN_EVASION_CONFIDENCE_HIGH   => 'high',
            $clamped >= self::MIN_EVASION_CONFIDENCE_MEDIUM => 'medium',
            $clamped >= 0.25                                => 'low',
            default                                         => 'low',
        };

        // Critical if confirmed
        $evasionConfirmed = $params['evasion_confirmed'] ?? false;
        if ($evasionConfirmed) {
            $severity = 'critical';
        }

        return EndpointAntiEvasionIndicator::create([
            'indicator_id'    => 'eaei-' . Str::uuid(),
            'endpoint_id'     => $endpointId,
            'tenant_id'       => $tenantId,
            'indicator_type'  => $indicatorType,
            'severity'        => $severity,
            'process_name'    => $processName,
            'evasion_confirmed'=> $evasionConfirmed,
            'confidence_score'=> $clamped,
            'replay_validated'=> $params['replay_validated'] ?? false,
            'is_advisory'     => true,
            'evasion_evidence'=> $params['evidence']         ?? null,
        ]);
    }

    // =========================================================================
    // Runtime visibility
    // =========================================================================

    public function recordRuntimeVisibility(
        string $endpointId,
        string $tenantId,
        string $visibilityType,
        array  $metrics = []
    ): EndpointRuntimeVisibility {
        if (!in_array($visibilityType, EndpointRuntimeVisibility::VISIBILITY_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid visibility type: {$visibilityType}");
        }

        $completeness = min(1.0, max(0.0, $metrics['visibility_completeness_pct'] ?? 1.0));

        return EndpointRuntimeVisibility::create([
            'visibility_id'               => 'erv-' . Str::uuid(),
            'endpoint_id'                 => $endpointId,
            'tenant_id'                   => $tenantId,
            'visibility_type'             => $visibilityType,
            'process_count'               => $metrics['process_count']     ?? 0,
            'module_count'                => $metrics['module_count']      ?? 0,
            'socket_count'                => $metrics['socket_count']      ?? 0,
            'registry_key_count'          => $metrics['registry_key_count']?? 0,
            'visibility_completeness_pct' => $completeness,
            'is_advisory'                 => true,
            'visibility_snapshot'         => $metrics ?: null,
        ]);
    }

    // =========================================================================
    // Lineage confidence
    // =========================================================================

    public function recordLineageConfidence(
        string $endpointId,
        string $tenantId,
        string $lineageType,
        float  $confidenceScore,
        float  $baselineConfidence = 1.0,
        array  $params = []
    ): EndpointLineageConfidence {
        if (!in_array($lineageType, EndpointLineageConfidence::LINEAGE_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid lineage type: {$lineageType}");
        }

        $clamped    = min(1.0, max(0.0, $confidenceScore));
        $delta      = $baselineConfidence - $clamped;
        $degraded   = $delta >= self::DEGRADATION_THRESHOLD;

        return EndpointLineageConfidence::create([
            'confidence_id'       => 'elc-' . Str::uuid(),
            'endpoint_id'         => $endpointId,
            'tenant_id'           => $tenantId,
            'lineage_type'        => $lineageType,
            'confidence_score'    => $clamped,
            'baseline_confidence' => $baselineConfidence,
            'degradation_delta'   => max(0.0, $delta),
            'degradation_detected'=> $degraded,
            'replay_safe'         => true,
            'is_advisory'         => true,
            'confidence_evidence' => $params['evidence'] ?? null,
        ]);
    }

    // =========================================================================
    // Socket anomalies
    // =========================================================================

    public function recordSocketAnomaly(
        string $endpointId,
        string $tenantId,
        string $processName,
        string $anomalyType,
        float  $anomalyScore,
        array  $params = []
    ): EndpointSocketAnomaly {
        if (!in_array($anomalyType, EndpointSocketAnomaly::ANOMALY_TYPES, true)) {
            throw new \InvalidArgumentException("Invalid anomaly type: {$anomalyType}");
        }

        $clamped  = min(1.0, max(0.0, $anomalyScore));
        $severity = match(true) {
            $clamped >= 0.85 => 'critical',
            $clamped >= 0.65 => 'high',
            $clamped >= 0.40 => 'medium',
            default          => 'low',
        };

        return EndpointSocketAnomaly::create([
            'anomaly_id'        => 'esa-' . Str::uuid(),
            'endpoint_id'       => $endpointId,
            'tenant_id'         => $tenantId,
            'process_name'      => $processName,
            'anomaly_type'      => $anomalyType,
            'anomaly_score'     => $clamped,
            'severity'          => $severity,
            'evasion_indicator' => $params['evasion_indicator'] ?? false,
            'replay_validated'  => $params['replay_validated']  ?? false,
            'is_advisory'       => true,
            'anomaly_evidence'  => $params['evidence']          ?? null,
        ]);
    }

    // =========================================================================
    // Dashboard
    // =========================================================================

    public function dashboardStats(): array
    {
        return [
            'hash_lineage_records'     => EndpointFileHashLineage::count(),
            'suspicious_propagation'   => EndpointFileHashLineage::where('suspicious_propagation', true)->count(),
            'unsigned_modules'         => EndpointModuleLoad::where('is_signed', false)->count(),
            'registry_persistence'     => EndpointRegistryTimeline::where('key_category', 'persistence')->count(),
            'socket_anomalies'         => EndpointSocketAnomaly::count(),
            'evasion_critical'         => EndpointAntiEvasionIndicator::where('severity', 'critical')->count(),
            'ancestry_divergence'      => EndpointProcessAncestryValidation::where('ancestry_divergence_detected', true)->count(),
            'confidence_degraded'      => EndpointLineageConfidence::where('degradation_detected', true)->count(),
        ];
    }
}
