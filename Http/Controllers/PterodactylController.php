<?php

namespace App\Services\Pterodactyl\Http\Controllers;

use App\Facades\Theme;
use App\Services\Pterodactyl\Entities\Pterodactyl;
use Illuminate\Contracts\Support\Renderable;
use Illuminate\Support\Facades\Http;
use Illuminate\Routing\Controller;
use App\Models\Payment;
use App\Models\Order;
use Carbon\Carbon;

class PterodactylController extends Controller
{
    /**
     * This is the main service function that returns the requested resource
     * by default 'manage' method is returned
     * @return Renderable
     */
    public function service(Order $order, $page = 'manage')
    {
        if($page == 'invoices') {
            return self::invoices($order);
        }

        if($page == 'renew') {
            return self::renew($order);
        }

        if($page == 'cancel-service') {
            return self::cancel($order);
        }

        if($page == 'cancel-undo') {
            return self::undoCancel($order);
        }

        return self::manage($order);
    }

    /**
     * Manage returns the index page of your service
     * @return Renderable
     */
    public function manage(Order $order)
    {
        return view(Theme::serviceView('pterodactyl', 'service.service'), compact('order'));
    }

    /**
     * Invoices returns the path to your service invoices
     * @return Renderable
     */
    public function invoices(Order $order)
    {
        return view(Theme::serviceView('pterodactyl', 'service.invoices'), compact('order'));
    }

    /**
     * This function manages renewals
    */
    public function renew(Order $order)
    {
        $validated = request()->validate([
            'frequency' => 'required|integer|between:1,12',
        ]);

        // check if there isn't any duplicate payment
        $duplicate_payment = $order->payments()->whereStatus('unpaid')->where('due_date', $order->due_date);
        if($duplicate_payment->exists()) {
            $duplicate_payment->first()->delete();
        }

        // calculate price
        $price = $order->price['renewal_price'] * $validated['frequency'];
        $period = $order->price['period'] * $validated['frequency'];

        $payment = Payment::generate([
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'description' => __('admin.ptero_renewal_desc', [
                'name' => $order->name,
                'param' => $order->due_date->translatedFormat(settings('date_format', 'd M Y')),
                'add_days_period' => $order->due_date->addDays($period)->translatedFormat(settings('date_format', 'd M Y'))]),
            'amount' => $price,
            'due_date' => $order->due_date,
            'options' => ['period' => $period],
            'handler' => config($order->service. '.handlers.renewal')
        ]);

        return redirect()->route('invoice', ['payment' => $payment->id])->with('success', __('admin.invoice_generated_successfully'));
    }

    /**
     * This function manages cancellations
    */
    public function cancel(Order $order)
    {
        request()->validate([
            'cancelled_at' => 'required',
            'cancel_reason' => 'max:255',
        ]);

        if($order->status !== 'active') {
            return redirect()->back()->with('error', __('admin.service_already_cancelled'));
        }

        if($order->price['cancellation_fee'] > 0)
        {
            $payment = Payment::generate([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'description' => 'Cancellation: '. $order->name,
                'amount' => $order->price['cancellation_fee'],
                'due_date' => Carbon::now()->addHours(6),
                'options' => request()->except(['_token', 'gateway']),
                'handler' => config($order->service. '.handlers.cancel')
            ]);

            return redirect()->route('invoice.pay', ['payment' => $payment->id, 'gateway' => request()->input('gateway')])->with('success',
                __('admin.pay_cancellation_fee_cancel_service'));
        }

        $order->cancel(request()->input('cancelled_at'), request()->input('cancel_reason'));
        return redirect()->back()->with('success', __('admin.your_service_was_cancelled'));
    }

    /**
     * This function manages restart for cancelled orders
    */
    public function undoCancel(Order $order)
    {
        if($order->status = 'cancelled')
        {
            $order->status = 'active';
            $order->cancelled_at = NULL;
            $order->cancel_reason = NULL;
            $order->save();
        }

        return redirect()->back()->with('success', __('admin.undo_canceled_resp'));
    }

    /**
     * Redirect the user to Pterodactyl and log them in
     */
    public function loginPanel(Order $order)
    {
        $url = settings('encrypted::pterodactyl::api_url') . '/sso-wemx';
        $secret = settings('encrypted::pterodactyl::sso_secret');

        $response = Http::get($url, [
            'sso_secret' => $secret,
            'user_id' => Pterodactyl::user($order->user)['id']
        ]);

        if (!$response->successful()){
            $message = __('admin.panel_login_mess');
            try {
                if (is_array($response->json()) && array_key_exists('message', $response->json())) {
                    $message = $response->json()['message'];
                }
            } catch (\Exception $e) {
                // Handle the exception here, if needed
            }
            return redirect()->back()->with('error',$message);
        }

        if(!isset($response['redirect'])) {
            return redirect()->back()->withError('Failed to connect to Pterodactyl | The SSO package is no longer installed https://docs.wemx.net/en/third-party/pterodactyl#pterodactyl-sso');
        }

        return redirect()->intended($response['redirect']);
    }
}
