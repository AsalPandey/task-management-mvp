<?php

$reviewSlaDays = filter_var(env('TASK_REVIEW_SLA_DAYS', 2), FILTER_VALIDATE_INT);

return [
    'review_sla_days' => $reviewSlaDays !== false && $reviewSlaDays >= 1 && $reviewSlaDays <= 30
        ? $reviewSlaDays
        : 2,
];
