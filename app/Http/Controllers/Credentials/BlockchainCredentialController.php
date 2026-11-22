<?php

namespace App\Http\Controllers\Credentials;

use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Models\CampusMembership;
use App\Models\DigitalCertificate;
use App\Models\MemberAchievement;
use App\Models\MemberAward;
use App\Services\Blockchain\BlockchainCredentialService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class BlockchainCredentialController extends Controller
{
    public function __construct(
        private readonly BlockchainCredentialService $blockchain,
    ) {}

    /**
     * Render each user's personal Blockchain-Protected Achievements, Awards & Certificates page.
     */
    public function index(Request $request): Response
    {
        $user = $request->user();

        $achievements = $user
            ? MemberAchievement::query()
                ->with(['campus:id,code,name', 'ledgerEntry'])
                ->where('user_id', $user->id)
                ->orderByDesc('achieved_on')
                ->get()
            : collect();

        $awards = $user
            ? MemberAward::query()
                ->with(['campus:id,code,name', 'issuer:id,name', 'ledgerEntry'])
                ->where('user_id', $user->id)
                ->orderByDesc('awarded_on')
                ->get()
            : collect();

        $certificates = $user
            ? DigitalCertificate::query()
                ->with(['campus:id,code,name', 'ledgerEntry'])
                ->where('user_id', $user->id)
                ->orderByDesc('issued_on')
                ->get()
            : collect();

        $membership = $user?->primaryMembership()->with('campus:id,code,name')->first();

        return Inertia::render('credentials/Index', [
            'owner' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'roleLabel' => $user->verifiedRole()?->label() ?? 'Peer Member',
                'campusName' => $membership?->campus?->name ?? 'CHMSU Talisay (Main Campus)',
                'courseYearSection' => $membership?->course_year_section,
            ] : null,
            'achievements' => $achievements,
            'awards' => $awards,
            'certificates' => $certificates,
            'chainIntegrity' => $this->blockchain->verifyChainIntegrity(),
            'blockchainNetwork' => [
                'driver' => (string) config('cpf.blockchain.driver', 'local_ledger'),
                'network' => (string) config('cpf.blockchain.network', 'cpf-local-chain'),
                'algorithm' => strtoupper((string) config('cpf.blockchain.hash_algorithm', 'sha256')),
            ],
        ]);
    }

    /**
     * Public verification endpoint for any CPF achievement, certificate, or award.
     */
    public function verify(Request $request, string $codeOrHash): JsonResponse
    {
        $result = $this->blockchain->verifyCredential($codeOrHash, $request->user(), $request->ip());

        return response()->json($result, $result['result'] === 'not_found' ? 404 : 200);
    }

    /**
     * Issue and anchor a member achievement onto the blockchain ledger.
     */
    public function storeAchievement(Request $request): JsonResponse
    {
        $role = $request->user()?->verifiedRole();
        abort_unless($role && $role->isOfficerOrAdviser(), 403, 'Only verified officers or the Club Adviser can anchor achievements.');

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:160'],
            'category' => ['required', 'string', 'max:60'],
            'achieved_on' => ['required', 'date'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->authorizeCampusCredentialIssuance($request, (int) $validated['campus_id'], (int) $validated['user_id']);

        $code = $this->blockchain->generateCode('CPF-ACH');

        $achievement = MemberAchievement::create([
            ...$validated,
            'achievement_code' => $code,
            'verified_by_user_id' => $request->user()->id,
        ]);

        $ledger = $this->blockchain->anchorCredential(
            $achievement,
            'achievement',
            $code,
            [
                'title' => $achievement->title,
                'category' => $achievement->category,
                'achieved_on' => $validated['achieved_on'],
                'description' => $achievement->description,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'anchored',
            'achievement' => $achievement->fresh(),
            'ledger' => $ledger,
        ], 201);
    }

    /**
     * Issue and anchor a digital certificate onto the blockchain ledger.
     */
    public function storeCertificate(Request $request): JsonResponse
    {
        $role = $request->user()?->verifiedRole();
        abort_unless($role && $role->isOfficerOrAdviser(), 403, 'Only verified officers or the Club Adviser can issue certificates.');

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:180'],
            'event_name' => ['required', 'string', 'max:180'],
            'issued_on' => ['required', 'date'],
            'file_hdd_path' => ['nullable', 'string', 'max:255'],
        ]);

        $this->authorizeCampusCredentialIssuance($request, (int) $validated['campus_id'], (int) $validated['user_id']);

        $code = $this->blockchain->generateCode('CPF-CERT');

        $certificate = DigitalCertificate::create([
            ...$validated,
            'certificate_code' => $code,
            'issued_by_user_id' => $request->user()->id,
        ]);

        $ledger = $this->blockchain->anchorCredential(
            $certificate,
            'certificate',
            $code,
            [
                'title' => $certificate->title,
                'event_name' => $certificate->event_name,
                'issued_on' => $validated['issued_on'],
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'anchored',
            'certificate' => $certificate->fresh(),
            'ledger' => $ledger,
        ], 201);
    }

    /**
     * Issue and anchor a member award onto the blockchain ledger.
     */
    public function storeAward(Request $request): JsonResponse
    {
        $role = $request->user()?->verifiedRole();
        abort_unless($role && $role->isOfficerOrAdviser(), 403, 'Only verified officers or the Club Adviser can issue awards.');

        $validated = $request->validate([
            'campus_id' => ['required', 'integer', 'exists:campuses,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'title' => ['required', 'string', 'max:180'],
            'award_category' => ['required', 'string', 'max:80'],
            'academic_year' => ['required', 'string', 'max:20'],
            'awarded_on' => ['required', 'date'],
            'citation' => ['nullable', 'string', 'max:2000'],
        ]);

        $this->authorizeCampusCredentialIssuance($request, (int) $validated['campus_id'], (int) $validated['user_id']);

        $code = $this->blockchain->generateCode('CPF-AWD');

        $award = MemberAward::create([
            ...$validated,
            'award_code' => $code,
            'issued_by_user_id' => $request->user()->id,
        ]);

        $ledger = $this->blockchain->anchorCredential(
            $award,
            'award',
            $code,
            [
                'title' => $award->title,
                'award_category' => $award->award_category,
                'academic_year' => $award->academic_year,
                'awarded_on' => $validated['awarded_on'],
                'citation' => $award->citation,
            ],
            $request->user(),
        );

        return response()->json([
            'status' => 'anchored',
            'award' => $award->fresh(),
            'ledger' => $ledger,
        ], 201);
    }

    /**
     * Audit the integrity of the entire blockchain credential ledger.
     */
    public function auditChain(Request $request): JsonResponse
    {
        $role = $request->user()?->verifiedRole();
        abort_unless($role && $role->isOfficerOrAdviser(), 403, 'Only verified officers or the Club Adviser can audit the ledger.');

        return response()->json($this->blockchain->verifyChainIntegrity());
    }

    private function authorizeCampusCredentialIssuance(Request $request, int $campusId, int $targetUserId): void
    {
        $membership = $request->user()?->primaryMembership;
        abort_unless(
            $membership !== null && $campusId === $membership->campus_id,
            403,
            'You can only issue credentials for your assigned campus.',
        );

        $recipientApproved = CampusMembership::query()
            ->where('campus_id', $membership->campus_id)
            ->where('user_id', $targetUserId)
            ->where('status', MembershipStatus::Approved->value)
            ->exists();

        abort_unless(
            $recipientApproved,
            422,
            'Credential recipient must be an approved member of your campus.',
        );
    }
}
