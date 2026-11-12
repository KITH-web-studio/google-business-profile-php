<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Exception;
use GoogleBusinessProfile\Client;
use GoogleBusinessProfile\PubSub\PullReceiver;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class PullGoogleBusinessProfileReviews extends Command
{
    protected $signature = 'gbp:pull-reviews';

    protected $description = 'Pull and process Google Business Profile review notifications';

    public function handle(PullReceiver $receiver, Client $sdk): int
    {
        $account = config('services.google_business_profile.account');
        $failed = false;

        foreach ($receiver->pull(5) as $delivery) {
            try {
                $notification = $delivery->notification();
                if ($notification->type === 'NEW_REVIEW') {
                    $reviewName = $notification->reviewName($account);
                    $review = $sdk->myBusiness()->reviewsGet($reviewName);

                    Log::info('New Google Business Profile review', [
                        'message_id' => $notification->messageId,
                        'review_name' => $reviewName,
                        'star_rating' => $review['starRating'] ?? null,
                    ]);
                }

                $receiver->acknowledge($delivery);
            } catch (Exception $error) {
                $failed = true;
                Log::warning('Google Business Profile Pub/Sub delivery failed', [
                    'subscription' => $delivery->subscription,
                    'delivery_attempt' => $delivery->deliveryAttempt,
                    'exception' => $error::class,
                ]);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
