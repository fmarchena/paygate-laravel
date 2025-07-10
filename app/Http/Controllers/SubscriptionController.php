<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use App\Services\SubscriptionService;
use App\Http\Requests\CreateSubscriptionRequest;
use App\Http\Requests\ChangePlanRequest;
use Illuminate\Support\Facades\Auth;

class SubscriptionController extends Controller
{
    private SubscriptionService $subscriptionService;

    public function __construct(SubscriptionService $subscriptionService)
    {
        $this->subscriptionService = $subscriptionService;
        $this->middleware('auth')->except(['plans', 'showPlans']);
    }

    /**
     * Mostrar página de planes
     */
    public function showPlans()
    {
        $plans = $this->subscriptionService->getAvailablePlans();
        return view('subscriptions.plans', compact('plans'));
    }

    /**
     * API: Obtener planes disponibles
     */
    public function plans(): JsonResponse
    {
        $plans = $this->subscriptionService->getAvailablePlans();
        
        return response()->json([
            'plans' => $plans,
        ]);
    }

    /**
     * Mostrar página de suscripción
     */
    public function showSubscription()
    {
        $user = Auth::user();
        $details = $this->subscriptionService->getSubscriptionDetails($user);
        $invoices = $this->subscriptionService->getInvoiceHistory($user);
        
        return view('subscriptions.manage', [
            'subscription' => $details['subscription'] ?? null,
            'invoices' => $invoices['invoices'] ?? [],
        ]);
    }

    /**
     * API: Crear suscripción
     */
    public function create(CreateSubscriptionRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $result = $this->subscriptionService->createSubscription(
            $user,
            $data['price_id'],
            $data['payment_method_id'] ?? null,
            $data['trial_days'] ?? 0
        );

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'subscription' => $result['subscription'],
            ]);
        }

        if (isset($result['requires_action'])) {
            return response()->json([
                'requires_action' => true,
                'payment_intent' => $result['payment_intent'],
                'message' => $result['message'],
            ], 402);
        }

        return response()->json([
            'error' => $result['message'],
        ], 400);
    }

    /**
     * API: Cambiar plan
     */
    public function changePlan(ChangePlanRequest $request): JsonResponse
    {
        $user = Auth::user();
        $data = $request->validated();

        $result = $this->subscriptionService->changePlan(
            $user,
            $data['new_price_id'],
            $data['prorate'] ?? true
        );

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'subscription' => $result['subscription'],
            ]);
        }

        return response()->json([
            'error' => $result['message'],
        ], 400);
    }

    /**
     * API: Cancelar suscripción
     */
    public function cancel(Request $request): JsonResponse
    {
        $request->validate([
            'immediately' => 'boolean',
        ]);

        $user = Auth::user();
        $immediately = $request->boolean('immediately');

        $result = $this->subscriptionService->cancelSubscription($user, $immediately);

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'subscription' => $result['subscription'],
            ]);
        }

        return response()->json([
            'error' => $result['message'],
        ], 400);
    }

    /**
     * API: Reanudar suscripción
     */
    public function resume(): JsonResponse
    {
        $user = Auth::user();
        $result = $this->subscriptionService->resumeSubscription($user);

        if ($result['success']) {
            return response()->json([
                'message' => $result['message'],
                'subscription' => $result['subscription'],
            ]);
        }

        return response()->json([
            'error' => $result['message'],
        ], 400);
    }

    /**
     * API: Obtener detalles de suscripción
     */
    public function details(): JsonResponse
    {
        $user = Auth::user();
        $result = $this->subscriptionService->getSubscriptionDetails($user);

        if ($result['success']) {
            return response()->json($result['subscription']);
        }

        return response()->json([
            'error' => $result['message'],
        ], 404);
    }

    /**
     * API: Obtener historial de facturas
     */
    public function invoices(): JsonResponse
    {
        $user = Auth::user();
        $result = $this->subscriptionService->getInvoiceHistory($user);

        return response()->json([
            'invoices' => $result['invoices'],
        ]);
    }

    /**
     * Descargar factura
     */
    public function downloadInvoice(Request $request, string $invoiceId)
    {
        $user = Auth::user();
        
        return $user->downloadInvoice($invoiceId, [
            'vendor' => config('app.name'),
            'product' => 'Suscripción Premium',
        ]);
    }

    /**
     * API: Crear setup intent para método de pago
     */
    public function createSetupIntent(): JsonResponse
    {
        $user = Auth::user();
        $result = $this->subscriptionService->createSetupIntent($user);

        if ($result['success']) {
            return response()->json([
                'client_secret' => $result['client_secret'],
            ]);
        }

        return response()->json([
            'error' => $result['error'],
        ], 400);
    }

    /**
     * API: Obtener métodos de pago del usuario
     */
    public function paymentMethods(): JsonResponse
    {
        $user = Auth::user();
        
        if (!$user->hasStripeId()) {
            return response()->json(['payment_methods' => []]);
        }

        $paymentMethods = $user->paymentMethods();

        $formattedMethods = $paymentMethods->map(function ($method) {
            return [
                'id' => $method->id,
                'type' => $method->type,
                'card' => $method->card ? [
                    'brand' => $method->card->brand,
                    'last4' => $method->card->last4,
                    'exp_month' => $method->card->exp_month,
                    'exp_year' => $method->card->exp_year,
                ] : null,
            ];
        });

        return response()->json([
            'payment_methods' => $formattedMethods,
            'default_payment_method' => $user->defaultPaymentMethod()?->id,
        ]);
    }

    /**
     * API: Eliminar método de pago
     */
    public function deletePaymentMethod(Request $request): JsonResponse
    {
        $request->validate([
            'payment_method_id' => 'required|string',
        ]);

        $user = Auth::user();
        
        try {
            $user->findPaymentMethod($request->payment_method_id)?->delete();

            return response()->json([
                'message' => 'Método de pago eliminado exitosamente',
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'error' => 'Error al eliminar el método de pago',
            ], 400);
        }
    }
}