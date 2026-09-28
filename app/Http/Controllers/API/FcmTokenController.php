<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\LavageFcmToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class FcmTokenController extends Controller
{
    public function index()
    {
        $tokens = LavageFcmToken::where('lavage_id', Auth::guard('api')->id())
            ->latest('last_used_at')
            ->get()
            ->map(fn (LavageFcmToken $token) => $this->formatToken($token));

        return response()->json([
            'success' => true,
            'message' => 'Appareils FCM récupérés avec succès.',
            'data' => $tokens,
        ]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|max:255',
            'platform' => ['nullable', Rule::in(['android', 'ios', 'web'])],
            'device_id' => 'nullable|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $lavageId = (int) Auth::guard('api')->id();
        $data = $validator->validated();

        $fcmToken = DB::transaction(function () use ($data, $lavageId) {
            $fcmToken = LavageFcmToken::where('token', $data['token'])
                ->lockForUpdate()
                ->first();

            if (!$fcmToken) {
                $fcmToken = new LavageFcmToken();
                $fcmToken->token = $data['token'];
            }

            $fcmToken->lavage_id = $lavageId;
            $fcmToken->platform = $data['platform'] ?? null;
            $fcmToken->device_id = $data['device_id'] ?? null;
            $fcmToken->last_used_at = now();
            $fcmToken->save();

            return $fcmToken;
        });

        return response()->json([
            'success' => true,
            'message' => 'Token FCM enregistré avec succès.',
            'data' => $this->formatToken($fcmToken),
        ]);
    }

    public function destroy(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|max:255',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Validation échouée.',
                'errors' => $validator->errors(),
            ], 422);
        }

        $deleted = LavageFcmToken::where('lavage_id', Auth::guard('api')->id())
            ->where('token', $request->token)
            ->delete();

        if ($deleted === 0) {
            return response()->json([
                'success' => false,
                'message' => 'Token FCM introuvable pour ce lavage.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Token FCM supprimé avec succès.',
        ]);
    }

    protected function formatToken(LavageFcmToken $token): array
    {
        return [
            'id' => $token->id,
            'platform' => $token->platform,
            'device_id' => $token->device_id,
            'last_used_at' => optional($token->last_used_at)->toDateTimeString(),
            'created_at' => optional($token->created_at)->toDateTimeString(),
        ];
    }
}
