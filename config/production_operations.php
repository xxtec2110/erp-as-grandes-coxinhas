<?php

return ['briefings_enabled' => (bool) env('PRODUCTION_BRIEFINGS_ENABLED', false), 'alerts_enabled' => (bool) env('PRODUCTION_ALERTS_ENABLED', false), 'regularization_notice' => (bool) env('PRODUCTION_REGULARIZATION_NOTICE', false)];
