<?php

namespace App\Services;

use App\Models\AbonnementUsager;
use App\Models\ReductionCard;
use App\Models\UserReductionCard;
use Illuminate\Support\Str;

class ReductionCardService
{
    public function assignCardsToSubscription(AbonnementUsager $abonnement): void
    {
        $cards = ReductionCard::where('forfait_usager_id', $abonnement->forfait_id)
            ->where('statut', 1)
            ->get();

        foreach ($cards as $card) {
            UserReductionCard::firstOrCreate(
                [
                    'reduction_card_id' => $card->id,
                    'abonnement_usager_id' => $abonnement->id,
                ],
                [
                    'user_id' => $abonnement->user_id,
                    'forfait_usager_id' => $abonnement->forfait_id,
                    'card_code' => $this->uniqueCardCode(),
                    'qr_code' => $this->uniqueQrCode(),
                    'date_debut' => $abonnement->date_debut,
                    'date_fin' => $abonnement->date_fin,
                    'statut' => 1,
                ]
            );
        }
    }

    protected function uniqueCardCode(): string
    {
        do {
            $code = 'RC-' . now()->format('dm') . '-' . strtoupper(Str::random(8));
        } while (UserReductionCard::where('card_code', $code)->exists());

        return $code;
    }

    protected function uniqueQrCode(): string
    {
        do {
            $code = 'TOOAUTO-REDUCTION-' . strtoupper(Str::random(18));
        } while (UserReductionCard::where('qr_code', $code)->exists());

        return $code;
    }
}
