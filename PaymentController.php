<?php

namespace App\Http\Controllers;

use App\Models\Transactions;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;

class PaymentController extends Controller
{

    private $consumer_key = "sMpgnYW62glBlxPXbyTBEGdPib8eJLOL";
    private $consumer_secret = "IcK2PkAFArVVVffU";
    private $pass_key = "bfb279f9aa9bdbcf158e97dd71a467cd2e0c893059b10f78e6b72ada1ed2c919";
    private $business_short_code = 174379;
    private $amount = 11;
    private $phone_number = 254717490329;
    private $party_b = 174379;
    private $uri_stk_push = "https://sandbox.safaricom.co.ke/mpesa/stkpush/v1/processrequest";
    private $callback_stk_push = "https://demo.nivlec.info/morvish/public/api/v1/transaction/confirmation";
    private $account_reference = "Morvish Investment";
    private $transaction_description = "Investment in Morvish Investment Platform";
    private $uri_access_token = "https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials";

    public function lipaNaMpesaPassword()
    {
        $lipa_time = Carbon::rawParse('now')->format('YmdHms');
        $timestamp = $lipa_time;
        $lipa_na_mpesa_password = base64_encode($this->business_short_code . $this->pass_key . $timestamp);
        return $lipa_na_mpesa_password;
    }

    public function C2B_STKPush($amount, $phone_number)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->uri_stk_push);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization:Bearer ' . $this->generateAccessToken()));
        $curl_post_data = [
            'BusinessShortCode' => $this->business_short_code,
            'Password' => $this->lipaNaMpesaPassword(),
            'Timestamp' => Carbon::rawParse('now')->format('YmdHms'),
            'TransactionType' => 'CustomerPayBillOnline',
            'Amount' => $amount,
            'PartyA' => $phone_number, // replace this with your phone number
            'PartyB' => $this->party_b,
            'PhoneNumber' => $phone_number, // replace this with your phone number
            'CallBackURL' => $this->callback_stk_push,
            'AccountReference' => $this->account_reference,
            'TransactionDesc' => $this->transaction_description
        ];
        $data_string = json_encode($curl_post_data);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);
        $curl_response = curl_exec($curl);
        return $curl_response;
    }

    public function generateAccessToken()
    {
        $credentials = base64_encode($this->consumer_key . ":" . $this->consumer_secret);
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $this->uri_access_token);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array("Authorization: Basic " . $credentials));
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        $curl_response = curl_exec($curl);
        $access_token = json_decode($curl_response);
        return $access_token->access_token;
    }

    /**
     * J-son Response to M-pesa API feedback - Success or Failure
     */
    public function createValidationResponse($result_code, $result_description)
    {
        $result = json_encode(["ResultCode" => $result_code, "ResultDesc" => $result_description]);
        $response = new Response();
        $response->headers->set("Content-Type", "application/json; charset=utf-8");
        $response->setContent($result);
        return $response;
    }


    /**
     *  M-pesa Validation Method
     * Safaricom will only call your validation if you have requested by writing an official letter to them
     */
    public function mpesaValidation(Request $request)
    {
        $result_code = "0";
        $result_description = "Accepted validation request.";
        return $this->createValidationResponse($result_code, $result_description);
    }

    /**
     * M-pesa Transaction confirmation method, we save the transaction in our databases
     */
    public function mpesaConfirmation(Request $request)
    {
        $content = json_decode($request->getContent());

        $mpesa_transaction = new Transactions();
        $mpesa_transaction->TransactionType = $content->TransactionType;
        $mpesa_transaction->TransID = $content->TransID;
        $mpesa_transaction->TransTime = $content->TransTime;
        $mpesa_transaction->TransAmount = $content->TransAmount;
        $mpesa_transaction->BusinessShortCode = $content->BusinessShortCode;
        $mpesa_transaction->BillRefNumber = $content->BillRefNumber;
        $mpesa_transaction->InvoiceNumber = $content->InvoiceNumber;
        $mpesa_transaction->OrgAccountBalance = $content->OrgAccountBalance;
        $mpesa_transaction->ThirdPartyTransID = $content->ThirdPartyTransID;
        $mpesa_transaction->MSISDN = $content->MSISDN;
        $mpesa_transaction->FirstName = $content->FirstName;
        $mpesa_transaction->MiddleName = $content->MiddleName;
        $mpesa_transaction->LastName = $content->LastName;
        $mpesa_transaction->save();

        // Responding to the confirmation request
        $response = new Response();
        $response->headers->set("Content-Type", "text/xml; charset=utf-8");
        $response->setContent(json_encode(["C2BPaymentConfirmationResult" => "Success"]));

        return $response;
    }


    /**
     * M-pesa Register Validation and Confirmation method
     */
    public function mpesaRegisterUrls()
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl');
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json', 'Authorization: Bearer ' . $this->generateAccessToken()));

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode(array(
            'ShortCode' => "600610",
            'ResponseType' => 'Completed',
            'ConfirmationURL' => "https://demo.nivlec.info/morvish/public/api/v1/transaction/confirmation",
            'ValidationURL' => "https://demo.nivlec.info/morvish/public/api/v1/validation"
        )));
        $curl_response = curl_exec($curl);
        // echo $curl_response;
        return $curl_response;
    }
}
