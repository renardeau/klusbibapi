<?php

namespace Api\Enrolment;


use Api\Exception\EnrolmentException;
use Api\Mail\MailManager;
use Api\Model\Product;
use Api\Model\User;
use Api\Model\PaymentMode;
use Api\Model\Payment;
use Api\Model\UserState;
use Api\Settings;
use DateTime;
use DateInterval;
use Mollie\Api\MollieApiClient;

class EnrolmentManager
{
    private $user;
    private $mailMgr;
    private $logger;
    private $mollie;

    function __construct($logger, User $user = null, MailManager $mailMgr = null, MollieApiClient $mollie = null) {
        $this->user = $user;
        $this->logger = $logger;
        if (is_null($mailMgr)) {
            $this->mailMgr = new MailManager();
        } else {
            $this->mailMgr = $mailMgr;
        }
        if (is_null($mollie)) {
            $this->mollie = new MollieApiClient();
        } else {
            $this->mollie = $mollie;
        }
    }

    function enrolmentByTransfer($orderId){
        $this->checkUserStateEnrolment();
        $this->user->payment_mode = PaymentMode::TRANSFER;
        $this->user->save();
        $payment = $this->lookupPayment($orderId);

        if ($payment == null) {
            $payment = $this->createNewPayment($orderId);
        };
        $this->mailMgr->sendEnrolmentConfirmation($this->user, PaymentMode::TRANSFER);
        return $payment;
    }

    function renewalByTransfer($orderId) {
        $this->checkUserStateRenewal();
        $this->user->payment_mode = PaymentMode::TRANSFER;
        $payment = $this->lookupPayment($orderId);

        if ($payment == null) {
            $payment = $this->createNewPayment($orderId,PaymentMode::TRANSFER, "OPEN",\Api\Settings::RENEWAL_AMOUNT, "EUR");
        };
        $this->mailMgr->sendRenewalConfirmation($this->user, PaymentMode::TRANSFER);
        $this->user->save();
        return $payment;
    }

    function enrolmentByMollie($orderId, $redirectUrl, $requestedPaymentMean, $requestUri) {
        $this->checkUserStateEnrolment();
        $this->user->payment_mode = PaymentMode::MOLLIE;
        $this->user->save();

        // Determine the url parts
//        $protocol = isset($_SERVER['HTTPS']) && strcasecmp('off', $_SERVER['HTTPS']) !== 0 ? "https" : "http";
//        if ($protocol == "http") {
//            $this->logger->warn("payment triggered on unsecure connection (SERVER var HTTPS=" .
//                (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : "not set") . ")");
//            $this->logger->warn("request uri scheme=". $requestUri->getScheme()
//                . ";host=" . $requestUri->getHost() . ";port=" . $requestUri->getPort());
//        }
//        $hostname = $_SERVER['HTTP_HOST'];

        return $this->initiateMolliePayment($orderId, \Api\Settings::ENROLMENT_AMOUNT_STRING, $redirectUrl,
            $requestedPaymentMean, $requestUri->getHost(), $requestUri->getScheme(), Product::ENROLMENT);
    }
    function renewalByMollie($orderId, $redirectUrl, $requestedPaymentMean, $requestUri) {
        $this->checkUserStateRenewal();
        $this->user->payment_mode = PaymentMode::MOLLIE;
        $this->user->save();
        $membershipEndDate = $this->getMembershipEndDate($this->user->membership_end_date);
        // Determine the url parts
//        $protocol = isset($_SERVER['HTTPS']) && strcasecmp('off', $_SERVER['HTTPS']) !== 0 ? "https" : "http";
//        if ($protocol == "http") {
//            $this->logger->warn("payment triggered on unsecure connection (SERVER var HTTPS=" .
//                (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : "not set") . ")");
//            $this->logger->warn("request uri scheme=". $requestUri->getScheme()
//                . ";host=" . $requestUri->getHost() . ";port=" . $requestUri->getPort());
//        }
//        $hostname = $_SERVER['HTTP_HOST'];

        return $this->initiateMolliePayment($orderId, \Api\Settings::ENROLMENT_AMOUNT_STRING, $redirectUrl,
            $requestedPaymentMean, $requestUri->getHost(), $requestUri->getScheme(), Product::RENEWAL, $membershipEndDate);
    }

    /**
     * @param $startDateMembership
     * @return string
     * @throws \Exception
     */
    protected function getMembershipEndDate($currentEndDateMembership): string
    {
        $endDate = DateTime::createFromFormat('Y-m-d', $currentEndDateMembership);
        $pivotDate = new DateTime('first day of december this year');
//        $pivotDate = DateTime::createFromFormat('Y-m-d', date('Y') . '-12-01');
        $membershipEndDate = $endDate->add(new DateInterval('P1Y')); //$endDate->format('Y');
        $currentDate = new DateTime();
        if ($currentDate > $pivotDate) { // extend membership until end of year
            $extendedEndDate = $currentDate->modify('last day of december next year');
            if ($membershipEndDate < $extendedEndDate) {
                $membershipEndDate = $extendedEndDate;
            }
        }
        return $membershipEndDate->format('Y-m-d');
    }
    /**
     * @param $orderId
     * @return Payment
     */
    protected function lookupPayment($orderId)
    {
        // use first() rather than get()
        // there should be only 1 result, but first returns a Model
        $payment = Payment::where([
            ['order_id', '=', $orderId],
            ['user_id', '=', $this->user->user_id],
            ['mode', '=', PaymentMode::TRANSFER],
        ])->first();
        return $payment;
    }

    /**
     * @param $orderId
     * @return Payment
     */
    protected function createNewPayment($orderId, $mode = PaymentMode::TRANSFER, $state = "OPEN", $amount = \Api\Settings::ENROLMENT_AMOUNT, $currency = "EUR"): Payment
    {
        $payment = new Payment();
        $payment->mode = $mode;
        $payment->order_id = $orderId;
        $payment->user_id = $this->user->user_id;
        $payment->payment_date = new \DateTime();
        $payment->amount = $amount;
        $payment->currency = $currency;
        $payment->state = $state;
        $payment->save();
        return $payment;
    }

    protected function checkUserStateEnrolment()
    {
        if ($this->user->state == UserState::ACTIVE || $this->user->state == UserState::EXPIRED) {
            throw new EnrolmentException("User already enrolled, consider a renewal", EnrolmentException::ALREADY_ENROLLED);
        }
        if ($this->user->state != UserState::CHECK_PAYMENT) {
            throw new EnrolmentException("User state unsupported for enrolment", EnrolmentException::UNSUPPORTED_STATE);
        }
    }
    protected function checkUserStateRenewal()
    {
        if ($this->user->state == UserState::CHECK_PAYMENT) {
            throw new EnrolmentException("Enrolment not yet complete, consider an enrolment", EnrolmentException::NOT_ENROLLED);
        }
        if ($this->user->state != UserState::ACTIVE && $this->user->state != UserState::EXPIRED) {
            throw new EnrolmentException("User state unsupported for renewal", EnrolmentException::UNSUPPORTED_STATE);
        }
    }

    /**
     * @param $orderId
     * @param $redirectUrl url to be called after Mollie payment
     * @param $requestedPaymentMean payment mean that will be used in Mollie transaction (when null, a choice screen is shown)
     * @param $hostname hostname to use in webhook url
     * @param $protocol protocol used in webhook url (http or https)
     * @throws EnrolmentException
     */
    protected function initiateMolliePayment($orderId, $amount, $redirectUrl, $requestedPaymentMean, $hostname, $protocol, $productId, $membershipEndDate = null)
    {
        if ($productId == Product::ENROLMENT) {
            $description = "Klusbib inschrijving {$this->user->firstname} {$this->user->lastname}";
        } elseif ($productId == Product::RENEWAL) {
            $description = "Klusbib verlenging lidmaatschap {$this->user->firstname} {$this->user->lastname}";
        }
        try {
            $this->mollie->setApiKey(MOLLIE_API_KEY);
            $paymentData = [
                "amount" => [
                    "currency" => "EUR",
                    "value" => $amount
                ],
                "description" => $description,
                "redirectUrl" => "{$redirectUrl}?orderId={$orderId}",
//                "webhookUrl" => "{$protocol}://{$hostname}/Enrolment/{$orderId}",
                "webhookUrl" => "https://{$hostname}/enrolment/{$orderId}",
                "locale" => Settings::MOLLIE_LOCALE,
                "metadata" => [
                    "order_id" => $orderId,
                    "user_id" => $this->user->user_id,
                    "product_id" => $productId,
                    "membership_end_date" => $membershipEndDate
                ],
            ];

            if (isset($requestedPaymentMean) && !empty($requestedPaymentMean)) {
                $paymentData["method"] = $requestedPaymentMean;
            }
//            $this->logger->info("payment data = " . print_r($paymentData, TRUE));
            $payment = $this->mollie->payments->create($paymentData);
            $this->logger->info("Payment created with order id {$orderId} webhook {$protocol}://{$hostname}/enrolment/{$orderId} and redirectUrl {$redirectUrl}"
                . "-productId=$productId;membership_end_date=$membershipEndDate");
            // store payment id -> needed?
            return $payment;

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            echo "API call failed: " . htmlspecialchars($e->getMessage());
            $this->logger->error("API call failed: " . htmlspecialchars($e->getMessage()));
            throw new EnrolmentException("API call failed: " . htmlspecialchars($e->getMessage()), EnrolmentException::MOLLIE_EXCEPTION, $e);
        }
    }

    public function processMolliePayment($paymentId) {
        try {
            $this->mollie->setApiKey(MOLLIE_API_KEY);

            /*
             * Retrieve the payment's current state.
             * See also https://docs.mollie.com/payments/status-changes
             */
            $paymentMollie = $this->mollie->payments->get($paymentId);
            $this->logger->info('Mollie payment:' . json_encode($paymentMollie));
            $orderId = $paymentMollie->metadata->order_id;
            $userId = $paymentMollie->metadata->user_id;
            $productId = $paymentMollie->metadata->product_id;
            $newMembershipEndDate = $paymentMollie->metadata->membership_end_date;

            // use first() rather than get()
            // there should be only 1 result, but first returns a Model
            $payment = \Api\Model\Payment::where([
                ['order_id', '=', $orderId],
                ['user_id', '=', $userId],
                ['mode', '=', 'MOLLIE'],
            ])->first();
            if ($payment == null) {
                // Create new payment
                $payment = new \Api\Model\Payment();
                $payment->mode = 'MOLLIE';
                $payment->order_id = $orderId;
                $payment->user_id = $userId;
                $payment->payment_date = new \DateTime();
                $payment->amount = $paymentMollie->amount->value;
                $payment->currency = $paymentMollie->amount->currency;
            };

            if ($paymentMollie->isPaid() && !$paymentMollie->hasRefunds() && !$paymentMollie->hasChargebacks()) {
                /*
                 * The payment is paid and isn't refunded or charged back.
                 * At this point you'd probably want to start the process of delivering the product to the customer.
                 */
                $payment->state = "SUCCESS";
            } elseif ($paymentMollie->isOpen()) {
                /*
                 * The payment is open.
                 */
                $payment->state = "OPEN";
            } elseif ($paymentMollie->isPending()) {
                /*
                 * The payment is pending.
                 */
                $payment->state = "PENDING";
            } elseif ($paymentMollie->isFailed()) {
                /*
                 * The payment has failed.
                 */
                $payment->state = "FAILED";
            } elseif ($paymentMollie->isExpired()) {
                /*
                 * The payment is expired.
                 */
                $payment->state = "EXPIRED";
            } elseif ($paymentMollie->isCanceled()) {
                /*
                 * The payment has been canceled.
                 */
                $payment->state = "CANCELED";
            } elseif ($paymentMollie->hasRefunds()) {
                /*
                 * The payment has been (partially) refunded.
                 * The status of the payment is still "paid"
                 */
                $payment->state = "REFUND";
            } elseif ($paymentMollie->hasChargebacks()) {
                /*
                 * The payment has been (partially) charged back.
                 * The status of the payment is still "paid"
                 */
                $payment->state = "CHARGEBACK";
            }
            $this->logger->info("Saving payment for orderId $orderId with state $payment->state (Mollie payment id=$paymentId / Internal payment id = $payment->payment_id)");
            $payment->save();

            // Lookup user and update state
            $user = \Api\Model\User::find($userId);
            if (null == $user) {
                $this->logger->error("POST /enrolment/$orderId failed: user $userId is not found");
                throw new EnrolmentException("No user found with id $userId", EnrolmentException::UNKNOWN_USER);
            }

            $mailmgr = new MailManager();
            if ($productId == \Api\Model\Product::ENROLMENT) {
                if ($payment->state == "SUCCESS") {
                    if ($user->state == UserState::CHECK_PAYMENT) {
                        $user->state = UserState::ACTIVE;
                        $user->save();
                        // send confirmation to new member
                        $mailmgr->sendEnrolmentConfirmation($user, PaymentMode::MOLLIE);
                        // send notification to Klusbib team
                        $mailmgr->sendEnrolmentSuccessNotification( ENROLMENT_NOTIF_EMAIL,$user);
                    }
                } else if ($payment->state == "FAILED"
                    || $payment->state == "EXPIRED"
                    || $payment->state == "CANCELED"
                    || $payment->state == "REFUND"
                    || $payment->state == "CHARGEBACK") {
                    // Permanent failure, or special case -> send notification for manual follow up
                    $mailmgr->sendEnrolmentFailedNotification( ENROLMENT_NOTIF_EMAIL,$user, $payment);
                }
            } else if ($productId == \Api\Model\Product::RENEWAL) {
                if ($payment->state == "SUCCESS") {
                    if ($user->state == UserState::ACTIVE
                        || $user->state == UserState::EXPIRED) {
                        $user->state = UserState::ACTIVE;

                        $user->membership_end_date = $newMembershipEndDate;
                        $user->save();
                        // send confirmation to new member
                        $mailmgr->sendRenewalConfirmation($user, PaymentMode::MOLLIE);
                        // send notification to Klusbib team
                        $mailmgr->sendEnrolmentSuccessNotification( ENROLMENT_NOTIF_EMAIL,$user);
                    }
                } else if ($payment->state == "FAILED"
                    || $payment->state == "EXPIRED"
                    || $payment->state == "CANCELED"
                    || $payment->state == "REFUND"
                    || $payment->state == "CHARGEBACK") {
                    // Permanent failure, or special case -> send notification for manual follow up
                    $mailmgr->sendEnrolmentFailedNotification( ENROLMENT_NOTIF_EMAIL,$user, $payment);
                }
            }

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            echo "Webhook call failed: " . htmlspecialchars($e->getMessage());
            throw new EnrolmentException($e->getMessage(), EnrolmentException::MOLLIE_EXCEPTION);
        }
    }
}