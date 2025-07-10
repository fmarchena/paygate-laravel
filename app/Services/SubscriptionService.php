<?php

namespace App\Services;

use App\Models\User;
use Laravel\Cashier\Subscription;
use Stripe\Stripe;
use Stripe\Price;
use Stripe\Product;
use Illuminate\Support\Facades\Log;
use Laravel\Cashier\Exceptions\IncompletePayment;

class SubscriptionService
{
    public function __construct()
    {
        Stripe::setApiKey(config('cashier.secret'));
    }

    /**
     * Obtener todos los planes disponibles
     */
    public function getAvailablePlans(): array
    {
        try {
            $prices = Price::all([
                'active' => true,
                'type' => 'recurring',
                'expand' => ['data.product'],
            ]);

            $plans = [];
            foreach ($prices->data as $price) {
                $product = $price->product;
                
                $plans[] = [
                    'id' => $price->id,
                    'product_id' => $product->id,
                    'name' => $product->name,
                    'description' => $product->description ?? '',
                    'amount' => $price->unit_amount,
                    'currency' => $price->currency,
                    'interval' => $price->recurring->interval,
                    'interval_count' => $price->recurring->interval_count,
                    'trial_period_days' => $price->recurring->trial_period_days ?? 0,
                    'features' => $product->metadata->features ?? '',
                    'formatted_price' => $this->formatPrice($price->unit_amount, $price->currency),
                ];
            }

            return $plans;

        } catch (\Exception $e) {
            Log::error('Error fetching subscription plans: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Crear suscripción para usuario
     */
    public function createSubscription(User $user, string $priceId, ?string $paymentMethodId = null, int $trialDays = 0): array
    {
        try {
            // Asegurar que el usuario tenga un customer ID en Stripe
            if (!$user->hasStripeId()) {
                $user->createAsStripeCustomer();
            }

            // Si se proporciona un método de pago, agregarlo como default
            if ($paymentMethodId) {
                $user->updateDefaultPaymentMethod($paymentMethodId);
            }

            // Crear suscripción
            $subscriptionBuilder = $user->newSubscription('default', $priceId);

            // Agregar período de prueba si se especifica
            if ($trialDays > 0) {
                $subscriptionBuilder->trialDays($trialDays);
            }

            $subscription = $subscriptionBuilder->create();

            return [
                'success' => true,
                'subscription' => $subscription,
                'message' => 'Suscripción creada exitosamente',
            ];

        } catch (IncompletePayment $e) {
            return [
                'success' => false,
                'requires_action' => true,
                'payment_intent' => $e->payment->id,
                'message' => 'La suscripción requiere confirmación de pago',
            ];

        } catch (\Exception $e) {
            Log::error('Error creating subscription: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al crear la suscripción',
            ];
        }
    }

    /**
     * Cambiar plan de suscripción
     */
    public function changePlan(User $user, string $newPriceId, bool $prorate = true): array
    {
        try {
            $subscription = $user->subscription('default');

            if (!$subscription || !$subscription->active()) {
                return [
                    'success' => false,
                    'message' => 'No se encontró una suscripción activa',
                ];
            }

            // Cambiar plan
            if ($prorate) {
                $subscription->swapAndInvoice($newPriceId);
            } else {
                $subscription->noProrate()->swap($newPriceId);
            }

            return [
                'success' => true,
                'subscription' => $subscription->fresh(),
                'message' => 'Plan cambiado exitosamente',
            ];

        } catch (\Exception $e) {
            Log::error('Error changing subscription plan: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al cambiar el plan',
            ];
        }
    }

    /**
     * Cancelar suscripción
     */
    public function cancelSubscription(User $user, bool $immediately = false): array
    {
        try {
            $subscription = $user->subscription('default');

            if (!$subscription || !$subscription->active()) {
                return [
                    'success' => false,
                    'message' => 'No se encontró una suscripción activa',
                ];
            }

            if ($immediately) {
                $subscription->cancelNow();
                $message = 'Suscripción cancelada inmediatamente';
            } else {
                $subscription->cancel();
                $message = 'Suscripción programada para cancelación al final del período';
            }

            return [
                'success' => true,
                'subscription' => $subscription->fresh(),
                'message' => $message,
            ];

        } catch (\Exception $e) {
            Log::error('Error canceling subscription: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al cancelar la suscripción',
            ];
        }
    }

    /**
     * Reanudar suscripción cancelada
     */
    public function resumeSubscription(User $user): array
    {
        try {
            $subscription = $user->subscription('default');

            if (!$subscription || !$subscription->canceled()) {
                return [
                    'success' => false,
                    'message' => 'No se encontró una suscripción cancelada',
                ];
            }

            $subscription->resume();

            return [
                'success' => true,
                'subscription' => $subscription->fresh(),
                'message' => 'Suscripción reanudada exitosamente',
            ];

        } catch (\Exception $e) {
            Log::error('Error resuming subscription: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'message' => 'Error al reanudar la suscripción',
            ];
        }
    }

    /**
     * Obtener historial de facturas
     */
    public function getInvoiceHistory(User $user, int $limit = 10): array
    {
        try {
            $invoices = $user->invoices($limit);

            $formattedInvoices = $invoices->map(function ($invoice) {
                return [
                    'id' => $invoice->id,
                    'number' => $invoice->number,
                    'amount' => $invoice->total(),
                    'currency' => $invoice->currency,
                    'status' => $invoice->status,
                    'date' => $invoice->date()->toDateString(),
                    'download_url' => route('invoices.download', $invoice->id),
                    'formatted_amount' => $this->formatPrice($invoice->total(), $invoice->currency),
                ];
            });

            return [
                'success' => true,
                'invoices' => $formattedInvoices,
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching invoice history: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
                'invoices' => [],
            ];
        }
    }

    /**
     * Obtener detalles de suscripción actual
     */
    public function getSubscriptionDetails(User $user): array
    {
        try {
            $subscription = $user->subscription('default');

            if (!$subscription) {
                return [
                    'success' => false,
                    'message' => 'Usuario sin suscripción',
                ];
            }

            $currentPlan = $this->getPlanDetails($subscription->stripe_price);

            return [
                'success' => true,
                'subscription' => [
                    'id' => $subscription->id,
                    'status' => $subscription->stripe_status,
                    'active' => $subscription->active(),
                    'canceled' => $subscription->canceled(),
                    'on_trial' => $subscription->onTrial(),
                    'trial_ends_at' => $subscription->trial_ends_at,
                    'ends_at' => $subscription->ends_at,
                    'current_period_start' => $subscription->asStripeSubscription()->current_period_start,
                    'current_period_end' => $subscription->asStripeSubscription()->current_period_end,
                    'plan' => $currentPlan,
                ],
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching subscription details: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Obtener detalles de un plan específico
     */
    private function getPlanDetails(string $priceId): ?array
    {
        try {
            $price = Price::retrieve([
                'id' => $priceId,
                'expand' => ['product'],
            ]);

            return [
                'id' => $price->id,
                'name' => $price->product->name,
                'amount' => $price->unit_amount,
                'currency' => $price->currency,
                'interval' => $price->recurring->interval,
                'formatted_price' => $this->formatPrice($price->unit_amount, $price->currency),
            ];

        } catch (\Exception $e) {
            Log::error('Error fetching plan details: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Formatear precio
     */
    private function formatPrice(int $amount, string $currency): string
    {
        $formatted = number_format($amount / 100, 2);
        $symbol = match(strtoupper($currency)) {
            'USD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency) . ' ',
        };

        return $symbol . $formatted;
    }

    /**
     * Crear setup intent para agregar método de pago
     */
    public function createSetupIntent(User $user): array
    {
        try {
            if (!$user->hasStripeId()) {
                $user->createAsStripeCustomer();
            }

            $setupIntent = $user->createSetupIntent();

            return [
                'success' => true,
                'client_secret' => $setupIntent->client_secret,
            ];

        } catch (\Exception $e) {
            Log::error('Error creating setup intent: ' . $e->getMessage());
            
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }
}