<?php

namespace Api\Enrolment;

use Api\Exception\EnrolmentException;
use Api\Mail\MailManager;
use Api\Model\Membership;
use Api\Model\MembershipType;
use Api\Model\PaymentState;
use Api\Model\Product;
use Api\Model\User;
use Api\Model\PaymentMode;
use Api\Model\Payment;
use Api\Model\UserRole;
use Api\Model\UserState;
use Api\ModelMapper\MembershipMapper;
use Api\Settings;
use Api\User\UserManager;
use Carbon\Carbon;
use DateTime;
use DateInterval;
use Mollie\Api\MollieApiClient;

class EnrolmentManager
{
    private $user;
    private $mailMgr;
    private $logger;
    private $mollie;
    private $userMgr;

    function __construct($logger, User $user = null, MailManager $mailMgr = null, MollieApiClient $mollie = null,
                         UserManager $userMgr = null) {
        $this->user = $user;
        $this->logger = $logger;
        if (is_null($mailMgr)) {
            $this->mailMgr = new MailManager(null, null, $logger);
        } else {
            $this->mailMgr = $mailMgr;
        }
        if (is_null($mollie)) {
            $this->mollie = new MollieApiClient();
        } else {
            $this->mollie = $mollie;
        }
        if (is_null($userMgr)) {
            $this->userMgr = UserManager::instance($logger);
        } else {
            $this->userMgr = $userMgr;
        }
    }

    /**
     * @param $orderId
     * @param $paymentMode
     * @param $membershipTypeName
     * @return Payment
     * @throws EnrolmentException
     */
    function enrolmentByVolunteer($orderId, $paymentMode, $membershipTypeName, $startMembershipDate = null
        , $acceptTermsDate = null){
        if (strcasecmp ($membershipTypeName, MembershipType::REGULAR) == 0) {
            $membershipType = MembershipType::regular();
        } elseif (strcasecmp ($membershipTypeName, MembershipType::TEMPORARY) == 0) {
            $membershipType = MembershipType::temporary();
        } else {
            throw new EnrolmentException("Unexpected membership type " . $membershipTypeName, EnrolmentException::UNEXPECTED_MEMBERSHIP_TYPE);
        }
        return $this->enrolment($orderId, $paymentMode, $membershipType, true, $startMembershipDate
            , $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @param $membershipTypeName
     * @param $paymentCompleted true if payment has already been done
     * @param $startMembershipDate
     * @return Payment
     * @throws EnrolmentException
     */
    // TODO: test enrolment by transfer to check correct creation of payment
    // TODO: replace other usages of payment_mode and Settings for enrolment/renewal amount
    function enrolmentByTransfer($orderId, $membershipTypeName, $paymentCompleted = false,
                                 $startMembershipDate = null, $acceptTermsDate = null){
        if (strcasecmp ($membershipTypeName, MembershipType::REGULAR) == 0) {
            $membershipType = MembershipType::regular();
        } elseif (strcasecmp ($membershipTypeName, MembershipType::TEMPORARY) == 0) {
            $membershipType = MembershipType::temporary();
        } else {
            throw new EnrolmentException("Unexpected membership type " . $membershipTypeName, EnrolmentException::UNEXPECTED_MEMBERSHIP_TYPE);
        }
        return $this->enrolment($orderId, PaymentMode::TRANSFER, $membershipType, $paymentCompleted,
            $startMembershipDate, $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @param $startMembershipDate
     * @return Payment
     * @throws EnrolmentException
     */
    function enrolmentByStroom($orderId, $startMembershipDate = null, $acceptTermsDate = null){
        return $this->enrolment($orderId, PaymentMode::STROOM, MembershipType::stroom(),
            false, $startMembershipDate, $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @param $paymentMode
     * @param $membershipType
     * @param bool $paymentCompleted
     * @param $startMembershipDate
     * @return Payment
     * @throws EnrolmentException
     */
    function enrolment($orderId, $paymentMode, $membershipType, $paymentCompleted = false,
                       $startMembershipDate = null, $acceptTermsDate = null){
        if ($this->user->state == UserState::DISABLED) {
            // enrolment for a disabled user -> enable the user and check payment as first step of enrolment
            $this->user->state = UserState::CHECK_PAYMENT;
        }
        // Validations
        $this->checkUserStateEnrolment();
        // check user info is complete
        $this->checkUserInfo();

        // check accept_terms_date is set to value between last terms update and current date
        $this->checkTermsAccepted($acceptTermsDate);

        $payment = $this->lookupPayment($orderId, $paymentMode);
        if ($payment != null) { // payment already exists, check its state
            if ($paymentCompleted) {
                if ($payment->state != PaymentState::OPEN) {
                    throw new EnrolmentException("Unexpected payment state: should be " . PaymentState::SUCCESS . " but was $payment->state (orderId: $payment->order_id)",
                        EnrolmentException::UNEXPECTED_PAYMENT_STATE);
                }
            } else {
                if ($payment->state != PaymentState::OPEN) {
                    throw new EnrolmentException("Unexpected payment state: should be " . PaymentState::OPEN . " but was $payment->state (orderId: $payment->order_id)",
                        EnrolmentException::UNEXPECTED_PAYMENT_STATE);
                }
            }
        }

        // Create membership
        $membershipId = $this->createUserMembership($membershipType, $startMembershipDate);
        $membership = Membership::findOrFail($membershipId);
        $membership->last_payment_mode = $paymentMode;
        $membership->save();
        $this->user->membership_start_date = $membership->start_at;
        $this->user->membership_end_date = $membership->expires_at;
        $this->user->payment_mode = $membership->last_payment_mode;
        // check member role, if no member yet (eg. supporter), convert it to member
        if ($this->user->role != UserRole::ADMIN && $this->user->role != UserRole::MEMBER) {
            $this->user->role = UserRole::MEMBER;
        }
        $this->userMgr->update($this->user, false, false, false, false);

        // Create payment
        if ($payment == null) {
            $payment = $this->createNewPayment($orderId, $paymentMode, PaymentState::OPEN,
                $membership->subscription->price, Settings::CURRENCY, $membership);
        }

        if ($paymentCompleted) {
            // TODO: immediately confirm payment, but avoid too much extra mails!
            // + customize email message based on payment mode
            $this->confirmPayment($paymentMode, false, $payment, false);
        }

        // Send emails
        $this->mailMgr->sendEnrolmentConfirmation($this->user, $paymentMode, $paymentCompleted);
        if ($paymentMode == PaymentMode::STROOM) {
            $this->logger->info("Sending enrolment notification to " . ENROLMENT_NOTIF_EMAIL . "(user: " . $this->user->full_name . ")");
            $this->mailMgr->sendEnrolmentStroomNotification(ENROLMENT_NOTIF_EMAIL, $this->user, false);
            $this->logger->info("Sending enrolment notification to " . STROOM_NOTIF_EMAIL . "(user: " . $this->user->full_name . ")");
            $this->mailMgr->sendEnrolmentStroomNotification(STROOM_NOTIF_EMAIL, $this->user, false);
        }
        return $payment;
    }

    /**
     * @param $orderId
     * @param $redirectUrl
     * @param $requestedPaymentMean
     * @param $requestUri
     * @return \Mollie\Api\Resources\Payment
     * @throws EnrolmentException
     */
    function enrolmentByMollie($orderId, $redirectUrl, $requestedPaymentMean, $requestUri) {
        $this->checkUserStateEnrolment();
        $membershipType = MembershipType::regular();
        $payment = $this->lookupPayment($orderId, PaymentMode::MOLLIE);
        if ($payment == null) {
            // Create new payment
            $payment = $this->createNewPayment($orderId,PaymentMode::MOLLIE, PaymentState::OPEN,
                $membershipType->price, Settings::CURRENCY);

            $membershipId = $this->createUserMembership($membershipType);
            $membership = Membership::findOrFail($membershipId);
            $membership->last_payment_mode = PaymentMode::MOLLIE;
            $membership->payment()->save($payment);
            $membership->save();

            $this->user->payments()->save($payment);
        }
        $this->user->payment_mode = $membership->last_payment_mode;
        $this->user->membership_start_date = $membership->start_at;
        $this->user->membership_end_date = $membership->expires_at;
        $this->userMgr->update($this->user, false, false, false, false);
        //$this->user->save();
        $this->user->refresh();

        return $this->initiateMolliePayment($orderId, $membership->subscription->price, $redirectUrl,
            $requestedPaymentMean, $requestUri->getHost(), $requestUri->getScheme(), Product::ENROLMENT);
    }

    /**
     * @param $orderId
     * @param $paymentMode
     * @return Payment
     * @throws EnrolmentException
     */
    function renewalByVolunteer($orderId, $paymentMode, $acceptTermsDate = null) {
        return $this->renewal($orderId, $paymentMode, true, $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @return Payment
     * @throws EnrolmentException
     */
    function renewalByTransfer($orderId, $paymentCompleted = false, $acceptTermsDate = null) {
        return $this->renewal($orderId, PaymentMode::TRANSFER, $paymentCompleted, $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @return Payment
     * @throws EnrolmentException
     */
    function renewalByStroom($orderId, $acceptTermsDate = null) {
        return $this->renewal($orderId, PaymentMode::STROOM, false, $acceptTermsDate);
    }

    /**
     * @param $orderId
     * @param $paymentMode
     * @param bool $paymentCompleted
     * @param $acceptTermsDate
     * @return Payment
     * @throws EnrolmentException
     * @throws \Exception
     */
    function renewal($orderId, $paymentMode, $paymentCompleted = false, $acceptTermsDate = null) {
        $this->checkUserStateRenewal();
        // check accept_terms_date is set to value between last terms update and current date
        $this->checkTermsAccepted($acceptTermsDate);

        $payment = $this->lookupPayment($orderId, $paymentMode);
        if ($payment != null) { // payment already exists -> check its state
            if ($paymentCompleted) {
                if ($payment->state != PaymentState::SUCCESS) {
                    throw new EnrolmentException("Unexpected payment state: should be " . PaymentState::SUCCESS . " but was $payment->state (orderId: $payment->order_id)",
                        EnrolmentException::UNEXPECTED_PAYMENT_STATE);
                }
            } else {
                if ($payment->state != PaymentState::OPEN) {
                    throw new EnrolmentException("Unexpected payment state: should be " . PaymentState::OPEN . " but was $payment->state (orderId: $payment->order_id)",
                        EnrolmentException::UNEXPECTED_PAYMENT_STATE);
                }
            }
        }

        // identify next membership type
        if ($paymentMode == PaymentMode::STROOM) {
            $nextMembershipType = MembershipType::stroom();
        } else {
            $membership = Membership::findOrFail($this->user->active_membership);
            if (isset($membership->subscription->next_subscription_id)) {
                $nextMembershipType = MembershipType::find($membership->subscription->next_subscription_id);
            } else {
                $nextMembershipType = $membership->subscription;
            }
        }
        // create payment and new membership
        if ($payment == null) {
            $payment = $this->createNewPayment($orderId, $paymentMode, PaymentState::OPEN,
                $nextMembershipType->price, Settings::CURRENCY);

            // Making sure "renew membership" is executed only once
            // -> only when a new payment is created and linked to the new membership
            $renewalMembership = $this->renewMembership($nextMembershipType, $this->user->activeMembership);
            $renewalMembership->last_payment_mode = $paymentMode;
            $renewalMembership->payment()->save($payment);
            $this->user->memberships()->save($renewalMembership);

            // Direct activation if payment is already completed
//            if ($paymentCompleted) {
//                // FIXME: already done in confirmPayment, so could be removed?
//                $this->activateRenewalMembership($membership, $renewalMembership);
//            }
            $renewalMembership->save();
        }

        if ($paymentCompleted) {
            // TODO: immediately confirm payment, but avoid too much extra mails! -> set email notif to false
            // + customize email message based on payment mode
            $this->confirmPayment($paymentMode, true, $payment, false);
        }
        $activeMembership = Membership::find($this->user->active_membership);
        $this->user->payment_mode = $activeMembership->last_payment_mode;
        $this->user->membership_start_date = $activeMembership->start_at;
        $this->user->membership_end_date = $activeMembership->expires_at;
        $this->userMgr->update($this->user, false, false, false, false);
        //$this->user->save();

        $this->mailMgr->sendRenewalConfirmation($this->user, $paymentMode, $paymentCompleted);
        if ($paymentMode == PaymentMode::STROOM) {
            $this->mailMgr->sendEnrolmentStroomNotification(ENROLMENT_NOTIF_EMAIL, $this->user, true);
            $this->mailMgr->sendEnrolmentStroomNotification(STROOM_NOTIF_EMAIL, $this->user, true);
        }
        return $payment;
    }

    /**
     * @param $orderId
     * @param $redirectUrl
     * @param $requestedPaymentMean
     * @param $requestUri
     * @return \Mollie\Api\Resources\Payment
     * @throws EnrolmentException
     * @throws \Exception
     */
    function renewalByMollie($orderId, $redirectUrl, $requestedPaymentMean, $requestUri) {
        $this->checkUserStateRenewal();
        $membership = Membership::findOrFail($this->user->active_membership);
        if (isset($membership->subscription->next_subscription_id)) {
            $nextMembershipType = MembershipType::find($membership->subscription->next_subscription_id);
        } else {
            $nextMembershipType = $membership->subscription;
        }

        $payment = $this->lookupPayment($orderId, PaymentMode::MOLLIE);
        if ($payment == null) {
            // Create new payment
            $payment = $this->createNewPayment($orderId,PaymentMode::MOLLIE, PaymentState::OPEN,
                $nextMembershipType->price, Settings::CURRENCY);

            // Create renewal membership with status PENDING
            $renewalMembership = $this->renewMembership($nextMembershipType, $this->user->activeMembership);
            $renewalMembership->last_payment_mode = PaymentMode::MOLLIE;
            $renewalMembership->payment()->save($payment);
            $this->user->memberships()->save($renewalMembership);
//            $renewalMembership->contact()->save($this->user);
            $renewalMembership->save();
        };

        $this->userMgr->update($this->user, false, false, false, false);
        //$this->user->save();

        return $this->initiateMolliePayment($orderId, $renewalMembership->subscription->price, $redirectUrl,
            $requestedPaymentMean, $requestUri->getHost(), $requestUri->getScheme(), Product::RENEWAL, $renewalMembership->expires_at);
    }

    /**
     * Confirm an open payment
     * This method can be called directly after payment creation when the payment was already received (e.g. CASH or PAYCONIQ payment)
     * or through a separate API call after validating the payment completed (e.g. TRANSFER, STROOM)
     * MOLLIE payment should not be confirmed, as they are processed through webhook (see processMolliePayment)
     * @param $paymentMode
     * @param $renewal true if confirming a renewal
     * @param $payment payment to be confirmed. When null, payment is looked up based on user id and payment mode
     * @param $sendEmailNotif when true, an email notification will be sent to user as payment confirmation
     * @throws EnrolmentException
     * @throws \Api\Mail\EnrolmentException
     * @throws \Exception
     */
    function confirmPayment($paymentMode, $renewal = false, Payment $payment = null, $sendEmailNotif = true) {
        if ($paymentMode == PaymentMode::MOLLIE) {
            $message = "Unexpected confirmation for payment mode ($paymentMode)";
            $this->logger->warning($message);
            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_CONFIRMATION);
        }
        $this->checkPaymentMode($paymentMode);
        $this->checkUserStateAtPayment($this->user);

        // lookup payment
        if ($payment == null) {
            $payments = Payment::forMembership()->where([
                ['user_id', '=', $this->user->user_id],
                ['mode', '=', $paymentMode]
            ])->get();

            if (empty($payments) || count($payments) == 0)  {
                $message = "Unexpected confirmation, no payment found for user " . $this->user->firstname .
                    " (" . $this->user->user_id .") for payment mode (" . $paymentMode . ")";
                $this->logger->warning($message);
                // note: no payment, so unable to send 'enrolment failed' notification
                throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_CONFIRMATION);
            } else {
                $this->logger->info("payments found: " . \json_encode($payments));
            }
            if (count($payments) == 1) {
                $payment = $payments[0];
            } else {
                // more than 1 payment, search OPEN payment
                $payment = null;
                foreach ($payments as $p) {
                    if ($p->state == PaymentState::OPEN) {
                        if ( $payment == null) {
                            $payment = $p;
                        } else { // more than 1 OPEN payment
                            $message = "Unable to process confirmation, more than 1 open payments found (first payment is ["
                                . \json_encode($payment) . "] - second payment is [" . \json_encode($p)."])";
                            $this->logger->warning($message);
                            $this->mailMgr->sendEnrolmentFailedNotification(ENROLMENT_NOTIF_EMAIL, $this->user, $payment, $renewal, $message);
                            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_PAYMENT_STATE);
                        }
                    }
                }
            }
        }
        if ($payment->state == PaymentState::SUCCESS || $payment->state == PaymentState::FAILED) {
            // payment already confirmed/declined
            $message = "Unable to process confirmation, payment already confirmed/declined (payment is ["
                . \json_encode($payment) . ")";
            $this->logger->warning($message);
            $this->mailMgr->sendEnrolmentFailedNotification(ENROLMENT_NOTIF_EMAIL, $this->user, $payment, $renewal, $message);
            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_PAYMENT_STATE);

        }

        $membership = Membership::find($payment->membership_id);
        if ($membership->last_payment_mode != $paymentMode) {
            $message = "Unexpected confirmation for payment mode ($paymentMode), expected payment mode $membership->last_payment_mode";
            $this->logger->warning($message);
            $this->mailMgr->sendEnrolmentFailedNotification(ENROLMENT_NOTIF_EMAIL, $this->user, $payment, $renewal, $message);
            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_PAYMENT_MODE);
        }

        // update payment
        $payment->state = PaymentState::SUCCESS;
        $payment->save();

        // update membership status
        $currentMembership = Membership::find($this->user->active_membership);
        $this->activateRenewalMembership($currentMembership, $membership);

        // update project participants
        if ($paymentMode == PaymentMode::STROOM) {
            $this->user->addToStroomProject();
        }

        // update user
        $this->user->payment_mode = $membership->last_payment_mode;
        $this->user->membership_start_date = $membership->start_at;
        $this->user->membership_end_date = $membership->expires_at;
        $this->user->state = UserState::ACTIVE;
        // update user through user manager to also sync inventory!
        $this->userMgr->update($this->user, false, false, false, true);

        // send email notifications
        if ($sendEmailNotif &&
            ($paymentMode == PaymentMode::TRANSFER || $paymentMode == PaymentMode::STROOM) ) {
            $this->mailMgr->sendEnrolmentPaymentConfirmation($this->user, $paymentMode);
        }
    }

    /**
     * @param $paymentMode
     * @param $user
     * @throws EnrolmentException
     */
    function declinePayment($paymentMode, $user)
    {
        $this->checkPaymentMode($paymentMode);
        $this->checkUserStateAtPayment($user);

        // update payment
        $membership = $user->activeMembership()->first();
        $payment = Payment::find($membership->payment->payment_id);
        $payment->state = PaymentState::FAILED;
        $payment->save();

        // update status
        $membership->status = Membership::STATUS_CANCELLED; // keep a cancelled membership for history
        $membership->save();
        $user->state = UserState::DELETED;
        // update user through user manager to also sync inventory!
        $this->userMgr->update($user, false, false, false, true);
        if ($paymentMode == PaymentMode::STROOM) {
            $this->mailMgr->sendEnrolmentPaymentDecline($user, $paymentMode);
        }
    }

    /**
     * @param MembershipType $type
     * @return mixed
     * @throws \Exception
     */
    function createUserMembership(MembershipType $type, $startMembershipDate = null) {
        if (is_null($this->user->active_membership)) {
            $status = MembershipMapper::getMembershipStatus($this->user->state, $this->user->user_id);
            if (empty($startMembershipDate) ) {
                $start_date = strftime('%Y-%m-%d', $startMembershipDate);
            } else {
                $start_date = strftime('%Y-%m-%d', time());
            }
            $end_date = self::getMembershipEndDate($start_date, $type);
            self::createMembership($type, $start_date, $end_date, $this->user, $status);
        }
        return $this->user->active_membership;
    }

    /**
     * Create a new membership keeping start date and extending end date
     * The current membership is set to expired
     * New membership is set to active (assume payment has already been checked when calling this method)
     * @param MembershipType $newType new memberhip type
     * @param Membership $membership current membership
     * @return Membership active membership (copy of actual membership, eventual changes will be lost)
     * @throws \Exception
     */
    function renewMembership(MembershipType $newType, Membership $membership) : Membership {
        $end_date = self::getMembershipEndDate($membership->expires_at->format('Y-m-d'), $newType);

        $renewalMembership = self::createMembership($newType, $membership->start_at, $end_date, null, Membership::STATUS_PENDING);
        return $renewalMembership;
    }

    function activateRenewalMembership(Membership $currentMembership, Membership $renewedMembership) {
        $currentMembership->status = Membership::STATUS_EXPIRED;
        $currentMembership->save();

        $renewedMembership->status = Membership::STATUS_ACTIVE;
        $renewedMembership->save();
        if (isset($this->user)) {
            $this->logger->info("associating new membership");
            $this->user->activeMembership()->associate($renewedMembership);
            // update user through user manager to also sync inventory!
            $this->userMgr->update($this->user,
                false, false, false, false);
        }
    }

    /**
     * For yearly subscription, this method computes the end date of membership
     * Subscriptions ending in december are automatically extended until end of year
     * @param $startDateMembership (expected in 'YYYY-MM-DD' format)
     * @return string
     * @throws \Exception
     */
    public static function getMembershipEndDate($startDateMembership, $membershipType = null): string
    {
        if (! isset($membershipType) ) {
            $membershipType = MembershipType::regular();
        }
        $startDate = DateTime::createFromFormat('Y-m-d', $startDateMembership);
        if ($startDate == false) {
            throw new \InvalidArgumentException("Invalid date format (expecting 'YYYY-MM-DD'): " . $startDateMembership);
        }
        if ($membershipType->isYearlySubscription()) {
            $pivotDate = new DateTime('first day of december next year');
            $membershipEndDate = $startDate->add(new DateInterval('P1Y')); //$endDate->format('Y');
            if ($membershipEndDate > $pivotDate) { // extend membership until end of year
                $extendedEndDate = new DateTime('last day of december next year');
                if ($membershipEndDate < $extendedEndDate) {
                    $membershipEndDate = $extendedEndDate;
                }
            }
        } else {
            $membershipEndDate = $startDate->add(new DateInterval('P' . $membershipType->duration . 'D'));
        }
        return $membershipEndDate->format('Y-m-d');
    }

    /**
     * @param MembershipType $type
     * @param $start_date
     * @param $end_date
     * @param User $user
     * @param $status
     * @return created membership
     * @throws \Exception
     */
    public static function createMembership(MembershipType $type, $start_date, $end_date, ?User $user, $status) : Membership {
        $membership = new Membership();
        $membership->subscription_id = $type->id;
        $membership->start_at = $start_date;
        $membership->expires_at = $end_date;
        if (isset($user)) {
            $membership->contact_id = $user->user_id;
        }
        Membership::isValidStatus($status);
        $membership->status = $status;
        $membership->save();

        if (isset($user)) {
            $user->activeMembership()->associate($membership);
            //$this->userMgr->update($user); FIXME: -> not accessible, static method...
            $user->save();
        }
        return $membership;
    }
    /**
     * @param $orderId
     * @return Payment
     */
    protected function lookupPayment($orderId, $paymentMode, $userId = null)
    {
        if ($userId == null) {
            $userId = $this->user->user_id;
        }
        // use first() rather than get()
        // there should be only 1 result, but first returns a Model
        return Payment::where([
                ['order_id', '=', $orderId],
                ['user_id', '=', $userId],
                ['mode', '=', $paymentMode],
            ])->first();
    }

    /**
     * @param $orderId
     * @return Payment
     */
    protected function createNewPayment($orderId, $mode, $state = PaymentState::OPEN, $amount, $currency,
        Membership $membership = null): Payment
    {
        $payment = new Payment();
        $payment->mode = $mode;
        $payment->order_id = $orderId;
        $payment->user_id = $this->user->user_id;
        $payment->payment_date = new \DateTime();
        $payment->amount = $amount;
        $payment->currency = $currency;
        $payment->state = $state;
        if (isset($membership)) {
            $membership->payment()->save($payment);
        } else {
            $payment->save();
        }
        return $payment;
    }

    /**
     * @throws EnrolmentException
     */
    protected function checkUserStateEnrolment()
    {
        if ($this->user->state == UserState::ACTIVE || $this->user->state == UserState::EXPIRED) {
            throw new EnrolmentException("User already enrolled, consider a renewal", EnrolmentException::ALREADY_ENROLLED);
        }
        // FIXME: should also allow enrolment of DISABLED users?
        if ($this->user->state != UserState::CHECK_PAYMENT) {
            throw new EnrolmentException("User state unsupported for enrolment", EnrolmentException::UNSUPPORTED_STATE);
        }
    }

    protected function checkUserInfo() {
        if (empty($this->user->firstname) ) {
            throw new EnrolmentException("User firstname is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->lastname) ) {
            throw new EnrolmentException("User lastname is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->role) ) {
            throw new EnrolmentException("User role is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->email) ) {
            throw new EnrolmentException("User email is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->address) ) {
            throw new EnrolmentException("User address is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->postal_code) ) {
            throw new EnrolmentException("User postal code is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->city) ) {
            throw new EnrolmentException("User city is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
        if (empty($this->user->registration_number) ) {
            throw new EnrolmentException("User registration number is missing", EnrolmentException::INCOMPLETE_USER_DATA);
        }
    }

    /**
     * check accept_terms_date is set to value between last terms update and current date
     */
    protected function checkTermsAccepted($acceptTermsDate = null) {
        if (!empty($acceptTermsDate)
         && Carbon::now()->gte($acceptTermsDate)                 // exclude future dates
         && $this->user->accept_terms_date->lt($acceptTermsDate) // only update if more recent than current value
        ) {
            $this->user->accept_terms_date = $acceptTermsDate;
        }

        if (empty($this->user->accept_terms_date) ) {
            throw new EnrolmentException("User did not accept terms yet", EnrolmentException::ACCEPT_TERMS_MISSING);
        }
        if ($this->user->accept_terms_date->gt(Carbon::now())) {
            throw new EnrolmentException("Invalid accept terms date (" . $this->user->accept_terms_date->format('Y-m-d') . " is a future date)",
                EnrolmentException::ACCEPT_TERMS_MISSING);
        }
        $terms_date = Carbon::createFromFormat('Y-m-d', Settings::LAST_TERMS_DATE_UPDATE);
        if ($this->user->accept_terms_date->lt($terms_date)) {
            throw new EnrolmentException("Terms have been updated and need reapproval "
            . "(last terms update on : " .Settings::LAST_TERMS_DATE_UPDATE. ", last approval on " .$this->user->accept_terms_date->format('Y-m-d') .")",
                EnrolmentException::ACCEPT_TERMS_MISSING);
        }
    }
    /**
     * @throws EnrolmentException
     */
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
     * @param $amount
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
                    "currency" => Settings::CURRENCY,
                    "value" => number_format($amount, 2, '.', ',')
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
            $this->logger->info("Payment (Mollie) created with order id {$orderId} webhook {$protocol}://{$hostname}/enrolment/{$orderId} and redirectUrl {$redirectUrl}"
                . "-productId=$productId;membership_end_date=$membershipEndDate");
            // store payment id -> needed?
            return $payment;

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            echo "API call failed: " . htmlspecialchars($e->getMessage());
            $this->logger->error("API call failed: " . htmlspecialchars($e->getMessage()));
            throw new EnrolmentException("API call failed: " . htmlspecialchars($e->getMessage()), EnrolmentException::MOLLIE_EXCEPTION, $e);
        }
    }

    /**
     * Payment confirmation from Mollie payment processor
     * Activate new membership if successful or trigger notification for manual follow up in case of failure
     * @param $paymentId
     * @throws EnrolmentException
     */
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

            $payment = $this->lookupPayment($orderId, PaymentMode::MOLLIE, $userId);
            if ($payment == null) { // should no longer happen, payment is created at "POST enrolment"
                $this->logger->error("POST /enrolment/$orderId failed: payment with orderid $orderId, payment mode " . PaymentMode::MOLLIE . " and user id $userId is not found");
                throw new EnrolmentException("No payment found with orderid $orderId, payment mode " . PaymentMode::MOLLIE . " and user id $userId",
                    EnrolmentException::UNKNOWN_PAYMENT);
            };
            $currentPaymentState = $payment->state;
            if ($paymentMollie->isPaid() && !$paymentMollie->hasRefunds() && !$paymentMollie->hasChargebacks()) {
                /*
                 * The payment is paid and isn't refunded or charged back.
                 * At this point you'd probably want to start the process of delivering the product to the customer.
                 */
                $payment->state = PaymentState::SUCCESS;
            } elseif ($paymentMollie->isOpen()) {
                /*
                 * The payment is open.
                 */
                $payment->state = PaymentState::OPEN;
            } elseif ($paymentMollie->isPending()) {
                /*
                 * The payment is pending.
                 */
                $payment->state = PaymentState::PENDING;
            } elseif ($paymentMollie->isFailed()) {
                /*
                 * The payment has failed.
                 */
                $payment->state = PaymentState::FAILED;
            } elseif ($paymentMollie->isExpired()) {
                /*
                 * The payment is expired.
                 */
                $payment->state = PaymentState::EXPIRED;
            } elseif ($paymentMollie->isCanceled()) {
                /*
                 * The payment has been canceled.
                 */
                $payment->state = PaymentState::CANCELED;
            } elseif ($paymentMollie->hasRefunds()) {
                /*
                 * The payment has been (partially) refunded.
                 * The status of the payment is still "paid"
                 */
                $payment->state = PaymentState::REFUND;
            } elseif ($paymentMollie->hasChargebacks()) {
                /*
                 * The payment has been (partially) charged back.
                 * The status of the payment is still "paid"
                 */
                $payment->state = PaymentState::CHARGEBACK;
            }
            $this->logger->info("Saving payment for orderId $orderId with state $payment->state (Mollie payment id=$paymentId / Internal payment id = $payment->payment_id)");

            if ($currentPaymentState == $payment->state) {
                // no change in state -> no need to reprocess Mollie payment (and avoid to resend notifications)
                return;
            }
            $payment->save();

            // Lookup user and update state
            $user = \Api\Model\User::find($userId);
            if (null == $user) {
                $this->logger->error("POST /enrolment/$orderId failed: user $userId is not found");
                throw new EnrolmentException("No user found with id $userId", EnrolmentException::UNKNOWN_USER);
            } else {
                $this->user = $user;
            }

            $membership = $user->activeMembership()->first(); // lookup active membership
            if (null == $membership) {
                $this->logger->error("POST /enrolment/$orderId failed: user $userId is not enrolled");
                throw new EnrolmentException("User with id $userId is not enrolled", EnrolmentException::NOT_ENROLLED);
            }

            if ($productId == \Api\Model\Product::ENROLMENT) {
                if ($payment->state == PaymentState::SUCCESS) {
                    // FIXME: should be based on membership status instead of user state!
                    if ($user->state != UserState::ACTIVE || $membership->status != Membership::STATUS_ACTIVE) {
                        $membership->status = Membership::STATUS_ACTIVE;
                        $membership->save();

                        // update user data
                        $user->state = UserState::ACTIVE;
                        $user->payment_mode = $membership->last_payment_mode;
                        $user->membership_start_date = $membership->start_at;
                        $user->membership_end_date = $membership->expires_at;
                        $this->userMgr->update($user, false, false, false, true);

                        // send confirmation to new member
                        $this->mailMgr->sendEnrolmentConfirmation($user, PaymentMode::MOLLIE);
                        // send notification to Klusbib team
                        $this->mailMgr->sendEnrolmentSuccessNotification( ENROLMENT_NOTIF_EMAIL,$user, false);
                    }
                } else if ($payment->state == PaymentState::FAILED
                    || $payment->state == PaymentState::EXPIRED
                    || $payment->state == PaymentState::CANCELED
                    || $payment->state == PaymentState::REFUND
                    || $payment->state == PaymentState::CHARGEBACK) {
                    // Permanent failure, or special case -> send notification for manual follow up
                    $this->mailMgr->sendEnrolmentFailedNotification( ENROLMENT_NOTIF_EMAIL, $user, $payment, false, "payment failed");
                }
            } else if ($productId == \Api\Model\Product::RENEWAL) {
                if ($payment->state == PaymentState::SUCCESS) {
                    if ($user->state == UserState::ACTIVE
                        || $user->state == UserState::EXPIRED) {

                        $renewalMembership = $payment->membership()->first();

                        if (isset($renewalMembership)) {
                            $this->activateRenewalMembership($membership, $renewalMembership);
                        } else {
                            $errorMsg = "Successful mollie payment received, but no linked membership (payment=" . \json_encode($payment) . ")";
                            $this->logger->warning($errorMsg);
                            $this->mailMgr->sendEnrolmentFailedNotification(ENROLMENT_NOTIF_EMAIL, $user, $payment, true, $errorMsg);
                            throw new EnrolmentException( $errorMsg, EnrolmentException::UNEXPECTED_CONFIRMATION);
                        }
                        $user->state = UserState::ACTIVE;
                        $user->payment_mode = $renewalMembership->last_payment_mode;
                        $user->membership_start_date = $renewalMembership->start_at;
                        $user->membership_end_date = $renewalMembership->expires_at;
                        $this->userMgr->update($user, false, false, false, true);

                        // send confirmation to new member
                        $this->mailMgr->sendRenewalConfirmation($user, PaymentMode::MOLLIE);
                        // send notification to Klusbib team
                        $this->mailMgr->sendEnrolmentSuccessNotification( ENROLMENT_NOTIF_EMAIL, $user, true);

                    } else {
                        $errorMsg = "Successful mollie payment received, but unexpected user state " . $user->state;
                        $this->logger->warning($errorMsg);
                        $this->mailMgr->sendEnrolmentFailedNotification(ENROLMENT_NOTIF_EMAIL, $user, true, $errorMsg);
                        throw new EnrolmentException( $errorMsg, EnrolmentException::UNEXPECTED_CONFIRMATION);
                    }
                } else if ($payment->state == PaymentState::FAILED
                    || $payment->state == PaymentState::EXPIRED
                    || $payment->state == PaymentState::CANCELED
                    || $payment->state == PaymentState::REFUND
                    || $payment->state == PaymentState::CHARGEBACK) {
                    // update renewal membership status
                    $renewalMembership = $payment->membership()->first();
                    $renewalMembership->status = Membership::STATUS_CANCELLED;
                    $renewalMembership->save();

                    // Permanent failure, or special case -> send notification for manual follow up
                    $this->mailMgr->sendEnrolmentFailedNotification( ENROLMENT_NOTIF_EMAIL,$user, $payment, true, "payment failed");
                    // FIXME: to check: no exception thrown, failed confirmation should be accepted??
                }
            }

        } catch (\Mollie\Api\Exceptions\ApiException $e) {
            echo "Webhook call failed: " . htmlspecialchars($e->getMessage());
            throw new EnrolmentException($e->getMessage(), EnrolmentException::MOLLIE_EXCEPTION);
        }
    }

    /**
     * @param $paymentMode
     * @throws EnrolmentException
     */
    private function checkPaymentMode($paymentMode): void
    {
        if ($paymentMode != PaymentMode::CASH &&
            $paymentMode != PaymentMode::TRANSFER &&
            $paymentMode != PaymentMode::MBON &&
            $paymentMode != PaymentMode::SPONSORING &&
            $paymentMode != PaymentMode::LETS &&
            $paymentMode != PaymentMode::PAYCONIQ &&
            $paymentMode != PaymentMode::OTHER &&
            $paymentMode != PaymentMode::STROOM &&
            $paymentMode != PaymentMode::OVAM
        ) {
            $message = "Unsupported payment mode ($paymentMode)";
            $this->logger->warning($message);
            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_PAYMENT_MODE);
        }
    }

    /**
     * @param $user
     * @throws EnrolmentException
     */
    private function checkUserStateAtPayment($user): void
    {
        if ($user->state != UserState::CHECK_PAYMENT &&
            $user->state != UserState::ACTIVE &&
            $user->state != UserState::EXPIRED) {
            $message = "Unexpected confirmation for user state ($user->state)";
            $this->logger->warning($message);
            throw new EnrolmentException($message, EnrolmentException::UNEXPECTED_CONFIRMATION);
        }
    }
}