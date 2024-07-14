<?php

namespace App\Http\Controllers;
use App\Models\Plan;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

trait PaymentTrait
{
    //

    public $paymentMethod = 'mobile_money';
    
    public function payment($user_id,$plan_id){
        $user = User::findOrFail($user_id);
        $plan = Plan::findOrFail($plan_id);

        // host
        $configs = DB::table('config')->whereBetween('id',[34,38])->get();


        $host = $this->gatewayHost("/checkout/payments");

        $amount = $plan->price;

        $trx_id = uniqid(Str::slug(getSetting()->site_name,'_').'_');
        $fee = $this->getFee($amount);

        if(!$fee){
            toastr()->error('Failed to make payment');
            return back();
        }
        // tranx

        $trnx = new Transaction();
        $order_id = Transaction::max('order_id');
        $trnx->order_id = $order_id < 1000 ? 1000 : $order_id + 1;
        $trnx->transaction_id = $trx_id;
        $trnx->payment_provider = 'fincra';
        $trnx->plan_id = $plan_id;
        $trnx->user_id  = $user_id ;
        $trnx->amount = $amount;
        $trnx->currency_symbol = $configs[0]->config_value;
        $trnx->payment_status = 'unpaid';
        $trnx->provider_fee = $fee;
        $trnx->save();

        // api data

        $data = [
            'amount' => $amount,
            'redirectUrl' => route('user.plan.upgrade',[
                'id' => $plan_id
            ] ),
            'currency' => 'GHS',
            'reference' => $trx_id,
            'feeBearer' => 'customer',
            "customer" => [
                "name" => Str::wordCount($user->name)==1 ? (getSetting()->site_name.' User: '.$user->name) : $user->name,"email" => $user->email, "phoneNumber" => $user->phone
            ],
            // 'paymentMethods' => ['bank_transfer','card','payattitude],
            'paymentMethods' => ['mobile_money'], // bank_transfer/card/payattitude/apple_pay  is not available for GHS
            'defaultPaymentMethod' => $this->paymentMethod,
            // 'metadata' => json_encode([

            // ]),
            'successMessage' => 'Thank you for your payment, stay with '.getSetting()->site_name
        ];

        $headers = [
            'accept' => 'application/json',
            'api-key' => $configs[1]->config_value,
            'content-type' => 'application/json',
            'x-pub-key' => $configs[2]->config_value
        ];

                
        $apiRequest = Http::withHeaders($headers)->post($host,$data);
        $apiResponse = json_decode($apiRequest->body());
        if(isset($apiResponse->status) and !isset($apiResponse->error) and $apiResponse->status=='true'){
            
            $apiResdata = $apiResponse->data;
            $trnx->provider_trx_id = $apiResponse->data->payCode;
            $trnx->save();
            return redirect()->away($apiResdata->link);
        }else{
             dd($apiResponse);
            toastr()->error('Failed to make payment','Payment Failed');
            return back();            
        }

    }

    public function getFee($amount){
        // host
        $configs = DB::table('config')->whereBetween('id',[34,39])->get();

        $host = $this->gatewayHost("/checkout/data/fees");

        // api data

        $data = [
            'amount' => $amount,
            'currency' => 'GHS',
            'type' => $this->paymentMethod,
        ];

        $headers = [
            'accept' => 'application/json',
            'api-key' => $configs[1]->config_value,
            'content-type' => 'application/json',
            'x-business-id' => $configs[5]->config_value
        ];

        
        
        $apiRequest = Http::withHeaders($headers)->post($host,$data);
        $apiResponse = json_decode($apiRequest->body());

        if(isset($apiResponse->status) and !isset($apiResponse->error) and $apiResponse->status=='true'){
            return  $apiResponse->data->fee;
        }else{
            return false;          
        }
    }

    function gatewayHost($url=null){
        $configs = DB::table('config')->where('id',38)->get();
    
        $host = "https://".($configs[0]->config_value=='sandbox' ? 'sandboxapi' : 'api').".fincra.com";
    
        if($url){
            $host .= (str($url)->endsWith('/') ? $url : '/'.$url);
        }
    
        return $host;
    }
    
}
