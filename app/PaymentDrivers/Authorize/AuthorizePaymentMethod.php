<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2020. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\PaymentDrivers\Authorize;

use App\Exceptions\GenericPaymentDriverFailure;
use App\Models\ClientGatewayToken;
use App\Models\GatewayType;
use App\PaymentDrivers\Authorize\AuthorizeCreateCustomer;
use App\PaymentDrivers\AuthorizePaymentDriver;
use net\authorize\api\contract\v1\CreateCustomerPaymentProfileRequest;
use net\authorize\api\contract\v1\CreateCustomerProfileRequest;
use net\authorize\api\contract\v1\CustomerAddressType;
use net\authorize\api\contract\v1\CustomerPaymentProfileType;
use net\authorize\api\contract\v1\CustomerProfileType;
use net\authorize\api\contract\v1\GetCustomerPaymentProfileRequest;
use net\authorize\api\contract\v1\OpaqueDataType;
use net\authorize\api\contract\v1\PaymentType;
use net\authorize\api\controller\CreateCustomerPaymentProfileController;
use net\authorize\api\controller\CreateCustomerProfileController;
use net\authorize\api\controller\GetCustomerPaymentProfileController;

/**
 * Class AuthorizePaymentMethod.
 */
class AuthorizePaymentMethod
{
    public $authorize;

    public $payment_method;

    public function __construct(AuthorizePaymentDriver $authorize)
    {
        $this->authorize = $authorize;
    }

    public function authorizeView($payment_method)
    {
        $this->payment_method = $payment_method;

        switch ($payment_method) {
            case GatewayType::CREDIT_CARD:
                return $this->authorizeCreditCard();
                break;
            case GatewayType::BANK_TRANSFER:
                return $this->authorizeBankTransfer();
                break;

            default:
                // code...
                break;
        }
    }

    public function authorizeResponseView($data)
    {
        $this->payment_method = $data['payment_method_id'];

        switch ($this->payment_method) {
            case GatewayType::CREDIT_CARD:
                return $this->authorizeCreditCardResponse($data);
                break;
            case GatewayType::BANK_TRANSFER:
                return $this->authorizeBankTransferResponse($data);
                break;

            default:
                // code...
                break;
        }
    }

    public function authorizeCreditCard()
    {
        $data['gateway'] = $this->authorize->company_gateway;
        $data['public_client_id'] = $this->authorize->init()->getPublicClientKey();
        $data['api_login_id'] = $this->authorize->company_gateway->getConfigField('apiLoginId');

        return render('gateways.authorize.add_credit_card', $data);
    }

    public function authorizeBankTransfer()
    {
    }

    public function authorizeCreditCardResponse($data)
    {
        $client_profile_id = null;

        if ($client_gateway_token = $this->authorize->findClientGatewayRecord()) {
            $payment_profile = $this->addPaymentMethodToClient($client_gateway_token->gateway_customer_reference, $data);
        } else {
            $gateway_customer_reference = (new AuthorizeCreateCustomer($this->authorize, $this->authorize->client))->create($data);
            $payment_profile = $this->addPaymentMethodToClient($gateway_customer_reference, $data);
        }

        $this->createClientGatewayToken($payment_profile, $gateway_customer_reference);

        return redirect()->route('client.payment_methods.index');
    }

    public function authorizeBankTransferResponse($data)
    {
    }

    public function createClientGatewayToken($payment_profile, $gateway_customer_reference)
    {
        //  info(print_r($payment_profile,1));

        $client_gateway_token = new ClientGatewayToken();
        $client_gateway_token->company_id = $this->authorize->client->company_id;
        $client_gateway_token->client_id = $this->authorize->client->id;
        $client_gateway_token->token = $payment_profile->getPaymentProfile()->getCustomerPaymentProfileId();
        $client_gateway_token->company_gateway_id = $this->authorize->company_gateway->id;
        $client_gateway_token->gateway_type_id = $this->payment_method;
        $client_gateway_token->gateway_customer_reference = $gateway_customer_reference;
        $client_gateway_token->meta = $this->buildPaymentMethod($payment_profile);
        $client_gateway_token->save();

        return $client_gateway_token;
    }

    public function buildPaymentMethod($payment_profile)
    {
        $payment_meta = new \stdClass;
        $payment_meta->exp_month = 'xx';
        $payment_meta->exp_year = 'xx';
        $payment_meta->brand = (string)$payment_profile->getPaymentProfile()->getPayment()->getCreditCard()->getCardType();
        $payment_meta->last4 = (string)$payment_profile->getPaymentProfile()->getPayment()->getCreditCard()->getCardNumber();
        $payment_meta->type = $this->payment_method;

        return $payment_meta;
    }

    public function addPaymentMethodToClient($gateway_customer_reference, $data)
    {
        error_reporting(E_ALL & ~E_DEPRECATED);

        $this->authorize->init();

        // Set the transaction's refId
        $refId = 'ref'.time();

        // Set the payment data for the payment profile to a token obtained from Accept.js
        $op = new OpaqueDataType();
        $op->setDataDescriptor($data['dataDescriptor']);
        $op->setDataValue($data['dataValue']);
        $paymentOne = new PaymentType();
        $paymentOne->setOpaqueData($op);

        $contact = $this->authorize->client->primary_contact()->first();

        if ($contact) {
            // Create the Bill To info for new payment type
            $billto = new CustomerAddressType();
            $billto->setFirstName($contact->present()->first_name());
            $billto->setLastName($contact->present()->last_name());
            $billto->setCompany($this->authorize->client->present()->name());
            $billto->setAddress($this->authorize->client->address1);
            $billto->setCity($this->authorize->client->city);
            $billto->setState($this->authorize->client->state);
            $billto->setZip($this->authorize->client->postal_code);

            if ($this->authorize->client->country_id) {
                $billto->setCountry($this->authorize->client->country->name);
            }

            $billto->setPhoneNumber($this->authorize->client->phone);
        }

        // Create a new Customer Payment Profile object
        $paymentprofile = new CustomerPaymentProfileType();
        $paymentprofile->setCustomerType('individual');

        if ($billto) {
            $paymentprofile->setBillTo($billto);
        }

        $paymentprofile->setPayment($paymentOne);
        $paymentprofile->setDefaultPaymentProfile(true);
        $paymentprofiles[] = $paymentprofile;

        // Assemble the complete transaction request
        $paymentprofilerequest = new CreateCustomerPaymentProfileRequest();
        $paymentprofilerequest->setMerchantAuthentication($this->authorize->merchant_authentication);

        // Add an existing profile id to the request
        $paymentprofilerequest->setCustomerProfileId($gateway_customer_reference);
        $paymentprofilerequest->setPaymentProfile($paymentprofile);
        $paymentprofilerequest->setValidationMode('liveMode');

        // Create the controller and get the response
        $controller = new CreateCustomerPaymentProfileController($paymentprofilerequest);
        $response = $controller->executeWithApiResponse($this->authorize->mode());

        if (($response != null) && ($response->getMessages()->getResultCode() == 'Ok')) {
            return $this->getPaymentProfile($gateway_customer_reference, $response->getCustomerPaymentProfileId());
        } else {
            $errorMessages = $response->getMessages()->getMessage();

            $message = 'Unable to add customer to Authorize.net gateway';

            if (is_array($errorMessages)) {
                $message = $errorMessages[0]->getCode().'  '.$errorMessages[0]->getText();
            }

            throw new GenericPaymentDriverFailure($message);
        }
    }

    public function getPaymentProfile($gateway_customer_reference, $payment_profile_id)
    {
        error_reporting(E_ALL & ~E_DEPRECATED);

        $this->authorize->init();

        // Set the transaction's refId
        $refId = 'ref'.time();

        //request requires customerProfileId and customerPaymentProfileId
        $request = new GetCustomerPaymentProfileRequest();
        $request->setMerchantAuthentication($this->authorize->merchant_authentication);
        $request->setRefId($refId);
        $request->setCustomerProfileId($gateway_customer_reference);
        $request->setCustomerPaymentProfileId($payment_profile_id);

        $controller = new GetCustomerPaymentProfileController($request);
        $response = $controller->executeWithApiResponse($this->authorize->mode());

        if (($response != null) && ($response->getMessages()->getResultCode() == 'Ok')) {
            return $response;
        } elseif ($response) {
            $errorMessages = $response->getMessages()->getMessage();

            $message = 'Unable to add payment method to Authorize.net gateway';

            if (is_array($errorMessages)) {
                $message = $errorMessages[0]->getCode().'  '.$errorMessages[0]->getText();
            }

            throw new GenericPaymentDriverFailure($message);
        } else {
            throw new GenericPaymentDriverFailure('Error communicating with Authorize.net');
        }
    }
}
