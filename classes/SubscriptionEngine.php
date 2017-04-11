<?php namespace Responsiv\Subscribe\Classes;

use Db;
use Carbon\Carbon;
use Responsiv\Subscribe\Models\Status as StatusModel;
use Responsiv\Subscribe\Models\StatusLog as StatusLogModel;
use Responsiv\Subscribe\Models\Service as ServiceModel;
use Responsiv\Subscribe\Models\Setting as SettingModel;
use Responsiv\Pay\Models\Invoice as InvoiceModel;
use Responsiv\Pay\Models\InvoiceStatus as InvoiceStatusModel;
use Exception;

/**
 * Subscription engine
 */
class SubscriptionEngine
{
    use \October\Rain\Support\Traits\Singleton;

    /**
     * @var Responsiv\Campaign\Classes\ServiceManager
     */
    protected $serviceManager;

    /**
     * @var Responsiv\Campaign\Classes\MembershipManager
     */
    protected $membershipManager;

    /**
     * @var Responsiv\Campaign\Classes\InvoiceManager
     */
    protected $invoiceManager;

    /**
     * @var Carbon\Carbon
     */
    protected $now;

    protected $serviceInvoiceCache = [];

    /**
     * Initialize this singleton.
     */
    protected function init()
    {
        $this->serviceManager = ServiceManager::instance();
        $this->membershipManager = MembershipManager::instance();
        $this->invoiceManager = InvoiceManager::instance();
        $this->now = Carbon::now();
    }

    public function reset()
    {
        $this->serviceInvoiceCache = [];
        $this->now(Carbon::now());
    }

    public function now($value = null)
    {
        if ($value) {
            $this->serviceManager->now = $value;
            $this->membershipManager->now = $value;
            $this->invoiceManager->now = $value;
            $this->now = $value;
        }

        return $this->now;
    }

    //
    // Hooks
    //

    public function invoiceAfterPayment(InvoiceModel $invoice)
    {
        if (
            $invoice &&
            $invoice->related &&
            $invoice->related instanceof ServiceModel
        ) {
            $this->receivePayment($invoice->related, $invoice);
        }
    }

    //
    // Services
    //

    /**
     * Receive a payment
     */
    public function receivePayment(ServiceModel $service, InvoiceModel $invoice, $comment = null)
    {
        $statusCode = $service->status ? $service->status->code : null;

        $isTrialInclusive = $service->plan ? $service->plan->isTrialInclusive() : false;

        // Include trial as part of the first period
        if ($isTrialInclusive && $statusCode == StatusModel::STATUS_TRIAL) {
            $service->delay_activated_at = $service->current_period_end;
        }

        if ($statusCode == StatusModel::STATUS_NEW || $statusCode == StatusModel::STATUS_TRIAL) {
            $service->count_renewal = 1;
            $this->serviceManager->activateService($service);
        }
        elseif ($statusCode == StatusModel::STATUS_GRACE) {
            $this->serviceManager->renewService($service);

            if ($service->hasServicePeriodEnded()) {
                $this->attemptRenewService($service);
            }
        }
        elseif ($statusCode == StatusModel::STATUS_ACTIVE) {
            $this->serviceManager->renewService($service);
        }
    }

    /**
     * Called at the end of a service period, this will raise an invoice, if not
     * existing already, and try to pay it.
     */
    public function attemptRenewService(ServiceModel $service)
    {
        /*
         * Raise a new invoice
         */
        $invoice = $this->invoiceManager->raiseServiceRenewalInvoice($service);

        /*
         * Attempt to pay the invoice automatically, otherwise the service
         * enters grace period or is cancelled via past due.
         */
        if (!$this->invoiceManager->attemptAutomaticPayment($invoice, $service)) {
            /*
             * Grace period
             */
            if ($service->hasGracePeriod()) {
                $this->serviceManager->startGracePeriod($service, 'Automatic payment failed');
            }
            /*
             * Past due / Cancelled
             */
            else {
                $this->serviceManager->pastDueService($service, 'Automatic payment failed');
            }
        }
    }
}
