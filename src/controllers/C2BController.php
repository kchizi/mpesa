<?php

namespace Ngodasamuel\Mpesa\controllers;

//use App\Http\Controllers\Controller;
use Carbon\Carbon;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Events\Dispatcher;
use Illuminate\Http\Request;
use Ngodasamuel\Mpesa\models\MpesaBalance;
use Ngodasamuel\Mpesa\models\MpesaPaymentLog;
use Ngodasamuel\Mpesa\models\Payment;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Client;

class C2BController extends BaseController
{

    protected $dispatcher;


    /**
     * C2BController constructor.
     * @param Dispatcher $dispatcher
     */
    public function __construct(Dispatcher $dispatcher)
    {
        $this->dispatcher = $dispatcher;
    }

    public function receiver(Request $request)
    {

        // Receive the Soap IPN from Safaricom
        $input = $request->getContent(); //getting the file input

        // check if $input is empty
        if (empty($input)) {
            return;
        }

        // extract data from the content
        $data = self::extractData($input);


        // create payment
        self::createPayment($data);
    }

    /**
     * We log the data as we receive it i.e. in soap payload
     *
     * @param $input
     * @param $type
     */
    protected function logReceivedData($input, $type)
    {
        $data = ['content' => $input, 'type' => $type];
        MpesaPaymentLog::create($data);
    }

    public function getauthtoken()
    {
        $client = new Client();
        $credentials = base64_encode(config('mpesa.CONSUMER_KEY').':'.config('mpesa.CONSUMER_SECRET'));
        //echo config('mpesa.CONSUMER_KEY');
//echo $credentials;
//exit();
    // Create a POST request
    try{
    $response = $client->request(
     'GET',
     'https://sandbox.safaricom.co.ke/oauth/v1/generate?grant_type=client_credentials',
     [
         'Authorization' => ['Basic '.$credentials ]
     ]
    );
  } catch (RequestException $e) {

  // Catch all 4XX errors

  // To catch exactly error 400 use
  if ($e->getResponse()->getStatusCode() == '400') {
    print_r($e->getResponse());
          echo "Got response 400";
  }

  // You can check for whatever error status code you need

} catch (\Exception $e) {

  // There was another exception.

}

    // Parse the response object, e.g. read the headers, body, etc.
    $headers = $response->getHeaders();
    $body = $response->getBody();

    return body['Access_Token'];
    }

    public function registerc2b(Request $request)
    {
        $url = 'https://sandbox.safaricom.co.ke/mpesa/c2b/v1/registerurl';

        $ACCESS_TOKEN =$this->getauthtoken();

        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HTTPHEADER, array('Content-Type:application/json','Authorization:Bearer '.$ACCESS_TOKEN)); //setting custom header


        $curl_post_data = array(
        //Fill in the request parameters with valid values
        'ShortCode' => ' ',
        'ResponseType' => ' ',
        'ConfirmationURL' => config('mpesa.CONFIRMATIONURL'),
        'ValidationURL' => config('mpesa.VALIDATIONURL')
        );

        $data_string = json_encode($curl_post_data);

        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $data_string);

        $curl_response = curl_exec($curl);
        print_r($curl_response);

        echo $curl_response;
        // Show the page
    }

    /**
     * We dive deep into the soap and extract the data needed to for the payments table
     *
     * @param $input
     * @return mixed
     */
    protected function extractData($input)
    {
        // initialize the DOMDocument  and create an object that we use to call loadXML and parse the XML
        $xml = new \DOMDocument();
        $xml->loadXML($input);// for c2b


        // common data
        $data['transaction_type'] = $xml->getElementsByTagName('TransType')->item(0)->nodeValue; // The type of the transaction eg. Paybill, Buygoods etc,
        $data['transaction_id'] = $xml->getElementsByTagName('TransID')->item(0)->nodeValue;
        $data['transaction_time'] = $xml->getElementsByTagName('TransTime')->item(0)->nodeValue;
        $data['amount'] = $xml->getElementsByTagName('TransAmount')->item(0)->nodeValue;
        $data['business_number'] = $xml->getElementsByTagName('BusinessShortCode')->item(0)->nodeValue;
        $data['acc_no'] = preg_replace('/\s+/', '', $xml->getElementsByTagName('BillRefNumber')->item(0)->nodeValue);
        $data['latest_org_balance'] = $xml->getElementsByTagName('OrgAccountBalance')->item(0)->nodeValue;


        // check the transaction type and extract specific data
        if ($xml->getElementsByTagName('TransType')->item(0)->nodeValue == 'Pay Bill') {

            // log
            self::logReceivedData($input, 'c2b');

            $data['phone_no'] = sprintf("254%d", substr(trim($xml->getElementsByTagName('MSISDN')->item(0)->nodeValue), -9));

            if ($xml->getElementsByTagName('KYCInfo')->length == 2) {
                $data['sender_first_name'] = $xml->getElementsByTagName('KYCValue')->item(0)->nodeValue;
                $data['sender_last_name'] = $xml->getElementsByTagName('KYCValue')->item(1)->nodeValue;
            } elseif ($xml->getElementsByTagName('KYCInfo')->length == 3) {
                $data['sender_first_name'] = $xml->getElementsByTagName('KYCValue')->item(0)->nodeValue;
                $data['sender_middle_name'] = $xml->getElementsByTagName('KYCValue')->item(1)->nodeValue;
                $data['sender_last_name'] = $xml->getElementsByTagName('KYCValue')->item(2)->nodeValue;
            }
        } elseif ($xml->getElementsByTagName('TransType')->item(0)->nodeValue == 'Organization To Organization Transfer') {
            // log
            self::logReceivedData($input, 'b2b');

            if (isset($xml->getElementsByTagName('InvoiceNumber')->item(0)->nodeValue)) {
                $invoiveNumber = explode(" ", $xml->getElementsByTagName('InvoiceNumber')->item(0)->nodeValue);
                $data['phone_no'] = sprintf("254%d", substr(trim($invoiveNumber[0]), -9));
                if (count($invoiveNumber) == 3) {
                    $data['sender_first_name'] = $invoiveNumber[1];
                    $data['sender_last_name'] = $invoiveNumber[2];
                } elseif (count($invoiveNumber) == 4) {
                    $data['sender_first_name'] = $invoiveNumber[1];
                    $data['sender_middle_name'] = $invoiveNumber[2];
                    $data['sender_last_name'] = $invoiveNumber[3];
                }
            }
        }

        return $data;
    }

    /**
     * Create the payment details and save in the payments table then fire an event with the data
     *
     * @param $data
     */
    protected function createPayment($data)
    {
        /**
         * save this in the payments table, but we first check if it exists (Safaricom sometimes send the notification twice)
         */
        $transaction = Payment::whereTransactionId($data['transaction_id'])->first();
        if ($transaction === null) {
            $result = Payment::create($data);


            $payload = [
                'payment' => $result
            ];

            // Fire the 'payment received' event
            $this->dispatcher->fire('c2b.received.payment', $payload);
        }
    }


    /**
     * @param $org_account_balance
     */
    protected function updateMpesaBalance($org_account_balance)
    {
        if (MpesaBalance::count() > 0) {
            // update
            $current_balance = MpesaBalance::where('id', '=', 1)->first();
            $current_balance->mpesa_balance = $org_account_balance;
            $current_balance->last_updated = Carbon::now();
            $current_balance->save();
        } else {
            // first time
            MpesaBalance::create(['mpesa_balance' => $org_account_balance]);
        }
    }
}
