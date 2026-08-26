<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReductionCardHistory;
use App\Models\UserReductionCard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReductionCardController extends Controller
{
    public function verify(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_code' => 'required_without:qr_code|nullable|string|max:50',
            'qr_code' => 'required_without:card_code|nullable|string|max:100',
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $cardValidation = $this->findValidCard($request);

        if (!$cardValidation['success']) {
            return response()->json([
                'success' => false,
                'message' => $cardValidation['message'],
            ], $cardValidation['status']);
        }

        return response()->json([
            'success' => true,
            'message' => 'Carte de réduction valide.',
            'data' => $this->formatVerifiedCard($cardValidation['card']),
        ]);
    }

    public function apply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_code' => 'required_without:qr_code|nullable|string|max:50',
            'qr_code' => 'required_without:card_code|nullable|string|max:100',
            'montant_initial' => 'required|numeric|min:0',
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'applied_by_id' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $cardValidation = $this->findValidCard($request);

        if (!$cardValidation['success']) {
            return response()->json([
                'success' => false,
                'message' => $cardValidation['message'],
            ], $cardValidation['status']);
        }

        $userCard = $cardValidation['card'];
        $reductionCard = $userCard->reductionCard;
        $montantInitial = round((float) $request->montant_initial, 2);
        $discountType = $reductionCard->discount_type;
        $discountValue = round((float) $reductionCard->discount_value, 2);
        $montantReduction = $this->calculateReduction($montantInitial, $discountType, $discountValue);
        $montantFinal = round($montantInitial - $montantReduction, 2);

        $history = DB::transaction(function () use ($request, $userCard, $reductionCard, $discountType, $discountValue, $montantInitial, $montantReduction, $montantFinal) {
            return ReductionCardHistory::create([
                'user_reduction_card_id' => $userCard->id,
                'reduction_card_id' => $reductionCard->id,
                'user_id' => $userCard->user_id,
                'abonnement_usager_id' => $userCard->abonnement_usager_id,
                'forfait_usager_id' => $userCard->forfait_usager_id,
                'discount_type' => $discountType,
                'discount_value' => $discountValue,
                'montant_initial' => $montantInitial,
                'montant_reduction' => $montantReduction,
                'montant_final' => $montantFinal,
                'applied_by_id' => $request->applied_by_id,
                'establishment_type' => $request->establishment_type,
                'establishment_id' => $request->establishment_id,
                'notes' => $request->notes,
                'used_at' => now(),
            ]);
        });

        $history->load(['user', 'reductionCard', 'appliedBy']);

        return response()->json([
            'success' => true,
            'message' => 'Réduction appliquée avec succès.',
            'data' => [
                'montant_initial' => $montantInitial,
                'montant_reduction' => $montantReduction,
                'montant_final' => $montantFinal,
                'usager' => $this->formatUser($userCard->user),
                'carte' => $this->formatReductionCard($reductionCard),
                'history' => $this->formatHistory($history),
            ],
        ], 201);
    }

    public function histories(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'user_id' => 'nullable|integer|min:1',
            'usager' => 'nullable|string|max:150',
            'reduction_card_id' => 'nullable|integer|min:1',
            'type_carte' => 'nullable|string|max:150',
            'montant' => 'nullable|numeric|min:0',
            'montant_min' => 'nullable|numeric|min:0',
            'montant_max' => 'nullable|numeric|min:0',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $query = ReductionCardHistory::with(['user', 'reductionCard', 'appliedBy'])
            ->where('establishment_type', $request->establishment_type)
            ->where('establishment_id', $request->establishment_id);

        if ($request->filled('date_debut')) {
            $query->where('used_at', '>=', $request->date('date_debut')->startOfDay());
        }

        if ($request->filled('date_fin')) {
            $query->where('used_at', '<=', $request->date('date_fin')->endOfDay());
        }

        if ($request->filled('user_id')) {
            $query->where('user_id', $request->user_id);
        }

        if ($request->filled('usager')) {
            $search = $request->usager;
            $query->whereHas('user', function ($subQuery) use ($search) {
                $subQuery->where('name', 'like', "%{$search}%")
                    ->orWhere('nom', 'like', "%{$search}%")
                    ->orWhere('prenoms', 'like', "%{$search}%")
                    ->orWhere('mobile', 'like', "%{$search}%");
            });
        }

        if ($request->filled('reduction_card_id')) {
            $query->where('reduction_card_id', $request->reduction_card_id);
        }

        if ($request->filled('type_carte')) {
            $search = $request->type_carte;
            $searchableColumns = collect(['name', 'nom', 'libelle'])
                ->filter(fn ($column) => Schema::hasColumn('reduction_cards', $column))
                ->values();

            if ($searchableColumns->isNotEmpty()) {
                $query->whereHas('reductionCard', function ($subQuery) use ($search, $searchableColumns) {
                    $subQuery->where(function ($nameQuery) use ($search, $searchableColumns) {
                        foreach ($searchableColumns as $index => $column) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $nameQuery->{$method}($column, 'like', "%{$search}%");
                        }
                    });
                });
            }
        }

        if ($request->filled('montant')) {
            $query->where('montant_initial', $request->montant);
        }

        if ($request->filled('montant_min')) {
            $query->where('montant_initial', '>=', $request->montant_min);
        }

        if ($request->filled('montant_max')) {
            $query->where('montant_initial', '<=', $request->montant_max);
        }

        $histories = $query->orderByDesc('used_at')
            ->paginate((int) $request->input('per_page', 20));

        $histories->getCollection()->transform(fn ($history) => $this->formatHistory($history));

        return response()->json([
            'success' => true,
            'message' => 'Historique des réductions récupéré avec succès.',
            'data' => $histories,
        ]);
    }

    protected function findValidCard(Request $request): array
    {
        $userCard = UserReductionCard::with(['user', 'reductionCard'])
            ->when($request->filled('card_code'), fn ($query) => $query->where('card_code', $request->card_code))
            ->when($request->filled('qr_code'), fn ($query) => $query->where('qr_code', $request->qr_code))
            ->first();

        if (!$userCard) {
            return [
                'success' => false,
                'message' => 'Carte de réduction introuvable.',
                'status' => 404,
            ];
        }

        if ((int) $userCard->statut !== 1) {
            return [
                'success' => false,
                'message' => 'Carte de réduction inactive.',
                'status' => 422,
            ];
        }

        $today = now()->toDateString();

        if ($userCard->date_debut && $userCard->date_debut->toDateString() > $today) {
            return [
                'success' => false,
                'message' => 'Carte de réduction pas encore valide.',
                'status' => 422,
            ];
        }

        if ($userCard->date_fin && $userCard->date_fin->toDateString() < $today) {
            return [
                'success' => false,
                'message' => 'Carte de réduction expirée.',
                'status' => 422,
            ];
        }

        if (!$userCard->reductionCard) {
            return [
                'success' => false,
                'message' => 'Configuration de carte de réduction introuvable.',
                'status' => 422,
            ];
        }

        if ((int) $userCard->reductionCard->statut !== 1) {
            return [
                'success' => false,
                'message' => 'Configuration de carte de réduction inactive.',
                'status' => 422,
            ];
        }

        if (!in_array($userCard->reductionCard->discount_type, ['percentage', 'fixed'], true)) {
            return [
                'success' => false,
                'message' => 'Type de réduction invalide sur la configuration.',
                'status' => 422,
            ];
        }

        return [
            'success' => true,
            'card' => $userCard,
        ];
    }

    protected function calculateReduction(float $montantInitial, string $discountType, float $discountValue): float
    {
        $montantReduction = $discountType === 'percentage'
            ? $montantInitial * $discountValue / 100
            : $discountValue;

        return round(min($montantReduction, $montantInitial), 2);
    }

    protected function formatVerifiedCard(UserReductionCard $userCard): array
    {
        return [
            'usager' => $this->formatUser($userCard->user),
            'carte' => $this->formatReductionCard($userCard->reductionCard),
            'type_reduction' => $userCard->reductionCard->discount_type,
            'valeur_reduction' => (float) $userCard->reductionCard->discount_value,
            'date_debut' => optional($userCard->date_debut)->toDateString(),
            'date_fin' => optional($userCard->date_fin)->toDateString(),
            'forfait_lie' => [
                'abonnement_usager_id' => $userCard->abonnement_usager_id,
                'forfait_usager_id' => $userCard->forfait_usager_id,
            ],
            'user_reduction_card' => [
                'id' => $userCard->id,
                'card_code' => $userCard->card_code,
                'qr_code' => $userCard->qr_code,
            ],
        ];
    }

    protected function formatHistory(ReductionCardHistory $history): array
    {
        return [
            'id' => $history->id,
            'date' => optional($history->used_at)->toDateTimeString(),
            'usager' => $this->formatUser($history->user),
            'carte' => $this->formatReductionCard($history->reductionCard),
            'montant_initial' => (float) $history->montant_initial,
            'reduction' => [
                'type' => $history->discount_type,
                'value' => (float) $history->discount_value,
                'montant' => (float) $history->montant_reduction,
            ],
            'montant_final' => (float) $history->montant_final,
            'applique_par' => $this->formatLavage($history->appliedBy),
            'establishment_type' => $history->establishment_type,
            'establishment_id' => $history->establishment_id,
            'notes' => $history->notes,
        ];
    }

    protected function formatReductionCard($card): ?array
    {
        if (!$card) {
            return null;
        }

        return [
            'id' => $card->id,
            'nom' => $card->nom ?? $card->name ?? $card->libelle ?? null,
            'discount_type' => $card->discount_type,
            'discount_value' => (float) $card->discount_value,
            'statut' => $card->statut,
            'raw' => $card->toArray(),
        ];
    }

    protected function formatUser($user): ?array
    {
        if (!$user) {
            return null;
        }

        return [
            'id' => $user->id,
            'nom' => trim(($user->nom ?? '') . ' ' . ($user->prenoms ?? '')) ?: ($user->name ?? null),
            'name' => $user->name,
            'mobile' => $user->mobile,
            'email' => $user->email,
        ];
    }

    protected function formatLavage($lavage): ?array
    {
        if (!$lavage) {
            return null;
        }

        return [
            'id' => $lavage->id,
            'nom' => trim(($lavage->first_name ?? '') . ' ' . ($lavage->last_name ?? '')),
            'mobile' => $lavage->mobile,
            'email' => $lavage->email,
        ];
    }
}
