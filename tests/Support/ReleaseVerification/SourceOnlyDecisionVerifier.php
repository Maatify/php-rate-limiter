<?php

declare(strict_types=1);

namespace Maatify\RateLimiter\Tests\Support\ReleaseVerification;

/**
 * Proves source-only canonical-delivery governance from immutable Git objects.
 *
 * Implements the Decision-related contract of:
 * - CI_WORKFLOW_STANDARD.md §2.5 (RAV: qualification-time proof) and §2.6 (PAV: historical chain)
 * - COMPOSER_PACKAGE_STANDARD.md §26.1
 * - DECISION_GOVERNANCE_STANDARD_AR.md (Status model, Index, supersession)
 *
 * Nothing is inferred from the mutable worktree: records, indexes and approval state are
 * read from Git commits. A Decision Record that can declare source-only delivery MUST
 * contain a `## Source-Only Delivery Declaration` section of `- Key: value` bullets:
 *
 *   Package, Composer Channel, Delivery Mode (`source-only`), Intentional Canonical Delivery (`yes`),
 *   Version Scope, Dist Rationale, Owner Approval (`APPROVED`), Approving Authority,
 *   Approval Date, Effective Date, and optionally Maintenance Owner.
 *
 * plus the repository-standard `## Status`, `## Decision ID` and `## Decision Authority`
 * (which MUST state `Owner-approved`) sections.
 */
final class SourceOnlyDecisionVerifier
{
    public const string INDEX_PATH = 'docs/decisions/DECISIONS_INDEX.md';
    private const int MAX_CHAIN_DEPTH = 50;

    /**
     * RAV: verifies the qualification-time Decision state from the immutable Decision reference.
     *
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}
     */
    public function qualify(
        GitRepository $repo,
        string $decisionId,
        string $decisionFile,
        string $decisionCommit,
        string $target,
        string $candidateSha,
        string $qualificationStartedAt,
    ): array {
        $decisionId = trim($decisionId);
        $decisionFile = ltrim(str_replace('\\', '/', trim($decisionFile)), '/');
        $decisionCommit = strtolower(trim($decisionCommit));

        if ($decisionId === '' || $decisionFile === '' || $decisionCommit === '') {
            return $this->fail('Source-only delivery policy requires non-empty decision_id, decision_file, and an immutable decision commit reference.');
        }
        if (! (bool) preg_match('/^DEC-\d+$/D', $decisionId)) {
            return $this->fail(sprintf('Decision ID "%s" is not a canonical Decision ID.', $decisionId));
        }
        if (! (bool) preg_match('#^docs/decisions/DEC-\d+[A-Za-z0-9_\-]*\.md$#D', $decisionFile)) {
            return $this->fail(sprintf('Decision record path "%s" is not a canonical docs/decisions/ record path.', $decisionFile));
        }
        if (! ReleaseContract::isSha($decisionCommit)) {
            return $this->fail(sprintf('Immutable Decision reference "%s" must be a full 40-hex commit SHA.', $decisionCommit));
        }
        if ($repo->objectType($decisionCommit) !== 'commit') {
            return $this->fail(sprintf('Immutable Decision reference "%s" does not exist as a commit object.', $decisionCommit));
        }
        if (! $repo->isAncestor($decisionCommit, $candidateSha)) {
            return $this->fail(sprintf('Decision commit "%s" is not contained in the history of candidate "%s"; the Decision does not pre-exist the qualification boundary.', $decisionCommit, $candidateSha));
        }

        $recordAtDecision = $repo->showBlob($decisionCommit, $decisionFile);
        if ($recordAtDecision === null) {
            return $this->fail(sprintf('Decision record "%s" does not exist at immutable commit "%s".', $decisionFile, $decisionCommit));
        }
        $indexAtDecision = $repo->showBlob($decisionCommit, self::INDEX_PATH);
        if ($indexAtDecision === null) {
            return $this->fail(sprintf('DECISIONS_INDEX.md does not exist at immutable commit "%s".', $decisionCommit));
        }

        $record = $this->parseRecord($recordAtDecision);
        if ($record['id'] !== $decisionId) {
            return $this->fail(sprintf('Decision record at "%s" declares ID "%s", not "%s".', $decisionCommit, $record['id'] ?? 'none', $decisionId));
        }
        if ($record['status'] !== 'ACTIVE') {
            return $this->fail(sprintf('Decision "%s" status at immutable reference is "%s", expected ACTIVE.', $decisionId, $record['status'] ?? 'none'));
        }
        $rowAtDecision = $this->indexRow($indexAtDecision, $decisionId);
        if ($rowAtDecision === null || $rowAtDecision['status'] !== 'ACTIVE' || $rowAtDecision['record'] !== $decisionFile) {
            return $this->fail(sprintf('Decision "%s" is not indexed as ACTIVE for "%s" in DECISIONS_INDEX.md at the immutable reference.', $decisionId, $decisionFile));
        }

        // Qualification boundary = the candidate commit: the record must be unchanged and still ACTIVE/indexed.
        $recordOid = $repo->blobOid($decisionCommit, $decisionFile);
        $recordOidAtCandidate = $repo->blobOid($candidateSha, $decisionFile);
        if ($recordOid === null || $recordOid !== $recordOidAtCandidate) {
            return $this->fail(sprintf('Decision record "%s" at candidate "%s" differs from the immutable reference; it was amended or removed before qualification.', $decisionFile, $candidateSha));
        }
        $indexAtCandidate = $repo->showBlob($candidateSha, self::INDEX_PATH);
        $indexOidAtCandidate = $repo->blobOid($candidateSha, self::INDEX_PATH);
        $rowAtCandidate = $indexAtCandidate === null ? null : $this->indexRow($indexAtCandidate, $decisionId);
        if ($indexOidAtCandidate === null || $rowAtCandidate === null || $rowAtCandidate['status'] !== 'ACTIVE' || $rowAtCandidate['record'] !== $decisionFile) {
            return $this->fail(sprintf('Decision "%s" is not indexed as ACTIVE at the candidate qualification boundary.', $decisionId));
        }

        $declaration = $record['declaration'];
        if ($declaration === null) {
            return $this->fail(sprintf('Decision "%s" has no "Source-Only Delivery Declaration" section.', $decisionId));
        }

        if (! (bool) preg_match('/\bOwner[- ]approved\b/i', $record['authority'])) {
            return $this->fail(sprintf('Decision "%s" does not state Owner approval under "Decision Authority".', $decisionId));
        }
        $approvalState = strtoupper($declaration['Owner Approval'] ?? '');
        $approver = $declaration['Approving Authority'] ?? '';
        $approvalDate = $declaration['Approval Date'] ?? '';
        $effectiveDate = $declaration['Effective Date'] ?? '';
        if ($approvalState !== 'APPROVED' || $approver === '' || $approvalDate === '' || $effectiveDate === '') {
            return $this->fail(sprintf('Decision "%s" lacks complete Owner approval evidence (Owner Approval, Approving Authority, Approval Date, Effective Date).', $decisionId));
        }

        $approvalAt = ReleaseContract::parseEffectiveInstant($approvalDate);
        $effectiveAt = ReleaseContract::parseEffectiveInstant($effectiveDate);
        $startedAt = ReleaseContract::parseTimestamp($qualificationStartedAt);
        if ($approvalAt === null || $effectiveAt === null || $startedAt === null) {
            return $this->fail(sprintf('Decision "%s" approval/effective timing is missing or ambiguous (expected YYYY-MM-DD or YYYY-MM-DDTHH:MM:SSZ).', $decisionId));
        }
        $effectiveInstant = max($approvalAt, $effectiveAt);
        if ($effectiveInstant >= $startedAt) {
            return $this->fail(sprintf('Decision "%s" approval/effective state (%s) does not predate the start of Release Artifact Verification (%s).', $decisionId, gmdate('Y-m-d\TH:i:s\Z', $effectiveInstant), $qualificationStartedAt));
        }

        if (($declaration['Package'] ?? '') !== ReleaseContract::PACKAGE_NAME) {
            return $this->fail(sprintf('Decision "%s" package "%s" does not match "%s".', $decisionId, $declaration['Package'] ?? '', ReleaseContract::PACKAGE_NAME));
        }
        $channel = $declaration['Composer Channel'] ?? '';
        if (! ReleaseContract::isValidChannel($channel)) {
            return $this->fail(sprintf('Decision "%s" does not identify a valid approved Composer channel/repository.', $decisionId));
        }
        if (strtolower($declaration['Delivery Mode'] ?? '') !== 'source-only' || strtolower($declaration['Intentional Canonical Delivery'] ?? '') !== 'yes') {
            return $this->fail(sprintf('Decision "%s" does not explicitly declare source-only intentional canonical delivery.', $decisionId));
        }
        $scope = $declaration['Version Scope'] ?? '';
        $covers = ReleaseContract::versionInScope($target, $scope);
        if ($covers === null) {
            return $this->fail(sprintf('Decision "%s" version scope "%s" is malformed or ambiguous.', $decisionId, $scope));
        }
        if (! $covers) {
            return $this->fail(sprintf('Target "%s" is outside the version scope "%s" of Decision "%s".', $target, $scope, $decisionId));
        }
        $rationale = $declaration['Dist Rationale'] ?? '';
        if ($rationale === '') {
            return $this->fail(sprintf('Decision "%s" does not state why dist is intentionally not offered.', $decisionId));
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Source-only delivery policy authorized under Decision "%s" proven at immutable reference %s.', $decisionId, $decisionCommit),
            'details' => [
                'decision_id' => $decisionId,
                'decision_file' => $decisionFile,
                'decision_commit' => $decisionCommit,
                'record_blob_sha' => $recordOid,
                'index_file' => self::INDEX_PATH,
                'index_blob_sha_at_qualification' => $indexOidAtCandidate,
                'status_at_qualification' => 'ACTIVE',
                'index_status_at_qualification' => 'ACTIVE',
                'owner_approval' => [
                    'authority_statement' => $record['authority'],
                    'approving_authority' => $approver,
                    'approval_date' => $approvalDate,
                    'effective_date' => $effectiveDate,
                ],
                'effective_at' => gmdate('Y-m-d\TH:i:s\Z', $effectiveInstant),
                'package_name' => ReleaseContract::PACKAGE_NAME,
                'approved_distribution_channel' => $channel,
                'version_scope' => $scope,
                'scope_covers_target' => true,
                'delivery_mode' => 'source-only',
                'intentional_canonical_delivery' => true,
                'rationale' => $rationale,
                'maintenance_owner' => ($declaration['Maintenance Owner'] ?? '') !== '' ? $declaration['Maintenance Owner'] : null,
                'qualification_target' => $target,
                'candidate_sha' => $candidateSha,
                'qualification_started_at' => $qualificationStartedAt,
            ],
        ];
    }

    /**
     * PAV: proves the retained qualification-time evidence is reproducible from immutable
     * history and that the Decision remains historically discoverable with a coherent
     * current Index / supersession chain.
     *
     * @param array<string, mixed> $evidence The `source_only_decision` object of the RAV evidence
     * @return array{status: 'PASS'|'FAIL', message: string, details?: array<string, mixed>}
     */
    public function verifyHistoricalChain(?GitRepository $repo, array $evidence, string $target, string $candidateSha): array
    {
        if ($repo === null || ! $repo->isWorkTree()) {
            return $this->fail('Repository history is unavailable; source-only historical Decision evidence cannot be proven (fail closed).');
        }

        $str = static fn(string $key): string => is_string($evidence[$key] ?? null) ? $evidence[$key] : '';

        $reproduced = $this->qualify(
            $repo,
            $str('decision_id'),
            $str('decision_file'),
            $str('decision_commit'),
            $target,
            $candidateSha,
            $str('qualification_started_at'),
        );
        if ($reproduced['status'] === 'FAIL') {
            return $this->fail('Retained qualification-time Decision evidence is not reproducible from immutable history: ' . $reproduced['message']);
        }
        if (($reproduced['details'] ?? null) !== $evidence) {
            return $this->fail('Retained qualification-time Decision evidence differs from the immutable historical Decision state.');
        }

        $decisionId = $str('decision_id');
        $decisionFile = $str('decision_file');

        $head = $repo->resolve('HEAD');
        if ($head === null || ! $repo->isAncestor($candidateSha, $head)) {
            return $this->fail('Qualified candidate SHA is not contained in the current repository history.');
        }

        $currentIndex = $repo->showBlob($head, self::INDEX_PATH);
        $currentRow = $currentIndex === null ? null : $this->indexRow($currentIndex, $decisionId);
        if ($currentRow === null || $currentRow['record'] !== $decisionFile) {
            return $this->fail(sprintf('Decision "%s" is no longer discoverable in the current DECISIONS_INDEX.md.', $decisionId));
        }
        $currentRecordRaw = $repo->showBlob($head, $decisionFile);
        if ($currentRecordRaw === null) {
            return $this->fail(sprintf('Decision record "%s" no longer exists in current history.', $decisionFile));
        }
        $currentRecord = $this->parseRecord($currentRecordRaw);
        if ($currentRecord['status'] !== $currentRow['status']) {
            return $this->fail(sprintf('Decision "%s" status in its record ("%s") disagrees with the current Index ("%s").', $decisionId, $currentRecord['status'] ?? 'none', $currentRow['status']));
        }

        if ($currentRow['status'] === 'ACTIVE') {
            if ($repo->blobOid($head, $decisionFile) !== $evidence['record_blob_sha']) {
                return $this->fail(sprintf('Decision "%s" remains ACTIVE but its record was rewritten after qualification.', $decisionId));
            }

            return [
                'status' => 'PASS',
                'message' => sprintf('Decision "%s" was ACTIVE and indexed at qualification and remains ACTIVE with a coherent Index and record.', $decisionId),
                'details' => ['current_status' => 'ACTIVE', 'chain' => [$decisionId]],
            ];
        }

        if ($currentRow['status'] !== 'SUPERSEDED') {
            return $this->fail(sprintf('Decision "%s" current status "%s" is neither ACTIVE nor SUPERSEDED.', $decisionId, $currentRow['status']));
        }

        // Legitimate supersession must not rewrite the approved declaration/authority.
        $historical = $this->parseRecord((string) $repo->showBlob($str('decision_commit'), $decisionFile));
        if ($currentRecord['declaration'] !== $historical['declaration'] || $currentRecord['authority'] !== $historical['authority']) {
            return $this->fail(sprintf('Superseded Decision "%s" had its approved declaration or authority rewritten after qualification.', $decisionId));
        }

        $chain = $this->verifySupersessionChain($repo, $head, $decisionId, $currentRow, $currentRecord);
        if ($chain['status'] === 'FAIL') {
            return $chain;
        }

        return [
            'status' => 'PASS',
            'message' => sprintf('Decision "%s" was ACTIVE at qualification and is legitimately SUPERSEDED with a coherent chain (%s).', $decisionId, implode(' -> ', $chain['chain'])),
            'details' => ['current_status' => 'SUPERSEDED', 'chain' => $chain['chain']],
        ];
    }

    /**
     * @param array{status: string, record: string, supersedes: list<string>, superseded_by: list<string>} $row
     * @param array{id: ?string, status: ?string, authority: string, declaration: ?array<string, string>, supersedes: list<string>, superseded_by: list<string>} $record
     * @return array{status: 'PASS', chain: list<string>}|array{status: 'FAIL', message: string}
     */
    private function verifySupersessionChain(GitRepository $repo, string $head, string $decisionId, array $row, array $record): array
    {
        $index = (string) $repo->showBlob($head, self::INDEX_PATH);
        $chain = [$decisionId];
        $visited = [$decisionId => true];
        /** @var list<array{id: string, row: array{status: string, record: string, supersedes: list<string>, superseded_by: list<string>}, record: array{id: ?string, status: ?string, authority: string, declaration: ?array<string, string>, supersedes: list<string>, superseded_by: list<string>}}> $queue */
        $queue = [['id' => $decisionId, 'row' => $row, 'record' => $record]];

        while ($queue !== []) {
            $node = array_shift($queue);
            if (count($visited) > self::MAX_CHAIN_DEPTH) {
                return $this->failChain('Supersession chain exceeds the maximum depth.');
            }

            $successors = $node['row']['superseded_by'];
            if ($successors === []) {
                return $this->failChain(sprintf('Decision "%s" is SUPERSEDED but names no successor in DECISIONS_INDEX.md.', $node['id']));
            }
            if ($node['record']['superseded_by'] !== $successors) {
                return $this->failChain(sprintf('Decision "%s" record and Index disagree on "Superseded By".', $node['id']));
            }

            foreach ($successors as $successorId) {
                if ($successorId === $node['id'] || isset($visited[$successorId])) {
                    return $this->failChain(sprintf('Supersession chain for "%s" contains a cycle at "%s".', $decisionId, $successorId));
                }
                $visited[$successorId] = true;

                $successorRow = $this->indexRow($index, $successorId);
                if ($successorRow === null) {
                    return $this->failChain(sprintf('Successor Decision "%s" is missing from DECISIONS_INDEX.md.', $successorId));
                }
                $successorRaw = $repo->showBlob($head, $successorRow['record']);
                if ($successorRaw === null) {
                    return $this->failChain(sprintf('Successor Decision record for "%s" does not exist.', $successorId));
                }
                $successorRecord = $this->parseRecord($successorRaw);
                if ($successorRecord['id'] !== $successorId || $successorRecord['status'] !== $successorRow['status']) {
                    return $this->failChain(sprintf('Successor Decision "%s" record and Index are inconsistent.', $successorId));
                }
                if (! in_array($node['id'], $successorRow['supersedes'], true) || ! in_array($node['id'], $successorRecord['supersedes'], true)) {
                    return $this->failChain(sprintf('Supersession is asymmetric: "%s" does not record that it supersedes "%s".', $successorId, $node['id']));
                }

                $chain[] = $successorId;
                if ($successorRow['status'] === 'SUPERSEDED') {
                    $queue[] = ['id' => $successorId, 'row' => $successorRow, 'record' => $successorRecord];
                } elseif ($successorRow['status'] !== 'ACTIVE') {
                    return $this->failChain(sprintf('Successor Decision "%s" has non-authoritative status "%s".', $successorId, $successorRow['status']));
                }
            }
        }

        return ['status' => 'PASS', 'chain' => $chain];
    }

    /**
     * @return array{id: ?string, status: ?string, authority: string, declaration: ?array<string, string>, supersedes: list<string>, superseded_by: list<string>}
     */
    public function parseRecord(string $markdown): array
    {
        $sections = [];
        $current = null;
        foreach (preg_split('/\R/', $markdown) ?: [] as $line) {
            if ((bool) preg_match('/^##\s+(.+?)\s*$/', $line, $m)) {
                $current = $m[1];
                $sections[$current] = '';
            } elseif ($current !== null) {
                $sections[$current] .= $line . "\n";
            }
        }

        $clean = static fn(string $value): string => trim(str_replace('`', '', $value));
        $idRaw = $clean($sections['Decision ID'] ?? '');
        $statusRaw = strtoupper($clean($sections['Status'] ?? ''));

        $declaration = null;
        if (isset($sections['Source-Only Delivery Declaration'])) {
            $declaration = [];
            foreach (explode("\n", $sections['Source-Only Delivery Declaration']) as $line) {
                if ((bool) preg_match('/^\s*[-*]\s+([A-Za-z][A-Za-z \-]*?)\s*:\s*(.*?)\s*$/', $line, $m)) {
                    $declaration[$m[1]] = $clean($m[2]);
                }
            }
        }

        return [
            'id' => $idRaw !== '' ? $idRaw : null,
            'status' => $statusRaw !== '' ? $statusRaw : null,
            'authority' => trim($sections['Decision Authority'] ?? ''),
            'declaration' => $declaration,
            'supersedes' => $this->extractIds($sections['Supersedes'] ?? ''),
            'superseded_by' => $this->extractIds($sections['Superseded By'] ?? ''),
        ];
    }

    /**
     * @return array{status: string, record: string, supersedes: list<string>, superseded_by: list<string>}|null
     */
    public function indexRow(string $indexMarkdown, string $decisionId): ?array
    {
        $found = null;
        foreach (preg_split('/\R/', $indexMarkdown) ?: [] as $line) {
            if (! (bool) preg_match('/^\|\s*\[' . preg_quote($decisionId, '/') . '\]\(([^)]+)\)\s*\|/', $line)) {
                continue;
            }
            $cells = array_map('trim', explode('|', trim($line, " \t|")));
            if (count($cells) < 8) {
                return null;
            }
            if ($found !== null) {
                return null; // duplicate rows: ambiguous
            }
            $link = (bool) preg_match('/\]\(([^)]+)\)/', $cells[4], $m) ? $m[1] : '';
            $found = [
                'status' => strtoupper(str_replace('`', '', $cells[2])),
                'record' => $link === '' ? '' : 'docs/decisions/' . ltrim($link, '/'),
                'supersedes' => $this->extractIds($cells[6]),
                'superseded_by' => $this->extractIds($cells[7]),
            ];
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function extractIds(string $text): array
    {
        preg_match_all('/DEC-\d+/', $text, $m);

        return array_values(array_unique($m[0]));
    }

    /**
     * @return array{status: 'FAIL', message: string}
     */
    private function fail(string $message): array
    {
        return ['status' => 'FAIL', 'message' => $message];
    }

    /**
     * @return array{status: 'FAIL', message: string}
     */
    private function failChain(string $message): array
    {
        return ['status' => 'FAIL', 'message' => $message];
    }
}
