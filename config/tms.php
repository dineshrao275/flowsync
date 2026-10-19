<?php

return [
    // Working hours one person can take on per weekday; the workload report's capacity baseline.
    'capacity_hours_per_day' => (float) env('TMS_CAPACITY_HOURS_PER_DAY', 8),
];
