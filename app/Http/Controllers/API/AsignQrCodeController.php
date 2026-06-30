<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;

use App\Models\QrcodeGenerate;
use App\Models\QrcodeAssignment;
use App\Models\StationLavage;
use App\Models\Vehicule;
use App\Models\User;
use App\Models\Lavage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AsignQrCodeController extends Controller
{
    public function assignByScan(Request $request)
	{
		try {
			Log::info('Tentative d\'attribution de QR code', ['request' => $request->all()]);

			// Validation des données d'entrée
			$validated = $request->validate([
				'qrcode' => 'required|string|max:255',
				'matricule' => 'required|string|max:50',
			]);

			// Vérification de l'authentification
			$lavage = auth('api')->user();
			if (!$lavage) {
				Log::warning('Tentative d\'attribution sans authentification');
				return response()->json([
					'status' => 'error',
					'message' => 'Lavage non authentifiée'
				], 401);
			}

			// Recherche du véhicule
			$vehicule = Vehicule::where(['matricule' => $validated['matricule']])->first();
			if (!$vehicule) {
				Log::warning('Véhicule non trouvé', ['matricule' => $validated['matricule']]);
				return response()->json([
					'status' => 'error',
					'message' => 'Véhicule introuvable.'
				], 404);
			}
			
			if ($vehicule->qrcode_generate_id) {
                Log::warning('Ce véhicule a déjà un QR code attribué', ['matricule' => $vehicule->matricule]);

                return response()->json([
                    'status' => 'error',
                    'message' => 'Ce véhicule (matricule : '.$vehicule->matricule.') a déjà un QR code attribué. Un véhicule ne peut avoir qu\'un seul QR code.',
                ], 409);
            }

			// Recherche du QR code
			Log::info('Recherche du QR code', ['qrcode' => $validated['qrcode']]);
			$qrcode = QrcodeGenerate::where('qrcode', $validated['qrcode'])->first();

			if (!$qrcode) {
				Log::warning('QR code non trouvé', ['qrcode' => $validated['qrcode']]);
				return response()->json([
					'status' => 'error',
					'message' => 'QR code invalide'
				], 404);
			}

			if ($qrcode->is_assigned) {
				Log::warning('QR code déjà attribué', ['qrcode' => $qrcode->qrcode]);
				return response()->json([
					'status' => 'error',
					'message' => 'Ce QR code est déjà attribué'
				], 409);
			}

			// Recherche de la station service associée
			$stationLavage = StationLavage::where(['created_by' => $station->id])
				->orWhere('created_by', $station->created_by)
				->first();

			if (!$stationLavage) {
				Log::warning('Station de lavage non trouvée', ['lavage_id' => $lavage->id]);
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvée.'
				], 404);
			}

			// Traitement de l'attribution du QR code
			DB::beginTransaction();

			try {
				Log::info('Début de la transaction pour l\'attribution du QR code', [
					'lavage_id' => $lavage->id,
					'qrcode_id' => $qrcode->id,
					'vehicule_id' => $vehicule->id
				]);

				// Création de l'historique d'attribution
				$assignment = QrcodeAssignment::create([
					'lavage_id' => $station->id,
					'qrcode_id' => $qrcode->id,
					'station_de_lavage_id' => $stationService->id,
					'user_id' => $vehicule->user_id,
					'assigned_at' => now(),
				]);

				// Mise à jour du statut du QR code
				$qrcode->update([
					'is_assigned' => true,
					'assigned_at' => now(),
				]);

				// Association du QR code au véhicule/usager
				$vehicule->qrcode_generate_id = $qrcode->id;
				$vehicule->save();

				DB::commit();

				Log::info('QR code attribué avec succès', [
					'qrcode' => $qrcode->qrcode,
					'lavage' => $lavage->id,
					'vehicule' => $vehicule->matricule
				]);

				return response()->json([
					'status' => 'success',
					'message' => 'QR code attribué et historisé avec succès',
					'data' => [
						'qrcode' => $qrcode->qrcode,
						'lavage' => $lavage->first_name . ' ' . $lavage->last_name,
						'assigned_at' => $assignment->assigned_at,
						'vehicule' => $vehicule->matricule
					]
				], 201);

			} catch (\Exception $e) {
				DB::rollBack();
				Log::error('Erreur lors de l\'attribution du QR code', [
					'error' => $e->getMessage(),
					'trace' => $e->getTraceAsString()
				]);
				return response()->json([
					'status' => 'error',
					'message' => 'Erreur lors de l\'attribution du QR code',
					'error' => $e->getMessage()
				], 500);
			}

		} catch (\Illuminate\Validation\ValidationException $e) {
			Log::warning('Validation échouée', ['errors' => $e->errors()]);
			return response()->json([
				'status' => 'error',
				'message' => 'Données invalides',
				'errors' => $e->errors()
			], 422);
		} catch (\Exception $e) {
			Log::error('Erreur inattendue', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Une erreur inattendue est survenue',
				'error' => $e->getMessage()
			], 500);
		}
	}

    public function historyByStation($station_de_lavage_id)
	{
		try {
			$lavage = auth('api')->user();

			if (!$lavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station non authentifiée'
				], 401);
			}

			$StationLavage = StationLavage::where('id', $station_de_lavage_id)
				->where(function ($query) use ($lavage) {
					$query->where('created_by', $lavage->id)
						  ->orWhere('created_by', $lavage->created_by);
				})->first();

			if (!$StationLavage) {
				return response()->json([
					'status' => 'error',
					'message' => 'Station de lavage non trouvé.'
				], 404);
			}

			$assignments = QrcodeAssignment::with(['qrcode', 'station'])
				->where('station_de_lavage_id', $StationLavage->id)
				->with('user.vehicules')
				->orderBy('assigned_at', 'desc')
				->get();

			return response()->json([
				'status' => 'success',
				'message' => $assignments->isEmpty() ? 'Aucune attribution trouvée' : 'Historique récupéré avec succès',
				'data' => [
					'history' => $assignments
				]
			], 200);

		} catch (\Exception $e) {
			Log::error('Erreur lors de la récupération de l\'historique', [
				'error' => $e->getMessage(),
				'trace' => $e->getTraceAsString()
			]);
			return response()->json([
				'status' => 'error',
				'message' => 'Erreur lors de la récupération de l\'historique',
				'error' => $e->getMessage()
			], 500);
		}
	}

}