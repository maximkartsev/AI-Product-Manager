<?php

namespace App\Services;

class AwsInterruptionSignalCatalog
{
    /**
     * @return array<int, string>
     */
    public static function supportedSignals(): array
    {
        return [
            'EC2 Spot Instance Interruption Warning',
            'EC2 Instance Rebalance Recommendation',
            'Auto Scaling lifecycle events',
            'IMDS Spot interruption notice',
            'IMDS Auto Scaling termination intent',
        ];
    }
}
