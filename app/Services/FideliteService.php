<?php

namespace App\Services;

use App\Models\AttributionVehicule;
use App\Models\Fidelite;
use App\Models\FidelitePassage;
use App\Models\Lavage;
use App\Models\Recompense;
use App\Models\StationLavage;
use App\Models\User;
use App\Models\Vehicule;
use Illuminate\Support\Facades\DB;

class FideliteService
{
    public function recordCompletedAttribution(
        AttributionVehicule $attribution,
        Lavage $manager,
        StationLavage $station
    ): array {
        return DB::transaction(function () use ($attribution, $manager, $station) {
            $lockedAttribution = AttributionVehicule::whereKey($attribution->id)
                ->lockForUpdate()
                ->first() ?? $attribution;

            $existingPassage = FidelitePassage::where('attribution_vehicule_id', $lockedAttribution->id)->first();
            if ($existingPassage) {
                return [
                    'fidelite' => $this->currentCardFor($existingPassage->fidelite),
                    'recompense_attribuee' => false,
                    'already_recorded' => true,
                ];
            }

            $vehicle = $lockedAttribution->vehicule;
            $userId = $vehicle?->user_id;
            if (!$userId) {
                throw new \RuntimeException('Le véhicule de cette attribution n’a pas d’usager associé.');
            }

            return $this->recordPassage(
                (int) $userId,
                $manager,
                $station,
                $lockedAttribution->matricule_vehicule,
                $lockedAttribution->type_lavage_id,
                'attribution',
                (int) $lockedAttribution->id
            );
        });
    }

    public function recordManualPassage(
        User $user,
        Lavage $manager,
        StationLavage $station,
        Vehicule $vehicle
    ): array {
        return $this->recordPassage(
            (int) $user->id,
            $manager,
            $station,
            $vehicle->matricule,
            null,
            'manual'
        );
    }

    protected function recordPassage(
        int $userId,
        Lavage $manager,
        StationLavage $station,
        string $matricule,
        ?int $typeLavageId,
        string $source,
        ?int $attributionId = null
    ): array {
        return DB::transaction(function () use ($userId, $manager, $station, $matricule, $typeLavageId, $source, $attributionId) {
            Vehicule::where('matricule', $matricule)->lockForUpdate()->first();

            if ($attributionId) {
                $duplicate = FidelitePassage::where('attribution_vehicule_id', $attributionId)->first();
                if ($duplicate) {
                    return [
                        'fidelite' => $this->currentCardFor($duplicate->fidelite),
                        'recompense_attribuee' => false,
                        'already_recorded' => true,
                    ];
                }
            }

            $fidelite = Fidelite::where('usager_id', $userId)
                ->where('lavage_id', $manager->id)
                ->where('station_lavage_id', $station->id)
                ->where('matricule_vehicule', $matricule)
                ->where('statut', 'en_cours')
                ->lockForUpdate()
                ->latest('id')
                ->first();

            if (!$fidelite) {
                $fidelite = Fidelite::create([
                    'usager_id' => $userId,
                    'lavage_id' => $manager->id,
                    'station_lavage_id' => $station->id,
                    'matricule_vehicule' => $matricule,
                    'cases_remplies' => 0,
                    'total_cases' => max(1, (int) ($manager->fidelite_total_cases ?: 10)),
                    'recompenses_gagnees' => 0,
                    'statut' => 'en_cours',
                ]);
            }

            FidelitePassage::create([
                'fidelite_id' => $fidelite->id,
                'attribution_vehicule_id' => $attributionId,
                'type_lavage_id' => $typeLavageId,
                'source' => $source,
                'used_at' => now(),
            ]);

            $fidelite->cases_remplies++;
            $recompenseAttribuee = false;
            $cardForResponse = $fidelite;

            if ($fidelite->isCarteComplete()) {
                $fidelite->statut = 'complete';
                $fidelite->completed_at = now();
                $fidelite->derniere_recompense = now();
                $fidelite->recompenses_gagnees++;
                $fidelite->save();

                Recompense::create([
                    'usager_id' => $userId,
                    'lavage_id' => $manager->id,
                    'matricule_vehicule' => $matricule,
                    'type_recompense' => 'lavage_gratuit',
                    'description' => 'Lavage gratuit offert pour avoir complété une carte de fidélité',
                    'valeur' => 0.00,
                    'statut' => 'attribuee',
                    'date_attribution' => now(),
                    'utilisee' => false,
                ]);

                $cardForResponse = Fidelite::create([
                    'usager_id' => $userId,
                    'lavage_id' => $manager->id,
                    'station_lavage_id' => $station->id,
                    'matricule_vehicule' => $matricule,
                    'cases_remplies' => 0,
                    'total_cases' => $fidelite->total_cases,
                    'recompenses_gagnees' => 0,
                    'statut' => 'en_cours',
                ]);

                $recompenseAttribuee = true;
            } else {
                $fidelite->save();
            }

            return [
                'fidelite' => $cardForResponse,
                'completed_card' => $recompenseAttribuee ? $fidelite : null,
                'recompense_attribuee' => $recompenseAttribuee,
                'already_recorded' => false,
            ];
        });
    }

    protected function currentCardFor(?Fidelite $card): ?Fidelite
    {
        if (!$card) {
            return null;
        }

        return Fidelite::where('usager_id', $card->usager_id)
            ->where('lavage_id', $card->lavage_id)
            ->where('station_lavage_id', $card->station_lavage_id)
            ->where('matricule_vehicule', $card->matricule_vehicule)
            ->where('statut', 'en_cours')
            ->latest('id')
            ->first() ?? $card;
    }
}
