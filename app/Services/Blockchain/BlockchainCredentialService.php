<?php

namespace App\Services\Blockchain;

use App\Models\BlockchainCredentialLedger;
use App\Models\BlockchainVerificationLog;
use App\Models\DigitalCertificate;
use App\Models\MemberAchievement;
use App\Models\MemberAward;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class BlockchainCredentialService
{
    public const GENESIS_HASH = '0000000000000000000000000000000000000000000000000000000000000000';

    /**
     * Anchor a member achievement, digital certificate, or award onto the
     * immutable hash-chained CPF Blockchain Credential Ledger.
     *
     * @param  MemberAchievement|DigitalCertificate|MemberAward  $credential
     */
    public function anchorCredential(
        Model $credential,
        string $credentialType,
        string $credentialCode,
        array $payload,
        ?User $issuer = null,
    ): BlockchainCredentialLedger {
        $algorithm = (string) config('cpf.blockchain.hash_algorithm', 'sha256');
        $driver = (string) config('cpf.blockchain.driver', 'local_ledger');
        $network = (string) config('cpf.blockchain.network', 'cpf-local-chain');
        $contractAddress = config('cpf.blockchain.contract_address');

        $canonicalPayload = $this->canonicalizePayload([
            'credential_type' => $credentialType,
            'credential_code' => $credentialCode,
            'campus_id' => (int) $credential->getAttribute('campus_id'),
            'recipient_user_id' => (int) $credential->getAttribute('user_id'),
            'issuer_user_id' => $issuer?->id,
            'data' => $payload,
        ]);

        $payloadJson = json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payloadHash = hash($algorithm, (string) $payloadJson);

        return DB::transaction(function () use (
            $credential,
            $credentialType,
            $credentialCode,
            $canonicalPayload,
            $payloadHash,
            $algorithm,
            $driver,
            $network,
            $contractAddress,
            $issuer,
        ): BlockchainCredentialLedger {
            $latest = BlockchainCredentialLedger::query()
                ->orderByDesc('block_index')
                ->lockForUpdate()
                ->first();

            $blockIndex = $latest ? $latest->block_index + 1 : 1;
            $previousBlockHash = $latest ? $latest->block_hash : self::GENESIS_HASH;
            $anchoredAt = now()->utc();

            $blockHash = $this->computeBlockHash(
                $blockIndex,
                $previousBlockHash,
                $payloadHash,
                $anchoredAt->toIso8601String(),
                $algorithm,
            );

            $txHash = $driver === 'local_ledger'
                ? '0x'.hash($algorithm, "cpf-tx|{$blockIndex}|{$blockHash}")
                : null;

            $entry = BlockchainCredentialLedger::create([
                'block_index' => $blockIndex,
                'campus_id' => (int) $credential->getAttribute('campus_id'),
                'recipient_user_id' => (int) $credential->getAttribute('user_id'),
                'issuer_user_id' => $issuer?->id,
                'credential_type' => $credentialType,
                'credential_id' => (int) $credential->getKey(),
                'credential_code' => $credentialCode,
                'canonical_payload' => $canonicalPayload,
                'payload_hash' => $payloadHash,
                'previous_block_hash' => $previousBlockHash,
                'block_hash' => $blockHash,
                'driver' => $driver,
                'network' => $network,
                'transaction_hash' => $txHash,
                'contract_address' => $contractAddress ?: null,
                'status' => $driver === 'local_ledger' ? 'anchored' : 'pending',
                'anchored_at' => $anchoredAt,
            ]);

            $credential->update([
                'blockchain_status' => $entry->status,
                'credential_hash' => $payloadHash,
                'blockchain_ledger_id' => $entry->id,
            ]);

            return $entry;
        });
    }

    /**
     * Publicly verify a credential by its code, payload hash, or block hash,
     * checking payload integrity, block hash, chain linkage, and revocation.
     *
     * @return array<string, mixed>
     */
    public function verifyCredential(string $codeOrHash, ?User $verifier = null, ?string $ip = null): array
    {
        $needle = trim($codeOrHash);
        $entry = BlockchainCredentialLedger::query()
            ->with(['campus:id,code,name', 'recipient:id,name', 'issuer:id,name'])
            ->where('credential_code', $needle)
            ->orWhere('payload_hash', $needle)
            ->orWhere('block_hash', $needle)
            ->orWhere('transaction_hash', $needle)
            ->first();

        if (! $entry) {
            BlockchainVerificationLog::create([
                'ledger_id' => null,
                'queried_identifier' => Str::limit($needle, 128, ''),
                'verified_by_user_id' => $verifier?->id,
                'verifier_ip' => $ip,
                'verification_result' => 'not_found',
                'checked_at' => now(),
            ]);

            return [
                'verified' => false,
                'result' => 'not_found',
                'message' => 'No blockchain credential record matches this code or hash.',
            ];
        }

        $algorithm = (string) config('cpf.blockchain.hash_algorithm', 'sha256');
        $canonicalPayload = $this->canonicalizePayload($entry->canonical_payload ?? []);
        $recomputedPayloadHash = hash($algorithm, (string) json_encode($canonicalPayload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $recomputedBlockHash = $this->computeBlockHash(
            $entry->block_index,
            $entry->previous_block_hash,
            $entry->payload_hash,
            $entry->anchored_at->utc()->toIso8601String(),
            $algorithm,
        );

        $previousLinkValid = true;
        if ($entry->block_index === 1) {
            $previousLinkValid = $entry->previous_block_hash === self::GENESIS_HASH;
        } else {
            $prevEntry = BlockchainCredentialLedger::query()
                ->where('block_index', $entry->block_index - 1)
                ->first();
            $previousLinkValid = $prevEntry !== null && $prevEntry->block_hash === $entry->previous_block_hash;
        }

        $integrityValid = hash_equals($entry->payload_hash, $recomputedPayloadHash)
            && hash_equals($entry->block_hash, $recomputedBlockHash)
            && $previousLinkValid;

        if ($entry->status === 'revoked' || $entry->revoked_at !== null) {
            $result = 'revoked';
        } elseif (! $integrityValid) {
            $result = 'tampered';
        } else {
            $result = 'valid';
        }

        BlockchainVerificationLog::create([
            'ledger_id' => $entry->id,
            'queried_identifier' => Str::limit($needle, 128, ''),
            'verified_by_user_id' => $verifier?->id,
            'verifier_ip' => $ip,
            'verification_result' => $result,
            'checked_at' => now(),
        ]);

        return [
            'verified' => $result === 'valid',
            'result' => $result,
            'block_index' => $entry->block_index,
            'credential_type' => $entry->credential_type,
            'credential_code' => $entry->credential_code,
            'campus' => $entry->campus?->name,
            'recipient' => $entry->recipient?->name,
            'issuer' => $entry->issuer?->name,
            'network' => $entry->network,
            'driver' => $entry->driver,
            'payload_hash' => $entry->payload_hash,
            'block_hash' => $entry->block_hash,
            'previous_block_hash' => $entry->previous_block_hash,
            'transaction_hash' => $entry->transaction_hash,
            'anchored_at' => $entry->anchored_at->toIso8601String(),
            'revoked_at' => $entry->revoked_at?->toIso8601String(),
            'revocation_reason' => $entry->revocation_reason,
            'payload' => $entry->canonical_payload['data'] ?? [],
        ];
    }

    /**
     * Walk the entire ledger chain and verify every block hash and link.
     *
     * @return array{valid: bool, total_blocks: int, broken_blocks: array<int, int>}
     */
    public function verifyChainIntegrity(): array
    {
        $algorithm = (string) config('cpf.blockchain.hash_algorithm', 'sha256');
        $blocks = BlockchainCredentialLedger::query()->orderBy('block_index')->get();

        $expectedPrevHash = self::GENESIS_HASH;
        $broken = [];

        foreach ($blocks as $block) {
            $recomputedPayloadHash = hash(
                $algorithm,
                (string) json_encode($this->canonicalizePayload($block->canonical_payload ?? []), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            );
            $recomputedBlockHash = $this->computeBlockHash(
                $block->block_index,
                $block->previous_block_hash,
                $block->payload_hash,
                $block->anchored_at->utc()->toIso8601String(),
                $algorithm,
            );

            if (
                ! hash_equals($expectedPrevHash, $block->previous_block_hash)
                || ! hash_equals($block->payload_hash, $recomputedPayloadHash)
                || ! hash_equals($block->block_hash, $recomputedBlockHash)
            ) {
                $broken[] = $block->block_index;
            }

            $expectedPrevHash = $block->block_hash;
        }

        return [
            'valid' => count($broken) === 0,
            'total_blocks' => $blocks->count(),
            'broken_blocks' => $broken,
        ];
    }

    public function generateCode(string $prefix): string
    {
        return strtoupper($prefix).'-'.now()->format('Y').'-'.strtoupper(Str::random(8));
    }

    private function computeBlockHash(
        int $blockIndex,
        string $previousBlockHash,
        string $payloadHash,
        string $anchoredAtIso,
        string $algorithm,
    ): string {
        return hash($algorithm, "{$blockIndex}|{$previousBlockHash}|{$payloadHash}|{$anchoredAtIso}");
    }

    /**
     * Recursively sort array keys for deterministic SHA-256 hashing.
     *
     * @param  array<string|int, mixed>  $data
     * @return array<string|int, mixed>
     */
    private function canonicalizePayload(array $data): array
    {
        ksort($data);
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $data[$key] = $this->canonicalizePayload($value);
            }
        }

        return $data;
    }
}
