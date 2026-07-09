<?php

namespace App\Services;

use Illuminate\Support\Carbon;

/**
 * META-MODULE-RATIONALIZE (bounded step): a read-only facade over the
 * StabilityEvidenceFreeze V2/V3/V4 sprawl, addressing the credibility
 * concern the finding raised — "reviewers may read 'Final XDR
 * Certification' as a real accreditation" applies equally here: each
 * version's freeze covers a DIFFERENT, non-overlapping phase range, so
 * mistaking v4's status for a superseding/merged result would be wrong.
 *
 * Deliberately NOT a merge of the three services: each version's gate set,
 * table schema, command, and controller are structurally distinct (12 vs
 * 22 vs 16 gates over different phase ranges) — collapsing them would risk
 * silently changing gate coverage or losing per-version evidence, which is
 * exactly the kind of "quiet regression from consolidation" this task must
 * not introduce. This facade only READS each version's existing, unchanged
 * getLatestFreeze() and answers "what's the current state, honestly
 * labelled by version" without writing anything or touching any of the
 * three write paths.
 */
class StabilityFreezeOverviewService
{
    public function __construct(
        private readonly StabilityEvidenceFreezeV2Service $v2,
        private readonly StabilityEvidenceFreezeV3Service $v3,
        private readonly StabilityEvidenceFreezeV4Service $v4,
    ) {
    }

    /**
     * Returns each version's latest freeze (null if that version has never
     * been run) plus `current`: the single most-recently-frozen run across
     * all versions, by `frozen_at`. `current` is explicitly NOT a "the
     * platform is now at this stability level" claim — it is only the most
     * recent evidence snapshot; the calling UI/CLI must still show which
     * version it came from and that version's own phase_range.
     */
    public function overview(): array
    {
        $versions = [
            'v2' => $this->v2->getLatestFreeze(),
            'v3' => $this->v3->getLatestFreeze(),
            'v4' => $this->v4->getLatestFreeze(),
        ];

        return [
            'versions' => $versions,
            'current' => $this->mostRecent($versions),
            'is_advisory' => true,
            'note' => 'Each stability-freeze version evaluates a different, non-overlapping phase '
                .'range (see versions[*].summary.phase_range). "current" is only the most recently '
                .'run freeze across all versions, not a merged or superseding status.',
        ];
    }

    private function mostRecent(array $versions): ?array
    {
        $latest = null;
        $latestAt = null;

        foreach ($versions as $data) {
            $frozenAt = $data['summary']['frozen_at'] ?? null;
            if ($frozenAt === null) {
                continue;
            }
            $at = Carbon::parse($frozenAt);
            if ($latestAt === null || $at->gt($latestAt)) {
                $latestAt = $at;
                $latest = $data;
            }
        }

        return $latest;
    }
}
