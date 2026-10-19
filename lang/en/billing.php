<?php

return [
    'price_change' => [
        'subject_up' => 'Your :plan price is changing on :date',
        'subject_down' => 'Your :plan price is going down on :date',
        'greeting' => 'Hello :organisation,',
        'increase' => 'We are updating the price of the :plan plan. From :date — your next renewal — you will be charged :new per :cycle instead of :old.',
        'decrease' => 'Good news: the price of the :plan plan is going down. From :date — your next renewal — you will be charged :new per :cycle instead of :old.',
        'nothing_now' => 'Nothing changes until then: the period you have already paid for is unaffected, and you will not be charged anything extra today.',
        'options' => 'You can review or change your plan, or cancel before the renewal, under Subscription in :app.',
        'link' => 'Manage your subscription: :url',
        'cycle_monthly' => 'month',
        'cycle_annual' => 'year',
    ],
    'trial_ending' => [
        'subject' => 'Your :app trial ends on :date',
        'body' => 'Your free trial for :organisation ends on :date. Choose a plan before then to keep using :app without interruption — your data stays safe either way.',
    ],
    'card_expiring' => [
        'subject' => 'The card on your :app account is expiring',
        'body' => 'The :brand card ending in :last4 expires on :expires. Update your payment method under Subscription to avoid a failed renewal.',
    ],
    'payment_action_required' => [
        'subject' => 'Action needed to complete your :app payment',
        'body' => 'Your bank asks you to confirm the latest :app payment. Open Subscription and use the payment link, or update your payment method.',
    ],
    'plan_change_scheduled' => [
        'subject' => 'Your plan change is scheduled',
        'body' => 'Your subscription will move from :from to :to on :date, when the current billing period ends. You keep your current plan until then and will not be charged twice.',
    ],
];
