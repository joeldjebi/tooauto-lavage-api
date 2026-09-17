<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\ReductionCampaign;
use App\Models\ReductionCampaignUsage;
use App\Models\Type_lavage;
use App\Models\UserReductionCard;
use App\Services\WasabiService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class ReductionCampaignController extends Controller
{
    public function __construct(protected WasabiService $wasabiService)
    {
    }

    public function index(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'statut' => 'nullable|integer|in:0,1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $query = ReductionCampaign::where('establishment_type', $request->establishment_type)
            ->where('establishment_id', $request->establishment_id);

        if ($request->filled('statut')) {
            $query->where('statut', $request->statut);
        }

        $campaigns = $query->orderByDesc('created_at')
            ->paginate((int) $request->input('per_page', 20));

        $campaigns->getCollection()->transform(fn ($campaign) => $this->formatCampaign($campaign));

        return response()->json([
            'success' => true,
            'message' => 'Campagnes récupérées avec succès.',
            'data' => $campaigns,
        ]);
    }

    public function store(Request $request)
    {
        $validator = $this->campaignValidator($request);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = $validator->validated();
        $productOrService = $this->prepareProductOrService($request);
        if (!$productOrService['success']) {
            return $this->businessError($productOrService);
        }

        $data['product_or_service'] = $productOrService['value'];
        $data['quantity_used'] = 0;
        $data['created_by'] = auth('api')->id();

        $data['discount_type'] = $this->normalizeDiscountType($data['discount_type']);
        $discountError = $this->validateDiscountValue(
            (float) $data['normal_price'],
            (float) $data['discount_value'],
            $data['discount_type']
        );
        if ($discountError) {
            return $discountError;
        }

        $data['promotional_price'] = $this->promotionalPriceFromDiscount(
            (float) $data['normal_price'],
            (float) $data['discount_value'],
            $data['discount_type']
        );
        $data['statut'] = (int) ($data['statut'] ?? 1);

        $imageUpload = $this->uploadCampaignImage($request);
        if (!$imageUpload['success']) {
            return $this->businessError($imageUpload);
        }

        if ($imageUpload['path']) {
            $data['image'] = $imageUpload['path'];
        }

        $campaign = ReductionCampaign::create($data);

        return response()->json([
            'success' => true,
            'message' => 'Campagne créée avec succès.',
            'data' => $this->formatCampaign($campaign),
        ], 201);
    }

    public function show(ReductionCampaign $reductionCampaign)
    {
        $reductionCampaign->loadCount('usages');

        return response()->json([
            'success' => true,
            'message' => 'Campagne récupérée avec succès.',
            'data' => $this->formatCampaign($reductionCampaign),
        ]);
    }

    public function update(Request $request, ReductionCampaign $reductionCampaign)
    {
        $validator = $this->campaignValidator($request, true);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $data = $validator->validated();

        if ($request->has('product_or_service')) {
            $productOrService = $this->prepareProductOrService($request);
            if (!$productOrService['success']) {
                return $this->businessError($productOrService);
            }

            $data['product_or_service'] = $productOrService['value'];
        }

        $lockedFields = ['discount_type', 'discount_value', 'normal_price', 'promotional_price'];
        if ($reductionCampaign->usages()->exists() && collect($lockedFields)->contains(fn ($field) => array_key_exists($field, $data))) {
            return response()->json([
                'success' => false,
                'message' => 'Les prix et la réduction ne peuvent plus être modifiés après une première utilisation de la campagne.',
            ], 422);
        }

        if (array_key_exists('discount_type', $data)) {
            $data['discount_type'] = $this->normalizeDiscountType($data['discount_type']);
        }

        if (array_key_exists('normal_price', $data) || array_key_exists('discount_value', $data) || array_key_exists('discount_type', $data)) {
            $normalPrice = (float) ($data['normal_price'] ?? $reductionCampaign->normal_price);
            $discountValue = (float) ($data['discount_value'] ?? $reductionCampaign->discount_value);
            $discountType = $data['discount_type'] ?? $reductionCampaign->discount_type;

            $discountError = $this->validateDiscountValue(
                $normalPrice,
                $discountValue,
                $discountType
            );
            if ($discountError) {
                return $discountError;
            }

            $data['promotional_price'] = $this->promotionalPriceFromDiscount($normalPrice, $discountValue, $discountType);
        }

        $imageUpload = $this->uploadCampaignImage($request);
        if (!$imageUpload['success']) {
            return $this->businessError($imageUpload);
        }

        if ($imageUpload['path']) {
            $oldImage = $reductionCampaign->image;
            $data['image'] = $imageUpload['path'];

            if ($oldImage) {
                $this->deleteCampaignImage($oldImage);
            }
        }

        $reductionCampaign->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Campagne mise à jour avec succès.',
            'data' => $this->formatCampaign($reductionCampaign->fresh()),
        ]);
    }

    public function destroy(ReductionCampaign $reductionCampaign)
    {
        if ($reductionCampaign->image) {
            $this->wasabiService->deleteFile($reductionCampaign->image);
        }

        $reductionCampaign->delete();

        return response()->json([
            'success' => true,
            'message' => 'Campagne supprimée avec succès.',
        ]);
    }

    // public function active(Request $request)
    // {
    //     $validator = Validator::make($request->all(), [
    //         'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
    //         'establishment_id' => 'required|integer|min:1',
    //     ]);

    //     if ($validator->fails()) {
    //         return $this->validationError($validator);
    //     }

    //     $campaign = $this->activeCampaignQuery($request->establishment_type, (int) $request->establishment_id)->first();

    //     if (!$campaign) {
    //         return response()->json([
    //             'success' => false,
    //             'message' => 'Aucune campagne active disponible pour cet établissement.',
    //         ], 404);
    //     }

    //     return response()->json([
    //         'success' => true,
    //         'message' => 'Campagne active récupérée avec succès.',
    //         'data' => $this->formatCampaign($campaign),
    //     ]);
    // }

    public function active(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $perPage = (int) $request->input('per_page', 20);

        $campaigns = $this->activeCampaignQuery(
            $request->establishment_type,
            (int) $request->establishment_id
        )->paginate($perPage);

        if ($campaigns->getCollection()->isEmpty()) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune campagne active disponible pour cet établissement.',
            ], 404);
        }

        $campaigns->getCollection()->transform(function (ReductionCampaign $campaign) {
            return $this->formatCampaign($campaign);
        });

        return response()->json([
            'success' => true,
            'message' => 'Campagnes actives récupérées avec succès.',
            'data' => $campaigns,
        ]);
    }

    public function verifyCard(Request $request)
    {
        $validator = $this->scanValidator($request);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $cardValidation = $this->findValidUserCard($request);

        if (!$cardValidation['success']) {
            return $this->businessError($cardValidation);
        }

        $campaign = $this->activeCampaignQuery($request->establishment_type, (int) $request->establishment_id)->first();

        if (!$campaign) {
            return response()->json([
                'success' => false,
                'message' => 'Aucune campagne active disponible pour cet établissement.',
            ], 404);
        }

        $amounts = $this->calculateCampaignAmounts($campaign);

        return response()->json([
            'success' => true,
            'message' => 'Carte valide. Campagne active trouvée.',
            'data' => [
                'usager' => $this->formatUser($cardValidation['card']->user),
                'user_reduction_card' => $this->formatUserCard($cardValidation['card']),
                'campaign' => $this->formatCampaign($campaign),
                'montants' => $amounts,
            ],
        ]);
    }

    public function apply(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'card_code' => 'required_without:qr_code|nullable|string|max:50',
            'qr_code' => 'required_without:card_code|nullable|string|max:100',
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'applied_by_id' => 'required|integer|min:1',
            'notes' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $cardValidation = $this->findValidUserCard($request);

        if (!$cardValidation['success']) {
            return $this->businessError($cardValidation);
        }

        $usage = DB::transaction(function () use ($request, $cardValidation) {
            $campaign = $this->activeCampaignQuery($request->establishment_type, (int) $request->establishment_id)
                ->lockForUpdate()
                ->first();

            if (!$campaign) {
                return [
                    'success' => false,
                    'message' => 'Aucune campagne active disponible pour cet établissement.',
                    'status' => 404,
                ];
            }

            if ($campaign->quantity_available !== null && (int) $campaign->quantity_used >= (int) $campaign->quantity_available) {
                return [
                    'success' => false,
                    'message' => 'Quantité disponible épuisée pour cette campagne.',
                    'status' => 422,
                ];
            }

            $userCard = $cardValidation['card'];
            $amounts = $this->calculateCampaignAmounts($campaign);
            $createdUsage = ReductionCampaignUsage::create([
                'reduction_campaign_id' => $campaign->id,
                'user_reduction_card_id' => $userCard->id,
                'user_id' => $userCard->user_id,
                'campaign_name' => $campaign->name,
                'product_or_service' => $campaign->product_or_service,
                'discount_type' => $campaign->discount_type,
                'discount_value' => $amounts['discount_value'],
                'normal_price' => (float) $campaign->normal_price,
                'promotional_price' => (float) $campaign->promotional_price,
                'montant_initial' => $amounts['montant_initial'],
                'montant_reduction' => $amounts['montant_reduction'],
                'montant_final' => $amounts['montant_final'],
                'applied_by_id' => $request->applied_by_id,
                'establishment_type' => $request->establishment_type,
                'establishment_id' => $request->establishment_id,
                'notes' => $request->notes,
                'used_at' => now(),
            ]);

            $campaign->increment('quantity_used');

            return [
                'success' => true,
                'usage' => $createdUsage->load(['campaign', 'user', 'userReductionCard', 'appliedBy']),
            ];
        });

        if (!$usage['success']) {
            return $this->businessError($usage);
        }

        return response()->json([
            'success' => true,
            'message' => 'Réduction appliquée avec succès.',
            'data' => $this->formatUsage($usage['usage']),
        ], 201);
    }

    public function usages(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
            'date_debut' => 'nullable|date',
            'date_fin' => 'nullable|date|after_or_equal:date_debut',
            'user_id' => 'nullable|integer|min:1',
            'usager' => 'nullable|string|max:150',
            'campaign_id' => 'nullable|integer|min:1',
            'campaign' => 'nullable|string|max:150',
            'montant' => 'nullable|numeric|min:0',
            'montant_min' => 'nullable|numeric|min:0',
            'montant_max' => 'nullable|numeric|min:0',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        if ($validator->fails()) {
            return $this->validationError($validator);
        }

        $query = ReductionCampaignUsage::with(['campaign', 'user', 'appliedBy'])
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

        if ($request->filled('campaign_id')) {
            $query->where('reduction_campaign_id', $request->campaign_id);
        }

        if ($request->filled('campaign')) {
            $query->where('campaign_name', 'like', "%{$request->campaign}%");
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

        $usages = $query->orderByDesc('used_at')
            ->paginate((int) $request->input('per_page', 20));

        $usages->getCollection()->transform(fn ($usage) => $this->formatUsage($usage));

        return response()->json([
            'success' => true,
            'message' => 'Historique des campagnes récupéré avec succès.',
            'data' => $usages,
        ]);
    }

    protected function campaignValidator(Request $request, bool $isUpdate = false)
    {
        $required = $isUpdate ? 'sometimes' : 'required';

        return Validator::make($request->all(), [
            'establishment_type' => [$required, Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => "{$required}|integer|min:1",
            'name' => "{$required}|string|max:255",
            'image' => 'nullable',
            'description' => 'nullable|string',
            'product_or_service' => $required,
            'product_or_service.*' => 'max:200',
            'discount_type' => [$required, Rule::in(['percentage', 'fixed', 'montant'])],
            'discount_value' => "{$required}|numeric|min:0",
            'normal_price' => "{$required}|numeric|min:0",
            'promotional_price' => 'nullable|numeric|min:0',
            'date_debut' => "{$required}|date",
            'date_fin' => "{$required}|date|after_or_equal:date_debut",
            'quantity_available' => 'nullable|integer|min:1',
            'conditions' => 'nullable|string',
            'statut' => 'nullable|integer|in:0,1',
        ])->after(function ($validator) use ($request) {
            $this->validateCampaignImage($validator, $request);

            if (!$request->has('product_or_service')) {
                return;
            }

            $value = $request->input('product_or_service');

            if (is_string($value) && trim($value) !== '' && mb_strlen($value) <= 255) {
                return;
            }

            if (is_array($value) && count(array_filter($value, fn ($item) => trim((string) $item) !== '')) > 0) {
                $normalized = $this->normalizeProductOrService($value);

                if (mb_strlen($normalized) <= 255) {
                    return;
                }
            }

            $validator->errors()->add('product_or_service', 'Le produit ou service doit être une valeur texte ou une liste de valeurs texte de 255 caractères maximum.');
        });
    }

    protected function scanValidator(Request $request)
    {
        return Validator::make($request->all(), [
            'card_code' => 'required_without:qr_code|nullable|string|max:50',
            'qr_code' => 'required_without:card_code|nullable|string|max:100',
            'establishment_type' => ['required', Rule::in(['etablissement', 'lavage', 'station'])],
            'establishment_id' => 'required|integer|min:1',
        ]);
    }

    protected function activeCampaignQuery(string $establishmentType, int $establishmentId)
    {
        $today = now()->toDateString();

        return ReductionCampaign::where('establishment_type', $establishmentType)
            ->where('establishment_id', $establishmentId)
            ->where('statut', 1)
            ->whereDate('date_debut', '<=', $today)
            ->whereDate('date_fin', '>=', $today)
            ->where(function ($query) {
                $query->whereNull('quantity_available')
                    ->orWhereRaw('COALESCE(quantity_used, 0) < quantity_available');
            })
            ->orderByDesc('created_at');
    }

    protected function findValidUserCard(Request $request): array
    {
        $userCard = UserReductionCard::with('user')
            ->when($request->filled('card_code'), fn ($query) => $query->where('card_code', $request->card_code))
            ->when($request->filled('qr_code'), fn ($query) => $query->where('qr_code', $request->qr_code))
            ->first();

        if (!$userCard) {
            return [
                'success' => false,
                'message' => 'Carte usager introuvable.',
                'status' => 404,
            ];
        }

        if ((int) $userCard->statut !== 1) {
            return [
                'success' => false,
                'message' => 'Carte usager inactive.',
                'status' => 422,
            ];
        }

        $today = now()->toDateString();

        if ($userCard->date_debut && $userCard->date_debut->toDateString() > $today) {
            return [
                'success' => false,
                'message' => 'Carte usager pas encore valide.',
                'status' => 422,
            ];
        }

        if ($userCard->date_fin && $userCard->date_fin->toDateString() < $today) {
            return [
                'success' => false,
                'message' => 'Carte usager expirée.',
                'status' => 422,
            ];
        }

        return [
            'success' => true,
            'card' => $userCard,
        ];
    }

    protected function calculateCampaignAmounts(ReductionCampaign $campaign): array
    {
        $normalPrice = round((float) $campaign->normal_price, 2);
        $promotionalPrice = round((float) $campaign->promotional_price, 2);
        $montantFinal = min($promotionalPrice, $normalPrice);
        $montantReduction = round($normalPrice - $montantFinal, 2);

        if ($campaign->discount_type === 'percentage') {
            $discountValue = $normalPrice > 0 ? round(($montantReduction / $normalPrice) * 100, 2) : 0;
        } else {
            $discountValue = $montantReduction;
        }

        return [
            'montant_initial' => $normalPrice,
            'montant_reduction' => $montantReduction,
            'montant_final' => round($montantFinal, 2),
            'discount_type' => $campaign->discount_type,
            'discount_value' => $campaign->discount_value !== null ? (float) $campaign->discount_value : $discountValue,
        ];
    }

    protected function validateDiscountValue(float $normalPrice, float $discountValue, string $discountType)
    {
        if ($discountType === 'percentage' && $discountValue > 100) {
            return response()->json([
                'success' => false,
                'message' => 'Le pourcentage de réduction ne peut pas dépasser 100%.',
                'errors' => [
                    'discount_value' => ['Le pourcentage de réduction ne peut pas dépasser 100%.'],
                ],
            ], 422);
        }

        if ($discountType === 'fixed' && $discountValue > $normalPrice) {
            return response()->json([
                'success' => false,
                'message' => 'Le montant de réduction ne peut pas dépasser le prix normal.',
                'errors' => [
                    'discount_value' => ['Le montant de réduction ne peut pas dépasser le prix normal.'],
                ],
            ], 422);
        }

        return null;
    }

    protected function normalizeDiscountType(string $discountType): string
    {
        return $discountType === 'montant' ? 'fixed' : $discountType;
    }

    protected function promotionalPriceFromDiscount(float $normalPrice, float $discountValue, string $discountType): float
    {
        $discountAmount = $discountType === 'percentage'
            ? $normalPrice * $discountValue / 100
            : $discountValue;

        return round(max($normalPrice - min($discountAmount, $normalPrice), 0), 2);
    }

    protected function validateCampaignImage($validator, Request $request): void
    {
        if (!$this->imageWasSent($request)) {
            return;
        }

        if (!$request->hasFile('image')) {
            $validator->errors()->add('image', 'Le champ image doit être un fichier envoyé en multipart/form-data avec la clé "image"; les liens, chemins texte et base64 ne sont pas acceptés.');
            return;
        }

        $file = $request->file('image');

        if (!$file->isValid()) {
            $validator->errors()->add('image', 'Le fichier image n’a pas été correctement reçu par PHP: ' . $file->getErrorMessage());
            return;
        }

        $maxImageSize = 2 * 1024 * 1024;
        $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'image/gif'];
        $allowedExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        $mimeType = $file->getMimeType();
        $extension = strtolower($file->getClientOriginalExtension());
        $imageInfo = @getimagesize($file->getRealPath());

        if (!$imageInfo) {
            $validator->errors()->add('image', 'Le fichier envoyé n’est pas une image lisible. Vérifiez que le fichier n’est pas corrompu et qu’il est envoyé en multipart/form-data avec la clé "image".');
            return;
        }

        if (!in_array($mimeType, $allowedMimeTypes, true) && $mimeType !== 'application/octet-stream') {
            $validator->errors()->add('image', 'Le fichier envoyé doit être une image JPEG, PNG, WEBP ou GIF. Type détecté: ' . ($mimeType ?: 'inconnu') . '.');
        }

        if ($mimeType === 'application/octet-stream' && !in_array($extension, $allowedExtensions, true)) {
            $validator->errors()->add('image', 'Le fichier est reçu avec le type application/octet-stream; son extension doit être jpg, jpeg, png, webp ou gif. Extension détectée: ' . ($extension ?: 'aucune') . '.');
        }

        if ($file->getSize() > $maxImageSize) {
            $validator->errors()->add('image', 'L’image ne doit pas dépasser 2 Mo. Taille reçue: ' . round($file->getSize() / 1024 / 1024, 2) . ' Mo.');
        }
    }

    protected function uploadCampaignImage(Request $request): array
    {
        if (!$this->imageWasSent($request)) {
            return [
                'success' => true,
                'path' => null,
            ];
        }

        if (!$request->hasFile('image')) {
            return [
                'success' => false,
                'message' => 'Image non enregistrée: le champ image doit être envoyé comme fichier multipart/form-data avec la clé "image".',
                'status' => 422,
            ];
        }

        try {
            return [
                'success' => true,
                'path' => $this->wasabiService->uploadFile($request->file('image'), 'reduction-campaigns', 'campaign'),
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => 'Image valide, mais l’envoi vers Wasabi a échoué: ' . $e->getMessage(),
                'status' => 500,
            ];
        }
    }

    protected function imageWasSent(Request $request): bool
    {
        return $request->hasFile('image') || $request->files->has('image') || $request->has('image');
    }

    protected function deleteCampaignImage(string $path): void
    {
        try {
            $this->wasabiService->deleteFile($path);
        } catch (\Throwable) {
            // La campagne pointe déjà vers la nouvelle image; l'ancien fichier pourra être nettoyé séparément.
        }
    }

    protected function normalizeProductOrService($value): string
    {
        if (is_array($value)) {
            return collect($value)
                ->filter(fn ($item) => trim((string) $item) !== '')
                ->map(fn ($item) => trim((string) $item))
                ->unique()
                ->implode(',');
        }

        return trim((string) $value);
    }

    protected function prepareProductOrService(Request $request): array
    {
        $normalized = $this->normalizeProductOrService($request->input('product_or_service'));

        if ($request->input('establishment_type') !== 'lavage') {
            return [
                'success' => true,
                'value' => $normalized,
            ];
        }

        $ids = $this->extractNumericIds($normalized);

        if (empty($ids)) {
            return [
                'success' => true,
                'value' => $normalized,
            ];
        }

        $existingIds = Type_lavage::where('lavage_id', (int) $request->input('establishment_id'))
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        $missingIds = array_values(array_diff($ids, $existingIds));

        if (!empty($missingIds)) {
            return [
                'success' => false,
                'message' => 'Certains services sélectionnés ne sont pas liés à ce lavage.',
                'status' => 422,
            ];
        }

        return [
            'success' => true,
            'value' => implode(',', $ids),
        ];
    }

    protected function extractNumericIds(?string $value): array
    {
        if (!$value) {
            return [];
        }

        $ids = [];
        foreach (array_map('trim', explode(',', $value)) as $item) {
            if ($item !== '' && ctype_digit($item)) {
                $ids[] = (int) $item;
            }
        }

        return array_values(array_unique($ids));
    }

    protected function resolveProductOrServiceLibelles(ReductionCampaign $campaign): array
    {
        $fallback = array_values(array_filter(array_map('trim', explode(',', (string) $campaign->product_or_service))));

        if ($campaign->establishment_type !== 'lavage') {
            return $fallback;
        }

        $ids = $this->extractNumericIds($campaign->product_or_service);

        if (empty($ids)) {
            return $fallback;
        }

        return Type_lavage::whereIn('id', $ids)
            ->get()
            ->sortBy(fn ($typeLavage) => array_search((int) $typeLavage->id, $ids, true))
            ->pluck('libelle')
            ->values()
            ->all();
    }

    protected function discountValueFromPrices(float $normalPrice, float $promotionalPrice, string $discountType): float
    {
        $montantReduction = max(round($normalPrice - $promotionalPrice, 2), 0);

        if ($discountType === 'percentage') {
            return $normalPrice > 0 ? round(($montantReduction / $normalPrice) * 100, 2) : 0;
        }

        return $montantReduction;
    }

    protected function formatCampaign(ReductionCampaign $campaign): array
    {
        $amounts = $this->calculateCampaignAmounts($campaign);
        $quantityAvailable = $campaign->quantity_available;
        $quantityUsed = (int) ($campaign->quantity_used ?? 0);

        return [
            'id' => $campaign->id,
            'establishment_type' => $campaign->establishment_type,
            'establishment_id' => $campaign->establishment_id,
            'name' => $campaign->name,
            'image' => $campaign->image,
            'image_url' => $this->signedCampaignImageUrl($campaign->image),
            'description' => $campaign->description,
            'product_or_service' => $campaign->product_or_service,
            'product_or_service_ids' => $this->extractNumericIds($campaign->product_or_service),
            'product_or_service_libelles' => $this->resolveProductOrServiceLibelles($campaign),
            'discount_type' => $campaign->discount_type,
            'discount_value' => $amounts['discount_value'],
            'normal_price' => (float) $campaign->normal_price,
            'promotional_price' => (float) $campaign->promotional_price,
            'montant_reduction' => $amounts['montant_reduction'],
            'date_debut' => optional($campaign->date_debut)->toDateString(),
            'date_fin' => optional($campaign->date_fin)->toDateString(),
            'quantity_available' => $quantityAvailable,
            'quantity_used' => $quantityUsed,
            'quantity_remaining' => $quantityAvailable === null ? null : max((int) $quantityAvailable - $quantityUsed, 0),
            'conditions' => $campaign->conditions,
            'statut' => $campaign->statut,
            'created_by' => $campaign->created_by,
            'created_at' => optional($campaign->created_at)->toDateTimeString(),
            'updated_at' => optional($campaign->updated_at)->toDateTimeString(),
        ];
    }

    protected function formatUsage(ReductionCampaignUsage $usage): array
    {
        return [
            'id' => $usage->id,
            'date' => optional($usage->used_at)->toDateTimeString(),
            'usager' => $this->formatUser($usage->user),
            'user_reduction_card_id' => $usage->user_reduction_card_id,
            'campaign' => $usage->campaign ? $this->formatCampaign($usage->campaign) : [
                'id' => $usage->reduction_campaign_id,
                'name' => $usage->campaign_name,
                'product_or_service' => $usage->product_or_service,
            ],
            'montant_initial' => (float) $usage->montant_initial,
            'reduction' => [
                'type' => $usage->discount_type,
                'value' => (float) $usage->discount_value,
                'montant' => (float) $usage->montant_reduction,
            ],
            'montant_final' => (float) $usage->montant_final,
            'applique_par' => $this->formatLavage($usage->appliedBy),
            'establishment_type' => $usage->establishment_type,
            'establishment_id' => $usage->establishment_id,
            'notes' => $usage->notes,
        ];
    }

    protected function formatUserCard(UserReductionCard $userCard): array
    {
        return [
            'id' => $userCard->id,
            'card_code' => $userCard->card_code,
            'qr_code' => $userCard->qr_code,
            'date_debut' => optional($userCard->date_debut)->toDateString(),
            'date_fin' => optional($userCard->date_fin)->toDateString(),
            'statut' => $userCard->statut,
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

    protected function signedCampaignImageUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        try {
            return $this->wasabiService->temporaryUrl($path);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function validationError($validator)
    {
        return response()->json([
            'success' => false,
            'message' => 'Validation échouée.',
            'errors' => $validator->errors(),
        ], 422);
    }

    protected function businessError(array $error)
    {
        return response()->json([
            'success' => false,
            'message' => $error['message'],
        ], $error['status']);
    }
}
