<?php namespace Responsiv\Subscribe\Models;

use Str;
use Carbon\Carbon;
use Model;
use Responsiv\Pay\Models\Tax;
use Responsiv\Pay\Models\InvoiceItem;
use Responsiv\Subscribe\Classes\ServiceManager;

/**
 * Plan Model
 */
class Plan extends Model
{
    use \October\Rain\Database\Traits\Nullable;
    use \October\Rain\Database\Traits\Validation;

    const TYPE_DAILY = 'daily';
    const TYPE_MONTHLY = 'monthly';
    const TYPE_YEARLY = 'yearly';
    const TYPE_LIFETIME = 'lifetime';

    /**
     * @var string The database table used by the model.
     */
    public $table = 'responsiv_subscribe_plans';

    public $rules = [
        'name' => 'required',
        'price' => 'required|numeric',
        'setup_price' => 'nullable|numeric',
        'membership_fee' => 'nullable|numeric',
        'renewal_period' => 'nullable|numeric',
        'plan_day_interval' => 'nullable|numeric',
        'plan_month_day' => 'nullable|numeric',
        'plan_month_interval' => 'nullable|numeric',
        'plan_year_interval' => 'nullable|numeric',
    ];

    /**
     * @var array Guarded fields
     */
    protected $guarded = [];

    /**
     * @var array Fillable fields
     */
    protected $fillable = [];

    /**
     * @var array List of attribute names which are json encoded and decoded from the database.
     */
    protected $jsonable = ['features'];

    /**
     * @var array List of attribute names which should be set to null when empty.
     */
    protected $nullable = ['grace_days', 'trial_days'];

    /**
     * @var array Relations
     */
    public $belongsTo = [
        'tax_class' => 'Responsiv\Pay\Models\Tax',
    ];

    public function filterFields($fields, $context = null)
    {
        if (!isset($fields->plan_type)) {
            return;
        }

        $planType = $this->plan_type ?: self::TYPE_MONTHLY;

        if ($planType != self::TYPE_MONTHLY) {
            $fields->plan_month_interval->hidden = true;
            $fields->plan_monthly_behavior->hidden = true;
            $fields->plan_month_day->hidden = true;
        }

        if ($planType != self::TYPE_DAILY) {
            $fields->plan_day_interval->hidden = true;
        }

        if ($planType != self::TYPE_YEARLY) {
            $fields->plan_year_interval->hidden = true;
        }

        if ($planType == self::TYPE_LIFETIME) {
            $fields->renewal_period->hidden = true;
        }

        if ($this->plan_monthly_behavior == 'monthly_signup') {
            $fields->plan_month_day->hidden = true;
        }
    }

    //
    // Scopes
    //

    public function scopeApplyActive($query)
    {
        return $query->where('is_active', true);
    }

    //
    // Options
    //

    public function getPlanTypeOptions()
    {
        return [
            self::TYPE_DAILY    => 'Daily',
            self::TYPE_MONTHLY  => 'Monthly',
            self::TYPE_YEARLY   => 'Yearly',
            self::TYPE_LIFETIME => 'Lifetime'
        ];
    }

    public function getPlanMonthlyBehaviorOptions()
    {
        return [
            'monthly_signup'  => ['Signup Date', 'Renew subscription every X months based on the signup date. For example if someone signs up on the 14th, the subscription will renew every month on the 14th.'],
            'monthly_prorate' => ['Prorated', 'Renew subscription the same day every X months and prorate billing for used time. For example if someone signs up on the 14th and your subscription renewal is on the 1st, they will be billed for 16 days at the start of the subscription.'],
            'monthly_free'    => ['Free Days', 'Renew subscription the same day every X months and do not bill until the renewal date. For example if someone signs up on the 14th and your subscription renewal is on the 1st, they will have free access for 16 days until renewal starts on the 1st.'],
            'monthly_none'    => ['No Start', 'Renew subscription the same day every X months and do not start the subscription until renewal date. For example if someone signs up on the 14th and your subscription renewal is on the 1st, do not start the subscription until 1st.'],
        ];
    }

    public function getPlanMonthDayOptions()
    {
        $result = [];

        for ($i = 1; $i <= 31; $i++) {
            $result[$i] = $i;
        }

        return $result;
    }

    //
    // Utils
    //

    public function isActive()
    {
        return $this->is_active;
    }

    public function isFree()
    {
        return $this->price == 0;
    }

    public function hasTrialPeriod()
    {
        return $this->getTrialPeriod() > 0;
    }

    public function getTrialPeriod()
    {
        if ($this->is_custom_membership) {
            return $this->trial_days;
        }

        return Setting::get('trial_days');
    }

    public function hasGracePeriod()
    {
        return $this->getGracePeriod() > 0;
    }

    public function getGracePeriod()
    {
        if ($this->is_custom_membership) {
            return $this->grace_days;
        }

        return Setting::get('grace_days');
    }

    public function hasSetupPrice()
    {
        return $this->getSetupPrice() > 0;
    }

    public function getSetupPrice()
    {
        return $this->setup_price;
    }

    public function hasMembershipPrice()
    {
        return $this->getMembershipPrice() > 0;
    }

    public function getMembershipPrice()
    {
        if ($this->is_custom_membership) {
            return $this->membership_price;
        }

        return Setting::get('membership_price');
    }

    public function getTaxClass()
    {
        if (!$this->tax_class) {
            $this->setRelation('tax_class', Tax::getDefault());
        }

        return $this->tax_class;
    }

    /**
     * Whether or not this plan can renew
     */
    public function isRenewable()
    {
        return $this->plan_type != self::TYPE_LIFETIME;
    }

    /**
     * Prorated monthy plans must always be trial inclusive.
     */
    public function isTrialInclusive()
    {
        if (
            $this->plan_type == self::TYPE_MONTHLY &&
            $this->plan_monthly_behavior == 'monthly_prorate'
        ) {
            return true;
        }

        return Setting::get('is_trial_inclusive');
    }

    /**
     * Returns the price to switch to this plan from another.
     */
    public function getSwitchPrice(Service $service)
    {
        return max($this->price - $service->price, 0);
    }

    /**
     * Returns true if switching to this plan is a downgrade.
     */
    public function isDowngrade(Service $service)
    {
        return $service->price > $this->price;
    }

    /**
     * Returns true if switching to this plan is an upgrade.
     */
    public function isUpgrade(Service $service)
    {
        return $this->price > $service->price;
    }

    //
    // Attributes
    //

    public function getTotalAttribute()
    {
        $setup = $this->setup_price ?: 0;

        return $this->price + $setup;
    }

    public function getTotalWithTaxAttribute()
    {
        return $this->total + $this->total_tax;
    }

    public function getTotalTaxAttribute()
    {
        return ($taxClass = $this->getTaxClass())
            ? $taxClass->getTotalTax($this->total)
            : 0;
    }

    public function getSetupPriceWithTaxAttribute()
    {
        return $this->setup_price + $this->setup_tax;
    }

    public function getSetupTaxAttribute()
    {
        return ($taxClass = $this->getTaxClass())
            ? $taxClass->getTotalTax($this->setup_price)
            : 0;
    }

    public function getPriceWithTaxAttribute()
    {
        return $this->getTaxAmountAttribute() + $this->price;
    }

    public function getPriceTaxAttribute()
    {
        return ($taxClass = $this->getTaxClass())
            ? $taxClass->getTotalTax($this->price)
            : 0;
    }

    public function getPlanTypeNameAttribute()
    {
        $message = '';

        if ($this->hasTrialPeriod()) {
            $message .= sprintf(
                'Trial period for %s %s then ',
                $this->getTrialPeriod(),
                Str::plural('day', $this->getTrialPeriod())
            );
        }

        if ($this->plan_type == self::TYPE_DAILY) {
            if ($this->plan_day_interval > 1) {
                $message .= sprintf('Renew every %s days', $this->plan_day_interval);
            }
            else {
                $message .= 'Renew every day';
            }
        }
        elseif ($this->plan_type == self::TYPE_MONTHLY) {
            if ($this->plan_monthly_behavior == 'monthly_signup') {
                $message .= sprintf('Renew every %s %s based on the signup date',
                    $this->plan_month_interval,
                    Str::plural('month', $this->plan_month_interval)
                );
            }
            elseif ($this->plan_monthly_behavior == 'monthly_prorate') {
                $message .= sprintf('Renew on the %s of the month and prorate billing for used time', Str::ordinal($this->plan_month_day));
            }
            elseif ($this->plan_monthly_behavior == 'monthly_free') {
                $message .= sprintf('Renew on the %s of the month and do not bill until the renewal date', Str::ordinal($this->plan_month_day));
            }
            elseif ($this->plan_monthly_behavior == 'monthly_none') {
                $message .= sprintf('Renew on the %s of the month and do not start the subscription until the renewal date', Str::ordinal($this->plan_month_day));
            }
        }
        elseif ($this->plan_type == self::TYPE_YEARLY) {
            if ($this->plan_year_interval > 1) {
                $message .= sprintf('Renew every %s years', $this->plan_year_interval);
            }
            else {
                $message .= 'Renew every year';
            }
        }
        elseif ($this->plan_type == self::TYPE_LIFETIME) {
            $message .= 'Never renew (lifetime membership)';
        }

        if ($this->plan_type != self::TYPE_LIFETIME && $this->renewal_period > 0) {
            $message .= sprintf(' for %s renewal periods', $this->renewal_period);
        }

        if ($this->hasGracePeriod()) {
            $message .= sprintf(
                ' and Grace period for %s %s',
                $this->getGracePeriod(),
                Str::plural('day', $this->getGracePeriod())
            );
        }

        return $message;
    }

    //
    // Date calculations
    //

    /*
     * Get start date for selected plan
     */
    public function getPeriodStartDate($current = null)
    {
        if (!$current) {
            $current = $this->freshTimestamp();
        }

        $result = clone $current;

        if ($this->plan_type == self::TYPE_MONTHLY) {
            /*
             * Do not start the subscription until the start renewal period
             */
            if ($this->plan_monthly_behavior == 'monthly_none') {

                $checkEndDay = $this->checkDate($this->plan_month_day, $current->month, $current->year);

                if ($current->day <= $checkEndDay) {
                    $result->day = $checkEndDay;
                }
                else {
                    $result = $result->addMonth();
                    $result->day = $this->checkDate($this->plan_month_day, $result->month, $result->year);
                }
            }
        }

        return $result;
    }

    /*
     * Get end date for selected plan
     */
    public function getPeriodEndDate($start = null)
    {
        if (!$start) {
            $start = $this->freshTimestamp();
        }

        $date = clone $start;
        $result = clone $start;

        switch ($this->plan_type) {
            case self::TYPE_DAILY:
                $result = $date->addDays($this->plan_day_interval);
                break;

            case self::TYPE_MONTHLY:
                $next = clone $date;
                $next->addMonth();

                if ($this->plan_monthly_behavior == 'monthly_signup') {
                    $result->year = $next->year;
                    $result->month = $next->month;
                    $result->day = $this->checkDate($date->day, $next->month, $next->year);
                }
                elseif ($this->plan_monthly_behavior == 'monthly_prorate') {
                    // Get plan end day of this month
                    $checkEndDay = $this->checkDate($this->plan_month_day, $date->month, $date->year);

                    // On the same day
                    if ($date->day == $checkEndDay) {
                        $result->year = $next->year;
                        $result->month = $next->month;
                        $result->day = $this->checkDate($this->plan_month_day, $next->month, $next->year);
                    }
                    // Is it going to renew this month
                    elseif ($date->day < $checkEndDay) {
                        $result->year = $date->year;
                        $result->month = $date->month;
                        $result->day = $checkEndDay;
                    }
                    // Passed the renewal, set for next month
                    else {
                        $result->year = $next->year;
                        $result->month = $next->month;
                        $result->day = $this->checkDate($this->plan_month_day, $next->month, $next->year);
                    }
                }
                elseif ($this->plan_monthly_behavior == 'monthly_free' || $this->plan_monthly_behavior == 'monthly_none') {
                    $result->year = $next->year;
                    $result->month = $next->month;
                    $result->day = $this->checkDate($this->plan_month_day, $next->month, $next->year);
                }
                else {
                    throw new ApplicationException('Unknown monthly behavior: '.$this->plan_monthly_behavior);
                }
                break;

            case self::TYPE_YEARLY:
                $result = $date->addYears($this->plan_year_interval);
                break;

            case self::TYPE_LIFETIME:
                $result = null;
                break;

            default:
                throw new ApplicationException('Unknown membership plan: '.$this->plan_type);
                break;
        }

        return $result;
    }

    /*
     * Returns first valid day from date
     */
    protected function checkDate($day, $month, $year)
    {
        // All months have less than 28 days
        if ($day <= 28) {
            return $day;
        }

        // Check if month has a valid day
        for ($checkDay = $day; $checkDay > 28; $checkDay--) {
            if (checkdate($month, $checkDay, $year)) {
                return $checkDay;
            }
        }
    }

    /*
     * Prorate the price of an item
     */
    public function adjustPrice($originalPrice, $currentDate = null)
    {
        if ($this->plan_type != self::TYPE_MONTHLY) {
            return $originalPrice;
        }

        if ($this->plan_monthly_behavior != 'monthly_prorate') {
            return $originalPrice;
        }

        // Get days until next billing
        $billableDays = $this->daysUntilBilling($currentDate);

        if (!$billableDays || $billableDays <= 0) {
            return $originalPrice;
        }

        // Get total days in billing cycle
        $totalDays = $this->daysInCycle($currentDate);

        if (!$totalDays || $totalDays <= 0) {
            return $originalPrice;
        }

        // Set daily rate based on original price divided by days since / until billing day
        $dayRate = (float) $originalPrice / $totalDays;

        // Prorate the price: total days left in cycle times day rate
        $newPrice = $billableDays * $dayRate;

        return round($newPrice, 2);
    }

    /**
     * Get number of days until the next billing cycle.
     */
    public function daysUntilBilling($current = null)
    {
        if ($this->plan_type == self::TYPE_LIFETIME) {
            return null;
        }

        if (!$current) {
            $current = $this->freshTimestamp();
        }

        $days = null;

        switch ($this->plan_type) {
            case self::TYPE_MONTHLY:
                if ($this->plan_monthly_behavior == 'monthly_signup') {
                    break;
                }

                // Get plan end day of this month
                $endDay = (int) $this->checkDate(
                    $this->plan_month_day,
                    $current->month,
                    $current->year
                );

                // On the same day
                if ($current->day == $endDay) {
                    $days = 0;
                    break;
                }

                // Current day less than the next billing cycle
                if ($current->day < $endDay) {
                    $days = $endDay - $current->day;
                    break;
                }

                // Current day is greater than the next billing cycle
                $next = clone $current;
                $next->addMonth(1);

                $nextDay = (int) $this->checkDate(
                    $this->plan_month_day,
                    $next->month,
                    $next->year
                );

                $next->day = $nextDay;
                $days = $next->diffInDays($current);
                break;
        }

        return $days;
    }

    /**
     * Get how many days are in a billing cycle.
     *
     * - daily: day interval
     * - yearly: yearly interval
     * - monthly: days in last month if we passed the monthly interval,
     *            or the days in current month if not.
     */
    public function daysInCycle($current = null)
    {
        if ($this->plan_type == self::TYPE_LIFETIME) {
            return null;
        }

        if (!$current) {
            $current = $this->freshTimestamp();
        }

        $days = null;

        switch ($this->plan_type) {

            case self::TYPE_DAILY:
                $days = $this->plan_day_interval;
                break;

            case self::TYPE_YEARLY:
                $days = $this->plan_year_interval;
                break;

            case self::TYPE_MONTHLY:
                if ($this->plan_monthly_behavior == 'monthly_signup') {
                    break;
                }

                // Get plan end day of this month
                $endDay = (int) $this->checkDate(
                    $this->plan_month_day,
                    $current->month,
                    $current->year
                );

                // On the same day
                if ($current->day == $endDay) {
                    $days = 0;
                    break;
                }

                // If today is less than ending day, look at last month
                if ($current->day < $endDay) {
                    $current->subMonth();
                }

                $days = $current->daysInMonth;
                break;
        }

        return $days;
    }

    /**
     * Get a fresh timestamp for the model.
     *
     * @return \Carbon\Carbon
     */
    public function freshTimestamp()
    {
        return clone ServiceManager::instance()->now;
    }
}
